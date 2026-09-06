<?php
/*
Plugin Name: TisaCase Telegram Daily Analytics Report
Description: Sends WooCommerce Analytics daily report to Telegram at 00:05.
Version: 4.0
*/

if (!defined('ABSPATH')) {
    exit;
}

/* ======================================================
 * فقط این دو مقدار را پر کن
 * ====================================================== */

if (!defined('TISACASE_TG_BOT_TOKEN')) {
    define('TISACASE_TG_BOT_TOKEN', '8523544340:AAGOMfvB4Zld8c1s561mTwcOGqQ-lDESlFU');
}

if (!defined('TISACASE_TG_CHAT_ID')) {
    define('TISACASE_TG_CHAT_ID', '-1003842704844');
}

/* ======================================================
 * تنظیمات ثابت
 * ====================================================== */

if (!defined('TISACASE_REPORT_TEST_KEY')) {
    define('TISACASE_REPORT_TEST_KEY', 'tisa_daily_report_2026');
}

/*
 * اگر مبلغ‌ها 10 برابر شد، این مقدار را 10 کن.
 * اگر با تومان درست بود، همین 1 بماند.
 */
if (!defined('TISACASE_REPORT_CURRENCY_DIVIDER')) {
    define('TISACASE_REPORT_CURRENCY_DIVIDER', 1);
}

if (!defined('TISACASE_REPORT_HOUR')) {
    define('TISACASE_REPORT_HOUR', 0);
}

if (!defined('TISACASE_REPORT_MINUTE')) {
    define('TISACASE_REPORT_MINUTE', 5);
}

/* ======================================================
 * زمان‌بندی خودکار 00:05
 * ====================================================== */

add_action('init', 'tisacase_report_schedule_v4');

function tisacase_report_schedule_v4() {
    wp_clear_scheduled_hook('tisacase_send_daily_telegram_report_hook');
    wp_clear_scheduled_hook('tisacase_send_daily_telegram_report_0005_hook');
    wp_clear_scheduled_hook('tisacase_telegram_daily_report_v3_hook');

    $hook = 'tisacase_telegram_daily_report_v4_hook';
    $next = wp_next_scheduled($hook);

    if ($next) {
        $tz = wp_timezone();
        $next_dt = (new DateTimeImmutable('@' . $next))->setTimezone($tz);

        if ($next_dt->format('H:i') !== '00:05') {
            wp_clear_scheduled_hook($hook);
            $next = false;
        }
    }

    if (!$next) {
        wp_schedule_event(tisacase_report_next_run_timestamp_v4(), 'daily', $hook);
    }
}

function tisacase_report_next_run_timestamp_v4() {
    $tz = wp_timezone();
    $now = new DateTimeImmutable('now', $tz);
    $target = $now->setTime(TISACASE_REPORT_HOUR, TISACASE_REPORT_MINUTE, 0);

    if ($target <= $now) {
        $target = $target->modify('+1 day');
    }

    return $target->getTimestamp();
}

add_action('tisacase_telegram_daily_report_v4_hook', 'tisacase_report_send_yesterday_v4');

function tisacase_report_send_yesterday_v4() {
    $tz = wp_timezone();
    $date = new DateTimeImmutable('yesterday', $tz);

    tisacase_report_send_for_date_v4($date->format('Y-m-d'), false, false);
}

/* ======================================================
 * لینک تست و وضعیت
 * ====================================================== */

add_action('init', 'tisacase_report_manual_routes_v4', 1);

