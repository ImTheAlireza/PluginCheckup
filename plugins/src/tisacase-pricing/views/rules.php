<?php
/**
 * تب «قوانین داینامیک».
 *
 * @package TisaCase_Pricing
 */

defined( 'ABSPATH' ) || exit;

$tcp_rules = TCP_Rules::settings();

$tcp_rule_row = static function ( $type, $id, $rule ) {
	$id   = absint( $id );
	$rule = TCP_Rules::normalize_rule( $rule );
	if ( 'products' === $type ) {
		$name = get_the_title( $id );
	} else {
		$term = get_term( $id, 'product_cat' );
		$name = ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
	}
	if ( '' === $name ) {
		return;
	}
	$n = esc_attr( $type ) . '[' . $id . ']';
	?>
	<tr data-rule-id="<?php echo esc_attr( $id ); ?>">
		<td class="tcp-rule-name">
			<strong><?php echo esc_html( $name ); ?></strong>
			<small class="tisa-code">#<?php echo esc_html( $id ); ?></small>
			<input type="hidden" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[exists]" value="1">
		</td>
		<td><input class="tisa-input tisa-input--number" type="number" min="0" max="500" step="0.1" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[increase]" value="<?php echo esc_attr( $rule['increase'] ); ?>"></td>
		<td><input class="tisa-input tisa-input--number" type="number" min="0" max="99.9" step="0.1" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[sale]" value="<?php echo esc_attr( $rule['sale'] ); ?>"></td>
		<td class="tcp-rule-enabled">
			<input type="hidden" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[enabled]" value="0">
			<label><input type="checkbox" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[enabled]" value="1" <?php checked( $rule['enabled'], 1 ); ?>> فعال</label>
		</td>
		<td><button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm tcp-remove-rule">حذف</button></td>
	</tr>
	<?php
};
?>

<p class="tcp-lead">قیمت‌ها هنگام نمایش محاسبه می‌شوند و چیزی در دیتابیس نوشته نمی‌شود. محصولی که فروش ویژهٔ واقعی دارد و قیمت همکاری، دست‌نخورده می‌مانند. اولویت: محصول ← دسته‌بندی ← سراسری.</p>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="<?php echo esc_attr( TCP_Rules::ACTION_SAVE ); ?>">
	<?php wp_nonce_field( TCP_Rules::ACTION_SAVE ); ?>

	<section class="tcp-card">
		<div class="tcp-card-head"><span class="tcp-dot"></span><div><h2>قانون سراسری</h2><p>روی همهٔ محصولات فعلی و آینده، مگر آن‌که قانون محصول یا دسته داشته باشند.</p></div></div>
		<div class="tcp-card-body">
			<div class="tcp-grid-3">
				<label class="tisa-switch tcp-switch-line">
					<input type="hidden" name="global[enabled]" value="0">
					<input type="checkbox" name="global[enabled]" value="1" <?php checked( $tcp_rules['global']['enabled'], 1 ); ?>>
					<span class="tisa-switch__track" aria-hidden="true"></span>
					<span>فعال برای همهٔ محصولات</span>
				</label>
				<label>درصد افزایش قیمت اصلی
					<input class="tisa-input tisa-input--number" type="number" min="0" max="500" step="0.1" name="global[increase]" value="<?php echo esc_attr( $tcp_rules['global']['increase'] ); ?>">
				</label>
				<label>درصد فروش ویژه از قیمت افزایش‌یافته
					<input class="tisa-input tisa-input--number" type="number" min="0" max="99.9" step="0.1" name="global[sale]" value="<?php echo esc_attr( $tcp_rules['global']['sale'] ); ?>">
				</label>
			</div>
		</div>
	</section>

	<section class="tcp-card">
		<div class="tcp-card-head"><span class="tcp-dot"></span><div><h2>محصولات تکی</h2><p>محصول متغیر یعنی همهٔ واریشن‌های آن.</p></div></div>
		<div class="tcp-card-body">
			<div class="tcp-search-box">
				<input type="search" class="tisa-input" id="tcp-product-search" placeholder="حداقل ۲ حرف از نام محصول…" autocomplete="off">
				<div id="tcp-product-results" class="tcp-search-results"></div>
			</div>
			<div class="tcp-table-scroll">
				<table class="tisa-table tcp-rules-table">
					<thead><tr><th>محصول</th><th>افزایش ٪</th><th>فروش ویژه ٪</th><th>وضعیت</th><th></th></tr></thead>
					<tbody id="tcp-product-rules">
						<?php foreach ( $tcp_rules['products'] as $id => $rule ) { $tcp_rule_row( 'products', $id, $rule ); } ?>
					</tbody>
				</table>
			</div>
		</div>
	</section>

	<section class="tcp-card">
		<div class="tcp-card-head"><span class="tcp-dot"></span><div><h2>دسته‌بندی‌ها</h2><p>قانون دسته روی خود دسته و همهٔ زیردسته‌هایش اعمال می‌شود.</p></div></div>
		<div class="tcp-card-body">
			<div class="tcp-search-box">
				<input type="search" class="tisa-input" id="tcp-category-search" placeholder="حداقل ۲ حرف از نام دسته‌بندی…" autocomplete="off">
				<div id="tcp-category-results" class="tcp-search-results"></div>
			</div>
			<div class="tcp-table-scroll">
				<table class="tisa-table tcp-rules-table">
					<thead><tr><th>دسته‌بندی</th><th>افزایش ٪</th><th>فروش ویژه ٪</th><th>وضعیت</th><th></th></tr></thead>
					<tbody id="tcp-category-rules">
						<?php foreach ( $tcp_rules['categories'] as $id => $rule ) { $tcp_rule_row( 'categories', $id, $rule ); } ?>
					</tbody>
				</table>
			</div>
		</div>
	</section>

	<div class="tcp-actions">
		<button type="submit" class="tisa-btn tisa-btn--primary tisa-btn--lg">ذخیرهٔ قوانین</button>
	</div>
</form>

<section class="tcp-card tcp-card--dashed">
	<div class="tcp-card-head"><span class="tcp-dot tcp-dot--muted"></span><div><h2>همگام‌سازی سریع کل فروشگاه</h2><p>قانون سراسری را روی <strong>۱۰٪ افزایش</strong> و <strong>۱۰٪ فروش ویژه</strong> فعال می‌کند؛ بدون بازنویسی محصولات، شامل محصولات آینده.</p></div></div>
	<div class="tcp-card-body">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('قانون سراسری ۱۰٪ افزایش + ۱۰٪ فروش ویژه برای همهٔ محصولات بدون فروش ویژهٔ واقعی فعال شود؟');">
			<input type="hidden" name="action" value="<?php echo esc_attr( TCP_Rules::ACTION_SYNC ); ?>">
			<?php wp_nonce_field( TCP_Rules::ACTION_SYNC ); ?>
			<button type="submit" class="tisa-btn tisa-btn--secondary">فعال‌سازی ۱۰٪ + ۱۰٪ سراسری</button>
		</form>
	</div>
</section>
