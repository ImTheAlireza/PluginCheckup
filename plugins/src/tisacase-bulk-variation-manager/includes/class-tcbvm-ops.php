<?php
/**
 * موتور اصلی عملیات متغیرها: حل ویژگی، ساخت ترم‌ها، ضرب دکارتی ترکیب‌ها،
 * پاکسازی کامل ویژگی‌های قبلی و بازسازی تمیز متغیرها با قیمت یکپارچه.
 *
 * @package TisaCase_Bulk_Variation_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCBVM_OPS' ) ) {

	final class TCBVM_OPS {

		/**
		 * اطمینان از ثبت بودن تمامی تاکسونومی‌های ویژگی ووکامرس در درخواست‌های ایجکس.
		 */
		public static function ensure_all_attribute_taxonomies_registered() {
			if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
				$taxonomies = wc_get_attribute_taxonomies();
				if ( ! empty( $taxonomies ) ) {
					foreach ( $taxonomies as $tax ) {
						$tax_name = wc_attribute_taxonomy_name( $tax->attribute_name );
						if ( ! taxonomy_exists( $tax_name ) ) {
							register_taxonomy(
								$tax_name,
								apply_filters( 'woocommerce_taxonomy_objects_' . $tax_name, array( 'product' ) ),
								apply_filters( 'woocommerce_taxonomy_args_' . $tax_name, array(
									'hierarchical' => false,
									'show_ui'      => false,
									'query_var'    => true,
									'rewrite'      => false,
									'label'        => ! empty( $tax->attribute_label ) ? $tax->attribute_label : $tax->attribute_name,
								) )
							);
						}
					}
				}
			}
		}

		/**
		 * حل دقیق ویژگی هدف روی خود محصول و شناسایی ویژگی‌های تکراری جهت پاکسازی قطعی.
		 *
		 * @param WC_Product $product         محصول هدف.
		 * @param string     $user_attr_label نام ویژگی واردشده توسط کاربر (مثلاً «مدل» یا «مدل گوشی»).
		 * @return array اطلاعات کلید، نوع تاکسونومی و کلیدهای تکراری که باید از محصول حذف شوند.
		 */
		public static function resolve_product_target_attribute( $product, $user_attr_label ) {
			self::ensure_all_attribute_taxonomies_registered();

			$user_attr_label = trim( (string) $user_attr_label );
			if ( '' === $user_attr_label ) {
				$user_attr_label = 'مدل گوشی';
			}
			$norm_target = TCBVM_DB::normalize_persian( $user_attr_label );

			$existing_attributes = $product instanceof WC_Product ? $product->get_attributes() : array();
			$matched_key         = null;
			$matched_is_taxonomy = false;
			$keys_to_remove      = array();

			// ۱) بررسی ویژگی‌های فعلی موجود روی خود این محصول
			foreach ( $existing_attributes as $key => $attr_obj ) {
				$attr_name  = $attr_obj instanceof WC_Product_Attribute ? $attr_obj->get_name() : $key;
				$attr_label = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $attr_name, $product ) : $attr_name;

				$is_match = false;
				if ( TCBVM_DB::normalize_persian( $attr_label ) === $norm_target ) {
					$is_match = true;
				} elseif ( TCBVM_DB::normalize_persian( $attr_name ) === $norm_target ) {
					$is_match = true;
				} elseif ( TCBVM_DB::normalize_persian( str_replace( 'pa_', '', $attr_name ) ) === $norm_target ) {
					$is_match = true;
				} elseif ( sanitize_title( $attr_label ) === sanitize_title( $user_attr_label ) ) {
					$is_match = true;
				} elseif ( sanitize_title( $attr_name ) === sanitize_title( $user_attr_label ) ) {
					$is_match = true;
				} elseif ( 'pa_' . sanitize_title( $user_attr_label ) === $attr_name ) {
					$is_match = true;
				}

				if ( $is_match ) {
					$is_tax = ( $attr_obj instanceof WC_Product_Attribute && $attr_obj->is_taxonomy() ) || taxonomy_exists( $attr_name );
					if ( null === $matched_key ) {
						$matched_key         = $attr_name;
						$matched_is_taxonomy = $is_tax;
					} else {
						// اگر چند ویژگی با همین نام بود، اولویت قطعی با تاکسونومی سراسری است
						if ( $is_tax && ! $matched_is_taxonomy ) {
							$keys_to_remove[]    = $matched_key;
							$matched_key         = $attr_name;
							$matched_is_taxonomy = true;
						} else {
							$keys_to_remove[] = $attr_name;
						}
					}
				}
			}

			// ۲) اگر روی محصول نبود، بررسی ویژگی‌های عمومی تعریف‌شده در ووکامرس
			if ( null === $matched_key && function_exists( 'wc_get_attribute_taxonomies' ) ) {
				foreach ( (array) wc_get_attribute_taxonomies() as $wc_attr ) {
					if ( empty( $wc_attr->attribute_name ) ) {
						continue;
					}
					$tax_name   = wc_attribute_taxonomy_name( $wc_attr->attribute_name );
					$candidates = array(
						$wc_attr->attribute_name,
						isset( $wc_attr->attribute_label ) ? $wc_attr->attribute_label : '',
						urldecode( $wc_attr->attribute_name ),
					);
					foreach ( $candidates as $cand ) {
						if ( '' !== $cand && TCBVM_DB::normalize_persian( $cand ) === $norm_target ) {
							$matched_key         = $tax_name;
							$matched_is_taxonomy = true;
							break 2;
						}
					}
				}
			}

			// ۳) بررسی اسلاگ متعارف تاکسونومی
			if ( null === $matched_key ) {
				$guess = wc_attribute_taxonomy_name( sanitize_title( $user_attr_label ) );
				if ( taxonomy_exists( $guess ) ) {
					$matched_key         = $guess;
					$matched_is_taxonomy = true;
				} elseif ( taxonomy_exists( $user_attr_label ) ) {
					$matched_key         = $user_attr_label;
					$matched_is_taxonomy = true;
				}
			}

			// ۴) اگر در ووکامرس نبود، ثبت تاکسونومی جدید در سیستم
			if ( null === $matched_key ) {
				$slug = sanitize_title( $user_attr_label );
				if ( empty( $slug ) ) {
					$slug = 'attr_' . time();
				}
				$tax_name = wc_attribute_taxonomy_name( $slug );

				if ( function_exists( 'wc_create_attribute' ) ) {
					wc_create_attribute( array(
						'name'         => $user_attr_label,
						'slug'         => $slug,
						'type'         => 'select',
						'order_by'     => 'menu_order',
						'has_archives' => false,
					) );

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
					$matched_key         = $tax_name;
					$matched_is_taxonomy = true;
				} else {
					$matched_key         = $user_attr_label;
					$matched_is_taxonomy = false;
				}
			}

			return array(
				'name'           => $matched_key,
				'is_taxonomy'    => $matched_is_taxonomy,
				'keys_to_remove' => array_values( array_unique( $keys_to_remove ) ),
			);
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

			// ۱) جستجو بر اساس نام دقیق
			$term = get_term_by( 'name', $name, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term;
			}

			// ۲) جستجو با تطبیق نرمال‌شده فارسی
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

			// ۳) جستجو با اسلاگ
			$slug = sanitize_title( $name );
			if ( '' !== $slug ) {
				$term = get_term_by( 'slug', $slug, $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					return $term;
				}
			}

			// ۴) در صورت نبودن، ترم در این تاکسونومی ساخته شود
			$args = array();
			if ( '' !== $slug ) {
				$args['slug'] = $slug;
			}

			$inserted = wp_insert_term( $name, $taxonomy, $args );
			if ( ! is_wp_error( $inserted ) && isset( $inserted['term_id'] ) ) {
				return get_term( (int) $inserted['term_id'], $taxonomy );
			}

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

			if ( false !== strpos( $raw, "\n" ) || false !== strpos( $raw, "\r" ) || false !== strpos( $raw, '|' ) ) {
				$lines = preg_split( '/[\r\n|]+/', $raw );
			} else {
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
			self::ensure_all_attribute_taxonomies_registered();

			$attr_name  = trim( (string) $attr_name );
			if ( '' === $attr_name ) {
				$attr_name = 'مدل گوشی';
			}

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

				$target_info = self::resolve_product_target_attribute( $product, $attr_name );
				$target_key  = $target_info['name'];

				// بررسی سایر ویژگی‌های متغیر جهت ضرب دکارتی
				$other_desc = array();
				$multiplier = 1;

				if ( $combine_other && $product->is_type( 'variable' ) ) {
					$attrs = $product->get_attributes();
					foreach ( $attrs as $k => $attr_obj ) {
						if ( ! $attr_obj instanceof WC_Product_Attribute || ! $attr_obj->get_variation() ) {
							continue;
						}
						if ( $k === $target_key || $attr_obj->get_name() === $target_key || in_array( $k, $target_info['keys_to_remove'], true ) ) {
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

				if ( count( $samples) < $max_samples ) {
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
		 * اجرای یک بسته محصول در پس‌زمینهٔ ایجکس با پشتیبانی کامل از Rollback.
		 */
		public static function execute_batch( $run_id, array $batch_ids, $attr_name, array $new_values, $price, $sale_price = '', $stock_status = 'instock', $combine_other = true ) {
			self::ensure_all_attribute_taxonomies_registered();

			$clean_values = self::sanitize_model_list( $new_values );
			if ( empty( $clean_values ) ) {
				throw new \InvalidArgumentException( 'لیست مقادیر ویژگی خالی است.' );
			}

			$attr_name = trim( (string) $attr_name );
			if ( '' === $attr_name ) {
				$attr_name = 'مدل گوشی';
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
						$attr_name,
						$clean_values,
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

			TCBVM_Backup::flush();

			return $results;
		}

		/**
		 * بازسازی کامل متغیرهای یک محصول:
		 * ۱. اسنپ‌شات کامل
		 * ۲. پاکسازی قطعی ویژگی‌های قبلی/تکراری و ترم‌های آن‌ها
		 * ۳. ثبت ویژگی هدف با تنها مقادیر جدید
		 * ۴. حذف تمام متغیرهای قبلی محصول
		 * ۵. تولید متغیرها از صفر طبق ترکیب صحیح با قیمت تعیین‌شده
		 *
		 * @param int    $product_id    شناسه محصول.
		 * @param string $run_id        شناسه نشست Rollback.
		 * @param string $attr_name     نام ویژگی واردشده توسط کاربر.
		 * @param array  $clean_values  مقادیر جدید ویژگی.
		 * @param int    $regular_price قیمت عادی.
		 * @param string $sale_price    قیمت فروش ویژه (اختیاری).
		 * @param string $stock_status  وضعیت انبار.
		 * @param bool   $combine_other ترکیب با سایر ویژگی‌ها.
		 * @return array نتیجه عملیات روی این محصول.
		 */
		private static function generate_product_variations( $product_id, $run_id, $attr_name, array $clean_values, $regular_price, $sale_price, $stock_status, $combine_other ) {
			$start_time = microtime( true );

			// ۱) تهیه پشتیبان کامل (Snapshot) قبل از هرگونه تغییر
			TCBVM_Backup::snapshot_product( $run_id, $product_id );

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return array( 'success' => false, 'message' => 'محصول در سیستم یافت نشد.' );
			}

			$title = $product->get_name();

			// ۲) تبدیل به محصول متغیر در صورت ساده بودن
			if ( ! $product->is_type( 'variable' ) ) {
				wp_set_object_terms( $product_id, 'variable', 'product_type' );
				$product = wc_get_product( $product_id );
				if ( ! $product instanceof WC_Product_Variable ) {
					return array( 'success' => false, 'title' => $title, 'message' => 'تبدیل محصول به متغیر با خطا مواجه شد.' );
				}
			}

			// ۳) حل ویژگی هدف و شناسایی ویژگی‌های تکراری جهت پاکسازی قطعی
			$target_info    = self::resolve_product_target_attribute( $product, $attr_name );
			$target_key     = $target_info['name'];
			$is_taxonomy    = $target_info['is_taxonomy'];
			$keys_to_remove = $target_info['keys_to_remove'];

			$existing_attributes = $product->get_attributes();
			$product_attributes  = array();

			// پاکسازی قطعی هر ویژگی تکراری یا قدیمی همنام از محصول
			foreach ( $existing_attributes as $ekey => $eattr ) {
				if ( in_array( $ekey, $keys_to_remove, true ) ) {
					if ( taxonomy_exists( $ekey ) ) {
						wp_set_object_terms( $product_id, array(), $ekey, false );
					}
					continue;
				}

				if ( $is_taxonomy && ( $ekey === $attr_name || sanitize_title( $ekey ) === sanitize_title( $attr_name ) ) && $ekey !== $target_key ) {
					continue;
				}

				$product_attributes[ $ekey ] = $eattr;
			}

			// ۴) ساخت یا واکشی ترم‌های جدید و تنظیم ویژگی
			$target_terms = array();
			$combo_matrix = array();

			if ( $is_taxonomy ) {
				foreach ( $clean_values as $val ) {
					$term = self::ensure_term_exists( $val, $target_key );
					if ( $term ) {
						$target_terms[] = array(
							'id'   => (int) $term->term_id,
							'slug' => $term->slug,
							'name' => $term->name,
						);
					}
				}

				if ( empty( $target_terms ) ) {
					return array( 'success' => false, 'title' => $title, 'message' => 'هیچ مقداری برای تاکسونومی ویژگی ایجاد نشد.' );
				}

				$term_ids   = wp_list_pluck( $target_terms, 'id' );
				$term_slugs = wp_list_pluck( $target_terms, 'slug' );

				// پاکسازی قطعی تمام ترم‌های قبلی این تاکسونومی و ثبت صرفاً مقادیر جدید
				wp_set_object_terms( $product_id, $term_ids, $target_key, false );

				$target_attr_obj = new WC_Product_Attribute();
				$tax_id          = function_exists( 'wc_attribute_taxonomy_id_by_name' ) ? wc_attribute_taxonomy_id_by_name( $target_key ) : 0;
				$target_attr_obj->set_id( $tax_id );
				$target_attr_obj->set_name( $target_key );
				$target_attr_obj->set_options( $term_ids );
				$target_attr_obj->set_position( 0 );
				$target_attr_obj->set_visible( true );
				$target_attr_obj->set_variation( true );

				$product_attributes[ $target_key ] = $target_attr_obj;
				$combo_matrix[ $target_key ]       = $term_slugs;
			} else {
				// ویژگی محلی
				$target_attr_obj = new WC_Product_Attribute();
				$target_attr_obj->set_id( 0 );
				$target_attr_obj->set_name( $target_key );
				$target_attr_obj->set_options( $clean_values );
				$target_attr_obj->set_position( 0 );
				$target_attr_obj->set_visible( true );
				$target_attr_obj->set_variation( true );

				$product_attributes[ $target_key ] = $target_attr_obj;
				$combo_matrix[ $target_key ]       = $clean_values;
			}

			// ۵) استخراج سایر ویژگی‌های متغیر محصول جهت ضرب دکارتی
			if ( $combine_other ) {
				foreach ( $product_attributes as $k => $attr_obj ) {
					if ( ! $attr_obj instanceof WC_Product_Attribute || ! $attr_obj->get_variation() ) {
						continue;
					}
					if ( $k === $target_key || $attr_obj->get_name() === $target_key ) {
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

			// ذخیره قطعی مجموعه ویژگی‌های تمیزشده روی محصول
			$product->set_attributes( $product_attributes );
			$product->save();

			// محاسبه تمام ترکیب‌های دکارتی
			$combinations = self::cartesian_product( $combo_matrix );

			if ( count( $combinations ) > 3000 ) {
				return array(
					'success' => false,
					'title'   => $title,
					'message' => sprintf( 'تعداد ترکیب‌های این محصول (%d متغیر) بیش از سقف مجاز است؛ جهت حفظ سرعت و امنیت رد شد.', count( $combinations ) ),
				);
			}

			// ۶) حذف سریع و یکباره تمام متغیرهای قبلی محصول از دیتابیس
			global $wpdb;
			$old_children  = (array) $product->get_children();
			$deleted_count = count( $old_children );

			if ( $deleted_count > 0 ) {
				$ids_in = implode( ',', array_map( 'absint', $old_children ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$ids_in})" );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$ids_in}) AND post_type = 'product_variation'" );

				foreach ( $old_children as $old_id ) {
					clean_post_cache( $old_id );
				}
			}

			// ۷) تولید پرسرعت متغیرها از صفر و درج بهینه در دیتابیس
			$created_count = 0;
			$price_str     = (string) $regular_price;
			$sale_str      = '' !== (string) $sale_price ? (string) $sale_price : '';
			$final_price   = '' !== $sale_str && (float) $sale_str > 0 ? $sale_str : $price_str;
			$now_mysql     = current_time( 'mysql' );
			$now_gmt       = current_time( 'mysql', 1 );
			$author_id     = get_current_user_id() ? get_current_user_id() : 1;

			foreach ( $combinations as $idx => $combination_attrs ) {
				$wpdb->insert(
					$wpdb->posts,
					array(
						'post_author'           => $author_id,
						'post_date'             => $now_mysql,
						'post_date_gmt'         => $now_gmt,
						'post_content'          => '',
						'post_title'            => sprintf( 'متغیر شماره #%d برای محصول #%d', $idx + 1, $product_id ),
						'post_status'           => 'publish',
						'comment_status'        => 'closed',
						'ping_status'           => 'closed',
						'post_name'             => 'product-' . $product_id . '-variation-' . ( $idx + 1 ),
						'post_modified'         => $now_mysql,
						'post_modified_gmt'     => $now_gmt,
						'post_parent'           => $product_id,
						'guid'                  => home_url( '/?product_variation=' . $product_id . '-' . ( $idx + 1 ) ),
						'menu_order'            => $idx,
						'post_type'             => 'product_variation',
					),
					array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' )
				);

				$var_id = (int) $wpdb->insert_id;
				if ( $var_id ) {
					$meta_data = array(
						'_price'         => $final_price,
						'_regular_price' => $price_str,
						'_sale_price'    => $sale_str,
						'_stock_status'  => $stock_status,
						'_manage_stock'  => 'no',
					);

					foreach ( $combination_attrs as $attr_slug => $val_slug ) {
						$meta_data[ 'attribute_' . sanitize_title( $attr_slug ) ] = (string) $val_slug;
					}

					foreach ( $meta_data as $m_key => $m_val ) {
						$wpdb->insert(
							$wpdb->postmeta,
							array(
								'post_id'    => $var_id,
								'meta_key'   => $m_key,
								'meta_value' => $m_val,
							),
							array( '%d', '%s', '%s' )
						);
					}

					TCBVM_Backup::track_created_variation( $run_id, $product_id, $var_id );
					$created_count++;
					clean_post_cache( $var_id );
				}
			}

			// ۸) همگام‌سازی کامل والد و پاکسازی کش‌ها
			WC_Product_Variable::sync( $product_id );
			wc_delete_product_transients( $product_id );
			delete_transient( 'wc_var_prices_' . $product_id );
			clean_post_cache( $product_id );

			$elapsed = round( microtime( true ) - $start_time, 2 );

			$message = sprintf(
				'ویژگی «%s» بروز شد؛ %d متغیر قبلی حذف و %d متغیر جدید با قیمت %s تومان ثبت گردید (زمان: %s ثانیه).',
				esc_html( $target_key ),
				$deleted_count,
				$created_count,
				number_format_i18n( $regular_price ),
				number_format_i18n( $elapsed, 2 )
			);

			return array(
				'success'      => true,
				'title'        => $title,
				'created'      => $created_count,
				'deleted'      => $deleted_count,
				'message'      => $message,
				'elapsed'      => $elapsed,
				'target_attr'  => $target_key,
				'models_count' => count( $clean_values ),
			);
		}
	}
}