function tisacase_report_manual_routes_v4() {
    if (isset($_GET['tisacase_report_test'])) {
        $key = sanitize_text_field(wp_unslash($_GET['tisacase_report_test']));

        if ($key !== TISACASE_REPORT_TEST_KEY) {
            wp_die('Invalid report key');
        }

        $day = isset($_GET['day']) ? sanitize_text_field(wp_unslash($_GET['day'])) : 'yesterday';
        $force = isset($_GET['force']) && $_GET['force'] === '1';

        $date = tisacase_report_resolve_date_v4($day);

        if (!$date) {
            wp_die('Invalid date. Use today, yesterday, or YYYY-MM-DD');
        }

        $sent = tisacase_report_send_for_date_v4($date->format('Y-m-d'), true, $force);

        if ($sent) {
            wp_die('TisaCase report sent for ' . esc_html($date->format('Y-m-d')));
        }

        $err = get_option('tisacase_report_last_error_v4', 'No error saved.');
        wp_die('Failed to send report. Error: ' . esc_html($err));
    }

    if (isset($_GET['tisacase_report_status'])) {
        $key = sanitize_text_field(wp_unslash($_GET['tisacase_report_status']));

        if ($key !== TISACASE_REPORT_TEST_KEY) {
            wp_die('Invalid status key');
        }

        $hook = 'tisacase_telegram_daily_report_v4_hook';
        $next = wp_next_scheduled($hook);

        $next_text = 'Not scheduled';

        if ($next) {
            $tz = wp_timezone();
            $next_dt = (new DateTimeImmutable('@' . $next))->setTimezone($tz);
            $next_text = $next_dt->format('Y-m-d H:i:s');
        }

        $last_response = get_option('tisacase_report_last_response_v4', array());
        $last_error = get_option('tisacase_report_last_error_v4', '');

        wp_die(
            '<b>TisaCase Daily Report Status</b>' .
            '<br>Next run: ' . esc_html($next_text) .
            '<br>Timezone: ' . esc_html(wp_timezone_string()) .
            '<br><br>Last response:<pre>' . esc_html(print_r($last_response, true)) . '</pre>' .
            '<br>Last error:<pre>' . esc_html($last_error) . '</pre>'
        );
    }
}

function tisacase_report_resolve_date_v4($day) {
    $tz = wp_timezone();

    if ($day === 'today') {
        return new DateTimeImmutable('today', $tz);
    }

    if ($day === 'yesterday') {
        return new DateTimeImmutable('yesterday', $tz);
    }

    return DateTimeImmutable::createFromFormat('Y-m-d', $day, $tz);
}

/* ======================================================
 * ارسال گزارش
 * ====================================================== */

function tisacase_report_send_for_date_v4($ymd, $is_test = false, $force = false) {
    $source = $is_test ? 'test' : 'auto';

    $lock_key = 'tisacase_report_lock_v4_' . $source . '_' . $ymd;

    if (!$force && get_transient($lock_key)) {
        update_option('tisacase_report_last_error_v4', 'Duplicate prevented for ' . $ymd . ' / ' . $source, false);
        return true;
    }

    set_transient($lock_key, 1, 3 * MINUTE_IN_SECONDS);

    if (!$is_test && !$force) {
        $sent_key = 'tisacase_report_auto_sent_v4_' . $ymd;

        if (get_option($sent_key)) {
            update_option('tisacase_report_last_error_v4', 'Auto report already sent for ' . $ymd, false);
            return true;
        }
    }

    $data = tisacase_report_get_data_v4($ymd);

    if (!$data) {
        update_option('tisacase_report_last_error_v4', 'Report data is empty or WooCommerce Analytics tables not found.', false);
        return false;
    }

    $message = tisacase_report_build_message_v4($data, $is_test);
    $sent = tisacase_report_send_telegram_v4($message);

    if ($sent && !$is_test) {
        update_option('tisacase_report_auto_sent_v4_' . $ymd, current_time('mysql'), false);
    }

    return $sent;
}

/* ======================================================
 * دریافت داده
 * بخش درآمد و سفارشات از خود WooCommerce Analytics Query
 * ====================================================== */

