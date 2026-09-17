<?php
/**
 * پاک‌سازی هنگام حذف افزونهٔ «نوار پیشوند SKU».
 *
 * تنها دادهٔ ماندگار افزونه، transient کش SKUها (wcspb_latest_skus) است.
 * این افزونه چیزی روی محصولات نمی‌نویسد (SKUها دادهٔ خود ووکامرس‌اند).
 *
 * @package WC_SKU_Prefix_Bar
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_transient( 'wcspb_latest_skus' );
