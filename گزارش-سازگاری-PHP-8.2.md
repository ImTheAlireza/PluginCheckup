# گزارش سازگاری افزونه‌ها با PHP 8.2

تاریخ تحلیل: 2026-11-01 11:33 | 56 افزونه | 13,462 فایل PHP | 2,874,924 خط کد

## نتیجهٔ کوتاه

به احتمال بسیار زیاد مشکل شما **کدهای رمزگذاری‌شده (ionCube / SourceGuardian)** است، نه خودِ PHP 8.2.

**6 افزونه** فایل رمزگذاری‌شده دارند و در همهٔ آن‌ها فایل اصلی (یا اولین include آن) رمزگذاری است؛ یعنی اگر لودر متناسب با PHP 8.2 روی هاست فعال نباشد، همان لحظهٔ لود وردپرس سایت می‌خوابد.

| افزونه | نسخه | نوع | تعداد فایل رمزگذاری‌شده | نقطهٔ ورود |
|---|---|---|---|---|
| Elementor Pro | 3.8.1 | SourceGuardian | 6 | `elementor-pro/abzarwp/abzarwp.php` |
| DIGITS: WordPress Mobile Number Signup and Login | 9.2.1 | ionCube | 268 | `فایل اصلی افزونه` |
| Zhaket Smart Updater | 4.2.4 | ionCube | 38 | `zhaket-updater/vendor/autoload.php` |
| افزونه مدیریت هوشمند راست چین | 2.5.2 | ionCube | 114 | `RTL-CareUnit/vendor/autoload.php` |
| دکمه تماس شناور | 1.0 | ionCube | 1 | `cta-button/includes.php` |
| فاکتور حرفه‌ای ووکامرس | 7.0.3 | SourceGuardian | 24 | `فایل اصلی افزونه` |

### اگر همین الان سایت بالا نمی‌آید

1. PHP را در هاست روی همان نسخهٔ قبلی برگردانید.
2. از طریق FTP/File Manager پوشه‌های زیر را در `wp-content/plugins/` تغییر نام دهید (مثلاً `digits` → `digits.off`) تا وردپرس آن‌ها را غیرفعال کند: `elementor-pro`، `digits`، `zhaket-updater`، `RTL-CareUnit`، `cta-button`، `woocommerce-invoice`
3. بعد از بالا آمدن سایت، لودرها را فعال کنید یا نسخهٔ سازگار بگیرید و دوباره به 8.2 بروید.


بعد از آن، این افزونه‌ها فراخوانیِ توابع **کاملاً حذف‌شده** در PHP 8 را در مسیر اجرا دارند:

- **Advanced Order Export For WooCommerce** (`woo-order-export-lite/woo-order-export-lite/classes/PHPExcel/Shared/PCLZip/pclzip.lib.php:4851`) — Function get_magic_quotes_runtime() is deprecated since PHP 7.4 and removed since PHP 8.0
- **WP-Parsidate** (`wp-parsidate/wp-parsidate/includes/plugins/wc-gateways/lib/class-rsa.php:218`) — Passing the $glue and $pieces parameters in reverse order to implode has been deprecated since PHP 7.4 and is removed since PHP 8.0; $glue should be the first parameter and $pieces the second
- **افزونه حمل و نقل ووکامرس** (`persian-woocommerce-shipping/persian-woocommerce-shipping/includes/admin/class-settings.php:113`) — Function create_function() is deprecated since PHP 7.2 and removed since PHP 8.0; Use an anonymous function instead

## جدول وضعیت همهٔ افزونه‌ها

