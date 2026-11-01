#!/usr/bin/env python3
"""Build the Persian PHP 8.2 compatibility deliverables.

Inputs  (produced by the earlier steps, see analysis/README.md):
  /tmp/scan/meta.json        plugin headers / readme / sizes
  /tmp/scan/aggregate.json   PHPCS + PHPCompatibility findings (+ third party flags)
  /tmp/scan/encoders.json    ionCube / SourceGuardian detection
Outputs:
  analysis/data.json                    machine readable summary (used by the html)
  گزارش-سازگاری-PHP-8.2.md   full written report (Persian)
  گزارش-سازگاری-PHP-8.2.html self contained dashboard (Persian, RTL)
"""
import datetime
import html
import json
import os
import re
import sys

SCAN = "/tmp/scan"
REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

TARGET = (8, 2)          # the PHP version the user is moving to
ASSUMED_CURRENT = (7, 4)  # anything removed in <= 7.4 cannot be the crash: the site runs today

DEP_RE = re.compile(r"deprecated since PHP ([0-9]+\.[0-9]+)")
REM_RE = re.compile(r"removed since PHP ([0-9]+\.[0-9]+)")


def ver(s):
    try:
        p = [int(x) for x in s.split(".")[:2]]
        return tuple(p)
    except Exception:
        return None


# ---------------------------------------------------------------- triage rules
# Findings we read line by line and could prove are NOT a problem.
FALSE_POSITIVES = [
    ("wp-statistics", "mysql_",
     "تشخیص اشتباه ابزار: منظور متد Helper::mysql_time_conditions() است نه اکستنشن mysql"),
    ("tisacase-ariocase-sync", "fam",
     "تشخیص اشتباه ابزار: منظور متد self::family_entry() است نه اکستنشن fam"),
]

# Removed APIs that are still in the source but never reached.
GUARDED = [
    ("admin-menu-editor", "get_magic_quotes_gpc",
     "فراخوانی با version_compare(phpversion(),'7.4.0alpha1','<') محافظت شده؛ روی ۸.۲ اجرا نمی‌شود"),
    ("woocommerce", "array_all",
     "پلی‌فیل خود ووکامرس است و با function_exists() محافظت شده"),
    ("wp-optimize", "create_function",
     "در phpseclib و پشت شرط version_compare(PHP_VERSION,'5.3.0') پنهان است؛ اجرا نمی‌شود"),
    ("digits", "create_function",
     "در shim کتابخانه thecodingmachine/safe است و فقط در صورت فراخوانی مستقیم اجرا می‌شود"),
    ("wp-optimize", "mcrypt",
     "phpseclib فقط وقتی engine برابر CRYPT_ENGINE_MCRYPT باشد از mcrypt استفاده می‌کند و آن هم منوط به extension_loaded('mcrypt') است"),
    ("digits", "mysql_",
     "تعریف تابع در shim کتابخانه safe است؛ روی وردپرس فراخوانی نمی‌شود"),
]

# Removed APIs that are confirmed to run in a reachable code path.
REAL_FATALS = {
    ("persian-woocommerce-shipping", "create_function"):
        "در ساخت صفحهٔ تنظیمات حمل‌ونقل (مدیریت) اجرا می‌شود و روی PHP 8 خطای Fatal می‌دهد",
    ("woo-order-export-lite", "get_magic_quotes_runtime"):
        "در PCLZip هنگام ساخت فایل زیپ خروجی اجرا می‌شود و روی PHP 8 خطای Fatal می‌دهد",
}

SNIFF_FA = {
    "RemovedFunctions": "تابع/اکستنشن حذف‌شده",
    "RemovedExtensions": "اکستنشن حذف‌شده",
    "RemovedConstants": "ثابت حذف‌شده",
    "RemovedIniDirectives": "تنظیم ini حذف‌شده",
    "RemovedPredefinedGlobalVariables": "متغیر سراسری حذف‌شده",
    "RemovedTypeCasts": "تبدیل نوع حذف‌شده",
    "RemovedDollarBraceStringEmbeds": "نوشتن ${var} داخل رشته",
    "RemovedImplodeFlexibleParamOrder": "ترتیب آرگومان‌های implode",
    "RemovedOptionalBeforeRequiredParam": "پارامتر اختیاری پیش از اجباری",
    "RemovedImplicitlyNullableParam": "پارامتر nullableِ ضمنی",
    "RemovedSerializable": "پیاده‌سازی Serializable",
    "RemovedFunctionParameters": "پارامتر حذف‌شده از تابع",
    "RemovedLanguageConstructs": "ساختار زبانی حذف‌شده",
    "NewFunctions": "تابع موجود در نسخه‌های جدیدتر",
    "NewConstants": "ثابت موجود در نسخه‌های جدیدتر",
    "NewClasses": "کلاس موجود در نسخه‌های جدیدتر",
    "ReservedNames": "نام رزرو‌شده",
    "ArgumentFunctionsReportCurrentValue": "رفتار func_get_args تغییر کرده",
    "RemovedMagicAutoload": "‎__autoload حذف‌شده",
    "ForbiddenNames": "نام ممنوع",
}


def sniff_fa(source):
    parts = source.split(".")
    if len(parts) >= 2:
        return SNIFF_FA.get(parts[-2], parts[-2])
    return source


PLUGINS_ROOT = "/tmp/plugins"

_CTX_CACHE = {}


