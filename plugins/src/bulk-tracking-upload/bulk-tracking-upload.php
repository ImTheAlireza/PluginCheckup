<?php
/**
 * Plugin Name: Bulk Tracking Code Upload for WooCommerce
 * Description: آپلود انبوه کد رهگیری از اکسل و نمایش اطلاعات کامل سفارش + لینک پیگیری پست
 * Version: 2.2
 * Author: علیرضا شعبان زاده
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

// استایل ادمین فقط روی دو صفحهٔ خودمان (بعد از لایهٔ توکن هاب اگر فعال باشد)
add_action('admin_enqueue_scripts', 'bwt_admin_assets');
function bwt_admin_assets($hook) {
    if ($hook !== 'woocommerce_page_bulk-tracking-upload' && $hook !== 'woocommerce_page_bwt-cleanup') {
        return;
    }
    wp_enqueue_style(
        'bwt-admin',
        plugin_dir_url(__FILE__) . 'assets/admin.css',
        wp_style_is('tisacase-ui', 'registered') ? array('tisacase-ui') : array(),
        '2.2'
    );
}

// ========== 2. صفحه آپلود اکسل ==========
function bwt_upload_page() {
    if (!current_user_can('manage_options')) {
        wp_die('دسترسی غیر مجاز');
    }

    $result_data = null;
    $upload_error = '';

    // پردازش قبل از رندر HTML تا پیام‌ها بالای فرم بیایند
    if (isset($_POST['bwt_upload'])) {
        if (!isset($_POST['bwt_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bwt_nonce'])), 'bwt_upload_action')) {
            $upload_error = 'خطای امنیتی: نانس نامعتبر است. لطفا صفحه را رفرش کنید.';
        } elseif (empty($_FILES['tracking_file']) || $_FILES['tracking_file']['error'] !== UPLOAD_ERR_OK) {
            $upload_error = bwt_get_upload_error_message($_FILES['tracking_file']['error'] ?? UPLOAD_ERR_NO_FILE);
        } else {
            $file = $_FILES['tracking_file'];

            // اعتبارسنجی حجم (حداکثر 5 مگابایت)
            $max_size = 5 * 1024 * 1024;
            if ($file['size'] > $max_size) {
                $upload_error = 'حجم فایل بیش از حد مجاز است (حداکثر ۵ مگابایت).';
            } elseif (!is_uploaded_file($file['tmp_name'])) {
                $upload_error = 'خطای آپلود: فایل به درستی آپلود نشده است.';
            } else {
                // اعتبارسنجی پسوند و نوع فایل
                $filetype = wp_check_filetype($file['name'], array('csv' => 'text/csv'));
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed_mimes = array('text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel');
                // بعضی سرورها mime را plain برمیگردانند، پس پسوند را هم چک میکنیم
                if ($ext !== 'csv') {
                    $upload_error = 'فرمت فایل نامعتبر است. فقط فایل CSV مجاز است.';
                } elseif (!empty($file['type']) && !in_array($file['type'], $allowed_mimes, true) && $filetype['ext'] !== 'csv') {
                    // اگر mime ناشناخته بود ولی پسوند csv بود، اجازه میدهیم (سخت‌گیری بیش از حد نکنیم)
                    // این شرط فقط برای لاگ است، خطا نمیدهیم
                }

                if (empty($upload_error)) {
                    $result_data = bwt_process_uploaded_file($file['tmp_name'], $file['name']);
                    if (isset($result_data['file_error']) && $result_data['file_error']) {
                        $upload_error = $result_data['file_error'];
                        $result_data = null;
                    }
                }
            }
        }
    }

    ?>
    <div class="wrap bwt-wrap">
        <h1>آپلود فایل کدهای رهگیری پستی</h1>
        <p class="bwt-lead">فرمت فایل CSV باید شامل دو ستون باشد: <strong>order_id, tracking_code</strong> (ردیف اول هدر است و نادیده گرفته می‌شود)</p>
        <div class="bwt-card">
            <p class="bwt-card-title"><strong>نمونه فایل صحیح:</strong></p>
            <code class="bwt-sample">
                order_id,tracking_code<br>
                1234,12345678901234567890<br>
                1235,98765432109876543210<br>
                1236,
            </code>
            <p class="bwt-hint">* اگر ستون دوم خالی باشد، کد رهگیری آن سفارش حذف می‌شود.</p>
        </div>

        <?php
        // نمایش خطای آپلود کلی
        if (!empty($upload_error)) {
            echo '<div class="notice notice-error"><p>❌ ' . esc_html($upload_error) . '</p></div>';
        }

        // نمایش نتیجه پردازش با جزئیات خطاها
        if ($result_data) {
            $updated = intval($result_data['updated']);
            $errors  = intval($result_data['errors']);
            $details = $result_data['details'];
            $total   = $updated + $errors;

            if ($errors === 0) {
                echo '<div class="notice notice-success"><p>✅ همه ردیف‌ها با موفقیت پردازش شد. تعداد بروزرسانی: <strong>' . esc_html($updated) . '</strong> از ' . esc_html($total) . ' ردیف.</p></div>';
            } else {
                echo '<div class="notice notice-warning"><p>⚠️ پردازش تمام شد. موفق: <strong>' . esc_html($updated) . '</strong> | ناموفق: <strong>' . esc_html($errors) . '</strong> | کل ردیف‌های پردازش شده (بدون هدر): <strong>' . esc_html($total) . '</strong></p></div>';
            }

            if (!empty($details)) {
                echo '<div class="bwt-card bwt-card--error">';
                echo '<h3 class="bwt-card-h">ردیف‌های خطا خورده (' . esc_html(count($details)) . ' مورد)</h3>';
                echo '<p class="bwt-hint">این ردیف‌ها بروزرسانی نشدند. فایل را اصلاح و دوباره آپلود کنید.</p>';
                echo '<div class="bwt-scroll">';
                echo '<table class="widefat striped bwt-table">';
                echo '<thead><tr><th>ردیف فایل</th><th>order_id</th><th>tracking_code</th><th>دلیل خطا</th></tr></thead><tbody>';
                foreach ($details as $err) {
                    echo '<tr>';
                    echo '<td>' . esc_html($err['row']) . '</td>';
                    echo '<td class="bwt-ltr">' . esc_html($err['order_id_raw'] !== '' ? $err['order_id_raw'] : '—') . '</td>';
                    echo '<td class="bwt-ltr bwt-code">' . esc_html($err['tracking_code_raw'] !== '' ? $err['tracking_code_raw'] : '—') . '</td>';
                    echo '<td class="bwt-reason">' . esc_html($err['reason']) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
                echo '</div>';
                echo '</div>';
            }
        }
        ?>

        <form method="post" enctype="multipart/form-data" class="bwt-card bwt-form">
            <label for="bwt_tracking_file" class="bwt-label">فایل CSV</label>
            <input type="file" id="bwt_tracking_file" name="tracking_file" accept=".csv" required />
            <?php wp_nonce_field('bwt_upload_action', 'bwt_nonce'); ?>
            <p class="bwt-actions"><button type="submit" name="bwt_upload" class="button button-primary">آپلود و بروزرسانی</button></p>
        </form>
    </div>
    <?php
}

function bwt_get_upload_error_message($code) {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'حجم فایل بیش از حد مجاز سرور است.';
        case UPLOAD_ERR_PARTIAL:
            return 'فایل ناقص آپلود شده. دوباره تلاش کنید.';
        case UPLOAD_ERR_NO_FILE:
            return 'لطفاً یک فایل انتخاب کنید.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'پوشه موقت سرور یافت نشد. با هاستینگ تماس بگیرید.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'خطا در نوشتن فایل روی دیسک.';
        case UPLOAD_ERR_EXTENSION:
            return 'آپلود توسط افزونه PHP متوقف شد.';
        default:
            return 'خطای نامشخص در آپلود فایل.';
    }
}

// ========== 3. پردازش فایل CSV ==========
function bwt_process_uploaded_file($file_path, $original_name = '') {
    $updated = 0;
    $errors = 0;
    $details = array();

    if (!is_readable($file_path)) {
        return array(
            'updated' => 0,
            'errors' => 0,
            'details' => array(),
            'file_error' => 'فایل قابل خواندن نیست.'
        );
    }

    $handle = fopen($file_path, "r");
    if ($handle === FALSE) {
        return array(
            'updated' => 0,
            'errors' => 0,
            'details' => array(),
            'file_error' => 'خطا در باز کردن فایل. لطفاً از فرمت CSV استفاده کنید.'
        );
    }

    $row_num = 0; // شماره ردیف واقعی فایل (برای نمایش به کاربر)
    $has_header = false;

    while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
        $row_num++;

        // حذف BOM از اولین سلول ردیف اول
        if ($row_num === 1 && isset($data[0])) {
            $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', $data[0]);
            // تشخیص هدر: اگر مقدار اول عدد نیست یا برابر order_id است، هدر فرض کن
            $first_cell = strtolower(trim((string) $data[0]));
            if ($first_cell === 'order_id' || $first_cell === 'id' || $first_cell === 'order id' || !is_numeric($first_cell)) {
                // چک اگر ردیف اول واقعا هدر باشد - ولی اگر کاربر هدر نگذاشته و order_id عددی هم نیست، باز خطا میدهیم
                // برای اینکه هدر اختیاری باشد، فقط وقتی که مقدار اول غیرعددی است، هدر حساب میکنیم
                // اگر اولین ردیف عددی بود، آن را داده حساب میکنیم
                if (!is_numeric($first_cell) || $first_cell === 'order_id' || $first_cell === 'id') {
                    // اگر غیر عددی بود، هدر است
                    if (!is_numeric($first_cell)) {
                        $has_header = true;
                        continue;
                    }
                }
            }
        }

        // رد کردن خطوط کاملا خالی
        $is_empty_line = true;
        foreach ($data as $cell) {
            if (trim((string) $cell) !== '') {
                $is_empty_line = false;
                break;
            }
        }
        if ($is_empty_line) {
            continue;
        }

        // اعتبارسنجی تعداد ستون‌ها
        if (count($data) < 1) {
            $errors++;
            $details[] = array(
                'row' => $row_num,
                'order_id_raw' => '',
                'tracking_code_raw' => '',
                'reason' => 'ردیف خالی یا ناقص است.'
            );
            continue;
        }

        $order_id_raw = isset($data[0]) ? trim((string) $data[0]) : '';
        $tracking_code_raw = isset($data[1]) ? trim((string) $data[1]) : '';

        // اگر فقط یک ستون داشت، دومی را خالی در نظر بگیر ولی اگر کاربر انتظار 2 ستون دارد، هشدار بده
        if (count($data) < 2) {
            // اگر تک ستونه و مقدار دوم خالی است، به عنوان حذف کد تلقی میکنیم ولی هشدار میدهیم
            // برای سخت‌گیری کمتر، اجازه میدهیم ولی در جزئیات مینویسیم
        }

        // اعتبارسنجی order_id
        if ($order_id_raw === '') {
            $errors++;
            $details[] = array(
                'row' => $row_num,
                'order_id_raw' => $order_id_raw,
                'tracking_code_raw' => $tracking_code_raw,
                'reason' => 'شماره سفارش خالی است.'
            );
            continue;
        }

        // تبدیل اعداد فارسی/عربی به انگلیسی
        $order_id_normalized = bwt_normalize_digits($order_id_raw);
        if (!ctype_digit($order_id_normalized)) {
            $errors++;
            $details[] = array(
                'row' => $row_num,
                'order_id_raw' => $order_id_raw,
                'tracking_code_raw' => $tracking_code_raw,
                'reason' => 'شماره سفارش باید عددی باشد.'
            );
            continue;
        }

        $order_id = intval($order_id_normalized);
        if ($order_id <= 0) {
            $errors++;
            $details[] = array(
                'row' => $row_num,
                'order_id_raw' => $order_id_raw,
                'tracking_code_raw' => $tracking_code_raw,
                'reason' => 'شماره سفارش نامعتبر است (باید بزرگتر از صفر باشد).'
            );
            continue;
        }

        // بررسی وجود سفارش - سازگار با HPOS
        $order = wc_get_order($order_id);
        if (!$order) {
            $errors++;
            $details[] = array(
                'row' => $row_num,
                'order_id_raw' => $order_id_raw,
                'tracking_code_raw' => $tracking_code_raw,
                'reason' => 'سفارش با این شماره یافت نشد ( #' . $order_id . ' وجود ندارد ).'
            );
            continue;
        }

        // اطمینان از نوع سفارش
        $order_type = $order->get_type();
        if ($order_type !== 'shop_order' && $order_type !== 'shop_order_placehold') {
            $errors++;
            $details[] = array(
                'row' => $row_num,
                'order_id_raw' => $order_id_raw,
                'tracking_code_raw' => $tracking_code_raw,
                'reason' => 'شناسه #' . $order_id . ' سفارش فروشگاهی نیست (نوع: ' . $order_type . ').'
            );
            continue;
        }

        $tracking_code = sanitize_text_field($tracking_code_raw);

        // اعتبارسنجی طول کد رهگیری (اگر پر باشد)
        if (!empty($tracking_code) && mb_strlen($tracking_code) > 100) {
            $errors++;
            $details[] = array(
                'row' => $row_num,
                'order_id_raw' => $order_id_raw,
                'tracking_code_raw' => $tracking_code_raw,
                'reason' => 'کد رهگیری خیلی طولانی است (حداکثر ۱۰۰ کاراکتر).'
            );
            continue;
        }

        // انجام بروزرسانی
        if ($tracking_code === '') {
            delete_post_meta($order_id, '_tracking_code');
            // برای HPOS هم meta را از خود order حذف کن (سازگاری)
            if (method_exists($order, 'delete_meta_data')) {
                $order->delete_meta_data('_tracking_code');
                $order->save();
            }
            $updated++;
        } else {
            update_post_meta($order_id, '_tracking_code', $tracking_code);
            if (method_exists($order, 'update_meta_data')) {
                $order->update_meta_data('_tracking_code', $tracking_code);
                $order->save();
            }
            $updated++;
        }
    }

    fclose($handle);

    // اگر فایل فقط هدر داشت یا خالی بود
    if ($row_num === 0 || ($has_header && $updated === 0 && $errors === 0)) {
        return array(
            'updated' => 0,
            'errors' => 0,
            'details' => array(),
            'file_error' => 'فایل خالی است یا فقط شامل هدر است. داده‌ای برای پردازش یافت نشد.'
        );
    }

    return array(
        'updated' => $updated,
        'errors' => $errors,
        'details' => $details,
        'file_error' => ''
    );
}

function bwt_normalize_digits($str) {
    $persian = array('۰','۱','۲','۳','۴','۵','۶','۷','۸','۹');
    $arabic  = array('٠','١','٢','٣','٤','٥','٦','٧','٨','٩');
    $english = array('0','1','2','3','4','5','6','7','8','9');
    $str = str_replace($persian, $english, $str);
    $str = str_replace($arabic, $english, $str);
    return trim($str);
}

// ========== 4. شورت‌کد جدید با اطلاعات کامل ==========
add_shortcode('tracking_search_form', 'bwt_tracking_search_form');
function bwt_tracking_search_form() {
    ob_start();
    $result_html = '';
    $submitted_order_id = isset($_POST['order_id']) ? sanitize_text_field(wp_unslash($_POST['order_id'])) : '';

    if (isset($_POST['tracking_submit'])) {
        $order_id_raw = $submitted_order_id;
        $order_id_normalized = bwt_normalize_digits($order_id_raw);

        if ($order_id_raw === '') {
            $result_html = '<div class="bwt-result error">❌ لطفاً شماره سفارش را وارد کنید.</div>';
        } elseif (!ctype_digit($order_id_normalized)) {
            $result_html = '<div class="bwt-result error">❌ شماره سفارش باید عددی باشد.</div>';
        } else {
            $order_id = intval($order_id_normalized);
            $order = wc_get_order($order_id);

            if (!$order) {
                $result_html = '<div class="bwt-result error">❌ شماره سفارش نامعتبر است. لطفاً دوباره بررسی کنید.</div>';
            } else {
                $tracking_code = get_post_meta($order_id, '_tracking_code', true);
                if (empty($tracking_code) && method_exists($order, 'get_meta')) {
                    $tracking_code = $order->get_meta('_tracking_code');
                }

                if ($tracking_code) {
                    $billing_first_name = $order->get_billing_first_name();
                    $billing_last_name  = $order->get_billing_last_name();
                    $billing_address_1  = $order->get_billing_address_1();
                    $billing_address_2  = $order->get_billing_address_2();
                    $billing_city       = $order->get_billing_city();
                    $billing_state      = $order->get_billing_state();
                    $billing_postcode   = $order->get_billing_postcode();

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

                    $order_date = $order->get_date_created();
                    $order_date_formatted = $order_date ? $order_date->date_i18n('j F Y - H:i') : '—';

                    $post_tracking_link = 'https://tracking.post.ir/?id=' . urlencode($tracking_code);

                    $result_html  = '<div class="bwt-result success">';
                    $result_html .= '<h3>📦 اطلاعات سفارش شما</h3>';
                    $result_html .= '<div class="order-info">';
                    $result_html .= '<p><strong>👤 نام و نام خانوادگی:</strong> ' . esc_html($billing_first_name . ' ' . $billing_last_name) . '</p>';
                    $result_html .= '<p><strong>📍 آدرس:</strong> ' . esc_html($full_address) . '</p>';
                    $result_html .= '<p><strong>📅 تاریخ ثبت سفارش:</strong> ' . esc_html($order_date_formatted) . '</p>';
                    $result_html .= '<p><strong>🔢 شماره سفارش:</strong> ' . esc_html($order_id) . '</p>';
                    $result_html .= '<hr>';
                    $result_html .= '<p><strong>📮 کد رهگیری پستی:</strong></p>';
                    $result_html .= '<div class="tracking-code-box">' . esc_html($tracking_code) . '</div>';
                    $result_html .= '<p><a href="' . esc_url($post_tracking_link) . '" target="_blank" rel="noopener noreferrer" class="post-tracking-btn">🚚 پیگیری مرسوله در سایت پست</a></p>';
                    $result_html .= '</div></div>';
                } else {
                    $result_html = '<div class="bwt-result error">'
                    . 'چنانچه در این بخش هنوز شماره مرسوله سفارش شما نمایش داده نشده است، به این معناست که سفارش در یکی از مراحل آماده‌سازی، تولید، بسته‌بندی یا ارسال قرار دارد و هنوز به شرکت حمل‌ونقل تحویل نشده است.<br><br>'
                    . 'زمان آماده‌سازی سفارش‌ها به شرح زیر است:<br>'
                    . 'کالاهای عادی: حداکثر ۷ روز کاری<br>'
                    . 'کالاهای چاپی و سفارشی: بین ۷ تا ۱۸ روز کاری (با توجه به فرآیند تولید)<br>'
                    . 'روزهای کاری صرفاً از شنبه تا چهارشنبه محاسبه می‌شوند و پنج‌شنبه، جمعه و تعطیلات رسمی جزو روزهای کاری محسوب نمی‌گردند.<br>'
                    . 'پس از تحویل سفارش به شرکت حمل‌ونقل، شماره مرسوله به‌صورت خودکار در همین صفحه ثبت و قابل مشاهده خواهد بود.<br><br>'
                    . 'خواهشمند است تا پیش از ثبت شماره مرسوله، از ارسال درخواست یا پیگیری جداگانه از طریق پیام خصوصی یا ارتباط با ادمین خودداری فرمایید؛ زیرا تمامی مراحل اطلاع‌رسانی و ثبت اطلاعات مرسوله از طریق همین صفحه انجام می‌شود.<br><br>'
                    . 'از شکیبایی و همراهی ارزشمند شما سپاسگزاریم.</div>';
                }
            }
        }
    }

    ?>
    <div class="bwt-tracking-container">
        <form method="post" class="bwt-tracking-form">
            <p>
                <label for="bwt_order_id">🔍 شماره سفارش خود را وارد کنید:</label>
                <input type="text" id="bwt_order_id" name="order_id" required placeholder="کد سفارش را به صورت لاتین وارد کنید.." value="<?php echo esc_attr($submitted_order_id); ?>" inputmode="numeric" autocomplete="off" />
            </p>
            <p>
                <button type="submit" name="tracking_submit">پیگیری سفارش</button>
            </p>
        </form>

        <?php echo wp_kses_post($result_html); ?>
    </div>

    <style>
    /* فرانت — بدون وابستگی به هاب؛ توکن‌ها اگر بودند، وگرنه fallback */
    .bwt-tracking-container{max-width:600px;margin:0 auto;padding:24px;background:var(--tisa-surface,#fff);border:1px solid var(--tisa-border,#E3E1DA);border-radius:var(--tisa-r,14px);font-variant-numeric:tabular-nums}
    .bwt-tracking-form label{display:block;margin-bottom:8px;font-weight:600;color:var(--tisa-ink,#1F2A2E)}
    .bwt-tracking-form input{width:100%;padding:12px;border:1px solid var(--tisa-border,#E3E1DA);border-radius:var(--tisa-r-sm,10px);font-size:16px;box-sizing:border-box;background:var(--tisa-surface,#fff);color:var(--tisa-ink,#1F2A2E)}
    .bwt-tracking-form input:focus{outline:none;border-color:var(--tisa-primary,#0E7C6B);box-shadow:0 0 0 3px var(--tisa-primary-soft,#E8F0EE)}
    .bwt-tracking-form button{width:100%;padding:12px 24px;border:0;border-radius:var(--tisa-r-sm,10px);background:var(--tisa-primary,#0E7C6B);color:var(--tisa-on-primary,#fff);font-size:16px;font-weight:600;cursor:pointer;transition:background .2s}
    .bwt-tracking-form button:hover{background:var(--tisa-primary-ink,#0A5F52)}
    .bwt-result{margin-top:24px;padding:20px;border-radius:var(--tisa-r-sm,10px);font-size:14px;line-height:1.8;border:1px solid var(--tisa-border,#E3E1DA);background:var(--tisa-surface-2,#FAFAF8);color:var(--tisa-ink,#1F2A2E)}
    .bwt-result.success{border-inline-start:3px solid var(--tisa-success,#1A7F37)}
    .bwt-result.error{border-inline-start:3px solid var(--tisa-danger,#B5453A);background:var(--tisa-danger-soft,#FBECEA);color:var(--tisa-danger-ink,#8E3128)}
    .bwt-result h3{margin:0 0 8px;font-size:16px}
    .order-info{margin-top:8px}
    .order-info p{margin:8px 0;font-size:15px}
    .order-info hr{border:0;border-top:1px dashed var(--tisa-border,#E3E1DA)}
    .tracking-code-box{margin:10px 0;padding:12px;border:1px dashed var(--tisa-border-strong,#C9DCD7);border-radius:var(--tisa-r-sm,10px);background:var(--tisa-surface,#fff);color:var(--tisa-ink,#1F2A2E);font-family:var(--tisa-mono,ui-monospace,Consolas,monospace);font-size:16px;font-weight:700;letter-spacing:1px;direction:ltr;text-align:center;word-break:break-all}
    .post-tracking-btn{display:inline-block;margin-top:10px;padding:10px 20px;border:1px solid var(--tisa-primary,#0E7C6B);border-radius:var(--tisa-r-sm,10px);background:transparent;color:var(--tisa-primary,#0E7C6B) !important;font-weight:600;text-decoration:none;transition:background .2s,color .2s}
    .post-tracking-btn:hover{background:var(--tisa-primary,#0E7C6B);color:var(--tisa-on-primary,#fff) !important;text-decoration:none}
    </style>
    <?php
    return ob_get_clean();
}

// ========== 5. صفحه پاک کردن کدها ==========
function bwt_cleanup_page() {
    if (!current_user_can('manage_options')) {
        wp_die('دسترسی غیر مجاز');
    }

    // پردازش قبل از نمایش فرم
    $message = '';
    if (isset($_POST['bwt_delete_all'])) {
        if (!isset($_POST['bwt_cleanup_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bwt_cleanup_nonce'])), 'bwt_cleanup_action')) {
            $message = '<div class="notice notice-error"><p>❌ خطای امنیتی: نانس نامعتبر.</p></div>';
        } else {
            $message = bwt_delete_all_tracking_codes();
        }
    } elseif (isset($_POST['bwt_delete_selected'])) {
        if (!isset($_POST['bwt_cleanup_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bwt_cleanup_nonce'])), 'bwt_cleanup_action')) {
            $message = '<div class="notice notice-error"><p>❌ خطای امنیتی: نانس نامعتبر.</p></div>';
        } elseif (empty($_POST['order_ids'])) {
            $message = '<div class="notice notice-error"><p>❌ لطفاً حداقل یک شماره سفارش وارد کنید.</p></div>';
        } else {
            $raw = sanitize_text_field(wp_unslash($_POST['order_ids']));
            // تبدیل اعداد فارسی به انگلیسی
            $raw = bwt_normalize_digits($raw);
            $parts = array_filter(array_map('trim', explode(',', $raw)));
            $order_ids = array();
            foreach ($parts as $p) {
                if (ctype_digit($p)) {
                    $order_ids[] = intval($p);
                }
            }
            if (empty($order_ids)) {
                $message = '<div class="notice notice-error"><p>❌ شماره سفارش معتبری یافت نشد.</p></div>';
            } else {
                $message = bwt_delete_selected_tracking_codes($order_ids);
            }
        }
    }

    ?>
    <div class="wrap bwt-wrap">
        <h1>پاک کردن کدهای رهگیری</h1>
        
        <div class="notice notice-warning">
            <p><strong>هشدار مهم:</strong> این عملیات غیرقابل بازگشت است! قبل از انجام، از اطلاعات خود بکاپ بگیرید.</p>
        </div>

        <?php echo wp_kses_post($message); ?>
        
        <form method="post" class="bwt-card bwt-form">
            <?php wp_nonce_field('bwt_cleanup_action', 'bwt_cleanup_nonce'); ?>
            
            <h3 class="bwt-card-h">پاک کردن انتخابی</h3>
            <p>
                <label for="bwt_order_ids" class="bwt-label">شماره سفارش‌ها (با کاما جدا کنید):</label>
                <input type="text" id="bwt_order_ids" name="order_ids" class="bwt-ids" placeholder="مثلاً 123,124,125" />
            </p>
            <p>
                <button type="submit" name="bwt_delete_selected" class="button button-secondary" onclick="return confirm('کد رهگیری سفارش‌های انتخابی پاک میشه؟')">
                    پاک کردن انتخابی
                </button>
            </p>
            
            <hr class="bwt-sep">
            
            <h3 class="bwt-card-h bwt-card-h--danger">پاک کردن همه کدها</h3>
            <p class="bwt-hint">غیرقابل بازگشت — همهٔ سفارش‌ها.</p>
            <p>
                <button type="submit" name="bwt_delete_all" class="button button-danger" onclick="return confirm('همه کدهای رهگیری پاک می‌شه! مطمئنی؟ این کار برگشت نداره!')">
                    پاک کردن همه کدهای رهگیری
                </button>
            </p>
        </form>
    </div>
    <?php
}

function bwt_delete_all_tracking_codes() {
    if (!current_user_can('manage_options')) {
        return '<div class="notice notice-error"><p>❌ دسترسی غیر مجاز.</p></div>';
    }
    global $wpdb;
    // حذف از postmeta
    $deleted = $wpdb->delete(
        $wpdb->postmeta,
        array('meta_key' => '_tracking_code'),
        array('%s')
    );
    // برای HPOS (جدول سفارشات جدید) هم پاک کن اگر وجود داشت
    $orders_table = $wpdb->prefix . 'wc_orders_meta';
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $orders_table));
    $deleted_hpos = 0;
    if ($table_exists === $orders_table) {
        $deleted_hpos = $wpdb->delete($orders_table, array('meta_key' => '_tracking_code'), array('%s'));
    }
    $total = intval($deleted) + intval($deleted_hpos);
    if ($total > 0) {
        return '<div class="notice notice-success"><p>✅ <strong>' . esc_html($total) . '</strong> کد رهگیری با موفقیت حذف شد.</p></div>';
    }
    return '<div class="notice notice-warning"><p>ℹ️ کدی برای حذف یافت نشد.</p></div>';
}

function bwt_delete_selected_tracking_codes($order_ids) {
    if (!current_user_can('manage_options')) {
        return '<div class="notice notice-error"><p>❌ دسترسی غیر مجاز.</p></div>';
    }
    $order_ids = array_map('intval', $order_ids);
    $order_ids = array_unique(array_filter($order_ids));

    $deleted = 0;
    $not_found = 0;
    $invalid = 0;
    $details = array();
    
    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            $invalid++;
            $details[] = '#' . $order_id . ' (سفارش یافت نشد)';
            continue;
        }
        $meta_exists = get_post_meta($order_id, '_tracking_code', true);
        if (empty($meta_exists) && method_exists($order, 'get_meta')) {
            $meta_exists = $order->get_meta('_tracking_code');
        }
        if ($meta_exists) {
            $ok1 = delete_post_meta($order_id, '_tracking_code');
            $ok2 = false;
            if (method_exists($order, 'delete_meta_data')) {
                $order->delete_meta_data('_tracking_code');
                $ok2 = $order->save();
            }
            if ($ok1 || $ok2) {
                $deleted++;
            } else {
                $not_found++;
            }
        } else {
            $not_found++;
            $details[] = '#' . $order_id . ' (کد نداشت)';
        }
    }
    
    $msg = '<div class="notice notice-success"><p>✅ <strong>' . esc_html($deleted) . '</strong> کد رهگیری حذف شد. ';
    if ($not_found > 0) {
        $msg .= '<strong>' . esc_html($not_found) . '</strong> سفارش کد رهگیری نداشت. ';
    }
    if ($invalid > 0) {
        $msg .= '<strong>' . esc_html($invalid) . '</strong> شماره نامعتبر بود.';
    }
    $msg .= '</p>';
    if (!empty($details) && ($not_found + $invalid) > 0) {
        $msg .= '<p class="bwt-hint">جزئیات: ' . esc_html(implode('، ', array_slice($details, 0, 20))) . (count($details) > 20 ? ' و...' : '') . '</p>';
    }
    $msg .= '</div>';
    return $msg;
}