/* پکیج ویژه قاب — ناوبری تب‌های صفحه تنظیمات */
(function () {
	"use strict";

	function ready(fn) {
		if (document.readyState !== "loading") { fn(); }
		else { document.addEventListener("DOMContentLoaded", fn); }
	}

	ready(function () {
		var tabs = document.querySelectorAll(".wcsp-tab[data-tab]");
		var panels = document.querySelectorAll(".wcsp-panel[data-panel]");
		if (tabs.length) {
			initTabs(tabs, panels);
		}
		initCategorySelect();
	});

	/**
	 * انتخاب دسته‌بندی‌ها: مسیر استاندارد ووکامرس.
	 * رویداد wc-enhanced-select-init همان سلکت۲ ووکامرس (selectWoo) را با ظاهر، RTL و
	 * جستجوی درست روی هر «select.wc-enhanced-select» اعمال می‌کند؛ اگر آن اسکریپت
	 * بارگیری نشده باشد، خودمان select2 را می‌زنیم تا فیلد بی‌استایل نماند.
	 */
	function initCategorySelect() {
		if (!window.jQuery) { return; }
		var $ = window.jQuery;
		var $select = $(".wcsp-select2");
		if (!$select.length) { return; }

		$(document.body).trigger("wc-enhanced-select-init");

		if ($select.hasClass("enhanced")) { return; } // ووکامرس خودش ساخته است.
		if ($.fn.select2) {
			$select.select2({ dir: "rtl", width: "100%" });
		}
	}

	function initTabs(tabs, panels) {

		function show(id) {
			tabs.forEach(function (t) {
				t.classList.toggle("active", t.getAttribute("data-tab") === id);
			});
			panels.forEach(function (p) {
				p.classList.toggle("active", p.getAttribute("data-panel") === id);
			});
			try { localStorage.setItem("wcsp_tab", id); } catch (e) {}
			if (history.replaceState) { history.replaceState(null, "", "#wcsp-" + id); }
			window.scrollTo({ top: 0, behavior: "smooth" });
		}

		tabs.forEach(function (t) {
			t.addEventListener("click", function () { show(t.getAttribute("data-tab")); });
		});

		// لینک‌های «دسترسی سریع» که به تب خاصی می‌روند.
		document.querySelectorAll("[data-goto]").forEach(function (el) {
			el.addEventListener("click", function (e) {
				e.preventDefault();
				show(el.getAttribute("data-goto"));
			});
		});

		var initial = (location.hash || "").replace("#wcsp-", "");
		if (!initial || !document.querySelector('.wcsp-tab[data-tab="' + initial + '"]')) {
			try { initial = localStorage.getItem("wcsp_tab") || ""; } catch (e) { initial = ""; }
		}
		show(initial && document.querySelector('.wcsp-tab[data-tab="' + initial + '"]') ? initial : "dash");
	}
})();
