<?php
/**
 * قالب صفحهٔ لانچر.
 *
 * متغیرها از TSH_Admin::view() می‌آیند:
 * @var array  $groups     گروه‌ها => array('label'=>, 'items'=>array(item))
 * @var array  $all        همهٔ آیتم‌های resolve‌شده
 * @var array  $pins       کلیدهای سنجاق‌شدهٔ کاربر
 * @var array  $health     خلاصهٔ گزارش سلامت
 * @var array  $unreg      افزونه‌های تیساکیسِ ثبت‌نشده
 * @var array  $settings   تنظیمات هاب
 * @var string $counts_at  ساعت آخرین محاسبهٔ شمارنده‌ها
 * @var array  $env        اطلاعات محیط
 *
 * @package TisaCase_Hub
 */

defined( 'ABSPATH' ) || exit;

$hub_url      = admin_url( 'admin.php?page=' . TSH_SLUG );
$settings_url = admin_url( 'admin.php?page=' . TSH_SLUG . '-settings' );
$health_url   = admin_url( 'admin.php?page=' . TSH_SLUG . '-health' );
$accents = array(
	'#0E7C6B' => __( 'تیساکیس', 'tisacase-hub' ),
	'#1467A8' => __( 'آبی', 'tisacase-hub' ),
	'#B5453A' => __( 'آجر', 'tisacase-hub' ),
	'#6D4AA8' => __( 'بنفش', 'tisacase-hub' ),
	'#1A7F37' => __( 'سبز', 'tisacase-hub' ),
);

