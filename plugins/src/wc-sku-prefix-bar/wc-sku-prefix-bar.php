<?php
/**
 * Plugin Name: WooCommerce SKU Prefix Bar
 * Description: SKU prefix dashboard, next-SKU generator, duplicate checker and protected REST API for the Telegram bot.
 * Version: 1.5.1
 * Author: Arena
 * Text Domain: wc-sku-prefix-bar
 * Requires at least: 5.6
 * Requires PHP: 7.2
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WC_SKU_Prefix_Bar {
    const TRANSIENT = 'wcspb_latest_skus';
    const VER = '1.5.1';
    const TTL = HOUR_IN_SECONDS * 6;
    private static $printed = false;

    public static function init() {
        $self = new self();
        add_action( 'manage_posts_extra_tablenav', array( $self, 'render_tablenav' ), 5 );
        add_action( 'admin_enqueue_scripts', array( $self, 'assets' ) );
        add_action( 'wp_ajax_wcspb_refresh', array( $self, 'ajax_refresh' ) );
        add_action( 'woocommerce_product_options_sku', array( $self, 'render_generator' ) );
        add_action( 'wp_ajax_wcspb_next', array( $self, 'ajax_next' ) );
        add_action( 'wp_ajax_wcspb_check', array( $self, 'ajax_check' ) );
        add_action( 'rest_api_init', array( $self, 'register_rest_routes' ) );
        add_action( 'save_post_product', array( __CLASS__, 'flush' ) );
        add_action( 'save_post_product_variation', array( __CLASS__, 'flush' ) );
        add_action( 'deleted_post', array( __CLASS__, 'flush' ) );
        add_action( 'woocommerce_update_product', array( __CLASS__, 'flush' ) );
    }
    public static function flush() { delete_transient( self::TRANSIENT ); }

    private function is_products_screen() {
        if ( function_exists( 'get_current_screen' ) ) {
            $s = get_current_screen();
            if ( ! $s || 'edit' !== $s->base || 'product' !== $s->post_type ) return false;
        } else {
            global $pagenow;
            if ( 'edit.php' !== $pagenow || 'product' !== ( isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : '' ) ) return false;
        }
        foreach ( array_keys( wp_unslash($_GET) ) as $key ) if ( 'post_type' !== $key ) return false;
        return true;
    }
    private function is_product_editor() {
        if ( function_exists( 'get_current_screen' ) ) {
            $s = get_current_screen();
            if ( $s && 'post' === $s->base && 'product' === $s->post_type ) return true;
        }
        global $pagenow, $post;
        if ( 'post-new.php' === $pagenow ) return 'product' === ( isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : '' );
        return 'post.php' === $pagenow && $post && 'product' === get_post_type($post);
    }
    /** وابستگی به لایهٔ توکن هاب، اگر فعال بود — تا var(--tisa-*) ما بعد از آن کسکید بگیرد. */
    private function style_deps() {
        return wp_style_is( 'tisacase-ui', 'registered' ) ? array( 'tisacase-ui' ) : array();
    }

    public function assets( $hook ) {
        if ( $this->is_product_editor() ) {
            wp_enqueue_style('wcspb', plugins_url('assets/bar.css',__FILE__), $this->style_deps(), self::VER);
            wp_enqueue_script('wcspb-editor',plugins_url('assets/editor.js',__FILE__),array('jquery'),self::VER,true);
            wp_localize_script('wcspb-editor','WCSPB_ED',array('ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('wcspb'),'postId'=>get_the_ID() ?: 0,'i18n'=>array('checking'=>__('در حال بررسی…','wc-sku-prefix-bar'),'dupe'=>__('این SKU قبلاً استفاده شده:','wc-sku-prefix-bar'),'free'=>__('SKU آزاد است','wc-sku-prefix-bar'),'edit'=>__('ویرایش','wc-sku-prefix-bar'))));
            return;
        }
        if ( ! $this->is_products_screen() ) return;
        wp_enqueue_style('wcspb',plugins_url('assets/bar.css',__FILE__), $this->style_deps(), self::VER);
        wp_enqueue_script('wcspb',plugins_url('assets/bar.js',__FILE__),array('jquery'),self::VER,true);
        wp_localize_script('wcspb','WCSPB',array('ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('wcspb')));
    }
    public function get_latest() {
        $cached=get_transient(self::TRANSIENT); if (is_array($cached)) return $cached;
        global $wpdb; $lookup=$wpdb->prefix.'wc_product_meta_lookup';
        $skus=array();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$lookup))) $skus=$wpdb->get_col("SELECT DISTINCT sku FROM {$lookup} WHERE sku <> ''");
        if (!$skus) $skus=$wpdb->get_col("SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key='_sku' AND pm.meta_value<>'' AND p.post_type IN ('product','product_variation') AND p.post_status NOT IN ('trash','auto-draft')");
        $best=array();
        foreach ((array)$skus as $sku) {
            $sku=trim((string)$sku); if (!preg_match('/^([^0-9]*)([0-9]+)(.*)$/u',$sku,$m)) continue;
            $prefix=strtoupper(trim($m[1])); $num=(int)$m[2]; $pad=strlen($m[2]);
            if (''===preg_replace('/[^A-Z]/','',$prefix)) continue;
            if (!isset($best[$prefix]) || $num>$best[$prefix]['num'] || ($num===$best[$prefix]['num'] && $pad>$best[$prefix]['pad'])) $best[$prefix]=array('num'=>$num,'pad'=>$pad,'sku'=>$sku);
        }
        ksort($best,SORT_NATURAL); $out=array();
        foreach($best as $prefix=>$d) $out[]=array('prefix'=>$prefix,'label'=>$prefix.str_pad((string)$d['num'],$d['pad'],'0',STR_PAD_LEFT),'next'=>$prefix.str_pad((string)($d['num']+1),$d['pad'],'0',STR_PAD_LEFT),'sample'=>$d['sku']);
        set_transient(self::TRANSIENT,$out,self::TTL); return $out;
    }
    public function render_tablenav($which){ if('top'===$which)$this->render_bar(); }
    public function render_bar(){ if(self::$printed||!$this->is_products_screen()||!current_user_can('edit_products'))return; self::$printed=true; echo $this->bar_html($this->get_latest()); }
    private function bar_html( $items ) {
        ob_start(); ?>
        <div class="wcspb-bar" id="wcspb-bar" dir="rtl" role="toolbar" aria-label="شناسه‌ها" style="display: flex;visibility: visible;opacity: 1;">
            <span class="wcspb-sort-label"><?php esc_html_e( 'مرتب‌سازی بر اساس:', 'wc-sku-prefix-bar' ); ?></span>
            <span class="wcspb-list">
            <?php if ( empty( $items ) ) : ?>
                <span class="wcspb-empty"><?php esc_html_e( 'شناسه‌ای پیدا نشد.', 'wc-sku-prefix-bar' ); ?></span>
            <?php else : foreach ( $items as $it ) : ?>
                <button type="button" class="wcspb-chip" data-prefix="<?php echo esc_attr( $it['prefix'] ); ?>" title="<?php echo esc_attr( sprintf( /* translators: %s: SKU prefix */ __( 'مرتب‌سازی محصولات بر اساس %s', 'wc-sku-prefix-bar' ), $it['prefix'] ) ); ?>"><?php echo esc_html( $it['prefix'] ); ?></button>
            <?php endforeach; endif; ?>
            </span>
            <span class="wcspb-tools">
                <button type="button" class="wcspb-refresh" id="wcspb-refresh" title="<?php esc_attr_e( 'شمارش دوبارهٔ سری‌ها', 'wc-sku-prefix-bar' ); ?>">⟳ <?php esc_html_e( 'تازه‌سازی', 'wc-sku-prefix-bar' ); ?></button>
                <small class="wcspb-credit"><?php esc_html_e( 'توسط علیرضا شعبان‌زاده', 'wc-sku-prefix-bar' ); ?></small>
            </span>
        </div>
        <?php return ob_get_clean();
    }
    public function ajax_refresh(){ check_ajax_referer('wcspb','nonce'); if(!current_user_can('edit_products'))wp_send_json_error(); self::flush(); wp_send_json_success(array('html'=>$this->bar_html($this->get_latest()))); }
    public function render_generator(){ if(!current_user_can('edit_products'))return; $items=$this->get_latest(); if(!$items)return; ?><p class="form-field wcspb-gen-field"><label for="wcspb-gen"><?php esc_html_e('SKU خودکار','wc-sku-prefix-bar'); ?></label><span class="wcspb-gen-wrap"><select id="wcspb-gen" class="wcspb-gen-select"><option value=""><?php esc_html_e('— انتخاب سری —','wc-sku-prefix-bar'); ?></option><?php foreach($items as $it): ?><option value="<?php echo esc_attr($it['next']); ?>"><?php echo esc_html($it['label'].'  →  '.$it['next']); ?></option><?php endforeach; ?></select><button type="button" class="button button-primary wcspb-gen-btn" id="wcspb-gen-btn"><?php esc_html_e('درج در فیلد SKU','wc-sku-prefix-bar'); ?></button></span><span class="wcspb-gen-status" id="wcspb-gen-status" role="status" aria-live="polite"></span></p><?php }
    public function ajax_next(){ check_ajax_referer('wcspb','nonce'); if(!current_user_can('edit_products'))wp_send_json_error(); $prefix=strtoupper(sanitize_text_field(wp_unslash($_POST['prefix']??''))); self::flush(); foreach($this->get_latest() as $it)if($it['prefix']===$prefix)wp_send_json_success(array('next'=>$it['next'])); wp_send_json_error(); }
    public function ajax_check(){ check_ajax_referer('wcspb','nonce'); if(!current_user_can('edit_products'))wp_send_json_error(); global $wpdb; $sku=sanitize_text_field(wp_unslash($_POST['sku']??'')); $self=absint($_POST['post_id']??0); if(''===$sku)wp_send_json_success(array('dupe'=>false)); $row=$wpdb->get_row($wpdb->prepare("SELECT p.ID,p.post_title,p.post_type FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key='_sku' AND pm.meta_value=%s AND p.ID<>%d AND p.post_type IN ('product','product_variation') AND p.post_status NOT IN ('trash','auto-draft') LIMIT 1",$sku,$self)); if(!$row)wp_send_json_success(array('dupe'=>false)); $id=(int)$row->ID; $title=$row->post_title; if('product_variation'===$row->post_type&&($parent=wp_get_post_parent_id($id))){$title=get_the_title($parent).' — '.__('variation','wc-sku-prefix-bar');$id=$parent;} wp_send_json_success(array('dupe'=>true,'title'=>$title?:('#'.$id),'link'=>get_edit_post_link($id,'raw'))); }

    /** REST endpoint consumed by the Telegram bot. WooCommerce consumer-key authentication maps to a WordPress user. */
    public function register_rest_routes(){ register_rest_route('wcspb/v1','/next-sku',array('methods'=>WP_REST_Server::READABLE,'permission_callback'=>function(){return current_user_can('edit_products');},'callback'=>function(WP_REST_Request $request){$prefix=strtoupper(preg_replace('/[^A-Z0-9_-]/i','',(string)$request->get_param('prefix')));if(''===$prefix)return new WP_Error('wcspb_missing_prefix','A SKU prefix is required',array('status'=>400));foreach($this->get_latest() as $item)if(strtoupper($item['prefix'])===$prefix)return rest_ensure_response(array('prefix'=>$prefix,'latest'=>$item['label'],'sku'=>$item['next']));return rest_ensure_response(array('prefix'=>$prefix,'latest'=>null,'sku'=>$prefix.'1'));},'args'=>array('prefix'=>array('required'=>true)))); }
}
add_action('plugins_loaded',array('WC_SKU_Prefix_Bar','init'));
register_deactivation_hook(__FILE__,array('WC_SKU_Prefix_Bar','flush'));
