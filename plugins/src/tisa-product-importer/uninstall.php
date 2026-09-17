<?php
/**
 * پاک‌سازی هنگام حذف افزونهٔ «ایمپورتر محصول تیسا».
 *
 * این افزونه option ندارد؛ دادهٔ ماندگارش transientهای حالت «شارژ محصول»
 * (tisa_update_*) است که خودکار یک‌ساعته منقضی می‌شوند، ولی برای تمیزی کامل
 * در حذف، صریحاً پاک می‌شوند.
 *
 * محصولات ساخته‌شده عمداً حذف نمی‌شوند — محتوای فروشگاه‌اند، نه دادهٔ افزونه.
 *
 * @package Tisa_Product_Importer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_tisa\_update\_%' OR option_name LIKE '\_transient\_timeout\_tisa\_update\_%'" );
