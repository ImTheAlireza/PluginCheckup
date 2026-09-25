/*!
 * TisaCase — گروه‌بندی متغیرها بر اساس برند | لایهٔ نمایش (سمت کاربر)
 * ------------------------------------------------------------------
 * کاری که می‌کند (فقط نمایش، بدون هیچ نوشتنی در دیتابیس):
 *   ۱) گزینه‌های ویژگی «مدل» را با همان قواعد سرور به برندها دسته‌بندی می‌کند.
 *   ۲) یک پنل تمیز و جستجوپذیر جای لیست تخت ۱۵۰تایی می‌سازد: عنوان برند،
 *      شمارش، خط جداکننده بین برندها و فیلتر برند.
 *   ۳) ویژگی «رنگ» را به سواچ رنگی تبدیل می‌کند.
 *   select اصلی ووکامرس دست‌نخورده در DOM می‌ماند (فقط پنهان می‌شود)، پس
 *   منطق قیمت/تصویر/سبد ووکامرس و قالب دقیقاً مثل قبل کار می‌کند.
 */
(function (window, document) {
	'use strict';

	var VERSION = '1.0.0';

	/* ============================================================
	   پیکربندی
	   ============================================================ */

	function normalizeCfg(raw) {
		raw = raw || {};
		var ui = raw.ui || {};
		var theme = raw.theme || {};
		var swatch = raw.swatch || {};
		return {
			brands: raw.brands || [],
			unknown: raw.unknown || { label: 'سایر مدل‌ها', color: '#94A3B8', enabled: 1, position: 'last' },
			groupScope: raw.groupScope || 'auto',
			groupAttrs: raw.groupAttrs || [],
			skipAttrs: raw.skipAttrs || [],
			swatchAttrs: raw.swatchAttrs || [],
			minBrands: raw.minBrands || 2,
			minOptions: raw.minOptions || 6,
			debug: !!raw.debug,
			colorMap: raw.colorMap || [],
			i18n: raw.i18n || {},
			ui: {
			mode: ui.mode || 'panel',
			picker: ui.picker || 'accordion',
			layout: ui.layout || 'chips',
				separator: ui.separator || 'line',
				groupStyle: ui.group_style || ui.groupStyle || 'header',
				sort: ui.sort || 'asis',
				search: ui.search === undefined ? 1 : ui.search,
				searchPlaceholder: ui.search_placeholder || 'جستجو…',
				counts: ui.counts === undefined ? 1 : ui.counts,
				sticky: ui.sticky === undefined ? 1 : ui.sticky,
				chips: ui.chips === undefined ? 0 : ui.chips,
				maxHeight: parseInt(ui.max_height, 10) || 320,
				colorsOnItems: ui.colors_on_items === undefined ? 0 : ui.colors_on_items,
				showLabel: ui.show_label === undefined ? 0 : ui.show_label,
				highlight: ui.highlight === undefined ? 1 : ui.highlight,
				oos: parseInt(ui.oos, 10) || 0,
				fa: ui.fa_digits === undefined ? 1 : ui.fa_digits
			},
			swatch: {
				enabled: swatch.enabled === undefined ? 1 : (swatch.enabled ? 1 : 0),
				shape: swatch.shape || 'circle',
				size: parseInt(swatch.size, 10) || 28,
				showLabel: swatch.showLabel === undefined ? 1 : swatch.showLabel,
				fallback: swatch.fallback || '#CBD5E1'
			},
			theme: theme
		};
	}

	var CFG = normalizeCfg(window.TCBV_CFG);

	function log() {
		if (CFG.debug && window.console && console.log) {
			var args = ['[TCBV ' + VERSION + ']'].concat([].slice.call(arguments));
			console.log.apply(console, args);
		}
	}

	/* ============================================================
	   ابزارها — نرمال‌سازی دقیقاً مثل سرور (class-tcbv-rules.php)
	   ============================================================ */

	var UNICODE_OK = (function () {
		try { new RegExp('[\\p{L}]', 'u'); return true; } catch (e) { return false; }
	})();

	var WORD_RE = UNICODE_OK ? /[^\p{L}\p{N}]+/gu : /[^A-Za-z0-9\u0600-\u06FF]+/g;
	var KEY_RE = UNICODE_OK ? /[^\p{L}\p{N}]+/gu : /[^A-Za-z0-9\u0600-\u06FF]+/g;

	function normalize(text) {
		var s = String(text === null || text === undefined ? '' : text);
		if (!s) { return ''; }
		s = s.replace(/[\u200B-\u200F\u00A0\u2060]/g, ' ');
		s = s.replace(/[\u064A\u0649\u06CC]/g, '\u06CC'); // ي ى ی → ی
		s = s.replace(/\u0643/g, '\u06A9').replace(/\u0629/g, '\u0647');
		s = s.replace(/[\u064B-\u0655\u0670]/g, '');
		s = s.replace(/[\u06F0-\u06F9]/g, function (d) { return String(d.charCodeAt(0) - 0x06F0); });
		s = s.replace(/[\u0660-\u0669]/g, function (d) { return String(d.charCodeAt(0) - 0x0660); });
		s = s.replace(/\s+/g, ' ').trim().toLowerCase();
		return s;
	}

	function keyOf(text) {
		return normalize(text).replace(KEY_RE, '');
	}

	function tokensOf(text) {
		var out = normalize(text).split(WORD_RE);
		var clean = [];
		for (var i = 0; i < out.length; i++) {
			if (out[i]) { clean.push(out[i]); }
		}
		return clean;
	}

	var SEG_RE = /\s*(?:\/|\\|\||\u060C|,|;|\u061B|\+|&|\u2044|\bو\b)\s*/;

	// «A30s/A50/A50s» سه مدل مستقل است؛ هر تکه جداگانه سنجیده می‌شود.
	function segmentsOf(text) {
		var norm = normalize(text);
		if (!norm) { return []; }
		var parts = norm.split(SEG_RE);
		var out = [];
		for (var i = 0; i < parts.length; i++) {
			var part = String(parts[i] || '').trim();
			if (part && out.indexOf(part) === -1) { out.push(part); }
		}
		return out;
	}

	function candidatesOf(text) {
		var norm = normalize(text);
		if (!norm) { return []; }
		var out = [norm];
		var segs = segmentsOf(text);
		for (var i = 0; i < segs.length; i++) {
			if (out.indexOf(segs[i]) === -1) { out.push(segs[i]); }
		}
		return out;
	}

	// برابری دقیق ← کد چسبیده به عدد (mi11t / iphone13) ← پیشوند کلیدواژهٔ بلند.
	function tokenMatches(token, kw) {
		if (!token || !kw) { return false; }
		if (token === kw) { return true; }
		if (kw.length < 2 || token.indexOf(kw) !== 0) { return false; }
		var rest = token.slice(kw.length);
		if (!rest) { return true; }
		if (/^[0-9]/.test(rest)) { return true; }
		return kw.length >= 4;
	}

	function escapeRegex(str) {
		return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	}

	function toRegex(pattern) {
		pattern = String(pattern || '').trim();
		if (!pattern) { return null; }
		try {
			var m = pattern.match(/^([/~#%!]).*\1[a-zA-Z]*$/);
			if (m) { return new RegExp(pattern.slice(1, pattern.lastIndexOf(m[1])), 'i'); }
			return new RegExp('(?:' + pattern + ')', 'i');
		} catch (e) {
			log('الگوی نامعتبر:', pattern, e.message);
			return null;
		}
	}

	function toDomRegex(pattern) {
		pattern = String(pattern || '').trim();
		if (!pattern) { return null; }
		try {
			var m = pattern.match(/^([/~#%!]).*\1[a-zA-Z]*$/);
			if (m) {
				var flags = pattern.slice(pattern.lastIndexOf(m[1]) + 1).replace(/[^gimsuy]/g, '');
				return new RegExp(pattern.slice(1, pattern.lastIndexOf(m[1])), flags);
			}
			return new RegExp('(?:' + pattern + ')');
		} catch (e) {
			return null;
		}
	}

	function linesOf(value) {
		if (Object.prototype.toString.call(value) === '[object Array]') {
			return value.filter(function (v) { return String(v).trim() !== ''; });
		}
		return String(value || '').split('\n').map(function (l) { return l.trim(); }).filter(Boolean);
	}

	function matchesBrand(value, brand) {
		var norm = normalize(value);
		if (!norm) { return false; }

		var cands = candidatesOf(value);
		var c;

		var exact = linesOf(brand.exact);
		for (var i = 0; i < exact.length; i++) {
			var needle = keyOf(exact[i]);
			if (!needle) { continue; }
			for (c = 0; c < cands.length; c++) {
				if (keyOf(cands[c]) === needle) { return true; }
			}
		}

		var patterns = linesOf(brand.regex);
		for (var j = 0; j < patterns.length; j++) {
			var rx = toRegex(patterns[j]);
			if (!rx) { continue; }
			if (rx.test(String(value))) { return true; }
			for (c = 0; c < cands.length; c++) {
				if (rx.test(cands[c])) { return true; }
			}
		}

		var keywords = linesOf(brand.keywords);
		for (c = 0; c < cands.length; c++) {
			var tokens = tokensOf(cands[c]);
			for (var k = 0; k < keywords.length; k++) {
				var kw = normalize(keywords[k]);
				if (!kw) { continue; }
				if (kw.indexOf(' ') !== -1) {
					if (cands[c].indexOf(kw) !== -1) { return true; }
					continue;
				}
				if (kw.charAt(kw.length - 1) === '*') {
					var prefix = kw.slice(0, -1);
					if (!prefix) { continue; }
					for (var t = 0; t < tokens.length; t++) {
						if (tokens[t].indexOf(prefix) === 0) { return true; }
					}
					continue;
				}
				for (var u = 0; u < tokens.length; u++) {
					if (tokenMatches(tokens[u], kw)) { return true; }
				}
			}
		}
		return false;
	}

	function classifyValue(value) {
		var hits = [];
		var texts = textsOf(value);
		for (var i = 0; i < CFG.brands.length; i++) {
			var brand = CFG.brands[i];
			if (!brand.enabled) { continue; }
			for (var t = 0; t < texts.length; t++) {
				if (matchesBrand(texts[t], brand)) { hits.push(brand.id); break; }
			}
		}
		return { id: hits.length ? hits[0] : 'unknown', hits: hits };
	}

	function groupValues(values) {
		var buckets = {}, order = [], i;
		for (i = 0; i < CFG.brands.length; i++) {
			if (CFG.brands[i].enabled) {
				buckets[CFG.brands[i].id] = [];
				order.push(CFG.brands[i].id);
			}
		}
		buckets.unknown = [];

		for (i = 0; i < values.length; i++) {
			var id = classifyValue(values[i]).id;
			if (!buckets[id]) { buckets[id] = []; order.push(id); }
			buckets[id].push(values[i]);
		}

		var groups = [];
		for (i = 0; i < CFG.brands.length; i++) {
			var brand = CFG.brands[i];
			if (!brand.enabled || !buckets[brand.id] || !buckets[brand.id].length) { continue; }
			groups.push({
				id: brand.id,
				label: brand.label,
				color: brand.color,
				icon: brand.icon || '',
				values: sortValues(buckets[brand.id]),
				unknown: false
			});
		}

		if (buckets.unknown.length && CFG.unknown.enabled) {
			var unknownGroup = {
				id: 'unknown',
				label: CFG.unknown.label || 'سایر مدل‌ها',
				color: CFG.unknown.color || '#94A3B8',
				icon: '',
				values: sortValues(buckets.unknown),
				unknown: true
			};
			if (CFG.unknown.position === 'first') { groups.unshift(unknownGroup); } else { groups.push(unknownGroup); }
		}

		return groups;
	}

	function sortValues(values) {
		var out = values.slice();
		if (CFG.ui.sort === 'asc' || CFG.ui.sort === 'desc') {
			out.sort(function (a, b) {
				return normalize(a).localeCompare(normalize(b), 'fa', { numeric: true, sensitivity: 'base' });
			});
			if (CFG.ui.sort === 'desc') { out.reverse(); }
		}
		return out;
	}

	function attrKeyOf(select) {
		var name = select.getAttribute('data-attribute_name') || select.getAttribute('name') || '';
		name = name.replace(/^attribute_/i, '');
		try { name = decodeURIComponent(name); } catch (e) { /* نام خام */ }
		return keyOf(name);
	}

	function listHas(list, attrKey) {
		for (var i = 0; i < list.length; i++) {
			if (keyOf(list[i]) === attrKey) { return true; }
		}
		return false;
	}

	function isColorAttr(attrKey) {
		return listHas(CFG.swatchAttrs, attrKey);
	}

	// نقشهٔ «مقدار select → متن دیده‌شده». مقدارِ گزینه در ووکامرس اسلاگ است
	// (مثلاً «a20-a30» یا برای فارسی درصدکدشده)، پس هم برای تشخیص برند و هم
	// برای جست‌وجو باید متن واقعیِ گزینه ملاک باشد.
	var LABELS = null;

	function decodeSafe(value) {
		var s = String(value === null || value === undefined ? '' : value);
		if (s.indexOf('%') === -1) { return s; }
		try { return decodeURIComponent(s); } catch (e) { return s; }
	}

	function useLabels(select) {
		var map = {}, options = (select && select.options) || [];
		for (var i = 0; i < options.length; i++) {
			var v = options[i].value;
			if (v === '' || v === null || typeof v === 'undefined') { continue; }
			map[v] = clean(options[i].text) || decodeSafe(v);
		}
		LABELS = map;
		return map;
	}

	// متن‌های قابل‌جست‌وجو/تطبیق یک مقدار: برچسب دیده‌شده + اسلاگ دیکدشده.
	function textsOf(value) {
		var out = [];
		var label = LABELS ? LABELS[value] : '';
		if (label) { out.push(label); }
		var dec = decodeSafe(value).replace(/[-_]+/g, ' ');
		if (dec && out.indexOf(dec) === -1) { out.push(dec); }
		return out.length ? out : [String(value)];
	}

	function optionValues(select) {
		var values = [], options = select.options || [];
		for (var i = 0; i < options.length; i++) {
			var v = options[i].value;
			if (v === '' || v === null || typeof v === 'undefined') { continue; }
			values.push(v);
		}
		return values;
	}

	function optionLabel(select, value) {
		var options = select.options || [];
		for (var i = 0; i < options.length; i++) {
			if (options[i].value === value) {
				var text = clean(options[i].text);
				return text || value;
			}
		}
		return value;
	}

	function shouldGroup(attrKey, values) {
		if (isColorAttr(attrKey)) { return false; }
		var listed = listHas(CFG.groupAttrs, attrKey);
		var skipped = listHas(CFG.skipAttrs, attrKey);

		if (CFG.groupScope === 'list') { return listed; }
		if (listed) { return true; }
		if (skipped) { return false; }

		var branded = 0, groups = groupValues(values), i;
		for (i = 0; i < groups.length; i++) {
			if (!groups[i].unknown) { branded++; }
		}
		if (branded >= CFG.minBrands && values.length >= CFG.minOptions) { return true; }
		return branded >= 1 && values.length >= 25 && values.length >= CFG.minOptions;
	}

	/* ============================================================
	   ابزارهای DOM
	   ============================================================ */

	function fa(value) {
		if (!CFG.ui.fa) { return String(value); }
		return String(value).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.charAt(d.charCodeAt(0) - 48); });
	}

	function fire(el, type) {
		var evt;
		try {
			evt = new window.Event(type, { bubbles: true, cancelable: true });
		} catch (e) {
			evt = document.createEvent('HTMLEvents');
			evt.initEvent(type, true, true);
		}
		el.dispatchEvent(evt);
	}

	function el(tag, cls, text) {
		var node = document.createElement(tag);
		if (cls) { node.className = cls; }
		if (text !== undefined && text !== null) { node.textContent = text; }
		return node;
	}

	function clean(text) {
		return String(text || '').replace(/\s+/g, ' ').trim();
	}

	function fieldLabel(select) {
		var id = select.getAttribute('id'), node, text;
		if (id && document.querySelector && window.CSS && CSS.escape) {
			try {
				node = document.querySelector('label[for="' + CSS.escape(id) + '"]');
				if (node) { return clean(node.textContent); }
			} catch (e) { /* بی‌خیال */ }
		}
		var scope = select.closest ? select.closest('tr, .form-row, p, .woocommerce-variation-attribute, .tcbv-scope') : null;
		if (scope) {
			node = scope.querySelector('th, label, .label, .attribute-label');
			if (node) {
				text = clean(node.textContent);
				if (text && text.length <= 40) { return text; }
			}
		}
		return '';
	}

	function debounce(fn, wait) {
		var timer = null;
		return function () {
			var args = arguments, self = this;
			window.clearTimeout(timer);
			timer = window.setTimeout(function () { fn.apply(self, args); }, wait);
		};
	}

	/* ============================================================
	   سواچ رنگ
	   ============================================================ */

	var COLOR_MAP = (function () {
		var map = {}, i, name;
		for (i = 0; i < CFG.colorMap.length; i++) {
			name = keyOf(CFG.colorMap[i][0]);
			if (name) { map[name] = CFG.colorMap[i][1]; }
		}
		return map;
	})();

	var MODIFIERS = [
		{ words: ['روشن', 'کمرنگ', 'light', 'lite'], cls: 'is-light' },
		{ words: ['تیره', 'پررنگ', 'dark', 'deep'], cls: 'is-dark' },
		{ words: ['متالیک', 'metallic', 'براق', 'glossy'], cls: 'is-glossy' }
	];

	function colorOf(label) {
		var norm = normalize(label);
		var k = keyOf(label);
		if (COLOR_MAP[k]) { return { hex: COLOR_MAP[k], special: '' }; }
		if (COLOR_MAP[norm]) { return { hex: COLOR_MAP[norm], special: '' }; }

		// حذف افزودنی‌های «روشن/تیره/متالیک» و تلاش دوباره
		var i, j, mod = null, base = norm;
		for (i = 0; i < MODIFIERS.length; i++) {
			for (j = 0; j < MODIFIERS[i].words.length; j++) {
				var w = MODIFIERS[i].words[j];
				if (base.indexOf(w) !== -1) {
					mod = MODIFIERS[i].cls;
					base = clean(base.split(w).join(' '));
				}
			}
		}
		if (mod) {
			var hit = COLOR_MAP[keyOf(base)] || COLOR_MAP[base];
			if (hit) { return { hex: hit, special: '', modifier: mod }; }
		}

		// آخرین تلاش: هر واژهٔ رنگ داخل نام
		var words = norm.split(/[\s\-_/]+/);
		for (i = 0; i < words.length; i++) {
			var single = COLOR_MAP[keyOf(words[i])];
			if (single) { return { hex: single, special: '', modifier: mod }; }
		}

		return { hex: CFG.swatch.fallback, special: '', matched: false };
	}

	/* ============================================================
	   ساخت UI
	   ============================================================ */

	function destroy(scope) {
		var nodes = scope.querySelectorAll('.tcbv-holder');
		for (var i = 0; i < nodes.length; i++) {
			var select = nodes[i].__tcbvSelect;
			if (select) {
				select.classList.remove('tcbv-native');
				select.removeAttribute('data-tcbv-active');
				delete select.__tcbv;
			}
			nodes[i].parentNode.removeChild(nodes[i]);
		}
		var hidden = scope.querySelectorAll('.tcbv-native-sib');
		for (var j = 0; j < hidden.length; j++) {
			hidden[j].classList.remove('tcbv-native-sib');
		}
	}

	function attach(select, root) {
		var holder = el('div', 'tcbv-holder');
		holder.__tcbvSelect = select;

		if (select.nextSibling) {
			select.parentNode.insertBefore(holder, select.nextSibling);
		} else {
			select.parentNode.appendChild(holder);
		}
		holder.appendChild(root);

		select.classList.add('tcbv-native');
		select.setAttribute('data-tcbv-active', '1');

		// پنهان‌کردن منوی select2/chosen قالب‌ها که روی همان select نشسته‌اند.
		var siblings = select.parentNode.children;
		for (var i = 0; i < siblings.length; i++) {
			var sib = siblings[i];
			if (sib === select || sib === holder) { continue; }
			var cls = sib.className || '';
			if (typeof cls === 'string' && (cls.indexOf('select2') !== -1 || cls.indexOf('chosen-container') !== -1)) {
				sib.classList.add('tcbv-native-sib');
			}
		}

		select.__tcbv = { holder: holder, root: root };
		return holder;
	}

	function buildSwatches(select, attrKey) {
		var values = optionValues(select);
		if (!values.length) { return null; }

		var root = el('div', 'tcbv tcbv--swatch');
		root.setAttribute('data-tcbv-kind', 'swatch');
		root.setAttribute('data-tcbv-shape', CFG.swatch.shape);

		var wrap = el('div', 'tcbv-sw-items');
		wrap.setAttribute('role', 'listbox');
		wrap.setAttribute('aria-label', fieldLabel(select) || CFG.i18n.models || 'انتخاب گزینه');

		var buttons = [];
		for (var i = 0; i < values.length; i++) {
			var color = colorOf(values[i]);
			var btn = el('button', 'tcbv-sw');
			btn.type = 'button';
			btn.setAttribute('role', 'option');
			btn.setAttribute('data-value', values[i]);
			btn.setAttribute('aria-selected', 'false');
			btn.title = values[i];

			var dot = el('span', 'tcbv-sw-dot');
			if (color.hex && color.hex.charAt(0) === '@') {
				if (color.hex === '@multi') { dot.classList.add('is-multi'); } else { dot.classList.add('is-clear'); }
			} else if (color.hex) {
				dot.style.setProperty('--tcbv-c', color.hex);
			}
			if (color.modifier) { dot.classList.add(color.modifier); }
			if (color.matched === false) { dot.classList.add('is-unknown'); }
			if (!color.special && (CFG.swatch.shape === 'dot')) { dot.classList.add('is-dot'); }

			btn.appendChild(dot);
			if (CFG.swatch.showLabel) {
				btn.appendChild(el('span', 'tcbv-sw-label', values[i]));
			}
			wrap.appendChild(btn);
			buttons.push(btn);
		}

		root.appendChild(wrap);

		var api = {
			select: select,
			root: root,
			items: buttons,
			setValue: function (value, silent) {
				if (!silent && select.value !== value) {
					select.value = value;
					fire(select, 'change');
				}
				for (var i = 0; i < buttons.length; i++) {
					var on = buttons[i].getAttribute('data-value') === select.value;
					buttons[i].classList.toggle('is-selected', on);
					buttons[i].setAttribute('aria-selected', on ? 'true' : 'false');
				}
			}
		};

		wrap.addEventListener('click', function (event) {
			var btn = event.target.closest ? event.target.closest('.tcbv-sw') : null;
			if (btn) { api.setValue(btn.getAttribute('data-value')); }
		});

		return api;
	}

	function buildPanel(select, attrKey) {
		useLabels(select);
		var values = optionValues(select);
		if (values.length < 2) { return null; }

		var groups = groupValues(values);
		if (!groups.length) { return null; }

		var picker = CFG.ui.picker === 'open' ? 'open' : 'accordion';
		var root = el('div', 'tcbv tcbv--brands tcbv--' + picker);
		root.setAttribute('data-tcbv-kind', 'brand');
		root.setAttribute('data-tcbv-picker', picker);
		root.setAttribute('data-tcbv-layout', 'chips');
		root.setAttribute('data-tcbv-sep', CFG.ui.separator);

		var items = [];
		var drops = [];
		var state = { query: '', oosSet: null, openId: '', manual: false };
		var oosMode = CFG.ui.oos;

		var searchWrap = el('div', 'tcbv-search tcbv-search--global');
		var searchInput = el('input', 'tcbv-input');
		searchInput.type = 'text';
		searchInput.placeholder = CFG.ui.searchPlaceholder || 'جستجوی مدل…';
		searchInput.setAttribute('aria-label', searchInput.placeholder);
		searchInput.autocomplete = 'off';
		searchWrap.appendChild(searchInput);
		var searchClear = el('button', 'tcbv-clear');
		searchClear.type = 'button';
		searchClear.setAttribute('aria-label', CFG.i18n.clear || 'پاک کردن');
		searchClear.hidden = true;
		searchWrap.appendChild(searchClear);
		root.appendChild(searchWrap);

		var list = el('div', 'tcbv-dd-list');

		function setDropOpen(drop, on) {
			drop.pop.hidden = !on;
			drop.wrap.classList.toggle('is-open', on);
			drop.trigger.setAttribute('aria-expanded', on ? 'true' : 'false');
		}

		function closeAll() {
			if (picker === 'open') { return; }
			for (var i = 0; i < drops.length; i++) { setDropOpen(drops[i], false); }
			state.openId = '';
			state.manual = false;
		}

		function openDrop(drop) {
			closeAll();
			setDropOpen(drop, true);
			state.openId = drop.id;
			state.manual = true;
		}

		function openMatches() {
			if (picker === 'open') { return; }
			for (var i = 0; i < drops.length; i++) {
				var drop = drops[i];
				var shown = 0;
				for (var j = 0; j < drop.items.length; j++) {
					if (!drop.items[j].hidden) { shown++; }
				}
				setDropOpen(drop, shown > 0 && !!state.query);
			}
			state.openId = '';
			state.manual = false;
		}

		for (var g = 0; g < groups.length; g++) {
			(function (group) {
				var wrap = el('div', 'tcbv-dd');
				wrap.setAttribute('data-brand', group.id);
				wrap.style.setProperty('--tcbv-c', group.color || 'currentColor');

				var trigger = el('button', 'tcbv-trigger is-placeholder');
				trigger.type = 'button';
				trigger.setAttribute('aria-haspopup', 'listbox');
				trigger.setAttribute('aria-expanded', 'false');
				var tLabel = el('span', 'tcbv-trigger-brand', group.label);
				var tValue = el('span', 'tcbv-trigger-text', 'انتخاب مدل');
				var tCount = el('span', 'tcbv-trigger-count', fa(group.values.length));
				trigger.appendChild(tLabel);
				trigger.appendChild(tValue);
				if (CFG.ui.counts) { trigger.appendChild(tCount); }
				trigger.appendChild(el('span', 'tcbv-trigger-caret'));
				wrap.appendChild(trigger);

				var pop = el('div', 'tcbv-pop');
				if (picker !== 'open') { pop.hidden = true; }
				pop.setAttribute('role', 'listbox');
				pop.setAttribute('aria-label', group.label);

				var dropItems = [];
				var itemWrap = el('div', 'tcbv-items');
				for (var v = 0; v < group.values.length; v++) {
					var value = group.values[v];
					var item = el('button', 'tcbv-item');
					item.type = 'button';
					item.setAttribute('role', 'option');
					item.setAttribute('data-value', value);
					item.setAttribute('data-brand', group.id);
					item.setAttribute('data-key', keyOf(optionLabel(select, value)));
					item.setAttribute('data-alt', keyOf(decodeSafe(value)));
					item.setAttribute('aria-selected', 'false');
					item.appendChild(el('span', 'tcbv-item-text', optionLabel(select, value)));
					itemWrap.appendChild(item);
					dropItems.push(item);
					items.push(item);
				}
				pop.appendChild(itemWrap);

				var empty = el('div', 'tcbv-empty', CFG.i18n.noResult || 'چیزی پیدا نشد');
				empty.hidden = true;
				pop.appendChild(empty);
				wrap.appendChild(pop);
				list.appendChild(wrap);

				var drop = {
					id: group.id,
					label: group.label,
					wrap: wrap,
					trigger: trigger,
					tValue: tValue,
					tCount: tCount,
					pop: pop,
					empty: empty,
					items: dropItems
				};
				drops.push(drop);

				if (picker === 'open') {
					trigger.setAttribute('aria-expanded', 'true');
					wrap.classList.add('is-open');
				} else {
					trigger.addEventListener('click', function (event) {
						event.preventDefault();
						event.stopPropagation();
						if (state.openId === drop.id && state.manual) { closeAll(); } else { openDrop(drop); }
					});
				}

				pop.addEventListener('click', function (event) {
					var target = event.target.closest ? event.target.closest('.tcbv-item') : null;
					if (!target || target.hidden) { return; }
				api.setValue(target.getAttribute('data-value'));
				if (!state.query) { closeAll(); }
				});
			})(groups[g]);
		}

		root.appendChild(list);

		var api = {
			select: select,
			root: root,
			items: items,
			setValue: function (value, silent) {
				if (!silent && select.value !== value) {
					select.value = value;
					fire(select, 'change');
				}
				applySelected();
			},
			sync: function () { applySelected(); },
			close: closeAll,
			setOos: function (set) {
				state.oosSet = set;
				applyFilter();
			}
		};

		function applySelected() {
			var val = select.value;
			root.classList.toggle('has-value', !!val);
			for (var d = 0; d < drops.length; d++) {
				var drop = drops[d];
				var hit = '';
				for (var i = 0; i < drop.items.length; i++) {
					var on = val !== '' && drop.items[i].getAttribute('data-value') === val;
					drop.items[i].classList.toggle('is-selected', on);
					drop.items[i].setAttribute('aria-selected', on ? 'true' : 'false');
					if (on) { hit = val; }
				}
				drop.tValue.textContent = hit ? optionLabel(select, hit) : 'انتخاب مدل';
				drop.trigger.classList.toggle('is-placeholder', !hit);
				drop.wrap.classList.toggle('has-value', !!hit);
			}
		}

		function matchQuery(item, brandHit) {
			if (!state.query) { return true; }
			if (brandHit) { return true; }
			var key = item.getAttribute('data-key') || '';
			var alt = item.getAttribute('data-alt') || '';
			return key.indexOf(state.query) !== -1 || alt.indexOf(state.query) !== -1;
		}

		function applyFilter() {
			var any = 0;
			for (var d = 0; d < drops.length; d++) {
				var drop = drops[d];
				var shown = 0;
				// جست‌وجوی نام برند («سامسونگ»، «xiaomi») کل آن گروه را نشان می‌دهد.
				var brandHit = !!state.query && keyOf(drop.label).indexOf(state.query) !== -1;
				for (var i = 0; i < drop.items.length; i++) {
					var item = drop.items[i];
					var oosHidden = oosMode === 2 && state.oosSet && state.oosSet.indexOf(item.getAttribute('data-value')) === -1;
					var show = matchQuery(item, brandHit) && !oosHidden;
					item.hidden = !show;
					if (show) { shown++; }
				}
				drop.empty.hidden = shown !== 0;
				drop.wrap.hidden = shown === 0 && !!state.query;
				if (drop.tCount) { drop.tCount.textContent = fa(shown); }
				any += shown;
			}
			searchClear.hidden = !state.query;
			searchWrap.classList.toggle('has-query', !!state.query);
			if (picker !== 'open') {
				if (state.query) { openMatches(); }
				else if (!state.manual) { closeAll(); }
			}
		}

		searchInput.addEventListener('input', debounce(function () {
			state.query = keyOf(searchInput.value);
			applyFilter();
		}, 80));
		searchClear.addEventListener('click', function () {
			searchInput.value = '';
			state.query = '';
			applyFilter();
			searchInput.focus();
		});
		searchInput.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				searchInput.value = '';
				state.query = '';
				applyFilter();
			}
		});

		document.addEventListener('click', function (event) {
			if (!root.contains(event.target) && !state.query) { closeAll(); }
		});
		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && !state.query) { closeAll(); }
		});

		return api;
	}

	/* ============================================================
	   چرخهٔ عمر
	   ============================================================ */

	var instances = [];

	function oosSetFor(form, select) {
		if (!CFG.ui.oos) { return null; }
		if (form.__tcbvOosCache && form.__tcbvOosCache.attr === (select.getAttribute('data-attribute_name') || select.getAttribute('name'))) {
			return form.__tcbvOosCache.list;
		}
		var raw = form.getAttribute('data-product_variations');
		if (!raw && window.jQuery) {
			try { raw = window.jQuery(form).attr('data-product_variations'); } catch (e) { raw = null; }
		}
		if (!raw) { return null; }
		var data;
		try { data = JSON.parse(raw); } catch (e) { return null; }
		if (!data || !data.length) { return null; }

		var attrName = select.getAttribute('data-attribute_name') || select.getAttribute('name');
		var available = [];
		for (var i = 0; i < data.length; i++) {
			var variation = data[i];
			if (!variation || !variation.attributes) { continue; }
			var value = variation.attributes[attrName];
			var inStock = variation.is_in_stock !== false && variation.is_in_stock !== 0 && variation.is_in_stock !== '0';
			var visible = variation.variation_is_visible !== false && variation.variation_is_visible !== 0 && variation.variation_is_visible !== '0';
			if (!inStock || !visible) { continue; }
			if (value === undefined || value === '') { continue; }
			if (available.indexOf(value) === -1) { available.push(value); }
		}
		form.__tcbvOosCache = { attr: attrName, list: available };
		return available;
	}

	function initSelect(form, select) {
		if (select.__tcbv) {
			try { select.__tcbv.sync(); } catch (e) { /* بی‌خیال */ }
			return;
		}
		if (select.disabled && select.options.length < 2) { return; }

		var attrKey = attrKeyOf(select);
		useLabels(select);
		var values = optionValues(select);
		if (values.length < 2) { return; }

		var api = null;
		try {
			if (isColorAttr(attrKey) && CFG.swatch.enabled) {
				api = buildSwatches(select, attrKey);
			} else if (shouldGroup(attrKey, values)) {
				api = buildPanel(select, attrKey);
			}
		} catch (e) {
			log('خطا در ساخت رابط برای', attrKey, e);
			return;
		}

		if (!api) { return; }

		attach(select, api.root);
		instances.push({ select: select, api: api });
		api.setValue(select.value, true);
		if (api.setOos) { api.setOos(oosSetFor(form, select)); }
		log('ساخته شد:', attrKey, values.length, 'گزینه');

		// هم‌گام‌سازی وقتی ووکامرس/قالب مقدار select را عوض می‌کند (پاک‌کردن، انتخاب خودکار…)
		select.addEventListener('change', function () { api.setValue(select.value, true); });
	}

	function scan(root) {
		root = root || document;
		var forms = root.querySelectorAll ? root.querySelectorAll('form.variations_form') : [];
		for (var i = 0; i < forms.length; i++) {
			var form = forms[i];
			var selects = form.querySelectorAll('select[data-attribute_name], select[name^="attribute_"], select.tcbv-native');
			for (var j = 0; j < selects.length; j++) {
				initSelect(form, selects[j]);
			}
			bindForm(form);
		}
	}

	var bound = [];

	function bindForm(form) {
		if (bound.indexOf(form) !== -1) { return; }
		bound.push(form);

		if (window.jQuery) {
			var jq = window.jQuery(form);
			var sync = debounce(function () {
				for (var i = 0; i < instances.length; i++) {
					if (form.contains(instances[i].select)) {
						instances[i].api.setValue(instances[i].select.value, true);
						if (instances[i].api.setOos) { instances[i].api.setOos(oosSetFor(form, instances[i].select)); }
					}
				}
			}, 60);
			jq.on('woocommerce_variation_form found_variation reset_data hide_variation show_variation', sync);
			jq.on('click', '.reset_variations', function () { window.setTimeout(sync, 30); });
		}

		if (window.MutationObserver) {
			var observer = new MutationObserver(debounce(function () {
				scan(form);
			}, 140));
			observer.observe(form, { childList: true, subtree: true });
		}
	}

	/* ============================================================
	   راه‌اندازی
	   ============================================================ */

	var attempts = 0;
	function boot() {
		scan(document);
		attempts++;
		if (attempts < 12) {
			window.setTimeout(function () {
				var pending = false;
				for (var i = 0; i < instances.length; i++) { pending = true; break; }
				if (!pending) { boot(); }
			}, 400);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { window.setTimeout(boot, 0); });
	} else {
		window.setTimeout(boot, 0);
	}
	window.addEventListener('load', function () { window.setTimeout(function () { scan(document); }, 120); });

	// اگر قالب فرم را دیرتر می‌سازد (مشاهدهٔ سریع/AJAX)
	if (window.jQuery) {
		window.jQuery(document).on('woocommerce_variation_form wc_variation_form wc_variations_loaded', function () {
			window.setTimeout(function () { scan(document); }, 30);
		});
	}

	function applyConfig(raw) {
		CFG = normalizeCfg(raw);
	}

	window.TCBV = {
		version: VERSION,
		config: function (raw) {
			applyConfig(raw);
			return CFG;
		},
		init: function (root) { scan(root || document); },
		destroy: function (root) {
			destroy(root || document);
			instances = instances.filter(function (inst) { return !(root || document).contains(inst.select); });
		},
		rebuild: function (root) {
			var scope = root || document;
			destroy(scope);
			scan(scope);
		},
		classify: function (value) { return classifyValue(value); },
		group: function (values) { return groupValues(values); },
		label: function (value) { return colorOf(value); },
		config_ref: function () { return CFG; }
	};

	log('آماده — برندها:', CFG.brands.map(function (b) { return b.label; }).join('، '));
})(window, document);
