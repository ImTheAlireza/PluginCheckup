<?php
/**
 * تست منطق «شرط ارسال سفارش» (فقط سفارش‌های پرداخت‌شده) بدون وردپرس.
 * اجرا:  php tests/run-tests.php
 *
 * @package WC_Telegram_Orders
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

require_once __DIR__ . '/stubs.php';
require_once dirname(__DIR__) . '/wc-telegram-orders.php';

/* ---------- زیرکلاس تست: تلگرام و لاگ واقعی جایگزین می‌شوند ---------- */

class WCTO_Testable extends WC_Telegram_Orders {
    public $sent_messages = [];
    public $logs          = [];

    public function send_to_all_chats($message, $chat_ids_raw = null) {
        $this->sent_messages[] = $message;
        return ['ok' => true, 'sent' => ['-100123' => 1], 'message' => ''];
    }

    public function build_message($order) {
        return 'پیام سفارش #' . $order->get_order_number();
    }

    public function log($level, $channel, $event, $message, $context = [], $object_id = 0) {
        $this->logs[] = [
            'level'   => $level,
            'channel' => $channel,
            'event'   => $event,
            'message' => $message,
            'context' => $context,
            'id'      => $object_id,
        ];
        return true;
    }

    public function has_event($event) {
        foreach ($this->logs as $row) {
            if ($row['event'] === $event) {
                return true;
            }
        }
        return false;
    }

    /** فراخوانی متدهای private */
    public function call($name, $args = []) {
        $rm = new ReflectionMethod(get_class($this), $name);
        $rm->setAccessible(true);
        return $rm->invokeArgs($this, $args);
    }
}

/* ---------- ابزار تست ---------- */

$pass = 0;
$fail = 0;

function t($label, $cond, $extra = '') {
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  OK   " . $label . "\n";
    } else {
        $fail++;
        echo "  FAIL " . $label . ($extra !== '' ? '  >> ' . $extra : '') . "\n";
    }
}

/** محیط تازه: تنظیمات دلخواه + یک افزونهٔ تستی */
function setup($settings = [], $orders = []) {
    wcto_reset_env();
    $GLOBALS['wcto_http'] = [];
    update_option(WC_TELEGRAM_ORDERS_OPTION, array_merge([
        'enabled'    => 'yes',
        'bot_token'  => 'test:token',
        'chat_ids'   => '-100123',
    ], $settings));
    foreach ($orders as $order) {
        $GLOBALS['wcto_orders'][$order->get_id()] = $order;
    }
    $plugin = new WCTO_Testable();
    $plugin->reset_settings_cache();
    return $plugin;
}

function send_events() {
    return wcto_cron_hooks('wc_telegram_send_order');
}

function status_events() {
    return wcto_cron_hooks('wc_telegram_send_status');
}

/* ---------- ۱) پیش‌فرض‌ها و تنظیمات ---------- */

echo "--- 1) پیش‌فرض‌ها و ذخیرهٔ تنظیمات ---\n";

$p = setup();
$defaults = $p->defaults();
t('پیش‌فرض: شرط پرداخت روشن است', isset($defaults['paid_gate']) && $defaults['paid_gate'] === 'yes', var_export(isset($defaults['paid_gate']) ? $defaults['paid_gate'] : null, true));
t('پیش‌فرض: وضعیت مجاز = processing', isset($defaults['send_statuses']) && $defaults['send_statuses'] === 'processing', var_export(isset($defaults['send_statuses']) ? $defaults['send_statuses'] : null, true));
t('فهرست وضعیت‌های مجاز (پیش‌فرض)', $p->call('send_allowed_statuses') === ['processing'], implode(',', $p->call('send_allowed_statuses')));

$out = $p->sanitize_settings([
    '_tab'          => 'orders',
    'paid_gate'     => 'yes',
    'send_statuses' => ' Processing , completed , WC-ON-HOLD ',
]);
t('ذخیره: اسلاگ‌ها نرمال و یکتا می‌شوند', $out['send_statuses'] === 'processing,completed,on-hold', $out['send_statuses']);
t('ذخیره: شرط پرداخت روشن می‌ماند', $out['paid_gate'] === 'yes', $out['paid_gate']);

$out = $p->sanitize_settings(['_tab' => 'orders', 'paid_gate' => 'yes', 'send_statuses' => '  ']);
t('ذخیره: فیلد خالی → پیش‌فرض processing', $out['send_statuses'] === 'processing', $out['send_statuses']);

