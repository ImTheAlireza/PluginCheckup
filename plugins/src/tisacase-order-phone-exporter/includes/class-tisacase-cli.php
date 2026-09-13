<?php
/**
 * دستور WP-CLI: wp tisacase export-phones --user=admin [--dedup] [--file=phones.txt]
 * سریع‌ترین راه خروجی برای فروشگاه‌های خیلی بزرگ.
 *
 * @package TisaCase_Order_Phone_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Phone_Exporter_Cli' ) ) {

	final class TisaCase_Phone_Exporter_Cli {

		public static function cli_export( $args, $assoc ) {
			if ( ! class_exists( 'WooCommerce' ) ) {
				\WP_CLI::error( 'WooCommerce is not active.' );
				return;
			}

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				\WP_CLI::error( 'Run this command with --user=<admin>.' );
				return;
			}

			$file  = isset( $assoc['file'] ) ? $assoc['file'] : 'phones-989.txt';
			$dedup = ! empty( $assoc['dedup'] );

			$handle = @fopen( $file, 'wb' );
			if ( ! $handle ) {
				\WP_CLI::error( "Cannot open file for writing: {$file}" );
				return;
			}

			$cursor = 0;
			$total  = 0;
			$seen   = array();

			\WP_CLI::log( 'Exporting order phones...' );

			while ( true ) {
				$rows = TisaCase_Phone_Exporter_Queries::fetch_rows_after( $cursor, TisaCase_Phone_Exporter::MAX_BATCH );

				if ( ! is_array( $rows ) ) {
					fclose( $handle );
					\WP_CLI::error( 'Database read failed.' );
					return;
				}

				foreach ( $rows as $row ) {
					$cursor = isset( $row['order_id'] ) ? (int) $row['order_id'] : $cursor;
					$phone  = TisaCase_Phone_Exporter_Phone::normalize_phone( isset( $row['phone'] ) ? $row['phone'] : '' );

					if ( '' === $phone ) {
						continue;
					}
					if ( $dedup && isset( $seen[ $phone ] ) ) {
						continue;
					}

					$seen[ $phone ] = true;
					fwrite( $handle, $phone . "\n" );
					$total++;

					if ( 0 === $total % 50000 ) {
						\WP_CLI::log( "  {$total} phones exported..." );
					}
				}

				if ( count( $rows ) < TisaCase_Phone_Exporter::MAX_BATCH ) {
					break;
				}

				if ( function_exists( 'set_time_limit' ) ) {
					@set_time_limit( 60 );
				}
			}

			fclose( $handle );
			\WP_CLI::success( "Exported {$total} phones to {$file}" );
		}
	}
}
