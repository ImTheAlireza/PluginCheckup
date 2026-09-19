<?php
/**
 * سیستم تهیه نسخه پشتیبان (اسنپ‌شات)، ثبت گزارش تاریخچه و بازگردانی خودکار (Rollback).
 *
 * ساختار دو لایه مستقل:
 * ۱) فهرست سبک تاریخچه‌ها (tcbvm_history_runs): برای بارگذاری فوری و بدون سنگینی جدول گزارش‌ها
 * ۲) داده‌های حجیم اسنپ‌شات (tcbvm_snap_{run_id}): برای بازگردانی دقیق بدون فشار به دیتابیس
 *
 * @package TisaCase_Bulk_Variation_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCBVM_Backup' ) ) {

	final class TCBVM_Backup {

		const OPTION_RUNS_LIST = 'tcbvm_history_runs';
		const MAX_RUNS_SAVED   = 30;

		/**
		 * کش موقت درون نشست برای اسنپ‌شات‌ها جهت بهینه‌سازی دیتابیس.
		 *
		 * @var array
		 */
		private static $current_snaps = array();

		/**
		 * کش موقت متغیرهای جدید ساخته‌شده.
		 *
		 * @var array
		 */
		private static $created_vars = array();

		/**
		 * ایجاد رکورد اجرای جدید و دریافت شناسه یکتا.
		 *
		 * @param string $operation_name عنوان عملیات.
		 * @param array  $product_ids    شناسه محصولات هدف.
		 * @param array  $extra_meta     متادیتای تکمیلی (ویژگی، قیمت و...).
		 * @return string شناسه نشست (Run ID).
		 */
		public static function create_run_session( $operation_name, array $product_ids, array $extra_meta = array() ) {
			$run_id     = 'run_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 6, false, false );
			$user       = wp_get_current_user();
			$user_login = ( $user && $user->exists() ) ? $user->user_login : 'مدیر سیستم';

			// ۱) ثبت گزارش در لیست تاریخچه‌ها
			$runs = get_option( self::OPTION_RUNS_LIST, array() );
			if ( ! is_array( $runs ) ) {
				$runs = array();
			}

			$clean_ids = array_values( array_filter( array_map( 'absint', $product_ids ) ) );

			$run_summary = array(
				'run_id'         => $run_id,
				'operation'      => sanitize_text_field( $operation_name ),
				'created_at'     => current_time( 'mysql' ),
				'completed_at'   => '',
				'user_login'     => $user_login,
				'total_products' => count( $clean_ids ),
				'product_ids'    => $clean_ids,
				'status'         => 'in_progress', // in_progress, completed, failed, rolled_back
				'created_count'  => 0,
				'deleted_count'  => 0,
				'meta'           => $extra_meta,
				'items'          => array(),
			);

			$runs[ $run_id ] = $run_summary;

			// نگهداری ۳۰ اجرای اخیر و پاکسازی اسنپ‌شات‌های قدیمی‌تر جهت حفظ سبکی دیتابیس
			if ( count( $runs ) > self::MAX_RUNS_SAVED ) {
				$excess_count = count( $runs ) - self::MAX_RUNS_SAVED;
				$old_runs     = array_slice( $runs, 0, $excess_count, true );
				foreach ( array_keys( $old_runs ) as $old_run_id ) {
					delete_option( 'tcbvm_snap_' . $old_run_id );
				}
				$runs = array_slice( $runs, - self::MAX_RUNS_SAVED, null, true );
			}

			update_option( self::OPTION_RUNS_LIST, $runs, false );

			// ۲) ایجاد رکورد اسنپ‌شات اختصاصی این اجرا
			$initial_snap = array(
				'run_id'       => $run_id,
				'snapshots'    => array(),
				'created_vars' => array(),
			);
			update_option( 'tcbvm_snap_' . $run_id, $initial_snap, false );

			self::$current_snaps = array();
			self::$created_vars  = array();

			return $run_id;
		}

		/**
		 * ثبت اسنپ‌شات وضعیت کامل یک محصول قبل از هرگونه تغییر روی متغیرها.
		 *
		 * @param string $run_id     شناسه اجرا.
		 * @param int    $product_id شناسه محصول.
		 * @return bool
		 */
		public static function snapshot_product( $run_id, $product_id ) {
			$product_id = absint( $product_id );
			if ( ! $product_id ) {
				return false;
			}

			$snap_key  = 'tcbvm_snap_' . $run_id;
			$snap_data = get_option( $snap_key, array() );
			if ( ! is_array( $snap_data ) ) {
				$snap_data = array(
					'run_id'       => $run_id,
					'snapshots'    => array(),
					'created_vars' => array(),
				);
			}

			// اگر قبلاً برای این محصول اسنپ‌شات ذخیره شده نیازی به تکرار نیست
			if ( isset( $snap_data['snapshots'][ $product_id ] ) ) {
				return true;
			}

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return false;
			}

			$product_snap = array(
				'product_id' => $product_id,
				'name'       => $product->get_name(),
				'type'       => $product->get_type(),
				'attributes' => get_post_meta( $product_id, '_product_attributes', true ),
				'terms'      => array(),
				'variations' => array(),
			);

			// ذخیره مقادیر ترم‌های تاکسونومی
			foreach ( (array) $product->get_attributes() as $attr ) {
				if ( $attr instanceof WC_Product_Attribute && $attr->is_taxonomy() ) {
					$terms = wp_get_post_terms( $product_id, $attr->get_name(), array( 'fields' => 'slugs' ) );
					if ( ! is_wp_error( $terms ) ) {
						$product_snap['terms'][ $attr->get_name() ] = $terms;
					}
				}
			}

			// ذخیره مشخصات و قیمت تمام متغیرهای فعلی محصول
			if ( $product->is_type( 'variable' ) ) {
				$children = (array) $product->get_children();
				foreach ( $children as $var_id ) {
					$var = wc_get_product( $var_id );
					if ( ! $var ) {
						continue;
					}

					$product_snap['variations'][ $var_id ] = array(
						'id'            => $var_id,
						'attributes'    => $var->get_attributes(),
						'regular_price' => $var->get_regular_price(),
						'sale_price'    => $var->get_sale_price(),
						'price'         => $var->get_price(),
						'stock_status'  => $var->get_stock_status(),
						'manage_stock'  => $var->get_manage_stock(),
						'stock_qty'     => $var->get_stock_quantity(),
						'sku'           => $var->get_sku(),
						'status'        => $var->get_status(),
						'post_parent'   => $product_id,
					);
				}
			}

			$snap_data['snapshots'][ $product_id ] = $product_snap;
			update_option( $snap_key, $snap_data, false );

			return true;
		}

		/**
		 * ثبت متغیرهای جدید ساخته‌شده برای اینکه در Rollback بتوان آن‌ها را تمیز پاک کرد.
		 *
		 * @param string $run_id       شناسه اجرا.
		 * @param int    $product_id   شناسه والد.
		 * @param int    $variation_id شناسه متغیر تازه ایجادشده.
		 */
		public static function track_created_variation( $run_id, $product_id, $variation_id ) {
			self::$created_vars[] = absint( $variation_id );
		}

		/**
		 * نوشتن تغییرات معلق اسنپ‌شات‌ها و متغیرها در پایان هر بسته.
		 */
		public static function flush() {
			// ذخیره دسته‌ای شناسه‌های متغیرهای ساخته‌شده در این درخواست
			if ( ! empty( self::$created_vars ) ) {
				// بازیابی اولین نشست فعال
				$runs = get_option( self::OPTION_RUNS_LIST, array() );
				if ( is_array( $runs ) && ! empty( $runs ) ) {
					$recent_keys = array_keys( $runs );
					$last_run_id = end( $recent_keys );
					if ( $last_run_id ) {
						$snap_key  = 'tcbvm_snap_' . $last_run_id;
						$snap_data = get_option( $snap_key, array() );
						if ( is_array( $snap_data ) ) {
							if ( empty( $snap_data['created_vars'] ) ) {
								$snap_data['created_vars'] = array();
							}
							$snap_data['created_vars'] = array_values( array_unique( array_merge( $snap_data['created_vars'], self::$created_vars ) ) );
							update_option( $snap_key, $snap_data, false );
						}
					}
				}
				self::$created_vars = array();
			}
		}

		/**
		 * پایان موفقیت‌آمیز نشست و ثبت جزئیات دقیق گزارش.
		 *
		 * @param string $run_id     شناسه اجرا.
		 * @param string $status     وضعیت پایانی (completed / completed_with_errors).
		 * @param array  $extra_data جزئیات گزارش (تعداد ساخته‌شده، حذف‌شده و لاگ آیتم‌ها).
		 * @return bool
		 */
		public static function finish_run_session( $run_id, $status = 'completed', array $extra_data = array() ) {
			self::flush();

			$runs = get_option( self::OPTION_RUNS_LIST, array() );
			if ( ! is_array( $runs ) || ! isset( $runs[ $run_id ] ) ) {
				return false;
			}

			$runs[ $run_id ]['status']       = sanitize_key( $status );
			$runs[ $run_id ]['completed_at'] = current_time( 'mysql' );

			if ( isset( $extra_data['created_count'] ) ) {
				$runs[ $run_id ]['created_count'] = absint( $extra_data['created_count'] );
			}
			if ( isset( $extra_data['deleted_count'] ) ) {
				$runs[ $run_id ]['deleted_count'] = absint( $extra_data['deleted_count'] );
			}
			if ( ! empty( $extra_data['items'] ) && is_array( $extra_data['items'] ) ) {
				$runs[ $run_id ]['items'] = $extra_data['items'];
			}

			update_option( self::OPTION_RUNS_LIST, $runs, false );
			return true;
		}

		/**
		 * دریافت لیست تمامی اجراها برای نمایش جدول گزارش و تاریخچه.
		 *
		 * @return array لیست اجراها به صورت آرایه مرتب‌شده از جدید به قدیم.
		 */
		public static function get_all_runs() {
			$runs = get_option( self::OPTION_RUNS_LIST, array() );
			if ( ! is_array( $runs ) || empty( $runs ) ) {
				return array();
			}
			return array_values( array_reverse( $runs ) );
		}

		/**
		 * دریافت اطلاعات کامل یک اجرا همراه با لیست جزئیات محصولات.
		 *
		 * @param string $run_id شناسه اجرا.
		 * @return array|null
		 */
		public static function get_run( $run_id ) {
			$runs = get_option( self::OPTION_RUNS_LIST, array() );
			if ( is_array( $runs ) && isset( $runs[ $run_id ] ) ) {
				return $runs[ $run_id ];
			}
			return null;
		}

		/**
		 * بازسازی یک متغیر حذف‌شده از روی اسنپ‌شات در حین Rollback.
		 *
		 * @param int   $product_id شناسهٔ والد.
		 * @param array $vdata      دادهٔ اسنپ‌شات متغیر.
		 * @return WC_Product_Variation|null
		 */
		private static function recreate_variation( $product_id, array $vdata ) {
			if ( ! class_exists( 'WC_Product_Variation' ) ) {
				return null;
			}

			$variation = new WC_Product_Variation();
			$variation->set_parent_id( absint( $product_id ) );
			$variation->set_status( isset( $vdata['status'] ) ? $vdata['status'] : 'publish' );

			if ( ! empty( $vdata['attributes'] ) && is_array( $vdata['attributes'] ) ) {
				$variation->set_attributes( $vdata['attributes'] );
			}

			// اگر SKU در فروشگاه تکراری نشده باشد بازگردانده شود
			if ( ! empty( $vdata['sku'] ) && function_exists( 'wc_get_product_id_by_sku' ) ) {
				$owner = wc_get_product_id_by_sku( $vdata['sku'] );
				if ( ! $owner ) {
					try {
						$variation->set_sku( $vdata['sku'] );
					} catch ( \Exception $e ) {
						// رد کردن خطا در صورت تکراری بودن SKU
					}
				}
			}

			try {
				$new_id = $variation->save();
			} catch ( \Exception $e ) {
				return null;
			}

			return $new_id ? wc_get_product( $new_id ) : null;
		}

		/**
		 * بازگردانی کامل (Rollback) یک اجرا بر اساس اسنپ‌شات اختصاصی آن.
		 *
		 * @param string $run_id شناسه نشست اجرا.
		 * @return array گزارش نتیجه بازگردانی.
		 */
		public static function rollback_run( $run_id ) {
			$runs = get_option( self::OPTION_RUNS_LIST, array() );
			if ( ! is_array( $runs ) || ! isset( $runs[ $run_id ] ) ) {
				return array(
					'success' => false,
					'message' => 'شناسه اجرای موردنظر در تاریخچه یافت نشد.',
				);
			}

			$run_data = $runs[ $run_id ];
			if ( 'rolled_back' === $run_data['status'] ) {
				return array(
					'success' => false,
					'message' => 'این عملیات قبلاً بازگردانی شده است.',
				);
			}

			$snap_key  = 'tcbvm_snap_' . $run_id;
			$snap_data = get_option( $snap_key, array() );

			if ( empty( $snap_data ) || empty( $snap_data['snapshots'] ) ) {
				return array(
					'success' => false,
					'message' => 'اطلاعات اسنپ‌شات و فایل‌های پشتیبان این اجرا یافت نشد.',
				);
			}

			$snapshots    = (array) $snap_data['snapshots'];
			$created_vars = isset( $snap_data['created_vars'] ) ? (array) $snap_data['created_vars'] : array();
			$restored_count = 0;

			// ۱) حذف قطعی تمام متغیرهایی که در این اجرا تازه ساخته شده بودند
			foreach ( $created_vars as $cvar_id ) {
				$cvar = wc_get_product( $cvar_id );
				if ( $cvar && $cvar->is_type( 'variation' ) ) {
					$cvar->delete( true );
				}
			}

			// ۲) بازگردانی وضعیت محصولات والد به اسنپ‌شات قبلی
			foreach ( $snapshots as $product_id => $snap ) {
				$product = wc_get_product( $product_id );
				if ( ! $product ) {
					continue;
				}

				// بازگردانی ویژگی‌های محصول
				if ( isset( $snap['attributes'] ) ) {
					update_post_meta( $product_id, '_product_attributes', $snap['attributes'] );
				}

				// بازگردانی ترم‌های تاکسونومی
				if ( ! empty( $snap['terms'] ) && is_array( $snap['terms'] ) ) {
					foreach ( $snap['terms'] as $tax => $slugs ) {
						wp_set_object_terms( $product_id, $slugs, $tax, false );
					}
				}

				// بازگردانی متغیرهای قدیمی محصول
				if ( ! empty( $snap['variations'] ) && is_array( $snap['variations'] ) ) {
					foreach ( $snap['variations'] as $vid => $vdata ) {
						$var = wc_get_product( $vid );

						// اگر متغیر در حین اجرا حذف شده بود، مجدداً از روی اسنپ‌شات ساخته می‌شود
						if ( ! $var ) {
							$var = self::recreate_variation( $product_id, $vdata );
							if ( ! $var ) {
								continue;
							}
						}

						$var->set_regular_price( $vdata['regular_price'] );
						if ( isset( $vdata['sale_price'] ) && '' !== (string) $vdata['sale_price'] ) {
							$var->set_sale_price( $vdata['sale_price'] );
						}
						if ( isset( $vdata['price'] ) && '' !== (string) $vdata['price'] ) {
							$var->set_price( $vdata['price'] );
						}
						$var->set_stock_status( $vdata['stock_status'] );
						$var->set_status( $vdata['status'] );
						if ( isset( $vdata['attributes'] ) && is_array( $vdata['attributes'] ) ) {
							$var->set_attributes( $vdata['attributes'] );
						}
						if ( ! empty( $vdata['manage_stock'] ) ) {
							$var->set_manage_stock( true );
							if ( null !== $vdata['stock_qty'] && '' !== $vdata['stock_qty'] ) {
								$var->set_stock_quantity( $vdata['stock_qty'] );
							}
						}
						$var->save();
					}
				}

				WC_Product_Variable::sync( $product_id );
				wc_delete_product_transients( $product_id );
				$restored_count++;
			}

			// ۳) ثبت وضعیت بازگردانی شده در تاریخچه
			$runs[ $run_id ]['status']         = 'rolled_back';
			$runs[ $run_id ]['rolled_back_at'] = current_time( 'mysql' );
			update_option( self::OPTION_RUNS_LIST, $runs, false );

			return array(
				'success'  => true,
				'restored' => $restored_count,
				'message'  => sprintf( 'تغییرات با موفقیت بازگردانی شد (%d محصول به وضعیت قبلی برگشتند).', $restored_count ),
			);
		}
	}
}
