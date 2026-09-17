<?php
/**
 * خط لوله نوشتن: بافرشده (حداقل syscall)، شکستن به پارت‌های ده‌هزارتایی
 * و Dedup با مرتب‌سازی تکه‌ای (External Sort) با حافظه محدود.
 *
 * @package TisaCase_Order_Phone_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Phone_Exporter_Pipeline' ) ) {

	final class TisaCase_Phone_Exporter_Pipeline {

		/** مسیر فایل یک پارت. */
		public static function part_path( $state, $part_number ) {
			return trailingslashit( $state['dir'] ) . 'phones-' . str_pad( (string) $part_number, 3, '0', STR_PAD_LEFT ) . '.txt';
		}

		/** ثبت پارت کامل‌شده در State (ایدمپوتنت). */
		public static function mark_part_complete( &$state, $part_number, $count ) {
			$internal = basename( self::part_path( $state, $part_number ) );

			foreach ( $state['files'] as $file ) {
				if ( isset( $file['internal'] ) && $internal === $file['internal'] ) {
					return;
				}
			}

			$state['files'][] = array(
				'internal' => $internal,
				'download' => 'phones-' . str_pad( (string) $part_number, 3, '0', STR_PAD_LEFT ) . '.xls',
				'count'    => (int) $count,
			);
		}

		/** حالت عادی: شکستن بافر به پارت‌های file_size تایی (بدون اتکا به filesize/stat). */
		public static function flush_parts( array $buffer, &$state ) {
			$buffer    = array_values( $buffer );
			$file_size = TisaCase_Phone_Exporter::file_size();

			while ( ! empty( $buffer ) ) {
				$part  = (int) $state['current_file'];
				$count = (int) $state['current_count'];

				if ( $count >= $file_size ) {
					self::mark_part_complete( $state, $part, $file_size );
					$state['current_file'] = $part + 1;
					$state['current_count'] = 0;
					continue;
				}

				$space  = $file_size - $count;
				$take   = array_slice( $buffer, 0, $space );
				$buffer = array_slice( $buffer, $space );

				$path   = self::part_path( $state, $part );
				$prefix = ( $count > 0 ) ? "\n" : '';

				if ( false === @file_put_contents( $path, $prefix . implode( "\n", $take ), FILE_APPEND | LOCK_EX ) ) {
					return new WP_Error( 'write_failed', __( 'نوشتن فایل موقت روی سرور ناموفق بود.', TisaCase_Phone_Exporter::TEXT_DOMAIN ) );
				}

				$state['current_count'] += count( $take );

				if ( (int) $state['current_count'] >= $file_size ) {
					self::mark_part_complete( $state, $part, $file_size );
					$state['current_file']  = $part + 1;
					$state['current_count'] = 0;
				}
			}

			return true;
		}

		/** حالت Dedup: همه شماره‌ها در یک فایل کاری جمع می‌شوند تا آخر مرحله یکجا یکتاسازی شوند. */
		public static function flush_working( array $buffer, &$state ) {
			if ( empty( $buffer ) ) {
				return true;
			}

			$path   = trailingslashit( $state['dir'] ) . TisaCase_Phone_Exporter::WORKING_FILE;
			$done   = isset( $state['working_count'] ) ? (int) $state['working_count'] : 0;
			$prefix = ( $done > 0 ) ? "\n" : '';

			if ( false === @file_put_contents( $path, $prefix . implode( "\n", $buffer ), FILE_APPEND | LOCK_EX ) ) {
				return new WP_Error( 'write_failed', __( 'نوشتن فایل موقت روی سرور ناموفق بود.', TisaCase_Phone_Exporter::TEXT_DOMAIN ) );
			}

			$state['working_count'] = $done + count( $buffer );

			return true;
		}

		/** ثبت آخرین پارت نیمه‌پر در حالت عادی. */
		public static function finalize_last_part( &$state ) {
			if ( (int) $state['current_count'] > 0 ) {
				self::mark_part_complete( $state, (int) $state['current_file'], (int) $state['current_count'] );
			}
		}

		/**
		 * پایان حالت Dedup: مرتب‌سازی تکه‌ای (External Sort) + حذف تکراری + شکستن به پارت‌ها.
		 * حافظه محدود به CHUNK_LINES خط است؛ حتی برای میلیون‌ها شماره هم کار می‌کند.
		 * خروجی بر اساس شماره صعودی مرتب می‌شود.
		 */
		public static function finalize_dedup( &$state ) {
			$dir     = trailingslashit( $state['dir'] );
			$working = $dir . TisaCase_Phone_Exporter::WORKING_FILE;

			if ( ! file_exists( $working ) ) {
				$state['duplicates'] = 0;
				return true;
			}

			$in = @fopen( $working, 'rb' );
			if ( ! $in ) {
				return new WP_Error( 'read_failed', __( 'خواندن فایل موقت خروجی ناموفق بود.', TisaCase_Phone_Exporter::TEXT_DOMAIN ) );
			}

			// مرحله ۱: مرتب‌سازی + یکتاسازیِ داخل هر تکه.
			$chunk_files = array();

			while ( ! feof( $in ) ) {
				$lines = array();

				while ( count( $lines ) < TisaCase_Phone_Exporter::CHUNK_LINES && false !== ( $line = fgets( $in ) ) ) {
					$line = trim( $line );
					if ( '' !== $line ) {
						$lines[] = $line;
					}
				}

				if ( empty( $lines ) ) {
					break;
				}

				$lines = array_values( array_unique( $lines ) );
				sort( $lines, SORT_STRING );

				$chunk = $dir . 'chunk-' . count( $chunk_files ) . '.tmp';

				if ( false === @file_put_contents( $chunk, implode( "\n", $lines ) . "\n" ) ) {
					fclose( $in );
					TisaCase_Phone_Exporter_Storage::delete_files( $chunk_files );
					return new WP_Error( 'chunk_failed', __( 'آماده‌سازی خروجی (مرتب‌سازی) ناموفق بود.', TisaCase_Phone_Exporter::TEXT_DOMAIN ) );
				}

				$chunk_files[] = $chunk;

				// تمدید قفل پس از هر تکه مرتب‌سازی (برای دیتاست‌های خیلی بزرگ).
				TisaCase_Phone_Exporter_Session::lock_refresh( $state['run_id'] );
			}

			fclose( $in );

			// مرحله ۲: ادغام K-way + یکتاسازی بین تکه‌ها + نوشتن پارت‌ها.
			$handles = array();
			$current = array();
			$failed  = false;

			foreach ( $chunk_files as $chunk ) {
				$handle = @fopen( $chunk, 'rb' );

				if ( ! $handle ) {
					$failed = true;
					break;
				}

				$handles[] = $handle;
				$current[] = self::read_merged_line( $handle );
			}

			if ( $failed ) {
				TisaCase_Phone_Exporter_Storage::close_all( $handles );
				TisaCase_Phone_Exporter_Storage::delete_files( $chunk_files );
				return new WP_Error( 'merge_failed', __( 'آماده‌سازی خروجی (ادغام) ناموفق بود.', TisaCase_Phone_Exporter::TEXT_DOMAIN ) );
			}

			$file_size = TisaCase_Phone_Exporter::file_size();
			$part      = 1;
			$count     = 0;
			$unique    = 0;
			$last      = null;
			$out       = null;
			$buf       = '';
			$error     = null;

			while ( true ) {
				$best      = -1;
				$best_line = null;

				foreach ( $current as $i => $line ) {
					if ( null === $line ) {
						continue;
					}
					if ( null === $best_line || strcmp( $line, $best_line ) < 0 ) {
						$best_line = $line;
						$best      = $i;
					}
				}

				if ( $best < 0 ) {
					break;
				}

				$current[ $best ] = self::read_merged_line( $handles[ $best ] );

				if ( null !== $last && $best_line === $last ) {
					continue; // تکراری: رد شد.
				}

				$last   = $best_line;
				$unique++;

				if ( null === $out ) {
					$out = @fopen( self::part_path( $state, $part ), 'wb' );

					if ( ! $out ) {
						$error = new WP_Error( 'part_failed', __( 'نوشتن فایل خروجی ناموفق بود.', TisaCase_Phone_Exporter::TEXT_DOMAIN ) );
						break;
					}

					$count = 0;
					$buf   = '';
				}

				$buf .= ( $count > 0 ? "\n" : '' ) . $best_line;
				$count++;

				// تمدید دوره‌ای قفل برای دیتاست‌های خیلی بزرگ (تا در طول Finalize منقضی نشود).
				if ( 0 === ( $unique % 50000 ) ) {
					TisaCase_Phone_Exporter_Session::lock_refresh( $state['run_id'] );
				}

				if ( $count >= $file_size ) {
					if ( false === fwrite( $out, $buf ) ) {
						$error = new WP_Error( 'part_failed', __( 'نوشتن فایل خروجی ناموفق بود.', TisaCase_Phone_Exporter::TEXT_DOMAIN ) );
						break;
					}
					fclose( $out );
					$out = null;
					self::mark_part_complete( $state, $part, $count );
					$part++;
					$count = 0;
					$buf   = '';
				} elseif ( strlen( $buf ) >= TisaCase_Phone_Exporter::WRITE_BUF ) {
					if ( false === fwrite( $out, $buf ) ) {
						$error = new WP_Error( 'part_failed', __( 'نوشتن فایل خروجی ناموفق بود.', TisaCase_Phone_Exporter::TEXT_DOMAIN ) );
						break;
					}
					$buf = '';
				}
			}

			if ( null !== $out ) {
				if ( null === $error && $count > 0 ) {
					if ( false !== fwrite( $out, $buf ) ) {
						self::mark_part_complete( $state, $part, $count );
					} else {
						$error = new WP_Error( 'part_failed', __( 'نوشتن فایل خروجی ناموفق بود.', TisaCase_Phone_Exporter::TEXT_DOMAIN ) );
					}
				}
				fclose( $out );
			}

			TisaCase_Phone_Exporter_Storage::close_all( $handles );
			TisaCase_Phone_Exporter_Storage::delete_files( $chunk_files );

			if ( null !== $error ) {
				// working.txt حفظ می‌شود تا دکمه «ادامه» بتواند دوباره تلاش کند.
				return $error;
			}

			@unlink( $working );

			$before                = (int) $state['valid_phones'];
			$state['valid_phones'] = $unique;
			$state['duplicates']   = max( 0, $before - $unique );

			return true;
		}

		/** خواندن خط غیرخالی بعدی از Handle ادغام. */
		private static function read_merged_line( $handle ) {
			while ( false !== ( $line = fgets( $handle ) ) ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					return $line;
				}
			}

			return null;
		}
	}
}
