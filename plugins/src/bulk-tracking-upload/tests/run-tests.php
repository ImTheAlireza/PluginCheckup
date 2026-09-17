<?php
/**
 * تست فرم پیگیری سفارش (احراز با شماره سفارش + موبایل ثبت‌شده) بدون وردپرس.
 * اجرا:  php tests/run-tests.php
 *
 * @package Bulk_Tracking_Upload
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

/* ---------- استاب‌های وردپرس/ووکامرس ---------- */

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);

function add_action(...$a) {}
function add_shortcode(...$a) {}
function add_filter(...$a) {}
function sanitize_text_field($s) { return is_string($s) ? trim(strip_tags($s)) : ''; }
function wp_unslash($s) { return is_string($s) ? stripslashes($s) : $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function wp_kses_post($s) { return $s; }
function __($s, $d = null) { return $s; }

$GLOBALS['bwt_transients'] = [];
function get_transient($k) { return isset($GLOBALS['bwt_transients'][$k]) ? $GLOBALS['bwt_transients'][$k] : false; }
function set_transient($k, $v, $ttl = 0) { $GLOBALS['bwt_transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['bwt_transients'][$k]); return true; }

class BWT_Fake_Order {
    private $d;
    public function __construct($d) { $this->d = $d; }
    public function get_billing_phone() { return $this->d['phone']; }
    public function get_billing_first_name() { return $this->d['first']; }
    public function get_billing_last_name() { return $this->d['last']; }
    public function get_billing_address_1() { return $this->d['addr1']; }
    public function get_billing_address_2() { return $this->d['addr2']; }
    public function get_billing_city() { return $this->d['city']; }
    public function get_billing_state() { return $this->d['state']; }
    public function get_billing_postcode() { return $this->d['postcode']; }
    public function get_meta($k) { return isset($this->d['meta'][$k]) ? $this->d['meta'][$k] : ''; }
    public function get_date_created() { return null; }
}

$GLOBALS['bwt_orders'] = [];
function wc_get_order($id) { return isset($GLOBALS['bwt_orders'][$id]) ? $GLOBALS['bwt_orders'][$id] : false; }
function get_post_meta($id, $k, $single = false) { return ''; } // مسیر $order->get_meta تست شود

require_once dirname(__DIR__) . '/bulk-tracking-upload.php';

/* ---------- هارنس ---------- */

$pass = 0; $fail = 0;
function t($name, $ok, $info = '') {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  OK   $name\n"; }
    else { $fail++; echo "  FAIL $name" . ($info !== '' ? "  >> $info" : '') . "\n"; }
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

$GLOBALS['bwt_orders'][101] = new BWT_Fake_Order([
    'phone' => '09123456789',
    'first' => 'علی', 'last' => 'رضایی',
    'addr1' => 'خ ولیعصر', 'addr2' => '', 'city' => 'تهران', 'state' => 'تهران', 'postcode' => '1234567890',
    'meta' => ['_tracking_code' => 'TRACK123456789'],
]);
$GLOBALS['bwt_orders'][102] = new BWT_Fake_Order([
    'phone' => '+98 935-111-2233',
    'first' => 'سارا', 'last' => 'محمدی',
    'addr1' => 'خ آزادی', 'addr2' => '', 'city' => 'شیراز', 'state' => 'فارس', 'postcode' => '',
    'meta' => [], // هنوز کد رهگیری ندارد
]);

function submit($order_id, $phone) {
    $_POST = ['tracking_submit' => '1', 'order_id' => $order_id, 'billing_phone' => $phone];
    $html = bwt_tracking_search_form();
    $_POST = [];
    return $html;
}

echo "--- 1) نرمال‌سازی شماره موبایل ---\n";
t('شمارهٔ صفر-پیشرو', bwt_normalize_phone('09123456789') === '989123456789', bwt_normalize_phone('09123456789'));
t('رقم‌های فارسی + جداکننده', bwt_normalize_phone('۰۹۱۲ ۳۴۵ ۶۷۸۹') === '989123456789', bwt_normalize_phone('۰۹۱۲ ۳۴۵ ۶۷۸۹'));
t('پیشوند +98', bwt_normalize_phone('+989123456789') === '989123456789');
t('پیشوند 0098', bwt_normalize_phone('00989123456789') === '989123456789');
t('بدون پیشوند (۱۰ رقم)', bwt_normalize_phone('9123456789') === '989123456789');
t('شمارهٔ نامعتبر → رشتهٔ خالی', bwt_normalize_phone('12345') === '' && bwt_normalize_phone('') === '');
t('تطبیق قالب‌های متفاوت یک شماره', bwt_phones_match('09123456789', '+98 912-345-6789') === true);
t('شمارهٔ متفاوت → عدم تطبیق', bwt_phones_match('09123456789', '09120000000') === false);
t('شمارهٔ ثبت‌شدهٔ خالی → عدم تطبیق (نه خطای PHP)', bwt_phones_match('', '09123456789') === false);

echo "--- 2) احراز هویت فرم پیگیری ---\n";
$html = submit('101', '۰۹۱۲۳۴۵۶۷۸۹');
t('سفارش + موبایل درست → اطلاعات نمایش داده می‌شود', strpos($html, 'TRACK123456789') !== false && strpos($html, 'علی') !== false);
t('لینک پیگیری پست ساخته می‌شود', strpos($html, 'tracking.post.ir/?id=TRACK123456789') !== false);

$html = submit('101', '09120000000');
t('موبایل غلط → هیچ PII نمایش داده نمی‌شود', strpos($html, 'علی') === false && strpos($html, 'TRACK123456789') === false && strpos($html, 'ولیعصر') === false);
t('موبایل غلط → پیام خطای یکسان', strpos($html, 'معتبر نیست') !== false);

$html = submit('999', '09123456789');
t('شماره سفارش نامعتبر → همان پیام (لو نرفتن وجود سفارش)', strpos($html, 'معتبر نیست') !== false);

$html = submit('101', '');
t('موبایل خالی → پیام الزامی‌بودن', strpos($html, 'الزامی است') !== false && strpos($html, 'علی') === false);

$html = submit('', '09123456789');
t('شماره سفارش خالی → پیام الزامی‌بودن', strpos($html, 'الزامی است') !== false);

echo "--- 3) محدودسازی تلاش ناموفق (ضد درو) ---\n";
$GLOBALS['bwt_transients'] = [];
for ($i = 0; $i < 10; $i++) {
    submit('101', '0912000000' . $i);
}
t('۱۰ تلاش ناموفق شمرده شد', (int) get_transient('bwt_track_fail_' . md5('203.0.113.7')) === 10);
$html = submit('101', '09123456789');
t('بعد از سقف تلاش، حتی رمز درست هم رد می‌شود', strpos($html, '۱۵ دقیقه') !== false && strpos($html, 'TRACK123456789') === false);

$GLOBALS['bwt_transients'] = [];
$html = submit('102', '09351112233');
t('سفارش بدون کد رهگیری → پیام «در حال آماده‌سازی»', strpos($html, 'آماده‌سازی') !== false && strpos($html, 'سارا') === false);
submit('101', '09120000000'); // یک شکست
submit('101', '09123456789'); // موفق → ریست
t('پس از موفقیت شمارندهٔ تلاش ریست می‌شود', get_transient('bwt_track_fail_' . md5('203.0.113.7')) === false);

echo "--- 4) خودِ فرم ---\n";
$html = bwt_tracking_search_form();
t('فیلد موبایل اجباری در فرم هست', strpos($html, 'name="billing_phone"') !== false && strpos($html, 'bwt_billing_phone') !== false);
t('فیلد شماره سفارش همچنان هست', strpos($html, 'name="order_id"') !== false);

echo "\nنتیجه: $pass موفق، $fail ناموفق\n";
exit($fail === 0 ? 0 : 1);
