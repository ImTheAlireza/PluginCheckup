<?php
/**
 * رابط مدیریت (Admin UI) منطبق بر زبان طراحی TisaCase Design System:
 * هدر گرادیانی سبز، تب‌های قرصی در هیرو، کارت‌های تخت با شماره‌گذاری گام‌ها، سوییچ‌های tisa-switch و دکمه‌های tisa-btn.
 *
 * @package TisaCase_Bulk_Variation_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCBVM_Admin' ) ) {

	final class TCBVM_Admin {

		public static function init() {
			add_action( 'admin_menu', array( __CLASS__, 'register_menus' ), 30 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
			add_action( 'admin_notices', array( __CLASS__, 'woocommerce_check_notice' ) );
		}

		/**
		 * آیا در صفحهٔ این افزونه هستیم؟
		 */
		public static function is_our_screen() {
			if ( isset( $_GET['page'] ) && TCBVM_Core::PAGE_SLUG === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
				return true;
			}
			if ( function_exists( 'get_current_screen' ) ) {
				$screen = get_current_screen();
				if ( $screen && ! empty( $screen->id ) && false !== strpos( $screen->id, TCBVM_Core::PAGE_SLUG ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * ثبت منو در هاب تیساکیس و منوی محصولات ووکامرس.
		 */
		public static function register_menus() {
			if ( ! TCBVM_Core::can() ) {
				return;
			}

			// ورودی استاندارد زیرمجموعه ووکامرس (مطابق tisacase-pricing و case-special-package)
			add_submenu_page(
				'woocommerce',
				'مدیریت گروهی متغیرها و مدل‌ها',
				'مدیریت متغیرها TisaCase',
				'manage_woocommerce',
				TCBVM_Core::PAGE_SLUG,
				array( __CLASS__, 'render_page' )
			);

			// میان‌بُر اختیاری در منوی محصولات
			add_submenu_page(
				'edit.php?post_type=product',
				'مدیریت گروهی متغیرها و مدل‌ها',
				'مدیریت متغیرهای گروهی',
				'manage_woocommerce',
				TCBVM_Core::PAGE_SLUG,
				array( __CLASS__, 'render_page' )
			);
		}

		public static function woocommerce_check_notice() {
			if ( ! class_exists( 'WooCommerce' ) ) {
				echo '<div class="notice notice-error"><p><strong>افزونه مدیریت متغیرهای TisaCase:</strong> برای استفاده از این افزونه، نصب و فعال‌سازی ووکامرس الزامی است.</p></div>';
			}
		}

		public static function enqueue_assets( $hook = '' ) {
			if ( ! self::is_our_screen() && false === strpos( (string) $hook, TCBVM_Core::PAGE_SLUG ) ) {
				return;
			}

			$deps = wp_style_is( 'tisacase-ui', 'registered' ) ? array( 'tisacase-ui' ) : array();

			wp_enqueue_style(
				'tcbvm-admin-css',
				TCBVM_URL . 'assets/admin.css',
				$deps,
				TCBVM_VERSION
			);

			wp_enqueue_script(
				'tcbvm-admin-js',
				TCBVM_URL . 'assets/admin.js',
				array( 'jquery' ),
				TCBVM_VERSION,
				true
			);

			wp_localize_script(
				'tcbvm-admin-js',
				'tcbvmData',
				array(
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'nonce'     => wp_create_nonce( TCBVM_Core::NONCE_ACTION ),
					'currency'  => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : 'تومان',
					'presets'   => TCBVM_Core::get_presets(),
					'batchSize' => method_exists( 'TCBVM_Core', 'get_batch_size' ) ? TCBVM_Core::get_batch_size() : 10,
					'i18n'      => array(
						'confirmStart'        => 'آیا از شروع عملیات روی {n} محصول انتخابی مطمئن هستید؟ از تغییرات پیش از اجرا به‌طور خودکار پشتیبان تهیه خواهد شد.',
						'confirmRollback'     => 'آیا از بازگردانی وضعیت محصولات به قبل از این عملیات اطمینان دارید؟',
						'selectProductsPrompt'=> 'لطفاً ابتدا حداقل یک محصول را از لیست انتخاب کنید.',
						'enterModelsPrompt'   => 'لطفاً حداقل یک مدل برای افزودن/حذف وارد نمایید.',
						'completedText'       => 'عملیات با موفقیت پایان یافت.',
						'confirmDeletePreset' => 'آیا از حذف این الگو مطمئن هستید؟',
					),
				)
			);
		}

		/**
		 * رندر صفحه واحد با زبان طراحی TisaCase (مشابه TisaCase Pricing و Case Special Package).
		 */
		public static function render_page() {
			if ( ! TCBVM_Core::can() ) {
				wp_die( 'دسترسی غیرمجاز است.' );
			}

			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'bulk'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! in_array( $tab, array( 'bulk', 'presets', 'runs', 'settings' ), true ) ) {
				$tab = 'bulk';
			}

			$base = admin_url( 'admin.php?page=' . TCBVM_Core::PAGE_SLUG );
			$tabs = array(
				'bulk'     => 'تغییر گروهی متغیرها و مدل‌ها',
				'presets'  => 'الگوهای آماده مدل گوشی',
				'runs'     => 'گزارش و بازگردانی (Rollback)',
				'settings' => 'تنظیمات و ابزارها',
			);

			$categories = TCBVM_DB::get_all_product_categories();
			$presets    = TCBVM_Core::get_presets();
			$runs       = TCBVM_Backup::get_all_runs();
			$settings   = TCBVM_Core::get_settings();

			if ( ! wp_style_is( 'tcbvm-admin-css', 'enqueued' ) ) {
				self::enqueue_assets();
			}
			?>
			<style id="tcbvm-critical-css">
				.tcbvm-wrap { box-sizing: border-box; width: 100%; max-width: 1120px; margin: 24px auto 0; padding: 0 20px 64px; font-variant-numeric: tabular-nums; }
				.tcbvm-hero { position: relative; overflow: hidden; margin: 0 0 28px; padding: 28px 28px 0; border-radius: 22px; background: linear-gradient(120deg, #0A5F52 0%, #0E7C6B 60%, #17A088 100%); color: #fff; box-shadow: 0 18px 40px -22px rgba(10, 95, 82, .55); }
				.tcbvm-hero-row { display: flex; align-items: center; gap: 16px; }
				.tcbvm-hero-mark { display: inline-flex; align-items: center; justify-content: center; width: 48px; height: 48px; min-width: 48px; max-width: 48px; border-radius: 14px; background: rgba(255, 255, 255, .16); color: #fff; }
				.tcbvm-hero-mark svg { width: 24px !important; height: 24px !important; max-width: 24px !important; max-height: 24px !important; display: block !important; }
				.tcbvm-hero-title { margin: 0; padding: 0; font-size: 22px; font-weight: 800; color: #fff; }
				.tcbvm-hero-sub { margin: 4px 0 0; font-size: 13px; opacity: .82; color: #fff; }
				.tcbvm-hero-ver { padding: 4px 10px; border-radius: 999px; background: rgba(255, 255, 255, .16); font-family: monospace; font-size: 11px; }
				.tcbvm-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin: 22px 0 0; padding: 0 0 20px; }
				.tcbvm-tab { display: inline-flex; align-items: center; height: 36px; padding: 0 16px; border-radius: 999px; background: rgba(255, 255, 255, .12); color: #fff !important; font-size: 13px; font-weight: 600; text-decoration: none; }
				.tcbvm-tab.is-active { background: #fff !important; color: #0A5F52 !important; }
				.tcbvm-card { margin: 0 0 20px; border: 1px solid #E3E1DA; border-radius: 18px; background: #fff; box-shadow: 0 2px 8px rgba(0, 0, 0, .02); }
				.tcbvm-card-head { display: flex; align-items: flex-start; gap: 14px; padding: 22px 24px 0; }
				.tcbvm-card-head h2 { margin: 0 0 3px; font-size: 16px; font-weight: 700; color: #1F2A2E; }
				.tcbvm-card-head p { margin: 0; font-size: 12.5px; color: #77828A; }
				.tcbvm-step { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: #E8F0EE; color: #0A5F52; font-weight: 800; font-size: 12.5px; }
				.tcbvm-card-body { padding: 16px 24px 24px; }
			</style>
			<div class="wrap tisa-wrap tcbvm-wrap" dir="rtl">
				<header class="tcbvm-hero">
					<div class="tcbvm-hero-row">
						<div class="tcbvm-hero-mark" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="24" height="24" style="width:24px!important;height:24px!important;min-width:24px!important;max-width:24px!important;display:block;" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
								<path d="M12 3l9 5-9 5-9-5zM3 13l9 5 9-5M3 18l9 5 9-5"/>
							</svg>
						</div>
						<div class="tcbvm-hero-text">
							<h1 class="tcbvm-hero-title">مدیریت گروهی متغیرها و مدل‌ها</h1>
							<p class="tcbvm-hero-sub">افزودن سری‌های جدید، حذف مدل‌های قدیمی، تغییر قیمت بر اساس مدل مرجع و بازگردانی خودکار (قاب‌های اسپیس و چاپی)</p>
						</div>
						<span class="tcbvm-hero-ver" dir="ltr">v<?php echo esc_html( TCBVM_VERSION ); ?></span>
					</div>
					<nav class="tcbvm-tabs" role="tablist">
						<?php foreach ( $tabs as $key => $label ) : ?>
							<a class="tcbvm-tab<?php echo $tab === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', $key, $base ) ); ?>">
								<?php echo esc_html( $label ); ?>
							</a>
						<?php endforeach; ?>
					</nav>
				</header>

				<div class="tcbvm-body">
					<?php if ( 'presets' === $tab ) : ?>
						<!-- تب ۲: الگوهای آماده -->
						<section class="tcbvm-card">
							<div class="tcbvm-card-head">
								<span class="tcbvm-step">۱</span>
								<div>
									<h2>الگوهای آماده مدل‌های گوشی (Model Presets)</h2>
									<p>الگوهای ذخیره‌شده مدل‌ها را می‌توانید با یک کلیک در بخش عملیات گروهی فراخوانی کنید.</p>
								</div>
							</div>
							<div class="tcbvm-card-body">
								<div class="tcbvm-table-scroll">
									<table class="tisa-table tcbvm-table">
										<thead>
											<tr>
												<th style="width: 200px;">نام الگو</th>
												<th>توضیحات</th>
												<th>نمونه مدل‌ها</th>
												<th style="width: 110px;">نوع / اقدام</th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ( $presets as $p_id => $preset ) : ?>
												<tr>
													<td><strong><?php echo esc_html( $preset['name'] ); ?></strong></td>
													<td><?php echo esc_html( $preset['description'] ); ?></td>
													<td>
														<div class="tcbvm-model-tags">
															<?php foreach ( array_slice( $preset['models'], 0, 8 ) as $m ) : ?>
																<span class="tcbvm-tag-model"><?php echo esc_html( $m ); ?></span>
															<?php endforeach; ?>
															<?php if ( count( $preset['models'] ) > 8 ) : ?>
																<span class="tcbvm-muted">+ <?php echo count( $preset['models'] ) - 8; ?> مدل دیگر</span>
															<?php endif; ?>
														</div>
													</td>
													<td>
														<?php if ( ! empty( $preset['is_builtin'] ) ) : ?>
															<span class="tcbvm-badge tcbvm-badge--success">سیستمی</span>
														<?php else : ?>
															<button type="button" class="tisa-btn tisa-btn--danger tisa-btn--sm tc-btn-delete-preset" data-id="<?php echo esc_attr( $p_id ); ?>">حذف</button>
														<?php endif; ?>
													</td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</div>

								<div class="tcbvm-subcard" style="margin-top: 24px;">
									<h3 style="margin-top: 0; font-size: 15px;">افزودن الگوی سفارشی جدید</h3>
									<div class="tcbvm-grid-2">
										<div class="tcbvm-field">
											<label class="tcbvm-label" for="tcbvm-new-preset-name">عنوان الگو</label>
											<input type="text" id="tcbvm-new-preset-name" class="tcbvm-input" placeholder="مثال: سری کامل آیفون‌های پاییز ۱۴۰۳">
										</div>
										<div class="tcbvm-field">
											<label class="tcbvm-label" for="tcbvm-new-preset-desc">توضیح کوتاه</label>
											<input type="text" id="tcbvm-new-preset-desc" class="tcbvm-input" placeholder="مثال: مخصوص قاب‌های اسپیس و چاپی">
										</div>
									</div>
									<div class="tcbvm-field">
										<label class="tcbvm-label" for="tcbvm-new-preset-models">لیست مدل‌های گوشی داخل این الگو (در خطوط جداگانه یا با ویرگول)</label>
										<textarea id="tcbvm-new-preset-models" class="tcbvm-textarea" rows="4" placeholder="مثلاً:&#10;iPhone 16&#10;iPhone 16 Plus&#10;iPhone 16 Pro&#10;iPhone 16 Pro Max"></textarea>
									</div>
									<div style="margin-top: 14px;">
										<button type="button" class="tisa-btn tisa-btn--primary" id="tcbvm-btn-save-preset">ذخیره الگو در سیستم</button>
									</div>
								</div>
							</div>
						</section>

					<?php elseif ( 'runs' === $tab ) : ?>
						<!-- تب ۳: گزارش و بازگردانی -->
						<section class="tcbvm-card">
							<div class="tcbvm-card-head">
								<span class="tcbvm-step">۱</span>
								<div>
									<h2>تاریخچه عملیات و قابلیت بازگردانی (Rollback Log)</h2>
									<p>قبل از هر عملیات، وضعیت متغیرها و قیمت‌ها ذخیره می‌شود و تا ۹۰ روز قابل بازگردانی کامل به حالت قبل است.</p>
								</div>
							</div>
							<div class="tcbvm-card-body">
								<?php if ( empty( $runs ) ) : ?>
									<div class="tcbvm-empty-state">
										<p>هنوز هیچ عملیاتی توسط این افزونه اجرا نشده است.</p>
									</div>
								<?php else : ?>
									<div class="tcbvm-table-scroll">
										<table class="tisa-table tcbvm-table">
											<thead>
												<tr>
													<th>شناسه اجرا</th>
													<th>زمان</th>
													<th>کاربر</th>
													<th>عملیات</th>
													<th>تعداد محصولات</th>
													<th>وضعیت</th>
													<th style="width: 140px;">اقدام</th>
												</tr>
											</thead>
											<tbody>
												<?php foreach ( $runs as $r ) : ?>
													<tr>
														<td><code><?php echo esc_html( $r->run_id ); ?></code></td>
														<td><?php echo esc_html( $r->created_at ); ?></td>
														<td><?php echo esc_html( $r->user_login ? $r->user_login : 'سیستم' ); ?></td>
														<td><strong><?php echo esc_html( self::get_op_title( $r->operation ) ); ?></strong></td>
														<td><?php echo number_format_i18n( (int) $r->total_products ); ?> محصول</td>
														<td>
															<?php if ( 'rolled_back' === $r->status ) : ?>
																<span class="tcbvm-badge tcbvm-badge--muted">بازگردانی شده</span>
															<?php elseif ( 'completed' === $r->status ) : ?>
																<span class="tcbvm-badge tcbvm-badge--success">تکمیل شده</span>
															<?php elseif ( 'completed_with_errors' === $r->status ) : ?>
																<span class="tcbvm-badge tcbvm-badge--warn">با خطا</span>
															<?php else : ?>
																<span class="tcbvm-badge"><?php echo esc_html( $r->status ); ?></span>
															<?php endif; ?>
														</td>
														<td>
															<?php if ( 'rolled_back' === $r->status ) : ?>
																<span class="tcbvm-muted">—</span>
															<?php else : ?>
																<button type="button" class="tisa-btn tisa-btn--danger tisa-btn--sm tc-btn-rollback" data-run-id="<?php echo esc_attr( $r->run_id ); ?>">
																	بازگردانی (Rollback)
																</button>
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

					<?php elseif ( 'settings' === $tab ) : ?>
						<!-- تب ۴: تنظیمات -->
						<form method="post" action="options.php">
							<?php settings_fields( 'tcbvm_settings_group' ); ?>
							<section class="tcbvm-card">
								<div class="tcbvm-card-head">
									<span class="tcbvm-step">۱</span>
									<div>
										<h2>تنظیمات هسته و نگهداری داده‌ها</h2>
										<p>تنظیمات پردازش دسته‌ای و ایمنی کار با دیتابیس محصولات متغیر.</p>
									</div>
								</div>
								<div class="tcbvm-card-body">
									<div class="tcbvm-grid-2">
										<div class="tcbvm-field">
											<label class="tcbvm-label" for="tcbvm_opt_batch_size">اندازه هر بسته پردازش (Batch Size)</label>
											<input type="number" id="tcbvm_opt_batch_size" name="tcbvm_settings[batch_size]" class="tcbvm-input" value="<?php echo esc_attr( $settings['batch_size'] ); ?>" min="1" max="100">
											<p class="tcbvm-muted">پیش‌فرض: ۱۰. در صورت استفاده از هاست اشتراکی، اعداد کمتر از ۱۵ مانع تایم‌اوت می‌شوند.</p>
										</div>

										<div class="tcbvm-field">
											<label class="tcbvm-label" for="tcbvm_opt_retention">مدت نگهداری لاگ و پشتیبان‌ها (روز)</label>
											<input type="number" id="tcbvm_opt_retention" name="tcbvm_settings[backup_retention_days]" class="tcbvm-input" value="<?php echo esc_attr( $settings['backup_retention_days'] ); ?>" min="7" max="365">
											<p class="tcbvm-muted">پیش‌فرض: ۹۰ روز. پشتیبان‌های قدیمی‌تر خودکار برای بهینه‌سازی دیتابیس پاک می‌شوند.</p>
										</div>
									</div>

									<div class="tcbvm-field" style="margin-top: 18px;">
										<label class="tisa-switch tcbvm-toggle">
											<input type="checkbox" name="tcbvm_settings[auto_sku]" value="1" <?php checked( ! empty( $settings['auto_sku'] ) ); ?>>
											<span class="tisa-switch__track" aria-hidden="true"></span>
											<span>تولید خودکار SKU متغیرها <span class="tcbvm-muted">— فرمت: {SKU والد}-{نام مدل انگلیسی یا اسلگ}</span></span>
										</label>
									</div>

									<div style="margin-top: 24px;">
										<?php submit_button( 'ذخیره تنظیمات', 'tisa-btn tisa-btn--primary', 'submit', false ); ?>
									</div>
								</div>
							</section>

							<section class="tcbvm-card">
								<div class="tcbvm-card-head">
									<span class="tcbvm-step">۲</span>
									<div>
										<h2>ابزارهای عیب‌یابی و کش</h2>
										<p>نوسازی کش قیمت‌های متغیر در ووکامرس برای رفع مغایرت قیمت کاتالوگ.</p>
									</div>
								</div>
								<div class="tcbvm-card-body">
									<p>اگر قیمت‌های متغیر در فرانت‌اند یا کاتالوگ بلافاصله به‌روز نشدند، با زدن دکمه زیر ترنزینت‌های کش ووکامرس را پاک کنید:</p>
									<button type="button" class="tisa-btn tisa-btn--soft" id="tcbvm-btn-flush-cache">نوسازی کش قیمت‌های ووکامرس</button>
								</div>
							</section>
						</form>

					<?php else : ?>
						<!-- تب ۱: عملیات گروهی (صفحه اصلی) -->
						<p class="tcbvm-lead">
							هدف را مشخص کنید، الگو یا مدل‌های موردنظر را وارد کرده و ابتدا «بررسی قبل از اجرا» را بزنید؛ تمامی عملیات‌ها دارای پیش‌نمایش و بازگردانی خودکار هستند.
						</p>

						<!-- گام ۱: هدف -->
						<section class="tcbvm-card">
							<div class="tcbvm-card-head">
								<span class="tcbvm-step">۱</span>
								<div>
									<h2>محصولات هدف</h2>
									<p>انتخاب دسته‌بندی یا فیلترهای مستقیم روی محصولات متغیر.</p>
								</div>
							</div>
							<div class="tcbvm-card-body">
								<div class="tcbvm-seg">
									<label class="tcbvm-radio">
										<input type="radio" name="tcbvm_target_mode" value="category" checked>
										<span>انتخاب بر اساس دسته‌بندی</span>
									</label>
									<label class="tcbvm-radio">
										<input type="radio" name="tcbvm_target_mode" value="manual">
										<span>انتخاب دستی با شناسه محصول (ID)</span>
									</label>
								</div>

								<div id="tcbvm-cat-box" class="tcbvm-field" style="margin-top: 18px;">
									<label class="tcbvm-label" for="tcbvm-cat-select">یک یا چند دسته‌بندی محصول (مثلاً قاب گوشی، قاب مگ‌سیف، قاب طرح‌دار)</label>
									<select id="tcbvm-cat-select" multiple="multiple" class="tcbvm-select" style="min-height: 110px;">
										<?php foreach ( $categories as $cat ) : ?>
											<option value="<?php echo esc_attr( $cat->term_id ); ?>">
												<?php echo esc_html( $cat->name . ' (' . $cat->count . ' محصول)' ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<label class="tisa-switch tcbvm-toggle">
										<input type="checkbox" id="tcbvm-cat-children" checked>
										<span class="tisa-switch__track" aria-hidden="true"></span>
										<span>زیردسته‌ها هم شامل شوند <span class="tcbvm-muted">— تمام زیرشاخه‌های دسته‌های منتخب نیز پردازش می‌شوند</span></span>
									</label>
								</div>

								<div id="tcbvm-manual-box" class="tcbvm-field" style="display:none; margin-top: 18px;">
									<label class="tcbvm-label" for="tcbvm-manual-ids">شناسه‌های محصول (IDs) — با کاما یا خط جدید جدا کنید</label>
									<textarea id="tcbvm-manual-ids" class="tcbvm-textarea" rows="3" placeholder="مثال: 1205, 1206, 1432"></textarea>
								</div>

								<div class="tcbvm-grid-3" style="margin-top: 16px;">
									<div class="tcbvm-field">
										<label class="tcbvm-label" for="tcbvm-keywords">شامل بودن کلمه کلیدی در عنوان (اختیاری)</label>
										<input type="text" id="tcbvm-keywords" class="tcbvm-input" placeholder="مثال: اسپیس، چاپی، سیلیکونی">
									</div>
									<div class="tcbvm-field">
										<label class="tcbvm-label" for="tcbvm-exclude-keywords">استثنا کردن عنوان (Exclude)</label>
										<input type="text" id="tcbvm-exclude-keywords" class="tcbvm-input" placeholder="مثال: محافظ لنز، شیشه‌ای">
									</div>
									<div class="tcbvm-field">
										<label class="tcbvm-label" for="tcbvm-model-filter">فقط محصولات دارای این مدل فعلی</label>
										<input type="text" id="tcbvm-model-filter" class="tcbvm-input" placeholder="مثال: iPhone 13">
									</div>
								</div>

								<div style="margin-top: 18px;">
									<button type="button" class="tisa-btn tisa-btn--soft" id="tcbvm-btn-search">
										جستجو و استخراج محصولات
									</button>
									<span id="tcbvm-search-counter" class="tcbvm-muted" style="margin-inline-start: 12px;"></span>
								</div>

								<!-- جدول انتخاب محصولات استخراج شده -->
								<div id="tcbvm-products-box" style="display:none; margin-top: 20px;">
									<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
										<h4 style="margin: 0; font-size: 14px; font-weight: 700;">محصولات واجد شرایط جهت عملیات:</h4>
										<span id="tcbvm-selected-badge" class="tcbvm-badge tcbvm-badge--success">۰ محصول انتخاب‌شده</span>
									</div>
									<div class="tcbvm-table-scroll" style="max-height: 280px;">
										<table class="tisa-table tcbvm-table">
											<thead>
												<tr>
													<th style="width:36px;"><input type="checkbox" id="tcbvm-select-all" checked></th>
													<th style="width:48px;">تصویر</th>
													<th>نام محصول</th>
													<th>SKU / شناسه</th>
													<th>دسته‌بندی</th>
													<th>تعداد متغیر</th>
													<th>نمونه مدل‌ها</th>
													<th style="width:60px;">لینک</th>
												</tr>
											</thead>
											<tbody id="tcbvm-products-tbody"></tbody>
										</table>
									</div>
								</div>
							</div>
						</section>

						<!-- گام ۲: نوع عملیات -->
						<section class="tcbvm-card">
							<div class="tcbvm-card-head">
								<span class="tcbvm-step">۲</span>
								<div>
									<h2>نوع عملیات روی متغیرها و مدل‌ها</h2>
									<p>انتخاب نوع تغییر روی ویژگی مدل گوشی (افزودن مدل‌های جدید، حذف مدل، تغییر نام، یا کپی قیمت از مدل مرجع).</p>
								</div>
							</div>
							<div class="tcbvm-card-body">
								<div class="tcbvm-field">
									<label class="tcbvm-label" for="tcbvm-op">عملیات اجرایی</label>
									<select id="tcbvm-op" class="tcbvm-select tcbvm-select--lg">
										<option value="add_models">افزودن مدل‌های جدید به محصولات (Add Variations)</option>
										<option value="sync_preset">همگام‌سازی کامل یک الگو (افزودن ناموجودها)</option>
										<option value="replace_model">تغییر نام یا جایگزینی یک مدل با مدل دیگر (Replace/Rename)</option>
										<option value="remove_models">حذف یک یا چند مدل از محصولات (Remove Variations)</option>
										<option value="bulk_price_stock">تنظیم دسته‌جمعی قیمت و موجودی مدل‌ها</option>
									</select>
								</div>

								<div class="tcbvm-field" style="margin-top: 14px;">
									<label class="tcbvm-label" for="tcbvm-attr-name">نام صفت/ویژگی ووکامرس (Attribute Name)</label>
									<input type="text" id="tcbvm-attr-name" class="tcbvm-input" value="مدل گوشی" placeholder="مثال: مدل گوشی یا Model">
									<p class="tcbvm-muted">نام ویژگی که مدل‌های گوشی روی آن سوار شده‌اند (معمولاً «مدل گوشی»).</p>
								</div>

								<!-- باکس درج مدل‌ها یا کلیک از روی الگوها -->
								<div id="tcbvm-models-input-wrap" class="tcbvm-field" style="margin-top: 18px;">
									<label class="tcbvm-label" for="tcbvm-models-input">لیست مدل‌های مورد نظر (در هر خط یک مدل بنویسید یا روی الگوهای زیر کلیک کنید)</label>
									<div class="tcbvm-chips-row">
										<span class="tcbvm-label-inline">درج سریع الگو:</span>
										<?php foreach ( $presets as $p_id => $preset ) : ?>
											<button type="button" class="tcbvm-chip-btn" data-preset-id="<?php echo esc_attr( $p_id ); ?>">
												+ <?php echo esc_html( $preset['name'] ); ?>
											</button>
										<?php endforeach; ?>
									</div>
									<textarea id="tcbvm-models-input" class="tcbvm-textarea" rows="5" placeholder="iPhone 16 Pro&#10;iPhone 16 Pro Max&#10;Samsung S24 Ultra"></textarea>
								</div>

								<!-- باکس جایگزینی مدل قدیمی با جدید -->
								<div id="tcbvm-replace-input-wrap" class="tcbvm-grid-2" style="display:none; margin-top: 18px;">
									<div class="tcbvm-field">
										<label class="tcbvm-label" for="tcbvm-old-model">نام مدل قدیمی (جهت جایگزینی)</label>
										<input type="text" id="tcbvm-old-model" class="tcbvm-input" placeholder="مثلاً: iPhone 11 Pro">
									</div>
									<div class="tcbvm-field">
										<label class="tcbvm-label" for="tcbvm-new-model">نام مدل جدید جایگزین</label>
										<input type="text" id="tcbvm-new-model" class="tcbvm-input" placeholder="مثلاً: iPhone 16">
									</div>
								</div>

								<!-- تنظیمات قیمت و موجودی مدل‌های جدید -->
								<div id="tcbvm-pricing-options-wrap" class="tcbvm-subcard" style="margin-top: 20px;">
									<h4 style="margin-top: 0; font-size: 14.5px; font-weight: 700;">قیمت‌گذاری و موجودی متغیرهای جدید</h4>

									<div style="margin-bottom: 14px;">
										<label class="tisa-switch tcbvm-toggle">
											<input type="checkbox" id="tcbvm-clone-price-check" checked>
											<span class="tisa-switch__track" aria-hidden="true"></span>
											<span>کپی هوشمند قیمت از مدل مرجع محصول <span class="tcbvm-muted">(توصیه تیساکیس برای قاب‌ها)</span></span>
										</label>
									</div>

									<div id="tcbvm-clone-ref-wrap" class="tcbvm-field" style="margin-bottom: 14px;">
										<label class="tcbvm-label" for="tcbvm-clone-ref-model">نام مدل مرجع برای استخراج قیمت (اختیاری — خالی بماند از اولین واریشن فعال کپی می‌شود)</label>
										<input type="text" id="tcbvm-clone-ref-model" class="tcbvm-input" placeholder="مثال: iPhone 13 Pro Max یا iPhone 15">
										<p class="tcbvm-muted">سیستم به‌طور خودکار قیمت عادی و فروش ویژهٔ این مدل را برمی‌دارد و روی مدل‌های جدید اضافه می‌کند.</p>
									</div>

									<div class="tcbvm-grid-3">
										<div class="tcbvm-field">
											<label class="tcbvm-label" for="tcbvm-regular-price">قیمت عادی ثابت (در صورت عدم کپی)</label>
											<input type="text" id="tcbvm-regular-price" class="tcbvm-input" placeholder="مثال: 380000">
										</div>
										<div class="tcbvm-field">
											<label class="tcbvm-label" for="tcbvm-sale-price">قیمت فروش ویژه ثابت (اختیاری)</label>
											<input type="text" id="tcbvm-sale-price" class="tcbvm-input" placeholder="مثال: 328000">
										</div>
										<div class="tcbvm-field">
											<label class="tcbvm-label" for="tcbvm-stock-status">وضعیت موجودی انبار</label>
											<select id="tcbvm-stock-status" class="tcbvm-select">
												<option value="instock">موجود در انبار (In Stock)</option>
												<option value="outofstock">ناموجود (Out of Stock)</option>
											</select>
										</div>
									</div>
								</div>

								<!-- نحوه حذف مدل -->
								<div id="tcbvm-delete-mode-wrap" class="tcbvm-field" style="display:none; margin-top: 18px;">
									<label class="tcbvm-label">نحوه برخورد با مدل‌های حذفی:</label>
									<div class="tcbvm-seg">
										<label class="tcbvm-radio">
											<input type="radio" name="tcbvm_delete_mode" value="soft" checked>
											<span>تغییر وضعیت به «ناموجود» (ایمن‌تر برای سئو و تاریخچه سفارش‌ها)</span>
										</label>
										<label class="tcbvm-radio">
											<input type="radio" name="tcbvm_delete_mode" value="hard">
											<span>حذف کامل متغیر از دیتابیس (Permanent Delete)</span>
										</label>
									</div>
								</div>
							</div>
						</section>

						<!-- گام ۳: بررسی و اجرا -->
						<section class="tcbvm-card">
							<div class="tcbvm-card-head">
								<span class="tcbvm-step">۳</span>
								<div>
									<h2>بررسی قبل از اجرا و شروع پردازش دسته‌ای</h2>
									<p>ابتدا با بررسی آزمایشی تغییرات را بازبینی کنید؛ پس از اطمینان، عملیات را با یک کلیک اجرا نمایید.</p>
								</div>
							</div>
							<div class="tcbvm-card-body">
								<div class="tcbvm-actions">
									<button type="button" class="tisa-btn tisa-btn--soft tisa-btn--lg" id="tcbvm-btn-preview">
										بررسی قبل از اجرا (Preview)
									</button>
									<button type="button" class="tisa-btn tisa-btn--primary tisa-btn--lg" id="tcbvm-btn-run">
										اجرای قطعی عملیات روی محصولات انتخابی
									</button>
								</div>

								<!-- خروجی پیش‌نمایش -->
								<div id="tcbvm-preview-output" style="display:none; margin-top: 20px;">
									<div class="tcbvm-alert tcbvm-alert--success">
										<strong>پیش‌نمایش آزمایشی آماده است:</strong>
										<div id="tcbvm-preview-content" style="margin-top: 8px;"></div>
									</div>
								</div>

								<!-- نوار پیشرفت و آمار زنده -->
								<div id="tcbvm-progress-wrap" class="tcbvm-progress-wrap" style="display:none; margin-top: 24px;">
									<div class="tcbvm-progress-header">
										<span id="tcbvm-progress-text" class="tcbvm-progress-text">در حال آماده‌سازی…</span>
										<span id="tcbvm-progress-percent" class="tcbvm-progress-percent">0%</span>
									</div>
									<div class="tcbvm-bar-track">
										<div id="tcbvm-bar-fill" class="tcbvm-bar-fill" style="width: 0%;"></div>
									</div>

									<div class="tcbvm-kpis">
										<div class="tcbvm-kpi">
											<span class="tcbvm-kpi-val" id="tcbvm-stat-total">0</span>
											<span class="tcbvm-kpi-label">کل محصولات</span>
										</div>
										<div class="tcbvm-kpi">
											<span class="tcbvm-kpi-val" id="tcbvm-stat-processed">0</span>
											<span class="tcbvm-kpi-label">پردازش‌شده</span>
										</div>
										<div class="tcbvm-kpi tcbvm-kpi--success">
											<span class="tcbvm-kpi-val" id="tcbvm-stat-success">0</span>
											<span class="tcbvm-kpi-label">موفق</span>
										</div>
										<div class="tcbvm-kpi tcbvm-kpi--danger">
											<span class="tcbvm-kpi-val" id="tcbvm-stat-failed">0</span>
											<span class="tcbvm-kpi-label">ناموفق / خطا</span>
										</div>
									</div>

									<div class="tcbvm-log-box">
										<div class="tcbvm-log-head">
											<span>گزارش زنده رویدادها (Realtime Execution Log)</span>
										</div>
										<pre id="tcbvm-log-console" class="tcbvm-log-console"></pre>
									</div>
								</div>
							</div>
						</section>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}

		private static function get_op_title( $op ) {
			$titles = array(
				'add_models'       => 'افزودن مدل‌های جدید',
				'sync_preset'      => 'همگام‌سازی الگو',
				'replace_model'    => 'جایگزینی/تغییر نام مدل',
				'remove_models'    => 'حذف مدل‌ها',
				'bulk_price_stock' => 'تنظیم قیمت و موجودی',
			);
			return isset( $titles[ $op ] ) ? $titles[ $op ] : $op;
		}
	}
}
