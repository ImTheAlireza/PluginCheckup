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
						if ( self::is_sync_rebuild() ) {
							// بازسازی کامل: همهٔ متغیرهای فعلی حذف و فهرست جدید ساخته می‌شود.
							$item_preview['to_remove'] = $current_models;
							$item_preview['to_add']    = $preset_models;
							$item_preview['notes'][]   = sprintf(
								'بازسازی کامل ویژگی: %d متغیر فعلی حذف و %d متغیر از فهرست الگو ساخته می‌شود.',
								count( $current_models ),
								count( $preset_models )
							);
						} else {
							$to_add = array_diff( $preset_models, $current_models );
							$to_rem = array_diff( $current_models, $preset_models );
							$item_preview['to_add']    = array_values( $to_add );
							$item_preview['to_remove'] = array_values( $to_rem );
						}
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

			$map   = array();
			$pairs = array(); // [ مقدار ویژگی، نام تاکسونومی ]
			$taxes = array();

			foreach ( $product->get_children() as $vid ) {
				$var = wc_get_product( $vid );
				if ( ! $var ) {
					continue;
				}
				foreach ( (array) $var->get_attributes() as $key => $value ) {
					if ( ! is_string( $value ) || '' === $value ) {
						continue;
					}

					$map[ $value ]                    = $vid; // مقدار ذخیره‌شده (معمولاً اسلاگ مدل).
					$map[ self::model_key( $value ) ] = $vid;

					$tax = ( 0 === strpos( (string) $key, 'pa_' ) ) ? (string) $key : '';
					if ( '' !== $tax ) {
						$pairs[]        = array( $value, $tax );
						$taxes[ $tax ]  = $tax;
					}
				}
			}

			// یک واکشی ترم به‌ازای هر تاکسونومی (نه به‌ازای هر مقدار) و افزودن کلیدهای
			// نام/اسلاگ، تا مدل‌های موجود با املای متفاوت دوباره ساخته نشوند.
			$lookup = array();
			foreach ( $taxes as $tax ) {
				$terms = get_terms(
					array(
						'taxonomy'   => $tax,
						'hide_empty' => false,
					)
				);
				if ( is_wp_error( $terms ) ) {
					continue;
				}
				foreach ( $terms as $term ) {
					$lookup[ $tax ][ $term->slug ]                     = $term;
					$lookup[ $tax ][ 'n:' . self::model_key( $term->name ) ] = $term;
					$lookup[ $tax ][ 'n:' . self::model_key( $term->slug ) ] = $term;
				}
			}

			foreach ( $pairs as $pair ) {
				list( $value, $tax ) = $pair;
				if ( empty( $lookup[ $tax ] ) ) {
					continue;
				}

				$vid = isset( $map[ $value ] ) ? $map[ $value ] : ( isset( $map[ self::model_key( $value ) ] ) ? $map[ self::model_key( $value ) ] : 0 );
				if ( ! $vid ) {
					continue;
				}

				$term = isset( $lookup[ $tax ][ $value ] ) ? $lookup[ $tax ][ $value ] : null;
				if ( ! $term && isset( $lookup[ $tax ][ 'n:' . self::model_key( $value ) ] ) ) {
					$term = $lookup[ $tax ][ 'n:' . self::model_key( $value ) ];
				}

				if ( $term ) {
					$map[ $term->name ]                    = $vid;
					$map[ self::model_key( $term->name ) ] = $vid;
					$map[ $term->slug ]                    = $vid;
				}
			}

			self::$variation_map[ $pid ] = $map;
			return $map;
		}

		/**
		 * تشخیص تاکسونومیِ یک مقدار ویژگی روی متغیر (برای تطبیق نام/اسلاگ مدل).
		 *
		 * @param WC_Product $variation متغیر.
		 * @param string     $value     مقدار ویژگی.
		 * @return string نام تاکسونومی یا نام ویژگی سفارشی.
		 */
		private static function attribute_taxonomy_of( $variation, $value ) {
			foreach ( (array) $variation->get_attributes() as $key => $val ) {
				if ( (string) $val === (string) $value && 0 === strpos( (string) $key, 'pa_' ) ) {
					return (string) $key;
				}
			}
			return 'pa_' . sanitize_title( $value );
		}

		/**
		 * کلید تطبیق نام مدل (نرمال‌سازی فارسی + کوچک‌کردن حروف).
		 *
		 * @param string $text متن.
		 * @return string
		 */
		public static function model_key( $text ) {
			$text = (string) $text;
			$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
			if ( class_exists( 'TCBVM_DB' ) ) {
				$text = TCBVM_DB::normalize_persian( $text );
			}
			return trim( $text );
		}

		/**
		 * یافتن متغیرِ موجودِ یک مدل (تطبیق مقاوم: نام، اسلاگ، نام نرمال‌شده).
		 *
		 * @param WC_Product_Variable $product    محصول.
		 * @param string              $taxonomy   تاکسونومی ویژگی.
		 * @param string              $model_name نام مدل درخواستی.
		 * @param WP_Term|null        $term       ترم متناظر (اگر پیدا شده).
		 * @return int شناسهٔ متغیر یا ۰.
		 */
		private static function existing_variation_for_model( WC_Product_Variable $product, $taxonomy, $model_name, $term ) {
			$map = self::get_variation_map( $product );
			if ( empty( $map ) ) {
				return 0;
			}

			$candidates = array( $model_name );
			if ( $term && ! is_wp_error( $term ) ) {
				$candidates[] = $term->slug;
				$candidates[] = $term->name;
				$candidates[] = urldecode( $term->slug );
			}
			$candidates[] = sanitize_title( $model_name );

			foreach ( $candidates as $candidate ) {
				$candidate = (string) $candidate;
				if ( '' === $candidate ) {
					continue;
				}
				if ( isset( $map[ $candidate ] ) ) {
					return (int) $map[ $candidate ];
				}
				$key = self::model_key( $candidate );
				if ( isset( $map[ $key ] ) ) {
					return (int) $map[ $key ];
				}
			}

			return 0;
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

			// محصول ساده: در حالت «فقط به‌روزرسانی» هیچ تغییری در نوع محصول نمی‌دهیم
			// (تبدیل نوع، ساختار محصول را عوض می‌کند و خارج از خواستهٔ کاربر است).
			if ( ! $product->is_type( 'variable' ) ) {
				if ( self::is_update_only() ) {
					return array(
						'success' => false,
						'message' => sprintf( 'این محصول «%s» است نه متغیر؛ در حالت «فقط به‌روزرسانی» نوع محصول تغییر نمی‌کند.', $product->get_type() ),
					);
				}
				wp_set_object_terms( $product_id, 'variable', 'product_type' );
				clean_post_cache( $product_id );
				$product = wc_get_product( $product_id );
			}

			$target_attr_name = ! empty( $params['attr_name'] ) ? sanitize_text_field( $params['attr_name'] ) : 'مدل گوشی';
			$models_hint      = isset( $params['models'] ) ? self::sanitize_model_list( $params['models'] ) : array();

			// بازسازی کامل، ساخت ویژگی/ترم را لازم دارد؛ دیگر عملیات‌ها در حالت
			// «فقط به‌روزرسانی» فقط با رکوردهای موجود کار می‌کنند.
			$allow_create = ( 'sync_preset' === $operation && self::is_sync_rebuild() ) || ! self::is_update_only();
			$taxonomy     = self::resolve_model_taxonomy( $product, $target_attr_name, $models_hint, $allow_create );

			if ( '' === $taxonomy ) {
				return array(
					'success' => false,
					'message' => sprintf( 'ویژگی «%s» روی این محصول پیدا نشد.', $target_attr_name ),
				);
			}

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
		 * ذخیرهٔ ایمن یک متغیر.
		 *
		 * این افزونه هیچ‌وقت SKU نمی‌سازد و SKU متغیرها را تغییر نمی‌دهد (SKUها متعلق به
		 * خود فروشگاه و ابزارهای آن است). اگر ذخیره به هر دلیلی شکست خورد، فقط همان مدل
		 * ناموفق می‌شود و علت واقعی به‌صورت هشدار برگردانده می‌شود.
		 *
		 * @param WC_Product_Variation $variation متغیر.
		 * @return array{id:int, warning:string}
		 */
		private static function save_variation_safely( $variation ) {
			try {
				$var_id = $variation->save();
				return array( 'id' => (int) $var_id, 'warning' => '' );
			} catch ( \Exception $e ) {
				return array( 'id' => 0, 'warning' => $e->getMessage() );
			}
		}

		/**
		 * آیا حالت «فقط به‌روزرسانی» فعال است؟ (پیش‌فرض: فعال)
		 *
		 * در این حالت هیچ محصول/متغیر/مدل/ویژگیِ جدیدی ساخته نمی‌شود و فقط رکوردهای
		 * موجود به‌روزرسانی می‌شوند؛ مدل‌های ناموجود فقط گزارش می‌شوند.
		 */
		public static function is_update_only() {
			$settings = TCBVM_Core::get_settings();
			return ! empty( $settings['update_only'] );
		}

		/**
		 * آیا در عملیات «همگام‌سازی کامل» ویژگی از صفر بازسازی شود؟ (پیش‌فرض: بله)
		 *
		 * بازسازی یعنی: همهٔ متغیرهای آن ویژگی حذف، ویژگی از والد برداشته و سپس با همان
		 * نام و فهرست جدید مدل‌ها از نو ساخته می‌شود.
		 */
		public static function is_sync_rebuild() {
			$settings = TCBVM_Core::get_settings();
			return ! empty( $settings['sync_rebuild'] );
		}

		/**
		 * آیا در بازسازی کامل، ترکیب سایر ویژگی‌های متغیر هم ساخته شود؟ (پیش‌فرض: خیر)
		 *
		 * پیش‌فرض خاموش یعنی «یک متغیر به‌ازای هر مدل» — همان چیزی که فروشگاه می‌خواهد:
		 * تعداد متغیرها دقیقاً برابر تعداد مدل‌های فهرست است.
		 */
		public static function is_sync_keep_other_attrs() {
			$settings = TCBVM_Core::get_settings();
			return ! empty( $settings['sync_keep_other_attrs'] );
		}

		/**
		 * پیدا کردن ویژگی/تاکسونومیِ درست برای «مدل» روی یک محصول.
		 *
		 * ترتیب تشخیص:
		 *   ۱) نام دقیق وارد‌شده (مثل «مدل») اگر همان تاکسونومی در ووکامرس موجود باشد.
		 *   ۲) تطبیق برچسب/نام ویژگی‌های ثبت‌شدهٔ ووکامرس با نام وارد‌شده (نرمال‌شده).
		 *   ۳) بین ویژگی‌های متغیر خود محصول، آن‌که مدل‌هایش با فهرست ما هم‌پوشانی دارد.
		 *   ۴) اگر محصول فقط یک ویژگی متغیر دارد، همان.
		 *   ۵) ساخت ویژگی جدید (فقط اگر مجاز باشد).
		 *
		 * @param WC_Product $product      محصول.
		 * @param string     $label        نام ویژگی وارد‌شده در فرم.
		 * @param array      $models_hint  فهرست مدل‌های ورودی (برای امتیازدهی).
		 * @param bool       $allow_create اجازهٔ ساخت ویژگی جدید.
		 * @return string نام تاکسونومی (ممکن است موجود نباشد).
		 */
		public static function resolve_model_taxonomy( $product, $label, array $models_hint = array(), $allow_create = true ) {
			$label = trim( (string) $label );
			$guess = '' !== $label ? wc_attribute_taxonomy_name( sanitize_title( $label ) ) : '';

			if ( '' !== $guess && taxonomy_exists( $guess ) ) {
				return $guess;
			}

			$target = self::model_key( $label );

			// ۲) تطبیق برچسب ویژگی‌های ثبت‌شدهٔ ووکامرس
			if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
				foreach ( (array) wc_get_attribute_taxonomies() as $attribute ) {
					if ( empty( $attribute->attribute_name ) ) {
						continue;
					}
					$tax = wc_attribute_taxonomy_name( $attribute->attribute_name );
					if ( ! taxonomy_exists( $tax ) ) {
						continue;
					}
					$labels = array(
						(string) $attribute->attribute_name,
						isset( $attribute->attribute_label ) ? (string) $attribute->attribute_label : '',
						urldecode( (string) $attribute->attribute_name ),
					);
					foreach ( $labels as $candidate ) {
						if ( '' !== $candidate && self::model_key( $candidate ) === $target ) {
							return $tax;
						}
					}
				}
			}

			// ۳ و ۴) ویژگی‌های خود محصول
			$product_attrs = array();
			if ( $product instanceof WC_Product && $product->get_id() ) {
				foreach ( (array) $product->get_attributes() as $attr ) {
					if ( $attr->get_variation() && taxonomy_exists( $attr->get_name() ) ) {
						$product_attrs[] = $attr->get_name();
					}
				}
			}

			if ( ! empty( $product_attrs ) && ! empty( $models_hint ) ) {
				$wanted = array();
				foreach ( $models_hint as $model ) {
					$wanted[ self::model_key( $model ) ] = true;
				}

				$best_tax   = '';
				$best_score = 0;
				foreach ( $product_attrs as $tax ) {
					$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'fields' => 'names' ) );
					if ( is_wp_error( $terms ) ) {
						continue;
					}
					$score = 0;
					foreach ( $terms as $term_name ) {
						if ( isset( $wanted[ self::model_key( $term_name ) ] ) ) {
							$score++;
						}
					}
					if ( $score > $best_score ) {
						$best_score = $score;
						$best_tax   = $tax;
					}
				}

				if ( $best_tax && $best_score > 0 ) {
					return $best_tax;
				}
			}

			if ( 1 === count( $product_attrs ) ) {
				return $product_attrs[0];
			}

			// ۵) ساخت ویژگی جدید (در حالت فقط‌به‌روزرسانی ساخته نمی‌شود)
			if ( ! $allow_create ) {
				return '' !== $guess ? $guess : '';
			}

			return self::find_or_create_attribute_taxonomy( '' !== $label ? $label : 'مدل گوشی', $allow_create );
		}

		/**
		 * عملیات ۱: افزودن مدل‌های جدید به محصول.
		 */
		private static function do_add_models( $run_id, WC_Product_Variable $product, $taxonomy, array $params ) {
			$product_id    = $product->get_id();
			$models_to_add = self::sanitize_model_list( $params['models'] ?? '' );
			if ( empty( $models_to_add ) ) {
				return array( 'success' => false, 'message' => 'هیچ مدلی وارد نشده است.' );
			}

			$update_only     = self::is_update_only();
			$existing_terms  = self::get_product_attribute_terms( $product_id, $taxonomy );
			$terms_to_assign = $existing_terms;
			$created_count   = 0;
			$updated_count   = 0;
			$skipped         = array();
			$warnings        = array();

			// قیمت/موجودی موردنظر (برای مدل‌های موجود هم اعمال می‌شود).
			$clone_price_ref = ! empty( $params['clone_from_model'] ) ? trim( $params['clone_from_model'] ) : '';
			$ref_prices      = $clone_price_ref ? self::get_reference_variation_prices( $product, $taxonomy, $clone_price_ref ) : null;

			$default_reg_price  = isset( $params['regular_price'] ) && '' !== $params['regular_price'] ? (float) $params['regular_price'] : '';
			$default_sale_price = isset( $params['sale_price'] ) && '' !== $params['sale_price'] ? (float) $params['sale_price'] : '';
			$stock_status       = ! empty( $params['stock_status'] ) ? sanitize_key( $params['stock_status'] ) : '';

			foreach ( $models_to_add as $model_name ) {
				// فقط «یافتن» ترم موجود؛ ساخت ترم جدید تنها وقتی حالت فقط‌به‌روزرسانی خاموش باشد.
				$term_obj = self::ensure_term_exists( $model_name, $taxonomy, ! $update_only );

				// ۱) آیا متغیری برای این مدل از قبل وجود دارد؟ (تطبیق مقاوم: اسلاگ، نام و نام نرمال‌شده)
				$existing_var_id = self::existing_variation_for_model( $product, $taxonomy, $model_name, $term_obj );

				if ( $existing_var_id ) {
					// به‌جای ساخت دوباره، همان متغیر قبلی به‌روزرسانی می‌شود.
					$variation = wc_get_product( $existing_var_id );
					if ( $variation ) {
						$changed = false;

						// روی متغیر موجود، قیمت حراج فقط وقتی نوشته می‌شود که مقداری داده شده باشد
						// (قیمت حراج فعلی فروشگاه پاک نمی‌شود).
						if ( $ref_prices && '' !== $ref_prices['regular_price'] ) {
							$variation->set_regular_price( $ref_prices['regular_price'] );
							if ( '' !== (string) $ref_prices['sale_price'] ) {
								$variation->set_sale_price( $ref_prices['sale_price'] );
							}
							$changed = true;
						} else {
							if ( '' !== $default_reg_price ) {
								$variation->set_regular_price( $default_reg_price );
								$changed = true;
							}
							if ( '' !== $default_sale_price ) {
								$variation->set_sale_price( $default_sale_price );
								$changed = true;
							}
						}
						if ( '' !== $stock_status ) {
							$variation->set_stock_status( $stock_status );
							$changed = true;
						}

						if ( $changed ) {
							$saved = self::save_variation_safely( $variation );
							if ( ! empty( $saved['id'] ) ) {
								$updated_count++;
							} else {
								$warnings[] = $model_name . ': ' . $saved['warning'];
							}
						}
					}

					if ( $term_obj && ! in_array( $term_obj->slug, $terms_to_assign, true ) ) {
						$terms_to_assign[] = $term_obj->slug;
					}
					continue;
				}

				// ۲) مدل موجود نیست
				if ( ! $term_obj ) {
					$skipped[] = $model_name . ' (مدل در فروشگاه ساخته نشده)';
					continue;
				}

				if ( $update_only ) {
					$skipped[] = $model_name . ' (متغیر جدید ساخته نشد — حالت فقط‌به‌روزرسانی)';
					continue;
				}

				// ۳) ساخت متغیر جدید برای مدلی که واقعاً وجود ندارد
				if ( ! in_array( $term_obj->slug, $terms_to_assign, true ) ) {
					$terms_to_assign[] = $term_obj->slug;
				}

				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $product_id );
				$variation->set_status( 'publish' );
				$variation->set_attributes( array( $taxonomy => $term_obj->slug ) );

				if ( '' !== $stock_status ) {
					$variation->set_stock_status( $stock_status );
				}

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

				// هیچ SKUای ساخته یا تغییر داده نمی‌شود؛ SKUها متعلق به فروشگاه است.

				$saved = self::save_variation_safely( $variation );
				if ( ! empty( $saved['id'] ) ) {
					TCBVM_Backup::track_created_variation( $run_id, $product_id, $saved['id'] );
					self::$variation_map[ $product_id ][ $term_obj->slug ] = $saved['id'];
					$created_count++;
				} else {
					$warnings[] = $model_name . ': ساخت متغیر ناموفق بود — ' . $saved['warning'];
				}
			}

			// اتصال ویژگی به محصول والد (و سینک) فقط اگر ویژگی‌های محصول واقعاً تغییر کرده باشد.
			$terms_changed = ( array_values( array_unique( $terms_to_assign ) ) !== array_values( array_unique( $existing_terms ) ) );
			if ( $terms_changed ) {
				self::sync_product_attribute( $product, $taxonomy, array_values( array_unique( $terms_to_assign ) ) );
			}

			WC_Product_Variable::sync( $product_id );
			wc_delete_product_transients( $product_id );
			self::forget_variation_map( $product_id );

			// پیام گویا: چه چیزی به‌روزرسانی شد، چه چیزی ساخته شد، چه چیزی رد شد.
			$parts = array();
			if ( $updated_count ) {
				$parts[] = sprintf( '%d متغیر موجود به‌روزرسانی شد', $updated_count );
			}
			if ( $created_count ) {
				$parts[] = sprintf( '%d متغیر جدید ساخته شد', $created_count );
			}
			if ( empty( $parts ) ) {
				$parts[] = 'تغییری لازم نبود';
			}
			if ( ! empty( $skipped ) ) {
				$parts[] = sprintf( '%d مدل رد شد: %s', count( $skipped ), implode( '، ', array_slice( $skipped, 0, 3 ) ) );
			}
			if ( ! empty( $warnings ) ) {
				$parts[] = 'هشدار: ' . implode( ' | ', array_slice( array_unique( $warnings ), 0, 2 ) );
			}

			return array(
				// «رد شدن مدل» خطا نیست؛ فقط هشدارهای واقعی شکست محسوب می‌شوند.
				'success' => empty( $warnings ),
				'message' => implode( ' — ', $parts ),
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

			$new_term = self::ensure_term_exists( $new_name, $taxonomy, ! self::is_update_only() );
			if ( ! $new_term ) {
				return array(
					'success' => false,
					'message' => self::is_update_only()
						? sprintf( 'مدل «%s» در فروشگاه وجود ندارد و در حالت «فقط به‌روزرسانی» ساخته نمی‌شود.', $new_name )
						: 'امکان ساخت مدل جدید وجود ندارد.',
				);
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
			$product_id = $product->get_id();
			$models     = self::sanitize_model_list( $params['models'] ?? '' );

			if ( empty( $models ) ) {
				return array( 'success' => false, 'message' => 'لیست مدل‌های الگو خالی است.' );
			}

			if ( '' === $taxonomy ) {
				return array( 'success' => false, 'message' => 'ویژگی مدل روی این محصول پیدا نشد.' );
			}

			// حالت قدیمی (ادغام) — فقط اگر بازسازی کامل در تنظیمات خاموش باشد.
			if ( ! self::is_sync_rebuild() ) {
				self::do_add_models( $run_id, $product, $taxonomy, $params );

				$current_models  = self::get_product_model_names( $product );
				$obsolete_models = array_diff( $current_models, $models );
				if ( ! empty( $obsolete_models ) ) {
					self::do_remove_models(
						$product,
						$taxonomy,
						array(
							'models'      => $obsolete_models,
							'delete_mode' => $params['delete_mode'] ?? 'soft',
						)
					);
				}
				self::forget_variation_map( $product_id );

				return array(
					'success' => true,
					'message' => sprintf( 'همگام‌سازی (ادغام) انجام شد: %d مدل در الگو.', count( $models ) ),
				);
			}

			// ==================== بازسازی کامل ====================
			$keep_other = self::is_sync_keep_other_attrs(); // پیش‌فرض: خیر (یک متغیر به‌ازای هر مدل)

			$attributes = $product->get_attributes();
			$prev_attr  = isset( $attributes[ $taxonomy ] ) ? $attributes[ $taxonomy ] : null;
			$deleted    = count( $product->get_children() );

			// سایر ویژگی‌های «متغیر» محصول را پیدا می‌کنیم.
			$other_taxes = array();
			foreach ( $attributes as $attr_name => $attr_obj ) {
				if ( $attr_name === $taxonomy || ! $attr_obj->get_variation() ) {
					continue;
				}
				$other_taxes[] = $attr_name;
			}

			// ترکیب سایر ویژگی‌ها فقط در صورت درخواست صریح.
			$other_maps = array();
			if ( $keep_other ) {
				foreach ( $product->get_children() as $vid ) {
					$variation = wc_get_product( $vid );
					if ( ! $variation ) {
						continue;
					}
					$other = (array) $variation->get_attributes();
					unset( $other[ $taxonomy ] );
					if ( ! empty( $other ) ) {
						$other_maps[ wp_json_encode( $other ) ] = $other;
					}
				}
			}
			if ( empty( $other_maps ) ) {
				$other_maps = array( 'single' => array() );
			}

			// ---------- محافظ ایمنی: پیش از هر تغییری ----------
			$total_combos = count( $models ) * count( $other_maps );
			if ( $total_combos > 3000 ) {
				return array(
					'success' => false,
					'message' => sprintf(
						'ایمنی: این عملیات %d متغیر می‌ساخت (%d مدل × %d ترکیب سایر ویژگی‌ها) و هیچ تغییری اعمال نشد. برای ساخت «یک متغیر به‌ازای هر مدل» گزینهٔ «حفظ سایر ویژگی‌های متغیر» را در تنظیمات خاموش کن.',
						$total_combos,
						count( $models ),
						count( $other_maps )
					),
				);
			}
			if ( count( $models ) > 2000 ) {
				return array(
					'success' => false,
					'message' => sprintf( 'ایمنی: %d مدل بیش از حد مجاز (۲۰۰۰) است و هیچ تغییری اعمال نشد.', count( $models ) ),
				);
			}

			// قیمت/موجودی مرجع را پیش از حذف متغیرها استخراج می‌کنیم.
			$clone_ref  = ! empty( $params['clone_from_model'] ) ? trim( $params['clone_from_model'] ) : '';
			$ref_prices = $clone_ref ? self::get_reference_variation_prices( $product, $taxonomy, $clone_ref ) : null;

			$default_reg_price  = isset( $params['regular_price'] ) && '' !== $params['regular_price'] ? (float) $params['regular_price'] : '';
			$default_sale_price = isset( $params['sale_price'] ) && '' !== $params['sale_price'] ? (float) $params['sale_price'] : '';
			$stock_status       = ! empty( $params['stock_status'] ) ? sanitize_key( $params['stock_status'] ) : '';

			// ---------- ۱) حذف کامل متغیرهای فعلی ----------
			foreach ( $product->get_children() as $vid ) {
				$variation = wc_get_product( $vid );
				if ( $variation && $variation->is_type( 'variation' ) ) {
					$variation->delete( true );
				}
			}
			self::forget_variation_map( $product_id );

			// ---------- ۲) برداشتن ویژگی‌ها از والد ----------
			$attributes = $product->get_attributes();
			unset( $attributes[ $taxonomy ] );
			wp_set_object_terms( $product_id, array(), $taxonomy, false );

			// در حالت «یک متغیر به‌ازای هر مدل»، سایر ویژگی‌های متغیر هم از محصول برداشته
			// می‌شوند تا ووکامرس منتظر مقدار آن‌ها نماند. ترم‌ها و داده‌هایشان حذف نمی‌شود
			// و بازگردانی هم آن‌ها را برمی‌گرداند.
			$removed_other = 0;
			if ( ! $keep_other ) {
				foreach ( $other_taxes as $other_tax ) {
					unset( $attributes[ $other_tax ] );
					wp_set_object_terms( $product_id, array(), $other_tax, false );
					$removed_other++;
				}
			}

			$product->set_attributes( $attributes );
			$product->save();
			clean_post_cache( $product_id );

			// ---------- ۳) ساخت ترم‌های مدل‌ها از صفر ----------
			$slugs = array();
			foreach ( $models as $model_name ) {
				$term = self::ensure_term_exists( $model_name, $taxonomy, true );
				if ( $term && ! is_wp_error( $term ) ) {
					$slugs[ $term->slug ] = $model_name;
				}
			}

			if ( empty( $slugs ) ) {
				return array(
					'success' => false,
					'message' => 'هیچ مدل معتبری برای ساخت پیدا نشد (نام ویژگی در ووکامرس درست است؟).',
				);
			}

			// ---------- ۴) ثبت مجدد ویژگی روی والد با همان نام ----------
			$product = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product_Variable ) {
				return array( 'success' => false, 'message' => 'بارگذاری مجدد محصول ممکن نشد.' );
			}
			self::sync_product_attribute( $product, $taxonomy, array_keys( $slugs ), $prev_attr );

			$product = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product_Variable ) {
				return array( 'success' => false, 'message' => 'بارگذاری مجدد محصول ممکن نشد.' );
			}

			// ---------- ۵) ساخت متغیرها (بدون هیچ دست‌کاری SKU) ----------
			$created  = 0;
			$warnings = array();

			foreach ( $slugs as $slug => $model_name ) {
				foreach ( $other_maps as $other ) {
					$variation = new WC_Product_Variation();
					$variation->set_parent_id( $product_id );
					$variation->set_status( 'publish' );
					$variation->set_attributes( array_merge( $other, array( $taxonomy => $slug ) ) );

					if ( '' !== $stock_status ) {
						$variation->set_stock_status( $stock_status );
					}

					if ( $ref_prices && '' !== (string) $ref_prices['regular_price'] ) {
						$variation->set_regular_price( $ref_prices['regular_price'] );
						if ( '' !== (string) $ref_prices['sale_price'] ) {
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

					$saved = self::save_variation_safely( $variation );
					if ( ! empty( $saved['id'] ) ) {
						TCBVM_Backup::track_created_variation( $run_id, $product_id, $saved['id'] );
						$created++;
					} else {
						$warnings[] = $model_name . ': ' . $saved['warning'];
					}
				}
			}

			WC_Product_Variable::sync( $product_id );
			wc_delete_product_transients( $product_id );
			self::forget_variation_map( $product_id );

			$message = sprintf(
				'بازسازی کامل ویژگی «%s»: %d متغیر قدیمی حذف و %d متغیر جدید از %d مدل ساخته شد.',
				self::attribute_label( $taxonomy ),
				$deleted,
				$created,
				count( $slugs )
			);
			if ( $removed_other ) {
				$message .= sprintf( ' (%d ویژگی متغیر دیگر از محصول برداشته شد تا دقیقاً یک متغیر به‌ازای هر مدل بماند)', $removed_other );
			}
			if ( ! empty( $warnings ) ) {
				$message .= ' — هشدار: ' . implode( ' | ', array_slice( array_unique( $warnings ), 0, 2 ) );
			}

			return array(
				'success' => empty( $warnings ),
				'message' => $message,
			);
		}

		/**
		 * برچسب خوانا برای یک تاکسونومی ویژگی (برای پیام‌های گزارش).
		 *
		 * @param string $taxonomy نام تاکسونومی.
		 * @return string
		 */
		public static function attribute_label( $taxonomy ) {
			$name = str_replace( 'pa_', '', (string) $taxonomy );
			if ( function_exists( 'wc_attribute_taxonomy_id_by_name' ) ) {
				$id = wc_attribute_taxonomy_id_by_name( $name );
				if ( $id && function_exists( 'wc_get_attribute' ) ) {
					$attr = wc_get_attribute( $id );
					if ( $attr && ! empty( $attr->name ) ) {
						return $attr->name;
					}
				}
			}
			return urldecode( $name );
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

		/**
		 * تبدیل متن ورودی به فهرست مدل‌ها.
		 *
		 * قاعدهٔ مهم فروشگاه تیساکیس: یک نام مدل می‌تواند چند گوشی را پوشش دهد و داخل
		 * خودش کاما دارد — مثل «iPhone 7,8,SE» یا «Redmi Note 9s,9 Pro». پس کاما هرگز
		 * جداکننده نیست. جداکننده‌های واقعی:
		 *   ۱) کاراکتر «|»
		 *   ۲) خط جدید (هر خط یک مدل)
		 *   ۳) ویرگول فارسی «،» (فقط وقتی هیچ‌کدام از دو مورد بالا نباشد)
		 * و به‌عنوان آخرین راه‌حل (سازگاری با فهرست‌های قدیمی کاماجدا) کامای انگلیسی.
		 *
		 * @param string|array $raw متن یا آرایهٔ مدل‌ها.
		 * @return array فهرست یکتای مدل‌ها با ترتیب ورودی.
		 */
		public static function sanitize_model_list( $raw ) {
			if ( is_array( $raw ) ) {
				return self::unique_models( array_map( 'strval', $raw ) );
			}

			$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $raw );
			$text = trim( $text );
			if ( '' === $text ) {
				return array();
			}

			// جداکننده‌های اصلی: «|» و خط جدید (هر دو با هم، چون کاربر ممکن است ابتدا
			// یک الگو را با خط جدید درج کند و بعد فهرست «|»دار را بچسباند).
			$parts = preg_split( '/[|\n]+/u', $text );

			// اگر هیچ «|» یا خط جدیدی نبود، فهرست‌های کاماجدای قدیمی را هم بپذیر،
			// ولی فقط وقتی کاما با فاصله آمده باشد ("A, B") تا «iPhone 7,8,SE» نشکند.
			if ( count( $parts ) < 2 ) {
				if ( preg_match( '/[,،]\s+/u', $text ) ) {
					$parts = preg_split( '/\s*[,،]\s*/u', $text );
				} elseif ( false !== strpos( $text, '،' ) ) {
					$parts = preg_split( '/\s*،\s*/u', $text );
				} else {
					$parts = array( $text );
				}
			}

			return self::unique_models( is_array( $parts ) ? $parts : array() );
		}

		/**
		 * حذف موارد خالی و تکراری (تطبیق بر اساس نام نرمال‌شده، حفظ ترتیب ورودی).
		 *
		 * @param array $models فهرست خام.
		 * @return array
		 */
		private static function unique_models( array $models ) {
			$clean = array();
			$seen  = array();

			foreach ( $models as $model ) {
				$model = trim( (string) $model );
				if ( '' === $model ) {
					continue;
				}
				$key = self::model_key( $model );
				if ( '' === $key || isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$clean[]      = $model;
			}

			return $clean;
		}

		public static function find_or_create_attribute_taxonomy( $label = 'مدل گوشی', $allow_create = null ) {
			$label    = trim( $label );
			$tax_name = wc_attribute_taxonomy_name( sanitize_title( $label ) );

			if ( null === $allow_create ) {
				$allow_create = ! self::is_update_only();
			}

			if ( taxonomy_exists( $tax_name ) ) {
				return $tax_name;
			}

			// شاید ویژگی با همان معنی ولی با برچسب/نام دیگری ساخته شده باشد
			// (مثلاً «مدل» یا «Model»). اول همان را پیدا می‌کنیم تا ویژگی تکراری ساخته نشود.
			if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
				$target = self::model_key( $label );
				foreach ( (array) wc_get_attribute_taxonomies() as $attribute ) {
					if ( empty( $attribute->attribute_name ) ) {
						continue;
					}
					$candidates = array(
						(string) $attribute->attribute_name,
						isset( $attribute->attribute_label ) ? (string) $attribute->attribute_label : '',
					);
					foreach ( $candidates as $candidate ) {
						if ( '' !== $candidate && self::model_key( $candidate ) === $target ) {
							$found = wc_attribute_taxonomy_name( $attribute->attribute_name );
							if ( taxonomy_exists( $found ) ) {
								return $found;
							}
						}
					}
				}
			}

			// ساخت ویژگی جدید فقط با اجازهٔ صریح (در حالت «فقط به‌روزرسانی» و بدون
			// بازسازی کامل، هیچ ویژگی جدیدی ساخته نمی‌شود).
			if ( ! $allow_create ) {
				return $tax_name;
			}

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

		public static function ensure_term_exists( $name, $taxonomy, $allow_create = true ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return null; // تاکسونومی وجود ندارد؛ چیزی ساخته نمی‌شود.
			}

			$name = trim( (string) $name );
			if ( '' === $name ) {
				return null;
			}

			$term = get_term_by( 'name', $name, $taxonomy );
			if ( $term ) {
				return $term;
			}
			$term = get_term_by( 'slug', sanitize_title( $name ), $taxonomy );
			if ( $term ) {
				return $term;
			}

			// تطبیق نرمال‌شده (ی/ک عربی، نیم‌فاصله، بزرگی/کوچکی حروف) تا مدل‌های موجود
			// با املای متفاوت دوباره ساخته نشوند.
			$target  = self::model_key( $name );
			$matches = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 50,
				)
			);
			if ( ! is_wp_error( $matches ) ) {
				foreach ( $matches as $candidate ) {
					if ( self::model_key( $candidate->name ) === $target || self::model_key( $candidate->slug ) === $target ) {
						return $candidate;
					}
				}
			}

			if ( ! $allow_create ) {
				return null;
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

		public static function sync_product_attribute( WC_Product_Variable $product, $taxonomy, array $term_slugs, $prev_attr = null ) {
			$product_id = $product->get_id();
			wp_set_object_terms( $product_id, $term_slugs, $taxonomy, false );

			$attributes = $product->get_attributes();
			$attr_obj   = new WC_Product_Attribute();
			$attr_obj->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
			$attr_obj->set_name( $taxonomy );
			$attr_obj->set_options( $term_slugs );

			// تنظیمات نمایش ویژگی قبلی (اگر وجود داشته) حفظ می‌شود.
			if ( $prev_attr instanceof WC_Product_Attribute ) {
				$attr_obj->set_position( $prev_attr->get_position() );
				$attr_obj->set_visible( $prev_attr->is_visible() );
			} else {
				$attr_obj->set_position( 0 );
				$attr_obj->set_visible( true );
			}
			$attr_obj->set_variation( true );

			$attributes[ $taxonomy ] = $attr_obj;
			$product->set_attributes( $attributes );
			$product->save();
		}
	}
}
