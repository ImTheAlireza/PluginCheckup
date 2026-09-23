#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""بررسی ایستای افزونهٔ هاب (tisacase-hub) — بدون نیاز به PHP یا وردپرس.

چه چیزهایی را چک می‌کند:
  ۱) هر `admin_post_<action>` که در قالب/کد یک فرم به آن پست می‌کند، واقعاً ثبت شده باشد.
  ۲) نام nonce فرم‌ها با `check_admin_referer` ها هم‌خوان باشد (پیشوندهای الحاقی هم پوشش داده می‌شود).
  ۳) هر آیتم پیش‌فرض رجیستری یا زیپش در `plugins/dist` باشد یا صریحاً `'zip' => false`.
  ۴) شمارهٔ نسخه در سه جا یکی باشد: هدر افزونه، `TSH_VERSION`، و `Stable tag` فایل readme.
  ۵) زیپ ساخته‌شده (اگر هست) پوشهٔ درست را داشته باشد و نسخه‌اش با سورس بخواند.

اجرا:  python3 tools/hub-check.py
"""
import io
import os
import re
import sys
import subprocess

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
HUB = os.path.join(ROOT, 'plugins', 'tisacase-hub')
DIST = os.path.join(ROOT, 'plugins', 'dist')

problems = []
notes = []


def read(path):
    with io.open(path, encoding='utf-8') as fh:
        return fh.read()


def php_files():
    for base, _dirs, files in os.walk(HUB):
        for name in sorted(files):
            if name.endswith('.php'):
                yield os.path.join(base, name)


def check_post_actions():
    registered = set()
    posted = {}  # action => file

    for path in php_files():
        text = read(path)
        rel = os.path.relpath(path, ROOT)
        for m in re.finditer(r"add_action\(\s*'admin_post_([a-z0-9_\-]+)'", text):
            registered.add(m.group(1))
        # فرم‌هایی که action را در input مخفی می‌گذارند
        for m in re.finditer(r"name=\"action\"\s+value=\"([a-z0-9_\-]+)\"", text):
            posted[m.group(1)] = rel
        # فرم‌هایی که action را در کوئری admin-post.php می‌گذارند
        for m in re.finditer(r"admin-post\.php\?action=([a-z0-9_\-]+)", text):
            posted[m.group(1)] = rel

    for action, where in sorted(posted.items()):
        if action not in registered:
            problems.append('فرم در %s به action=%s پست می‌کند ولی admin_post_%s ثبت نشده است.' % (where, action, action))

    notes.append('اکشن‌های ثبت‌شده: %s' % ', '.join(sorted(registered)))


def check_nonces():
    fields = set()
    checks = set()

    for path in php_files():
        text = read(path)
        # nonce فرم‌ها: هم wp_nonce_field (کادر مخفی) و هم wp_nonce_url (لینک اقدام)
        for m in re.finditer(r"wp_nonce_field\(\s*'([a-z0-9_\-]*)'", text):
            fields.add(m.group(1))
        for m in re.finditer(r"wp_nonce_url\([^,]+,\s*'([a-z0-9_\-]*)'", text):
            fields.add(m.group(1))
        for m in re.finditer(r"check_admin_referer\(\s*'([a-z0-9_\-]*)'", text):
            checks.add(m.group(1))

    # مقایسهی پیشوندی: 'tsh_install_' . $key  ↔  check_admin_referer( 'tsh_install_' . $key )
    for field in sorted(fields):
        if field in checks:
            continue
        if any(field and c.startswith(field) for c in checks):
            continue
        problems.append('nonce فرم «%s» هیچ check_admin_referer متناظری ندارد.' % field)

    for check in sorted(checks):
        if check in fields:
            continue
        if any(check and f and (f.startswith(check) or check.startswith(f)) for f in fields):
            continue
        problems.append('check_admin_referer «%s» هیچ کادر nonce متناظری در فرم‌ها ندارد.' % check)

    notes.append('nonceها: %s' % ', '.join(sorted(checks)))


def check_defaults_zips():
    src = read(os.path.join(HUB, 'includes', 'class-tsh-registry.php'))
    body = src.split('private static function defaults()', 1)
    if len(body) < 2:
        problems.append('تابع defaults() در رجیستری پیدا نشد.')
        return
    body = body[1].split('return apply_filters', 1)[0]

    items = re.findall(r"\$items\['([a-z0-9_\-]+)'\] = array\(\s*(.*?)\n\t\t\t\);", body, re.S)
    if not items:
        problems.append('هیچ آیتم پیش‌فرضی در defaults() پیدا نشد (الگوی پارس عوض شده؟).')
        return

    base = os.path.join('plugins', 'dist')
    ready = retired = 0
    for key, chunk in items:
        m = re.search(r"'dir'\s*=>\s*'([^']+)'", chunk)
        if not m:
            problems.append('آیتم «%s» کلید dir ندارد.' % key)
            continue
        directory = m.group(1)
        if "'zip'   => false" in chunk or "'zip' => false" in chunk:
            retired += 1
            continue
        if not os.path.exists(os.path.join(ROOT, base, directory + '.zip')):
            problems.append('آیتم «%s» دکمهٔ نصب از مخزن خواهد داشت ولی plugins/dist/%s.zip وجود ندارد.' % (key, directory))
        else:
            ready += 1

    notes.append('آیتم‌های پیش‌فرض: %d — آمادهٔ نصب: %d · بازنشسته (بدون زیپ): %d' % (len(items), ready, retired))


def check_versions():
    main = read(os.path.join(HUB, 'tisacase-hub.php'))
    header = re.search(r'\*\s*Version:\s*([0-9][0-9A-Za-z\.\-]*)', main)
    const = re.search(r"define\(\s*'TSH_VERSION'\s*,\s*'([^']+)'", main)
    stable = re.search(r'Stable tag:\s*([0-9][0-9A-Za-z\.\-]*)', read(os.path.join(HUB, 'readme.txt')))

    if not header or not const:
        problems.append('نسخه در فایل اصلی افزونه (هدر یا TSH_VERSION) پیدا نشد.')
        return

    version = header.group(1)
    versions = {
        'هدر افزونه': version,
        'TSH_VERSION': const.group(1),
        'readme Stable tag': stable.group(1) if stable else '—',
    }
    for label, value in versions.items():
        if value != version:
            problems.append('ناهم‌خوانی نسخه: %s = %s ولی هدر = %s.' % (label, value, version))
    notes.append('نسخهٔ هاب: %s' % version)
    return version


def check_zip(version):
    path = os.path.join(DIST, 'tisacase-hub.zip')
    if not os.path.exists(path):
        notes.append('زیپ hub ساخته نشده (با tools/build-plugin-zip.sh tisacase-hub بسازید).')
        return
    try:
        names = subprocess.check_output(['unzip', '-Z1', path], stderr=subprocess.DEVNULL).decode('utf-8', 'replace').splitlines()
    except Exception as exc:  # noqa: BLE001
        notes.append('خواندن زیپ ممکن نشد: %s' % exc)
        return

    if not names:
        problems.append('زیپ hub خالی است.')
        return
    top = names[0].split('/')[0]
    if top != 'tisacase-hub':
        problems.append('پوشهٔ داخل زیپ hub «%s» است (باید tisacase-hub باشد).' % top)
    if 'tisacase-hub/tisacase-hub.php' not in names:
        problems.append('فایل اصلی افزونه داخل زیپ نیست.')

    inside = subprocess.check_output(['unzip', '-p', path, 'tisacase-hub/tisacase-hub.php']).decode('utf-8', 'replace')
    m = re.search(r'\*\s*Version:\s*([0-9][0-9A-Za-z\.\-]*)', inside)
    if version and m and m.group(1) != version:
        problems.append('زیپ نسخهٔ %s دارد ولی سورس %s است (زیپ را دوباره بسازید).' % (m.group(1), version))
    notes.append('زیپ hub: %d فایل' % len(names))


def main():
    print('بررسی ایستای هاب — %s' % HUB)
    print()
    check_post_actions()
    check_nonces()
    check_defaults_zips()
    version = check_versions()
    check_zip(version)

    for note in notes:
        print('• %s' % note)
    print()
    if problems:
        print('✗ %d مشکل:' % len(problems))
        for problem in problems:
            print('  - %s' % problem)
        return 1
    print('✓ همهٔ بررسی‌ها سالم')
    return 0


if __name__ == '__main__':
    sys.exit(main())
