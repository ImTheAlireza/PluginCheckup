=== TisaCase Bulk Variation Manager ===
Contributors: Alireza Shabanzadeh
Tags: woocommerce, bulk edit, variations, phone cases, space case
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 5.0
WC tested up to: 9.2
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

مدیریت گروهی هوشمند متغیرها، مدل‌های گوشی و ویژگی‌های محصولات ووکامرس برای قاب‌های اسپیس و چاپی تیساکیس.

== Description ==

افزونه اختصاصی مدیریت انبوه متغیرها، افزودن سری‌های جدید گوشی (مانند سری آیفون ۱۶ و گلکسی S24)، حذف مدل‌های قدیمی، تغییر قیمت بر اساس مدل مرجع و بازگردانی خودکار (Rollback).

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory, or upload the zip via WordPress admin.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Access the plugin via WooCommerce -> Products -> TisaCase Variation Manager, or via TisaCase Hub menu.

== Changelog ==

= 1.0.1 =
* رفع باگ منوی انتخاب دسته‌بندی: گزینه‌ها با مقدار و نام خالی رندر می‌شدند (دسترسی شیءوار به آرایهٔ دسته‌ها) و کشویی عملاً بدون آیتم بود؛ اکنون شناسه، نام و تعداد محصول هر دسته درست نمایش داده می‌شود.
* ساخت سلکت۲ از مسیر استاندارد ووکامرس (رویداد wc-enhanced-select-init) به‌جای ساخت دستی و ساختهٔ دوباره؛ پشتیبان مستقیم select2.css و پیام روشن در صورت نبود دسته‌بندی.
* پنهان‌سازی تضمینی سلکت اصلی بعد از فعال‌شدن سلکت۲ (رفع تداخل با استایل‌های !important فیلدها).

= 1.0.0 =
* Initial release with full phone case variations bulk management, AJAX batching, model presets, and rollback.
