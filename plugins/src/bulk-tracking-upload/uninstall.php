<?php
/**
 * پاک‌سازی هنگام حذف افزونهٔ «آپلود انبوه کد رهگیری».
 *
 * این افزونه هیچ option یا cron ندارد؛ تنها دادهٔ ماندگارِ خودش transientهای
 * محدودسازی تلاش پیگیری (bwt_track_fail_*) است.
 *
 * توجه: متای `_tracking_code` روی سفارش‌ها عمداً حذف نمی‌شود — دادهٔ سفارش است
 * و ممکن است قالب/ایمیل فروشگاه به آن مراجعه کند؛ حذفش خلل ایجاد می‌کند.
 *
 * @package Bulk_Tracking_Upload
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// transientها در جدول options با پیشوند _transient_ ذخیره می‌شوند.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_bwt\_track\_fail\_%' OR option_name LIKE '\_transient\_timeout\_bwt\_track\_fail\_%'" );
