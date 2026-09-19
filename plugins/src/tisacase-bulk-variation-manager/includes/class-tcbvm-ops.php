<?php
/**
 * موتور اصلی عملیات متغیرها: حل ویژگی، ساخت ترم‌ها، ضرب دکارتی ترکیب‌ها،
 * بازسازی کامل متغیرها و قیمت‌گذاری انبوه.
 *
 * @package TisaCase_Bulk_Variation_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCBVM_OPS' ) ) {

	final class TCBVM_OPS {

		/**
		 * حل یا ساخت تاکسونومی ویژگی موردنظر (مثل pa_model یا ویژگی جدید).
		 *
		 * @param string $label نام ویژگی واردشده توسط کاربر (مثلاً «مدل گوشی»).
		 * @return string نام تاکسونومی (مثل pa_model یا pa_model_phone).
		 */
		public static function resolve_target_attribute( $label ) {
			$label = trim( (string) $label );
			if ( '' === $label ) {
				$label = 'مدل گوشی';
			}

			// ۱) بررسی مستقیم اگر نام تاکسونومی وارد شده باشد (مثل pa_model)
			if ( taxonomy_exists( $label ) ) {
				return $label;
			}

			// ۲) بررسی اسلاگ متعارف تاکسونومی ووکامرس
			$guess = wc_attribute_taxonomy_name( sanitize_title( $label ) );
			if ( taxonomy_exists( $guess ) ) {
				return $guess;
			}

			// ۳) جستجو در ویژگی‌های عمومی ثبت‌شده ووکامرس بر اساس نام یا برچسب
			if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
				$norm_target = TCBVM_DB::normalize_persian( $label );
				$all_attrs   = (array) wc_get_attribute_taxonomies();

				foreach ( $all_attrs as $attribute ) {
					if ( empty( $attribute->attribute_name ) ) {
						continue;
					}

					$tax_name = wc_attribute_taxonomy_name( $attribute->attribute_name );
					$candidates = array(
						$attribute->attribute_name,
						isset( $attribute->attribute_label ) ? $attribute->attribute_label : '',
						urldecode( $attribute->attribute_name ),
					);

					foreach ( $candidates as $cand ) {
						if ( '' !== $cand && TCBVM_DB::normalize_persian( $cand ) === $norm_target ) {
							if ( taxonomy_exists( $tax_name ) ) {
								return $tax_name;
							}
						}
					}
				}
			}

			// ۴) اگر وجود نداشت، تاکسونومی جدید در ووکامرس ثبت شود
			$slug = sanitize_title( $label );
			if ( empty( $slug ) ) {
				$slug = 'attr_' . time();
			}

			$tax_name = wc_attribute_taxonomy_name( $slug );

			if ( ! taxonomy_exists( $tax_name ) && function_exists( 'wc_create_attribute' ) ) {
				$tax_id = wc_create_attribute( array(
					'name'         => $label,
					'slug'         => $slug,
					'type'         => 'select',
					'order_by'     => 'menu_order',
					'has_archives' => false,
				) );

				if ( ! is_wp_error( $tax_id ) ) {
					register_taxonomy(
						$tax_name,
						apply_filters( 'woocommerce_taxonomy_objects_' . $tax_name, array( 'product' ) ),
						apply_filters( 'woocommerce_taxonomy_args_' . $tax_name, array(
							'hierarchical' => false,
							'show_ui'      => false,
							'query_var'    => true,
							'rewrite'      => false,
						) )
					);
				}
			}

			return $tax_name;
		}

		/**
		 * اطمینان از وجود یک ترم (مقدار ویژگی) در تاکسونومی و بازگرداندن آبجکت آن.
		 *
		 * @param string $name نام مقدار (مثلاً «iPhone 15 Pro Max»).
		 * @param string $taxonomy تاکسونومی هدف.
		 * @return WP_Term|null
		 */
		public static function ensure_term_exists( $name, $taxonomy ) {
			$name = trim( (string) $name );
			if ( '' === $name || ! taxonomy_exists( $taxonomy ) ) {
				return null;
			}

			// جستجو بر اساس نام دقیق
			$term = get_term_by( 'name', $name, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term;
			}

			// جستجو با تطبیق نرمال‌شده فارسی
			$norm_name = TCBVM_DB::normalize_persian( $name );
			$all_terms = get_terms( array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			) );

			if ( ! is_wp_error( $all_terms ) && is_array( $all_terms ) ) {
				foreach ( $all_terms as $t ) {
					if ( TCBVM_DB::normalize_persian( $t->name ) === $norm_name ) {
						return $t;
					}
				}
			}

			// جستجو با اسلاگ
			$slug = sanitize_title( $name );
			if ( '' !== $slug ) {
				$term = get_term_by( 'slug', $slug, $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					return $term;
				}
			}

			// در صورت نبودن، ترم ساخته شود
			$args = array();
			if ( '' !== $slug ) {
				$args['slug'] = $slug;
			}

			$inserted = wp_insert_term( $name, $taxonomy, $args );
			if ( ! is_wp_error( $inserted ) && isset( $inserted['term_id'] ) ) {
				return get_term( (int) $inserted['term_id'], $taxonomy );
			}

			// فالبک برای حالتی که خطا اعلام کرد ترم از قبل هست
			if ( is_wp_error( $inserted ) && isset( $inserted->error_data['term_exists'] ) ) {
				return get_term( (int) $inserted->error_data['term_exists'], $taxonomy );
			}

			return null;
		}

		/**
		 * محاسبه ضرب دکارتی تمام ترکیب‌ها برای ویژگی‌های متغیر.
		 *
		 * @param array $input آرایه کلید-مقدار از نام ویژگی به آرایه اسلاگ‌ها/مقادیر.
		 * @return array لیست ترکیب‌ها.
		 */
		public static function cartesian_product( array $input ) {
			$result = array( array() );
			foreach ( $input as $key => $values ) {
				$append = array();
				foreach ( $result as $combination ) {
					foreach ( $values as $value ) {
						$combination[ $key ] = $value;
						$append[]            = $combination;
					}
				}
				$result = $append;
			}
			return $result;
		}

		/**
		 * پاکسازی و تفکیک لیست مدل‌ها/متغیرها.
		 * پشتیبانی از خطوط جدید، خط عمودی | و حفظ کاما داخل نام مدل.
		 *
		 * @param string|array $raw ورودی متنی یا آرایه‌ای.
		 * @return array لیست مقادیر یکتا و مرتب.
		 */
		public static function sanitize_model_list( $raw ) {
			if ( is_array( $raw ) ) {
				$raw = implode( "\n", $raw );
			}
			$raw = (string) $raw;
			if ( '' === trim( $raw ) ) {
				return array();
			}

			// اگر شامل خط جدید یا پایپ است، با آن‌ها جدا شود
			if ( false !== strpos( $raw, "\n" ) || false !== strpos( $raw, "\r" ) || false !== strpos( $raw, '|' ) ) {
				$lines = preg_split( '/[\r\n|]+/', $raw );
			} else {
				// اگر فقط روی یک خط با کاما جدا شده
				$lines = preg_split( '/[,،]+/', $raw );
			}

			$clean = array();
			foreach ( $lines as $line ) {
				$line = trim( (string) $line );
				$line = trim( $line, "\"'`•- " );
				if ( '' !== $line && ! in_array( $line, $clean, true ) ) {
					$clean[] = $line;
				}
			}

			return array_values( $clean );
		}

		/**
		 * پیش‌نمایش تغییرات و تخمین ترکیب‌ها پیش از اعمال قطعی.
		 *
		 * @param array  $product_ids شناسه محصولات.
		 * @param string $attr_name   نام ویژگی هدف (مثلاً «مدل گوشی»).
		 * @param array  $new_values  مقادیر جدید ویژگی.
		 * @param string $price       قیمت متغیرها.
		 * @param string $sale_price  قیمت حراج (اختیاری).
		 * @param bool   $combine_other ترکیب با سایر ویژگی‌های متغیر.
		 * @return array داده‌های پیش‌نمایش.
		 */
		public static function preview( array $product_ids, $attr_name, array $new_values, $price, $sale_price = '', $combine_other = true ) {
			$attr_name  = trim( (string) $attr_name );
			if ( '' === $attr_name ) {
				$attr_name = 'مدل گوشی';
			}

			$taxonomy   = self::resolve_target_attribute( $attr_name );
			$clean_vals = self::sanitize_model_list( $new_values );
			$val_count  = count( $clean_vals );

			$price_num  = absint( preg_replace( '/[^\d]/', '', (string) $price ) );
			$sale_num   = absint( preg_replace( '/[^\d]/', '', (string) $sale_price ) );

			$samples     = array();
			$total_old   = 0;
			$total_new   = 0;
			$max_samples = 25;

			foreach ( $product_ids as $pid ) {
				$product = wc_get_product( $pid );
				if ( ! $product ) {
					continue;
				}

				$old_count = $product->is_type( 'variable' ) ? count( $product->get_children() ) : 0;
				$total_old += $old_count;

				// بررسی سایر ویژگی‌های متغیر جهت ضرب دکارتی
				$other_desc = array();
				$multiplier = 1;

				if ( $combine_other && $product->is_type( 'variable' ) ) {
					$attrs = $product->get_attributes();
					foreach ( $attrs as $k => $attr_obj ) {
						if ( ! $attr_obj instanceof WC_Product_Attribute || ! $attr_obj->get_variation() ) {
							continue;
						}
						if ( $k === $taxonomy || $attr_obj->get_name() === $taxonomy || $attr_obj->get_name() === $attr_name ) {
							continue;
						}

						$opt_count = 0;
						if ( $attr_obj->is_taxonomy() ) {
							$terms = wc_get_product_terms( $pid, $attr_obj->get_name(), array( 'fields' => 'names' ) );
							$opt_count = is_array( $terms ) ? count( $terms ) : 0;
							if ( $opt_count > 0 ) {
								$label = wc_attribute_label( $attr_obj->get_name() );
								$other_desc[] = sprintf( '%s (%d گزینه)', $label ? $label : $attr_obj->get_name(), $opt_count );
							}
						} else {
							$opts = (array) $attr_obj->get_options();
							$opt_count = count( $opts );
							if ( $opt_count > 0 ) {
								$other_desc[] = sprintf( '%s (%d گزینه)', $attr_obj->get_name(), $opt_count );
							}
						}

						if ( $opt_count > 0 ) {
							$multiplier *= $opt_count;
						}
					}
				}

				$new_count = $val_count * $multiplier;
				$total_new += $new_count;

				if ( count( $samples ) < $max_samples ) {
					$samples[] = array(
						'id'          => $pid,
						'name'        => $product->get_name(),
						'type'        => $product->get_type(),
						'old_vars'    => $old_count,
						'new_vars'    => $new_count,
						'multiplier'  => $multiplier,
						'other_attrs' => ! empty( $other_desc ) ? implode( ' + ', $other_desc ) : 'تک‌ویژگی',
					);
				}
			}

			return array(
				'total_products'  => count( $product_ids ),
				'attr_name'       => $attr_name,
				'taxonomy'        => $taxonomy,
				'new_values'      => $clean_vals,
				'new_values_count'=> $val_count,
				'price'           => $price_num,
				'sale_price'      => $sale_num > 0 ? $sale_num : null,
				'total_old_vars'  => $total_old,
				'total_new_vars'  => $total_new,
				'samples'         => $samples,
				'combine_other'   => (bool) $combine_other,
			);
		}

		/**
		 * اجرای یک دسته محصول در پس‌زمینهٔ ایجکس با پشتیبانی کامل از Rollback.
		 *
		 * @param string $run_id       شناسه نشست پشتیبان.
		 * @param array  $batch_ids    شناسه‌های محصولات این بسته.
		 * @param string $attr_name    نام ویژگی هدف.
		 * @param array  $new_values   مقادیر جدید ویژگی.
		 * @param string $price        قیمت متغیرها.
		 * @param string $sale_price   قیمت فروش ویژه (اختیاری).
		 * @param string $stock_status وضعیت موجودی (پیش‌فرض instock).
		 * @param bool   $combine_other ترکیب با سایر ویژگی‌ها.
		 * @return array گزارش نتیجه بسته.
		 */
		public static function execute_batch( $run_id, array $batch_ids, $attr_name, array $new_values, $price, $sale_price = '', $stock_status = 'instock', $combine_other = true ) {
			$clean_values = self::sanitize_model_list( $new_values );
			if ( empty( $clean_values ) ) {
				throw new \InvalidArgumentException( 'لیست مقادیر ویژگی خالی است.' );
			}

			$attr_name  = trim( (string) $attr_name );
			if ( '' === $attr_name ) {
				$attr_name = 'مدل گوشی';
			}

			$taxonomy = self::resolve_target_attribute( $attr_name );

			// ترم‌های لازم در تاکسونومی ساخته و آماده شوند
			$target_terms = array();
			foreach ( $clean_values as $val ) {
				$term = self::ensure_term_exists( $val, $taxonomy );
				if ( $term ) {
					$target_terms[] = array(
						'id'   => (int) $term->term_id,
						'slug' => $term->slug,
						'name' => $term->name,
					);
				}
			}

			if ( empty( $target_terms ) ) {
				throw new \RuntimeException( 'هیچ مقداری در تاکسونومی ویژگی ساخته یا یافت نشد.' );
			}

			$reg_price = absint( preg_replace( '/[^\d]/', '', (string) $price ) );
			$sal_price = absint( preg_replace( '/[^\d]/', '', (string) $sale_price ) );
			$stock_st  = in_array( $stock_status, array( 'instock', 'outofstock' ), true ) ? $stock_status : 'instock';

			$results = array(
				'processed' => 0,
				'success'   => 0,
				'failed'    => 0,
				'items'     => array(),
			);

			foreach ( $batch_ids as $product_id ) {
				$results['processed']++;

				try {
					$res = self::generate_product_variations(
						$product_id,
						$run_id,
						$taxonomy,
						$attr_name,
						$target_terms,
						$reg_price,
						$sal_price > 0 ? $sal_price : '',
						$stock_st,
						(bool) $combine_other
					);

					if ( $res['success'] ) {
						$results['success']++;
						$results['items'][] = array(
							'id'      => $product_id,
							'status'  => 'success',
							'title'   => $res['title'],
							'message' => $res['message'],
							'created' => $res['created'],
							'deleted' => $res['deleted'],
						);
					} else {
						$results['failed']++;
						$results['items'][] = array(
							'id'      => $product_id,
							'status'  => 'error',
							'title'   => isset( $res['title'] ) ? $res['title'] : "محصول #{$product_id}",
							'message' => $res['message'],
						);
					}
				} catch ( \Throwable $e ) {
					$results['failed']++;
					$results['items'][] = array(
						'id'      => $product_id,
						'status'  => 'error',
						'title'   => "محصول #{$product_id}",
						'message' => 'خطا: ' . $e->getMessage(),
					);
				}
			}

			// نوشتن تغییرات اسنپ‌شات‌ها و لاگ‌ها در دیتابیس در پایان بسته
			TCBVM_Backup::flush();

			return $results;
		}

		/**
		 * بازسازی کامل و تمیز متغیرهای یک محصول طبق ترکیب صحیح.
		 *
		 * @param int    $product_id    شناسه محصول.
		 * @param string $run_id        شناسه نشست Rollback.
		 * @param string $taxonomy      تاکسونومی ویژگی.
		 * @param string $attr_label    برچسب ویژگی.
		 * @param array  $target_terms  ترم‌های ویژگی هدف.
		 * @param int    $regular_price قیمت عادی.
		 * @param string $sale_price    قیمت فروش ویژه (اختیاری).
		 * @param string $stock_status  وضعیت انبار.
		 * @param bool   $combine_other ترکیب با سایر ویژگی‌ها.
		 * @return array نتیجه عملیات روی این محصول.
		 */
		private static function generate_product_variations( $product_id, $run_id, $taxonomy, $attr_label, array $target_terms, $regular_price, $sale_price, $stock_status, $combine_other ) {
			// ۱) تهیه پشتیبان کامل (Snapshot) قبل از هرگونه تغییر
			TCBVM_Backup::snapshot_product( $run_id, $product_id );

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return array( 'success' => false, 'message' => 'محصول در سیستم یافت نشد.' );
			}

			$title = $product->get_name();

			// ۲) اگر محصول ساده است، به متغیر تبدیل شود
			if ( ! $product->is_type( 'variable' ) ) {
				wp_set_object_terms( $product_id, 'variable', 'product_type' );
				$product = wc_get_product( $product_id );
				if ( ! $product instanceof WC_Product_Variable ) {
					return array( 'success' => false, 'title' => $title, 'message' => 'تبدیل محصول به متغیر با خطا مواجه شد.' );
				}
			}

			// ۳) استخراج سایر ویژگی‌های متغیر محصول (در صورت وجود و فعال بودن ترکیب)
			$existing_attributes = $product->get_attributes();
			$combo_matrix        = array();

			// بعد اول ماتریس: مقادیر ویژگی هدف ما
			$target_slugs = wp_list_pluck( $target_terms, 'slug' );
			$combo_matrix[ $taxonomy ] = $target_slugs;

			// ابعاد بعدی: سایر ویژگی‌های متغیر محصول
			if ( $combine_other ) {
				foreach ( $existing_attributes as $k => $attr_obj ) {
					if ( ! $attr_obj instanceof WC_Product_Attribute || ! $attr_obj->get_variation() ) {
						continue;
					}
					if ( $k === $taxonomy || $attr_obj->get_name() === $taxonomy || $attr_obj->get_name() === $attr_label ) {
						continue;
					}

					$opts = array();
					if ( $attr_obj->is_taxonomy() ) {
						$terms = wc_get_product_terms( $product_id, $attr_obj->get_name(), array( 'fields' => 'slugs' ) );
						if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
							$opts = $terms;
						}
					} else {
						$raw_opts = (array) $attr_obj->get_options();
						if ( ! empty( $raw_opts ) ) {
							$opts = $raw_opts;
						}
					}

					if ( ! empty( $opts ) ) {
						$combo_matrix[ $attr_obj->get_name() ] = array_values( array_unique( $opts ) );
					}
				}
			}

			// محاسبه تمام ترکیب‌های دکارتی
			$combinations = self::cartesian_product( $combo_matrix );

			// محافظ ایمنی در برابر انفجار ترکیبی نامعقول
			if ( count( $combinations ) > 2000 ) {
				return array(
					'success' => false,
					'title'   => $title,
					'message' => sprintf( 'تعداد ترکیب‌های این محصول (%d متغیر) بیش از سقف مجاز (۲۰۰۰) است؛ جهت جلوگیری از خطای سرور رد شد.', count( $combinations ) ),
				);
			}

			// ۴) حذف متغیرهای قبلی محصول از صفر
			$old_children = $product->get_children();
			$deleted_count = 0;
			foreach ( $old_children as $old_id ) {
				wp_delete_post( $old_id, true );
				$deleted_count++;
			}

			// ۵) به‌روزرسانی ویژگی هدف در والد
			$term_ids = wp_list_pluck( $target_terms, 'id' );
			wp_set_object_terms( $product_id, $term_ids, $taxonomy, false );

			// تنظیم آبجکت ویژگی در متای محصول والد
			$product_attributes = $existing_attributes;

			$target_attr_obj = new WC_Product_Attribute();
			$tax_id          = function_exists( 'wc_attribute_taxonomy_id_by_name' ) ? wc_attribute_taxonomy_id_by_name( $taxonomy ) : 0;
			$target_attr_obj->set_id( $tax_id );
			$target_attr_obj->set_name( $taxonomy );
			$target_attr_obj->set_options( $term_ids );
			$target_attr_obj->set_position( 0 );
			$target_attr_obj->set_visible( true );
			$target_attr_obj->set_variation( true );

			$product_attributes[ $taxonomy ] = $target_attr_obj;
			$product->set_attributes( $product_attributes );
			$product->save();

			// ۶) تولید تمامی متغیرها از صفر طبق ترکیب صحیح
			$created_count = 0;
			$price_str     = (string) $regular_price;
			$sale_str      = '' !== (string) $sale_price ? (string) $sale_price : '';

			foreach ( $combinations as $combination_attrs ) {
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $product_id );
				$variation->set_status( 'publish' );
				$variation->set_attributes( $combination_attrs );

				// تنظیم قیمت الزامی
				$variation->set_regular_price( $price_str );
				if ( '' !== $sale_str && (float) $sale_str > 0 ) {
					$variation->set_sale_price( $sale_str );
					$variation->set_price( $sale_str );
				} else {
					$variation->set_price( $price_str );
				}

				$variation->set_stock_status( $stock_status );
				$variation->set_manage_stock( false );

				$var_id = $variation->save();

				if ( $var_id ) {
					// ثبت صریح متای attribute_ برای تضمین ۱۰۰ درصدی تطبیق در ووکامرس
					foreach ( $combination_attrs as $attr_slug => $val_slug ) {
						update_post_meta( $var_id, 'attribute_' . sanitize_title( $attr_slug ), $val_slug );
					}

					TCBVM_Backup::track_created_variation( $run_id, $product_id, $var_id );
					$created_count++;
				}
			}

			// ۷) همگام‌سازی کامل محصول والد و نوسازی ترنزینت‌ها
			WC_Product_Variable::sync( $product_id );
			wc_delete_product_transients( $product_id );
			delete_transient( 'wc_var_prices_' . $product_id );

			$message = sprintf(
				'بازسازی کامل متغیرها: %d متغیر قدیمی حذف و %d متغیر جدید با قیمت %s تومان ایجاد شد.',
				$deleted_count,
				$created_count,
				number_format_i18n( $regular_price )
			);

			return array(
				'success' => true,
				'title'   => $title,
				'created' => $created_count,
				'deleted' => $deleted_count,
				'message' => $message,
			);
		}
	}
}
