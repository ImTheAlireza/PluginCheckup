<?php
/**
 * قالب «ظاهر و تنظیمات».
 *
 * @var array $settings تنظیمات فعلی
 * @var array $font     وضعیت فونت محلی {count, weights}
 * @var array $hidden   کلیدهای مخفی‌شده
 * @var array $items    همهٔ آیتم‌ها
 * @var array $unreg    افزونه‌های ثبت‌نشده
 * @var array $env      اطلاعات محیط
 *
 * @package TisaCase_Hub
 */

defined( 'ABSPATH' ) || exit;

$opt     = TSH_OPTION;
$accents = array(
	'#0E7C6B' => __( 'تیساکیس (پیش‌فرض)', 'tisacase-hub' ),
	'#1467A8' => __( 'آبی', 'tisacase-hub' ),
	'#B5453A' => __( 'آجر', 'tisacase-hub' ),
	'#6D4AA8' => __( 'بنفش', 'tisacase-hub' ),
	'#1A7F37' => __( 'سبز', 'tisacase-hub' ),
	'#8A6116' => __( 'خاکی', 'tisacase-hub' ),
);

$snippet = "# فایل اصلی افزونه — فقط یک خط هدر، بدون کد:\n"
	. "# TisaCase Hub: title=\"حذف انبوه پیش‌نویس\"; icon=trash; group=products; page=admin.php?page=bdc-cleaner; screen=woocommerce_page_bdc-cleaner; parent=woocommerce; slug=bdc-cleaner\n\n"
	. "// یا با فیلتر (وقتی آیتم چند صفحه دارد یا شمارندهٔ سفارشی می‌خواهید):\n"
	. "add_filter( 'tisacase_hub_items', function ( \$items ) {\n"
	. "\t\$items['bdc'] = array(\n"
	. "\t\t'title' => 'حذف انبوه پیش‌نویس',\n"
	. "\t\t'desc'  => 'حذف امن محصولات پیش‌نویس، با بکاپ.',\n"
	. "\t\t'group' => 'products',            // products | pricing | orders | system\n"
	. "\t\t'icon'  => 'trash',\n"
	. "\t\t'dir'   => 'bulk-product-cleaner', // نام پوشهٔ افزونه\n"
	. "\t\t'cap'   => 'manage_woocommerce',\n"
	. "\t\t'pages' => array(\n"
	. "\t\t\tarray( 'label' => 'پاک‌ساز', 'path' => 'admin.php?page=bdc-cleaner', 'screen' => 'woocommerce_page_bdc-cleaner', 'parent' => 'woocommerce', 'slug' => 'bdc-cleaner' ),\n"
	. "\t\t\tarray( 'label' => 'بکاپ‌ها', 'path' => 'admin.php?page=bdc-cleaner&tab=backups' ),\n"
	. "\t\t),\n"
	. "\t\t'count' => 'bdc_drafts',           // یکی از شمارنده‌های آماده\n"
	. "\t);\n"
	. "\treturn \$items;\n} );";
