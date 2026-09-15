<?php
/**
 * رابط مدیریت (Admin UI) استاندارد هماهنگ با سبک TisaCase Bulk Price Manager:
 * استفاده از استایل‌های بومی وردپرس، nav-tab-wrapper، کارت‌های استاندارد سفید و دکمه‌های رسمی پیشخوان.
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

			if ( $has_tisa_hub ) {
				add_submenu_page(
					$hub_slug,
					'مدیریت گروهی متغیرها و مدل‌ها',
					'مدیریت متغیرها (قاب گوشی)',
					'manage_woocommerce',
					TCBVM_Core::PAGE_SLUG,
					array( __CLASS__, 'render_page' )
				);
			} else {
				add_menu_page(
					'مدیریت متغیرها TisaCase',
					'TisaCase متغیرها',
					'manage_woocommerce',
					TCBVM_Core::PAGE_SLUG,
					array( __CLASS__, 'render_page' ),
					'dashicons-screenoptions',
					57
				);
			}

			// دسترسی در منوی محصولات
			add_submenu_page(
				'edit.php?post_type=product',
				'تنظیمات متغیرهای گروهی',
				'تنظیمات متغیرهای گروهی',
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
		 * رندر صفحه واحد با تب‌های استاندارد وردپرس (مشابه TisaCase Bulk Price Manager).
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
			?>
			<div class="wrap tcbvm-wrap" dir="rtl">
				<h1>مدیریت گروهی متغیرها و مدل‌های قاب گوشی</h1>
				<p class="description">افزودن سری‌های جدید، حذف مدل‌های قدیمی، تغییر قیمت بر اساس مدل مرجع و بازگردانی خودکار (مخصوص قاب‌های اسپیس و چاپی تیساکیس).</p>

				<nav class="nav-tab-wrapper tcbvm-nav">
					<?php foreach ( $tabs as $key => $label ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'tab', $key, $base ) ); ?>" class="nav-tab<?php echo $tab === $key ? ' nav-tab-active' : ''; ?>">
							<?php echo esc_html( $label ); ?>
						</a>
					<?php endforeach; ?>
				</nav>

				<div class="tcbvm-tab-content">
					<?php if ( 'presets' === $tab ) : ?>
						<!-- تب ۲: الگوهای آماده -->
						<div class="tcbvm-card">
							<h2>الگوهای آماده مدل‌های گوشی (Model Presets)</h2>
							<p>الگوهای ذخیره‌شده مدل‌ها را می‌توانید با یک کلیک در بخش عملیات گروهی فراخوانی کنید.</p>

							<table class="wp-list-table widefat fixed striped" style="margin-top: 14px;">
								<thead>
									<tr>
										<th style="width: 220px;">نام الگو</th>
										<th>توضیحات</th>
										<th>نمونه مدل‌ها</th>
										<th style="width: 100px;">نوع / اقدام</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $presets as $p_id => $preset ) : ?>
										<tr>
											<td><strong><?php echo esc_html( $preset['name'] ); ?></strong></td>
											<td><?php echo esc_html( $preset['description'] ); ?></td>
											<td>
												<?php foreach ( array_slice( $preset['models'], 0, 8 ) as $m ) : ?>
													<span class="tcbvm-tag-model"><?php echo esc_html( $m ); ?></span>
												<?php endforeach; ?>
												<?php if ( count( $preset['models'] ) > 8 ) : ?>
													<span class="tcbvm-muted">+ <?php echo count( $preset['models'] ) - 8; ?> مدل دیگر</span>
												<?php endif; ?>
											</td>
											<td>
												<?php if ( ! empty( $preset['is_builtin'] ) ) : ?>
													<span class="tcbvm-status-badge tcbvm-st-done">سیستمی</span>
												<?php else : ?>
													<button type="button" class="button button-small tc-btn-delete-preset" data-id="<?php echo esc_attr( $p_id ); ?>">حذف</button>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>

							<div style="margin-top: 24px; border-top: 1px solid #dcdcde; padding-top: 18px;">
								<h3>افزودن الگوی سفارشی جدید</h3>
								<div class="tcbvm-field">
									<label for="tcbvm-new-preset-name">عنوان الگو:</label>
									<input type="text" id="tcbvm-new-preset-name" class="regular-text" placeholder="مثال: سری کامل آیفون‌های پاییز ۱۴۰۳">
								</div>
								<div class="tcbvm-field">
									<label for="tcbvm-new-preset-desc">توضیح کوتاه:</label>
									<input type="text" id="tcbvm-new-preset-desc" class="regular-text" placeholder="مثال: مخصوص قاب‌های اسپیس و چاپی">
								</div>
								<div class="tcbvm-field">
									<label for="tcbvm-new-preset-models">لیست مدل‌های گوشی داخل این الگو:</label>
									<textarea id="tcbvm-new-preset-models" rows="4" style="width:100%; max-width:600px;" placeholder="مدل‌ها را با ویرگول یا در خطوط جداگانه وارد کنید..."></textarea>
								</div>
								<p>
									<button type="button" class="button button-primary" id="tcbvm-btn-save-preset">ذخیره الگو</button>
								</p>
							</div>
						</div>

					<?php elseif ( 'runs' === $tab ) : ?>
						<!-- تب ۳: گزارش و بازگردانی -->
						<div class="tcbvm-card">
							<h2>گزارش عملیات و بازگردانی (Rollback)</h2>
							<p>قبل از هر تغییر انبوه، یک اسنپ‌شات کامل از ویژگی‌ها و متغیرها ذخیره می‌شود و با یک کلیک امکان بازگردانی محصولات به حالت اولیه وجود دارد.</p>

							<?php if ( empty( $runs ) ) : ?>
								<p class="tcbvm-muted">هنوز هیچ عملیاتی اجرا نشده است.</p>
							<?php else : ?>
								<table class="wp-list-table widefat fixed striped" style="margin-top: 14px;">
									<thead>
										<tr>
											<th style="width: 170px;">شناسه نشست</th>
											<th>نوع عملیات</th>
											<th style="width: 150px;">تاریخ و ساعت</th>
											<th style="width: 110px;">تعداد محصولات</th>
											<th style="width: 110px;">وضعیت</th>
											<th style="width: 120px;">اقدام</th>
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
														<span class="tcbvm-status-badge tcbvm-st-done">انجام‌شده</span>
													<?php elseif ( 'rolled_back' === $run['status'] ) : ?>
														<span class="tcbvm-status-badge tcbvm-st-rolled_back">بازگردانده‌شده</span>
													<?php else : ?>
														<span class="tcbvm-status-badge tcbvm-st-failed"><?php echo esc_html( $run['status'] ); ?></span>
													<?php endif; ?>
												</td>
												<td>
													<?php if ( 'rolled_back' !== $run['status'] ) : ?>
														<button type="button" class="button button-secondary tc-btn-rollback" data-run-id="<?php echo esc_attr( $r_id ); ?>">بازگردانی</button>
													<?php else : ?>
														<span class="tcbvm-muted">—</span>
													<?php endif; ?>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							<?php endif; ?>
						</div>

					<?php elseif ( 'settings' === $tab ) : ?>
						<!-- تب ۴: تنظیمات -->
						<div class="tcbvm-card">
							<h2>تنظیمات کارایی و بهینه‌سازی</h2>
							<div class="tcbvm-field">
								<label for="tcbvm-setting-batch-size">اندازه بسته‌های پردازش (Batch Size):</label>
								<input type="number" id="tcbvm-setting-batch-size" class="small-text" value="<?php echo esc_attr( $settings['batch_size'] ); ?>" min="1" max="50">
								<p class="description">تعداد محصولاتی که در هر ثانیه/درخواست ایجکس پردازش می‌شوند (پیشنهاد: ۵ تا ۱۰).</p>
							</div>

							<div class="tcbvm-field" style="margin-top: 24px;">
								<label>پاکسازی کش قیمت‌ها و ترنزینت‌های ووکامرس:</label>
								<p class="description">اگر پس از تغییرات، قیمت‌ها در کاتالوگ فروشگاه موقتاً تغییر نکردند، از این دکمه استفاده کنید.</p>
								<button type="button" class="button button-secondary" id="tcbvm-btn-flush-cache">نوسازی کش قیمت‌های ووکامرس</button>
							</div>
						</div>

					<?php else : ?>
						<!-- تب ۱: تغییر گروهی متغیرها (صفحه اصلی) -->
						<p>هدف را انتخاب کن؛ ابتدا «بررسی قبل از اجرا» را بزن تا نمونهٔ قبل/بعد را ببینی. اجرا فقط پس از تأیید فعال می‌شود و همهٔ تغییرات برای بازگردانی ثبت می‌گردد.</p>

						<!-- ۱) انتخاب محصولات -->
						<div class="tcbvm-card">
							<h2>۱) انتخاب محصولات هدف</h2>
							<p>
								<label class="tcbvm-radio"><input type="radio" name="tcbvm_target_mode" value="category" checked> دسته‌بندی</label>
								<label class="tcbvm-radio"><input type="radio" name="tcbvm_target_mode" value="manual"> شناسه‌های مستقیم</label>
							</p>

							<div id="tcbvm-cat-box">
								<p><strong>یک یا چند دسته‌بندی:</strong></p>
								<select id="tcbvm-cat-select" multiple="multiple" style="width:100%;max-width:860px;" size="4">
									<?php foreach ( $categories as $cat ) : ?>
										<option value="<?php echo esc_attr( $cat['id'] ); ?>">
											<?php echo esc_html( $cat['name'] ); ?> (<?php echo esc_html( $cat['count'] ); ?> محصول)
										</option>
									<?php endforeach; ?>
								</select>
								<p>
									<label><input type="checkbox" id="tcbvm-cat-children" checked> زیردسته‌ها هم شامل شوند</label>
								</p>
								<div class="tcbvm-row" style="margin-top: 10px;">
									<div style="flex:1;">
										<label>کلمات کلیدی در عنوان (مثبت):</label>
										<input type="text" id="tcbvm-keywords" class="regular-text" style="width:100%;" placeholder="مثال: قاب، اسپیس، چاپی (با ویرگول جدا کنید)">
									</div>
									<div style="flex:1;">
										<label>کلمات منفی و استثنا (منفی):</label>
										<input type="text" id="tcbvm-exclude-keywords" class="regular-text" style="width:100%;" placeholder="مثال: تبلت، ایرپاد، ساعت">
									</div>
								</div>
							</div>

							<div id="tcbvm-manual-box" style="display:none; margin-top: 10px;">
								<label>شناسه‌های مشخص محصول (با ویرگول جدا کنید):</label>
								<input type="text" id="tcbvm-manual-ids" class="large-text" placeholder="مثال: 1205, 1208, 1450">
								<div style="margin-top: 10px;">
									<label>فقط محصولاتی که این مدل را دارند:</label>
									<input type="text" id="tcbvm-model-filter" class="regular-text" placeholder="مثال: iPhone 11">
								</div>
							</div>

							<div class="tcbvm-actions">
								<button type="button" class="button button-primary" id="tcbvm-btn-search">جستجو و بررسی محصولات منطبق</button>
								<span id="tcbvm-search-counter" class="tcbvm-muted" style="margin-right: 10px;">هنوز جستجویی انجام نشده است.</span>
							</div>

							<!-- جدول زنده نتایج -->
							<div id="tcbvm-products-box" style="display:none; margin-top: 16px;">
								<p>
									<label><input type="checkbox" id="tcbvm-select-all" checked> <strong>انتخاب همه موارد این لیست</strong></label>
									<span id="tcbvm-selected-badge" class="tcbvm-muted" style="margin-right: 12px;">۰ محصول انتخاب‌شده</span>
								</p>
								<table class="wp-list-table widefat fixed striped">
									<thead>
										<tr>
											<th style="width: 38px;">انتخاب</th>
											<th style="width: 44px;">تصویر</th>
											<th>نام محصول</th>
											<th>شناسه / SKU</th>
											<th>دسته‌بندی</th>
											<th>متغیرها</th>
											<th>نمونه مدل‌ها</th>
											<th style="width: 60px;">اقدام</th>
										</tr>
									</thead>
									<tbody id="tcbvm-products-tbody"></tbody>
								</table>
							</div>
						</div>

						<!-- ۲) نوع تغییر متغیرها -->
						<div class="tcbvm-card">
							<h2>۲) نوع عملیات متغیرها</h2>
							<select id="tcbvm-op" style="min-width:460px; max-width:100%;">
								<option value="add_models" selected>افزودن مدل‌های جدید به محصولات</option>
								<option value="remove_models">حذف یا ناموجود کردن مدل‌های قدیمی</option>
								<option value="sync_preset">همگام‌سازی کامل با الگو (حذف موارد نامربوط + افزودن جدیدها)</option>
								<option value="replace_model">جایگزینی یا تغییر نام یک مدل</option>
								<option value="bulk_price_stock">تغییر قیمت یا موجودی برای مدل‌های خاص</option>
							</select>

							<div class="tcbvm-field" style="max-width: 400px;">
								<label for="tcbvm-attr-name">نام ویژگی متغیر در ووکامرس:</label>
								<input type="text" id="tcbvm-attr-name" class="regular-text" value="مدل گوشی">
							</div>

							<!-- باکس مدل‌ها -->
							<div id="tcbvm-models-input-wrap" class="tcbvm-field">
								<div class="tcbvm-quick-chips">
									<span class="tcbvm-muted">درج سریع از الگوها:</span>
									<?php foreach ( $presets as $p ) : ?>
										<button type="button" class="tcbvm-chip tcbvm-chip-btn" data-preset-id="<?php echo esc_attr( $p['id'] ); ?>">
											+ <?php echo esc_html( $p['name'] ); ?>
										</button>
									<?php endforeach; ?>
								</div>
								<label for="tcbvm-models-input">لیست مدل‌های گوشی (هر مدل در یک خط یا با ویرگول جدا کنید):</label>
								<textarea id="tcbvm-models-input" rows="4" style="width:100%; max-width:860px;" placeholder="iPhone 16 Pro Max&#10;iPhone 16 Pro&#10;iPhone 16 Plus&#10;iPhone 16"></textarea>
							</div>

							<!-- باکس جایگزینی -->
							<div id="tcbvm-replace-input-wrap" class="tcbvm-field" style="display:none;">
								<div class="tcbvm-row">
									<div>
										<label for="tcbvm-old-model">مدل فعلی قدیمی:</label>
										<input type="text" id="tcbvm-old-model" class="regular-text" placeholder="مثال: iPhone 11 Pro">
									</div>
									<div>
										<label for="tcbvm-new-model">نام مدل جدید جایگزین:</label>
										<input type="text" id="tcbvm-new-model" class="regular-text" placeholder="مثال: آیفون ۱۱ پرو">
									</div>
								</div>
							</div>

							<!-- تنظیمات قیمت و انبار متغیرهای جدید -->
							<div id="tcbvm-pricing-options-wrap" class="tcbvm-safe-box" style="margin-top: 16px;">
								<p>
									<label><input type="checkbox" id="tcbvm-clone-price-check" checked> <strong>کپی هوشمند قیمت از یک مدل مرجع (توصیه‌شده برای قاب گوشی)</strong></label>
								</p>
								<div id="tcbvm-clone-ref-wrap" style="margin: 8px 24px 0 0;">
									<label>نام مدل مرجع:</label>
									<input type="text" id="tcbvm-clone-ref-model" class="regular-text" placeholder="مثلاً: iPhone 15 Pro Max" value="iPhone 15 Pro Max">
									<span class="tcbvm-muted"> — قیمت متغیر جدید از قیمت این مدل در همان محصول کپی می‌شود.</span>
								</div>

								<div class="tcbvm-row" style="margin-top: 14px;">
									<div>
										<label>قیمت عادی پیش‌فرض (تومان):</label>
										<input type="number" id="tcbvm-regular-price" class="regular-text" placeholder="مثال: 290000">
									</div>
									<div>
										<label>قیمت فروش ویژه (اختیاری):</label>
										<input type="number" id="tcbvm-sale-price" class="regular-text" placeholder="مثال: 250000">
									</div>
									<div>
										<label>وضعیت انبار:</label>
										<select id="tcbvm-stock-status">
											<option value="instock" selected>موجود در انبار</option>
											<option value="outofstock">ناموجود</option>
										</select>
									</div>
								</div>
							</div>

							<!-- حالت حذف -->
							<div id="tcbvm-delete-mode-wrap" class="tcbvm-warn" style="display:none; margin-top: 14px;">
								<label><strong>حالت حذف مدل‌های قدیمی:</strong></label>
								<p>
									<label class="tcbvm-radio"><input type="radio" name="tcbvm_delete_mode" value="soft" checked> حالت ایمن (ناموجود و مخفی کردن برای حفظ سئو و سفارش‌ها)</label>
									<label class="tcbvm-radio"><input type="radio" name="tcbvm_delete_mode" value="hard"> حذف کامل و دائمی از دیتابیس</label>
								</p>
							</div>
						</div>

						<!-- ۳) پیش‌نمایش و اجرا -->
						<div class="tcbvm-card">
							<h2>۳) بررسی قبل از اجرا و شروع عملیات</h2>
							<div class="tcbvm-actions">
								<button type="button" class="button button-secondary" id="tcbvm-btn-preview">بررسی قبل از اجرا (پیش‌نمایش)</button>
								<button type="button" class="button button-primary button-hero" id="tcbvm-btn-run" style="margin-right: 8px;">شروع اعمال تغییرات</button>
							</div>

							<!-- پیش‌نمایش -->
							<div id="tcbvm-preview-output" style="display:none; margin-top: 16px;">
								<div id="tcbvm-preview-box">
									<h3>نمونه تغییرات روی محصولات:</h3>
									<div id="tcbvm-preview-content"></div>
								</div>
							</div>

							<!-- پروگرس‌بار -->
							<div id="tcbvm-progress-wrap" style="display:none; margin-top: 18px;">
								<div class="tcbvm-progressbar-line">
									<span id="tcbvm-progress-text" class="tcbvm-status">در حال پردازش…</span>
									<span id="tcbvm-progress-percent" style="font-weight:700;">0%</span>
								</div>
								<div class="tcbvm-bar">
									<div id="tcbvm-bar-fill"></div>
								</div>
								<div class="tcbvm-counters">
									کل محصولات: <strong id="tcbvm-stat-total">0</strong> |
									پردازش‌شده: <strong id="tcbvm-stat-processed">0</strong> |
									موفق: <strong id="tcbvm-stat-success" style="color:#008a20;">0</strong> |
									ناموفق: <strong id="tcbvm-stat-failed" style="color:#b32d2e;">0</strong>
								</div>
								<div id="tcbvm-log-console" class="tcbvm-console-log"></div>
							</div>
						</div>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}
	}
}
