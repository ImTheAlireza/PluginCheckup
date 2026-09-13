<?php
/**
 * * رابط پیشخوان: منوی واحد با سه تب (تغییر گروهی قیمت/گزارش و بازگردانی/تنظیمات) و asset ها.
 *
 * @package TisaCase_Bulk_Price_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCBPM_Admin' ) ) {

	final class TCBPM_Admin {

		public static function menus() {
			if ( ! TCBPM_Core::can() ) {
				return;
			}
			add_submenu_page(
				'edit.php?post_type=product',
				'تنظیمات قیمت گروهی',
				'تنظیمات قیمت گروهی',
				TCBPM_Core::setting( 'min_capability' ),
				TCBPM_Core::MAIN_PAGE,
				array( __CLASS__, 'admin_page' )
			);
		}

		/** تب فعال صفحهٔ واحد: bulk | runs | settings */
		public static function current_tab() {
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'bulk'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! in_array( $tab, array( 'bulk', 'runs', 'settings' ), true ) ) {
				$tab = 'bulk';
			}
			return $tab;
		}

		/** صفحهٔ واحد با سه تب. */
		public static function admin_page() {
			if ( ! TCBPM_Core::can() ) {
				wp_die( 'دسترسی غیرمجاز است.' );
			}
			$tab  = self::current_tab();
			$base = admin_url( 'admin.php?page=' . TCBPM_Core::MAIN_PAGE );
			$tabs = array(
				'bulk'     => 'تغییر گروهی قیمت',
				'runs'     => 'گزارش و بازگردانی',
				'settings' => 'تنظیمات',
			);
			?>
			<div class="wrap" dir="rtl">
				<h1>تنظیمات قیمت گروهی</h1>
				<nav class="nav-tab-wrapper tcbpm-nav">
					<?php foreach ( $tabs as $key => $label ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'tab', $key, $base ) ); ?>" class="nav-tab<?php echo $tab === $key ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</nav>
				<div class="tcbpm-tab-content">
					<?php
					if ( 'runs' === $tab ) {
						self::page_runs();
					} elseif ( 'settings' === $tab ) {
						self::page_settings();
					} else {
						self::page_bulk();
					}
					?>
				</div>
			</div>
			<?php
		}

		public static function action_links( $links ) {
			$url = admin_url( 'admin.php?page=' . TCBPM_Core::MAIN_PAGE );
			array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . __( 'مدیریت قیمت', 'tisacase-bulk-price-manager' ) . '</a>' );
			return $links;
		}

		public static function notices() {
			if ( ! TCBPM_Core::wc_active() ) {
				echo '<div class="notice notice-error"><p>افزونهٔ «TisaCase Bulk Price Manager» نیازمند ووکامرس فعال است.</p></div>';
			}
		}

		/* -----------------------------------------------------------------
		 * assets
		 * --------------------------------------------------------------- */

		public static function assets() {
			if ( empty( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
			}
			$page = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( TCBPM_Core::MAIN_PAGE !== $page ) {
				return;
			}
			$tab = self::current_tab();

			wp_enqueue_style( 'tcbpm-admin', TCBPM_URL . 'assets/admin.css', wp_style_is( 'tisacase-ui', 'registered' ) ? array( 'tisacase-ui' ) : array(), TCBPM_VERSION );

			if ( 'bulk' === $tab && TCBPM_Core::wc_active() ) {
				wp_enqueue_style( 'woocommerce_admin_styles' );
				wp_enqueue_script( 'wc-enhanced-select' );
			}

			wp_enqueue_script( 'tcbpm-admin', TCBPM_URL . 'assets/admin.js', array( 'jquery' ), TCBPM_VERSION, true );
			wp_localize_script( 'tcbpm-admin', 'TCBPM_DATA', self::localized( $tab ) );
		}

		private static function localized( $tab ) {
			$ops = array();
			foreach ( TCBPM_OPS::ops() as $slug => $meta ) {
				$ops[ $slug ] = array(
					'label' => $meta[0],
					'kind'  => $meta[1],
					'group' => $meta[2],
					'cap100' => in_array( $slug, array( 'regular_decrease_percent', 'sale_discount_percent', 'wholesale_decrease_percent', 'wholesale_from_retail_percent' ), true ),
				);
			}
			$settings = TCBPM_Core::get_settings();

			return array(
				'page'      => TCBPM_Core::MAIN_PAGE,
				'tab'       => $tab,
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( TCBPM_Core::NONCE ),
				'currency'  => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '',
				'actions'   => array(
					'preview'    => TCBPM_Core::AJAX_PREVIEW,
					'run'        => TCBPM_Core::AJAX_RUN,
					'finish'     => TCBPM_Core::AJAX_FINISH,
					'rollback_start' => TCBPM_Core::AJAX_ROLLBACK_START,
					'rollback_page'  => TCBPM_Core::AJAX_ROLLBACK_PAGE,
					'export'     => TCBPM_Core::AJAX_EXPORT,
					'cancel'     => TCBPM_Core::AJAX_CANCEL,
					'search'     => TCBPM_Core::AJAX_SEARCH,
				),
				'ops'       => $ops,
				'filters'   => array(
					'types' => array(
						'simple'   => 'ساده',
						'variable' => 'متغیر',
						'grouped'  => 'گروهی',
						'external' => 'خارجی',
					),
					'statuses' => array(
						'publish' => 'انتشار یافته',
						'private' => 'خصوصی',
						'draft'   => 'پیش‌نویس',
						'pending' => 'در انتظار بررسی',
						'future'  => 'زمان‌بندی‌شده',
					),
				),
				'limits'    => array(
					'threshold' => TCBPM_Core::confirm_threshold(),
					'batch'     => TCBPM_Core::batch_size(),
					'sample'    => TCBPM_Core::sample_size(),
					'maxAmount' => TCBPM_Core::max_amount(),
					'percentMax'=> TCBPM_Core::PERCENT_CEIL,
					'logging'   => (int) TCBPM_Core::logging_enabled(),
					'scheduledEnabled' => (int) ! empty( TCBPM_Core::setting( 'scheduled' ) ),
					'rollbackEnabled'  => (int) TCBPM_Core::rollback_enabled(),
				),
			);
		}

		/* -----------------------------------------------------------------
		 * صفحهٔ اصلی
		 * --------------------------------------------------------------- */

		public static function page_bulk() {
			if ( ! TCBPM_Core::can() ) {
				wp_die( 'دسترسی غیرمجاز است.' );
			}
			if ( ! TCBPM_Core::wc_active() ) {
				echo '<div class="notice notice-error"><p>ووکامرس باید فعال باشد.</p></div>';
				return;
			}

			$cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
			if ( is_wp_error( $cats ) ) {
				$cats = array();
			}
			?>
			<p>هدف را انتخاب کن؛ ابتدا «بررسی قبل از اجرا» را بزن تا نمونهٔ قبل/بعد و شمارش دقیق را ببینی. اجرا فقط پس از پیش‌نمایشِ تأییدشده فعال می‌شود و همهٔ تغییرات برای بازگردانی ثبت می‌گردد.</p>

			<div id="tcbpm-busy-note" class="notice notice-warning" style="display:none"></div>

				<div class="tcbpm-card">
					<h2>۱) انتخاب محصولات هدف</h2>
					<p>
						<label class="tcbpm-radio"><input type="radio" name="tcbpm_target" value="category" checked> دسته‌بندی</label>
						<label class="tcbpm-radio"><input type="radio" name="tcbpm_target" value="products"> انتخاب مستقیم محصول</label>
					</p>

					<div id="tcbpm-cat-box">
						<p><strong>یک یا چند دسته‌بندی</strong></p>
						<select id="tcbpm-cats" class="wc-enhanced-select" multiple="multiple" data-tcbpm-w="wide" data-placeholder="دسته‌بندی را انتخاب کن...">
							<?php foreach ( $cats as $cat ) : ?>
								<option value="<?php echo esc_attr( $cat->term_id ); ?>"><?php echo esc_html( self::cat_label( $cat ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<p>
							<label><input type="checkbox" id="tcbpm-children"> زیردسته‌ها هم شامل شوند</label>
							<span class="tcbpm-muted"> — برای امنیت، پیش‌فرض خاموش است</span>
						</p>
						<div id="tcbpm-children-warning" class="tcbpm-warn" style="display:none">
							<strong>هشدار:</strong> با روشن‌کردن این گزینه، محصولات تمام زیردسته‌های دسته‌های انتخاب‌شده هم وارد عملیات می‌شوند.
						</div>
					</div>

					<div id="tcbpm-product-box" style="display:none">
						<div id="tcbpm-retail-product-search">
							<p><strong>محصولات</strong></p>
							<select id="tcbpm-products" class="wc-product-search" multiple="multiple" data-tcbpm-w="wide" data-placeholder="نام، شناسه یا SKU محصول را جستجو کن..." data-action="woocommerce_json_search_products"></select>
						</div>
						<div id="tcbpm-wholesale-product-search" style="display:none">
							<p><strong>محصولات دارای قیمت عمده</strong></p>
							<select id="tcbpm-wholesale-products" class="wc-product-search" multiple="multiple" data-tcbpm-w="wide" data-placeholder="فقط بین محصولات دارای قیمت عمده جستجو کن..." data-action="<?php echo esc_attr( TCBPM_Core::AJAX_SEARCH ); ?>"></select>
							<p class="description">در این جستجو فقط محصولاتی نمایش داده می‌شوند که خود محصول یا حداقل یکی از وریشن‌هایش قیمت عمده داشته باشد.</p>
						</div>
					</div>
				</div>

				<div class="tcbpm-card">
					<h2>۲) نوع تغییر قیمت</h2>
					<select id="tcbpm-op" class="tcbpm-select-op">
						<optgroup label="قیمت عادی و فروش ویژه">
							<option value="regular_increase_percent">افزایش قیمت عادی به صورت درصدی</option>
							<option value="regular_decrease_percent">کاهش قیمت عادی به صورت درصدی</option>
							<option value="regular_increase_fixed">افزایش قیمت عادی به مبلغ ثابت</option>
							<option value="regular_decrease_fixed">کاهش قیمت عادی به مبلغ ثابت</option>
							<option value="regular_set">تعیین قیمت عادی دقیق</option>
							<option value="sale_set">تعیین قیمت فروش ویژه دقیق</option>
							<option value="sale_discount_percent">تعیین فروش ویژه با درصد تخفیف از قیمت عادی</option>
							<option value="sale_remove">حذف قیمت فروش ویژه</option>
						</optgroup>
						<optgroup label="قیمت عمده">
							<option value="wholesale_increase_percent">افزایش قیمت عمده به صورت درصدی</option>
							<option value="wholesale_decrease_percent">کاهش قیمت عمده به صورت درصدی</option>
							<option value="wholesale_increase_fixed">افزایش قیمت عمده به مبلغ ثابت</option>
							<option value="wholesale_decrease_fixed">کاهش قیمت عمده به مبلغ ثابت</option>
							<option value="wholesale_set">تعیین قیمت عمده دقیق</option>
							<option value="wholesale_from_retail_percent">تعیین قیمت عمده با درصد کمتر از قیمت فروش فعلی</option>
							<option value="wholesale_clear">حذف قیمت عمده</option>
						</optgroup>
					</select>

					<div id="tcbpm-value-box" class="tcbpm-field">
						<label><strong id="tcbpm-value-label">مقدار درصد</strong></label>
						<div class="tcbpm-row">
							<input type="text" id="tcbpm-value" inputmode="decimal" autocomplete="off" placeholder="مثلاً 10" class="tcbpm-value">
							<span id="tcbpm-unit" class="tcbpm-unit">%</span>
						</div>
					</div>

					<div class="tcbpm-hint">
						مبلغ ثابت را با همان واحد قیمت فروشگاه وارد کن<?php echo function_exists( 'get_woocommerce_currency_symbol' ) ? ' (' . esc_html( get_woocommerce_currency_symbol() ) . ')' : ''; ?>.
						اگر قیمت فروش ویژهٔ جدید مساوی یا بیشتر از قیمت عادی باشد، آن مورد ذخیره نمی‌شود.<br>
						در عملیات قیمت عمده فقط محصول/وری‌شنی که از قبل قیمت عمده دارد تغییر می‌کند.
					</div>
				</div>

				<div class="tcbpm-card">
					<h2>۳) فیلترهای تکمیلی (اختیاری)</h2>
					<div class="tcbpm-grid">
						<div>
							<label><strong>نوع محصول مادر</strong></label>
							<select id="tcbpm-filter-types" multiple="multiple" data-tcbpm-w="full">
								<option value="simple">ساده</option>
								<option value="variable">متغیر</option>
								<option value="grouped">گروهی</option>
								<option value="external">خارجی</option>
							</select>
						</div>
						<div>
							<label><strong>وضعیت انتشار</strong></label>
							<select id="tcbpm-filter-statuses" multiple="multiple" data-tcbpm-w="full">
								<option value="publish" selected>انتشار یافته</option>
								<option value="private" selected>خصوصی</option>
								<option value="draft" selected>پیش‌نویس</option>
								<option value="pending" selected>در انتظار بررسی</option>
								<option value="future">زمان‌بندی‌شده</option>
							</select>
						</div>
						<div>
							<label><strong>قیمت عادی فعلی از</strong></label>
							<input type="text" id="tcbpm-price-min" inputmode="decimal" class="tcbpm-price-input" placeholder="مثلاً 100000">
						</div>
						<div>
							<label><strong>تا</strong></label>
							<input type="text" id="tcbpm-price-max" inputmode="decimal" class="tcbpm-price-input" placeholder="مثلاً 5000000">
						</div>
					</div>
					<p id="tcbpm-sale-only-row" style="display:none">
						<label><input type="checkbox" id="tcbpm-filter-only-sale"> فقط محصولاتی که اکنون فروش ویژه دارند</label>
					</p>
					<p id="tcbpm-wholesale-only-row" style="display:none">
						<label><input type="checkbox" id="tcbpm-filter-only-wholesale"> فقط محصولاتی که اکنون قیمت عمده دارند</label>
					</p>
					<p class="tcbpm-muted">محدودهٔ قیمت و فیلترهای بالا روی محصولات مادر اعمال می‌شوند؛ یک محصول اگر حداقل یکی از آیتم‌هایش (وری‌شن‌ها) در محدوده باشد وارد می‌شود.</p>
				</div>

				<div class="tcbpm-card">
					<h2>۴) بررسی و اجرا</h2>
					<div class="tcbpm-safe-box">
						<strong>مرحلهٔ امنیتی اجباری:</strong>
						ابتدا «بررسی قبل از اجرا» را بزن. افزونه تعداد دقیق محصولات مادر و اشیاء قیمتِ واجد شرایط را حساب می‌کند و نمونهٔ واقعی «قبل → بعد» نمایش می‌دهد. تا وقتی پیش‌نمایش تأیید نشود، اجرا فعال نمی‌شود.
					</div>

					<div class="tcbpm-actions">
						<button type="button" class="button button-secondary button-large" id="tcbpm-preview">بررسی قبل از اجرا</button>
						<button type="button" class="button button-primary button-large" id="tcbpm-start" disabled>اجرای نهایی تغییر قیمت</button>
						<button type="button" class="button button-large" id="tcbpm-schedule" disabled title="اجرا به صف WP-Cron اضافه می‌شود">ثبت در صف زمان‌بندی</button>
						<button type="button" class="button button-large" id="tcbpm-stop" style="display:none">توقف بعد از این مرحله</button>
					</div>

					<div id="tcbpm-preview-box" style="display:none">
						<h3>نتیجهٔ بررسی قبل از اجرا</h3>
						<div id="tcbpm-preview-summary"></div>
						<div id="tcbpm-sample-box">
							<h3>نمونهٔ واقعی «قبل → بعد»</h3>
							<table class="widefat striped tcbpm-sample-table">
								<thead>
									<tr>
										<th>#</th>
										<th>نام محصول</th>
										<th>نوع</th>
										<th>قیمت فعلی</th>
										<th>قیمت جدید</th>
										<th>وضعیت</th>
									</tr>
								</thead>
								<tbody></tbody>
							</table>
						</div>
					</div>

					<div id="tcbpm-progress" style="display:none">
						<div class="tcbpm-bar"><div id="tcbpm-bar"></div></div>
						<p id="tcbpm-status" class="tcbpm-status"></p>
						<p class="tcbpm-counters">
							محصول مادر بررسی‌شده: <strong id="tcbpm-parent-count">0</strong> |
							قیمت تغییرکرده: <strong id="tcbpm-updated-count">0</strong> |
							ردشده/بدون قیمت: <strong id="tcbpm-skipped-count">0</strong> |
							خطا: <strong id="tcbpm-error-count">0</strong>
						</p>
						<div id="tcbpm-errors" class="tcbpm-errors" style="display:none"></div>
					</div>
				</div>

				<div id="tcbpm-dialog" class="tcbpm-dialog-backdrop" style="display:none">
					<div class="tcbpm-dialog">
						<h3 id="tcbpm-dialog-title"></h3>
						<div id="tcbpm-dialog-body"></div>
						<div class="tcbpm-dialog-actions">
							<button type="button" class="button button-primary" id="tcbpm-dialog-ok">تأیید</button>
							<button type="button" class="button" id="tcbpm-dialog-cancel">انصراف</button>
						</div>
					</div>
				</div>
			<?php
		}

		private static function cat_label( $term ) {
			$parts = array( $term->name );
			$parent = absint( $term->parent );
			$guard = 0;
			while ( $parent && $guard < 10 ) {
				$p = get_term( $parent, 'product_cat' );
				if ( ! $p || is_wp_error( $p ) ) {
					break;
				}
				array_unshift( $parts, $p->name );
				$parent = absint( $p->parent );
				$guard++;
			}
			return implode( ' ← ', $parts );
		}

		/* -----------------------------------------------------------------
		 * صفحهٔ گزارش اجراها
		 * --------------------------------------------------------------- */

		public static function page_runs() {
			if ( ! TCBPM_Core::can() ) {
				wp_die( 'دسترسی غیرمجاز است.' );
			}
			// اجراهای running که بیش از حدِ قفل به‌روز نشده‌اند را interrupted کن تا دکمهٔ ادامه درست دیده شود.
			TCBPM_DB::busy_slot( 0 );

			$rows = TCBPM_DB::list_runs( 100 );
			$show_rollback = TCBPM_Core::rollback_enabled();
			$uid  = get_current_user_id();
			?>
			<p class="tcbpm-muted">همهٔ تغییرات قیمت ثبت می‌شوند و تا زمان «نگهداری لاگ» می‌توان آن اجرا را بازگردانی کرد یا خروجی CSV گرفت.</p>

			<?php if ( empty( $rows ) ) : ?>
					<div class="notice notice-info"><p>هنوز اجرایی ثبت نشده است.</p></div>
				<?php else : ?>
					<table class="widefat striped tcbpm-runs-table">
						<thead>
							<tr>
								<th>#</th>
								<th>نوع</th>
								<th>عملیات</th>
								<th>پیشرفت</th>
								<th>آمار</th>
								<th>وضعیت</th>
								<th>کاربر</th>
								<th>تاریخ</th>
								<th>اقدامات</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $r ) : ?>
								<?php
								$row_id   = (int) $r['id'];
								$type     = sanitize_key( $r['type'] );
								$status   = sanitize_key( $r['status'] );
								$can_rollback = $show_rollback && 'rollback' !== $type && in_array( $status, array( 'done', 'stopped' ), true );
								$can_resume   = in_array( $status, array( 'interrupted' ), true ) && (int) $r['user_id'] === $uid;
								$can_stop     = in_array( $status, array( 'running' ), true ) && (int) $r['user_id'] === $uid && 'rollback' !== $type;
								$can_cancel   = 'scheduled' === $type && 'queued' === $status;
								$progress = ( (int) $r['total_pages'] > 0 && 'done' !== $status && 'rolled_back' !== $status )
									? min( 100, (int) round( ( (int) $r['page'] / (int) $r['total_pages'] ) * 100 ) )
									: ( in_array( $status, array( 'done', 'rolled_back' ), true ) ? 100 : (int) $r['page'] );
								$op_val = '' !== (string) $r['operation'] ? TCBPM_OPS::op_label( $r['operation'] ) : '—';
								if ( null !== $r['value'] && 'none' !== TCBPM_OPS::op_kind( $r['operation'] ) ) {
									$op_val .= ' (' . number_format_i18n( (float) $r['value'] ) . ')';
								}
								$created = mysql2date( 'Y/m/d H:i', $r['created_at'] );
								?>
								<tr data-run="<?php echo esc_attr( $row_id ); ?>" data-status="<?php echo esc_attr( $status ); ?>" data-type="<?php echo esc_attr( $type ); ?>">
									<td><?php echo esc_html( $row_id ); ?></td>
									<td><?php echo esc_html( TCBPM_Core::translation( $type ) ); ?></td>
									<td><?php echo esc_html( $op_val ); ?></td>
									<td class="tcbpm-run-progress">
										<div class="tcbpm-bar"><div style="width:<?php echo esc_attr( $progress ); ?>%"></div></div>
										<span class="tcbpm-muted"><?php echo esc_html( $progress ); ?>٪</span>
									</td>
									<td class="tcbpm-run-stats">
										تغییر: <b><?php echo esc_html( number_format_i18n( (int) $r['count_updated'] ) ); ?></b> /
										رد: <b><?php echo esc_html( number_format_i18n( (int) $r['count_skipped'] ) ); ?></b> /
										خطا: <b><?php echo esc_html( number_format_i18n( (int) $r['count_errors'] ) ); ?></b>
										<?php if ( 'rollback' === $type ) : ?>
											<div class="tcbpm-muted">از اجرای #<?php echo esc_html( (int) $r['parent_run_id'] ); ?></div>
										<?php endif; ?>
									</td>
									<td><span class="tcbpm-status-badge tcbpm-st-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( TCBPM_Core::translation( $status ) ); ?></span></td>
									<td><?php echo esc_html( $r['user_label'] ); ?></td>
									<td><?php echo esc_html( $created ); ?></td>
									<td class="tcbpm-run-actions">
										<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>?action=<?php echo esc_attr( TCBPM_Core::AJAX_EXPORT ); ?>&run_id=<?php echo esc_attr( $row_id ); ?>&_wpnonce=<?php echo esc_attr( wp_create_nonce( 'tcbpm_export_' . $row_id ) ); ?>">CSV</a>
										<?php if ( $can_resume ) : ?>
											<button type="button" class="button button-small tcbpm-act-resume">ادامه</button>
										<?php endif; ?>
										<?php if ( $can_stop ) : ?>
											<button type="button" class="button button-small tcbpm-act-stop">توقف</button>
										<?php endif; ?>
										<?php if ( $can_rollback ) : ?>
											<button type="button" class="button button-small tcbpm-act-rollback">بازگردانی</button>
										<?php endif; ?>
										<?php if ( $can_cancel ) : ?>
											<button type="button" class="button button-small tcbpm-act-cancel">انصراف از صف</button>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p class="tcbpm-muted">اجراهای زمان‌بندی‌شده که هنوز در صف‌اند، با دکمهٔ «انصراف از صف» کنسل می‌شوند. اجرای running که بیش از حد مجاز بی‌حرکت مانده باشد «ناتمام» شده و با دکمهٔ «ادامه» از همان صفحهٔ قبل ادامه می‌یابد.</p>
				<?php endif; ?>
			<?php
		}

		/* -----------------------------------------------------------------
		 * تنظیمات
		 * --------------------------------------------------------------- */

		public static function page_settings() {
			if ( ! TCBPM_Core::can() ) {
				wp_die( 'دسترسی غیرمجاز است.' );
			}

			$saved_notice = false;
			if ( ! empty( $_POST['tcbpm_settings_save'] ) ) {
				check_admin_referer( TCBPM_Core::NONCE, '_wpnonce' );
				TCBPM_Core::update_settings( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- داخل update_settings پاک‌سازی می‌شود
				$saved_notice = true;
				wp_safe_redirect( add_query_arg( array( 'tab' => 'settings', 'saved' => '1' ), admin_url( 'admin.php?page=' . TCBPM_Core::MAIN_PAGE ) ) );
				exit;
			}
			if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$saved_notice = true;
			}

			$s = TCBPM_Core::get_settings();
			?>
			<?php if ( $saved_notice ) : ?>
					<div class="notice notice-success"><p>تنظیمات ذخیره شد.</p></div>
				<?php endif; ?>

				<form method="post" action="" class="tcbpm-card tcbpm-card--narrow">
					<?php wp_nonce_field( TCBPM_Core::NONCE ); ?>
					<input type="hidden" name="tcbpm_settings_save" value="1">

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="tcbpm-cap">حداقل سطح دسترسی</label></th>
							<td>
								<select name="min_capability" id="tcbpm-cap">
									<option value="manage_woocommerce" <?php selected( $s['min_capability'], 'manage_woocommerce' ); ?>>مدیر فروشگاه و بالاتر (manage_woocommerce)</option>
									<option value="edit_products" <?php selected( $s['min_capability'], 'edit_products' ); ?>>هر کس که می‌تواند محصول ویرایش کند</option>
									<option value="manage_options" <?php selected( $s['min_capability'], 'manage_options' ); ?>>فقط ادمین کل سایت</option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="tcbpm-batch">اندازهٔ دسته (محصول در هر مرحله)</label></th>
							<td><input type="number" min="1" max="100" name="batch_size" id="tcbpm-batch" value="<?php echo esc_attr( $s['batch_size'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="tcbpm-threshold">آستانهٔ تأیید دستی (تعداد محصول مادر)</label></th>
							<td><input type="number" min="0" name="confirm_threshold" id="tcbpm-threshold" value="<?php echo esc_attr( $s['confirm_threshold'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="tcbpm-lock">مهلت بی‌حرکتی اجرا (دقیقه)</label></th>
							<td><input type="number" min="2" name="lock_minutes" id="tcbpm-lock" value="<?php echo esc_attr( $s['lock_minutes'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="tcbpm-retention">مدت نگهداری لاگ (روز)</label></th>
							<td><input type="number" min="1" name="retention_days" id="tcbpm-retention" value="<?php echo esc_attr( $s['retention_days'] ); ?>"> <span class="description">پس از این مدت گزارش‌ها پاک می‌شوند و بازگردانی دیگر ممکن نیست.</span></td>
						</tr>
						<tr>
							<th scope="row"><label for="tcbpm-max">سقف مبلغ ورودی (set/ثابت)</label></th>
							<td><input type="number" min="1" name="max_amount" id="tcbpm-max" value="<?php echo esc_attr( (float) $s['max_amount'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row">ثبت لاگ</th>
							<td><label><input type="checkbox" name="logging" value="1" <?php checked( $s['logging'] ); ?>> ثبت همهٔ تغییرات برای گزارش و بازگردانی</label></td>
						</tr>
						<tr>
							<th scope="row">بازگردانی</th>
							<td><label><input type="checkbox" name="rollback" value="1" <?php checked( $s['rollback'] ); ?>> فعال‌بودن دکمهٔ بازگردانی اجراها</label></td>
						</tr>
						<tr>
							<th scope="row">صف زمان‌بندی WP-Cron</th>
							<td><label><input type="checkbox" name="scheduled" value="1" <?php checked( $s['scheduled'] ); ?>> فعال‌بودن دکمهٔ «ثبت در صف زمان‌بندی»</label></td>
						</tr>
						<tr>
							<th scope="row"><label for="tcbpm-cronpages">تعداد صفحه در هر تیک Cron</label></th>
							<td><input type="number" min="1" max="500" name="cron_pages" id="tcbpm-cronpages" value="<?php echo esc_attr( $s['cron_pages'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="tcbpm-sample">تعداد نمونهٔ پیش‌نمایش</label></th>
							<td><input type="number" min="1" max="50" name="sample_size" id="tcbpm-sample" value="<?php echo esc_attr( $s['sample_size'] ); ?>"></td>
						</tr>
					</table>
					<?php submit_button( 'ذخیرهٔ تنظیمات' ); ?>
				</form>
			<?php
		}
	}
}
