<?php
/**
 * قالب «سلامت افزونه‌ها» — گزارش بررسی ایستای فایل‌ها.
 *
 * @var array $checks   ردیف‌های گزارش
 * @var array $summary   خلاصه {crit,warn,info,ok,total}
 * @var array $env       اطلاعات محیط
 * @var array $settings  تنظیمات
 *
 * @package TisaCase_Hub
 */

defined( 'ABSPATH' ) || exit;

$icons = array(
	'crit' => 'warning',
	'warn' => 'warning',
	'info' => 'search',
	'ok'   => 'shield',
);
$labels = array(
	'crit' => __( 'بحرانی', 'tisacase-hub' ),
	'warn' => __( 'هشدار', 'tisacase-hub' ),
	'info' => __( 'راهنما', 'tisacase-hub' ),
	'ok'   => __( 'سالم', 'tisacase-hub' ),
);
$badges = array(
	'crit' => 'tisa-badge--danger',
	'warn' => 'tisa-badge--warning',
	'info' => 'tisa-badge--info',
	'ok'   => 'tisa-badge--success',
);
?>
<div class="wrap tisa-wrap tisa-hub-wrap" dir="rtl">

	<header class="tisa-hero">
		<div class="tisa-hero-row">
			<span class="tisa-hero-mark" aria-hidden="true"><?php echo TSH_View::icon( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<div>
				<h1 class="tisa-hero-title"><?php esc_html_e( 'سلامت افزونه‌های اختصاصی', 'tisacase-hub' ); ?></h1>
				<p class="tisa-lead"><?php esc_html_e( 'فایل‌ها خوانده و الگوها بررسی می‌شوند: دسترسی‌های باز، مهار ZIP، uninstall، سازگاری HPOS، قلم CDN و فاصله‌گرفتن از توکن‌های طراحی. چیزی تغییر نمی‌کند و هیچ داده‌ای از سایت بیرون نمی‌رود.', 'tisacase-hub' ); ?></p>
			</div>
		</div>
		<div class="tisa-hero-actions">
			<a class="tisa-btn tisa-btn--sm" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tisacase_hub_action&task=health' ), 'tsh_action' ) ); ?>">
				<?php echo TSH_View::icon( 'refresh' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php esc_html_e( 'بازبینی دوباره', 'tisacase-hub' ); ?></span>
			</a>
			<a class="tisa-btn tisa-btn--sm" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TSH_SLUG ) ); ?>"><?php esc_html_e( '← ابزارها', 'tisacase-hub' ); ?></a>
		</div>
	</header>

	<section class="tisa-card">
		<div class="tisa-stats">
			<div class="tisa-stat<?php echo $summary['crit'] ? ' tisa-stat--danger' : ''; ?>">
				<span class="tisa-stat__num"><?php echo esc_html( TSH_View::num( $summary['crit'] ) ); ?></span>
				<span class="tisa-stat__lbl"><?php esc_html_e( 'مورد بحرانی', 'tisacase-hub' ); ?></span>
			</div>
			<div class="tisa-stat">
				<span class="tisa-stat__num"><?php echo esc_html( TSH_View::num( $summary['warn'] ) ); ?></span>
				<span class="tisa-stat__lbl"><?php esc_html_e( 'هشدار', 'tisacase-hub' ); ?></span>
			</div>
			<div class="tisa-stat">
				<span class="tisa-stat__num"><?php echo esc_html( TSH_View::num( $summary['info'] ) ); ?></span>
				<span class="tisa-stat__lbl"><?php esc_html_e( 'راهنمای یکدستی', 'tisacase-hub' ); ?></span>
			</div>
			<div class="tisa-stat">
				<span class="tisa-stat__num"><?php echo esc_html( TSH_View::num( $summary['ok'] ) ); ?></span>
				<span class="tisa-stat__lbl"><?php esc_html_e( 'بررسی سالم', 'tisacase-hub' ); ?></span>
			</div>
		</div>
		<p class="tisa-hint" style="margin-top:var(--tisa-sp-4)">
			<?php esc_html_e( 'این گزارش «بررسی ایستا» است: نام متغیرها و ساختار فایل‌ها را می‌خواند، نه رفتار زمان اجرا. قبل از هر اصلاحی، خودِ خط را ببینید.', 'tisacase-hub' ); ?>
		</p>
	</section>

	<section class="tisa-card tisa-card--flush" style="padding:0">
		<?php foreach ( $checks as $check ) : ?>
			<?php
			$level = $check['level'];
			$hits  = isset( $check['hits'] ) ? (array) $check['hits'] : array();
			$shown = array_slice( $hits, 0, 6 );
			?>
			<div class="tisa-check is-<?php echo esc_attr( $level ); ?>">
				<span class="tisa-check__mark" aria-hidden="true"><?php echo TSH_View::icon( isset( $icons[ $level ] ) ? $icons[ $level ] : 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				<div class="tisa-check__body">
					<h3 class="tisa-check__title"><?php echo esc_html( $check['title'] ); ?></h3>
					<p class="tisa-meta" style="margin:0"><?php echo esc_html( $check['detail'] ); ?></p>

					<?php if ( $shown ) : ?>
						<ul class="tisa-check__list">
							<?php foreach ( $shown as $hit ) : ?>
								<li>
									<span class="tisa-badge <?php echo 'crit' === $level ? 'tisa-badge--danger' : 'tisa-badge--outline'; ?>"><?php echo esc_html( TSH_Registry::title_for_dir( $hit['plugin'] ) ); ?></span>
									<span class="tisa-code"><?php echo esc_html( $hit['plugin'] . '/' . $hit['file'] . ( ! empty( $hit['line'] ) ? ':' . $hit['line'] : '' ) ); ?></span>
									<span class="tisa-tiny"><?php echo esc_html( $hit['text'] ); ?></span>
								</li>
							<?php endforeach; ?>
							<?php if ( count( $hits ) > count( $shown ) ) : ?>
								<li><span class="tisa-tiny">+ <?php echo esc_html( TSH_View::num( count( $hits ) - count( $shown ) ) ); ?> <?php esc_html_e( 'مورد دیگر', 'tisacase-hub' ); ?></span></li>
							<?php endif; ?>
						</ul>
					<?php endif; ?>

					<?php if ( $hits && ! empty( $check['fix'] ) ) : ?>
						<p class="tisa-check__fix"><b><?php esc_html_e( 'اصلاح:', 'tisacase-hub' ); ?></b> <?php echo esc_html( $check['fix'] ); ?></p>
					<?php endif; ?>
				</div>
				<div>
					<span class="tisa-badge <?php echo isset( $badges[ $level ] ) ? $badges[ $level ] : ''; ?>">
						<?php echo isset( $labels[ $level ] ) ? esc_html( $labels[ $level ] ) : ''; ?>
						<?php if ( $hits ) : ?>
							<span class="tisa-num"><?php echo esc_html( TSH_View::num( count( $hits ) ) ); ?></span>
						<?php endif; ?>
					</span>
				</div>
			</div>
		<?php endforeach; ?>
	</section>

	<section class="tisa-card">
		<h2 class="tisa-h2"><?php esc_html_e( 'محیط', 'tisacase-hub' ); ?></h2>
		<div class="tisa-sysbar" style="border:0;padding:0;margin:0">
			<span>WordPress <span class="tisa-num"><?php echo esc_html( $env['wp'] ); ?></span></span><span class="is-sep">·</span>
			<span>PHP <span class="tisa-num"><?php echo esc_html( $env['php'] ); ?></span></span><span class="is-sep">·</span>
			<span>WooCommerce <span class="tisa-num"><?php echo esc_html( $env['wc'] ); ?></span></span><span class="is-sep">·</span>
			<span>HPOS <span><?php echo esc_html( $env['hpos'] ); ?></span></span><span class="is-sep">·</span>
			<span><?php echo esc_html( $env['locale'] ); ?> / <?php echo esc_html( $env['rtl'] ); ?></span><span class="is-sep">·</span>
			<span><?php esc_html_e( 'حافظهٔ مصرفی', 'tisacase-hub' ); ?> <span class="tisa-num"><?php echo esc_html( $env['memory'] ); ?></span></span><span class="is-sep">·</span>
			<span><?php echo esc_html( $env['fonts'] ); ?></span>
		</div>
		<p class="tisa-hint" style="margin-top:var(--tisa-sp-4)">
			<?php esc_html_e( 'اگر هاب را غیرفعال کنید، استایل این صفحه و لانچر می‌رود ولی صفحهٔ هیچ افزونه‌ای خراب نمی‌شود — افزونه‌ها CSS خودشان را دارند و توکن‌ها با fallback نوشته شده‌اند.', 'tisacase-hub' ); ?>
		</p>
	</section>
</div>
