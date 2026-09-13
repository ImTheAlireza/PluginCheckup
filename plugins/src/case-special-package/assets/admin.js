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
		if (!tabs.length) { return; }

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

		// مقداردهی select2 در صورت وجود.
		if (window.jQuery && jQuery.fn.select2) {
			jQuery(".wcsp-select2").select2({ dir: "rtl", width: "100%" });
		}
	});
})();
