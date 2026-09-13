<?php
/**
 * قالب صفحهٔ لانچر — فقط دیدن و باز کردن.
 *
 * عمداً سبک است: شمارندهٔ محتوا، گزارش سلامت، توضیح افزونه و یادآوری‌ای اینجا
 * نیست. هر ابزار یک **ردیف** است (نه کارتِ گرید) تا در صفحه‌های عریض سوراخ
 * نیفتد و ردیف آخر ناقص نماند. کنترل‌های سراسری (رنگ برند، چگالی) به صفحهٔ
 * تنظیمات رفته‌اند تا بی‌صدا ذخیره نشوند.
 *
 * متغیرها از TSH_Admin::hub_data() می‌آیند:
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

	<header class="tisa-hero tisa-hero--slim">
		<div class="tisa-hero-row">
			<span class="tisa-hero-mark" aria-hidden="true"><?php echo TSH_View::icon( 'grid' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<div class="tisa-hero-txt">
				<h1 class="tisa-hero-title"><?php esc_html_e( 'اختصاصی تیساکیس', 'tisacase-hub' ); ?></h1>
				<p class="tisa-lead"><?php esc_html_e( '«باز کردن» هر ابزار را در تب جدید می‌آورد.', 'tisacase-hub' ); ?></p>
			</div>
			<div class="tisa-hero-actions">
				<a class="tisa-btn tisa-btn--sm tisa-btn--on-dark" href="<?php echo esc_url( $settings_url ); ?>">
					<span><?php echo TSH_View::icon( 'gear' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'تنظیمات', 'tisacase-hub' ); ?></span>
				</a>
			</div>
		</div>
	</header>

	<section class="tisa-card tisa-hub-card">

		<div class="tisa-hub-card__top">
			<form class="tisa-hub-toolbar" role="search" method="get" onsubmit="return false;" style="margin:0">
				<div class="tisa-hub-search">
					<span class="tisa-hub-search__icon" aria-hidden="true"><?php echo TSH_View::icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					<input type="search" id="tsh-q" class="tisa-input" autocomplete="off" spellcheck="false"
						placeholder="<?php esc_attr_e( 'جستجوی ابزار…', 'tisacase-hub' ); ?>"
						aria-label="<?php esc_attr_e( 'جستجو در ابزارها', 'tisacase-hub' ); ?>">
					<kbd class="tisa-kbd" aria-hidden="true">/</kbd>
				</div>
				<span class="tisa-hub-count" id="tsh-count" aria-live="polite"><?php echo esc_html( $tool_n ); ?> <?php esc_html_e( 'ابزار', 'tisacase-hub' ); ?></span>
			</form>
		</div>

		<div class="tisa-hub-card__body">

			<div class="tisa-hub-group" id="tsh-pinned" hidden role="group" aria-labelledby="tsh-pinned-t">
				<h2 class="tisa-hub-group__title" id="tsh-pinned-t"><?php esc_html_e( '📌 سنجاق‌شده', 'tisacase-hub' ); ?>
					<span class="tisa-badge tisa-badge--outline" data-pinned-n></span>
				</h2>
				<div class="tisa-hub-grid" data-tsh-grid="pinned"></div>
			</div>

			<?php foreach ( $groups as $gkey => $group ) : ?>
				<div class="tisa-hub-group" data-group="<?php echo esc_attr( $gkey ); ?>" role="group" aria-labelledby="tsh-g-<?php echo esc_attr( $gkey ); ?>">
					<h2 class="tisa-hub-group__title" id="tsh-g-<?php echo esc_attr( $gkey ); ?>">
						<?php echo esc_html( $group['label'] ); ?>
						<span class="tisa-badge tisa-badge--outline"><?php echo esc_html( TSH_View::num( count( $group['items'] ) ) ); ?></span>
					</h2>
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
				<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" id="tsh-clear"><?php esc_html_e( 'پاک کردن جستجو', 'tisacase-hub' ); ?></button>
			</div>

			<?php if ( ! empty( $hidden ) ) : ?>
				<p class="tisa-hub-footnote">
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
		</div>
	</section>
</div>
