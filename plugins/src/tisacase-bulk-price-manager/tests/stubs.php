<?php
/* ---------- محیط تقلبی ---------- */

define( 'ABSPATH', __DIR__ . '/' );
define( 'TCBPM_VERSION', '2.0.0' );
define( 'TCBPM_FILE', __DIR__ . '/tisacase-bulk-price-manager.php' );
define( 'TCBPM_DIR', __DIR__ . '/' );
define( 'TCBPM_URL', 'http://example.test/' );
define( 'TCBPM_WHOLESALE_META', '_tisacase_wholesale_price' );

define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );
define( 'OBJECT', 'OBJECT' );
define( 'OBJECT_K', 'OBJECT_K' );

require_once dirname( __DIR__ ) . '/includes/class-tcbpm-core.php';
require_once dirname( __DIR__ ) . '/includes/class-tcbpm-db.php';
require_once dirname( __DIR__ ) . '/includes/class-tcbpm-ops.php';
require_once dirname( __DIR__ ) . '/includes/class-tcbpm-admin.php';
require_once dirname( __DIR__ ) . '/includes/class-tcbpm-ajax.php';
require_once dirname( __DIR__ ) . '/includes/class-tcbpm-scheduler.php';

/* --- کلاس‌های پایه وردپرس --- */

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_message() { return $this->message; }
		public function get_error_code() { return $this->code; }
	}
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

/* --- ذخیرهٔ متا و محصولات تقلبی --- */

$GLOBALS['tcbpm_meta'] = array();   // post_id => [ key => value ]
$GLOBALS['tcbpm_products'] = array(); // id => object

