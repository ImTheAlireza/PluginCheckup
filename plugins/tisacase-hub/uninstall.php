<?php
/**
 * حذف تنظیمات هاب. دادهٔ افزونه‌های دیگر دست نمی‌خورد.
 *
 * @package TisaCase_Hub
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'tisacase_hub_settings' );
delete_transient( 'tsh_health' );
delete_option( 'tsh_counts_at' );

foreach ( array(
	'bdc_drafts',
	'sku_missing',
	'desc_backups',
	'bpm_runs',
	'pm_rules',
	'wcsp_exceptions',
	'tracking_codes',
	'orders_30d',
) as $t ) {
	delete_transient( 'tsh_count_' . $t );
}

$users = get_users( array( 'fields' => 'ID', 'number' => 0 ) );
foreach ( (array) $users as $uid ) {
	delete_user_meta( (int) $uid, 'tisacase_hub_pins' );
}
