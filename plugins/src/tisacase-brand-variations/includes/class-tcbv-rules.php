<?php
/**
 * موتور دسته‌بندی: نرمال‌سازی نام مدل، تطبیق با برند، گروه‌بندی مقادیر.
 *
 * همین منطق با همان الگوها در `assets/frontend.js` هم پیاده شده است تا
 * گروه‌بندی سمت سرور (بدون جاوااسکریپت) و سمت کاربر یکی باشد.
 *
 * @package TisaCase_Brand_Variations
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCBV_Rules' ) ) {

	final class TCBV_Rules {

		const UNKNOWN = 'unknown';

		/** @var array<string,string> نقشهٔ «مقدار گزینه → متن دیده‌شده». */
		private static $labels = array();

		/**
		 * ثبت برچسب گزینه‌ها.
		 *
		 * مقدار گزینه در ووکامرس اسلاگ است (`a20-a30` یا برای فارسی درصدکدشده)؛
		 * تشخیص برند باید روی متنی انجام شود که مشتری می‌بیند.
		 *
		 * @param array $map value => label.
		 * @return void
		 */
		public static function set_labels( $map ) {
			self::$labels = is_array( $map ) ? $map : array();
		}

		/**
		 * متن‌های قابل‌تطبیق یک مقدار: برچسب + اسلاگ دیکدشده.
		 *
		 * @param string $value مقدار گزینه.
		 * @return string[]
		 */
		public static function texts_for( $value ) {
			$out = array();
			$key = (string) $value;

			if ( isset( self::$labels[ $key ] ) && '' !== trim( (string) self::$labels[ $key ] ) ) {
				$out[] = (string) self::$labels[ $key ];
			}

			$decoded = rawurldecode( $key );
			$decoded = str_replace( array( '-', '_' ), ' ', $decoded );
			if ( '' !== trim( $decoded ) && ! in_array( $decoded, $out, true ) ) {
				$out[] = $decoded;
			}

			return empty( $out ) ? array( $key ) : $out;
		}

		/**
		 * نرمال‌سازی: نیم‌فاصله/فاصلهٔ مجازی، ی و ک عربی، اعراب، ارقام فارسی، حروف کوچک.
		 *
		 * @param mixed $text ورودی.
		 * @return string
		 */
		public static function normalize( $text ) {
			$text = (string) $text;
			if ( '' === $text ) {
				return '';
			}

			$text = str_replace(
				array( "\xE2\x80\x8C", "\xE2\x80\x8D", "\xE2\x80\x8E", "\xE2\x80\x8F", "\xC2\xA0", "\xE2\x81\xA0" ),
				' ',
				$text
			);
			$text = preg_replace( '/[\x{064A}\x{0649}\x{06CC}]/u', 'ی', $text ); // ي ى ی  →  ی
			$text = str_replace( array( 'ك', 'ة' ), array( 'ک', 'ه' ), $text );
			$text = preg_replace( '/[\x{064B}-\x{0655}\x{0670}]/u', '', $text );   // اعراب
			$text = self::fa_digits( $text );
			$text = preg_replace( '/\s+/u', ' ', $text );
			$text = trim( (string) $text );

			return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		}

		/**
		 * ارقام فارسی/عربی → لاتین.
		 *
		 * @param string $text ورودی.
		 * @return string
		 */
		private static function fa_digits( $text ) {
			$fa = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
			$en = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
			return str_replace( $fa, $en, $text );
		}

		/**
		 * کلید مقایسه: بدون فاصله، خط تیره و نویسه‌های تزئینی — برای فهرست دستی.
		 *
		 * @param mixed $text ورودی.
		 * @return string
		 */
		public static function key( $text ) {
			$text = self::normalize( $text );
			return preg_replace( '/[^\p{L}\p{N}]+/u', '', $text );
		}

		/**
		 * کلمه‌های مستقل یک مقدار.
		 *
		 * @param mixed $text ورودی.
		 * @return array
		 */
		public static function tokens( $text ) {
			$text = self::normalize( $text );
			$out  = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
			return is_array( $out ) ? $out : array();
		}

		/**
		 * تکه‌های یک مقدار چندمدلی.
		 *
		 * «A30s/A50/A50s» یا «A12, M12» یا «Mi11t | tpro» سه/دو مدل مستقل‌اند؛
		 * برای تشخیص برند باید هر تکه جداگانه سنجیده شود، وگرنه الگوهای لنگرداشته
		 * (^…$) هیچ‌وقت نمی‌خوانند و مقدار به «سایر مدل‌ها» می‌افتد.
		 *
		 * @param mixed $text ورودی.
		 * @return string[] تکه‌های نرمال‌شده (بدون تکرار).
		 */
		public static function segments( $text ) {
			$norm = self::normalize( $text );
			if ( '' === $norm ) {
				return array();
			}

			$parts = preg_split( '#\s*(?:/|\\\\|\||،|,|;|؛|\+|&|\x{2044}|\bو\b)\s*#u', $norm, -1, PREG_SPLIT_NO_EMPTY );
			$parts = is_array( $parts ) ? $parts : array();

			$out = array();
			foreach ( $parts as $part ) {
				$part = trim( $part );
				if ( '' !== $part && ! in_array( $part, $out, true ) ) {
					$out[] = $part;
				}
			}

			return $out;
		}

		/**
		 * نامزدهای تطبیق: کل مقدار + تک‌تک تکه‌ها.
		 *
		 * @param mixed $text ورودی.
		 * @return string[]
		 */
		public static function candidates( $text ) {
			$norm = self::normalize( $text );
			if ( '' === $norm ) {
				return array();
			}

			$out  = array( $norm );
			foreach ( self::segments( $text ) as $segment ) {
				if ( ! in_array( $segment, $out, true ) ) {
					$out[] = $segment;
				}
			}

			return $out;
		}

		/**
		 * آیا این کلمه با این کلیدواژه می‌خواند؟
		 *
		 * قاعده‌ها (به‌ترتیب): برابری دقیق ← کد چسبیده به عدد («mi11t» با «mi»،
		 * «iphone13» با «iphone») ← پیشوند برای کلیدواژه‌های بلند («redminote12»
		 * با «redmi»). کلیدواژهٔ یک‌حرفی فقط برابری دقیق می‌گیرد تا «a» همه‌چیز را نبلعد.
		 *
		 * @param string $token کلمهٔ نرمال‌شده.
		 * @param string $kw    کلیدواژهٔ نرمال‌شده.
		 * @return bool
		 */
		private static function token_matches( $token, $kw ) {
			if ( '' === $token || '' === $kw ) {
				return false;
			}
			if ( $token === $kw ) {
				return true;
			}

			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $kw, 'UTF-8' ) : strlen( $kw );
			if ( $len < 2 || 0 !== strpos( $token, $kw ) ) {
				return false;
			}

			$rest = substr( $token, strlen( $kw ) );
			if ( '' === $rest ) {
				return true;
			}

			// «mi11t» / «iphone13pro»: بلافاصله بعد از نام برند، شمارهٔ مدل.
			if ( preg_match( '/^\d/', $rest ) ) {
				return true;
			}

			// کلیدواژهٔ بلند (≥۴ نویسه) به‌عنوان پیشوند: redminote12، pocox3، galaxya52.
			return $len >= 4;
		}

		/**
		 * کلید ویژگی: نام ویژگی ووکامرس (ممکن است percent-encoded باشد) → کلید یکدست.
		 *
		 * «attribute_مدل»، «مدل»، «%d9%85%d8%af%d9%84» و «pa_model» همگی یک کلید می‌دهند.
		 *
		 * @param string $name نام ویژگی.
		 * @return string
		 */
		public static function attr_key( $name ) {
			$name = (string) $name;
			$name = preg_replace( '/^attribute_/i', '', $name );
			$name = rawurldecode( $name );
			$name = sanitize_title( $name );
			$name = rawurldecode( $name );
			return self::key( $name );
		}

		/**
		 * الگوی کاربر → الگوی معتبر PCRE (یا رشتهٔ خالی).
		 *
		 * @param string $pattern الگو.
		 * @return string
		 */
		public static function to_regex( $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' === $pattern ) {
				return '';
			}

			// الگوی کامل با جداکننده: /.../iu یا #...# یا ~...~ (آخرین جداکننده + پرچم‌ها)
			$first = substr( $pattern, 0, 1 );
			if ( in_array( $first, array( '/', '~', '#', '%', '!', '@' ), true ) ) {
				$pos = strrpos( $pattern, $first );
				if ( false !== $pos && $pos > 0 ) {
					$mods = substr( $pattern, $pos + 1 );
					if ( '' === $mods || preg_match( '/^[a-zA-Z]+$/', $mods ) ) {
						if ( false !== @preg_match( $pattern, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
							return $pattern;
						}
					}
				}
			}

			$body = str_replace( '/', '\/', $pattern );
			$rx   = '/(?:' . $body . ')/iu';

			return ( false !== @preg_match( $rx, '' ) ) ? $rx : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		/**
		 * آیا مقدار با این برند می‌خواند؟
		 *
		 * ترتیب بررسی: فهرست دستی (دقیق) ← regex ← کلیدواژه.
		 *
		 * @param string $value مقدار (نام مدل).
		 * @param array  $brand ردیف برند.
		 * @return bool
		 */
		public static function matches( $value, $brand ) {
			$norm = self::normalize( $value );
			if ( '' === $norm ) {
				return false;
			}

			$candidates = self::candidates( $value );

			// ۱) فهرست دستی — همیشه برنده است (کل مقدار یا یکی از تکه‌ها).
			foreach ( TCBV_Settings::lines( isset( $brand['exact'] ) ? $brand['exact'] : '' ) as $line ) {
				$needle = self::key( $line );
				if ( '' === $needle ) {
					continue;
				}
				foreach ( $candidates as $candidate ) {
					if ( self::key( $candidate ) === $needle ) {
						return true;
					}
				}
			}

			// ۲) الگوهای regex — روی کل مقدار و روی هر تکه (پس «A20/A30» هم می‌خواند).
			foreach ( TCBV_Settings::lines( isset( $brand['regex'] ) ? $brand['regex'] : '' ) as $line ) {
				$rx = self::to_regex( $line );
				if ( '' === $rx ) {
					continue;
				}
				if ( @preg_match( $rx, (string) $value ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					return true;
				}
				foreach ( $candidates as $candidate ) {
					if ( @preg_match( $rx, $candidate ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						return true;
					}
				}
			}

			// ۳) کلیدواژه‌ها — روی هر تکه جداگانه.
			$keywords = TCBV_Settings::lines( isset( $brand['keywords'] ) ? $brand['keywords'] : '' );
			foreach ( $candidates as $candidate ) {
				$tokens = self::tokens( $candidate );
				foreach ( $keywords as $line ) {
					$kw = self::normalize( $line );
					if ( '' === $kw ) {
						continue;
					}
					if ( false !== strpos( $kw, ' ' ) ) {
						// کلیدواژهٔ چندکلمه‌ای: جست‌وجوی عبارت.
						if ( false !== strpos( $candidate, $kw ) ) {
							return true;
						}
						continue;
					}
					if ( '*' === substr( $kw, -1 ) ) {
						// پیشوند: «redmi*» → redmini و redmi را می‌گیرد.
						$prefix = substr( $kw, 0, -1 );
						if ( '' === $prefix ) {
							continue;
						}
						foreach ( $tokens as $token ) {
							if ( 0 === strpos( $token, $prefix ) ) {
								return true;
							}
						}
						continue;
					}
					foreach ( $tokens as $token ) {
						if ( self::token_matches( $token, $kw ) ) {
							return true;
						}
					}
				}
			}

			return false;
		}

		/**
		 * دستهٔ یک مقدار + همهٔ برندهایی که با آن می‌خوانند (برای گزارش تضاد).
		 *
		 * @param string $value  مقدار.
		 * @param array  $brands فهرست برندهای فعال.
		 * @return array{id:string,hits:string[]}
		 */
		public static function classify( $value, $brands ) {
			$hits  = array();
			$texts = self::texts_for( $value );
			foreach ( $brands as $brand ) {
				if ( isset( $brand['enabled'] ) && empty( $brand['enabled'] ) ) {
					continue;
				}
				foreach ( $texts as $text ) {
					if ( self::matches( $text, $brand ) ) {
						$hits[] = (string) $brand['id'];
						break;
					}
				}
			}
			return array(
				'id'   => empty( $hits ) ? self::UNKNOWN : $hits[0],
				'hits' => $hits,
			);
		}

		/**
		 * گروه‌بندی یک فهرست مقدار.
		 *
		 * @param array      $values   مقادیر (نام مدل‌ها).
		 * @param array|null $settings تنظیمات.
		 * @return array فهرست گروه‌ها: id,label,color,icon,count,values,unknown
		 */
		public static function group( $values, $settings = null ) {
			$settings = is_array( $settings ) ? $settings : TCBV_Settings::get();
			$brands   = TCBV_Settings::active_brands( $settings );

			$buckets = array();
			foreach ( $brands as $brand ) {
				$buckets[ $brand['id'] ] = array();
			}
			$buckets[ self::UNKNOWN ] = array();

			foreach ( $values as $value ) {
				$result                            = self::classify( $value, $brands );
				$buckets[ $result['id'] ][]        = $value;
			}

			$sort  = isset( $settings['ui']['sort'] ) ? $settings['ui']['sort'] : 'asis';
			$brands = is_array( $brands ) ? $brands : array();
			$order = array();

			foreach ( $brands as $brand ) {
				if ( empty( $buckets[ $brand['id'] ] ) ) {
					continue;
				}
				$order[] = array(
					'id'      => (string) $brand['id'],
					'label'   => (string) $brand['label'],
					'color'   => isset( $brand['color'] ) ? (string) $brand['color'] : '',
					'icon'    => isset( $brand['icon'] ) ? (string) $brand['icon'] : '',
					'count'   => count( $buckets[ $brand['id'] ] ),
					'values'  => self::sort_values( $buckets[ $brand['id'] ], $sort ),
					'unknown' => false,
				);
			}

			$unknown = isset( $settings['unknown'] ) ? $settings['unknown'] : array();
			if ( ! empty( $buckets[ self::UNKNOWN ] ) ) {
				$row = array(
					'id'      => self::UNKNOWN,
					'label'   => isset( $unknown['label'] ) ? (string) $unknown['label'] : 'سایر مدل‌ها',
					'color'   => isset( $unknown['color'] ) ? (string) $unknown['color'] : '#94A3B8',
					'icon'    => '',
					'count'   => count( $buckets[ self::UNKNOWN ] ),
					'values'  => self::sort_values( $buckets[ self::UNKNOWN ], $sort ),
					'unknown' => true,
				);
				if ( isset( $unknown['position'] ) && 'first' === $unknown['position'] ) {
					array_unshift( $order, $row );
				} else {
					$order[] = $row;
				}
			}

			return $order;
		}

		/**
		 * مرتب‌سازی داخل گروه.
		 *
		 * @param array  $values مقادیر.
		 * @param string $mode   asis | asc | desc.
		 * @return array
		 */
		public static function sort_values( $values, $mode ) {
			if ( 'asc' === $mode || 'desc' === $mode ) {
				usort(
					$values,
					function ( $a, $b ) {
						return strnatcasecmp( self::normalize( $a ), self::normalize( $b ) );
					}
				);
				if ( 'desc' === $mode ) {
					$values = array_reverse( $values );
				}
			}
			return array_values( $values );
		}

		/**
		 * آیا این ویژگی باید بر اساس برند گروه‌بندی شود؟
		 *
		 * @param string     $attr     نام ویژگی.
		 * @param array      $values   مقادیر.
		 * @param array|null $settings تنظیمات.
		 * @return bool
		 */
		public static function should_group( $attr, $values, $settings = null ) {
			$settings = is_array( $settings ) ? $settings : TCBV_Settings::get();
			$key      = self::attr_key( $attr );

			// ویژگی رنگ با مسیر سواچ می‌رود.
			if ( self::is_color_attr( $attr, $settings ) ) {
				return false;
			}

			$listed  = self::in_list( $key, isset( $settings['group_attrs'] ) ? $settings['group_attrs'] : '' );
			$skipped = self::in_list( $key, isset( $settings['skip_attrs'] ) ? $settings['skip_attrs'] : '' );
			$scope   = isset( $settings['group_scope'] ) ? $settings['group_scope'] : 'auto';

			if ( 'list' === $scope ) {
				return apply_filters( 'tcbv_should_group', $listed, $attr, $values, $settings );
			}

			// حالت خودکار: فهرست صریح بر «وتو» اولویت دارد.
			if ( $listed ) {
				return apply_filters( 'tcbv_should_group', true, $attr, $values, $settings );
			}
			if ( $skipped ) {
				return apply_filters( 'tcbv_should_group', false, $attr, $values, $settings );
			}

			$min_brands  = isset( $settings['min_brands'] ) ? (int) $settings['min_brands'] : 2;
			$min_options = isset( $settings['min_options'] ) ? (int) $settings['min_options'] : 6;
			$groups      = self::group( $values, $settings );

			$branded = 0;
			foreach ( $groups as $group ) {
				if ( empty( $group['unknown'] ) ) {
					$branded++;
				}
			}

			$enough = ( $branded >= $min_brands && count( $values ) >= $min_options )
				|| ( $branded >= 1 && count( $values ) >= 25 && count( $values ) >= $min_options );

			return apply_filters( 'tcbv_should_group', $enough, $attr, $values, $settings );
		}

		/**
		 * آیا این ویژگی «رنگ» است (مسیر سواچ)؟
		 *
		 * @param string     $attr     نام ویژگی.
		 * @param array|null $settings تنظیمات.
		 * @return bool
		 */
		public static function is_color_attr( $attr, $settings = null ) {
			$settings = is_array( $settings ) ? $settings : TCBV_Settings::get();
			if ( empty( $settings['swatch']['enabled'] ) ) {
				return false;
			}
			$list = isset( $settings['swatch_attrs'] ) ? $settings['swatch_attrs'] : "رنگ\ncolor";
			$key  = self::attr_key( $attr );

			foreach ( TCBV_Settings::lines( $list ) as $line ) {
				if ( self::attr_key( $line ) === $key ) {
					return (bool) apply_filters( 'tcbv_is_color_attr', true, $attr, $settings );
				}
			}
			return (bool) apply_filters( 'tcbv_is_color_attr', false, $attr, $settings );
		}

		/**
		 * آیا کلید ویژگی در فهرست خطی هست؟
		 *
		 * @param string $key  کلید ویژگی.
		 * @param string $list متن چندخطی.
		 * @return bool
		 */
		private static function in_list( $key, $list ) {
			if ( '' === $key ) {
				return false;
			}
			foreach ( TCBV_Settings::lines( $list ) as $line ) {
				if ( self::attr_key( $line ) === $key ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * گزارش تضاد: مقادیری که با بیش از یک برند می‌خوانند.
		 *
		 * @param array      $values   مقادیر.
		 * @param array|null $settings تنظیمات.
		 * @return array
		 */
		public static function conflicts( $values, $settings = null ) {
			$settings = is_array( $settings ) ? $settings : TCBV_Settings::get();
			$brands   = TCBV_Settings::active_brands( $settings );
			$out      = array();

			foreach ( $values as $value ) {
				$result = self::classify( $value, $brands );
				if ( count( $result['hits'] ) > 1 ) {
					$out[] = array(
						'value' => $value,
						'hits'  => $result['hits'],
						'used'  => $result['id'],
					);
				}
			}

			return $out;
		}

		/**
		 * پیکربندی ارسالی به جاوااسکریپت.
		 *
		 * @param array|null $settings تنظیمات.
		 * @return array
		 */
		public static function js_config( $settings = null ) {
			$settings = is_array( $settings ) ? $settings : TCBV_Settings::get();
			$brands   = array();

			foreach ( TCBV_Settings::active_brands( $settings ) as $brand ) {
				$brands[] = array(
					'id'       => (string) $brand['id'],
					'label'    => (string) $brand['label'],
					'color'    => (string) $brand['color'],
					'icon'     => isset( $brand['icon'] ) ? (string) $brand['icon'] : '',
					'enabled'  => empty( $brand['enabled'] ) ? 0 : 1,
					'keywords' => TCBV_Settings::lines( isset( $brand['keywords'] ) ? $brand['keywords'] : '' ),
					'regex'    => TCBV_Settings::lines( isset( $brand['regex'] ) ? $brand['regex'] : '' ),
					'exact'    => TCBV_Settings::lines( isset( $brand['exact'] ) ? $brand['exact'] : '' ),
				);
			}

			$map = array();
			foreach ( TCBV_Settings::color_map( $settings ) as $name => $hex ) {
				$map[] = array( $name, $hex );
			}

			return array(
				'brands'      => $brands,
				'unknown'     => $settings['unknown'],
				'groupScope'  => $settings['group_scope'],
				'groupAttrs'  => TCBV_Settings::lines( $settings['group_attrs'] ),
				'skipAttrs'   => TCBV_Settings::lines( $settings['skip_attrs'] ),
				'swatchAttrs' => TCBV_Settings::lines( isset( $settings['swatch_attrs'] ) ? $settings['swatch_attrs'] : '' ),
				'minBrands'   => (int) $settings['min_brands'],
				'minOptions'  => (int) $settings['min_options'],
				'ui'          => $settings['ui'],
				'theme'       => $settings['theme'],
				'swatch'      => array(
					'enabled'   => empty( $settings['swatch']['enabled'] ) ? 0 : 1,
					'shape'     => $settings['swatch']['shape'],
					'size'      => (int) $settings['swatch']['size'],
					'showLabel' => empty( $settings['swatch']['show_label'] ) ? 0 : 1,
					'fallback'  => $settings['swatch']['fallback'],
				),
				'colorMap'    => $map,
				'debug'       => empty( $settings['advanced']['debug'] ) ? 0 : 1,
				'i18n'        => array(
					'search'      => $settings['ui']['search_placeholder'],
					'all'         => 'همه',
					'noResult'    => 'چیزی پیدا نشد',
					'clear'       => 'پاک کردن جستجو',
					'models'      => 'مدل',
					'outOfStock'  => 'ناموجود',
					'selected'    => 'انتخاب‌شده',
				),
			);
		}
	}
}
