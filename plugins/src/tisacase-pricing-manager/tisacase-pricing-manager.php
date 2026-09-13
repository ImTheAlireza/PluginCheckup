<?php
/**
 * Plugin Name: TisaCase Pricing Manager
 * Description: مدیریت سبک قیمت‌گذاری داینامیک ووکامرس برای محصولات، دسته‌بندی‌ها و کل فروشگاه؛ با حفظ قیمت همکاری و محصولات دارای فروش ویژه واقعی.
 * Version: 1.1.0
 * Author: Shayan Zakizadeh
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * Text Domain: tisacase-pricing-manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Pricing_Manager' ) ) {

	final class TisaCase_Pricing_Manager {

		const VERSION         = '1.0.1';
		const OPTION          = 'tisacase_pricing_manager_rules_v1';
		const CACHE_VERSION   = 'tisacase_pricing_manager_cache_v1';
		const PAGE_SLUG       = 'tisacase-pricing-manager';
		const PRIORITY        = 99999;
		const WHOLESALE_ROLE  = 'tisacase_partner';
		const WHOLESALE_CAP   = 'tisacase_view_wholesale_prices';
		const WHOLESALE_META  = '_tisacase_wholesale_price';

		private static $settings_cache = null;
		private static $rule_cache     = array();
		private static $category_ids   = array();
		private static $wholesale      = array();

		public static function bootstrap() {
			add_action( 'plugins_loaded', array( __CLASS__, 'init' ), 20 );
		}

		public static function activate() {
			if ( false === get_option( self::OPTION, false ) ) {
				add_option( self::OPTION, self::defaults(), '', false );
			}

			if ( false === get_option( self::CACHE_VERSION, false ) ) {
				add_option( self::CACHE_VERSION, 1, '', false );
			}
		}

		private static function defaults() {
			return array(
				'global'     => array(
					'enabled'  => 0,
					'increase' => 10,
					'sale'     => 10,
				),
				'products'   => array(),
				'categories' => array(),
			);
		}

		public static function init() {
			if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Product' ) ) {
				add_action( 'admin_notices', array( __CLASS__, 'woocommerce_missing_notice' ) );
				return;
			}

			// Admin UI.
			add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
			add_action( 'admin_post_tisacase_pm_save', array( __CLASS__, 'save_settings' ) );
			add_action( 'admin_post_tisacase_pm_sync_all', array( __CLASS__, 'sync_all_defaults' ) );
			add_action( 'wp_ajax_tisacase_pm_search_products', array( __CLASS__, 'ajax_search_products' ) );
			add_action( 'wp_ajax_tisacase_pm_search_categories', array( __CLASS__, 'ajax_search_categories' ) );

			// Product getters.
			add_filter( 'woocommerce_product_get_regular_price', array( __CLASS__, 'filter_regular_price' ), self::PRIORITY, 2 );
			add_filter( 'woocommerce_product_variation_get_regular_price', array( __CLASS__, 'filter_regular_price' ), self::PRIORITY, 2 );
			add_filter( 'woocommerce_product_get_sale_price', array( __CLASS__, 'filter_sale_price' ), self::PRIORITY, 2 );
			add_filter( 'woocommerce_product_variation_get_sale_price', array( __CLASS__, 'filter_sale_price' ), self::PRIORITY, 2 );
			add_filter( 'woocommerce_product_get_price', array( __CLASS__, 'filter_active_price' ), self::PRIORITY, 2 );
			add_filter( 'woocommerce_product_variation_get_price', array( __CLASS__, 'filter_active_price' ), self::PRIORITY, 2 );

			// Variable product price arrays/caching.
			add_filter( 'woocommerce_variation_prices_regular_price', array( __CLASS__, 'variation_regular_price' ), self::PRIORITY, 3 );
			add_filter( 'woocommerce_variation_prices_sale_price', array( __CLASS__, 'variation_sale_price' ), self::PRIORITY, 3 );
			add_filter( 'woocommerce_variation_prices_price', array( __CLASS__, 'variation_active_price' ), self::PRIORITY, 3 );
			add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'variation_hash' ), self::PRIORITY, 3 );
			add_filter( 'woocommerce_product_is_on_sale', array( __CLASS__, 'filter_is_on_sale' ), self::PRIORITY, 2 );

			// Personalized wholesale pages should never be shared by a public page cache.
			add_action( 'template_redirect', array( __CLASS__, 'protect_partner_cache' ), 1 );
		}

		public static function woocommerce_missing_notice() {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p><strong>TisaCase Pricing Manager:</strong> برای اجرا به WooCommerce نیاز دارد.</p></div>';
			}
		}

		/* =========================================================
		 * Settings / rules
		 * =======================================================*/

		private static function percent( $value, $max = 500 ) {
			$value = is_numeric( $value ) ? (float) $value : 0;
			return max( 0, min( $max, $value ) );
		}

		private static function normalize_rule( $rule ) {
			return array(
				'enabled'  => ! empty( $rule['enabled'] ) ? 1 : 0,
				'increase' => self::percent( $rule['increase'] ?? 0, 500 ),
				'sale'     => self::percent( $rule['sale'] ?? 0, 99.9 ),
			);
		}

		private static function settings() {
			if ( null !== self::$settings_cache ) {
				return self::$settings_cache;
			}

			$raw      = get_option( self::OPTION, array() );
			$defaults = self::defaults();

			$settings = array(
				'global'     => self::normalize_rule( is_array( $raw['global'] ?? null ) ? $raw['global'] : $defaults['global'] ),
				'products'   => array(),
				'categories' => array(),
			);

			foreach ( (array) ( $raw['products'] ?? array() ) as $id => $rule ) {
				$id = absint( $id );
				if ( $id && is_array( $rule ) ) {
					$settings['products'][ $id ] = self::normalize_rule( $rule );
				}
			}

			foreach ( (array) ( $raw['categories'] ?? array() ) as $id => $rule ) {
				$id = absint( $id );
				if ( $id && is_array( $rule ) ) {
					$settings['categories'][ $id ] = self::normalize_rule( $rule );
				}
			}

			self::$settings_cache = $settings;
			return $settings;
		}

		private static function bump_cache_version() {
			$version = (int) get_option( self::CACHE_VERSION, 1 );
			update_option( self::CACHE_VERSION, max( 1, $version + 1 ), false );
			self::$settings_cache = null;
			self::$rule_cache     = array();
			self::$category_ids   = array();
		}

		private static function scope_product_id( $product ) {
			if ( ! $product instanceof WC_Product ) {
				return 0;
			}
			return $product->is_type( 'variation' ) ? absint( $product->get_parent_id() ) : absint( $product->get_id() );
		}

		private static function product_category_ids( $product_id ) {
			$product_id = absint( $product_id );
			if ( ! $product_id ) {
				return array();
			}

			if ( array_key_exists( $product_id, self::$category_ids ) ) {
				return self::$category_ids[ $product_id ];
			}

			$ids = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
			self::$category_ids[ $product_id ] = is_wp_error( $ids ) ? array() : array_map( 'absint', $ids );
			return self::$category_ids[ $product_id ];
		}

		private static function category_rule_matches( $category_id, $product_category_ids ) {
			$category_id = absint( $category_id );
			if ( ! $category_id || empty( $product_category_ids ) ) {
				return false;
			}

			static $expanded = array();
			if ( ! isset( $expanded[ $category_id ] ) ) {
				$children = get_term_children( $category_id, 'product_cat' );
				if ( is_wp_error( $children ) ) {
					$children = array();
				}
				$expanded[ $category_id ]   = array_map( 'absint', (array) $children );
				$expanded[ $category_id ][] = $category_id;
				$expanded[ $category_id ]   = array_values( array_unique( $expanded[ $category_id ] ) );
			}

			return (bool) array_intersect( $expanded[ $category_id ], $product_category_ids );
		}

		private static function resolve_rule( $product ) {
			$scope_id = self::scope_product_id( $product );
			if ( ! $scope_id ) {
				return null;
			}

			if ( array_key_exists( $scope_id, self::$rule_cache ) ) {
				return self::$rule_cache[ $scope_id ];
			}

			$settings = self::settings();

			// 1. Product override has the highest priority.
			if ( isset( $settings['products'][ $scope_id ] ) && ! empty( $settings['products'][ $scope_id ]['enabled'] ) ) {
				self::$rule_cache[ $scope_id ] = $settings['products'][ $scope_id ];
				return self::$rule_cache[ $scope_id ];
			}

			// 2. First matching category rule. Category rules include descendants.
			$product_cats = self::product_category_ids( $scope_id );
			foreach ( $settings['categories'] as $category_id => $rule ) {
				if ( empty( $rule['enabled'] ) ) {
					continue;
				}
				if ( self::category_rule_matches( $category_id, $product_cats ) ) {
					self::$rule_cache[ $scope_id ] = $rule;
					return self::$rule_cache[ $scope_id ];
				}
			}

			// 3. Global rule.
			if ( ! empty( $settings['global']['enabled'] ) ) {
				self::$rule_cache[ $scope_id ] = $settings['global'];
				return self::$rule_cache[ $scope_id ];
			}

			self::$rule_cache[ $scope_id ] = null;
			return null;
		}

		/* =========================================================
		 * Pricing engine
		 * =======================================================*/

		private static function can_apply( $product ) {
			if ( ! $product instanceof WC_Product ) {
				return false;
			}

			if ( is_admin() && function_exists( 'wp_doing_ajax' ) && ! wp_doing_ajax() ) {
				return false;
			}

			$scope_id = self::scope_product_id( $product );
			return $scope_id && 'publish' === get_post_status( $scope_id );
		}

		private static function has_real_sale( $product ) {
			if ( ! $product instanceof WC_Product ) {
				return false;
			}

			$sale = $product->get_sale_price( 'edit' );
			return '' !== $sale && null !== $sale && is_numeric( $sale ) && (float) $sale > 0;
		}

		private static function is_partner() {
			if ( ! is_user_logged_in() ) {
				return false;
			}

			$user = wp_get_current_user();
			if ( ! $user instanceof WP_User ) {
				return false;
			}

			return in_array( self::WHOLESALE_ROLE, (array) $user->roles, true ) || user_can( $user, self::WHOLESALE_CAP );
		}

		private static function wholesale_price( $product ) {
			if ( ! $product instanceof WC_Product ) {
				return false;
			}

			$id = absint( $product->get_id() );
			if ( ! $id ) {
				return false;
			}

			if ( array_key_exists( $id, self::$wholesale ) ) {
				return self::$wholesale[ $id ];
			}

			$value = get_post_meta( $id, self::WHOLESALE_META, true );

			// Safe fallback for stores that keep a wholesale value on the variable parent.
			if ( ( '' === $value || ! is_numeric( $value ) || (float) $value <= 0 ) && $product->is_type( 'variation' ) ) {
				$parent_id = absint( $product->get_parent_id() );
				if ( $parent_id ) {
					$value = get_post_meta( $parent_id, self::WHOLESALE_META, true );
				}
			}

			self::$wholesale[ $id ] = ( '' !== $value && is_numeric( $value ) && (float) $value > 0 ) ? (float) $value : false;
			return self::$wholesale[ $id ];
		}

		private static function round_to_8( $price ) {
			$price = (float) $price;
			$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'IRT';

			if ( 'IRR' === $currency ) {
				$step   = 100000;
				$ending = 80000;
			} else {
				$step   = 10000;
				$ending = 8000;
			}

			return ( floor( $price / $step ) * $step ) + $ending;
		}

		private static function step_value() {
			return 'IRR' === get_woocommerce_currency() ? 100000 : 10000;
		}

		private static function calculated_regular( $product, $rule ) {
			if ( ! $product instanceof WC_Product || self::has_real_sale( $product ) ) {
				return '';
			}

			$regular = $product->get_regular_price( 'edit' );
			if ( '' === $regular || ! is_numeric( $regular ) || (float) $regular <= 0 ) {
				return '';
			}

			$value = (float) $regular * ( 1 + ( (float) $rule['increase'] / 100 ) );
			return self::round_to_8( $value );
		}

		private static function calculated_sale( $product, $rule ) {
			if ( ! $product instanceof WC_Product || self::has_real_sale( $product ) || (float) $rule['sale'] <= 0 ) {
				return '';
			}

			$regular = self::calculated_regular( $product, $rule );
			if ( '' === $regular || ! is_numeric( $regular ) || (float) $regular <= 0 ) {
				return '';
			}

			$sale = (float) $regular * ( 1 - ( (float) $rule['sale'] / 100 ) );
			$sale = self::round_to_8( $sale );

			if ( $sale >= (float) $regular ) {
				$sale = max( 0, $sale - self::step_value() );
			}

			return $sale;
		}

		public static function filter_regular_price( $price, $product ) {
			if ( ! self::can_apply( $product ) || self::has_real_sale( $product ) ) {
				return $price;
			}

			$rule = self::resolve_rule( $product );
			if ( ! $rule ) {
				return $price;
			}

			$new_price = self::calculated_regular( $product, $rule );
			return '' !== $new_price ? $new_price : $price;
		}

		public static function filter_sale_price( $price, $product ) {
			if ( ! self::can_apply( $product ) || self::has_real_sale( $product ) ) {
				return $price;
			}

			$rule = self::resolve_rule( $product );
			if ( ! $rule || (float) $rule['sale'] <= 0 ) {
				return $price;
			}

			// Wholesale remains completely untouched for partner users.
			if ( self::is_partner() && false !== self::wholesale_price( $product ) ) {
				return $price;
			}

			$new_price = self::calculated_sale( $product, $rule );
			return '' !== $new_price ? $new_price : $price;
		}

		public static function filter_active_price( $price, $product ) {
			if ( ! self::can_apply( $product ) || self::has_real_sale( $product ) ) {
				return $price;
			}

			$rule = self::resolve_rule( $product );
			if ( ! $rule ) {
				return $price;
			}

			if ( self::is_partner() && false !== self::wholesale_price( $product ) ) {
				return $price;
			}

			if ( (float) $rule['sale'] > 0 ) {
				$sale = self::calculated_sale( $product, $rule );
				if ( '' !== $sale ) {
					return $sale;
				}
			}

			$regular = self::calculated_regular( $product, $rule );
			return '' !== $regular ? $regular : $price;
		}

		public static function variation_regular_price( $price, $variation, $parent ) {
			return self::filter_regular_price( $price, $variation );
		}

		public static function variation_sale_price( $price, $variation, $parent ) {
			return self::filter_sale_price( $price, $variation );
		}

		public static function variation_active_price( $price, $variation, $parent ) {
			return self::filter_active_price( $price, $variation );
		}

		public static function filter_is_on_sale( $on_sale, $product ) {
			if ( ! self::can_apply( $product ) || self::has_real_sale( $product ) ) {
				return $on_sale;
			}

			$rule = self::resolve_rule( $product );
			if ( ! $rule || (float) $rule['sale'] <= 0 ) {
				return $on_sale;
			}

			if ( self::is_partner() && false !== self::wholesale_price( $product ) ) {
				return $on_sale;
			}

			// Variable products determine sale state from the generated variation arrays.
			if ( $product->is_type( 'variable' ) ) {
				return $on_sale;
			}

			return '' !== self::calculated_sale( $product, $rule ) ? true : $on_sale;
		}

		public static function variation_hash( $hash, $product, $for_display ) {
			$hash['tisacase_pm'] = array(
				'plugin'  => self::VERSION,
				'version' => (int) get_option( self::CACHE_VERSION, 1 ),
				'mode'    => self::is_partner() ? 'partner' : 'retail',
			);
			return $hash;
		}

		public static function protect_partner_cache() {
			if ( ! self::is_partner() ) {
				return;
			}

			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}

			if ( ! headers_sent() ) {
				nocache_headers();
			}
		}

		/* =========================================================
		 * Admin
		 * =======================================================*/

		public static function admin_menu() {
			add_submenu_page(
				'woocommerce',
				'قیمت‌گذاری TisaCase',
				'قیمت‌گذاری TisaCase',
				'manage_woocommerce',
				self::PAGE_SLUG,
				array( __CLASS__, 'render_admin_page' )
			);
		}

		public static function admin_assets( $hook ) {
			if ( 'woocommerce_page_' . self::PAGE_SLUG !== $hook ) {
				return;
			}

			wp_enqueue_style(
				'tisacase-pm-admin',
				plugins_url( 'assets/admin.css', __FILE__ ),
				wp_style_is( 'tisacase-ui', 'registered' ) ? array( 'tisacase-ui' ) : array(),
				self::VERSION
			);

			wp_enqueue_script(
				'tisacase-pm-admin',
				plugins_url( 'assets/admin.js', __FILE__ ),
				array( 'jquery' ),
				self::VERSION,
				true
			);

			wp_localize_script(
				'tisacase-pm-admin',
				'TisaCasePM',
				array(
					'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
					'nonce'      => wp_create_nonce( 'tisacase_pm_search' ),
					'minChars'   => 2,
					'productAct' => 'tisacase_pm_search_products',
					'catAct'     => 'tisacase_pm_search_categories',
				)
			);
		}

		private static function rule_row( $type, $id, $rule ) {
			$id   = absint( $id );
			$rule = self::normalize_rule( $rule );

			if ( 'products' === $type ) {
				$name = get_the_title( $id );
			} else {
				$term = get_term( $id, 'product_cat' );
				$name = ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
			}

			if ( '' === $name ) {
				return;
			}
			?>
			<tr data-rule-id="<?php echo esc_attr( $id ); ?>">
				<td class="tisa-rule-name">
					<strong><?php echo esc_html( $name ); ?></strong>
					<small class="tisa-code">#<?php echo esc_html( $id ); ?></small>
					<input type="hidden" name="<?php echo esc_attr( $type ); ?>[<?php echo esc_attr( $id ); ?>][exists]" value="1">
				</td>
				<td><input class="tisa-input tisa-input--number" type="number" min="0" max="500" step="0.1" name="<?php echo esc_attr( $type ); ?>[<?php echo esc_attr( $id ); ?>][increase]" value="<?php echo esc_attr( $rule['increase'] ); ?>"></td>
				<td><input class="tisa-input tisa-input--number" type="number" min="0" max="99.9" step="0.1" name="<?php echo esc_attr( $type ); ?>[<?php echo esc_attr( $id ); ?>][sale]" value="<?php echo esc_attr( $rule['sale'] ); ?>"></td>
				<td class="tisa-rule-enabled">
					<input type="hidden" name="<?php echo esc_attr( $type ); ?>[<?php echo esc_attr( $id ); ?>][enabled]" value="0">
					<label><input type="checkbox" name="<?php echo esc_attr( $type ); ?>[<?php echo esc_attr( $id ); ?>][enabled]" value="1" <?php checked( $rule['enabled'], 1 ); ?>> فعال</label>
				</td>
				<td><button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm tisa-remove-rule">حذف</button></td>
			</tr>
			<?php
		}

		public static function render_admin_page() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( 'دسترسی غیرمجاز.' );
			}

			$settings = self::settings();
			?>
			<div class="wrap tisa-wrap tisa-pm-wrap" dir="rtl">
				<h1 class="tisa-h1">قیمت‌گذاری TisaCase</h1>

				<?php if ( isset( $_GET['saved'] ) ) : ?>
					<div class="notice notice-success is-dismissible tisa-notice tisa-notice--success"><p>تنظیمات ذخیره شد و کش قیمت متغیرها نسخه جدید گرفت.</p></div>
				<?php endif; ?>

				<?php if ( isset( $_GET['synced'] ) ) : ?>
					<div class="notice notice-success is-dismissible tisa-notice tisa-notice--success"><p><strong>همگام‌سازی انجام شد:</strong> قانون سراسری ۱۰٪ افزایش + ۱۰٪ فروش ویژه برای همه محصولات فعلی و محصولات جدید فعال شد.</p></div>
				<?php endif; ?>

				<div class="tisa-pm-note tisa-notice tisa-notice--info">
					<strong>منطق ثابت:</strong> محصول یا متغیری که از قبل قیمت فروش فوق‌العاده واقعی دارد، کاملاً دست‌نخورده می‌ماند. قیمت همکاری نیز تغییر نمی‌کند؛ ولی قیمت Retail محصول دارای قیمت همکاری طبق قانون انتخاب‌شده محاسبه می‌شود. اولویت قوانین: محصول تکی ← دسته‌بندی ← قانون سراسری.
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="tisacase_pm_save">
					<?php wp_nonce_field( 'tisacase_pm_save' ); ?>

					<section class="tisa-card">
						<h2 class="tisa-h2">قانون سراسری</h2>
						<p>وقتی فعال باشد، محصولات فعلی و هر محصول جدیدی که بعداً منتشر شود به‌صورت خودکار همین قانون را می‌گیرد؛ مگر اینکه قانون محصول یا دسته‌بندی برایش تعریف شده باشد.</p>
						<div class="tisa-grid-3">
							<label class="tisa-switch tisa-switch-line">
								<input type="hidden" name="global[enabled]" value="0">
								<input type="checkbox" name="global[enabled]" value="1" <?php checked( $settings['global']['enabled'], 1 ); ?>>
								<span class="tisa-switch__track" aria-hidden="true"></span>
								<span>فعال برای همه محصولات</span>
							</label>
							<label>درصد افزایش قیمت اصلی
								<input class="tisa-input tisa-input--number" type="number" min="0" max="500" step="0.1" name="global[increase]" value="<?php echo esc_attr( $settings['global']['increase'] ); ?>">
							</label>
							<label>درصد فروش فوق‌العاده از قیمت افزایش‌یافته
								<input class="tisa-input tisa-input--number" type="number" min="0" max="99.9" step="0.1" name="global[sale]" value="<?php echo esc_attr( $settings['global']['sale'] ); ?>">
							</label>
						</div>
					</section>

					<section class="tisa-card">
						<div class="tisa-section-head">
							<div><h2 class="tisa-h2">محصولات تکی</h2><p class="tisa-meta">نام محصول را جستجو کن و برای همان محصول درصدهای مستقل تعریف کن. محصول متغیر یعنی تمام Variationهای آن محصول.</p></div>
						</div>
						<div class="tisa-search-box">
							<input type="search" class="tisa-input" id="tisa-product-search" placeholder="حداقل ۲ حرف از نام محصول..." autocomplete="off">
							<div id="tisa-product-results" class="tisa-search-results"></div>
						</div>
						<div class="tisa-table-scroll">
							<table class="tisa-table tisa-rules-table">
								<thead><tr><th>محصول</th><th>افزایش ٪</th><th>فروش ویژه ٪</th><th>وضعیت</th><th></th></tr></thead>
								<tbody id="tisa-product-rules">
									<?php foreach ( $settings['products'] as $id => $rule ) { self::rule_row( 'products', $id, $rule ); } ?>
								</tbody>
							</table>
						</div>
					</section>

					<section class="tisa-card">
						<h2 class="tisa-h2">دسته‌بندی‌ها</h2>
						<p>نام دسته را جستجو کن. قانون دسته روی خود دسته و تمام زیردسته‌های آن اعمال می‌شود.</p>
						<div class="tisa-search-box">
							<input type="search" class="tisa-input" id="tisa-category-search" placeholder="حداقل ۲ حرف از نام دسته‌بندی..." autocomplete="off">
							<div id="tisa-category-results" class="tisa-search-results"></div>
						</div>
						<div class="tisa-table-scroll">
							<table class="tisa-table tisa-rules-table">
								<thead><tr><th>دسته‌بندی</th><th>افزایش ٪</th><th>فروش ویژه ٪</th><th>وضعیت</th><th></th></tr></thead>
								<tbody id="tisa-category-rules">
									<?php foreach ( $settings['categories'] as $id => $rule ) { self::rule_row( 'categories', $id, $rule ); } ?>
								</tbody>
							</table>
						</div>
					</section>

					<p><button type="submit" class="tisa-btn tisa-btn--primary tisa-btn--lg">ذخیره تنظیمات</button></p>
				</form>

				<section class="tisa-card tisa-sync-card">
					<h2 class="tisa-h2">همگام‌سازی سریع کل فروشگاه</h2>
					<p>این دکمه بدون حلقه‌زدن روی هزاران محصول، قانون سراسری را روی <strong>۱۰٪ افزایش</strong> و <strong>۱۰٪ فروش فوق‌العاده</strong> فعال می‌کند. بنابراین دیتابیس محصولات بازنویسی نمی‌شود و محصولات جدید هم خودکار شامل می‌شوند.</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('قانون سراسری ۱۰٪ افزایش + ۱۰٪ فروش ویژه برای همه محصولات بدون Sale واقعی فعال شود؟');">
						<input type="hidden" name="action" value="tisacase_pm_sync_all">
						<?php wp_nonce_field( 'tisacase_pm_sync_all' ); ?>
						<button type="submit" class="tisa-btn tisa-btn--secondary">همگام‌سازی همه محصولات با ۱۰٪ + ۱۰٪</button>
					</form>
				</section>
			</div>
			<?php
		}

		private static function posted_rule_group( $group, $type ) {
			$out = array();
			foreach ( (array) $group as $id => $rule ) {
				$id = absint( $id );
				if ( ! $id || ! is_array( $rule ) ) {
					continue;
				}

				if ( 'product' === $type && 'product' !== get_post_type( $id ) ) {
					continue;
				}

				if ( 'category' === $type && ! term_exists( $id, 'product_cat' ) ) {
					continue;
				}

				$out[ $id ] = self::normalize_rule( $rule );
			}
			return $out;
		}

		public static function save_settings() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( 'دسترسی غیرمجاز.' );
			}
			check_admin_referer( 'tisacase_pm_save' );

			$settings = array(
				'global'     => self::normalize_rule( isset( $_POST['global'] ) ? wp_unslash( $_POST['global'] ) : array() ),
				'products'   => self::posted_rule_group( isset( $_POST['products'] ) ? wp_unslash( $_POST['products'] ) : array(), 'product' ),
				'categories' => self::posted_rule_group( isset( $_POST['categories'] ) ? wp_unslash( $_POST['categories'] ) : array(), 'category' ),
			);

			update_option( self::OPTION, $settings, false );
			self::bump_cache_version();

			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
			exit;
		}

		public static function sync_all_defaults() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( 'دسترسی غیرمجاز.' );
			}
			check_admin_referer( 'tisacase_pm_sync_all' );

			$settings = self::settings();
			$settings['global'] = array(
				'enabled'  => 1,
				'increase' => 10,
				'sale'     => 10,
			);

			update_option( self::OPTION, $settings, false );
			self::bump_cache_version();

			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'synced' => 1 ), admin_url( 'admin.php' ) ) );
			exit;
		}

		private static function ajax_auth() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
			}
			check_ajax_referer( 'tisacase_pm_search', 'nonce' );
		}

		public static function ajax_search_products() {
			self::ajax_auth();
			$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
			if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $term ) : strlen( $term ) ) < 2 ) {
				wp_send_json_success( array() );
			}

			$query = new WP_Query(
				array(
					'post_type'              => 'product',
					'post_status'            => array( 'publish', 'draft', 'private', 'pending' ),
					'posts_per_page'         => 20,
					's'                      => $term,
					'fields'                 => 'ids',
					'orderby'                => 'relevance',
					'order'                  => 'DESC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			$items = array();
			foreach ( $query->posts as $id ) {
				$product = wc_get_product( $id );
				if ( ! $product ) {
					continue;
				}
				$items[] = array(
					'id'    => (int) $id,
					'name'  => $product->get_name(),
					'sku'   => $product->get_sku(),
					'type'  => $product->get_type(),
				);
			}

			wp_send_json_success( $items );
		}

		public static function ajax_search_categories() {
			self::ajax_auth();
			$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
			if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $term ) : strlen( $term ) ) < 2 ) {
				wp_send_json_success( array() );
			}

			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'name__like' => $term,
					'number'     => 20,
					'orderby'    => 'name',
					'order'      => 'ASC',
				)
			);

			$items = array();
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $item ) {
					$items[] = array(
						'id'    => (int) $item->term_id,
						'name'  => $item->name,
						'count' => (int) $item->count,
					);
				}
			}

			wp_send_json_success( $items );
		}
	}
}

TisaCase_Pricing_Manager::bootstrap();
register_activation_hook( __FILE__, array( 'TisaCase_Pricing_Manager', 'activate' ) );
