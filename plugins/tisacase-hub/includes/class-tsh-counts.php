<?php
/**
 * شمارنده‌های روی کارت‌ها. همه کش‌شده (پیش‌فرض ۵ دقیقه) و همه با پرس‌وجوی آماده.
 *
 * این شمارنده‌ها «آمار» نیستند؛ فقط یک نشانهٔ عملیاتی‌اند. هر کدام در صورت نبود
 شرط لازم null برمی‌گردانند تا کارت بدون بج رندر شود.
 *
 * @package TisaCase_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TSH_Counts' ) ) {

	/**
	 * پرس‌وجوی کش‌شدهٔ شمارنده‌ها.
	 */
	final class TSH_Counts {

		/**
		 * نام‌های قابل‌استفاده در registry.
		 *
		 * @return array<int,string>
		 */
		public static function names() {
			return array(
				'bdc_drafts',
				'sku_missing',
				'desc_backups',
				'bpm_runs',
				'pm_rules',
				'wcsp_exceptions',
				'tracking_codes',
				'orders_30d',
			);
		}

		/**
		 * شمارندهٔ کش‌شده.
		 *
		 * @param string $name نام شمارنده.
		 * @return array|null {value:int|string, label:string, tone:string}
		 */
		public static function get( $name ) {
			if ( ! $name || ! TSH_UI::setting( 'show_counts' ) ) {
				return null;
			}
			$name = sanitize_key( $name );
			$data = get_transient( 'tsh_count_' . $name );
			if ( false === $data ) {
				$data = self::compute( $name );
				$ttl  = (int) TSH_UI::setting( 'cache_ttl', 300 );
				$ttl  = max( 0, $ttl );
				if ( $ttl > 0 ) {
					set_transient( 'tsh_count_' . $name, $data, $ttl );
				}
			}
			if ( is_array( $data ) && ( ! isset( $data['value'] ) || null === $data['value'] ) ) {
				return null;
			}
			return is_array( $data ) ? $data : null;
		}

		/**
		 * زمان آخرین محاسبه (برای نوار پایین هاب).
		 *
		 * @return string
		 */
		public static function last_updated() {
			$t = (int) get_option( 'tsh_counts_at', 0 );
			return $t ? (string) date_i18n( get_option( 'time_format', 'H:i' ), $t ) : '';
		}

		/**
		 * محاسبهٔ خام.
		 *
		 * @param string $name نام.
		 * @return array
		 */
		private static function compute( $name ) {
			global $wpdb;

			$out = array(
				'value' => null,
				'label' => '',
				'tone'  => '',
			);
			$has_products = post_type_exists( 'product' );

			switch ( $name ) {

				case 'bdc_drafts':
					if ( ! $has_products ) {
						break;
					}
					$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ('draft','pending')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$out = array(
						'value' => $n,
						'label' => __( 'پیش‌نویس/در صف', 'tisacase-hub' ),
						'tone'  => $n > 0 ? 'warn' : '',
					);
					break;

				case 'sku_missing':
					if ( ! $has_products ) {
						break;
					}
					$sql   = "SELECT COUNT(*) FROM {$wpdb->posts} p
						WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','pending')
						AND p.ID NOT IN ( SELECT m.post_id FROM {$wpdb->postmeta} m WHERE m.meta_key = '_product_type' AND m.meta_value = 'variation' )
						AND p.ID NOT IN ( SELECT s.post_id FROM {$wpdb->postmeta} s WHERE s.meta_key = '_sku' AND s.meta_value <> '' )";
					$n     = (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$out   = array(
						'value' => $n,
						'label' => __( 'محصول بدون SKU', 'tisacase-hub' ),
						'tone'  => $n > 0 ? 'warn' : '',
					);
					break;

				case 'desc_backups':
					$backups = get_option( 'tisacase_desc_backups', array() );
					$n       = is_array( $backups ) ? count( $backups ) : 0;
					$out     = array(
						'value' => $n,
						'label' => __( 'نسخهٔ قابل بازگردانی', 'tisacase-hub' ),
						'tone'  => '',
					);
					break;

				case 'bpm_runs':
					$table = $wpdb->prefix . 'tisacase_bpm_runs';
					$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					if ( $exists !== $table ) {
						break;
					}
					$n   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
					$out = array(
						'value' => $n,
						'label' => __( 'اجرای ثبت‌شده', 'tisacase-hub' ),
						'tone'  => '',
					);
					break;

				case 'pm_rules':
					$raw = get_option( 'tisacase_pricing_manager_rules_v1', array() );
					$n   = self::rule_count( $raw );
					$out = array(
						'value' => $n,
						'label' => __( 'قانون قیمت', 'tisacase-hub' ),
						'tone'  => $n > 0 ? '' : 'muted',
					);
					break;

				case 'wcsp_exceptions':
					$raw  = get_option( 'wcsp_settings', array() );
					$line = '';
					if ( is_array( $raw ) && isset( $raw['sku_exceptions'] ) ) {
						$line = (string) $raw['sku_exceptions'];
					}
					$lines = array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', $line ) ) );
					$on    = is_array( $raw ) && isset( $raw['enabled'] ) && 'yes' === $raw['enabled'];
					$out   = array(
						'value' => count( $lines ),
						'label' => $on ? __( 'استثنای SKU', 'tisacase-hub' ) : __( 'پکیج غیرفعال است', 'tisacase-hub' ),
						'tone'  => $on ? '' : 'warn',
					);
					break;

				case 'tracking_codes':
					$n = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT meta_id) FROM {$wpdb->postmeta} WHERE meta_key = '_tracking_code' AND meta_value <> ''" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$out = array(
						'value' => $n,
						'label' => __( 'کد رهگیری ثبت‌شده', 'tisacase-hub' ),
						'tone'  => '',
					);
					break;

				case 'orders_30d':
					if ( ! function_exists( 'wc_get_orders' ) ) {
						break;
					}
					$res = wc_get_orders(
						array(
							'limit'      => 1,
							'return'     => 'ids',
							'paginate'   => true,
							'orderby'    => 'ID',
							'order'      => 'DESC',
							'status'     => array( 'wc-processing', 'wc-completed' ),
							'date_created' => '>' . gmdate( 'Y-m-d 00:00:00', time() - 30 * DAY_IN_SECONDS ),
						)
					);
					$n   = ( isset( $res->total ) ) ? (int) $res->total : 0;
					$out = array(
						'value' => $n,
						'label' => __( 'سفارش ۳۰ روز اخیر', 'tisacase-hub' ),
						'tone'  => '',
					);
					break;
			}

			update_option( 'tsh_counts_at', time(), false );
			return $out;
		}

		/**
		 * شمارش قانون داخل ساختارهای مختلف آپشن.
		 *
		 * @param mixed $raw مقدار ذخیره‌شده.
		 * @return int
		 */
		private static function rule_count( $raw ) {
			if ( ! is_array( $raw ) ) {
				return 0;
			}
			if ( isset( $raw['rules'] ) && is_array( $raw['rules'] ) ) {
				$raw = $raw['rules'];
			}
			$n = 0;
			foreach ( (array) $raw as $item ) {
				if ( is_array( $item ) ) {
					if ( ! empty( $item ) ) {
						++$n;
					}
					continue;
				}
				if ( is_scalar( $item ) && '' !== trim( (string) $item ) ) {
					++$n;
				}
			}
			return $n;
		}

		/**
		 * پاک کردن همهٔ شمارنده‌ها.
		 *
		 * @return void
		 */
		public static function flush() {
			foreach ( self::names() as $name ) {
				delete_transient( 'tsh_count_' . $name );
			}
		}

		/**
		 * شمارنده‌ها را دوباره حساب می‌کند و به‌صورت JSON برمی‌گرداند (برای دکمهٔ ⟳).
		 *
		 * @return array<string,array>
		 */
		public static function refresh_all() {
			self::flush();
			$items = TSH_Registry::items();
			$out   = array();
			foreach ( $items as $key => $item ) {
				if ( empty( $item['count'] ) ) {
					continue;
				}
				$count = self::get( $item['count'] );
				if ( $count ) {
					$out[ $key ] = $count;
				}
			}
			return $out;
		}
	}
}
