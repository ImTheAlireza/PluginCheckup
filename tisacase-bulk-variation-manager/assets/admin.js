/**
 * TisaCase Bulk Variation Manager — Admin JavaScript
 */
(function ($) {
	'use strict';

	var state = {
		matchedIds: [],
		selectedIds: [],
		isRunning: false,
		shouldStop: false,
		currentRunId: null
	};

	$(document).ready(function () {
		initTabs();
		initOpCards();
		initPresetChips();
		initSearch();
		initSelection();
		initPreview();
		initBulkRun();
		initRollback();
		initPresetSave();
		initCacheFlush();
		initQuickJump();
	});

	/* -------------------------------------------------------------
	 * ۱. تب‌ها (Tabs Navigation)
	 * ----------------------------------------------------------- */
	function initTabs() {
		$(document).on('click', '.tcbvm-tab', function (e) {
			e.preventDefault();
			var tabKey = $(this).data('tab');

			$('.tcbvm-tab').removeClass('active');
			$(this).addClass('active');

			$('.tcbvm-panel').removeClass('active');
			$('.tcbvm-panel[data-panel="' + tabKey + '"]').addClass('active');

			if (history.pushState) {
				history.pushState(null, null, '#tab-' + tabKey);
			}
		});

		var hash = window.location.hash.replace('#tab-', '');
		if (hash && $('.tcbvm-panel[data-panel="' + hash + '"]').length) {
			$('.tcbvm-tab[data-tab="' + hash + '"]').trigger('click');
		}
	}

	function initQuickJump() {
		$(document).on('click', '.tcbvm-goto-tab', function () {
			var target = $(this).data('target');
			if (target && $('.tcbvm-tab[data-tab="' + target + '"]').length) {
				$('.tcbvm-tab[data-tab="' + target + '"]').trigger('click');
				$('html, body').animate({ scrollTop: 0 }, 200);
			}
		});
	}

	/* -------------------------------------------------------------
	 * ۲. کارت‌های انتخاب عملیات
	 * ----------------------------------------------------------- */
	function initOpCards() {
		$(document).on('click', '.tcbvm-op-card', function () {
			$('.tcbvm-op-card').removeClass('is-active');
			$(this).addClass('is-active');

			var op = $(this).find('input[type="radio"]').val();
			updateFormFieldsForOp(op);
		});

		$('#tcbvm-clone-price-check').on('change', function () {
			if ($(this).is(':checked')) {
				$('#tcbvm-clone-ref-wrap').slideDown(150);
			} else {
				$('#tcbvm-clone-ref-wrap').slideUp(150);
			}
		});
	}

	function updateFormFieldsForOp(op) {
		var $modelsWrap = $('#tcbvm-models-input-wrap');
		var $replaceWrap = $('#tcbvm-replace-input-wrap');
		var $pricingWrap = $('#tcbvm-pricing-options-wrap');
		var $deleteModeWrap = $('#tcbvm-delete-mode-wrap');

		switch (op) {
			case 'add_models':
				$modelsWrap.slideDown(150);
				$replaceWrap.slideUp(150);
				$pricingWrap.slideDown(150);
				$deleteModeWrap.slideUp(150);
				break;

			case 'remove_models':
				$modelsWrap.slideDown(150);
				$replaceWrap.slideUp(150);
				$pricingWrap.slideUp(150);
				$deleteModeWrap.slideDown(150);
				break;

			case 'replace_model':
				$modelsWrap.slideUp(150);
				$replaceWrap.slideDown(150);
				$pricingWrap.slideUp(150);
				$deleteModeWrap.slideUp(150);
				break;

			case 'sync_preset':
				$modelsWrap.slideDown(150);
				$replaceWrap.slideUp(150);
				$pricingWrap.slideDown(150);
				$deleteModeWrap.slideDown(150);
				break;

			case 'bulk_price_stock':
				$modelsWrap.slideDown(150);
				$replaceWrap.slideUp(150);
				$pricingWrap.slideDown(150);
				$deleteModeWrap.slideUp(150);
				break;
		}
	}

	/* -------------------------------------------------------------
	 * ۳. چیپ‌های درج سریع الگوها
	 * ----------------------------------------------------------- */
	function initPresetChips() {
		$(document).on('click', '.tcbvm-chip-btn', function () {
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
			$textarea.css('background', '#e3f2ef');
			setTimeout(function () {
				$textarea.css('background', '');
			}, 300);
		});
	}

	/* -------------------------------------------------------------
	 * ۴. جستجوی زنده و فیلتر کردن محصولات
	 * ----------------------------------------------------------- */
	function initSearch() {
		$('#tcbvm-btn-search').on('click', function () {
			var $btn = $(this);
			var originalHtml = $btn.html();

			var selectedCats = $('#tcbvm-cat-select').val() || [];
			var includeChildren = $('#tcbvm-cat-children').is(':checked') ? 1 : 0;
			var keywords = $('#tcbvm-keywords').val();
			var excludeKeywords = $('#tcbvm-exclude-keywords').val();
			var manualIds = $('#tcbvm-manual-ids').val();
			var modelFilter = $('#tcbvm-model-filter').val();

			$btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> در حال جستجو…');
			$('#tcbvm-search-counter').text('در حال اسکن پایگاه داده…');

			$.ajax({
				url: tcbvmData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'tcbvm_search_products',
					nonce: tcbvmData.nonce,
					filters: {
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
			$tbody.html('<tr><td colspan="8" style="text-align:center; padding:20px; color:#6f6a5e;">هیچ محصولی با فیلترهای انتخابی یافت نشد.</td></tr>');
			$box.slideDown(200);
			updateSelectionBadge();
			return;
		}

		$.each(items, function (idx, item) {
			var modelsHtml = '';
			if (item.models && item.models.length > 0) {
				$.each(item.models.slice(0, 6), function (i, m) {
					modelsHtml += '<span class="tcbvm-tag">' + escapeHtml(m) + '</span>';
				});
				if (item.models_total > 6) {
					modelsHtml += '<span class="tcbvm-tag" style="background:#e5e7eb; font-weight:700;">+' + (item.models_total - 6) + '</span>';
				}
			} else {
				modelsHtml = '<span style="color:#a0aec0; font-size:11px;">بدون متغیر فعلی</span>';
			}

			var row = '<tr data-id="' + item.id + '">'
				+ '<td><input type="checkbox" class="tc-prod-checkbox" value="' + item.id + '" checked></td>'
				+ '<td><img src="' + item.image_url + '" class="tcbvm-thumb" alt=""></td>'
				+ '<td><strong><a href="' + item.edit_url + '" target="_blank">' + escapeHtml(item.name) + '</a></strong></td>'
				+ '<td><code>' + escapeHtml(item.sku) + '</code> <small>(#' + item.id + ')</small></td>'
				+ '<td><span style="font-size:12px; color:#4a5568;">' + escapeHtml(item.cats) + '</span></td>'
				+ '<td><span class="tcbvm-badge">' + item.variation_count + ' متغیر</span></td>'
				+ '<td>' + modelsHtml + '</td>'
				+ '<td><a href="' + item.edit_url + '" class="tcbvm-btn tcbvm-btn--secondary" style="padding:4px 8px; font-size:11px;" target="_blank">ویرایش</a></td>'
				+ '</tr>';

			$tbody.append(row);
		});

		$box.slideDown(200);
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

			var op = $('input[name="tcbvm_op"]:checked').val();
			var params = collectParams(op);

			var $btn = $(this);
			var orig = $btn.html();
			$btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> در حال محاسبه…');

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
			$content.html('<p>هیچ تغییری برای این محصولات ثبت نشد.</p>');
			$box.slideDown(200);
			return;
		}

		var summaryHtml = '<p style="margin-bottom:12px; font-weight:700;">پیش‌نمایش روی نمونه‌ای از ' + data.total_selected + ' محصول انتخابی:</p>';
		$content.append(summaryHtml);

		$.each(data.samples, function (idx, item) {
			var addsHtml = item.to_add.length ? '<p style="color:#0e7a6b; margin:3px 0;"><strong>+ مدل‌های اضافه شونده:</strong> ' + item.to_add.join('، ') + '</p>' : '';
			var remsHtml = item.to_remove.length ? '<p style="color:#b3261e; margin:3px 0;"><strong>- مدل‌های حذف/ناموجود شونده:</strong> ' + item.to_remove.join('، ') + '</p>' : '';
			var modsHtml = item.to_modify.length ? '<p style="color:#1d4ed8; margin:3px 0;"><strong>~ مدل‌های ویرایش شونده:</strong> ' + item.to_modify.join('، ') + '</p>' : '';
			var notesHtml = item.notes.length ? '<p style="color:#6f6a5e; font-size:11.5px; margin:3px 0;"><strong>نکات:</strong> ' + item.notes.join(' | ') + '</p>' : '';

			var itemHtml = '<div style="background:#fff; border:1px solid #fae8a4; border-radius:10px; padding:12px 14px; margin-bottom:8px;">'
				+ '<h5 style="margin:0 0 5px; font-size:13px; color:#221d15;">' + escapeHtml(item.name) + ' <small>(#' + item.id + ' | SKU: ' + escapeHtml(item.sku) + ')</small></h5>'
				+ addsHtml + remsHtml + modsHtml + notesHtml
				+ '</div>';

			$content.append(itemHtml);
		});

		$box.slideDown(200);
		$('html, body').animate({
			scrollTop: $box.offset().top - 50
		}, 300);
	}

	/* -------------------------------------------------------------
	 * ۷. پردازش پله‌ای تحت ایجکس (Batch Execution)
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

			var op = $('input[name="tcbvm_op"]:checked').val();
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

		var $btn = $('#tcbvm-btn-run');
		$btn.prop('disabled', true);

		$('#tcbvm-progress-wrap').slideDown(200);
		$('#tcbvm-progress-bar').css('width', '0%');
		$('#tcbvm-progress-percent').text('0%');
		$('#tcbvm-progress-text').text('در حال ثبت نشست و تهیه اسنپ‌شات اولیه…');
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
					executeNextBatch(0, batches, op, params, 0, 0);
				} else {
					state.isRunning = false;
					$btn.prop('disabled', false);
					alert(res.data.message || 'خطا در شروع نشست');
				}
			},
			error: function () {
				state.isRunning = false;
				$btn.prop('disabled', false);
				alert('خطای ارتباط در ایجاد نشست.');
			}
		});
	}

	function executeNextBatch(batchIndex, batches, op, params, totalSuccess, totalFailed) {
		if (state.shouldStop || batchIndex >= batches.length) {
			finishBatchProcess(totalSuccess, totalFailed);
			return;
		}

		var currentBatch = batches[batchIndex];
		var totalItems = state.selectedIds.length;
		var currentProcessed = (batchIndex * batches[0].length) + currentBatch.length;
		if (currentProcessed > totalItems) currentProcessed = totalItems;

		var percent = Math.round((currentProcessed / totalItems) * 100);
		$('#tcbvm-progress-bar').css('width', percent + '%');
		$('#tcbvm-progress-percent').text(percent + '%');
		$('#tcbvm-progress-text').text('در حال ویرایش بسته ' + (batchIndex + 1) + ' از ' + batches.length + ' (' + currentProcessed + '/' + totalItems + ')…');

		$.ajax({
			url: tcbvmData.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'tcbvm_execute_batch',
				nonce: tcbvmData.nonce,
				run_id: state.currentRunId,
				batch_ids: currentBatch,
				operation: op,
				params: params
			},
			success: function (res) {
				if (res.success) {
					totalSuccess += res.data.success || 0;
					totalFailed += res.data.failed || 0;

					$('#tcbvm-stat-processed').text(currentProcessed);
					$('#tcbvm-stat-success').text(totalSuccess);
					$('#tcbvm-stat-failed').text(totalFailed);

					if (res.data.details) {
						$.each(res.data.details, function (i, d) {
							logMessage((d.success ? '[OK] ' : '[ERR] ') + 'Product #' + d.id + ': ' + d.message);
						});
					}

					setTimeout(function () {
						executeNextBatch(batchIndex + 1, batches, op, params, totalSuccess, totalFailed);
					}, 200);
				} else {
					logMessage('[FAIL] Batch ' + (batchIndex + 1) + ' failed: ' + (res.data.message || 'unknown error'));
					executeNextBatch(batchIndex + 1, batches, op, params, totalSuccess, totalFailed + currentBatch.length);
				}
			},
			error: function () {
				logMessage('[ERROR] Network timeout on batch ' + (batchIndex + 1));
				executeNextBatch(batchIndex + 1, batches, op, params, totalSuccess, totalFailed + currentBatch.length);
			}
		});
	}

	function finishBatchProcess(totalSuccess, totalFailed) {
		state.isRunning = false;
		$('#tcbvm-btn-run').prop('disabled', false);

		$('#tcbvm-progress-bar').css('width', '100%');
		$('#tcbvm-progress-percent').text('100%');
		$('#tcbvm-progress-text').text('عملیات به پایان رسید.');

		logMessage('--- All batches finished! Success: ' + totalSuccess + ' | Failed: ' + totalFailed + ' ---');

		$.ajax({
			url: tcbvmData.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'tcbvm_finish_run',
				nonce: tcbvmData.nonce,
				run_id: state.currentRunId,
				status: (totalFailed === 0) ? 'completed' : 'completed_with_errors'
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

			var orig = $btn.html();
			$btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> در حال بازگردانی…');

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
						$btn.prop('disabled', false).html(orig);
						alert(res.data.message || 'خطا در بازگردانی');
					}
				},
				error: function () {
					$btn.prop('disabled', false).html(orig);
					alert('خطا در برقراری ارتباط با سرور.');
				}
			});
		});
	}

	/* -------------------------------------------------------------
	 * ۹. مدیریت الگوهای آماده
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
			var orig = $btn.html();
			$btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> در حال نوسازی…');

			$.ajax({
				url: tcbvmData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'tcbvm_flush_cache',
					nonce: tcbvmData.nonce
				},
				success: function (res) {
					$btn.prop('disabled', false).html(orig);
					alert(res.data.message);
				},
				error: function () {
					$btn.prop('disabled', false).html(orig);
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
