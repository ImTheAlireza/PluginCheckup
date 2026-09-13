<?php
/**
 * کارت افزونه در لانچر — بیرون از قالب، تا صفحهٔ تنظیمات هم بتواند استفاده کند.
 *
 * @package TisaCase_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tsh_card' ) ) {

	/**
	 * رندر یک کارت افزونه.
	 *
	 * @param array $item     آیتم resolve‌شدهٔ registry.
	 * @param array $pins     کلیدهای سنجاق‌شده.
	 * @param array $settings تنظیمات هاب.
	 * @return string HTML.
	 */
	function tsh_card( $item, $pins, $settings ) {
		$state   = isset( $item['state'] ) ? $item['state'] : 'missing';
		$cls     = 'tisa-plugin-card';
		$no_page = ! empty( $item['no_page'] );
		$open    = ! $no_page && ! empty( $item['pages'] ) && in_array( $state, array( 'active', 'system' ), true ) && ! empty( $item['can'] );

		if ( $no_page ) {
			$cls .= ' is-nopage';
		}
		$main  = ! empty( $item['pages'] ) ? $item['pages'][0]['url'] : '';
		// برای ابزاری که صفحهٔ مستقل ندارد، همان لینک را «پیوند» می‌گذاریم نه دکمهٔ «باز کردن».
		$rest = $no_page ? array_slice( (array) $item['pages'], 0 ) : ( $open ? array_slice( (array) $item['pages'], 1 ) : array() );

		if ( in_array( $state, array( 'inactive', 'missing' ), true ) ) {
			$cls .= ' is-inactive';
		}
		if ( 'active' === $state && ! empty( $item['count']['tone'] ) && 'warn' === $item['count']['tone'] ) {
			$cls .= ' is-warn';
		}
		if ( in_array( $item['key'], (array) $pins, true ) ) {
			$cls .= ' is-pinned';
		}

		$search = strtolower(
			wp_strip_all_tags(
				implode(
					' ',
					array(
						$item['title'],
						$item['desc'],
						$item['dir'],
						$item['version'],
						$item['key'],
						isset( $item['group'] ) ? $item['group'] : '',
						isset( $item['name'] ) ? $item['name'] : '',
					)
				)
			)
		);

		ob_start();
		?>
		<article class="<?php echo esc_attr( $cls ); ?>" data-key="<?php echo esc_attr( $item['key'] ); ?>" data-group="<?php echo esc_attr( isset( $item['group'] ) ? $item['group'] : 'products' ); ?>" data-search="<?php echo esc_attr( $search ); ?>" tabindex="-1">
			<button type="button" class="tisa-pin" data-pin="<?php echo esc_attr( $item['key'] ); ?>"
				aria-pressed="<?php echo in_array( $item['key'], (array) $pins, true ) ? 'true' : 'false'; ?>"
				title="<?php esc_attr_e( 'سنجاق کردن در بالای صفحه', 'tisacase-hub' ); ?>">★</button>

			<div class="tisa-plugin-card__top">
				<span class="tisa-plugin-card__icon" aria-hidden="true"><?php echo TSH_View::icon( $item['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				<div>
					<h4 class="tisa-plugin-card__title">
						<?php if ( $open && $main ) : ?>
							<a class="tisa-plugin-card__link" href="<?php echo esc_url( $main ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $item['title'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $item['title'] ); ?>
						<?php endif; ?>
					</h4>
					<span class="tisa-plugin-card__ver">
						<?php
						if ( ! empty( $item['version'] ) ) {
							echo 'v' . esc_html( $item['version'] ) . ' · ';
						}
						echo esc_html( ! empty( $item['dir'] ) ? $item['dir'] : __( 'میان‌بُر سیستمی', 'tisacase-hub' ) );
						?>
					</span>
				</div>
			</div>

			<p class="tisa-plugin-card__desc"><?php echo esc_html( $item['desc'] ); ?></p>

			<?php if ( $no_page && ! empty( $item['note'] ) ) : ?>
				<p class="tisa-hint" style="margin:0"><?php echo esc_html( $item['note'] ); ?></p>
			<?php endif; ?>

			<?php if ( empty( $item['can'] ) ) : ?>
				<p class="tisa-hint" style="color:var(--tisa-warning)"><?php esc_html_e( 'نقش شما به این ابزار دسترسی ندارد.', 'tisacase-hub' ); ?></p>
			<?php endif; ?>

			<div class="tisa-plugin-card__foot">
				<span class="tisa-row-actions">
					<?php if ( 'active' === $state ) : ?>
						<span class="tisa-badge tisa-badge--success"><i class="tisa-dot tisa-dot--on"></i><?php esc_html_e( 'فعال', 'tisacase-hub' ); ?></span>
					<?php elseif ( 'inactive' === $state ) : ?>
						<span class="tisa-badge"><i class="tisa-dot"></i><?php esc_html_e( 'غیرفعال', 'tisacase-hub' ); ?></span>
					<?php elseif ( 'missing' === $state ) : ?>
						<span class="tisa-badge tisa-badge--danger"><i class="tisa-dot tisa-dot--warn"></i><?php esc_html_e( 'نصب نیست', 'tisacase-hub' ); ?></span>
					<?php endif; ?>

					<?php
					if ( ! empty( $settings['show_counts'] ) && ! empty( $item['count'] ) ) :
						$tone = empty( $item['count']['tone'] ) ? '' : $item['count']['tone'];
						?>
						<span class="tisa-badge <?php echo 'warn' === $tone ? 'tisa-badge--warning' : ( 'muted' === $tone ? 'tisa-badge--outline' : 'tisa-badge--primary' ); ?>" data-count="<?php echo esc_attr( $item['key'] ); ?>">
							<span class="tisa-num"><?php echo esc_html( TSH_View::num( $item['count']['value'] ) ); ?></span>
							<?php echo esc_html( $item['count']['label'] ); ?>
						</span>
					<?php endif; ?>

					<?php foreach ( $rest as $page ) : ?>
						<a href="<?php echo esc_url( $page['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $page['label'] ); ?> ↗</a>
					<?php endforeach; ?>
				</span>

				<span class="tisa-row-actions">
					<?php if ( 'inactive' === $state && ! empty( $item['can_manage'] ) ) : ?>
						<a class="tisa-btn tisa-btn--sm tisa-btn--primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tisacase_hub_action&task=activate&item=' . $item['key'] ), 'tsh_action' ) ); ?>"><?php esc_html_e( 'فعال‌سازی', 'tisacase-hub' ); ?></a>
					<?php elseif ( 'active' === $state && ! empty( $item['can_manage'] ) ) : ?>
						<a class="tisa-btn tisa-btn--sm tisa-btn--danger-ghost" onclick="return confirm('<?php echo esc_js( sprintf( /* translators: %s: plugin title */ __( '%s غیرفعال شود؟', 'tisacase-hub' ), $item['title'] ) ); ?>');" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tisacase_hub_action&task=deactivate&item=' . $item['key'] ), 'tsh_action' ) ); ?>"><?php esc_html_e( 'غیرفعال‌سازی', 'tisacase-hub' ); ?></a>
					<?php endif; ?>

					<?php if ( $open && $main ) : ?>
						<a class="tisa-btn tisa-btn--sm tisa-btn--primary" href="<?php echo esc_url( $main ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'باز کردن', 'tisacase-hub' ); ?></a>
					<?php endif; ?>

					<a class="tisa-btn tisa-btn--sm tisa-btn--link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tisacase_hub_action&task=hide&item=' . $item['key'] ), 'tsh_action' ) ); ?>" title="<?php esc_attr_e( 'پنهان کردن این کارت از هاب', 'tisacase-hub' ); ?>">✕</a>
				</span>
			</div>
		</article>
		<?php
		return (string) ob_get_clean();
	}
}
