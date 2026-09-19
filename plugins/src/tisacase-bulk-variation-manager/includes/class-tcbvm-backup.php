<?php
/**
 * سیستم تهیه نسخه پشتیبان (اسنپ‌شات) و بازگردانی خودکار (Rollback) قبل از هر تغییر انبوه.
 *
 * @package TisaCase_Bulk_Variation_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCBVM_Backup' ) ) {

	final class TCBVM_Backup {

		/**
		 * کش درون‌درخواستیِ تاریخچهٔ اجراها.
		 *
		 * هر اسنپ‌شات و هر متغیر تازه، قبلاً یک get_option + update_option کامل روی همین
		 * آپشن (شامل تمام اسنپ‌شات‌ها و متغیرهای همهٔ اجراها) انجام می‌داد؛ یعنی برای هر
		 * متغیر، کل داده دوباره خوانده و بازنویسی می‌شد. در عملیات گروهی این رفتار
		 * درخواست را به‌شدت کند و سنگین می‌کرد و می‌توانست به تایم‌اوت/کمبود حافظه
		 * منجر شود. حالا یک‌بار در هر درخواست خوانده و در پایان کار یک‌بار نوشته می‌شود.
		 *
		 * @var array|null
		 */
		private static $runs_cache = null;

		/**
		 * آیا داده‌ای در حافظه هست که باید ذخیره شود؟
		 *
		 * @var bool
		 */
		private static $runs_dirty = false;

		/**
		 * تاریخچهٔ اجراها را یک‌بار می‌خواند و در حافظه نگه می‌دارد.
		 *
		 * @return array
		 */
		private static function runs_load() {
			if ( null === self::$runs_cache ) {
				$runs             = get_option( TCBVM_Core::OPTION_RUNS, array() );
				self::$runs_cache = is_array( $runs ) ? $runs : array();
			}
			return self::$runs_cache;
		}

		/**
		 * ثبت تغییرات حافظه در دیتابیس (یک‌بار در پایان هر بسته/درخواست).
		 *
		 * @param bool $immediate ذخیرهٔ فوری (برای داده‌ای که درخواست بعدی لازمش دارد).
		 */
		private static function runs_commit( $immediate = false ) {
			self::$runs_dirty = true;
			if ( $immediate ) {
				self::flush();
			}
		}

		/**
		 * نوشتن تغییرات معلق در دیتابیس (برای فراخوانی در پایان بسته و در shutdown).
		 */
		public static function flush() {
			if ( self::$runs_dirty && null !== self::$runs_cache ) {
				update_option( TCBVM_Core::OPTION_RUNS, self::$runs_cache, false );
				self::$runs_dirty = false;
			}
		}

		/**
		 * ایجاد رکورد اجرای جدید و دریافت شناسه یکتا.
		 */
		public static function create_run_session( $operation_name, array $product_ids, array $extra_meta = array() ) {
			$run_id = 'run_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 6, false, false );
			self::runs_load();
			$runs = self::$runs_cache;

			$runs[ $run_id ] = array(
				'run_id'         => $run_id,
				'operation'      => sanitize_text_field( $operation_name ),
				'created_at'     => current_time( 'mysql' ),
				'total_products' => count( $product_ids ),
				'product_ids'    => array_values( array_map( 'absint', $product_ids ) ),
				'status'         => 'in_progress', // in_progress, completed, failed, rolled_back
				'snapshots'      => array(),
				'created_vars'   => array(), // variation IDs created in this run
				'logs'           => array(),
				'meta'           => $extra_meta,
			);

			// نگهداری فقط ۲۰ اجرای اخیر برای جلوگیری از سنگین شدن دیتابیس
			if ( count( $runs ) > TCBVM_Core::MAX_RUNS_SAVED ) {
				$runs = array_slice( $runs, - TCBVM_Core::MAX_RUNS_SAVED, null, true );
			}

			self::$runs_cache = $runs;
			self::runs_commit( true ); // درخواست بعدی (اجرای بسته) باید این نشست را ببیند.
			return $run_id;
		}

		/**
		 * ثبت اسنپ‌شات وضعیت فعلی یک محصول قبل از ویرایش آن.
		 */
		public static function snapshot_product( $run_id, $product_id ) {
			self::runs_load();
			if ( ! isset( self::$runs_cache[ $run_id ] ) ) {
				return false;
			}

			// اگر قبلاً برای این محصول در این نشست اسنپ‌شات گرفته شده، نیاز به تکرار نیست
			if ( isset( self::$runs_cache[ $run_id ]['snapshots'][ $product_id ] ) ) {
				return true;
			}

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return false;
			}

			$snapshot_data = array(
				'product_id'   => $product_id,
				'type'         => $product->get_type(),
				'attributes'   => get_post_meta( $product_id, '_product_attributes', true ),
				'terms'        => array(),
				'variations'   => array(),
			);

			// ذخیره ترم‌های تاکسونومی‌های ویژگی
			$attributes = $product->get_attributes();
			foreach ( $attributes as $attr ) {
				if ( $attr->is_taxonomy() ) {
					$terms = wp_get_post_terms( $product_id, $attr->get_name(), array( 'fields' => 'slugs' ) );
					if ( ! is_wp_error( $terms ) ) {
						$snapshot_data['terms'][ $attr->get_name() ] = $terms;
					}
				}
			}

			// اگر متغیر است، تمام متغیرهای فعلی با جزییات کامل ثبت شوند
			if ( $product->is_type( 'variable' ) ) {
				$children = $product->get_children();
				foreach ( $children as $var_id ) {
					$var = wc_get_product( $var_id );
					if ( ! $var ) {
						continue;
					}
					$snapshot_data['variations'][ $var_id ] = array(
						'id'            => $var_id,
						'attributes'    => $var->get_attributes(),
						'regular_price' => $var->get_regular_price(),
						'sale_price'    => $var->get_sale_price(),
						'stock_status'  => $var->get_stock_status(),
						'manage_stock'  => $var->get_manage_stock(),
						'stock_qty'     => $var->get_stock_quantity(),
						'sku'           => $var->get_sku(),
						'status'        => $var->get_status(),
						'post_parent'   => $product_id,
					);
				}
			}

			self::$runs_cache[ $run_id ]['snapshots'][ $product_id ] = $snapshot_data;
			self::runs_commit(); // نوشتن در پایان بسته انجام می‌شود، نه برای هر محصول.
			return true;
		}

		/**
		 * ثبت متغیرهای جدید ساخته‌شده برای اینکه در Rollback بتوان آن‌ها را تمیز پاک کرد.
		 */
		public static function track_created_variation( $run_id, $product_id, $variation_id ) {
			self::runs_load();
			if ( ! isset( self::$runs_cache[ $run_id ] ) ) {
				return;
			}
			self::$runs_cache[ $run_id ]['created_vars'][] = absint( $variation_id );
			self::runs_commit(); // بدون نوشتن فوری؛ برای هر متغیر یک بازنویسی کامل لازم نیست.
		}

		/**
		 * بستن و تکمیل نشست اجرا.
		 */
		public static function finish_run_session( $run_id, $status = 'completed', $extra_log = '' ) {
			self::runs_load();
			if ( ! isset( self::$runs_cache[ $run_id ] ) ) {
				return false;
			}
			self::$runs_cache[ $run_id ]['status']       = sanitize_key( $status );
			self::$runs_cache[ $run_id ]['completed_at'] = current_time( 'mysql' );
			if ( ! empty( $extra_log ) ) {
				self::$runs_cache[ $run_id ]['logs'][] = sanitize_text_field( $extra_log );
			}
			self::$runs_dirty = true;
			self::flush();
			return true;
		}

		/**
		 * دریافت اطلاعات تمام اجراها جهت نمایش در جدول تاریخچه و بازگردانی.
		 */
		public static function get_all_runs() {
			return array_reverse( self::runs_load() );
		}

		/**
		 * بازگردانی کامل (Rollback) یک اجرا.
		 *
		 * @param string $run_id شناسه نشست اجرا
		 * @return array نتیجه بازگردانی شامل تعداد محصولات بازگردانده‌شده.
		 */
		public static function rollback_run( $run_id ) {
			self::runs_load();
			$runs = self::$runs_cache;
			if ( ! isset( $runs[ $run_id ] ) ) {
				return array(
					'success' => false,
					'message' => 'شناسه اجرای موردنظر یافت نشد.',
				);
			}

			$run_data = $runs[ $run_id ];
			if ( 'rolled_back' === $run_data['status'] ) {
				return array(
					'success' => false,
					'message' => 'این عملیات قبلاً بازگردانی شده است.',
				);
			}

			$snapshots    = isset( $run_data['snapshots'] ) ? (array) $run_data['snapshots'] : array();
			$created_vars = isset( $run_data['created_vars'] ) ? (array) $run_data['created_vars'] : array();
			$restored_count = 0;

			// ۱. حذف تمام متغیرهایی که در این اجرا تازه ساخته شده بودند
			if ( ! empty( $created_vars ) ) {
				foreach ( $created_vars as $cvar_id ) {
					$cvar = wc_get_product( $cvar_id );
					if ( $cvar && $cvar->is_type( 'variation' ) ) {
						$cvar->delete( true );
					}
				}
			}

			// ۲. بازگردانی وضعیت تک تک محصولات والد به اسنپ‌شات قبلی
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
				if ( ! empty( $snap['terms'] ) ) {
					foreach ( $snap['terms'] as $tax => $slugs ) {
						wp_set_object_terms( $product_id, $slugs, $tax, false );
					}
				}

				// بازگردانی متغیرهای قدیمی (قیمت‌ها، موجودی، فعال/غیرفعال)
				if ( ! empty( $snap['variations'] ) ) {
					foreach ( $snap['variations'] as $vid => $vdata ) {
						$var = wc_get_product( $vid );
						if ( $var ) {
							$var->set_regular_price( $vdata['regular_price'] );
							$var->set_sale_price( $vdata['sale_price'] );
							$var->set_stock_status( $vdata['stock_status'] );
							$var->set_status( $vdata['status'] );
							if ( isset( $vdata['attributes'] ) && is_array( $vdata['attributes'] ) ) {
								$var->set_attributes( $vdata['attributes'] );
							}
							$var->save();
						}
					}
				}

				// همگام‌سازی و پاکسازی کش والد
				WC_Product_Variable::sync( $product_id );
				wc_delete_product_transients( $product_id );
				$restored_count++;
			}

			// علامت‌گذاری به عنوان بازگردانده‌شده
			self::$runs_cache[ $run_id ]['status']         = 'rolled_back';
			self::$runs_cache[ $run_id ]['rolled_back_at'] = current_time( 'mysql' );
			self::$runs_dirty = true;
			self::flush();

			return array(
				'success'  => true,
				'restored' => $restored_count,
				'message'  => sprintf( 'تغییرات با موفقیت بازگردانی شد (%d محصول به حالت قبل برگشتند).', $restored_count ),
			);
		}
	}
}
