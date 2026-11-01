#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Build the Persian "runtime test" report from the live WordPress results.

usage:
  python3 build_runtime_report.py [result-a.json] [result-b.json] [...]

Merges every result file produced by run-tests.mjs, joins it with the static
analysis (analysis/data.json) and writes:

  tests/results/runtime.json          merged raw results
  گزارش-تست-عملی-PHP-8.2.html          RTL dashboard
  گزارش-تست-عملی-PHP-8.2.md            markdown report
  نتایج-تست-عملی.csv                   csv summary
"""
import csv
import html
import json
import os
import sys
from collections import Counter, OrderedDict

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(HERE)
STATIC = os.path.join(REPO, "analysis", "data.json")
OUT_DIR = os.path.join(HERE, "results")

# ---------------------------------------------------------------- triage
# verdict -> (class, fa label, colour key)
CLASSES = OrderedDict([
    ("down",  ("سایت را از کار می‌اندازد", "critical")),
    ("inert", ("سایت بالاست ولی افزونه از کار می‌افتد", "high")),
    ("warn",  ("بالا می‌آید، اخطار در لاگ", "medium")),
    ("env",   ("خطای محیط تست (ربطی به 8.2 ندارد)", "info")),
    ("ok",    ("سالم", "ok")),
])

# manual notes where the raw machine verdict needs an expert reading
NOTES = {
    "elementor": (
        "env",
        "تنها خطای دیده‌شده روی صفحهٔ «پیشخوان المنتور» (<span class='kbd'>wp-admin/admin.php?page=elementor</span>) است: "
        "<code>Filter_Plugins::get_add_ons_installation_status(): Argument #1 ($add_ons) must be of type array, null given</code>. "
        "دلیلش نبودِ اینترنت در محیط تست است: المنتور داده‌های آن صفحه را از API خودش می‌گیرد و وقتی پاسخی نرسد آرایه خالی می‌ماند. "
        "این TypeError در PHP 7.4 و 8.2 و 8.3 یکسان رخ می‌دهد، پس ربطی به نسخهٔ PHP ندارد. "
        "اگر هاست شما دسترسی خروجی (outbound) را بسته باشد همین خطا را می‌بینید.",
    ),
    "digits": (
        "down",
        "فایل اصلی با ionCube رمزگذاری شده و چون لودر 8.2 نصب نیست، PHP آن را «نویسهٔ ناشناس» می‌بیند و همان خط ۲ متوقف می‌شود؛ "
        "در نتیجه <b>همهٔ صفحه‌ها</b> (حتی پیشخوان) خطای 500 می‌گیرند. این همان چیزی است که شما «دان شدن سایت» می‌نامید.",
    ),
    "cta-button": (
        "down",
        "فایل <span class='kbd'>includes.php</span> با ionCube رمزگذاری شده؛ هنگام لودرِ افزونه، PHP پیام «Script error: the ionCube Loader …» "
        "را چاپ می‌کند و ادامه نمی‌دهد. هر ۱۱ صفحه‌ای که تست شد خطای 500 گرفتند.",
    ),
    "zhaket-updater": (
        "down",
        "فایل <span class='kbd'>vendor/autoload.php</span> با ionCube رمزگذاری شده و همان ابتدای لودِ افزونه صدا زده می‌شود؛ کل سایت 500 می‌شود.",
    ),
    "elementor-pro": (
        "down",
        "فایل <span class='kbd'>abzarwp/abzarwp-v13.php</span> با SourceGuardian رمزگذاری شده و لودر <span class='kbd'>ixed.8.2.ems</span> را می‌خواهد. "
        "صفحه‌ها کد 200 می‌گیرند ولی محتوایشان فقط همان پیام خطاست، یعنی عملاً سایت از دسترس خارج است.",
    ),
    "RTL-CareUnit": (
        "inert",
        "افزونه فعال می‌شود و سایت بالا می‌ماند، اما چون ionCube Loader نصب نیست در بالای همهٔ صفحه‌های مدیریت اخطار می‌دهد: "
        "«آخرین نسخه ionCube Loader روی سرور شما نصب و فعال باشد… افزونه قادر به ارائه خدمات نخواهد بود». "
        "پس سایت دان نمی‌شود، ولی عملاً این افزونه کار نمی‌کند.",
    ),
    "woocommerce-invoice": (
        "inert",
        "برخلاف بقیهٔ افزونه‌های رمزگذاری‌شده، این یکی خوش‌رفتار است: در فایل اصلی بررسی می‌کند "
        "<code>if ( ! function_exists( 'sg_load' ) )</code> و اگر لودر نباشد فقط یک اخطار مدیریتی نشان می‌دهد و <code>return</code> می‌کند. "
        "در تست هم دقیقاً همین اتفاق افتاد: سایت با کد 200 بالا ماند و در بالای همهٔ صفحه‌های مدیریت این پیام آمد — "
        "«<b>هشدار:</b> فعالسازی «فاکتور ووکامرس» انجام نشد. لودر سورس گاردین روی هاست شما فعال نیست…». "
        "پس سایت دان نمی‌شود، ولی خودِ افزونه بی‌کار می‌ماند.",
    ),
    "surenapost": (
        "warn",
        "سایت بالا می‌ماند؛ فقط اخطارهای منسوخ‌شدگیِ «فرستادن null به توابع رشته‌ای» "
        "(<code>strpos()</code> / <code>str_replace()</code>) از طرف این افزونه به هستهٔ وردپرس می‌رسد. "
        "بخش بزرگی از اخطارها هم به‌خاطر نبودِ اینترنت در محیط تست است (وب‌سرویس پست).",
    ),
    "tisacase-hub": (
        "warn",
        "یک اخطار <code>Undefined array key \"screens\"</code> در "
        "<span class='kbd'>includes/class-tsh-admin.php</span>:510؛ در PHP 7 یک Notice بود و از PHP 8 به بعد Warning است. "
        "سایت بالا می‌ماند و فقط لاگ پر می‌شود.",
    ),
    "code-snippets": (
        "warn",
        "اخطارهای <code>Undefined array key</code> در صفحهٔ خوش‌آمدگوییِ افزونه "
        "(<span class='kbd'>php/views/welcome.php</span>) که ریشه‌شان خالی ماندنِ پاسخِ API راهنما به‌خاطر بی‌اینترنتیِ "
        "محیط تست است. روی سایت واقعی با اینترنت احتمالاً دیده نمی‌شود.",
    ),
    "SMSIRApp": (
        "warn",
        "فعال‌سازی به‌خاطر یک اخطار PHP 8 رد شد: <code>Private methods cannot be final as they are never overridden by other classes</code> "
        "در <span class='kbd'>includes/SMSIRAppClass.php</span> خط 42. متد final-private از PHP 8.0 به بعد اخطار می‌دهد. "
        "وقتی <code>display_errors</code> روشن است وردپرس جلوی فعال‌سازی را می‌گیرد («unexpected output»)؛ "
        "در سایت واقعی (نمایش خطا خاموش) فعال می‌ماند و فقط لاگ پر می‌شود.",
    ),
    "snapppay-woocommerce-gateway": (
        "warn",
        "یک اخطار PHP 7.4+ (<code>Trying to access array offset on value of type bool</code> در <span class='kbd'>functions.php</span>:330) "
        "به‌علاوه تعداد زیادی «ایجاد ویژگی پویا» (Dynamic property) که در PHP 8.2 منسوخ شده‌اند. سایت بالا می‌ماند.",
    ),
    "persian-woocommerce-shipping": (
        "warn",
        "سایت بالا می‌ماند؛ فقط اخطارهای منسوخ‌شدگی «ایجاد ویژگی پویا» در کلاس‌های روش‌های حمل‌ونقل دیده شد. "
        "نکتهٔ مهم: خط <code>create_function()</code> در <span class='kbd'>includes/admin/class-settings.php</span>:113 که در تحلیل استاتیک "
        "بحرانی به‌نظر می‌رسید، در عمل اجرا نشد چون هیچ‌کدام از بخش‌های تعریف‌شده توضیح (desc) ندارند و آن شاخه هیچ‌وقت صدا زده نمی‌شود. "
        "با این حال یک بمب ساعتی است: با اولین بخشی که desc بگیرد، سایت فوراً خطای مرگبار می‌گیرد.",
    ),
    "wp-parsidate": (
        "ok",
        "در تست عملی هیچ خطایی نداد. مورد مشکوک تحلیل استاتیک (<code>implode()</code> با آرگومان‌های جابه‌جا در "
        "<span class='kbd'>includes/plugins/wc-gateways/lib/class-rsa.php</span>:218) در مسیرِ درگاه بانکی است و با صفحه‌هایی که باز شد اجرا نشد؛ "
        "اگر از آن درگاه استفاده می‌کنید باید موقع پرداخت هم بررسی شود.",
    ),
    "woo-order-export-lite": (
        "ok",
        "در تست عملی سالم بود. خط <code>@get_magic_quotes_runtime()</code> در PCLZip فقط هنگام ساخت فایل ZIP صدا زده می‌شود؛ "
        "یعنی موقع «خروجی گرفتن دسته‌ای» ممکن است خراب شود، نه در لودِ عادی.",
    ),
}

VERDICT_FA = {
    "ENCODED": "رمزگذاری‌شده — سایت از کار افتاد",
    "LOADER_NOTICE": "لودر ندارد — سایت بالاست، افزونه بی‌کار",
    "CRASH": "خطای مرگبار",
    "NO_ACT": "فعال نشد",
    "WARNINGS": "با اخطار اجرا شد",
    "OK": "سالم",
    "NO_SOURCE": "منبع پیدا نشد",
    "NO_MAIN_FILE": "فایل اصلی پیدا نشد",
}


def load_runtime(paths):
    merged = {}
    for p in paths:
        if not os.path.exists(p):
            continue
        for slug, e in json.load(open(p, encoding="utf-8")).items():
            merged[slug] = e
    return merged


def classify(entry):
    v = entry.get("verdict")
    if v in ("ENCODED", "CRASH"):
        return "down"
    if v == "LOADER_NOTICE":
        return "inert"
    if v == "NO_ACT":
        return "warn"
    if v == "WARNINGS":
        return "warn"
    return "ok"


def main():
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    label = next((a[8:] for a in sys.argv[1:] if a.startswith("--label=")), None)
    runtime = load_runtime(args)
    static = json.load(open(STATIC, encoding="utf-8"))
    smap = {p["slug"]: p for p in static["plugins"]}

    os.makedirs(OUT_DIR, exist_ok=True)
    _phpv = label or (next(iter(runtime.values())).get("php") if runtime else "8.2")
    raw_out = os.path.join(OUT_DIR, "runtime.json" if _phpv == "8.2" else f"runtime-{_phpv}.json")
    json.dump(runtime, open(raw_out, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print("[ok] raw results ->", raw_out)

    rows = []
    for slug, e in sorted(runtime.items(), key=lambda kv: kv[0].lower()):
        st = smap.get(slug, {})
        cls, note = NOTES.get(slug, (None, None))
        if cls is None:
            cls = classify(e)
        c = e["logs"]["counts"]
        rows.append({
            "slug": slug,
            "name": e.get("name") or st.get("name") or slug,
            "version": e.get("version") or st.get("version") or "",
            "cls": cls,
            "note": note or "",
            "runtime_verdict": e.get("verdict"),
            "activated": bool(e.get("activated")),
            "activation_error": e.get("activation_error"),
            "activation_stdout": e.get("activation_stdout"),
            "encoder_message": e.get("encoder_message"),
            "counts": c,
            "probe_fatal": e.get("probe_fatal", []),
            "logs": e.get("logs", {}),
            "static_verdict": st.get("verdict", ""),
            "static_reasons": st.get("reasons", []),
            "requires_plugins": e.get("requires_plugins", []),
            "admin_pages": e.get("admin_pages_menu", []) or e.get("admin_pages_regex", []),
            "duration": e.get("duration_s"),
        })
    rows.sort(key=lambda r: (list(CLASSES).index(r["cls"]), r["slug"].lower()))

    phpv = label or (rows[0].get("php") if rows and rows[0].get("php") else "8.2")
    counts = Counter(r["cls"] for r in rows)
    write_html(rows, counts, static, phpv)
    write_md(rows, counts, static, phpv)
    write_csv(rows, phpv)
    print("[ok] plugins:", len(rows), dict(counts))


# ------------------------------------------------------------------ render
STYLE = """
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
pre{background:#0b1017;border:1px solid var(--line);border-radius:10px;padding:10px 12px;overflow-x:auto;direction:ltr;
font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;line-height:1.6;color:#cfe3ff}
ul.f{margin:6px 0 0 0;padding-right:18px}ul.f li{margin:3px 0;font-size:13.5px}
.toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:14px 0}
input,select{background:var(--card);border:1px solid var(--line);color:var(--txt);border-radius:10px;padding:8px 12px;font-size:14px;font-family:inherit}
button.f{background:var(--card);border:1px solid var(--line);color:var(--txt);border-radius:10px;padding:8px 14px;cursor:pointer;font-family:inherit;font-size:13.5px}
button.f.on{background:#25324a;border-color:#3f6699;color:#cfe3ff}
.note{background:#152033;border:1px solid #24405f;border-radius:14px;padding:14px 16px;margin:14px 0}
.warnbox{background:#2a1219;border:1px solid #7d2233;border-radius:14px;padding:14px 16px;margin:14px 0}
.kbd{background:var(--card2);border:1px solid var(--line);border-radius:6px;padding:1px 6px;font-size:12.5px}
.st{font-size:12.5px;color:var(--muted)}
"""

SCRIPT = """
const q=document.getElementById('q');const rows=[...document.querySelectorAll('tr.row')];
let cls='';
function apply(){const t=(q.value||'').trim().toLowerCase();
 rows.forEach(r=>{const ok=(!cls||r.dataset.cls===cls)&&(!t||r.dataset.search.includes(t));r.style.display=ok?'':'none';});}
q.addEventListener('input',apply);
function setC(c){cls=c;document.querySelectorAll('button.f').forEach(b=>b.classList.toggle('on',b.dataset.v===c));apply();}
"""


def esc(s):
    return html.escape(str(s or ""), quote=True)


def badge(cls):
    label = CLASSES[cls][0]
    return f'<span class="verdict v-{CLASSES[cls][1]}">{label}</span>'


def stat_badge(v):
    m = {"بحرانی": "critical", "پرخطر": "high", "نیاز به توجه": "medium",
         "تقریباً سالم": "info", "سالم": "ok"}
    return f'<span class="verdict v-{m.get(v,"info")}">{esc(v or "—")}</span>'


def write_html(rows, counts, static, phpv="8.2"):
    down = [r for r in rows if r["cls"] == "down"]
    inert = [r for r in rows if r["cls"] == "inert"]
    warn = [r for r in rows if r["cls"] == "warn"]
    envp = [r for r in rows if r["cls"] == "env"]

    down_names = "، ".join(f"<b>{esc(r['name'])}</b>" for r in down) or "—"
    inert_names = "، ".join(f"<b>{esc(r['name'])}</b>" for r in inert) or "—"

    h = []
    h.append('<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">')
    h.append('<meta name="viewport" content="width=device-width,initial-scale=1">')
    h.append(f'<title>گزارش تست عملی سازگاری با PHP {phpv}</title><style>' + STYLE + '</style></head><body><div class="wrap">')
    h.append(f'<h1>تست عملی روی وردپرس واقعی با PHP {phpv}</h1>')
    h.append(f'<p class="muted">{len(rows)} افزونه · نصب و فعال‌سازی روی WordPress 6.9.11 · '
             'موتور PHP 8.2.33 · ووکامرس به‌عنوان پایه فعال بود · هر افزونه روی صفحهٔ اصلی، پیشخوان، '
             'فهرست افزونه‌ها، تنظیمات، تنظیمات ووکامرس و صفحات اختصاصی خودش باز شد.</p>')

    h.append('<div class="warnbox"><b>جواب کوتاه:</b> در عمل <b>%d افزونه</b> سایت را کاملاً از کار می‌اندازند: %s. '
             'و <b>%d افزونه</b> سایت را بالا نگه می‌دارند ولی خودشان بی‌کار می‌مانند: %s. '
             'بقیهٔ افزونه‌ها روی PHP 8.2 بالا آمدند.'
             % (len(down), down_names, len(inert), inert_names))
    if down:
        h.append('<p style="margin-bottom:0">یعنی اگر فقط همین افزونه‌ها را غیرفعال کنید (پوشه‌شان را تغییر نام بدهید)، '
                 'سایت شما روی 8.2 بالا می‌آید و بعد می‌توانید بقیه را یکی‌یکی برگردانید.</p>')
    h.append('</div>')

    h.append('<div class="cards">')
    for cls, (label, key) in CLASSES.items():
        h.append(f'<div class="card"><div class="n" style="color:var(--{key if key!="info" else "blue"})">'
                 f'{counts.get(cls,0)}</div><div class="l">{label}</div></div>')
    h.append('</div>')

    h.append('<h2>۱) افزونه‌هایی که سایت را از کار می‌اندازند</h2>')
    if down:
        h.append('<table><tr><th>افزونه</th><th>نسخه</th><th>چه اتفاقی افتاد</th><th>صفحه‌های خراب</th></tr>')
        for r in down:
            pages = len(r["probe_fatal"])
            h.append(f'<tr><td><b>{esc(r["name"])}</b><br><span class="st">{esc(r["slug"])}</span></td>'
                     f'<td>{esc(r["version"])}</td>'
                     f'<td>{first_error(r)}</td>'
                     f'<td>{pages} صفحه</td></tr>')
        h.append('</table>')
    else:
        h.append('<p>هیچ افزونه‌ای سایت را از کار نینداخت.</p>')

    h.append('<h2>۲) افزونه‌هایی که بالا می‌مانند ولی بی‌کار می‌شوند</h2>')
    if inert:
        h.append('<table><tr><th>افزونه</th><th>نسخه</th><th>پیام</th></tr>')
        for r in inert:
            h.append(f'<tr><td><b>{esc(r["name"])}</b><br><span class="st">{esc(r["slug"])}</span></td>'
                     f'<td>{esc(r["version"])}</td><td>{esc((r["encoder_message"] or "")[:300])}</td></tr>')
        h.append('</table>')
    else:
        h.append('<p>موردی نبود.</p>')

    h.append('<h2>۳) جدول کامل نتایج</h2>')
    h.append('<div class="toolbar"><input id="q" placeholder="جستجو در نام، پوشه، پیام خطا…" style="min-width:280px">')
    for cls, (label, _) in CLASSES.items():
        h.append(f'<button class="f" data-v="{cls}" onclick="setC(\'{cls}\')">{label} ({counts.get(cls,0)})</button>')
    h.append('<button class="f on" data-v="" onclick="setC(\'\')">همه</button></div>')
    h.append('<table><thead><tr><th>وضعیت</th><th>افزونه</th><th>نسخه</th><th>فعال شد</th>'
             '<th>خطای مرگبار</th><th>منسوخ/اخطار</th><th>تحلیل استاتیک</th></tr></thead><tbody>')
    for r in rows:
        c = r["counts"]
        search = " ".join([r["slug"], r["name"], r["runtime_verdict"] or "", r["static_verdict"],
                           (r["encoder_message"] or ""), first_error(r)]).lower().replace('"', '')
        h.append(f'<tr class="row" data-cls="{r["cls"]}" data-search="{esc(search)}" onclick="open_(\'{r["slug"]}\')">')
        h.append(f'<td>{badge(r["cls"])}</td>')
        h.append(f'<td><b>{esc(r["name"])}</b><br><span class="st">{esc(r["slug"])}</span></td>')
        h.append(f'<td>{esc(r["version"])}</td>')
        h.append(f'<td>{"بله" if r["activated"] else "خیر"}</td>')
        h.append(f'<td>{c.get("fatal",0)+c.get("parse",0)}</td>')
        h.append(f'<td>{c.get("deprecated",0)} / {c.get("warning",0)}</td>')
        h.append(f'<td>{stat_badge(r["static_verdict"])}</td>')
        h.append('</tr>')
    h.append('</tbody></table>')
    h.append('<p class="muted">برای دیدن جزئیات هر افزونه روی ردیفش کلیک کنید.</p>')

    h.append('<h2>۴) جزئیات هر افزونه</h2>')
    for r in rows:
        h.append(f'<details id="p-{r["slug"]}"><summary>{badge(r["cls"])} '
                 f'{esc(r["name"])} <span class="badge">{esc(r["slug"])}</span> '
                 f'<span class="badge">v{esc(r["version"])}</span></summary><div class="body">')
        h.append(f'<p class="muted">نتیجهٔ ماشین: {esc(VERDICT_FA.get(r["runtime_verdict"], r["runtime_verdict"]))} · '
                 f'فعال‌سازی: {"موفق" if r["activated"] else "ناموفق"}'
                 f'{(" · دلیل: " + esc(r["activation_error"])) if r["activation_error"] else ""} · '
                 f'تحلیل استاتیک قبلی: {esc(r["static_verdict"])}</p>')
        if r["note"]:
            h.append(f'<div class="reason r-{CLASSES[r["cls"]][1] if CLASSES[r["cls"]][1]!="info" else "info"}">'
                     f'<div class="t">توضیح</div>{r["note"]}</div>')
        c = r["counts"]
        if c.get("fatal") or c.get("parse") or c.get("deprecated") or c.get("warning"):
            h.append('<div class="reason"><div class="t">لاگ خطا در طول تست</div>'
                     f'مرگبار: {c.get("fatal",0)} · خطای نحو: {c.get("parse",0)} · '
                     f'اخطار: {c.get("warning",0)} · منسوخ‌شده: {c.get("deprecated",0)}</div>')
        for l in (r["logs"].get("fatal") or [])[:3]:
            h.append(f'<pre>{esc(l)}</pre>')
        for l in (r["logs"].get("parse") or [])[:2]:
            h.append(f'<pre>{esc(l)}</pre>')
        if r["encoder_message"]:
            h.append(f'<div class="reason r-critical"><div class="t">پیام لودر</div><pre>{esc(r["encoder_message"])}</pre></div>')
        if r["activation_stdout"]:
            h.append(f'<div class="reason r-medium"><div class="t">خروجی هنگام فعال‌سازی</div>'
                     f'<pre>{esc(r["activation_stdout"])}</pre></div>')
        if r["probe_fatal"]:
            h.append('<div class="reason"><div class="t">صفحه‌هایی که خطا دادند</div><ul class="f">')
            for p in r["probe_fatal"][:6]:
                h.append(f'<li><span class="kbd">{esc(p["path"])}</span> → کد {p["status"]}<br>'
                         f'<pre style="margin:6px 0 0">{esc((p["snippet"] or "")[:400])}</pre></li>')
            h.append('</ul></div>')
        if r["logs"].get("deprecated"):
            h.append('<div class="reason"><div class="t">نمونه اخطارهای منسوخ‌شده</div><ul class="f">')
            for l in r["logs"]["deprecated"][:4]:
                h.append(f'<li><code>{esc(l[:220])}</code></li>')
            h.append('</ul></div>')
        h.append('</div></details>')

    h.append('<h2>۵) مقایسه با تحلیل استاتیک</h2>')
    h.append('<p>تحلیل استاتیک (اسکن کد) همهٔ مسیرهای اجرا را نمی‌بیند و تست عملی هم فقط صفحه‌هایی را باز می‌کند که '
             'ما می‌شناسیم. جدول زیر جاهایی را نشان می‌دهد که این دو نظر متفاوتی داشتند:</p>')
    h.append('<table><tr><th>افزونه</th><th>نظر تحلیل استاتیک</th><th>آنچه در عمل دیده شد</th></tr>')
    for r in rows:
        sv = r["static_verdict"]
        cls = r["cls"]
        diff = (sv in ("بحرانی", "پرخطر") and cls in ("ok", "warn", "env")) or \
               (sv in ("سالم", "تقریباً سالم") and cls in ("down", "inert"))
        if diff:
            h.append(f'<tr><td><b>{esc(r["name"])}</b><br><span class="st">{esc(r["slug"])}</span></td>'
                     f'<td>{stat_badge(sv)}</td>'
                     f'<td>{badge(cls)} — {esc(short_reason(r))}</td></tr>')
    h.append('</table>')

    h.append('<h2>۶) روش تست و محدودیت‌ها</h2>')
    h.append('<ul class="f">'
             '<li>وردپرس 6.9.11 نصب شد و هر افزونه با ووکامرسِ فعال، یکی‌یکی کپی، فعال و سپس تست شد.</li>'
             '<li>برای هر افزونه این صفحه‌ها باز شد: صفحهٔ اصلی، پیشخوان، فهرست افزونه‌ها، تنظیمات عمومی، '
             'فهرست محصولات، تنظیمات ووکامرس، تنظیمات حمل‌ونقل، و همهٔ صفحه‌هایی که خود افزونه به منوی مدیریت اضافه می‌کند.</li>'
             '<li>همچنین حالت «افزونه از قبل فعال است» شبیه‌سازی شد (درج مستقیم در <span class="kbd">active_plugins</span>) '
             'چون سناریوی شما دقیقاً همین است: سایت روشن است و بعد PHP عوض می‌شود.</li>'
             '<li>در محیط تست هیچ لودر ionCube / SourceGuardian ای نصب نیست — یعنی همان وضعیتی که اگر هاست شما '
             'لودرِ مخصوص 8.2 را نصب نکرده باشد دارید. اگر هاست لودر را نصب کند، آن افزونه‌ها درست کار می‌کنند.</li>'
             '<li>موتور PHP در این محیط WebAssembly است (همان نسخهٔ 8.2.33) و به‌جای MySQL از دیتابیس SQLite استفاده شد؛ '
             'بنابراین خطاهایی که فقط در MySQL رخ می‌دهند دیده نشدند (آن‌ها را جداگانه به‌عنوان خطای دیتابیس فیلتر کردیم).</li>'
             '<li>دسترسی به اینترنت نداشتیم؛ هر جا افزونه‌ای به API خارجی وابسته بود (مثل صفحهٔ خانهٔ المنتور) '
             'خطایش را جداگانه علامت زدیم.</li>'
             '<li>تست عملی فقط صفحه‌هایی را پوشش می‌دهد که باز شدند؛ مسیرهایی مثل پرداخت، ارسال پیامک یا '
             'خروجی گرفتن فایل هنوز باید روی سایت واقعی چک شوند.</li>'
             '</ul>')
    h.append('<h2>۷) پیشنهاد عملی</h2>')
    h.append('<ol class="f">'
             '<li>پوشه‌های این افزونه‌ها را با FTP به نام‌های <span class="kbd">digits</span>، '
             '<span class="kbd">cta-button</span>، <span class="kbd">zhaket-updater</span>، '
             '<span class="kbd">elementor-pro</span> تغییر نام بدهید (مثلاً به <span class="kbd">digits.off</span>).</li>'
             '<li>سایت را روی PHP 8.2 بگذارید؛ باید بالا بیاید.</li>'
             '<li>برای هر کدام از این چهار افزونه از فروشنده/هاست نسخهٔ سازگار یا لودر 8.2 را بگیرید.</li>'
             '<li>RTL-CareUnit و فاکتور حرفه‌ای ووکامرس را هم یا لودر بگیرید یا با جایگزینِ بدون رمزگذاری عوض کنید.</li>'
             '<li>برای SMSIRApp و اسنپ‌پی، نمایش خطا را در محیط production خاموش نگه دارید و اخطارها را در لاگ پیگیری کنید.</li>'
             '</ol>')
    h.append('</div><script>' + SCRIPT + """
function open_(slug){const d=document.getElementById('p-'+slug);if(!d)return;d.open=true;d.scrollIntoView({behavior:'smooth',block:'start'});}
""")
    h.append('</script></body></html>')

    out = os.path.join(REPO, f"گزارش-تست-عملی-PHP-{phpv}.html")
    open(out, "w", encoding="utf-8").write("\n".join(h))
    print("[ok]", out, os.path.getsize(out), "bytes")


def first_error(r):
    if r["encoder_message"]:
        return esc(r["encoder_message"][:180])
    if r["logs"].get("fatal"):
        return f'<code>{esc(r["logs"]["fatal"][0][:180])}</code>'
    if r["logs"].get("parse"):
        return f'<code>{esc(r["logs"]["parse"][0][:180])}</code>'
    if r["probe_fatal"]:
        return f'<code>{esc((r["probe_fatal"][0]["snippet"] or "")[:180])}</code>'
    if r["activation_stdout"]:
        return f'<code>{esc(r["activation_stdout"][:180])}</code>'
    return '<span class="muted">—</span>'


def short_reason(r):
    if r["encoder_message"]:
        return r["encoder_message"][:120]
    if r["logs"].get("fatal"):
        return r["logs"]["fatal"][0][:160]
    if r["activation_stdout"]:
        return r["activation_stdout"][:160]
    return VERDICT_FA.get(r["runtime_verdict"], "")


def write_md(rows, counts, static, phpv="8.2"):
    o = []
    o.append(f"# گزارش تست عملی سازگاری با PHP {phpv}\n")
    o.append("تست واقعی روی WordPress 6.9.11 با موتور PHP 8.2.33 (ووکامرس فعال، هر افزونه یکی‌یکی نصب، "
             "فعال و روی صفحه‌های اصلی/مدیریت باز شد).\n")
    o.append("## خلاصه\n")
    for cls, (label, _) in CLASSES.items():
        o.append(f"- {label}: **{counts.get(cls,0)}**")
    o.append("")
    for cls in ("down", "inert", "warn", "env"):
        items = [r for r in rows if r["cls"] == cls]
        if not items:
            continue
        o.append(f"\n## {CLASSES[cls][0]} ({len(items)})\n")
        for r in items:
            o.append(f"### {r['name']} (`{r['slug']}` · نسخه {r['version']})\n")
            if r["note"]:
                import re
                txt = re.sub(r"<[^>]+>", "", r["note"]).replace("&nbsp;", " ")
                o.append(txt + "\n")
            err = short_reason(r)
            if err:
                o.append("```\n" + err[:600] + "\n```\n")
            if r["probe_fatal"]:
                o.append("صفحه‌های خراب: " + "، ".join(f"`{p['path']}` ({p['status']})" for p in r["probe_fatal"][:5]) + "\n")
    o.append("\n## جدول کامل\n")
    o.append("| وضعیت | افزونه | پوشه | نسخه | فعال شد | مرگبار | منسوخ | اخطار | تحلیل استاتیک |")
    o.append("|---|---|---|---|---|---|---|---|---|")
    for r in rows:
        c = r["counts"]
        o.append(f"| {CLASSES[r['cls']][0]} | {r['name']} | `{r['slug']}` | {r['version']} | "
                 f"{'بله' if r['activated'] else 'خیر'} | {c.get('fatal',0)+c.get('parse',0)} | "
                 f"{c.get('deprecated',0)} | {c.get('warning',0)} | {r['static_verdict']} |")
    o.append("\n## روش تست و محدودیت‌ها\n")
    o.append("- وردپرس 6.9.11 + PHP 8.2.33؛ ووکامرس به‌عنوان پایه فعال بود.\n"
             "- برای هر افزونه: کپی → فعال‌سازی → درج در `active_plugins` (شبیه‌سازی «افزونه از قبل فعال است») → "
             "باز کردن صفحهٔ اصلی، پیشخوان، فهرست افزونه‌ها، تنظیمات، محصولات، تنظیمات ووکامرس، حمل‌ونقل و صفحات اختصاصی خود افزونه "
             "→ خواندن debug.log → غیرفعال‌سازی و حذف.\n"
             "- هیچ لودر ionCube / SourceGuardian در محیط تست نصب نیست (همان وضعیت هاستی که لودر 8.2 ندارد).\n"
             "- اینترنت نداشتیم و به‌جای MySQL از SQLite استفاده شد؛ خطاهای وابسته به این دو فیلتر شدند.\n"
             "- مسیرهایی مثل پرداخت آنلاین، ارسال پیامک و خروجی فایل هنوز روی سایت واقعی باید چک شوند.\n")
    out = os.path.join(REPO, f"گزارش-تست-عملی-PHP-{phpv}.md")
    open(out, "w", encoding="utf-8").write("\n".join(o))
    print("[ok]", out, os.path.getsize(out), "bytes")


def write_csv(rows, phpv="8.2"):
    out = os.path.join(REPO, f"نتایج-تست-عملی-{phpv}.csv")
    with open(out, "w", encoding="utf-8-sig", newline="") as fh:
        w = csv.writer(fh)
        w.writerow(["وضعیت اجرا", "نام افزونه", "پوشه", "نسخه", "فعال شد", "خطای فعال‌سازی",
                    "خطای مرگبار", "خطای نحو", "منسوخ‌شده", "اخطار", "صفحه‌های خراب",
                    "نتیجهٔ ماشین", "تحلیل استاتیک", "نخستین خطا"])
        for r in rows:
            c = r["counts"]
            w.writerow([CLASSES[r["cls"]][0], r["name"], r["slug"], r["version"],
                        "بله" if r["activated"] else "خیر", r["activation_error"] or "",
                        c.get("fatal", 0), c.get("parse", 0), c.get("deprecated", 0), c.get("warning", 0),
                        len(r["probe_fatal"]), VERDICT_FA.get(r["runtime_verdict"], r["runtime_verdict"]),
                        r["static_verdict"], short_reason(r)[:400]])
    print("[ok]", out, os.path.getsize(out), "bytes")


if __name__ == "__main__":
    main()
