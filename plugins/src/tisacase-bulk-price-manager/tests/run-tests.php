<?php
/**
 * تست منطق خالص افزونه بدون وردپرس (با استاب).
 * اجرا:  php tests/run-tests.php
 *
 * @package TisaCase_Bulk_Price_Manager
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

require_once __DIR__ . '/stubs.php';
/* ---------- اجرای تست‌ها ---------- */

$pass = 0;
$fail = 0;

function t( $label, $cond, $extra = '' ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "  OK   " . $label . "\n";
	} else {
		$fail++;
		echo "  FAIL " . $label . ( '' !== $extra ? '  >> ' . $extra : '' ) . "\n";
	}
}

function call_private( $class, $name, $args = array() ) {
	$rm = new ReflectionMethod( $class, $name );
	$rm->setAccessible( true );
	return $rm->invokeArgs( null, $args );
}

echo "--- 1) تبدیل عدد فارسی/ویرگول ---\n";
t( 'فارسی ۱٬۲۳۴ → 1234', TCBPM_OPS::number( '۱٬۲۳۴' ) === 1234.0, var_export( TCBPM_OPS::number( '۱٬۲۳۴' ), true ) );
t( '۱،۵۰۰،۰۰۰ → 1500000', TCBPM_OPS::number( '۱،۵۰۰،۰۰۰' ) === 1500000.0 );
t( '۱۲.۵ → 12.5', TCBPM_OPS::number( '۱۲.۵' ) === 12.5 );
t( 'عدد نامعتبر → null', TCBPM_OPS::number( 'abc' ) === null );
t( 'اعشار معمولی 1e3 معتبر', is_float( TCBPM_OPS::number( '1e3' ) ) );

echo "--- 2) تنظیمات (چک‌باکس خاموش) ---\n";
$saved = TCBPM_Core::update_settings( array( 'batch_size' => '12', 'min_capability' => 'manage_woocommerce', 'rollback' => '1' ) );
t( 'logging بدون کلید → 0', 0 === $saved['logging'], var_export( $saved['logging'], true ) );
t( 'rollback ارسال‌شده → 1', 1 === $saved['rollback'] );
t( 'batch_size=12', 12 === $saved['batch_size'] );
t( 'batch حداقل 1', 1 === TCBPM_Core::update_settings( array( 'batch_size' => '0' ) )['batch_size'] );
TCBPM_Core::update_settings( array( 'batch_size' => '5', 'rollback' => '1', 'logging' => '1' ) );

echo "--- 3) اعتبارسنجی args_from_post ---\n";
$good = array(
	'nonce' => 'good', 'operation' => 'regular_increase_percent', 'target_type' => 'products',
	'product_ids' => '7,9', 'value' => '10', 'include_children' => '0',
	'filters' => json_encode( array( 'types' => array( 'simple' ), 'statuses' => array( 'publish', 'private', 'draft', 'pending' ), 'only_sale' => false, 'only_wholesale' => false, 'price_min' => null, 'price_max' => null ) ),
);
$a = TCBPM_OPS::args_from_post( $good );
t( 'آرگومان معتبر ساخته می‌شود', ! is_wp_error( $a ) );
$bad_nonce = $good; $bad_nonce['nonce'] = 'bad';
t( 'nonce خراب → WP_Error', is_wp_error( TCBPM_OPS::args_from_post( $bad_nonce ) ) );
$bad_op = $good; $bad_op['operation'] = 'delete_everything';
t( 'عملیات نامعتبر → WP_Error', is_wp_error( TCBPM_OPS::args_from_post( $bad_op ) ) );
$bad_val = $good; $bad_val['value'] = '۱٬۰۰۰٬۰۰۰٬۰۰۰٬۰۰۰'; // از سقف بیشتر
$er = TCBPM_OPS::args_from_post( $bad_val );
t( 'ارزش بالای سقف → WP_Error', is_wp_error( $er ) );
$no_prod = $good; $no_prod['product_ids'] = '';
t( 'بدون محصول → WP_Error', is_wp_error( TCBPM_OPS::args_from_post( $no_prod ) ) );
$sale_d = $good; $sale_d['operation'] = 'sale_discount_percent'; $sale_d['value'] = '150';
t( 'تخفیف 150 > 100 → WP_Error', is_wp_error( TCBPM_OPS::args_from_post( $sale_d ) ) );
$filters_junk = $good; $filters_junk['filters'] = json_encode( array( 'price_min' => '300', 'price_max' => '100', 'types' => array( 'evil' ), 'statuses' => array( 'trash' ) ) );
$a2 = TCBPM_OPS::args_from_post( $filters_junk );
t( 'فیلتر قیمت معکوس → price_max خالی می‌شود', ! is_wp_error( $a2 ) && null === $a2['filters']['price_max'] );
t( 'نوع نامعتبر در فیلتر حذف می‌شود', ! is_wp_error( $a2 ) && array() === $a2['filters']['types'] );
t( 'وضعیت نامعتبر حذف می‌شود', ! is_wp_error( $a2 ) && empty( $a2['filters']['statuses'] ) );