$active_n   = 0;
$inactive_n = 0;
$tool_n     = 0;
foreach ( $all as $it ) {
	if ( 'system' === $it['state'] ) {
		continue;
	}
	$tool_n++;
	if ( 'active' === $it['state'] ) {
		$active_n++;
	} elseif ( 'inactive' === $it['state'] ) {
		$inactive_n++;
	}
}
?>
<div class="wrap tisa-wrap tisa-hub-wrap" dir="rtl">

	<header class="tisa-hero">
		<div class="tisa-hero-row">
			<span class="tisa-hero-mark" aria-hidden="true"><?php echo TSH_View::icon( 'grid' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<div>
				<h1 class="tisa-hero-title"><?php esc_html_e( 'اختصاصی تیساکیس', 'tisacase-hub' ); ?></h1>
				<p class="tisa-lead"><?php esc_html_e( 'همهٔ ابزارهای اختصاصی در یک صفحه — هر کدام در تب خودش باز می‌شود، همه با یک زبان طراحی و یک رنگ برند.', 'tisacase-hub' ); ?></p>
				<div class="tisa-hero-chips">
					<span class="tisa-chip"><span class="tisa-num"><?php echo esc_html( TSH_View::num( $active_n ) ); ?></span> <?php esc_html_e( 'فعال', 'tisacase-hub' ); ?></span>
					<?php if ( $inactive_n ) : ?>
						<span class="tisa-chip"><span class="tisa-num"><?php echo esc_html( TSH_View::num( $inactive_n ) ); ?></span> <?php esc_html_e( 'غیرفعال', 'tisacase-hub' ); ?></span>
					<?php endif; ?>
					<?php if ( $health['crit'] ) : ?>
						<span class="tisa-chip"><?php echo esc_html( TSH_View::num( $health['crit'] ) ); ?> <?php esc_html_e( 'مورد بحرانی', 'tisacase-hub' ); ?></span>
					<?php endif; ?>
					<?php if ( $health['warn'] ) : ?>
						<span class="tisa-chip"><?php echo esc_html( TSH_View::num( $health['warn'] ) ); ?> <?php esc_html_e( 'هشدار', 'tisacase-hub' ); ?></span>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div class="tisa-hero-actions">
			<a class="tisa-btn tisa-btn--sm" href="<?php echo esc_url( $health_url ); ?>"><?php echo TSH_View::icon( 'shield' ); ?><?php esc_html_e( 'سلامت افزونه‌ها', 'tisacase-hub' ); ?></a>
			<a class="tisa-btn tisa-btn--sm" href="<?php echo esc_url( $settings_url ); ?>"><?php echo TSH_View::icon( 'gear' ); ?><?php esc_html_e( 'ظاهر و تنظیمات', 'tisacase-hub' ); ?></a>
			<a class="tisa-btn tisa-btn--sm" href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>" target="_blank" rel="noopener"><?php echo TSH_View::icon( 'plug' ); ?><?php esc_html_e( 'افزونه‌ها', 'tisacase-hub' ); ?></a>
		</div>
	</header>

	<section class="tisa-card tisa-hub-card">

		<div class="tisa-hub-card__top">
			<div class="tisa-hub-toolbar" style="margin:0 0 var(--tisa-sp-4)">
				<div class="tisa-hub-search">
					<span class="tisa-hub-search__icon" aria-hidden="true"><?php echo TSH_View::icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					<input type="search" id="tsh-q" class="tisa-input" autocomplete="off" spellcheck="false"
						placeholder="<?php esc_attr_e( 'جستجوی ابزار…', 'tisacase-hub' ); ?>"
						aria-label="<?php esc_attr_e( 'جستجو در ابزارها', 'tisacase-hub' ); ?>">
				</div>
				<span class="tisa-hub-count" id="tsh-count" aria-live="polite"></span>
				<span class="tisa-spacer"></span>

				<span class="tisa-accent" id="tsh-accents" title="<?php esc_attr_e( 'رنگ برند — روی همهٔ صفحات افزونه‌ها اعمال می‌شود', 'tisacase-hub' ); ?>">
					<span class="tisa-meta"><?php esc_html_e( 'رنگ برند', 'tisacase-hub' ); ?></span>
					<?php foreach ( $accents as $hex => $label ) : ?>
						<button type="button" class="tisa-accent__sw<?php echo strtolower( (string) $settings['accent'] ) === strtolower( $hex ) ? ' is-on' : ''; ?>"
							data-hex="<?php echo esc_attr( $hex ); ?>" style="background:<?php echo esc_attr( $hex ); ?>"
							title="<?php echo esc_attr( $label ); ?>"></button>
					<?php endforeach; ?>
					<input type="color" id="tsh-accent-custom" class="tisa-accent__custom" value="<?php echo esc_attr( $settings['accent'] ); ?>"
						title="<?php esc_attr_e( 'رنگ دلخواه', 'tisacase-hub' ); ?>" aria-label="<?php esc_attr_e( 'رنگ دلخواه', 'tisacase-hub' ); ?>">
				</span>

				<label class="tisa-switch" title="<?php esc_attr_e( 'حالت کم‌فضا: فاصله و اندازه‌ها جمع‌وجورتر', 'tisacase-hub' ); ?>">
					<input type="checkbox" id="tsh-compact" <?php checked( ! empty( $settings['compact'] ) ); ?>>
					<span class="tisa-switch__track" aria-hidden="true"></span>
					<span><?php esc_html_e( 'کم‌فضا', 'tisacase-hub' ); ?></span>
				</label>

				<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" id="tsh-refresh">
					<?php echo TSH_View::icon( 'refresh' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php esc_html_e( 'تازه‌سازی شمارش‌ها', 'tisacase-hub' ); ?></span>
				</button>
			</div>

			<div class="tisa-health">
				<a class="<?php echo $health['crit'] ? 'is-crit' : 'is-ok'; ?>" href="<?php echo esc_url( $health_url ); ?>">
					<b style="color:<?php echo $health['crit'] ? 'var(--tisa-danger)' : 'var(--tisa-success)'; ?>"><?php echo esc_html( TSH_View::num( $health['crit'] ) ); ?></b>
					<span><?php esc_html_e( 'مورد بحرانی', 'tisacase-hub' ); ?><br><?php esc_html_e( 'دسترسی، نشت داده', 'tisacase-hub' ); ?></span>
				</a>
				<a class="<?php echo $health['warn'] ? 'is-warn' : 'is-ok'; ?>" href="<?php echo esc_url( $health_url ); ?>">
					<b style="color:<?php echo $health['warn'] ? 'var(--tisa-warning)' : 'var(--tisa-success)'; ?>"><?php echo esc_html( TSH_View::num( $health['warn'] ) ); ?></b>
					<span><?php esc_html_e( 'هشدار', 'tisacase-hub' ); ?><br><?php esc_html_e( 'ZIP، uninstall، HPOS', 'tisacase-hub' ); ?></span>
				</a>
				<a class="is-ok" href="<?php echo esc_url( $health_url ); ?>">
					<b style="color:var(--tisa-primary)"><?php echo esc_html( TSH_View::num( $tool_n ) ); ?></b>
					<span><?php esc_html_e( 'ابزار اختصاصی', 'tisacase-hub' ); ?><br><?php esc_html_e( 'ثبت‌شده در این هاب', 'tisacase-hub' ); ?></span>
				</a>
			</div>
		</div>

		<div class="tisa-hub-card__body">

			<div class="tisa-hub-group" id="tsh-pinned" hidden>
				<h3 class="tisa-hub-group__title"><?php esc_html_e( '📌 سنجاق‌شده', 'tisacase-hub' ); ?>
					<span class="tisa-badge tisa-badge--outline" data-pinned-n></span>
				</h3>
				<div class="tisa-hub-grid" data-tsh-grid="pinned"></div>
			</div>

			<?php foreach ( $groups as $gkey => $group ) : ?>
				<div class="tisa-hub-group" data-group="<?php echo esc_attr( $gkey ); ?>">
					<h3 class="tisa-hub-group__title">
						<?php echo esc_html( $group['label'] ); ?>
						<span class="tisa-badge tisa-badge--outline"><?php echo esc_html( TSH_View::num( count( $group['items'] ) ) ); ?></span>
					</h3>
					<div class="tisa-hub-grid" data-tsh-grid>
						<?php foreach ( $group['items'] as $item ) : ?>
							<?php echo tsh_card( $item, $pins, $settings ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>

			<div class="tisa-empty" id="tsh-empty" hidden>
				<span class="tisa-empty__icon"><?php echo TSH_View::icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				<b class="tisa-h3"><?php esc_html_e( 'ابزاری با این نام پیدا نشد', 'tisacase-hub' ); ?></b>
				<span class="tisa-meta"><?php esc_html_e( 'عبارت دیگری را امتحان کنید — مثلاً «قیمت»، «SKU» یا «رهگیری».', 'tisacase-hub' ); ?></span>
				<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" id="tsh-clear"><?php esc_html_e( 'پاک کردن جستجو', 'tisacase-hub' ); ?></button>
			</div>

			<?php if ( $unreg ) : ?>
				<div class="tisa-note">
					<b class="tisa-h3"><?php esc_html_e( 'این افزونه‌های تیساکیس در هاب ثبت نشده‌اند', 'tisacase-hub' ); ?></b>
					<p class="tisa-meta"><?php esc_html_e( 'یک خط هدر اضافه کنید تا خودشان اینجا ظاهر شوند — بدون تغییر کد:', 'tisacase-hub' ); ?></p>
					<ul class="tisa-check__list">
						<?php foreach ( $unreg as $u ) : ?>
							<li>
								<span><?php echo esc_html( $u['name'] ); ?></span>
								<span class="tisa-code"><?php echo esc_html( $u['dir'] ); ?></span>
								<?php if ( $u['version'] ) : ?>
									<span class="tisa-tiny">v<?php echo esc_html( $u['version'] ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<a class="tisa-btn tisa-btn--link" href="<?php echo esc_url( $settings_url . '#tsh-dev' ); ?>"><?php esc_html_e( 'نمونهٔ خط هدر ↓', 'tisacase-hub' ); ?></a>
				</div>
			<?php endif; ?>
		</div>

		<div class="tisa-hub-card__foot">
			<div class="tisa-sysbar">
				<span><?php esc_html_e( 'ووکامرس', 'tisacase-hub' ); ?> <span class="tisa-num"><?php echo esc_html( $env['wc'] ); ?></span></span><span class="is-sep">·</span>
				<span>PHP <span class="tisa-num"><?php echo esc_html( $env['php'] ); ?></span></span><span class="is-sep">·</span>
				<span>HPOS <b style="color:var(--tisa-primary);font-weight:700"><?php echo esc_html( $env['hpos'] ); ?></b></span><span class="is-sep">·</span>
				<span><?php echo esc_html( $env['theme'] ); ?></span><span class="is-sep">·</span>
				<span><?php echo esc_html( $env['fonts'] ); ?></span><span class="is-sep">·</span>
				<span>
					<?php
					if ( $counts_at ) {
						printf(
							/* translators: %s: time */
							esc_html__( 'آخرین تازه‌سازی شمارش‌ها: %s', 'tisacase-hub' ),
							'<span class="tisa-num">' . esc_html( $counts_at ) . '</span>'
						);
					} else {
						esc_html_e( 'شمارنده‌ها محاسبه نشده‌اند', 'tisacase-hub' );
					}
					?>
				</span>
				<span class="tisa-spacer"></span>
				<span class="tisa-tiny"><?php esc_html_e( 'میان‌بُر:', 'tisacase-hub' ); ?> <span class="tisa-kbd">/</span> <span class="tisa-kbd">↑↓</span> <span class="tisa-kbd">Enter</span> <span class="tisa-kbd">Esc</span></span>
			</div>
		</div>
	</section>
</div>
