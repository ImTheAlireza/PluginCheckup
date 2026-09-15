<?php
/**
 * رابط مدیریت (Admin UI): منو، استایل‌ها، تب‌ها و رندر پنل کاربری.
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
			$hub_slug = 'tisacase-desc'; // اسلاگ منوی اصلی تیساکیس در صورت وجود

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

			// ۱. اتصال به هاب تیساکیس
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
				// اگر هنوز منوی والد تیساکیس ساخته نشده، منوی والد اختصاصی با آیکون تیساکیس می‌سازیم
				$icon = 'data:image/svg+xml;base64,' . base64_encode( self::icon_svg() );
				self::$hook_suffix = add_menu_page(
					'مدیریت گروهی متغیرها TisaCase',
					'TisaCase متغیرها',
					'manage_woocommerce',
					TCBVM_Core::PAGE_SLUG,
					array( __CLASS__, 'render_page' ),
					$icon,
					57
				);
			}

			// ۲. دسترسی سریع همچنین زیر منوی «محصولات» ووکامرس
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
				echo '<div class="notice notice-error"><p><strong>افزونه مدیریت متغیرهای TisaCase:</strong> برای استفاده از این افزونه، نصب و فعال‌سازی ووکامرس الزامی است.</p></div>';
			}
		}

		public static function enqueue_assets( $hook ) {
			// فقط در صفحه این افزونه بارگذاری شود
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
		 * رندر صفحه کامل مدیریت با ظاهر مدرن.
		 */
		public static function render_page() {
			if ( ! TCBVM_Core::can() ) {
				wp_die( 'دسترسی غیرمجاز است.' );
			}

			$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'bulk'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$categories = TCBVM_DB::get_all_product_categories();
			$presets    = TCBVM_Core::get_presets();
			$runs       = TCBVM_Backup::get_all_runs();
			$settings   = TCBVM_Core::get_settings();
			?>
			<div class="wrap tc-wrap" dir="rtl">
				<!-- Hero Header -->
				<header class="tc-hero">
					<div class="tc-hero-mark">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="#FFFFFF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
							<line x1="8" y1="21" x2="16" y2="21"></line>
							<line x1="12" y1="17" x2="12" y2="21"></line>
							<path d="M7 8h4M7 11h8"></path>
						</svg>
					</div>
					<div class="tc-hero-body">
						<h1 class="tc-hero-title">مدیریت گروهی متغیرها و مدل‌های تیساکیس</h1>
						<p class="tc-hero-sub">به‌روزرسانی یکپارچه و سریع مدل‌های گوشی برای قاب‌های اسپیس و چاپی، حذف قدیمی‌ها، شبیه‌سازی قیمت و بازگردانی ایمن</p>
					</div>
					<div class="tc-hero-badge">نسخه <?php echo esc_html( TCBVM_VERSION ); ?></div>
				</header>

				<!-- Nav Tabs -->
				<nav class="tc-tabs-nav">
					<a href="#tab-bulk" class="tc-tab-btn <?php echo ( 'bulk' === $active_tab ) ? 'is-active' : ''; ?>" data-tab="bulk">
						<span class="dashicons dashicons-admin-generic"></span>
						عملیات گروهی متغیرها
					</a>
					<a href="#tab-presets" class="tc-tab-btn <?php echo ( 'presets' === $active_tab ) ? 'is-active' : ''; ?>" data-tab="presets">
						<span class="dashicons dashicons-category"></span>
						الگوهای آماده مدل گوشی
					</a>
					<a href="#tab-history" class="tc-tab-btn <?php echo ( 'history' === $active_tab ) ? 'is-active' : ''; ?>" data-tab="history">
						<span class="dashicons dashicons-backup"></span>
						تاریخچه و بازگردانی (Rollback)
					</a>
					<a href="#tab-settings" class="tc-tab-btn <?php echo ( 'settings' === $active_tab ) ? 'is-active' : ''; ?>" data-tab="settings">
						<span class="dashicons dashicons-admin-settings"></span>
						تنظیمات و ابزارها
					</a>
				</nav>

				<main class="tc-tabs-content">
					<!-- TAB 1: BULK OPS -->
					<div id="tab-bulk" class="tc-tab-pane <?php echo ( 'bulk' === $active_tab ) ? 'is-active' : ''; ?>">
						<!-- مرحله ۱: انتخاب و فیلتر محصولات -->
						<div class="tc-card">
							<div class="tc-step-badge">مرحله ۱ از ۳</div>
							<h2 class="tc-card-title">انتخاب محصولات هدف (قاب‌های اسپیس، چاپی یا دسته‌بندی دلخواه)</h2>
							<p class="tc-card-desc">محصولاتی که می‌خواهید متغیرهای آن‌ها تغییر کنند را با فیلتر دسته‌بندی یا کلمات کلیدی پیدا کنید.</p>

							<div class="tc-form-grid">
								<!-- انتخاب دسته‌بندی -->
								<div class="tc-field">
									<label class="tc-label" for="tcbvm-cat-select">دسته‌بندی‌های هدف:</label>
									<select id="tcbvm-cat-select" class="tc-select" multiple size="5">
										<?php foreach ( $categories as $cat ) : ?>
											<option value="<?php echo esc_attr( $cat['id'] ); ?>">
												<?php echo esc_html( $cat['name'] ); ?> (<?php echo esc_html( $cat['count'] ); ?> محصول)
											</option>
										<?php endforeach; ?>
									</select>
									<label class="tc-checkbox-inline" style="margin-top: 6px;">
										<input type="checkbox" id="tcbvm-cat-children" checked>
										<span>شامل زیردسته‌ها باشد</span>
									</label>
								</div>

								<!-- کلمات کلیدی و استثناها -->
								<div class="tc-field">
									<label class="tc-label" for="tcbvm-keywords">کلمات کلیدی در عنوان محصول (مثبت):</label>
									<input type="text" id="tcbvm-keywords" class="tc-input" placeholder="مثال: قاب، اسپیس، چاپی (با ویرگول جدا کنید)">
									<small class="tc-hint">فقط محصولاتی که شامل این کلمات باشند انتخاب می‌شوند (خالی بگذارید تا همه لحاظ شوند).</small>

									<label class="tc-label" for="tcbvm-exclude-keywords" style="margin-top: 12px;">کلمات منفی و استثنا (منفی):</label>
									<input type="text" id="tcbvm-exclude-keywords" class="tc-input" placeholder="مثال: تبلت، ایرپاد، ساعت">
									<small class="tc-hint">اگر محصول این کلمات را داشته باشد، از لیست کنار گذاشته می‌شود.</small>
								</div>
							</div>

							<!-- حالت پیشرفته شناسه دستی -->
							<details class="tc-details" style="margin-top: 14px;">
								<summary class="tc-summary">جستجوی مستقیم بر اساس شناسه (ID) یا مدل خاص موجود</summary>
								<div class="tc-details-body tc-form-grid">
									<div class="tc-field">
										<label class="tc-label">شناسه‌های مشخص محصول (با ویرگول جدا کنید):</label>
										<input type="text" id="tcbvm-manual-ids" class="tc-input" placeholder="مثال: 1205, 1208, 1450">
									</div>
									<div class="tc-field">
										<label class="tc-label">فقط محصولاتی که هم‌اکنون این مدل را دارند:</label>
										<input type="text" id="tcbvm-model-filter" class="tc-input" placeholder="مثال: iPhone 11">
									</div>
								</div>
							</details>

							<div class="tc-actions" style="margin-top: 18px;">
								<button type="button" class="tc-btn tc-btn--primary" id="tcbvm-btn-search">
									<span class="dashicons dashicons-search"></span>
									جستجو و بررسی محصولات منطبق
								</button>
								<span class="tc-search-counter" id="tcbvm-search-counter">هنوز جستجویی انجام نشده است.</span>
							</div>

							<!-- جدول زنده نتایج -->
							<div class="tc-products-preview" id="tcbvm-products-box" style="display: none; margin-top: 20px;">
								<div class="tc-table-header-bar">
									<div class="tc-bulk-select-wrap">
										<label class="tc-checkbox-inline">
											<input type="checkbox" id="tcbvm-select-all" checked>
											<strong>انتخاب همه موارد این لیست</strong>
										</label>
										<span class="tc-selected-count-badge" id="tcbvm-selected-badge">۰ محصول انتخاب‌شده</span>
									</div>
								</div>
								<div class="tc-table-responsive">
									<table class="tc-table" id="tcbvm-products-table">
										<thead>
											<tr>
												<th style="width: 38px;">انتخاب</th>
												<th style="width: 50px;">تصویر</th>
												<th>نام محصول</th>
												<th>شناسه / SKU</th>
												<th>دسته‌بندی</th>
												<th>تعداد متغیرها</th>
												<th>نمونه مدل‌های فعلی</th>
												<th style="width: 60px;">عملیات</th>
											</tr>
										</thead>
										<tbody id="tcbvm-products-tbody">
											<!-- محتوا با AJAX پر می‌شود -->
										</tbody>
									</table>
								</div>
							</div>
						</div>

						<!-- مرحله ۲: نوع عملیات و تنظیمات متغیرها -->
						<div class="tc-card" style="margin-top: 20px;">
							<div class="tc-step-badge">مرحله ۲ از ۳</div>
							<h2 class="tc-card-title">انتخاب نوع عملیات روی متغیرها</h2>
							<p class="tc-card-desc">مشخص کنید چه بلایی می‌خواهید سر مدل‌ها بیاورید: اضافه کردن سری جدید، حذف قدیمی‌ها، یا همگام‌سازی کامل.</p>

							<div class="tc-ops-grid">
								<label class="tc-op-card is-active">
									<input type="radio" name="tcbvm_op" value="add_models" checked>
									<div class="tc-op-icon"><span class="dashicons dashicons-plus-alt2"></span></div>
									<div class="tc-op-content">
										<strong>افزودن مدل‌های جدید</strong>
										<small>ایجاد متغیر برای سری‌های جدید گوشی بدون دستکاری مدل‌های فعلی</small>
									</div>
								</label>

								<label class="tc-op-card">
									<input type="radio" name="tcbvm_op" value="remove_models">
									<div class="tc-op-icon"><span class="dashicons dashicons-trash"></span></div>
									<div class="tc-op-content">
										<strong>حذف / ناموجود کردن مدل‌های قدیمی</strong>
										<small>حذف تمیز یا ناموجود کردن مدل‌های از رده خارج شده</small>
									</div>
								</label>

								<label class="tc-op-card">
									<input type="radio" name="tcbvm_op" value="sync_preset">
									<div class="tc-op-icon"><span class="dashicons dashicons-randomize"></span></div>
									<div class="tc-op-content">
										<strong>همگام‌سازی کامل با الگو</strong>
										<small>تطبیق صددرصدی با لیست الگو (حذف موارد خارج از الگو + ایجاد مدل‌های جدید)</small>
									</div>
								</label>

								<label class="tc-op-card">
									<input type="radio" name="tcbvm_op" value="replace_model">
									<div class="tc-op-icon"><span class="dashicons dashicons-update"></span></div>
									<div class="tc-op-content">
										<strong>جایگزینی یا تغییر نام مدل</strong>
										<small>تغییر عنوان یک مدل قدیمی به یک مدل یا املای جدید</small>
									</div>
								</label>

								<label class="tc-op-card">
									<input type="radio" name="tcbvm_op" value="bulk_price_stock">
									<div class="tc-op-icon"><span class="dashicons dashicons-tag"></span></div>
									<div class="tc-op-content">
										<strong>تغییر قیمت یا موجودی مدل‌های خاص</strong>
										<small>به‌روزرسانی قیمت یا موجودی مدل‌های مشخص بدون ایجاد متغیر جدید</small>
									</div>
								</label>
							</div>

							<!-- فیلدهای پویا بر اساس عملیات -->
							<div class="tc-op-fields-container" style="margin-top: 24px; border-top: 1px dashed var(--tc-border); padding-top: 20px;">

								<!-- انتخاب ویژگی هدف -->
								<div class="tc-field" style="max-width: 480px; margin-bottom: 18px;">
									<label class="tc-label" for="tcbvm-attr-name">نام ویژگی متغیر در ووکامرس:</label>
									<input type="text" id="tcbvm-attr-name" class="tc-input" value="مدل گوشی">
									<small class="tc-hint">پیش‌فرض: «مدل گوشی». اگر در فروشگاه شما نام دیگری دارد، تغییر دهید.</small>
								</div>

								<!-- باکس مدل‌ها (برای افزودن، حذف، همگام‌سازی، تغییر قیمت) -->
								<div id="tcbvm-models-input-wrap">
									<div class="tc-field-header-row">
										<label class="tc-label" for="tcbvm-models-input">لیست مدل‌های گوشی:</label>
										<div class="tc-quick-presets-chips">
											<span class="tc-chips-label">درج سریع از الگوهای آماده:</span>
											<?php foreach ( $presets as $p ) : ?>
												<button type="button" class="tc-chip-btn" data-preset-id="<?php echo esc_attr( $p['id'] ); ?>">
													+ <?php echo esc_html( $p['name'] ); ?>
												</button>
											<?php endforeach; ?>
										</div>
									</div>
									<textarea id="tcbvm-models-input" class="tc-textarea" rows="4" placeholder="مثال:
iPhone 16 Pro Max
iPhone 16 Pro
iPhone 16 Plus
iPhone 16
(هر مدل در یک خط یا با ویرگول جدا کنید)"></textarea>
								</div>

								<!-- باکس اختصاصی جایگزینی مدل -->
								<div id="tcbvm-replace-input-wrap" style="display: none;">
									<div class="tc-form-grid">
										<div class="tc-field">
											<label class="tc-label" for="tcbvm-old-model">مدل قدیمی فعلی:</label>
											<input type="text" id="tcbvm-old-model" class="tc-input" placeholder="مثال: iPhone 11 Pro">
										</div>
										<div class="tc-field">
											<label class="tc-label" for="tcbvm-new-model">نام مدل جدید جایگزین:</label>
											<input type="text" id="tcbvm-new-model" class="tc-input" placeholder="مثال: آیفون ۱۱ پرو">
										</div>
									</div>
								</div>

								<!-- تنظیمات قیمت و موجودی (برای افزودن و همگام‌سازی) -->
								<div id="tcbvm-pricing-options-wrap" style="margin-top: 18px;">
									<h3 style="font-size: 14px; margin-bottom: 8px;">تنظیمات قیمت و موجودی متغیرهای جدید</h3>
									<div class="tc-pricing-box">
										<!-- گزینه شبیه‌سازی هوشمند قیمت -->
										<div class="tc-field">
											<label class="tc-checkbox-inline">
												<input type="checkbox" id="tcbvm-clone-price-check" checked>
												<strong>کپی هوشمند قیمت از یک مدل مرجع (توصیه‌شده برای قاب گوشی)</strong>
											</label>
											<div id="tcbvm-clone-ref-wrap" style="margin-top: 8px; margin-right: 22px;">
												<input type="text" id="tcbvm-clone-ref-model" class="tc-input" style="max-width: 320px;" placeholder="نام مدل مرجع، مثلاً: iPhone 15 Pro Max" value="iPhone 15 Pro Max">
												<small class="tc-hint">قیمت متغیر جدید دقیقاً برابر با قیمت این مدل در همان محصول قرار داده می‌شود.</small>
											</div>
										</div>

										<div class="tc-form-grid" style="margin-top: 12px;">
											<div class="tc-field">
												<label class="tc-label">قیمت عادی پیش‌فرض (تومان):</label>
												<input type="number" id="tcbvm-regular-price" class="tc-input" placeholder="مثال: 290000">
												<small class="tc-hint">در صورت پیدا نشدن مدل مرجع، این قیمت اعمال خواهد شد.</small>
											</div>
											<div class="tc-field">
												<label class="tc-label">قیمت فروش ویژه (اختیاری):</label>
												<input type="number" id="tcbvm-sale-price" class="tc-input" placeholder="مثال: 250000">
											</div>
											<div class="tc-field">
												<label class="tc-label">وضعیت موجودی متغیرهای جدید:</label>
												<select id="tcbvm-stock-status" class="tc-select">
													<option value="instock" selected>موجود در انبار (In Stock)</option>
													<option value="outofstock">ناموجود (Out of Stock)</option>
												</select>
											</div>
										</div>
									</div>
								</div>

								<!-- تنظیمات حالت حذف -->
								<div id="tcbvm-delete-mode-wrap" style="display: none; margin-top: 18px;">
									<label class="tc-label">نحوه حذف مدل‌های قدیمی:</label>
									<div class="tc-radio-group">
										<label class="tc-radio">
											<input type="radio" name="tcbvm_delete_mode" value="soft" checked>
											<span><strong>حالت ایمن (توصیه‌شده):</strong> فقط متغیر «ناموجود و مخفی» می‌شود تا سابقه‌ی سفارش‌ها و سئو حفظ گردد.</span>
										</label>
										<label class="tc-radio">
											<input type="radio" name="tcbvm_delete_mode" value="hard">
											<span><strong>حذف کامل و دائمی:</strong> رکورد متغیر و مقدار ویژگی کاملاً از دیتابیس پاک شود.</span>
										</label>
									</div>
								</div>
							</div>
						</div>

						<!-- مرحله ۳: پیش‌نمایش، تایید و اجرای پله‌ای -->
						<div class="tc-card" style="margin-top: 20px;">
							<div class="tc-step-badge">مرحله ۳ از ۳</div>
							<h2 class="tc-card-title">پیش‌نمایش آزمایشی و اجرای تغییرات گروهی</h2>
							<p class="tc-card-desc">قبل از هر اجرا، یک اسنپ‌شات کامل برای بازگردانی خودکار تهیه شده و پردازش به صورت پله‌ای (AJAX) انجام می‌گیرد تا سرور تایم‌اوت ندهد.</p>

							<div class="tc-actions-bar">
								<button type="button" class="tc-btn tc-btn--secondary" id="tcbvm-btn-preview">
									<span class="dashicons dashicons-visibility"></span>
									پیش‌نمایش آزمایشی (بدون تغییر)
								</button>
								<button type="button" class="tc-btn tc-btn--primary tc-btn--lg" id="tcbvm-btn-run">
									<span class="dashicons dashicons-controls-play"></span>
									شروع اعمال تغییرات روی محصولات انتخاب‌شده
								</button>
							</div>

							<!-- باکس پیش‌نمایش آزمایشی -->
							<div id="tcbvm-preview-output" class="tc-preview-box" style="display: none; margin-top: 20px;">
								<h3 class="tc-preview-title">نتیجه پیش‌نمایش روی نمونه محصولات:</h3>
								<div id="tcbvm-preview-content"></div>
							</div>

							<!-- باکس پروگرس‌بار و پردازش زنده -->
							<div id="tcbvm-progress-wrap" class="tc-progress-container" style="display: none; margin-top: 20px;">
								<div class="tc-progress-header">
									<span class="tc-progress-status-text" id="tcbvm-progress-text">در حال آماده‌سازی نشست و اسنپ‌شات…</span>
									<span class="tc-progress-percent" id="tcbvm-progress-percent">0%</span>
								</div>
								<div class="tc-progress-bar-bg">
									<div class="tc-progress-bar-fill" id="tcbvm-progress-bar" style="width: 0%;"></div>
								</div>
								<div class="tc-progress-stats">
									<span>کل محصولات: <strong id="tcbvm-stat-total">0</strong></span>
									<span>پردازش‌شده: <strong id="tcbvm-stat-processed">0</strong></span>
									<span>موفق: <strong id="tcbvm-stat-success" style="color: #0E7C6B;">0</strong></span>
									<span>خطا: <strong id="tcbvm-stat-failed" style="color: #B5453A;">0</strong></span>
								</div>
								<div class="tc-log-console" id="tcbvm-log-console"></div>
							</div>
						</div>
					</div>

					<!-- TAB 2: PRESETS -->
					<div id="tab-presets" class="tc-tab-pane <?php echo ( 'presets' === $active_tab ) ? 'is-active' : ''; ?>">
						<div class="tc-card">
							<h2 class="tc-card-title">الگوهای آماده مدل‌های گوشی (Model Presets)</h2>
							<p class="tc-card-desc">با استفاده از الگوها می‌توانید لیست‌های ۳۰-۴۰ تایی مدل‌ها را فقط با یک کلیک روی تمام قاب‌های فروشگاه پیاده‌سازی یا به‌روزرسانی کنید.</p>

							<!-- لیست الگوهای موجود -->
							<div class="tc-presets-grid" id="tcbvm-presets-cards-container">
								<?php foreach ( $presets as $preset ) : ?>
									<div class="tc-preset-card" data-preset-id="<?php echo esc_attr( $preset['id'] ); ?>">
										<div class="tc-preset-header">
											<h3 class="tc-preset-name"><?php echo esc_html( $preset['name'] ); ?></h3>
											<?php if ( ! empty( $preset['is_builtin'] ) ) : ?>
												<span class="tc-badge tc-badge--primary">سیستمی</span>
											<?php else : ?>
												<span class="tc-badge tc-badge--accent">سفارشی</span>
												<button type="button" class="tc-btn-icon tc-btn-delete-preset" data-id="<?php echo esc_attr( $preset['id'] ); ?>" title="حذف الگو">
													<span class="dashicons dashicons-trash"></span>
												</button>
											<?php endif; ?>
										</div>
										<p class="tc-preset-desc"><?php echo esc_html( $preset['description'] ); ?></p>
										<div class="tc-preset-models-preview">
											<?php
											$sample_models = array_slice( $preset['models'], 0, 12 );
											foreach ( $sample_models as $sm ) :
												?>
												<span class="tc-model-tag"><?php echo esc_html( $sm ); ?></span>
											<?php endforeach; ?>
											<?php if ( count( $preset['models'] ) > 12 ) : ?>
												<span class="tc-model-tag tc-model-tag--more">+ <?php echo count( $preset['models'] ) - 12; ?> مدل دیگر</span>
											<?php endif; ?>
										</div>
									</div>
								<?php endforeach; ?>
							</div>

							<!-- فرم ساخت الگوی سفارشی جدید -->
							<div class="tc-subcard" style="margin-top: 26px;">
								<h3 style="margin-top: 0;">افزودن الگوی سفارشی جدید</h3>
								<div class="tc-form-grid">
									<div class="tc-field">
										<label class="tc-label" for="tcbvm-new-preset-name">عنوان الگو:</label>
										<input type="text" id="tcbvm-new-preset-name" class="tc-input" placeholder="مثال: سری آیفون‌های پکیج ویژه ۱۴۰۳">
									</div>
									<div class="tc-field">
										<label class="tc-label" for="tcbvm-new-preset-desc">توضیح کوتاه:</label>
										<input type="text" id="tcbvm-new-preset-desc" class="tc-input" placeholder="مثال: مدل‌های چاپ روی قاب ژله‌ای">
									</div>
								</div>
								<div class="tc-field" style="margin-top: 14px;">
									<label class="tc-label" for="tcbvm-new-preset-models">لیست مدل‌های گوشی داخل این الگو:</label>
									<textarea id="tcbvm-new-preset-models" class="tc-textarea" rows="3" placeholder="مدل‌ها را با ویرگول یا در خطوط جداگانه وارد کنید..."></textarea>
								</div>
								<div class="tc-actions" style="margin-top: 14px;">
									<button type="button" class="tc-btn tc-btn--primary" id="tcbvm-btn-save-preset">
										<span class="dashicons dashicons-saved"></span>
										ذخیره الگو
									</button>
								</div>
							</div>
						</div>
					</div>

					<!-- TAB 3: HISTORY & ROLLBACK -->
					<div id="tab-history" class="tc-tab-pane <?php echo ( 'history' === $active_tab ) ? 'is-active' : ''; ?>">
						<div class="tc-card">
							<h2 class="tc-card-title">تاریخچه اجراها و بازگردانی سریع (Rollback)</h2>
							<p class="tc-card-desc">قبل از هر تغییر انبوه، تمام وضعیت متغیرها ذخیره می‌شود. اگر اشتباهی رخ داد یا نتیجه مطابق میلتان نبود، با یک کلیک همه چیز به حالت قبل بازمی‌گردد.</p>

							<?php if ( empty( $runs ) ) : ?>
								<div class="tc-empty-state">
									<span class="dashicons dashicons-info-outline"></span>
									<p>هنوز هیچ عملیات گروهی اجرا نشده است. پس از اجرای اولین تغییر، گزارش و دکمه بازگردانی در اینجا ظاهر خواهد شد.</p>
								</div>
							<?php else : ?>
								<div class="tc-table-responsive">
									<table class="tc-table">
										<thead>
											<tr>
												<th>شناسه اجرا</th>
												<th>عنوان عملیات</th>
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
															<span class="tc-badge tc-badge--success">انجام‌شده</span>
														<?php elseif ( 'rolled_back' === $run['status'] ) : ?>
															<span class="tc-badge tc-badge--muted">بازگردانده‌شده</span>
														<?php else : ?>
															<span class="tc-badge tc-badge--warn"><?php echo esc_html( $run['status'] ); ?></span>
														<?php endif; ?>
													</td>
													<td>
														<?php if ( 'rolled_back' !== $run['status'] ) : ?>
															<button type="button" class="tc-btn tc-btn--danger-outline tc-btn-rollback" data-run-id="<?php echo esc_attr( $r_id ); ?>">
																<span class="dashicons dashicons-undo"></span>
																بازگردانی (Rollback)
															</button>
														<?php else : ?>
															<span style="color: #77828A; font-size: 12px;">قبلاً بازگردانی شد</span>
														<?php endif; ?>
													</td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</div>
							<?php endif; ?>
						</div>
					</div>

					<!-- TAB 4: SETTINGS & TOOLS -->
					<div id="tab-settings" class="tc-tab-pane <?php echo ( 'settings' === $active_tab ) ? 'is-active' : ''; ?>">
						<div class="tc-card">
							<h2 class="tc-card-title">تنظیمات کارایی و بهینه‌سازی سرور</h2>
							<p class="tc-card-desc">تنظیمات مربوط به سرعت پردازش پله‌ای و ابزارهای نگهداری کش ووکامرس.</p>

							<div class="tc-subcard">
								<h3 style="margin-top: 0;">اندازه دسته‌ها (Batch Size)</h3>
								<p class="tc-hint">تعداد محصولاتی که در هر درخواست AJAX ویرایش می‌شوند. مقدار پیشنهادی ۵ تا ۱۰ است تا سرور با قطعی یا کمبود حافظه مواجه نشود.</p>
								<div class="tc-field" style="max-width: 220px;">
									<input type="number" id="tcbvm-setting-batch-size" class="tc-input" value="<?php echo esc_attr( $settings['batch_size'] ); ?>" min="1" max="50">
								</div>
							</div>

							<div class="tc-subcard" style="margin-top: 20px;">
								<h3 style="margin-top: 0;">ابزار پاکسازی کش قیمت‌های ووکامرس</h3>
								<p class="tc-hint">پس از تغییرات سنگین روی متغیرها، اگر محدوده قیمت در کاتالوگ فروشگاه به صورت موقت به‌روز نشد، با زدن این دکمه کش قیمت‌ها بازسازی می‌شود.</p>
								<button type="button" class="tc-btn tc-btn--secondary" id="tcbvm-btn-flush-cache">
									<span class="dashicons dashicons-update-alt"></span>
									نوسازی کش قیمت‌ها و ترنزینت‌های ووکامرس
								</button>
							</div>
						</div>
					</div>
				</main>
			</div>
			<?php
		}
	}
}