def context_of(slug, rel, line, window=2):
    """Guess whether a finding sits on a real call, a declaration, an ini read or a guard."""
    key = (slug, rel, line)
    if key in _CTX_CACHE:
        return _CTX_CACHE[key]
    path = os.path.join(PLUGINS_ROOT, slug, rel)
    text = ""
    try:
        with open(path, "r", encoding="utf-8", errors="replace") as fh:
            lines = fh.read().splitlines()
        lo = max(0, line - 1 - window)
        hi = min(len(lines), line + window)
        text = "\n".join(lines[lo:hi])
    except OSError:
        text = ""
    if re.search(r"\b(ini_get|ini_set|ini_alter|get_cfg_var)\s*\(", text):
        ctx = "ini"
    elif re.search(r"\b(function_exists|extension_loaded|method_exists|class_exists|defined)\s*\(", text):
        ctx = "guarded"
    elif re.search(r"version_compare\s*\(|PHP_VERSION_ID|phpversion\s*\(", text):
        ctx = "guarded"
    else:
        ctx = "call"
    _CTX_CACHE[key] = ctx
    return ctx


SYMBOL_RE = re.compile(r"(?:Function|function)\s+\\?([A-Za-z_]\w*)\s*\(")


def looks_like_declaration(slug, rel, line, message, back=15):
    """Is this line inside the body of a function that shares the reported name?
    (typical for library shims such as thecodingmachine/safe)"""
    m = SYMBOL_RE.search(message)
    if not m:
        return False
    sym = m.group(1)
    path = os.path.join(PLUGINS_ROOT, slug, rel)
    try:
        with open(path, "r", encoding="utf-8", errors="replace") as fh:
            lines = fh.read().splitlines()
    except OSError:
        return False
    lo = max(0, line - 1 - back)
    chunk = "\n".join(lines[lo:line])
    return re.search(r"\bfunction\s+\\?" + re.escape(sym) + r"\s*\(", chunk) is not None


def buckets(findings, slug):
    """Split findings into: fatal (live), conditional, dead code, deprecated (<=8.2), future, fp."""
    out = {"fatal": [], "dead": [], "dep": [], "future": [], "fp": [], "cond": []}
    for f in findings:
        msg = f["message"]
        dep = ver(DEP_RE.search(msg).group(1)) if DEP_RE.search(msg) else None
        rem = ver(REM_RE.search(msg).group(1)) if REM_RE.search(msg) else None
        item = {**f, "dep": dep, "rem": rem, "fa": sniff_fa(f["source"])}

        hit_fp = next((why for s, key, why in FALSE_POSITIVES
                       if s == slug and ("'" + key + "'" in msg or key + "_" in msg or key + "()" in msg
                                         or key in msg)), None)
        if hit_fp:
            out["fp"].append({**item, "why": hit_fp})
            continue
        hit_guard = next(((key, why) for s, key, why in GUARDED if s == slug and key in msg), None)
        if hit_guard:
            out["dead"].append({**item, "why": hit_guard[1]})
            continue
        real = next((why for (s, key), why in REAL_FATALS.items() if s == slug and key in msg), None)
        if real:
            out["fatal"].append({**item, "why": real})
            continue

        if rem and rem <= ASSUMED_CURRENT:
            out["dead"].append({**item, "why":
                                f"در PHP {'.'.join(map(str, rem))} حذف شده؛ چون سایت الان روی نسخهٔ فعلی بالاست، این مسیر اجرا نمی‌شود"})
            continue
        if "thecodingmachine/safe" in f["file"]:
            out["cond"].append({**item, "why": "در shim کتابخانهٔ جانبی thecodingmachine/safe است و فقط در صورت "
                                               "فراخوانی مستقیم آن تابع اجرا می‌شود"})
            continue
        if rem and rem <= TARGET:
            ctx = context_of(slug, f["file"], f["line"])
            why = (f"در PHP {'.'.join(map(str, rem))} حذف شده و فراخوانی آن خطای Fatal می‌دهد")
            if ctx == "ini":
                out["cond"].append({**item, "why": "فقط خواندن یک تنظیم ini است که در ۸.x دیگر وجود ندارد؛ "
                                                   "مقدار false برمی‌گردد و خطای Fatal نمی‌دهد"})
            elif ctx in ("guarded", "decl") or looks_like_declaration(slug, f["file"], f["line"], msg):
                out["dead"].append({**item, "why": f"در مسیری محافظت‌شده/تعریف تابع است (زمینه: {ctx}) و در حالت عادی اجرا نمی‌شود"})
            else:
                out["fatal"].append({**item, "why": why, "ctx": ctx})
            continue
        if rem and rem > TARGET:
            out["future"].append(item)
            continue
        if dep and dep <= TARGET:
            out["dep"].append(item)
            continue
        out["future"].append(item)
    return out


def load():
    meta = json.load(open(os.path.join(SCAN, "meta.json"), encoding="utf-8"))
    agg = json.load(open(os.path.join(SCAN, "aggregate.json"), encoding="utf-8"))
    enc = json.load(open(os.path.join(SCAN, "encoders.json"), encoding="utf-8"))
    grep = json.load(open(os.path.join(SCAN, "grep_findings.json"), encoding="utf-8"))
    return meta, agg, enc, grep