function get_post_meta( $post_id, $key, $single = false ) {
	$m = isset( $GLOBALS['tcbpm_meta'][ (int) $post_id ] ) ? $GLOBALS['tcbpm_meta'][ (int) $post_id ] : array();
	return isset( $m[ $key ] ) ? $m[ $key ] : '';
}
function metadata_exists( $type, $post_id, $key ) {
	return isset( $GLOBALS['tcbpm_meta'][ (int) $post_id ][ $key ] );
}
function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['tcbpm_meta'][ (int) $post_id ][ $key ] = (string) $value;
	return true;
}
function delete_post_meta( $post_id, $key ) {
	unset( $GLOBALS['tcbpm_meta'][ (int) $post_id ][ $key ] );
	return true;
}
function clean_post_cache( $id ) {}
function update_meta_cache( $type, $ids ) {}
function get_option( $k, $d = false ) { return isset( $GLOBALS['tcbpm_options'][ $k ] ) ? $GLOBALS['tcbpm_options'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['tcbpm_options'][ $k ] = $v; return true; }

/* --- محصول تقلبی ووکامرس --- */

class FakeProduct {
	public $id; public $title; public $type; public $parent = 0; public $children = array(); public $saves = 0;
	public function __construct( $id, $title, $type, $parent = 0 ) {
		$this->id = (int) $id; $this->title = $title; $this->type = $type; $this->parent = (int) $parent;
	}
	public function get_id() { return $this->id; }
	public function get_name() { return $this->title; }
	public function get_parent_id() { return $this->parent; }
	public function is_type( $t ) { return $this->type === $t; }
	public function get_children() { return $this->children; }
	private function m( $k ) {
		return isset( $GLOBALS['tcbpm_meta'][ $this->id ][ $k ] ) ? $GLOBALS['tcbpm_meta'][ $this->id ][ $k ] : '';
	}
	private function setm( $k, $v ) { $GLOBALS['tcbpm_meta'][ $this->id ][ $k ] = (string) $v; }
	public function get_regular_price( $ctx = 'view' ) { return $this->m( '_regular_price' ); }
	public function set_regular_price( $v ) { $this->setm( '_regular_price', $v ); }
	public function get_sale_price( $ctx = 'view' ) { return $this->m( '_sale_price' ); }
	public function set_sale_price( $v ) { $this->setm( '_sale_price', $v ); }
	public function set_date_on_sale_from( $v ) {}
	public function set_date_on_sale_to( $v ) {}
	public function get_price( $ctx = 'view' ) {
		$sale = $this->get_sale_price( $ctx );
		return '' !== $sale ? $sale : $this->get_regular_price( $ctx );
	}
	public function save() { $this->saves++; }
}

function register_product( $id, $title, $type, $meta = array(), $parent = 0, $children = array() ) {
	$p = new FakeProduct( $id, $title, $type, $parent );
	$p->children = $children;
	$GLOBALS['tcbpm_products'][ (int) $id ] = $p;
	foreach ( $meta as $k => $v ) { $GLOBALS['tcbpm_meta'][ (int) $id ][ $k ] = (string) $v; }
	return $p;
}

function WC() { return true; }
function wc_get_product( $id ) {
	return isset( $GLOBALS['tcbpm_products'][ (int) $id ] ) ? $GLOBALS['tcbpm_products'][ (int) $id ] : null;
}
function wc_format_decimal( $n ) {
	if ( '' === (string) $n || null === $n ) { return ''; }
	return rtrim( rtrim( number_format( (float) $n, 6, '.', '' ), '0' ), '.' );
}
function wc_get_price_decimals() { return 0; }
function wc_update_product_lookup_tables() {}

if ( ! class_exists( 'WC_Product_Variable' ) ) {
	class WC_Product_Variable { public static function sync( $id ) {} }
}
function wc_delete_product_transients( $id ) {}
function wc_products_array_filter_readable( $p ) { return true; }
function esc_sql( $s ) { return (string) $s; }
function wp_strip_all_tags( $s ) { return preg_replace( '/<[^>]*>/', '', $s ); }

/* --- $wpdb تقلبی برای چند پرس‌وجوی سادهٔ افزونه --- */

if ( ! class_exists( 'FakeWpdb' ) ) {
	class FakeWpdb {
		public $prefix = 'wp_';
		public $posts = 'wp_posts';
		public $postmeta = 'wp_postmeta';
		public $term_relationships = 'wp_term_relationships';
		public $term_taxonomy = 'wp_term_taxonomy';
		public $terms = 'wp_terms';
		public function get_results( $sql, $out = ARRAY_A ) {
			if ( false !== strpos( $sql, 'term_relationships' ) ) {
				$rows = array();
				foreach ( $GLOBALS['fake_types'] as $oid => $type ) {
					$rows[] = array( 'pid' => (string) $oid, 'type' => $type );
				}
				return $rows;
			}
			if ( false !== strpos( $sql, "'product_variation'" ) ) {
				return $GLOBALS['fake_variations'];
			}
			if ( false !== strpos( $sql, 'meta_key IN' ) ) {
				return $GLOBALS['fake_meta_rows'];
			}
			return array();
		}
		public function get_col( $sql ) {
			if ( false !== strpos( $sql, 'FROM wp_posts WHERE ID IN' ) ) {
				return $GLOBALS['fake_id_col'];
			}
			return array();
		}
		public function get_row( $sql, $out = ARRAY_A ) { return null; }
		public function get_var( $sql ) { return 0; }
		public function insert( $t, $d, $f ) { return 1; }
		public function update( $t, $d, $w, $f, $wf ) { return 1; }
		public $rows_affected = 1;
		public function query( $sql ) { $this->rows_affected = 1; return 1; }
		public function prepare( $sql ) { return $sql; }
		public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
	}
}
$GLOBALS['wpdb'] = new FakeWpdb();
$GLOBALS['fake_types'] = array();
$GLOBALS['fake_variations'] = array();
$GLOBALS['fake_meta_rows'] = array();
$GLOBALS['fake_id_col'] = array();

/* --- توابع وردپرس --- */

function wp_verify_nonce( $nonce, $action ) { return ( 'good' === $nonce ); }
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
function wp_salt( $scheme ) { return 'test-salt-123'; }
function is_user_logged_in() { return true; }
function get_current_user_id() { return 1; }
function current_user_can( $cap ) { return true; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function current_time( $type ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_term( $id, $tax ) {
	if ( in_array( (int) $id, array( 11, 12 ), true ) ) { return (object) array( 'term_id' => (int) $id, 'name' => 'cat' . $id, 'parent' => 0 ); }
	return null;
}
function get_userdata( $id ) { return (object) array( 'display_name' => 'admin', 'user_login' => 'admin' ); }
function number_format_i18n( $n ) { return number_format( (float) $n, 0, '.', ',' ); }
function mysql2date( $f, $s ) { return $s; }
function wp_json_encode( $v ) { return json_encode( $v, JSON_UNESCAPED_UNICODE ); }
function load_plugin_textdomain() {}
function plugin_basename( $f ) { return $f; }
function get_woocommerce_currency_symbol() { return 'تومان'; }
function wp_create_nonce( $a ) { return 'nonce-' . $a; }
function check_admin_referer() {}
function submit_button() {}
function wp_nonce_field( $a ) {}
function selected( $a, $b ) {}
function checked( $a ) {}
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return $s; }
function wp_die( $m = '' ) { throw new RuntimeException( 'wp_die: ' . $m ); }
function add_action() {}
function add_filter() {}
function absint( $v ) { return abs( (int) $v ); }

