<?php
/**
 * Plugin Name: Bulk Tracking Code Upload for WooCommerce
 * Description: آپلود انبوه کد رهگیری از اکسل و نمایش اطلاعات کامل سفارش + لینک پیگیری پست
 * Version: 2.4.0
 * Author: علیرضا شعبان زاده
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BWT_VERSION', '2.4.0');

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
        BWT_VERSION
    );
}


// سر سبز مشترک دو صفحه (زبان طراحی TisaCase)
function bwt_render_hero($active) {
    $tabs = array(
        'upload'  => array('label' => 'آپلود کد رهگیری', 'url' => admin_url('admin.php?page=bulk-tracking-upload')),
        'cleanup' => array('label' => 'پاک کردن کدها',   'url' => admin_url('admin.php?page=bwt-cleanup')),
    );
    ?>
    <header class="bwt-hero">
        <div class="bwt-hero-row">
            <div class="bwt-hero-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5z"/><path d="M3 7.5 12 12l9-4.5M12 12v9"/></svg>
            </div>
            <div class="bwt-hero-text">
                <h1 class="bwt-hero-title">کد رهگیری پستی</h1>
                <p class="bwt-hero-sub">افزودن انبوه کد رهگیری از CSV و پاک‌کردن کدها — به همراه فرم پیگیری سفارش برای مشتری</p>
            </div>
            <span class="bwt-hero-ver" dir="ltr">v<?php echo esc_html(BWT_VERSION); ?></span>
        </div>
        <nav class="bwt-tabs">
            <?php foreach ($tabs as $key => $t) : ?>
                <a class="bwt-tab <?php echo $key === $active ? 'is-active' : ''; ?>" href="<?php echo esc_url($t['url']); ?>"><?php echo esc_html($t['label']); ?></a>
            <?php endforeach; ?>
        </nav>
    </header>
    <?php
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
    <div class="wrap tisa-wrap bwt-wrap" dir="rtl">
        <?php bwt_render_hero('upload'); ?>

        <?php
        // نمایش خطای آپلود کلی
        if (!empty($upload_error)) {
            echo '<div class="tisa-notice tisa-notice--danger bwt-notice">' . esc_html($upload_error) . '</div>';
        }

        // نمایش نتیجه پردازش با جزئیات خطاها
        if ($result_data) {
            $updated = intval($result_data['updated']);
            $errors  = intval($result_data['errors']);
            $details = $result_data['details'];
            $total   = $updated + $errors;

            echo '<div class="bwt-kpis">';
            echo '<div class="bwt-kpi"><div class="t">ردیف‌های پردازش‌شده</div><div class="v">' . esc_html(number_format_i18n($total)) . '</div></div>';
            echo '<div class="bwt-kpi bwt-kpi--ok"><div class="t">بروزرسانی موفق</div><div class="v">' . esc_html(number_format_i18n($updated)) . '</div></div>';
            echo '<div class="bwt-kpi' . ($errors > 0 ? ' bwt-kpi--bad' : '') . '"><div class="t">ناموفق</div><div class="v">' . esc_html(number_format_i18n($errors)) . '</div></div>';
            echo '</div>';

            if ($errors === 0) {
                echo '<div class="tisa-notice tisa-notice--success bwt-notice">همه ردیف‌ها با موفقیت پردازش شد.</div>';
            } else {
                echo '<div class="tisa-notice tisa-notice--warning bwt-notice">پردازش تمام شد؛ ' . esc_html(number_format_i18n($errors)) . ' ردیف بروزرسانی نشد. جزئیات در جدول زیر.</div>';
            }

            if (!empty($details)) {
                echo '<section class="bwt-card bwt-card--error">';
                echo '<div class="bwt-card-head"><span class="bwt-dot bwt-dot--bad"></span><div><h2>ردیف‌های خطا خورده (' . esc_html(number_format_i18n(count($details))) . ' مورد)</h2><p>این ردیف‌ها بروزرسانی نشدند. فایل را اصلاح و دوباره آپلود کنید.</p></div></div>';
                echo '<div class="bwt-card-body">';
                echo '<div class="tisa-table-scroll bwt-scroll">';
                echo '<table class="tisa-table bwt-table">';
                echo '<thead><tr><th>ردیف فایل</th><th>order_id</th><th>tracking_code</th><th>دلیل خطا</th></tr></thead><tbody>';
                foreach ($details as $err) {
                    echo '<tr>';
                    echo '<td>' . esc_html($err['row']) . '</td>';
                    echo '<td class="bwt-ltr">' . esc_html($err['order_id_raw'] !== '' ? $err['order_id_raw'] : '—') . '</td>';
                    echo '<td class="bwt-ltr"><span class="tisa-code">' . esc_html($err['tracking_code_raw'] !== '' ? $err['tracking_code_raw'] : '—') . '</span></td>';
                    echo '<td class="bwt-reason">' . esc_html($err['reason']) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
                echo '</div>';
                echo '</div>';
                echo '</section>';
            }
        }
        ?>

        <div class="bwt-grid">
            <form method="post" enctype="multipart/form-data" class="bwt-card bwt-form">
                <div class="bwt-card-head"><span class="bwt-dot"></span><div><h2>آپلود فایل CSV</h2><p>دو ستون <span class="tisa-code">order_id, tracking_code</span> — ردیف اول هدر است و نادیده گرفته می‌شود.</p></div></div>
                <div class="bwt-card-body">
                    <label for="bwt_tracking_file" class="bwt-label">فایل CSV</label>
                    <input type="file" id="bwt_tracking_file" name="tracking_file" accept=".csv" required class="bwt-file" />
                    <p class="bwt-hint">اگر ستون دوم خالی باشد، کد رهگیری آن سفارش حذف می‌شود.</p>
                    <?php wp_nonce_field('bwt_upload_action', 'bwt_nonce'); ?>
                    <div class="bwt-actions"><button type="submit" name="bwt_upload" class="tisa-btn tisa-btn--primary tisa-btn--lg">آپلود و بروزرسانی</button></div>
                </div>
            </form>

            <section class="bwt-card">
                <div class="bwt-card-head"><span class="bwt-dot bwt-dot--muted"></span><div><h2>نمونه فایل صحیح</h2></div></div>
                <div class="bwt-card-body">
                    <pre class="bwt-sample">order_id,tracking_code
1234,12345678901234567890
1235,98765432109876543210
1236,</pre>
                    <ul class="bwt-help">
                        <li>شماره سفارش‌ها می‌توانند با ارقام فارسی هم باشند.</li>
                        <li>ردیف آخر (بدون کد) = حذف کد رهگیری سفارش ۱۲۳۶.</li>
                        <li>شورت‌کد فرم پیگیری مشتری: <span class="tisa-code">[tracking_search]</span></li>
                    </ul>
                </div>
            </section>
        </div>
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
/**
 * نرمال‌سازی شمارهٔ موبایل به قالب یکتای 989XXXXXXXXX.
 * ورودی: فارسی/عربی، فاصله/خط‌تیره/پرانتز، پیشوندهای +98 / 0098 / 98 / 0.
 * خروجی نامعتبر = رشتهٔ خالی.
 */
