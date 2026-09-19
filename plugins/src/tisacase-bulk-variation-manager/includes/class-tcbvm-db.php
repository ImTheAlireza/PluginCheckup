<?php
/**
 * لایهٔ داده‌ها: جستجو، فیلتر چندلایه، نرمال‌سازی متن فارسی و استخراج ویژگی‌ها و متغیرها.
 *
 * @package TisaCase_Bulk_Variation_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCBVM_DB' ) ) {

	final class TCBVM_DB {

		/**
		 * نرمال‌سازی کاراکترهای فارسی و عربی (ی، ک، نیم‌فاصله).
		 */
		public static function normalize_persian( $str ) {
			if ( ! is_string( $str ) || '' === $str ) {
				return '';
			}
			$arabic_chars  = array( 'ي', 'ك', 'ة', 'ۀ', "\xC2\xA0", "\xE2\x80\x8C" );
			$persian_chars = array( 'ی', 'ک', 'ه', 'ه', ' ', ' ' );
			$clean         = str_replace( $arabic_chars, $persian_chars, $str );
			return trim( preg_replace( '/\s+/', ' ', $clean ) );
		}

		/**
		 * جستجو و استخراج شناسه‌های محصولات بر اساس فیلترهای مشخص‌شده.
		 *
		 * @param array $filters آرایه فیلترها (دسته‌بندی، کلمه کلیدی، استثناها، شناسه‌ها، نوع محصول، پیشوند SKU).
		 * @return array لیست شناسه‌های یکتا و مرتب‌شده محصولات.
		 */
		public static function query_product_ids( array $filters ) {
			$mode = isset( $filters['mode'] ) ? sanitize_key( $filters['mode'] ) : 'filters';

			// اگر کاربر شناسه‌ها را به صورت مستقیم وارد کرده باشد
			if ( 'manual' === $mode || ! empty( $filters['manual_ids'] ) ) {
				$raw_ids = is_array( $filters['manual_ids'] )
					? $filters['manual_ids']
					: explode( ',', (string) $filters['manual_ids'] );
				$ids = array_values( array_filter( array_map( 'absint', $raw_ids ) ) );
				if ( ! empty( $ids ) ) {
					return self::filter_valid_products( $ids, $filters );
				}
			}

			// اگر فیلتر اختصاصی بر اساس شناسه / کد محصول (SKU) باشد
			if ( 'sku' === $mode && ! empty( $filters['sku'] ) ) {
				$sku_mode = isset( $filters['sku_mode'] ) ? sanitize_key( $filters['sku_mode'] ) : 'starts_with';
				$sku_ids  = self::query_product_ids_by_sku( $filters['sku'], $sku_mode );
				if ( empty( $sku_ids ) ) {
					return array();
				}
				return self::filter_valid_products( $sku_ids, $filters );
			}

			$post_types = array( 'product' );
			$post_statuses = isset( $filters['statuses'] ) && is_array( $filters['statuses'] )
				? array_map( 'sanitize_key', $filters['statuses'] )
				: array( 'publish', 'draft', 'private' );

			$tax_query = array( 'relation' => 'AND' );

			// فیلتر دسته‌بندی
			if ( ! empty( $filters['category_ids'] ) ) {
				$cat_ids = array_values( array_filter( array_map( 'absint', (array) $filters['category_ids'] ) ) );
				if ( ! empty( $cat_ids ) ) {
					$include_children = ! empty( $filters['include_children'] );
					$tax_query[] = array(
						'taxonomy'         => 'product_cat',
						'field'            => 'term_id',
						'terms'            => $cat_ids,
						'include_children' => $include_children,
						'operator'         => 'IN',
					);
				}
			}

			// فیلتر نوع محصول (به طور پیش‌فرض متغیر و ساده)
			$product_types = isset( $filters['product_types'] ) && is_array( $filters['product_types'] )
				? array_map( 'sanitize_key', $filters['product_types'] )
				: array( 'variable', 'simple' );

			if ( ! empty( $product_types ) && ! in_array( 'all', $product_types, true ) ) {
				$tax_query[] = array(
					'taxonomy' => 'product_type',
					'field'    => 'slug',
					'terms'    => $product_types,
					'operator' => 'IN',
				);
			}

			$query_args = array(
				'post_type'              => $post_types,
				'post_status'            => $post_statuses,
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'orderby'                => 'ID',
				'order'                  => 'DESC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);

			if ( count( $tax_query ) > 1 ) {
				$query_args['tax_query'] = $tax_query;
			}

			$wp_query = new WP_Query( $query_args );
			$raw_ids  = array_map( 'absint', (array) $wp_query->posts );

			if ( empty( $raw_ids ) ) {
				return array();
			}

			// اگر فیلتر SKU در کنار سایر فیلترها تعیین شده بود
			if ( ! empty( $filters['sku'] ) ) {
				$sku_mode = isset( $filters['sku_mode'] ) ? sanitize_key( $filters['sku_mode'] ) : 'starts_with';
				$sku_ids  = self::query_product_ids_by_sku( $filters['sku'], $sku_mode );
				$raw_ids  = array_values( array_intersect( $raw_ids, $sku_ids ) );
				if ( empty( $raw_ids ) ) {
					return array();
				}
			}

			// اعمال فیلترهای کلمات کلیدی، استثناها و ویژگی‌ها
			return self::refine_product_ids( $raw_ids, $filters );
		}

		/**
		 * استخراج محصولات بر اساس پیشوند یا تطبیق شناسه (SKU).
		 * چه SKU روی خود محصول والد تنظیم شده باشد چه روی متغیرهای آن.
		 *
		 * @param string $sku_text متن یا پیشوند SKU (مثلاً CH).
		 * @param string $mode     حالت تطبیق: starts_with یا contains یا exact.
		 * @return array لیست شناسه‌های یکتا.
		 */
		public static function query_product_ids_by_sku( $sku_text, $mode = 'starts_with' ) {
			global $wpdb;
			$sku_text = trim( (string) $sku_text );
			if ( '' === $sku_text ) {
				return array();
			}

			if ( 'exact' === $mode ) {
				$sku_pattern = $sku_text;
				$operator    = '=';
			} elseif ( 'contains' === $mode ) {
				$sku_pattern = '%' . $wpdb->esc_like( $sku_text ) . '%';
				$operator    = 'LIKE';
			} else { // starts_with
				$sku_pattern = $wpdb->esc_like( $sku_text ) . '%';
				$operator    = 'LIKE';
			}

			$sql = "SELECT DISTINCT IF(p.post_type = 'product_variation', p.post_parent, p.ID) AS pid
					FROM {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					WHERE p.post_type IN ('product', 'product_variation')
					  AND p.post_status NOT IN ('trash', 'auto-draft')
					  AND pm.meta_key = '_sku'
					  AND pm.meta_value {$operator} %s";

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$ids = $wpdb->get_col( $wpdb->prepare( $sql, $sku_pattern ) );
			return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		}

		/**
		 * پالایش و فیلتر دقیق شناسه‌ها بر اساس کلمات کلیدی، استثناها و متغیرها.
		 */
		private static function refine_product_ids( array $ids, array $filters ) {
			if ( empty( $ids ) ) {
				return array();
			}

			global $wpdb;
			$include_keywords = isset( $filters['keywords'] ) ? self::parse_keywords( $filters['keywords'] ) : array();
			$exclude_keywords = isset( $filters['exclude_keywords'] ) ? self::parse_keywords( $filters['exclude_keywords'] ) : array();
			$match_mode       = isset( $filters['match_mode'] ) ? sanitize_key( $filters['match_mode'] ) : 'contains';
			$has_model_filter = ! empty( $filters['model_term'] );
			$target_model     = $has_model_filter ? trim( (string) $filters['model_term'] ) : '';

			$passed_ids = array();

			// دسته‌بندی برای بهینه‌سازی خواندن عناوین
			$chunks = array_chunk( $ids, 500 );
			foreach ( $chunks as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$chunk
					)
				);

				foreach ( $rows as $row ) {
					$pid   = (int) $row->ID;
					$title = self::normalize_persian( $row->post_title );

					// بررسی کلمات مثبت (باید حداقل یکی یا همه را داشته باشد)
					if ( ! empty( $include_keywords ) ) {
						$matched = false;
						foreach ( $include_keywords as $kw ) {
							$kw = self::normalize_persian( $kw );
							if ( '' === $kw ) {
								continue;
							}
							if ( 'starts_with' === $match_mode ) {
								if ( 0 === mb_stripos( $title, $kw, 0, 'UTF-8' ) ) {
									$matched = true;
									break;
								}
							} else {
								if ( false !== mb_stripos( $title, $kw, 0, 'UTF-8' ) ) {
									$matched = true;
									break;
								}
							}
						}
						if ( ! $matched ) {
							continue;
						}
					}

					// بررسی کلمات منفی/استثنا
					if ( ! empty( $exclude_keywords ) ) {
						$excluded = false;
						foreach ( $exclude_keywords as $ekw ) {
							$ekw = self::normalize_persian( $ekw );
							if ( '' !== $ekw && false !== mb_stripos( $title, $ekw, 0, 'UTF-8' ) ) {
								$excluded = true;
								break;
							}
						}
						if ( $excluded ) {
							continue;
						}
					}

					// اگر فیلتر داشتن یک مدل خاص تنظیم شده باشد
					if ( $has_model_filter ) {
						if ( ! self::product_has_variation_term( $pid, $target_model ) ) {
							continue;
						}
					}

					$passed_ids[] = $pid;
				}
			}

			return array_values( array_unique( $passed_ids ) );
		}

		/**
		 * اعتبارسنجی شناسه‌های دستی وارد شده.
		 */
		private static function filter_valid_products( array $ids, array $filters ) {
			if ( empty( $ids ) ) {
				return array();
			}
			global $wpdb;
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$valid_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE ID IN ($placeholders) AND post_type = 'product'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$ids
				)
			);
			return array_map( 'absint', (array) $valid_ids );
		}

		/**
		 * تبدیل متن کلمات کلیدی به آرایه.
		 */
		public static function parse_keywords( $raw ) {
			if ( is_array( $raw ) ) {
				return array_values( array_filter( array_map( 'trim', $raw ) ) );
			}
			$clean = str_replace( array( '،', "\n", "\r", '|' ), ',', (string) $raw );
			$parts = explode( ',', $clean );
			return array_values( array_filter( array_map( 'trim', $parts ) ) );
		}

		/**
		 * بررسی اینکه آیا محصول موردنظر مدل خاصی را دارد یا خیر.
		 */
		public static function product_has_variation_term( $product_id, $term_name ) {
			$product = wc_get_product( $product_id );
			if ( ! $product || ! $product->is_type( 'variable' ) ) {
				return false;
			}
			$normalized_term = mb_strtolower( self::normalize_persian( $term_name ), 'UTF-8' );

			$attributes = $product->get_attributes();
			foreach ( $attributes as $attr ) {
				if ( $attr->is_taxonomy() ) {
					$terms = wc_get_product_terms( $product_id, $attr->get_name(), array( 'fields' => 'names' ) );
					foreach ( $terms as $name ) {
						if ( mb_strtolower( self::normalize_persian( $name ), 'UTF-8' ) === $normalized_term ) {
							return true;
						}
					}
				} else {
					$options = (array) $attr->get_options();
					foreach ( $options as $opt ) {
						if ( mb_strtolower( self::normalize_persian( $opt ), 'UTF-8' ) === $normalized_term ) {
							return true;
						}
					}
				}
			}
			return false;
		}

		/**
		 * دریافت اطلاعات کامل یک لیست از محصولات برای نمایش جدول زنده در فرانت‌اند.
		 */
		public static function get_products_summary( array $ids, $limit = 50, $offset = 0 ) {
			if ( empty( $ids ) ) {
				return array(
					'total' => 0,
					'items' => array(),
				);
			}

			$total      = count( $ids );
			$slice_ids  = array_slice( $ids, $offset, $limit );
			$items      = array();

			foreach ( $slice_ids as $pid ) {
				$product = wc_get_product( $pid );
				if ( ! $product ) {
					continue;
				}

				// استخراج دسته‌بندی‌ها
				$cats = wc_get_product_category_list( $pid, '، ' );

				// استخراج مدل‌های فعلی و تعداد متغیرها
				$variation_count = 0;
				$current_models  = array();

				if ( $product->is_type( 'variable' ) ) {
					$variation_ids = $product->get_children();
					$variation_count = count( $variation_ids );

					// خواندن نام مدل‌ها از ویژگی‌ها
					$attributes = $product->get_attributes();
					foreach ( $attributes as $attr ) {
						if ( $attr->get_variation() ) {
							if ( $attr->is_taxonomy() ) {
								$terms = wc_get_product_terms( $pid, $attr->get_name(), array( 'fields' => 'names' ) );
								$current_models = array_merge( $current_models, $terms );
							} else {
								$current_models = array_merge( $current_models, (array) $attr->get_options() );
							}
						}
					}
				}

				// تصویر شاخص
				$img_id  = $product->get_image_id();
				$img_url = $img_id ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : wc_placeholder_img_src();

				$items[] = array(
					'id'              => $pid,
					'name'            => $product->get_name(),
					'sku'             => $product->get_sku() ? $product->get_sku() : '—',
					'type'            => $product->get_type(),
					'status'          => $product->get_status(),
					'cats'            => wp_strip_all_tags( $cats ),
					'variation_count' => $variation_count,
					'models'          => array_slice( array_unique( $current_models ), 0, 15 ),
					'models_total'    => count( array_unique( $current_models ) ),
					'image_url'       => $img_url,
					'edit_url'        => get_edit_post_link( $pid, '' ),
					'view_url'        => $product->get_permalink(),
				);
			}

			return array(
				'total' => $total,
				'items' => $items,
			);
		}

		/**
		 * دریافت فهرست تمام دسته‌بندی‌های محصولات جهت انتخاب در فرم فیلتر.
		 */
		public static function get_all_product_categories() {
			$terms = get_terms( array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			) );

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				return array();
			}

			$list = array();
			foreach ( $terms as $t ) {
				$list[] = array(
					'id'     => $t->term_id,
					'name'   => $t->name,
					'count'  => $t->count,
					'parent' => $t->parent,
				);
			}
			return $list;
		}

		/**
		 * دریافت لیست تمام تاکسونومی‌های ویژگی عمومی ووکامرس (مثل pa_model).
		 */
		public static function get_attribute_taxonomies() {
			$taxonomies = wc_get_attribute_taxonomies();
			$res        = array();
			if ( ! empty( $taxonomies ) ) {
				foreach ( $taxonomies as $tax ) {
					$res[] = array(
						'name'  => wc_attribute_taxonomy_name( $tax->attribute_name ),
						'label' => $tax->attribute_label ? $tax->attribute_label : $tax->attribute_name,
					);
				}
			}
			return $res;
		}
	}
}
