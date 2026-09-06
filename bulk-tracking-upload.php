<?php
/**
 * Plugin Name: Bulk Tracking Code Upload for WooCommerce
 * Description: آپلود انبوه کد رهگیری از اکسل و نمایش اطلاعات کامل سفارش + لینک پیگیری پست
 * Version: 2.0
 * Author: shayan zakizadeh
 */

if (!defined('ABSPATH')) {
    exit;
}

// ========== 1. افزودن منو به پیشخوان ==========
add_action('admin_menu', 'bwt_add_admin_menu');
function bwt_add_admin_menu() {
    add_submenu_page(
        'woocommerce',
        'آپلود کد رهگیری انبوه',
        'آپلود کد رهگیری',
        'manage_options',
        'bulk-tracking-upload',
        'bwt_upload_page'
    );
    
    add_submenu_page(
        'woocommerce',
        'پاک کردن کدهای رهگیری',
        'پاک کردن کدها',
        'manage_options',
        'bwt-cleanup',
        'bwt_cleanup_page'
    );
}

// ========== 2. صفحه آپلود اکسل ==========
function bwt_upload_page() {
    ?>
    <div class="wrap">
        <h1>آپلود فایل کدهای رهگیری پستی</h1>
        <p>فرمت فایل CSV باید شامل دو ستون باشد: <strong>order_id, tracking_code</strong></p>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="tracking_file" accept=".csv" required />
            <?php wp_nonce_field('bwt_upload_action', 'bwt_nonce'); ?>
            <p><button type="submit" name="bwt_upload" class="button button-primary">آپلود و بروزرسانی</button></p>
        </form>
    </div>
    <?php

    if (isset($_POST['bwt_upload']) && check_admin_referer('bwt_upload_action', 'bwt_nonce')) {
        if (!empty($_FILES['tracking_file']['tmp_name'])) {
            $result = bwt_process_uploaded_file($_FILES['tracking_file']['tmp_name']);
            echo '<div class="notice notice-success"><p>' . $result . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>لطفاً یک فایل انتخاب کنید.</p></div>';
        }
    }
}

// ========== 3. پردازش فایل CSV ==========
function bwt_process_uploaded_file($file_path) {
    $updated = 0;
    $errors = 0;

    if (($handle = fopen($file_path, "r")) !== FALSE) {
        $row = 0;
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if ($row == 0) {
                $row++;
                continue;
            }
            $order_id = intval($data[0]);
            $tracking_code = sanitize_text_field($data[1]);

            if ($order_id && get_post_type($order_id) === 'shop_order') {
                if (empty($tracking_code)) {
                    delete_post_meta($order_id, '_tracking_code');
                    $updated++;
                } else {
                    update_post_meta($order_id, '_tracking_code', $tracking_code);
                    $updated++;
                }
            } else {
                $errors++;
            }
            $row++;
        }
        fclose($handle);
        return "تعداد $updated سفارش بروزرسانی شد. تعداد خطا: $errors";
    } else {
        return "خطا در باز کردن فایل. لطفاً از فرمت CSV استفاده کنید.";
    }
}