function bwt_normalize_phone($input) {
    $s = bwt_normalize_digits((string) $input);
    $s = preg_replace('/[^0-9]/', '', $s);
    if ($s === '') {
        return '';
    }
    if (strpos($s, '0098') === 0) {
        $s = substr($s, 4);
    } elseif (strlen($s) === 12 && strpos($s, '98') === 0) {
        $s = substr($s, 2);
    }
    if (strpos($s, '0') === 0) {
        $s = substr($s, 1);
    }
    if (strlen($s) === 10 && strpos($s, '9') === 0) {
        return '98' . $s;
    }
    return '';
}

// مقایسهٔ شمارهٔ ثبت‌شده روی سفارش با ورودی کاربر (بدون افشای اطلاعات، زمان‌ثابت)
function bwt_phones_match($stored, $input) {
    $a = bwt_normalize_phone($stored);
    $b = bwt_normalize_phone($input);
    if ($a === '' || $b === '') {
        return false;
    }
    return hash_equals($a, $b);
}

// محدودسازی تلاش‌های ناموفق پیگیری بر اساس IP (ضد درو کردن شماره سفارش‌ها)
function bwt_client_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'cli';
}
function bwt_track_throttle_key() {
    return 'bwt_track_fail_' . md5(bwt_client_ip());
}
function bwt_track_is_throttled() {
    return (int) get_transient(bwt_track_throttle_key()) >= 10;
}
function bwt_track_note_fail() {
    $key = bwt_track_throttle_key();
    set_transient($key, (int) get_transient($key) + 1, 15 * MINUTE_IN_SECONDS);
}
function bwt_track_reset_fails() {
    delete_transient(bwt_track_throttle_key());
}

