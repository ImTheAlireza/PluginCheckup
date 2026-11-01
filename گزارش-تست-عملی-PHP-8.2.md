# گزارش تست عملی سازگاری با PHP 8.2

تست واقعی روی WordPress 6.9.11 با موتور PHP 8.2.33 (ووکامرس فعال، هر افزونه یکی‌یکی نصب، فعال و روی صفحه‌های اصلی/مدیریت باز شد).

## خلاصه

- سایت را از کار می‌اندازد: **4**
- سایت بالاست ولی افزونه از کار می‌افتد: **2**
- بالا می‌آید، اخطار در لاگ: **6**
- خطای محیط تست (ربطی به 8.2 ندارد): **1**
- سالم: **43**


## سایت را از کار می‌اندازد (4)

### cta-button (`cta-button` · نسخه 1.0)

فایل includes.php با ionCube رمزگذاری شده؛ هنگام لودرِ افزونه، PHP پیام «Script error: the ionCube Loader …» را چاپ می‌کند و ادامه نمی‌دهد. هر ۱۱ صفحه‌ای که تست شد خطای 500 گرفتند.

```
Script error: the ionCube Loader for PHP needs to be installed. The ionCube Loader is the industry standard PHP extensio
```

صفحه‌های خراب: `/` (500)، `/wp-admin/` (500)، `/wp-admin/plugins.php` (500)، `/wp-admin/options-general.php` (500)، `/wp-admin/edit.php?post_type=product` (500)

### digits (`digits` · نسخه 9.2.1)

فایل اصلی با ionCube رمزگذاری شده و چون لودر 8.2 نصب نیست، PHP آن را «نویسهٔ ناشناس» می‌بیند و همان خط ۲ متوقف می‌شود؛ در نتیجه همهٔ صفحه‌ها (حتی پیشخوان) خطای 500 می‌گیرند. این همان چیزی است که شما «دان شدن سایت» می‌نامید.

```
خطای مرگبار
```

صفحه‌های خراب: `/` (500)، `/wp-admin/` (500)، `/wp-admin/plugins.php` (500)، `/wp-admin/options-general.php` (500)، `/wp-admin/edit.php?post_type=product` (500)

### elementor-pro (`elementor-pro` · نسخه 3.8.1)

فایل abzarwp/abzarwp-v13.php با SourceGuardian رمزگذاری شده و لودر ixed.8.2.ems را می‌خواهد. صفحه‌ها کد 200 می‌گیرند ولی محتوایشان فقط همان پیام خطاست، یعنی عملاً سایت از دسترس خارج است.

```
PHP script '/tmp/wp/wordpress/wp-content/plugins/elementor-pro/abzarwp/abzarwp-v13.php' is protected by SourceGuardian a
```

صفحه‌های خراب: `/` (200)، `/wp-admin/` (200)، `/wp-admin/plugins.php` (200)، `/wp-admin/options-general.php` (200)، `/wp-admin/edit.php?post_type=product` (200)

### zhaket-updater (`zhaket-updater` · نسخه 4.2.4)

فایل vendor/autoload.php با ionCube رمزگذاری شده و همان ابتدای لودِ افزونه صدا زده می‌شود؛ کل سایت 500 می‌شود.

```
Script error: the ionCube Loader for PHP needs to be installed. The ionCube Loader is the industry standard PHP extensio
```

صفحه‌های خراب: `/` (500)، `/wp-admin/` (500)، `/wp-admin/plugins.php` (500)، `/wp-admin/options-general.php` (500)، `/wp-admin/edit.php?post_type=product` (500)


## سایت بالاست ولی افزونه از کار می‌افتد (2)

### RTL-CareUnit (`RTL-CareUnit` · نسخه 2.5.2)

افزونه فعال می‌شود و سایت بالا می‌ماند، اما چون ionCube Loader نصب نیست در بالای همهٔ صفحه‌های مدیریت اخطار می‌دهد: «آخرین نسخه ionCube Loader روی سرور شما نصب و فعال باشد… افزونه قادر به ارائه خدمات نخواهد بود». پس سایت دان نمی‌شود، ولی عملاً این افزونه کار نمی‌کند.

