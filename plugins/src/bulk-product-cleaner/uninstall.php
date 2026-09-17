<?php
/**
 * Uninstall routine.
 *
 * Removes plugin options, transients and (optionally) backup archives.
 * Backups are preserved by default; define BDC_DELETE_BACKUPS_ON_UNINSTALL
 * as true in wp-config.php to wipe them too.
 *
 * @package BulkProductCleaner
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// 1) Clear scheduled retention cron.
wp_clear_scheduled_hook( 'bdc_purge_expired_backups' );

// 2) Remove options and any leftover transients.
delete_option( 'bdc_backup_index' );
delete_option( 'bdc_db_version' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_bdc\_%'
	    OR option_name LIKE '\_transient\_timeout\_bdc\_%'
	    OR option_name LIKE 'bdc\_%'"
);

// 3) Multisite: repeat per site.
if ( is_multisite() ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );

	foreach ( (array) $blog_ids as $blog_id ) {
		switch_to_blog( (int) $blog_id );

		delete_option( 'bdc_backup_index' );
		delete_option( 'bdc_db_version' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '\_transient\_bdc\_%'
			    OR option_name LIKE '\_transient\_timeout\_bdc\_%'
			    OR option_name LIKE 'bdc\_%'"
		);

		restore_current_blog();
	}
}

// 4) Backup archives are intentionally kept unless explicitly opted out.
if ( defined( 'BDC_DELETE_BACKUPS_ON_UNINSTALL' ) && BDC_DELETE_BACKUPS_ON_UNINSTALL ) {
	$uploads = wp_get_upload_dir();
	$root    = isset( $uploads['basedir'] ) ? $uploads['basedir'] . '/bdc-backups' : '';

	if ( $root && is_dir( $root ) ) {
		$real = realpath( $root );
		$base = realpath( isset( $uploads['basedir'] ) ? $uploads['basedir'] : '' );

		if ( $real && $base && 0 === strpos( $real, $base ) && $real !== $base ) {
			try {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $real, FilesystemIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::CHILD_FIRST
				);

				foreach ( $iterator as $item ) {
					if ( $item->isDir() ) {
						@rmdir( $item->getPathname() ); // phpcs:ignore
					} else {
						@unlink( $item->getPathname() ); // phpcs:ignore
					}
				}

				@rmdir( $real ); // phpcs:ignore
			} catch ( Exception $e ) {
				// Nothing sensible to do during uninstall.
			}
		}
	}
}
