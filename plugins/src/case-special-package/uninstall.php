<?php
/**
 * پاک‌سازی هنگام حذف افزونهٔ «پکیج ویژه قاب موبایل».
 *
 * حذف: option تنظیمات (wcsp_settings) و transient آمار (wcsp_stats_v1).
 *
 * توجه: متاهای محصول (`_wcsp_mode`, `_wcsp_package_price`) و متای آیتم سفارش
 * (`_wcsp_package_unit_price`, `_wcsp_package_total`) عمداً حذف نمی‌شوند —
 * اولی تنظیم محصول است (در صورت نصب مجدد برمی‌گردد) و دومی دادهٔ تاریخی
 * سفارش‌هاست که فاکتورها/گزارش‌ها به آن تکیه می‌کنند.
 *
 * @package Case_Special_Package
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wcsp_settings' );
delete_transient( 'wcsp_stats_v1' );
