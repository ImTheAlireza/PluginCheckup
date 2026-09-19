<?php
/**
 * رابط مدیریت (Admin UI) حرفه‌ای منطبق بر زبان طراحی TisaCase Design System:
 * هدر گرادیانی سبز تیساکیس، تب‌های قرصی، کارت‌های تخت با شماره‌گذاری استپ‌ها، چیپ‌های تعاملی،
 * Select2 یکپارچه برای دسته‌ها، سوییچ‌های tisa-switch و دکمه‌های شکیل tisa-btn.
 *
 * @package TisaCase_Bulk_Variation_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCBVM_Admin' ) ) {

	final class TCBVM_Admin {

		public static function init() {
			add_action( 'admin_menu', array( __CLASS__, 'register_menus' ), 30 );
			// اولویت ۲۰: بعد از ووکامرس تا هندل‌های select2/enhanced-select ثبت شده باشند.
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 20 );
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

			if ( class_exists( 'WooCommerce' ) ) {
				wp_enqueue_style( 'woocommerce_admin_styles' );
				wp_enqueue_script( 'wc-enhanced-select' );
			}

			$deps = array( 'jquery' );
			if ( wp_script_is( 'wc-enhanced-select', 'registered' ) ) {
				$deps[] = 'wc-enhanced-select';
			}

			$css_deps = wp_style_is( 'tisacase-ui', 'registered' ) ? array( 'tisacase-ui' ) : array();

			// CSS سلکت۲: در ووکامرس هندلی به نام «select2» برای استایل وجود ندارد و CSS آن داخل
			// woocommerce_admin_styles (فایل admin.css) می‌آید. اگر آن استایل روی این صفحه نبود
			// (افزونه‌های بهینه‌ساز حذفش می‌کنند) یا هندل جداگانه‌ای ثبت شده بود، همان را می‌زنیم
			// تا منوی دسته‌بندی بدون استایل و عملاً غیرقابل‌استفاده نشود.
			if ( function_exists( 'WC' ) && WC() ) {
				if ( wp_style_is( 'select2', 'registered' ) ) {
					wp_enqueue_style( 'select2' );
					$css_deps[] = 'select2';
				} elseif ( wp_style_is( 'woocommerce_admin_styles', 'registered' ) ) {
					$css_deps[] = 'woocommerce_admin_styles';
				} elseif ( file_exists( WC()->plugin_path() . '/assets/css/select2.css' ) ) {
					wp_enqueue_style( 'tcbvm-select2', WC()->plugin_url() . '/assets/css/select2.css', array(), '4.0.3' );
					$css_deps[] = 'tcbvm-select2';
				}
			}

			// برای جلوگیری قطعی از کش شدن فایل CSS توسط مرورگر کاربر
			$css_file = TCBVM_PATH . 'assets/admin.css';
			$ver      = TCBVM_VERSION . '.' . ( file_exists( $css_file ) ? filemtime( $css_file ) : time() );

			wp_enqueue_style(
				'tcbvm-admin-css',
				TCBVM_URL . 'assets/admin.css',
				$css_deps,
				$ver
			);

			wp_enqueue_script(
				'tcbvm-admin-js',
				TCBVM_URL . 'assets/admin.js',
				$deps,
				$ver,
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
						'confirmStart'        => 'آیا از شروع عملیات روی {n} محصول انتخابی مطمئن هستید؟ از تمام متغیرها قبل از اجرا به‌طور خودکار پشتیبان کامل گرفته خواهد شد.',
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
			<div class="wrap tisa-wrap tcbvm-wrap" dir="rtl">
				<!-- هدر گرادیانی سبز تیساکیس با تب‌های قرصی -->
				<header class="tcbvm-hero">
					<div class="tcbvm-hero-row">
						<div class="tcbvm-hero-mark" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
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
												<th style="width: 220px;">نام الگو</th>
												<th>توضیحات</th>
												<th>نمونه مدل‌ها</th>
												<th style="width: 110px; text-align: center;">نوع / اقدام</th>
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
													<td style="text-align: center;">
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
									<h3 style="margin-top: 0; font-size: 15px; font-weight: 700; color: #0F172A;">افزودن الگوی سفارشی جدید</h3>
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
										<button type="button" class="tisa-btn tisa-btn--primary" id="tcbvm-btn-save-preset">
											ذخیره الگو در سیستم
										</button>
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
													<th style="width: 140px; text-align: center;">اقدام</th>
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
														<td style="text-align: center;">
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
										<p class="tcbvm-muted">پیش‌فرض: ۵. برای عملیات «افزودن مدل» و «همگام‌سازی با الگو» که هر محصول صدها متغیر دارد، مقدار ۲ تا ۵ امن‌ترین انتخاب است؛ عدد بزرگ‌تر ریسک تایم‌اوت سرور در میانهٔ کار را بالا می‌برد. اگر خطای سرور دیدی، همین عدد را کمتر کن.</p>
										</div>

										<div class="tcbvm-field">
											<label class="tcbvm-label" for="tcbvm_opt_retention">مدت نگهداری لاگ و پشتیبان‌ها (روز)</label>
											<input type="number" id="tcbvm_opt_retention" name="tcbvm_settings[backup_retention_days]" class="tcbvm-input" value="<?php echo esc_attr( $settings['backup_retention_days'] ); ?>" min="7" max="365">
											<p class="tcbvm-muted">پیش‌فرض: ۹۰ روز. پشتیبان‌های قدیمی‌تر خودکار برای بهینه‌سازی دیتابیس پاک می‌شوند.</p>
										</div>
									</div>

									<div class="tcbvm-field" style="margin-top: 18px;">
										<label class="tisa-switch tcbvm-toggle">
											<input type="checkbox" name="tcbvm_settings[update_only]" value="1" <?php checked( ! empty( $settings['update_only'] ) ); ?>>
											<span class="tisa-switch__track" aria-hidden="true"></span>
											<span>فقط به‌روزرسانی <span class="tcbvm-muted">— هیچ مدل، متغیر، محصول یا ویژگی جدیدی ساخته نمی‌شود؛ فقط رکوردهای موجود به‌روزرسانی می‌شوند (پیشنهاد: روشن)</span></span>
										</label>
										<p class="tcbvm-muted" style="margin-top: 8px;">این افزونه هیچ‌وقت SKU نمی‌سازد و SKUهای موجود را تغییر نمی‌دهد؛ SKUها متعلق به خود فروشگاه است. برای ساخت مدل‌های جدید در عملیات «افزودن مدل»، این گزینه را خاموش کن.</p>
									</div>

									<div class="tcbvm-field" style="margin-top: 18px;">
										<label class="tisa-switch tcbvm-toggle">
											<input type="checkbox" name="tcbvm_settings[sync_rebuild]" value="1" <?php checked( ! empty( $settings['sync_rebuild'] ) ); ?>>
											<span class="tisa-switch__track" aria-hidden="true"></span>
											<span>بازسازی کامل در «همگام‌سازی با الگو» <span class="tcbvm-muted">— همهٔ متغیرهای آن ویژگی حذف و دقیقاً از فهرست واردشده از نو ساخته می‌شوند (پیشنهاد: روشن)</span></span>
										</label>
										<p class="tcbvm-muted" style="margin-top: 8px;">در این حالت ترتیب و تعداد متغیرها دقیقاً برابر فهرست شماست (۱۱۲ مدل = ۱۱۲ متغیر): همهٔ متغیرهای قبلی حذف و از صفر ساخته می‌شوند، و ویژگی انتخاب‌شده (مثل «مدل») با همان نام بازسازی می‌شود. برای بازگردانی، از تب «گزارش و بازگردانی» استفاده کن.</p>
									</div>

									<div class="tcbvm-field" style="margin-top: 18px;">
										<label class="tisa-switch tcbvm-toggle">
											<input type="checkbox" name="tcbvm_settings[sync_keep_other_attrs]" value="1" <?php checked( ! empty( $settings['sync_keep_other_attrs'] ) ); ?>>
											<span class="tisa-switch__track" aria-hidden="true"></span>
											<span>حفظ سایر ویژگی‌های متغیر (ساخت ترکیبی) <span class="tcbvm-muted">— پیش‌فرض: خاموش</span></span>
										</label>
										<p class="tcbvm-muted" style="margin-top: 8px;">خاموش (پیشنهاد): دقیقاً «یک متغیر به‌ازای هر مدل» ساخته می‌شود و سایر ویژگی‌های متغیر از محصول برداشته می‌شوند (داده‌هایشان حذف نمی‌شود و با بازگردانی برمی‌گردد). روشن: هر مدل در ترکیب با سایر ویژگی‌ها ضرب می‌شود (مثلاً ۱۱۲ مدل × ۳ رنگ = ۳۳۶ متغیر) — فقط وقتی محصول واقعاً چند ویژگی متغیر دارد.</p>
									</div>

									<div style="margin-top: 24px;">
										<button type="submit" class="tisa-btn tisa-btn--primary">ذخیره تنظیمات</button>
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
									<p class="tcbvm-muted" style="margin-bottom: 14px;">اگر قیمت‌های متغیر در فرانت‌اند یا کاتالوگ بلافاصله به‌روز نشدند، با زدن دکمه زیر ترنزینت‌های کش ووکامرس را پاک کنید:</p>
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
									<p>انتخاب دسته‌بندی یا شناسه‌های مستقیم محصولات جهت ویرایش و اعمال متغیرها</p>
								</div>
							</div>
							<div class="tcbvm-card-body">
								<!-- نوار سگمنت دکمه‌ای مدرن -->
								<div class="tcbvm-seg-bar">
									<label class="tcbvm-seg-item is-active">
										<input type="radio" name="tcbvm_target_mode" value="category" checked>
										<span>انتخاب بر اساس دسته‌بندی</span>
									</label>
									<label class="tcbvm-seg-item">
										<input type="radio" name="tcbvm_target_mode" value="manual">
										<span>انتخاب دستی با شناسه محصول (ID)</span>
									</label>
								</div>

								<!-- باکس دسته‌بندی با سلکت۲ -->
								<div id="tcbvm-cat-box" class="tcbvm-field" style="margin-top: 20px;">
									<label class="tcbvm-label" for="tcbvm-cat-select">انتخاب دسته‌بندی‌ها (قاب گوشی، قاب اسپیس، قاب چاپی و...)</label>
									<select id="tcbvm-cat-select" multiple="multiple" class="tcbvm-select wc-enhanced-select" data-placeholder="دسته‌بندی‌ها را انتخاب یا جستجو کنید…" data-allow_clear="true" style="width: 100%;">
										<?php
										foreach ( $categories as $cat ) :
											// TCBVM_DB::get_all_product_categories() آرایهٔ کلیددار برمی‌گرداند (id/name/count/parent)؛
											// دسترسی با -> مقادیر را خالی می‌کرد و منو بدون آیتم معتبر می‌ماند.
											$cat_id    = is_array( $cat ) ? (int) $cat['id'] : (int) $cat->term_id;
											$cat_name  = is_array( $cat ) ? (string) $cat['name'] : (string) $cat->name;
											$cat_count = is_array( $cat ) ? (int) $cat['count'] : (int) $cat->count;
											?>
											<option value="<?php echo esc_attr( $cat_id ); ?>">
												<?php echo esc_html( $cat_name . ' (' . number_format_i18n( $cat_count ) . ' محصول)' ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<?php if ( empty( $categories ) ) : ?>
										<p class="tcbvm-muted">هیچ دستهٔ محصولی در فروشگاه ساخته نشده است؛ منو عمداً خالی است. اول از «محصولات ← دسته‌ها» دسته بساز یا از حالت «انتخاب دستی با شناسه محصول» استفاده کن.</p>
									<?php endif; ?>
									<div style="margin-top: 10px;">
										<label class="tisa-switch tcbvm-toggle">
											<input type="checkbox" id="tcbvm-cat-children" checked>
											<span class="tisa-switch__track" aria-hidden="true"></span>
											<span>زیردسته‌ها هم شامل شوند <span class="tcbvm-muted">— تمامی زیرشاخه‌های دسته‌های منتخب نیز پردازش خواهند شد</span></span>
										</label>
									</div>
								</div>

								<!-- باکس شناسه دستی -->
								<div id="tcbvm-manual-box" class="tcbvm-field tcbvm-hidden" style="margin-top: 20px;">
									<label class="tcbvm-label" for="tcbvm-manual-ids">شناسه‌های محصول (IDs)</label>
									<textarea id="tcbvm-manual-ids" class="tcbvm-textarea" rows="3" placeholder="شناسه‌ها را با کاما یا در خطوط جداگانه وارد کنید (مثال: 1205, 1206, 1432)"></textarea>
								</div>

								<!-- فیلترهای تکمیلی ۳ ستونه -->
								<div class="tcbvm-grid-3" style="margin-top: 20px;">
									<div class="tcbvm-field">
										<label class="tcbvm-label" for="tcbvm-keywords">کلمه کلیدی در عنوان (اختیاری)</label>
										<input type="text" id="tcbvm-keywords" class="tcbvm-input" placeholder="مثال: اسپیس، چاپی، شفاف">
									</div>
									<div class="tcbvm-field">
										<label class="tcbvm-label" for="tcbvm-exclude-keywords">استثنا کردن عنوان (Exclude)</label>
										<input type="text" id="tcbvm-exclude-keywords" class="tcbvm-input" placeholder="مثال: محافظ لنز، شیشه‌ای">
									</div>
									<div class="tcbvm-field">
										<label class="tcbvm-label" for="tcbvm-model-filter">فیلتر فقط محصولات دارای این مدل</label>
										<input type="text" id="tcbvm-model-filter" class="tcbvm-input" placeholder="مثال: iPhone 13">
									</div>
								</div>

								<div class="tcbvm-actions" style="margin-top: 22px;">
									<button type="button" class="tisa-btn tisa-btn--soft" id="tcbvm-btn-search">
										<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
										<span>جستجو و استخراج محصولات</span>
									</button>
									<span id="tcbvm-search-counter" class="tcbvm-counter-text"></span>
								</div>

								<!-- جدول نتایج جستجو -->
								<div id="tcbvm-products-box" class="tcbvm-hidden" style="margin-top: 24px;">
									<div class="tcbvm-products-header">
										<h4 class="tcbvm-products-title">محصولات انتخاب‌شده جهت اعمال عملیات:</h4>
										<span id="tcbvm-selected-badge" class="tcbvm-badge tcbvm-badge--success">۰ محصول انتخاب‌شده</span>
									</div>
									<div class="tcbvm-table-scroll" style="max-height: 320px;">
										<table class="tisa-table tcbvm-table">
											<thead>
												<tr>
													<th style="width:38px; text-align:center;"><input type="checkbox" id="tcbvm-select-all" checked></th>
													<th style="width:48px;">تصویر</th>
													<th>نام محصول</th>
													<th>SKU / شناسه</th>
													<th>دسته‌بندی</th>
													<th>تعداد متغیر</th>
													<th>نمونه مدل‌ها</th>
													<th style="width:70px; text-align:center;">اقدام</th>
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
									<p>تعیین عملیات تغییر متغیرها، مقادیر مدل‌ها، قیمت‌گذاری و وضعیت موجودی</p>
								</div>
							</div>
							<div class="tcbvm-card-body">
								<div class="tcbvm-grid-2">
									<div class="tcbvm-field">
										<label class="tcbvm-label" for="tcbvm-op">نوع عملیات اجرایی</label>
										<select id="tcbvm-op" class="tcbvm-select tcbvm-select--lg">
											<option value="add_models">افزودن مدل‌های جدید به محصولات (Add Variations)</option>
											<option value="sync_preset">همگام‌سازی کامل یک الگو (افزودن مدل‌های ناموجود)</option>
											<option value="replace_model">تغییر نام یا جایگزینی یک مدل با مدل دیگر (Replace/Rename)</option>
											<option value="remove_models">حذف یک یا چند مدل از محصولات (Remove Variations)</option>
											<option value="bulk_price_stock">تنظیم دسته‌جمعی قیمت و موجودی مدل‌ها</option>
										</select>
									</div>
									<div class="tcbvm-field">
										<label class="tcbvm-label" for="tcbvm-attr-name">نام صفت/ویژگی متغیر در ووکامرس</label>
										<input type="text" id="tcbvm-attr-name" class="tcbvm-input" value="مدل گوشی" placeholder="مثال: مدل گوشی یا Model">
										<p class="tcbvm-muted">نام صفتی که مدل‌ها روی آن تعریف شده‌اند (مثلاً «مدل» یا «مدل گوشی»). افزونه خودش ویژگی موجود همنام را پیدا می‌کند.</p>
									</div>
								</div>

								<!-- باکس مدل‌ها و الگوها -->
								<div id="tcbvm-models-input-wrap" class="tcbvm-field" style="margin-top: 20px;">
									<div class="tcbvm-field-header">
										<label class="tcbvm-label" for="tcbvm-models-input" style="margin-bottom: 0;">لیست مدل‌های مورد نظر (با «|» یا در هر خط یک مدل — کاما داخل نام مدل حفظ می‌شود)</label>
									</div>
									<div class="tcbvm-chips-row">
										<span class="tcbvm-chips-title">الگوهای آماده تیساکیس:</span>
										<?php foreach ( $presets as $p_id => $preset ) : ?>
											<button type="button" class="tcbvm-chip-btn" data-preset-id="<?php echo esc_attr( $p_id ); ?>">
												<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
												<span><?php echo esc_html( $preset['name'] ); ?></span>
											</button>
										<?php endforeach; ?>
									</div>
									<textarea id="tcbvm-models-input" class="tcbvm-textarea" rows="5" placeholder="iPhone 6s | iPhone 7,8,SE | iPhone X,Xs | iPhone 11 Pro Max | Redmi Note 9s,9 Pro | Samsung A54"></textarea>
									<p class="tcbvm-muted">هر «|» یا هر خط، یک مدل است. کاما داخل نام مدل می‌ماند (مثل <span class="tisa-code">iPhone 7,8,SE</span>). <strong id="tcbvm-models-count">۰ مدل شناسایی شد</strong></p>
								</div>

								<!-- باکس جایگزینی مدل -->
								<div id="tcbvm-replace-input-wrap" class="tcbvm-grid-2 tcbvm-hidden" style="margin-top: 20px;">
									<div class="tcbvm-field">
										<label class="tcbvm-label" for="tcbvm-old-model">نام مدل قدیمی (جهت جایگزینی)</label>
										<input type="text" id="tcbvm-old-model" class="tcbvm-input" placeholder="مثلاً: iPhone 11 Pro">
									</div>
									<div class="tcbvm-field">
										<label class="tcbvm-label" for="tcbvm-new-model">نام مدل جدید جایگزین</label>
										<input type="text" id="tcbvm-new-model" class="tcbvm-input" placeholder="مثلاً: iPhone 16">
									</div>
								</div>

								<!-- باکس قیمت‌گذاری و موجودی -->
								<div id="tcbvm-pricing-options-wrap" class="tcbvm-subcard" style="margin-top: 22px;">
									<div class="tcbvm-subcard-head">
										<div class="tcbvm-subcard-icon">
											<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
										</div>
										<div>
											<h4 style="margin: 0; font-size: 15px; font-weight: 700; color: #0F172A;">قیمت‌گذاری و موجودی متغیرهای جدید</h4>
											<p style="margin: 2px 0 0; font-size: 12px; color: #64748B;">تعیین قیمت بر اساس کپی خودکار از مدل‌های موجود یا تنظیم قیمت ثابت</p>
										</div>
									</div>

									<div style="margin-top: 16px;">
										<label class="tisa-switch tcbvm-toggle">
											<input type="checkbox" id="tcbvm-clone-price-check" checked>
											<span class="tisa-switch__track" aria-hidden="true"></span>
											<span>کپی هوشمند قیمت از مدل مرجع محصول <span class="tcbvm-badge tcbvm-badge--success" style="margin-inline-start: 6px;">پیشنهاد تیساکیس</span></span>
										</label>
									</div>

									<div id="tcbvm-clone-ref-wrap" class="tcbvm-field" style="margin-top: 14px;">
										<label class="tcbvm-label" for="tcbvm-clone-ref-model">نام مدل مرجع برای کپی قیمت (اختیاری — در صورت خالی بودن، اولین واریشن فعال کپی می‌شود)</label>
										<input type="text" id="tcbvm-clone-ref-model" class="tcbvm-input" placeholder="مثال: iPhone 13 Pro Max یا iPhone 15">
										<p class="tcbvm-muted">قیمت عادی و فروش ویژهٔ این مدل برداشته شده و دقیقاً روی مدل‌های جدید اضافه می‌شود.</p>
									</div>

									<div class="tcbvm-grid-3" style="margin-top: 16px;">
										<div class="tcbvm-field">
											<label class="tcbvm-label" for="tcbvm-regular-price">قیمت عادی ثابت (تومان)</label>
											<input type="text" id="tcbvm-regular-price" class="tcbvm-input" placeholder="مثال: 380000">
										</div>
										<div class="tcbvm-field">
											<label class="tcbvm-label" for="tcbvm-sale-price">قیمت فروش ویژه (اختیاری)</label>
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

								<!-- حالت حذف مدل -->
								<div id="tcbvm-delete-mode-wrap" class="tcbvm-field tcbvm-hidden" style="margin-top: 20px;">
									<label class="tcbvm-label">نحوه برخورد با مدل‌های حذفی:</label>
									<div class="tcbvm-seg-bar">
										<label class="tcbvm-seg-item is-active">
											<input type="radio" name="tcbvm_delete_mode" value="soft" checked>
											<span>ناموجود کردن (حفظ سئو و تاریخچه سفارشات)</span>
										</label>
										<label class="tcbvm-seg-item">
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
										<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
										<span>بررسی قبل از اجرا (Preview)</span>
									</button>
									<button type="button" class="tisa-btn tisa-btn--primary tisa-btn--lg" id="tcbvm-btn-run">
										<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
										<span>اجرای قطعی عملیات روی محصولات انتخابی</span>
									</button>
								</div>

								<!-- خروجی پیش‌نمایش -->
								<div id="tcbvm-preview-output" class="tcbvm-hidden" style="margin-top: 22px;">
									<div class="tcbvm-preview-card">
										<div class="tcbvm-preview-head">
											<span class="tcbvm-preview-badge">نتیجه بررسی آزمایشی</span>
											<p style="margin: 4px 0 0; font-size: 13px; color: #475569;">پیش‌نمایش تغییراتی که روی محصولات منتخب اعمال خواهد شد:</p>
										</div>
										<div id="tcbvm-preview-content" class="tcbvm-preview-body"></div>
									</div>
								</div>

								<!-- نوار پیشرفت و آمار زنده -->
								<div id="tcbvm-progress-wrap" class="tcbvm-progress-wrap tcbvm-hidden" style="margin-top: 24px;">
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
											<span class="tcbvm-kpi-label">خطا / ناموفق</span>
										</div>
									</div>

									<div class="tcbvm-log-box">
										<div class="tcbvm-log-head">
											<div style="display:flex; align-items:center; gap:8px;">
												<span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:#10B981;"></span>
												<span>گزارش زنده رویدادها (Realtime Execution Log)</span>
											</div>
											<span style="font-size:11px; opacity:.7;">اتصال فعال</span>
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
