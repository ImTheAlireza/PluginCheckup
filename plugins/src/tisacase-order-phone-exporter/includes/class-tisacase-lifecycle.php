<?php
/**
 * چرخه حیات افزونه: فعال‌سازی، غیرفعال‌سازی و حذف کامل (Uninstall).
 *
 * @package TisaCase_Order_Phone_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Phone_Exporter_Lifecycle' ) ) {

	final class TisaCase_Phone_Exporter_Lifecycle {

		public static function activate() {
			if ( ! wp_next_scheduled( TisaCase_Phone_Exporter::CRON_HOOK ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', TisaCase_Phone_Exporter::CRON_HOOK );
			}
		}

		public static function deactivate() {
			wp_clear_scheduled_hook( TisaCase_Phone_Exporter::CRON_HOOK );
			TisaCase_Phone_Exporter_Storage::sweep_old_exports( true );
		}

		/** حذف کامل ردپاها هنگام Uninstall (فایل‌ها، کرون و transientهای جدول options). */
		public static function uninstall() {
			wp_clear_scheduled_hook( TisaCase_Phone_Exporter::CRON_HOOK );

			foreach ( TisaCase_Phone_Exporter_Storage::base_dirs() as $base ) {
				if ( is_string( $base ) && is_dir( $base ) ) {
					TisaCase_Phone_Exporter_Storage::delete_directory( $base );
				}
			}

			global $wpdb;

			$prefixes = array( '_transient_tisacase_', '_transient_timeout_tisacase_' );
			foreach ( $prefixes as $prefix ) {
				$like = $wpdb->esc_like( $prefix ) . '%';
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
			}

			/*
			 * نکته: اگر Object Cache خارجی فعال باشد، transientهای آن به‌صورت طبیعی با TTL
			 * منقضی می‌شوند. عمداً هیچ کشی flush نمی‌شود تا به کارایی سایت آسیب نرسد.
			 */
		}
	}
}
