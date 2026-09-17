<?php
/**
 * مدیریت جلسه: State (در Transient)، قفل همزمانی، پرچم لغو (Tombstone) و پاک‌سازی جلسه.
 *
 * @package TisaCase_Order_Phone_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Phone_Exporter_Session' ) ) {

	final class TisaCase_Phone_Exporter_Session {

		/** بررسی دسترسی و وجود ووکامرس (برای همه اندپوینت‌های AJAX). */
		public static function ensure_access() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز است.', TisaCase_Phone_Exporter::TEXT_DOMAIN ) ), 403 );
			}

			if ( ! class_exists( 'WooCommerce' ) ) {
				wp_send_json_error( array( 'message' => __( 'ووکامرس فعال نیست.', TisaCase_Phone_Exporter::TEXT_DOMAIN ) ), 400 );
			}
		}

		private static function state_key() {
			return 'tisacase_state_' . get_current_user_id();
		}

		public static function get_state() {
			$state = get_transient( self::state_key() );
			return is_array( $state ) ? $state : array();
		}

		/** ذخیره بی‌قیدوشرط (فقط برای شروع جلسه جدید که State تازه است). */
		public static function save_state( $state ) {
			set_transient( self::state_key(), $state, TisaCase_Phone_Exporter::STATE_TTL );
		}

		/**
		 * ذخیره محافظت‌شده: فقط وقتی مجاز است که جلسه هنوز لغو نشده باشد و State فعلی
		 * هنوز متعلق به همین run_id باشد. این متد مانع «زنده‌شدن دوباره» (Resurrection)
		 * یک جلسه لغوشده توسط پاسخ‌های قدیمی/در حال اجرا می‌شود.
		 *
		 * @return bool آیا ذخیره انجام شد؟
		 */
		public static function save_state_guarded( $state ) {
			$run_id = isset( $state['run_id'] ) ? $state['run_id'] : '';

			// جلسه لغو شده؛ هیچ پاسخی نباید آن را بازنویسی/احیا کند.
			if ( '' !== $run_id && self::cancel_requested( $run_id ) ) {
				return false;
			}

			$current = self::get_state();
			if ( ! empty( $current['run_id'] ) && $current['run_id'] !== $run_id ) {
				return false; // جلسه جدیدتری State را به دست گرفته است.
			}

			set_transient( self::state_key(), $state, TisaCase_Phone_Exporter::STATE_TTL );
			return true;
		}

		public static function delete_state() {
			delete_transient( self::state_key() );
		}

		private static function lock_key() {
			return 'tisacase_lock_' . get_current_user_id();
		}

		/** آیا قفل این کاربر گرفته شده؟ (هر مقداری) */
		public static function lock_exists() {
			return (bool) get_transient( self::lock_key() );
		}

		/**
		 * گرفتن/تمدید قفل پردازش برای جلوگیری از اجرای همزمان دو تب.
		 * مقدار قفل = run_id جلسه؛ اگر قفلِ دیگری (یا متعلق به اجرای متفاوت) باشد false برمی‌گردد.
		 */
		public static function lock_acquire( $run_id ) {
			$current = get_transient( self::lock_key() );

			if ( $current === $run_id ) {
				set_transient( self::lock_key(), $run_id, TisaCase_Phone_Exporter::LOCK_TTL );
				return true;
			}

			if ( $current ) {
				return false;
			}

			set_transient( self::lock_key(), $run_id, TisaCase_Phone_Exporter::LOCK_TTL );
			return true;
		}

		/**
		 * فقط تمدید قفلِ خودمان — هرگز قفل آزادشده را دوباره نمی‌گیرد.
		 * (برای فراخوانی از داخل پردازش‌های طولانی؛ اگر لغو شده باشد قفل را بازپس نمی‌گیرد.)
		 */
		public static function lock_refresh( $run_id ) {
			if ( get_transient( self::lock_key() ) === $run_id ) {
				set_transient( self::lock_key(), $run_id, TisaCase_Phone_Exporter::LOCK_TTL );
				return true;
			}

			return false;
		}

		public static function lock_release() {
			delete_transient( self::lock_key() );
		}

		/** آزادکردن قفل فقط اگر هنوز متعلق به همین run_id باشد (حذف امن، بدون قفل جلسه جدید). */
		public static function lock_release_if( $run_id ) {
			if ( get_transient( self::lock_key() ) === $run_id ) {
				delete_transient( self::lock_key() );
			}
		}

		private static function cancel_key( $run_id ) {
			return 'tisacase_cancel_' . $run_id;
		}

		/** آیا لغو این جلسه درخواست شده؟ */
		public static function cancel_requested( $run_id ) {
			return (bool) get_transient( self::cancel_key( $run_id ) );
		}

		/** گذاشتن پرچم لغو (Tombstone) — تا ۵ دقیقه هر پاسخ قدیمی را متوقف می‌کند. */
		public static function set_cancel_flag( $run_id ) {
			if ( is_string( $run_id ) && '' !== $run_id ) {
				set_transient( self::cancel_key( $run_id ), 1, 300 );
			}
		}

		/** پاک‌کردن پرچم لغو (فقط برای run_id های بی‌صاحب/قدیمی). */
		public static function clear_cancel_flag( $run_id ) {
			if ( is_string( $run_id ) && '' !== $run_id ) {
				delete_transient( self::cancel_key( $run_id ) );
			}
		}

		/**
		 * پاک‌سازی کامل یک جلسه: فایل‌ها + State + قفل.
		 * پرچم لغو (Tombstone) عمداً حذف نمی‌شود تا هر درخواستِ قدیمیِ هنوز در حال اجرا،
		 * در اولین ایستگاه خودش متوقف شود و نتواند State را دوباره احیا کند.
		 */
		public static function cleanup_session( $state ) {
			if ( is_array( $state ) && ! empty( $state['dir'] ) && is_string( $state['dir'] ) ) {
				TisaCase_Phone_Exporter_Storage::delete_directory( $state['dir'] );
			}
			self::delete_state();
			self::lock_release();
		}
	}
}
