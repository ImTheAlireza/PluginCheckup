#!/usr/bin/env python3
"""Second, independent pass: grep every php file for the constructs that make
PHP 8.x die (or that were removed between PHP 7.4 and 8.2).

PHPCS/PHPCompatibility already reports most of these, but this pass is cheap,
covers files phpcs skipped, and - most importantly - keeps the matching source
line so dead code (legacy bundled libraries that never run) can be told apart
from code that really executes.
"""
import json
import os
import re
import sys

ROOT = sys.argv[1] if len(sys.argv) > 1 else "/tmp/plugins"
OUT = sys.argv[2] if len(sys.argv) > 2 else "/tmp/scan/grep_findings.json"

# (id, severity, since-version-note, regex)
RULES = [
    ("create_function", "fatal", "removed in 8.0",
     re.compile(r"(?<![\w$>])create_function\s*\(")),
    ("each()", "fatal", "removed in 8.0",
     re.compile(r"(?<![\w$>])each\s*\(\s*\$")),
    ("money_format", "fatal", "removed in 8.0",
     re.compile(r"(?<![\w$>])money_format\s*\(")),
    ("mysql_* ext", "fatal", "removed in 7.0 (already gone)",
     re.compile(r"(?<![\w$>i])(mysql_connect|mysql_query|mysql_fetch_array|mysql_fetch_assoc|"
                r"mysql_num_rows|mysql_real_escape_string|mysql_select_db|mysql_close|"
                r"mysql_insert_id|mysql_error|mysql_affected_rows|mysql_result|mysql_free_result)\s*\(")),
    ("mcrypt_*", "fatal", "removed in 8.0 (pecl since 7.2)",
     re.compile(r"(?<![\w$>])mcrypt_[a-z_]+\s*\(")),
    ("is_real", "fatal", "removed in 8.0",
     re.compile(r"(?<![\w$>])is_real\s*\(")),
    ("hebrevc/convert_cyr/ezmlm", "fatal", "removed in 8.0",
     re.compile(r"(?<![\w$>])(hebrevc|convert_cyr_string|ezmlm_hash|restore_include_path|"
                r"get_magic_quotes_gpc|get_magic_quotes_runtime|set_magic_quotes_runtime)\s*\(")),
    ("read_exif_data/image2wbmp", "fatal", "removed in 8.0",
     re.compile(r"(?<![\w$>])(read_exif_data|image2wbmp|gzgetss|php_logo_guid|zend_logo_guid)\s*\(")),
    ("FILTER_SANITIZE_STRING", "fatal", "removed in 8.1 (undefined constant)",
     re.compile(r"FILTER_SANITIZE_STRING|FILTER_SANITIZE_MAGIC_QUOTES")),
    ("curly offset", "fatal", "removed in 8.0 ($str{0})",
     re.compile(r"\$[A-Za-z_]\w*\s*\{[^}]*\}\s*(?:=|;)")),
    ("function match()", "fatal", "match is a reserved keyword in 8.0",
     re.compile(r"function\s+match\s*\(", re.I)),
    ("class enum/readonly", "fatal", "reserved since 8.1",
     re.compile(r"^\s*(?:final\s+|abstract\s+)?class\s+(enum|readonly|never)\b", re.I | re.M)),
    ("$HTTP_*_VARS", "fatal", "$HTTP_SERVER_VARS removed in 5.4 - dead code if site runs today",
     re.compile(r"\$HTTP_(SERVER_VARS|ENV_VARS|GET_VARS|POST_VARS|COOKIE_VARS|SESSION_VARS|FILES_VARS)")),
    ("utf8_encode/decode", "deprecated", "deprecated in 8.2, removed in 9.0",
     re.compile(r"(?<![\w$>])utf8_(encode|decode)\s*\(")),
    ("strftime/gmstrftime", "deprecated", "deprecated in 8.1, removed in 9.0",
     re.compile(r"(?<![\w$>])(strftime|gmstrftime)\s*\(")),
    ("date_sunrise/date_sunset", "deprecated", "removed in 8.1",
     re.compile(r"(?<![\w$>])(date_sunrise|date_sunset)\s*\(")),
    ("mhash_*", "deprecated", "deprecated in 8.1",
     re.compile(r"(?<![\w$>])mhash[a-z_]*\s*\(")),
    ("libxml_disable_entity_loader", "deprecated", "deprecated in 8.0",
     re.compile(r"(?<![\w$>])libxml_disable_entity_loader\s*\(")),
    ("Serializable (no __serialize)", "deprecated", "deprecated in 8.1",
     re.compile(r"implements\s+[^{]*\bSerializable\b")),
    ("implode(a,b) reversed", "fatal", "removed in 8.0",
     re.compile(r"(?<![\w$>])implode\s*\(\s*\$[A-Za-z_]\w*\s*,\s*\$[A-Za-z_]\w*\s*\)")),
    ("nested ternary", "fatal", "unparenthesised ternary is a compile error in 8.0",
     re.compile(r"\?\s*[^?:;]+\s*:\s*[^?:;]*\?")),
    ("${} in string", "deprecated", "deprecated in 8.2",
     re.compile(r"(?<!\\)\"\$\{|\"\$\{" )),
    ("each()/create in string", "info", "",
     re.compile(r"(?<![\w$>])create_function\s*\(")),
]


def read_lines(path):
    try:
        with open(path, "r", encoding="utf-8", errors="replace") as fh:
            return fh.read().splitlines()
    except OSError:
        return []


def main():
    findings = []
    for slug in sorted(os.listdir(ROOT)):
        pdir = os.path.join(ROOT, slug)
        if not os.path.isdir(pdir):
            continue
        for dirpath, dirnames, filenames in os.walk(pdir):
            dirnames[:] = [d for d in dirnames if d != "node_modules"]
            for fn in filenames:
                if not fn.endswith(".php"):
                    continue
                full = os.path.join(dirpath, fn)
                try:
                    if os.path.getsize(full) > 6_000_000:
                        continue
                except OSError:
                    continue
                lines = read_lines(full)
                for i, line in enumerate(lines, 1):
                    if len(line) > 4000:
                        continue
                    for rid, sev, note, rx in RULES:
                        if rx.search(line):
                            findings.append({
                                "plugin": slug,
                                "file": os.path.relpath(full, pdir),
                                "line": i,
                                "rule": rid,
                                "severity": sev,
                                "note": note,
                                "code": line.strip()[:220],
                            })
    json.dump(findings, open(OUT, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(f"wrote {OUT}: {len(findings)} hits")

    from collections import Counter
    c = Counter((f["plugin"], f["rule"]) for f in findings)
    for (p, r), n in sorted(c.items()):
        print(f"{n:5d}  {p:38s} {r}")


if __name__ == "__main__":
    main()
