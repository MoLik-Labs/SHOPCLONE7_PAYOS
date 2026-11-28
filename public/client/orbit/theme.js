/* Appearance is a browser-local preference, not a shop or account setting. */
(function () {
  "use strict";
  var key = "molik.appearance.v1";
  var allowed = ["light", "latte", "mocha", "dark"];
  var root = document.documentElement;
  var selected = root.getAttribute("data-theme") || "light";
  try {
    var saved = window.localStorage.getItem(key);
    if (allowed.indexOf(saved) !== -1) selected = saved;
  } catch (_) { /* storage is optional */ }
  if (allowed.indexOf(selected) === -1) selected = "light";
  root.setAttribute("data-theme", selected);
  function setup() {
    var select = document.getElementById("appearance-theme");
    var status = document.getElementById("appearance-status");
    if (!select) return;
    select.value = selected;
    select.disabled = false;
    select.addEventListener("change", function () {
      if (allowed.indexOf(select.value) === -1) return;
      selected = select.value;
      root.setAttribute("data-theme", selected);
      try {
        window.localStorage.setItem(key, selected);
        if (status) status.textContent = "Đã lưu trên trình duyệt này.";
      } catch (_) {
        if (status) status.textContent = "Không thể lưu; lựa chọn chỉ áp dụng cho trang hiện tại.";
      }
    });
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", setup);
  else setup();
})();
