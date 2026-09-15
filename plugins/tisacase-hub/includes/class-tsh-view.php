<?php
/**
 * کمک‌تابع‌های نمایش (آیکون، عدد، فرار کاراکتر).
 *
 * @package TisaCase_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TSH_View' ) ) {

	/**
	 * ابزارهای رندر که بین قالب‌ها و کلاس‌ها مشترک است.
	 */
	final class TSH_View {

		/**
		 * مجموعهٔ آیکون‌های خطی (۲۴×۲۴، currentColor).
		 *
		 * @return array<string,string>
		 */
		private static function icons() {
			static $icons = null;
			if ( null !== $icons ) {
				return $icons;
			}
			$icons = array(
				'grid'     => '<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/>',
				'trash'    => '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6"/>',
				'doc'      => '<path d="M6 3h8l4 4v14H6zM14 3v4h4M9 12h6M9 16h6"/>',
				'box'      => '<path d="M3 8l9-5 9 5v8l-9 5-9-5zM3 8l9 5 9-5M12 13v8"/>',
				'hash'     => '<path d="M9 4L7 20M17 4l-2 16M4 9h16M3 15h16"/>',
				'tag'      => '<path d="M12 3H4v8l9 9 8-8zM8 8h.01"/>',
				'bolt'     => '<path d="M13 3L5 14h5l-1 7 8-11h-5z"/>',
				'layers'   => '<path d="M12 3l9 5-9 5-9-5zM3 13l9 5 9-5"/>',
				'truck'    => '<path d="M3 7h10v9H3zM13 11h4l3 3v2h-7M7 19a2 2 0 100-4 2 2 0 000 4zM17 19a2 2 0 100-4 2 2 0 000 4z"/>',
				'send'     => '<path d="M21 4 3 11l6 2.5L11.5 20l3-4.5L19 17z"/><path d="M9 13.5 19 6"/>',
				'phone'    => '<path d="M7 3h4l1 4-2 1 3 6 4-1 1 2v4a1 1 0 01-1 1C10 24 2 16 2 5a1 1 0 011-1z"/>',
				'gear'     => '<path d="M12 15a3 3 0 100-6 3 3 0 000 6zM4 12H2M6 5l-1-2M18 19l1 2M12 4V2M20 12h2M6 19l-1 2M18 5l1-2"/>',
				'search'   => '<path d="M11 19a8 8 0 100-16 8 8 0 000 16zM21 21l-4.5-4.5"/>',
				'refresh'  => '<path d="M20 12a8 8 0 11-3-6.2M20 4v5h-5"/>',
				'star'     => '<path d="M12 3l3 6.5 7 .9-5 4.9 1.2 7-6.2-3.4L5.8 22 7 15.4l-5-5 7-.9z"/>',
				'shield'   => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
				'plug'     => '<path d="M9 3v6M15 3v6M6 9h12v3a6 6 0 01-12 0zM12 18v3"/>',
				'warning'  => '<path d="M12 4l9 16H3zM12 10v5M12 18h.01"/>',
				'chart'    => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
				'scan'     => '<path d="M4 8V4h4M16 4h4v4M20 16v4h-4M8 20H4v-4M3 12h18"/>',
				'send'     => '<path d="M21 3L3 11l7 3 3 7z"/>',
				'archive'  => '<path d="M3 5h18v4H3zM5 9v11h14V9M10 13h4"/>',
				'heart'    => '<path d="M12 20s-8-4.6-8-10a4.5 4.5 0 018-2.9A4.5 4.5 0 0120 10c0 5.4-8 10-8 10z"/>',
				'lock'     => '<path d="M6 11V8a6 6 0 0112 0v3M5 11h14v10H5zM12 15v3"/>',
				'external' => '<path d="M14 4h6v6M20 4l-9 9M18 14v6H4V6h6"/>',
				'upload'   => '<path d="M12 16V4M7 9l5-5 5 5M4 17v3h16v-3"/>',
				'pin'      => '<path d="M12 21v-7M8 3h8l-1 6 3 3H6l3-3z"/>',
			);
			return $icons;
		}

		/**
		 * خروجی SVG آیکون.
		 *
		 * @param string $name کلید آیکون.
		 * @param string $class کلاس اضافه.
		 * @return string HTML امن.
		 */
		public static function icon( $name, $class = '' ) {
			$set = self::icons();
			if ( ! isset( $set[ $name ] ) ) {
				$name = 'grid';
			}
			$out = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"';
			if ( $class ) {
				$out .= ' class="' . esc_attr( $class ) . '"';
			}
			$out .= '>' . $set[ $name ] . '</svg>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return $out;
		}

		/**
		 * آیکون به‌صورت data URI (برای منوی وردپرس).
		 *
		 * @param string $name نام آیکون.
		 * @param string $color رنگ خط.
		 * @return string
		 */
		public static function icon_data_uri( $name, $color = '#a7aaad' ) {
			$set = self::icons();
			$body = isset( $set[ $name ] ) ? $set[ $name ] : $set['grid'];
			$svg  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="' . $color . '" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
			return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		/**
		 * قالب‌بندی عدد با جداکنندهٔ هزارگان. قاعدهٔ طراحی: یک الگوی رقم در هر صفحه.
		 *
		 * @param mixed $n عدد.
		 * @return string
		 */
		public static function num( $n ) {
			if ( ! is_numeric( $n ) ) {
				return '—';
			}
			$out = number_format_i18n( (float) $n );
			return (string) apply_filters( 'tisacase_hub_number', $out, $n );
		}

		/**
		 * عدد بزرگ‌تر از حد: 999+ .
		 *
		 * @param mixed $n      عدد.
		 * @param int   $limit   سقف نمایش.
		 * @return string
		 */
		public static function num_capped( $n, $limit = 9999 ) {
			if ( ! is_numeric( $n ) ) {
				return '—';
			}
			if ( (int) $n > $limit ) {
				return '+' . self::num( $limit );
			}
			return self::num( (int) $n );
		}

		/**
		 * نمایش «هیچ» استاندارد طبق قرارداد طراحی.
		 *
		 * @return string
		 */
		public static function none() {
			return '<span class="tisa-num" style="color:var(--tisa-muted-2,#9AA3A9)">—</span>';
		}

		/**
		 * مسیر کوتاه‌شدهٔ فایل برای گزارش سلامت.
		 *
		 * @param string $path مسیر مطلق.
		 * @return string
		 */
		public static function short_path( $path ) {
			$path = str_replace( array( WP_PLUGIN_DIR . '/', ABSPATH ), array( 'wp-content/plugins/', '' ), (string) $path );
			return ltrim( str_replace( '\\', '/', $path ), '/' );
		}

		/**
		 * شمارش با فارسی‌سازیِ نرم (فقط رقم‌های لاتین؛ با .tisa-num ست می‌شود).
		 *
		 * @param int $n تعداد.
		 * @return string
		 */
		public static function plural_count( $n ) {
			return '<span class="tisa-num">' . esc_html( self::num( (int) $n ) ) . '</span>';
		}
	}
}