```
لودر ندارد — سایت بالاست، افزونه بی‌کار
```

صفحه‌های خراب: `/wp-admin/` (200)، `/wp-admin/plugins.php` (200)، `/wp-admin/options-general.php` (200)، `/wp-admin/edit.php?post_type=product` (200)، `/wp-admin/admin.php?page=wc-settings` (200)

### woocommerce-invoice (`woocommerce-invoice` · نسخه 7.0.3)

برخلاف بقیهٔ افزونه‌های رمزگذاری‌شده، این یکی خوش‌رفتار است: در فایل اصلی بررسی می‌کند if ( ! function_exists( 'sg_load' ) ) و اگر لودر نباشد فقط یک اخطار مدیریتی نشان می‌دهد و return می‌کند. در تست هم دقیقاً همین اتفاق افتاد: سایت با کد 200 بالا ماند و در بالای همهٔ صفحه‌های مدیریت این پیام آمد — «هشدار: فعالسازی «فاکتور ووکامرس» انجام نشد. لودر سورس گاردین روی هاست شما فعال نیست…». پس سایت دان نمی‌شود، ولی خودِ افزونه بی‌کار می‌ماند.

```
سالم
```


## بالا می‌آید، اخطار در لاگ (6)

### code-snippets (`code-snippets` · نسخه 3.6.8)

اخطارهای Undefined array key در صفحهٔ خوش‌آمدگوییِ افزونه (php/views/welcome.php) که ریشه‌شان خالی ماندنِ پاسخِ API راهنما به‌خاطر بی‌اینترنتیِ محیط تست است. روی سایت واقعی با اینترنت احتمالاً دیده نمی‌شود.

```
با اخطار اجرا شد
```

### persian-woocommerce-shipping (`persian-woocommerce-shipping` · نسخه 4.2.8)

سایت بالا می‌ماند؛ فقط اخطارهای منسوخ‌شدگی «ایجاد ویژگی پویا» در کلاس‌های روش‌های حمل‌ونقل دیده شد. نکتهٔ مهم: خط create_function() در includes/admin/class-settings.php:113 که در تحلیل استاتیک بحرانی به‌نظر می‌رسید، در عمل اجرا نشد چون هیچ‌کدام از بخش‌های تعریف‌شده توضیح (desc) ندارند و آن شاخه هیچ‌وقت صدا زده نمی‌شود. با این حال یک بمب ساعتی است: با اولین بخشی که desc بگیرد، سایت فوراً خطای مرگبار می‌گیرد.

```
با اخطار اجرا شد
```

### SMSIRApp (`SMSIRApp` · نسخه 1.0.26)

فعال‌سازی به‌خاطر یک اخطار PHP 8 رد شد: Private methods cannot be final as they are never overridden by other classes در includes/SMSIRAppClass.php خط 42. متد final-private از PHP 8.0 به بعد اخطار می‌دهد. وقتی display_errors روشن است وردپرس جلوی فعال‌سازی را می‌گیرد («unexpected output»)؛ در سایت واقعی (نمایش خطا خاموش) فعال می‌ماند و فقط لاگ پر می‌شود.

```
OUTPUT: Warning:  Private methods cannot be final as they are never overridden by other classes in /tmp/wp/wordpress/wp-content/plugins/SMSIRApp/includes/SMSIRA
```

### snapppay-woocommerce-gateway (`snapppay-woocommerce-gateway` · نسخه 1.4.2)

یک اخطار PHP 7.4+ (Trying to access array offset on value of type bool در functions.php:330) به‌علاوه تعداد زیادی «ایجاد ویژگی پویا» (Dynamic property) که در PHP 8.2 منسوخ شده‌اند. سایت بالا می‌ماند.

