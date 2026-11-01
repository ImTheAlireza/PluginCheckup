# تحلیل سازگاری افزونه‌ها با PHP 8.2

این پوشه اسکریپت‌هایی را نگه می‌دارد که خروجیِ `گزارش-سازگاری-PHP-8.2.html`
و `گزارش-سازگاری-PHP-8.2.md` را ساختند. همه چیز قابل تکرار است.

## خلاصهٔ روش

1. هر فایل زیپِ پوشهٔ بالا استخراج شد (در `/tmp/plugins/<slug>`).
2. برای هر افزونه، **PHP_CodeSniffer + PHPCompatibility** با
   `--runtime-set testVersion 8.2-` روی یک موتور واقعیِ **PHP 8.2** اجرا شد.
   (در این محیط PHP نصب نبود، بنابراین از بیلدِ WebAssembly‏ِ PHP 8.2.33
   یعنی بستهٔ npmیِ `@php-wasm/node` استفاده شد.)
3. خروجیِ phpcs با چند پاسِ دیگر ترکیب شد:
   - تشخیص فایل‌های **رمزگذاری‌شده** با ionCube / SourceGuardian،
     و اینکه آیا روی مسیر لودِ افزونه هستند یا نه؛
   - یک اسکن grep برای الگوهایِ کشندهٔ کلاسیک؛
   - **بازبینی دستیِ خط‌به‌خطِ** هر خطای بحرانی، تا کدهای مرده
     (کتابخانه‌های قدیمی همراه، فراخوانی‌های پشت شرطِ نسخه،
     تشخیص‌های اشتباه ابزار) از لیست حذف شوند.
4. در نهایت داده‌ها در `data.json` ذخیره و به صورت HTML/MD رندر شدند.

## فایل‌ها

| فایل | کار |
|---|---|
| `collect_meta.py` | هدرهای افزونه، readme.txt، composer.json، تعداد فایل/خط |
| `scan_encoders.py` | شناسایی ionCube / SourceGuardian و مسیر لود |
| `grep_scan.py` | اسکن الگوهای کشنده (create_function، each، curly offset و …) |
| `aggregate.py` | ادغام گزارش‌های phpcs + تشخیص vendor + گراف include |
| `build_report.py` | دسته‌بندی/تری‌آژ یافته‌ها و ساخت گزارش نهایی |
| `phpcs_wrapper.php` | اجرای phpcs/PHPCompatibility داخل php-wasm |
| `scanner.mjs` | کارگرِ اسکن (یک بار بالا آوردن wasm برای چند افزونه) |
| `runphp.mjs` | اجرای یک اسکریپت PHP در php-wasm با FS میزبان |
| `data.json` | دادهٔ نهاییِ گزارش (برای داشبورد HTML) |

## اجرای دوباره

