<?php
/**
 * Plugin Name: پکیج ویژه قاب موبایل
 * Plugin URI:  https://example.com/wc-case-special-package
 * Description: افزودن گزینه «پکیج ویژه» با قیمت ثابت به محصولات قاب موبایل (تشخیص از روی عنوان/دسته‌بندی، با لیست استثنا بر اساس SKU). قیمت به ازای هر عدد محاسبه و در فاکتور، ایمیل و پیشخوان نمایش داده می‌شود.
 * Version:     1.3.0
 * Author:      علیرضا شعبان زاده
 * Text Domain: case-special-package
 * WC requires at least: 5.0
 * WC tested up to: 9.4
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCSP_MAIN_FILE', __FILE__ );
define( 'WCSP_VERSION', '1.3.0' );

/**
 * کلاس اصلی پلاگین.
 */
final class WC_Case_Special_Package {

	const OPTION_KEY   = 'wcsp_settings';
	const CART_KEY     = 'wcsp_package';
	const PRODUCT_META = '_wcsp_mode';

	/** @var WC_Case_Special_Package|null */
	private static $instance = null;

	/**
	 * دریافت نمونه یکتا (Singleton).
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// هوک‌های ادمین.
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// فیلد دستی روی صفحه ویرایش محصول.
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'product_field' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_field' ) );

		// فرانت‌اند: نمایش چک‌باکس در صفحه محصول.
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_checkbox' ) );

		// سبد خرید.
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'get_item_data' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'adjust_price' ), 20, 1 );

		// سفارش / فاکتور / ایمیل.
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_item_meta' ), 10, 4 );

		// سازگاری با HPOS و اعلام ناسازگاری با بلوک‌های سبد/پرداخت (چون چک‌باکس کلاسیک است).
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );

		// باطل‌کردن کش آمار داشبورد در زمان‌های لازم.
		add_action( 'save_post_product', array( __CLASS__, 'flush_stats' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'flush_stats' ) );
		add_action( 'woocommerce_new_order_item', array( __CLASS__, 'flush_stats' ) );
	}

	public static function flush_stats() {
		delete_transient( 'wcsp_stats_v1' );
	}

	public function declare_compatibility() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, false );
		}
	}

	/* ------------------------------------------------------------------
	 * تنظیمات
	 * ----------------------------------------------------------------*/

	public static function default_settings() {
		return array(
			'enabled'        => 'no',
			'label'          => 'پکیج ویژه',
			'checkbox_text'  => 'افزودن پکیج ویژه (به ازای هر عدد)',
			'price'          => 0,
			'keywords'       => 'قاب',
			'match_mode'     => 'contains', // contains | starts_with
			'categories'     => array(),
			'sku_exceptions' => '',
		);
	}

