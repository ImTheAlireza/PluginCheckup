<?php
/**
 * اندپوینت‌های AJAX: شروع، پردازش Batch و لغو — همراه با قفل همزمانی و run_id.
 *
 * @package TisaCase_Order_Phone_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Phone_Exporter_Ajax' ) ) {

	final class TisaCase_Phone_Exporter_Ajax {

		public static function ajax_start() {
			TisaCase_Phone_Exporter_Session::ensure_access();
			check_ajax_referer( TisaCase_Phone_Exporter::NONCE_ACTION, 'nonce' );

			// اگر پردازشی در جریان است، شروع جدید ممنوع است (تا دایرکتوری وسط کار پاک نشود).
			if ( TisaCase_Phone_Exporter_Session::lock_exists() ) {
				wp_send_json_error( array(
					'message' => __( 'یک خروجی هم‌اکنون در تب دیگری در حال اجراست. ابتدا آن را کامل کنید یا دکمه «توقف و پاک‌سازی» را در همان تب بزنید (اگر تب بسته شده، حداکثر ۲ دقیقه صبر کنید).', TisaCase_Phone_Exporter::TEXT_DOMAIN ),
				), 409 );
			}

			$run_id = strtolower( wp_generate_password( 16, false ) );

			// قفل گرفته می‌شود، سپس دایرکتوری قبلی پاک و جلسه جدید ساخته می‌شود.
			if ( ! TisaCase_Phone_Exporter_Session::lock_acquire( $run_id ) ) {
				wp_send_json_error( array( 'message' => __( 'شروع خروجی جدید ناموفق بود؛ لطفاً دوباره تلاش کنید.', TisaCase_Phone_Exporter::TEXT_DOMAIN ) ), 409 );
			}

			$dir = TisaCase_Phone_Exporter_Storage::create_clean_user_dir( $run_id );

			if ( is_wp_error( $dir ) ) {
				TisaCase_Phone_Exporter_Session::lock_release_if( $run_id );
				wp_send_json_error( array( 'message' => $dir->get_error_message() ), 500 );
			}

			$total = TisaCase_Phone_Exporter_Queries::count_orders();
			$dedup = ! empty( $_POST['dedup'] );

			$state = array(
				'run_id'        => $run_id,
				'dir'           => $dir,
				'cursor'        => 0,
				'processed'     => 0,
				'total_orders'  => $total,
				'valid_phones'  => 0,
				'skipped'       => 0,
				'duplicates'    => 0,
				'dedup'         => $dedup,
				'working_count' => 0,
				'current_file'  => 1,
				'current_count' => 0,
				'files'         => array(),
				'done'          => false,
				'storage'       => TisaCase_Phone_Exporter_Queries::hpos_enabled() ? 'HPOS' : 'Legacy',
				'started_at'    => time(),
			);

			TisaCase_Phone_Exporter_Session::delete_state();
			TisaCase_Phone_Exporter_Session::save_state( $state );

			wp_send_json_success( self::response_payload( $state ) );
		}

		public static function ajax_process() {
			TisaCase_Phone_Exporter_Session::ensure_access();
			check_ajax_referer( TisaCase_Phone_Exporter::NONCE_ACTION, 'nonce' );

			$run_id = isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : '';
			$state  = TisaCase_Phone_Exporter_Session::get_state();

			if ( empty( $state ) || empty( $state['dir'] ) || empty( $state['run_id'] ) ) {
				wp_send_json_error( array( 'message' => __( 'جلسه خروجی پیدا نشد. دوباره «شروع ساخت خروجی» را بزنید.', TisaCase_Phone_Exporter::TEXT_DOMAIN ) ), 400 );
			}

			// run_id ناهماهوان = جلسه‌ای جدید در تب دیگری شروع شده؛ این حلقه باید متوقف شود.
			if ( '' !== $run_id && $run_id !== $state['run_id'] ) {
				wp_send_json_error( array( 'message' => __( 'جلسه خروجی جدیدی شروع شده است؛ این تب متوقف شد.', TisaCase_Phone_Exporter::TEXT_DOMAIN ) ), 409 );
			}

			if ( ! empty( $state['done'] ) ) {
				wp_send_json_success( self::response_payload( $state ) );
			}

			if ( TisaCase_Phone_Exporter_Session::cancel_requested( $state['run_id'] ) ) {
				TisaCase_Phone_Exporter_Session::cleanup_session( $state );
				wp_send_json_success( array( 'cancelled' => true ) );
			}

			if ( ! TisaCase_Phone_Exporter_Session::lock_acquire( $state['run_id'] ) ) {
				wp_send_json_error( array( 'message' => __( 'یک پردازش هم‌اکنون در تب دیگری در جریان است.', TisaCase_Phone_Exporter::TEXT_DOMAIN ) ), 409 );
			}

			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 60 );
			}

			$batch = TisaCase_Phone_Exporter::batch_size();
			$rows  = TisaCase_Phone_Exporter_Queries::fetch_rows_after( (int) $state['cursor'], $batch );

			if ( ! is_array( $rows ) ) {
				wp_send_json_error( array( 'message' => __( 'خواندن سفارش‌ها از دیتابیس ناموفق بود.', TisaCase_Phone_Exporter::TEXT_DOMAIN ) ), 500 );
			}

			$buffer = array();

			foreach ( $rows as $row ) {
				$order_id  = isset( $row['order_id'] ) ? absint( $row['order_id'] ) : 0;
				$raw_phone = isset( $row['phone'] ) ? $row['phone'] : '';

				if ( $order_id > 0 ) {
					$state['cursor'] = $order_id;
				}

				$state['processed']++;

				$phone = TisaCase_Phone_Exporter_Phone::normalize_phone( $raw_phone );

				if ( '' === $phone ) {
					$state['skipped']++;
					continue;
				}

				$buffer[] = $phone;
				$state['valid_phones']++;
			}

			if ( ! empty( $buffer ) ) {
				$flush = ! empty( $state['dedup'] )
					? TisaCase_Phone_Exporter_Pipeline::flush_working( $buffer, $state )
					: TisaCase_Phone_Exporter_Pipeline::flush_parts( $buffer, $state );

				if ( is_wp_error( $flush ) ) {
					// لغو در میانه اجرا (فایل‌ها احتمالاً همین حالا پاک شده‌اند) — ادامه نداریم.
					if ( TisaCase_Phone_Exporter_Session::cancel_requested( $state['run_id'] ) ) {
						TisaCase_Phone_Exporter_Session::cleanup_session( $state );
						wp_send_json_success( array( 'cancelled' => true ) );
					}

					// State ذخیره می‌شود تا «ادامه» از همان‌جا ممکن باشد؛ قفل با TTL خودش آزاد می‌شود.
					TisaCase_Phone_Exporter_Session::save_state( $state );
					wp_send_json_error( array( 'message' => $flush->get_error_message() ), 500 );
				}
			}

			// پایان کار: کمتر از Batch ردیف برگشت، یعنی سفارش دیگری باقی نمانده.
			if ( count( $rows ) < $batch ) {
				if ( ! empty( $state['dedup'] ) ) {
					if ( function_exists( 'set_time_limit' ) ) {
						@set_time_limit( 300 );
					}

					$finalize = TisaCase_Phone_Exporter_Pipeline::finalize_dedup( $state );

					if ( is_wp_error( $finalize ) ) {
						if ( TisaCase_Phone_Exporter_Session::cancel_requested( $state['run_id'] ) ) {
							TisaCase_Phone_Exporter_Session::cleanup_session( $state );
							wp_send_json_success( array( 'cancelled' => true ) );
						}

						TisaCase_Phone_Exporter_Session::save_state( $state );
						wp_send_json_error( array( 'message' => $finalize->get_error_message() ), 500 );
					}
				} else {
					TisaCase_Phone_Exporter_Pipeline::finalize_last_part( $state );
				}

				$state['done'] = true;
				// شمارش تازه برای نمایش دقیق (سفارش‌ها ممکن است در این مدت تغییر کرده باشند).
				$state['total_orders'] = max( (int) $state['processed'], TisaCase_Phone_Exporter_Queries::count_orders() );
				TisaCase_Phone_Exporter_Session::lock_release_if( $state['run_id'] );
			}

			// اگر وسط کار «توقف» خواسته شده، همان‌جا پاک‌سازی می‌کنیم.
			if ( TisaCase_Phone_Exporter_Session::cancel_requested( $state['run_id'] ) ) {
				TisaCase_Phone_Exporter_Session::cleanup_session( $state );
				wp_send_json_success( array( 'cancelled' => true ) );
			}

			// تازه‌سازی mtime پوشه تا اسکن کرونیِ فایل‌های قدیمی به جلسه فعال دست نزند.
			if ( is_string( $state['dir'] ) ) {
				@touch( $state['dir'] );
			}

			if ( ! TisaCase_Phone_Exporter_Session::save_state_guarded( $state ) ) {
				/*
				 * جلسه در همین لحظه لغو شده یا جلسه جدیدی State را به دست گرفته است.
				 * به State/قفلِ فعلی دست نمی‌زنیم (ممکن است متعلق به جلسه جدید باشد)؛
				 * فقط پوشه همین run را پاک می‌کنیم و پاسخ «لغو شده» برمی‌گردانیم.
				 */
				if ( ! empty( $state['dir'] ) && is_string( $state['dir'] ) ) {
					TisaCase_Phone_Exporter_Storage::delete_directory( $state['dir'] );
				}
				TisaCase_Phone_Exporter_Session::lock_release_if( $state['run_id'] );
				wp_send_json_success( array( 'cancelled' => true ) );
			}

			wp_send_json_success( self::response_payload( $state ) );
		}

		public static function ajax_cancel() {
			TisaCase_Phone_Exporter_Session::ensure_access();
			check_ajax_referer( TisaCase_Phone_Exporter::NONCE_ACTION, 'nonce' );

			$run_id = isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : '';
			$state  = TisaCase_Phone_Exporter_Session::get_state();

			$matches = ! empty( $state )
				&& ! empty( $state['run_id'] )
				&& ( '' === $run_id || $run_id === $state['run_id'] );

			if ( ! $matches ) {
				/*
				 * جلسه‌ای مطابقِ این run_id وجود ندارد. پرچم لغوِ همین run_id قدیمی پاک می‌شود
				 * و اگر اصلاً state ای وجود ندارد و قفلِ رهاشده‌ای (از اجرای کرش‌شده) مانده، آزاد می‌شود.
				 */
				TisaCase_Phone_Exporter_Session::clear_cancel_flag( $run_id );

				if ( empty( $state ) ) {
					TisaCase_Phone_Exporter_Session::lock_release();
				}

				wp_send_json_success( array( 'cancelled' => true ) );
			}

			$state_run_id = $state['run_id'];

			/*
			 * ۱) پرچم لغو (Tombstone): هر درخواست Processِ هنوز در حال اجرا، در اولین
			 *    ایستگاه خودش متوقف می‌شود و State را احیا نمی‌کند.
			 */
			TisaCase_Phone_Exporter_Session::set_cancel_flag( $state_run_id );

			/*
			 * ۲) پاک‌سازی همین حالا — به‌صورت قطعی و بدون اتکا به وجود «درخواست در حال اجرا».
			 *    (نسخه‌های قبل اینجا فقط پرچم می‌گذاشتند و منتظر درخواست در حال اجرا می‌ماندند؛
			 *    اگر تب بسته شده بود، هیچ‌چیز پاک نمی‌شد و جلسه نصفه باقی می‌ماند.)
			 *    پاک‌سازی Idempotent است: حذف دایرکتوری/State/قفلِ غایب هیچ ضرری ندارد.
			 */
			TisaCase_Phone_Exporter_Session::cleanup_session( $state );

			wp_send_json_success( array( 'cancelled' => true ) );
		}

		/** ساخت پاسخ JSON برای UI (آمار + لیست فایل‌ها با Nonce واحد دانلود). */
		public static function response_payload( $state ) {
			$files = array();

			if ( ! empty( $state['files'] ) && is_array( $state['files'] ) ) {
				// یک Nonce واحد برای همه دانلودها (به‌جای Nonce به‌ازای هر فایل در هر پاسخ).
				$download_nonce = wp_create_nonce( TisaCase_Phone_Exporter::DOWNLOAD );

				foreach ( $state['files'] as $file ) {
					if ( empty( $file['internal'] ) || empty( $file['download'] ) ) {
						continue;
					}

					/*
					 * URL خام ساخته می‌شود چون این payload توسط JavaScript رندر می‌شود و
					 * wp_nonce_url کاراکتر & را HTML-escape می‌کند (همه اجزا سمت سرور و قابل اعتمادند).
					 */
					$url = add_query_arg(
						array(
							'action'   => TisaCase_Phone_Exporter::DOWNLOAD,
							'part'     => $file['internal'],
							'_wpnonce' => $download_nonce,
						),
						admin_url( 'admin-post.php' )
					);

					$files[] = array(
						'name'  => $file['download'],
						'count' => isset( $file['count'] ) ? (int) $file['count'] : 0,
						'url'   => $url,
					);
				}
			}

			return array(
				'run_id'       => isset( $state['run_id'] ) ? $state['run_id'] : '',
				'processed'    => isset( $state['processed'] ) ? (int) $state['processed'] : 0,
				'total_orders' => isset( $state['total_orders'] ) ? (int) $state['total_orders'] : 0,
				'valid_phones' => isset( $state['valid_phones'] ) ? (int) $state['valid_phones'] : 0,
				'skipped'      => isset( $state['skipped'] ) ? (int) $state['skipped'] : 0,
				'duplicates'   => isset( $state['duplicates'] ) ? (int) $state['duplicates'] : 0,
				'dedup'        => ! empty( $state['dedup'] ),
				'done'         => ! empty( $state['done'] ),
				'storage'      => isset( $state['storage'] ) ? $state['storage'] : '',
				'files'        => $files,
			);
		}
	}
}
