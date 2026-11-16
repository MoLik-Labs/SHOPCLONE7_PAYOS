/*
 * Storefront behaviour — about 2 KB, no dependencies, no network.
 *
 * Everything it enhances already works without it:
 *   - the mobile menu is a normal list of links; this only toggles it;
 *   - dialogs are native <dialog> elements with a visible close control;
 *   - the catalogue is server-rendered and paginated.
 */
(function () {
  "use strict";

  function ready(fn) {
    if (document.readyState !== "loading") {
      fn();
    } else {
      document.addEventListener("DOMContentLoaded", fn);
    }
  }

  function setupMenu() {
    var toggle = document.querySelector("[data-menu-toggle]");
    var sheet = document.querySelector("[data-menu-sheet]");
    if (!toggle || !sheet) {
      return;
    }
    function setOpen(open) {
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
      sheet.hidden = !open;
    }
    toggle.addEventListener("click", function () {
      setOpen(sheet.hidden);
    });
    sheet.addEventListener("click", function (event) {
      if (event.target.closest("a")) {
        setOpen(false);
      }
    });
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && !sheet.hidden) {
        setOpen(false);
        toggle.focus();
      }
    });
    var wide = window.matchMedia("(min-width: 1024px)");
    var onChange = function (event) {
      if (event.matches) {
        setOpen(false);
      }
    };
    if (wide.addEventListener) {
      wide.addEventListener("change", onChange);
    } else if (wide.addListener) {
      wide.addListener(onChange);
    }
  }

  function setupDialogs() {
    document.querySelectorAll("[data-dialog-open]").forEach(function (button) {
      button.addEventListener("click", function () {
        var id = button.getAttribute("data-dialog-open");
        var dialog = id ? document.querySelector(id) : null;
        if (dialog && typeof dialog.showModal === "function") {
          dialog.showModal();
        }
      });
    });
    document.querySelectorAll("dialog[data-dialog]").forEach(function (dialog) {
      dialog.addEventListener("click", function (event) {
        if (event.target === dialog) {
          dialog.close();
        }
      });
      dialog.querySelectorAll("[data-dialog-close]").forEach(function (button) {
        button.addEventListener("click", function () {
          dialog.close();
        });
      });
      dialog.addEventListener("close", function () {
        var opener = dialog.getAttribute("data-opener");
        if (opener) {
          var el = document.getElementById(opener);
          if (el) {
            el.focus();
          }
        }
      });
    });
  }

  /* Admin-popup notice: show once per two hours, never while the visitor is mid-form. */
  function setupNotice() {
    var dialog = document.querySelector("dialog[data-notice]");
    if (!dialog || typeof dialog.showModal !== "function") {
      return;
    }
    var key = dialog.getAttribute("data-notice") || "store-notice";
    var last = 0;
    try {
      last = parseInt(window.localStorage.getItem(key), 10) || 0;
    } catch (error) {
      last = 0;
    }
    if (last && Date.now() - last < 2 * 60 * 60 * 1000) {
      return;
    }
    if (document.querySelector("form:focus-within")) {
      return;
    }
    dialog.showModal();
    dialog.addEventListener("close", function () {
      try {
        window.localStorage.setItem(key, String(Date.now()));
      } catch (error) {
        /* private mode: showing it again is harmless */
      }
    });
  }

  ready(function () {
    setupMenu();
    setupDialogs();
    setupNotice();
  });
})();