function tisacase_report_get_data_v4($ymd) {
    global $wpdb;

    $summary = tisacase_report_get_revenue_from_analytics_query_v4($ymd);

    if (!$summary) {
        $summary = tisacase_report_get_revenue_from_sql_fallback_v4($ymd);
    }

    if (!$summary) {
        return false;
    }

    $extra = tisacase_report_get_extra_data_v4($ymd);

    return array(
        'date_gregorian'       => $ymd,
        'date_jalali'          => tisacase_report_gregorian_to_jalali_text_v4($ymd),

        'orders_count'         => (int) ($summary['orders_count'] ?? 0),
        'total_sales'          => tisacase_report_money_value_v4($summary['total_sales'] ?? 0),
        'net_sales'            => tisacase_report_money_value_v4($summary['net_sales'] ?? 0),
        'shipping_total'       => tisacase_report_money_value_v4($summary['shipping_total'] ?? 0),
        'refund_amount'        => tisacase_report_money_value_v4($summary['refund_amount'] ?? 0),
        'coupon_amount'        => tisacase_report_money_value_v4($summary['coupon_amount'] ?? 0),
        'items_sold'           => (int) ($summary['items_sold'] ?? 0),

        'processing_count'     => (int) ($extra['processing_count'] ?? 0),
        'completed_count'      => (int) ($extra['completed_count'] ?? 0),
        'variation_items_sold' => (int) ($extra['variation_items_sold'] ?? 0),
        'top_products'         => $extra['top_products'] ?? array(),
        'top_categories'       => $extra['top_categories'] ?? array(),

        'data_source'          => $summary['source'] ?? 'unknown',
    );
}

function tisacase_report_get_revenue_from_analytics_query_v4($ymd) {
    if (!class_exists('\Automattic\WooCommerce\Admin\API\Reports\Revenue\Query')) {
        return false;
    }

    try {
        $tz = wp_timezone();

        $start = new DateTimeImmutable($ymd . ' 00:00:00', $tz);
        $end   = new DateTimeImmutable($ymd . ' 23:59:59', $tz);

        $args = array(
            'after'    => $start->format(DateTimeInterface::ATOM),
            'before'   => $end->format(DateTimeInterface::ATOM),
            'interval' => 'day',
            'page'     => 1,
            'per_page' => 1,
            'orderby'  => 'date',
            'order'    => 'asc',
        );

        $query  = new \Automattic\WooCommerce\Admin\API\Reports\Revenue\Query($args);
        $result = $query->get_data();

        $totals = tisacase_report_extract_totals_v4($result);

        if (!$totals) {
            return false;
        }

        $orders_count = tisacase_report_total_value_v4($totals, array('orders_count', 'orders'), 0);
        $items_sold   = tisacase_report_total_value_v4($totals, array('num_items_sold', 'items_sold'), 0);

        $net_sales    = tisacase_report_total_value_v4($totals, array('net_revenue', 'net_sales', 'net_total'), 0);
        $shipping     = tisacase_report_total_value_v4($totals, array('shipping', 'shipping_total'), 0);
        $total_sales  = tisacase_report_total_value_v4($totals, array('total_sales'), 0);
        $refunds      = abs((float) tisacase_report_total_value_v4($totals, array('refunds', 'returns'), 0));
        $coupons      = abs((float) tisacase_report_total_value_v4($totals, array('coupons', 'coupon_amount'), 0));
        $taxes        = tisacase_report_total_value_v4($totals, array('taxes', 'tax_total'), 0);

        /*
         * اگر total_sales خالی برگشت، مثل کارت WooCommerce Analytics از جمع خالص + حمل‌ونقل + مالیات استفاده می‌کنیم.
         */
        if ((float) $total_sales === 0.0 && ((float) $net_sales > 0 || (float) $shipping > 0)) {
            $total_sales = (float) $net_sales + (float) $shipping + (float) $taxes;
        }

        return array(
            'orders_count'   => (int) $orders_count,
            'items_sold'     => (int) $items_sold,
            'total_sales'    => (float) $total_sales,
            'net_sales'      => (float) $net_sales,
            'shipping_total' => (float) $shipping,
            'refund_amount'  => (float) $refunds,
            'coupon_amount'  => (float) $coupons,
            'source'         => 'WooCommerce Analytics Revenue Query',
        );
    } catch (Throwable $e) {
        update_option('tisacase_report_last_error_v4', 'Analytics Query error: ' . $e->getMessage(), false);
        return false;
    }
}

