<?php
/**
 * ذخیره‌سازی فایل: انتخاب مسیر امن (اول uploads، بعد tmp)، محافظت پوشه،
 * ساخت پوشه هر جلسه و پاک‌سازی خودکار (Cron Sweep).
 *
 * @package TisaCase_Order_Phone_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Phone_Exporter_Storage' ) ) {

	final class TisaCase_Phone_Exporter_Storage {

		/** همه مسیرهای پایه ممکن (uploads + tmp؛ برای اسکن/پاک‌سازی کرونی). */
		public static function base_dirs() {
			$dirs = array();

			$uploads = wp_upload_dir();
			if ( empty( $uploads['error'] ) ) {
				$dirs[] = trailingslashit( $uploads['basedir'] ) . TisaCase_Phone_Exporter::BASE_DIR_NAME;
			}

			// Fallback و محل نسخه‌های قبلی؛ اسکن کرون اینجا را هم پاک‌سازی می‌کند.
			$dirs[] = trailingslashit( get_temp_dir() ) . TisaCase_Phone_Exporter::BASE_DIR_NAME;

			return $dirs;
		}

		/** اولین مسیر پایه قابل‌نوشتن (ترجیحاً uploads). */
		public static function base_dir() {
			foreach ( self::base_dirs() as $dir ) {
				if ( wp_mkdir_p( $dir ) && wp_is_writable( $dir ) ) {
					self::protect_directory( $dir );
					return $dir;
				}
			}

			return new WP_Error( 'export_dir', __( 'پوشه قابل‌نوشتن برای خروجی پیدا نشد.', TisaCase_Phone_Exporter::TEXT_DOMAIN ) );
		}

		/**
		 * مسیر اختصاصی هر «جلسه»؛ با Hash غیرقابل حدس تا در هاست‌های Nginx (بدون .htaccess)
		 * هم حدس زدن URL مستقیم فایل عملاً ناممکن باشد. چون run_id بخشی از نام پوشه است،
		 * حتی در نبضه‌های نادرِ انقضای قفل، دو جلسه هرگز روی فایل یکدیگر نمی‌نویسند.
		 */
		public static function user_dir( $run_id = '' ) {
			$base = self::base_dir();

			if ( is_wp_error( $base ) ) {
				return $base;
			}

			$hash = substr( hash( 'sha256', get_current_user_id() . '|' . wp_salt( 'auth' ) ), 0, 12 );

			if ( ! is_string( $run_id ) || '' === $run_id ) {
				return trailingslashit( $base ) . 'u' . $hash;
			}

			return trailingslashit( $base ) . 'u' . $hash . '-' . substr( $run_id, 0, 8 );
		}

		/** پیشوند مشترک پوشه‌های این کاربر (برای پاک‌سازی جلسه‌های قبلی هنگام شروع جدید). */
		public static function user_dir_prefix() {
			$base = self::base_dir();

			if ( is_wp_error( $base ) ) {
				return '';
			}

			return trailingslashit( $base ) . 'u' . substr( hash( 'sha256', get_current_user_id() . '|' . wp_salt( 'auth' ) ), 0, 12 );
		}

		/** index.html خالی + htaccess با Deny برای جلوگیری از دسترسی مستقیم وب. */
		public static function protect_directory( $dir ) {
			if ( ! is_string( $dir ) || ! is_dir( $dir ) ) {
				return;
			}

			$index = trailingslashit( $dir ) . 'index.html';
			if ( ! file_exists( $index ) ) {
				@file_put_contents( $index, '' );
			}

			$htaccess = trailingslashit( $dir ) . '.htaccess';
			if ( ! file_exists( $htaccess ) ) {
				@file_put_contents(
					$htaccess,
					"Options -Indexes\n" .
					"<FilesMatch \"\\.(txt|xls|xlsx|csv|tmp)$\">\n" .
					"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" .
					"<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" .
					"</FilesMatch>\n"
				);
			}
		}

		/** حذف بازگشتی یک دایرکتوری (با مقاوم‌بودن نسبت به Symlink). */
		public static function delete_directory( $dir ) {
			if ( ! is_string( $dir ) || '' === $dir || ! is_dir( $dir ) ) {
				return;
			}

			$items = @scandir( $dir );
			if ( ! is_array( $items ) ) {
				return;
			}

			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}

				$path = trailingslashit( $dir ) . $item;
				if ( is_dir( $path ) && ! is_link( $path ) ) {
					self::delete_directory( $path );
				} else {
					@unlink( $path );
				}
			}

			@rmdir( $dir );
		}

		/** ساخت پوشه تمیز برای جلسه جدید + پاک‌سازی جلسه‌های قبلی همین کاربر. */
		public static function create_clean_user_dir( $run_id = '' ) {
			$dir = self::user_dir( $run_id );

			if ( is_wp_error( $dir ) ) {
				return $dir;
			}

			/*
			 * شروع خروجی جدید فقط وقتی مجاز است که قفل آزاد باشد؛ بنابراین هیچ پردازش
			 * فعالی از همین کاربر در جریان نیست و پاک‌سازی پوشه‌های قبلیِ همین کاربر ایمن است.
			 */
			$prefix = self::user_dir_prefix();

			if ( '' !== $prefix && is_dir( dirname( $prefix ) ) ) {
				$entries = @scandir( dirname( $prefix ) );

				if ( is_array( $entries ) ) {
					$family = basename( $prefix );

					foreach ( $entries as $entry ) {
						if ( 0 === strpos( $entry, $family ) ) {
							self::delete_directory( trailingslashit( dirname( $prefix ) ) . $entry );
						}
					}
				}
			}

			if ( is_dir( $dir ) ) {
				self::delete_directory( $dir );
			}

			if ( ! wp_mkdir_p( $dir ) || ! wp_is_writable( $dir ) ) {
				return new WP_Error( 'session_dir', __( 'امکان ساخت پوشه موقت خروجی وجود ندارد.', TisaCase_Phone_Exporter::TEXT_DOMAIN ) );
			}

			self::protect_directory( $dir );
			return $dir;
		}

		/** حذف چند فایل (بی‌خطر نسبت به مقادیر نامعتبر). */
		public static function delete_files( array $paths ) {
			foreach ( $paths as $path ) {
				if ( is_string( $path ) && '' !== $path ) {
					@unlink( $path );
				}
			}
		}

		/** بستن همه Handle های باز. */
		public static function close_all( array $handles ) {
			foreach ( $handles as $handle ) {
				if ( is_resource( $handle ) ) {
					@fclose( $handle );
				}
			}
		}

		/** پاک‌سازی فایل‌های موقت قدیمی‌تر از FILE_TTL (یا همه، وقتی $force است). */
		public static function sweep_old_exports( $force = false ) {
			foreach ( self::base_dirs() as $base ) {
				if ( ! is_string( $base ) || ! is_dir( $base ) ) {
					continue;
				}

				$items = @scandir( $base );
				if ( ! is_array( $items ) ) {
					continue;
				}

				foreach ( $items as $item ) {
					if ( '.' === $item || '..' === $item ) {
						continue;
					}

					$path = trailingslashit( $base ) . $item;

					if ( ! is_dir( $path ) || is_link( $path ) ) {
						continue;
					}

					if ( $force || ( time() - (int) @filemtime( $path ) ) > TisaCase_Phone_Exporter::FILE_TTL ) {
						self::delete_directory( $path );
					}
				}
			}
		}
	}
}