| وضعیت | افزونه | نسخه | Requires PHP | فایل PHP | رمزگذاری | Fatal | Deprecated 8.2 |
|---|---|---|---|---|---|---|---|
| بحرانی | Elementor Pro | 3.8.1 | — | 509 | 6 | — | 19 |
| بحرانی | DIGITS: WordPress Mobile Number Signup and Login | 9.2.1 | 5.5 | 1294 | 268 | — | 16 |
| بحرانی | Zhaket Smart Updater | 4.2.4 | 7.4 | 69 | 38 | — | — |
| بحرانی | افزونه مدیریت هوشمند راست چین | 2.5.2 | 7.4 | 115 | 114 | — | — |
| بحرانی | دکمه تماس شناور | 1.0 | — | 3 | 1 | — | — |
| بحرانی | فاکتور حرفه‌ای ووکامرس | 7.0.3 | — | 564 | 24 | — | — |
| پرخطر | Advanced Order Export For WooCommerce | 3.6.0 | 7.1.0 | 269 | — | 1 | 10 |
| پرخطر | WP-Parsidate | 5.1.5 | 7.4 | 42 | — | 1 | — |
| پرخطر | افزونه حمل و نقل ووکامرس | 4.2.8 | 7.4 | 43 | — | 1 | — |
| نیاز به توجه | Elementor | 3.27.4 | 7.4 | 838 | — | — | 6 |
| نیاز به توجه | Order Export & Order Import for WooCommerce | 2.5.9 | 5.6 | 85 | — | — | 6 |
| نیاز به توجه | Product Import Export for WooCommerce | 2.4.4 | 5.6 | 89 | — | — | 6 |
| نیاز به توجه | Woodmart Core | 1.1.0 | — | 31 | — | — | 2 |
| نیاز به توجه | TeraWallet | 1.5.10 | 7.4 | 57 | — | — | 1 |
| نیاز به توجه | WooCommerce | 10.3.7 | 7.4 | 3316 | — | — | 1 |
| تقریباً سالم | Admin Menu Editor | 1.15.2 | 7.4 | 246 | — | — | — |
| تقریباً سالم | Automatic Postal Tracking for WooCommerce | 3.7.0 | 7.4 | 3 | — | — | — |
| تقریباً سالم | Behpardakht Woocommerce | 2.6.5 | 7.4 | 25 | — | — | — |
| تقریباً سالم | Code Snippets | 3.6.8 | 7.4 | 158 | — | — | — |
| تقریباً سالم | DIGITS: One Click Login/Signup Addon | 4.4 | 5.5 | 40 | — | — | — |
| تقریباً سالم | FiboSearch - AJAX Search for WooCommerce (Pro) | 1.30.1 | 7.4 | 422 | — | — | — |
| تقریباً سالم | Loco Translate | 2.7.1 | 7.2.24 | 241 | — | — | — |
| تقریباً سالم | Plugin Check (PCP) | 2.1.0 | 7.4 | 1606 | — | — | — |
| تقریباً سالم | SnappPay Woocommerce Gateway | 1.4.2 | 7.4 | 5 | — | — | — |
| تقریباً سالم | Surenapost | 1.3.13 | 7.4 | 24 | — | — | — |
| تقریباً سالم | TisaCase Exporter — مرکز خروجی تیساکیس | 2.1.6 | 7.4 | 29 | — | — | — |
| تقریباً سالم | TisaCase Pricing | 1.4.2 | 7.4 | 28 | — | — | — |
| تقریباً سالم | WC Sadad Payment gateway | 5.1.0 | — | 11 | — | — | — |
| تقریباً سالم | WP Statistics | 14.12.6 | 7.2 | 613 | — | — | — |
| تقریباً سالم | WP-Optimize - Clean, Compress, Cache | 4.5.3 | 7.2 | 592 | — | — | — |
| تقریباً سالم | Waitlist woocommerce( Back in stock notifier ) | 2.8.4 | — | 77 | — | — | — |
| تقریباً سالم | Yoast SEO | 24.4 | 7.2.5 | 1124 | — | — | — |
| تقریباً سالم | اختصاصی تیساکیس (TisaCase Hub) | 1.9.15 | 7.4 | 10 | — | — | — |
| تقریباً سالم | ارسال سفارش‌ها به تلگرام ووکامرس | 1.14.3 | 7.4 | 4 | — | — | — |
| تقریباً سالم | افزونه درگاه پرداخت الکترونیک سامان (سپ) ووکامرس | 1.3.0 | — | 3 | — | — | — |
| تقریباً سالم | افزونه وردپرس پنل پیامکی ایده پردازان | 1.0.26 | 7.0 | 14 | — | — | — |
| تقریباً سالم | مرکز بهینه‌سازی تیساکیس (TisaCase Optimizer) | 1.3.4 | 7.4 | 33 | — | — | — |
| تقریباً سالم | ووکامرس فارسی | 9.3.4 | 7.4 | 254 | — | — | — |
| سالم | Bulk Product Cleaner (Drafts + Images) | 12.6.3 | 7.4 | 11 | — | — | — |
| سالم | Checkout Field Editor for WooCommerce | 2.1.4 | 5.6 | 39 | — | — | — |
| سالم | LiteSpeed Cache | 7.9.1 | 7.4 | 212 | — | — | — |
| سالم | Members | 3.2.18 | 7.4 | 112 | — | — | — |
| سالم | Page Links To | 3.3.7 | — | 8 | — | — | — |
| سالم | PeproDev WooCommerce Receipt Uploader | 2.8.0 | 5.6 | 19 | — | — | — |
| سالم | Tisa Product ZIP Importer | 0.8.0 | — | 4 | — | — | — |
| سالم | TisaCase Order Phone Exporter | 1.5.0 | 7.4 | 15 | — | — | — |
| سالم | TisaCase Order Wallet Reporter | 1.0.0 | 7.4 | 1 | — | — | — |
| سالم | TisaCase — قوانین توضیحات محصول | 1.6.2 | 7.4 | 7 | — | — | — |
| سالم | TisaCase — مدیریت انبوه موجودی | 1.4.3 | 7.4 | 7 | — | — | — |
| سالم | TisaCase — مدیریت گروهی متغیرها و مدل‌ها | 2.3.1 | 7.4 | 7 | — | — | — |
| سالم | TisaCase — همگام‌سازی موجودی با همکار | 1.28.0 | 7.4 | 39 | — | — | — |
| سالم | TisaCase — کارت به کارت تیساکیس | 1.4.0 | 7.4 | 19 | — | — | — |
| سالم | Variation Swatches for WooCommerce | 2.2.0 | 7.4 | 39 | — | — | — |
| سالم | Variation Swatches for WooCommerce - Pro | 2.2.0 | 7.4 | 32 | — | — | — |
| سالم | WooCommerce SKU Prefix Bar | 1.5.3 | 7.2 | 3 | — | — | — |
| سالم | پکیج ویژه قاب موبایل | 1.5.4 | 7.4 | 2 | — | — | — |

