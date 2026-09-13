<?php
/**
 * دانلود فایل‌های خروجی: لیست سفید + Nonce + استریم SpreadsheetML 2003.
 *
 * @package TisaCase_Order_Phone_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Phone_Exporter_Download' ) ) {

	final class TisaCase_Phone_Exporter_Download {

		private static function xml_escape( $value ) {
			return htmlspecialchars( (string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		}

		/**
		 * استریم SpreadsheetML 2003 به‌صورت .xls؛ بدون نیاز به ZipArchive یا PhpSpreadsheet.
		 * سلول‌ها صریحاً String هستند تا اکسل شماره 989... را هرگز به نماد علمی تبدیل نکند.
		 */
		public static function download_file() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( __( 'دسترسی غیرمجاز است.', TisaCase_Phone_Exporter::TEXT_DOMAIN ) );
			}

			$part = isset( $_GET['part'] ) ? sanitize_file_name( wp_unslash( $_GET['part'] ) ) : '';
			if ( '' === $part ) {
				wp_die( __( 'فایل مشخص نشده است.', TisaCase_Phone_Exporter::TEXT_DOMAIN ) );
			}

			check_admin_referer( TisaCase_Phone_Exporter::DOWNLOAD );

			$state = TisaCase_Phone_Exporter_Session::get_state();
			if ( empty( $state['dir'] ) || empty( $state['files'] ) ) {
				wp_die( __( 'خروجی پیدا نشد یا منقضی شده است.', TisaCase_Phone_Exporter::TEXT_DOMAIN ) );
			}

			$allowed      = false;
			$allowed_name = '';

			foreach ( $state['files'] as $file ) {
				if ( ! empty( $file['internal'] ) && $part === $file['internal'] ) {
					$allowed      = true;
					$allowed_name = isset( $file['download'] ) ? $file['download'] : 'phones.xls';
					break;
				}
			}

			if ( ! $allowed ) {
				wp_die( __( 'فایل معتبر نیست.', TisaCase_Phone_Exporter::TEXT_DOMAIN ) );
			}

			$path = trailingslashit( $state['dir'] ) . basename( $part );

			if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
				wp_die( __( 'فایل موقت خروجی پیدا نشد.', TisaCase_Phone_Exporter::TEXT_DOMAIN ) );
			}

			nocache_headers();
			header( 'Content-Type: application/vnd.ms-excel; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $allowed_name ) . '"' );
			header( 'X-Content-Type-Options: nosniff' );

			echo '<?xml version="1.0" encoding="UTF-8"?>';
			echo '<?mso-application progid="Excel.Sheet"?>';
			echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"';
			echo ' xmlns:o="urn:schemas-microsoft-com:office:office"';
			echo ' xmlns:x="urn:schemas-microsoft-com:office:excel"';
			echo ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
			echo '<Worksheet ss:Name="Phones"><Table>';

			$handle = @fopen( $path, 'rb' );

			if ( $handle ) {
				while ( false !== ( $line = fgets( $handle ) ) ) {
					$phone = trim( $line );

					if ( '' === $phone ) {
						continue;
					}

					echo '<Row><Cell><Data ss:Type="String">' . self::xml_escape( $phone ) . '</Data></Cell></Row>';
				}
				fclose( $handle );
			}

			echo '</Table></Worksheet></Workbook>';
			exit;
		}
	}
}
