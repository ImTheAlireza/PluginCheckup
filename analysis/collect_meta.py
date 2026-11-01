#!/usr/bin/env python3
"""Collect metadata for every extracted WordPress plugin zip.

For each plugin directory we dig out:
  - plugin headers from the main plugin file (name, version, Requires PHP, ...)
  - readme.txt metadata (Requires at least / Tested up to / Requires PHP / Stable tag)
  - composer.json php requirement (if the plugin ships one)
  - size facts: php file count, php line count, bundled vendor dirs
  - newest file mtime inside the plugin (a rough "last touched" signal)
"""
import json
import os
import re
import sys
import zipfile
from datetime import datetime, timezone

ROOT = sys.argv[1] if len(sys.argv) > 1 else "/tmp/plugins"
ZIPDIR = sys.argv[2] if len(sys.argv) > 2 else "/home/user/PluginCheckup"
OUT = sys.argv[3] if len(sys.argv) > 3 else "/tmp/scan/meta.json"

HEADER_RE = re.compile(r"^[ \t\/*#@]*([A-Za-z][A-Za-z0-9 _\-]*?)\s*:\s*(.*)$")


def parse_headers(text, limit=8192):
    head = text[:limit]
    out = {}
    for line in head.splitlines():
        m = HEADER_RE.match(line)
        if m:
            key = m.group(1).strip()
            val = m.group(2).strip().strip("*/").strip()
            if key not in out and val:
                out[key] = val
    return out


def read_text(path, limit=None):
    try:
        with open(path, "r", encoding="utf-8", errors="replace") as fh:
            return fh.read() if limit is None else fh.read(limit)
    except OSError:
        return ""


def find_main_file(pdir, slug):
    """Heuristic: the php file that carries the `Plugin Name:` header."""
    candidates = []
    for dirpath, dirnames, filenames in os.walk(pdir):
        dirnames[:] = [d for d in dirnames if d not in ("node_modules", ".git")]
        for fn in filenames:
            if not fn.endswith(".php"):
                continue
            full = os.path.join(dirpath, fn)
            try:
                if os.path.getsize(full) > 4_000_000:
                    continue
            except OSError:
                continue
            txt = read_text(full, 8192)
            if "Plugin Name:" in txt:
                depth = full[len(pdir):].count(os.sep)
                score = (depth, 0 if fn.lower().startswith(slug.lower()) else 1, len(fn))
                candidates.append((score, full))
    if not candidates:
        return None
    candidates.sort(key=lambda c: c[0])
    return candidates[0][1]


def wc_headers_from_readme(txt):
    out = {}
    for line in txt.splitlines()[:120]:
        m = re.match(r"^(Requires at least|Tested up to|Requires PHP|Stable tag|WC requires at least|WC tested up to)\s*:\s*(.+)$",
                     line, re.I)
        if m:
            out[m.group(1).lower()] = m.group(2).strip()
    return out


def newest_mtime(pdir):
    newest = 0
    for dirpath, dirnames, filenames in os.walk(pdir):
        for fn in filenames:
            try:
                st = os.stat(os.path.join(dirpath, fn))
            except OSError:
                continue
            if st.st_mtime > newest:
                newest = st.st_mtime
    return newest


def main():
    meta = {}
    for slug in sorted(os.listdir(ROOT)):
        pdir = os.path.join(ROOT, slug)
        if not os.path.isdir(pdir):
            continue
        info = {"slug": slug, "dir": pdir}

        # zip level facts
        zip_path = None
        for cand in (os.path.join(ZIPDIR, slug + ".zip"), os.path.join(ZIPDIR, slug + " (1).zip")):
            if os.path.exists(cand):
                zip_path = cand
                break
        if zip_path:
            info["zip"] = os.path.basename(zip_path)
            try:
                with zipfile.ZipFile(zip_path) as zf:
                    dates = [datetime(*d).replace(tzinfo=timezone.utc).timestamp()
                             for d in (i.date_time for i in zf.infolist())]
                    info["zip_newest_file"] = max(dates) if dates else None
                    info["zip_oldest_file"] = min(dates) if dates else None
                    info["zip_entries"] = len(zf.infolist())
            except (zipfile.BadZipFile, OSError) as exc:
                info["zip_error"] = str(exc)

        # code facts
        php_files = 0
        php_lines = 0
        vendor_dirs = set()
        composer_files = []
        for dirpath, dirnames, filenames in os.walk(pdir):
            for d in list(dirnames):
                if d in ("vendor", "lib", "libs", "third-party", "third_party", "composer"):
                    vendor_dirs.add(os.path.relpath(os.path.join(dirpath, d), pdir))
            for fn in filenames:
                full = os.path.join(dirpath, fn)
                if fn == "composer.json":
                    composer_files.append(full)
                if fn.endswith(".php"):
                    php_files += 1
                    try:
                        with open(full, "rb") as fh:
                            php_lines += fh.read().count(b"\n")
                    except OSError:
                        pass
        info["php_files"] = php_files
        info["php_lines"] = php_lines
        info["vendor_dirs"] = sorted(vendor_dirs)[:40]
        info["fs_newest_mtime"] = newest_mtime(pdir)

        main_file = find_main_file(pdir, slug)
        if main_file:
            info["main_file"] = os.path.relpath(main_file, pdir)
            hdrs = parse_headers(read_text(main_file))
            for key in ("Plugin Name", "Plugin URI", "Version", "Description", "Author",
                        "Requires at least", "Tested up to", "Requires PHP", "Text Domain",
                        "WC requires at least", "WC tested up to", "Domain Path"):
                if key in hdrs:
                    info["hdr_" + key] = hdrs[key]
        else:
            info["main_file"] = None

        readme = None
        for dirpath, dirnames, filenames in os.walk(pdir):
            for fn in filenames:
                if fn.lower() in ("readme.txt", "readme.md"):
                    readme = os.path.join(dirpath, fn)
                    break
            if readme:
                break
        if readme:
            info["readme"] = os.path.relpath(readme, pdir)
            info.update({"readme_" + k: v for k, v in
                         wc_headers_from_readme(read_text(readme, 20000)).items()})

        cf = None
        for c in composer_files:
            if c.endswith("composer.json"):
                cf = c
                break
        if cf:
            try:
                cj = json.loads(read_text(cf))
                info["composer_php"] = (cj.get("require") or {}).get("php")
                info["composer_name"] = cj.get("name")
            except (ValueError, OSError):
                pass
        meta[slug] = info

    with open(OUT, "w", encoding="utf-8") as fh:
        json.dump(meta, fh, ensure_ascii=False, indent=1)
    print(f"wrote {OUT} for {len(meta)} plugins")


if __name__ == "__main__":
    main()