## اخطارهای Deprecated (غیرمرگبار)

**Elementor Pro** (19 مورد):
- `elementor-pro/abzarwp/abzarwp-v12.php:2` — تابع/اکستنشن حذف‌شده
- `elementor-pro/abzarwp/abzarwp-v12.php:2` — تابع/اکستنشن حذف‌شده
- `elementor-pro/abzarwp/abzarwp-v12.php:2` — تابع/اکستنشن حذف‌شده
- `elementor-pro/abzarwp/abzarwp-v12.php:2` — تابع/اکستنشن حذف‌شده
- `elementor-pro/abzarwp/abzarwp-v13.php:2` — تابع/اکستنشن حذف‌شده
- `elementor-pro/abzarwp/abzarwp-v13.php:2` — تابع/اکستنشن حذف‌شده
- `elementor-pro/abzarwp/abzarwp-v13.php:2` — تابع/اکستنشن حذف‌شده
- `elementor-pro/abzarwp/abzarwp-v13.php:2` — تابع/اکستنشن حذف‌شده
**DIGITS: WordPress Mobile Number Signup and Login** (16 مورد):
- `digits/gateways/1b1/nusoap-php5.3.php:960` — تابع/اکستنشن حذف‌شده
- `digits/gateways/1b1/nusoap-php5.3.php:2767` — پارامتر اختیاری پیش از اجباری
- `digits/gateways/1b1/nusoap-php5.3.php:2767` — پارامتر اختیاری پیش از اجباری
- `digits/gateways/1b1/nusoap-php5.3.php:7225` — تابع/اکستنشن حذف‌شده
- `digits/gateways/1b1/nusoap-php7.php:890` — تابع/اکستنشن حذف‌شده
- `digits/gateways/1b1/nusoap-php7.php:2673` — پارامتر اختیاری پیش از اجباری
- `digits/gateways/1b1/nusoap-php7.php:2673` — پارامتر اختیاری پیش از اجباری
- `digits/gateways/1b1/nusoap-php7.php:7086` — تابع/اکستنشن حذف‌شده
**Advanced Order Export For WooCommerce** (10 مورد):
- `woo-order-export-lite/classes/FPDF/class-woe-pdf-mc-table.php:549` — تابع/اکستنشن حذف‌شده
- `woo-order-export-lite/classes/PHPExcel/Calculation/DateTime.php:537` — تابع/اکستنشن حذف‌شده
- `woo-order-export-lite/classes/PHPExcel/Calculation/DateTime.php:557` — تابع/اکستنشن حذف‌شده
- `woo-order-export-lite/classes/PHPExcel/Calculation/DateTime.php:563` — تابع/اکستنشن حذف‌شده
- `woo-order-export-lite/classes/PHPExcel/Calculation/DateTime.php:566` — تابع/اکستنشن حذف‌شده
- `woo-order-export-lite/classes/PHPExcel/Reader/CSV.php:248` — تنظیم ini حذف‌شده
- `woo-order-export-lite/classes/PHPExcel/Reader/CSV.php:249` — تنظیم ini حذف‌شده
- `woo-order-export-lite/classes/PHPExcel/Reader/CSV.php:307` — تنظیم ini حذف‌شده
**Elementor** (6 مورد):
- `elementor/core/utils/import-export/parsers/wxr-parser-simple-xml.php:47` — تابع/اکستنشن حذف‌شده
- `elementor/core/utils/import-export/parsers/wxr-parser-simple-xml.php:53` — تابع/اکستنشن حذف‌شده
- `elementor/core/utils/import-export/wp-exporter.php:170` — تابع/اکستنشن حذف‌شده
- `elementor/core/utils/svg/svg-sanitizer.php:87` — تابع/اکستنشن حذف‌شده
- `elementor/core/utils/svg/svg-sanitizer.php:112` — تابع/اکستنشن حذف‌شده
- `elementor/modules/global-classes/global-classes-repository.php:35` — نوشتن ${var} داخل رشته
**Order Export & Order Import for WooCommerce** (6 مورد):
- `order-import-export-for-woocommerce/admin/classes/class-csvreader.php:39` — تنظیم ini حذف‌شده
- `order-import-export-for-woocommerce/admin/classes/class-csvreader.php:130` — تنظیم ini حذف‌شده
- `order-import-export-for-woocommerce/admin/classes/class-csvreader.php:262` — تابع/اکستنشن حذف‌شده
- `order-import-export-for-woocommerce/admin/modules/order/import/import.php:697` — تابع/اکستنشن حذف‌شده
- `order-import-export-for-woocommerce/admin/modules/order/import/import.php:758` — تابع/اکستنشن حذف‌شده
- `order-import-export-for-woocommerce/admin/modules/order/import/import.php:1611` — تابع/اکستنشن حذف‌شده
**Product Import Export for WooCommerce** (6 مورد):
- `product-import-export-for-woo/admin/classes/class-csvreader.php:39` — تنظیم ini حذف‌شده
- `product-import-export-for-woo/admin/classes/class-csvreader.php:130` — تنظیم ini حذف‌شده
- `product-import-export-for-woo/admin/classes/class-csvreader.php:263` — تابع/اکستنشن حذف‌شده
- `product-import-export-for-woo/admin/classes/class-csvwriter.php:122` — تابع/اکستنشن حذف‌شده
- `product-import-export-for-woo/admin/modules/product/export/class-wt-prodimpexpcsv-basic-exporter.php:662` — تابع/اکستنشن حذف‌شده
- `product-import-export-for-woo/admin/modules/product/export/export.php:648` — تابع/اکستنشن حذف‌شده
**Woodmart Core** (2 مورد):
- `woodmart-core/importer/parsers/class-wxr-parser-simplexml.php:21` — تابع/اکستنشن حذف‌شده
- `woodmart-core/importer/parsers/class-wxr-parser-simplexml.php:25` — تابع/اکستنشن حذف‌شده
**TeraWallet** (1 مورد):
- `woo-wallet/includes/export/class-terawallet-csv-exporter.php:414` — تابع/اکستنشن حذف‌شده
**WooCommerce** (1 مورد):
- `woocommerce/includes/admin/importers/class-wc-tax-rate-importer.php:128` — تابع/اکستنشن حذف‌شده

## چک‌لیست عملیاتی

1. بک‌آپ کامل و تست روی staging.
2. فعال کردن ionCube Loader و SourceGuardian Loader برای PHP 8.2 در هاست.
3. به‌روزرسانی وردپرس (حداقل 6.6) و افزونه‌های دارای خطای Fatal.
4. برای «فاکتور حرفه‌ای ووکامرس» و «المنتور پرو» نسخهٔ سازگار از ناشر بگیرید.
5. با Health Check افزونه‌ها را یکی‌یکی روی 8.2 فعال کنید.
6. `WP_DEBUG_LOG` را روشن کنید و `wp-content/debug.log` را بخوانید.

## روش و محدودیت‌ها

اسکن با PHP_CodeSniffer + PHPCompatibility روی موتور واقعی PHP 8.2.33 انجام شد (`testVersion 8.2-`) و سپس هر مورد بحرانی به‌صورت دستی بازبینی شد تا کدهای مرده حذف شوند. فایل‌های رمزگذاری‌شده قابل تحلیل نیستند و نیاز به تست روی هاست دارند.