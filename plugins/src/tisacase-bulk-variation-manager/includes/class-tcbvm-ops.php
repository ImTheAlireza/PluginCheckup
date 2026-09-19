<?php
/**
 * موتور عملیات متغیرها: افزودن، حذف، تغییر نام، قیمت‌گذاری هوشمند و همگام‌سازی ویژگی‌ها و متغیرهای ووکامرس.
 *
 * @package TisaCase_Bulk_Variation_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCBVM_OPS' ) ) {

	final class TCBVM_OPS {

		/**
		 * لیست کلیدهای عملیات معتبر.
		 */
		public static function supported_ops() {
			return array(
				'add_models'       => 'افزودن مدل‌های جدید به محصولات',
				'remove_models'    => 'حذف یا ناموجود کردن مدل‌های قدیمی',
				'replace_model'    => 'جایگزینی / تغییر نام یک مدل',
				'sync_preset'      => 'همگام‌سازی کامل با الگو (حذف قدیمی‌ها + افزودن جدیدها)',
				'bulk_price_stock' => 'تغییر قیمت یا موجودی برای مدل‌های خاص',
			);
		}

		/**
		 * پیش‌نمایش زنده تغییرات برای نمونه‌ای از محصولات (بدون اعمال در دیتابیس).
		 */
		public static function preview( array $product_ids, $operation, array $params ) {
			$preview_ids = array_slice( $product_ids, 0, 5 );
			$results     = array();

			foreach ( $preview_ids as $pid ) {
				$product = wc_get_product( $pid );
				if ( ! $product ) {
					continue;
				}

				$current_models = self::get_product_model_names( $product );
				$item_preview   = array(
					'id'             => $pid,
					'name'           => $product->get_name(),
					'sku'            => $product->get_sku() ? $product->get_sku() : '—',
					'current_models' => $current_models,
					'to_add'         => array(),
					'to_remove'      => array(),
					'to_modify'      => array(),
					'notes'          => array(),
				);

				switch ( $operation ) {
					case 'add_models':
						$new_models = self::sanitize_model_list( $params['models'] ?? '' );
						foreach ( $new_models as $m ) {
							if ( in_array( $m, $current_models, true ) ) {
								$item_preview['notes'][] = sprintf( 'مدل «%s» از قبل وجود دارد (رد می‌شود)', $m );
							} else {
								$item_preview['to_add'][] = $m;
							}
						}
						break;

					case 'remove_models':
						$target_models = self::sanitize_model_list( $params['models'] ?? '' );
						$mode          = $params['delete_mode'] ?? 'soft';
						foreach ( $target_models as $m ) {
							if ( in_array( $m, $current_models, true ) ) {
								$label = ( 'hard' === $mode ) ? 'حذف کامل' : 'ناموجود کردن';
								$item_preview['to_remove'][] = sprintf( '%s (%s)', $m, $label );
							}
						}
						break;

					case 'replace_model':
						$old_m = trim( $params['old_model'] ?? '' );
						$new_m = trim( $params['new_model'] ?? '' );
						if ( in_array( $old_m, $current_models, true ) ) {
							$item_preview['to_modify'][] = sprintf( 'تبدیل «%s» به «%s»', $old_m, $new_m );
						} else {
							$item_preview['notes'][] = sprintf( 'مدل قدیمی «%s» در این محصول وجود ندارد', $old_m );
						}
						break;

					case 'sync_preset':
						$preset_models = self::sanitize_model_list( $params['models'] ?? '' );
						$to_add = array_diff( $preset_models, $current_models );
						$to_rem = array_diff( $current_models, $preset_models );
						$item_preview['to_add']    = array_values( $to_add );
						$item_preview['to_remove'] = array_values( $to_rem );
						break;

					case 'bulk_price_stock':
						$models = self::sanitize_model_list( $params['models'] ?? '' );
						$change_desc = array();
						if ( ! empty( $params['regular_price'] ) ) {
							$change_desc[] = sprintf( 'قیمت عادی: %s', number_format_i18n( (float) $params['regular_price'] ) );
						}
						if ( ! empty( $params['stock_status'] ) ) {
							$change_desc[] = ( 'instock' === $params['stock_status'] ) ? 'موجود' : 'ناموجود';
						}
						foreach ( $models as $m ) {
							if ( in_array( $m, $current_models, true ) ) {
								$item_preview['to_modify'][] = sprintf( '%s ← %s', $m, implode( ' | ', $change_desc ) );
							}
						}
						break;
				}

				$results[] = $item_preview;
			}

			return array(
				'total_selected' => count( $product_ids ),
				'preview_count'  => count( $results ),
				'samples'        => $results,
			);
		}

		/**
		 * کش درون‌درخواستی نقشهٔ متغیرها: [ product_id => [ term_slug => variation_id ] ].
		 *
		 * قبلاً برای هر مدل، همهٔ فرزندان محصول با wc_get_product و get_attributes بارگذاری
		 * می‌شدند (O(مدل × متغیر))؛ حالا یک‌بار ساخته و در ادامهٔ همان درخواست استفاده می‌شود.
		 *
		 * @var array
		 */
		private static $variation_map = array();

		/**
		 * ساخت/دریافت نقشهٔ اسلاگ مدل ← شناسهٔ متغیر برای یک محصول.
		 *
		 * @param WC_Product_Variable $product محصول.
		 * @return array
		 */
		public static function get_variation_map( WC_Product_Variable $product ) {
			$pid = $product->get_id();
			if ( isset( self::$variation_map[ $pid ] ) ) {
				return self::$variation_map[ $pid ];
			}

			$map = array();
			foreach ( $product->get_children() as $vid ) {
				$var = wc_get_product( $vid );
				if ( ! $var ) {
					continue;
				}
				foreach ( (array) $var->get_attributes() as $slug ) {
					if ( is_string( $slug ) && '' !== $slug ) {
						$map[ $slug ] = $vid;
					}
				}
			}

			self::$variation_map[ $pid ] = $map;
			return $map;
		}

		/**
		 * باطل‌کردن نقشهٔ کش‌شدهٔ یک محصول پس از تغییر متغیرهایش.
		 *
		 * @param int $product_id شناسهٔ محصول.
		 */
		public static function forget_variation_map( $product_id ) {
			unset( self::$variation_map[ absint( $product_id ) ] );
		}

		/**
		 * اجرای یک بسته (Batch) از عملیات روی محصولات مشخص‌شده.
		 */
		public static function execute_batch( $run_id, array $batch_ids, $operation, array $params ) {
			$success_count = 0;
			$failed_count  = 0;
			$details       = array();

			foreach ( $batch_ids as $pid ) {
				try {
					// ۱. تهیه اسنپ‌شات قبل از تغییر محصول
					TCBVM_Backup::snapshot_product( $run_id, $pid );

					$res = self::process_single_product( $run_id, $pid, $operation, $params );
				} catch ( \Throwable $e ) {
					// خطای یک محصول نباید کل بسته (و کل اجرا) را از کار بیندازد.
					$res = array(
						'success' => false,
						'message' => 'خطای غیرمنتظره: ' . $e->getMessage(),
					);
				} catch ( \Exception $e ) { // سازگاری با PHP 5/7 بدون Throwable.
					$res = array(
						'success' => false,
						'message' => 'خطای غیرمنتظره: ' . $e->getMessage(),
					);
				}

				if ( ! empty( $res['success'] ) ) {
					$success_count++;
				} else {
					$failed_count++;
				}

				$details[] = array(
					'id'      => $pid,
					'success' => ! empty( $res['success'] ),
					'message' => isset( $res['message'] ) ? $res['message'] : '',
				);
			}

			// یک‌بار در پایان بسته اسنپ‌شات‌ها/متغیرهای ساخته‌شده ذخیره می‌شوند.
			TCBVM_Backup::flush();

			return array(
				'processed' => count( $batch_ids ),
				'success'   => $success_count,
				'failed'    => $failed_count,
				'details'   => $details,
			);
		}

		/**
		 * پردازش عملیات روی یک محصول تکی.
		 */
		private static function process_single_product( $run_id, $product_id, $operation, array $params ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return array(
					'success' => false,
					'message' => 'محصول یافت نشد.',
				);
			}

			// اگر محصول ساده است، آن را به متغیر ارتقا می‌دهیم
			if ( ! $product->is_type( 'variable' ) ) {
				wp_set_object_terms( $product_id, 'variable', 'product_type' );
				clean_post_cache( $product_id );
				$product = wc_get_product( $product_id );
			}

			$target_attr_name = ! empty( $params['attr_name'] ) ? sanitize_text_field( $params['attr_name'] ) : 'مدل گوشی';
			$taxonomy         = self::find_or_create_attribute_taxonomy( $target_attr_name );

			switch ( $operation ) {
				case 'add_models':
					return self::do_add_models( $run_id, $product, $taxonomy, $params );

				case 'remove_models':
					return self::do_remove_models( $product, $taxonomy, $params );

				case 'replace_model':
					return self::do_replace_model( $product, $taxonomy, $params );

				case 'sync_preset':
					return self::do_sync_preset( $run_id, $product, $taxonomy, $params );

				case 'bulk_price_stock':
					return self::do_bulk_price_stock( $product, $taxonomy, $params );

				default:
					return array(
						'success' => false,
						'message' => 'عملیات نامعتبر است.',
					);
			}
		}

		/**
		 * ساخت SKU یکتا برای متغیر.
		 *
		 * ریشهٔ خطای «SKU نامعتبر یا تکراری است»: کد قبلی بدون بررسی، SKU را
		 * «SKU والد + اسلاگ مدل» می‌ساخت؛ اگر همان SKU از قبل در فروشگاه وجود داشت
		 * (متغیر باقی‌ماندهٔ اجرای قبلی، حذف نرم، یا SKU مشابه در محصول دیگر)
		 * ووکامرس در save() استثنا می‌داد و کل محصول (و در عمل کل بسته) شکست می‌خورد.
		 *
		 * @param string $base SKU پیشنهادی.
		 * @return string SKU یکتا یا رشتهٔ خالی اگر امکان ساخت نبود.
		 */
		public static function unique_variation_sku( $base ) {
			$base = trim( (string) $base );
			if ( '' === $base ) {
				return '';
			}
			if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
				return $base;
			}

			$candidate = $base;
			$suffix    = 2;
			while ( wc_get_product_id_by_sku( $candidate ) ) {
				$candidate = $base . '-' . $suffix;
				$suffix++;
				if ( $suffix > 50 ) {
					return ''; // به‌جای شکست عملیات، بدون SKU ذخیره می‌شود.
				}
			}
			return $candidate;
		}

		/**
		 * ذخیرهٔ ایمن یک متغیر: اگر ووکامرس به‌خاطر SKU استثنا داد، بدون SKU
		 * دوباره تلاش می‌شود (یک مدل مشکل‌دار نباید کل محصول را شکست بدهد).
		 *
		 * @param WC_Product_Variation $variation متغیر.
		 * @return array{id:int, warning:string} شناسهٔ متغیر (۰ = ناموفق) و هشدار.
		 */
		private static function save_variation_safely( $variation ) {
			try {
				$var_id = $variation->save();
				return array( 'id' => (int) $var_id, 'warning' => '' );
			} catch ( \Exception $e ) {
				$message   = $e->getMessage();
				$is_sku    = ( false !== mb_stripos( $message, 'SKU' ) );
				$had_sku   = '' !== (string) $variation->get_sku();

				if ( $is_sku && $had_sku ) {
					// تلاش دوباره بدون SKU (SKU دستی بعداً قابل ثبت است).
					try {
						$variation->set_sku( '' );
						$var_id = $variation->save();
						return array(
							'id'      => (int) $var_id,
							'warning' => 'SKU خودکار ثبت نشد (تکراری بود)؛ متغیر بدون SKU ساخته شد.',
						);
					} catch ( \Exception $e2 ) {
						return array( 'id' => 0, 'warning' => $e2->getMessage() );
					}
				}

				return array( 'id' => 0, 'warning' => $message );
			}
		}

		/**
		 * عملیات ۱: افزودن مدل‌های جدید به محصول.
		 */
		private static function do_add_models( $run_id, WC_Product_Variable $product, $taxonomy, array $params ) {
			$product_id   = $product->get_id();
			$models_to_add = self::sanitize_model_list( $params['models'] ?? '' );
			if ( empty( $models_to_add ) ) {
				return array( 'success' => false, 'message' => 'هیچ مدلی وارد نشده است.' );
			}

			$existing_terms = self::get_product_attribute_terms( $product_id, $taxonomy );
			$terms_to_assign = $existing_terms;
			$added_count     = 0;
			$warnings        = array();

			// تولید SKU خودکار فقط اگر در تنظیمات فعال باشد (پیش‌فرض: فعال).
			$settings  = TCBVM_Core::get_settings();
			$auto_sku  = ! isset( $settings['auto_sku'] ) || ! empty( $settings['auto_sku'] );
			$parent_sku = $auto_sku ? (string) $product->get_sku() : '';

			// پیدا کردن قیمت مرجع در صورت انتخاب شبیه‌سازی قیمت
			$clone_price_ref = ! empty( $params['clone_from_model'] ) ? trim( $params['clone_from_model'] ) : '';
			$ref_prices      = $clone_price_ref ? self::get_reference_variation_prices( $product, $taxonomy, $clone_price_ref ) : null;

			$default_reg_price  = isset( $params['regular_price'] ) && '' !== $params['regular_price'] ? (float) $params['regular_price'] : '';
			$default_sale_price = isset( $params['sale_price'] ) && '' !== $params['sale_price'] ? (float) $params['sale_price'] : '';
			$stock_status       = ! empty( $params['stock_status'] ) ? sanitize_key( $params['stock_status'] ) : 'instock';

			foreach ( $models_to_add as $model_name ) {
				// اطمینان از وجود ترم در تاکسونومی ووکامرس
				$term_obj = self::ensure_term_exists( $model_name, $taxonomy );
				if ( ! $term_obj ) {
					continue;
				}

				if ( ! in_array( $term_obj->slug, $terms_to_assign, true ) ) {
					$terms_to_assign[] = $term_obj->slug;
				}

				// بررسی عدم وجود قبلی متغیر برای این مدل
				$existing_var_id = self::find_variation_by_term( $product, $taxonomy, $term_obj->slug );
				if ( $existing_var_id ) {
					continue; // از قبل هست
				}

				// ساخت متغیر جدید
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $product_id );
				$variation->set_status( 'publish' );
				$variation->set_stock_status( $stock_status );
				$variation->set_attributes( array(
					$taxonomy => $term_obj->slug,
				) );

				// تعیین قیمت (اگر از مدل مرجع خوانده شد یا قیمت پیش‌فرض)
				if ( $ref_prices && '' !== $ref_prices['regular_price'] ) {
					$variation->set_regular_price( $ref_prices['regular_price'] );
					if ( '' !== $ref_prices['sale_price'] ) {
						$variation->set_sale_price( $ref_prices['sale_price'] );
					}
				} else {
					if ( '' !== $default_reg_price ) {
						$variation->set_regular_price( $default_reg_price );
					}
					if ( '' !== $default_sale_price ) {
						$variation->set_sale_price( $default_sale_price );
					}
				}

				// الگوی SKU خودکار (با تضمین یکتا بودن تا ووکامرس استثنا ندهد).
				if ( '' !== $parent_sku ) {
					$sku = self::unique_variation_sku( $parent_sku . '-' . $term_obj->slug );
					if ( '' !== $sku ) {
						$variation->set_sku( $sku );
					}
				}

				$saved = self::save_variation_safely( $variation );
				if ( ! empty( $saved['warning'] ) ) {
					$warnings[] = $term_obj->name . ': ' . $saved['warning'];
				}
				if ( ! empty( $saved['id'] ) ) {
					TCBVM_Backup::track_created_variation( $run_id, $product_id, $saved['id'] );
					self::$variation_map[ $product_id ][ $term_obj->slug ] = $saved['id'];
					$added_count++;
				} else {
					$warnings[] = $term_obj->name . ': ساخت متغیر ناموفق بود.';
				}
			}

			// اتصال ویژگی به محصول والد
			self::sync_product_attribute( $product, $taxonomy, $terms_to_assign );

			// همگام‌سازی نهایی ووکامرس
			WC_Product_Variable::sync( $product_id );
			wc_delete_product_transients( $product_id );
			self::forget_variation_map( $product_id );

			$message = sprintf( '%d متغیر جدید اضافه شد.', $added_count );
			if ( ! empty( $warnings ) ) {
				$message .= ' — هشدارها: ' . implode( ' | ', array_slice( array_unique( $warnings ), 0, 3 ) );
			}

			return array(
				// فقط وقتی شکست کامل است که هیچ متغیری ساخته نشده ولی خطا داشته‌ایم.
				'success' => ( $added_count > 0 || empty( $warnings ) ),
				'message' => $message,
			);
		}

		/**
		 * عملیات ۲: حذف یا ناموجود کردن مدل‌های قدیمی.
		 */
		private static function do_remove_models( WC_Product_Variable $product, $taxonomy, array $params ) {
			$product_id       = $product->get_id();
			$models_to_remove = self::sanitize_model_list( $params['models'] ?? '' );
			$mode             = $params['delete_mode'] ?? 'soft';
			$removed_count    = 0;

			$existing_slugs = self::get_product_attribute_terms( $product_id, $taxonomy );
			$slugs_to_keep  = $existing_slugs;

			foreach ( $models_to_remove as $model_name ) {
				$term = get_term_by( 'name', $model_name, $taxonomy );
				if ( ! $term ) {
					$term = get_term_by( 'slug', sanitize_title( $model_name ), $taxonomy );
				}
				if ( ! $term ) {
					continue;
				}

				$var_id = self::find_variation_by_term( $product, $taxonomy, $term->slug );
				if ( $var_id ) {
					$variation = wc_get_product( $var_id );
					if ( $variation ) {
						if ( 'hard' === $mode ) {
							$variation->delete( true );
						} else {
							// Soft delete: ناموجود و غیرفعال کردن
							$variation->set_stock_status( 'outofstock' );
							$variation->set_status( 'private' );
							$variation->save();
						}
						$removed_count++;
					}
				}

				if ( 'hard' === $mode ) {
					$slugs_to_keep = array_diff( $slugs_to_keep, array( $term->slug ) );
				}
			}

			if ( 'hard' === $mode ) {
				self::sync_product_attribute( $product, $taxonomy, array_values( $slugs_to_keep ) );
			}

			WC_Product_Variable::sync( $product_id );
			wc_delete_product_transients( $product_id );
			self::forget_variation_map( $product_id );

			return array(
				'success' => true,
				'message' => sprintf( '%d مدل %s شد.', $removed_count, ( 'hard' === $mode ) ? 'حذف' : 'ناموجود' ),
			);
		}

		/**
		 * عملیات ۳: جایگزینی یا تغییر نام مدل.
		 */
		private static function do_replace_model( WC_Product_Variable $product, $taxonomy, array $params ) {
			$product_id = $product->get_id();
			$old_name   = trim( $params['old_model'] ?? '' );
			$new_name   = trim( $params['new_model'] ?? '' );

			if ( empty( $old_name ) || empty( $new_name ) ) {
				return array( 'success' => false, 'message' => 'نام مدل قدیمی یا جدید مشخص نشده است.' );
			}

			$old_term = get_term_by( 'name', $old_name, $taxonomy );
			if ( ! $old_term ) {
				$old_term = get_term_by( 'slug', sanitize_title( $old_name ), $taxonomy );
			}
			if ( ! $old_term ) {
				return array( 'success' => true, 'message' => 'مدل قدیمی در این محصول یافت نشد.' );
			}

			$new_term = self::ensure_term_exists( $new_name, $taxonomy );
			if ( ! $new_term ) {
				return array( 'success' => false, 'message' => 'امکان ساخت مدل جدید وجود ندارد.' );
			}

			$var_id   = self::find_variation_by_term( $product, $taxonomy, $old_term->slug );
			$warning  = '';
			if ( $var_id ) {
				$variation = wc_get_product( $var_id );
				if ( $variation ) {
					$variation->set_attributes( array(
						$taxonomy => $new_term->slug,
					) );
					$saved   = self::save_variation_safely( $variation );
					$warning = empty( $saved['id'] ) ? ( ' — هشدار: ' . $saved['warning'] ) : '';
				}
			}

			$terms   = self::get_product_attribute_terms( $product_id, $taxonomy );
			$terms   = array_diff( $terms, array( $old_term->slug ) );
			$terms[] = $new_term->slug;
			self::sync_product_attribute( $product, $taxonomy, array_unique( $terms ) );

			WC_Product_Variable::sync( $product_id );
			wc_delete_product_transients( $product_id );

			return array(
				'success' => true,
				'message' => sprintf( 'مدل «%s» با «%s» جایگزین شد.%s', $old_name, $new_name, $warning ),
			);
		}

		/**
		 * عملیات ۴: همگام‌سازی کامل با یک الگو (افزودن ناموجودها و حذف اضافه‌ها).
		 */
		private static function do_sync_preset( $run_id, WC_Product_Variable $product, $taxonomy, array $params ) {
			$preset_models = self::sanitize_model_list( $params['models'] ?? '' );
			if ( empty( $preset_models ) ) {
				return array( 'success' => false, 'message' => 'لیست مدل‌های الگو خالی است.' );
			}

			// ابتدا مدل‌های جدید را اضافه می‌کنیم
			$add_res = self::do_add_models( $run_id, $product, $taxonomy, $params );

			// سپس مدل‌هایی که در الگو نیستند را ناموجود یا حذف می‌کنیم
			$mode            = $params['delete_mode'] ?? 'soft';
			$current_models  = self::get_product_model_names( $product );
			$obsolete_models = array_diff( $current_models, $preset_models );

			if ( ! empty( $obsolete_models ) ) {
				$rem_params = array(
					'models'      => $obsolete_models,
					'delete_mode' => $mode,
				);
				self::do_remove_models( $product, $taxonomy, $rem_params );
			}

			self::forget_variation_map( $product->get_id() );

			return array(
				'success' => true,
				'message' => sprintf(
					'همگام‌سازی با الگو انجام شد (%d مدل افزوده/موجود، %d مدل خارج از الگو %s).',
					count( $preset_models ),
					count( $obsolete_models ),
					( 'hard' === $mode ) ? 'حذف شد' : 'ناموجود شد'
				),
			);
		}

		/**
		 * عملیات ۵: تغییر قیمت یا موجودی برای یک یا چند مدل خاص.
		 */
		private static function do_bulk_price_stock( WC_Product_Variable $product, $taxonomy, array $params ) {
			$product_id = $product->get_id();
			$models     = self::sanitize_model_list( $params['models'] ?? '' );
			$updated    = 0;

			$new_reg_price  = isset( $params['regular_price'] ) && '' !== $params['regular_price'] ? (float) $params['regular_price'] : null;
			$new_sale_price = isset( $params['sale_price'] ) && '' !== $params['sale_price'] ? (float) $params['sale_price'] : null;
			$stock_status   = ! empty( $params['stock_status'] ) ? sanitize_key( $params['stock_status'] ) : '';
			$warnings       = array();

			foreach ( $models as $model_name ) {
				$term = get_term_by( 'name', $model_name, $taxonomy );
				if ( ! $term ) {
					$term = get_term_by( 'slug', sanitize_title( $model_name ), $taxonomy );
				}
				if ( ! $term ) {
					continue;
				}

				$var_id = self::find_variation_by_term( $product, $taxonomy, $term->slug );
				if ( $var_id ) {
					$variation = wc_get_product( $var_id );
					if ( $variation ) {
						if ( null !== $new_reg_price ) {
							$variation->set_regular_price( $new_reg_price );
						}
						if ( null !== $new_sale_price ) {
							$variation->set_sale_price( $new_sale_price );
						}
						if ( '' !== $stock_status ) {
							$variation->set_stock_status( $stock_status );
						}
						$saved = self::save_variation_safely( $variation );
						if ( ! empty( $saved['id'] ) ) {
							$updated++;
						} else {
							$warnings[] = $model_name . ': ' . $saved['warning'];
						}
					}
				}
			}

			WC_Product_Variable::sync( $product_id );
			wc_delete_product_transients( $product_id );

			$message = sprintf( '%d متغیر به‌روزرسانی شد.', $updated );
			if ( ! empty( $warnings ) ) {
				$message .= ' — هشدارها: ' . implode( ' | ', array_slice( array_unique( $warnings ), 0, 3 ) );
			}

			return array(
				'success' => ( $updated > 0 || empty( $warnings ) ),
				'message' => $message,
			);
		}

		/* -------------------------------------------------------------
		 * متدهای کمکی و داخلی تاکسونومی و ویژگی‌های ووکامرس
		 * ----------------------------------------------------------- */

		public static function sanitize_model_list( $raw ) {
			if ( is_array( $raw ) ) {
				return array_values( array_filter( array_map( 'trim', $raw ) ) );
			}
			$clean = str_replace( array( '،', "\n", "\r", '|' ), ',', (string) $raw );
			$parts = explode( ',', $clean );
			return array_values( array_filter( array_map( 'trim', $parts ) ) );
		}

		public static function find_or_create_attribute_taxonomy( $label = 'مدل گوشی' ) {
			$label = trim( $label );
			$tax_name = wc_attribute_taxonomy_name( sanitize_title( $label ) );

			// اگر این تاکسونومی در ووکامرس وجود ندارد، آن را ثبت می‌کنیم
			if ( ! taxonomy_exists( $tax_name ) ) {
				$tax_id = wc_create_attribute( array(
					'name'         => $label,
					'slug'         => sanitize_title( $label ),
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
			}

			return $tax_name;
		}

		public static function ensure_term_exists( $name, $taxonomy ) {
			$term = get_term_by( 'name', $name, $taxonomy );
			if ( $term ) {
				return $term;
			}
			$term = get_term_by( 'slug', sanitize_title( $name ), $taxonomy );
			if ( $term ) {
				return $term;
			}

			$inserted = wp_insert_term( $name, $taxonomy );
			if ( is_wp_error( $inserted ) ) {
				return null;
			}
			return get_term( $inserted['term_id'], $taxonomy );
		}

		public static function get_product_attribute_terms( $product_id, $taxonomy ) {
			$terms = wp_get_post_terms( $product_id, $taxonomy, array( 'fields' => 'slugs' ) );
			return is_wp_error( $terms ) ? array() : $terms;
		}

		public static function get_product_model_names( WC_Product $product ) {
			$names = array();
			$attributes = $product->get_attributes();
			foreach ( $attributes as $attr ) {
				if ( $attr->get_variation() ) {
					if ( $attr->is_taxonomy() ) {
						$terms = wc_get_product_terms( $product->get_id(), $attr->get_name(), array( 'fields' => 'names' ) );
						$names = array_merge( $names, $terms );
					} else {
						$names = array_merge( $names, (array) $attr->get_options() );
					}
				}
			}
			return array_values( array_unique( $names ) );
		}

		public static function find_variation_by_term( WC_Product_Variable $product, $taxonomy, $term_slug ) {
			$map = self::get_variation_map( $product );
			return isset( $map[ $term_slug ] ) ? $map[ $term_slug ] : null;
		}

		public static function get_reference_variation_prices( WC_Product_Variable $product, $taxonomy, $ref_model_name ) {
			$term = get_term_by( 'name', $ref_model_name, $taxonomy );
			if ( ! $term ) {
				$term = get_term_by( 'slug', sanitize_title( $ref_model_name ), $taxonomy );
			}
			if ( ! $term ) {
				return null;
			}

			$vid = self::find_variation_by_term( $product, $taxonomy, $term->slug );
			if ( ! $vid ) {
				return null;
			}

			$var = wc_get_product( $vid );
			if ( ! $var ) {
				return null;
			}

			return array(
				'regular_price' => $var->get_regular_price(),
				'sale_price'    => $var->get_sale_price(),
			);
		}

		public static function sync_product_attribute( WC_Product_Variable $product, $taxonomy, array $term_slugs ) {
			$product_id = $product->get_id();
			wp_set_object_terms( $product_id, $term_slugs, $taxonomy, false );

			$attributes = $product->get_attributes();
			$attr_obj   = new WC_Product_Attribute();
			$attr_obj->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
			$attr_obj->set_name( $taxonomy );
			$attr_obj->set_options( $term_slugs );
			$attr_obj->set_position( 0 );
			$attr_obj->set_visible( true );
			$attr_obj->set_variation( true );

			$attributes[ $taxonomy ] = $attr_obj;
			$product->set_attributes( $attributes );
			$product->save();
		}
	}
}