function tisacase_report_extract_totals_v4($result) {
    if (is_array($result)) {
        if (isset($result['totals'])) {
            return $result['totals'];
        }

        if (isset($result['total'])) {
            return $result['total'];
        }

        if (isset($result['data']['totals'])) {
            return $result['data']['totals'];
        }
    }

    if (is_object($result)) {
        if (isset($result->totals)) {
            return $result->totals;
        }

        if (isset($result->total)) {
            return $result->total;
        }

        if (isset($result->data) && is_object($result->data) && isset($result->data->totals)) {
            return $result->data->totals;
        }
    }

    return false;
}

function tisacase_report_total_value_v4($totals, $keys, $default = 0) {
    foreach ((array) $keys as $key) {
        if (is_array($totals) && isset($totals[$key])) {
            return $totals[$key];
        }

        if (is_object($totals) && isset($totals->{$key})) {
            return $totals->{$key};
        }
    }

    return $default;
}

/* ======================================================
 * fallback SQL فقط اگر Analytics Query در دسترس نبود
 * ====================================================== */

function tisacase_report_get_revenue_from_sql_fallback_v4($ymd) {
    global $wpdb;

    $stats_table = $wpdb->prefix . 'wc_order_stats';

    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $stats_table)) !== $stats_table) {
        return false;
    }

    $start = $ymd . ' 00:00:00';
    $end   = date('Y-m-d 00:00:00', strtotime($ymd . ' +1 day'));

    $excluded = tisacase_report_excluded_statuses_v4();
    $excluded_sql = implode(',', array_fill(0, count($excluded), '%s'));

    $args = array_merge(array($start, $end), $excluded);

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "
            SELECT
                COUNT(DISTINCT order_id) AS orders_count,
                COALESCE(SUM(total_sales), 0) AS total_sales,
                COALESCE(SUM(net_total), 0) AS net_sales,
                COALESCE(SUM(num_items_sold), 0) AS items_sold,
                COALESCE(SUM(shipping_total), 0) AS shipping_total
            FROM {$stats_table}
            WHERE parent_id = 0
              AND date_created >= %s
              AND date_created < %s
              AND status NOT IN ({$excluded_sql})
            ",
            $args
        ),
        ARRAY_A
    );

    return array(
        'orders_count'   => (int) ($row['orders_count'] ?? 0),
        'items_sold'     => (int) ($row['items_sold'] ?? 0),
        'total_sales'    => (float) ($row['total_sales'] ?? 0),
        'net_sales'      => (float) ($row['net_sales'] ?? 0),
        'shipping_total' => (float) ($row['shipping_total'] ?? 0),
        'refund_amount'  => 0,
        'coupon_amount'  => 0,
        'source'         => 'SQL fallback',
    );
}

/* ======================================================
 * دیتیل‌های تکمیلی: وضعیت‌ها، محصولات، دسته‌ها
 * ====================================================== */

