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
		 * ایجاد رکورد اجرای جدید و دریافت شناسه یکتا.
		 */
		public static function create_run_session( $operation_name, array $product_ids, array $extra_meta = array() ) {
			$run_id = 'run_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 6, false, false );
			$runs   = get_option( TCBVM_Core::OPTION_RUNS, array() );
			if ( ! is_array( $runs ) ) {
				$runs = array();
			}

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

			update_option( TCBVM_Core::OPTION_RUNS, $runs, false );
			return $run_id;
		}

		/**
		 * ثبت اسنپ‌شات وضعیت فعلی یک محصول قبل از ویرایش آن.
		 */
		public static function snapshot_product( $run_id, $product_id ) {
			$runs = get_option( TCBVM_Core::OPTION_RUNS, array() );
			if ( ! isset( $runs[ $run_id ] ) ) {
				return false;
			}

			// اگر قبلاً برای این محصول در این نشست اسنپ‌شات گرفته شده، نیاز به تکرار نیست
			if ( isset( $runs[ $run_id ]['snapshots'][ $product_id ] ) ) {
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

			$runs[ $run_id ]['snapshots'][ $product_id ] = $snapshot_data;
			update_option( TCBVM_Core::OPTION_RUNS, $runs, false );
			return true;
		}

		/**
		 * ثبت متغیرهای جدید ساخته‌شده برای اینکه در Rollback بتوان آن‌ها را تمیز پاک کرد.
		 */
		public static function track_created_variation( $run_id, $product_id, $variation_id ) {
			$runs = get_option( TCBVM_Core::OPTION_RUNS, array() );
			if ( ! isset( $runs[ $run_id ] ) ) {
				return;
			}
			$runs[ $run_id ]['created_vars'][] = absint( $variation_id );
			update_option( TCBVM_Core::OPTION_RUNS, $runs, false );
		}

		/**
		 * بستن و تکمیل نشست اجرا.
		 */
		public static function finish_run_session( $run_id, $status = 'completed', $extra_log = '' ) {
			$runs = get_option( TCBVM_Core::OPTION_RUNS, array() );
			if ( ! isset( $runs[ $run_id ] ) ) {
				return false;
			}
			$runs[ $run_id ]['status']       = sanitize_key( $status );
			$runs[ $run_id ]['completed_at'] = current_time( 'mysql' );
			if ( ! empty( $extra_log ) ) {
				$runs[ $run_id ]['logs'][] = sanitize_text_field( $extra_log );
			}
			return update_option( TCBVM_Core::OPTION_RUNS, $runs, false );
		}

		/**
		 * دریافت اطلاعات تمام اجراها جهت نمایش در جدول تاریخچه و بازگردانی.
		 */
		public static function get_all_runs() {
			$runs = get_option( TCBVM_Core::OPTION_RUNS, array() );
			if ( ! is_array( $runs ) ) {
				return array();
			}
			// معکوس کردن برای نمایش جدیدترین‌ها در ابتدا
			return array_reverse( $runs );
		}

		/**
		 * بازگردانی کامل (Rollback) یک اجرا.
		 *
		 * @param string $run_id شناسه نشست اجرا
		 * @return array نتیجه بازگردانی شامل تعداد محصولات بازگردانده‌شده.
		 */
		public static function rollback_run( $run_id ) {
			$runs = get_option( TCBVM_Core::OPTION_RUNS, array() );
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
			$runs[ $run_id ]['status']        = 'rolled_back';
			$runs[ $run_id ]['rolled_back_at'] = current_time( 'mysql' );
			update_option( TCBVM_Core::OPTION_RUNS, $runs, false );

			return array(
				'success'  => true,
				'restored' => $restored_count,
				'message'  => sprintf( 'تغییرات با موفقیت بازگردانی شد (%d محصول به حالت قبل برگشتند).', $restored_count ),
			);
		}
	}
}