$out = $p->sanitize_settings(['_tab' => 'orders']);
t('ذخیره: تیک نخورده → شرط پرداخت خاموش', $out['paid_gate'] === 'no', $out['paid_gate']);

/* ---------- ۲) قضاوت وضعیت ---------- */

echo "--- 2) مجاز بودن ارسال بر اساس وضعیت ---\n";

$p = setup();
$cases = [
    'pending'        => false,
    'checkout-draft' => false,
    'on-hold'        => false,
    'cancelled'      => false,
    'failed'         => false,
    'refunded'       => false,
    'trash'          => false,
    'processing'     => true,
    'completed'      => false,
];
foreach ($cases as $status => $expected) {
    $order = new WC_Order(101, $status);
    $got   = $p->call('order_send_is_allowed', [$order]);
    t('وضعیت «' . $status . '» → ' . ($expected ? 'مجاز' : 'غیرمجاز'), $got === $expected, var_export($got, true));
}

$p2 = setup(['send_statuses' => 'processing,completed']);
t('با فهرست سفارشی، completed هم مجاز است', $p2->call('order_send_is_allowed', [new WC_Order(102, 'completed')]) === true);

$p3 = setup(['paid_gate' => 'no']);
t('شرط خاموش: سفارش pending با آدرس و اقلام (رفتار قبلی) مجاز است', $p3->call('order_send_is_allowed', [new WC_Order(103, 'pending')]) === true);
t('شرط خاموش: on-hold مجاز است (رفتار قبلی)', $p3->call('order_send_is_allowed', [new WC_Order(104, 'on-hold')]) === true);

t('وضعیت مرده: cancelled', $p->call('order_status_is_dead', ['cancelled']) === true);
t('وضعیت مرده: auto-cancelled', $p->call('order_status_is_dead', ['auto-cancelled']) === true);
t('وضعیت زنده: processing', $p->call('order_status_is_dead', ['processing']) === false);

/* ---------- ۳) ساخت سفارش جدید ---------- */

echo "--- 3) ساخت سفارش جدید (on_new_order) ---\n";

$order = new WC_Order(201, 'pending');
$p     = setup([], [$order]);
$p->on_new_order(201, null);
t('سفارش در انتظار پرداخت به تلگرام نمی‌رود', count($p->sent_messages) === 0 && count(send_events()) === 0, 'رویداد=' . count(send_events()));
t('پرچم انتظار ثبت می‌شود', $order->get_meta('_wc_telegram_pending') === 'yes');
t('یادداشت «هنوز پرداخت نشده» در سفارش', strpos(implode("\n", $order->wcto_notes()), 'پرداخت نشده') !== false, implode(' | ', $order->wcto_notes()));
t('رویداد لاگ order_pending_payment', $p->has_event('order_pending_payment'));

$order = new WC_Order(202, 'processing');
$p     = setup([], [$order]);
$p->on_new_order(202, null);
t('سفارش پرداخت‌شده زمان‌بندی ارسال می‌گیرد', count(send_events()) === 1, 'رویداد=' . count(send_events()));
t('پرچم انتظار ثبت نمی‌شود', $order->get_meta('_wc_telegram_pending') === '');

$order = new WC_Order(203, 'cancelled');
$p     = setup([], [$order]);
$p->on_new_order(203, null);
t('سفارش لغوشده هیچ پیام/زمان‌بندی نمی‌گیرد', count($p->sent_messages) === 0 && count(send_events()) === 0);
t('سفارش لغوشده پرچم انتظار نمی‌گیرد', $order->get_meta('_wc_telegram_pending') === '');
t('رویداد لاگ order_skipped_dead', $p->has_event('order_skipped_dead'));

/* ---------- ۴) تغییر وضعیت به «در حال انجام» ---------- */

echo "--- 4) تغییر وضعیت: پرداخت شد ---\n";

$order = new WC_Order(301, 'processing', ['meta' => ['_wc_telegram_pending' => 'yes']]);
$p     = setup([], [$order]);
$returned = $p->on_status_changed(301, 'pending', 'processing', $order);
t('با پرداخت، پیام سفارش زمان‌بندی می‌شود', count(send_events()) === 1, 'رویداد=' . count(send_events()));
t('پیام کوتاه تغییر وضعیت نمی‌رود (پیام کامل کافی است)', count(status_events()) === 0, 'رویداد=' . count(status_events()));

