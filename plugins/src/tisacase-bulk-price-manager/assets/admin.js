/**
 * TisaCase Bulk Price Manager — Admin UI
 * صفحهٔ اصلی (پیش‌نمایش/اجرا) + صفحهٔ گزارش (بازگردانی/ادامه)
 */
/* global TCBPM_DATA, jQuery, confirm, prompt */
(function ($) {
	'use strict';

	var D = window.TCBPM_DATA || {};
	var A = D.actions || {};

	/* ---------------- ابزار عمومی ---------------- */

	/** حذف جداکننده و تبدیل ارقام فارسی/عربی به انگلیسی. */
	function normNum(v) {
		if (v === null || v === undefined) { return ''; }
		var s = String(v).replace(/[\u0660-\u0669\u06F0-\u06F9]/g, function (c) {
			return String(c.charCodeAt(0) & 0xf);
		});
		return s.replace(/[,٬،\s]/g, '');
	}

	function esc(s) {
		return $('<div/>').text(s == null ? '' : String(s)).html();
	}

	function isNum(n) {
		var s = normNum(n);
		return s !== '' && isFinite(Number(s));
	}

	/* ---------------- دیالوگ ---------------- */

	function getDialog() {
		var $d = $('#tcbpm-dialog');
		if (!$d.length) {
			$d = $('<div class="tcbpm-dialog-backdrop" id="tcbpm-dialog" style="display:none">' +
				'<div class="tcbpm-dialog"><h3 id="tcbpm-dialog-title"></h3>' +
				'<div id="tcbpm-dialog-body" class="tcbpm-dialog-body"></div>' +
				'<div class="tcbpm-dialog-actions">' +
				'<button type="button" class="button button-primary" id="tcbpm-dialog-ok">تأیید</button>' +
				'<button type="button" class="button" id="tcbpm-dialog-cancel">انصراف</button>' +
				'</div></div></div>').appendTo('body');
		}
		return $d;
	}

	/**
	 * بازکردن دیالوگ؛ در صورت تأیید resolve با خروجی readValue (پیش‌فرض true) و در صورت انصراف null.
	 */
	function openDialog(opts) {
		var $d = getDialog();
		var $ok = $d.find('#tcbpm-dialog-ok');
		var $cancel = $d.find('#tcbpm-dialog-cancel');
		$d.find('#tcbpm-dialog-title').html(opts.title || '');
		$d.find('#tcbpm-dialog-body').html(opts.body || '');
		$ok.text(opts.okText || 'تأیید');
		$ok.attr('class', 'button button-large ' + (opts.okClass || 'button-primary'));
		$cancel.toggle(!(opts.noCancel));
		$d.show();

		return new Promise(function (resolve) {
			function done(val) {
				$ok.off('click', onOk);
				$cancel.off('click', onCancel);
				$d.hide();
				resolve(val);
			}
			function onOk() {
				var v = opts.readValue ? opts.readValue() : true;
				if (v === false) { return; } // خواندن نامعتبر؛ دیالوگ باز می‌ماند
				done(v);
			}
			function onCancel() { done(null); }
			$ok.one('click', onOk);
			$cancel.one('click', onCancel);
			$d.find('#tcbpm-dialog-cancel').css('display', opts.noCancel ? 'none' : '');
			setTimeout(function () { $d.find('input:first,button:first').focus(); }, 30);
		});
	}

	function inform(title, msg) {
		return openDialog({ title: title, body: '<p>' + msg + '</p>', okText: 'باشه', noCancel: true });
	}

	/* =====================================================================
	 * صفحهٔ اصلی — مدیریت گروهی قیمت
	 * ===================================================================== */

	if (D.tab === 'bulk') {

		var running = false;
		var stopNow = false;
		var runId = 0;
		var previewValid = false;
		var previewToken = '';
		var previewInfo = null;
		var totals = { parents: 0, updated: 0, skipped: 0, errors: 0 };

		function targetType() {
			return $('input[name="tcbpm_target"]:checked').val();
		}
		function currentOp() {
			return $('#tcbpm-op').val();
		}
		function opMeta(slug) {
			return (D.ops && D.ops[slug]) || { label: slug, kind: '', group: '' };
		}
		function isWholesaleOp() {
			return opMeta(currentOp()).group === 'wholesale';
		}
		function isSaleOp() {
			return opMeta(currentOp()).group === 'sale';
		}
		function activeSelect() {
			return isWholesaleOp() ? $('#tcbpm-wholesale-products') : $('#tcbpm-products');
		}
		function valueKind() {
			return opMeta(currentOp()).kind; // percent | amount | set | none
		}

		function filtersPayload() {
			var f = {
				types: $('#tcbpm-filter-types').val() || [],
				statuses: $('#tcbpm-filter-statuses').val() || [],
				only_sale: $('#tcbpm-filter-only-sale').is(':checked'),
				only_wholesale: $('#tcbpm-filter-only-wholesale').is(':checked'),
				price_min: $('#tcbpm-price-min').val() ? normNum($('#tcbpm-price-min').val()) : null,
				price_max: $('#tcbpm-price-max').val() ? normNum($('#tcbpm-price-max').val()) : null
			};
			if (f.price_min === '') { f.price_min = null; }
			if (f.price_max === '') { f.price_max = null; }
			return f;
		}

		function commonPayload(extra) {
			var p = {
				action: '',
				nonce: D.nonce,
				target_type: targetType(),
				category_ids: ($('#tcbpm-cats').val() || []).join(','),
				product_ids: (activeSelect().val() || []).join(','),
				include_children: $('#tcbpm-children').is(':checked') ? '1' : '0',
				operation: currentOp(),
				value: $('#tcbpm-value').val() || '',
				filters: JSON.stringify(filtersPayload())
			};
			return $.extend(p, extra || {});
		}

		function invalidatePreview() {
			if (running) { return; }
			previewValid = false;
			previewToken = '';
			previewInfo = null;
			$('#tcbpm-start').prop('disabled', true);
			$('#tcbpm-schedule').prop('disabled', true);
			$('#tcbpm-preview-box').hide();
			$('#tcbpm-preview-summary').empty();
			$('#tcbpm-sample-box tbody').empty();
		}

		function updateFilterVisibility() {
			$('#tcbpm-sale-only-row').toggle(isSaleOp());
			$('#tcbpm-wholesale-only-row').toggle(isWholesaleOp());
		}

		function updateProductSearch() {
			if (isWholesaleOp()) {
				$('#tcbpm-retail-product-search').hide();
				$('#tcbpm-wholesale-product-search').show();
			} else {
				$('#tcbpm-wholesale-product-search').hide();
				$('#tcbpm-retail-product-search').show();
			}
			$(document.body).trigger('wc-enhanced-select-init');
		}

		function updateTarget() {
			if (targetType() === 'products') {
				$('#tcbpm-cat-box').hide();
				$('#tcbpm-product-box').show();
				updateProductSearch();
			} else {
				$('#tcbpm-product-box').hide();
				$('#tcbpm-cat-box').show();
			}
			invalidatePreview();
		}

		function updateOpUi() {
			var kind = valueKind();
			updateProductSearch();
			updateFilterVisibility();
			invalidatePreview();
			if (kind === 'none') { $('#tcbpm-value-box').hide(); return; }
			$('#tcbpm-value-box').show();
			if (kind === 'percent') {
				$('#tcbpm-value-label').text('مقدار درصد');
				$('#tcbpm-unit').text('%');
				$('#tcbpm-value').attr('placeholder', 'مثلاً 10');
			} else if (kind === 'set') {
				$('#tcbpm-value-label').text('قیمت موردنظر');
				$('#tcbpm-unit').text(D.currency || 'واحد پول');
				$('#tcbpm-value').attr('placeholder', 'مثلاً 688000');
			} else {
				$('#tcbpm-value-label').text('مبلغ تغییر');
				$('#tcbpm-unit').text(D.currency || 'واحد پول');
				$('#tcbpm-value').attr('placeholder', 'مثلاً 50000');
			}
		}

		function lockUI(v) {
			$('#tcbpm-preview').prop('disabled', v);
			$('#tcbpm-start').prop('disabled', v ? true : !previewValid);
			$('#tcbpm-schedule').prop('disabled', v ? true : !previewValid);
			$('input[name="tcbpm_target"],#tcbpm-children,#tcbpm-op,#tcbpm-value').prop('disabled', v);
			$('#tcbpm-cats,#tcbpm-products,#tcbpm-wholesale-products,#tcbpm-filter-types,#tcbpm-filter-statuses,#tcbpm-price-min,#tcbpm-price-max,#tcbpm-filter-only-sale,#tcbpm-filter-only-wholesale')
				.prop('disabled', v);
			$('#tcbpm-cats,#tcbpm-products,#tcbpm-wholesale-products,#tcbpm-filter-types,#tcbpm-filter-statuses').trigger('change.select2');
			$('#tcbpm-stop').toggle(v);
		}

		function resetProgress() {
			stopNow = false;
			totals = { parents: 0, updated: 0, skipped: 0, errors: 0 };
			$('#tcbpm-parent-count,#tcbpm-updated-count,#tcbpm-skipped-count,#tcbpm-error-count').text('0');
			$('#tcbpm-errors').hide().empty();
			$('#tcbpm-bar').css('width', '0%');
			$('#tcbpm-progress').show();
			$('#tcbpm-busy-note').hide();
		}

		function showFinal(msg, ok) {
			running = false;
			$('#tcbpm-status').html('<strong style="color:' + (ok ? '#008a20' : '#b32d2e') + '">' + esc(msg) + '</strong>');
			if (ok) { $('#tcbpm-bar').css('width', '100%'); }
			lockUI(false);
		}

		function addErrors(items) {
			if (!items || !items.length) { return; }
			var box = $('#tcbpm-errors').show();
			items.forEach(function (x) { $('<div/>').text(x).appendTo(box); });
		}

		/** اعتبارسنجی فرم سمت کلاینت. */
		function valid() {
			if (targetType() === 'category' && !($('#tcbpm-cats').val() || []).length) {
				return 'حداقل یک دسته‌بندی انتخاب کن.';
			}
			if (targetType() === 'products' && !(activeSelect().val() || []).length) {
				return isWholesaleOp() ? 'حداقل یک محصول دارای قیمت عمده انتخاب کن.' : 'حداقل یک محصول انتخاب کن.';
			}
			var kind = valueKind();
			if (kind !== 'none' && $('#tcbpm-value').val().trim() === '') {
				return 'مقدار را وارد کن.';
			}
			if (kind !== 'none') {
				var n = Number(normNum($('#tcbpm-value').val()));
				if (!isFinite(n) || n < 0) { return 'مقدار واردشده معتبر نیست.'; }
				if (kind === 'percent') {
					var cap = opMeta(currentOp()).cap100 ? 100 : (D.limits.percentMax || 100000);
					if (n > cap) { return 'مقدار درصد از حد مجاز (' + cap + ') بیشتر است.'; }
				} else {
					var maxA = D.limits.maxAmount || 1e9;
					if (n > maxA) { return 'مبلغ از سقف مجاز (' + maxA + ') بیشتر است.'; }
				}
			}
			var f = filtersPayload();
			if (f.price_min !== null && f.price_max !== null && Number(f.price_min) > Number(f.price_max)) {
				return 'محدودهٔ قیمت را درست وارد کن (از ≤ تا).';
			}
			return null;
		}

		/* ---- پیش‌نمایش ---- */
		function renderSamples(samples) {
			var $tb = $('#tcbpm-sample-box tbody').empty();
			if (!samples || !samples.length) {
				$('#tcbpm-sample-box').hide();
				return;
			}
			$('#tcbpm-sample-box').show();
			var cur = D.currency || '';
			samples.forEach(function (s) {
				var before = s.before === '' ? '—' : esc(s.before) + ' ' + cur;
				var after = s.after === '' ? '—' : esc(s.after) + ' ' + cur;
				var stateHtml = '';
				if (s.state === 'error') {
					stateHtml = '<span class="sample-error">خطا — ' + esc(s.note) + '</span>';
				} else if (s.state === 'skip') {
					stateHtml = '<span class="sample-skip">رد شد — ' + esc(s.note) + '</span>';
				} else if (s.state === 'updated') {
					stateHtml = '<span style="color:#008a20">✓ قابل اجرا</span>';
				} else {
					stateHtml = esc(s.note || '');
				}
				$tb.append($('<tr/>').append(
					$('<td/>').text(s.object_id),
					$('<td/>').text(s.label || ('#' + s.object_id)),
					$('<td/>').text(s.type || ''),
					$('<td class="sample-now"/>').html(before),
					$('<td class="sample-new"/>').html(after),
					$('<td/>').html(stateHtml)
				));
			});
		}

		function runPreview() {
			var err = valid();
			if (err) { inform('توجه', esc(err)); return; }
			invalidatePreview();
			var $btn = $('#tcbpm-preview').prop('disabled', true).text('در حال بررسی...');

			var payload = commonPayload({ action: A.preview });

			$.post(D.ajax, payload, null, 'json')
				.done(function (r) {
					if (!r || !r.success) {
						inform('خطا در بررسی', esc((r && r.data && r.data.message) || 'خطای نامشخص.'));
						return;
					}
					var d = r.data || {};
					previewValid = true;
					previewToken = String(d.preview_token || '');
					previewInfo = d;

					var html = '<p><strong>نوع عملیات:</strong> ' + esc(d.operation_label || '') + '</p>';
					html += '<p><strong>محدوده انتخاب:</strong> ' + (d.target_type === 'products' ? 'محصولات انتخاب‌شده به صورت مستقیم' : (d.include_children ? 'دسته‌بندی + تمام زیردسته‌ها' : 'فقط خود دسته‌بندی‌ها؛ بدون زیردسته')) + '</p>';
					if (d.category_labels && d.category_labels.length) {
						html += '<p><strong>دسته‌ها:</strong> ' + esc(d.category_labels.join(' ، ')) + '</p>';
					}
					html += '<p style="font-size:16px"><strong>تعداد محصولات مادر هدف: <span style="color:#b32d2e">' + Number(d.parent_count || 0) + '</span></strong></p>';
					html += '<p><strong>تعداد قیمت/متغیر واجد شرایط این عملیات:</strong> ' + Number(d.price_object_count || 0) + '</p>';
					if (d.include_children) {
						html += '<p style="color:#b32d2e"><strong>هشدار: زیردسته‌ها نیز لحاظ شده‌اند.</strong></p>';
					}
					if (Number(d.parent_count || 0) >= (D.limits.threshold || 500)) {
						html += '<p style="background:#fff2f0;border:1px solid #d63638;padding:9px"><strong>هشدار پرریسک:</strong> بیش از ' + (D.limits.threshold || 500) + ' محصول مادر در محدوده است. هنگام اجرا باید شمارهٔ دقیق را دستی تایپ کنی.</p>';
					}
					$('#tcbpm-preview-summary').html(html);
					$('#tcbpm-preview-box').show();
					renderSamples(d.samples);
					$('#tcbpm-start').prop('disabled', running);
					$('#tcbpm-schedule').prop('disabled', running || !(D.limits && D.limits.scheduledEnabled));
				})
				.fail(function (xhr) {
					inform('خطا در بررسی', esc((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'ارتباط با سرور برای بررسی قطع شد.'));
				})
				.always(function () {
					$btn.prop('disabled', false).text('بررسی قبل از اجرا');
				});
		}

		/* ---- اجرا ---- */
		function startRun(scheduled) {
			var err = valid();
			if (err) { inform('توجه', esc(err)); return; }
			if (!previewValid || !previewToken) {
				inform('توجه', 'ابتدا بررسی قبل از اجرا را انجام بده.');
				return;
			}
			var parents = Number(previewInfo && previewInfo.parent_count || 0);
			var prices = Number(previewInfo && previewInfo.price_object_count || 0);

			var body = '<p>قرار است عملیات روی <b>' + parents + '</b> محصول مادر و حدود <b>' + prices + '</b> قیمت/متغیر واجد شرایط اجرا شود.</p>';
			if (previewInfo && previewInfo.include_children) {
				body += '<p style="color:#b32d2e"><strong>هشدار: زیردسته‌ها هم شامل عملیات هستند.</strong></p>';
			}
			body += '<p>' + (scheduled ? 'اجرا به صف زمان‌بندی (WP-Cron) اضافه می‌شود و در پیشخوان اطلاع‌رسانی می‌گردد.' : 'همهٔ تغییرات برای بازگردانی بعدی ثبت می‌شوند.') + '</p>';

			// تأیید دستی تایپ‌شده برای تعداد زیاد.
			if (parents >= (D.limits.threshold || 500)) {
				var required = 'تایید ' + parents;
				openDialog({
					title: 'تأیید امنیتی',
					body: '<p>تعداد محصولات زیاد است. برای جلوگیری از اشتباه، عبارت زیر را دقیقاً تایپ کن:</p>' +
						'<p style="background:#f6f7f7;padding:8px;text-align:center;font-weight:700;font-size:16px" dir="ltr">' + esc(required) + '</p>' +
						'<input type="text" id="tcbpm-confirm-input" autocomplete="off" placeholder="' + esc(required) + '">',
					okText: scheduled ? 'ثبت در صف' : 'شروع اجرا',
					okClass: 'button-primary',
					readValue: function () {
						var typed = $('#tcbpm-confirm-input').val().trim();
						if (typed !== required) { return false; }
						return typed;
					}
				}).then(function (val) {
					if (val === null) { return; }
					doStart(scheduled);
				});
				return;
			}

			openDialog({
				title: 'تأیید نهایی',
				body: body,
				okText: scheduled ? 'ثبت در صف' : 'اجرا کن',
				okClass: 'button-primary'
			}).then(function (ok) {
				if (ok) { doStart(scheduled); }
			});
		}

		function doStart(scheduled) {
			var payload = commonPayload({
				action: A.run,
				preview_token: previewToken,
				schedule: scheduled ? '1' : '0'
			});

			$.post(D.ajax, payload, null, 'json')
				.done(function (r) {
					if (!r || !r.success) {
						inform('شروع اجرا ممکن نشد', esc((r && r.data && r.data.message) || 'خطای نامشخص.'));
						return;
					}
					if (scheduled) {
						inform('ثبت در صف', esc((r.data && r.data.message) || 'در صف قرار گرفت.'));
						$('#tcbpm-schedule').prop('disabled', true);
						return;
					}
					running = true;
					resetProgress();
					lockUI(true);
					$('#tcbpm-status').text('در حال پردازش مرحله ۱ ...');
					handleRunResponse(r, 'next');
				})
				.fail(function (xhr) {
					inform('شروع اجرا ممکن نشد', esc((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'ارتباط با سرور قطع شد.'));
				});
		}

		function handleRunResponse(r, mode) {
			if (!r || !r.success) {
				showFinal((r && r.data && r.data.message) || 'خطای نامشخص.', false);
				return;
			}
			var d = r.data || {};
			runId = Number(d.run_id || runId);
			totals.parents += Number(d.parents || 0);
			totals.updated += Number(d.updated || 0);
			totals.skipped += Number(d.skipped || 0);
			totals.errors += (d.errors || []).length;
			addErrors(d.errors || []);
			$('#tcbpm-parent-count').text(totals.parents);
			$('#tcbpm-updated-count').text(totals.updated);
			$('#tcbpm-skipped-count').text(totals.skipped);
			$('#tcbpm-error-count').text(totals.errors);
			$('#tcbpm-bar').css('width', Math.min(100, Number(d.progress || 0)) + '%');

			if (stopNow) {
				stopNow = false;
				finishRun('stopped');
				return;
			}
			if (d.done) {
				// جمع‌بندی نهایی بر اساس آمار قطعی سرور.
				if (d.totals) {
					$('#tcbpm-parent-count').text(totals.parents);
					$('#tcbpm-updated-count').text(d.totals.updated);
					$('#tcbpm-skipped-count').text(d.totals.skipped);
					$('#tcbpm-error-count').text(d.totals.errors);
				}
				var hadErrors = (d.totals && d.totals.errors > 0) || totals.errors > 0;
				if (hadErrors) {
					showFinal('عملیات تمام شد اما ' + ((d.totals && d.totals.errors) || totals.errors) + ' خطا ثبت شد — جزئیات در «گزارش و بازگردانی» موجود است.', false);
				} else {
					showFinal('عملیات با موفقیت تمام شد.', true);
				}
				return;
			}
			$('#tcbpm-status').text('در حال پردازش مرحله ' + Number(d.page || 0) + ' ...');
			runNext(runId);
		}

		function runNext(id) {
			if (!running) { return; }
			$.post(D.ajax, { action: A.run, nonce: D.nonce, run_id: id }, null, 'json')
				.done(function (r) { handleRunResponse(r, 'next'); })
				.fail(function (xhr) {
					var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'ارتباط با سرور قطع شد.';
					// اگر نهایی‌شده باشد (race) فقط اطلاع بده.
					showFinal(msg, false);
				});
		}

		function finishRun(outcome) {
			if (!runId) { return; }
			$.post(D.ajax, { action: A.finish, nonce: D.nonce, run_id: runId }, null, 'json')
				.done(function (r) {
					showFinal((r && r.data && r.data.message) || 'عملیات متوقف شد.', true);
				})
				.fail(function (xhr) {
					showFinal((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'توقف ثبت نشد؛ گزارش را چک کن.', false);
				});
		}

		/* ---- رویدادها ---- */
		$('input[name="tcbpm_target"]').on('change', updateTarget);
		$('#tcbpm-op').on('change', updateOpUi);
		$('#tcbpm-children').on('change', function () {
			$('#tcbpm-children-warning').toggle($(this).is(':checked'));
			invalidatePreview();
		});
		$('#tcbpm-cats,#tcbpm-products,#tcbpm-wholesale-products,#tcbpm-filter-types,#tcbpm-filter-statuses,#tcbpm-price-min,#tcbpm-price-max,#tcbpm-filter-only-sale,#tcbpm-filter-only-wholesale')
			.on('change', invalidatePreview);
		$('#tcbpm-value').on('input', invalidatePreview);
		$('#tcbpm-price-min,#tcbpm-price-max').on('input', invalidatePreview);

		$('#tcbpm-preview').on('click', function () { if (!running) { runPreview(); } });
		$('#tcbpm-start').on('click', function () { if (!running) { startRun(false); } });
		$('#tcbpm-schedule').on('click', function () { if (!running) { startRun(true); } });
		$('#tcbpm-stop').on('click', function () {
			stopNow = true;
			$(this).prop('disabled', true).text('در حال توقف...');
		});

		updateTarget();
		updateOpUi();
		updateFilterVisibility();
		$('#tcbpm-children-warning').toggle($('#tcbpm-children').is(':checked'));
		if (!(D.limits && D.limits.scheduledEnabled)) { $('#tcbpm-schedule').hide(); }
		$(document.body).trigger('wc-enhanced-select-init');
	}

	/* =====================================================================
	 * صفحهٔ گزارش اجراها — بازگردانی / ادامه / انصراف
	 * ===================================================================== */

	if (D.tab === 'runs') {

		var rollbackRunId = 0;

		function rowRunId(btn) {
			return Number($(btn).closest('tr').data('run') || 0);
		}

		/** دیالوگ پیشرفت عمومی برای ادامه/بازگردانی. */
		function openProgress(title, onStop) {
			var $d = getDialog();
			$d.find('#tcbpm-dialog-title').html(esc(title));
			$d.find('#tcbpm-dialog-body').html(
				'<div class="tcbpm-progressbar-line"><div class="tcbpm-bar" style="flex:1"><div id="tcbpm-pg-bar" style="width:0"></div></div>' +
				'<span id="tcbpm-pg-pct">0٪</span></div>' +
				'<p id="tcbpm-pg-status" class="tcbpm-status" style="margin-top:8px">شروع...</p>' +
				'<div id="tcbpm-pg-errors" class="tcbpm-errors" style="display:none"></div>'
			);
			var $ok = $d.find('#tcbpm-dialog-ok').text('توقف').attr('class', 'button button-large').show();
			$d.find('#tcbpm-dialog-cancel').text('بستن').hide();
			$d.show();
			$ok.one('click', function () {
				onStop();
				$ok.prop('disabled', true).text('در حال توقف...');
			});
		}

		function setPg(pct, status) {
			$('#tcbpm-pg-bar').css('width', Math.min(100, Number(pct || 0)) + '%');
			$('#tcbpm-pg-pct').text(Math.min(100, Number(pct || 0)) + '٪');
			if (status) { $('#tcbpm-pg-status').text(status); }
		}

		function addPgErrors(items) {
			if (!items || !items.length) { return; }
			var box = $('#tcbpm-pg-errors').show();
			items.forEach(function (x) { $('<div/>').text(x).appendTo(box); });
		}

		function closeProgressAndReload(msg) {
			var $d = getDialog();
			$d.hide();
			$d.find('#tcbpm-pg-errors').empty();
			if (msg) {
				inform('نتیجه', esc(msg)).then(function () { window.location.reload(); });
				return;
			}
			window.location.reload();
		}

		/* ---- ادامهٔ اجرای ناتمام ---- */
		$(document).on('click', '.tcbpm-act-resume', function () {
			var rid = rowRunId(this);
			inform('ادامهٔ اجرا #' + rid, 'اجرا از همان جایی که قطع شده ادامه می‌یابد و پیشرفت آن در این پنجره نمایش داده می‌شود.').then(function (ok) {
				if (!ok) { return; }
				openProgress('ادامهٔ اجرای #' + rid, function () {
					// توقف بعد از صفحهٔ فعلی
					window.stopFlagResume = true;
					$('#tcbpm-dialog-ok').prop('disabled', true);
				});
				pollRun(rid, true);
			});
		});

		function pollRun(rid, resumeFirst) {
			var payload = { action: A.run, nonce: D.nonce, run_id: rid };
			if (resumeFirst) { payload.resume = '1'; }
			$.post(D.ajax, payload, null, 'json')
				.done(function (r) {
					if (!r || !r.success) {
						closeProgressAndReload((r && r.data && r.data.message) || 'خطا در ادامه‌ی اجرا.');
						return;
					}
					var d = r.data || {};
					if (window.stopFlagResume) {
						window.stopFlagResume = false;
						finishAndReload(rid);
						return;
					}
					if (d.done) {
						closeProgressAndReload('اجرا #' + rid + ' کامل شد.');
						return;
					}
					setPg(d.progress, 'مرحلهٔ ' + (d.page || 0) + ' — تاکنون ' + ((d.totals && d.totals.updated) || 0) + ' تغییر.');
					addPgErrors(d.errors || []);
					pollRun(rid, false);
				})
				.fail(function (xhr) {
					closeProgressAndReload((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'ارتباط با سرور قطع شد.');
				});
		}

		function finishAndReload(rid) {
			$.post(D.ajax, { action: A.finish, nonce: D.nonce, run_id: rid }, null, 'json')
				.done(function () { closeProgressAndReload('اجرا متوقف شد.'); })
				.fail(function (xhr) { closeProgressAndReload((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'توقف ثبت نشد.'); });
		}

		/* ---- توقف اجرای در جریان ---- */
		$(document).on('click', '.tcbpm-act-stop', function () {
			var rid = rowRunId(this);
			openDialog({
				title: 'توقف اجرای #' + rid,
				body: '<p>اجرا بعد از صفحهٔ جاری متوقف می‌شود و تاکنون ثبت‌شده‌ها برای بازگردانی/گزارش باقی می‌مانند.</p>',
				okText: 'توقف اجرا',
				okClass: 'button-primary'
			}).then(function (ok) {
				if (ok) { finishAndReload(rid); }
			});
		});

		/* ---- بازگردانی ---- */
		$(document).on('click', '.tcbpm-act-rollback', function () {
			var rid = rowRunId(this);
			var $tr = $(this).closest('tr');
			var updated = $tr.find('.tcbpm-run-stats b').eq(0).text();
			openDialog({
				title: 'بازگردانی اجرای #' + rid,
				body: '<p>همهٔ <b>' + esc(updated) + '</b> تغییری که این اجرا ثبت کرده به حالت «قبل» برگردانده می‌شود (حذف‌ها بازسازی و تغییرها معکوس می‌شوند).</p>' +
					'<p style="color:#b32d2e">این عمل روی قیمت‌ها اثر مستقیم دارد. مطمئن هستی؟</p>',
				okText: 'بله، بازگردانی کن',
				okClass: 'button-primary'
			}).then(function (ok) {
				if (!ok) { return; }
				$.post(D.ajax, { action: A.rollback_start, nonce: D.nonce, run_id: rid }, null, 'json')
					.done(function (r) {
						if (!r || !r.success) {
							inform('بازگردانی شروع نشد', esc((r && r.data && r.data.message) || 'خطای نامشخص.'));
							return;
						}
						rollbackRunId = Number(r.data.run_id || 0);
						openProgress('بازگردانی اجرای #' + rid + ' (' + r.data.rows + ' رکورد)', function () {
							window.stopFlagRollback = true;
							$('#tcbpm-dialog-ok').prop('disabled', true);
						});
						pollRollback(rollbackRunId);
					})
					.fail(function (xhr) {
						inform('بازگردانی شروع نشد', esc((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'ارتباط با سرور قطع شد.'));
					});
			});
		});

		function pollRollback(rbid) {
			$.post(D.ajax, { action: A.rollback_page, nonce: D.nonce, run_id: rbid }, null, 'json')
				.done(function (r) {
					if (!r || !r.success) {
						closeProgressAndReload((r && r.data && r.data.message) || 'خطا در بازگردانی.');
						return;
					}
					var d = r.data || {};
					if (window.stopFlagRollback) {
						window.stopFlagRollback = false;
						$.post(D.ajax, { action: A.finish, nonce: D.nonce, run_id: rbid }, null, 'json')
							.done(function () { closeProgressAndReload('بازگردانی متوقف شد.'); })
							.fail(function () { closeProgressAndReload('بازگردانی متوقف شد (گزارش را چک کن).'); });
						return;
					}
					if (d.done) {
						closeProgressAndReload('بازگردانی اجرا کامل شد و اجرای مبدأ «بازگردانی شده» شد.');
						return;
					}
					setPg(d.progress, 'در حال بازگردانی... ' + (d.updated || 0) + ' رکورد در این مرحله.');
					addPgErrors(d.errors || []);
					pollRollback(rbid);
				})
				.fail(function (xhr) {
					closeProgressAndReload((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'ارتباط با سرور قطع شد.');
				});
		}

		/* ---- انصراف از صف ---- */
		$(document).on('click', '.tcbpm-act-cancel', function () {
			var rid = rowRunId(this);
			openDialog({
				title: 'انصراف از صف',
				body: '<p>اجرای زمان‌بندی‌شدهٔ #' + rid + ' از صف حذف می‌شود و اجرا نمی‌شود. مطمئن هستی؟</p>',
				okText: 'حذف از صف',
				okClass: 'button-primary'
			}).then(function (ok) {
				if (!ok) { return; }
				$.post(D.ajax, { action: A.cancel, nonce: D.nonce, run_id: rid }, null, 'json')
					.done(function (r) {
						if (r && r.success) { window.location.reload(); }
						else { inform('انصراف نشد', esc((r && r.data && r.data.message) || 'خطای نامشخص.')); }
					})
					.fail(function () { inform('انصراف نشد', 'ارتباط با سرور قطع شد.'); });
			});
		});

		$(document.body).trigger('wc-enhanced-select-init');
	}
})(jQuery);