function tisacase_report_get_extra_data_v4($ymd) {
    global $wpdb;

    $stats_table   = $wpdb->prefix . 'wc_order_stats';
    $product_table = $wpdb->prefix . 'wc_order_product_lookup';

    $empty = array(
        'processing_count'     => 0,
        'completed_count'      => 0,
        'variation_items_sold' => 0,
        'top_products'         => array(),
        'top_categories'       => array(),
    );

    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $stats_table)) !== $stats_table) {
        return $empty;
    }

    $start = $ymd . ' 00:00:00';
    $end   = date('Y-m-d 00:00:00', strtotime($ymd . ' +1 day'));

    $excluded = tisacase_report_excluded_statuses_v4();
    $excluded_sql = implode(',', array_fill(0, count($excluded), '%s'));
    $where_args = array_merge(array($start, $end), $excluded);

    $status_counts = $wpdb->get_row(
        $wpdb->prepare(
            "
            SELECT
                SUM(CASE WHEN status = 'wc-processing' THEN 1 ELSE 0 END) AS processing_count,
                SUM(CASE WHEN status = 'wc-completed' THEN 1 ELSE 0 END) AS completed_count
            FROM {$stats_table}
            WHERE parent_id = 0
              AND date_created >= %s
              AND date_created < %s
              AND status NOT IN ({$excluded_sql})
            ",
            $where_args
        ),
        ARRAY_A
    );

    $variation_items_sold = 0;
    $top_products = array();
    $top_categories = array();

    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $product_table)) === $product_table) {
        $variation_items_sold = $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT COALESCE(SUM(p.product_qty), 0)
                FROM {$product_table} p
                INNER JOIN {$stats_table} s ON s.order_id = p.order_id
                WHERE s.parent_id = 0
                  AND s.date_created >= %s
                  AND s.date_created < %s
                  AND s.status NOT IN ({$excluded_sql})
                  AND p.variation_id IS NOT NULL
                  AND p.variation_id <> 0
                ",
                $where_args
            )
        );

        $top_products = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT
                    p.product_id,
                    COALESCE(post.post_title, CONCAT('Product #', p.product_id)) AS product_name,
                    COALESCE(SUM(p.product_qty), 0) AS qty,
                    COALESCE(SUM(p.product_net_revenue), 0) AS net_revenue
                FROM {$product_table} p
                INNER JOIN {$stats_table} s ON s.order_id = p.order_id
                LEFT JOIN {$wpdb->posts} post ON post.ID = p.product_id
                WHERE s.parent_id = 0
                  AND s.date_created >= %s
                  AND s.date_created < %s
                  AND s.status NOT IN ({$excluded_sql})
                GROUP BY p.product_id
                ORDER BY qty DESC, net_revenue DESC
                LIMIT 5
                ",
                $where_args
            ),
            ARRAY_A
        );

        $top_categories = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT
                    terms.name AS category_name,
                    COALESCE(SUM(p.product_qty), 0) AS qty,
                    COALESCE(SUM(p.product_net_revenue), 0) AS net_revenue
                FROM {$product_table} p
                INNER JOIN {$stats_table} s ON s.order_id = p.order_id
                INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.product_id
                INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                INNER JOIN {$wpdb->terms} terms ON terms.term_id = tt.term_id
                WHERE s.parent_id = 0
                  AND s.date_created >= %s
                  AND s.date_created < %s
                  AND s.status NOT IN ({$excluded_sql})
                  AND tt.taxonomy = 'product_cat'
                GROUP BY terms.term_id
                ORDER BY qty DESC, net_revenue DESC
                LIMIT 5
                ",
                $where_args
            ),
            ARRAY_A
        );
    }

    return array(
        'processing_count'     => (int) ($status_counts['processing_count'] ?? 0),
        'completed_count'      => (int) ($status_counts['completed_count'] ?? 0),
        'variation_items_sold' => (int) ($variation_items_sold ?? 0),
        'top_products'         => is_array($top_products) ? $top_products : array(),
        'top_categories'       => is_array($top_categories) ? $top_categories : array(),
    );
}

function tisacase_report_excluded_statuses_v4() {
    $excluded = get_option('woocommerce_excluded_report_order_statuses', array());

    if (!is_array($excluded)) {
        $excluded = array();
    }

    $defaults = array(
        'pending',
        'failed',
        'cancelled',
        'refunded',
        'trash',
        'auto-draft',
        'checkout-draft',
    );

    $excluded = array_merge($excluded, $defaults);

    $final = array();

    foreach ($excluded as $status) {
        $status = trim((string) $status);

        if ($status === '') {
            continue;
        }

        if (strpos($status, 'wc-') !== 0) {
            $status = 'wc-' . $status;
        }

        $final[] = $status;
    }

    return array_values(array_unique($final));
}

/* ======================================================
 * ساخت متن پیام
 * ====================================================== */