// همان مسیر وقتی «ارسال پیام تغییر وضعیت» خاموش است
$order = new WC_Order(302, 'processing', ['meta' => ['_wc_telegram_pending' => 'yes']]);
$p     = setup(['status_enabled' => 'no'], [$order]);
$p->on_status_changed(302, 'pending', 'processing', $order);
t('با پیام وضعیت خاموش هم سفارش پرداخت‌شده معرفی می‌شود', count(send_events()) === 1, 'رویداد=' . count(send_events()));

// لغو قبل از پرداخت
$order = new WC_Order(303, 'cancelled', ['meta' => ['_wc_telegram_pending' => 'yes']]);
$p     = setup([], [$order]);
$p->on_status_changed(303, 'pending', 'cancelled', $order);
t('لغو قبل از پرداخت: پیامی نمی‌رود', count($p->sent_messages) === 0 && count(send_events()) === 0 && count(status_events()) === 0);
t('لغو قبل از پرداخت: پرچم انتظار پاک می‌شود', $order->get_meta('_wc_telegram_pending') === '');

// on-hold (هنوز پرداخت نشده)
$order = new WC_Order(304, 'on-hold', ['meta' => ['_wc_telegram_pending' => 'yes']]);
$p     = setup([], [$order]);
$p->on_status_changed(304, 'pending', 'on-hold', $order);
t('on-hold قبل از پرداخت: پیامی نمی‌رود', count(send_events()) === 0, 'رویداد=' . count(send_events()));
t('on-hold: پرچم انتظار می‌ماند', $order->get_meta('_wc_telegram_pending') === 'yes');

/* ---------- ۵) اجرای کرون ارسال ---------- */

echo "--- 5) پردازشگر کرون (process_order_send) ---\n";

$order = new WC_Order(401, 'pending');
$p     = setup([], [$order]);
$p->process_order_send(401);
t('کرون، سفارش پرداخت‌نشده را ارسال نمی‌کند', count($p->sent_messages) === 0);
t('کرون، پرچم انتظار را نگه می‌دارد', $order->get_meta('_wc_telegram_pending') === 'yes');

$order = new WC_Order(402, 'cancelled', ['meta' => ['_wc_telegram_attempts' => 2]]);
$p     = setup([], [$order]);
$p->process_order_send(402);
t('کرون، سفارش لغوشده را ارسال نمی‌کند', count($p->sent_messages) === 0);

$order = new WC_Order(403, 'processing');
$p     = setup([], [$order]);
$p->process_order_send(403);
t('کرون، سفارش پرداخت‌شده را ارسال می‌کند', count($p->sent_messages) === 1, var_export($p->sent_messages, true));
t('پرچم ارسال ثبت می‌شود', $order->get_meta('_wc_telegram_sent') === 'yes');

$order = new WC_Order(404, 'pending', ['meta' => ['_wc_telegram_pending' => 'yes']]);
$p     = setup([], [$order]);
$p->process_order_send(404);
t('مسیر معوق: سفارش پرداخت‌نشده ارسال نمی‌شود', count($p->sent_messages) === 0);
t('مسیر معوق: پرچم انتظار می‌ماند', $order->get_meta('_wc_telegram_pending') === 'yes');

$order = new WC_Order(405, 'processing', ['meta' => ['_wc_telegram_pending' => 'yes']]);
$p     = setup([], [$order]);
$p->process_order_send(405);
t('مسیر معوق: با پرداخت شدن ارسال می‌شود', count($p->sent_messages) === 1);
t('مسیر معوق: پرچم‌ها پاک می‌شوند', $order->get_meta('_wc_telegram_pending') === '' && $order->get_meta('_wc_telegram_sent') === 'yes');

/* ---------- ۶) جاروی دوره‌ای ---------- */

echo "--- 6) جاروی سفارش‌های معوق ---\n";