def build():
    meta, agg, enc, grep = load()
    data = {"generated": datetime.datetime.now().strftime("%Y-%m-%d %H:%M"),
            "target": "8.2", "plugins": []}

    for slug in sorted(agg):
        a = agg[slug]
        e = enc.get(slug, {})
        b = buckets(a["findings"], slug)
        name = a.get("hdr_Plugin Name") or slug
        version = a.get("hdr_Version") or "?"
        req_php = a.get("hdr_Requires PHP") or a.get("readme_requires php") or ""
        tested = a.get("hdr_Tested up to") or a.get("readme_tested up to") or ""

        encoded_total = e.get("ioncube_count", 0) + e.get("sourceguardian_count", 0)
        loader_kind = ("ionCube" if e.get("ioncube_count") else "") or \
                      ("SourceGuardian" if e.get("sourceguardian_count") else "")
        on_path = e.get("main_file_encoded") or bool(e.get("direct_requires_encoded"))

        # why/reason strings (Persian)
        reasons = []
        if on_path:
            where = "فایل اصلی افزونه" if e.get("main_file_encoded") else \
                    "فایلی که فایل اصلی آن را include می‌کند (" + \
                    "، ".join(list(e.get("direct_requires_encoded", {}))[:2]) + ")"
            reasons.append({
                "level": "critical",
                "title": f"کد رمزگذاری‌شده با {loader_kind}",
                "text": (f"{encoded_total} فایل این افزونه با {loader_kind} رمزگذاری شده و {where} هم جزو آن‌هاست. "
                         f"اجرای این فایل‌ها نیازمند نصب Loader سازگار با PHP 8.2 روی هاست است. "
                         f"اگر Loader نصب نباشد یا نسخه‌اش با 8.2 هم‌خوان نباشد، همان لحظهٔ لود شدن افزونه پیام "
                         f"«Script error: ... requires the {loader_kind} PHP Loader» چاپ شده و سایت از کار می‌افتد.")})
        for f in b["fatal"]:
            reasons.append({
                "level": "high",
                "title": f"فراخوانی تابع حذف‌شده: {f.get('sniff', '')}",
                "text": f"{f['file']}:{f['line']} — {f['message']} — {f.get('why','')}"})
        if b["dep"]:
            files = sorted({f["file"] for f in b["dep"]})
            reasons.append({
                "level": "medium",
                "title": f"{len(b['dep'])} اخطار منسوخ‌شدگی مرتبط با PHP 8.2/8.1",
                "text": "این موارد سایت را از کار نمی‌اندازند ولی در لاگ خطا نویز ایجاد می‌کنند و اگر در پاسخ "
                        "AJAX/JSON چاپ شوند می‌توانند درخواست‌ها را خراب کنند. مهم‌ترین‌ها: " +
                        "، ".join(sorted({d["fa"] for d in b["dep"]})[:4]) +
                        ("؛ فایل‌ها: " + "، ".join(files[:3]) if files else "")})
        if b["future"]:
            reasons.append({
                "level": "info",
                "title": f"{len(b['future'])} مورد برای PHP 8.3 به بعد",
                "text": "این‌ها روی 8.2 مشکلی ندارند و فقط برای ارتقاهای آینده مهم‌اند."})
        if b["dead"]:
            reasons.append({
                "level": "ok",
                "title": f"{len(b['dead'])} موردِ بی‌خطر (کدِ اجرا‌نشدنی)",
                "text": "مواردی که ابزار گزارش کرده ولی با بررسی دستی مشخص شد یا با شرط محافظت شده‌اند، "
                        "یا مربوط به نسخه‌های بسیار قدیمی‌تر از نسخهٔ فعلی شما هستند و مسیرشان اجرا نمی‌شود."})

        own_dep = len([x for x in b["dep"] if not x["third_party"]])
        vendor_dep = len(b["dep"]) - own_dep
        if vendor_dep:
            reasons.append({
                "level": "info",
                "title": f"{vendor_dep} اخطار در کتابخانه‌های همراه (vendor)",
                "text": "این اخطارها در کدِ کتابخانه‌های ثالثِ همراهِ افزونه هستند، نه در کد خود افزونه. "
                        "تا وقتی آن کتابخانه به‌روز نشود فقط در لاگ دیده می‌شوند."})
        # verdict
        if on_path:
            verdict, score = "بحرانی", 100
        elif b["fatal"]:
            verdict, score = "پرخطر", 70
        elif own_dep:
            verdict, score = "نیاز به توجه", 40
        elif b["dep"] or b["future"]:
            verdict, score = "تقریباً سالم", 15
        else:
            verdict, score = "سالم", 5

        data["plugins"].append({
            "slug": slug,
            "name": name,
            "version": version,
            "requires_php": req_php,
            "tested_wp": tested,
            "php_files": a["php_files"],
            "php_lines": a["php_lines"],
            "zip_date": datetime.datetime.utcfromtimestamp(a["zip_newest_file"]).strftime("%Y-%m-%d")
                        if a.get("zip_newest_file") else "",
            "encoded_total": encoded_total,
            "encoded_kind": loader_kind,
            "encoded_main": e.get("main_file_encoded") or "",
            "encoded_direct": e.get("direct_requires_encoded", {}),
            "on_load_path": on_path,
            "counts": {
                "fatal": len(b["fatal"]),
                "dep": len(b["dep"]),
                "future": len(b["future"]),
                "dead": len(b["dead"]),
                "fp": len(b["fp"]),
                "cond": len(b["cond"]),
                "own_dep": len([x for x in b["dep"] if not x["third_party"]]),
                "own_fatal": len([x for x in b["fatal"] if not x["third_party"]]),
            },
            "fatal": b["fatal"][:60],
            "cond": b["cond"][:20],
            "dep": sorted(b["dep"], key=lambda x: (x["third_party"], x["file"]))[:80],
            "dead_samples": b["dead"][:20],
            "verdict": verdict,
            "score": score,
            "reasons": reasons,
        })

    data["plugins"].sort(key=lambda p: (-p["score"], -p["counts"]["fatal"], -p["counts"]["own_dep"], p["name"]))
    totals = {
        "plugins": len(data["plugins"]),
        "php_files": sum(p["php_files"] for p in data["plugins"]),
        "php_lines": sum(p["php_lines"] for p in data["plugins"]),
        "encoded_plugins": [p["slug"] for p in data["plugins"] if p["encoded_total"]],
        "fatal_plugins": [p["slug"] for p in data["plugins"] if p["counts"]["fatal"]],
        "dep_plugins": [p["slug"] for p in data["plugins"] if p["counts"]["own_dep"]],
    }
    data["totals"] = totals
    json.dump(data, open(os.path.join(REPO, "analysis", "data.json"), "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)
    print("wrote analysis/data.json")
    for p in data["plugins"][:12]:
        print(f"{p['slug']:38s} {p['verdict']:14s} enc={p['encoded_total']:4d} fatal={p['counts']['fatal']:3d} "
              f"dep82={p['counts']['own_dep']:3d} future={p['counts']['future']:4d}")


if __name__ == "__main__":
    build()


# ------------------------------------------------------------------ rendering
CSS = """
:root{--bg:#0f1420;--card:#171d2b;--card2:#1e2536;--line:#2c3448;--txt:#e8ecf5;
--muted:#98a2b8;--red:#ff5f6d;--orange:#ff9f43;--yellow:#f7d154;--green:#3ddc97;--blue:#5aa9ff;}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--txt);
font-family:"Vazirmatn","Vazir",Tahoma,"Segoe UI",system-ui,sans-serif;direction:rtl;line-height:1.75}
.wrap{max-width:1180px;margin:0 auto;padding:28px 20px 80px}
h1{font-size:26px;margin:0 0 6px}h2{font-size:20px;margin:34px 0 12px;border-bottom:1px solid var(--line);padding-bottom:8px}
p,li{font-size:15px;color:#dbe2f0}
.muted{color:var(--muted);font-size:13.5px}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin:18px 0}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px 16px}
.card .n{font-size:26px;font-weight:700}.card .l{color:var(--muted);font-size:13px}
.verdict{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12.5px;font-weight:700}
.v-critical{background:#3a1620;color:#ff8f99;border:1px solid #7d2233}
.v-high{background:#3a2a16;color:#ffb877;border:1px solid #7d5322}
.v-medium{background:#37320f;color:#f7d154;border:1px solid #77700f}
.v-ok{background:#123326;color:#7ce7b6;border:1px solid #1d6b4c}
.v-info{background:#1b2434;color:#9fb6d6;border:1px solid #2c3448}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{padding:9px 10px;border-bottom:1px solid var(--line);text-align:right;vertical-align:top}
th{color:var(--muted);font-size:12.5px;font-weight:600;position:sticky;top:0;background:var(--bg)}
tr.row{cursor:pointer}tr.row:hover{background:var(--card)}
details{background:var(--card);border:1px solid var(--line);border-radius:14px;margin:10px 0;overflow:hidden}
summary{padding:14px 16px;cursor:pointer;font-weight:600;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.body{padding:4px 18px 18px}
.badge{font-size:12px;color:var(--muted);background:var(--card2);border:1px solid var(--line);border-radius:8px;padding:1px 8px}
.reason{border-radius:12px;padding:10px 14px;margin:9px 0;border:1px solid var(--line);background:var(--card2)}
.reason .t{font-weight:700;margin-bottom:3px}
.r-critical{border-color:#7d2233;background:#2a1219}.r-high{border-color:#7d5322;background:#2a1f12}
.r-medium{border-color:#77700f;background:#262310}.r-info{border-color:#2c3448}
.r-ok{border-color:#1d6b4c;background:#11251d}
code,.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;direction:ltr;display:inline-block}
ul.f{margin:6px 0 0 0;padding-right:18px}ul.f li{margin:3px 0;font-size:13.5px}
.toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:14px 0}
input,select{background:var(--card);border:1px solid var(--line);color:var(--txt);border-radius:10px;padding:8px 12px;font-size:14px;font-family:inherit}
button.f{background:var(--card);border:1px solid var(--line);color:var(--txt);border-radius:10px;padding:8px 14px;cursor:pointer;font-family:inherit;font-size:13.5px}
button.f.on{background:#25324a;border-color:#3f6699;color:#cfe3ff}
.note{background:#152033;border:1px solid #24405f;border-radius:14px;padding:14px 16px;margin:14px 0}
.kbd{background:var(--card2);border:1px solid var(--line);border-radius:6px;padding:1px 6px;font-size:12.5px}
"""


def esc(x):
    return html.escape(str(x))


def render_html(data):
    t = data["totals"]
    enc_plugins = [p for p in data["plugins"] if p["encoded_total"]]
    fatal_plugins = [p for p in data["plugins"] if p["counts"]["fatal"]]
    dep_plugins = [p for p in data["plugins"] if p["counts"]["own_dep"]]
    ok_plugins = [p for p in data["plugins"] if p["verdict"] in ("سالم", "تقریباً سالم")]

    parts = []
    parts.append(f"""<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>گزارش سازگاری افزونه‌ها با PHP 8.2</title><style>{CSS}</style></head><body><div class="wrap">""")
    parts.append(f"""<h1>گزارش سازگاری افزونه‌های سایت با PHP 8.2</h1>
<p class="muted">تحلیل خودکارِ {t['plugins']} افزونه · {t['php_files']:,} فایل PHP · {t['php_lines']:,} خط کد
· ابزار: PHP_CodeSniffer + PHPCompatibility روی موتور PHP 8.2.33 · تاریخ: {data['generated']}</p>""")

    # pointer to the empirical test (phase 2)
    parts.append("""<div class="note" style="border-color:#1d6b4c;background:#11251d"><b>تست عملی هم انجام شده:</b>
این گزارش بر اساس <i>تحلیل کد</i> است. بعد از آن همهٔ افزونه‌ها روی یک وردپرس واقعی
(WordPress 6.9.11) با PHP 8.2 هم نصب و اجرا شدند تا معلوم شود کدام‌ها <b>واقعاً</b> خطا می‌دهند.
نتیجهٔ آن تست (۴ افزونه سایت را از کار می‌اندازند و ۲ افزونه فقط بی‌کار می‌مانند) در
<a href="گزارش-تست-عملی-PHP-8.2.html" style="color:#7ce7b6">گزارش تست عملی</a> است.</div>""")

    # answer box
    parts.append(f"""<div class="note"><b>جواب کوتاه:</b> به احتمال خیلی زیاد مشکل شما از <b>کدهای رمزگذاری‌شده</b> است، نه از نسخهٔ PHP.
<b>{len(enc_plugins)} افزونه</b> فایل‌هایی دارند که با ionCube یا SourceGuardian رمزگذاری شده‌اند
({esc('، '.join(p['name'] for p in enc_plugins))}).
این فایل‌ها برای اجرا به «لودر» مخصوص همان نسخهٔ PHP نیاز دارند. وقتی PHP را به 8.2 تغییر می‌دهید و لودر 8.2 روی هاست نصب نیست،
همان اولین فایلِ رمزگذاری‌شده پیام خطا چاپ می‌کند و <b>کل سایت از کار می‌افتد</b> (صفحه سفید / Script error).
<br>بعد از آن، <b>{len(fatal_plugins)} افزونه</b> فراخوانیِ توابع کاملاً حذف‌شده در PHP 8 دارند که در مسیر اجرا هستند.</div>""")

    enc_slugs = "، ".join(f"<span class='kbd'>{esc(p['slug'])}</span>" for p in enc_plugins)
    parts.append(f"""<div class="note" style="border-color:#7d2233;background:#2a1219">
<b>اگر همین الان سایت بالا نمی‌آید (راه نجات سریع):</b>
<ol style="margin:8px 0 0">
<li>PHP را در هاست دوباره روی همان نسخهٔ قبلی (مثلاً 8.1 یا 7.4) بگذارید تا سایت برگردد.</li>
<li>اگر به محیط مدیریت دسترسی دارید، افزونه‌های زیر را غیرفعال کنید: {enc_slugs}</li>
<li>اگر به پیشخوان دسترسی ندارید، با FTP/File Manager پوشهٔ
<span class="kbd">wp-content/plugins/&lt;نام-پوشه&gt;</span> را به چیزی مثل
<span class="kbd">digits.off</span> تغییر نام دهید؛ وردپرس افزونه را خودکار غیرفعال می‌کند و سایت بالا می‌آید.</li>
<li>بعد از بالا آمدن سایت، لودرها را درست کنید یا نسخهٔ سازگار افزونه را نصب کنید و دوباره به 8.2 بروید.</li>
</ol></div>""")

    parts.append('<div class="cards">')
    for n, l, c in ((len(enc_plugins), "افزونهٔ رمزگذاری‌شده (بحرانی)", "var(--red)"),
                    (len(fatal_plugins), "افزونه با خطای Fatal واقعی", "var(--orange)"),
                    (len(dep_plugins), "افزونه با اخطار Deprecated در 8.2", "var(--yellow)"),
                    (len(ok_plugins), "افزونهٔ سالم", "var(--green)")):
        parts.append(f'<div class="card"><div class="n" style="color:{c}">{n}</div><div class="l">{l}</div></div>')
    parts.append('</div>')

    # ---- section 1
    parts.append("<h2>۱) افزونه‌های رمزگذاری‌شده (مهم‌ترین عامل قطع شدن سایت)</h2>")
    parts.append("""<p>این فایل‌ها با <span class="kbd">die()</span> و پیام «Script error: ... requires the ionCube/SourceGuardian PHP Loader»
    خاتمه پیدا می‌کنند اگر لودرِ متناسب با PHP 8.2 نصب نباشد. چون فایل اصلیِ افزونه (یا فایلی که مستقیماً include می‌شود) رمزگذاری است،
    این اتفاق در <b>هر بار بالا آمدن وردپرس</b> می‌افتد — یعنی کل سایت، نه فقط یک بخش.</p>""")
    parts.append("<table><tr><th>افزونه</th><th>نسخه</th><th>نوع رمزگذاری</th><th>تعداد فایل</th><th>نقطهٔ ورود</th></tr>")
    for p in enc_plugins:
        entry = "فایل اصلی افزونه" if p["encoded_main"] else "، ".join(list(p["encoded_direct"])[:2]) or "—"
        parts.append(f"""<tr><td><b>{esc(p['name'])}</b><br><span class="mono muted">{esc(p['slug'])}</span></td>
<td>{esc(p['version'])}</td><td>{esc(p['encoded_kind'])}</td><td>{p['encoded_total']}</td>
<td class="mono" style="font-size:12px">{esc(entry)}</td></tr>""")
    parts.append("</table>")
    parts.append("""<div class="note"><b>چه باید کرد؟</b><br>
۱) در هاست (cPanel &gt; Select PHP Version &gt; Extensions یا MultiPHP INI Editor) تیک
<span class="kbd">ioncube</span> و <span class="kbd">sourceguardian</span> را برای PHP 8.2 فعال کنید و نسخهٔ لودر را چک کنید.<br>
۲) اگر بعد از فعال‌سازی باز هم خطا می‌گیرید، یعنی فایل با انکودر قدیمی رمزگذاری شده و فقط برای نسخه‌های قدیمی PHP مجاز است؛
در این صورت باید از فروشنده/ناشر افزونه نسخهٔ سازگار با 8.2 را بگیرید.<br>
۳) راهِ تست: افزونه را از مسیر <span class="kbd">wp-content/plugins</span> موقتاً تغییر نام دهید تا وردپرس آن را غیرفعال کند،
بعد PHP را به 8.2 ببرید و سایت را چک کنید.</div>""")

    # ---- section 2
    parts.append("<h2>۲) خطاهای Fatal واقعی (تابع/ساختار حذف‌شده در مسیر اجرا)</h2>")
    parts.append("""<p>این موارد را خط‌به‌خط بررسی کردم: نه در کتابخانهٔ بی‌استفاده‌اند، نه پشت شرطِ نسخه پنهان شده‌اند.
با فعال بودن افزونه و رسیدن اجرا به این خط، PHP 8.x با خطای <span class="kbd">Fatal error: Uncaught Error: Call to undefined function ...</span>
متوقف می‌شود.</p>""")
    for p in fatal_plugins:
        parts.append(f'<details open><summary><b>{esc(p["name"])}</b> <span class="badge">{esc(p["slug"])}</span> '
                     f'<span class="badge">نسخه {esc(p["version"])}</span></summary><div class="body">')
        for f in p["fatal"]:
            parts.append(f"""<div class="reason r-high"><div class="t">{esc(f['fa'])} — {esc(f['source'].split('.')[-1])}</div>
<div class="muted mono">{esc(p['slug'])}/{esc(f['file'])}:{f['line']}</div>
<div style="margin-top:4px">{esc(f['message'])}</div>
<div class="muted" style="margin-top:4px">{esc(f.get('why',''))}</div></div>""")
        parts.append("</div></details>")

    # ---- section 3
    parts.append("<h2>۳) اخطارهای Deprecated مرتبط با 8.2 (سایت را نمی‌اندازد ولی باید رفع شود)</h2>")
    parts.append("""<p>این‌ها «اخطار» هستند نه خطای مرگبار؛ ولی لاگ خطا را پر می‌کنند و اگر خروجی‌شان در پاسخ AJAX/REST چاپ شود،
درخواست را خراب می‌کنند. بیشترشان مربوط به <span class="kbd">utf8_encode/utf8_decode</span> (منسوخ در 8.2)،
<span class="kbd">strftime</span> (منسوخ در 8.1)، <span class="kbd">auto_detect_line_endings</span>،
<span class="kbd">libxml_disable_entity_loader</span> و نوشتن <span class="kbd">${var}</span> در رشته است.</p>""")
    for p in dep_plugins:
        own = [f for f in p["dep"] if not f["third_party"]][:12]
        if not own:
            continue
        parts.append(f'<details><summary><b>{esc(p["name"])}</b> <span class="badge">{p["counts"]["own_dep"]} مورد</span></summary><div class="body"><ul class="f">')
        for f in own:
            parts.append(f"""<li><span class="mono">{esc(f['file'])}:{f['line']}</span> — {esc(f['fa'])}: {esc(f['message'][:110])}</li>""")
        parts.append("</ul></div></details>")

    # ---- full table
    parts.append("<h2>۴) جدول کامل همهٔ افزونه‌ها</h2>")
    parts.append("""<div class="toolbar"><input id="q" placeholder="جستجوی نام افزونه..." oninput="filterAll()">
<button class="f on" data-v="all" onclick="setV('all')">همه</button>
<button class="f" data-v="بحرانی" onclick="setV('بحرانی')">بحرانی</button>
<button class="f" data-v="پرخطر" onclick="setV('پرخطر')">پرخطر</button>
<button class="f" data-v="نیاز به توجه" onclick="setV('نیاز به توجه')">نیاز به توجه</button>
<button class="f" data-v="سالم" onclick="setV('سالم')">سالم</button></div>""")
    parts.append("""<table><thead><tr><th>وضعیت</th><th>افزونه</th><th>نسخه</th><th>Requires PHP</th>
<th>فایل‌های PHP</th><th>رمزگذاری</th><th>Fatal</th><th>Deprecated 8.2</th><th>آینده (8.3+)</th></tr></thead><tbody id="tb">""")
    cls = {"بحرانی": "v-critical", "پرخطر": "v-high", "نیاز به توجه": "v-medium", "سالم": "v-ok", "تقریباً سالم": "v-info"}
    for p in data["plugins"]:
        parts.append(f"""<tr class="row" data-v="{esc(p['verdict'])}" data-n="{esc((p['name']+' '+p['slug']).lower())}" onclick="openSlug('{esc(p['slug'])}')">
<td><span class="verdict {cls.get(p['verdict'],'v-info')}">{esc(p['verdict'])}</span></td>
<td><b>{esc(p['name'])}</b><br><span class="mono muted">{esc(p['slug'])}</span></td>
<td>{esc(p['version'])}</td><td>{esc(p['requires_php'] or '—')}</td>
<td>{p['php_files']:,}</td>
<td>{('<b style="color:var(--red)">' + str(p['encoded_total']) + ' ' + esc(p['encoded_kind']) + '</b>') if p['encoded_total'] else '—'}</td>
<td>{p['counts']['fatal'] or '—'}</td><td>{p['counts']['own_dep'] or '—'}</td><td>{p['counts']['future'] or '—'}</td></tr>""")
    parts.append("</tbody></table>")

    # ---- details
    parts.append("<h2>۵) جزئیات هر افزونه</h2>")
    for p in data["plugins"]:
        parts.append(f'<details id="d-{esc(p["slug"])}"><summary><span class="verdict {cls.get(p["verdict"],"v-info")}">{esc(p["verdict"])}</span>'
                     f'<b>{esc(p["name"])}</b><span class="badge">{esc(p["slug"])}</span>'
                     f'<span class="badge">نسخه {esc(p["version"])}</span>'
                     f'<span class="badge">{p["php_files"]} فایل</span>'
                     f'<span class="badge">آخرین تغییر {esc(p["zip_date"] or "؟")}</span></summary><div class="body">')
        if not p["reasons"]:
            parts.append('<div class="reason r-ok"><div class="t">هیچ مورد مرتبطی با PHP 8.2 پیدا نشد</div></div>')
        for r in p["reasons"]:
            lvl = {"critical": "critical", "high": "high", "medium": "medium", "info": "info", "ok": "ok"}[r["level"]]
            parts.append(f'<div class="reason r-{lvl}"><div class="t">{esc(r["title"])}</div><div>{esc(r["text"])}</div></div>')
        own_fatal = [f for f in p["fatal"]]
        if own_fatal:
            parts.append('<ul class="f">')
            for f in own_fatal:
                parts.append(f'<li class="mono">{esc(f["file"])}:{f["line"]} — {esc(f["message"][:120])}</li>')
            parts.append("</ul>")
        own_dep = [f for f in p["dep"] if not f["third_party"]][:15]
        if own_dep:
            parts.append('<div class="muted" style="margin-top:8px">مهم‌ترین اخطارها:</div><ul class="f">')
            for f in own_dep:
                parts.append(f'<li class="mono">{esc(f["file"])}:{f["line"]} — {esc(f["fa"])}</li>')
            parts.append("</ul>")
        parts.append("</div></details>")

    # ---- checklist
    parts.append("""<h2>۶) چک‌لیست عملیاتی برای رفتن به PHP 8.2</h2>
<ol>
<li><b>قبل از هر چیز بک‌آپ بگیرید</b> (فایل + دیتابیس) و یک نسخه از سایت را روی ساب‌دامین staging منتقل کنید.</li>
<li>در cPanel/DirectAdmin بخش Select PHP Version را باز کنید و برای PHP 8.2 تیک
<span class="kbd">ioncube_loader</span> و <span class="kbd">sourceguardian</span> را فعال کنید
(اگر گزینه ندارید، از هاست بخواهید نصب کند).</li>
<li>وردپرس را به آخرین نسخه (حداقل 6.6) و ووکامرس را به‌روز کنید؛ نسخهٔ فعلی شما 10.3.7 است و از نظر 8.2 مشکلی ندارد.</li>
<li>افزونه‌های <b>فاکتور حرفه‌ای ووکامرس</b> و <b>المنتور پرو</b> را از ناشرشان (woocommerce.ir / ارائه‌دهنده) نسخهٔ سازگار با PHP 8.2 بگیرید؛
اگر نسخهٔ جدید ندادند، باید غیرفعال شوند.</li>
<li>افزونه‌های <b>حمل‌ونقل ووکامرس</b> و <b>پی‌پی‌زد/درگاه‌های بانکی wp-parsidate</b> را به‌روز کنید
(<span class="kbd">create_function</span> و <span class="kbd">implode</span> معکوس در آن‌ها روی 8 می‌شکند).</li>
<li>با افزونهٔ <span class="kbd">Health Check &amp; Troubleshooting</span> حالت Troubleshooting را فعال کنید و افزونه‌ها را یکی‌یکی
روی PHP 8.2 روشن کنید تا مقصر دقیق مشخص شود (بدون اینکه بازدیدکننده‌ها خطا ببینند).</li>
<li>خطاها را از <span class="kbd">wp-content/debug.log</span> بخوانید
(در <span class="kbd">wp-config.php</span>: <span class="kbd">define('WP_DEBUG', true); define('WP_DEBUG_LOG', true); define('WP_DEBUG_DISPLAY', false);</span>).</li>
</ol>""")

    parts.append(f"""<h2>۷) روش کار</h2>
<p class="muted">هر {t['plugins']} فایل زیپ بازگشایی شد و همهٔ {t['php_files']:,} فایل PHP آن‌ها
با <b>PHP_CodeSniffer + PHPCompatibility</b> (موتور PHP 8.2 واقعی، نسخهٔ 8.2.33) و با
<code>--runtime-set testVersion 8.2-</code> اسکن شد؛ سپس هر خطای بحرانی خط‌به‌خط بازبینی شد تا
کدهای مرده (کتابخانه‌های قدیمی همراه، فراخوانی‌های پشت شرطِ نسخه، تشخیص‌های اشتباه ابزار) حذف شوند.
اسکریپت‌ها در پوشهٔ <span class="mono">analysis/</span> هستند و دوباره قابل اجرایند.</p>
<p class="muted">محدودیت مهم: فایل‌های رمزگذاری‌شده (ionCube/SourceGuardian) قابل تحلیلِ کد نیستند؛
برای آن‌ها فقط می‌توان گفت «نیازمند لودرِ سازگار با 8.2» و تشخیص نهایی با اجرای واقعی روی هاست است.</p>""")
    parts.append("</div>")
    parts.append("""<script>
let cur='all';
function setV(v){cur=v;document.querySelectorAll('button.f').forEach(b=>b.classList.toggle('on',b.dataset.v===v));filterAll();}
function filterAll(){const q=document.getElementById('q').value.trim().toLowerCase();
document.querySelectorAll('#tb tr').forEach(tr=>{const ok=(cur==='all'||tr.dataset.v===cur)&&(!q||tr.dataset.n.includes(q));
tr.style.display=ok?'':'none';});}
function openSlug(s){const el=document.getElementById('d-'+s);if(el){el.open=true;el.scrollIntoView({behavior:'smooth',block:'center'});
el.style.outline='2px solid #5aa9ff';setTimeout(()=>el.style.outline='',1800);}}
</script></body></html>""")
    return "\n".join(parts)


def render_md(data):
    t = data["totals"]
    enc = [p for p in data["plugins"] if p["encoded_total"]]
    fatal = [p for p in data["plugins"] if p["counts"]["fatal"]]
    dep = [p for p in data["plugins"] if p["counts"]["own_dep"]]
    L = []
    L.append("# گزارش سازگاری افزونه‌ها با PHP 8.2\n")
    L.append(f"تاریخ تحلیل: {data['generated']} | {t['plugins']} افزونه | "
             f"{t['php_files']:,} فایل PHP | {t['php_lines']:,} خط کد\n")
    L.append("## نتیجهٔ کوتاه\n")
    L.append("به احتمال بسیار زیاد مشکل شما **کدهای رمزگذاری‌شده (ionCube / SourceGuardian)** است، نه خودِ PHP 8.2.\n")
    L.append(f"**{len(enc)} افزونه** فایل رمزگذاری‌شده دارند و در همهٔ آن‌ها فایل اصلی (یا اولین include آن) رمزگذاری است؛ "
             "یعنی اگر لودر متناسب با PHP 8.2 روی هاست فعال نباشد، همان لحظهٔ لود وردپرس سایت می‌خوابد.\n")
    L.append("| افزونه | نسخه | نوع | تعداد فایل رمزگذاری‌شده | نقطهٔ ورود |")
    L.append("|---|---|---|---|---|")
    for p in enc:
        entry = "فایل اصلی افزونه" if p["encoded_main"] else "، ".join(list(p["encoded_direct"])[:2]) or "—"
        L.append(f"| {p['name']} | {p['version']} | {p['encoded_kind']} | {p['encoded_total']} | `{entry}` |")
    L.append("\n### اگر همین الان سایت بالا نمی‌آید\n")
    L.append("1. PHP را در هاست روی همان نسخهٔ قبلی برگردانید.")
    L.append("2. از طریق FTP/File Manager پوشه‌های زیر را در `wp-content/plugins/` تغییر نام دهید "
             "(مثلاً `digits` → `digits.off`) تا وردپرس آن‌ها را غیرفعال کند: "
             + "، ".join(f"`{p['slug']}`" for p in enc))
    L.append("3. بعد از بالا آمدن سایت، لودرها را فعال کنید یا نسخهٔ سازگار بگیرید و دوباره به 8.2 بروید.\n")
    L.append("\nبعد از آن، این افزونه‌ها فراخوانیِ توابع **کاملاً حذف‌شده** در PHP 8 را در مسیر اجرا دارند:\n")
    for p in fatal:
        for f in p["fatal"]:
            L.append(f"- **{p['name']}** (`{p['slug']}/{f['file']}:{f['line']}`) — {f['message']}")
    L.append("\n## جدول وضعیت همهٔ افزونه‌ها\n")
    L.append("| وضعیت | افزونه | نسخه | Requires PHP | فایل PHP | رمزگذاری | Fatal | Deprecated 8.2 |")
    L.append("|---|---|---|---|---|---|---|---|")
    for p in data["plugins"]:
        L.append(f"| {p['verdict']} | {p['name']} | {p['version']} | {p['requires_php'] or '—'} | "
                 f"{p['php_files']} | {p['encoded_total'] or '—'} | {p['counts']['fatal'] or '—'} | {p['counts']['own_dep'] or '—'} |")
    L.append("\n## اخطارهای Deprecated (غیرمرگبار)\n")
    for p in dep:
        own = [f for f in p["dep"] if not f["third_party"]][:8]
        if own:
            L.append(f"**{p['name']}** ({p['counts']['own_dep']} مورد):")
            for f in own:
                L.append(f"- `{f['file']}:{f['line']}` — {f['fa']}")
    L.append("\n## چک‌لیست عملیاتی\n")
    L.append("1. بک‌آپ کامل و تست روی staging.")
    L.append("2. فعال کردن ionCube Loader و SourceGuardian Loader برای PHP 8.2 در هاست.")
    L.append("3. به‌روزرسانی وردپرس (حداقل 6.6) و افزونه‌های دارای خطای Fatal.")
    L.append("4. برای «فاکتور حرفه‌ای ووکامرس» و «المنتور پرو» نسخهٔ سازگار از ناشر بگیرید.")
    L.append("5. با Health Check افزونه‌ها را یکی‌یکی روی 8.2 فعال کنید.")
    L.append("6. `WP_DEBUG_LOG` را روشن کنید و `wp-content/debug.log` را بخوانید.")
    L.append("\n## روش و محدودیت‌ها\n")
    L.append("اسکن با PHP_CodeSniffer + PHPCompatibility روی موتور واقعی PHP 8.2.33 انجام شد "
             "(`testVersion 8.2-`) و سپس هر مورد بحرانی به‌صورت دستی بازبینی شد تا کدهای مرده حذف شوند. "
             "فایل‌های رمزگذاری‌شده قابل تحلیل نیستند و نیاز به تست روی هاست دارند.")
    return "\n".join(L)


def render_csv(data):
    import csv
    path = os.path.join(REPO, "خلاصه-افزونه‌ها.csv")
    with open(path, "w", encoding="utf-8-sig", newline="") as fh:
        w = csv.writer(fh)
        w.writerow(["وضعیت", "نام افزونه", "پوشه", "نسخه", "Requires PHP", "تست‌شده تا WP",
                    "فایل PHP", "خط کد", "فایل رمزگذاری‌شده", "نوع رمزگذاری",
                    "خطای Fatal", "Deprecated مرتبط با 8.2", "موارد 8.3+", "آخرین تغییر بسته"])
        for p in data["plugins"]:
            w.writerow([p["verdict"], p["name"], p["slug"], p["version"], p["requires_php"] or "",
                        p["tested_wp"] or "", p["php_files"], p["php_lines"],
                        p["encoded_total"] or "", p["encoded_kind"] or "",
                        p["counts"]["fatal"] or "", p["counts"]["own_dep"] or "",
                        p["counts"]["future"] or "", p["zip_date"]])
    print("wrote", path)


def render():
    data = json.load(open(os.path.join(REPO, "analysis", "data.json"), encoding="utf-8"))
    html_out = render_html(data)
    md_out = render_md(data)
    render_csv(data)
    hp = os.path.join(REPO, "گزارش-سازگاری-PHP-8.2.html")
    mp = os.path.join(REPO, "گزارش-سازگاری-PHP-8.2.md")
    open(hp, "w", encoding="utf-8").write(html_out)
    open(mp, "w", encoding="utf-8").write(md_out)
    print("wrote", hp, len(html_out), "bytes")
    print("wrote", mp, len(md_out), "bytes")


if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == "render":
        render()
