<?php
/**
 * Plugin Name: TisaCase Order Phone Exporter
 * Description: خروجی شماره موبایل سفارش‌های ووکامرس در فایل‌های Excel ده‌هزارتایی، بدون هدر و با فرمت 989xxxxxxxxx، با حذف شماره‌های تکراری، پردازش سبکِ Batch و پاک‌سازی خودکار فایل‌های موقت.
 * Version: 1.4.0
 * Author: علیرضا شعبان زاده
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Requires Plugins: woocommerce
 * Text Domain: tisacase-order-phone-exporter
 * License: GPL-2.0-or-later
 *
 * @package TisaCase_Order_Phone_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TISA_PHONE_EXPORTER_FILE', __FILE__ );
define( 'TISA_PHONE_EXPORTER_DIR', plugin_dir_path( __FILE__ ) );

/*
 * بارگذاری فقط در زمینه‌های لازم: پنل مدیریت (شامل admin-ajax و admin-post)، کرون و WP-CLI.
 * (پاک‌سازی هنگام حذف افزونه به‌صورت مستقل از طریق uninstall.php انجام می‌شود.)
 * در بازدیدهای عادی سایت، اجرای افزونه به همین چند خط خلاصه می‌شود؛ هیچ کلاس، هوک یا
 * کوئری‌ای روی سرعت فرانت‌اند اثر نمی‌گذارد.
 */
if ( ! ( is_admin()
	|| ( defined( 'DOING_CRON' ) && DOING_CRON )
	|| defined( 'WP_CLI' ) ) ) {
	return;
}

require_once TISA_PHONE_EXPORTER_DIR . 'includes/class-tisacase-plugin.php';
require_once TISA_PHONE_EXPORTER_DIR . 'includes/class-tisacase-phone.php';
require_once TISA_PHONE_EXPORTER_DIR . 'includes/class-tisacase-queries.php';
require_once TISA_PHONE_EXPORTER_DIR . 'includes/class-tisacase-storage.php';
require_once TISA_PHONE_EXPORTER_DIR . 'includes/class-tisacase-session.php';
require_once TISA_PHONE_EXPORTER_DIR . 'includes/class-tisacase-pipeline.php';
require_once TISA_PHONE_EXPORTER_DIR . 'includes/class-tisacase-ajax.php';
require_once TISA_PHONE_EXPORTER_DIR . 'includes/class-tisacase-download.php';
require_once TISA_PHONE_EXPORTER_DIR . 'includes/class-tisacase-admin-page.php';
require_once TISA_PHONE_EXPORTER_DIR . 'includes/class-tisacase-cli.php';
require_once TISA_PHONE_EXPORTER_DIR . 'includes/class-tisacase-lifecycle.php';

TisaCase_Phone_Exporter::init();

register_activation_hook( __FILE__, array( 'TisaCase_Phone_Exporter_Lifecycle', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TisaCase_Phone_Exporter_Lifecycle', 'deactivate' ) );
// پاک‌سازی هنگام Uninstall از طریق uninstall.php (استاندارد وردپرس) انجام می‌شود.

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
	\WP_CLI::add_command( 'tisacase export-phones', array( 'TisaCase_Phone_Exporter_Cli', 'cli_export' ) );
}
