<?php
/**
 * تنظیمات، پیش‌فرض‌ها، کتابخانهٔ برندهای آماده و اعتبارسنجی ورودی.
 *
 * همهٔ دادهٔ افزونه در یک آپشن (`tcbv_settings`) زندگی می‌کند.
 * هیچ متا، جدول، محصول یا متغیری نوشته نمی‌شود.
 *
 * @package TisaCase_Brand_Variations
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCBV_Settings' ) ) {

	final class TCBV_Settings {

		const OPTION = 'tcbv_settings';

		/** @var array|null کش درون‌درخواستی. */
		private static $cache = null;

		/**
		 * پیش‌فرض‌های افزونه.
		 *
		 * @return array
		 */
		public static function defaults() {
			return array(
				'enabled'     => 1,
				// حالت تست: پیش‌فرض روشن است تا افزونه تا انتخاب «محصول آزمایشی»
				// به هیچ محصولی در فروشگاه دست نزند.
				'test'        => array(
					'enabled'  => 1,
					'products' => '',
					'badge'    => 1,
				),
				'group_scope' => 'auto', // auto | list
				'group_attrs' => "مدل\nmodel\nمدل گوشی\nمدل دستگاه\nmodel name",
				'skip_attrs'  => "رنگ\ncolor\ncolour\nحجم\nsize\nظرفیت\ncapacity\nگارانتی\nwarranty",
				'swatch_attrs' => "رنگ\ncolor\ncolour\nرنگ\xE2\x80\x8Cبندی\nرنگ بندی",
				'min_brands'  => 2,
				'min_options' => 6,
				'brands'      => array(
					array(
						'id'       => 'apple',
						'label'    => 'آیفون',
						'color'    => '#111827',
						'icon'     => '',
						'enabled'  => 1,
						'keywords' => "iphone\nipad\nipod\napple\nآیفون\nاپل",
						'regex'    => '',
						'exact'    => '',
					),
					array(
						'id'       => 'samsung',
						'label'    => 'سامسونگ',
						'color'    => '#1428A0',
						'icon'     => '',
						'enabled'  => 1,
						'keywords' => "galaxy\nsamsung\nسامسونگ\nگلکسی",
						'regex'    => '^(?:galaxy[\s\-]*)?(?:(?:note|tab)[\s\-]?\d{1,2}[a-z]{0,3}|[asmfjz][\s\-]?\d{1,3}[a-z]{0,3}|z[\s\-]?(?:flip|fold)[\s\-]?\d{0,2})(?:\s.*)?$',
						'exact'    => '',
					),
					array(
						'id'       => 'xiaomi',
						'label'    => 'شیائومی',
						'color'    => '#FF6900',
						'icon'     => '',
						'enabled'  => 1,
						'keywords' => "redmi\npoco\nmi\nxiaomi\nردمی\nپوکو\nشیائومی",
						'regex'    => '^(?:mi|redmi|poco)[\s\-]?\d{1,3}[a-z]{0,4}(?:\s.*)?$',
						'exact'    => '',
					),
					array(
						'id'       => 'realme',
						'label'    => 'ریلمی',
						'color'    => '#F9C400',
						'icon'     => '',
						'enabled'  => 1,
						'keywords' => "realme\nrealmi\nrealmy\nnarzo\nریلمی\nرلمی",
						'regex'    => '',
						'exact'    => '',
					),
				),
				'unknown'     => array(
					'label'    => 'سایر مدل‌ها',
					'color'    => '#94A3B8',
					'enabled'  => 1,
					'position' => 'last', // last | first
				),
				'ui'          => array(
					'mode'               => 'panel', // panel | native | off
					'picker'             => 'accordion', // accordion | open
					'layout'             => 'chips', // chips | list | grid
					'separator'          => 'line',  // line | dashed | gradient | space | label
					'group_style'        => 'header', // header | pill
					'sort'               => 'asis',  // asis | asc | desc
					'search'             => 1,
					'search_placeholder' => 'جستجوی مدل…',
					'counts'             => 1,
					'sticky'             => 1,
					'chips'              => 0,
					'max_height'         => 320,
					'colors_on_items'    => 0,
					'show_label'         => 0,
					'highlight'          => 1,
					'fa_digits'          => 1,
					'oos'                => 0, // 0 خاموش | 1 کم‌رنگ | 2 مخفی
				),
				'theme'       => array(
					'accent'   => '#0E7C6B',
					'bg'       => '#FFFFFF',
					'bg_alt'   => '#FAFAF8',
					'border'   => '#E3E1DA',
					'text'     => '#1F2A2E',
					'muted'    => '#77828A',
					'sep'      => '#EAE8E2',
					'hover'    => '#F4F7F6',
					'sel_bg'   => '#0E7C6B',
					'sel_text' => '#FFFFFF',
					'radius'   => 14,
					'font'     => 14,
					'item_pad' => 9,
					'shadow'   => 1,
				),
				'swatch'      => array(
					'enabled'    => 1,
					'shape'      => 'circle', // circle | square | pill | dot
					'size'       => 28,
					'show_label' => 1,
					'fallback'   => '#CBD5E1',
					'map'        => self::default_color_map(),
				),
				'advanced'    => array(
					'products_scope'   => 'all', // all | include | exclude
					'categories'       => '',
					'respect_optgroups' => 1,
					'safe_mode'        => 0,
					'debug'            => 0,
					'delete_on_uninstall' => 0,
				),
			);
		}

		/**
		 * رنگ‌های پرکاربرد فارسی/انگلیسی برای ویژگی «رنگ».
		 *
		 * @return string
		 */
		public static function default_color_map() {
			$map = array(
				'مشکی'        => '#111111',
				'سیاه'        => '#111111',
				'black'       => '#111111',
				'سفید'        => '#FFFFFF',
				'white'       => '#FFFFFF',
				'آبی'         => '#2F80ED',
				'ابی'         => '#2F80ED',
				'blue'        => '#2F80ED',
				'آبی روشن'    => '#7DB6F5',
				'سرمه‌ای'     => '#1E3A8A',
				'نیلی'        => '#1E3A8A',
				'فیروزه‌ای'   => '#2AA7A0',
				'سبزآبی'      => '#0E7C6B',
				'صورتی'       => '#FF7EB6',
				'pink'        => '#FF7EB6',
				'صورتی روشن'  => '#FFB3D4',
				'قرمز'        => '#E23A3A',
				'red'         => '#E23A3A',
				'زرشکی'       => '#8E1F3F',
				'آجری'        => '#B4552D',
				'سبز'         => '#2E9E5B',
				'green'       => '#2E9E5B',
				'سبز روشن'    => '#8FD19E',
				'زیتونی'      => '#6B7A2F',
				'زرد'         => '#F2C744',
				'yellow'      => '#F2C744',
				'نارنجی'      => '#F2994A',
				'orange'      => '#F2994A',
				'بنفش'        => '#7C4DFF',
				'purple'      => '#7C4DFF',
				'یاسی'        => '#C6A8FF',
				'قهوه‌ای'     => '#8B5A2B',
				'brown'       => '#8B5A2B',
				'خاکستری'     => '#9AA3A9',
				'طوسی'        => '#9AA3A9',
				'gray'        => '#9AA3A9',
				'grey'        => '#9AA3A9',
				'نقره‌ای'     => '#C7CCD4',
				'نقره ای'     => '#C7CCD4',
				'silver'      => '#C7CCD4',
				'طلایی'       => '#D4AF37',
				'gold'        => '#D4AF37',
				'بژ'          => '#E8DCC8',
				'کرم'         => '#F3E7CF',
				'cream'       => '#F3E7CF',
				'لیمویی'      => '#E9F58A',
				'سفید صدفی'   => '#F6F4EF',
				'طرح‌دار'     => '@multi',
				'چندرنگ'      => '@multi',
				'multi'       => '@multi',
				'شفاف'        => '@clear',
				'clear'       => '@clear',
				'transparent' => '@clear',
			);

			$lines = array();
			foreach ( $map as $name => $hex ) {
				$lines[] = $name . ': ' . $hex;
			}
			return implode( "\n", $lines );
		}

		/**
		 * خواندن تنظیمات با ادغام عمیق روی پیش‌فرض‌ها (کلیدهای تازه خودشان اضافه می‌شوند).
		 *
		 * @return array
		 */
		public static function get() {
			if ( is_array( self::$cache ) ) {
				return self::$cache;
			}
			$stored = get_option( self::OPTION );
			$stored = is_array( $stored ) ? $stored : array();
			self::$cache = self::merge( self::defaults(), $stored );
			return self::$cache;
		}

		/**
		 * ادغام بازگشتی: مقدار ذخیره‌شده بر پیش‌فرض غلبه می‌کند.
		 *
		 * @param array $base    پیش‌فرض.
		 * @param array $saved   ذخیره‌شده.
		 * @return array
		 */
		public static function merge( $base, $saved ) {
			foreach ( $saved as $key => $value ) {
				if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && self::is_assoc( $base[ $key ] ) ) {
					$base[ $key ] = self::merge( $base[ $key ], $value );
				} else {
					$base[ $key ] = $value;
				}
			}
			return $base;
		}

		/**
		 * آرایهٔ انجمنی (نه لیست)؟
		 *
		 * @param array $arr آرایه.
		 * @return bool
		 */
		private static function is_assoc( $arr ) {
			if ( array() === $arr ) {
				return false;
			}
			return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
		}

		/**
		 * ذخیرهٔ تنظیمات تمیزشده.
		 *
		 * @param array $raw ورودی خام فرم.
		 * @return array تنظیمات ذخیره‌شده.
		 */
		public static function save( $raw ) {
			$clean = self::sanitize( $raw );
			update_option( self::OPTION, $clean );
			self::$cache = $clean;
			return $clean;
		}

		/**
		 * اعتبارسنجی کامل ورودی فرم.
		 *
		 * @param mixed $raw ورودی خام.
		 * @return array
		 */
		public static function sanitize( $raw ) {
			$out = self::defaults();
			if ( ! is_array( $raw ) ) {
				return $out;
			}

			$out['enabled']     = empty( $raw['enabled'] ) ? 0 : 1;
			$out['group_scope'] = ( isset( $raw['group_scope'] ) && 'list' === $raw['group_scope'] ) ? 'list' : 'auto';
			$out['group_attrs'] = self::textarea( isset( $raw['group_attrs'] ) ? $raw['group_attrs'] : '' );
			$out['skip_attrs']  = self::textarea( isset( $raw['skip_attrs'] ) ? $raw['skip_attrs'] : '' );
			$out['swatch_attrs'] = self::textarea( isset( $raw['swatch_attrs'] ) ? $raw['swatch_attrs'] : $out['swatch_attrs'] );
			$out['min_brands']  = max( 1, min( 6, self::int( isset( $raw['min_brands'] ) ? $raw['min_brands'] : 2 ) ) );
			$out['min_options'] = max( 1, min( 200, self::int( isset( $raw['min_options'] ) ? $raw['min_options'] : 6 ) ) );

			// حالت تست (فقط محصول‌های انتخاب‌شده آزمایش می‌شوند).
			if ( isset( $raw['test'] ) && is_array( $raw['test'] ) ) {
				$t = $raw['test'];
				$out['test']['enabled'] = empty( $t['enabled'] ) ? 0 : 1;
				$out['test']['badge']   = empty( $t['badge'] ) ? 0 : 1;

				$list = isset( $t['products'] ) ? $t['products'] : '';
				if ( is_array( $list ) ) {
					$list = implode( ',', array_map( 'strval', $list ) );
				}
				preg_match_all( '/\d+/', (string) $list, $found );
				$ids = array();
				foreach ( $found[0] as $one ) {
					$one = absint( $one );
					if ( $one > 0 ) {
						$ids[ $one ] = true;
					}
				}
				$ids = array_slice( array_keys( $ids ), 0, 20 ); // سقف ایمنی
				$out['test']['products'] = implode( ',', array_map( 'strval', $ids ) );
			}

			// برندها (ترتیب ارسال = ترتیب نمایش = اولویت تطبیق).
			$brands = array();
			$seen   = array();
			if ( isset( $raw['brands'] ) && is_array( $raw['brands'] ) ) {
				foreach ( $raw['brands'] as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
					if ( '' === trim( $label ) ) {
						continue; // ردیف خالی حذف می‌شود
					}
					$id = isset( $row['id'] ) ? sanitize_key( $row['id'] ) : '';
					if ( '' === $id || 'unknown' === $id || isset( $seen[ $id ] ) ) {
						$id = self::unique_id( $label, $seen );
					}
					$seen[ $id ] = true;

					$brands[] = array(
						'id'       => $id,
						'label'    => $label,
						'color'    => self::hex( isset( $row['color'] ) ? $row['color'] : '', '#94A3B8' ),
						'icon'     => isset( $row['icon'] ) ? mb_substr( sanitize_text_field( $row['icon'] ), 0, 4 ) : '',
						'enabled'  => empty( $row['enabled'] ) ? 0 : 1,
						'keywords' => self::textarea( isset( $row['keywords'] ) ? $row['keywords'] : '' ),
						'regex'    => self::textarea( isset( $row['regex'] ) ? $row['regex'] : '' ),
						'exact'    => self::textarea( isset( $row['exact'] ) ? $row['exact'] : '' ),
					);
				}
			}
			$out['brands'] = $brands;

			if ( isset( $raw['unknown'] ) && is_array( $raw['unknown'] ) ) {
				$u                   = $raw['unknown'];
				$out['unknown']      = array(
					'label'    => isset( $u['label'] ) ? sanitize_text_field( $u['label'] ) : $out['unknown']['label'],
					'color'    => self::hex( isset( $u['color'] ) ? $u['color'] : '', $out['unknown']['color'] ),
					'enabled'  => empty( $u['enabled'] ) ? 0 : 1,
					'position' => ( isset( $u['position'] ) && 'first' === $u['position'] ) ? 'first' : 'last',
				);
				if ( '' === trim( $out['unknown']['label'] ) ) {
					$out['unknown']['label'] = 'سایر مدل‌ها';
				}
			}

			if ( isset( $raw['ui'] ) && is_array( $raw['ui'] ) ) {
				$ui                 = $raw['ui'];
				$out['ui']['mode']  = self::pick( isset( $ui['mode'] ) ? $ui['mode'] : '', array( 'panel', 'native', 'off' ), 'panel' );
				$out['ui']['picker'] = self::pick( isset( $ui['picker'] ) ? $ui['picker'] : '', array( 'accordion', 'open' ), 'accordion' );
				$out['ui']['layout'] = self::pick( isset( $ui['layout'] ) ? $ui['layout'] : '', array( 'chips', 'list', 'grid' ), 'chips' );
				$out['ui']['separator'] = self::pick( isset( $ui['separator'] ) ? $ui['separator'] : '', array( 'line', 'dashed', 'gradient', 'space', 'label', 'none' ), 'line' );
				$out['ui']['group_style'] = self::pick( isset( $ui['group_style'] ) ? $ui['group_style'] : '', array( 'header', 'pill' ), 'header' );
				$out['ui']['sort']  = self::pick( isset( $ui['sort'] ) ? $ui['sort'] : '', array( 'asis', 'asc', 'desc' ), 'asis' );

				foreach ( array( 'search', 'counts', 'sticky', 'chips', 'colors_on_items', 'show_label', 'highlight', 'fa_digits' ) as $flag ) {
					$out['ui'][ $flag ] = empty( $ui[ $flag ] ) ? 0 : 1;
				}
				$out['ui']['oos']                = max( 0, min( 2, self::int( isset( $ui['oos'] ) ? $ui['oos'] : 0 ) ) );
				$out['ui']['max_height']         = max( 140, min( 900, self::int( isset( $ui['max_height'] ) ? $ui['max_height'] : 320 ) ) );
				$out['ui']['search_placeholder'] = isset( $ui['search_placeholder'] ) && '' !== trim( (string) $ui['search_placeholder'] )
					? sanitize_text_field( $ui['search_placeholder'] )
					: $out['ui']['search_placeholder'];
			}

			if ( isset( $raw['theme'] ) && is_array( $raw['theme'] ) ) {
				$t = $raw['theme'];
				foreach ( array( 'accent', 'bg', 'bg_alt', 'border', 'text', 'muted', 'sep', 'hover', 'sel_bg', 'sel_text' ) as $ck ) {
					$out['theme'][ $ck ] = self::hex( isset( $t[ $ck ] ) ? $t[ $ck ] : '', $out['theme'][ $ck ] );
				}
				$out['theme']['radius']   = max( 0, min( 30, self::int( isset( $t['radius'] ) ? $t['radius'] : 14 ) ) );
				$out['theme']['font']     = max( 10, min( 22, self::int( isset( $t['font'] ) ? $t['font'] : 14 ) ) );
				$out['theme']['item_pad'] = max( 2, min( 24, self::int( isset( $t['item_pad'] ) ? $t['item_pad'] : 9 ) ) );
				$out['theme']['shadow']   = empty( $t['shadow'] ) ? 0 : 1;
			}

			if ( isset( $raw['swatch'] ) && is_array( $raw['swatch'] ) ) {
				$s                    = $raw['swatch'];
				$out['swatch']['enabled']    = empty( $s['enabled'] ) ? 0 : 1;
				$out['swatch']['shape']      = self::pick( isset( $s['shape'] ) ? $s['shape'] : '', array( 'circle', 'square', 'pill', 'dot' ), 'circle' );
				$out['swatch']['show_label'] = empty( $s['show_label'] ) ? 0 : 1;
				$out['swatch']['size']       = max( 14, min( 60, self::int( isset( $s['size'] ) ? $s['size'] : 28 ) ) );
				$out['swatch']['fallback']   = self::hex( isset( $s['fallback'] ) ? $s['fallback'] : '', '#CBD5E1' );
				$out['swatch']['map']        = self::textarea( isset( $s['map'] ) ? $s['map'] : $out['swatch']['map'] );
			}

			if ( isset( $raw['advanced'] ) && is_array( $raw['advanced'] ) ) {
				$a                              = $raw['advanced'];
				$out['advanced']['products_scope'] = self::pick( isset( $a['products_scope'] ) ? $a['products_scope'] : '', array( 'all', 'include', 'exclude' ), 'all' );
				$out['advanced']['categories']     = self::textarea( isset( $a['categories'] ) ? $a['categories'] : '' );
				$out['advanced']['respect_optgroups'] = empty( $a['respect_optgroups'] ) ? 0 : 1;
				$out['advanced']['safe_mode']      = empty( $a['safe_mode'] ) ? 0 : 1;
				$out['advanced']['debug']          = empty( $a['debug'] ) ? 0 : 1;
				$out['advanced']['delete_on_uninstall'] = empty( $a['delete_on_uninstall'] ) ? 0 : 1;
			}

			return $out;
		}

		/**
		 * آیا حالت تست روشن است؟
		 *
		 * @param array|null $settings تنظیمات.
		 * @return bool
		 */
		public static function test_mode_on( $settings = null ) {
			$settings = is_array( $settings ) ? $settings : self::get();
			return ! empty( $settings['test']['enabled'] );
		}

		/**
		 * شناسهٔ محصول‌های آزمایشی (حالت تست).
		 *
		 * @param array|null $settings تنظیمات.
		 * @return int[]
		 */
		public static function test_products( $settings = null ) {
			$settings = is_array( $settings ) ? $settings : self::get();
			$list     = isset( $settings['test']['products'] ) ? (string) $settings['test']['products'] : '';
			if ( '' === trim( $list ) ) {
				return array();
			}
			$out = array();
			foreach ( preg_split( '/[^0-9]+/', $list ) as $one ) {
				$one = absint( $one );
				if ( $one > 0 ) {
					$out[ $one ] = true;
				}
			}
			return array_map( 'intval', array_keys( $out ) );
		}

		/**
		 * برندهای فعال (برای موتور تطبیق).
		 *
		 * @param array|null $settings تنظیمات.
		 * @return array
		 */
		public static function active_brands( $settings = null ) {
			$settings = is_array( $settings ) ? $settings : self::get();
			$brands   = array();
			foreach ( (array) $settings['brands'] as $index => $brand ) {
				if ( ! is_array( $brand ) || empty( $brand['label'] ) ) {
					continue;
				}
				$id = isset( $brand['id'] ) && '' !== $brand['id'] ? sanitize_key( $brand['id'] ) : 'brand-' . ( (int) $index + 1 );
				if ( 'unknown' === $id ) {
					$id = 'brand-' . ( (int) $index + 1 );
				}
				$brands[] = array(
					'id'       => $id,
					'label'    => (string) $brand['label'],
					'color'    => isset( $brand['color'] ) ? (string) $brand['color'] : '#94A3B8',
					'icon'     => isset( $brand['icon'] ) ? (string) $brand['icon'] : '',
					'enabled'  => ! isset( $brand['enabled'] ) || ! empty( $brand['enabled'] ) ? 1 : 0,
					'keywords' => isset( $brand['keywords'] ) ? (string) $brand['keywords'] : '',
					'regex'    => isset( $brand['regex'] ) ? (string) $brand['regex'] : '',
					'exact'    => isset( $brand['exact'] ) ? (string) $brand['exact'] : '',
				);
			}
			return $brands;
		}

		/**
		 * کتابخانهٔ برندهای آماده — با یک کلیک به فهرست اضافه می‌شوند.
		 * الگوها عمداً محافظه‌کارانه‌اند تا با برندهای دیگر تداخل نکنند.
		 *
		 * @return array
		 */
		public static function presets() {
			return array(
				'apple'    => array(
					'label'    => 'آیفون',
					'color'    => '#111827',
					'keywords' => "iphone\nipad\nipod\napple\nآیفون\nاپل",
					'regex'    => '',
				),
				'samsung'  => array(
					'label'    => 'سامسونگ',
					'color'    => '#1428A0',
					'keywords' => "galaxy\nsamsung\nسامسونگ\nگلکسی",
					'regex'    => '^(?:galaxy[\s\-]*)?(?:(?:note|tab)[\s\-]?\d{1,2}[a-z]{0,3}|[asmfjz][\s\-]?\d{1,3}[a-z]{0,3}|z[\s\-]?(?:flip|fold)[\s\-]?\d{0,2})(?:\s.*)?$',
				),
				'xiaomi'   => array(
					'label'    => 'شیائومی',
					'color'    => '#FF6900',
					'keywords' => "redmi\npoco\nmi\nxiaomi\nردمی\nپوکو\nشیائومی",
					'regex'    => '',
				),
				'honor'    => array(
					'label'    => 'هواوی / آنر',
					'color'    => '#C8102E',
					'keywords' => "huawei\nhonor\nnova\nmate\nهواوی\nآنر\nنوا",
					'regex'    => '^(?:p|y)\s?\d{1,2}[a-z]?(?:\s.*)?$',
				),
				'google'   => array(
					'label'    => 'گوگل پیکسل',
					'color'    => '#4285F4',
					'keywords' => "pixel\ngoogle",
					'regex'    => '',
				),
				'nokia'    => array(
					'label'    => 'نوکیا',
					'color'    => '#124191',
					'keywords' => "nokia\nنوکیا",
					'regex'    => '',
				),
				'oppo'     => array(
					'label'    => 'اوپو',
					'color'    => '#0F9D58',
					'keywords' => "oppo\nreno\nاوپو",
					'regex'    => '',
				),
				'realme'   => array(
					'label'    => 'ریلمی',
					'color'    => '#F9C400',
					'keywords' => "realme\nrealmi\nrealmy\nnarzo\nریلمی\nرلمی\nریلمی",
					'regex'    => '',
				),
				'oneplus'  => array(
					'label'    => 'وان‌پلاس',
					'color'    => '#EB0028',
					'keywords' => "oneplus\none plus",
					'regex'    => '',
				),
				'nothing'  => array(
					'label'    => 'ناتینگ',
					'color'    => '#1F2937',
					'keywords' => "nothing\ncmf",
					'regex'    => '',
				),
				'sony'     => array(
					'label'    => 'سونی',
					'color'    => '#0F172A',
					'keywords' => "xperia\nsony",
					'regex'    => '',
				),
				'motorola' => array(
					'label'    => 'موتورولا',
					'color'    => '#1B5EAB',
					'keywords' => "moto\nmotorola\nedge\ng\s?power",
					'regex'    => '^(?:moto|motorola)\b.*$',
				),
				'asus'     => array(
					'label'    => 'ایسوس',
					'color'    => '#00539B',
					'keywords' => "asus\nzenfone\nrog",
					'regex'    => '',
				),
				'tecno'    => array(
					'label'    => 'تکنو / اینفینیکس',
					'color'    => '#0EA5E9',
					'keywords' => "tecno\ninfinix\nspark\ncamon",
					'regex'    => '^(?:hot|smart|note)\s?\d{1,2}.*$',
				),
				'ipad'     => array(
					'label'    => 'آیپد و تبلت',
					'color'    => '#6B7280',
					'keywords' => "ipad\ntab\nتبلت\ngalaxy tab",
					'regex'    => '',
				),
				'case'     => array(
					'label'    => 'غیر موبایل',
					'color'    => '#A3A3A3',
					'keywords' => "ایرپاد\nairpod\nهندزفری\nساعت\nwatch\nشارژر\nپاوربانک",
					'regex'    => '',
				),
			);
		}

		/**
		 * نقشهٔ رنگ (نام → هگز) از متن ذخیره‌شده.
		 *
		 * @param array|null $settings تنظیمات.
		 * @return array<string,string> کلید = نام نرمال‌شده.
		 */
		public static function color_map( $settings = null ) {
			$settings = is_array( $settings ) ? $settings : self::get();
			$raw      = isset( $settings['swatch']['map'] ) ? (string) $settings['swatch']['map'] : '';
			$map      = array();

			foreach ( self::lines( $raw ) as $line ) {
				if ( false === strpos( $line, ':' ) ) {
					continue;
				}
				list( $name, $hex ) = explode( ':', $line, 2 );
				$name = trim( $name );
				$hex  = trim( $hex );
				if ( '' === $name || '' === $hex ) {
					continue;
				}
				if ( 0 === strpos( $hex, '@' ) ) {
					$map[ TCBV_Rules::key( $name ) ] = $hex; // @multi / @clear
					continue;
				}
				if ( '#' !== substr( $hex, 0, 1 ) ) {
					$hex = '#' . $hex;
				}
				$safe = sanitize_hex_color( $hex );
				if ( $safe ) {
					$map[ TCBV_Rules::key( $name ) ] = strtoupper( $safe );
				}
			}

			return $map;
		}

		/**
		 * متن چندخطی → آرایهٔ خطوط تمیز.
		 *
		 * @param string $text متن.
		 * @return array
		 */
		public static function lines( $text ) {
			$text  = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
			$lines = array();
			foreach ( explode( "\n", $text ) as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$lines[] = $line;
				}
			}
			return $lines;
		}

		/**
		 * ذخیرهٔ متن چندخطی با پاک‌سازی سبک (تگ ممنوع، خطوط حفظ).
		 *
		 * @param mixed $value ورودی.
		 * @return string
		 */
		private static function textarea( $value ) {
			if ( is_array( $value ) ) {
				$value = implode( "\n", array_map( 'strval', $value ) );
			}
			$value = wp_strip_all_tags( (string) $value );
			$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
			return trim( $value );
		}

		/**
		 * عدد صحیح.
		 *
		 * @param mixed $value ورودی.
		 * @return int
		 */
		private static function int( $value ) {
			return (int) round( (float) $value );
		}

		/**
		 * یکی از مقادیر مجاز.
		 *
		 * @param string $value   ورودی.
		 * @param array  $allowed مجازها.
		 * @param string $default پیش‌فرض.
		 * @return string
		 */
		private static function pick( $value, $allowed, $default ) {
			$value = is_string( $value ) ? sanitize_key( $value ) : '';
			return in_array( $value, $allowed, true ) ? $value : $default;
		}

		/**
		 * رنگ هگز امن با فالبک.
		 *
		 * @param string $value    ورودی.
		 * @param string $fallback فالبک.
		 * @return string
		 */
		private static function hex( $value, $fallback ) {
			$value = is_string( $value ) ? trim( $value ) : '';
			if ( '' !== $value && '#' !== substr( $value, 0, 1 ) ) {
				$value = '#' . $value;
			}
			$safe = sanitize_hex_color( $value );
			return $safe ? strtoupper( $safe ) : $fallback;
		}

		/**
		 * شناسهٔ یکتا از روی برچسب.
		 *
		 * @param string $label برچسب.
		 * @param array  $seen  شناسه‌های استفاده‌شده.
		 * @return string
		 */
		private static function unique_id( $label, $seen ) {
			$id = sanitize_key( $label );
			if ( '' === $id ) {
				$id = 'brand';
			}
			$i = 2;
			$try = $id;
			while ( isset( $seen[ $try ] ) ) {
				$try = $id . '-' . $i;
				$i++;
			}
			return $try;
		}
	}
}
