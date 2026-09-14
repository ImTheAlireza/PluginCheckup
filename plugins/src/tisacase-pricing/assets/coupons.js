/**
 * TisaCase Pricing — تب کد تخفیف: کپی کد، کد تصادفی، حذف/تغییر وضعیت با فرم‌های مخفی.
 */
(function ($) {
	'use strict';

	var ALPHA = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	function randomCode(len) {
		var out = '';
		for (var i = 0; i < len; i++) { out += ALPHA[Math.floor(Math.random() * ALPHA.length)]; }
		return out;
	}

	$('#tcp-cp-random').on('click', function () {
		$('#tcp-cp-code').val(randomCode(8)).trigger('focus');
	});

	$('.tcp-cp-type').on('change', function () {
		var pct = $(this).val() === 'percent';
		$(this).closest('.tcp-grid-3').find('.tcp-cp-unit').text(pct ? '(٪)' : '(' + ((window.TCP_COUPONS && window.TCP_COUPONS.currency) || '') + ')');
	});

	$(document).on('click', '.tcp-cp-code', function () {
		var code = $(this).data('code');
		var $b = $(this);
		var done = function () { $b.addClass('is-copied'); setTimeout(function () { $b.removeClass('is-copied'); }, 900); };
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(code).then(done);
		} else {
			var ta = $('<textarea>').val(code).appendTo('body').select();
			try { document.execCommand('copy'); } catch (e) { /* noop */ }
			ta.remove(); done();
		}
	});

	$('#tcp-cp-all').on('change', function () {
		$('input[name="coupon_ids[]"]').prop('checked', this.checked).trigger('change');
	});
	$(document).on('change', 'input[name="coupon_ids[]"]', function () {
		$('#tcp-cp-delete-selected').prop('disabled', !$('input[name="coupon_ids[]"]:checked').length);
	});

	$('#tcp-cp-list-form').on('submit', function () {
		var n = $('input[name="coupon_ids[]"]:checked').length;
		return window.confirm(n + ' کد برای همیشه حذف شود؟');
	});

	$('#tcp-cp-delete-batch').on('click', function () {
		var b = $(this).data('batch');
		if (!window.confirm('همهٔ کدهای گروه «' + b + '» حذف شوند؟')) { return; }
		var $f = $('#tcp-cp-list-form');
		if (!$f.length) { return; }
		$f.off('submit');
		$('input[name="coupon_ids[]"]').prop('checked', false);
		$('#tcp-cp-delete-batch-input').val(b);
		$f.trigger('submit');
	});

	$(document).on('click', '.tcp-cp-toggle', function () {
		$('#tcp-cp-toggle-id').val($(this).data('id'));
		$('#tcp-cp-toggle-form').trigger('submit');
	});
})(jQuery);
