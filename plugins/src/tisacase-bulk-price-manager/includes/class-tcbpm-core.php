<?php
/**
 * هستهٔ افزونه: تنظیمات، قابلیت‌ها و ثبات‌ها.
 *
 * @package TisaCase_Bulk_Price_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCBPM_Core' ) ) {

	final class TCBPM_Core {

		const MAIN_PAGE = 'tisacase-bulk-price-manager';

		const NONCE     = 'tcbpm_nonce';
		const OPTION    = 'tcbpm_settings';
		const DB_VERSION = '2.0.0';

		const AJAX_PREVIEW        = 'tcbpm_preview';
		const AJAX_RUN            = 'tcbpm_run';
		const AJAX_FINISH         = 'tcbpm_run_finish';
		const AJAX_ROLLBACK_START = 'tcbpm_rollback_start';
		const AJAX_ROLLBACK_PAGE  = 'tcbpm_rollback_page';
		const AJAX_EXPORT         = 'tcbpm_export_csv';
		const AJAX_CANCEL         = 'tcbpm_cancel_scheduled';
		const AJAX_SEARCH         = 'tcbpm_search_wholesale_products';

		const CRON_TICK   = 'tcbpm_process_scheduled_tick';
		const CRON_CLEAN  = 'tcbpm_daily_cleanup';
		const CRON_META   = 'tcbpm_cron_event_pending';

		/** بیشترین درصد مجاز برای عملیات درصدی (جلوگیری از overflow). */
		const PERCENT_CEIL = 100000.0;
		/** سقف نتیجهٔ هر محاسبهٔ قیمت؛ بالاتر خطا می‌دهیم تا INF/داده خراب نسازیم. */
		const RESULT_CEIL  = 1e14;

		private static $instance = null;

		private function __construct() {
			$this->hooks();
		}

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function hooks() {
			add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ) );
			add_action( 'admin_menu', array( 'TCBPM_Admin', 'menus' ) );
			add_action( 'admin_enqueue_scripts', array( 'TCBPM_Admin', 'assets' ) );
			add_action( 'admin_notices', array( 'TCBPM_Admin', 'notices' ) );

			add_action( 'wp_ajax_' . self::AJAX_PREVIEW, array( 'TCBPM_Ajax', 'ajax_preview' ) );
			add_action( 'wp_ajax_' . self::AJAX_RUN, array( 'TCBPM_Ajax', 'ajax_run' ) );
			add_action( 'wp_ajax_' . self::AJAX_FINISH, array( 'TCBPM_Ajax', 'ajax_finish' ) );
			add_action( 'wp_ajax_' . self::AJAX_ROLLBACK_START, array( 'TCBPM_Ajax', 'ajax_rollback_start' ) );
			add_action( 'wp_ajax_' . self::AJAX_ROLLBACK_PAGE, array( 'TCBPM_Ajax', 'ajax_rollback_page' ) );
			add_action( 'wp_ajax_' . self::AJAX_EXPORT, array( 'TCBPM_Ajax', 'ajax_export_csv' ) );
			add_action( 'wp_ajax_' . self::AJAX_CANCEL, array( 'TCBPM_Ajax', 'ajax_cancel_scheduled' ) );
			add_action( 'wp_ajax_' . self::AJAX_SEARCH, array( 'TCBPM_Ajax', 'ajax_search_wholesale_products' ) );

			// بررسی و ارتقای جدول‌ها هنگام باز شدن پیشخوان (بدون نیاز به deactivate/activate).
			add_action( 'admin_init', array( 'TCBPM_DB', 'maybe_install' ) );
			add_action( 'init', array( 'TCBPM_Scheduler', 'register_cron' ) );
			add_action( self::CRON_TICK, array( 'TCBPM_Scheduler', 'cron_tick' ) );
			add_action( self::CRON_CLEAN, array( 'TCBPM_DB', 'cron_cleanup' ) );

			add_filter( 'plugin_action_links_' . plugin_basename( TCBPM_FILE ), array( 'TCBPM_Admin', 'action_links' ) );
		}

		public static function load_textdomain() {
			load_plugin_textdomain( 'tisacase-bulk-price-manager', false, dirname( plugin_basename( TCBPM_FILE ) ) . '/languages' );
		}

		/**
		 * آیا ووکامرس فعال است؟
		 */
		public static function wc_active() {
			return function_exists( 'WC' ) && function_exists( 'wc_get_product' );
		}

		/* -----------------------------------------------------------------
		 * تنظیمات
		 * --------------------------------------------------------------- */

		public static function defaults() {
			return array(
				'min_capability'   => 'manage_woocommerce', // یا manage_options
				'batch_size'       => 5,
				'confirm_threshold'=> 500,
				'lock_minutes'     => 20,
				'retention_days'   => 90,
				'logging'          => 1,
				'rollback'         => 1,
				'scheduled'        => 1,
				'max_amount'       => 1000000000, // بیشترین مبلغ مجاز ورودی (set / ثابت)
				'sample_size'      => 8,
				'cron_pages'       => 20,
			);
		}

		public static function get_settings() {
			$saved = get_option( self::OPTION, array() );
			if ( ! is_array( $saved ) ) {
				$saved = array();
			}
			return wp_parse_args( $saved, self::defaults() );
		}

		public static function setting( $key ) {
			$s = self::get_settings();
			return isset( $s[ $key ] ) ? $s[ $key ] : null;
		}

		public static function update_settings( $raw ) {
			$defaults = self::defaults();
			$booleans = array( 'logging', 'rollback', 'scheduled' );
			$clean    = array();
			foreach ( $defaults as $k => $v ) {
				$clean[ $k ] = $v;
				if ( in_array( $k, $booleans, true ) ) {
					// چک‌باکس: اگر در درخواست نباشد یعنی خاموش است.
					$clean[ $k ] = empty( $raw[ $k ] ) ? 0 : 1;
					continue;
				}
				if ( ! isset( $raw[ $k ] ) || '' === $raw[ $k ] ) {
					continue;
				}
				$val = $raw[ $k ];
				if ( is_int( $v ) ) {
					$clean[ $k ] = max( 1, absint( $val ) );
				} elseif ( is_float( $v ) ) {
					$clean[ $k ] = max( 0, (float) $val );
				}
			}
			$cap = isset( $raw['min_capability'] ) ? sanitize_key( $raw['min_capability'] ) : '';
			$clean['min_capability'] = in_array( $cap, array( 'manage_woocommerce', 'edit_products', 'manage_options' ), true )
				? $cap
				: $defaults['min_capability'];
			update_option( self::OPTION, $clean );
			return $clean;
		}

		/**
		 * مجاز بودن کاربر جاری برای کار با افزونه.
		 */
		public static function can() {
			if ( ! is_user_logged_in() ) {
				return false;
			}
			return current_user_can( self::setting( 'min_capability' ) );
		}

		/**
		 * آیا لاگ/بازگردانی فعال است؟ هر دو به هم گره خورده‌اند.
		 */
		public static function logging_enabled() {
			return ! empty( self::setting( 'logging' ) );
		}

		public static function rollback_enabled() {
			return self::logging_enabled() && ! empty( self::setting( 'rollback' ) );
		}

		public static function batch_size() {
			return max( 1, min( 100, absint( self::setting( 'batch_size' ) ) ) );
		}

		public static function lock_minutes() {
			return max( 2, absint( self::setting( 'lock_minutes' ) ) );
		}

		public static function confirm_threshold() {
			return max( 0, absint( self::setting( 'confirm_threshold' ) ) );
		}

		public static function max_amount() {
			$v = (float) self::setting( 'max_amount' );
			return $v > 0 ? $v : 1000000000;
		}

		public static function sample_size() {
			return max( 1, min( 50, absint( self::setting( 'sample_size' ) ) ) );
		}

		public static function translation( $key ) {
			$labels = array(
				'bulk'      => 'اجرای گروهی',
				'scheduled' => 'اجرای زمان‌بندی‌شده',
				'rollback'  => 'بازگردانی',
				'running'   => 'در حال اجرا',
				'queued'    => 'در صف زمان‌بندی',
				'done'      => 'کامل شد',
				'stopped'   => 'متوقف شد',
				'failed'    => 'ناموفق',
				'interrupted' => 'ناتمام (قابل ادامه)',
				'rolled_back' => 'بازگردانی شد',
				'cancelled'   => 'انصراف داده شد',
			);
			return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
		}
	}
}