function tisacase_report_build_message_v4($data, $is_test = false) {
    $title = $is_test ? '🧪 گزارش تست تیساکیس' : '📊 گزارش روزانه تیساکیس';

    $message = "<b>{$title}</b>\n";
    $message .= "🗓 تاریخ شمسی: <b>" . esc_html($data['date_jalali']) . "</b>\n";
    $message .= "📅 تاریخ میلادی: <code>" . esc_html($data['date_gregorian']) . "</code>\n";
    $message .= "⏰ زمان ارسال خودکار: <b>۰۰:۰۵</b>\n";
    $message .= "━━━━━━━━━━━━━━\n\n";

    $message .= "💰 <b>فروش و درآمد</b>\n";
    $message .= "▫️ کل فروش: <b>" . tisacase_report_money_text_v4($data['total_sales']) . "</b>\n";
    $message .= "▫️ فروش خالص: <b>" . tisacase_report_money_text_v4($data['net_sales']) . "</b>\n";
    $message .= "▫️ حمل‌ونقل: <b>" . tisacase_report_money_text_v4($data['shipping_total']) . "</b>\n";
    $message .= "▫️ مبلغ برگشتی: <b>" . tisacase_report_money_text_v4($data['refund_amount']) . "</b>\n";
    $message .= "▫️ تخفیف / کوپن: <b>" . tisacase_report_money_text_v4($data['coupon_amount']) . "</b>\n\n";

    $message .= "🧾 <b>سفارشات</b>\n";
    $message .= "▫️ تعداد سفارشات ثبت شده: <b>" . tisacase_report_num_v4($data['orders_count']) . "</b>\n";
    $message .= "▫️ سفارشات در حال انجام: <b>" . tisacase_report_num_v4($data['processing_count']) . "</b>\n";
    $message .= "▫️ سفارشات موفق / تکمیل شده: <b>" . tisacase_report_num_v4($data['completed_count']) . "</b>\n";
    $message .= "▫️ محصولات فروخته شده: <b>" . tisacase_report_num_v4($data['items_sold']) . "</b>\n";
    $message .= "▫️ متغیر فروخته شده: <b>" . tisacase_report_num_v4($data['variation_items_sold']) . "</b>\n\n";

    if (!empty($data['top_products'])) {
        $message .= "🏆 <b>محصولات پرفروش</b>\n";

        $i = 1;
        foreach ($data['top_products'] as $product) {
            $name = isset($product['product_name']) ? wp_strip_all_tags($product['product_name']) : 'محصول';
            $qty = isset($product['qty']) ? (int) $product['qty'] : 0;
            $revenue = tisacase_report_money_value_v4($product['net_revenue'] ?? 0);

            $message .= tisacase_report_num_v4($i) . ". " . esc_html($name) . "\n";
            $message .= "   تعداد: <b>" . tisacase_report_num_v4($qty) . "</b> | فروش خالص: <b>" . tisacase_report_money_text_v4($revenue) . "</b>\n";

            $i++;
        }

        $message .= "\n";
    }

    if (!empty($data['top_categories'])) {
        $message .= "📦 <b>دسته‌بندی‌های پرفروش</b>\n";

        $i = 1;
        foreach ($data['top_categories'] as $cat) {
            $name = isset($cat['category_name']) ? wp_strip_all_tags($cat['category_name']) : 'دسته‌بندی';
            $qty = isset($cat['qty']) ? (int) $cat['qty'] : 0;
            $revenue = tisacase_report_money_value_v4($cat['net_revenue'] ?? 0);

            $message .= tisacase_report_num_v4($i) . ". " . esc_html($name) . "\n";
            $message .= "   تعداد: <b>" . tisacase_report_num_v4($qty) . "</b> | فروش خالص: <b>" . tisacase_report_money_text_v4($revenue) . "</b>\n";

            $i++;
        }

        $message .= "\n";
    }

    $message .= "✅ وضعیت: گزارش روز قبل";

    return $message;
}

