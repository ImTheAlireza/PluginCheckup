/**
 * TisaCase Bulk Variation Manager — Admin JavaScript
 * Clean & Native WordPress styling (Matching TisaCase Design System)
 */
(function ($) {
	'use strict';

	/**
	 * نمایش/پنهان‌سازی مطمئن کادرها.
	 * استایل‌های این افزونه با display: … !important نوشته شده‌اند و همین باعث می‌شد
	 * hide()/slideUp() جی‌کوئری و display:none اینلاین بی‌اثر بمانند (همهٔ کادرها باز می‌ماندند).
	 * پس همه‌جا با کلاس tcbvm-hidden (!important و اولویت بالاتر) کار می‌کنیم.
	 */
	function setVisible(selector, show) {
		$(selector).toggleClass('tcbvm-hidden', !show);
	}

	function isChecked(selector) {
		return !!(selector && $(selector).length && $(selector).is(':checked'));
	}

	var state = {
		matchedIds: [],
		selectedIds: [],
		isRunning: false,
		shouldStop: false,
		currentRunId: null,
		processedCount: 0,
		successCount: 0,
		failedCount: 0
	};

	$(document).ready(function () {
		initSegmentBars();
		initSelect2();
		initTargetModeToggle();
		initOpChange();
		initPresetChips();
		initModelsCounter();
		initSearch();
		initSelection();
		initPreview();
		initBulkRun();
		initRollback();
		initPresetSave();
		initCacheFlush();
	});

	/* -------------------------------------------------------------
	 * ۰. فعال‌سازی نوار سگمنت دکمه‌ای (Segmented Radio Bars)
	 * ----------------------------------------------------------- */
	function initSegmentBars() {
		$(document).on('change', '.tcbvm-seg-item input[type="radio"]', function () {
			var $radio = $(this);
			var name = $radio.attr('name');
			$('input[name="' + name + '"]').closest('.tcbvm-seg-item').removeClass('is-active');
			$radio.closest('.tcbvm-seg-item').addClass('is-active');
		});
	}

	/* -------------------------------------------------------------
	 * ۰.۱ فعال‌سازی Select2 برای دراپ‌داون دسته‌بندی‌ها
	 * ----------------------------------------------------------- */
	function initSelect2() {
		var $catSelect = $('#tcbvm-cat-select');
		if (!$catSelect.length) { return; }

		// مسیر استاندارد ووکامرس: رویداد init را می‌زنیم تا خود ووکامرس (selectWoo) با ظاهر،
		// RTL، جستجو و data-placeholder همان سلکت را بسازد. فراخوانی مستقیم select2 روی
		// عنصری که ووکامرس قبلاً ساخته، ساختهٔ قبلی را destroy و از نو می‌سازد و می‌تواند
		// ظاهر/رفتار کشویی را خراب کند.
		$(document.body).trigger('wc-enhanced-select-init');

		if ($catSelect.hasClass('enhanced') || $catSelect.data('select2')) { return; }

		// فالبک: اگر اسکریپت ووکامرس بارگیری نشده باشد، خودمان سلکت۲ را اعمال می‌کنیم.
		if (typeof $.fn.select2 !== 'undefined') {
			$catSelect.select2({
				placeholder: 'انتخاب یک یا چند دسته‌بندی…',
				allowClear: true,
				width: '100%',
				dir: $('html').attr('dir') || 'rtl'
			});
		}
	}

	/* -------------------------------------------------------------
	 * ۱. انتخاب حالت هدف (دسته‌بندی یا دستی)
	 * ----------------------------------------------------------- */
	function applyTargetMode(mode) {
		var manual = (mode === 'manual');
		// فقط کادرِ حالت فعال باز می‌ماند: دسته‌بندی یا شناسه‌های دستی.
		setVisible('#tcbvm-cat-box', !manual);
		setVisible('#tcbvm-manual-box', manual);
	}

	function initTargetModeToggle() {
		// وضعیت اولیه بر اساس گزینهٔ انتخاب‌شده (قبلاً استایل اینلاین به‌خاطر !important بی‌اثر بود).
		applyTargetMode($('input[name="tcbvm_target_mode"]:checked').val() || 'category');

		$(document).on('change', 'input[name="tcbvm_target_mode"]', function () {
			applyTargetMode($(this).val());
		});

		setVisible('#tcbvm-clone-ref-wrap', isChecked('#tcbvm-clone-price-check'));
		$('#tcbvm-clone-price-check').on('change', function () {
			setVisible('#tcbvm-clone-ref-wrap', $(this).is(':checked'));
		});
	}

	/* -------------------------------------------------------------
	 * ۲. تغییر نوع عملیات در دراپ‌داون
	 * ----------------------------------------------------------- */
	function applyOpVisibility(op) {
		var show = {
			models: true,
			replace: false,
			pricing: true,
			deleteMode: false
		};

		switch (op) {
			case 'remove_models':
				show.deleteMode = true;
				break;
			case 'replace_model':
				show.models = false;
				show.replace = true;
				show.pricing = false;
				break;
			case 'sync_preset':
				show.deleteMode = true;
				break;
			case 'bulk_price_stock':
			case 'add_models':
			default:
				break;
		}

		setVisible('#tcbvm-models-input-wrap', show.models);
		setVisible('#tcbvm-replace-input-wrap', show.replace);
		setVisible('#tcbvm-pricing-options-wrap', show.pricing);
		setVisible('#tcbvm-delete-mode-wrap', show.deleteMode);
	}

	function initOpChange() {
		applyOpVisibility($('#tcbvm-op').val());
		$('#tcbvm-op').on('change', function () {
			applyOpVisibility($(this).val());
		});
	}

	/* -------------------------------------------------------------
	 * ۳. چیپ‌های درج سریع الگوها
	 * ----------------------------------------------------------- */
	function initPresetChips() {
		$(document).on('click', '.tcbvm-chip-btn', function (e) {
			e.preventDefault();
			var pId = $(this).data('preset-id');
			var preset = tcbvmData.presets[pId];
			if (!preset || !preset.models) {
				return;
			}

			var $textarea = $('#tcbvm-models-input');
			var currentText = $.trim($textarea.val());
			var newLines = preset.models.join('\n');

			if (currentText.length > 0) {
				$textarea.val(currentText + '\n' + newLines);
			} else {
				$textarea.val(newLines);
			}

			$textarea.trigger('input');
			$textarea.css('border-color', '#0E7C6B');
			setTimeout(function () {
				$textarea.css('border-color', '');
			}, 400);
		});
	}

	/* -------------------------------------------------------------
	 * ۳.۱ شمارندهٔ مدل‌های ورودی (با همان قواعد سرور)
	 * ----------------------------------------------------------- */
	function normalizePersian(text) {
		return (text || '')
			.replace(/ي/g, 'ی')
			.replace(/ك/g, 'ک')
			.replace(/ة|ۀ/g, 'ه')
			.replace(/\u00a0|\u200c/g, ' ')
			.replace(/\s+/g, ' ')
			.trim();
	}

	/**
	 * همان منطق TCBVM_OPS::sanitize_model_list — جداکننده «|» و خط جدید؛
	 * کاما داخل نام مدل حفظ می‌شود (iPhone 7,8,SE یک مدل است).
	 */
	function parseModelList(raw) {
		var text = (raw || '').replace(/\r\n|\r/g, '\n').trim();
		if (!text) { return []; }

		var parts = text.split(/[|\n]+/);

		if (parts.length < 2) {
			if (/[,،]\s+/.test(text)) {
				parts = text.split(/\s*[,،]\s*/);
			} else if (text.indexOf('،') !== -1) {
				parts = text.split(/\s*،\s*/);
			} else {
				parts = [text];
			}
		}

		var seen = {};
		var out = [];
		for (var i = 0; i < parts.length; i++) {
			var item = $.trim(parts[i]);
			if (!item) { continue; }
			var key = normalizePersian(item).toLowerCase();
			if (!key || seen[key]) { continue; }
			seen[key] = true;
			out.push(item);
		}
		return out;
	}

	function updateModelsCount() {
		var $counter = $('#tcbvm-models-count');
		if (!$counter.length) { return; }
		var count = parseModelList($('#tcbvm-models-input').val()).length;
		$counter.text(count + ' مدل شناسایی شد');
	}

	function initModelsCounter() {
		updateModelsCount();
		$(document).on('input change', '#tcbvm-models-input', updateModelsCount);
		// درج الگو با کلیک انجام می‌شود؛ بعد از آن هم شمارنده تازه شود.
		$(document).on('click', '.tcbvm-chip-btn', function () {
			setTimeout(updateModelsCount, 10);
		});
	}

	/* -------------------------------------------------------------
	 * ۴. جستجوی زنده و فیلتر کردن محصولات
	 * ----------------------------------------------------------- */
	function initSearch() {
		$('#tcbvm-btn-search').on('click', function () {
			var $btn = $(this);
			var originalHtml = $btn.html();

			var mode = $('input[name="tcbvm_target_mode"]:checked').val() || 'category';
			var selectedCats = $('#tcbvm-cat-select').val() || [];
			var includeChildren = $('#tcbvm-cat-children').is(':checked') ? 1 : 0;
			var keywords = $('#tcbvm-keywords').val();
			var excludeKeywords = $('#tcbvm-exclude-keywords').val();
			var manualIds = $('#tcbvm-manual-ids').val();
			var modelFilter = $('#tcbvm-model-filter').val();

			$btn.prop('disabled', true).text('در حال جستجو…');
			$('#tcbvm-search-counter').text('در حال اسکن محصولات…');

			$.ajax({
				url: tcbvmData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'tcbvm_search_products',
					nonce: tcbvmData.nonce,
					filters: {
						mode: mode,
						category_ids: selectedCats,
						include_children: includeChildren,
						keywords: keywords,
						exclude_keywords: excludeKeywords,
						manual_ids: manualIds,
						model_term: modelFilter,
						product_types: ['variable', 'simple']
					}
				},
				success: function (res) {
					$btn.prop('disabled', false).html(originalHtml);
					if (res.success) {
						state.matchedIds = res.data.ids || [];
						state.selectedIds = state.matchedIds.slice();
						renderProductsTable(res.data.items, res.data.total);
						$('#tcbvm-search-counter').html('تعداد <strong>' + res.data.total + '</strong> محصول منطبق یافت شد.');
					} else {
						alert(res.data.message || 'خطا در جستجو');
					}
				},
				error: function () {
					$btn.prop('disabled', false).html(originalHtml);
					alert('خطای اتصال به سرور هنگام جستجو.');
				}
			});
		});
	}

	function renderProductsTable(items, total) {
		var $box = $('#tcbvm-products-box');
		var $tbody = $('#tcbvm-products-tbody');
		$tbody.empty();

		if (!items || items.length === 0) {
			$tbody.html('<tr><td colspan="8" style="text-align:center; padding:24px; color:#64748B;">هیچ محصولی با فیلترهای انتخابی یافت نشد.</td></tr>');
			setVisible('#tcbvm-products-box', true);
			updateSelectionBadge();
			return;
		}

		$.each(items, function (idx, item) {
			var modelsHtml = '';
			if (item.models && item.models.length > 0) {
				$.each(item.models.slice(0, 6), function (i, m) {
					modelsHtml += '<span class="tcbvm-tag-model">' + escapeHtml(m) + '</span>';
				});
				if (item.models_total > 6) {
					modelsHtml += '<span class="tcbvm-muted">+' + (item.models_total - 6) + '</span>';
				}
			} else {
				modelsHtml = '<span class="tcbvm-muted">بدون متغیر فعلی</span>';
			}

			var row = '<tr data-id="' + item.id + '">'
				+ '<td><input type="checkbox" class="tc-prod-checkbox" value="' + item.id + '" checked></td>'
				+ '<td><img src="' + item.image_url + '" class="tcbvm-thumb-img" alt=""></td>'
				+ '<td><strong><a href="' + item.edit_url + '" target="_blank" style="color:#0F172A; text-decoration:none;">' + escapeHtml(item.name) + '</a></strong></td>'
				+ '<td><code>' + escapeHtml(item.sku) + '</code> <small class="tcbvm-muted">(#' + item.id + ')</small></td>'
				+ '<td><span style="font-size:12px; color:#475569;">' + escapeHtml(item.cats) + '</span></td>'
				+ '<td><strong>' + item.variation_count + '</strong> متغیر</td>'
				+ '<td>' + modelsHtml + '</td>'
				+ '<td><a href="' + item.edit_url + '" class="tisa-btn tisa-btn--outline tisa-btn--sm" target="_blank">ویرایش</a></td>'
				+ '</tr>';

			$tbody.append(row);
		});

		setVisible('#tcbvm-products-box', true);
		$('#tcbvm-select-all').prop('checked', true);
		updateSelectionBadge();
	}

	/* -------------------------------------------------------------
	 * ۵. مدیریت چک‌باکس‌ها
	 * ----------------------------------------------------------- */
	function initSelection() {
		$('#tcbvm-select-all').on('change', function () {
			var isChecked = $(this).is(':checked');
			$('.tc-prod-checkbox').prop('checked', isChecked);
			if (isChecked) {
				state.selectedIds = state.matchedIds.slice();
			} else {
				state.selectedIds = [];
			}
			updateSelectionBadge();
		});

		$(document).on('change', '.tc-prod-checkbox', function () {
			var id = parseInt($(this).val(), 10);
			if ($(this).is(':checked')) {
				if (state.selectedIds.indexOf(id) === -1) {
					state.selectedIds.push(id);
				}
			} else {
				var idx = state.selectedIds.indexOf(id);
				if (idx !== -1) {
					state.selectedIds.splice(idx, 1);
				}
			}
			updateSelectionBadge();
		});
	}

	function updateSelectionBadge() {
		var count = state.selectedIds.length;
		$('#tcbvm-selected-badge').text(count + ' محصول انتخاب‌شده');
	}

	/* -------------------------------------------------------------
	 * ۶. پیش‌نمایش آزمایشی (Dry Run)
	 * ----------------------------------------------------------- */
	function initPreview() {
		$('#tcbvm-btn-preview').on('click', function () {
			if (state.selectedIds.length === 0) {
				alert(tcbvmData.i18n.selectProductsPrompt);
				return;
			}

			var op = $('#tcbvm-op').val();
			var params = collectParams(op);

			var $btn = $(this);
			var orig = $btn.html();
			$btn.prop('disabled', true).text('در حال بررسی…');

			$.ajax({
				url: tcbvmData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'tcbvm_preview',
					nonce: tcbvmData.nonce,
					product_ids: state.selectedIds,
					operation: op,
					params: params
				},
				success: function (res) {
					$btn.prop('disabled', false).html(orig);
					if (res.success) {
						renderPreviewOutput(res.data);
					} else {
						alert(res.data.message || 'خطا در محاسبه پیش‌نمایش');
					}
				},
				error: function () {
					$btn.prop('disabled', false).html(orig);
					alert('خطا در ارتباط با سرور.');
				}
			});
		});
	}

	function renderPreviewOutput(data) {
		var $box = $('#tcbvm-preview-output');
		var $content = $('#tcbvm-preview-content');
		$content.empty();

		if (!data.samples || data.samples.length === 0) {
			$content.html('<p>تغییری برای محصولات نمونه لازم نیست یا مدلی یافت نشد.</p>');
			setVisible('#tcbvm-preview-output', true);
			return;
		}

		var summaryHtml = '<p style="margin-bottom:12px; font-weight:700; color:#0F172A;">بررسی نمونه‌ای از ' + data.total_selected + ' محصول انتخابی:</p>';
		$content.append(summaryHtml);

		$.each(data.samples, function (idx, item) {
			var addsHtml = item.to_add.length ? '<p style="color:#0E7C6B; margin:4px 0;"><strong>+ مدل‌های جدید:</strong> ' + item.to_add.join('، ') + '</p>' : '';
			var remsHtml = item.to_remove.length ? '<p style="color:#EF4444; margin:4px 0;"><strong>- مدل‌های حذف/ناموجود:</strong> ' + item.to_remove.join('، ') + '</p>' : '';
			var modsHtml = item.to_modify.length ? '<p style="color:#2563EB; margin:4px 0;"><strong>~ تغییرات:</strong> ' + item.to_modify.join('، ') + '</p>' : '';
			var notesHtml = item.notes.length ? '<p class="tcbvm-muted" style="margin:4px 0;">' + item.notes.join(' | ') + '</p>' : '';

			var itemHtml = '<div class="tcbvm-preview-item">'
				+ '<h4>' + escapeHtml(item.name) + ' <small class="tcbvm-muted">(#' + item.id + ' | SKU: ' + escapeHtml(item.sku) + ')</small></h4>'
				+ addsHtml + remsHtml + modsHtml + notesHtml
				+ '</div>';

			$content.append(itemHtml);
		});

		setVisible('#tcbvm-preview-output', true);
		$('html, body').animate({
			scrollTop: $box.offset().top - 30
		}, 300);
	}

	/* -------------------------------------------------------------
	 * ۷. پردازش دسته‌ای پله‌ای تحت ایجکس (Batch Execution)
	 * ----------------------------------------------------------- */
	function initBulkRun() {
		$('#tcbvm-btn-run').on('click', function () {
			if (state.isRunning) {
				return;
			}

			if (state.selectedIds.length === 0) {
				alert(tcbvmData.i18n.selectProductsPrompt);
				return;
			}

			var op = $('#tcbvm-op').val();
			var params = collectParams(op);

			if (op === 'replace_model') {
				if (!params.old_model || !params.new_model) {
					alert('نام مدل قدیمی و جدید هر دو الزامی هستند.');
					return;
				}
			} else {
				if (!params.models || params.models.length === 0) {
					alert(tcbvmData.i18n.enterModelsPrompt);
					return;
				}
			}

			var confirmMsg = tcbvmData.i18n.confirmStart.replace('{n}', state.selectedIds.length);
			if (!confirm(confirmMsg)) {
				return;
			}

			startBatchProcess(op, params);
		});
	}

	function startBatchProcess(op, params) {
		state.isRunning = true;
		state.shouldStop = false;
		state.processedCount = 0;
		state.successCount = 0;
		state.failedCount = 0;

		var $btn = $('#tcbvm-btn-run');
		$btn.prop('disabled', true);

		setVisible('#tcbvm-progress-wrap', true);
		$('#tcbvm-bar-fill').css('width', '0%');
		$('#tcbvm-progress-percent').text('0%');
		$('#tcbvm-progress-text').text('در حال ثبت نشست و تهیه پشتیبان خودکار…');
		$('#tcbvm-log-console').empty();

		logMessage('Starting run: ' + op + ' on ' + state.selectedIds.length + ' products...');

		$.ajax({
			url: tcbvmData.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'tcbvm_start_run',
				nonce: tcbvmData.nonce,
				product_ids: state.selectedIds,
				operation: op,
				params: params
			},
			success: function (res) {
				if (res.success) {
					state.currentRunId = res.data.run_id;
					var batches = res.data.batches;
					$('#tcbvm-stat-total').text(res.data.total_items);
					$('#tcbvm-stat-processed').text('0');
					$('#tcbvm-stat-success').text('0');
					$('#tcbvm-stat-failed').text('0');

					logMessage('Run session created: ' + state.currentRunId + ' (' + batches.length + ' batches total)');
					executeNextBatch(0, batches, op, params);
				} else {
					state.isRunning = false;
					$btn.prop('disabled', false);
					alert(res.data.message || 'خطا در شروع نشست');
				}
			},
			error: function (xhr, status, errorThrown) {
				state.isRunning = false;
				$btn.prop('disabled', false);
				var detail = describeAjaxError(xhr, status, errorThrown);
				logMessage('[ERROR] ایجاد نشست اجرا ناموفق بود: ' + detail);
				alert('ایجاد نشست اجرا ناموفق بود.\n' + detail);
			}
		});
	}

	function describeAjaxError(xhr, status, errorThrown) {
		var parts = [];

		if (xhr && xhr.status) {
			parts.push('HTTP ' + xhr.status + (xhr.statusText ? ' ' + xhr.statusText : ''));
		} else if (status === 'timeout') {
			parts.push('تایم‌اوت سرور (بدون پاسخ)');
		} else {
			parts.push('بدون پاسخ سرور');
		}

		if (status && status !== 'error') { parts.push('(' + status + ')'); }
		if (errorThrown && errorThrown !== 'error') { parts.push(errorThrown); }

		var body = (xhr && typeof xhr.responseText === 'string') ? xhr.responseText : '';
		if (body) {
			var snippet = body.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
			if (snippet.length > 240) { snippet = snippet.slice(0, 240) + '…'; }
			if (snippet) { parts.push('— ' + snippet); }
		}

		return parts.join(' ');
	}

	function batchHint(description) {
		if (/HTTP (500|502|503|504)/.test(description) || /fatal|memory|خطای مهلک|حافظه/i.test(description)) {
			return 'سرور در میانهٔ پردازش بسته خطا داد. «اندازهٔ هر بسته (Batch Size)» را در تب تنظیمات کمتر کن (مثلاً ۲) و دوباره اجرا کن؛ اگر تکرار شد، پیام خطای همین لاگ را برای پشتیبانی بفرست.';
		}
		if (/HTTP 403|nonce|توکن امنیتی/i.test(description)) {
			return 'توکن امنیتی صفحه منقضی شده است؛ صفحه را رفرش کن و دوباره اجرا کن.';
		}
		return '';
	}

	function updateProgressUi() {
		var total = state.selectedIds.length || 1;
		var percent = Math.round((state.processedCount / total) * 100);
		if (percent > 100) { percent = 100; }
		$('#tcbvm-bar-fill').css('width', percent + '%');
		$('#tcbvm-progress-percent').text(percent + '%');
		$('#tcbvm-stat-processed').text(state.processedCount);
		$('#tcbvm-stat-success').text(state.successCount);
		$('#tcbvm-stat-failed').text(state.failedCount);
	}

	function sendBatch(ids, op, params, callback) {
		$.ajax({
			url: tcbvmData.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			timeout: 240000, // سقف ۴ دقیقه؛ عملیات متغیرها می‌تواند طولانی باشد.
			data: {
				action: 'tcbvm_execute_batch',
				nonce: tcbvmData.nonce,
				run_id: state.currentRunId,
				batch_ids: ids,
				operation: op,
				params: params
			},
			success: function (res) {
				if (res && res.success) {
					callback(null, res.data);
					return;
				}

				// پاسخ JSON معتبر ولی «ناموفق» (مثلاً خطای اعتبارسنجی یا خطای مهلک سرور).
				var message = (res && res.data && res.data.message) ? res.data.message : 'پاسخ نامعتبر از سرور.';
				if (res && res.data && res.data.fatal) { message = '[FATAL] ' + message; }
				callback(new Error(message), null);
			},
			error: function (xhr, status, errorThrown) {
				callback(new Error(describeAjaxError(xhr, status, errorThrown)), null);
			}
		});
	}

	/**
	 * اجرای یک بسته با تلاش مجدد هوشمند.
	 *
	 * اگر بسته به‌خاطر خطای سرور/شبکه شکست بخورد، به دو نیم تقسیم و دوباره تلاش
	 * می‌شود (تا تک‌محصولی) تا یک محصول مشکل‌دار کل اجرا را از کار نیندازد.
	 */
	function runBatchWithRetry(ids, op, params, depth, callback) {
		if (!ids.length) {
			callback(0, 0);
			return;
		}

		sendBatch(ids, op, params, function (err, data) {
			if (!err) {
				var okCount = (data && typeof data.success === 'number') ? data.success : ids.length;
				var failCount = (data && typeof data.failed === 'number') ? data.failed : 0;

				state.processedCount += ids.length;
				state.successCount += okCount;
				state.failedCount += failCount;
				updateProgressUi();

				if (data && data.details) {
					$.each(data.details, function (i, d) {
						logMessage((d.success ? '[OK] ' : '[ERR] ') + 'Product #' + d.id + ': ' + d.message);
					});
				}

				callback(0, 0);
				return;
			}

			// بسته را نصف کن و دوباره تلاش کن.
			if (ids.length > 1 && depth < 4) {
				logMessage('[WARN] بستهٔ ' + ids.length + ' محصولی خطا داد؛ تلاش دوباره با دو بستهٔ کوچک‌تر… (' + err.message + ')');
				var half = Math.ceil(ids.length / 2);

				runBatchWithRetry(ids.slice(0, half), op, params, depth + 1, function () {
					runBatchWithRetry(ids.slice(half), op, params, depth + 1, function () {
						callback(0, 0);
					});
				});
				return;
			}

			// تک‌محصولی هم شکست خورد: ثبت خطا و ادامه دادن با بقیهٔ محصولات.
			state.processedCount += ids.length;
			state.failedCount += ids.length;
			updateProgressUi();

			logMessage('[ERROR] ' + (ids.length === 1 ? 'Product #' + ids[0] : ids.length + ' محصول') + ': ' + err.message);
			var hint = batchHint(err.message);
			if (hint) { logMessage('[HINT] ' + hint); }

			callback(0, 0);
		});
	}

	function executeNextBatch(batchIndex, batches, op, params) {
		if (state.shouldStop || batchIndex >= batches.length) {
			finishBatchProcess();
			return;
		}

		var currentBatch = batches[batchIndex];
		$('#tcbvm-progress-text').text('در حال پردازش بسته ' + (batchIndex + 1) + ' از ' + batches.length + ' (' + state.processedCount + '/' + state.selectedIds.length + ')…');
		updateProgressUi();

		runBatchWithRetry(currentBatch, op, params, 0, function () {
			setTimeout(function () {
				executeNextBatch(batchIndex + 1, batches, op, params);
			}, 200);
		});
	}

	function finishBatchProcess() {
		state.isRunning = false;
		$('#tcbvm-btn-run').prop('disabled', false);

		$('#tcbvm-bar-fill').css('width', '100%');
		$('#tcbvm-progress-percent').text('100%');
		$('#tcbvm-progress-text').text('عملیات کامل شد.');
		updateProgressUi();

		logMessage('--- All batches finished! Success: ' + state.successCount + ' | Failed: ' + state.failedCount + ' ---');

		if (state.failedCount > 0) {
			logMessage('[HINT] برای برگرداندن تغییرات همین اجرا، از تب «گزارش و بازگردانی (Rollback)» استفاده کن.');
		}

		$.ajax({
			url: tcbvmData.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			timeout: 120000,
			data: {
				action: 'tcbvm_finish_run',
				nonce: tcbvmData.nonce,
				run_id: state.currentRunId,
				status: (state.failedCount === 0) ? 'completed' : 'completed_with_errors'
			},
			success: function () {
				alert(tcbvmData.i18n.completedText);
			}
		});
	}

	function logMessage(msg) {
		var $console = $('#tcbvm-log-console');
		var time = new Date().toLocaleTimeString();
		$console.append('[' + time + '] ' + escapeHtml(msg) + '\n');
		$console.scrollTop($console[0].scrollHeight);
	}

	function collectParams(op) {
		var attrName = $.trim($('#tcbvm-attr-name').val()) || 'مدل گوشی';
		var models = $.trim($('#tcbvm-models-input').val());
		var cloneCheck = $('#tcbvm-clone-price-check').is(':checked') ? 1 : 0;
		var cloneRef = $.trim($('#tcbvm-clone-ref-model').val());
		var regPrice = $('#tcbvm-regular-price').val();
		var salePrice = $('#tcbvm-sale-price').val();
		var stockStatus = $('#tcbvm-stock-status').val();
		var deleteMode = $('input[name="tcbvm_delete_mode"]:checked').val() || 'soft';

		var oldModel = $.trim($('#tcbvm-old-model').val());
		var newModel = $.trim($('#tcbvm-new-model').val());

		return {
			attr_name: attrName,
			models: models,
			clone_from_model: cloneCheck ? cloneRef : '',
			regular_price: regPrice,
			sale_price: salePrice,
			stock_status: stockStatus,
			delete_mode: deleteMode,
			old_model: oldModel,
			new_model: newModel
		};
	}

	/* -------------------------------------------------------------
	 * ۸. بازگردانی (Rollback)
	 * ----------------------------------------------------------- */
	function initRollback() {
		$(document).on('click', '.tc-btn-rollback', function () {
			var $btn = $(this);
			var runId = $btn.data('run-id');

			if (!confirm(tcbvmData.i18n.confirmRollback)) {
				return;
			}

			$btn.prop('disabled', true).text('در حال بازگردانی…');

			$.ajax({
				url: tcbvmData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'tcbvm_rollback',
					nonce: tcbvmData.nonce,
					run_id: runId
				},
				success: function (res) {
					if (res.success) {
						alert(res.data.message);
						location.reload();
					} else {
						$btn.prop('disabled', false).text('بازگردانی');
						alert(res.data.message || 'خطا در بازگردانی');
					}
				},
				error: function () {
					$btn.prop('disabled', false).text('بازگردانی');
					alert('خطا در برقراری ارتباط با سرور.');
				}
			});
		});
	}

	/* -------------------------------------------------------------
	 * ۹. ذخیره و حذف الگوها
	 * ----------------------------------------------------------- */
	function initPresetSave() {
		$('#tcbvm-btn-save-preset').on('click', function () {
			var name = $.trim($('#tcbvm-new-preset-name').val());
			var desc = $.trim($('#tcbvm-new-preset-desc').val());
			var models = $.trim($('#tcbvm-new-preset-models').val());

			if (!name || !models) {
				alert('عنوان الگو و مدل‌ها الزامی هستند.');
				return;
			}

			var $btn = $(this);
			$btn.prop('disabled', true);

			$.ajax({
				url: tcbvmData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'tcbvm_save_preset',
					nonce: tcbvmData.nonce,
					name: name,
					description: desc,
					models: models
				},
				success: function (res) {
					$btn.prop('disabled', false);
					if (res.success) {
						alert(res.data.message);
						location.reload();
					} else {
						alert(res.data.message || 'خطا');
					}
				},
				error: function () {
					$btn.prop('disabled', false);
					alert('خطا در ثبت الگو.');
				}
			});
		});

		$(document).on('click', '.tc-btn-delete-preset', function (e) {
			e.stopPropagation();
			var id = $(this).data('id');
			if (!confirm(tcbvmData.i18n.confirmDeletePreset)) {
				return;
			}

			$.ajax({
				url: tcbvmData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'tcbvm_delete_preset',
					nonce: tcbvmData.nonce,
					id: id
				},
				success: function (res) {
					if (res.success) {
						location.reload();
					} else {
						alert(res.data.message || 'خطا');
					}
				}
			});
		});
	}

	/* -------------------------------------------------------------
	 * ۱۰. نوسازی کش قیمت‌ها
	 * ----------------------------------------------------------- */
	function initCacheFlush() {
		$('#tcbvm-btn-flush-cache').on('click', function () {
			var $btn = $(this);
			$btn.prop('disabled', true).text('در حال نوسازی…');

			$.ajax({
				url: tcbvmData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'tcbvm_flush_cache',
					nonce: tcbvmData.nonce
				},
				success: function (res) {
					$btn.prop('disabled', false).text('نوسازی کش قیمت‌های ووکامرس');
					alert(res.data.message);
				},
				error: function () {
					$btn.prop('disabled', false).text('نوسازی کش قیمت‌های ووکامرس');
					alert('خطا در پاکسازی کش.');
				}
			});
		});
	}

	function escapeHtml(text) {
		if (!text) return '';
		return $('<div>').text(text).html();
	}

})(jQuery);
