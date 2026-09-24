<?php
/**
 * حذف تنظیمات هاب. دادهٔ افزونه‌های دیگر دست نمی‌خورد.
 *
 * @package TisaCase_Hub
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'tisacase_hub_settings' );
delete_option( 'tisacase_hub_catalog' );
delete_transient( 'tsh_screen_map' );

$users = get_users( array( 'fields' => 'ID', 'number' => 0 ) );
foreach ( (array) $users as $uid ) {
	delete_user_meta( (int) $uid, 'tisacase_hub_pins' );
}
