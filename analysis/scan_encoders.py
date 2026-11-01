#!/usr/bin/env python3
"""Detect encoded (ionCube / SourceGuardian) php files and whether they sit on
the plugin's load path (main plugin file or anything it directly requires).

An encoded file cannot run unless the matching PHP extension (loader) is
installed *for that exact PHP version*. Without it the file prints
"Script error: ... requires the ionCube/SourceGuardian PHP Loader" and calls
die() - which is exactly what a white screen looks like after a PHP upgrade.
"""
import json
import os
import re
import sys

ROOT = sys.argv[1] if len(sys.argv) > 1 else "/tmp/plugins"
META = sys.argv[2] if len(sys.argv) > 2 else "/tmp/scan/meta.json"
OUT = sys.argv[3] if len(sys.argv) > 3 else "/tmp/scan/encoders.json"

REQUIRE_RE = re.compile(
    r"(?:require|include)(?:_once)?\s*\(?\s*"
    r"((?:__DIR__|dirname\s*\(\s*(?:__FILE__|__DIR__)\s*\)|plugin_dir_path\s*\(\s*__FILE__\s*\)|"
    r"[A-Za-z_][A-Za-z0-9_]*)\s*\.\s*['\"]([^'\"]+)['\"]"
    r"|['\"]([^'\"]+\.php)['\"])",
    re.I,
)


def read_head(path, n=4000):
    try:
        with open(path, "rb") as fh:
            return fh.read(n)
    except OSError:
        return b""


def read_text(path):
    try:
        with open(path, "r", encoding="utf-8", errors="replace") as fh:
            return fh.read()
    except OSError:
        return ""


def detect(head):
    """Return 'ioncube' | 'sourceguardian' | None for a file's first bytes."""
    if head.startswith(b"<?php //ICB0") or b"//ICB0" in head[:20]:
        return "ioncube"
    if b"sg_load" in head[:3000] or b"function_exists('sg_load')" in head[:3000]:
        return "sourceguardian"
    if b"ionCube" in head[:2000] and b"Loader" in head[:2000]:
        return "ioncube"
    return None


def main():
    meta = json.load(open(META, encoding="utf-8"))
    out = {}
    for slug, info in meta.items():
        pdir = os.path.join(ROOT, slug)
        if not os.path.isdir(pdir):
            continue
        enc = {"ioncube": [], "sourceguardian": []}
        for dirpath, dirnames, filenames in os.walk(pdir):
            for fn in filenames:
                if not fn.endswith(".php"):
                    continue
                full = os.path.join(dirpath, fn)
                kind = detect(read_head(full))
                if kind:
                    enc[kind].append(os.path.relpath(full, pdir))

        main_file = os.path.join(pdir, info["main_file"]) if info.get("main_file") else None
        main_kind = detect(read_head(main_file)) if main_file else None

        # what the main file pulls in right away
        direct = []
        if main_file and os.path.exists(main_file):
            txt = read_text(main_file)
            base = os.path.dirname(main_file)
            php_literals = []
            for m in REQUIRE_RE.finditer(txt):
                rel = m.group(2) or m.group(3)
                if rel:
                    php_literals.append(rel)
            # loose fallback: any '...php' string, plus __DIR__/dirname chains with
            # DIRECTORY_SEPARATOR concatenations
            php_literals += re.findall(r"['\"]([\w\-./]*[\w\-]+\.php)['\"]", txt)
            all_files = []
            for dp, dn, fns in os.walk(pdir):
                all_files.extend(os.path.relpath(os.path.join(dp, f), pdir) for f in fns)
            for rel in php_literals:
                rel = rel.lstrip("/")
                cand = os.path.normpath(os.path.join(base, rel))
                if os.path.exists(cand):
                    direct.append(os.path.relpath(cand, pdir))
                    continue
                # try to match by suffix anywhere in the plugin
                for f in all_files:
                    if f.endswith(rel) and f not in direct:
                        direct.append(f)
                        break

        direct_encoded = {}
        for rel in direct:
            k = detect(read_head(os.path.join(pdir, rel)))
            if k:
                direct_encoded[rel] = k

        out[slug] = {
            "ioncube_files": enc["ioncube"],
            "sourceguardian_files": enc["sourceguardian"],
            "ioncube_count": len(enc["ioncube"]),
            "sourceguardian_count": len(enc["sourceguardian"]),
            "main_file": info.get("main_file"),
            "main_file_encoded": main_kind,
            "direct_requires": direct,
            "direct_requires_encoded": direct_encoded,
            "on_load_path": bool(main_kind or direct_encoded),
        }

    json.dump(out, open(OUT, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(f"wrote {OUT}")
    for slug, d in sorted(out.items(), key=lambda kv: -(kv[1]["ioncube_count"] + kv[1]["sourceguardian_count"])):
        if d["ioncube_count"] or d["sourceguardian_count"]:
            print(f"{slug:34s} ioncube={d['ioncube_count']:4d} sg={d['sourceguardian_count']:3d} "
                  f"main={d['main_file_encoded']} direct_encoded={list(d['direct_requires_encoded'].items())[:3]}")


if __name__ == "__main__":
    main()