```
OUTPUT: Warning:  Trying to access array offset on value of type bool in /tmp/wp/wordpress/wp-content/plugins/snapppay-woocommerce-gateway/functions.php on line
```

### surenapost (`surenapost` · نسخه 1.3.13)

سایت بالا می‌ماند؛ فقط اخطارهای منسوخ‌شدگیِ «فرستادن null به توابع رشته‌ای» (strpos() / str_replace()) از طرف این افزونه به هستهٔ وردپرس می‌رسد. بخش بزرگی از اخطارها هم به‌خاطر نبودِ اینترنت در محیط تست است (وب‌سرویس پست).

```
با اخطار اجرا شد
```

### tisacase-hub (`tisacase-hub` · نسخه 1.9.15)

یک اخطار Undefined array key "screens" در includes/class-tsh-admin.php:510؛ در PHP 7 یک Notice بود و از PHP 8 به بعد Warning است. سایت بالا می‌ماند و فقط لاگ پر می‌شود.

```
با اخطار اجرا شد
```


## خطای محیط تست (ربطی به 8.2 ندارد) (1)

### elementor (`elementor` · نسخه 3.27.4)

تنها خطای دیده‌شده روی صفحهٔ «پیشخوان المنتور» (wp-admin/admin.php?page=elementor) است: Filter_Plugins::get_add_ons_installation_status(): Argument #1 ($add_ons) must be of type array, null given. دلیلش نبودِ اینترنت در محیط تست است: المنتور داده‌های آن صفحه را از API خودش می‌گیرد و وقتی پاسخی نرسد آرایه خالی می‌ماند. این TypeError در PHP 7.4 هم رخ می‌دهد، پس ربطی به 8.2 ندارد. اگر هاست شما دسترسی خروجی (outbound) را بسته باشد همین خطا را می‌بینید.

```
[01-Nov-2026 12:55:58 UTC] PHP Fatal error:  Uncaught TypeError: Elementor\Modules\Home\Transformations\Filter_Plugins::get_add_ons_installation_status(): Argum
```

صفحه‌های خراب: `/wp-admin/admin.php?page=elementor` (500)، `/wp-admin/admin.php?page=elementor-system-info` (200)


## جدول کامل

