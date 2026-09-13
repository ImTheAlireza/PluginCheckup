<?php
/**
 * منو، صفحه‌ها، اقدام‌ها (فعال/غیرفعال/مخفی) و AJAX.
 *
 * @package TisaCase_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TSH_Admin' ) ) {

	/**
	 * لایهٔ مدیریتی هاب.
	 */
	final class TSH_Admin {

		/** @var string capability صفحه‌ها. */
		private static $cap = 'manage_woocommerce';

		/** @var string هوک صفحهٔ اصلی. */
		private static $main = '';

		/**
		 * اتصال هوک‌ها.
		 *
		 * @return void
		 */
		public static function init() {
			self::$cap = (string) apply_filters( 'tisacase_hub_cap', 'manage_woocommerce' );

			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 5 );
			add_action( 'admin_print_styles', array( __CLASS__, 'hide_scattered' ), 5 );

			// نگاشت اسکرین‌ها به فهرست افزونه‌ها وابسته است؛ با هر تغییر کش می‌رود.
			add_action( 'activated_plugin', array( 'TSH_UI', 'flush' ) );
			add_action( 'deactivated_plugin', array( 'TSH_UI', 'flush' ) );
			add_action( 'switch_theme', array( 'TSH_UI', 'flush' ) );
			add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
			add_action( 'admin_post_tisacase_hub_action', array( __CLASS__, 'handle_action' ) );
			add_action( 'admin_post_tisacase_hub_save', array( __CLASS__, 'handle_save' ) );
			add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 61 );

			add_action( 'wp_ajax_tsh_pin', array( __CLASS__, 'ajax_pin' ) );
			add_action( 'wp_ajax_tsh_prefs', array( __CLASS__, 'ajax_prefs' ) );
		}

		/**
		 * ثبت منو.
		 *
		 * @return void
		 */
		public static function menu() {
			$position = self::menu_position();
			$icon     = TSH_View::icon_data_uri( 'grid' );

			self::$main = ( '' === $position )
				? add_menu_page( __( 'اختصاصی تیساکیس', 'tisacase-hub' ), __( 'اختصاصی تیساکیس', 'tisacase-hub' ), self::$cap, TSH_SLUG, array( __CLASS__, 'render_hub' ), $icon )
				: add_menu_page( __( 'اختصاصی تیساکیس', 'tisacase-hub' ), __( 'اختصاصی تیساکیس', 'tisacase-hub' ), self::$cap, TSH_SLUG, array( __CLASS__, 'render_hub' ), $icon, $position );

			add_submenu_page( TSH_SLUG, __( 'ابزارها', 'tisacase-hub' ), __( 'ابزارها', 'tisacase-hub' ), self::$cap, TSH_SLUG, array( __CLASS__, 'render_hub' ) );
			add_submenu_page( TSH_SLUG, __( 'ظاهر و تنظیمات', 'tisacase-hub' ), __( 'ظاهر و تنظیمات', 'tisacase-hub' ), self::$cap, TSH_SLUG . '-settings', array( __CLASS__, 'render_settings' ) );

			// عمداً هیچ ورودی‌ای داخل منوی ووکامرس ثبت نمی‌شود: هاب یک گزینهٔ مستقل در
			// نوار کنار است. (جایگاهش از تنظیمات قابل تغییر است.)
		}

		/**
		 * جایگاه‌های آماده در نوار کنار (عددِ جایگاه وردپرس + توضیحش).
		 *
		 * @return array<string,array{label:string,pos:string}>
		 */
		public static function positions() {
			return array(
				'top'         => array(
					'label' => __( 'بالا — درست بعد از داشبورد', 'tisacase-hub' ),
					'pos'   => '2.5',
				),
				'before_wc'   => array(
					'label' => __( 'بالای ووکامرس', 'tisacase-hub' ),
					'pos'   => '55.4',
				),
				'after_products' => array(
					'label' => __( 'بعد از محصولات (جای همیشگی این ابزارها)', 'tisacase-hub' ),
					'pos'   => '30.5',
				),
				'before_tools' => array(
					'label' => __( 'نزدیک پایین — قبل از ابزارها', 'tisacase-hub' ),
					'pos'   => '74.5',
				),
				'default'     => array(
					'label' => __( 'پیش‌فرض وردپرس (انتهای فهرست)', 'tisacase-hub' ),
					'pos'   => '',
				),
				'custom'      => array(
					'label' => __( 'عدد دلخواه', 'tisacase-hub' ),
					'pos'   => 'custom',
				),
			);
		}

		/**
		 * عدد جایگاه نهایی.
		 *
		 * @return string رشتهٔ خالی یعنی «به وردپرس بسپار».
		 */
		public static function menu_position() {
			$key  = (string) TSH_UI::setting( 'menu_position', 'top' );
			$list = self::positions();
			if ( ! isset( $list[ $key ] ) ) {
				$key = 'top';
			}
			$pos = $list[ $key ]['pos'];
			if ( 'custom' === $pos ) {
				$raw = (string) TSH_UI::setting( 'menu_position_custom', '' );
				return preg_match( '/^\d{1,2}(\.\d{1,2})?$/', $raw ) ? $raw : '';
			}
			return (string) apply_filters( 'tisacase_hub_menu_position', $pos, $key );
		}
		/**
		 * مخفی‌کردن ورودی‌های پراکندهٔ افزونه‌ها از منوی وردپرس.
		 *
		 * این کار عمداً فقط ظاهری است: هیچ درای از $menu/$submenu کم نمی‌شود.
		 * وردپرس مسیرِ `admin.php?page=…` را از همان آرایهٔ منو پیدا می‌کند، پس
		 * remove_submenu_page() صفحه را می‌کُشد و URL مستقیم با خطای «شما اجازهٔ
		 * دسترسی به این برگه را ندارید» رد می‌شود. اینجا آیتم سر جایش می‌ماند و
		 * تنها با CSS دیده نمی‌شود؛ با CSS-بلاک‌شدن هم صفحه باز است.
		 *
		 * @return void
		 */
		public static function hide_scattered() {
			if ( is_network_admin() || is_user_admin() || ! TSH_UI::setting( 'hide_scattered' ) ) {
				return;
			}
			$css = self::scattered_css();
			if ( '' === $css ) {
				return;
			}
			echo '<style id="tisa-hide-scattered">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		/**
		 * قاعدهٔ CSS برای هر آیتم منویی که افزونه‌های ما ثبت کرده‌اند.
		 *
		 * @return string
		 */
		private static function scattered_css() {
			$out = array();
			foreach ( TSH_Registry::menu_entries() as $entry ) {
				$slug = (string) $entry['slug'];
				if ( '' === $slug || ! preg_match( '/^[A-Za-z0-9_.\-\/?&=]+$/', $slug ) ) {
					continue;
				}
				if ( 'top' === $entry['parent'] ) {
					$out[] = '#adminmenu #toplevel_page_' . $slug . '{display:none!important}';
					continue;
				}
				// قاعدهٔ اول li را می‌کَنَد؛ دومی فقط لینک را (مرورگری که :has ندارد).
				// جدا نوشته می‌شوند، چون یک سلکتور نامعتبر کل rule را باطل می‌کند.
				$out[] = '#adminmenu .wp-submenu li:has(> a[href*="' . $slug . '"]){display:none!important}';
				$out[] = '#adminmenu .wp-submenu li> a[href*="' . $slug . '"]{display:none!important}';
			}
			return implode( '', $out );
		}

		/**
		 * میان‌بُر در نوار بالا.
		 *
		 * @param WP_Admin_Bar $bar نوار.
		 * @return void
		 */
		public static function admin_bar( $bar ) {
			if ( ! is_admin_bar_showing() || ! current_user_can( self::$cap ) ) {
				return;
			}
			$bar->add_node(
				array(
					'id'    => 'tisacase-hub',
					'title' => __( 'تیساکیس', 'tisacase-hub' ),
					'href'  => admin_url( 'admin.php?page=' . TSH_SLUG ),
					'meta'  => array( 'title' => __( 'اختصاصی تیساکیس — همهٔ ابزارها', 'tisacase-hub' ) ),
				)
			);
			foreach ( array_slice( array_keys( TSH_Registry::items() ), 0, 6 ) as $key ) {
				$items = TSH_Registry::items();
				if ( empty( $items[ $key ]['pages'][0]['path'] ) ) {
					continue;
				}
				$bar->add_node(
					array(
						'id'     => 'tisacase-hub-' . $key,
						'parent' => 'tisacase-hub',
						'title'  => $items[ $key ]['title'],
						'href'   => admin_url( $items[ $key ]['pages'][0]['path'] ),
					)
				);
			}
		}

		/**
		 * تنظیمات.
		 *
		 * @return void
		 */
		public static function register_settings() {
			register_setting(
				'tisacase_hub_group',
				TSH_OPTION,
				array(
					'type'              => 'array',
					'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
					'default'           => TSH_UI::defaults(),
				)
			);
		}

		/**
		 * پاک‌سازی ورودی تنظیمات.
		 *
		 * @param mixed $input ورودی خام.
		 * @return array
		 */
		public static function sanitize_settings( $input ) {
			$in       = is_array( $input ) ? $input : array();
			$defaults = TSH_UI::defaults();
			$out      = array();

			$accent = isset( $in['accent'] ) ? trim( (string) $in['accent'] ) : $defaults['accent'];
			if ( ! preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $accent ) ) {
				$accent = $defaults['accent'];
			}
			$out['accent'] = TSH_UI::normalize_hex( $accent );

			foreach ( array( 'compact', 'hide_scattered', 'style_plugins', 'style_product_screens' ) as $bool ) {
				$out[ $bool ] = empty( $in[ $bool ] ) ? 0 : 1;
			}

			// این کلید در فرم نیست (از روی کارت‌ها عوض می‌شود) → دست نخورد.
			$pos_keys = array_keys( self::positions() );
			$pos      = isset( $in['menu_position'] ) ? sanitize_key( $in['menu_position'] ) : 'top';
			$out['menu_position'] = in_array( $pos, $pos_keys, true ) ? $pos : 'top';
			$custom               = isset( $in['menu_position_custom'] ) ? trim( (string) $in['menu_position_custom'] ) : '';
			$out['menu_position_custom'] = preg_match( '/^\d{1,2}(\.\d{1,2})?$/', $custom ) ? $custom : '';

			$current = TSH_UI::settings();
			$hidden  = isset( $in['hidden'] ) ? (array) $in['hidden'] : (array) ( isset( $current['hidden'] ) ? $current['hidden'] : array() );
			$hidden  = array_values( array_unique( array_map( 'sanitize_key', array_filter( $hidden ) ) ) );
			$known   = array_keys( TSH_Registry::items() );
			$out['hidden'] = array_values( array_intersect( $hidden, $known ) );

			TSH_UI::flush();
			return apply_filters( 'tisacase_hub_sanitize_settings', $out, $in );
		}

		/* * * * * * * * * * * رندر صفحه‌ها * * * * * * * * * * * */

		/**
		 * صفحهٔ لانچر.
		 *
		 * @return void
		 */
		public static function render_hub() {
			if ( ! current_user_can( self::$cap ) ) {
				wp_die( esc_html__( 'دسترسی ندارید.', 'tisacase-hub' ) );
			}
			self::view( 'hub.php', self::hub_data() );
		}

		/**
		 * دادهٔ لازم برای لانچر.
		 *
		 * @param bool $refresh شمارنده‌ها را دوباره حساب کند.
		 * @return array
		 */
		private static function hub_data() {
			$data = TSH_Registry::grouped();
			return array(
				'groups'   => $data['groups'],
				'all'      => $data['all'],
				'pins'     => self::pins(),
				'settings' => TSH_UI::settings(),
				'hidden'   => (array) TSH_UI::setting( 'hidden', array() ),
			);
		}

		/**
		 * صفحهٔ تنظیمات.
		 *
		 * @return void
		 */
		public static function render_settings() {
			if ( ! current_user_can( self::$cap ) ) {
				wp_die( esc_html__( 'دسترسی ندارید.', 'tisacase-hub' ) );
			}
			$all    = TSH_Registry::grouped()['all'];
			$hidden = (array) TSH_UI::setting( 'hidden', array() );
			self::view(
				'settings.php',
				array(
					'settings' => TSH_UI::settings(),
					'font'     => TSH_UI::font_status(),
					'hidden'   => $hidden,
					'items'    => $all,
					'env'      => self::env(),
				)
			);
		}

		/**
		 * بارگذاری قالب.
		 *
		 * @param string $file نام فایل قالب.
		 * @param array  $data متغیرهای قالب.
		 * @return void
		 */
		private static function view( $file, $data ) {
			$path = TSH_DIR . 'templates/' . $file;
			if ( ! file_exists( $path ) ) {
				echo '<div class="wrap"><p>' . esc_html__( 'قالب پیدا نشد.', 'tisacase-hub' ) . '</p></div>';
				return;
			}
			extract( $data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			include $path;
		}

		/* * * * * * * * * * * اقدام‌ها * * * * * * * * * * * */

		/**
		 * ذخیرهٔ تنظیمات.
		 *
		 * عمداً به‌جای options.php: آن مسیر manage_options می‌خواهد و shop manager
		 * را رد می‌کند، در حالی که این تنظیمات فقط ظاهر را عوض می‌کنند.
		 *
		 * @return void
		 */
		public static function handle_save() {
			check_admin_referer( 'tsh_save' );
			if ( ! current_user_can( self::$cap ) ) {
				wp_die( esc_html__( 'دسترسی ندارید.', 'tisacase-hub' ) );
			}
			$raw = isset( $_POST[ TSH_OPTION ] ) ? wp_unslash( $_POST[ TSH_OPTION ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$in  = is_array( $raw ) ? $raw : array();

			// جعبه‌های تیک‌دار نیستم؛ نبودنشان یعنی خاموش.
			foreach ( array( 'compact', 'hide_scattered', 'style_plugins', 'style_product_screens' ) as $flag ) {
				$in[ $flag ] = isset( $in[ $flag ] ) ? 1 : 0;
			}

			$clean = self::sanitize_settings( $in );
			update_option( TSH_OPTION, $clean );
			TSH_UI::flush();

			wp_safe_redirect( add_query_arg( 'tsh_msg', 'saved', admin_url( 'admin.php?page=' . TSH_SLUG . '-settings' ) ) );
			exit;
		}

		/**
		 * فعال/غیرفعال کردن، مخفی کردن کارت، تازه‌سازی گزارش.
		 *
		 * @return void
		 */
		public static function handle_action() {
			$task = isset( $_GET['task'] ) ? sanitize_key( wp_unslash( $_GET['task'] ) ) : '';
			$key  = isset( $_GET['item'] ) ? sanitize_key( wp_unslash( $_GET['item'] ) ) : '';

			check_admin_referer( 'tsh_action' );

			$back = admin_url( 'admin.php?page=' . TSH_SLUG );
			$ref  = wp_get_referer();
			if ( ! $ref ) {
				$ref = wp_get_raw_referer();
			}
			if ( $ref ) {
				$checked = wp_validate_redirect( $ref, '' );
				if ( $checked ) {
					$back = $checked;
				}
			}
			$back = remove_query_arg( array( 'tsh_msg', 'tsh_err', 'tsh_item', 'refresh', '_wpnonce', 'action', 'task', 'item' ), $back );
			if ( false === strpos( $back, 'page=' ) ) {
				$back = admin_url( 'admin.php?page=' . TSH_SLUG );
			}


			$items = TSH_Registry::items();
			if ( ! isset( $items[ $key ] ) ) {
				wp_safe_redirect( add_query_arg( 'tsh_msg', 'bad', $back ) );
				exit;
			}
			$item = $items[ $key ];

			if ( 'hide' === $task || 'unhide' === $task ) {
				if ( ! current_user_can( self::$cap ) ) {
					wp_die( esc_html__( 'دسترسی ندارید.', 'tisacase-hub' ) );
				}
				$hidden = (array) TSH_UI::setting( 'hidden', array() );
				$hidden = array_values( array_unique( array_map( 'sanitize_key', $hidden ) ) );
				if ( 'hide' === $task ) {
					$hidden[] = $key;
				} else {
					$hidden = array_values( array_diff( $hidden, array( $key ) ) );
				}
				$saved          = TSH_UI::settings();
				$saved['hidden'] = $hidden;
				update_option( TSH_OPTION, $saved );
				TSH_UI::flush();
				wp_safe_redirect( add_query_arg( 'tsh_msg', 'hidden', $back ) );
				exit;
			}

			if ( 'activate' === $task || 'deactivate' === $task ) {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					wp_die( esc_html__( 'برای فعال/غیرفعال کردن افزونه اجازه ندارید.', 'tisacase-hub' ) );
				}
				if ( empty( $item['dir'] ) ) {
					wp_safe_redirect( add_query_arg( 'tsh_msg', 'bad', $back ) );
					exit;
				}
				$basename = TSH_Registry::basename_for( $item['dir'] );
				if ( ! $basename || ! file_exists( WP_PLUGIN_DIR . '/' . $basename ) ) {
					wp_safe_redirect( add_query_arg( array( 'tsh_msg' => 'notfound', 'tsh_err' => rawurlencode( (string) $item['dir'] ) ), $back ) );
					exit;
				}
				if ( 'deactivate' === $task ) {
					deactivate_plugins( array( $basename ), false, is_multisite() && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $basename ) );
					$msg = 'deactivated';
				} else {
					$result = activate_plugin( $basename );
					if ( is_wp_error( $result ) ) {
						wp_safe_redirect(
							add_query_arg(
								array(
									'tsh_msg' => 'failed',
									'tsh_err' => rawurlencode( $result->get_error_message() ),
								),
								$back
							)
						);
						exit;
					}
					$msg = 'activated';
				}
				wp_safe_redirect( add_query_arg( array( 'tsh_msg' => $msg, 'tsh_item' => $key ), $back ) );
				exit;
			}

			wp_safe_redirect( $back );
			exit;
		}

		/* * * * * * * * * * * AJAX * * * * * * * * * * * */

		/**
		 * سنجاق (در متای کاربر).
		 *
		 * @return void
		 */
		public static function ajax_pin() {
			check_ajax_referer( 'tsh_hub', 'nonce' );
			if ( ! is_user_logged_in() ) {
				wp_send_json_error( array( 'msg' => 'auth' ), 401 );
			}
			$key  = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
			$on   = ! empty( $_POST['on'] );
			if ( ! $key ) {
				wp_send_json_error( array( 'msg' => 'bad key' ), 400 );
			}
			$pins = self::pins();
			if ( $on ) {
				$pins[] = $key;
			} else {
				$pins = array_values( array_diff( $pins, array( $key ) ) );
			}
			$pins = array_values( array_unique( array_map( 'sanitize_key', $pins ) ) );
			update_user_meta( get_current_user_id(), TSH_META_PINS, $pins );
			wp_send_json_success( array( 'pins' => $pins ) );
		}

		/**
		 * ذخیرهٔ سریع یک تنظیم (رنگ برند / چگالی / شمارنده‌ها).
		 *
		 * @return void
		 */
		public static function ajax_prefs() {
			check_ajax_referer( 'tsh_hub', 'nonce' );
			if ( ! current_user_can( self::$cap ) ) {
				wp_send_json_error( array( 'msg' => 'cap' ), 403 );
			}
			$allow = array( 'accent', 'compact' );
			$saved = TSH_UI::settings();
			$done  = array();
			foreach ( $allow as $k ) {
				if ( ! isset( $_POST[ $k ] ) ) {
					continue;
				}
				$v = wp_unslash( $_POST[ $k ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( 'accent' === $k ) {
					if ( ! TSH_UI::is_hex( $v ) ) {
						continue;
					}
					$saved[ $k ] = TSH_UI::normalize_hex( $v );
				} elseif ( 'compact' === $k ) {
					$saved[ $k ] = ( $v === '0' || $v === 'false' || '' === $v ) ? 0 : 1;
				} else {
					$saved[ $k ] = empty( $v ) || '0' === $v ? 0 : 1;
				}
				$done[] = $k;
			}
			if ( $done ) {
				update_option( TSH_OPTION, $saved );
				TSH_UI::flush();
			}
			wp_send_json_success( array( 'saved' => $done, 'vars' => TSH_UI::vars() ) );
		}

		/* * * * * * * * * * * ابزارها * * * * * * * * * * * */

		/**
		 * سنجاق‌های کاربر جاری.
		 *
		 * @return array<int,string>
		 */
		public static function pins() {
			$pins = get_user_meta( get_current_user_id(), TSH_META_PINS, true );
			return is_array( $pins ) ? array_values( array_map( 'sanitize_key', $pins ) ) : array();
		}

		/**
		 * اطلاعات محیط برای نوار پایین.
		 *
		 * @return array<string,string>
		 */
		public static function env() {
			global $wp_version;
			$wc  = defined( 'WC_VERSION' ) ? WC_VERSION : '';
			$hpos = '—';
			if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) && function_exists( 'wc_get_option' ) ) {
				$cpt = get_option( 'woocommerce_custom_orders_table_enabled' );
				$hpos = 'yes' === $cpt ? __( 'روشن', 'tisacase-hub' ) : ( 'no' === $cpt ? __( 'خاموش', 'tisacase-hub' ) : '—' );
			} elseif ( function_exists( 'wc_get_option' ) ) {
				$cpt  = get_option( 'woocommerce_custom_orders_table_enabled' );
				$hpos = 'yes' === $cpt ? __( 'روشن', 'tisacase-hub' ) : __( 'خاموش', 'tisacase-hub' );
			}
			$font = TSH_UI::font_status();
			return array(
				'wp'      => (string) $wp_version,
				'php'     => PHP_VERSION,
				'wc'      => $wc ? (string) $wc : __( 'فعال نیست', 'tisacase-hub' ),
				'hpos'    => $hpos,
				'locale'  => (string) get_locale(),
				'rtl'     => is_rtl() ? 'RTL' : 'LTR',
				'memory'  => function_exists( 'size_format' ) ? size_format( (int) memory_get_usage( true ) ) : '',
				'fonts'   => $font['count'] ? sprintf( /* translators: %d: count */ __( '%d فایل قلم محلی', 'tisacase-hub' ), $font['count'] ) : __( 'قلم سیستم', 'tisacase-hub' ),
				'theme'   => (string) ( wp_get_theme() ? wp_get_theme()->get( 'Name' ) : '' ),
				'items'   => (string) count( TSH_Registry::items() ),
			);
		}

		/**
		 * پیام بعد از اقدام.
		 *
		 * @return void
		 */
		public static function admin_notice() {
			if ( empty( $_GET['tsh_msg'] ) || ! TSH_UI::is_hub_screen() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
			}
			$msg = sanitize_key( wp_unslash( $_GET['tsh_msg'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$err = isset( $_GET['tsh_err'] ) ? sanitize_text_field( wp_unslash( $_GET['tsh_err'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$texts = array(
				'activated'   => array( 'success', __( 'افزونه فعال شد.', 'tisacase-hub' ) ),
				'deactivated' => array( 'success', __( 'افزونه غیرفعال شد.', 'tisacase-hub' ) ),
				'hidden'      => array( 'info', sprintf( /* translators: %s: settings url */ __( 'ردیف مخفی شد. %sبازگردانی%s', 'tisacase-hub' ), '<a href="' . esc_url( admin_url( 'admin.php?page=' . TSH_SLUG . '-settings' ) ) . '">', '</a>' ) ),
				'saved'       => array( 'success', __( 'تنظیمات ذخیره شد و روی همهٔ صفحه‌ها اعمال می‌شود.', 'tisacase-hub' ) ),
				'notfound'    => array( 'error', sprintf( /* translators: %s: dir */ __( 'پوشهٔ افزونه (%s) روی این سرور نیست.', 'tisacase-hub' ), $err ) ),
				'failed'      => array( 'error', sprintf( /* translators: %s: error */ __( 'فعال‌سازی نشد: %s', 'tisacase-hub' ), $err ) ),
				'bad'         => array( 'error', __( 'اقدام نامعتبر بود.', 'tisacase-hub' ) ),
			);
			if ( ! isset( $texts[ $msg ] ) ) {
				return;
			}
			list( $type, $text ) = $texts[ $msg ];
			$cls = 'info' === $type ? 'info' : $type;
			printf(
				'<div class="notice notice-%1$s is-dismissible" style="margin-top:12px"><p>%2$s</p></div>',
				esc_attr( $cls ),
				wp_kses(
					$text,
					array(
						'a'      => array( 'href' => array() ),
						'b'      => array(),
						'code'   => array(),
						'span'   => array( 'class' => array() ),
					)
				)
			);
		}

		/**
		 * پیش‌فرض‌ها هنگام فعال‌سازی.
		 *
		 * @return void
		 */
		public static function activate() {
			if ( false === get_option( TSH_OPTION, false ) ) {
				add_option( TSH_OPTION, TSH_UI::defaults() );
			}
			delete_transient( 'tsh_health' );
		}

		/**
		 * پاک‌کاری هنگام غیرفعال کردن (داده‌ها می‌مانند؛ فقط کش).
		 *
		 * @return void
		 */
		public static function deactivate() {
			delete_transient( 'tsh_health' );
		}
	}

	// پیام‌ها بعد از redirect — روی همهٔ صفحه‌های هاب.
	add_action( 'admin_notices', array( 'TSH_Admin', 'admin_notice' ) );
}