// ========== 4. شورت‌کد جدید با اطلاعات کامل ==========
add_shortcode('tracking_search_form', 'bwt_tracking_search_form');
function bwt_tracking_search_form() {
    ob_start();
    ?>
    <div class="bwt-tracking-container">
        <form method="post" class="bwt-tracking-form">
            <p>
                <label for="order_id">🔍 شماره سفارش خود را وارد کنید:</label>
                <input type="text" id="order_id" name="order_id" required placeholder="کد سفارش را به صورت لاتین وارد کنید.." />
            </p>
            <p>
                <button type="submit" name="tracking_submit">پیگیری سفارش</button>
            </p>
        </form>

        <?php
        if (isset($_POST['tracking_submit']) && !empty($_POST['order_id'])) {
            $order_id = intval($_POST['order_id']);
            $order = wc_get_order($order_id);
            
            if ($order) {
                $tracking_code = get_post_meta($order_id, '_tracking_code', true);
                
                if ($tracking_code) {
                    // دریافت اطلاعات مشتری از صورتحساب
                    $billing_first_name = $order->get_billing_first_name();
                    $billing_last_name = $order->get_billing_last_name();
                    $billing_address_1 = $order->get_billing_address_1();
                    $billing_address_2 = $order->get_billing_address_2();
                    $billing_city = $order->get_billing_city();
                    $billing_state = $order->get_billing_state();
                    $billing_postcode = $order->get_billing_postcode();
                    
                    // ساخت آدرس کامل
                    $full_address = $billing_address_1;
                    if ($billing_address_2) {
                        $full_address .= ' - ' . $billing_address_2;
                    }
                    $full_address .= '، ' . $billing_city;
                    if ($billing_state) {
                        $full_address .= '، ' . $billing_state;
                    }
                    if ($billing_postcode) {
                        $full_address .= '، کد پستی: ' . $billing_postcode;
                    }
                    
                    // تاریخ ثبت سفارش
                    $order_date = $order->get_date_created();
                    $order_date_formatted = $order_date->date_i18n('j F Y - H:i');
                    
                    // لینک پیگیری پست
                    $post_tracking_link = 'https://tracking.post.ir/?id=' . urlencode($tracking_code);
                    
                    // نمایش اطلاعات کامل
                    echo '<div class="bwt-result success">';
                    echo '<h3>📦 اطلاعات سفارش شما</h3>';
                    echo '<div class="order-info">';
                    echo '<p><strong>👤 نام و نام خانوادگی:</strong> ' . esc_html($billing_first_name . ' ' . $billing_last_name) . '</p>';
                    echo '<p><strong>📍 آدرس:</strong> ' . esc_html($full_address) . '</p>';
                    echo '<p><strong>📅 تاریخ ثبت سفارش:</strong> ' . esc_html($order_date_formatted) . '</p>';
                    echo '<p><strong>🔢 شماره سفارش:</strong> ' . esc_html($order_id) . '</p>';
                    echo '<hr style="margin: 15px 0;">';
                    echo '<p><strong>📮 کد رهگیری پستی:</strong></p>';
                    echo '<div class="tracking-code-box">' . esc_html($tracking_code) . '</div>';
                    echo '<p><a href="' . esc_url($post_tracking_link) . '" target="_blank" rel="noopener noreferrer" class="post-tracking-btn">';
                    echo '🚚 پیگیری مرسوله در سایت پست</a></p>';
                    echo '</div>';
                    echo '</div>';
                } else {
                    echo '<div class="bwt-result error">
چنانچه در این بخش هنوز شماره مرسوله سفارش شما نمایش داده نشده است، به این معناست که سفارش در یکی از مراحل آماده‌سازی، تولید، بسته‌بندی یا ارسال قرار دارد و هنوز به شرکت حمل‌ونقل تحویل نشده است.

زمان آماده‌سازی سفارش‌ها به شرح زیر است:
کالاهای عادی: حداکثر ۷ روز کاری
کالاهای چاپی و سفارشی: بین ۷ تا ۱۸ روز کاری (با توجه به فرآیند تولید)
روزهای کاری صرفاً از شنبه تا چهارشنبه محاسبه می‌شوند و پنج‌شنبه، جمعه و تعطیلات رسمی جزو روزهای کاری محسوب نمی‌گردند.
پس از تحویل سفارش به شرکت حمل‌ونقل، شماره مرسوله به‌صورت خودکار در همین صفحه ثبت و قابل مشاهده خواهد بود.

خواهشمند است تا پیش از ثبت شماره مرسوله، از ارسال درخواست یا پیگیری جداگانه از طریق پیام خصوصی یا ارتباط با ادمین خودداری فرمایید؛ زیرا تمامی مراحل اطلاع‌رسانی و ثبت اطلاعات مرسوله از طریق همین صفحه انجام می‌شود.

از شکیبایی و همراهی ارزشمند شما سپاسگزاریم.</div>';
                }
            } else {
                echo '<div class="bwt-result error">❌ شماره سفارش نامعتبر است. لطفاً دوباره بررسی کنید.</div>';
            }
        }
        ?>
    </div>

    <style>
    .bwt-tracking-container {
        max-width: 600px;
        margin: 0 auto;
        padding: 25px;
        background: #ffffff;
        border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    .bwt-tracking-form label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #333;
    }
    .bwt-tracking-form input {
        width: 100%;
        padding: 12px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 16px;
        box-sizing: border-box;
    }
    .bwt-tracking-form button {
        background: #7a9c59;
        color: white;
        padding: 12px 25px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-size: 16px;
        font-weight: 600;
        width: 100%;
        transition: background 0.3s;
    }
    .bwt-tracking-form button:hover {
        background: #5a7c3e;
    }
    .bwt-result {
		font-size: 14px;
        margin-top: 25px;
        padding: 20px;
        border-radius: 10px;
    }
    .bwt-result.success {
		font-size: 14px;
        background: #f0f9ff;
        border: 1px solid #b8e1fc;
    }
    .bwt-result.error {
        background: #f8d7da;
        border: 1px solid #f5c6cb;
        color: #721c24;
    }
    .order-info {
        margin-top: 10px;
    }
    .order-info p {
        margin: 10px 0;
        font-size: 15px;
        color: #333;
    }
    .order-info hr {
        border: none;
        border-top: 1px dashed #ccc;
    }
    .tracking-code-box {
        background: #fff3cd;
        border: 1px solid #ffeeba;
        padding: 12px;
        border-radius: 8px;
        font-size: 16px;
        font-weight: bold;
        direction: ltr;
        text-align: center;
        letter-spacing: 1px;
        color: #856404;
        margin: 10px 0;
        font-family: monospace;
    }
    .post-tracking-btn {
        display: inline-block;
        background: #2196F3;
        color: white !important;
        padding: 10px 20px;
        border-radius: 8px;
        text-decoration: none;
        font-weight: 600;
        margin-top: 10px;
        transition: background 0.3s;
    }
    .post-tracking-btn:hover {
        background: #0b7dda;
        text-decoration: none;
        color: white;
    }
    </style>
    <?php
    return ob_get_clean();
}

