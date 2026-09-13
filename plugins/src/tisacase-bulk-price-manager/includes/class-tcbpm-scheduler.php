<?php
/**
 * زمان‌بندی اجراها با WP-Cron برای حجم‌های بزرگ یا اجرای خودکار.
 *
 * @package TisaCase_Bulk_Price_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCBPM_Scheduler' ) ) {

	final class TCBPM_Scheduler {

		/** رویداد تکرارشوندهٔ پاک‌سازی روزانه را ثبت کن. */
		public static function register_cron() {
			if ( ! wp_next_scheduled( TCBPM_Core::CRON_CLEAN ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', TCBPM_Core::CRON_CLEAN );
			}
			// اگر در صف چیزی هست و رویداد تیک فعال نیست، زمان بده.
			if ( TCBPM_DB::count_queued() > 0 && ! wp_next_scheduled( TCBPM_Core::CRON_TICK ) ) {
				wp_schedule_single_event( time() + 30, TCBPM_Core::CRON_TICK );
			}
		}

		/** اطمینان از وجود یک تیک آینده (بعد از افزودن به صف). */
		public static function schedule_tick() {
			if ( ! wp_next_scheduled( TCBPM_Core::CRON_TICK ) ) {
				wp_schedule_single_event( time() + 30, TCBPM_Core::CRON_TICK );
			}
		}

		/** اجرای تیک کرون: چند صفحه از اجرای در صف را پردازش کن. */
		public static function cron_tick() {
			if ( ! TCBPM_Core::wc_active() || TCBPM_DB::count_queued() < 1 ) {
				return;
			}

			// اگر اجرای فعالِ دستی/بازگردانی در جریان است، این تیک را رها کن.
			$busy = TCBPM_DB::busy_slot( 0 );
			if ( ! empty( $busy['active'] ) ) {
				self::schedule_tick();
				return;
			}

			$run = TCBPM_DB::claim_queued_run();
			if ( ! $run ) {
				return;
			}

			$start    = time();
			$max_pages = max( 1, min( 500, absint( TCBPM_Core::setting( 'cron_pages' ) ) ) );
			$done     = false;

			for ( $i = 0; $i < $max_pages; $i++ ) {
				if ( ( time() - $start ) > 20 ) {
					break; // سهمیهٔ زمانی هر تیک؛ ادامه در تیک بعد.
				}
				$fresh = TCBPM_DB::get_run( (int) $run['id'] );
				if ( ! $fresh || 'running' !== $fresh['status'] ) {
					$done = true;
					break;
				}
				$res = TCBPM_OPS::run_next_page( $fresh );
				if ( ! $res['ok'] ) {
					TCBPM_DB::update_run( (int) $run['id'], array(
						'status'      => 'failed',
						'last_error'  => mb_substr( $res['msg'], 0, 255 ),
						'updated_at'  => TCBPM_DB::now(),
					) );
					$done = true;
					break;
				}
				if ( ! empty( $res['data']['done'] ) ) {
					$done = true;
					break;
				}
			}

			if ( $done ) {
				$final = TCBPM_DB::get_run( (int) $run['id'] );
				if ( $final && 'running' === $final['status'] ) {
					TCBPM_OPS::finalize_run( (int) $run['id'], 'done' );
				}
			} else {
				TCBPM_DB::update_run( (int) $run['id'], array( 'updated_at' => TCBPM_DB::now() ) );
			}

			// اگر هنوز اجرای در صف مانده، تیک بعدی را زمان بده.
			if ( TCBPM_DB::count_queued() > 0 ) {
				self::schedule_tick();
			}
		}
	}
}
