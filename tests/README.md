# تست عملی روی وردپرس واقعی با PHP 8.2

این پوشه هارنسِ **تستِ واقعی** را نگه می‌دارد: هر افزونه روی یک وردپرس واقعی
(WordPress 6.9.11) که با PHP 8.2.33 اجرا می‌شود نصب، فعال و روی چند صفحه باز می‌شود
تا معلوم شود آیا واقعاً خطا می‌دهد یا نه (برخلاف تحلیل استاتیک که فقط کد را می‌خواند).

## محیط تست

| جزء | مقدار | توضیح |
|---|---|---|
| وردپرس | 6.9.11 | نسخهٔ رسمی از GitHub |
| PHP | 8.2.33 | موتور WebAssembly از بستهٔ `@php-wasm/node` |
| دیتابیس | SQLite | از طریق افزونهٔ `sqlite-database-integration` (در محیط تست سرور MySQL نداریم) |
| افزونهٔ پایه | WooCommerce | همیشه فعال است چون بیشتر افزونه‌ها به آن نیاز دارند |

## فایل‌ها

```
tests/
├─ wp.mjs                 بالا آوردن وردپرس روی php-wasm (حالت سرور یا تک‌درخواست) + لاگین به پیشخوان
├─ run-tests.mjs          اجرای تست برای هر افزونه
├─ build_runtime_report.py ساخت گزارش فارسی (HTML / Markdown / CSV)
└─ results/runtime.json   خروجی خامِ ادغام‌شدهٔ همهٔ اجراها
```

## اجرا

```bash
# ۱) نصب وابستگی‌ها
mkdir -p /tmp/pw && cd /tmp/pw && npm i @php-wasm/node@3.1.61
cp /home/user/PluginCheckup/tests/*.mjs /tmp/pw/

# ۲) آماده‌سازی وردپرس
mkdir -p /tmp/wp && cd /tmp/wp
curl -sSL -o wp.tgz https://codeload.github.com/WordPress/WordPress/tar.gz/refs/tags/6.9.11
tar xzf wp.tgz && mv wordpress wordpress   # مسیر: /tmp/wp/wordpress

# افزونهٔ SQLite (از بستهٔ npm مخصوص Playground؛ تarball گیت‌هابِ این افزونه ناقص است)
cd /tmp && npm pack @wp-playground/wordpress-builds@0.9.19
tar xzf wp-playground-wordpress-builds-*.tgz \
    package/src/sqlite-database-integration/sqlite-database-integration.zip
unzip -q package/src/sqlite-database-integration/sqlite-database-integration.zip \
     -d /tmp/wp/wordpress/wp-content/plugins/
mv /tmp/wp/wordpress/wp-content/plugins/sqlite-database-integration-main \
   /tmp/wp/wordpress/wp-content/plugins/sqlite-database-integration
# و کپی فایل db.copy به wp-content/db.php (جاهای {…} را با مسیر واقعی پر کنید)

# ۳) wp-config.php
#    DB_ENGINE=sqlite و DB_DIR از __DIR__ مشتق شود (مهم برای جداسازی چند نسخه)،
#    WP_DEBUG / WP_DEBUG_LOG / WP_DEBUG_DISPLAY روشن، WP_HTTP_BLOCK_EXTERNAL روشن.

# ۴) نصب وردپرس
cd /tmp/pw && node wp.mjs req POST "/wp-admin/install.php?step=2" \
  "weblog_title=Test&user_name=admin&admin_password=Admin%4012345&admin_password2=Admin%4012345&admin_email=a%40b.com&Submit=Install+WordPress"

# ۵) اجرای تست‌ها (دو worker برای سرعت؛ هر کدام ریشه و دیتابیس خودش را دارد)
WP_ROOT=/tmp/wp/wordpress  PHPPID=1  node run-tests.mjs --root /tmp/wp/wordpress  --only slug1,slug2 --out /tmp/wptest/results-a.json
WP_ROOT=/tmp/wp2/wordpress PORT=9500 PHPPID=50 node run-tests.mjs --root /tmp/wp2/wordpress --only slug3,slug4 --out /tmp/wptest/results-b.json

# ۶) گزارش
python3 /home/user/PluginCheckup/tests/build_runtime_report.py /tmp/wptest/results-a.json /tmp/wptest/results-b.json
```

## آنچه برای هر افزونه انجام می‌شود

1. کپی پوشهٔ افزونه به `wp-content/plugins`
2. خواندن هدر `Requires Plugins` و فعال کردن وابستگی‌ها
3. `activate_plugin()` — خروجیِ غیرمنتظره و `WP_Error` ذخیره می‌شود
4. **درج مستقیم در `active_plugins`** — شبیه‌سازی حالت واقعی شما: سایت از قبل فعال است و بعد PHP عوض می‌شود
5. باز کردن این صفحه‌ها: صفحهٔ اصلی، پیشخوان، فهرست افزونه‌ها، تنظیمات عمومی، محصولات،
   تنظیمات ووکامرس، تنظیمات حمل‌ونقل + همهٔ صفحه‌هایی که خود افزونه به منوی مدیریت اضافه می‌کند
   (منو از HTML واقعیِ پیشخوان استخراج می‌شود)
6. خواندن `debug.log` و تفکیک خطاها: مرگبار / خطای نحو / اخطار / منسوخ‌شده
7. بازگشت به حالت پایه و حذف افزونه

## رده‌بندی نتایج

| verdict | معنی |
|---|---|
| `ENCODED` | فایل رمزگذاری‌شده و لودر ندارد → سایت از کار افتاد (کد ۵۰۰ یا صفحهٔ فقط-خطا) |
| `LOADER_NOTICE` | افزونه خودش نبودِ لودر را تشخیص داده و فقط اخطار نشان می‌دهد؛ سایت بالاست |
| `CRASH` | خطای مرگبار PHP یا WSOD روی یکی از صفحه‌ها |
| `NO_ACT` | وردپرس اجازهٔ فعال‌سازی نداد (وابستگی یا خروجی غیرمنتظره) |
| `WARNINGS` | اجرا شد ولی اخطار/منسوخ‌شدگی در لاگ ثبت شد |
| `OK` | بدون خطا |

خطاهای ناشی از محیط تست (نبود اینترنت، محدودیت SQLite، خودِ لایهٔ دیتابیس) جداگانه
دسته‌بندی می‌شوند (`network` / `infra` / `db`) تا در حکم نهایی لحاظ نشوند.

## محدودیت‌ها

* هیچ لودر ionCube / SourceGuardian ای نصب نیست — دقیقاً همان وضعیت هاستی که لودر 8.2 ندارد.
* اینترنت نداریم؛ هر افزونه‌ای که هنگام لود به API خارجی وابسته باشد ممکن است رفتار متفاوتی نشان دهد
  (نمونه: صفحهٔ خانهٔ المنتور).
* SQLite به‌جای MySQL؛ خطاهای مربوط به آن فیلتر می‌شوند.
* فقط صفحه‌هایی که باز شدند پوشش داده می‌شوند؛ مسیرهایی مثل پرداخت، ارسال پیامک و خروجیِ فایل
  باید روی سایت واقعی هم بررسی شوند.