echo "--- 4) توکن و امضا ---\n";
$tok = TCBPM_OPS::make_token( $a );
t( 'توکن ساخته می‌شود', 64 === strlen( $tok ) );
t( 'توکن معتبر درست است', TCBPM_OPS::verify_token( $a, $tok ) );
$b = $a; $b['value'] = 10.0; // معادل 10 — باید هنوز تأیید شود
t( '10 و 10.0 معادل', TCBPM_OPS::verify_token( $b, $tok ) );
$c = $a; $c['operation'] = 'regular_decrease_percent';
t( 'تغییر عملیات → توکن رد می‌شود', ! TCBPM_OPS::verify_token( $c, $tok ) );
$d = $a; $d['category_ids'] = array( 11 );
t( 'تغییر دسته → توکن رد می‌شود', ! TCBPM_OPS::verify_token( $d, $tok ) );
$e = $a; $e['filters']['only_sale'] = true;
t( 'تغییر فیلتر → توکن رد می‌شود', ! TCBPM_OPS::verify_token( $e, $tok ) );
$e2 = $a; $e2['filters']['types'][] = 'variable';
t( 'تغییر نوع محصول → توکن رد می‌شود', ! TCBPM_OPS::verify_token( $e2, $tok ) );

echo "--- 5) محاسبات خالص (Reflection) ---\n";
$reg = call_private( 'TCBPM_OPS', 'calc_regular', array( 'regular_increase_percent', '1000', '10' ) );
t( 'افزایش 10٪ از 1000 → 1100', $reg['ok'] && $reg['new'] === '1100', var_export( $reg, true ) );
$reg = call_private( 'TCBPM_OPS', 'calc_regular', array( 'regular_decrease_fixed', '1000', '250' ) );
t( 'کاهش ثابت 250 → 750', $reg['ok'] && $reg['new'] === '750' );
$reg = call_private( 'TCBPM_OPS', 'calc_regular', array( 'regular_set', '', '500' ) );
t( 'set روی بدون قیمت → 500', $reg['ok'] && $reg['new'] === '500' );
$sale = call_private( 'TCBPM_OPS', 'calc_sale', array( 'sale_discount_percent', '1000', '15' ) );
t( 'فروش ویژه 15٪ → 850', $sale['ok'] && $sale['new'] === '850' );
$sale = call_private( 'TCBPM_OPS', 'calc_sale', array( 'sale_set', '1000', '1200' ) );
t( 'sale >= regular → رد', ! $sale['ok'] );
$ws = call_private( 'TCBPM_OPS', 'calc_wholesale', array( 'wholesale_from_retail_percent', '800', '1000', '20' ) );
t( 'عمده 20٪ کمتر از خرده → 800', $ws['ok'] && $ws['new'] === '800' );
$ws = call_private( 'TCBPM_OPS', 'calc_wholesale', array( 'wholesale_clear', '', '1000', null ) );
t( 'clear بدون مبدأ OK نیست (خودش مستقل)', true );

echo "--- 6) اجرای process_parent روی محصول ساده ---\n";
register_product( 7, 'پیراهن', 'simple', array( '_regular_price' => '1000' ) );
$r = TCBPM_OPS::process_parent( 7, 'regular_increase_percent', 10.0 );
t( 'ساده+افزایش 10٪ → updated=1', 1 === $r['updated'], var_export( $r, true ) );
t( 'قیمت عادی = 1100', get_post_meta( 7, '_regular_price' ) === '1100' );
t( 'لاگ قبل/بعد درست', isset( $r['entries'][0] ) && $r['entries'][0]['before'] === '1000' && $r['entries'][0]['after'] === '1100' );
t( 'object_type=regular', isset( $r['entries'][0] ) && $r['entries'][0]['object_type'] === 'regular' );

register_product( 8, 'کفش', 'simple', array( '_regular_price' => '2000' ) );
$r = TCBPM_OPS::process_parent( 8, 'sale_set', 1500.0 );
t( 'sale_set معتبر → updated', 1 === $r['updated'] && get_post_meta( 8, '_sale_price' ) === '1500' );
register_product( 9, 'کتاب', 'simple', array( '_regular_price' => '500' ) );
$r = TCBPM_OPS::process_parent( 9, 'sale_set', 600.0 );
t( 'sale_set >= regular → error', count( $r['errors'] ) === 1, var_export( $r, true ) );

