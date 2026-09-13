<?php
/**
 * Plugin Name: TisaCase Bulk Price Manager
 * Plugin URI:  https://tisacase.com/
 * Description: مدیریت گروهی امن قیمت عادی، فروش ویژه و قیمت عمده محصولات ووکامرس با پیش‌نمایش اجباری، گزارش کامل تغییرات، بازگردانی (Rollback) و خروجی CSV.
 * Version:     2.2.0
 * Author:      علیرضا شعبان زاده
 * License:     GPL-2.0-or-later
 * Text Domain: tisacase-bulk-price-manager
 * Requires PHP: 7.4
 * WC requires at least: 4.6
 * WC tested up to: 8.9
 *
 * @package TisaCase_Bulk_Price_Manager
 */

defined( 'ABSPATH' ) || exit;

define( 'TCBPM_VERSION', '2.2.0' );
define( 'TCBPM_FILE', __FILE__ );
define( 'TCBPM_DIR', plugin_dir_path( __FILE__ ) );
define( 'TCBPM_URL', plugin_dir_url( __FILE__ ) );
define( 'TCBPM_WHOLESALE_META', '_tisacase_wholesale_price' );

require_once TCBPM_DIR . 'includes/class-tcbpm-core.php';
require_once TCBPM_DIR . 'includes/class-tcbpm-db.php';
require_once TCBPM_DIR . 'includes/class-tcbpm-ops.php';
require_once TCBPM_DIR . 'includes/class-tcbpm-admin.php';
require_once TCBPM_DIR . 'includes/class-tcbpm-ajax.php';
require_once TCBPM_DIR . 'includes/class-tcbpm-scheduler.php';

register_activation_hook( __FILE__, array( 'TCBPM_DB', 'activate' ) );

add_action( 'plugins_loaded', array( 'TCBPM_Core', 'instance' ), 20 );
