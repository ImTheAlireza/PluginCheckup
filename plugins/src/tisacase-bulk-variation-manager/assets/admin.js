/**
 * TisaCase Bulk Variation Manager — Client Controller
 *
 * @package TisaCase_Bulk_Variation_Manager
 */

(function($) {
	'use strict';

	/**
	 * آبجکت مرکزی مدیریت متغیرهای گروهی
	 */
	const TCBVM = {
		selectedProducts: {},
		isExecuting: false,

		init: function() {
			this.bindTargetMode();
			this.bindProductSearch();
			this.bindProductTable();
			this.bindModelsInput();
			this.bindPriceFormat();
			this.bindActions();
			this.bindPresets();
			this.bindRollback();
			this.bindCacheFlush();
			this.initSelect2();
		},

		/**
		 * راه‌اندازی Select2 ووکامرس برای دسته‌بندی‌ها
		 */
		initSelect2: function() {
			if ($.fn.select2) {
				$('#tcbvm-cat-select').select2({
					dir: 'rtl',
					placeholder: $('#tcbvm-cat-select').data('placeholder') || 'دسته‌بندی‌ها را انتخاب کنید…',
					allowClear: true,
					width: '100%'
				});
			}
		},

		/**
		 * فرمت ارقام با جداکننده سه‌رقمی
		 */
		formatNumber: function(num) {
			if (!num && num !== 0) return '0';
			return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
		},

		/**
		 * تبدیل ارقام به فارسی
		 */
		toPersianDigits: function(str) {
			if (str === null || str === undefined) return '';
			const farsi = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
			return str.toString().replace(/[0-9]/g, function(d) {
				return farsi[d];
			});
		},

		/**
		 * لاگ کردن پیام در کنسول زنده
		 */
		log: function(msg, type) {
			const $console = $('#tcbvm-log-console');
			if (!$console.length) return;

			const time = new Date().toLocaleTimeString('fa-IR');
			let prefix = '';
			if (type === 'success') prefix = '✓ ';
			else if (type === 'error') prefix = '✗ ';
			else if (type === 'info') prefix = 'ℹ ';

			const line = '[' + time + '] ' + prefix + msg + '\n';
			$console.append(line);
			$console.scrollTop($console[0].scrollHeight);
		},

		/**
		 * سوییچ بین شیوه‌های انتخاب محصول (دسته‌بندی، شناسه SKU، مستقیم، شناسه عددی)
		 */
		bindTargetMode: function() {
			$('input[name="tcbvm_target_mode"]').on('change', function() {
				const mode = $(this).val();
				$('.tcbvm-seg-item').removeClass('is-active');
				$(this).closest('.tcbvm-seg-item').addClass('is-active');

				$('.tcbvm-tab-pane').addClass('tcbvm-hidden');
				if (mode === 'category') {
					$('#tcbvm-cat-box').removeClass('tcbvm-hidden');
				} else if (mode === 'sku') {
					$('#tcbvm-sku-box').removeClass('tcbvm-hidden');
				} else if (mode === 'direct') {
					$('#tcbvm-direct-box').removeClass('tcbvm-hidden');
				} else if (mode === 'manual') {
					$('#tcbvm-manual-box').removeClass('tcbvm-hidden');
				}
			});
		},

		/**
		 * جستجو و استخراج محصولات
		 */
		bindProductSearch: function() {
			const self = this;

			// جستجو بر اساس دسته‌بندی و فیلترها (شامل فیلتر SKU اختیاری)
			$('#tcbvm-btn-search').on('click', function(e) {
				e.preventDefault();
				const $btn = $(this);
				const catIds = $('#tcbvm-cat-select').val() || [];
				const includeChildren = $('#tcbvm-cat-children').is(':checked') ? 1 : 0;
				const keywords = $('#tcbvm-keywords').val();
				const excludeKeywords = $('#tcbvm-exclude-keywords').val();
				const catSku = $('#tcbvm-cat-sku').val();

				$btn.prop('disabled', true).addClass('is-busy');
				$('#tcbvm-search-counter').text('در حال واکشی محصولات…');

				$.ajax({
					url: tcbvmData.ajaxUrl,
					type: 'POST',
					data: {
						action: 'tcbvm_search_products',
						nonce: tcbvmData.nonce,
						filters: {
							category_ids: catIds,
							include_children: includeChildren,
							keywords: keywords,
							exclude_keywords: excludeKeywords,
							sku: catSku
						}
					},
					success: function(resp) {
						$btn.prop('disabled', false).removeClass('is-busy');
						if (resp.success && resp.data && resp.data.items) {
							resp.data.items.forEach(function(item) {
								self.selectedProducts[item.id] = item;
							});
							self.renderProductTable();
							$('#tcbvm-search-counter').text(resp.data.message || (resp.data.items.length + ' محصول یافت شد.'));
						} else {
							$('#tcbvm-search-counter').text(resp.data && resp.data.message ? resp.data.message : 'محصولی یافت نشد.');
						}
					},
					error: function() {
						$btn.prop('disabled', false).removeClass('is-busy');
						$('#tcbvm-search-counter').text('خطا در برقراری ارتباط با سرور.');
					}
				});
			});

			// جستجو اختصاصی بر اساس شناسه / کد محصول (SKU)
			$('#tcbvm-btn-sku-search').on('click', function(e) {
				e.preventDefault();
				const $btn = $(this);
				const skuVal = $('#tcbvm-sku-input').val().trim();
				const skuMode = $('#tcbvm-sku-mode').val();

				if (!skuVal) {
					alert('لطفاً پیشوند یا مقدار شناسه (SKU) مورد نظر را وارد نمایید (مثلاً: CH).');
					return;
				}

				$btn.prop('disabled', true).addClass('is-busy');
				$('#tcbvm-sku-counter').text('در حال استخراج محصولات با شناسه ' + skuVal + '…');

				$.ajax({
					url: tcbvmData.ajaxUrl,
					type: 'POST',
					data: {
						action: 'tcbvm_search_products',
						nonce: tcbvmData.nonce,
						filters: {
							mode: 'sku',
							sku: skuVal,
							sku_mode: skuMode
						}
					},
					success: function(resp) {
						$btn.prop('disabled', false).removeClass('is-busy');
						if (resp.success && resp.data && resp.data.items) {
							resp.data.items.forEach(function(item) {
								self.selectedProducts[item.id] = item;
							});
							self.renderProductTable();
							$('#tcbvm-sku-counter').text(resp.data.message || (resp.data.items.length + ' محصول یافت شد.'));
						} else {
							$('#tcbvm-sku-counter').text(resp.data && resp.data.message ? resp.data.message : 'هیچ محصولی با این شناسه یافت نشد.');
						}
					},
					error: function() {
						$btn.prop('disabled', false).removeClass('is-busy');
						$('#tcbvm-sku-counter').text('خطا در برقراری ارتباط با سرور.');
					}
				});
			});

			// افزودن دستی شناسه‌ها
			$('#tcbvm-btn-manual-add').on('click', function(e) {
				e.preventDefault();
				const $btn = $(this);
				const rawIds = $('#tcbvm-manual-ids').val();
				if (!rawIds.trim()) {
					alert('لطفاً حداقل یک شناسه محصول وارد کنید.');
					return;
				}

				$btn.prop('disabled', true);
				$.ajax({
					url: tcbvmData.ajaxUrl,
					type: 'POST',
					data: {
						action: 'tcbvm_search_products',
						nonce: tcbvmData.nonce,
						filters: {
							mode: 'manual',
							manual_ids: rawIds
						}
					},
					success: function(resp) {
						$btn.prop('disabled', false);
						if (resp.success && resp.data && resp.data.items) {
							resp.data.items.forEach(function(item) {
								self.selectedProducts[item.id] = item;
							});
							self.renderProductTable();
							$('#tcbvm-manual-ids').val('');
						} else {
							alert('محصول معتبری با این شناسه‌ها یافت نشد.');
						}
					},
					error: function() {
						$btn.prop('disabled', false);
						alert('خطا در بررسی شناسه‌ها.');
					}
				});
			});

			// جستجوی مستقیم تک‌محصول با Autocomplete
			let searchTimeout = null;
			$('#tcbvm-single-search').on('input keyup', function() {
				const query = $(this).val().trim();
				clearTimeout(searchTimeout);
				if (query.length < 2) {
					$('#tcbvm-single-dropdown').addClass('tcbvm-hidden').empty();
					return;
				}

				searchTimeout = setTimeout(function() {
					$.ajax({
						url: tcbvmData.ajaxUrl,
						type: 'GET',
						data: {
							action: 'tcbvm_search_single_products',
							nonce: tcbvmData.nonce,
							term: query
						},
						success: function(resp) {
							const $drop = $('#tcbvm-single-dropdown');
							$drop.empty();
							if (resp.success && resp.data && resp.data.results && resp.data.results.length) {
								resp.data.results.forEach(function(item) {
									const $it = $('<div class="tcbvm-autocomplete-item"></div>');
									$it.append('<img src="' + item.image_url + '" class="tcbvm-thumb" alt="">');
									$it.append('<div><strong>' + item.name + '</strong><br><small class="tcbvm-muted">SKU: ' + item.sku + ' | شناسه: #' + item.id + ' (' + item.variation_count + ' متغیر فعلی)</small></div>');
									$it.on('click', function() {
										self.selectedProducts[item.id] = item;
										self.renderProductTable();
										$drop.addClass('tcbvm-hidden').empty();
										$('#tcbvm-single-search').val('');
									});
									$drop.append($it);
								});
								$drop.removeClass('tcbvm-hidden');
							} else {
								$drop.append('<div class="tcbvm-autocomplete-item"><small class="tcbvm-muted">محصولی یافت نشد.</small></div>').removeClass('tcbvm-hidden');
							}
						}
					});
				}, 300);
			});

			$('#tcbvm-btn-single-search').on('click', function() {
				$('#tcbvm-single-search').trigger('input');
			});
		},

		/**
		 * رندر و به‌روزرسانی جدول محصولات انتخاب‌شده
		 */
		renderProductTable: function() {
			const self = this;
			const pids = Object.keys(self.selectedProducts);
			const $tbody = $('#tcbvm-products-tbody');
			const $box = $('#tcbvm-products-box');

			if (pids.length === 0) {
				$box.addClass('tcbvm-hidden');
				$('#tcbvm-selected-badge').text('۰ محصول انتخاب‌شده');
				return;
			}

			$box.removeClass('tcbvm-hidden');
			$('#tcbvm-selected-badge').text(self.toPersianDigits(self.formatNumber(pids.length)) + ' محصول انتخاب‌شده');
			$tbody.empty();

			pids.forEach(function(pid) {
				const item = self.selectedProducts[pid];
				const isChecked = item.selected !== false;
				const row = $(
					'<tr data-pid="' + item.id + '">' +
						'<td class="tcbvm-col-w38 tcbvm-center"><input type="checkbox" class="tcbvm-product-checkbox" ' + (isChecked ? 'checked' : '') + '></td>' +
						'<td class="tcbvm-col-w48"><img src="' + (item.image_url || '') + '" class="tcbvm-thumb" alt=""></td>' +
						'<td><a href="' + (item.edit_url || '#') + '" target="_blank"><strong>' + item.name + '</strong></a></td>' +
						'<td><span class="tisa-code">' + (item.sku || '—') + '</span> <small class="tcbvm-muted">(#' + item.id + ')</small></td>' +
						'<td><small class="tcbvm-muted">' + (item.cats || '—') + '</small></td>' +
						'<td><span class="tcbvm-badge">' + (item.type === 'variable' ? 'متغیر' : 'ساده') + '</span></td>' +
						'<td><strong>' + self.toPersianDigits(item.variation_count || 0) + '</strong> متغیر</td>' +
						'<td class="tcbvm-col-w70 tcbvm-center"><button type="button" class="tcbvm-btn-remove-row" title="حذف از لیست">&times;</button></td>' +
					'</tr>'
				);
				$tbody.append(row);
			});
		},

		/**
		 * مدیریت رخدادهای جدول محصولات (چک‌باکس، حذف، پاک کردن همه)
		 */
		bindProductTable: function() {
			const self = this;

			// تغییر وضعیت تک محصول
			$(document).on('change', '.tcbvm-product-checkbox', function() {
				const pid = $(this).closest('tr').data('pid');
				if (self.selectedProducts[pid]) {
					self.selectedProducts[pid].selected = $(this).is(':checked');
				}
				self.updateSelectedBadge();
			});

			// انتخاب همه / لغو همه
			$('#tcbvm-select-all').on('change', function() {
				const checked = $(this).is(':checked');
				$('.tcbvm-product-checkbox').prop('checked', checked);
				Object.keys(self.selectedProducts).forEach(function(pid) {
					self.selectedProducts[pid].selected = checked;
				});
				self.updateSelectedBadge();
			});

			// دکمه حذف تک‌سطر
			$(document).on('click', '.tcbvm-btn-remove-row', function() {
				const pid = $(this).closest('tr').data('pid');
				delete self.selectedProducts[pid];
				self.renderProductTable();
			});

			// دکمه پاک کردن کل لیست
			$('#tcbvm-btn-clear-selection').on('click', function() {
				if (confirm('آیا مایل به پاک کردن تمام محصولات انتخاب‌شده از لیست هستید؟')) {
					self.selectedProducts = {};
					self.renderProductTable();
				}
			});
		},

		updateSelectedBadge: function() {
			const checkedCount = this.getActiveProductIds().length;
			$('#tcbvm-selected-badge').text(this.toPersianDigits(this.formatNumber(checkedCount)) + ' محصول فعال');
		},

		/**
		 * استخراج شناسه‌های محصولاتی که تیک خورده‌اند
		 */
		getActiveProductIds: function() {
			const self = this;
			const ids = [];
			Object.keys(self.selectedProducts).forEach(function(pid) {
				if (self.selectedProducts[pid].selected !== false) {
					ids.push(parseInt(pid, 10));
				}
			});
			return ids;
		},

		/**
		 * مدیریت ورودی مدل‌ها و الگوهای آماده
		 */
		bindModelsInput: function() {
			const self = this;

			const updateCount = function() {
				const raw = $('#tcbvm-models-input').val();
				const list = self.parseModelsList(raw);
				$('#tcbvm-models-count').text(self.toPersianDigits(list.length) + ' متغیر تعریف شد');
			};

			$('#tcbvm-models-input').on('input keyup change', updateCount);

			// کلیک روی چیپ الگوهای آماده جهت درج سریع
			$('.tcbvm-chip-btn').on('click', function(e) {
				e.preventDefault();
				const pId = $(this).data('preset-id');
				if (tcbvmData.presets && tcbvmData.presets[pId] && tcbvmData.presets[pId].models) {
					const models = tcbvmData.presets[pId].models;
					$('#tcbvm-models-input').val(models.join('\n')).trigger('input');
				}
			});
		},

		/**
		 * تفکیک مقادیر مدل‌ها از روی متن
		 */
		parseModelsList: function(raw) {
			if (!raw || !raw.trim()) return [];
			let lines = [];
			if (raw.indexOf('\n') !== -1 || raw.indexOf('\r') !== -1 || raw.indexOf('|') !== -1) {
				lines = raw.split(/[\r\n|]+/);
			} else {
				lines = raw.split(/[,،]+/);
			}

			const unique = [];
			lines.forEach(function(line) {
				const trimmed = line.trim().replace(/^["'`•\-\s]+|["'`•\-\s]+$/g, '');
				if (trimmed && unique.indexOf(trimmed) === -1) {
					unique.push(trimmed);
				}
			});
			return unique;
		},

		/**
		 * فرمت زنده قیمت‌ها به حروف و تومان
		 */
		bindPriceFormat: function() {
			const self = this;

			const handlePrice = function($input, $display) {
				$input.on('input keyup', function() {
					const digits = $(this).val().replace(/[^\d]/g, '');
					if (!digits) {
						$display.text('');
						return;
					}
					const num = parseInt(digits, 10);
					$display.text(self.toPersianDigits(self.formatNumber(num)) + ' ' + (tcbvmData.currency || 'تومان'));
				});
			};

			handlePrice($('#tcbvm-regular-price'), $('#tcbvm-price-preview'));
			handlePrice($('#tcbvm-sale-price'), $('#tcbvm-sale-preview'));
		},

		/**
		 * دکمه‌های پیش‌نمایش و شروع اجرای قطعی
		 */
		bindActions: function() {
			const self = this;

			// پیش‌نمایش قبل از اجرا
			$('#tcbvm-btn-preview').on('click', function(e) {
				e.preventDefault();
				const pids = self.getActiveProductIds();
				if (!pids.length) {
					alert(tcbvmData.i18n.selectProductsPrompt);
					return;
				}

				const attrName = $('#tcbvm-attr-name').val().trim() || 'مدل گوشی';
				const models = self.parseModelsList($('#tcbvm-models-input').val());
				if (!models.length) {
					alert(tcbvmData.i18n.enterModelsPrompt);
					return;
				}

				const price = $('#tcbvm-regular-price').val().replace(/[^\d]/g, '');
				if (!price) {
					alert(tcbvmData.i18n.enterPricePrompt);
					return;
				}

				const salePrice = $('#tcbvm-sale-price').val().replace(/[^\d]/g, '');
				const combineOther = $('#tcbvm-combine-other').is(':checked') ? 1 : 0;

				const $btn = $(this);
				$btn.prop('disabled', true).addClass('is-busy');

				$.ajax({
					url: tcbvmData.ajaxUrl,
					type: 'POST',
					data: {
						action: 'tcbvm_preview',
						nonce: tcbvmData.nonce,
						product_ids: pids,
						attr_name: attrName,
						new_values: models,
						price: price,
						sale_price: salePrice,
						combine_other: combineOther
					},
					success: function(resp) {
						$btn.prop('disabled', false).removeClass('is-busy');
						if (resp.success && resp.data) {
							self.renderPreview(resp.data);
						} else {
							alert(resp.data && resp.data.message ? resp.data.message : 'خطا در محاسبه پیش‌نمایش.');
						}
					},
					error: function() {
						$btn.prop('disabled', false).removeClass('is-busy');
						alert('خطا در ارتباط با سرور.');
					}
				});
			});

			// اجرای قطعی عملیات
			$('#tcbvm-btn-run').on('click', function(e) {
				e.preventDefault();
				if (self.isExecuting) return;

				const pids = self.getActiveProductIds();
				if (!pids.length) {
					alert(tcbvmData.i18n.selectProductsPrompt);
					return;
				}

				const attrName = $('#tcbvm-attr-name').val().trim() || 'مدل گوشی';
				const models = self.parseModelsList($('#tcbvm-models-input').val());
				if (!models.length) {
					alert(tcbvmData.i18n.enterModelsPrompt);
					return;
				}

				const price = $('#tcbvm-regular-price').val().replace(/[^\d]/g, '');
				if (!price) {
					alert(tcbvmData.i18n.enterPricePrompt);
					return;
				}

				const promptMsg = tcbvmData.i18n.confirmStart.replace('{n}', self.toPersianDigits(pids.length));
				if (!confirm(promptMsg)) {
					return;
				}

				self.startBatchExecution({
					product_ids: pids,
					attr_name: attrName,
					new_values: models,
					price: price,
					sale_price: $('#tcbvm-sale-price').val().replace(/[^\d]/g, ''),
					stock_status: $('#tcbvm-stock-status').val(),
					combine_other: $('#tcbvm-combine-other').is(':checked') ? 1 : 0
				});
			});
		},

		/**
		 * رندر کارت پیش‌نمایش
		 */
		renderPreview: function(data) {
			const self = this;
			const $box = $('#tcbvm-preview-output');
			const $content = $('#tcbvm-preview-content');
			$content.empty();

			let html = '<div class="tcbvm-preview-grid">';
			html += '<div class="tcbvm-stat-box"><span class="tcbvm-stat-num">' + self.toPersianDigits(data.total_products) + '</span><span class="tcbvm-stat-lbl">محصول انتخابی</span></div>';
			html += '<div class="tcbvm-stat-box"><span class="tcbvm-stat-num">' + self.toPersianDigits(data.new_values_count) + '</span><span class="tcbvm-stat-lbl">متغیر جدید (' + data.attr_name + ')</span></div>';
			html += '<div class="tcbvm-stat-box"><span class="tcbvm-stat-num">' + self.toPersianDigits(self.formatNumber(data.price)) + '</span><span class="tcbvm-stat-lbl">قیمت متغیرها (تومان)</span></div>';
			html += '<div class="tcbvm-stat-box"><span class="tcbvm-stat-num">' + self.toPersianDigits(self.formatNumber(data.total_new_vars)) + '</span><span class="tcbvm-stat-lbl">مجموع ترکیب‌های تولیدی</span></div>';
			html += '</div>';

			if (data.samples && data.samples.length) {
				html += '<h4 style="margin: 16px 0 8px; font-size: 14px;">نمونه محصولات جهت بازسازی متغیرها:</h4>';
				html += '<div class="tcbvm-table-scroll"><table class="tisa-table tcbvm-table">';
				html += '<thead><tr><th>شناسه</th><th>نام محصول</th><th>وضعیت فعلی</th><th>تغییرات</th><th>ترکیب ویژگی‌ها</th></tr></thead><tbody>';

				data.samples.forEach(function(s) {
					html += '<tr>';
					html += '<td><span class="tisa-code">#' + s.id + '</span></td>';
					html += '<td><strong>' + s.name + '</strong></td>';
					html += '<td>' + self.toPersianDigits(s.old_vars) + ' متغیر فعلی</td>';
					html += '<td><span class="tcbvm-badge tcbvm-badge--success">' + self.toPersianDigits(s.new_vars) + ' متغیر جدید</span></td>';
					html += '<td><small class="tcbvm-muted">' + s.other_attrs + '</small></td>';
					html += '</tr>';
				});

				html += '</tbody></table></div>';
			}

			$content.html(html);
			$box.removeClass('tcbvm-hidden');
			$('html, body').animate({ scrollTop: $box.offset().top - 40 }, 400);
		},

		/**
		 * اجرای پله‌ای و ایجکس دسته‌ها (Batch Execution Engine)
		 */
		startBatchExecution: function(params) {
			const self = this;
			self.isExecuting = true;

			const $progressWrap = $('#tcbvm-progress-wrap');
			const $bar = $('#tcbvm-bar-fill');
			const $pText = $('#tcbvm-progress-text');
			const $pPercent = $('#tcbvm-progress-percent');
			const $console = $('#tcbvm-log-console');

			$progressWrap.removeClass('tcbvm-hidden');
			$console.empty();
			$bar.css('width', '0%');
			$pPercent.text('0%');
			$pText.text('در حال آغاز نشست و ایجاد اسنپ‌شات بازگردانی…');

			$('#tcbvm-btn-run, #tcbvm-btn-preview').prop('disabled', true);
			$('html, body').animate({ scrollTop: $progressWrap.offset().top - 30 }, 400);

			self.log('آغاز عملیات تغییر و تولید گروهی متغیرها برای ' + params.product_ids.length + ' محصول…', 'info');

			// ۱) ایجاد نشست
			$.ajax({
				url: tcbvmData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'tcbvm_start_run',
					nonce: tcbvmData.nonce,
					product_ids: params.product_ids,
					attr_name: params.attr_name,
					new_values: params.new_values,
					price: params.price,
					sale_price: params.sale_price,
					stock_status: params.stock_status,
					combine_other: params.combine_other
				},
				success: function(resp) {
					if (!resp.success || !resp.data || !resp.data.batches) {
						alert(resp.data && resp.data.message ? resp.data.message : 'خطا در ایجاد نشست.');
						self.isExecuting = false;
						$('#tcbvm-btn-run, #tcbvm-btn-preview').prop('disabled', false);
						return;
					}

					const runId = resp.data.run_id;
					const batches = resp.data.batches;
					const totalProducts = resp.data.total_items;

					$('#tcbvm-stat-total').text(self.toPersianDigits(totalProducts));
					$('#tcbvm-stat-processed').text('۰');
					$('#tcbvm-stat-success').text('۰');
					$('#tcbvm-stat-failed').text('۰');

					self.log('نشست با موفقیت ثبت شد (Run ID: ' + runId + '). پردازش در ' + batches.length + ' بسته آغاز می‌شود.', 'info');

					let currentBatchIndex = 0;
					let processedCount = 0;
					let successCount = 0;
					let failedCount = 0;
					let totalCreated = 0;
					let totalDeleted = 0;
					const allItems = [];

					const runNextBatch = function() {
						if (currentBatchIndex >= batches.length) {
							// پایان تمام بسته‌ها
							self.finishRun(runId, totalCreated, totalDeleted, allItems, function() {
								$bar.css('width', '100%');
								$pPercent.text('۱۰۰٪');
								$pText.text('تولید و بازسازی تمام متغیرها با موفقیت تکمیل و ثبت شد.');
								self.log('پایان تمام بسته‌ها! ' + totalCreated + ' متغیر تازه ساخته و ' + totalDeleted + ' متغیر قدیمی پاکسازی شد.', 'success');
								self.isExecuting = false;
								$('#tcbvm-btn-run, #tcbvm-btn-preview').prop('disabled', false);
								alert(tcbvmData.i18n.completedText);
							});
							return;
						}

						const batchIds = batches[currentBatchIndex];
						const batchNum = currentBatchIndex + 1;
						const batchDesc = batchIds.map(function(id) { return '#' + id; }).join('، ');
						$pText.text('در حال پردازش بسته ' + self.toPersianDigits(batchNum) + ' از ' + self.toPersianDigits(batches.length) + ' (' + batchDesc + ')…');
						self.log('⏳ شروع بسته ' + self.toPersianDigits(batchNum) + ' شامل ' + self.toPersianDigits(batchIds.length) + ' محصول (' + batchDesc + ')…', 'info');

						$.ajax({
							url: tcbvmData.ajaxUrl,
							type: 'POST',
							data: {
								action: 'tcbvm_execute_batch',
								nonce: tcbvmData.nonce,
								run_id: runId,
								batch_ids: batchIds,
								attr_name: params.attr_name,
								new_values: params.new_values,
								price: params.price,
								sale_price: params.sale_price,
								stock_status: params.stock_status,
								combine_other: params.combine_other
							},
							success: function(bResp) {
								if (bResp.success && bResp.data && bResp.data.items) {
									bResp.data.items.forEach(function(it) {
										processedCount++;
										allItems.push(it);
										if (it.status === 'success') {
											successCount++;
											totalCreated += (it.created || 0);
											totalDeleted += (it.deleted || 0);
											self.log('[#' + it.id + '] ' + it.title + ' ➔ ' + it.message, 'success');
										} else {
											failedCount++;
											self.log('[#' + it.id + '] ' + it.title + ' ➔ خطا: ' + it.message, 'error');
										}
									});
								} else {
									batchIds.forEach(function(pid) {
										processedCount++;
										failedCount++;
										const errItem = { id: pid, status: 'error', title: 'محصول #' + pid, message: (bResp.data && bResp.data.message ? bResp.data.message : 'خطا در پردازش بسته') };
										allItems.push(errItem);
										self.log('محصول #' + pid + ': خطا در بسته ' + (bResp.data && bResp.data.message ? bResp.data.message : ''), 'error');
									});
								}

								// به‌روزرسانی نوار پیشرفت و آمار
								const pct = Math.round((processedCount / totalProducts) * 100);
								$bar.css('width', pct + '%');
								$pPercent.text(self.toPersianDigits(pct) + '٪');
								$pText.text('پردازش‌شده: ' + self.toPersianDigits(processedCount) + ' از ' + self.toPersianDigits(totalProducts) + ' محصول (' + self.toPersianDigits(pct) + '٪)');
								$('#tcbvm-stat-processed').text(self.toPersianDigits(processedCount));
								$('#tcbvm-stat-success').text(self.toPersianDigits(successCount));
								$('#tcbvm-stat-failed').text(self.toPersianDigits(failedCount));

								currentBatchIndex++;
								setTimeout(runNextBatch, 50);
							},
							error: function(xhr, status, err) {
								self.log('خطای شبکه در بسته ' + batchNum + ': ' + err + '. تلاش برای ادامه بسته بعدی…', 'error');
								batchIds.forEach(function(pid) {
									processedCount++;
									failedCount++;
									allItems.push({ id: pid, status: 'error', title: 'محصول #' + pid, message: 'خطای شبکه در ارتباط با سرور' });
								});
								currentBatchIndex++;
								setTimeout(runNextBatch, 100);
							}
						});
					};

					runNextBatch();
				},
				error: function() {
					alert('خطا در شروع نشست با سرور.');
					self.isExecuting = false;
					$('#tcbvm-btn-run, #tcbvm-btn-preview').prop('disabled', false);
				}
			});
		},

		/**
		 * اتمام نشست
		 */
		finishRun: function(runId, createdCount, deletedCount, items, callback) {
			$.ajax({
				url: tcbvmData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'tcbvm_finish_run',
					nonce: tcbvmData.nonce,
					run_id: runId,
					status: 'completed',
					created_count: createdCount,
					deleted_count: deletedCount,
					items: items
				},
				complete: function() {
					if (typeof callback === 'function') callback();
				}
			});
		},

		/**
		 * مدیریت ذخیره و حذف الگوهای سفارشی
		 */
		bindPresets: function() {
			$('#tcbvm-form-new-preset').on('submit', function(e) {
				e.preventDefault();
				const name = $('#preset_name').val().trim();
				const desc = $('#preset_desc').val().trim();
				const models = $('#preset_models').val();

				if (!name || !models.trim()) {
					alert('نام الگو و حداقل یک مدل الزامی است.');
					return;
				}

				$.ajax({
					url: tcbvmData.ajaxUrl,
					type: 'POST',
					data: {
						action: 'tcbvm_save_preset',
						nonce: tcbvmData.nonce,
						name: name,
						description: desc,
						models: models
					},
					success: function(resp) {
						if (resp.success) {
							alert('الگو با موفقیت ذخیره شد.');
							window.location.reload();
						} else {
							alert(resp.data && resp.data.message ? resp.data.message : 'خطا در ذخیره الگو.');
						}
					}
				});
			});

			$('.tc-btn-delete-preset').on('click', function(e) {
				e.preventDefault();
				if (!confirm(tcbvmData.i18n.confirmDeletePreset)) return;

				const pId = $(this).data('preset-id');
				$.ajax({
					url: tcbvmData.ajaxUrl,
					type: 'POST',
					data: {
						action: 'tcbvm_delete_preset',
						nonce: tcbvmData.nonce,
						id: pId
					},
					success: function(resp) {
						if (resp.success) {
							window.location.reload();
						} else {
							alert(resp.data && resp.data.message ? resp.data.message : 'خطا در حذف الگو.');
						}
					}
				});
			});
		},

		/**
		 * بازگردانی (Rollback) و مشاهده جزئیات لاگ
		 */
		bindRollback: function() {
			// باز کردن / بستن کشوی جزئیات گزارش
			$(document).on('click', '.tc-btn-toggle-run-details', function(e) {
				e.preventDefault();
				const runId = $(this).data('run-id');
				$('#run-details-' + runId).toggleClass('tcbvm-hidden');
			});

			$('.tc-btn-rollback').on('click', function(e) {
				e.preventDefault();
				if (!confirm(tcbvmData.i18n.confirmRollback)) return;

				const $btn = $(this);
				const runId = $btn.data('run-id');
				$btn.prop('disabled', true).text('در حال بازگردانی…');

				$.ajax({
					url: tcbvmData.ajaxUrl,
					type: 'POST',
					data: {
						action: 'tcbvm_rollback',
						nonce: tcbvmData.nonce,
						run_id: runId
					},
					success: function(resp) {
						if (resp.success) {
							alert('عملیات با موفقیت بازگردانده شد و وضعیت متغیرها به حالت قبل برگشت.');
							window.location.reload();
						} else {
							alert(resp.data && resp.data.message ? resp.data.message : 'خطا در بازگردانی.');
							$btn.prop('disabled', false).text('بازگردانی (Rollback)');
						}
					},
					error: function() {
						alert('خطا در ارتباط با سرور.');
						$btn.prop('disabled', false).text('بازگردانی (Rollback)');
					}
				});
			});
		},

		/**
		 * پاکسازی ترنزینت‌ها و کش قیمت ووکامرس
		 */
		bindCacheFlush: function() {
			$('#tcbvm-btn-flush-cache').on('click', function(e) {
				e.preventDefault();
				const $btn = $(this);
				$btn.prop('disabled', true).text('در حال نوسازی…');

				$.ajax({
					url: tcbvmData.ajaxUrl,
					type: 'POST',
					data: {
						action: 'tcbvm_flush_cache',
						nonce: tcbvmData.nonce
					},
					success: function(resp) {
						$btn.prop('disabled', false).text('نوسازی کش قیمت‌های متغیر ووکامرس');
						alert(resp.data && resp.data.message ? resp.data.message : 'کش نوسازی شد.');
					},
					error: function() {
						$btn.prop('disabled', false).text('نوسازی کش قیمت‌های متغیر ووکامرس');
						alert('خطا در نوسازی کش.');
					}
				});
			});
		}
	};

	$(document).ready(function() {
		TCBVM.init();
	});

})(jQuery);
