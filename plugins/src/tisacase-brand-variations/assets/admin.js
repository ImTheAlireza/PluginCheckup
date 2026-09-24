/*!
 * TisaCase — گروه‌بندی متغیرها بر اساس برند | اسکریپت پنل مدیریت
 * مدیریت ردیف‌های برند، تحلیلگر مدل‌ها و پیش‌نمایش زندهٔ پنل سمت کاربر.
 */
(function (window, document) {
	'use strict';

	var A = window.TCBV_ADMIN || { strings: {} };
	var S = A.strings || {};

	var form = document.querySelector('.tcbv-form');
	if (!form) { return; }

	var brandsBox = document.getElementById('tcbv-brands');
	var tpl = document.getElementById('tcbv-brand-template');
	var preview = document.getElementById('tcbv-preview');

	/* مدل‌های نمونه (همان ساختار واقعی فروشگاه) برای پیش‌نمایش */
	var SAMPLE_MODELS = ('iPhone 6|iPhone 6s|iPhone 6 Plus|iPhone 6s Plus|iPhone 7|iPhone 8|iPhone SE|iPhone SE 2|iPhone 7 Plus|iPhone 8 Plus|iPhone X|iPhone Xs|iPhone Xs Max|iPhone 11|iPhone 11 Pro|iPhone 11 Pro Max|iPhone 12|iPhone 13|iPhone 14|iPhone 12 mini|iPhone 12 Pro|iPhone 13 Pro|iPhone 14 Pro|iPhone 12 Pro Max|iPhone 13 Pro Max|iPhone 14 Pro Max|iPhone 15|iPhone 15 Pro|iPhone 15 Pro Max|iPhone 16|iPhone 16 Pro|iPhone 16 Pro Max|iPhone 17|iPhone 17 Pro|iPhone 17 Pro Max|A03s|A04s|A05s|A06|A07|A10|A10s|A11|A12|A13 4G|A13 5G|A14|A15|A16|A17|A20|A20s|A21s|A22 4G|A22 5G|A23 4G|A24|A25|A26|A30|A30s|A31|A32 4G|A32 5G|A33|A34|A35|A36|A37|A50|A51|A52|A52s|A53|A54|A55|A56|A57|A70|A71|A72|A73|S20 FE|S21|S21 FE|S21 Ultra|S22 Ultra|S23|S23 FE|S23 Ultra|S24|S24 FE|S24 Ultra|S25|S25 FE|S25 Ultra|Redmi Note 8|Redmi Note 8 Pro|Redmi Note 9|Redmi Note 9S|Redmi Note 9 Pro|Redmi Note 10 4G|Redmi Note 10S|Redmi Note 10 Pro|Redmi Note 10 Pro Max|Redmi Note 11 4G|Redmi Note 11S|Redmi Note 11 Pro 4G|Redmi Note 12 4G|Redmi Note 12 Pro 4G|Redmi Note 12 Pro 5G|Redmi Note 13 4G|Redmi Note 13 Pro 4G|Redmi Note 13 Pro 5G|Redmi Note 13 Pro Plus 5G|Redmi Note 14 4G|Redmi Note 14 Pro 4G|Redmi Note 14 Pro Plus 5G|Redmi Note 15 4G|Redmi Note 15 Pro 4G|Redmi A5|Redmi 10C|Redmi 12|Redmi 12C|Redmi 13|Redmi 13C|Redmi 14C|Redmi 15|Redmi 15C|Mi 11T|Mi 11T Pro|Mi 12T|Mi 12T Pro|Mi 13T|Mi 13T Pro|Mi 11 Lite|Mi 12 Lite|Mi 13 Lite|Poco X3|Poco X3 GT|Poco X3 Pro|Poco X4 Pro|Poco X6|Poco X6 Pro|Poco X7|Poco M3|Poco F6|Poco F6 Pro').split('|');
	var SAMPLE_COLORS = ['صورتی', 'آبی', 'مشکی', 'سفید'];

	/* ============================================================
	   ابزارهای کوچک
	   ============================================================ */

	function qsa(sel, root) { return [].slice.call((root || document).querySelectorAll(sel)); }
	function splitLines(text) {
		return String(text || '').split('\n').map(function (l) { return l.trim(); }).filter(Boolean);
	}
	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (text !== undefined) { n.textContent = text; }
		return n;
	}
	function field(name) {
		var node = form.elements[name];
		if (!node) { return null; }
		if (node.length && !node.tagName) { return node[0]; }
		return node;
	}
	function val(name, fallback) {
		var node = field(name);
		return node ? node.value : (fallback === undefined ? '' : fallback);
	}
	function checked(name) {
		var node = field(name);
		return node && node.checked ? 1 : 0;
	}
	function debounce(fn, wait) {
		var t = null;
		return function () {
			var args = arguments, self = this;
			clearTimeout(t);
			t = setTimeout(function () { fn.apply(self, args); }, wait);
		};
	}
	function slug(text) {
		return String(text || '').trim().toLowerCase().replace(/[^a-z0-9\u0600-\u06FF]+/g, '-').replace(/^-+|-+$/g, '') || 'brand';
	}

	/* ============================================================
	   ۱) ردیف‌های برند
	   ============================================================ */

	function renumber() {
		if (!brandsBox) { return; }
		qsa('.tcbv-brand', brandsBox).forEach(function (row, index) {
			row.setAttribute('data-index', index);
			qsa('[name]', row).forEach(function (input) {
				input.name = input.name.replace(/tcbv\[brands\]\[[^\]]+\]/, 'tcbv[brands][' + index + ']');
			});
		});
	}

	function addRow(data) {
		if (!tpl || !brandsBox) { return null; }
		data = data || {};
		var html = tpl.innerHTML.replace(/__INDEX__/g, String(qsa('.tcbv-brand', brandsBox).length));
		var holder = document.createElement('div');
		holder.innerHTML = html.trim();
		var row = holder.firstElementChild;

		if (data.label) { setField(row, 'label', data.label); }
		if (data.color) { setField(row, 'color', data.color); }
		if (data.keywords) { setField(row, 'keywords', data.keywords); }
		if (data.regex) { setField(row, 'regex', data.regex); }
		if (data.exact) { setField(row, 'exact', data.exact); }
		if (data.icon) { setField(row, 'icon', data.icon); }

		brandsBox.appendChild(row);
		renumber();
		schedulePreview();
		return row;
	}

	function setField(row, name, value) {
		var node = row.querySelector('[data-field="' + name + '"]');
		if (node) { node.value = value; }
	}

	if (brandsBox) {
		brandsBox.addEventListener('click', function (event) {
			var target = event.target;
			if (target.classList.contains('tcbv-del')) {
				if (!window.confirm(S.confirmDel || 'حذف شود؟')) { return; }
				target.closest('.tcbv-brand').parentNode.removeChild(target.closest('.tcbv-brand'));
				renumber();
				schedulePreview();
			} else if (target.classList.contains('tcbv-up')) {
				var row = target.closest('.tcbv-brand');
				if (row.previousElementSibling) {
					row.parentNode.insertBefore(row, row.previousElementSibling);
					renumber();
					schedulePreview();
				}
			} else if (target.classList.contains('tcbv-down')) {
				var row2 = target.closest('.tcbv-brand');
				if (row2.nextElementSibling) {
					row2.parentNode.insertBefore(row2.nextElementSibling, row2);
					renumber();
					schedulePreview();
				}
			}
		});
	}

	var addBtn = document.getElementById('tcbv-add-brand');
	if (addBtn) {
		addBtn.addEventListener('click', function () { addRow({ label: 'برند جدید', color: '#94A3B8' }); });
	}

	var presetBtn = document.getElementById('tcbv-add-preset');
	if (presetBtn) {
		presetBtn.addEventListener('click', function () {
			var select = document.getElementById('tcbv-preset');
			var id = select ? select.value : '';
			if (!id) {
				addRow({ label: '', color: '#94A3B8' });
				return;
			}
			if ('custom' === id) {
				addRow({ label: '', color: '#94A3B8' });
			} else if (A.presets && A.presets[id]) {
				addRow(A.presets[id]);
			}
			select.value = '';
		});
	}

	/* ============================================================
	   ۲) نقشهٔ رنگ
	   ============================================================ */

	var colorMap = document.getElementById('tcbv-color-map');

	function addColors() {
		if (!colorMap) { return; }
		var existing = {};
		splitLines(colorMap.value).forEach(function (line) {
			var parts = line.split(':');
			if (parts[0]) { existing[parts[0].trim()] = true; }
		});
		var added = 0;
		String(A.colors || '').split('\n').forEach(function (line) {
			line = line.trim();
			if (!line) { return; }
			var name = line.split(':')[0].trim();
			if (name && !existing[name]) { colorMap.value = colorMap.value.replace(/\s*$/, '') + '\n' + line; existing[name] = true; added++; }
		});
		if (added) { schedulePreview(); }
	}

	var addColorsBtn = document.getElementById('tcbv-add-colors');
	if (addColorsBtn) { addColorsBtn.addEventListener('click', addColors); }

	var resetColorsBtn = document.getElementById('tcbv-reset-colors');
	if (resetColorsBtn) {
		resetColorsBtn.addEventListener('click', function () {
			if (colorMap) { colorMap.value = A.colors || ''; schedulePreview(); }
		});
	}

	/* ============================================================
	   ۳) ساخت پیکربندی از فرم (برای پیش‌نمایش و تحلیل)
	   ============================================================ */

	function brandsFromForm() {
		var out = [];
		if (!brandsBox) { return out; }
		qsa('.tcbv-brand', brandsBox).forEach(function (row) {
			var labelNode = row.querySelector('[data-field="label"]');
			if (!labelNode) { return; }
			var label = (labelNode.value || '').trim();
			if (!label) { return; }
			var id = (row.querySelector('[data-field="id"]').value || '').trim() || slug(label);
			out.push({
				id: id,
				label: label,
				color: row.querySelector('[data-field="color"]').value,
				icon: (row.querySelector('[data-field="icon"]').value || '').trim(),
				enabled: row.querySelector('[data-field="enabled"]').checked ? 1 : 0,
				keywords: splitLines(row.querySelector('[data-field="keywords"]').value),
				regex: splitLines(row.querySelector('[data-field="regex"]').value),
				exact: splitLines(row.querySelector('[data-field="exact"]').value)
			});
		});
		return out;
	}

	function colorMapArray() {
		var out = [];
		splitLines(colorMap ? colorMap.value : '').forEach(function (line) {
			var idx = line.indexOf(':');
			if (idx === -1) { return; }
			var name = line.slice(0, idx).trim();
			var hex = line.slice(idx + 1).trim();
			if (name && hex) { out.push([name, hex]); }
		});
		return out;
	}

	function cfgFromForm() {
		var theme = {
			accent: val('tcbv[theme][accent]'),
			bg: val('tcbv[theme][bg]'),
			bg_alt: val('tcbv[theme][bg_alt]'),
			border: val('tcbv[theme][border]'),
			text: val('tcbv[theme][text]'),
			muted: val('tcbv[theme][muted]'),
			sep: val('tcbv[theme][sep]'),
			hover: val('tcbv[theme][hover]'),
			sel_bg: val('tcbv[theme][sel_bg]'),
			sel_text: val('tcbv[theme][sel_text]'),
			radius: parseInt(val('tcbv[theme][radius]', '14'), 10),
			font: parseInt(val('tcbv[theme][font]', '14'), 10),
			item_pad: parseInt(val('tcbv[theme][item_pad]', '9'), 10)
		};
		// کلیدها دقیقاً همان شکل خروجی TCBV_Rules::js_config() هستند (snake_case)
		// تا پیش‌نمایش پنل عیناً مثل فرانت‌اند واقعی رندر شود.
		var ui = {
			mode: val('tcbv[ui][mode]', 'panel'),
			layout: val('tcbv[ui][layout]', 'chips'),
			separator: val('tcbv[ui][separator]', 'line'),
			group_style: val('tcbv[ui][group_style]', 'header'),
			sort: val('tcbv[ui][sort]', 'asis'),
			search: checked('tcbv[ui][search]'),
			search_placeholder: val('tcbv[ui][search_placeholder]', 'جستجو…'),
			counts: checked('tcbv[ui][counts]'),
			sticky: checked('tcbv[ui][sticky]'),
			chips: checked('tcbv[ui][chips]'),
			max_height: parseInt(val('tcbv[ui][max_height]', '320'), 10),
			colors_on_items: checked('tcbv[ui][colors_on_items]'),
			show_label: checked('tcbv[ui][show_label]'),
			highlight: checked('tcbv[ui][highlight]'),
			oos: parseInt(val('tcbv[ui][oos]', '0'), 10),
			fa_digits: checked('tcbv[ui][fa_digits]')
		};
		var swatch = {
			enabled: checked('tcbv[swatch][enabled]'),
			shape: val('tcbv[swatch][shape]', 'circle'),
			size: parseInt(val('tcbv[swatch][size]', '28'), 10),
			showLabel: checked('tcbv[swatch][show_label]'),
			fallback: val('tcbv[swatch][fallback]', '#CBD5E1')
		};
		var pos = val('tcbv[unknown][position]', 'last');

		return {
			brands: brandsFromForm(),
			unknown: {
				label: val('tcbv[unknown][label]', 'سایر مدل‌ها'),
				color: val('tcbv[unknown][color]', '#94A3B8'),
				enabled: checked('tcbv[unknown][enabled]'),
				position: pos
			},
			groupScope: val('tcbv[group_scope]', 'auto'),
			groupAttrs: splitLines(val('tcbv[group_attrs]')),
			skipAttrs: splitLines(val('tcbv[skip_attrs]')),
			swatchAttrs: splitLines(val('tcbv[swatch_attrs]')),
			minBrands: parseInt(val('tcbv[min_brands]', '2'), 10),
			minOptions: parseInt(val('tcbv[min_options]', '6'), 10),
			ui: ui,
			theme: theme,
			swatch: swatch,
			colorMap: colorMapArray(),
			debug: checked('tcbv[advanced][debug]'),
			i18n: {
				search: ui.search_placeholder,
				all: 'همه',
				noResult: 'چیزی پیدا نشد',
				clear: 'پاک کردن جستجو',
				models: 'مدل',
				outOfStock: 'ناموجود',
				selected: 'انتخاب‌شده'
			}
		};
	}

	/* ============================================================
	   ۴) پیش‌نمایش زنده
	   ============================================================ */

	var previewState = { models: SAMPLE_MODELS.slice(0, 80), colors: SAMPLE_COLORS.slice() };

	function applyTheme(cfg, root) {
		var t = cfg.theme || {};
		var map = {
			'--tcbv-accent': t.accent,
			'--tcbv-bg': t.bg,
			'--tcbv-bg-alt': t.bg_alt,
			'--tcbv-border': t.border,
			'--tcbv-text': t.text,
			'--tcbv-muted': t.muted,
			'--tcbv-sep': t.sep,
			'--tcbv-hover': t.hover,
			'--tcbv-sel-bg': t.sel_bg,
			'--tcbv-sel-text': t.sel_text,
			'--tcbv-radius': (t.radius || 14) + 'px',
			'--tcbv-fs': (t.font || 14) + 'px',
			'--tcbv-pad': (t.item_pad || 9) + 'px',
			'--tcbv-sw-size': ((cfg.swatch && cfg.swatch.size) || 28) + 'px'
		};
		Object.keys(map).forEach(function (key) {
			if (map[key]) { root.style.setProperty(key, map[key]); }
		});
	}

	function previewMarkup(cfg) {
		var models = previewState.models;
		var colors = previewState.colors;
		var options = models.map(function (m) { return '<option value="' + esc(m) + '">' + esc(m) + '</option>'; }).join('');
		var colorOptions = colors.map(function (c) { return '<option value="' + esc(c) + '">' + esc(c) + '</option>'; }).join('');

		return '' +
			'<form class="variations_form tcbv-preview-form" data-product_variations="">' +
				'<div class="tcbv-preview-field">' +
					'<label class="tcbv-preview-label" for="tcbv-pv-model">مدل</label>' +
					'<select id="tcbv-pv-model" name="attribute_\u0645\u062f\u0644" data-attribute_name="attribute_\u0645\u062f\u0644">' +
						'<option value="">یک گزینه را انتخاب کنید</option>' + options +
					'</select>' +
				'</div>' +
				'<div class="tcbv-preview-field">' +
					'<label class="tcbv-preview-label" for="tcbv-pv-color">رنگ</label>' +
					'<select id="tcbv-pv-color" name="attribute_\u0631\u0646\u06af" data-attribute_name="attribute_\u0631\u0646\u06af">' +
						'<option value="">یک گزینه را انتخاب کنید</option>' + colorOptions +
					'</select>' +
				'</div>' +
				'<div class="tcbv-preview-note">' + fa(models.length) + ' مدل · ' + fa(colors.length) + ' رنگ — این پیش‌نمایش با همان CSS/JS صفحهٔ محصول ساخته می‌شود.</div>' +
			'</form>';
	}

	function esc(text) {
		return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}
	function fa(num) {
		return String(num).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.charAt(+d); });
	}

	var renderPreview = function () {
		if (!preview || !window.TCBV) { return; }
		var cfg = cfgFromForm();
		preview.style.setProperty('--tcbv-max-h', (cfg.ui.max_height || 320) + 'px');
		applyTheme(cfg, preview);
		preview.innerHTML = previewMarkup(cfg);
		window.TCBV.config(cfg);
		window.TCBV.rebuild(preview);
	};
	renderPreview();

	var schedulePreview = debounce(renderPreview, 160);

	form.addEventListener('input', function (event) {
		if (event.target.closest('#tcbv-test-input')) { return; }
		schedulePreview();
	});
	form.addEventListener('change', function (event) {
		if (event.target.closest('#tcbv-test-input')) { return; }
		schedulePreview();
	});
	form.addEventListener('click', function (event) {
		if (event.target.closest('.tcbv-up, .tcbv-down, .tcbv-del')) { schedulePreview(); }
	});

	/* ============================================================
	   ۵) تحلیلگر
	   ============================================================ */

	var testInput = document.getElementById('tcbv-test-input');
	var out = document.getElementById('tcbv-analyse-out');

	function post(action, data, pairs) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', A.nonce);
		Object.keys(data || {}).forEach(function (key) { body.append(key, data[key]); });
		(pairs || []).forEach(function (pair) { body.append(pair[0], pair[1]); });
		return window.fetch(A.ajax, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (!json || !json.success) { throw new Error((json && json.data && json.data.message) || S.error); }
				return json.data;
			});
	}

	function brandPairs(rows) {
		var pairs = [];
		rows.forEach(function (b, i) {
			pairs.push(['rules[brands][' + i + '][id]', b.id]);
			pairs.push(['rules[brands][' + i + '][label]', b.label]);
			pairs.push(['rules[brands][' + i + '][color]', b.color]);
			pairs.push(['rules[brands][' + i + '][icon]', b.icon]);
			pairs.push(['rules[brands][' + i + '][enabled]', b.enabled ? '1' : '0']);
			pairs.push(['rules[brands][' + i + '][keywords]', b.keywords.join('\n')]);
			pairs.push(['rules[brands][' + i + '][regex]', b.regex.join('\n')]);
			pairs.push(['rules[brands][' + i + '][exact]', b.exact.join('\n')]);
		});
		return pairs;
	}

	function analyse() {
		if (!testInput || !out) { return; }
		var values = testInput.value.trim();
		if (!values) { window.alert('اول یک لیست مدل بگذارید (یا از محصول بخوانید).'); return; }

		out.hidden = false;
		out.innerHTML = '<p class="tcbv-muted">' + (S.analysing || '…') + '</p>';

		var rows = brandsFromForm();
		var brandNames = rows.map(function (b) { return { id: b.id, label: b.label, color: b.color }; });

		post('tcbv_classify', {
			values: values,
			'rules[group_scope]': val('tcbv[group_scope]', 'auto'),
			'rules[group_attrs]': val('tcbv[group_attrs]'),
			'rules[skip_attrs]': val('tcbv[skip_attrs]'),
			'rules[swatch_attrs]': val('tcbv[swatch_attrs]'),
			'rules[unknown][label]': val('tcbv[unknown][label]'),
			'rules[unknown][color]': val('tcbv[unknown][color]'),
			'rules[unknown][position]': val('tcbv[unknown][position]', 'last'),
			'rules[ui][sort]': val('tcbv[ui][sort]', 'asis')
		}, brandPairs(rows)).then(function (data) {
			var html = '';
			html += '<div class="tcbv-analyse-sum">' + fa(data.total) + ' مدل تحلیل شد — ' +
				fa(data.groups.filter(function (g) { return !g.unknown; }).length) + ' برند شناسایی شد' +
				(data.conflicts.length ? ' · ' + fa(data.conflicts.length) + ' تضاد' : '') + '</div>';

			data.groups.forEach(function (group) {
				html += '<div class="tcbv-analyse-group' + (group.unknown ? ' is-unknown' : '') + '" style="--tcbv-c:' + esc(group.color) + '">';
				html += '<header><span class="tcbv-dot"></span><strong>' + esc(group.label) + '</strong><span class="tcbv-badge">' + fa(group.count) + ' مدل</span>';
				if (group.unknown) {
					html += '<span class="tcbv-assign">' +
						'<select class="tcbv-assign-brand">' + brandNames.map(function (b) { return '<option value="' + esc(b.id) + '">' + esc(b.label) + '</option>'; }).join('') + '</select>' +
						'<button type="button" class="button tcbv-assign-btn">افزودن همه به فهرست دستی</button></span>';
				}
				html += '</header>';
				html += '<div class="tcbv-tags">' + group.values.map(function (value) {
					return '<span class="tcbv-tag" data-value="' + esc(value) + '">' + esc(value) +
						(group.unknown ? '<button type="button" class="tcbv-tag-add" title="افزودن به برند انتخاب‌شده">+</button>' : '') +
						'</span>';
				}).join('') + '</div>';
				html += '</div>';
			});

			if (data.conflicts.length) {
				html += '<details class="tcbv-conflicts"><summary>' + (S.conflict || 'تضاد') + ': ' + fa(data.conflicts.length) + ' مدل با بیش از یک برند می‌خواند</summary><ul>';
				data.conflicts.forEach(function (row) {
					html += '<li><code dir="ltr">' + esc(row.value) + '</code> → ' + esc(row.hits.join(' ، ')) + ' <em>(برنده: ' + esc(row.used) + ')</em></li>';
				});
				html += '</ul></details>';
			}

			out.innerHTML = html;
		}).catch(function (error) {
			out.innerHTML = '<p class="tcbv-error">' + esc(error.message || S.error) + '</p>';
		});
	}

	var analyseBtn = document.getElementById('tcbv-analyse');
	if (analyseBtn) { analyseBtn.addEventListener('click', analyse); }

	var clearBtn = document.getElementById('tcbv-clear-list');
	if (clearBtn) {
		clearBtn.addEventListener('click', function () {
			if (testInput) { testInput.value = ''; }
			if (out) { out.hidden = true; out.innerHTML = ''; }
		});
	}

	/* افزودن مدل به «فهرست دستی» برند (بدون رفت‌وبرگشت سرور) */
	if (out) {
		out.addEventListener('click', function (event) {
			var btn = event.target.closest('.tcbv-tag-add');
			var allBtn = event.target.closest('.tcbv-assign-btn');
			if (!btn && !allBtn) { return; }

			var groupNode = event.target.closest('.tcbv-analyse-group');
			var select = groupNode.querySelector('.tcbv-assign-brand');
			var brandId = select ? select.value : '';
			var row = qsa('.tcbv-brand', brandsBox).filter(function (r) {
				var id = (r.querySelector('[data-field="id"]').value || slug(r.querySelector('[data-field="label"]').value)).trim();
				return id === brandId;
			})[0];
			if (!row) { return; }

			var area = row.querySelector('[data-field="exact"]');
			var current = splitLines(area.value);

			if (allBtn) {
				qsa('.tcbv-tag', groupNode).forEach(function (tag) {
					var value = tag.getAttribute('data-value');
					if (current.indexOf(value) === -1) { current.push(value); }
				});
			} else {
				var single = btn.parentNode.getAttribute('data-value');
				if (current.indexOf(single) === -1) { current.push(single); }
			}

			area.value = current.join('\n');
			area.classList.add('is-flash');
			setTimeout(function () { area.classList.remove('is-flash'); }, 900);
			schedulePreview();
			analyse();
		});
	}

	/* ============================================================
	   ۶) خواندن مدل‌های واقعی محصول
	   ============================================================ */

	var pickerBox = document.getElementById('tcbv-product-picker');
	var resultsBox = document.getElementById('tcbv-analyse-products');
	var searchInput = document.getElementById('tcbv-analyse-search');

	var loadBtn = document.getElementById('tcbv-load-product');
	if (loadBtn) {
		loadBtn.addEventListener('click', function () {
			if (pickerBox) { pickerBox.hidden = !pickerBox.hidden; }
			if (pickerBox && !pickerBox.hidden && searchInput) { searchInput.focus(); }
		});
	}

	function searchProducts() {
		if (!searchInput || !resultsBox) { return; }
		resultsBox.hidden = false;
		resultsBox.innerHTML = '<p class="tcbv-muted">' + (S.loading || '…') + '</p>';
		post('tcbv_products', { term: searchInput.value }).then(function (data) {
			if (!data.products.length) {
				resultsBox.innerHTML = '<p class="tcbv-muted">' + (S.noProduct || '—') + '</p>';
				return;
			}
			resultsBox.innerHTML = data.products.map(function (p) {
				return '<button type="button" class="tcbv-product" data-id="' + p.id + '"><strong>' + esc(p.title) + '</strong><small>#' + p.id + '</small></button>';
			}).join('');
		}).catch(function (error) {
			resultsBox.innerHTML = '<p class="tcbv-error">' + esc(error.message || S.error) + '</p>';
		});
	}

	var searchBtn = document.getElementById('tcbv-analyse-search-btn');
	if (searchBtn) { searchBtn.addEventListener('click', searchProducts); }
	if (searchInput) {
		searchInput.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') { event.preventDefault(); searchProducts(); }
		});
	}

	var previewResults = document.getElementById('tcbv-product-results');
	var previewPicker = document.getElementById('tcbv-preview-picker');
	var previewSearch = document.getElementById('tcbv-product-search');

	var realToggle = document.getElementById('tcbv-preview-real');
	if (realToggle && previewPicker) {
		realToggle.addEventListener('change', function () {
			previewPicker.hidden = !realToggle.checked;
			if (previewResults) { previewResults.hidden = !realToggle.checked; }
		});
	}

	var previewSearchBtn = document.getElementById('tcbv-product-search-btn');
	if (previewSearchBtn) {
		previewSearchBtn.addEventListener('click', function () {
			if (!previewSearch || !previewResults) { return; }
			previewResults.hidden = false;
			previewResults.innerHTML = '<p class="tcbv-muted">' + (S.loading || '…') + '</p>';
			post('tcbv_products', { term: previewSearch.value }).then(function (data) {
				previewResults.innerHTML = data.products.length
					? data.products.map(function (p) {
						return '<button type="button" class="tcbv-product" data-id="' + p.id + '"><strong>' + esc(p.title) + '</strong><small>#' + p.id + '</small></button>';
					}).join('')
					: '<p class="tcbv-muted">' + (S.noProduct || '—') + '</p>';
			}).catch(function (error) {
				previewResults.innerHTML = '<p class="tcbv-error">' + esc(error.message || S.error) + '</p>';
			});
		});
	}

	function useProduct(id, target) {
		post('tcbv_product_attrs', { product_id: id }).then(function (data) {
			var modelAttr = null, colorAttr = null;
			data.attributes.forEach(function (attr) {
				if (attr.isColor && !colorAttr) { colorAttr = attr; }
				if (!attr.isColor && (!modelAttr || attr.count > modelAttr.count)) { modelAttr = attr; }
			});

			if (target === 'analyse') {
				if (!testInput || !modelAttr) { window.alert('ویژگی مناسبی در این محصول پیدا نشد.'); return; }
				testInput.value = modelAttr.values.join('\n');
				analyse();
			} else {
				if (modelAttr) { previewState.models = modelAttr.values.slice(0, 300); }
				if (colorAttr) { previewState.colors = colorAttr.values.slice(); }
				renderPreview();
			}
		}).catch(function (error) {
			window.alert(error.message || S.error);
		});
	}

	if (resultsBox) {
		resultsBox.addEventListener('click', function (event) {
			var btn = event.target.closest('.tcbv-product');
			if (btn) { useProduct(btn.getAttribute('data-id'), 'analyse'); }
		});
	}
	if (previewResults) {
		previewResults.addEventListener('click', function (event) {
			var btn = event.target.closest('.tcbv-product');
			if (btn) { useProduct(btn.getAttribute('data-id'), 'preview'); }
		});
	}

	/* ============================================================
	   ۷) کارت «حالت تست» — انتخاب محصول‌های آزمایشی
	   ============================================================ */

	var testCard     = document.getElementById('tcbv-test-card');
	var testToggle   = document.getElementById('tcbv-test-enabled');
	var testChips    = document.getElementById('tcbv-test-chips');
	var testEmpty    = document.getElementById('tcbv-test-empty');
	var testResults  = document.getElementById('tcbv-test-results');
	var testSearch   = document.getElementById('tcbv-test-search');
	var testStatus   = document.getElementById('tcbv-test-status');
	var heroPill     = document.getElementById('tcbv-hero-pill');

	function testCount() {
		return testChips ? testChips.querySelectorAll('.tcbv-test-chip').length : 0;
	}

	function testState() {
		var on = !!(testToggle && testToggle.checked);
		var n = testCount();
		if (testStatus) {
			testStatus.classList.toggle('is-off', !on);
			testStatus.classList.toggle('is-empty', on && !n);
			testStatus.textContent = !on
				? 'خاموش — روی همهٔ محصولات متغیر'
				: (n ? 'روشن — فقط ' + fa(n) + ' محصول زیر' : 'روشن — بدون محصول (بی‌اثر)');
		}
		if (testCard) { testCard.classList.toggle('is-on', on); }
		if (heroPill) {
			heroPill.classList.toggle('is-test', on);
			heroPill.textContent = !on
				? 'همهٔ محصولات متغیر'
				: (n ? 'حالت تست · ' + fa(n) + ' محصول' : 'حالت تست · بدون محصول');
		}
		if (testEmpty) {
			testEmpty.textContent = n
				? 'برای حذف یک محصول، روی × همان چیپ بزنید.'
				: 'هنوز محصولی انتخاب نشده — در این وضعیت افزونه روی هیچ محصولی اجرا نمی‌شود.';
		}
	}

	function testChipNode(id, title, link) {
		var chip = el('span', 'tcbv-test-chip');
		chip.setAttribute('data-id', String(id));

		var hidden = document.createElement('input');
		hidden.type = 'hidden';
		hidden.name = 'tcbv[test][products][]';
		hidden.value = String(id);
		chip.appendChild(hidden);

		var label = el('span', 'tcbv-test-chip-t', title || ('محصول #' + id));
		var em = el('em', '', '#' + id);
		em.setAttribute('dir', 'ltr');
		label.appendChild(em);
		chip.appendChild(label);

		if (link) {
			var a = el('a', 'tcbv-test-link', 'مشاهده');
			a.href = link;
			a.target = '_blank';
			a.setAttribute('rel', 'noopener noreferrer');
			chip.appendChild(a);
		}

		var del = el('button', 'tcbv-test-del', '×');
		del.type = 'button';
		del.setAttribute('aria-label', 'حذف محصول');
		chip.appendChild(del);

		return chip;
	}

	function testAddProduct(id, title, link) {
		if (!testChips) { return; }
		var exists = testChips.querySelector('.tcbv-test-chip[data-id="' + id + '"]');
		if (exists) {
			exists.classList.add('is-flash');
			window.setTimeout(function () { exists.classList.remove('is-flash'); }, 700);
			return;
		}
		if (testCount() >= 20) { window.alert('حداکثر ۲۰ محصول می‌توانید انتخاب کنید.'); return; }
		testChips.appendChild(testChipNode(id, title, link));
		testState();
	}

	if (testToggle) {
		testToggle.addEventListener('change', testState);
	}

	if (testChips) {
		testChips.addEventListener('click', function (event) {
			var del = event.target.closest('.tcbv-test-del');
			if (!del) { return; }
			var chip = del.closest('.tcbv-test-chip');
			if (chip) { chip.parentNode.removeChild(chip); testState(); }
		});
	}

	function testSearchProducts() {
		if (!testResults || !testSearch) { return; }
		testResults.hidden = false;
		testResults.innerHTML = '<p class="tcbv-muted">' + (S.loading || '…') + '</p>';
		post('tcbv_products', { term: testSearch.value }).then(function (data) {
			testResults.innerHTML = data.products.length
				? data.products.map(function (p) {
					return '<button type="button" class="tcbv-product" data-id="' + p.id + '" data-title="' + esc(p.title) + '" data-link="' + esc(p.link || '') + '">' +
						'<strong>' + esc(p.title) + '</strong><small>#' + p.id + ' · افزودن به لیست تست</small></button>';
				}).join('')
				: '<p class="tcbv-muted">' + (S.noProduct || '—') + '</p>';
		}).catch(function (error) {
			testResults.innerHTML = '<p class="tcbv-error">' + esc(error.message || S.error) + '</p>';
		});
	}

	var testSearchBtn = document.getElementById('tcbv-test-search-btn');
	if (testSearchBtn) { testSearchBtn.addEventListener('click', testSearchProducts); }
	if (testSearch) {
		testSearch.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') { event.preventDefault(); testSearchProducts(); }
		});
	}
	if (testResults) {
		testResults.addEventListener('click', function (event) {
			var btn = event.target.closest('.tcbv-product');
			if (!btn) { return; }
			testAddProduct(btn.getAttribute('data-id'), btn.getAttribute('data-title'), btn.getAttribute('data-link'));
			btn.classList.add('is-added');
		});
	}

	var testAll = document.getElementById('tcbv-test-all');
	if (testAll) {
		testAll.addEventListener('click', function () {
			if (!window.confirm('حالت تست خاموش شود و افزونه روی همهٔ محصولات متغیر فروشگاه اعمال شود؟')) { return; }
			if (testToggle) { testToggle.checked = false; }
			testState();
			form.submit();
		});
	}

	testState();

	/* ============================================================
	   ۸) خروجی JSON
	   ============================================================ */

	var resetForm = document.getElementById('tcbv-reset-form');
	if (resetForm) {
		resetForm.addEventListener('submit', function (event) {
			if (!window.confirm(S.confirmReset || 'بازنشانی شود؟')) { event.preventDefault(); }
		});
	}

	qsa('.tcbv-textarea[readonly]').forEach(function (area) {
		area.addEventListener('click', function () { area.select(); });
	});

	renumber();
	renderPreview();
})(window, document);
