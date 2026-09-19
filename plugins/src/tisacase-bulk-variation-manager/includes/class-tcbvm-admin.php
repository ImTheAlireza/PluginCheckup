<?php
/**
 * رابط کاربری مدیریت (Admin UI) حرفه‌ای تیساکیس:
 * طراحی گام‌به‌گام بر پایه سیستم طراحی TisaCase، هدر گرادیانی سبز تیساکیس،
 * تب‌های قرصی، جدول تعاملی محصولات، ورودی هوشمند ویژگی و متغیرها،
 * کادر قیمت‌گذاری زنده، نوار پیشرفت و لاگ بلادرنگ.
 *
 * @package TisaCase_Bulk_Variation_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCBVM_Admin' ) ) {

	final class TCBVM_Admin {

		public static function init() {
			add_action( 'admin_menu', array( __CLASS__, 'register_menus' ), 30 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 20 );
			add_action( 'admin_notices', array( __CLASS__, 'woocommerce_check_notice' ) );
		}

		public static function is_our_screen() {
			if ( isset( $_GET['page'] ) && TCBVM_Core::PAGE_SLUG === sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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

		public static function register_menus() {
			if ( ! TCBVM_Core::can() ) {
				return;
			}

			// زیرمنوی ووکامرس
			add_submenu_page(
				'woocommerce',
				'تغییر و تولید انبوه متغیرها',
				'مدیریت متغیرها TisaCase',
				'manage_woocommerce',
				TCBVM_Core::PAGE_SLUG,
				array( __CLASS__, 'render_page' )
			);

			// میان‌بر در منوی محصولات
			add_submenu_page(
				'edit.php?post_type=product',
				'تغییر و تولید انبوه متغیرها',
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
					'batchSize' => TCBVM_Core::get_batch_size(),
					'i18n'      => array(
						'confirmStart'         => 'آیا از شروع تولید و بازسازی متغیرها روی {n} محصول انتخابی مطمئن هستید؟ از تمام متغیرها و قیمت‌های قبلی نسخه پشتیبان کامل برای بازگردانی تهیه خواهد شد.',
						'confirmRollback'      => 'آیا از بازگردانی وضعیت محصولات به حالت قبل از این عملیات اطمینان دارید؟',
						'selectProductsPrompt' => 'لطفاً ابتدا حداقل یک محصول را از جدول انتخاب کنید.',
						'enterAttrPrompt'      => 'لطفاً نام ویژگی را وارد کنید (مثلاً: مدل گوشی).',
						'enterModelsPrompt'    => 'لطفاً حداقل یک متغیر جدید در کادر متغیرها وارد نمایید.',
						'enterPricePrompt'     => 'لطفاً مبلغ قیمت متغیرها را وارد نمایید.',
						'completedText'        => 'عملیات تولید و بازسازی متغیرها با موفقیت پایان یافت.',
						'confirmDeletePreset'  => 'آیا از حذف این الگو مطمئن هستید؟',
						'productAdded'         => 'محصول به لیست اضافه شد.',
					),
				)
			);
		}

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
				'bulk'     => 'تولید و بازسازی انبوه متغیرها',
				'presets'  => 'الگوهای آماده مدل‌ها',
				'runs'     => 'تاریخچه و بازگردانی (Rollback)',
				'settings' => 'تنظیمات و ابزارها',
			);

			$categories = TCBVM_DB::get_all_product_categories();
			$presets    = TCBVM_Core::get_presets();
			$runs       = TCBVM_Backup::get_all_runs();
			$settings   = TCBVM_Core::get_settings();
			$attributes = TCBVM_DB::get_attribute_taxonomies();

			if ( ! wp_style_is( 'tcbvm-admin-css', 'enqueued' ) ) {
				self::enqueue_assets();
			}
			?>
			<div class="wrap tisa-wrap tcbvm-wrap" dir="rtl">
				<!-- هدر شکیل با گرادیان سبز تیساکیس -->
				<header class="tcbvm-hero">
					<div class="tcbvm-hero-row">
						<div class="tcbvm-hero-mark" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
								<line x1="8" y1="21" x2="16" y2="21"/>
								<line x1="12" y1="17" x2="12" y2="21"/>
							</svg>
						</div>
						<div class="tcbvm-hero-text">
							<h1 class="tcbvm-hero-title">تغییر و تولید انبوه متغیرها</h1>
							<p class="tcbvm-hero-sub">انتخاب گروهی محصولات، تعیین ویژگی و متغیرهای جدید، تولید خودکار تمام ترکیب‌ها و قیمت‌گذاری یکپارچه</p>
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
									<p>الگوهای ذخیره‌شده را می‌توانید در بخش عملیات گروهی با یک کلیک روی متغیرها اعمال کنید.</p>
								</div>
							</div>
							<div class="tcbvm-card-body">
								<div class="tcbvm-table-scroll">
									<table class="tisa-table tcbvm-table">
										<thead>
											<tr>
												<th class="tcbvm-col-w220">نام الگو</th>
												<th>توضیحات</th>
												<th>تعداد و نمونه مدل‌ها</th>
												<th class="tcbvm-col-w110 tcbvm-center">نوع / اقدام</th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ( $presets as $p_id => $p ) : ?>
												<tr>
													<td><strong><?php echo esc_html( $p['name'] ); ?></strong></td>
													<td class="tcbvm-muted"><?php echo esc_html( ! empty( $p['description'] ) ? $p['description'] : '—' ); ?></td>
													<td>
														<span class="tcbvm-badge tcbvm-badge--info"><?php echo number_format_i18n( count( $p['models'] ) ); ?> مدل</span>
														<div class="tcbvm-preset-tags">
															<?php
															$shown_models = array_slice( $p['models'], 0, 7 );
															foreach ( $shown_models as $m ) :
																?>
																<span class="tcbvm-tag"><?php echo esc_html( $m ); ?></span>
															<?php endforeach; ?>
															<?php if ( count( $p['models'] ) > 7 ) : ?>
																<span class="tcbvm-tag tcbvm-tag--more">+<?php echo number_format_i18n( count( $p['models'] ) - 7 ); ?> دیگر</span>
															<?php endif; ?>
														</div>
													</td>
													<td class="tcbvm-center">
														<?php if ( ! empty( $p['is_builtin'] ) ) : ?>
															<span class="tcbvm-badge tcbvm-badge--success">سیستمی</span>
														<?php else : ?>
															<button type="button" class="tisa-btn tisa-btn--danger tisa-btn--sm tc-btn-delete-preset" data-preset-id="<?php echo esc_attr( $p_id ); ?>">حذف</button>
														<?php endif; ?>
													</td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</div>
							</div>
						</section>

						<!-- فرم ساخت الگوی جدید -->
						<section class="tcbvm-card">
							<div class="tcbvm-card-head">
								<span class="tcbvm-step">۲</span>
								<div>
									<h2>تعریف الگوی سفارشی جدید</h2>
									<p>یک لیست اختصاصی از مدل‌ها یا مقادیر بسازید تا همیشه در دسترس باشد.</p>
								</div>
							</div>
							<div class="tcbvm-card-body">
								<form id="tcbvm-form-new-preset">
									<div class="tcbvm-grid-2">
										<div class="tcbvm-field">
											<label class="tcbvm-label" for="preset_name">نام الگو</label>
											<input type="text" id="preset_name" class="tcbvm-input" placeholder="مثلاً: مدل‌های اقتصادی شیائومی">
										</div>
										<div class="tcbvm-field">
											<label class="tcbvm-label" for="preset_desc">توضیحات کوتاه</label>
											<input type="text" id="preset_desc" class="tcbvm-input" placeholder="توضیح اختیاری درباره الگو">
										</div>
									</div>
									<div class="tcbvm-field">
										<label class="tcbvm-label" for="preset_models">لیست مدل‌ها (هر خط یک مدل یا با | جدا کنید)</label>
										<textarea id="preset_models" class="tcbvm-textarea" rows="4" placeholder="iPhone 15&#10;iPhone 15 Pro&#10;iPhone 15 Pro Max"></textarea>
									</div>
									<button type="submit" class="tisa-btn tisa-btn--primary">ذخیره الگوی جدید</button>
								</form>
							</div>
						</section>

					<?php elseif ( 'runs' === $tab ) : ?>
						<!-- تب ۳: تاریخچه و بازگردانی -->
						<section class="tcbvm-card">
							<div class="tcbvm-card-head">
								<span class="tcbvm-step">۱</span>
								<div>
									<h2>تاریخچه عملیات و قابلیت بازگردانی (Rollback Log)</h2>
									<p>قبل از هر عملیات بازسازی، تمام متغیرها و قیمت‌های قبلی ذخیره شده و با یک کلیک قابل بازگردانی کامل به حالت قبل هستند.</p>
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
													<th class="tcbvm-col-w140 tcbvm-center">اقدام</th>
												</tr>
											</thead>
											<tbody>
												<?php foreach ( $runs as $r ) : ?>
													<tr>
														<td><span class="tisa-code"><?php echo esc_html( $r->run_id ); ?></span></td>
														<td><?php echo esc_html( $r->created_at ); ?></td>
														<td><?php echo esc_html( $r->user_login ? $r->user_login : 'سیستم' ); ?></td>
														<td><strong><?php echo esc_html( $r->operation ); ?></strong></td>
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
														<td class="tcbvm-center">
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
										<h2>تنظیمات هسته و پردازش دسته‌ای</h2>
										<p>پیکربندی عملکرد سرور و سرعت پردازش بسته‌ها</p>
									</div>
								</div>
								<div class="tcbvm-card-body">
									<div class="tcbvm-grid-2">
										<div class="tcbvm-field">
											<label class="tcbvm-label" for="tcbvm_opt_batch_size">اندازه هر بسته پردازش (Batch Size)</label>
											<input type="number" id="tcbvm_opt_batch_size" name="tcbvm_settings[batch_size]" class="tcbvm-input" value="<?php echo esc_attr( $settings['batch_size'] ); ?>" min="1" max="50">
											<p class="tcbvm-muted">پیش‌فرض: ۵ محصول در هر بسته. برای محصولات با تعداد متغیرهای بالا، عدد ۲ تا ۵ سرعتی عالی و پایداری کامل بدون قطعی ایجاد می‌کند.</p>
										</div>
										<div class="tcbvm-field">
											<label class="tcbvm-label" for="tcbvm_opt_attr_default">نام پیش‌فرض ویژگی</label>
											<input type="text" id="tcbvm_opt_attr_default" name="tcbvm_settings[target_attr_name]" class="tcbvm-input" value="<?php echo esc_attr( $settings['target_attr_name'] ); ?>">
											<p class="tcbvm-muted">نام صفتی که هنگام باز شدن فرم به صورت پیش‌فرض درج می‌شود (مثل: مدل گوشی).</p>
										</div>
									</div>
									<div class="tcbvm-actions">
										<button type="submit" class="tisa-btn tisa-btn--primary">ذخیره تنظیمات</button>
									</div>
								</div>
							</section>

							<section class="tcbvm-card">
								<div class="tcbvm-card-head">
									<span class="tcbvm-step">۲</span>
									<div>
										<h2>ابزارهای کش و ترنزینت‌های ووکامرس</h2>
										<p>نوسازی جداول قیمت و حافظه موقت ووکامرس در صورت عدم نمایش قیمت جدید در کاتالوگ</p>
									</div>
								</div>
								<div class="tcbvm-card-body">
									<p class="tcbvm-muted">اگر قیمت‌های متغیرها پس از بازسازی در صفحه محصول یا آرشیو فروشگاه بلافاصله بروز نشدند، با دکمه زیر کش ووکامرس را نوسازی کنید:</p>
									<button type="button" class="tisa-btn tisa-btn--soft" id="tcbvm-btn-flush-cache">نوسازی کش قیمت‌های متغیر ووکامرس</button>
								</div>
							</section>
						</form>

					<?php else : ?>
						<!-- تب اصلی: تولید و بازسازی انبوه متغیرها -->
						<div class="tcbvm-lead-box">
							<p class="tcbvm-lead">
								روند کار بسیار ساده است: محصولات هدف را انتخاب کنید، نام ویژگی و مقادیر جدید را بنویسید، قیمت دلخواه را وارد کنید و دکمه بازسازی را بزنید. افزونه تمام ترکیب‌ها را با دقت جنریت کرده و قیمت تعیین‌شده را برای همه اعمال می‌کند.
							</p>
						</div>

						<!-- گام ۱: انتخاب محصولات هدف -->
						<section class="tcbvm-card">
							<div class="tcbvm-card-head">
								<span class="tcbvm-step">۱</span>
								<div>
									<h2>انتخاب محصولات هدف</h2>
									<p>محصولاتی که می‌خواهید متغیرهای آن‌ها از اول با ترکیب و قیمت جدید بازسازی شوند را انتخاب کنید.</p>
								</div>
							</div>
							<div class="tcbvm-card-body">
								<!-- سگمنت انتخاب شیوه -->
								<div class="tcbvm-seg-bar">
									<label class="tcbvm-seg-item is-active">
										<input type="radio" name="tcbvm_target_mode" value="category" checked>
										<span>انتخاب بر اساس دسته‌بندی</span>
									</label>
									<label class="tcbvm-seg-item">
										<input type="radio" name="tcbvm_target_mode" value="direct">
										<span>جستجو و انتخاب مستقیم محصول</span>
									</label>
									<label class="tcbvm-seg-item">
										<input type="radio" name="tcbvm_target_mode" value="manual">
										<span>ورود مستقیم شناسه‌ها (IDs)</span>
									</label>
								</div>

								<!-- باکس حالت ۱: دسته‌بندی -->
								<div id="tcbvm-cat-box" class="tcbvm-tab-pane">
									<div class="tcbvm-field">
										<label class="tcbvm-label" for="tcbvm-cat-select">انتخاب دسته‌بندی‌ها (قاب گوشی، قاب اسپیس، قاب چاپی و...)</label>
										<select id="tcbvm-cat-select" multiple="multiple" class="tcbvm-select wc-enhanced-select" data-placeholder="دسته‌بندی‌ها را انتخاب یا جستجو کنید…" data-allow_clear="true">
											<?php
											foreach ( $categories as $cat ) :
												$cat_id    = is_array( $cat ) ? (int) $cat['id'] : (int) $cat->term_id;
												$cat_name  = is_array( $cat ) ? (string) $cat['name'] : (string) $cat->name;
												$cat_count = is_array( $cat ) ? (int) $cat['count'] : (int) $cat->count;
												?>
												<option value="<?php echo esc_attr( $cat_id ); ?>">
													<?php echo esc_html( $cat_name . ' (' . number_format_i18n( $cat_count ) . ' محصول)' ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</div>

									<div class="tcbvm-row-tight">
										<label class="tisa-switch tcbvm-toggle">
											<input type="checkbox" id="tcbvm-cat-children" checked>
											<span class="tisa-switch__track" aria-hidden="true"></span>
											<span>شامل زیردسته‌ها <span class="tcbvm-muted">— تمامی زیرشاخه‌های دسته‌های منتخب نیز واکشی شوند</span></span>
										</label>
									</div>

									<div class="tcbvm-grid-2">
										<div class="tcbvm-field">
											<label class="tcbvm-label" for="tcbvm-keywords">فیلتر کلمه کلیدی در عنوان (اختیاری)</label>
											<input type="text" id="tcbvm-keywords" class="tcbvm-input" placeholder="مثلاً: اسپیس، چاپی، مگ سیف">
										</div>
										<div class="tcbvm-field">
											<label class="tcbvm-label" for="tcbvm-exclude-keywords">استثنا کردن عنوان‌ها (Exclude)</label>
											<input type="text" id="tcbvm-exclude-keywords" class="tcbvm-input" placeholder="مثلاً: محافظ لنز، شیشه‌ای">
										</div>
									</div>

									<div class="tcbvm-actions">
										<button type="button" class="tisa-btn tisa-btn--soft" id="tcbvm-btn-search">
											<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
											<span>استخراج و افزودن محصولات به لیست</span>
										</button>
										<span id="tcbvm-search-counter" class="tcbvm-counter-text"></span>
									</div>
								</div>

								<!-- باکس حالت ۲: انتخاب مستقیم با جستجوی زنده -->
								<div id="tcbvm-direct-box" class="tcbvm-tab-pane tcbvm-hidden">
									<div class="tcbvm-field">
										<label class="tcbvm-label" for="tcbvm-single-search">جستجوی زنده محصول بر اساس نام، SKU یا شناسه ID:</label>
										<div class="tcbvm-search-inline">
											<input type="text" id="tcbvm-single-search" class="tcbvm-input" placeholder="نام یا بخشی از نام محصول یا SKU را تایپ کنید…">
											<button type="button" class="tisa-btn tisa-btn--soft" id="tcbvm-btn-single-search">جستجو</button>
										</div>
										<div id="tcbvm-single-dropdown" class="tcbvm-autocomplete-dropdown tcbvm-hidden"></div>
									</div>
								</div>

								<!-- باکس حالت ۳: شناسه‌های دستی -->
								<div id="tcbvm-manual-box" class="tcbvm-tab-pane tcbvm-hidden">
									<div class="tcbvm-field">
										<label class="tcbvm-label" for="tcbvm-manual-ids">شناسه‌های محصول (IDs)</label>
										<textarea id="tcbvm-manual-ids" class="tcbvm-textarea" rows="3" placeholder="شناسه‌ها را با کاما یا در خطوط جداگانه وارد کنید (مثال: 1205, 1206, 1432)"></textarea>
									</div>
									<div class="tcbvm-actions">
										<button type="button" class="tisa-btn tisa-btn--soft" id="tcbvm-btn-manual-add">افزودن شناسه‌ها به لیست</button>
									</div>
								</div>

								<!-- جدول زنده محصولات انتخاب‌شده -->
								<div id="tcbvm-products-box" class="tcbvm-products-box tcbvm-hidden">
									<div class="tcbvm-products-header">
										<div class="tcbvm-products-title-group">
											<h4 class="tcbvm-products-title">محصولات انتخاب‌شده جهت اعمال عملیات:</h4>
											<span id="tcbvm-selected-badge" class="tcbvm-badge tcbvm-badge--success">۰ محصول انتخاب‌شده</span>
										</div>
										<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" id="tcbvm-btn-clear-selection">پاک کردن کل لیست</button>
									</div>
									<div class="tcbvm-table-scroll">
										<table class="tisa-table tcbvm-table">
											<thead>
												<tr>
													<th class="tcbvm-col-w38 tcbvm-center"><input type="checkbox" id="tcbvm-select-all" checked></th>
													<th class="tcbvm-col-w48">تصویر</th>
													<th>نام محصول</th>
													<th>SKU / شناسه</th>
													<th>دسته‌بندی</th>
													<th>نوع</th>
													<th>متغیرهای فعلی</th>
													<th class="tcbvm-col-w70 tcbvm-center">حذف</th>
												</tr>
											</thead>
											<tbody id="tcbvm-products-tbody"></tbody>
										</table>
									</div>
								</div>
							</div>
						</section>

						<!-- گام ۲: نام ویژگی و متغیرهای جدید -->
						<section class="tcbvm-card">
							<div class="tcbvm-card-head">
								<span class="tcbvm-step">۲</span>
								<div>
									<h2>مشخصات ویژگی و متغیرهای جدید</h2>
									<p>نام ویژگی مورد نظر را وارد کرده و لیست متغیرهای جدید را تعریف کنید.</p>
								</div>
							</div>
							<div class="tcbvm-card-body">
								<div class="tcbvm-grid-2">
									<div class="tcbvm-field">
										<label class="tcbvm-label" for="tcbvm-attr-name">نام ویژگی مورد نظر (صفت ووکامرس)</label>
										<input type="text" id="tcbvm-attr-name" class="tcbvm-input" list="tcbvm-attr-datalist" value="<?php echo esc_attr( $settings['target_attr_name'] ); ?>" placeholder="مثلاً: مدل گوشی، مدل، سایز، رنگ">
										<datalist id="tcbvm-attr-datalist">
											<option value="مدل گوشی"></option>
											<option value="مدل"></option>
											<option value="رنگ"></option>
											<option value="سایز"></option>
											<?php foreach ( $attributes as $a ) : ?>
												<option value="<?php echo esc_attr( $a['label'] ); ?>"><?php echo esc_html( $a['name'] ); ?></option>
											<?php endforeach; ?>
										</datalist>
										<p class="tcbvm-muted">نام ویژگی را بنویسید؛ اگر ویژگی از قبل روی محصولات باشد مقادیر آن نوسازی می‌شود و در غیر این صورت به عنوان ویژگی متغیر جدید اضافه خواهد شد.</p>
									</div>

									<div class="tcbvm-field">
										<label class="tcbvm-label">حالت ترکیب با سایر ویژگی‌ها</label>
										<div class="tcbvm-switch-card">
											<label class="tisa-switch tcbvm-toggle">
												<input type="checkbox" id="tcbvm-combine-other" checked>
												<span class="tisa-switch__track" aria-hidden="true"></span>
												<span>ترکیب خودکار با سایر ویژگی‌های محصول</span>
											</label>
											<p class="tcbvm-muted">اگر محصول دارای ویژگی‌های متغیر دیگری (مثل رنگ یا جنس) باشد، تمامی ترکیب‌ها به صورت خودکار ضرب و جنریت خواهند شد.</p>
										</div>
									</div>
								</div>

								<!-- باکس تعریف متغیرهای جدید -->
								<div class="tcbvm-field">
									<div class="tcbvm-field-header">
										<label class="tcbvm-label" for="tcbvm-models-input">لیست متغیرهای جدید (هر خط یک متغیر، یا با خط عمودی | جدا کنید):</label>
										<span id="tcbvm-models-count" class="tcbvm-badge tcbvm-badge--info">۰ متغیر تعریف شد</span>
									</div>

									<!-- چیپ‌های الگوهای سریع -->
									<div class="tcbvm-chips-row">
										<span class="tcbvm-chips-title">درج سریع الگوهای آماده:</span>
										<?php foreach ( $presets as $p_id => $preset ) : ?>
											<button type="button" class="tcbvm-chip-btn" data-preset-id="<?php echo esc_attr( $p_id ); ?>">
												<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
												<span><?php echo esc_html( $preset['name'] ); ?></span>
											</button>
										<?php endforeach; ?>
									</div>

									<textarea id="tcbvm-models-input" class="tcbvm-textarea" rows="6" placeholder="iPhone 11&#10;iPhone 12&#10;iPhone 13&#10;iPhone 14 Pro&#10;iPhone 15 Pro Max&#10;iPhone 16 Pro Max&#10;Galaxy S24 Ultra&#10;(یا به صورت: iPhone 11 | iPhone 12 | iPhone 13)"></textarea>
									<p class="tcbvm-muted">کاما داخل نام متغیر حفظ می‌شود (مثلاً <span class="tisa-code">iPhone 7,8,SE</span> یک متغیر حساب می‌شود). برای هر متغیر یک خط یا علامت | بگذارید.</p>
								</div>
							</div>
						</section>

						<!-- گام ۳: قیمت‌گذاری متغیرها -->
						<section class="tcbvm-card">
							<div class="tcbvm-card-head">
								<span class="tcbvm-step">۳</span>
								<div>
									<h2>تعیین قیمت متغیرها</h2>
									<p>مبلغی که در این کادر وارد می‌کنید، برای تمام متغیرهای جدید جنریت‌شده در همه محصولات انتخاب‌شده ثبت خواهد شد.</p>
								</div>
							</div>
							<div class="tcbvm-card-body">
								<div class="tcbvm-grid-3">
									<div class="tcbvm-field">
										<label class="tcbvm-label" for="tcbvm-regular-price">قیمت متغیرها (تومان) <span class="tcbvm-badge tcbvm-badge--warn">الزامی</span></label>
										<input type="text" id="tcbvm-regular-price" class="tcbvm-input tcbvm-price-input" placeholder="مثال: 350000">
										<div id="tcbvm-price-preview" class="tcbvm-price-display"></div>
										<p class="tcbvm-muted">این قیمت روی تمام متغیرهای ساخته‌شده اعمال می‌گردد.</p>
									</div>

									<div class="tcbvm-field">
										<label class="tcbvm-label" for="tcbvm-sale-price">قیمت حراج / فروش ویژه (اختیاری)</label>
										<input type="text" id="tcbvm-sale-price" class="tcbvm-input tcbvm-price-input" placeholder="مثال: 290000 (اختیاری)">
										<div id="tcbvm-sale-preview" class="tcbvm-price-display"></div>
										<p class="tcbvm-muted">در صورت نیاز به اعمال تخفیف روی همه متغیرها.</p>
									</div>

									<div class="tcbvm-field">
										<label class="tcbvm-label" for="tcbvm-stock-status">وضعیت موجودی انبار</label>
										<select id="tcbvm-stock-status" class="tcbvm-select">
											<option value="instock">موجود در انبار (In Stock)</option>
											<option value="outofstock">ناموجود (Out of Stock)</option>
										</select>
										<p class="tcbvm-muted">وضعیت موجودی پیش‌فرض متغیرهای جنریت‌شده.</p>
									</div>
								</div>
							</div>
						</section>

						<!-- گام ۴: بررسی و اجرای تولید انبوه -->
						<section class="tcbvm-card">
							<div class="tcbvm-card-head">
								<span class="tcbvm-step">۴</span>
								<div>
									<h2>بررسی پیش‌نمایش و تولید نهایی متغیرها</h2>
									<p>ابتدا با بررسی آزمایشی تعداد ترکیب‌ها را بازبینی کنید؛ پس از اطمینان با یک کلیک عملیات را اجرا نمایید.</p>
								</div>
							</div>
							<div class="tcbvm-card-body">
								<div class="tcbvm-actions">
									<button type="button" class="tisa-btn tisa-btn--soft tisa-btn--lg" id="tcbvm-btn-preview">
										<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
										<span>بررسی قبل از اجرا (پیش‌نمایش محاسبات)</span>
									</button>
									<button type="button" class="tisa-btn tisa-btn--primary tisa-btn--lg" id="tcbvm-btn-run">
										<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
										<span>تولید و بازسازی متغیرها روی محصولات انتخابی</span>
									</button>
								</div>

								<!-- باکس پیش‌نمایش تغییرات -->
								<div id="tcbvm-preview-output" class="tcbvm-preview-output tcbvm-hidden">
									<div class="tcbvm-preview-card">
										<div class="tcbvm-preview-head">
											<span class="tcbvm-preview-badge">خلاصه بررسی قبل از اجرا</span>
											<p class="tcbvm-preview-sub">آمار تغییراتی که قرار است روی محصولات انتخابی اعمال گردد:</p>
										</div>
										<div id="tcbvm-preview-content" class="tcbvm-preview-body"></div>
									</div>
								</div>

								<!-- نوار پیشرفت و گزارش زنده اجرا -->
								<div id="tcbvm-progress-wrap" class="tcbvm-progress-wrap tcbvm-hidden">
									<div class="tcbvm-progress-header">
										<span id="tcbvm-progress-text" class="tcbvm-progress-text">در حال آماده‌سازی و تهیه اسنپ‌شات…</span>
										<span id="tcbvm-progress-percent" class="tcbvm-progress-percent">0%</span>
									</div>
									<div class="tcbvm-bar-track">
										<div id="tcbvm-bar-fill" class="tcbvm-bar-fill"></div>
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
											<span class="tcbvm-kpi-label">خطا / رد شده</span>
										</div>
									</div>

									<div class="tcbvm-log-box">
										<div class="tcbvm-log-head">
											<div class="tcbvm-log-title-wrap">
												<span class="tcbvm-log-dot"></span>
												<span>گزارش زنده رویدادها (Realtime Execution Log)</span>
											</div>
											<span class="tcbvm-log-status">اتصال فعال</span>
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
	}
}