// ========== 5. صفحه پاک کردن کدها ==========
function bwt_cleanup_page() {
    ?>
    <div class="wrap">
        <h1>🗑️ پاک کردن کدهای رهگیری</h1>
        
        <div class="notice notice-warning">
            <p><strong>⚠️ هشدار مهم:</strong> این عملیات غیرقابل بازگشت است! قبل از انجام، از اطلاعات خود بکاپ بگیرید.</p>
        </div>
        
        <form method="post" style="background:#f5f5f5; padding:20px; margin-top:20px; border-radius:8px;">
            <?php wp_nonce_field('bwt_cleanup_action', 'bwt_cleanup_nonce'); ?>
            
            <h3>📌 پاک کردن انتخابی:</h3>
            <p>
                <label>شماره سفارش‌ها (با کاما جدا کنید):</label><br>
                <input type="text" name="order_ids" style="width:300px; padding:8px;" placeholder="مثلاً 123,124,125" />
            </p>
            <p>
                <button type="submit" name="bwt_delete_selected" class="button button-secondary" onclick="return confirm('کد رهگیری سفارش‌های انتخابی پاک میشه؟')">
                    🗑️ پاک کردن انتخابی
                </button>
            </p>
            
            <hr style="margin:30px 0;">
            
            <h3>⚠️ پاک کردن همه کدها:</h3>
            <p>
                <button type="submit" name="bwt_delete_all" class="button button-danger" onclick="return confirm('همه کدهای رهگیری پاک می‌شه! مطمئنی؟ این کار برگشت نداره!')">
                    🗑️ پاک کردن همه کدهای رهگیری
                </button>
            </p>
        </form>
    </div>
    <style>
    .button-danger {
        background: #dc3232 !important;
        color: white !important;
        border-color: #dc3232 !important;
    }
    .button-danger:hover {
        background: #a00 !important;
    }
    </style>
    <?php
    
    if (isset($_POST['bwt_delete_all']) && check_admin_referer('bwt_cleanup_action', 'bwt_cleanup_nonce')) {
        bwt_delete_all_tracking_codes();
    }
    
    if (isset($_POST['bwt_delete_selected']) && !empty($_POST['order_ids']) && check_admin_referer('bwt_cleanup_action', 'bwt_cleanup_nonce')) {
        $order_ids = array_map('intval', explode(',', $_POST['order_ids']));
        bwt_delete_selected_tracking_codes($order_ids);
    }
}

function bwt_delete_all_tracking_codes() {
    global $wpdb;
    $deleted = $wpdb->delete(
        $wpdb->postmeta,
        array('meta_key' => '_tracking_code'),
        array('%s')
    );
    echo '<div class="notice notice-success"><p>✅ <strong>' . $deleted . '</strong> کد رهگیری با موفقیت حذف شد.</p></div>';
}

function bwt_delete_selected_tracking_codes($order_ids) {
    $deleted = 0;
    $not_found = 0;
    
    foreach ($order_ids as $order_id) {
        $meta_exists = get_post_meta($order_id, '_tracking_code', true);
        if ($meta_exists) {
            if (delete_post_meta($order_id, '_tracking_code')) {
                $deleted++;
            }
        } else {
            $not_found++;
        }
    }
    
    echo '<div class="notice notice-success"><p>✅ <strong>' . $deleted . '</strong> کد رهگیری حذف شد. ';
    if ($not_found > 0) {
        echo '<strong>' . $not_found . '</strong> سفارش کد رهگیری نداشت.</p></div>';
    } else {
        echo '</p></div>';
    }
}