/* ======================================================
 * ارسال تلگرام
 * ====================================================== */

function tisacase_report_send_telegram_v4($message) {
    $token = TISACASE_TG_BOT_TOKEN;
    $chat_id = TISACASE_TG_CHAT_ID;

    if (empty($token) || $token === 'اینجا_توکن_ربات_تلگرام') {
        update_option('tisacase_report_last_error_v4', 'Telegram bot token is empty.', false);
        return false;
    }

    if (empty($chat_id) || $chat_id === 'اینجا_چت_آیدی') {
        update_option('tisacase_report_last_error_v4', 'Telegram chat ID is empty.', false);
        return false;
    }

    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';

    $response = wp_remote_post($url, array(
        'timeout' => 30,
        'body' => array(
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ),
    ));

    if (is_wp_error($response)) {
        update_option('tisacase_report_last_error_v4', $response->get_error_message(), false);
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    update_option('tisacase_report_last_response_v4', array(
        'time' => current_time('mysql'),
        'code' => $code,
        'body' => $body,
    ), false);

    if ($code >= 200 && $code < 300) {
        update_option('tisacase_report_last_error_v4', '', false);
        return true;
    }

    update_option('tisacase_report_last_error_v4', 'Telegram HTTP Error: ' . $code . ' - ' . $body, false);

    return false;
}

/* ======================================================
 * Helperها
 * ====================================================== */

function tisacase_report_money_value_v4($amount) {
    $amount = (float) $amount;

    if ((float) TISACASE_REPORT_CURRENCY_DIVIDER > 1) {
        $amount = $amount / (float) TISACASE_REPORT_CURRENCY_DIVIDER;
    }

    return $amount;
}

function tisacase_report_money_text_v4($amount) {
    return tisacase_report_num_v4(number_format(round((float) $amount))) . ' تومان';
}

function tisacase_report_num_v4($value) {
    $value = (string) $value;

    $en = array('0','1','2','3','4','5','6','7','8','9');
    $fa = array('۰','۱','۲','۳','۴','۵','۶','۷','۸','۹');

    return str_replace($en, $fa, $value);
}

function tisacase_report_gregorian_to_jalali_text_v4($ymd) {
    $parts = explode('-', $ymd);

    if (count($parts) !== 3) {
        return $ymd;
    }

    list($jy, $jm, $jd) = tisacase_report_gregorian_to_jalali_v4((int) $parts[0], (int) $parts[1], (int) $parts[2]);

    $months = array(
        1 => 'فروردین',
        2 => 'اردیبهشت',
        3 => 'خرداد',
        4 => 'تیر',
        5 => 'مرداد',
        6 => 'شهریور',
        7 => 'مهر',
        8 => 'آبان',
        9 => 'آذر',
        10 => 'دی',
        11 => 'بهمن',
        12 => 'اسفند',
    );

    return tisacase_report_num_v4($jd) . ' ' . $months[$jm] . ' ' . tisacase_report_num_v4($jy);
}

function tisacase_report_gregorian_to_jalali_v4($gy, $gm, $gd) {
    $g_d_m = array(0,31,59,90,120,151,181,212,243,273,304,334);

    if ($gy > 1600) {
        $jy = 979;
        $gy -= 1600;
    } else {
        $jy = 0;
        $gy -= 621;
    }

    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;

    $days = (365 * $gy)
        + (int)(($gy2 + 3) / 4)
        - (int)(($gy2 + 99) / 100)
        + (int)(($gy2 + 399) / 400)
        - 80
        + $gd
        + $g_d_m[$gm - 1];

    $jy += 33 * (int)($days / 12053);
    $days %= 12053;

    $jy += 4 * (int)($days / 1461);
    $days %= 1461;

    if ($days > 365) {
        $jy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }

    if ($days < 186) {
        $jm = 1 + (int)($days / 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + (int)(($days - 186) / 30);
        $jd = 1 + (($days - 186) % 30);
    }

    return array($jy, $jm, $jd);
}