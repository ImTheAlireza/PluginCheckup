<?php
/**
 * حذف کامل افزونه.
 *
 * این افزونه هیچ جدول، متا، محصول یا متغیری نمی‌سازد؛ تنها چیزی که می‌تواند
 * باقی بماند یک آپشن تنظیمات است. پیش‌فرض: حفظ می‌شود (اگر دوباره نصب کنید،
 * تنظیمات‌تان همان‌جا هست) — مگر در تب «پیشرفته» خلافش را خواسته باشید.
 *
 * @package TisaCase_Brand_Variations
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$tcbv_settings = get_option( 'tcbv_settings' );

if ( is_array( $tcbv_settings ) && ! empty( $tcbv_settings['advanced']['delete_on_uninstall'] ) ) {
	delete_option( 'tcbv_settings' );
}
