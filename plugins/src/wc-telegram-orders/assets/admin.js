/* ارسال سفارش‌ها به تلگرام — رفتار صفحهٔ تنظیمات: درج متغیر در جای مکان‌نما */
(function () {
	"use strict";

	function insertAtCursor(el, text) {
		el.focus();
		var start = el.selectionStart, end = el.selectionEnd, v = el.value;
		if (typeof start !== "number") { el.value = v + text; return; }
		// {if_wallet}…{/if_wallet}: متن انتخاب‌شده را داخل بلاک بگذار
		var m = text.match(/^(\{if_wallet\})…(\{\/if_wallet\})$/);
		var ins = text, caret;
		if (m) {
			var sel = v.slice(start, end);
			ins = m[1] + (sel || "") + m[2];
			caret = start + m[1].length + sel.length;
		} else {
			caret = start + ins.length;
		}
		el.value = v.slice(0, start) + ins + v.slice(end);
		el.setSelectionRange(caret, caret);
		el.dispatchEvent(new Event("input", { bubbles: true }));
	}

	document.addEventListener("click", function (e) {
		var btn = e.target.closest(".wcto-var");
		if (!btn) { return; }
		var box = btn.closest(".wcto-vars");
		var target = box && document.getElementById(box.getAttribute("data-target"));
		if (!target) { return; }
		insertAtCursor(target, btn.getAttribute("data-var"));
		btn.classList.add("is-hit");
		window.setTimeout(function () { btn.classList.remove("is-hit"); }, 350);
	});
})();