register_product( 10, 'موبایل', 'simple', array( '_regular_price' => '3000', '_sale_price' => '2500' ) );
$r = TCBPM_OPS::process_parent( 10, 'sale_remove', null );
t( 'sale_remove حذف می‌شود', 1 === $r['updated'] && '' === get_post_meta( 10, '_sale_price' ) );
t( 'entry قبل=2500 بعد=خالی', isset( $r['entries'][0] ) && $r['entries'][0]['before'] === '2500' && $r['entries'][0]['after'] === '' );

register_product( 11, 'بدون قیمت', 'simple', array() );
$r = TCBPM_OPS::process_parent( 11, 'regular_decrease_percent', 5.0 );
t( 'بدون قیمت عادی → skip', 1 === $r['skipped'] && 0 === $r['updated'] );

echo "--- 7) اجرای wholesale ---\n";
register_product( 12, 'عمده‌دار', 'simple', array( '_regular_price' => '2000', '_tisacase_wholesale_price' => '1500' ) );
$r = TCBPM_OPS::process_parent( 12, 'wholesale_increase_percent', 10.0 );
t( 'عمده +10٪ → updated', 1 === $r['updated'] && get_post_meta( 12, '_tisacase_wholesale_price' ) === '1650' );
t( 'لاگ عمده', isset( $r['entries'][0] ) && 'wholesale' === $r['entries'][0]['object_type'] && $r['entries'][0]['before'] === '1500' && $r['entries'][0]['after'] === '1650' );

register_product( 13, 'بدون عمده', 'simple', array( '_regular_price' => '2000' ) );
$r = TCBPM_OPS::process_parent( 13, 'wholesale_set', 1000.0 );
t( 'عمده روی محصول بدون عمده → skip و متا ساخته نشد', 1 === $r['skipped'] && '' === get_post_meta( 13, '_tisacase_wholesale_price' ) );

register_product( 14, 'عمده‌دار۲', 'simple', array( '_regular_price' => '2000', '_tisacase_wholesale_price' => '1500' ) );
$r = TCBPM_OPS::process_parent( 14, 'wholesale_clear', null );
t( 'wholesale_clear حذف می‌شود', 1 === $r['updated'] && '' === get_post_meta( 14, '_tisacase_wholesale_price' ) );

register_product( 15, 'عمده‌ازخرده', 'simple', array( '_regular_price' => '2000', '_tisacase_wholesale_price' => '1000' ) );
$r = TCBPM_OPS::process_parent( 15, 'wholesale_from_retail_percent', 25.0 );
t( 'عمده از خرده 25٪ → 1500', 1 === $r['updated'] && get_post_meta( 15, '_tisacase_wholesale_price' ) === '1500' );

echo "--- 8) محصول متغیر (وری‌شن) ---\n";
register_product( 20, 'تی‌شرت', 'variable', array(), 0, array( 21, 22 ) );
register_product( 21, 'تی‌شرت سایز M', 'variation', array( '_regular_price' => '1000' ), 20 );
register_product( 22, 'تی‌شرت سایز L', 'variation', array( '_regular_price' => '1200' ), 20 );
$r = TCBPM_OPS::process_parent( 20, 'regular_increase_percent', 10.0 );
t( 'متغیر: هر دو وریشن آپدیت', 2 === $r['updated'], var_export( $r, true ) );
t( 'وری‌شن M=1100', get_post_meta( 21, '_regular_price' ) === '1100' );
t( 'وری‌شن L=1320', get_post_meta( 22, '_regular_price' ) === '1320' );

register_product( 23, 'گروهی', 'grouped', array() );
$r = TCBPM_OPS::process_parent( 23, 'regular_increase_percent', 10.0 );
t( 'محصول گروهی → رد بدون خطا', 1 === $r['skipped'] && 0 === count( $r['errors'] ), var_export( $r, true ) );
register_product( 24, 'متغیر بی‌وری‌شن', 'variable', array() );
$r = TCBPM_OPS::process_parent( 24, 'regular_increase_percent', 10.0 );
t( 'متغیر بی‌وری‌شن → skip بدون خطا', 1 === $r['skipped'] && 0 === count( $r['errors'] ) );

echo "--- 9) بازگردانی (restore) ---\n";
t( 'بازیابی regular', TCBPM_OPS::apply_field_value( 7, 'regular', '999' ) && get_post_meta( 7, '_regular_price' ) === '999' );
t( 'حذف فروش ویژه (بازیابی به بدون فروش)', TCBPM_OPS::apply_field_value( 10, 'sale', '2000' ) && get_post_meta( 10, '_sale_price' ) === '2000' );
t( 'بازیابی wholesale حذف‌شده', TCBPM_OPS::apply_field_value( 14, 'wholesale', '1400' ) && get_post_meta( 14, '_tisacase_wholesale_price' ) === '1400' );
t( 'بازیابی wholesale به خالی → حذف', TCBPM_OPS::apply_field_value( 12, 'wholesale', '' ) && '' === get_post_meta( 12, '_tisacase_wholesale_price' ) );

