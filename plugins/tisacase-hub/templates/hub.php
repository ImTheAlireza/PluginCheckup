<?php
/**
 * قالب صفحهٔ لانچر — نسخهٔ ۲ (v1.4): بدون پنل داخل پنل.
 *
 * ساختار: سرِ سبز (عنوان + جستجو داخل خودش) → گروه‌ها با خط‌عنوان ظریف →
 * کارت‌های تخت. کل کادر با max-width و margin auto در مرکز است.
 *
 * @var array $groups   گروه‌ها => array('label'=>, 'items'=>array(item))
 * @var array $all      همهٔ آیتم‌های resolve‌شده
 * @var array $pins     کلیدهای سنجاق‌شدهٔ کاربر
 * @var array $settings تنظیمات هاب
 * @var array $hidden   کلیدهای مخفی‌شده
 *
 * @package TisaCase_Hub
 */

defined( 'ABSPATH' ) || exit;

$settings_url = admin_url( 'admin.php?page=' . TSH_SLUG . '-settings' );
$tool_n       = TSH_View::num( count( $all ) );
?>
<div class="wrap tisa-wrap tisa-hub-wrap" dir="rtl">

	<header class="tisa-hero tisa-hero--slim tsh-hero">
		<div class="tsh-hero__row">
			<span class="tisa-hero-mark" aria-hidden="true"><?php echo TSH_View::icon( 'grid' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<div class="tisa-hero-txt">
				<h1 class="tisa-hero-title"><?php esc_html_e( 'اختصاصی تیساکیس', 'tisacase-hub' ); ?></h1>
				<p class="tisa-lead"><?php echo esc_html( $tool_n ); ?> <?php esc_html_e( 'ابزار · هر کدام در تب جدید باز می‌شود', 'tisacase-hub' ); ?></p>
			</div>
			<div class="tisa-hero-actions">
				<?php
				$repo    = class_exists( 'TSH_Remote' ) ? TSH_Remote::repo() : '';
				$branch  = class_exists( 'TSH_Remote' ) ? TSH_Remote::branch() : '';
				$gh_link = $repo ? ( 'https://github.com/' . $repo . ( $branch && 'main' !== $branch ? '/tree/' . $branch : '' ) ) : '';
				?>
				<button type="button" class="tisa-btn tisa-btn--sm tisa-btn--on-dark" id="tsh-connect" data-url="<?php echo esc_attr( $gh_link ); ?>">
					<span><?php esc_html_e( 'اتصال مخزن', 'tisacase-hub' ); ?></span>
				</button>
				<button type="button" class="tisa-btn tisa-btn--sm tisa-btn--on-dark" id="tsh-sync">
					<span><?php esc_html_e( 'همگام‌سازی', 'tisacase-hub' ); ?></span>
				</button>
				<a class="tisa-btn tisa-btn--sm tisa-btn--on-dark" href="<?php echo esc_url( $settings_url ); ?>">
					<span><?php echo TSH_View::icon( 'gear' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'تنظیمات', 'tisacase-hub' ); ?></span>
				</a>
			</div>
		</div>

		<form class="tsh-search tisa-hub-toolbar" role="search" method="get" onsubmit="return false;">
			<span class="tsh-search__icon" aria-hidden="true"><?php echo TSH_View::icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<input type="search" id="tsh-q" class="tsh-search__input" autocomplete="off" spellcheck="false"
				placeholder="<?php esc_attr_e( 'جستجوی ابزار…', 'tisacase-hub' ); ?>"
				aria-label="<?php esc_attr_e( 'جستجو در ابزارها', 'tisacase-hub' ); ?>">
			<kbd class="tisa-kbd" aria-hidden="true">/</kbd>
			<span class="screen-reader-text" id="tsh-count" aria-live="polite"><?php echo esc_html( $tool_n ); ?> <?php esc_html_e( 'ابزار', 'tisacase-hub' ); ?></span>
		</form>
	</header>

	<main class="tsh-body">

		<section class="tsh-group tisa-hub-group" id="tsh-pinned" hidden role="group" aria-labelledby="tsh-pinned-t">
			<h2 class="tsh-group__title" id="tsh-pinned-t">
				<span class="tsh-group__label"><?php esc_html_e( 'سنجاق‌شده', 'tisacase-hub' ); ?></span>
				<span class="tsh-group__n" data-pinned-n></span>
			</h2>
			<div class="tisa-hub-grid tsh-grid" data-tsh-grid="pinned"></div>
		</section>

		<?php foreach ( $groups as $gkey => $group ) : ?>
			<section class="tsh-group tisa-hub-group" data-group="<?php echo esc_attr( $gkey ); ?>" role="group" aria-labelledby="tsh-g-<?php echo esc_attr( $gkey ); ?>">
				<h2 class="tsh-group__title" id="tsh-g-<?php echo esc_attr( $gkey ); ?>">
					<span class="tsh-group__label"><?php echo esc_html( $group['label'] ); ?></span>
					<span class="tsh-group__n"><?php echo esc_html( TSH_View::num( count( $group['items'] ) ) ); ?></span>
				</h2>
				<div class="tisa-hub-grid tsh-grid" data-tsh-grid>
					<?php foreach ( $group['items'] as $item ) : ?>
						<?php echo tsh_card( $item, $pins, $settings ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endforeach; ?>

		<div class="tsh-empty" id="tsh-empty" hidden>
			<span class="tsh-empty__icon" aria-hidden="true"><?php echo TSH_View::icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<b><?php esc_html_e( 'ابزاری با این نام پیدا نشد', 'tisacase-hub' ); ?></b>
			<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" id="tsh-clear"><?php esc_html_e( 'پاک کردن جستجو', 'tisacase-hub' ); ?></button>
		</div>

		<?php if ( ! empty( $hidden ) ) : ?>
			<p class="tsh-foot">
				<?php
				printf(
					/* translators: %d: count of hidden tools */
					esc_html__( '%d ابزار مخفی است.', 'tisacase-hub' ),
					(int) count( $hidden )
				);
				?>
				<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'بازگردانی', 'tisacase-hub' ); ?></a>
			</p>
		<?php endif; ?>
	</main>
</div>

<div class="tsh-modal" id="tsh-repo-modal" hidden>
	<div class="tsh-modal__back" data-close="1"></div>
	<div class="tsh-modal__box" role="dialog" aria-modal="true" aria-labelledby="tsh-repo-title">
		<h2 class="tsh-modal__title" id="tsh-repo-title"><?php esc_html_e( 'اتصال مخزن', 'tisacase-hub' ); ?></h2>
		<p class="tisa-meta"><?php esc_html_e( 'لینک مخزن گیت‌هاب را بچسبانید. اگر برنچ خاصی است، همان لینک برنچ را بگذارید.', 'tisacase-hub' ); ?></p>
		<label class="screen-reader-text" for="tsh-repo-url"><?php esc_html_e( 'لینک مخزن', 'tisacase-hub' ); ?></label>
		<input type="url" id="tsh-repo-url" class="tisa-input tisa-input--code" dir="ltr" placeholder="https://github.com/owner/repo">
		<p class="tsh-modal__status" id="tsh-repo-status" hidden></p>
		<div class="tsh-modal__actions">
			<button type="button" class="tisa-btn tisa-btn--secondary" id="tsh-repo-test"><?php esc_html_e( 'تست اتصال', 'tisacase-hub' ); ?></button>
			<button type="button" class="tisa-btn tisa-btn--primary" id="tsh-repo-save"><?php esc_html_e( 'اتصال', 'tisacase-hub' ); ?></button>
			<button type="button" class="tisa-btn tisa-btn--ghost" data-close="1"><?php esc_html_e( 'بستن', 'tisacase-hub' ); ?></button>
		</div>
	</div>
</div>