```bash
# ۰) ابزارها
mkdir -p /tmp/tools && cd /tmp/tools
for r in PHPCSStandards/PHP_CodeSniffer:4.0.4 PHPCompatibility/PHPCompatibility:10.0.0-alpha2 \
         PHPCSStandards/PHPCSUtils:1.2.3 PHPCSStandards/PHPCSExtra:1.5.1; do
  repo=${r%%:*}; tag=${r##*:}
  curl -sSL -o "$tag.tgz" "https://codeload.github.com/$repo/tar.gz/refs/tags/$tag" && tar xzf "$tag.tgz"
done
mkdir -p /tmp/pw && cd /tmp/pw && npm i @php-wasm/node

# ۱) استخراج زیپ‌ها
mkdir -p /tmp/plugins
for f in /path/to/plugins/*.zip; do
  n=$(basename "$f" .zip); mkdir -p "/tmp/plugins/$n"; unzip -qq -o "$f" -d "/tmp/plugins/$n"
done

# ۲) اسکن با PHPCompatibility (دو کارگر موازی، خروجی در /tmp/scan)
cd /tmp/pw && cp /path/to/analysis/{scanner.mjs,phpcs_wrapper.php} .
ls -d /tmp/plugins/*/ | head -30 > /tmp/listA.txt     # هر کارگر نیمی از افزونه‌ها
PHPPID=11 node scanner.mjs /tmp/scan $(cat /tmp/listA.txt)
# برای فایل‌های خیلی بزرگ (مثل woocommerce) گزارش را به stdout بگیرید تا مشکل
# نوشتن فایلِ حجیم در FS شبیه‌سازی‌شده پیش نیاید، و حافظه را بالا ببرید
# (phpcs_wrapper.php خودش memory_limit را 1536M می‌کند).

# ۳) ادغام و گزارش
python3 analysis/collect_meta.py /tmp/plugins <مسیر-زیپ‌ها> /tmp/scan/meta.json
python3 analysis/scan_encoders.py /tmp/plugins /tmp/scan/meta.json /tmp/scan/encoders.json
python3 analysis/grep_scan.py     /tmp/plugins /tmp/scan/grep_findings.json
python3 analysis/aggregate.py     /tmp/scan    /tmp/scan/meta.json /tmp/scan/aggregate.json
python3 analysis/build_report.py
python3 analysis/build_report.py render
```

## نکتهٔ مهم دربارهٔ تری‌آژ

تعداد خامِ خطاهای phpcs گمراه‌کننده است: بیشترِ آن‌ها در
کتابخانه‌های همراه (مثل nusoap، phpseclib، PCLZip، thecodingmachine/safe)
یا در شاخه‌هایی هستند که هرگز اجرا نمی‌شوند.
قانونی که اینجا استفاده شد:

- هرچه **در نسخهٔ ۷.۴ یا قبل‌تر حذف شده** باشد، نمی‌تواند عاملِ خرابیِ امروز باشد
  (سایت همین الان روی نسخهٔ فعلی بالاست) → «کد مرده».
- هر خطای بحرانی که در یک **تعریف تابعِ shim**، پشت **شرط نسخه**،
  یا فقط در **خواندن ini** باشد → بی‌خطر.
- بقیهٔ خطاهای «حذف‌شده در ۸.۰/۸.۱/۸.۲» → **خطای Fatal بالقوه**.

یک استثنای مهم هم وجود دارد: **فایل‌های رمزگذاری‌شده** اصلاً قابل تحلیلِ
ایستا نیستند و تنها راهِ تشخیصِ قطعی برای آن‌ها اجرا روی هاست است.

## مرحلهٔ دوم: تست عملی (اجرای واقعی)

تحلیل ایستا نمی‌تواند بگوید یک خطا **در عمل** رخ می‌دهد یا نه (مسیرهای اجرا،
وابستگی‌ها و ترتیبِ لود شدن را ندارد). برای همین مرحلهٔ دوم همهٔ افزونه‌ها روی یک
وردپرس واقعی (WordPress 6.9.11) با PHP 8.2.33 نصب و اجرا شدند:

```
tests/README.md          روشِ راه‌اندازی و اجرا
tests/wp.mjs             بالا آوردن وردپرس روی php-wasm + لاگین به پیشخوان
tests/run-tests.mjs      اجرای تست برای هر افزونه
tests/build_runtime_report.py  ساخت گزارش فارسی
tests/results/runtime.json     خروجی خام
گزارش-تست-عملی-PHP-8.2.html    گزارش نهاییِ تست عملی
نتایج-تست-عملی.csv             جدول خلاصه
```

نتیجهٔ عملی (۵۶ افزونه): ۴ افزونه سایت را کاملاً از کار می‌اندازند
(`digits`، `cta-button`، `zhaket-updater`، `elementor-pro`)،
۲ افزونه سایت را بالا نگه می‌دارند ولی خودشان بی‌کار می‌مانند
(`RTL-CareUnit`، `woocommerce-invoice`)، و بقیه اجرا می‌شوند.
