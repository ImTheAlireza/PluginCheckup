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
				<p class="tisa-lead"><?php esc_html_e( 'هر چیزی که اینجا ست می‌شود، روی صفحهٔ هر ۹ افزونه هم اعمال می‌شود — بدون آنکه آن‌ها از وجود هاب باخبر شوند.', 'tisacase-hub' ); ?></p>
			</div>
		</div>
		<div class="tisa-hero-actions">
			<a class="tisa-btn tisa-btn--sm" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TSH_SLUG ) ); ?>"><?php esc_html_e( '← ابزارها', 'tisacase-hub' ); ?></a>
			<a class="tisa-btn tisa-btn--sm" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TSH_SLUG . '-health' ) ); ?>"><?php esc_html_e( 'گزارش سلامت', 'tisacase-hub' ); ?></a>
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
			<div class="tisa-setrow__ctrl" id="tsh-accent-row">
				<?php foreach ( $accents as $hex => $label ) : ?>
					<button type="button" class="tisa-accent__sw<?php echo strtolower( (string) $settings['accent'] ) === strtolower( $hex ) ? ' is-on' : ''; ?>" data-hex="<?php echo esc_attr( $hex ); ?>" style="background:<?php echo esc_attr( $hex ); ?>" title="<?php echo esc_attr( $label ); ?>"></button>
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
				<b class="tisa-h3"><?php esc_html_e( 'لیست و ویرایش محصول را هم یکدست کن', 'tisacase-hub' ); ?></b>
				<p class="tisa-meta"><?php esc_html_e( 'این صفحه‌ها متعلق به ووکامرس‌اند (نوار SKU آنجا زندگی می‌کند). اگر چیدمان جدول واریاسیون‌ها یا پنل دادهٔ محصول به‌هم ریخت، همین یکی را خاموش کنید — بقیهٔ صفحه‌ها یکدست می‌مانند.', 'tisacase-hub' ); ?></p>
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
				<b class="tisa-h3"><?php esc_html_e( 'شمارنده‌های روی کارت‌ها', 'tisacase-hub' ); ?></b>
				<p class="tisa-meta"><?php esc_html_e( 'تعداد پیش‌نویس‌ها، SKUهای بدون پیشوند، اجراهای ثبت‌شده و … با کش. اگر سایت بزرگ است، کش را بالا ببرید.', 'tisacase-hub' ); ?></p>
			</div>
			<div class="tisa-setrow__ctrl">
				<label class="tisa-switch">
					<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[show_counts]" value="1" <?php checked( ! empty( $settings['show_counts'] ) ); ?>>
					<span class="tisa-switch__track" aria-hidden="true"></span>
					<span><?php esc_html_e( 'نمایش', 'tisacase-hub' ); ?></span>
				</label>
				<label class="tisa-field" style="margin:0">
					<span class="tisa-label"><?php esc_html_e( 'کش (ثانیه)', 'tisacase-hub' ); ?></span>
					<input type="number" name="<?php echo esc_attr( $opt ); ?>[cache_ttl]" class="tisa-input tisa-input--number tisa-input--w-sm" min="0" max="86400" step="60" value="<?php echo esc_attr( (string) $settings['cache_ttl'] ); ?>">
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

		<p class="tisa-row" style="margin:var(--tisa-sp-5) 0 0">
			<?php submit_button( __( 'ذخیرهٔ تنظیمات', 'tisacase-hub' ), 'primary', 'submit', false ); ?>
			<span class="tisa-hint"><?php esc_html_e( 'بلافاصله روی همهٔ صفحه‌ها اعمال می‌شود.', 'tisacase-hub' ); ?></span>
		</p>
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
