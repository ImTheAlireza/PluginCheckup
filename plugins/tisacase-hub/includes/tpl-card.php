<?php
/**
 * کارت افزونه در لانچر.
 *
 * مهندسی رنگ کارت: در هر کارت فقط **یک** عنصر پررنگ هست — دکمهٔ «باز کردن».
 * بقیه (وضعیت، نسخه، سنجاق، مخفی‌کردن، غیرفعال‌سازی) خاکستریِ آرام‌اند و تنها
 * با hover رنگ می‌گیرند. تأیید غیرفعال‌سازی پیش‌فرض بسته است و فقط وقتی کاربر
 * خودش ⏻ را بزند، جای ردیف اکشن را می‌گیرد (نه confirm() بومی).
 *
 * @package TisaCase_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tsh_card' ) ) {

	/**
	 * رندر یک کارت.
	 *
	 * @param array $item     آیتم resolve‌شدهٔ registry.
	 * @param array $pins     کلیدهای سنجاق‌شده.
	 * @param array $settings تنظیمات هاب.
	 * @return string HTML.
	 */
	function tsh_card( $item, $pins, $settings ) {
		$state  = isset( $item['state'] ) ? $item['state'] : 'missing';
		$cls    = 'tisa-plugin-card tisa-hub-tile';
		$pages  = ! empty( $item['pages'] ) ? (array) $item['pages'] : array();
		$main   = $pages ? $pages[0] : array();
		$url    = isset( $main['url'] ) ? $main['url'] : '';
		$tip    = isset( $item['tip'] ) ? (string) $item['tip'] : '';
		$open   = (bool) $url && 'active' === $state && ! empty( $item['can'] );
		$pinned = in_array( $item['key'], (array) $pins, true );

		if ( in_array( $state, array( 'inactive', 'missing' ), true ) ) {
			$cls .= ' is-inactive';
		}
		if ( $url && empty( $item['can'] ) ) {
			$cls .= ' is-nocap';
		}
		if ( $pinned ) {
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

		ob_start();
		?>
		<article class="<?php echo esc_attr( $cls ); ?>" data-key="<?php echo esc_attr( $item['key'] ); ?>" data-group="<?php echo esc_attr( isset( $item['group'] ) ? $item['group'] : 'products' ); ?>" data-search="<?php echo esc_attr( $search ); ?>" tabindex="-1">

			<div class="tisa-hub-tile__head">
				<span class="tisa-plugin-card__icon" aria-hidden="true"><?php echo TSH_View::icon( $item['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>

				<span class="tisa-hub-tile__quiet">
					<button type="button" class="tisa-pin" data-pin="<?php echo esc_attr( $item['key'] ); ?>"
						aria-pressed="<?php echo $pinned ? 'true' : 'false'; ?>"
						title="<?php esc_attr_e( 'سنجاق در بالای صفحه', 'tisacase-hub' ); ?>">★<span class="screen-reader-text"><?php esc_html_e( 'سنجاق', 'tisacase-hub' ); ?></span></button>
					<a class="tisa-quiet-btn" href="<?php echo esc_url( $act_url( 'hide' ) ); ?>"
						title="<?php esc_attr_e( 'مخفی کردن از هاب (از تنظیمات برمی‌گردد)', 'tisacase-hub' ); ?>">✕<span class="screen-reader-text"><?php esc_html_e( 'مخفی کردن', 'tisacase-hub' ); ?></span></a>
				</span>
			</div>

			<div class="tisa-hub-tile__body">
				<?php if ( $open ) : ?>
					<a class="tisa-plugin-card__title tisa-hub-tile__go" data-open="1" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"<?php echo $tip ? ' title="' . esc_attr( $tip ) . '"' : ''; ?>><?php echo esc_html( $item['title'] ); ?></a>
				<?php else : ?>
					<b class="tisa-plugin-card__title"><?php echo esc_html( $item['title'] ); ?></b>
				<?php endif; ?>

				<span class="tisa-hub-tile__meta">
					<?php if ( ! empty( $item['version'] ) ) : ?>
						<span class="tisa-hub-tile__ver tisa-code" dir="ltr">v<?php echo esc_html( $item['version'] ); ?></span>
					<?php endif; ?>
					<?php if ( 'active' === $state ) : ?>
						<span class="tisa-hub-tile__state is-on"><i class="tisa-dot tisa-dot--on"></i><?php esc_html_e( 'فعال', 'tisacase-hub' ); ?></span>
					<?php elseif ( 'inactive' === $state ) : ?>
						<span class="tisa-hub-tile__state"><i class="tisa-dot"></i><?php esc_html_e( 'غیرفعال', 'tisacase-hub' ); ?></span>
					<?php else : ?>
						<span class="tisa-hub-tile__state is-warn"><i class="tisa-dot tisa-dot--warn"></i><?php esc_html_e( 'نصب نیست', 'tisacase-hub' ); ?></span>
					<?php endif; ?>
				</span>
			</div>

			<div class="tisa-hub-tile__foot">
				<?php if ( $open ) : ?>
					<a class="tisa-btn tisa-btn--primary tisa-btn--sm" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"<?php echo $tip ? ' title="' . esc_attr( $tip ) . '"' : ''; ?>><?php esc_html_e( 'باز کردن', 'tisacase-hub' ); ?></a>
				<?php elseif ( $url && empty( $item['can'] ) ) : ?>
					<span class="tisa-btn tisa-btn--sm is-disabled" aria-disabled="true"
						title="<?php echo esc_attr( sprintf( /* translators: %s: capability */ __( 'این برگهٔ افزونه با دسترسی «%s» قفل شده است.', 'tisacase-hub' ), isset( $item['cap_name'] ) ? $item['cap_name'] : '' ) ); ?>"><?php esc_html_e( 'باز کردن', 'tisacase-hub' ); ?></span>
				<?php elseif ( 'inactive' === $state && ! empty( $item['can_manage'] ) ) : ?>
					<a class="tisa-btn tisa-btn--secondary tisa-btn--sm" href="<?php echo esc_url( $act_url( 'activate' ) ); ?>"><?php esc_html_e( 'فعال‌سازی', 'tisacase-hub' ); ?></a>
				<?php else : ?>
					<span class="tisa-hub-tile__na"><?php esc_html_e( 'پوشهٔ افزونه روی این سرور نیست', 'tisacase-hub' ); ?></span>
				<?php endif; ?>

				<?php if ( 'missing' !== $state && ! empty( $item['can_update'] ) ) : ?>
					<form class="tisa-hub-tile__upd" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-upd="1">
						<input type="hidden" name="action" value="tisacase_hub_update">
						<input type="hidden" name="item" value="<?php echo esc_attr( $item['key'] ); ?>">
						<?php wp_nonce_field( 'tsh_update_' . $item['key'], '_tshnonce' ); ?>
						<label class="tisa-quiet-btn" title="<?php echo esc_attr( sprintf( /* translators: %s: tool title */ __( 'به‌روزرسانی «%s» با فایل زیپ', 'tisacase-hub' ), $item['title'] ) ); ?>">
							<?php echo TSH_View::icon( 'upload' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span class="screen-reader-text"><?php esc_html_e( 'به‌روزرسانی', 'tisacase-hub' ); ?></span>
							<input type="file" name="tsh_zip" accept=".zip,application/zip">
						</label>
						<noscript><button type="submit" class="tisa-btn tisa-btn--sm tisa-btn--ghost"><?php esc_html_e( 'نصب', 'tisacase-hub' ); ?></button></noscript>
					</form>
				<?php endif; ?>

				<?php if ( 'active' === $state && ! empty( $item['can_manage'] ) ) : ?>
					<button type="button" class="tisa-quiet-btn tisa-hub-tile__off" data-confirm="1"
						title="<?php echo esc_attr( sprintf( /* translators: %s: tool title */ __( 'غیرفعال‌کردن «%s»', 'tisacase-hub' ), $item['title'] ) ); ?>">
						<?php echo TSH_View::icon( 'plug' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span class="screen-reader-text"><?php esc_html_e( 'غیرفعال‌سازی', 'tisacase-hub' ); ?></span>
					</button>
				<?php endif; ?>
			</div>

			<?php if ( 'active' === $state && ! empty( $item['can_manage'] ) ) : ?>
				<div class="tisa-confirmbar" hidden>
					<span><?php esc_html_e( 'غیرفعال شود؟', 'tisacase-hub' ); ?></span>
					<a class="tisa-btn tisa-btn--sm tisa-btn--danger-soft" href="<?php echo esc_url( $act_url( 'deactivate' ) ); ?>"><?php esc_html_e( 'بله', 'tisacase-hub' ); ?></a>
					<button type="button" class="tisa-btn tisa-btn--sm tisa-btn--ghost" data-confirm-no="1"><?php esc_html_e( 'نه', 'tisacase-hub' ); ?></button>
				</div>
			<?php endif; ?>
		</article>
		<?php
		return (string) ob_get_clean();
	}
}
