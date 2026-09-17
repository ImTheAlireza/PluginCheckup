<?php
/**
 * هسته افزونه: ثابت‌ها، تنظیمات قابل‌فیلتر و اتصال هوک‌ها.
 *
 * @package TisaCase_Order_Phone_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Phone_Exporter' ) ) {

	final class TisaCase_Phone_Exporter {

		/* -----------------------------------------------------------------
		 * تنظیمات
		 * ----------------------------------------------------------------- */

		const MENU_SLUG     = 'tisacase-order-phone-exporter';
		const AJAX_START    = 'tisacase_phone_export_start_v120';
		const AJAX_PROCESS  = 'tisacase_phone_export_process_v120';
		const AJAX_CANCEL   = 'tisacase_phone_export_cancel_v120';
		const DOWNLOAD      = 'tisacase_phone_export_download_v120';
		const NONCE_ACTION  = 'tisacase_phone_export_nonce_v120';
		const CRON_HOOK     = 'tisacase_phone_export_sweep';
		const STATE_TTL     = 43200;   // اعتبار جلسه خروجی: ۱۲ ساعت.
		const LOCK_TTL      = 120;     // قفل همزمانی: حداکثر ۲ دقیقه بین دو Batch.
		const FILE_TTL      = 86400;   // پاک‌سازی خودکار فایل‌های قدیمی‌تر از ۲۴ ساعت.
		const BASE_DIR_NAME = 'tisacase-private-phone-exports';
		const WORKING_FILE  = 'working.txt';
		const CHUNK_LINES   = 100000;  // حداکثر خطوط هر تکهٔ مرتب‌سازی (سقف حافظهٔ Dedup).
		const WRITE_BUF     = 65536;   // بافر نوشتن (بایت).
		const MIN_BATCH     = 100;
		const MAX_BATCH     = 2000;
		const TEXT_DOMAIN   = 'tisacase-order-phone-exporter';

		/** اندازه هر Batch (با فیلتر قابل تنظیم برای قدرت هاست‌های مختلف). */
		public static function batch_size() {
			$size = (int) apply_filters( 'tisacase_phone_export_batch_size', 1000 );
			return max( self::MIN_BATCH, min( self::MAX_BATCH, $size ) );
		}

		/** تعداد شماره در هر فایل خروجی (با فیلتر قابل تنظیم). */
		public static function file_size() {
			$size = (int) apply_filters( 'tisacase_phone_export_file_size', 10000 );
			return max( 100, $size );
		}

		/* -----------------------------------------------------------------
		 * راه‌اندازی
		 * ----------------------------------------------------------------- */

		public static function init() {
			if ( is_admin() ) {
				add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
				add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
				add_action( 'wp_ajax_' . self::AJAX_START, array( 'TisaCase_Phone_Exporter_Ajax', 'ajax_start' ) );
				add_action( 'wp_ajax_' . self::AJAX_PROCESS, array( 'TisaCase_Phone_Exporter_Ajax', 'ajax_process' ) );
				add_action( 'wp_ajax_' . self::AJAX_CANCEL, array( 'TisaCase_Phone_Exporter_Ajax', 'ajax_cancel' ) );
				add_action( 'admin_post_' . self::DOWNLOAD, array( 'TisaCase_Phone_Exporter_Download', 'download_file' ) );
				add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_hpos_compatibility' ) );

				if ( function_exists( 'load_plugin_textdomain' ) ) {
					load_plugin_textdomain(
						self::TEXT_DOMAIN,
						false,
						dirname( plugin_basename( TISA_PHONE_EXPORTER_FILE ) ) . '/languages'
					);
				}
			}

			// پاک‌سازی ساعتیِ فایل‌های موقت قدیمی (حریم خصوصی + دیسک).
			add_action( self::CRON_HOOK, array( 'TisaCase_Phone_Exporter_Storage', 'sweep_old_exports' ) );
		}

		/** اعلام سازگاری با HPOS (جدول‌های سفارش اختصاصی ووکامرس). */
		public static function declare_hpos_compatibility() {
			if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
					'custom_order_tables',
					TISA_PHONE_EXPORTER_FILE,
					true
				);
			}
		}

		/** استایل صفحهٔ خودمان — بعد از لایهٔ توکن هاب اگر فعال باشد. */
		public static function admin_assets( $hook ) {
			if ( false === strpos( (string) $hook, self::MENU_SLUG ) ) {
				return;
			}
			$deps = wp_style_is( 'tisacase-ui', 'registered' ) ? array( 'tisacase-ui' ) : array();
			wp_enqueue_style( 'tisacase-phone-exporter', plugins_url( 'assets/admin.css', TISA_PHONE_EXPORTER_FILE ), $deps, TISA_PHONE_EXPORTER_VERSION );
		}

		public static function admin_menu() {
			add_submenu_page(
				'woocommerce',
				__( 'خروجی شماره تماس سفارش‌ها', self::TEXT_DOMAIN ),
				__( 'خروجی شماره تماس‌ها', self::TEXT_DOMAIN ),
				'manage_woocommerce',
				self::MENU_SLUG,
				array( 'TisaCase_Phone_Exporter_Admin_Page', 'admin_page' )
			);
		}
	}
}
