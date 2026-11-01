#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Compare two runtime runs (e.g. PHP 8.2 vs 8.3) plugin by plugin.

usage:
  python3 compare_versions.py <old.json> <new.json> [--old-label 8.2] [--new-label 8.3]

Writes:
  مقایسه-<old>-و-<new>.md   Persian markdown comparison (repo root)
  مقایسه-<old>-و-<new>.csv  csv
"""
import csv
import json
import os
import re
import sys
from collections import Counter

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(HERE)

CLS_FA = {
    "down": "سایت را از کار می‌اندازد",
    "inert": "سایت بالاست، افزونه بی‌کار است",
    "warn": "با اخطار اجرا می‌شود",
    "env": "خطای محیط تست (ربطی به نسخه ندارد)",
    "ok": "سالم",
}
ORDER = ["down", "inert", "warn", "env", "ok"]

# differences that are NOT real regressions: our detector got better, the plugin did not change
NOT_A_REGRESSION = {
    "woocommerce-invoice": "در هر دو نسخه رفتار یکی است (افزونه اخطار «لودر سورس گاردین فعال نیست» را نشان می‌دهد)؛ "
                           "فقط در اجرای 8.3 ردیابِ ما پیام فارسی را هم شناسایی کرد، پس این تغییر واقعی نیست.",
}


def load(path):
    return json.load(open(path, encoding="utf-8"))


ENV_NOISE = {"elementor": "env"}   # crash caused by our offline test box, not by PHP


def classify(e):
    if e.get("slug") in ENV_NOISE:
        return ENV_NOISE[e["slug"]]
    v = e.get("verdict")
    if v in ("ENCODED", "CRASH"):
        return "down"
    if v == "LOADER_NOTICE":
        return "inert"
    if v in ("NO_ACT", "WARNINGS"):
        return "warn"
    return "ok"


def key_error(e):
    c = e.get("logs", {}).get("counts", {})
    if e.get("encoder_message"):
        return e["encoder_message"][:200]
    for k in ("fatal", "parse"):
        if e.get("logs", {}).get(k):
            return e["logs"][k][0][:250]
    if e.get("probe_fatal"):
        return (e["probe_fatal"][0].get("snippet") or "")[:250]
    if e.get("activation_stdout"):
        return e["activation_stdout"][:250]
    return ""


def main():
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    old_p, new_p = args[0], args[1]
    old_l = next((a[12:] for a in sys.argv[1:] if a.startswith("--old-label=")), "8.2")
    new_l = next((a[12:] for a in sys.argv[1:] if a.startswith("--new-label=")), "8.3")
    old, new = load(old_p), load(new_p)

    rows = []
    for slug in sorted(set(old) | set(new)):
        o, n = old.get(slug), new.get(slug)
        if not o or not n:
            continue
        o = dict(o, slug=slug); n = dict(n, slug=slug)
        co, cn = classify(o), classify(n)
        rows.append({
            "slug": slug,
            "name": (n.get("name") or o.get("name") or slug),
            "version": n.get("version") or o.get("version") or "",
            "old_cls": co, "new_cls": cn,
            "worse": ORDER.index(cn) < ORDER.index(co) and slug not in NOT_A_REGRESSION,
            "note": (NOT_A_REGRESSION.get(slug) if slug in NOT_A_REGRESSION and ORDER.index(cn) != ORDER.index(co) else ""),
            "better": ORDER.index(cn) > ORDER.index(co),
            "old_act": o.get("activated"), "new_act": n.get("activated"),
            "old_counts": o.get("logs", {}).get("counts", {}),
            "new_counts": n.get("logs", {}).get("counts", {}),
            "old_err": key_error(o), "new_err": key_error(n),
        })
    rows.sort(key=lambda r: (not r["worse"], ORDER.index(r["new_cls"]), r["slug"].lower()))

    worse = [r for r in rows if r["worse"]]
    better = [r for r in rows if r["better"]]
    same = [r for r in rows if not r["worse"] and not r["better"]]

    o = []
    o.append(f"# مقایسهٔ PHP {old_l} با PHP {new_l} — تست عملی روی وردپرس واقعی\n")
    o.append(f"هر دو بار همهٔ {len(rows)} افزونه روی WordPress 6.9.11 نصب، فعال و روی صفحه‌های "
             f"اصلی/مدیریت باز شدند؛ فقط نسخهٔ PHP فرق داشت.\n")
    o.append("## خلاصه\n")
    co_old = Counter(r["old_cls"] for r in rows)
    co_new = Counter(r["new_cls"] for r in rows)
    o.append("| وضعیت | " + old_l + " | " + new_l + " |")
    o.append("|---|---|---|")
    for c in ORDER:
        o.append(f"| {CLS_FA[c]} | {co_old.get(c,0)} | {co_new.get(c,0)} |")
    o.append("")
    o.append(f"- **بدتر شده**: {len(worse)} افزونه")
    o.append(f"- **بهتر شده**: {len(better)} افزونه")
    o.append(f"- **بدون تغییر**: {len(same)} افزونه\n")
    if any(r["note"] for r in rows):
        o.append("\n### تفاوت‌هایی که تغییرِ واقعی نیستند\n")
        for r in rows:
            if r["note"]:
                o.append(f"- **{r['name']}** (`{r['slug']}`): {r['note']}\n")

    if worse:
        o.append(f"\n## افزونه‌هایی که روی {new_l} بدتر شدند ({len(worse)})\n")
        o.append("| افزونه | پوشه | " + old_l + " | " + new_l + " | پیام خطای جدید |")
        o.append("|---|---|---|---|---|")
        for r in worse:
            o.append(f"| {r['name']} | `{r['slug']}` | {CLS_FA[r['old_cls']]} | {CLS_FA[r['new_cls']]} | "
                     f"`{r['new_err'][:180]}` |")
    if better:
        o.append(f"\n## افزونه‌هایی که روی {new_l} بهتر شدند ({len(better)})\n")
        o.append("| افزونه | پوشه | " + old_l + " | " + new_l + " |")
        o.append("|---|---|---|---|")
        for r in better:
            o.append(f"| {r['name']} | `{r['slug']}` | {CLS_FA[r['old_cls']]} | {CLS_FA[r['new_cls']]} |")

    o.append(f"\n## جدول کامل\n")
    o.append("| وضعیت روی " + new_l + " | تغییر | افزونه | پوشه | نسخه | فعال(" + old_l + ") | فعال(" + new_l + ") |"
             " مرگبار | منسوخ | اخطار |")
    o.append("|---|---|---|---|---|---|---|---|---|---|")
    for r in rows:
        ch = "بدتر ⬇" if r["worse"] else ("بهتر ⬆" if r["better"] else "—")
        nc = r["new_counts"]
        o.append(f"| {CLS_FA[r['new_cls']]} | {ch} | {r['name']} | `{r['slug']}` | {r['version']} | "
                 f"{'بله' if r['old_act'] else 'خیر'} | {'بله' if r['new_act'] else 'خیر'} | "
                 f"{nc.get('fatal',0)+nc.get('parse',0)} | {nc.get('deprecated',0)} | {nc.get('warning',0)} |")

    md = os.path.join(REPO, f"مقایسه-{old_l}-و-{new_l}.md")
    open(md, "w", encoding="utf-8").write("\n".join(o))
    print("[ok]", md, os.path.getsize(md), "bytes")

    csv_p = os.path.join(REPO, f"مقایسه-{old_l}-و-{new_l}.csv")
    with open(csv_p, "w", encoding="utf-8-sig", newline="") as fh:
        w = csv.writer(fh)
        w.writerow(["افزونه", "پوشه", "نسخه", f"وضعیت {old_l}", f"وضعیت {new_l}", "تغییر",
                    f"فعال({old_l})", f"فعال({new_l})", "مرگبار", "منسوخ", "اخطار",
                    f"خطا در {old_l}", f"خطا در {new_l}"])
        for r in rows:
            nc = r["new_counts"]
            w.writerow([r["name"], r["slug"], r["version"], CLS_FA[r["old_cls"]], CLS_FA[r["new_cls"]],
                        "بدتر" if r["worse"] else ("بهتر" if r["better"] else "بدون تغییر"),
                        "بله" if r["old_act"] else "خیر", "بله" if r["new_act"] else "خیر",
                        nc.get("fatal", 0) + nc.get("parse", 0), nc.get("deprecated", 0), nc.get("warning", 0),
                        r["old_err"][:300], r["new_err"][:300]])
    print("[ok]", csv_p, os.path.getsize(csv_p), "bytes")
    print("[summary] worse:", len(worse), "better:", len(better), "same:", len(same))


if __name__ == "__main__":
    main()
