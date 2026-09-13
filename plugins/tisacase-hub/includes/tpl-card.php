<?php
/**
 * کارت افزونه در لانچر — بیرون از قالب، تا صفحهٔ تنظیمات هم بتواند استفاده کند.
 *
 * هر ابزار یک ردیف است: آیکون · نام · نسخه · وضعیت · اکشن‌ها. کل ردیف (وقتی
 * صفحه‌اش باز شدنی است) کلیک‌پذیر است و یک دکمهٔ «باز کردن» هم دارد؛ کارهای
 * پرخطر (غیرفعال‌سازی) آیکونی‌اند و با تأیید درون‌خطی کار می‌کنند، نه
 * confirm() بومی مرورگر.
 *
 * @package TisaCase_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tsh_card' ) ) {

	/**
	 * رندر یک ردیف افزونه.
	 *
	 * @param array $item     آیتم resolve‌شدهٔ registry.
	 * @param array $pins     کلیدهای سنجاق‌شده.
	 * @param array $settings تنظیمات هاب.
	 * @return string HTML.
	 */
	function tsh_card( $item, $pins, $settings ) {
		$state = isset( $item['state'] ) ? $item['state'] : 'missing';
		$cls   = 'tisa-plugin-card tisa-plugin-card--row';
		$pages = ! empty( $item['pages'] ) ? (array) $item['pages'] : array();
		$main  = $pages ? $pages[0] : array();
		$url   = isset( $main['url'] ) ? $main['url'] : '';
		$tip   = isset( $item['tip'] ) ? (string) $item['tip'] : '';
		$open  = (bool) $url && 'active' === $state && ! empty( $item['can'] );

		if ( in_array( $state, array( 'inactive', 'missing' ), true ) ) {
			$cls .= ' is-inactive';
		}
		if ( $url && empty( $item['can'] ) ) {
			$cls .= ' is-nocap';
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

		$act_url = static function ( $task ) use ( $item ) {
			return wp_nonce_url( admin_url( 'admin-post.php?action=tisacase_hub_action&task=' . $task . '&item=' . $item['key'] ), 'tsh_action' );
		};

		$deactivate_url = $act_url( 'deactivate' );
		$activate_url   = $act_url( 'activate' );
		$hide_url       = $act_url( 'hide' );

		ob_start();
		?>
		<article class="<?php echo esc_attr( $cls ); ?>" data-key="<?php echo esc_attr( $item['key'] ); ?>" data-group="<?php echo esc_attr( isset( $item['group'] ) ? $item['group'] : 'products' ); ?>" data-search="<?php echo esc_attr( $search ); ?>" tabindex="-1">
			<?php if ( $open ) : ?>
				<a class="tisa-plugin-card__go" data-open="1" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"<?php echo $tip ? ' title="' . esc_attr( $tip ) . '"' : ''; ?>>
					<span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: tool title */ __( 'باز کردن «%s» در تب جدید', 'tisacase-hub' ), $item['title'] ) ); ?></span>
				</a>
			<?php endif; ?>

			<span class="tisa-plugin-card__icon" aria-hidden="true"><?php echo TSH_View::icon( $item['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>

			<span class="tisa-plugin-card__txt">
				<b class="tisa-plugin-card__title"><?php echo esc_html( $item['title'] ); ?></b>
				<?php if ( ! empty( $item['version'] ) ) : ?>
					<span class="tisa-plugin-card__ver tisa-code" dir="ltr">v<?php echo esc_html( $item['version'] ); ?></span>
				<?php endif; ?>
			</span>

			<span class="tisa-plugin-card__state">
				<?php if ( 'active' === $state ) : ?>
					<span class="tisa-badge tisa-badge--success"><i class="tisa-dot tisa-dot--on"></i><?php esc_html_e( 'فعال', 'tisacase-hub' ); ?></span>
				<?php elseif ( 'inactive' === $state ) : ?>
					<span class="tisa-badge"><i class="tisa-dot"></i><?php esc_html_e( 'غیرفعال', 'tisacase-hub' ); ?></span>
				<?php else : ?>
					<span class="tisa-badge tisa-badge--danger"><i class="tisa-dot tisa-dot--warn"></i><?php esc_html_e( 'نصب نیست', 'tisacase-hub' ); ?></span>
				<?php endif; ?>
			</span>

			<span class="tisa-row-tools">
				<?php if ( $open ) : ?>
					<a class="tisa-btn tisa-btn--sm" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"<?php echo $tip ? ' title="' . esc_attr( $tip ) . '"' : ''; ?>><?php esc_html_e( 'باز کردن', 'tisacase-hub' ); ?></a>
				<?php elseif ( $url && empty( $item['can'] ) ) : ?>
					<span class="tisa-btn tisa-btn--sm is-disabled" aria-disabled="true"
						title="<?php echo esc_attr( sprintf( /* translators: %s: capability */ __( 'این برگهٔ افزونه با دسترسی «%s» قفل شده است.', 'tisacase-hub' ), isset( $item['cap_name'] ) ? $item['cap_name'] : '' ) ); ?>"><?php esc_html_e( 'باز کردن', 'tisacase-hub' ); ?></span>
				<?php elseif ( 'inactive' === $state && ! empty( $item['can_manage'] ) ) : ?>
					<a class="tisa-btn tisa-btn--sm tisa-btn--primary" href="<?php echo esc_url( $activate_url ); ?>"><?php esc_html_e( 'فعال‌سازی', 'tisacase-hub' ); ?></a>
				<?php endif; ?>

				<?php if ( 'active' === $state && ! empty( $item['can_manage'] ) ) : ?>
					<button type="button" class="tisa-btn tisa-btn--sm tisa-btn--icon" data-confirm="1"
						title="<?php echo esc_attr( sprintf( /* translators: %s: tool title */ __( 'غیرفعال‌کردن «%s»', 'tisacase-hub' ), $item['title'] ) ); ?>">
						<?php echo TSH_View::icon( 'plug' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span class="screen-reader-text"><?php esc_html_e( 'غیرفعال‌سازی', 'tisacase-hub' ); ?></span>
					</button>
				<?php elseif ( 'missing' === $state ) : ?>
					<span class="tisa-badge tisa-badge--outline" title="<?php esc_attr_e( 'پوشهٔ این افزونه روی این سرور نیست', 'tisacase-hub' ); ?>">—</span>
				<?php endif; ?>

				<button type="button" class="tisa-pin" data-pin="<?php echo esc_attr( $item['key'] ); ?>"
					aria-pressed="<?php echo in_array( $item['key'], (array) $pins, true ) ? 'true' : 'false'; ?>"
					title="<?php esc_attr_e( 'سنجاق کردن در بالای صفحه', 'tisacase-hub' ); ?>">★<span class="screen-reader-text"><?php esc_html_e( 'سنجاق', 'tisacase-hub' ); ?></span></button>

				<a class="tisa-btn tisa-btn--sm tisa-btn--icon" href="<?php echo esc_url( $hide_url ); ?>"
					title="<?php esc_attr_e( 'مخفی کردن این ردیف از هاب (از تنظیمات برمی‌گردد)', 'tisacase-hub' ); ?>">✕<span class="screen-reader-text"><?php esc_html_e( 'مخفی کردن', 'tisacase-hub' ); ?></span></a>
			</span>

			<span class="tisa-confirmbar" hidden>
				<b><?php echo esc_html( sprintf( /* translators: %s: tool title */ __( '«%s» غیرفعال شود؟', 'tisacase-hub' ), $item['title'] ) ); ?></b>
				<a class="tisa-btn tisa-btn--sm tisa-btn--danger" href="<?php echo esc_url( $deactivate_url ); ?>"><?php esc_html_e( 'بله، غیرفعال شود', 'tisacase-hub' ); ?></a>
				<button type="button" class="tisa-btn tisa-btn--sm tisa-btn--secondary" data-confirm-no="1"><?php esc_html_e( 'نه', 'tisacase-hub' ); ?></button>
			</span>
		</article>
		<?php
		return (string) ob_get_clean();
	}
}
