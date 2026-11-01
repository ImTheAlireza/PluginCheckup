#!/usr/bin/env python3
"""Merge PHPCS/PHPCompatibility reports + plugin metadata into one summary.

Adds two things the raw phpcs output cannot tell us:
  * third-party / bundled-library detection (composer vendor dirs etc.)
  * an "always loaded" estimate: which php files the plugin main file pulls in
    (require/include chain), because a fatal error in one of those files kills
    the site on *every* request.
"""
import json
import os
import re
import sys
from collections import Counter, defaultdict

SCAN_DIR = sys.argv[1] if len(sys.argv) > 1 else "/tmp/scan"
META = sys.argv[2] if len(sys.argv) > 2 else "/tmp/scan/meta.json"
OUT = sys.argv[3] if len(sys.argv) > 3 else "/tmp/scan/aggregate.json"

PLUGINS_ROOT = "/tmp/plugins"

THIRD_PARTY_RE = re.compile(
    r"/(vendor|node_modules|third[-_]?party|libraries|lib|libs|packages|"
    r"includes/vendor|assets/js|assets/lib)/",
    re.I,
)

# require / include patterns with a constant-ish path
INCLUDE_RE = re.compile(
    r"(?:require|include)(?:_once)?\s*\(?\s*"
    r"((?:__DIR__|dirname\s*\(\s*(?:__FILE__|__DIR__)\s*\)|"
    r"plugin_dir_path\s*\(\s*__FILE__\s*\)|"
    r"WP_PLUGIN_DIR\s*\.\s*'[^']*'|"
    r"get_template_directory|get_stylesheet_directory|"
    r"__FILE__))\s*\.\s*['\"]([^'\"]+)['\"]",
    re.I,
)
INCLUDE_LITERAL_RE = re.compile(
    r"(?:require|include)(?:_once)?\s*\(?\s*['\"]([^'\"]+\.php)['\"]", re.I
)
INCLUDE_DIR_RE = re.compile(
    r"(?:require|include)(?:_once)?\s*\(?\s*"
    r"(?:__DIR__|dirname\s*\(\s*(?:__FILE__|__DIR__)\s*\))\s*\.\s*['\"]([^'\"]*/)['\"]",
    re.I,
)


def read_text(path, limit=None):
    try:
        with open(path, "r", encoding="utf-8", errors="replace") as fh:
            return fh.read() if limit is None else fh.read(limit)
    except OSError:
        return ""


def includes_of(path, pdir):
    """Return absolute paths this file pulls in (best effort, static)."""
    txt = read_text(path)
    if not txt:
        return []
    out = []
    base = os.path.dirname(path)
    for m in INCLUDE_RE.finditer(txt):
        anchor, rel = m.group(1), m.group(2)
        if anchor.lower().startswith("plugin_dir_path") or anchor.startswith("WP_PLUGIN_DIR"):
            continue
        cand = os.path.normpath(os.path.join(base, rel))
        out.append(cand)
    for m in INCLUDE_LITERAL_RE.finditer(txt):
        rel = m.group(1)
        if rel.startswith("/"):
            cand = rel
        else:
            cand = os.path.normpath(os.path.join(base, rel))
        out.append(cand)
    return [c for c in out if c.startswith(pdir)]


def always_loaded(main_file, pdir, max_depth=4):
    """BFS over require/include starting at the plugin main file."""
    if not main_file:
        return set()
    seen = set()
    frontier = [os.path.normpath(main_file)]
    by_dir = defaultdict(list)
    for dirpath, _dirs, files in os.walk(pdir):
        for fn in files:
            if fn.endswith(".php"):
                by_dir[dirpath].append(os.path.join(dirpath, fn))
    for _ in range(max_depth):
        nxt = []
        for f in frontier:
            if f in seen or not os.path.exists(f):
                continue
            seen.add(f)
            for child in includes_of(f, pdir):
                if child not in seen:
                    # tolerate "include a whole directory" style loaders
                    if os.path.isdir(child):
                        for p in by_dir.get(child, []):
                            nxt.append(p)
                    else:
                        nxt.append(child)
        frontier = nxt
        if not frontier:
            break
    return seen


def main():
    meta = json.load(open(META, encoding="utf-8"))
    agg = {}
    for slug, info in meta.items():
        rep_path = os.path.join(SCAN_DIR, slug + ".json")
        if not os.path.exists(rep_path):
            continue
        rep = json.load(open(rep_path, encoding="utf-8"))
        pdir = os.path.join(PLUGINS_ROOT, slug)
        main_file = os.path.join(pdir, info["main_file"]) if info.get("main_file") else None
        boot = always_loaded(main_file, pdir) if main_file else set()

        findings = []
        files_with_issues = 0
        for path, finfo in rep.get("files", {}).items():
            if not finfo["messages"]:
                continue
            files_with_issues += 1
            rel = os.path.relpath(path, pdir)
            third_party = bool(THIRD_PARTY_RE.search(path))
            for m in finfo["messages"]:
                findings.append({
                    "file": rel,
                    "line": m["line"],
                    "type": m["type"],          # ERROR = removed/fatal, WARNING = deprecated
                    "source": m["source"],
                    "sniff": m["source"].split(".", 1)[-1] if "." in m["source"] else m["source"],
                    "message": m["message"],
                    "fixable": m.get("fixable", False),
                    "third_party": third_party,
                    "on_boot_path": os.path.normpath(path) in boot,
                })

        errors = [f for f in findings if f["type"] == "ERROR"]
        warns = [f for f in findings if f["type"] == "WARNING"]
        own_errors = [f for f in errors if not f["third_party"]]
        own_warns = [f for f in warns if not f["third_party"]]
        boot_errors = [f for f in own_errors if f["on_boot_path"]]

        by_sniff = Counter((f["type"], f["source"]) for f in findings)
        agg[slug] = {
            **info,
            "totals": {"errors": len(errors), "warnings": len(warns),
                       "own_errors": len(own_errors), "own_warnings": len(own_warns),
                       "boot_errors": len(boot_errors),
                       "files_scanned": len(rep.get("files", {})),
                       "files_with_issues": files_with_issues},
            "sniff_counts": {f"{t}|{s}": c for (t, s), c in by_sniff.most_common()},
            "findings": findings,
            "boot_files": len(boot),
        }

    json.dump(agg, open(OUT, "w", encoding="utf-8"), ensure_ascii=False)
    print(f"wrote {OUT}: {len(agg)} plugins")
    for slug, a in sorted(agg.items(), key=lambda kv: (-kv[1]["totals"]["own_errors"], -kv[1]["totals"]["errors"])):
        t = a["totals"]
        print(f"{slug:38s} E={t['errors']:4d} W={t['warnings']:4d} "
              f"ownE={t['own_errors']:4d} ownW={t['own_warnings']:4d} bootE={t['boot_errors']:3d} "
              f"bootfiles={a['boot_files']:3d}")


if __name__ == "__main__":
    main()
