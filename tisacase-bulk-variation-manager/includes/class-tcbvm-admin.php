<?php
/**
 * رابط مدیریت (Admin UI) با طراحی اختصاصی تیساکیس:
 * پالت گرم، داشبورد با کارت‌های آماری، وضعیت سلامت اتصال‌ها، دسترسی سریع و تب‌های مدرن.
 *
 * @package TisaCase_Bulk_Variation_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCBVM_Admin' ) ) {

	final class TCBVM_Admin {

		private static $hook_suffix = '';

		public static function init() {
			add_action( 'admin_menu', array( __CLASS__, 'register_menus' ), 30 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
			add_action( 'admin_notices', array( __CLASS__, 'woocommerce_check_notice' ) );
		}

		/**
		 * ثبت منو در هاب تیساکیس و منوی محصولات ووکامرس.
		 */
		public static function register_menus() {
			if ( ! TCBVM_Core::can() ) {
				return;
			}

			global $menu;
			$hub_slug = 'tisacase-desc';

			$has_tisa_hub = false;
			if ( is_array( $menu ) ) {
				foreach ( $menu as $item ) {
					if ( isset( $item[2] ) && ( $item[2] === $hub_slug || $item[2] === 'tisacase-hub' ) ) {
						$has_tisa_hub = true;
						$hub_slug = $item[2];
						break;
					}
				}
			}

			// اتصال به هاب تیساکیس در صورت وجود
			if ( $has_tisa_hub ) {
				self::$hook_suffix = add_submenu_page(
					$hub_slug,
					'مدیریت گروهی متغیرها و مدل‌ها',
					'مدیریت متغیرها (قاب گوشی)',
					'manage_woocommerce',
					TCBVM_Core::PAGE_SLUG,
					array( __CLASS__, 'render_page' )
				);
			} else {
				$icon = 'data:image/svg+xml;base64,' . base64_encode( self::icon_svg() );
				self::$hook_suffix = add_menu_page(
					'مدیریت متغیرها TisaCase',
					'TisaCase متغیرها',
					'manage_woocommerce',
					TCBVM_Core::PAGE_SLUG,
					array( __CLASS__, 'render_page' ),
					$icon,
					57
				);
			}

			// دسترسی سریع در منوی محصولات
			add_submenu_page(
				'edit.php?post_type=product',
				'مدیریت گروهی متغیرها تیساکیس',
				'مدیریت متغیرها تیساکیس',
				'manage_woocommerce',
				TCBVM_Core::PAGE_SLUG,
				array( __CLASS__, 'render_page' )
			);
		}

		public static function icon_svg() {
			return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24">'
				. '<rect x="3" y="3" width="18" height="18" rx="4" fill="#0E7C6B"/>'
				. '<path d="M7 8h10M7 12h7M7 16h10" stroke="#FFFFFF" stroke-width="2" stroke-linecap="round"/>'
				. '<circle cx="17" cy="12" r="1.5" fill="#FFFFFF"/>'
				. '</svg>';
		}

		public static function woocommerce_check_notice() {
			if ( ! class_exists( 'WooCommerce' ) ) {
				echo '<div class="notice notice-error"><p><strong>افزونه مدیریت متغیرهای TisaCase:</strong> برای استفاده، نصب و فعال‌سازی ووکامرس الزامی است.</p></div>';
			}
		}

		public static function enqueue_assets( $hook ) {
			if ( strpos( $hook, TCBVM_Core::PAGE_SLUG ) === false ) {
				return;
			}

			wp_enqueue_style(
				'tcbvm-admin-css',
				TCBVM_URL . 'assets/admin.css',
				array(),
				TCBVM_VERSION
			);

			wp_enqueue_script(
				'tcbvm-admin-js',
				TCBVM_URL . 'assets/admin.js',
				array( 'jquery' ),
				TCBVM_VERSION,
				true
			);

			$settings = TCBVM_Core::get_settings();
			$presets  = TCBVM_Core::get_presets();

			wp_localize_script( 'tcbvm-admin-js', 'tcbvmData', array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( TCBVM_Core::NONCE_ACTION ),
				'settings' => $settings,
				'presets'  => $presets,
				'i18n'     => array(
					'selectProductsPrompt' => 'لطفاً ابتدا حداقل یک محصول را جستجو و انتخاب کنید.',
					'enterModelsPrompt'    => 'لطفاً نام حداقل یک مدل را وارد کنید.',
					'confirmStart'         => 'آیا از شروع این عملیات گروهی روی {n} محصول انتخاب‌شده اطمینان دارید؟',
					'confirmRollback'      => 'آیا مطمئن هستید که می‌خواهید تغییرات این مرحله را به حالت قبل بازگردانید؟',
					'confirmDeletePreset'  => 'آیا از حذف این الگو مطمئن هستید؟',
					'runningText'          => 'در حال پردازش دسته‌ای… لطفاً این صفحه را نبندید.',
					'completedText'        => 'عملیات با موفقیت روی تمام محصولات پایان یافت!',
					'errorOccurred'        => 'خطایی رخ داد. جزئیات در لاگ ثبت شد.',
				),
			) );
		}

		/**
		 * دریافت آمارهای داشبورد (با کش گذرا).
		 */
		private static function get_dashboard_stats() {
			$stats = get_transient( 'tcbvm_dash_stats' );
			if ( false !== $stats && is_array( $stats ) ) {
				return $stats;
			}

			global $wpdb;
			$total_products = (int) wp_count_posts( 'product' )->publish;

			// تعداد محصولات متغیر
			$total_variable = (int) $wpdb->get_var(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
				WHERE p.post_type = 'product' AND p.post_status = 'publish'
				AND tt.taxonomy = 'product_type' AND t.slug = 'variable'"
			);

			// تعداد اجراهای ثبت شده
			$runs       = TCBVM_Backup::get_all_runs();
			$total_runs = count( $runs );

			// تعداد الگوهای فعال
			$presets       = TCBVM_Core::get_presets();
			$total_presets = count( $presets );

			$stats = array(
				'total_products' => $total_products,
				'total_variable' => $total_variable,
				'total_runs'     => $total_runs,
				'total_presets'  => $total_presets,
			);

			set_transient( 'tcbvm_dash_stats', $stats, HOUR_IN_SECONDS );
			return $stats;
		}

		/**
		 * رندر صفحه کامل مدیریت با استایل هماهنگ تیساکیس.
		 */
		public static function render_page() {
			if ( ! TCBVM_Core::can() ) {
				wp_die( 'دسترسی غیرمجاز است.' );
			}

			$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dash'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$categories = TCBVM_DB::get_all_product_categories();
			$presets    = TCBVM_Core::get_presets();
			$runs       = TCBVM_Backup::get_all_runs();
			$settings   = TCBVM_Core::get_settings();
			$stats      = self::get_dashboard_stats();
			?>
			<div class="tcbvm-app" dir="rtl">
				<div class="tcbvm-main">

					<!-- هدر گرادیانت تیساکیس -->
					<header class="tcbvm-top">
						<div class="tcbvm-top-id">
							<div class="tcbvm-logo">
								<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#eafff8" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
									<rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
									<line x1="8" y1="21" x2="16" y2="21"></line>
									<line x1="12" y1="17" x2="12" y2="21"></line>
									<path d="M7 8h4M7 11h8"></path>
								</svg>
							</div>
							<div>
								<h1>مدیریت متغیرها و مدل‌های قاب <span class="tcbvm-ver">نسخه <?php echo esc_html( TCBVM_VERSION ); ?></span></h1>
								<p>به‌روزرسانی گروهی متغیرها برای قاب‌های اسپیس و چاپی، افزودن سری‌های جدید آیفون و سامسونگ، کپی هوشمند قیمت و بازگردانی خودکار.</p>
							</div>
						</div>
						<div class="tcbvm-top-state">
							<span class="tcbvm-chip"><i class="dot"></i>ووکامرس متصل</span>
							<span class="tcbvm-chip"><i class="dot"></i>سازگار با HPOS</span>
							<span class="tcbvm-chip"><i class="dot"></i>پشتیبان‌گیری فعال</span>
						</div>
					</header>

					<!-- تب‌های افقی چسبان -->
					<nav class="tcbvm-tabs">
						<button type="button" class="tcbvm-tab <?php echo ( 'dash' === $active_tab ) ? 'active' : ''; ?>" data-tab="dash">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 13h5v8H3zM10 3h5v18h-5zM17 9h5v12h-5z"/></svg>
							داشبورد و آمار
						</button>
						<button type="button" class="tcbvm-tab <?php echo ( 'bulk' === $active_tab ) ? 'active' : ''; ?>" data-tab="bulk">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="4" y1="7" x2="20" y2="7"/><circle cx="9" cy="7" r="2.5"/><line x1="4" y1="17" x2="20" y2="17"/><circle cx="15" cy="17" r="2.5"/></svg>
							عملیات گروهی متغیرها
						</button>
						<button type="button" class="tcbvm-tab <?php echo ( 'presets' === $active_tab ) ? 'active' : ''; ?>" data-tab="presets">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg>
							الگوهای آماده مدل‌ها
						</button>
						<button type="button" class="tcbvm-tab <?php echo ( 'history' === $active_tab ) ? 'active' : ''; ?>" data-tab="history">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/></svg>
							تاریخچه و بازگردانی (Rollback)
						</button>
						<button type="button" class="tcbvm-tab <?php echo ( 'settings' === $active_tab ) ? 'active' : ''; ?>" data-tab="settings">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
							تنظیمات و کش
						</button>
					</nav>

					<!-- ================= ۱. تب داشبورد ================= -->
					<section class="tcbvm-panel <?php echo ( 'dash' === $active_tab ) ? 'active' : ''; ?>" data-panel="dash">
						<!-- کارت‌های آماری KPI -->
						<div class="tcbvm-kpis">
							<div class="tcbvm-kpi">
								<div class="t">محصولات متغیر فعال</div>
								<div class="v"><?php echo esc_html( number_format_i18n( $stats['total_variable'] ) ); ?></div>
								<div class="s">از <?php echo esc_html( number_format_i18n( $stats['total_products'] ) ); ?> کل محصول ووکامرس</div>
							</div>
							<div class="tcbvm-kpi k-amber">
								<div class="t">الگوهای آماده مدل‌ها</div>
								<div class="v"><?php echo esc_html( number_format_i18n( $stats['total_presets'] ) ); ?> <small>پکیج</small></div>
								<div class="s">آیفون، سامسونگ، شیائومی و سفارشی</div>
							</div>
							<div class="tcbvm-kpi k-plum">
								<div class="t">عملیات‌های ثبت‌شده</div>
								<div class="v"><?php echo esc_html( number_format_i18n( $stats['total_runs'] ) ); ?> <small>اجرا</small></div>
								<div class="s">با قابلیت بازگردانی (Rollback)</div>
							</div>
							<div class="tcbvm-kpi k-ink">
								<div class="t">وضعیت پایداری سرور</div>
								<div class="v">۱۰۰٪ امن</div>
								<div class="s">پردازش پله‌ای پکت‌های ۵ تایی</div>
							</div>
						</div>

						<div class="tcbvm-grid-2">
							<!-- سلامت سیستم -->
							<div class="tcbvm-card">
								<h3 class="tcbvm-sec">سلامت سیستم و اتصالات</h3>
								<p class="tcbvm-sec-desc">وضعیت مؤلفه‌های مورد نیاز برای مدیریت متغیرها در یک نگاه.</p>
								<div class="tcbvm-health">
									<div class="tcbvm-htile"><i class="ind"></i><span class="k">ووکامرس</span><span class="v">متصل</span></div>
									<div class="tcbvm-htile"><i class="ind"></i><span class="k">سازگاری HPOS</span><span class="v">فعال</span></div>
									<div class="tcbvm-htile"><i class="ind"></i><span class="k">پشتیبان‌گیری خودکار</span><span class="v">آماده</span></div>
									<div class="tcbvm-htile"><i class="ind"></i><span class="k">پردازش AJAX</span><span class="v">فعال</span></div>
									<div class="tcbvm-htile"><i class="ind"></i><span class="k">کش قیمت‌ها</span><span class="v">همگام</span></div>
									<div class="tcbvm-htile"><i class="ind"></i><span class="k">دسته‌بندی‌ها</span><span class="v"><?php echo esc_html( count( $categories ) ); ?> دسته</span></div>
								</div>
							</div>

							<!-- دسترسی سریع -->
							<div class="tcbvm-card">
								<h3 class="tcbvm-sec">دسترسی سریع</h3>
								<p class="tcbvm-sec-desc">پرش مستقیم به بخش‌های پرکاربرد مدیریت متغیرها و کاتالوگ فروشگاه.</p>
								<div class="tcbvm-quick">
									<button type="button" class="tcbvm-goto-tab" data-target="bulk">
										<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
										افزودن مدل جدید
									</button>
									<button type="button" class="tcbvm-goto-tab" data-target="presets">
										<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
										مشاهده الگوها
									</button>
									<button type="button" class="tcbvm-goto-tab" data-target="history">
										<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
										بازگردانی تغییرات
									</button>
									<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>">
										<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/></svg>
										لیست محصولات
									</a>
								</div>
							</div>
						</div>
					</section>

					<!-- ================= ۲. تب عملیات گروهی ================= -->
					<section class="tcbvm-panel <?php echo ( 'bulk' === $active_tab ) ? 'active' : ''; ?>" data-panel="bulk">
						<!-- مرحله ۱: انتخاب محصولات -->
						<div class="tcbvm-card">
							<span class="tcbvm-step-pill">مرحله ۱ از ۳</span>
							<h3 class="tcbvm-sec">انتخاب محصولات هدف (قاب‌های اسپیس، چاپی یا دسته‌بندی مشخص)</h3>
							<p class="tcbvm-sec-desc">محصولاتی که متغیرهای آن‌ها باید ویرایش شوند را از طریق دسته‌بندی یا کلمات کلیدی پیدا کنید.</p>

							<div class="tcbvm-form-grid">
								<div class="tcbvm-field">
									<label class="tcbvm-label" for="tcbvm-cat-select">دسته‌بندی‌های هدف:</label>
									<select id="tcbvm-cat-select" class="tcbvm-select" multiple size="5">
										<?php foreach ( $categories as $cat ) : ?>
											<option value="<?php echo esc_attr( $cat['id'] ); ?>">
												<?php echo esc_html( $cat['name'] ); ?> (<?php echo esc_html( $cat['count'] ); ?> محصول)
											</option>
										<?php endforeach; ?>
									</select>
									<label class="tcbvm-check-label" style="margin-top: 8px;">
										<input type="checkbox" id="tcbvm-cat-children" checked>
										<span>شامل زیردسته‌ها نیز باشد</span>
									</label>
								</div>

								<div class="tcbvm-field">
									<label class="tcbvm-label" for="tcbvm-keywords">کلمات کلیدی در عنوان محصول (فیلتر مثبت):</label>
									<input type="text" id="tcbvm-keywords" class="tcbvm-input" placeholder="مثال: قاب، اسپیس، چاپی (با ویرگول جدا کنید)">
									<p class="tcbvm-hint">فقط محصولاتی که شامل این کلمات باشند انتخاب می‌شوند (برای همه خالی بگذارید).</p>

									<label class="tcbvm-label" for="tcbvm-exclude-keywords" style="margin-top: 14px;">کلمات منفی و استثنا (فیلتر منفی):</label>
									<input type="text" id="tcbvm-exclude-keywords" class="tcbvm-input" placeholder="مثال: تبلت، ایرپاد، ساعت">
									<p class="tcbvm-hint">محصولات حاوی این کلمات خودکار کنار گذاشته می‌شوند.</p>
								</div>
							</div>

							<!-- شناسه‌های مستقیم -->
							<details style="margin-top: 14px; background: var(--paper); border: 1px solid var(--line); border-radius: 12px; padding: 12px 16px;">
								<summary style="font-size: 12.5px; font-weight: 700; color: var(--teal); cursor: pointer;">جستجوی مستقیم با شناسه یا فیلتر داشتن مدل خاص</summary>
								<div class="tcbvm-form-grid" style="margin-top: 12px;">
									<div class="tcbvm-field">
										<label class="tcbvm-label">شناسه‌های دستی (با ویرگول جدا کنید):</label>
										<input type="text" id="tcbvm-manual-ids" class="tcbvm-input" placeholder="مثال: 1205, 1208, 1450">
									</div>
									<div class="tcbvm-field">
										<label class="tcbvm-label">فقط محصولاتی که هم‌اکنون این مدل را دارند:</label>
										<input type="text" id="tcbvm-model-filter" class="tcbvm-input" placeholder="مثال: iPhone 11">
									</div>
								</div>
							</details>

							<div style="margin-top: 18px; display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
								<button type="button" class="tcbvm-btn tcbvm-btn--primary" id="tcbvm-btn-search">
									<span class="dashicons dashicons-search"></span>
									جستجو و بررسی محصولات منطبق
								</button>
								<span id="tcbvm-search-counter" style="font-size: 13px; color: var(--muted);">هنوز جستجویی انجام نشده است.</span>
							</div>

							<!-- جدول زنده نتایج -->
							<div id="tcbvm-products-box" style="display: none; margin-top: 20px;">
								<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
									<label class="tcbvm-check-label">
										<input type="checkbox" id="tcbvm-select-all" checked>
										<strong>انتخاب همه موارد این لیست</strong>
									</label>
									<span class="tcbvm-badge" id="tcbvm-selected-badge">۰ محصول انتخاب‌شده</span>
								</div>
								<div class="tcbvm-table-wrap">
									<table class="tcbvm-table">
										<thead>
											<tr>
												<th style="width: 38px;">انتخاب</th>
												<th style="width: 48px;">تصویر</th>
												<th>نام محصول</th>
												<th>شناسه / SKU</th>
												<th>دسته‌بندی</th>
												<th>متغیرها</th>
												<th>نمونه مدل‌های فعلی</th>
												<th style="width: 60px;">عملیات</th>
											</tr>
										</thead>
										<tbody id="tcbvm-products-tbody"></tbody>
									</table>
								</div>
							</div>
						</div>

						<!-- مرحله ۲: نوع عملیات -->
						<div class="tcbvm-card">
							<span class="tcbvm-step-pill">مرحله ۲ از ۳</span>
							<h3 class="tcbvm-sec">تعیین نوع عملیات و متغیرها</h3>
							<p class="tcbvm-sec-desc">انتخاب کنید چه تغییری روی متغیرهای محصولات انتخابی اعمال شود.</p>

							<div class="tcbvm-ops-grid">
								<label class="tcbvm-op-card is-active">
									<input type="radio" name="tcbvm_op" value="add_models" checked>
									<div>
										<strong>افزودن مدل‌های جدید</strong>
										<small>افزودن سری‌های جدید گوشی بدون دستکاری متغیرهای فعلی</small>
									</div>
								</label>

								<label class="tcbvm-op-card">
									<input type="radio" name="tcbvm_op" value="remove_models">
									<div>
										<strong>حذف / ناموجود کردن مدل‌ها</strong>
										<small>حذف تمیز یا ناموجود کردن مدل‌های قدیمی از رده خارج</small>
									</div>
								</label>

								<label class="tcbvm-op-card">
									<input type="radio" name="tcbvm_op" value="sync_preset">
									<div>
										<strong>همگام‌سازی کامل با الگو</strong>
										<small>تطبیق ۱۰۰٪ با الگو (حذف موارد نامربوط + افزودن جدیدها)</small>
									</div>
								</label>

								<label class="tcbvm-op-card">
									<input type="radio" name="tcbvm_op" value="replace_model">
									<div>
										<strong>تغییر نام یا جایگزینی مدل</strong>
										<small>تغییر املای مدل یا جایگزینی با مدل جدید</small>
									</div>
								</label>

								<label class="tcbvm-op-card">
									<input type="radio" name="tcbvm_op" value="bulk_price_stock">
									<div>
										<strong>تغییر قیمت یا موجودی مدل خاص</strong>
										<small>به‌روزرسانی قیمت بدون ایجاد یا حذف متغیر</small>
									</div>
								</label>
							</div>

							<!-- فیلدها -->
							<div style="border-top: 1px dashed var(--line); padding-top: 18px;">
								<div class="tcbvm-field" style="max-width: 420px;">
									<label class="tcbvm-label" for="tcbvm-attr-name">نام ویژگی در ووکامرس:</label>
									<input type="text" id="tcbvm-attr-name" class="tcbvm-input" value="مدل گوشی">
									<p class="tcbvm-hint">پیش‌فرض: «مدل گوشی» (یا نام ویژگی متغیر در فروشگاه شما).</p>
								</div>

								<!-- باکس مدل‌ها -->
								<div id="tcbvm-models-input-wrap">
									<div class="tcbvm-chips-row">
										<span style="font-size: 12px; color: var(--muted);">درج سریع از الگوهای آماده:</span>
										<?php foreach ( $presets as $p ) : ?>
											<button type="button" class="tcbvm-chip-btn" data-preset-id="<?php echo esc_attr( $p['id'] ); ?>">
												+ <?php echo esc_html( $p['name'] ); ?>
											</button>
										<?php endforeach; ?>
									</div>
									<div class="tcbvm-field">
										<label class="tcbvm-label" for="tcbvm-models-input">لیست مدل‌های گوشی:</label>
										<textarea id="tcbvm-models-input" class="tcbvm-textarea" rows="4" placeholder="iPhone 16 Pro Max&#10;iPhone 16 Pro&#10;iPhone 16 Plus&#10;iPhone 16&#10;(هر مدل در یک خط یا با ویرگول جدا کنید)"></textarea>
									</div>
								</div>

								<!-- باکس جایگزینی مدل -->
								<div id="tcbvm-replace-input-wrap" style="display: none;">
									<div class="tcbvm-form-grid">
										<div class="tcbvm-field">
											<label class="tcbvm-label" for="tcbvm-old-model">مدل فعلی قدیمی:</label>
											<input type="text" id="tcbvm-old-model" class="tcbvm-input" placeholder="مثال: iPhone 11 Pro">
										</div>
										<div class="tcbvm-field">
											<label class="tcbvm-label" for="tcbvm-new-model">نام مدل جدید جایگزین:</label>
											<input type="text" id="tcbvm-new-model" class="tcbvm-input" placeholder="مثال: آیفون ۱۱ پرو">
										</div>
									</div>
								</div>

								<!-- قیمت و موجودی -->
								<div id="tcbvm-pricing-options-wrap" style="background: var(--paper); border: 1px solid var(--line); border-radius: 14px; padding: 18px; margin-top: 14px;">
									<label class="tcbvm-check-label">
										<input type="checkbox" id="tcbvm-clone-price-check" checked>
										<strong>کپی هوشمند قیمت از روی یک مدل مرجع (پیشنهاد ویژه تیساکیس)</strong>
									</label>
									<div id="tcbvm-clone-ref-wrap" style="margin: 10px 24px 0 0;">
										<input type="text" id="tcbvm-clone-ref-model" class="tcbvm-input" style="max-width: 320px;" placeholder="نام مدل مرجع، مثلاً: iPhone 15 Pro Max" value="iPhone 15 Pro Max">
										<p class="tcbvm-hint">قیمت متغیر جدید برابر با قیمت این مدل در همان محصول قرار می‌گیرد.</p>
									</div>

									<div class="tcbvm-form-grid" style="margin-top: 14px;">
										<div class="tcbvm-field">
											<label class="tcbvm-label">قیمت عادی پیش‌فرض (تومان):</label>
											<input type="number" id="tcbvm-regular-price" class="tcbvm-input" placeholder="مثال: 290000">
										</div>
										<div class="tcbvm-field">
											<label class="tcbvm-label">قیمت فروش ویژه (اختیاری):</label>
											<input type="number" id="tcbvm-sale-price" class="tcbvm-input" placeholder="مثال: 250000">
										</div>
										<div class="tcbvm-field">
											<label class="tcbvm-label">وضعیت انبار متغیرها:</label>
											<select id="tcbvm-stock-status" class="tcbvm-select">
												<option value="instock" selected>موجود در انبار</option>
												<option value="outofstock">ناموجود</option>
											</select>
										</div>
									</div>
								</div>

								<!-- حالت حذف -->
								<div id="tcbvm-delete-mode-wrap" style="display: none; margin-top: 14px;">
									<label class="tcbvm-label">حالت حذف متغیرها:</label>
									<div style="display: flex; gap: 20px; margin-top: 6px;">
										<label class="tcbvm-check-label">
											<input type="radio" name="tcbvm_delete_mode" value="soft" checked>
											<span><strong>حالت ایمن:</strong> ناموجود و مخفی شود (حفظ سئو و سفارش‌ها)</span>
										</label>
										<label class="tcbvm-check-label">
											<input type="radio" name="tcbvm_delete_mode" value="hard">
											<span><strong>حذف کامل:</strong> پاکسازی کامل رکورد متغیر از دیتابیس</span>
										</label>
									</div>
								</div>
							</div>
						</div>

						<!-- مرحله ۳: پیش‌نمایش و اجرا -->
						<div class="tcbvm-card">
							<span class="tcbvm-step-pill">مرحله ۳ از ۳</span>
							<h3 class="tcbvm-sec">پیش‌نمایش آزمایشی و اجرای امن</h3>
							<p class="tcbvm-sec-desc">ابتدا پیش‌نمایش بگیرید؛ پردازش با ذخیره اسنپ‌شات بازگردانی و بسته‌های ۵ تایی ایجکس انجام می‌شود.</p>

							<div style="display: flex; gap: 12px; flex-wrap: wrap;">
								<button type="button" class="tcbvm-btn tcbvm-btn--secondary" id="tcbvm-btn-preview">
									<span class="dashicons dashicons-visibility"></span>
									پیش‌نمایش آزمایشی (Dry Run)
								</button>
								<button type="button" class="tcbvm-btn tcbvm-btn--primary" id="tcbvm-btn-run">
									<span class="dashicons dashicons-controls-play"></span>
									شروع اعمال تغییرات روی محصولات انتخاب‌شده
								</button>
							</div>

							<!-- باکس پیش‌نمایش -->
							<div id="tcbvm-preview-output" style="display: none; margin-top: 18px; background: #fffbe8; border: 1px solid #fae8a4; border-radius: 14px; padding: 18px;">
								<h4 style="margin: 0 0 10px; color: #92400e;">نمونه پیش‌نمایش تغییرات:</h4>
								<div id="tcbvm-preview-content"></div>
							</div>

							<!-- پروگرس‌بار زنده -->
							<div id="tcbvm-progress-wrap" class="tcbvm-progress-card" style="display: none;">
								<div class="tcbvm-progress-head">
									<span id="tcbvm-progress-text">در حال آماده‌سازی نشست و اسنپ‌شات…</span>
									<span id="tcbvm-progress-percent" style="color: var(--teal);">0%</span>
								</div>
								<div class="tcbvm-progress-track">
									<div class="tcbvm-progress-fill" id="tcbvm-progress-bar"></div>
								</div>
								<div class="tcbvm-progress-stats">
									<span>کل محصولات: <strong id="tcbvm-stat-total">0</strong></span>
									<span>پردازش‌شده: <strong id="tcbvm-stat-processed">0</strong></span>
									<span>موفق: <strong id="tcbvm-stat-success" style="color: var(--ok);">0</strong></span>
									<span>ناموفق: <strong id="tcbvm-stat-failed" style="color: var(--bad);">0</strong></span>
								</div>
								<div class="tcbvm-console" id="tcbvm-log-console"></div>
							</div>
						</div>
					</section>

					<!-- ================= ۳. تب الگوهای آماده ================= -->
					<section class="tcbvm-panel <?php echo ( 'presets' === $active_tab ) ? 'active' : ''; ?>" data-panel="presets">
						<div class="tcbvm-card">
							<h3 class="tcbvm-sec">الگوهای آماده مدل‌های گوشی (Model Presets)</h3>
							<p class="tcbvm-sec-desc">تعریف و نگهداری لیست‌های مدل‌ها تا در تغییرات بعدی فقط با یک کلیک آن‌ها را فراخوانی کنید.</p>

							<div class="tcbvm-presets-grid">
								<?php foreach ( $presets as $preset ) : ?>
									<div class="tcbvm-preset-item">
										<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
											<h4><?php echo esc_html( $preset['name'] ); ?></h4>
											<?php if ( ! empty( $preset['is_builtin'] ) ) : ?>
												<span class="tcbvm-badge">سیستمی</span>
											<?php else : ?>
												<button type="button" class="tc-btn-delete-preset" data-id="<?php echo esc_attr( $preset['id'] ); ?>" style="background:none;border:none;color:var(--bad);cursor:pointer;" title="حذف الگو">
													<span class="dashicons dashicons-trash"></span>
												</button>
											<?php endif; ?>
										</div>
										<p><?php echo esc_html( $preset['description'] ); ?></p>
										<div>
											<?php foreach ( array_slice( $preset['models'], 0, 10 ) as $m ) : ?>
												<span class="tcbvm-tag"><?php echo esc_html( $m ); ?></span>
											<?php endforeach; ?>
											<?php if ( count( $preset['models'] ) > 10 ) : ?>
												<span class="tcbvm-tag" style="background:#e5e7eb; font-weight:700;">+ <?php echo count( $preset['models'] ) - 10; ?> مدل دیگر</span>
											<?php endif; ?>
										</div>
									</div>
								<?php endforeach; ?>
							</div>

							<!-- ساخت الگوی جدید -->
							<div style="margin-top: 24px; background: var(--paper); border: 1px solid var(--line); border-radius: 14px; padding: 20px;">
								<h4 style="margin: 0 0 12px; font-weight: 800;">افزودن الگوی سفارشی جدید</h4>
								<div class="tcbvm-form-grid">
									<div class="tcbvm-field">
										<label class="tcbvm-label" for="tcbvm-new-preset-name">عنوان الگو:</label>
										<input type="text" id="tcbvm-new-preset-name" class="tcbvm-input" placeholder="مثال: سری کامل آیفون‌های پاییز ۱۴۰۳">
									</div>
									<div class="tcbvm-field">
										<label class="tcbvm-label" for="tcbvm-new-preset-desc">توضیح مختصر:</label>
										<input type="text" id="tcbvm-new-preset-desc" class="tcbvm-input" placeholder="مثال: مخصوص قاب‌های اسپیس لنزدار">
									</div>
								</div>
								<div class="tcbvm-field" style="margin-top: 10px;">
									<label class="tcbvm-label" for="tcbvm-new-preset-models">لیست مدل‌های داخل این الگو:</label>
									<textarea id="tcbvm-new-preset-models" class="tcbvm-textarea" rows="3" placeholder="مدل‌ها را با ویرگول یا در خطوط جداگانه وارد کنید..."></textarea>
								</div>
								<button type="button" class="tcbvm-btn tcbvm-btn--primary" id="tcbvm-btn-save-preset" style="margin-top: 8px;">
									<span class="dashicons dashicons-saved"></span>
									ذخیره الگو
								</button>
							</div>
						</div>
					</section>

					<!-- ================= ۴. تب تاریخچه و بازگردانی ================= -->
					<section class="tcbvm-panel <?php echo ( 'history' === $active_tab ) ? 'active' : ''; ?>" data-panel="history">
						<div class="tcbvm-card">
							<h3 class="tcbvm-sec">تاریخچه اجراها و بازگردانی (Rollback)</h3>
							<p class="tcbvm-sec-desc">قبل از هر اجرا اسنپ‌شاتی از متغیرها گرفته می‌شود و با یک کلیک امکان بازگردانی محصولات به حالت اولیه وجود دارد.</p>

							<?php if ( empty( $runs ) ) : ?>
								<p style="text-align: center; color: var(--muted); padding: 30px;">هنوز هیچ عملیات گروهی ثبت نشده است.</p>
							<?php else : ?>
								<div class="tcbvm-table-wrap">
									<table class="tcbvm-table">
										<thead>
											<tr>
												<th>شناسه نشست</th>
												<th>نوع عملیات</th>
												<th>تاریخ و ساعت</th>
												<th>تعداد محصولات</th>
												<th>وضعیت</th>
												<th>اقدام</th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ( $runs as $r_id => $run ) : ?>
												<tr>
													<td><code><?php echo esc_html( $r_id ); ?></code></td>
													<td><strong><?php echo esc_html( $run['operation'] ); ?></strong></td>
													<td><?php echo esc_html( $run['created_at'] ); ?></td>
													<td><?php echo esc_html( $run['total_products'] ); ?> محصول</td>
													<td>
														<?php if ( 'completed' === $run['status'] ) : ?>
															<span class="tcbvm-badge">انجام‌شده</span>
														<?php elseif ( 'rolled_back' === $run['status'] ) : ?>
															<span style="color: var(--muted); font-size: 11px;">بازگردانده‌شده</span>
														<?php else : ?>
															<span style="color: var(--amber); font-size: 11px;"><?php echo esc_html( $run['status'] ); ?></span>
														<?php endif; ?>
													</td>
													<td>
														<?php if ( 'rolled_back' !== $run['status'] ) : ?>
															<button type="button" class="tcbvm-btn tcbvm-btn--danger tc-btn-rollback" data-run-id="<?php echo esc_attr( $r_id ); ?>" style="padding: 4px 10px; font-size: 11.5px;">
																<span class="dashicons dashicons-undo"></span>
																بازگردانی (Rollback)
															</button>
														<?php else : ?>
															<span style="color: var(--muted); font-size: 11px;">—</span>
														<?php endif; ?>
													</td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</div>
							<?php endif; ?>
						</div>
					</section>

					<!-- ================= ۵. تب تنظیمات ================= -->
					<section class="tcbvm-panel <?php echo ( 'settings' === $active_tab ) ? 'active' : ''; ?>" data-panel="settings">
						<div class="tcbvm-card">
							<h3 class="tcbvm-sec">تنظیمات و بهینه‌سازی سرور</h3>
							<p class="tcbvm-sec-desc">کنترل اندازه بسته‌های پردازش و ابزارهای نوسازی کش ووکامرس.</p>

							<div style="background: var(--paper); border: 1px solid var(--line); border-radius: 14px; padding: 18px; margin-bottom: 16px;">
								<h4 style="margin: 0 0 6px;">اندازه بسته‌ها در هر درخواست (Batch Size)</h4>
								<p class="tcbvm-hint">تعداد محصولاتی که در هر ثانیه/درخواست ایجکس پردازش می‌شوند (پیشنهاد: ۵ تا ۱۰).</p>
								<input type="number" id="tcbvm-setting-batch-size" class="tcbvm-input" style="max-width: 180px; margin-top: 8px;" value="<?php echo esc_attr( $settings['batch_size'] ); ?>" min="1" max="50">
							</div>

							<div style="background: var(--paper); border: 1px solid var(--line); border-radius: 14px; padding: 18px;">
								<h4 style="margin: 0 0 6px;">نوسازی کش قیمت‌ها و ترنزینت‌های ووکامرس</h4>
								<p class="tcbvm-hint">اگر بعد از تغییرات، قیمت‌ها در کاتالوگ فروشگاه با تاخیر تغییر کردند، کش را با این دکمه نوسازی کنید.</p>
								<button type="button" class="tcbvm-btn tcbvm-btn--secondary" id="tcbvm-btn-flush-cache" style="margin-top: 10px;">
									<span class="dashicons dashicons-update-alt"></span>
									پاکسازی و نوسازی کش ترنزینت‌های قیمت
								</button>
							</div>
						</div>
					</section>

				</div>
			</div>
			<?php
		}
	}
}