	public static function get_settings() {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::default_settings(), $saved );
	}

	public function add_settings_page() {
		add_submenu_page(
			'woocommerce',
			'پکیج ویژه قاب',
			'پکیج ویژه قاب',
			'manage_woocommerce',
			'wcsp-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'wcsp_settings_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	public function sanitize_settings( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$clean   = array();

		$clean['enabled']        = ( ! empty( $input['enabled'] ) && 'yes' === $input['enabled'] ) ? 'yes' : 'no';
		$clean['label']          = sanitize_text_field( isset( $input['label'] ) ? $input['label'] : '' );
		$clean['checkbox_text']  = sanitize_text_field( isset( $input['checkbox_text'] ) ? $input['checkbox_text'] : '' );
		$clean['price']          = isset( $input['price'] ) ? max( 0, (float) wc_format_decimal( $input['price'] ) ) : 0;
		$clean['keywords']       = sanitize_textarea_field( isset( $input['keywords'] ) ? $input['keywords'] : '' );
		$clean['match_mode']     = ( isset( $input['match_mode'] ) && 'starts_with' === $input['match_mode'] ) ? 'starts_with' : 'contains';
		$clean['sku_exceptions'] = sanitize_textarea_field( isset( $input['sku_exceptions'] ) ? $input['sku_exceptions'] : '' );

		$clean['categories'] = array();
		if ( ! empty( $input['categories'] ) && is_array( $input['categories'] ) ) {
			$clean['categories'] = array_values( array_filter( array_map( 'absint', $input['categories'] ) ) );
		}

		if ( '' === $clean['label'] ) {
			$clean['label'] = 'پکیج ویژه';
		}

		return $clean;
	}

	public function enqueue_admin_assets( $hook ) {
		if ( false === strpos( $hook, 'wcsp-settings' ) ) {
			return;
		}
		// قلم از لایهٔ طراحی مشترک (هاب) می‌آید؛ بارگیری از CDN خارجی حذف شد (F5).
		wp_enqueue_style( 'select2' );
		wp_enqueue_script( 'select2' );
		$deps = wp_style_is( 'tisacase-ui', 'registered' ) ? array( 'tisacase-ui', 'select2' ) : array( 'select2' );
		wp_enqueue_style( 'wcsp-admin', plugins_url( 'assets/admin.css', WCSP_MAIN_FILE ), $deps, WCSP_VERSION );
		wp_enqueue_script( 'wcsp-admin', plugins_url( 'assets/admin.js', WCSP_MAIN_FILE ), array(), WCSP_VERSION, true );
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$s     = self::get_settings();
		$stats = self::get_stats();
		$opt   = self::OPTION_KEY;

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		// پیش‌نمایش زنده لیست استثنا: کدام SKUها به کدام محصول می‌خورند؟
		$exception_rows = array();
		foreach ( self::parse_list( $s['sku_exceptions'] ) as $sku ) {
			$pid              = function_exists( 'wc_get_product_id_by_sku' ) ? wc_get_product_id_by_sku( $sku ) : 0;
			$exception_rows[] = array(
				'sku'  => $sku,
				'name' => $pid ? get_the_title( $pid ) : '',
			);
		}

		$max = 1;
		foreach ( $stats['series'] as $pt ) {
			$max = max( $max, (int) $pt['count'] );
		}

		$sym = get_woocommerce_currency_symbol();
		$avg = $stats['orders'] > 0 ? $stats['revenue'] / $stats['orders'] : 0;

		// ----- ساخت نقاط نمودار سطحی (SVG) -----
		$cw    = 560;
		$ch    = 150;
		$padx  = 6;
		$top   = 12;
		$bot   = 10;
		$n     = count( $stats['series'] );
		$step  = ( $cw - $padx * 2 ) / max( 1, $n - 1 );
		$pts   = array();
		foreach ( $stats['series'] as $i => $pt ) {
			$x     = round( $padx + $i * $step, 1 );
			$ratio = $max > 0 ? (int) $pt['count'] / $max : 0;
			$y     = round( $ch - $bot - $ratio * ( $ch - $top - $bot ), 1 );
			$pts[] = array( $x, $y, (int) $pt['count'] );
		}
		$line = '';
		foreach ( $pts as $p ) {
			$line .= ( $line ? ' L ' : 'M ' ) . $p[0] . ' ' . $p[1];
		}
		$area = $line . ' L ' . $pts[ $n - 1 ][0] . ' ' . ( $ch - $bot ) . ' L ' . $pts[0][0] . ' ' . ( $ch - $bot ) . ' Z';
		?>
		<div class="wcsp-app" dir="rtl">
			<form method="post" action="options.php" id="wcsp-form">
				<?php settings_fields( 'wcsp_settings_group' ); ?>
				<div class="wcsp-main">

					<header class="wcsp-top">
						<div class="wcsp-top-id">
							<div class="wcsp-logo">
								<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#eafff8" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="7" y="2.5" width="10" height="19" rx="2.5"/><line x1="10.5" y1="5.5" x2="13.5" y2="5.5"/><path d="M4 9v6M20 9v6"/></svg>
							</div>
							<div>
								<h1>پکیج ویژه قاب <span class="wcsp-ver">نسخه <?php echo esc_html( WCSP_VERSION ); ?></span></h1>
								<p>قیمت ثابت پکیج روی محصولات قاب، به ازای هر عدد — با محاسبه خودکار در سبد و ثبت شفاف در فاکتور و ایمیل.</p>
							</div>
						</div>
						<div class="wcsp-top-state">
							<span class="wcsp-chip <?php echo 'yes' === $s['enabled'] ? '' : 'off'; ?>"><i class="dot"></i><?php echo 'yes' === $s['enabled'] ? 'قابلیت فعال' : 'قابلیت غیرفعال'; ?></span>
							<span class="wcsp-chip">ووکامرس متصل</span>
						</div>
					</header>

					<nav class="wcsp-tabs">
						<button type="button" class="wcsp-tab" data-tab="dash">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 13h5v8H3zM10 3h5v18h-5zM17 9h5v12h-5z"/></svg>
							داشبورد
						</button>
						<button type="button" class="wcsp-tab" data-tab="general">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="4" y1="7" x2="20" y2="7"/><circle cx="9" cy="7" r="2.5"/><line x1="4" y1="17" x2="20" y2="17"/><circle cx="15" cy="17" r="2.5"/></svg>
							تنظیمات عمومی
						</button>
						<button type="button" class="wcsp-tab" data-tab="detect">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg>
							تشخیص محصولات
						</button>
						<button type="button" class="wcsp-tab" data-tab="exceptions">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><line x1="5.8" y1="5.8" x2="18.2" y2="18.2"/></svg>
							استثناها (SKU)
						</button>
						<button type="button" class="wcsp-tab" data-tab="texts">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="4 7 4 4 20 4 20 7"/><line x1="12" y1="4" x2="12" y2="20"/><line x1="8" y1="20" x2="16" y2="20"/></svg>
							متن‌ها و نمایش
						</button>
						<button type="button" class="wcsp-tab" data-tab="help">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M9.2 9a2.8 2.8 0 0 1 5.5.8c0 1.8-2.7 2.2-2.7 3.6"/><line x1="12" y1="17" x2="12" y2="17.01"/></svg>
							راهنما
						</button>
					</nav>

					<?php if ( isset( $_GET['settings-updated'] ) ) : // phpcs:ignore ?>
						<div class="wcsp-toast">
							<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M20 6 9 17l-5-5"/></svg>
							تنظیمات با موفقیت ذخیره شد.
						</div>
					<?php endif; ?>

					<!-- ================= داشبورد ================= -->
					<section class="wcsp-panel" data-panel="dash">

						<div class="wcsp-kpis">
							<div class="wcsp-kpi">
								<div class="t">محصولات واجد شرایط</div>
								<div class="v"><?php echo esc_html( number_format_i18n( $stats['eligible'] ) ); ?></div>
								<div class="s">از <?php echo esc_html( number_format_i18n( $stats['total_products'] ) ); ?> محصول منتشرشده</div>
							</div>
							<div class="wcsp-kpi k-amber">
								<div class="t">قیمت پکیج</div>
								<div class="v"><?php echo esc_html( wc_format_localized_price( $s['price'] ) ); ?> <small><?php echo esc_html( $sym ); ?></small></div>
								<div class="s">به ازای هر عدد، ضرب در تعداد سبد</div>
							</div>
							<div class="wcsp-kpi k-plum">
								<div class="t">سفارش‌های دارای پکیج</div>
								<div class="v"><?php echo esc_html( number_format_i18n( $stats['orders'] ) ); ?></div>
								<div class="s"><?php echo esc_html( number_format_i18n( $stats['today'] ) ); ?> سفارش امروز</div>
							</div>
							<div class="wcsp-kpi k-ink">
								<div class="t">درآمد کل پکیج</div>
								<div class="v"><?php echo esc_html( wc_format_localized_price( $stats['revenue'] ) ); ?></div>
								<div class="s">میانگین <?php echo esc_html( wc_format_localized_price( $avg ) ); ?> در هر سفارش</div>
							</div>
						</div>

						<div class="wcsp-grid">
							<div class="wcsp-card">
								<div class="wcsp-chart-head">
									<div>
										<h3 class="wcsp-sec">روند سفارش‌های پکیج</h3>
										<p class="wcsp-sec-desc">سفارش‌های دارای پکیج ویژه در ۱۴ روز اخیر (در حال انجام و تکمیل‌شده).</p>
									</div>
									<span class="wcsp-legend"><i></i>تعداد سفارش پکیج</span>
								</div>
								<div class="wcsp-svgwrap">
									<svg class="wcsp-svg" viewBox="0 0 <?php echo $cw; ?> <?php echo $ch; ?>" preserveAspectRatio="none" role="img" aria-label="نمودار روند سفارش‌های پکیج در ۱۴ روز اخیر">
										<defs>
											<linearGradient id="wcspAreaGrad" x1="0" y1="0" x2="0" y2="1">
												<stop offset="0%" stop-color="#0e7a6b" stop-opacity="0.28"/>
												<stop offset="100%" stop-color="#0e7a6b" stop-opacity="0.02"/>
											</linearGradient>
										</defs>
										<line x1="0" y1="<?php echo $ch - $bot; ?>" x2="<?php echo $cw; ?>" y2="<?php echo $ch - $bot; ?>" stroke="#e7e1d5" stroke-width="1"/>
										<line x1="0" y1="<?php echo round( ( $ch - $bot ) / 2, 1 ); ?>" x2="<?php echo $cw; ?>" y2="<?php echo round( ( $ch - $bot ) / 2, 1 ); ?>" stroke="#efeae0" stroke-width="1" stroke-dasharray="4 5"/>
										<path d="<?php echo esc_attr( $area ); ?>" fill="url(#wcspAreaGrad)"/>
										<path d="<?php echo esc_attr( $line ); ?>" fill="none" stroke="#0e7a6b" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
										<?php foreach ( $pts as $p ) : if ( $p[2] > 0 ) : ?>
											<circle cx="<?php echo $p[0]; ?>" cy="<?php echo $p[1]; ?>" r="4" fill="#fff" stroke="#0e7a6b" stroke-width="2.5"/>
										<?php endif; endforeach; ?>
									</svg>
									<div class="wcsp-xlabels">
										<?php foreach ( $stats['series'] as $pt ) : ?>
											<span><?php echo esc_html( $pt['label'] ); ?></span>
										<?php endforeach; ?>
									</div>
								</div>
							</div>

							<div class="wcsp-card wcsp-rev">
								<h3 class="wcsp-sec">خلاصه درآمد</h3>
								<div class="big"><?php echo esc_html( wc_format_localized_price( $stats['revenue'] ) ); ?></div>
								<div class="cur"><?php echo esc_html( $sym ); ?> درآمد تجمیعی پکیج ویژه</div>
								<div class="rows">
									<div class="r"><span class="k">سفارش‌های دارای پکیج</span><span class="v"><?php echo esc_html( number_format_i18n( $stats['orders'] ) ); ?></span></div>
									<div class="r"><span class="k">سفارش امروز</span><span class="v"><?php echo esc_html( number_format_i18n( $stats['today'] ) ); ?></span></div>
									<div class="r"><span class="k">محصولات مستثنی (SKU)</span><span class="v"><?php echo esc_html( number_format_i18n( $stats['exceptions'] ) ); ?></span></div>
									<div class="r"><span class="k">حالت تشخیص عنوان</span><span class="v"><?php echo 'starts_with' === $s['match_mode'] ? 'شروع با کلمه' : 'شامل بودن کلمه'; ?></span></div>
								</div>
							</div>
						</div>

						<div class="wcsp-grid-2">
							<div class="wcsp-card">
								<h3 class="wcsp-sec">سلامت سیستم</h3>
								<p class="wcsp-sec-desc">وضعیت اجزای مورد نیاز افزونه، در یک نگاه.</p>
								<div class="wcsp-health">
									<div class="wcsp-htile"><i class="ind"></i><span class="k">ووکامرس</span><span class="v">فعال</span></div>
									<div class="wcsp-htile <?php echo 'yes' === $s['enabled'] ? '' : 'off'; ?>"><i class="ind"></i><span class="k">قابلیت پکیج</span><span class="v"><?php echo 'yes' === $s['enabled'] ? 'فعال' : 'غیرفعال'; ?></span></div>
									<div class="wcsp-htile"><i class="ind"></i><span class="k">محصولات قاب</span><span class="v"><?php echo esc_html( number_format_i18n( $stats['eligible'] ) ); ?> محصول</span></div>
									<div class="wcsp-htile <?php echo $stats['exceptions'] > 0 ? 'warn' : 'off'; ?>"><i class="ind"></i><span class="k">استثناهای SKU</span><span class="v"><?php echo $stats['exceptions'] > 0 ? esc_html( number_format_i18n( $stats['exceptions'] ) ) . ' مورد' : 'خالی'; ?></span></div>
									<div class="wcsp-htile"><i class="ind"></i><span class="k">سازگاری HPOS</span><span class="v">سازگار</span></div>
									<div class="wcsp-htile"><i class="ind"></i><span class="k">کش آمار</span><span class="v">۱ ساعته</span></div>
								</div>
							</div>

							<div class="wcsp-card">
								<h3 class="wcsp-sec">دسترسی سریع</h3>
								<p class="wcsp-sec-desc">پرش به بخش‌های تنظیمات و مدیریت فروشگاه.</p>
								<div class="wcsp-quick">
									<a href="#" data-goto="general">
										<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="4" y1="7" x2="20" y2="7"/><circle cx="9" cy="7" r="2.5"/><line x1="4" y1="17" x2="20" y2="17"/><circle cx="15" cy="17" r="2.5"/></svg>
										قیمت و فعال‌سازی
									</a>
									<a href="#" data-goto="detect">
										<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg>
										کلمات کلیدی
									</a>
									<a href="#" data-goto="exceptions">
										<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><line x1="5.8" y1="5.8" x2="18.2" y2="18.2"/></svg>
										لیست استثنا
									</a>
									<a href="#" data-goto="texts">
										<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="4 7 4 4 20 4 20 7"/><line x1="12" y1="4" x2="12" y2="20"/><line x1="8" y1="20" x2="16" y2="20"/></svg>
										متن‌ها و نمایش
									</a>
									<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>">
										<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/></svg>
										محصولات
									</a>
									<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=shop_order' ) ); ?>">
										<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
										سفارش‌ها
									</a>
								</div>
							</div>
						</div>
					</section>

					<!-- ================= تنظیمات عمومی ================= -->
					<section class="wcsp-panel" data-panel="general">
						<div class="wcsp-card">
							<h3 class="wcsp-sec">تنظیمات عمومی</h3>
							<p class="wcsp-sec-desc">فعال‌سازی قابلیت و تعیین قیمت ثابت پکیج به ازای هر عدد.</p>

							<div class="wcsp-field">
								<label class="wcsp-switch">
									<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[enabled]" value="yes" <?php checked( $s['enabled'], 'yes' ); ?> />
									<span class="wcsp-track"></span>
									<span class="sw-txt">قابلیت پکیج ویژه فعال باشد</span>
								</label>
							</div>

							<div class="wcsp-field">
								<label class="wcsp-label" for="wcsp_price">قیمت پکیج (به ازای هر عدد)</label>
								<div class="wcsp-money">
									<input type="text" id="wcsp_price" name="<?php echo esc_attr( $opt ); ?>[price]" value="<?php echo esc_attr( wc_format_localized_price( $s['price'] ) ); ?>" />
									<span class="cur"><?php echo esc_html( $sym ); ?></span>
								</div>
								<p class="wcsp-hint">اگر مشتری ۵ عدد قاب سفارش دهد، این مبلغ ۵ بار به جمع کل اضافه می‌شود و در فاکتور به تفکیک نمایش داده می‌شود.</p>
							</div>

							<div class="wcsp-field">
								<span class="wcsp-label">حالت تطبیق عنوان محصول</span>
								<div class="wcsp-seg">
									<label>
										<input type="radio" name="<?php echo esc_attr( $opt ); ?>[match_mode]" value="contains" <?php checked( $s['match_mode'], 'contains' ); ?> />
										<span>عنوان شامل کلمه کلیدی باشد</span>
									</label>
									<label>
										<input type="radio" name="<?php echo esc_attr( $opt ); ?>[match_mode]" value="starts_with" <?php checked( $s['match_mode'], 'starts_with' ); ?> />
										<span>عنوان با کلمه کلیدی شروع شود</span>
									</label>
								</div>
							</div>
						</div>
					</section>

					<!-- ================= تشخیص محصولات ================= -->
					<section class="wcsp-panel" data-panel="detect">
						<div class="wcsp-card">
							<h3 class="wcsp-sec">تشخیص محصولات قاب</h3>
							<p class="wcsp-sec-desc">محصولات قدیمی و جدید به‌صورت خودکار بررسی می‌شوند؛ نیازی به ویرایش تک‌تک آن‌ها نیست.</p>

							<div class="wcsp-field">
								<label class="wcsp-label" for="wcsp_keywords">کلمه / کلمات کلیدی عنوان</label>
								<textarea id="wcsp_keywords" rows="3" name="<?php echo esc_attr( $opt ); ?>[keywords]" placeholder="قاب"><?php echo esc_textarea( $s['keywords'] ); ?></textarea>
								<p class="wcsp-hint">هر خط (یا جدا شده با کاما) یک کلمه کلیدی. تطبیق با حروف فارسی/عربی یکسان‌سازی می‌شود (ی/ي، ک/ك، نیم‌فاصله).</p>
							</div>

							<div class="wcsp-field">
								<label class="wcsp-label" for="wcsp_categories">دسته‌بندی‌های واجد شرایط</label>
								<select id="wcsp_categories" class="wcsp-select2" multiple="multiple" name="<?php echo esc_attr( $opt ); ?>[categories][]">
									<?php foreach ( $terms as $term ) : ?>
										<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php echo in_array( (int) $term->term_id, array_map( 'intval', (array) $s['categories'] ), true ) ? 'selected' : ''; ?>>
											<?php echo esc_html( $term->name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="wcsp-hint">اختیاری. محصولی در این دسته‌ها باشد، حتی بدون کلمه کلیدی در عنوان، واجد شرایط می‌شود.</p>
							</div>

							<div class="wcsp-field">
								<span class="wcsp-label">کنترل دستی روی هر محصول</span>
								<p class="wcsp-hint">در صفحه ویرایش هر محصول، بخش «اطلاعات عمومی محصول»، فیلد «پکیج ویژه قاب» وجود دارد: خودکار / اجباراً فعال / اجباراً غیرفعال. برای محصولات متغیر، تشخیص بر اساس عنوان محصول والد انجام می‌شود.</p>
							</div>
						</div>
					</section>

					<!-- ================= استثناها ================= -->
					<section class="wcsp-panel" data-panel="exceptions">
						<div class="wcsp-card">
							<h3 class="wcsp-sec">لیست استثنا بر اساس SKU</h3>
							<p class="wcsp-sec-desc">این محصولات هرگز گزینه پکیج ویژه را نمی‌گیرند — حتی اگر عنوانشان «قاب» باشد. این لیست بر همه قوانین اولویت دارد.</p>

							<div class="wcsp-field">
								<label class="wcsp-label" for="wcsp_sku_exceptions">SKUهای مستثنی</label>
								<textarea id="wcsp_sku_exceptions" rows="5" name="<?php echo esc_attr( $opt ); ?>[sku_exceptions]" placeholder="LP180&#10;LP181"><?php echo esc_textarea( $s['sku_exceptions'] ); ?></textarea>
								<p class="wcsp-hint">هر خط یک SKU، یا جدا شده با کاما.</p>
							</div>

							<?php if ( $exception_rows ) : ?>
								<div class="wcsp-field">
									<span class="wcsp-label">پیش‌نمایش تطبیق (وضعیت فعلی فروشگاه)</span>
									<?php foreach ( $exception_rows as $row ) : ?>
										<div class="wcsp-sku">
											<span class="sku"><?php echo esc_html( $row['sku'] ); ?></span>
											<?php if ( $row['name'] ) : ?>
												<span class="found">✔ <?php echo esc_html( $row['name'] ); ?></span>
											<?php else : ?>
												<span class="missing">محصولی با این SKU یافت نشد</span>
											<?php endif; ?>
										</div>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</div>
					</section>

					<!-- ================= متن‌ها ================= -->
					<section class="wcsp-panel" data-panel="texts">
						<div class="wcsp-card">
							<h3 class="wcsp-sec">متن‌ها و نمایش</h3>
							<p class="wcsp-sec-desc">عنوان گزینه در فاکتور، ایمیل و سبد خرید استفاده می‌شود؛ متن کنار چک‌باکس در صفحه محصول نمایش داده می‌شود.</p>

							<div class="wcsp-field">
								<label class="wcsp-label" for="wcsp_label">عنوان گزینه (برچسب فاکتور)</label>
								<input type="text" id="wcsp_label" name="<?php echo esc_attr( $opt ); ?>[label]" value="<?php echo esc_attr( $s['label'] ); ?>" />
							</div>

							<div class="wcsp-field">
								<label class="wcsp-label" for="wcsp_checkbox_text">متن کنار چک‌باکس در صفحه محصول</label>
								<input type="text" id="wcsp_checkbox_text" name="<?php echo esc_attr( $opt ); ?>[checkbox_text]" value="<?php echo esc_attr( $s['checkbox_text'] ); ?>" />
							</div>

							<div class="wcsp-field">
								<span class="wcsp-label">پیش‌نمایش چک‌باکس در صفحه محصول</span>
								<div class="wcsp-preview">
									<div class="pv-tag">نمای صفحه محصول (فقط پیش‌نمایش)</div>
									<div class="pv-row">
										<input type="checkbox" checked disabled />
										<span>
											<?php echo esc_html( $s['checkbox_text'] ); ?>
											<span class="pv-price">+ <?php echo wp_kses_post( wc_price( $s['price'] ) ); ?> به ازای هر عدد</span>
										</span>
									</div>
								</div>
							</div>
						</div>
					</section>

					<!-- ================= راهنما ================= -->
					<section class="wcsp-panel" data-panel="help">
						<div class="wcsp-card wcsp-help">
							<h3 class="wcsp-sec">راهنمای استفاده</h3>
							<p class="wcsp-sec-desc">خلاصه رفتار افزونه و نکات مهم.</p>
							<ul>
								<li><b>تشخیص خودکار:</b> عنوان محصول (والد، در محصولات متغیر) با کلمه کلیدی یا دسته‌بندی‌های انتخابی مطابقت کند.</li>
								<li><b>محاسبه قیمت:</b> مبلغ پکیج به قیمت هر واحد اضافه می‌شود، پس با تغییر تعداد در سبد خرید به‌درستی ضرب می‌شود.</li>
								<li><b>فاکتور و ایمیل:</b> زیر همان آیتم محصول نمایش داده می‌شود: «بله — X در هر عدد × N عدد = Y».</li>
								<li><b>امنیت:</b> واجد شرایط بودن هنگام افزودن به سبد، دوباره در سمت سرور بررسی می‌شود.</li>
								<li><b>اولویت استثنا:</b> لیست SKU بر همه قوانین (حتی «اجباراً فعال» دستی) مقدم است.</li>
								<li><b>سبد/پرداخت بلوکی:</b> چک‌باکس با صفحات کلاسیک (shortcode) کار می‌کند؛ اگر فروشگاه از بلوک‌های جدید استفاده می‌کند، صفحات سبد و پرداخت را به حالت کلاسیک برگردانید.</li>
								<li><b>کش آمار:</b> اعداد داشبورد تا یک ساعت کش می‌شوند و با ذخیره محصول یا تغییر سفارش‌ها خودکار تازه می‌شوند.</li>
							</ul>
						</div>
					</section>

					<div class="wcsp-savebar">
						<button type="submit" class="wcsp-btn">ذخیره تنظیمات</button>
						<span class="note">تغییرات بلافاصله روی همه محصولات (قدیمی و جدید) اعمال می‌شود.</span>
					</div>
				</div>
			</form>
			<div class="wcsp-credit">ساخته شده توسط علیرضا شعبان زاده</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * ابزارهای تشخیص
	 * ----------------------------------------------------------------*/

	/**
	 * نرمال‌سازی متن فارسی/عربی برای تطبیق مطمئن‌تر.
	 */
	public static function normalize_text( $text ) {
		$text = (string) $text;
		// یکسان‌سازی ی/ک عربی و انواع الف.
		$map = array(
			'ي' => 'ی',
			'ك' => 'ک',
			'إ' => 'ا',
			'أ' => 'ا',
			'ٱ' => 'ا',
			'ة' => 'ه',
			'ؤ' => 'و',
			'ٔ' => '',
		);
		$text = str_replace( array_keys( $map ), array_values( $map ), $text );
		// حذف نیم‌فاصله، کشیده و فاصله‌های اضافی.
		$text = str_replace( array( "\u{200C}", "\u{200F}", "\u{200E}", "\u{0640}" ), array( ' ', '', '', '' ), $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return mb_strtolower( trim( $text ), 'UTF-8' );
	}

	/**
	 * تبدیل رشته چندخطی/کاماجدا به آرایه.
	 */
	public static function parse_list( $raw ) {
		$raw   = (string) $raw;
		$parts = preg_split( '/[\n\r,،]+/u', $raw );
		$out   = array();
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' !== $part ) {
				$out[] = $part;
			}
		}
		return $out;
	}

	/**
	 * آیا محصول واجد شرایط پکیج ویژه است؟
	 *
	 * ترتیب اولویت:
	 * ۱) غیرفعال بودن کلی قابلیت
	 * ۲) لیست استثنا بر اساس SKU (همیشه برنده است)
	 * ۳) حالت دستی روی محصول (اجباراً فعال / اجباراً غیرفعال)
	 * ۴) دسته‌بندی واجد شرایط
	 * ۵) کلمه کلیدی در عنوان
	 *
	 * @param int|WC_Product $product محصول یا شناسه آن (می‌تواند واریاسیون باشد).
	 */
	public static function is_eligible( $product ) {
		if ( ! function_exists( 'WC' ) || ! WC() ) {
			return false;
		}

		$settings = self::get_settings();

		if ( 'yes' !== $settings['enabled'] || (float) $settings['price'] <= 0 ) {
			return false;
		}

		if ( is_numeric( $product ) ) {
			$product = wc_get_product( (int) $product );
		}
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		// محصول والد (برای واریاسیون‌ها تشخیص بر اساس والد انجام می‌شود).
		$parent = $product;
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( ! $parent instanceof WC_Product ) {
				$parent = $product;
			}
		}

		// ۲) لیست استثنا بر اساس SKU — همیشه اولویت دارد.
		$exceptions = array_map( array( __CLASS__, 'normalize_text' ), self::parse_list( $settings['sku_exceptions'] ) );
		if ( ! empty( $exceptions ) ) {
			$skus = array( $parent->get_sku(), $product->get_sku() );
			foreach ( $skus as $sku ) {
				$sku = self::normalize_text( $sku );
				if ( '' !== $sku && in_array( $sku, $exceptions, true ) ) {
					return false;
				}
			}
		}

		// ۳) حالت دستی روی محصول.
		$mode = (string) $parent->get_meta( self::PRODUCT_META, true );
		if ( 'force_off' === $mode ) {
			return false;
		}
		if ( 'force_on' === $mode ) {
			return true;
		}

		// ۴) دسته‌بندی واجد شرایط.
		$cat_ids = array_map( 'intval', (array) $settings['categories'] );
		if ( ! empty( $cat_ids ) ) {
			$product_cats = wp_get_post_terms( $parent->get_id(), 'product_cat', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $product_cats ) && array_intersect( $cat_ids, array_map( 'intval', $product_cats ) ) ) {
				return true;
			}
		}

		// ۵) کلمه کلیدی در عنوان.
		$keywords = self::parse_list( $settings['keywords'] );
		if ( ! empty( $keywords ) ) {
			$title = self::normalize_text( $parent->get_name() );
			foreach ( $keywords as $keyword ) {
				$keyword = self::normalize_text( $keyword );
				if ( '' === $keyword ) {
					continue;
				}
				if ( 'starts_with' === $settings['match_mode'] ) {
					if ( 0 === strpos( $title, $keyword ) ) {
						return true;
					}
				} elseif ( false !== strpos( $title, $keyword ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * آمار داشبورد: محصولات واجد شرایط، سفارش‌های دارای پکیج و سری ۱۴ روز اخیر.
	 * نتیجه یک ساعت کش می‌شود.
	 */
	public static function get_stats() {
		$cached = get_transient( 'wcsp_stats_v1' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$stats = array(
			'eligible'       => 0,
			'total_products' => 0,
			'exceptions'     => 0,
			'orders'         => 0,
			'revenue'        => 0.0,
			'today'          => 0,
			'series'         => array(),
		);

		for ( $i = 13; $i >= 0; $i-- ) {
			$ts             = strtotime( "-{$i} days" );
			$stats['series'][] = array(
				'label' => date_i18n( 'j F', $ts ),
				'date'  => gmdate( 'Y-m-d', $ts + ( get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS ) ),
				'count' => 0,
			);
		}

		if ( function_exists( 'WC' ) && WC() ) {
			$settings           = self::get_settings();
			$stats['exceptions'] = count( self::parse_list( $settings['sku_exceptions'] ) );

			// شمارش محصولات واجد شرایط.
			$ids = get_posts(
				array(
					'post_type'   => 'product',
					'post_status' => 'publish',
					'fields'      => 'ids',
					'numberposts' => -1,
				)
			);
			foreach ( $ids as $pid ) {
				$p = wc_get_product( $pid );
				if ( $p && self::is_eligible( $p ) ) {
					$stats['eligible']++;
				}
			}
			$stats['total_products'] = count( $ids );

			// سفارش‌های دارای پکیج.
			$orders = wc_get_orders(
				array(
					'status'  => array( 'wc-processing', 'wc-completed' ),
					'limit'   => -1,
					'orderby' => 'date',
					'order'   => 'ASC',
				)
			);
			$today  = gmdate( 'Y-m-d', time() + ( get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS ) );
			foreach ( $orders as $order ) {
				$total = 0.0;
				foreach ( $order->get_items() as $item ) {
					$v = $item->get_meta( '_wcsp_package_total', true );
					if ( '' !== $v && null !== $v ) {
						$total += (float) $v;
					}
				}
				if ( $total <= 0 ) {
					continue;
				}
				$stats['orders']++;
				$stats['revenue'] += $total;
				$d = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '';
				if ( $d === $today ) {
					$stats['today']++;
				}
				foreach ( $stats['series'] as $k => $pt ) {
					if ( $pt['date'] === $d ) {
						$stats['series'][ $k ]['count']++;
						break;
					}
				}
			}
		}

		set_transient( 'wcsp_stats_v1', $stats, HOUR_IN_SECONDS );
		return $stats;
	}

	/* ------------------------------------------------------------------
	 * فیلد دستی در صفحه ویرایش محصول
	 * ----------------------------------------------------------------*/

	public function product_field() {
		woocommerce_wp_select(
			array(
				'id'          => self::PRODUCT_META,
				'label'       => 'پکیج ویژه قاب',
				'description' => 'روی «خودکار» تشخیص بر اساس عنوان/دسته‌بندی انجام می‌شود.',
				'options'     => array(
					'auto'      => 'خودکار (پیش‌فرض)',
					'force_on'  => 'اجباراً فعال باشد',
					'force_off' => 'اجباراً فعال نباشد',
				),
			)
		);
	}

	public function save_product_field( $post_id ) {
		if ( isset( $_POST[ self::PRODUCT_META ] ) ) {
			$mode = sanitize_key( wp_unslash( $_POST[ self::PRODUCT_META ] ) );
			if ( ! in_array( $mode, array( 'auto', 'force_on', 'force_off' ), true ) ) {
				$mode = 'auto';
			}
			if ( 'auto' === $mode ) {
				delete_post_meta( $post_id, self::PRODUCT_META );
			} else {
				update_post_meta( $post_id, self::PRODUCT_META, $mode );
			}
		}
	}

	/* ------------------------------------------------------------------
	 * فرانت‌اند: چک‌باکس در صفحه محصول
	 * ----------------------------------------------------------------*/

	public function render_checkbox() {
		global $product;

		if ( ! $product instanceof WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return;
		}
		if ( ! self::is_eligible( $product ) ) {
			return;
		}

		$settings = self::get_settings();
		$price    = (float) $settings['price'];
		$text     = $settings['checkbox_text'];
		?>
		<p class="form-row form-row-wide wcsp-package-row" style="clear:both;">
			<label for="wcsp_package_checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
				<input type="checkbox" id="wcsp_package_checkbox" name="<?php echo esc_attr( self::CART_KEY ); ?>" value="1" style="width:auto; margin:0;" />
				<span>
					<?php echo esc_html( $text ); ?>
					<?php
					echo wp_kses_post(
						sprintf(
							'<span class="wcsp-package-price">+ %s به ازای هر عدد</span>',
							wc_price( $price )
						)
					);
					?>
				</span>
			</label>
		</p>
		<?php
	}

	/* ------------------------------------------------------------------
	 * سبد خرید
	 * ----------------------------------------------------------------*/

	/**
	 * ذخیره انتخاب کاربر در آیتم سبد — با بررسی مجدد واجد شرایط بودن در سمت سرور.
	 */
	public function add_cart_item_data( $cart_item_data, $product_id ) {
		if ( empty( $_POST[ self::CART_KEY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $cart_item_data;
		}

		$variation_id = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$check_id     = $variation_id ? $variation_id : absint( $product_id );

		if ( self::is_eligible( $check_id ) ) {
			$cart_item_data[ self::CART_KEY ] = 1;
		}

		return $cart_item_data;
	}

	/**
	 * نمایش در سبد خرید و صفحه پرداخت.
	 */
	public function get_item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item[ self::CART_KEY ] ) ) {
			return $item_data;
		}

		$settings = self::get_settings();
		$price    = (float) $settings['price'];
		$qty      = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 1;

		$item_data[] = array(
			'key'     => $settings['label'],
			'value'   => wp_strip_all_tags(
				sprintf(
					'بله — %s در هر عدد × %d = %s',
					wc_price( $price ),
					$qty,
					wc_price( $price * $qty )
				)
			),
			'display' => wp_kses_post(
				sprintf(
					'بله — %s در هر عدد × %d = %s',
					wc_price( $price ),
					$qty,
					wc_price( $price * $qty )
				)
			),
		);

		return $item_data;
	}

	/**
	 * افزودن قیمت پکیج به قیمت هر واحد — به‌صورت خودکار در تعداد ضرب می‌شود.
	 * محاسبه از قیمت پایه (regular/sale) انجام می‌شود تا در اجرای چندباره هوک دوبار جمع نشود.
	 */
	public function adjust_price( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( did_action( 'woocommerce_before_calculate_totals' ) >= 3 ) {
			return; // محافظت در برابر حلقه.
		}

		$settings = self::get_settings();
		$package  = (float) $settings['price'];
		if ( 'yes' !== $settings['enabled'] || $package <= 0 ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item[ self::CART_KEY ] ) || ! isset( $cart_item['data'] ) ) {
				continue;
			}

			$product = $cart_item['data'];
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			// اگر محصول دیگر واجد شرایط نیست (مثلاً تنظیمات عوض شده)، مبلغ اضافه نشود.
			if ( ! self::is_eligible( $product ) ) {
				continue;
			}

			$base = (float) ( $product->is_on_sale() ? $product->get_sale_price( 'edit' ) : $product->get_regular_price( 'edit' ) );
			$product->set_price( $base + $package );
		}
	}

	/* ------------------------------------------------------------------
	 * سفارش / فاکتور
	 * ----------------------------------------------------------------*/

	/**
	 * ذخیره متای آیتم سفارش — به‌صورت خودکار در فاکتور، ایمیل و پیشخوان نمایش داده می‌شود.
	 */
	public function save_order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values[ self::CART_KEY ] ) ) {
			return;
		}

		$settings = self::get_settings();
		$price    = (float) $settings['price'];
		$qty      = (int) $item->get_quantity();
		$total    = $price * $qty;

		$item->add_meta_data(
			$settings['label'],
			sprintf(
				'بله — %s در هر عدد × %d عدد = %s',
				wp_strip_all_tags( wc_price( $price ) ),
				$qty,
				wp_strip_all_tags( wc_price( $total ) )
			),
			true
		);

		// مبلغ عددی خالص هم برای استفاده احتمالی سایر پلاگین‌های فاکتور.
		$item->add_meta_data( '_wcsp_package_unit_price', $price, true );
		$item->add_meta_data( '_wcsp_package_total', $total, true );
	}
}

/**
 * راه‌اندازی پس از بارگذاری ووکامرس.
 */
function wcsp_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>پلاگین «پکیج ویژه قاب موبایل» برای کار کردن به ووکامرس نیاز دارد.</p></div>';
			}
		);
		return;
	}
	WC_Case_Special_Package::instance();
}
add_action( 'plugins_loaded', 'wcsp_bootstrap' );
