<?php
/**
 * پنل مدیریت TisaCase — گروه‌بندی متغیرها بر اساس برند.
 *
 * چهار تب: برندها · نمایش · رنگ‌ها · پیشرفته — به‌همراه تحلیلگر زندهٔ مدل‌ها
 * (تحلیل لیست واقعی محصولات و پیش‌نمایش زندهٔ پنل با همان CSS/JS فرانت‌اند).
 *
 * @package TisaCase_Brand_Variations
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCBV_Admin' ) ) {

	final class TCBV_Admin {

		const PAGE = 'tisacase-brand-variations';
		const CAP  = 'manage_woocommerce';

		public static function init() {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
			add_action( 'admin_post_tcbv_save', array( __CLASS__, 'handle_save' ) );
			add_action( 'admin_post_tcbv_reset', array( __CLASS__, 'handle_reset' ) );

			add_action( 'wp_ajax_tcbv_products', array( __CLASS__, 'ajax_products' ) );
			add_action( 'wp_ajax_tcbv_product_attrs', array( __CLASS__, 'ajax_product_attrs' ) );
			add_action( 'wp_ajax_tcbv_classify', array( __CLASS__, 'ajax_classify' ) );
		}

		/* ============================================================
		   منو و دارایی‌ها
		   ============================================================ */

		public static function menu() {
			add_submenu_page(
				'woocommerce',
				__( 'گروه‌بندی متغیرها بر اساس برند', 'tisacase-brand-variations' ),
				__( 'گروه‌بندی متغیرها', 'tisacase-brand-variations' ),
				self::CAP,
				self::PAGE,
				array( __CLASS__, 'render' )
			);
		}

		public static function is_our_screen() {
			if ( isset( $_GET['page'] ) && self::PAGE === sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return true;
			}
			if ( function_exists( 'get_current_screen' ) ) {
				$screen = get_current_screen();
				if ( $screen && ! empty( $screen->id ) && false !== strpos( $screen->id, self::PAGE ) ) {
					return true;
				}
			}
			return false;
		}

		public static function assets() {
			if ( ! self::is_our_screen() ) {
				return;
			}

			$settings = TCBV_Settings::get();

			$deps = array();
			if ( wp_style_is( 'tisacase-ui', 'registered' ) || wp_style_is( 'tisacase-ui', 'enqueued' ) ) {
				$deps[] = 'tisacase-ui';
				wp_enqueue_style( 'tisacase-ui' );
			}
			wp_enqueue_style( 'tcbv-admin', TCBV_URL . 'assets/admin.css', $deps, TCBV_VERSION );
			wp_enqueue_script( 'tcbv-admin', TCBV_URL . 'assets/admin.js', array(), TCBV_VERSION, true );

			// همان CSS/JS صفحهٔ محصول برای پیش‌نمایش زنده.
			wp_enqueue_style( 'tcbv-preview', TCBV_URL . 'assets/frontend.css', array(), TCBV_VERSION );
			wp_add_inline_style( 'tcbv-preview', TCBV_Frontend::css_vars( $settings ) );
			wp_enqueue_script( 'tcbv-frontend', TCBV_URL . 'assets/frontend.js', array(), TCBV_VERSION, true );
			wp_add_inline_script( 'tcbv-frontend', 'window.TCBV_CFG = ' . wp_json_encode( TCBV_Rules::js_config( $settings ) ) . ';', 'before' );

			wp_localize_script(
				'tcbv-admin',
				'TCBV_ADMIN',
				array(
					'ajax'     => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'tcbv_admin' ),
					'presets'  => TCBV_Settings::presets(),
					'colors'   => TCBV_Settings::default_color_map(),
					'strings'  => array(
						'loading'    => 'در حال خواندن…',
						'noProduct'  => 'محصولی پیدا نشد.',
						'error'      => 'خطا در ارتباط با سرور.',
						'analysing'  => 'در حال تحلیل…',
						'brand'      => 'برند',
						'unknown'    => 'ناشناس',
						'conflict'   => 'تضاد',
						'willGroup'  => 'گروه‌بندی می‌شود',
						'isColor'    => 'سواچ رنگ',
						'noGroup'    => 'دست‌نخورده',
						'confirmDel' => 'این برند حذف شود؟',
						'confirmReset' => 'همهٔ تنظیمات به پیش‌فرض برگردد؟',
						'added'      => 'اضافه شد',
						'products'   => 'محصولات متغیر',
					),
				)
			);
		}

		/* ============================================================
		   ذخیره / بازنشانی
		   ============================================================ */

		public static function handle_save() {
			if ( ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'دسترسی کافی ندارید.', 'tisacase-brand-variations' ) );
			}
			check_admin_referer( 'tcbv_save' );

			$current = TCBV_Settings::get();
			$raw     = isset( $_POST['tcbv'] ) && is_array( $_POST['tcbv'] ) ? wp_unslash( $_POST['tcbv'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- در sanitize پاک می‌شود.

			// هر بخشی که به‌کل در POST نبود (فرم ناقص/قالب عوض‌شده)، از تنظیمات فعلی پر می‌شود.
			foreach ( array( 'ui', 'theme', 'swatch', 'advanced', 'unknown', 'test' ) as $key ) {
				if ( ! isset( $raw[ $key ] ) || ! is_array( $raw[ $key ] ) ) {
					$raw[ $key ] = $current[ $key ]; // تیک‌های نخورده نباید از مقدار قبلی پر شوند.
				}
			}
			if ( empty( $raw['brands_marker'] ) ) {
				$raw['brands'] = $current['brands'];
			}

			// واردکردن JSON (اختیاری)
			if ( ! empty( $raw['import'] ) ) {
				$decoded = json_decode( (string) $raw['import'], true );
				if ( is_array( $decoded ) ) {
					$raw = array_merge( $current, $decoded );
					$raw['brands_marker'] = 1;
				}
			}
			unset( $raw['import'], $raw['export'], $raw['brands_marker'] );

			TCBV_Settings::save( $raw );

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => self::PAGE,
						'tab'        => isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'brands',
						'tcbv-saved' => 1,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		public static function handle_reset() {
			if ( ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'دسترسی کافی ندارید.', 'tisacase-brand-variations' ) );
			}
			check_admin_referer( 'tcbv_reset' );
			TCBV_Settings::save( TCBV_Settings::defaults() );
			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'tcbv-reset' => 1 ), admin_url( 'admin.php' ) ) );
			exit;
		}

		/* ============================================================
		   رندر صفحه
		   ============================================================ */

		public static function render() {
			if ( ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'دسترسی کافی ندارید.', 'tisacase-brand-variations' ) );
			}

			$settings = TCBV_Settings::get();
			$tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'brands'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$tabs     = array(
				'brands'   => 'برندها',
				'display'  => 'نمایش',
				'colors'   => 'رنگ‌ها',
				'advanced' => 'پیشرفته',
			);
			if ( ! isset( $tabs[ $tab ] ) ) {
				$tab = 'brands';
			}
			$base      = admin_url( 'admin.php?page=' . self::PAGE );
			$test_on   = TCBV_Settings::test_mode_on( $settings );
			$test_ids  = TCBV_Settings::test_products( $settings );
			$test_pill = $test_on
				? ( $test_ids ? 'حالت تست · ' . number_format_i18n( count( $test_ids ) ) . ' محصول' : 'حالت تست · بدون محصول' )
				: 'همهٔ محصولات متغیر';
			?>
			<div class="wrap tcbv-wrap tisa-scope" dir="rtl">

				<header class="tcbv-hero">
					<div class="tcbv-hero-row">
						<div class="tcbv-hero-mark" aria-hidden="true">
							<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
								<path d="M4 6h16M4 12h16M4 18h16"/><circle cx="9" cy="6" r="1.6" fill="currentColor" stroke="none"/><circle cx="15" cy="12" r="1.6" fill="currentColor" stroke="none"/><circle cx="11" cy="18" r="1.6" fill="currentColor" stroke="none"/>
							</svg>
						</div>
						<div class="tcbv-hero-text">
							<h1 class="tcbv-hero-title">گروه‌بندی متغیرها بر اساس برند</h1>
							<p class="tcbv-hero-sub">لیست تخت مدل‌ها در صفحهٔ محصول → آیفون، خط جداکننده، سامسونگ، خط جداکننده، شیائومی؛ با پنل جستجو و سواچ رنگ. هیچ محصول یا متغیری تغییر نمی‌کند.</p>
						</div>
						<span class="tcbv-hero-ver" dir="ltr">v<?php echo esc_html( TCBV_VERSION ); ?></span>
						<span class="tcbv-hero-pill<?php echo $test_on ? ' is-test' : ''; ?>" id="tcbv-hero-pill"><?php echo esc_html( $test_pill ); ?></span>
					</div>
					<nav class="tcbv-tabs" role="tablist">
						<?php foreach ( $tabs as $key => $label ) : ?>
							<a class="tcbv-tab<?php echo $tab === $key ? ' is-active' : ''; ?>"
								href="<?php echo esc_url( add_query_arg( 'tab', $key, $base ) ); ?>"
								role="tab" aria-selected="<?php echo $tab === $key ? 'true' : 'false'; ?>">
								<?php echo esc_html( $label ); ?>
							</a>
						<?php endforeach; ?>
					</nav>
				</header>

				<?php if ( isset( $_GET['tcbv-saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<div class="notice notice-success is-dismissible"><p>تنظیمات ذخیره شد.</p></div>
				<?php endif; ?>
				<?php if ( isset( $_GET['tcbv-reset'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<div class="notice notice-success is-dismissible"><p>تنظیمات به پیش‌فرض برگشت.</p></div>
				<?php endif; ?>
				<?php if ( $test_on && ! $test_ids ) : ?>
					<div class="notice notice-warning"><p><strong>حالت تست روشن است ولی هنوز محصولی انتخاب نشده؛</strong> بنابراین افزونه فعلاً روی هیچ محصولی در فروشگاه اجرا نمی‌شود. در کارت «حالت تست» زیر، محصول‌های آزمایشی را اضافه کنید.</p></div>
				<?php elseif ( $test_on ) : ?>
					<div class="notice notice-info"><p><strong>حالت تست روشن است:</strong> افزونه فقط روی <?php echo esc_html( number_format_i18n( count( $test_ids ) ) ); ?> محصول انتخاب‌شده اجرا می‌شود. برای اعمال روی کل فروشگاه، تیک «حالت تست» را بردارید (یا دکمهٔ «روشن‌کردن برای کل فروشگاه» را بزنید).</p></div>
				<?php endif; ?>
				<?php if ( ! empty( $settings['advanced']['safe_mode'] ) ) : ?>
					<div class="notice notice-warning"><p><strong>حالت ایمن روشن است:</strong> فقط مرتب‌سازی سرور (optgroup) انجام می‌شود و پنل جستجوپذیر سمت کاربر خاموش است.</p></div>
				<?php endif; ?>

				<div class="tcbv-layout">
					<form class="tcbv-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="tcbv_save">
						<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
						<input type="hidden" name="tcbv[brands_marker]" value="1">
						<?php wp_nonce_field( 'tcbv_save' ); ?>

						<?php
						self::section_test( $settings );
						self::section_brands( $settings, $tab );
						self::section_display( $settings, $tab );
						self::section_colors( $settings, $tab );
						self::section_advanced( $settings, $tab );
						?>

						<div class="tcbv-actions">
							<button type="submit" class="button button-primary button-hero">ذخیرهٔ تنظیمات</button>
							<span class="tcbv-muted">تغییرات فقط روی نمایش صفحهٔ محصول اثر می‌گذارد.</span>
						</div>
					</form>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tcbv-reset-form" id="tcbv-reset-form">
						<input type="hidden" name="action" value="tcbv_reset">
						<?php wp_nonce_field( 'tcbv_reset' ); ?>
						<button type="submit" class="button button-link-delete">بازنشانی همهٔ تنظیمات به پیش‌فرض</button>
					</form>

					<aside class="tcbv-side">
						<div class="tcbv-card tcbv-card--sticky">
							<div class="tcbv-card-head">
								<span class="tcbv-step">پیش‌نمایش زنده</span>
								<span class="tcbv-hint">با تغییر تنظیمات، همان لحظه به‌روز می‌شود</span>
							</div>
							<div class="tcbv-card-body">
								<div class="tcbv-preview" id="tcbv-preview" data-sample="1">
									<p class="tcbv-muted">در حال ساخت پیش‌نمایش…</p>
								</div>
								<div class="tcbv-preview-tools">
									<label class="tcbv-check">
										<input type="checkbox" id="tcbv-preview-real">
										<span>پیش‌نمایش با مدل‌های واقعی یک محصول</span>
									</label>
									<div class="tcbv-row" id="tcbv-preview-picker" hidden>
										<input type="search" id="tcbv-product-search" class="tcbv-input" placeholder="جستجوی نام محصول…">
										<button type="button" class="button" id="tcbv-product-search-btn">جستجو</button>
									</div>
									<div id="tcbv-product-results" class="tcbv-results" hidden></div>
								</div>
							</div>
						</div>
					</aside>
				</div>
			</div>
			<?php
		}

		/* ============================================================
		   کارت «حالت تست» — همیشه بالای همهٔ تب‌ها (بخشی از همان فرم)
		   ============================================================ */

		private static function section_test( $settings ) {
			$t   = isset( $settings['test'] ) ? $settings['test'] : array();
			$on  = ! empty( $t['enabled'] );
			$ids = TCBV_Settings::test_products( $settings );
			?>
			<section class="tcbv-card tcbv-card--test<?php echo $on ? ' is-on' : ''; ?>" id="tcbv-test-card" data-tab="test">
				<div class="tcbv-card-head">
					<span class="tcbv-step tcbv-step--test">تست</span>
					<div class="tcbv-test-head">
						<h2>حالت تست — فعلاً فقط روی محصول‌هایی که خودتان انتخاب می‌کنید</h2>
						<p class="tcbv-hint">تا وقتی این حالت روشن است، افزونه روی هیچ محصول دیگری اثر نمی‌گذارد — نه پنل سمت کاربر، نه مرتب‌سازی سرور. محصول‌های آزمایشی را پایین اضافه کنید و نتیجه را همان‌جا ببینید؛ هر وقت راضی بودید، با یک کلیک برای کل فروشگاه روشن کنید.</p>
					</div>
					<span class="tcbv-status" id="tcbv-test-status" data-on="<?php echo $on ? '1' : '0'; ?>">…</span>
				</div>
				<div class="tcbv-card-body">
					<div class="tcbv-test-top">
						<label class="tcbv-switch">
							<input type="checkbox" id="tcbv-test-enabled" name="tcbv[test][enabled]" value="1" <?php checked( $on ); ?>>
							<span class="tcbv-switch-ui" aria-hidden="true"></span>
							<span class="tcbv-switch-text">حالت تست</span>
						</label>
						<label class="tcbv-check">
							<input type="checkbox" name="tcbv[test][badge]" value="1" <?php checked( ! empty( $t['badge'] ) ); ?>>
							<span>نشان «حالت تست» روی صفحهٔ محصول (فقط برای مدیر، بازدیدکنندهٔ عادی نمی‌بیند)</span>
						</label>
						<button type="button" class="button" id="tcbv-test-all">روشن‌کردن برای کل فروشگاه</button>
					</div>

					<div class="tcbv-row tcbv-test-search">
						<input type="search" id="tcbv-test-search" class="tcbv-input" placeholder="نام محصول آزمایشی (مثلاً قاب TS184)…">
						<button type="button" class="button" id="tcbv-test-search-btn">جستجوی محصول</button>
					</div>
					<div id="tcbv-test-results" class="tcbv-results" hidden></div>

					<div class="tcbv-test-chips" id="tcbv-test-chips">
						<?php foreach ( $ids as $id ) : ?>
							<?php self::test_chip( $id ); ?>
						<?php endforeach; ?>
					</div>
					<p class="tcbv-muted" id="tcbv-test-empty"><?php echo $ids ? 'برای حذف یک محصول، روی × همان چیپ بزنید.' : 'هنوز محصولی انتخاب نشده — در این وضعیت افزونه روی هیچ محصولی اجرا نمی‌شود.'; ?></p>
				</div>
			</section>
			<?php
		}

		/**
		 * یک چیپ محصول آزمایشی (شامل input مخفی برای ذخیره).
		 *
		 * @param int $id شناسهٔ محصول.
		 */
		private static function test_chip( $id ) {
			$id    = (int) $id;
			$post  = get_post( $id );
			$title = $post ? (string) get_the_title( $id ) : '';
			if ( '' === trim( $title ) ) {
				$title = $post ? 'محصول بدون عنوان' : 'محصول پیدا نشد';
			}
			$link = $post ? get_permalink( $id ) : '';
			?>
			<span class="tcbv-test-chip" data-id="<?php echo esc_attr( $id ); ?>">
				<input type="hidden" name="tcbv[test][products][]" value="<?php echo esc_attr( $id ); ?>">
				<span class="tcbv-test-chip-t"><?php echo esc_html( $title ); ?><em dir="ltr">#<?php echo esc_html( $id ); ?></em></span>
				<?php if ( $link ) : ?>
					<a class="tcbv-test-link" href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener noreferrer">مشاهده</a>
				<?php endif; ?>
				<button type="button" class="tcbv-test-del" aria-label="حذف محصول">×</button>
			</span>
			<?php
		}

		/* ============================================================
		   تب ۱ — برندها
		   ============================================================ */

		private static function section_brands( $settings, $tab ) {
			$brands = isset( $settings['brands'] ) ? $settings['brands'] : array();
			?>
			<section class="tcbv-card<?php echo 'brands' === $tab ? '' : ' is-hidden'; ?>" data-tab="brands">
				<div class="tcbv-card-head">
					<span class="tcbv-step">۱</span>
					<div>
						<h2>برندها و قواعد تشخیص</h2>
						<p class="tcbv-hint">ترتیب ردیف‌ها = ترتیب نمایش در صفحهٔ محصول = اولویت تطبیق. اگر مدلی با چند برند بخواند، بالاترین برند برنده است. «فهرست دستی» همیشه بر قواعد دیگر مقدم است.</p>
					</div>
					<label class="tcbv-switch">
						<input type="checkbox" name="tcbv[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
						<span>افزونه فعال باشد</span>
					</label>
				</div>

				<div class="tcbv-card-body">
					<div class="tcbv-brands" id="tcbv-brands">
						<?php foreach ( $brands as $index => $brand ) { self::brand_row( $index, $brand ); } ?>
					</div>

					<div class="tcbv-row tcbv-row--between">
						<div class="tcbv-row">
							<select id="tcbv-preset" class="tcbv-input">
								<option value="">افزودن برند آماده…</option>
								<?php foreach ( TCBV_Settings::presets() as $id => $preset ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $preset['label'] ); ?></option>
								<?php endforeach; ?>
								<option value="custom">برند دلخواه…</option>
							</select>
							<button type="button" class="button" id="tcbv-add-preset">افزودن</button>
						</div>
						<button type="button" class="button" id="tcbv-add-brand">+ برند خالی</button>
					</div>

					<hr class="tcbv-hr">

					<h3 class="tcbv-h3">مدل‌های ناشناس</h3>
					<p class="tcbv-hint">هر مدلی که با هیچ قاعده‌ای نخواند، اینجا می‌افتد — با عنوان و رنگ خودش.</p>
					<div class="tcbv-grid-3">
						<label class="tcbv-field">
							<span>عنوان گروه</span>
							<input type="text" class="tcbv-input" name="tcbv[unknown][label]" value="<?php echo esc_attr( $settings['unknown']['label'] ); ?>">
						</label>
						<label class="tcbv-field">
							<span>رنگ</span>
							<input type="color" class="tcbv-color" name="tcbv[unknown][color]" value="<?php echo esc_attr( $settings['unknown']['color'] ); ?>">
						</label>
						<label class="tcbv-field">
							<span>جایگاه</span>
							<select class="tcbv-input" name="tcbv[unknown][position]">
								<option value="last" <?php selected( 'last', $settings['unknown']['position'] ); ?>>آخر فهرست</option>
								<option value="first" <?php selected( 'first', $settings['unknown']['position'] ); ?>>ابتدای فهرست</option>
							</select>
						</label>
					</div>
					<label class="tcbv-check">
						<input type="checkbox" name="tcbv[unknown][enabled]" value="1" <?php checked( ! empty( $settings['unknown']['enabled'] ) ); ?>>
						<span>نمایش گروه ناشناس‌ها</span>
					</label>

					<hr class="tcbv-hr">

					<h3 class="tcbv-h3">تحلیلگر مدل‌ها</h3>
					<p class="tcbv-hint">لیست واقعی مدل‌های یک محصول را بخوانید و ببینید کدام برند می‌شود و کدام «ناشناس» می‌ماند. مدل‌های ناشناس را همان‌جا با یک کلیک به برند دلخواه بچسبانید (به «فهرست دستی» آن برند اضافه می‌شود).</p>
					<div class="tcbv-row">
						<button type="button" class="button button-primary" id="tcbv-analyse">تحلیل لیست زیر</button>
						<button type="button" class="button" id="tcbv-load-product">بارگذاری مدل‌های یک محصول…</button>
						<button type="button" class="button" id="tcbv-clear-list">پاک کردن</button>
					</div>
					<div class="tcbv-row" id="tcbv-product-picker" hidden>
						<input type="search" class="tcbv-input" id="tcbv-analyse-search" placeholder="نام محصول متغیر… (مثلاً قاب اسپیس)">
						<button type="button" class="button" id="tcbv-analyse-search-btn">جستجو</button>
					</div>
					<div id="tcbv-analyse-products" class="tcbv-results" hidden></div>

					<textarea id="tcbv-test-input" class="tcbv-textarea" rows="6" placeholder="هر مدل در یک خط — مثلاً:&#10;iPhone 15 Pro Max&#10;A55&#10;Redmi Note 13 Pro 5G"></textarea>
					<div id="tcbv-analyse-out" class="tcbv-analyse" hidden></div>
				</div>
			</section>

			<template id="tcbv-brand-template">
				<?php self::brand_row( '__INDEX__', array( 'label' => '', 'color' => '#94A3B8', 'icon' => '', 'enabled' => 1, 'keywords' => '', 'regex' => '', 'exact' => '' ) ); ?>
			</template>
			<?php
		}

		/**
		 * یک ردیف برند.
		 *
		 * @param int|string $index شماره/جای‌نگهدار.
		 * @param array      $brand دادهٔ برند.
		 */
		private static function brand_row( $index, $brand ) {
			$brand = array_merge(
				array( 'id' => '', 'label' => '', 'color' => '#94A3B8', 'icon' => '', 'enabled' => 1, 'keywords' => '', 'regex' => '', 'exact' => '' ),
				$brand
			);
			$name = 'tcbv[brands][' . $index . ']';
			?>
			<div class="tcbv-brand" data-index="<?php echo esc_attr( $index ); ?>">
				<div class="tcbv-brand-head">
					<span class="tcbv-drag" title="ترتیب">⠿</span>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( $brand['id'] ); ?>" data-field="id">
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>[enabled]" value="0">
					<label class="tcbv-check" title="فعال">
						<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( ! empty( $brand['enabled'] ) ); ?> data-field="enabled">
					</label>
					<input type="text" class="tcbv-input tcbv-brand-label" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $brand['label'] ); ?>" placeholder="نام برند (مثلاً آیفون)" data-field="label">
					<input type="color" class="tcbv-color" name="<?php echo esc_attr( $name ); ?>[color]" value="<?php echo esc_attr( $brand['color'] ); ?>" data-field="color" title="رنگ برند">
					<input type="text" class="tcbv-input tcbv-brand-icon" name="<?php echo esc_attr( $name ); ?>[icon]" value="<?php echo esc_attr( $brand['icon'] ); ?>" placeholder="آیکن" data-field="icon" title="آیکن یا ایموجی (اختیاری)">
					<span class="tcbv-brand-actions">
						<button type="button" class="button-link tcbv-up" title="بالا">▲</button>
						<button type="button" class="button-link tcbv-down" title="پایین">▼</button>
						<button type="button" class="button-link tcbv-del" title="حذف">✕</button>
					</span>
				</div>
				<div class="tcbv-grid-3 tcbv-brand-rules">
					<label class="tcbv-field">
						<span>کلیدواژه‌ها (هر خط یکی) <em>مثلاً iphone</em></span>
						<textarea class="tcbv-textarea" rows="4" name="<?php echo esc_attr( $name ); ?>[keywords]" data-field="keywords"><?php echo esc_textarea( $brand['keywords'] ); ?></textarea>
					</label>
					<label class="tcbv-field">
						<span>الگو (regex، هر خط یکی)</span>
						<textarea class="tcbv-textarea" rows="4" name="<?php echo esc_attr( $name ); ?>[regex]" data-field="regex" dir="ltr"><?php echo esc_textarea( $brand['regex'] ); ?></textarea>
					</label>
					<label class="tcbv-field">
						<span>فهرست دستی مدل‌ها (هر خط یکی، دقیق)</span>
						<textarea class="tcbv-textarea" rows="4" name="<?php echo esc_attr( $name ); ?>[exact]" data-field="exact"><?php echo esc_textarea( $brand['exact'] ); ?></textarea>
					</label>
				</div>
			</div>
			<?php
		}

		/* ============================================================
		   تب ۲ — نمایش
		   ============================================================ */

		private static function section_display( $settings, $tab ) {
			$ui = $settings['ui'];
			?>
			<section class="tcbv-card<?php echo 'display' === $tab ? '' : ' is-hidden'; ?>" data-tab="display">
				<div class="tcbv-card-head">
					<span class="tcbv-step">۲</span>
					<div>
						<h2>شکل نمایش در صفحهٔ محصول</h2>
						<p class="tcbv-hint">پیش‌فرض «پنل جستجوپذیر» است: عنوان برند، خط جداکننده، شمارش، جستجو و فیلتر برند. حالت «فقط مرتب‌سازی» بدون جاوااسکریپت هم کار می‌کند (optgroup).</p>
					</div>
				</div>
				<div class="tcbv-card-body">
					<div class="tcbv-grid-3">
						<label class="tcbv-field">
							<span>حالت نمایش</span>
							<select class="tcbv-input" name="tcbv[ui][mode]">
								<option value="panel" <?php selected( 'panel', $ui['mode'] ); ?>>پنل جستجوپذیر (پیشنهادی)</option>
								<option value="native" <?php selected( 'native', $ui['mode'] ); ?>>فقط مرتب‌سازی داخل دراپ‌داون</option>
								<option value="off" <?php selected( 'off', $ui['mode'] ); ?>>خاموش</option>
							</select>
						</label>
						<label class="tcbv-field">
							<span>چیدمان گزینه‌ها</span>
							<select class="tcbv-input" name="tcbv[ui][layout]">
								<option value="chips" <?php selected( 'chips', $ui['layout'] ); ?>>چیپ‌های کنار هم (پیشنهادی)</option>
								<option value="list" <?php selected( 'list', $ui['layout'] ); ?>>لیست تک‌ستونی</option>
								<option value="grid" <?php selected( 'grid', $ui['layout'] ); ?>>دو ستونه</option>
							</select>
						</label>
						<label class="tcbv-field">
							<span>جداکنندهٔ بین برندها</span>
							<select class="tcbv-input" name="tcbv[ui][separator]">
								<option value="line" <?php selected( 'line', $ui['separator'] ); ?>>خط ساده</option>
								<option value="dashed" <?php selected( 'dashed', $ui['separator'] ); ?>>خط‌چین</option>
								<option value="gradient" <?php selected( 'gradient', $ui['separator'] ); ?>>خط محو (گرادیان)</option>
								<option value="label" <?php selected( 'label', $ui['separator'] ); ?>>خط با نام برند روی آن</option>
								<option value="space" <?php selected( 'space', $ui['separator'] ); ?>>فقط فاصله</option>
								<option value="none" <?php selected( 'none', $ui['separator'] ); ?>>بدون جداکننده</option>
							</select>
						</label>
						<label class="tcbv-field">
							<span>شکل عنوان برند</span>
							<select class="tcbv-input" name="tcbv[ui][group_style]">
								<option value="header" <?php selected( 'header', $ui['group_style'] ); ?>>سرصفحهٔ چسبان</option>
								<option value="pill" <?php selected( 'pill', $ui['group_style'] ); ?>>قرص رنگی</option>
							</select>
						</label>
						<label class="tcbv-field">
							<span>ترتیب مدل‌ها داخل هر برند</span>
							<select class="tcbv-input" name="tcbv[ui][sort]">
								<option value="asis" <?php selected( 'asis', $ui['sort'] ); ?>>همان ترتیب فروشگاه (پیشنهادی)</option>
								<option value="asc" <?php selected( 'asc', $ui['sort'] ); ?>>طبیعی صعودی (۶، ۷، ۸…)</option>
								<option value="desc" <?php selected( 'desc', $ui['sort'] ); ?>>برعکس (جدیدترین‌ها اول)</option>
							</select>
						</label>
						<label class="tcbv-field">
							<span>ارتفاع پنل (px)</span>
							<input type="number" class="tcbv-input" min="140" max="900" step="10" name="tcbv[ui][max_height]" value="<?php echo esc_attr( $ui['max_height'] ); ?>">
						</label>
						<label class="tcbv-field">
							<span>جای نگه‌دار جستجو</span>
							<input type="text" class="tcbv-input" name="tcbv[ui][search_placeholder]" value="<?php echo esc_attr( $ui['search_placeholder'] ); ?>">
						</label>
						<label class="tcbv-field">
							<span>مدل‌های ناموجود</span>
							<select class="tcbv-input" name="tcbv[ui][oos]">
								<option value="0" <?php selected( 0, (int) $ui['oos'] ); ?>>دست‌نخورده</option>
								<option value="1" <?php selected( 1, (int) $ui['oos'] ); ?>>کم‌رنگ و خط‌خورده</option>
								<option value="2" <?php selected( 2, (int) $ui['oos'] ); ?>>پنهان شود</option>
							</select>
						</label>
					</div>

					<div class="tcbv-toggles">
						<?php
						$toggles = array(
							'search'           => 'جعبهٔ جستجو',
							'counts'           => 'شمارش مدل هر برند',
							'sticky'           => 'عنوان برند چسبان (هنگام اسکرول)',
							'chips'            => 'چیپ فیلتر برند بالای پنل',
							'colors_on_items'  => 'رنگ برند روی خود گزینه‌ها',
							'show_label'       => 'نمایش برچسب ویژگی در نوار بالا',
							'highlight'        => 'سایهٔ گزینهٔ انتخاب‌شده',
							'fa_digits'        => 'اعداد فارسی (۱۲۳)',
						);
						foreach ( $toggles as $key => $label ) :
							?>
							<label class="tcbv-check">
								<input type="checkbox" name="tcbv[ui][<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $ui[ $key ] ) ); ?>>
								<span><?php echo esc_html( $label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			</section>
			<?php
		}

		/* ============================================================
		   تب ۳ — رنگ‌ها
		   ============================================================ */

		private static function section_colors( $settings, $tab ) {
			$t      = $settings['theme'];
			$swatch = $settings['swatch'];
			?>
			<section class="tcbv-card<?php echo 'colors' === $tab ? '' : ' is-hidden'; ?>" data-tab="colors">
				<div class="tcbv-card-head">
					<span class="tcbv-step">۳</span>
					<div>
						<h2>رنگ‌ها و سواچ</h2>
						<p class="tcbv-hint">رنگ‌های پنل و رنگ هر برند (در تب برندها). ویژگی «رنگ» هم به سواچ رنگی تبدیل می‌شود.</p>
					</div>
				</div>
				<div class="tcbv-card-body">
					<h3 class="tcbv-h3">رنگ‌بندی پنل</h3>
					<div class="tcbv-grid-4">
						<?php
						$theme_fields = array(
							'accent'   => 'رنگ تأکید',
							'bg'       => 'پس‌زمینه',
							'bg_alt'   => 'پس‌زمینهٔ گزینه',
							'border'   => 'خط و حاشیه',
							'text'     => 'متن',
							'muted'    => 'متن کم‌رنگ',
							'sep'      => 'رنگ جداکننده',
							'hover'    => 'پس‌زمینهٔ هاور',
							'sel_bg'   => 'پس‌زمینهٔ انتخاب‌شده',
							'sel_text' => 'متن انتخاب‌شده',
						);
						foreach ( $theme_fields as $key => $label ) :
							?>
							<label class="tcbv-field tcbv-field--color">
								<span><?php echo esc_html( $label ); ?></span>
								<span class="tcbv-colorwrap">
									<input type="color" class="tcbv-color" name="tcbv[theme][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $t[$key] ); ?>">
									<code dir="ltr"><?php echo esc_html( $t[ $key ] ); ?></code>
								</span>
							</label>
						<?php endforeach; ?>
					</div>

					<div class="tcbv-grid-4">
						<label class="tcbv-field">
							<span>گردی گوشه‌ها (px)</span>
							<input type="number" class="tcbv-input" min="0" max="30" name="tcbv[theme][radius]" value="<?php echo esc_attr( $t['radius'] ); ?>">
						</label>
						<label class="tcbv-field">
							<span>اندازهٔ قلم (px)</span>
							<input type="number" class="tcbv-input" min="10" max="22" name="tcbv[theme][font]" value="<?php echo esc_attr( $t['font'] ); ?>">
						</label>
						<label class="tcbv-field">
							<span>فاصلهٔ داخلی گزینه (px)</span>
							<input type="number" class="tcbv-input" min="2" max="24" name="tcbv[theme][item_pad]" value="<?php echo esc_attr( $t['item_pad'] ); ?>">
						</label>
						<label class="tcbv-check tcbv-check--boxed">
							<input type="checkbox" name="tcbv[theme][shadow]" value="1" <?php checked( ! empty( $t['shadow'] ) ); ?>>
							<span>سایهٔ ملایم پنل</span>
						</label>
					</div>

					<hr class="tcbv-hr">

					<h3 class="tcbv-h3">سواچ ویژگی «رنگ»</h3>
					<div class="tcbv-grid-4">
						<label class="tcbv-field">
							<span>شکل سواچ</span>
							<select class="tcbv-input" name="tcbv[swatch][shape]">
								<option value="circle" <?php selected( 'circle', $swatch['shape'] ); ?>>دایره</option>
								<option value="square" <?php selected( 'square', $swatch['shape'] ); ?>>مربع گرد</option>
								<option value="pill" <?php selected( 'pill', $swatch['shape'] ); ?>>قرص پهن</option>
								<option value="dot" <?php selected( 'dot', $swatch['shape'] ); ?>>نقطهٔ کوچک</option>
							</select>
						</label>
						<label class="tcbv-field">
							<span>اندازه (px)</span>
							<input type="number" class="tcbv-input" min="14" max="60" name="tcbv[swatch][size]" value="<?php echo esc_attr( $swatch['size'] ); ?>">
						</label>
						<label class="tcbv-field">
							<span>رنگ فالبک (نام ناشناس)</span>
							<span class="tcbv-colorwrap">
								<input type="color" class="tcbv-color" name="tcbv[swatch][fallback]" value="<?php echo esc_attr( $swatch['fallback'] ); ?>">
								<code dir="ltr"><?php echo esc_html( $swatch['fallback'] ); ?></code>
							</span>
						</label>
						<label class="tcbv-check tcbv-check--boxed">
							<input type="checkbox" name="tcbv[swatch][enabled]" value="1" <?php checked( ! empty( $swatch['enabled'] ) ); ?>>
							<span>سواچ رنگ فعال باشد</span>
						</label>
						<label class="tcbv-check tcbv-check--boxed">
							<input type="checkbox" name="tcbv[swatch][show_label]" value="1" <?php checked( ! empty( $swatch['show_label'] ) ); ?>>
							<span>نمایش نام رنگ زیر سواچ</span>
						</label>
					</div>

					<label class="tcbv-field">
						<span>نقشهٔ رنگ‌ها — «نام: کد» در هر خط. برای طرح‌دار <code>@multi</code> و برای شفاف <code>@clear</code> بگذارید.</span>
						<textarea class="tcbv-textarea" rows="10" name="tcbv[swatch][map]" id="tcbv-color-map"><?php echo esc_textarea( $swatch['map'] ); ?></textarea>
					</label>
					<div class="tcbv-row">
						<button type="button" class="button" id="tcbv-add-colors">افزودن رنگ‌های پرکاربرد فارسی</button>
						<button type="button" class="button" id="tcbv-reset-colors">بازگشت به فهرست پیش‌فرض</button>
						<span class="tcbv-muted">رنگ‌های تکراری نادیده گرفته می‌شوند.</span>
					</div>
				</div>
			</section>
			<?php
		}

		/* ============================================================
		   تب ۴ — پیشرفته
		   ============================================================ */

		private static function section_advanced( $settings, $tab ) {
			$a = $settings['advanced'];
			?>
			<section class="tcbv-card<?php echo 'advanced' === $tab ? '' : ' is-hidden'; ?>" data-tab="advanced">
				<div class="tcbv-card-head">
					<span class="tcbv-step">۴</span>
					<div>
						<h2>پیشرفته</h2>
						<p class="tcbv-hint">دامنهٔ اثر، ویژگی‌های هدف و ابزارهای نگه‌داری. هیچ‌کدام از این‌ها به محصولات دست نمی‌زند.</p>
					</div>
				</div>
				<div class="tcbv-card-body">
					<div class="tcbv-grid-3">
						<label class="tcbv-field">
							<span>دامنهٔ تشخیص ویژگی‌ها</span>
							<select class="tcbv-input" name="tcbv[group_scope]">
								<option value="auto" <?php selected( 'auto', $settings['group_scope'] ); ?>>خودکار (هر ویژگی‌ای که حداقل دو برند در آن دیده شود)</option>
								<option value="list" <?php selected( 'list', $settings['group_scope'] ); ?>>فقط ویژگی‌های فهرست زیر</option>
							</select>
						</label>
						<label class="tcbv-field">
							<span>حداقل تعداد برند برای گروه‌بندی خودکار</span>
							<input type="number" class="tcbv-input" min="1" max="6" name="tcbv[min_brands]" value="<?php echo esc_attr( $settings['min_brands'] ); ?>">
						</label>
						<label class="tcbv-field">
							<span>حداقل تعداد گزینه</span>
							<input type="number" class="tcbv-input" min="1" max="200" name="tcbv[min_options]" value="<?php echo esc_attr( $settings['min_options'] ); ?>">
						</label>
					</div>

					<div class="tcbv-grid-3">
						<label class="tcbv-field">
							<span>ویژگی‌های هدف (هر خط یکی)</span>
							<textarea class="tcbv-textarea" rows="5" name="tcbv[group_attrs]"><?php echo esc_textarea( $settings['group_attrs'] ); ?></textarea>
						</label>
						<label class="tcbv-field">
							<span>ویژگی‌های مستثنا (هرگز گروه‌بندی نشود)</span>
							<textarea class="tcbv-textarea" rows="5" name="tcbv[skip_attrs]"><?php echo esc_textarea( $settings['skip_attrs'] ); ?></textarea>
						</label>
						<label class="tcbv-field">
							<span>ویژگی‌های رنگ (سواچ)</span>
							<textarea class="tcbv-textarea" rows="5" name="tcbv[swatch_attrs]"><?php echo esc_textarea( $settings['swatch_attrs'] ); ?></textarea>
						</label>
					</div>

					<hr class="tcbv-hr">

					<h3 class="tcbv-h3">دامنهٔ محصولات</h3>
					<div class="tcbv-grid-3">
						<label class="tcbv-field">
							<span>کجا فعال باشد؟</span>
							<select class="tcbv-input" name="tcbv[advanced][products_scope]">
								<option value="all" <?php selected( 'all', $a['products_scope'] ); ?>>همهٔ محصولات متغیر (پیشنهادی)</option>
								<option value="include" <?php selected( 'include', $a['products_scope'] ); ?>>فقط دسته‌های زیر</option>
								<option value="exclude" <?php selected( 'exclude', $a['products_scope'] ); ?>>همه جز دسته‌های زیر</option>
							</select>
						</label>
						<label class="tcbv-field tcbv-field--wide">
							<span>دسته‌ها (اسلاگ یا شناسه، با کاما)</span>
							<textarea class="tcbv-textarea" rows="3" name="tcbv[advanced][categories]" dir="ltr"><?php echo esc_textarea( $a['categories'] ); ?></textarea>
						</label>
					</div>

					<div class="tcbv-toggles">
						<label class="tcbv-check">
							<input type="checkbox" name="tcbv[advanced][respect_optgroups]" value="1" <?php checked( ! empty( $a['respect_optgroups'] ) ); ?>>
							<span>گروه‌بندی‌های قبلی قالب را دست نزن</span>
						</label>
						<label class="tcbv-check">
							<input type="checkbox" name="tcbv[advanced][safe_mode]" value="1" <?php checked( ! empty( $a['safe_mode'] ) ); ?>>
							<span>حالت ایمن (بدون پنل جستجو، فقط مرتب‌سازی سرور)</span>
						</label>
						<label class="tcbv-check">
							<input type="checkbox" name="tcbv[advanced][debug]" value="1" <?php checked( ! empty( $a['debug'] ) ); ?>>
							<span>گزارش تشخیصی در کنسول مرورگر</span>
						</label>
						<label class="tcbv-check">
							<input type="checkbox" name="tcbv[advanced][delete_on_uninstall]" value="1" <?php checked( ! empty( $a['delete_on_uninstall'] ) ); ?>>
							<span>با حذف افزونه، تنظیمات هم پاک شود</span>
						</label>
					</div>

					<hr class="tcbv-hr">

					<h3 class="tcbv-h3">پشتیبان تنظیمات (JSON)</h3>
					<p class="tcbv-hint">برای انتقال تنظیمات به سایت دیگر: متن زیر را کپی کنید و در سایت مقصد در کادر «بازیابی» بگذارید و ذخیره کنید.</p>
					<div class="tcbv-grid-2">
						<label class="tcbv-field">
							<span>خروجی</span>
							<textarea class="tcbv-textarea tcbv-export" rows="4" readonly dir="ltr"><?php echo esc_textarea( wp_json_encode( $settings ) ); ?></textarea>
						</label>
						<label class="tcbv-field">
							<span>بازیابی (با ذخیره اعمال می‌شود)</span>
							<textarea class="tcbv-textarea" rows="4" name="tcbv[import]" dir="ltr" placeholder='{"enabled":1,…}'></textarea>
						</label>
					</div>

				</div>
			</section>
			<?php
		}

		/* ============================================================
		   AJAX — خواندن محصول و تحلیل
		   ============================================================ */

		private static function guard() {
			check_ajax_referer( 'tcbv_admin', 'nonce' );
			if ( ! current_user_can( self::CAP ) ) {
				wp_send_json_error( array( 'message' => 'دسترسی کافی ندارید.' ), 403 );
			}
		}

		/**
		 * جست‌وجوی محصولات متغیر.
		 */
		public static function ajax_products() {
			self::guard();

			$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';

			$query = new WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page' => 20,
					's'              => $term,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						array(
							'taxonomy' => 'product_type',
							'field'    => 'name',
							'terms'    => 'variable',
						),
					),
				)
			);

			$out = array();
			foreach ( $query->posts as $post ) {
				$out[] = array(
					'id'    => (int) $post->ID,
					'title' => get_the_title( $post ),
					'edit'  => get_edit_post_link( $post->ID, 'raw' ),
					'link'  => get_permalink( $post->ID ),
				);
			}

			wp_send_json_success( array( 'products' => $out ) );
		}

		/**
		 * ویژگی‌ها و مدل‌های یک محصول متغیر + تصمیم افزونه برای هر ویژگی.
		 */
		public static function ajax_product_attrs() {
			self::guard();

			$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
			$product    = $product_id ? wc_get_product( $product_id ) : null;

			if ( ! $product || ! $product->is_type( 'variable' ) ) {
				wp_send_json_error( array( 'message' => 'این محصول متغیر نیست.' ), 400 );
			}

			$settings = TCBV_Settings::get();
			$raw_attrs = $product->get_variation_attributes();
			$labels    = array();

			foreach ( $product->get_attributes() as $attribute ) {
				if ( ! $attribute instanceof WC_Product_Attribute ) {
					continue;
				}
				$labels[ sanitize_title( $attribute->get_name() ) ] = $attribute->get_name();
			}

			$attributes = array();
			foreach ( $raw_attrs as $name => $values ) {
				$values = array_values( array_filter( array_map( 'strval', (array) $values ), 'strlen' ) );
				if ( ! $values ) {
					continue;
				}
				$key   = sanitize_title( $name );
				$label = isset( $labels[ $key ] ) ? $labels[ $key ] : wc_attribute_label( $name );
				$groups = TCBV_Rules::group( $values, $settings );
				$compact = array();
				foreach ( $groups as $group ) {
					$compact[] = array(
						'label'   => $group['label'],
						'color'   => $group['color'],
						'count'   => $group['count'],
						'unknown' => $group['unknown'],
						'values'  => array_slice( $group['values'], 0, 400 ),
					);
				}

				$attributes[] = array(
					'name'      => $name,
					'label'     => $label,
					'count'     => count( $values ),
					'willGroup' => TCBV_Rules::should_group( $name, $values, $settings ),
					'isColor'   => TCBV_Rules::is_color_attr( $name, $settings ),
					'groups'    => $compact,
					'values'    => array_slice( $values, 0, 400 ),
					'conflicts' => array_slice( TCBV_Rules::conflicts( $values, $settings ), 0, 20 ),
				);
			}

			wp_send_json_success(
				array(
					'id'         => $product_id,
					'title'      => $product->get_name(),
					'attributes' => $attributes,
				)
			);
		}

		/**
		 * تحلیل یک فهرست مدل با قواعد فعلی فرم (حتی قبل از ذخیره).
		 */
		public static function ajax_classify() {
			self::guard();

			$base = TCBV_Settings::get();
			$post = isset( $_POST['rules'] ) && is_array( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			$merged = $base;
			if ( isset( $post['brands'] ) && is_array( $post['brands'] ) ) {
				$merged['brands'] = $post['brands'];
			}
			if ( isset( $post['unknown'] ) && is_array( $post['unknown'] ) ) {
				$merged['unknown'] = array_merge( $base['unknown'], $post['unknown'] );
			}
			if ( isset( $post['group_scope'] ) ) {
				$merged['group_scope'] = $post['group_scope'];
			}
			foreach ( array( 'group_attrs', 'skip_attrs', 'swatch_attrs' ) as $key ) {
				if ( isset( $post[ $key ] ) ) {
					$merged[ $key ] = $post[ $key ];
				}
			}
			if ( isset( $post['ui'] ) && is_array( $post['ui'] ) ) {
				$merged['ui'] = array_merge( $base['ui'], $post['ui'] );
			}

			$settings = TCBV_Settings::sanitize( $merged );

			$text   = isset( $_POST['values'] ) ? (string) wp_unslash( $_POST['values'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$values = array_slice( TCBV_Settings::lines( wp_strip_all_tags( $text ) ), 0, 3000 );

			if ( ! $values ) {
				wp_send_json_error( array( 'message' => 'لیستی برای تحلیل نیست.' ), 400 );
			}

			$groups = TCBV_Rules::group( $values, $settings );
			$out    = array();
			foreach ( $groups as $group ) {
				$out[] = array(
					'id'      => $group['id'],
					'label'   => $group['label'],
					'color'   => $group['color'],
					'count'   => $group['count'],
					'unknown' => (bool) $group['unknown'],
					'values'  => $group['values'],
				);
			}

			wp_send_json_success(
				array(
					'total'     => count( $values ),
					'groups'    => $out,
					'conflicts' => array_slice( TCBV_Rules::conflicts( $values, $settings ), 0, 50 ),
				)
			);
		}
	}
}