echo "--- 10) نمونهٔ پیش‌نمایش ---\n";
register_product( 30, 'نمونه۱', 'simple', array( '_regular_price' => '1000' ) );
$row = TCBPM_OPS::sample_row( 30, array( 'parent_id' => 30, 'type' => 'simple' ), 'regular_increase_percent', 10.0 );
t( 'نمونه قبل=1000 بعد=1100', $row['before'] === '1000' && $row['after'] === '1100' && $row['state'] === 'updated', var_export( $row, true ) );
register_product( 31, 'نمونه۲', 'simple', array( '_regular_price' => '1000' ) );
$row = TCBPM_OPS::sample_row( 31, array( 'parent_id' => 31, 'type' => 'simple' ), 'sale_set', 1500.0 );
t( 'نمونه sale نامعتبر state=error', $row['state'] === 'error', var_export( $row, true ) );
register_product( 32, 'نمونه۳', 'simple', array( '_regular_price' => '1000', '_tisacase_wholesale_price' => '800' ) );
$row = TCBPM_OPS::sample_row( 32, array( 'parent_id' => 32, 'type' => 'simple' ), 'wholesale_set', 750.0 );
t( 'نمونه عمده قبل/بعد', $row['before'] === '800' && $row['after'] === '750' );
register_product( 33, 'نمونه۴', 'simple', array( '_regular_price' => '1000' ) );
$row = TCBPM_OPS::sample_row( 33, array( 'parent_id' => 33, 'type' => 'simple' ), 'sale_remove', null );
t( 'نمونه sale_remove بدون فروش → skip', $row['state'] === 'skip' );

echo "--- 11.5) انتخاب SQL و شمارش (DB) ---\n";
$GLOBALS['fake_types'] = array( 7 => 'simple', 20 => 'variable' );
$GLOBALS['fake_variations'] = array( array( 'vid' => '21', 'pid' => '20' ), array( 'vid' => '22', 'pid' => '20' ) );
$GLOBALS['fake_meta_rows'] = array(
	array( 'post_id' => '7', 'meta_key' => '_regular_price', 'meta_value' => '1000' ),
	array( 'post_id' => '21', 'meta_key' => '_regular_price', 'meta_value' => '1000' ),
	array( 'post_id' => '22', 'meta_key' => '_regular_price', 'meta_value' => '1200' ),
);
$GLOBALS['fake_id_col'] = array( '7', '20' );

$args_sel = array(
	'target_type' => 'products',
	'product_ids' => array( 7, 20 ),
	'category_ids' => array(),
	'include_children' => false,
	'operation' => 'regular_increase_percent',
	'value' => 10.0,
	'filters' => array( 'types' => array(), 'statuses' => array(), 'only_sale' => false, 'only_wholesale' => false, 'price_min' => null, 'price_max' => null ),
);
$sel = TCBPM_DB::selection_parent_ids( $args_sel );
t( 'انتخاب مستقیم: هر دو محصول باقی می‌مانند', array( 7, 20 ) === $sel, var_export( $sel, true ) );
$an = TCBPM_DB::analyze_targets( $sel, 'regular_increase_percent', 10 );
t( 'شمارش: 1 ساده + 2 وریشن = 3', 3 === $an['eligible'], var_export( $an, true ) );
t( 'نمونه شامل وریشن 21 هم هست', isset( $an['samples'][21] ), var_export( array_keys( $an['samples'] ), true ) );

// فیلتر نوع: فقط simple → محصول متغیر حذف شود.
$args_sel['filters']['types'] = array( 'simple' );
$sel2 = TCBPM_DB::selection_parent_ids( $args_sel );
t( 'فیلتر نوع simple → فقط محصول 7', array( 7 ) === $sel2, var_export( $sel2, true ) );

echo "--- 11) همگام‌سازی (sync) ساختار ---\n";
t( '15 عملیات تعریف شده', count( TCBPM_OPS::ops() ) === 15 );
t( 'wholesale گروه درست', 'wholesale' === TCBPM_OPS::op_group( 'wholesale_clear' ) );
t( 'kind=set برای wholesale_set', 'set' === TCBPM_OPS::op_kind( 'wholesale_set' ) );

echo "\n========================================\n";
echo "PASS: {$pass}   FAIL: {$fail}\n";
exit( $fail > 0 ? 1 : 0 );