$waiting   = new WC_Order(501, 'pending', ['meta' => ['_wc_telegram_pending' => 'yes']]);
$paid      = new WC_Order(502, 'processing', ['meta' => ['_wc_telegram_pending' => 'yes']]);
$cancelled = new WC_Order(503, 'cancelled', ['meta' => ['_wc_telegram_pending' => 'yes']]);
$p         = setup([], [$waiting, $paid, $cancelled]);
$p->sweep_pending_orders();
t('جارو: سفارش پرداخت‌شده زمان‌بندی می‌شود', count(send_events()) === 1, 'رویداد=' . count(send_events()));
t('جارو: سفارش در انتظار پرداخت رها نمی‌شود و ارسال هم نمی‌شود', $waiting->get_meta('_wc_telegram_pending') === 'yes');
t('جارو: سفارش لغوشده از فهرست معوق‌ها خارج می‌شود', $cancelled->get_meta('_wc_telegram_pending') === '');

/* ---------- ۷) رندر تب تنظیمات ---------- */

echo "--- 7) رندر تب «ارسال سفارشات جدید» ---\n";

$p = setup(['send_statuses' => 'processing,completed']);
$rm = new ReflectionMethod($p, 'render_orders_tab');
$rm->setAccessible(true);
ob_start();
$rm->invoke($p, $p->get_settings(), 'wc_telegram_orders_settings');
$html = ob_get_clean();
t('کلید «فقط سفارش‌های پرداخت‌شده ارسال شوند» در فرم است', strpos($html, '[paid_gate]') !== false);
t('فیلد «وضعیت‌های مجاز ارسال» در فرم است', strpos($html, '[send_statuses]') !== false);
t('مقدار ذخیره‌شده در فیلد نمایش داده می‌شود', strpos($html, 'value="processing,completed"') !== false, substr($html, (int) strpos($html, 'wc-tg-send-statuses'), 120));
t('راهنمای اسلاگ وضعیت‌های سایت چاپ می‌شود', strpos($html, 'pending, processing, on-hold') !== false, substr($html, (int) strpos($html, 'وضعیت‌های این سایت'), 160));
t('کارت «شرط ارسال سفارش» وجود دارد', strpos($html, 'شرط ارسال سفارش') !== false);

echo "--- 8) سفارش منقضی‌شده (بیش از ۲۴ ساعت معوق) ---\n";

$old = new WC_Order(601, 'pending', ['meta' => ['_wc_telegram_pending' => 'yes'], 'created' => time() - (2 * DAY_IN_SECONDS)]);
$p   = setup([], [$old]);
$p->sweep_pending_orders();
t('جارو: سفارش قدیمیِ پرداخت‌نشده منقضی می‌شود', $old->get_meta('_wc_telegram_expired') === 'yes' && $old->get_meta('_wc_telegram_pending') === '', var_export($old->wcto_meta(), true));
t('جارو: سفارش منقضی ارسال نمی‌شود', count(send_events()) === 0 && count($p->sent_messages) === 0);

$old->set_status('processing');
$p = setup([], [$old]);
$p->flush_pending_message(601, $old);
t('پرداخت دیرهنگامِ سفارش منقضی → زمان‌بندی ارسال', count(send_events()) === 1, 'رویداد=' . count(send_events()));
t('پرچم انتظار دوباره ثبت می‌شود', $old->get_meta('_wc_telegram_pending') === 'yes');
$p->process_order_send(601);
t('سفارش منقضیِ پرداخت‌شده ارسال می‌شود', count($p->sent_messages) === 1, var_export($p->sent_messages, true));
t('پرچم انقضا بعد از ارسال پاک می‌شود', $old->get_meta('_wc_telegram_expired') === '' && $old->get_meta('_wc_telegram_sent') === 'yes');

echo "--- 9) پاک‌سازی هنگام حذف افزونه ---\n";

preg_match_all("/'(_wc_telegram_[a-z_]+)'/", file_get_contents(dirname(__DIR__) . '/wc-telegram-orders.php'), $mm);
$used_keys = array_values(array_unique($mm[1]));
$uninstall = file_get_contents(dirname(__DIR__) . '/uninstall.php');
$missing_keys = [];
foreach ($used_keys as $key) {
    if (strpos($uninstall, $key) === false) {
        $missing_keys[] = $key;
    }
}
t('همهٔ کلیدهای متای افزونه در uninstall.php پاک می‌شوند (' . count($used_keys) . ' کلید)', $missing_keys === [], 'جامانده: ' . implode(', ', $missing_keys));

/* ---------- نتیجه ---------- */

echo "\nنتیجه: {$pass} موفق، {$fail} ناموفق\n";
exit($fail === 0 ? 0 : 1);
