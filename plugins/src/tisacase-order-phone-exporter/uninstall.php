<?php
/**
 * پاک‌سازی کامل ردپاهای افزونه هنگام حذف (Uninstall).
 * وردپرس این فایل را به‌صورت مستقل اجرا می‌کند (فایل اصلی افزونه بارگذاری نمی‌شود).
 *
 * @package TisaCase_Order_Phone_Exporter
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-tisacase-plugin.php';
require_once __DIR__ . '/includes/class-tisacase-storage.php';
require_once __DIR__ . '/includes/class-tisacase-lifecycle.php';

TisaCase_Phone_Exporter_Lifecycle::uninstall();