| وضعیت | افزونه | پوشه | نسخه | فعال شد | مرگبار | منسوخ | اخطار | تحلیل استاتیک |
|---|---|---|---|---|---|---|---|---|
| سایت را از کار می‌اندازد | cta-button | `cta-button` | 1.0 | خیر | 0 | 0 | 0 | بحرانی |
| سایت را از کار می‌اندازد | digits | `digits` | 9.2.1 | خیر | 8 | 0 | 0 | بحرانی |
| سایت را از کار می‌اندازد | elementor-pro | `elementor-pro` | 3.8.1 | خیر | 0 | 0 | 0 | بحرانی |
| سایت را از کار می‌اندازد | zhaket-updater | `zhaket-updater` | 4.2.4 | خیر | 0 | 0 | 0 | بحرانی |
| سایت بالاست ولی افزونه از کار می‌افتد | RTL-CareUnit | `RTL-CareUnit` | 2.5.2 | بله | 0 | 0 | 0 | بحرانی |
| سایت بالاست ولی افزونه از کار می‌افتد | woocommerce-invoice | `woocommerce-invoice` | 7.0.3 | بله | 0 | 0 | 0 | بحرانی |
| بالا می‌آید، اخطار در لاگ | code-snippets | `code-snippets` | 3.6.8 | بله | 0 | 2 | 3 | تقریباً سالم |
| بالا می‌آید، اخطار در لاگ | persian-woocommerce-shipping | `persian-woocommerce-shipping` | 4.2.8 | بله | 0 | 40 | 1 | پرخطر |
| بالا می‌آید، اخطار در لاگ | SMSIRApp | `SMSIRApp` | 1.0.26 | خیر | 0 | 0 | 3 | تقریباً سالم |
| بالا می‌آید، اخطار در لاگ | snapppay-woocommerce-gateway | `snapppay-woocommerce-gateway` | 1.4.2 | خیر | 0 | 63 | 8 | تقریباً سالم |
| بالا می‌آید، اخطار در لاگ | surenapost | `surenapost` | 1.3.13 | بله | 0 | 42 | 0 | تقریباً سالم |
| بالا می‌آید، اخطار در لاگ | tisacase-hub | `tisacase-hub` | 1.9.15 | بله | 0 | 0 | 1 | تقریباً سالم |
| خطای محیط تست (ربطی به 8.2 ندارد) | elementor | `elementor` | 3.27.4 | بله | 1 | 1 | 8 | نیاز به توجه |
| سالم | admin-menu-editor | `admin-menu-editor` | 1.15.2 | بله | 0 | 0 | 0 | تقریباً سالم |
| سالم | ajax-search-for-woocommerce-premium | `ajax-search-for-woocommerce-premium` | 1.30.1 | بله | 0 | 0 | 0 | تقریباً سالم |
| سالم | bulk-product-cleaner | `bulk-product-cleaner` | 12.6.3 | بله | 0 | 0 | 0 | سالم |
| سالم | bulk-tracking-upload | `bulk-tracking-upload` | 3.7.0 | بله | 0 | 0 | 0 | تقریباً سالم |
| سالم | case-special-package | `case-special-package` | 1.5.4 | بله | 0 | 0 | 0 | سالم |
| سالم | digoneclickls | `digoneclickls` | 4.4 | بله | 0 | 0 | 0 | تقریباً سالم |
| سالم | litespeed-cache | `litespeed-cache` | 7.9.1 | بله | 0 | 0 | 0 | سالم |
| سالم | loco-translate | `loco-translate` | 2.7.1 | بله | 0 | 0 | 0 | تقریباً سالم |
| سالم | members | `members` | 3.2.18 | بله | 0 | 0 | 0 | سالم |
| سالم | order-import-export-for-woocommerce | `order-import-export-for-woocommerce` | 2.5.9 | بله | 0 | 0 | 0 | نیاز به توجه |
| سالم | page-links-to | `page-links-to` | 3.3.7 | بله | 0 | 0 | 0 | سالم |
| سالم | pepro-bacs-receipt-upload-for-woocommerce | `pepro-bacs-receipt-upload-for-woocommerce` | 2.8.0 | بله | 0 | 0 | 0 | سالم |
| سالم | persian-woocommerce | `persian-woocommerce` | 9.3.4 | بله | 0 | 0 | 0 | تقریباً سالم |
| سالم | plugin-check | `plugin-check` | 2.1.0 | بله | 0 | 0 | 0 | تقریباً سالم |
| سالم | product-import-export-for-woo | `product-import-export-for-woo` | 2.4.4 | بله | 0 | 0 | 0 | نیاز به توجه |
| سالم | sadad-woocommerce2 | `sadad-woocommerce2` | 5.1.0 | بله | 0 | 0 | 0 | تقریباً سالم |
| سالم | tisa-product-importer | `tisa-product-importer` | 0.8.0 | بله | 0 | 0 | 0 | سالم |
| سالم | tisacase-ariocase-sync | `tisacase-ariocase-sync` | 1.28.0 | بله | 0 | 0 | 0 | سالم |
| سالم | tisacase-bulk-stock-manager | `tisacase-bulk-stock-manager` | 1.4.3 | بله | 0 | 0 | 0 | سالم |
| سالم | tisacase-bulk-variation-manager | `tisacase-bulk-variation-manager` | 2.3.1 | بله | 0 | 0 | 0 | سالم |
| سالم | tisacase-card-to-card | `tisacase-card-to-card` | 1.4.0 | بله | 0 | 0 | 0 | سالم |
| سالم | tisacase-exporter | `tisacase-exporter` | 2.1.6 | بله | 0 | 0 | 0 | تقریباً سالم |
| سالم | tisacase-optimizer | `tisacase-optimizer` | 1.3.4 | بله | 0 | 0 | 0 | تقریباً سالم |
| سالم | tisacase-order-phone-exporter | `tisacase-order-phone-exporter` | 1.5.0 | بله | 0 | 0 | 0 | سالم |
| سالم | tisacase-order-wallet-reporter | `tisacase-order-wallet-reporter` | 1.0.0 | بله | 0 | 0 | 0 | سالم |
| سالم | tisacase-pricing | `tisacase-pricing` | 1.4.2 | بله | 0 | 0 | 0 | تقریباً سالم |
| سالم | tisacase-product-description | `tisacase-product-description` | 1.6.2 | بله | 0 | 0 | 0 | سالم |
| سالم | waitlist-woocommerce | `waitlist-woocommerce` | 2.8.4 | بله | 0 | 0 | 0 | تقریباً سالم |
| سالم | wc-sku-prefix-bar | `wc-sku-prefix-bar` | 1.5.3 | بله | 0 | 0 | 0 | سالم |
| سالم | wc-telegram-orders | `wc-telegram-orders` | 1.14.3 | بله | 0 | 0 | 0 | تقریباً سالم |
| سالم | woo-behpardakht | `woo-behpardakht` | 2.6.5 | بله | 0 | 0 | 0 | تقریباً سالم |
| سالم | woo-checkout-field-editor-pro | `woo-checkout-field-editor-pro` | 2.1.4 | بله | 0 | 0 | 0 | سالم |
| سالم | woo-order-export-lite | `woo-order-export-lite` | 3.6.0 | بله | 0 | 0 | 0 | پرخطر |
| سالم | woo-variation-swatches | `woo-variation-swatches` | 2.2.0 | بله | 0 | 0 | 0 | سالم |
| سالم | woo-variation-swatches-pro | `woo-variation-swatches-pro` | 2.2.0 | بله | 0 | 0 | 0 | سالم |
| سالم | woo-wallet | `woo-wallet` | 1.5.10 | بله | 0 | 0 | 0 | نیاز به توجه |
| سالم | woocommerce | `woocommerce` | 10.3.7 | بله | 0 | 0 | 0 | نیاز به توجه |
| سالم | woodmart-core | `woodmart-core` | 1.1.0 | بله | 0 | 0 | 0 | نیاز به توجه |
| سالم | wordpress-seo | `wordpress-seo` | 24.4 | بله | 0 | 0 | 0 | تقریباً سالم |
| سالم | wp-optimize | `wp-optimize` | 4.5.3 | بله | 0 | 0 | 0 | تقریباً سالم |
| سالم | wp-parsidate | `wp-parsidate` | 5.1.5 | بله | 0 | 0 | 0 | پرخطر |
| سالم | wp-statistics | `wp-statistics` | 14.12.6 | بله | 0 | 0 | 0 | تقریباً سالم |
| سالم | zhaket-woo-sep | `zhaket-woo-sep` | 1.3.0 | بله | 0 | 0 | 0 | تقریباً سالم |

## روش تست و محدودیت‌ها

- وردپرس 6.9.11 + PHP 8.2.33؛ ووکامرس به‌عنوان پایه فعال بود.
- برای هر افزونه: کپی → فعال‌سازی → درج در `active_plugins` (شبیه‌سازی «افزونه از قبل فعال است») → باز کردن صفحهٔ اصلی، پیشخوان، فهرست افزونه‌ها، تنظیمات، محصولات، تنظیمات ووکامرس، حمل‌ونقل و صفحات اختصاصی خود افزونه → خواندن debug.log → غیرفعال‌سازی و حذف.
- هیچ لودر ionCube / SourceGuardian در محیط تست نصب نیست (همان وضعیت هاستی که لودر 8.2 ندارد).
- اینترنت نداشتیم و به‌جای MySQL از SQLite استفاده شد؛ خطاهای وابسته به این دو فیلتر شدند.
- مسیرهایی مثل پرداخت آنلاین، ارسال پیامک و خروجی فایل هنوز روی سایت واقعی باید چک شوند.
