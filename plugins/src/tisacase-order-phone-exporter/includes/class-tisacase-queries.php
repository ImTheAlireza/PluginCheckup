<?php
/**
 * کوئری‌های فقط-خواندنی دیتابیس (Cursor Pagination؛ سازگار با HPOS و Legacy).
 *
 * @package TisaCase_Order_Phone_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Phone_Exporter_Queries' ) ) {

	final class TisaCase_Phone_Exporter_Queries {

		/** تشخیص حافظه authoritative سفارش‌ها (HPOS یا Posts قدیمی). */
		public static function hpos_enabled() {
			if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
				return (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
			}

			return false;
		}

		/** شمارش سفارش‌ها در همه وضعیت‌های استاندارد ووکامرس (بدون سطل زباله). */
		public static function count_orders() {
			global $wpdb;

			if ( self::hpos_enabled() ) {
				$sql      = "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type = %s AND status LIKE %s";
				$prepared = $wpdb->prepare( $sql, 'shop_order', 'wc-%' );
			} else {
				$sql      = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status LIKE %s";
				$prepared = $wpdb->prepare( $sql, 'shop_order', 'wc-%' );
			}

			return (int) $wpdb->get_var( $prepared );
		}

		/**
		 * فقط ID + شماره تماس؛ بدون OFFSET عمیق، بدون ساخت آبجکت WC_Order.
		 * GROUP BY + MAX در حالت Legacy از تکرار سفارش در صورت وجود متای تکراری جلوگیری می‌کند
		 * و با sql_mode=ONLY_FULL_GROUP_BY (پیش‌فرض MySQL 5.7+) هم سازگار است.
		 */
		public static function fetch_rows_after( $cursor, $limit ) {
			global $wpdb;

			$cursor = absint( $cursor );
			$limit  = max( 1, min( TisaCase_Phone_Exporter::MAX_BATCH, absint( $limit ) ) );

			if ( self::hpos_enabled() ) {
				$sql = "
					SELECT o.id AS order_id, a.phone AS phone
					FROM {$wpdb->prefix}wc_orders o
					LEFT JOIN {$wpdb->prefix}wc_order_addresses a
						ON a.order_id = o.id
						AND a.address_type = 'billing'
					WHERE o.type = %s
					  AND o.status LIKE %s
					  AND o.id > %d
					ORDER BY o.id ASC
					LIMIT %d
				";

				return $wpdb->get_results(
					$wpdb->prepare( $sql, 'shop_order', 'wc-%', $cursor, $limit ),
					ARRAY_A
				);
			}

			$sql = "
				SELECT p.ID AS order_id, MAX(pm.meta_value) AS phone
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm
					ON pm.post_id = p.ID
					AND pm.meta_key = '_billing_phone'
				WHERE p.post_type = %s
				  AND p.post_status LIKE %s
				  AND p.ID > %d
				GROUP BY p.ID
				ORDER BY p.ID ASC
				LIMIT %d
			";

			return $wpdb->get_results(
				$wpdb->prepare( $sql, 'shop_order', 'wc-%', $cursor, $limit ),
				ARRAY_A
			);
		}
	}
}
