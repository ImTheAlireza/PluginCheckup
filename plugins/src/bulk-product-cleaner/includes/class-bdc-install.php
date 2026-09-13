<?php
/**
 * Activation / deactivation / directory bootstrap.
 *
 * @package BulkProductCleaner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BDC_Install
 */
class BDC_Install {

	const CRON_PURGE = 'bdc_purge_expired_backups';
	const OPT_DB_VER = 'bdc_db_version';
	const DB_VERSION = '12.0.0';

	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public static function activate() {
		self::ensure_directories();

		if ( ! wp_next_scheduled( self::CRON_PURGE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_PURGE );
		}

		update_option( self::OPT_DB_VER, self::DB_VERSION, false );
	}

	/**
	 * Runs on plugin deactivation. Backups are intentionally preserved.
	 *
	 * @return void
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::CRON_PURGE );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_PURGE );
		}
		wp_clear_scheduled_hook( self::CRON_PURGE );
	}

	/**
	 * Absolute path to the backup root directory (no trailing slash).
	 *
	 * @return string
	 */
	public static function backup_root() {
		$uploads = wp_get_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';

		/**
		 * Filters the absolute path of the backup root directory.
		 *
		 * @param string $path Absolute path without trailing slash.
		 */
		return untrailingslashit( apply_filters( 'bdc_backup_root', $base . '/bdc-backups' ) );
	}

	/**
	 * Creates the protected backup directory structure.
	 *
	 * @return bool True when the directory exists and is writable.
	 */
	public static function ensure_directories() {
		$root = self::backup_root();

		if ( ! file_exists( $root ) && ! wp_mkdir_p( $root ) ) {
			return false;
		}

		// Deny direct HTTP access (Apache 2.2 + 2.4).
		$htaccess = $root . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules  = "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n";
			$rules .= "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";
			self::write_file( $htaccess, $rules );
		}

		// Silence is golden (directory listing fallback).
		$index = $root . '/index.php';
		if ( ! file_exists( $index ) ) {
			self::write_file( $index, "<?php\n// Silence is golden.\n" );
		}

		// nginx hint file for admins.
		$readme = $root . '/README.txt';
		if ( ! file_exists( $readme ) ) {
			self::write_file(
				$readme,
				"Bulk Product Cleaner backups.\n"
				. "Retention: " . BDC_RETENTION_DAYS . " days.\n"
				. "Do NOT delete manually unless you are sure; these files power the Restore feature.\n"
				. "If you use nginx, add:  location ~* /bdc-backups/ { deny all; }\n"
			);
		}

		return wp_is_writable( $root );
	}

	/**
	 * Writes a file using WP_Filesystem when available, falling back to file_put_contents.
	 *
	 * @param string $path     Absolute file path.
	 * @param string $contents File contents.
	 * @return bool
	 */
	public static function write_file( $path, $contents ) {
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();
		}

		if ( $wp_filesystem && method_exists( $wp_filesystem, 'put_contents' ) ) {
			return (bool) $wp_filesystem->put_contents( $path, $contents, FS_CHMOD_FILE );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false !== @file_put_contents( $path, $contents ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