?>
<div class="wrap tisa-wrap tisa-hub-wrap" dir="rtl">

	<header class="tisa-hero">
		<div class="tisa-hero-row">
			<span class="tisa-hero-mark" aria-hidden="true"><?php echo TSH_View::icon( 'gear' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<div>
				<h1 class="tisa-hero-title"><?php esc_html_e( 'ظاهر و تنظیمات هاب', 'tisacase-hub' ); ?></h1>
				<p class="tisa-lead"><?php esc_html_e( 'هر چیزی که اینجا ست می‌شود، روی صفحهٔ همهٔ افزونه‌های مجموعه هم اعمال می‌شود — بدون آنکه آن‌ها از وجود هاب باخبر شوند.', 'tisacase-hub' ); ?></p>
			</div>
		</div>
		<div class="tisa-hero-actions">
			<a class="tisa-btn tisa-btn--sm" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TSH_SLUG ) ); ?>"><?php esc_html_e( '← ابزارها', 'tisacase-hub' ); ?></a>
		</div>
	</header>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=tisacase_hub_save' ) ); ?>" class="tisa-card">
		<?php wp_nonce_field( 'tsh_save' ); ?>

		<h2 class="tisa-h2"><?php esc_html_e( 'زبان طراحی', 'tisacase-hub' ); ?></h2>

		<div class="tisa-setrow">
			<div class="tisa-setrow__text">
				<b class="tisa-h3"><?php esc_html_e( 'رنگ برند', 'tisacase-hub' ); ?></b>
				<p class="tisa-meta"><?php esc_html_e( 'توکن‌های --tisa-primary* و سایه/حلقهٔ فوکوس از همین یک رنگ ساخته می‌شوند. هر افزونه‌ای که از var() استفاده کند، خودکار rebrand می‌شود.', 'tisacase-hub' ); ?></p>
			</div>
			<div class="tisa-setrow__ctrl" id="tsh-accent-row" role="radiogroup" aria-label="<?php esc_attr_e( 'رنگ برند', 'tisacase-hub' ); ?>">
				<?php foreach ( $accents as $hex => $label ) : ?>
					<?php $is_on = strtolower( (string) $settings['accent'] ) === strtolower( $hex ); ?>
					<button type="button" class="tisa-accent__sw<?php echo $is_on ? ' is-on' : ''; ?>" role="radio" aria-checked="<?php echo $is_on ? 'true' : 'false'; ?>"
						data-hex="<?php echo esc_attr( $hex ); ?>" style="background:<?php echo esc_attr( $hex ); ?>"
						aria-label="<?php echo esc_attr( $label ); ?>" title="<?php echo esc_attr( $label ); ?>"></button>
				<?php endforeach; ?>
				<span class="tisa-input-group" style="max-width:170px">
					<span class="tisa-input-group__addon">#</span>
					<input type="text" name="<?php echo esc_attr( $opt ); ?>[accent]" id="tsh-accent" class="tisa-input tisa-input--code" value="<?php echo esc_attr( ltrim( (string) $settings['accent'], '#' ) ); ?>" maxlength="6" pattern="[0-9a-fA-F]{3,6}" dir="ltr">
				</span>
				<input type="color" id="tsh-accent-pick" class="tisa-accent__custom" value="<?php echo esc_attr( $settings['accent'] ); ?>" title="<?php esc_attr_e( 'انتخاب از پالت', 'tisacase-hub' ); ?>">
			</div>
		</div>

		<div class="tisa-setrow">
			<div class="tisa-setrow__text">
				<b class="tisa-h3"><?php esc_html_e( 'حالت کم‌فضا', 'tisacase-hub' ); ?></b>
				<p class="tisa-meta"><?php esc_html_e( 'فاصله‌ها، گوشه‌ها و ارتفاع کنترل‌ها جمع می‌شوند — برای صفحه‌های شلوغ مثل جدول قیمت‌ها.', 'tisacase-hub' ); ?></p>
			</div>
			<div class="tisa-setrow__ctrl">
				<label class="tisa-switch">
					<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[compact]" value="1" <?php checked( ! empty( $settings['compact'] ) ); ?>>
					<span class="tisa-switch__track" aria-hidden="true"></span>
					<span><?php esc_html_e( 'کم‌فضا', 'tisacase-hub' ); ?></span>
				</label>
			</div>
		</div>

		<div class="tisa-setrow">
			<div class="tisa-setrow__text">
				<b class="tisa-h3"><?php esc_html_e( 'یکدست‌سازی صفحات افزونه‌ها', 'tisacase-hub' ); ?></b>
				<p class="tisa-meta"><?php esc_html_e( 'هاب کلاس tisa-scope را روی بدنهٔ همان صفحه‌ها می‌گذارد و دکمه/نوتیس/جدول/فرم وردپرس را بازنویسی می‌کند. اگر جایی در صفحهٔ ووکامرس به‌هم ریخت، این را خاموش کنید.', 'tisacase-hub' ); ?></p>
			</div>
			<div class="tisa-setrow__ctrl">
				<label class="tisa-switch">
					<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[style_plugins]" value="1" <?php checked( ! empty( $settings['style_plugins'] ) ); ?>>
					<span class="tisa-switch__track" aria-hidden="true"></span>
					<span><?php esc_html_e( 'روشن', 'tisacase-hub' ); ?></span>
				</label>
			</div>
		</div>

		<div class="tisa-setrow">
			<div class="tisa-setrow__text">
				<b class="tisa-h3"><?php esc_html_e( 'لیست محصولات را هم یکدست کن', 'tisacase-hub' ); ?></b>
				<p class="tisa-meta"><?php esc_html_e( 'صفحهٔ edit.php?post_type=product مال خودِ ووکامرس است، ولی نوار SKU آنجا زندگی می‌کند. صفحهٔ ویرایش محصول عمداً دست‌خورده نمی‌شود (جدول واریاسیون‌ها و پنل دادهٔ محصول شکننده‌اند)؛ اگر آن را هم خواستید، با فیلتر tisacase_hub_skubar_screens باز می‌شود.', 'tisacase-hub' ); ?></p>
			</div>
			<div class="tisa-setrow__ctrl">
				<label class="tisa-switch">
					<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[style_product_screens]" value="1" <?php checked( ! empty( $settings['style_product_screens'] ) ); ?>>
					<span class="tisa-switch__track" aria-hidden="true"></span>
					<span><?php esc_html_e( 'روشن', 'tisacase-hub' ); ?></span>
				</label>
			</div>
		</div>

		<h2 class="tisa-h2" style="margin-top:var(--tisa-sp-6)"><?php esc_html_e( 'منو و لانچر', 'tisacase-hub' ); ?></h2>

		<div class="tisa-setrow">
			<div class="tisa-setrow__text">
				<b class="tisa-h3"><?php esc_html_e( 'جایگاه هاب در نوار کنار', 'tisacase-hub' ); ?></b>
				<p class="tisa-meta"><?php esc_html_e( 'هاب یک گزینهٔ مستقل است — هیچ ورودی‌ای داخل منوی ووکامرس ثبت نمی‌کند. جایگاهش را خودتان انتخاب کنید؛ اگر جایی شلوغ بود، «عدد دلخواه» را امتحان کنید (اعداد اعشاری مثل ۵۷٫۵ هم قبول است).', 'tisacase-hub' ); ?></p>
			</div>
			<div class="tisa-setrow__ctrl">
				<label class="tisa-field" style="margin:0">
					<span class="tisa-label"><?php esc_html_e( 'جایگاه', 'tisacase-hub' ); ?></span>
					<select name="<?php echo esc_attr( $opt ); ?>[menu_position]" class="tisa-select" id="tsh-pos">
						<?php foreach ( TSH_Admin::positions() as $pkey => $pinfo ) : ?>
							<option value="<?php echo esc_attr( $pkey ); ?>" <?php selected( $settings['menu_position'], $pkey ); ?>><?php echo esc_html( $pinfo['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="tisa-field" style="margin:0">
					<span class="tisa-label"><?php esc_html_e( 'عدد دلخواه', 'tisacase-hub' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $opt ); ?>[menu_position_custom]" class="tisa-input tisa-input--number" style="max-width:110px" value="<?php echo esc_attr( (string) $settings['menu_position_custom'] ); ?>" placeholder="57.5" pattern="\d{1,2}(\.\d{1,2})?" dir="ltr">
				</label>
			</div>
		</div>

		<div class="tisa-setrow">
			<div class="tisa-setrow__text">
				<b class="tisa-h3"><?php esc_html_e( 'آیتم‌های پخش‌شده در ووکامرس/محصولات را مخفی کن', 'tisacase-hub' ); ?></b>
				<p class="tisa-meta"><?php esc_html_e( 'فقط نمایش در منو حذف می‌شود؛ URL مستقیم، بوک‌مارک‌ها و صفحهٔ هر افزونه سالم است. با خاموش‌کردن این گزینه یا غیرفعال‌کردن هاب، همه برمی‌گردند.', 'tisacase-hub' ); ?></p>
			</div>
			<div class="tisa-setrow__ctrl">
				<label class="tisa-switch">
					<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[hide_scattered]" value="1" <?php checked( ! empty( $settings['hide_scattered'] ) ); ?>>
					<span class="tisa-switch__track" aria-hidden="true"></span>
					<span><?php esc_html_e( 'هاب تنها ورودی باشد', 'tisacase-hub' ); ?></span>
				</label>
			</div>
		</div>

		<div class="tisa-setrow">
			<div class="tisa-setrow__text">
				<b class="tisa-h3"><?php esc_html_e( 'کارت‌های مخفی‌شده', 'tisacase-hub' ); ?></b>
				<p class="tisa-meta"><?php esc_html_e( 'مخفی کردن یعنی فقط در لانچر دیده نشود؛ افزونه در «افزونه‌ها»ی وردپرس دست‌نخورده است.', 'tisacase-hub' ); ?></p>
			</div>
			<div class="tisa-setrow__ctrl">
				<?php if ( ! $hidden ) : ?>
					<span class="tisa-badge tisa-badge--outline"><?php esc_html_e( 'موردی مخفی نیست', 'tisacase-hub' ); ?></span>
				<?php else : ?>
					<?php foreach ( $hidden as $hk ) : ?>
						<a class="tisa-btn tisa-btn--sm tisa-btn--secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tisacase_hub_action&task=unhide&item=' . $hk ), 'tsh_action' ) ); ?>">
							<?php echo esc_html( isset( $items[ $hk ] ) ? $items[ $hk ]['title'] : $hk ); ?> ↩
						</a>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>

		<h2 class="tisa-h2" style="margin-top:var(--tisa-sp-6)"><?php esc_html_e( 'قلم', 'tisacase-hub' ); ?></h2>

		<div class="tisa-setrow">
			<div class="tisa-setrow__text">
				<b class="tisa-h3"><?php esc_html_e( 'Vazirmatn محلی', 'tisacase-hub' ); ?></b>
				<p class="tisa-meta">
					<?php
					if ( $font['count'] ) {
						printf(
							/* translators: 1: count markup, 2: weights markup */
							esc_html__( '%1$s فایل در assets/fonts نصب شده (وزن‌ها: %2$s). هاب خودکار @font-face می‌سازد و افزونه‌ها هم همان را می‌گیرند.', 'tisacase-hub' ),
							'<span class="tisa-num">' . esc_html( TSH_View::num( $font['count'] ) ) . '</span>',
							'<span class="tisa-code">' . esc_html( $font['weights'] ) . '</span>'
						);
					} else {
						esc_html_e( 'فایلی نصب نیست؛ از قلم سیستم (Segoe UI / Tahoma) استفاده می‌شود. اگر Vazirmatn را با نام‌های vazirmatn-400.woff2 … vazirmatn-800.woff2 در assets/fonts بگذارید، بدون هیچ تنظیمی فعال می‌شود.', 'tisacase-hub' );
					}
					?>
				</p>
				<p class="tisa-hint"><?php esc_html_e( 'نکته: قلم مونو فقط برای کد (SKU، شناسه، لاگ) است؛ اعداد و مبالغ با قلم رابط و tabular-nums رندر می‌شوند تا رقم فارسی نشکند.', 'tisacase-hub' ); ?></p>
			</div>
		</div>

		<h2 class="tisa-h2" style="margin-top:var(--tisa-sp-6)"><?php esc_html_e( 'مخزن و شاخه', 'tisacase-hub' ); ?></h2>

		<div class="tisa-setrow">
			<div class="tisa-setrow__text">
				<b class="tisa-h3"><?php esc_html_e( 'مخزن GitHub و شاخهٔ متصل', 'tisacase-hub' ); ?></b>
				<p class="tisa-meta"><?php esc_html_e( 'نصب، به‌روزرسانی از مخزن، و شناسایی افزونهٔ جدید از همین مخزن و همین شاخه خوانده می‌شود. برای کار روی برنچ جدا، نام برنچ را اینجا بگذارید و ذخیره کنید؛ لازم نیست هاب را عوض کنید.', 'tisacase-hub' ); ?></p>
			</div>
			<div class="tisa-setrow__control" style="display:flex;flex-direction:column;gap:8px;min-width:280px">
				<label class="tisa-hint" for="tsh-repo"><?php esc_html_e( 'مخزن (owner/name)', 'tisacase-hub' ); ?></label>
				<input type="text" id="tsh-repo" class="tisa-input tisa-input--code" dir="ltr"
					name="<?php echo esc_attr( $opt ); ?>[repo]"
					value="<?php echo esc_attr( isset( $settings['repo'] ) ? $settings['repo'] : 'ImTheAlireza/TisaCaseHub' ); ?>"
					placeholder="ImTheAlireza/TisaCaseHub">
				<label class="tisa-hint" for="tsh-branch"><?php esc_html_e( 'شاخه', 'tisacase-hub' ); ?></label>
				<input type="text" id="tsh-branch" class="tisa-input tisa-input--code" dir="ltr"
					name="<?php echo esc_attr( $opt ); ?>[branch]"
					value="<?php echo esc_attr( isset( $settings['branch'] ) ? $settings['branch'] : 'main' ); ?>"
					placeholder="main">
			</div>
		</div>

		<h2 class="tisa-h2" style="margin-top:var(--tisa-sp-6)"><?php esc_html_e( 'نصب از مخزن', 'tisacase-hub' ); ?></h2>

		<div class="tisa-setrow">
			<div class="tisa-setrow__text">
				<b class="tisa-h3"><?php esc_html_e( 'پایهٔ آدرس زیپ‌های مجموعه', 'tisacase-hub' ); ?></b>
				<p class="tisa-meta">
					<?php esc_html_e( 'کارت افزونه‌هایی که روی این سرور نصب نیستند، دکمهٔ «نصب از مخزن» می‌گیرند و زیپ را از همین مسیر می‌خوانند:', 'tisacase-hub' ); ?>
					<span class="tisa-code" dir="ltr"><?php echo esc_html( TSH_Registry::zip_base() ); ?></span>
				</p>
				<p class="tisa-hint">
					<?php esc_html_e( 'پیش‌فرض روی شاخهٔ main مخزن مجموعه است. اگر سرور به raw.githubusercontent.com دسترسی ندارد یا می‌خواهید شاخهٔ دیگری را تست کنید، همین کادر را عوض کنید. نمونه‌ها:', 'tisacase-hub' ); ?>
					<br><span class="tisa-code" dir="ltr">https://cdn.jsdelivr.net/gh/ImTheAlireza/TisaCaseHub@main/plugins/dist/</span>
					<br><span class="tisa-code" dir="ltr">https://tisacap.com/wp-content/uploads/tisacase-zips/</span>
					<?php esc_html_e( 'برای افزونه‌های بازنشسته (قیمت گروهی و قیمت‌گذاری داینامیک) زیپی وجود ندارد و دکمهٔ نصب نشان داده نمی‌شود.', 'tisacase-hub' ); ?>
				</p>
			</div>
			<div class="tisa-setrow__control">
				<label class="screen-reader-text" for="tsh-zip-base"><?php esc_html_e( 'پایهٔ آدرس زیپ‌ها', 'tisacase-hub' ); ?></label>
				<input type="url" id="tsh-zip-base" class="tisa-input tisa-input--code" style="min-width:320px"
					name="<?php echo esc_attr( $opt ); ?>[zip_base]" dir="ltr" inputmode="url"
					value="<?php echo esc_attr( (string) $settings['zip_base'] ); ?>"
					placeholder="<?php echo esc_attr( TSH_Registry::ZIP_BASE ); ?>">
			</div>
		</div>

		<p class="tisa-row" style="margin:var(--tisa-sp-5) 0 0">
			<?php submit_button( __( 'ذخیرهٔ تنظیمات', 'tisacase-hub' ), 'primary', 'submit', false ); ?>
			<span class="tisa-hint"><?php esc_html_e( 'بلافاصله روی همهٔ صفحه‌ها اعمال می‌شود.', 'tisacase-hub' ); ?></span>
		</p>
	</form>

	<?php
	$cat     = class_exists( 'TSH_Remote' ) ? TSH_Remote::catalog() : array();
	$cat_n   = isset( $cat['items'] ) ? count( (array) $cat['items'] ) : 0;
	$cat_at  = ! empty( $cat['at'] ) ? wp_date( 'Y-m-d H:i', (int) $cat['at'] ) : '';
	$cat_br  = isset( $cat['branch'] ) ? $cat['branch'] : '';
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:var(--tisa-sp-4)">
		<input type="hidden" name="action" value="tisacase_hub_sync">
		<?php wp_nonce_field( 'tsh_sync_catalog', '_tshnonce' ); ?>
		<div class="tisa-setrow">
			<div class="tisa-setrow__text">
				<b class="tisa-h3"><?php esc_html_e( 'شناسایی افزونه‌های مخزن', 'tisacase-hub' ); ?></b>
				<p class="tisa-meta"><?php esc_html_e( 'زیپ‌های plugins/dist روی شاخهٔ ذخیره‌شده را می‌خواند و کارت افزونهٔ جدید می‌سازد — بدون به‌روزرسانی خودِ هاب. اول مخزن و شاخه را ذخیره کنید، بعد این دکمه را بزنید.', 'tisacase-hub' ); ?></p>
				<?php if ( $cat_at ) : ?>
					<p class="tisa-hint" dir="ltr"><?php echo esc_html( sprintf( /* translators: 1: branch, 2: count, 3: time */ __( 'آخرین همگام‌سازی: %1$s · %2$s افزونه · %3$s', 'tisacase-hub' ), $cat_br, (string) $cat_n, $cat_at ) ); ?></p>
				<?php endif; ?>
			</div>
			<div class="tisa-setrow__control">
				<button type="submit" class="tisa-btn tisa-btn--primary"><?php esc_html_e( 'همگام‌سازی با مخزن', 'tisacase-hub' ); ?></button>
			</div>
		</div>
	</form>

	<section class="tisa-card" id="tsh-dev">
		<h2 class="tisa-h2"><?php esc_html_e( 'افزونهٔ جدید را به هاب وصل کنید', 'tisacase-hub' ); ?></h2>
		<p class="tisa-meta"><?php esc_html_e( 'هر دو روش اختیاری‌اند؛ افزونه‌های فعلی از فهرست داخلی هاب استفاده می‌کنند. اگر روزی آیتمی را اینجا نبودید، همین یک خط کافی است.', 'tisacase-hub' ); ?></p>
		<div style="position:relative">
			<pre class="tisa-codebox"><code><?php echo esc_html( $snippet ); ?></code></pre>
			<button type="button" class="tisa-btn tisa-btn--sm tisa-codebox__copy" id="tsh-copy"><?php esc_html_e( 'کپی', 'tisacase-hub' ); ?></button>
		</div>
		<p class="tisa-hint" style="margin-top:var(--tisa-sp-3)">
			<?php esc_html_e( 'برای استایل اختصاصی هم همین قانون: به‌جای هگز، var(--tisa-primary, #0E7C6B). fallback باعث می‌شود اگر هاب غیرفعال شد، صفحهٔ افزونه خودش را از هم نپاشد.', 'tisacase-hub' ); ?>
		</p>
	</section>

	<section class="tisa-card">
		<h2 class="tisa-h2"><?php esc_html_e( 'اطلاعات', 'tisacase-hub' ); ?></h2>
		<div class="tisa-stats">
			<div class="tisa-stat">
				<span class="tisa-stat__num"><?php echo esc_html( TSH_View::num( $env['items'] ) ); ?></span>
				<span class="tisa-stat__lbl"><?php esc_html_e( 'آیتم ثبت‌شده', 'tisacase-hub' ); ?></span>
			</div>
			<div class="tisa-stat">
				<span class="tisa-stat__num"><?php echo esc_html( $env['wc'] ? $env['wc'] : '—' ); ?></span>
				<span class="tisa-stat__lbl"><?php esc_html_e( 'نسخهٔ ووکامرس', 'tisacase-hub' ); ?></span>
			</div>
			<div class="tisa-stat">
				<span class="tisa-stat__num"><?php echo esc_html( $env['php'] ); ?></span>
				<span class="tisa-stat__lbl"><?php esc_html_e( 'PHP', 'tisacase-hub' ); ?></span>
			</div>
			<div class="tisa-stat">
				<span class="tisa-stat__num"><?php echo esc_html( $env['hpos'] ); ?></span>
				<span class="tisa-stat__lbl"><?php esc_html_e( 'جداول سفارش', 'tisacase-hub' ); ?></span>
			</div>
		</div>
		<?php if ( $unreg ) : ?>
			<p class="tisa-hint" style="margin-top:var(--tisa-sp-3)">
				<?php
				printf(
					/* translators: %s: count */
					esc_html__( '%s افزونهٔ تیساکیسی در این نصب پیدا شد که هنوز در هاب ثبت نشده (بالای صفحهٔ ابزارها فهرست شده‌اند).', 'tisacase-hub' ),
					'<span class="tisa-num">' . esc_html( TSH_View::num( count( $unreg ) ) ) . '</span>'
				);
				?>
			</p>
		<?php endif; ?>
	</section>
</div>
