<?php
/**
 * کارت افزونه در لانچر — بیرون از قالب، تا صفحهٔ تنظیمات هم بتواند استفاده کند.
 *
 * عمداً کم‌حرف است: نام، نسخه، وضعیت، و دکمهٔ باز کردن. توضیح افزونه، شمارنده
 * و لینک‌های فرعی در لانچر رندر نمی‌شوند (عبارت جستجو همچنان توضیح و پوشه را
 * هم می‌خواند، پس چیز گم نمی‌شود).
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
		$state = isset( $item['state'] ) ? $item['state'] : 'missing';
		$cls   = 'tisa-plugin-card';
		$pages = ! empty( $item['pages'] ) ? (array) $item['pages'] : array();
		$main  = $pages ? $pages[0] : array();
		$url   = isset( $main['url'] ) ? $main['url'] : '';

		// صفحهٔ اختصاصی یعنی مسیرش admin.php?page=… یا edit.php?…&page=… است.
		// اگر نبود (مثل نوار SKU که صفحهٔ مستقل ندارد) برچسبِ خودِ صفحه را می‌گذاریم.
		$own_page = $main && isset( $main['path'] ) && false !== strpos( (string) $main['path'], 'page=' );
		$label    = $own_page ? __( 'باز کردن', 'tisacase-hub' ) : ( isset( $main['label'] ) ? $main['label'] : __( 'باز کردن', 'tisacase-hub' ) );
		$open     = (bool) $url && 'active' === $state && ! empty( $item['can'] );

		if ( in_array( $state, array( 'inactive', 'missing' ), true ) ) {
			$cls .= ' is-inactive';
		}
		if ( empty( $item['can'] ) ) {
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

		ob_start();
		?>
		<article class="<?php echo esc_attr( $cls ); ?>" data-key="<?php echo esc_attr( $item['key'] ); ?>" data-group="<?php echo esc_attr( isset( $item['group'] ) ? $item['group'] : 'products' ); ?>" data-search="<?php echo esc_attr( $search ); ?>" tabindex="-1">
			<button type="button" class="tisa-pin" data-pin="<?php echo esc_attr( $item['key'] ); ?>"
				aria-pressed="<?php echo in_array( $item['key'], (array) $pins, true ) ? 'true' : 'false'; ?>"
				title="<?php esc_attr_e( 'سنجاق کردن در بالای صفحه', 'tisacase-hub' ); ?>">★</button>

			<div class="tisa-plugin-card__top">
				<span class="tisa-plugin-card__icon" aria-hidden="true"><?php echo TSH_View::icon( $item['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				<div class="tisa-plugin-card__txt">
					<h4 class="tisa-plugin-card__title">
						<?php if ( $open ) : ?>
							<a class="tisa-plugin-card__link" data-open="1" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $item['title'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $item['title'] ); ?>
						<?php endif; ?>
					</h4>
					<?php if ( ! empty( $item['version'] ) ) : ?>
						<span class="tisa-plugin-card__ver tisa-num">v<?php echo esc_html( $item['version'] ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<div class="tisa-plugin-card__foot">
				<span class="tisa-row-actions">
					<?php if ( 'active' === $state ) : ?>
						<span class="tisa-badge tisa-badge--success"><i class="tisa-dot tisa-dot--on"></i><?php esc_html_e( 'فعال', 'tisacase-hub' ); ?></span>
					<?php elseif ( 'inactive' === $state ) : ?>
						<span class="tisa-badge"><i class="tisa-dot"></i><?php esc_html_e( 'غیرفعال', 'tisacase-hub' ); ?></span>
					<?php else : ?>
						<span class="tisa-badge tisa-badge--danger"><i class="tisa-dot tisa-dot--warn"></i><?php esc_html_e( 'نصب نیست', 'tisacase-hub' ); ?></span>
					<?php endif; ?>
				</span>

				<span class="tisa-row-actions">
					<?php if ( 'inactive' === $state && ! empty( $item['can_manage'] ) ) : ?>
						<a class="tisa-btn tisa-btn--sm tisa-btn--primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tisacase_hub_action&task=activate&item=' . $item['key'] ), 'tsh_action' ) ); ?>"><?php esc_html_e( 'فعال‌سازی', 'tisacase-hub' ); ?></a>
					<?php elseif ( 'active' === $state && ! empty( $item['can_manage'] ) ) : ?>
						<a class="tisa-btn tisa-btn--sm tisa-btn--danger-ghost" onclick="return confirm('<?php echo esc_js( sprintf( /* translators: %s: plugin title */ __( '%s غیرفعال شود؟', 'tisacase-hub' ), $item['title'] ) ); ?>');" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tisacase_hub_action&task=deactivate&item=' . $item['key'] ), 'tsh_action' ) ); ?>"><?php esc_html_e( 'غیرفعال‌سازی', 'tisacase-hub' ); ?></a>
					<?php endif; ?>

					<?php if ( $open ) : ?>
						<a class="tisa-btn tisa-btn--sm tisa-btn--primary" data-open="1" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $label ); ?></a>
					<?php elseif ( $url && empty( $item['can'] ) ) : ?>
						<span class="tisa-btn tisa-btn--sm is-disabled" aria-disabled="true"
							title="<?php echo esc_attr( sprintf( /* translators: %s: capability name */ __( 'خودِ افزونه این صفحه را با دسترسی «%s» قفل کرده است.', 'tisacase-hub' ), isset( $item['cap_name'] ) ? $item['cap_name'] : '' ) ); ?>">
							<?php echo esc_html( $label ); ?>
						</span>
					<?php endif; ?>

					<a class="tisa-btn tisa-btn--sm tisa-btn--link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tisacase_hub_action&task=hide&item=' . $item['key'] ), 'tsh_action' ) ); ?>" title="<?php esc_attr_e( 'پنهان کردن این کارت از هاب', 'tisacase-hub' ); ?>">✕</a>
				</span>
			</div>
		</article>
		<?php
		return (string) ob_get_clean();
	}
}
