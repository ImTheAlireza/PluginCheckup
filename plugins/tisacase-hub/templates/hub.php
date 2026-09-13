<?php
/**
 * قالب صفحهٔ لانچر — فقط دیدن و باز کردن.
 *
 * عمداً سبک است: هیچ شمارنده، گزارش سلامت، توضیح افزونه یا یادآوری‌ای در این
 * صفحه نیست. کارت‌ها فقط افزونه‌های اختصاصی‌اند (هیچ صفحهٔ وردپرس به‌عنوان کارت
 * رندر نمی‌شود).
 *
 * متغیرها از TSH_Admin::hub_data() می‌آیند:
 * @var array $groups   گروه‌ها => array('label'=>, 'items'=>array(item))
 * @var array $all      همهٔ آیتم‌های resolve‌شده
 * @var array $pins     کلیدهای سنجاق‌شدهٔ کاربر
 * @var array $settings تنظیمات هاب
 * @var array $hidden    کلیدهای مخفی‌شده (برای همان خطِ «بازگردانی»)
 *
 * @package TisaCase_Hub
 */

defined( 'ABSPATH' ) || exit;

$settings_url = admin_url( 'admin.php?page=' . TSH_SLUG . '-settings' );
$accents = array(
	'#0E7C6B' => __( 'تیساکیس', 'tisacase-hub' ),
	'#1467A8' => __( 'آبی', 'tisacase-hub' ),
	'#B5453A' => __( 'آجر', 'tisacase-hub' ),
	'#6D4AA8' => __( 'بنفش', 'tisacase-hub' ),
	'#1A7F37' => __( 'سبز', 'tisacase-hub' ),
);

$tool_n = count( $all );
?>
<div class="wrap tisa-wrap tisa-hub-wrap" dir="rtl">

	<header class="tisa-hero tisa-hero--slim">
		<span class="tisa-hero-mark" aria-hidden="true"><?php echo TSH_View::icon( 'grid' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
		<div class="tisa-hero-txt">
			<h1 class="tisa-hero-title"><?php esc_html_e( 'اختصاصی تیساکیس', 'tisacase-hub' ); ?></h1>
			<p class="tisa-lead">
				<?php
				printf(
					/* translators: %d: number of tools */
					esc_html__( '%d ابزار اختصاصی — «باز کردن» هر کدام را در تب جدید می‌آورد.', 'tisacase-hub' ),
					(int) $tool_n
				);
				?>
			</p>
		</div>
		<div class="tisa-hero-actions">
			<a class="tisa-btn tisa-btn--sm" href="<?php echo esc_url( $settings_url ); ?>"><?php echo TSH_View::icon( 'gear' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'تنظیمات', 'tisacase-hub' ); ?></a>
		</div>
	</header>

	<section class="tisa-card tisa-hub-card">

		<div class="tisa-hub-card__top">
			<div class="tisa-hub-toolbar" style="margin:0">
				<div class="tisa-hub-search">
					<span class="tisa-hub-search__icon" aria-hidden="true"><?php echo TSH_View::icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					<input type="search" id="tsh-q" class="tisa-input" autocomplete="off" spellcheck="false"
						placeholder="<?php esc_attr_e( 'جستجوی ابزار…', 'tisacase-hub' ); ?>"
						aria-label="<?php esc_attr_e( 'جستجو در ابزارها', 'tisacase-hub' ); ?>"
						title="<?php esc_attr_e( 'با / روی این کادر بپرید، با Esc پاک شود', 'tisacase-hub' ); ?>">
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
				<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" id="tsh-clear"><?php esc_html_e( 'پاک کردن جستجو', 'tisacase-hub' ); ?></button>
			</div>

			<?php if ( ! empty( $hidden ) ) : ?>
				<p class="tisa-tiny tisa-hub-footnote">
					<?php
					printf(
						/* translators: %d: count of hidden cards */
						esc_html__( '%d کارت مخفی است.', 'tisacase-hub' ),
						(int) count( $hidden )
					);
					?>
					<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'بازگردانی', 'tisacase-hub' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
	</section>
</div>
