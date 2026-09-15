<?php
/**
 * Plugin Name:       TisaCase — مدیریت گروهی متغیرها و مدل‌ها
 * Plugin URI:        https://tisacase.com
 * Description:       افزونه پیشرفته مدیریت، افزودن، حذف، تغییر نام و همگام‌سازی انبوه متغیرها و مدل‌های گوشی برای قاب‌های اسپیس، چاپی و محصولات متغیر ووکامرس.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 5.0
 * Author:            علیرضا شعبان زاده
 * Author URI:        https://tisacase.com
 * Text Domain:       tisacase-bvm
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package TisaCase_Bulk_Variation_Manager
 */

defined( 'ABSPATH' ) || exit;

define( 'TCBVM_VERSION', '1.0.0' );
define( 'TCBVM_FILE', __FILE__ );
define( 'TCBVM_PATH', plugin_dir_path( __FILE__ ) );
define( 'TCBVM_URL', plugin_dir_url( __FILE__ ) );

/**
 * HPOS Compatibility declaration for WooCommerce.
 */
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

require_once TCBVM_PATH . 'includes/class-tcbvm-core.php';
require_once TCBVM_PATH . 'includes/class-tcbvm-db.php';
require_once TCBVM_PATH . 'includes/class-tcbvm-ops.php';
require_once TCBVM_PATH . 'includes/class-tcbvm-backup.php';
require_once TCBVM_PATH . 'includes/class-tcbvm-ajax.php';
require_once TCBVM_PATH . 'includes/class-tcbvm-admin.php';

register_activation_hook( __FILE__, array( 'TCBVM_Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TCBVM_Core', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'TCBVM_Core', 'instance' ), 20 );