function bwt_tracking_search_form() {
    ob_start();
    $result_html = '';
    $submitted_order_id = isset($_POST['order_id']) ? sanitize_text_field(wp_unslash($_POST['order_id'])) : '';
    $submitted_phone    = isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash($_POST['billing_phone'])) : '';

    if (isset($_POST['tracking_submit'])) {
        if (bwt_track_is_throttled()) {
            $result_html = '<div class="bwt-result error">❌ تعداد تلاش‌های ناموفق بیش از حد مجاز است. لطفاً ۱۵ دقیقه دیگر دوباره تلاش کنید.</div>';
        } else {
            $order_id_raw = $submitted_order_id;
            $order_id_normalized = bwt_normalize_digits($order_id_raw);

            if ($order_id_raw === '' || $submitted_phone === '') {
                $result_html = '<div class="bwt-result error">❌ وارد کردن شماره سفارش و شماره موبایلی که سفارش با آن ثبت شده، الزامی است.</div>';
            } elseif (!ctype_digit($order_id_normalized)) {
                $result_html = '<div class="bwt-result error">❌ شماره سفارش باید عددی باشد.</div>';
            } else {
                $order_id = intval($order_id_normalized);
                $order = wc_get_order($order_id);

                // احراز مالکیت: شماره سفارش به‌تنهایی کافی نیست — موبایل ثبت‌شده روی سفارش هم باید بخواند.
                // پیام خطا برای «سفارش نامعتبر» و «موبایل نامطابق» یکی است تا وجود سفارش لو نرود.
                $verified = $order && bwt_phones_match($order->get_billing_phone(), $submitted_phone);

                if (!$verified) {
                    bwt_track_note_fail();
                    $result_html = '<div class="bwt-result error">❌ شماره سفارش یا شماره موبایل واردشده معتبر نیست. شماره موبایل باید همان شماره‌ای باشد که سفارش با آن ثبت شده است.</div>';
                } else {
                    bwt_track_reset_fails();
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
    }

    ?>
    <div class="bwt-tracking-container">
        <form method="post" class="bwt-tracking-form">
            <p>
                <label for="bwt_order_id">🔍 شماره سفارش خود را وارد کنید:</label>
                <input type="text" id="bwt_order_id" name="order_id" required placeholder="کد سفارش را به صورت لاتین وارد کنید.." value="<?php echo esc_attr($submitted_order_id); ?>" inputmode="numeric" autocomplete="off" />
            </p>
            <p>
                <label for="bwt_billing_phone">📱 شماره موبایلی که سفارش با آن ثبت شده:</label>
                <input type="text" id="bwt_billing_phone" name="billing_phone" required placeholder="مثال: ۰۹۱۲۳۴۵۶۷۸۹" value="<?php echo esc_attr($submitted_phone); ?>" inputmode="tel" autocomplete="off" />
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
            $message = '<div class="tisa-notice tisa-notice--danger bwt-notice"><p>خطای امنیتی: نانس نامعتبر.</p></div>';
        } else {
            $message = bwt_delete_all_tracking_codes();
        }
    } elseif (isset($_POST['bwt_delete_selected'])) {
        if (!isset($_POST['bwt_cleanup_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bwt_cleanup_nonce'])), 'bwt_cleanup_action')) {
            $message = '<div class="tisa-notice tisa-notice--danger bwt-notice"><p>خطای امنیتی: نانس نامعتبر.</p></div>';
        } elseif (empty($_POST['order_ids'])) {
            $message = '<div class="tisa-notice tisa-notice--danger bwt-notice"><p>لطفاً حداقل یک شماره سفارش وارد کنید.</p></div>';
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
                $message = '<div class="tisa-notice tisa-notice--danger bwt-notice"><p>شماره سفارش معتبری یافت نشد.</p></div>';
            } else {
                $message = bwt_delete_selected_tracking_codes($order_ids);
            }
        }
    }

    ?>
    <div class="wrap tisa-wrap bwt-wrap" dir="rtl">
        <?php bwt_render_hero('cleanup'); ?>

        <?php echo wp_kses_post($message); ?>

        <form method="post" class="bwt-grid">
            <?php wp_nonce_field('bwt_cleanup_action', 'bwt_cleanup_nonce'); ?>

            <section class="bwt-card">
                <div class="bwt-card-head"><span class="bwt-dot"></span><div><h2>پاک کردن انتخابی</h2><p>کد رهگیری فقط از سفارش‌هایی که وارد می‌کنید حذف می‌شود.</p></div></div>
                <div class="bwt-card-body">
                    <label for="bwt_order_ids" class="bwt-label">شماره سفارش‌ها (با کاما جدا کنید)</label>
                    <input type="text" id="bwt_order_ids" name="order_ids" class="tisa-input bwt-ids" dir="ltr" inputmode="numeric" placeholder="123,124,125" />
                    <div class="bwt-actions">
                        <button type="submit" name="bwt_delete_selected" class="tisa-btn tisa-btn--secondary" onclick="return confirm('کد رهگیری سفارش‌های انتخابی پاک میشه؟')">پاک کردن انتخابی</button>
                    </div>
                </div>
            </section>

            <section class="bwt-card bwt-card--danger">
                <div class="bwt-card-head"><span class="bwt-dot bwt-dot--bad"></span><div><h2>پاک کردن همهٔ کدها</h2><p>غیرقابل بازگشت — از همهٔ سفارش‌ها. قبل از انجام بکاپ بگیرید.</p></div></div>
                <div class="bwt-card-body">
                    <div class="bwt-actions">
                        <button type="submit" name="bwt_delete_all" class="tisa-btn tisa-btn--danger" onclick="return confirm('همه کدهای رهگیری پاک می‌شه! مطمئنی؟ این کار برگشت نداره!')">پاک کردن همه کدهای رهگیری</button>
                    </div>
                </div>
            </section>
        </form>
    </div>
    <?php
}

function bwt_delete_all_tracking_codes() {
    if (!current_user_can('manage_options')) {
        return '<div class="tisa-notice tisa-notice--danger bwt-notice"><p>دسترسی غیر مجاز.</p></div>';
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
        return '<div class="tisa-notice tisa-notice--success bwt-notice"><p><strong>' . esc_html($total) . '</strong> کد رهگیری با موفقیت حذف شد.</p></div>';
    }
    return '<div class="tisa-notice tisa-notice--warning bwt-notice"><p>کدی برای حذف یافت نشد.</p></div>';
}

function bwt_delete_selected_tracking_codes($order_ids) {
    if (!current_user_can('manage_options')) {
        return '<div class="tisa-notice tisa-notice--danger bwt-notice"><p>دسترسی غیر مجاز.</p></div>';
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
    
    $msg = '<div class="tisa-notice tisa-notice--success bwt-notice"><p><strong>' . esc_html($deleted) . '</strong> کد رهگیری حذف شد. ';
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