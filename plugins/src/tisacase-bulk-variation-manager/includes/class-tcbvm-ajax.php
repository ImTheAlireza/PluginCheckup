<?php
/**
 * مدیریت درخواست‌های ناهمگام (AJAX): جستجوی زنده، پیش‌نمایش، پردازش دسته‌ای و بازگردانی.
 *
 * @package TisaCase_Bulk_Variation_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCBVM_Ajax' ) ) {

	final class TCBVM_Ajax {

		public static function init() {
			add_action( 'wp_ajax_tcbvm_search_products', array( __CLASS__, 'ajax_search_products' ) );
			add_action( 'wp_ajax_tcbvm_preview', array( __CLASS__, 'ajax_preview' ) );
			add_action( 'wp_ajax_tcbvm_start_run', array( __CLASS__, 'ajax_start_run' ) );
			add_action( 'wp_ajax_tcbvm_execute_batch', array( __CLASS__, 'ajax_execute_batch' ) );
			add_action( 'wp_ajax_tcbvm_finish_run', array( __CLASS__, 'ajax_finish_run' ) );
			add_action( 'wp_ajax_tcbvm_rollback', array( __CLASS__, 'ajax_rollback' ) );
			add_action( 'wp_ajax_tcbvm_save_preset', array( __CLASS__, 'ajax_save_preset' ) );
			add_action( 'wp_ajax_tcbvm_delete_preset', array( __CLASS__, 'ajax_delete_preset' ) );
			add_action( 'wp_ajax_tcbvm_flush_cache', array( __CLASS__, 'ajax_flush_cache' ) );
		}

		private static function check_auth() {
			if ( ! TCBVM_Core::can() ) {
				wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز است.' ), 403 );
			}
			$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, TCBVM_Core::NONCE_ACTION ) ) {
				wp_send_json_error( array( 'message' => 'توکن امنیتی منقضی شده است. لطفاً صفحه را تازه‌سازی کنید.' ), 403 );
			}
		}

		/**
		 * آیا پاسخ JSON همین حالا ارسال شده است؟ (برای محافظ خطای مهلک)
		 *
		 * @var bool
		 */
		private static $responded = false;

		/**
		 * بافر خروجیِ خودمان (برای دور ریختن notice/warning قبل از پاسخ JSON).
		 *
		 * @var bool
		 */
		private static $own_buffer = false;

		/**
		 * آماده‌سازی محیط درخواست‌های سنگین (اجرای بسته‌ها و تهیه پشتیبان).
		 *
		 * بدون این‌ها، روی هاست‌های معمولی، درخواست در میانهٔ کار با خطای
		 * «تایم‌اوت شبکه»/۵۰۰ قطع می‌شد و کاربر فقط «Network timeout» می‌دید.
		 */
		private static function prepare_runtime() {
			if ( function_exists( 'wp_raise_memory_limit' ) ) {
				wp_raise_memory_limit( 'admin' );
			}
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			@ini_set( 'max_execution_time', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.max_execution_time_Blacklist
			if ( function_exists( 'ignore_user_abort' ) ) {
				ignore_user_abort( true );
			}

			// هر خروجی سرگردان (notice/warning افزونه‌های دیگر) JSON را خراب می‌کند؛
			// بافر می‌گیریم تا در صورت نیاز دور ریخته شود.
			if ( ! ob_get_level() ) {
				ob_start();
				self::$own_buffer = true;
			}

			register_shutdown_function( array( __CLASS__, 'shutdown_guard' ) );
		}

		/**
		 * دور ریختن بافر خروجیِ خودمان قبل از ارسال JSON.
		 */
		private static function clean_output() {
			if ( self::$own_buffer && ob_get_level() ) {
				@ob_end_clean(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				self::$own_buffer = false;
			}
		}

		/**
		 * ارسال پاسخ JSON و علامت‌گذاری آن (تا محافظ مهلک دوباره پاسخ ندهد).
		 *
		 * @param array $payload دادهٔ پاسخ.
		 */
		private static function send_error_json( array $payload ) {
			self::clean_output();
			self::$responded = true;
			wp_send_json_error( $payload, 500 );
		}

		/**
		 * محافظ خطای مهلک: اگر PHP در میانهٔ پردازش از کار افتاد (کمبود حافظه،
		 * خطای کشنده، تایم‌اوت سرور)، به‌جای صفحهٔ سفید/۵۰۰، پیام واقعی به مرورگر
		 * برگردانده می‌شود تا کاربر و توسعه‌دهنده بدانند دقیقاً چه شد.
		 * همچنین اسنپ‌شات‌های در حافظه در همین لحظه ذخیره می‌شوند.
		 */
		public static function shutdown_guard() {
			$error      = error_get_last();
			$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
			$is_fatal    = ( $error && in_array( $error['type'], $fatal_types, true ) );

			// هر چه از اسنپ‌شات‌ها/متغیرها در حافظه مانده، قبل از هر چیز ذخیره شود.
			if ( class_exists( 'TCBVM_Backup' ) ) {
				TCBVM_Backup::flush();
			}

			if ( self::$responded || ! $is_fatal ) {
				return;
			}

			$can_see_details = function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' );
			$message         = $can_see_details
				? sprintf( 'خطای مهلک PHP در میانهٔ اجرا: %s — %s خط %d', $error['message'], basename( (string) $error['file'] ), (int) $error['line'] )
				: 'سرور در میانهٔ اجرا با خطای مهلک متوقف شد.';

			self::clean_output();

			$payload = wp_json_encode(
				array(
					'success' => false,
					'data'    => array(
						'message' => $message,
						'fatal'   => true,
					),
				)
			);

			if ( ! headers_sent() ) {
				status_header( 500 );
				header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
			}
			echo $payload; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON ساخته‌شده.
			self::$responded = true;
		}

		/**
		 * جستجوی زنده و فیلتر کردن محصولات.
		 */
		public static function ajax_search_products() {
			self::check_auth();

			$filters = isset( $_POST['filters'] ) && is_array( $_POST['filters'] )
				? wp_unslash( $_POST['filters'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				: array();

			$ids     = TCBVM_DB::query_product_ids( $filters );
			$total   = count( $ids );
			$summary = TCBVM_DB::get_products_summary( $ids, 50, 0 );

			wp_send_json_success( array(
				'total'   => $total,
				'ids'     => $ids,
				'items'   => $summary['items'],
				'message' => sprintf( '%d محصول منطبق یافت شد.', $total ),
			) );
		}

		/**
		 * پیش‌نمایش تغییرات بدون ذخیره در پایگاه داده.
		 */
		public static function ajax_preview() {
			self::check_auth();

			$product_ids = isset( $_POST['product_ids'] ) ? array_map( 'absint', (array) $_POST['product_ids'] ) : array();
			$operation   = isset( $_POST['operation'] ) ? sanitize_key( $_POST['operation'] ) : '';
			$params      = isset( $_POST['params'] ) && is_array( $_POST['params'] ) ? wp_unslash( $_POST['params'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( empty( $product_ids ) ) {
				wp_send_json_error( array( 'message' => 'هیچ محصولی انتخاب نشده است.' ) );
			}

			if ( empty( $operation ) ) {
				wp_send_json_error( array( 'message' => 'عملیات مشخص نشده است.' ) );
			}

			$preview_data = TCBVM_OPS::preview( $product_ids, $operation, $params );
			wp_send_json_success( $preview_data );
		}

		/**
		 * شروع نشست جدید برای پردازش پله‌ای.
		 */
		public static function ajax_start_run() {
			self::check_auth();
			self::prepare_runtime(); // ساخت نشست + پشتیبان اولیه سنگین است.

			$product_ids = isset( $_POST['product_ids'] ) ? array_map( 'absint', (array) $_POST['product_ids'] ) : array();
			$operation   = isset( $_POST['operation'] ) ? sanitize_key( $_POST['operation'] ) : '';
			$params      = isset( $_POST['params'] ) && is_array( $_POST['params'] ) ? wp_unslash( $_POST['params'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( empty( $product_ids ) ) {
				wp_send_json_error( array( 'message' => 'هیچ محصولی برای اجرا انتخاب نشده است.' ) );
			}

			$op_labels = TCBVM_OPS::supported_ops();
			$op_title  = isset( $op_labels[ $operation ] ) ? $op_labels[ $operation ] : $operation;

			$run_id = TCBVM_Backup::create_run_session( $op_title, $product_ids, array(
				'operation' => $operation,
				'params'    => $params,
			) );

			$settings   = TCBVM_Core::get_settings();
			$batch_size = max( 1, (int) $settings['batch_size'] );
			$batches    = array_chunk( $product_ids, $batch_size );

			wp_send_json_success( array(
				'run_id'        => $run_id,
				'total_items'   => count( $product_ids ),
				'batch_size'    => $batch_size,
				'total_batches' => count( $batches ),
				'batches'       => $batches,
			) );
		}

		/**
		 * اجرای یک بسته (Batch).
		 */
		public static function ajax_execute_batch() {
			self::check_auth();
			self::prepare_runtime();

			$run_id    = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
			$batch_ids = isset( $_POST['batch_ids'] ) ? array_map( 'absint', (array) $_POST['batch_ids'] ) : array();
			$operation = isset( $_POST['operation'] ) ? sanitize_key( $_POST['operation'] ) : '';
			$params    = isset( $_POST['params'] ) && is_array( $_POST['params'] ) ? wp_unslash( $_POST['params'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( empty( $run_id ) || empty( $batch_ids ) || empty( $operation ) ) {
				wp_send_json_error( array( 'message' => 'پارامترهای ارسالی دسته ناقص است.' ) );
			}

			try {
				$batch_result = TCBVM_OPS::execute_batch( $run_id, $batch_ids, $operation, $params );
			} catch ( \Throwable $e ) {
				TCBVM_Backup::flush();
				self::send_error_json(
					array(
						'message' => 'خطا در اجرای بسته: ' . $e->getMessage(),
						'fatal'   => true,
					)
				);
			} catch ( \Exception $e ) { // سازگاری با PHP قدیمی‌تر.
				TCBVM_Backup::flush();
				self::send_error_json(
					array(
						'message' => 'خطا در اجرای بسته: ' . $e->getMessage(),
						'fatal'   => true,
					)
				);
			}

			self::clean_output();
			self::$responded = true;
			wp_send_json_success( $batch_result );
		}

		/**
		 * بستن و ثبت وضعیت پایان نشست.
		 */
		public static function ajax_finish_run() {
			self::check_auth();

			$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
			$status = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'completed';

			if ( empty( $run_id ) ) {
				wp_send_json_error( array( 'message' => 'شناسه اجرا مشخص نشده است.' ) );
			}

			TCBVM_Backup::finish_run_session( $run_id, $status );
			wp_send_json_success( array(
				'message' => 'عملیات با موفقیت به پایان رسید و در تاریخچه ثبت شد.',
			) );
		}

		/**
		 * بازگردانی (Rollback) یک اجرا.
		 */
		public static function ajax_rollback() {
			self::check_auth();

			$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
			if ( empty( $run_id ) ) {
				wp_send_json_error( array( 'message' => 'شناسه اجرا مشخص نیست.' ) );
			}

			$result = TCBVM_Backup::rollback_run( $run_id );
			if ( $result['success'] ) {
				wp_send_json_success( $result );
			} else {
				wp_send_json_error( $result );
			}
		}

		/**
		 * ذخیره الگوی سفارشی جدید.
		 */
		public static function ajax_save_preset() {
			self::check_auth();

			$id          = isset( $_POST['id'] ) ? sanitize_key( $_POST['id'] ) : '';
			$name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
			$description = isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '';
			$models_raw  = isset( $_POST['models'] ) ? wp_unslash( $_POST['models'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			$models = TCBVM_OPS::sanitize_model_list( $models_raw );

			if ( empty( $id ) ) {
				$id = 'custom_' . time();
			}
			if ( empty( $name ) || empty( $models ) ) {
				wp_send_json_error( array( 'message' => 'نام الگو و حداقل یک مدل الزامی است.' ) );
			}

			$saved = TCBVM_Core::save_custom_preset( $id, $name, $description, $models );
			if ( $saved ) {
				wp_send_json_success( array(
					'message' => 'الگو با موفقیت ذخیره شد.',
					'preset'  => array(
						'id'          => $id,
						'name'        => $name,
						'description' => $description,
						'models'      => $models,
					),
				) );
			} else {
				wp_send_json_error( array( 'message' => 'ذخیره الگو با خطا مواجه شد.' ) );
			}
		}

		/**
		 * حذف الگوی سفارشی.
		 */
		public static function ajax_delete_preset() {
			self::check_auth();

			$id = isset( $_POST['id'] ) ? sanitize_key( $_POST['id'] ) : '';
			if ( empty( $id ) ) {
				wp_send_json_error( array( 'message' => 'شناسه الگو ارسال نشده است.' ) );
			}

			$deleted = TCBVM_Core::delete_custom_preset( $id );
			if ( $deleted ) {
				wp_send_json_success( array( 'message' => 'الگو حذف شد.' ) );
			} else {
				wp_send_json_error( array( 'message' => 'الگوهای پیش‌فرض سیستم قابل حذف نیستند یا الگو یافت نشد.' ) );
			}
		}

		/**
		 * پاکسازی کش و ترنزینت‌های ووکامرس.
		 */
		public static function ajax_flush_cache() {
			self::check_auth();

			wc_delete_product_transients();
			delete_transient( 'wc_var_prices' );

			wp_send_json_success( array(
				'message' => 'کش قیمت‌ها و ترنزینت‌های ووکامرس با موفقیت نوسازی شد.',
			) );
		}
	}
}
