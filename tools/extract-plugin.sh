#!/usr/bin/env bash
# plugins/src/<dir> را از زیپِ ریشه می‌سازد تا منبع افزونه برای بازطراحی قابل ویرایش/مرور باشد.
# استفاده: tools/extract-plugin.sh <نام-افزونه> [--force]
set -euo pipefail
cd "$(dirname "$0")/.."
name="${1:-}"; [ -n "$name" ] || { echo "مصرف: tools/extract-plugin.sh <plugin-dir> [--force]" >&2; exit 2; }
force="${2:-}"

zip=$(ls -1 *.zip 2>/dev/null | grep -i -- "$name" | grep -v '^tisacase-hub.zip$' | head -1)
[ -n "$zip" ] || { echo "زیپی برای «$name» در ریشهٔ مخزن پیدا نشد." >&2; exit 1; }

tmp=$(mktemp -d); unzip -qq "$zip" -d "$tmp"
top=$(find "$tmp" -mindepth 1 -maxdepth 1 -type d | head -1); top=${top#$tmp/}
[ -n "$top" ] || { rm -rf "$tmp"; echo "ساختار زیپ غیرمنتظره است." >&2; exit 1; }

dest="plugins/src/$top"
if [ -e "$dest" ] && [ "$force" != "--force" ]; then
  rm -rf "$tmp"; echo "$dest از قبل وجود دارد (برای بازنویسی: --force)." >&2; exit 1
fi
rm -rf "$dest"; mkdir -p "$(dirname "$dest")"; mv "$tmp/$top" "$dest"; rm -rf "$tmp"

echo "✓ $zip → $dest ($(find "$dest" -type f | wc -l) فایل)"
echo "  بازطراحی را همین‌جا انجام بده، بعد: tools/build-plugin-zip.sh $top"
