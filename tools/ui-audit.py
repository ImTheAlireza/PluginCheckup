#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
ui-audit — چک‌لیست پذیرشِ فاز ۴ را مکانیکی می‌کند.

    python3 tools/ui-audit.py plugins/src/<plugin-dir>

قانون‌ها (از PLUGIN_REDESIGN.md):
  hex      رنگ هاردکد بیرون از fallbackِ var(--x, #fff)
  radius   border-radius: <px> هاردکد
  fsize    font-size: <px> هاردکد
  cdn      ارجاع به CDN/قلم بیرونی
  inline   style="… تزئینی در PHP (مقدار پویا مجاز است: ٪ ، px پویا، top/left)
  mono     استفاده از قلم مونو روی چیزی که بوی «مبلغ/عدد» می‌دهد (هشدار، دستی بررسی شود)

خروجی: صفر خطا = آمادهٔ زیپ‌سازی. با --strict اگر هشداری هم باشد کد خروجی ۱ است.
"""
import io
import os
import re
import subprocess
import sys

ASSET_EXT = (".css", ".scss")
SRC_EXT = (".php", ".css", ".scss", ".js")
VAR_FALLBACK = re.compile(r"var\(\s*--[\w-]+\s*,[^()]*(?:\([^()]*\)[^()]*)*\)")
HEX = re.compile(r"#[0-9a-fA-F]{3,8}\b")
RADIUS = re.compile(r"border-radius:\s*\d*\.?\d+px")
FSIZE = re.compile(r"font-size:\s*\d*\.?\d+px")
CDN = re.compile(r"(fonts\.googleapis|fonts\.gstatic|cdn\.jsdelivr|cdnjs|unpkg\.com|bootstrapcdn)", re.I)
INLINE = re.compile(r'style="([^"]*)"')
DYNAMIC = re.compile(r"%|<\?php|px;|top:|left:|right:|bottom:|width|height|z-index|^display:\s*none;?$")
MONO_ON_NUM = re.compile(r"(money|price|amount|total|count|qty|quantity)[^;{]*\{[^}]*mono", re.I)


def walk(root):
    for dirpath, dirs, files in os.walk(root):
        dirs[:] = [d for d in dirs if d not in ("node_modules", ".git", "__MACOSX")]
        for fn in files:
            if fn.endswith(SRC_EXT):
                yield os.path.join(dirpath, fn)


def main():
    if len(sys.argv) < 2 or not os.path.isdir(sys.argv[1]):
        print("مصرف: python3 tools/ui-audit.py plugins/src/<plugin-dir>", file=sys.stderr)
        return 2
    root = sys.argv[1].rstrip("/")
    strict = "--strict" in sys.argv
    found = {}

    for path in walk(root):
        try:
            text = io.open(path, encoding="utf-8", errors="ignore").read()
        except OSError:
            continue
        rel = os.path.relpath(path, root)
        css = path.endswith(ASSET_EXT)

        if css:
            stripped = VAR_FALLBACK.sub("", text)  # fallback های var() مجازند
            for m in HEX.finditer(stripped):
                line = stripped.count("\n", 0, m.start()) + 1
                found.setdefault("hex", []).append(f"{rel}:{line} {m.group(0)}")
            for rx, key in ((RADIUS, "radius"), (FSIZE, "fsize")):
                for m in rx.finditer(text):
                    line = text.count("\n", 0, m.start()) + 1
                    found.setdefault(key, []).append(f"{rel}:{line} {m.group(0)}")
            if MONO_ON_NUM.search(text):
                found.setdefault("mono", []).append(rel + " مونو روی ستون عددی/مبلغ — دستی بررسی شود")

        if path.endswith(".php"):
            for m in INLINE.finditer(text):
                val = m.group(1).strip()
                if not val or DYNAMIC.search(val):
                    continue
                line = text.count("\n", 0, m.start()) + 1
                found.setdefault("inline", []).append(f'{rel}:{line} style="{val}"')

        if path.endswith((".php", ".css", ".scss", ".js")) and CDN.search(text):
            for m in CDN.finditer(text):
                line = text.count("\n", 0, m.start()) + 1
                found.setdefault("cdn", []).append(f"{rel}:{line} {m.group(0)}")

    keys = ["hex", "radius", "fsize", "cdn", "inline", "mono"]
    bad = 0
    for k in keys:
        items = found.get(k, [])
        if not items:
            print(f"  ✓ {k:7s} پاک")
            continue
        hard = k not in ("mono",)
        bad += len(items) if hard else 0
        print(f"  {'✗' if hard else '!'} {k:7s} {len(items)} مورد")
        for it in items[:12]:
            print(f"      {it}")
        if len(items) > 12:
            print(f"      … و {len(items)-12} مورد دیگر")

    # بررسی ساختاری PHP/JS با ابزار موجود
    for cmd, label in ((["python3", "tools/php-check.py", root], "php-check"),):
        r = subprocess.run(cmd, capture_output=True, text=True, cwd=os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
        ok = r.returncode == 0
        bad += 0 if ok else 1
        print(f"  {'✓' if ok else '✗'} {label:7s} " + ("" if ok else r.stdout.strip()))

    print(("\nآمادهٔ بسته‌بندی: ./tools/build-plugin-zip.sh %s" % os.path.basename(root)) if bad == 0
          else f"\n{bad} مورد باید درست شود.")
    if strict and found.get("mono"):
        return 1
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main())
