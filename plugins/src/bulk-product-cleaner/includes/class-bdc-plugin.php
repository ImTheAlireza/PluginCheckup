<?php
/**
 * Plugin bootstrap / service container.
 *
 * @package BulkProductCleaner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BDC_Plugin
 */
class BDC_Plugin {

	/**
	 * Retention cron hook name.
	 *
	 * Mirrors BDC_Install::CRON_PURGE so the front-end boot path never has to
	 * autoload that class merely to read a string.
	 */
	const CRON_PURGE = 'bdc_purge_expired_backups';

	/**
	 * Singleton instance.
	 *
	 * @var BDC_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Backup engine.
	 *
	 * @var BDC_Backup|null
	 */
	public $backup = null;

	/**
	 * Deleter.
	 *
	 * @var BDC_Deleter|null
	 */
	public $deleter = null;

	/**
	 * Admin UI.
	 *
	 * @var BDC_Admin|null
	 */
	public $admin = null;

	/**
	 * AJAX controller.
	 *
	 * @var BDC_Ajax|null
	 */
	public $ajax = null;

	/**
	 * Whether WooCommerce was detected.
	 *
	 * @var bool
	 */
	private $has_woocommerce = false;

	/**
	 * Returns the singleton.
	 *
	 * @return BDC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->boot();
	}

	/**
	 * Prevents cloning.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Prevents unserialization.
	 *
	 * @throws Exception Always.
	 * @return void
	 */
	public function __wakeup() {
		throw new Exception( 'Cannot unserialize BDC_Plugin.' );
	}

	/**
	 * Wires everything up.
	 *
	 * @return void
	 */
	private function boot() {
		$is_cli = ( defined( 'WP_CLI' ) && WP_CLI );

		// The retention cron must be reachable in every context, but simply
		// registering a callback is free — nothing is loaded until it fires.
		// The hook name is a literal here on purpose: referencing
		// BDC_Install::CRON_PURGE would trigger the autoloader on every
		// front-end request just to read a constant.
		add_action( self::CRON_PURGE, array( __CLASS__, 'run_purge' ) );

		// A front-end page view needs nothing else from this plugin: no
		// text domain, no option reads, no object graph, no cron probing,
		// and — critically — no class files parsed at all.
		if ( ! is_admin() && ! $is_cli && ! wp_doing_cron() ) {
			return;
		}

		// Translations are only ever rendered in the admin / CLI.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Self-heal a missing schedule, but at most once a day and never on
		// the front end. wp_next_scheduled() reads the cron option, so we
		// gate it behind a cheap transient.
		$this->maybe_ensure_cron();

		$this->has_woocommerce = class_exists( 'WooCommerce' );

		if ( ! $this->has_woocommerce ) {
			if ( is_admin() ) {
				add_action( 'admin_notices', array( $this, 'missing_woocommerce_notice' ) );
			}
			return;
		}

		if ( is_admin() ) {
			$this->admin = new BDC_Admin();
			$this->ajax  = new BDC_Ajax();
		}

		if ( $is_cli ) {
			WP_CLI::add_command( 'bdc', 'BDC_CLI' );
		}
	}

	/**
	 * Loads translations. Hooked to `init` so it never fires early.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'bulk-product-cleaner',
			false,
			dirname( BDC_BASENAME ) . '/languages'
		);
	}

	/**
	 * Cron callback wrapper, so the backup class is only loaded when the
	 * purge actually runs.
	 *
	 * @return int
	 */
	public static function run_purge() {
		return BDC_Backup::purge_expired();
	}

	/**
	 * Ensures the retention cron exists without paying for a cron-option read
	 * on every admin request.
	 *
	 * @return void
	 */
	private function maybe_ensure_cron() {
		if ( false !== get_transient( 'bdc_cron_checked' ) ) {
			return;
		}

		set_transient( 'bdc_cron_checked', 1, DAY_IN_SECONDS );

		if ( ! wp_next_scheduled( self::CRON_PURGE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_PURGE );
		}
	}

	/**
	 * Lazily builds the backup engine.
	 *
	 * @return BDC_Backup
	 */
	public function backup() {
		if ( null === $this->backup ) {
			$this->backup = new BDC_Backup();
		}

		return $this->backup;
	}

	/**
	 * Lazily builds the deleter.
	 *
	 * @return BDC_Deleter
	 */
	public function deleter() {
		if ( null === $this->deleter ) {
			$this->deleter = new BDC_Deleter( $this->backup() );
		}

		return $this->deleter;
	}

	/**
	 * Admin notice when WooCommerce is absent.
	 *
	 * @return void
	 */
	public function missing_woocommerce_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>'
			. esc_html__( 'حذف انبوه پیش‌نویس', 'bulk-product-cleaner' )
			. '</strong> — '
			. esc_html__( 'این افزونه برای کار کردن به ووکامرس فعال نیاز دارد.', 'bulk-product-cleaner' )
			. '</p></div>';
	}
}
