/*
 * Storefront behaviour — no dependencies, no build step.
 *
 * Everything it enhances still renders without it:
 *   - the mobile menu is a normal list of links; this only toggles it;
 *   - dialogs are native <dialog> elements with a visible close control;
 *   - the catalogue is server-rendered and paginated;
 *   - the buy panel shows price, stock and balance; JavaScript only asks the server for the
 *     exact total (coupons, tier discounts) and submits the same request the legacy modal used.
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

  /* --- helpers ------------------------------------------------------------ */

  function postForm(endpoint, fields) {
    if (!endpoint || String(endpoint).charAt(0) === "#") {
      /* The static preview rewrites every route to an inert anchor; answer without a network call. */
      return Promise.resolve({ status: "error", msg: "Bản xem trước tĩnh: biểu mẫu không gửi dữ liệu." });
    }
    var body = new URLSearchParams();
    Object.keys(fields).forEach(function (key) {
      body.append(key, fields[key] == null ? "" : fields[key]);
    });
    return fetch(endpoint, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
      body: body.toString()
    }).then(function (response) {
      return response.json();
    });
  }

  function setStatus(el, text, kind) {
    if (!el) {
      return;
    }
    el.textContent = text || "";
    el.classList.remove("is-error", "is-ok");
    if (kind) {
      el.classList.add(kind === "ok" ? "is-ok" : "is-error");
    }
  }

  function recaptchaValue() {
    var field = document.querySelector('[name="g-recaptcha-response"]');
    return field && field.value ? field.value : "";
  }

  /* --- product gallery ---------------------------------------------------- */

  function setupGallery() {
    var main = document.querySelector("[data-gallery-main]");
    var thumbs = document.querySelector("[data-gallery-thumbs]");
    if (!main || !thumbs) {
      return;
    }
    thumbs.addEventListener("click", function (event) {
      var button = event.target.closest("[data-gallery-src]");
      if (!button) {
        return;
      }
      main.src = button.getAttribute("data-gallery-src");
      thumbs.querySelectorAll("[data-gallery-src]").forEach(function (item) {
        item.classList.toggle("is-active", item === button);
      });
    });
  }

  /* --- buy panel ---------------------------------------------------------- */

  function setupBuy() {
    var panel = document.querySelector("[data-buy]");
    if (!panel) {
      return;
    }
    var endpoint = panel.getAttribute("data-endpoint");
    var productId = panel.getAttribute("data-product");
    var token = panel.getAttribute("data-token") || "";
    var apiKey = panel.getAttribute("data-api-key") || "";
    var min = parseInt(panel.getAttribute("data-min"), 10) || 1;
    var max = parseInt(panel.getAttribute("data-max"), 10) || 1;
    var amountInput = panel.querySelector("#amount");
    var couponInput = panel.querySelector("#coupon");
    var moneyOut = panel.querySelector("[data-buy-money]");
    var discountOut = panel.querySelector("[data-buy-discount]");
    var payOut = panel.querySelector("[data-buy-pay]");
    var statusEl = panel.querySelector("[data-buy-status]");
    var submit = panel.querySelector("[data-buy-submit]");
    if (!endpoint || String(endpoint).charAt(0) === "#" || !productId || !amountInput) {
      return;
    }

    var timer = null;
    var request = null;

    function clampAmount() {
      var value = parseInt(amountInput.value, 10);
      if (isNaN(value)) {
        value = min;
      }
      value = Math.max(min, Math.min(max || value, value));
      amountInput.value = String(value);
      return value;
    }

    function quote() {
      if (!token) {
        return;
      }
      if (request) {
        request.abort();
      }
      request = typeof AbortController === "function" ? new AbortController() : null;
      var fields = {
        action: "total_payment",
        id: productId,
        amount: clampAmount(),
        coupon: couponInput ? couponInput.value : "",
        token: token
      };
      var body = new URLSearchParams();
      Object.keys(fields).forEach(function (key) {
        body.append(key, fields[key]);
      });
      fetch(endpoint, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
        body: body.toString(),
        signal: request ? request.signal : undefined
      }).then(function (response) {
        return response.json();
      }).then(function (data) {
        if (data.status === "success") {
          if (moneyOut) { moneyOut.textContent = data.money; }
          if (discountOut) { discountOut.textContent = data.discount; }
          if (payOut) { payOut.textContent = data.pay; }
          setStatus(statusEl, "");
        } else {
          setStatus(statusEl, data.msg || "Không tính được tổng tiền.", "error");
        }
      }).catch(function (error) {
        if (error && error.name === "AbortError") {
          return;
        }
        setStatus(statusEl, "Không kết nối được máy chủ. Vui lòng thử lại.", "error");
      });
    }

    function scheduleQuote() {
      window.clearTimeout(timer);
      timer = window.setTimeout(quote, 350);
    }

    amountInput.addEventListener("input", scheduleQuote);
    amountInput.addEventListener("change", scheduleQuote);
    if (couponInput) {
      couponInput.addEventListener("change", scheduleQuote);
    }

    panel.querySelectorAll("[data-step]").forEach(function (button) {
      button.addEventListener("click", function () {
        var step = parseInt(button.getAttribute("data-step"), 10) || 0;
        var value = (parseInt(amountInput.value, 10) || min) + step;
        amountInput.value = String(Math.max(min, Math.min(max || value, value)));
        quote();
      });
    });

    if (!submit) {
      return;
    }
    submit.addEventListener("click", function () {
      if (!token || !apiKey) {
        setStatus(statusEl, "Phiên đăng nhập đã hết. Vui lòng đăng nhập lại.", "error");
        return;
      }
      var amount = clampAmount();
      submit.disabled = true;
      setStatus(statusEl, "Đang xử lý thanh toán…");
      postForm(endpoint, {
        action: "buyProduct",
        id: productId,
        amount: amount,
        coupon: couponInput ? couponInput.value : "",
        api_key: apiKey
      }).then(function (data) {
        if (data.status === "success") {
          var orderUrl = (panel.getAttribute("data-order-url") || "") + (data.trans_id || "");
          statusEl.textContent = "";
          var message = document.createElement("span");
          message.textContent = (data.msg || "Thanh toán thành công.") + " ";
          var link = document.createElement("a");
          link.href = orderUrl;
          link.textContent = "Xem đơn hàng";
          statusEl.appendChild(message);
          statusEl.appendChild(link);
          statusEl.classList.remove("is-error");
          statusEl.classList.add("is-ok");
          submit.textContent = "Đã thanh toán";
        } else {
          setStatus(statusEl, data.msg || "Thanh toán thất bại.", "error");
          submit.disabled = false;
        }
      }).catch(function () {
        setStatus(statusEl, "Không kết nối được máy chủ. Vui lòng thử lại.", "error");
        submit.disabled = false;
      });
    });

    quote();
  }

  /* --- sign-in / registration -------------------------------------------- */

  function setupAuth() {
    var form = document.querySelector("[data-auth-form]");
    if (!form) {
      return;
    }
    var endpoint = form.getAttribute("data-endpoint");
    var action = form.getAttribute("data-action") || "Login";
    var redirect = form.getAttribute("data-redirect") || "/";
    var statusEl = form.querySelector("[data-auth-status]");
    var submit = form.querySelector("button[type=submit]");
    var strength = form.querySelector("[data-password-strength]");
    var strengthInput = form.querySelector("[data-password-strength-input]");

    function fieldError(name, message) {
      var el = form.querySelector('[data-error-for="' + name + '"]');
      if (el) {
        el.textContent = message || "";
        el.hidden = !message;
      }
      var input = form.querySelector('[name="' + name + '"]');
      if (input) {
        if (message) {
          input.setAttribute("aria-invalid", "true");
          input.classList.add("input--invalid");
        } else {
          input.removeAttribute("aria-invalid");
          input.classList.remove("input--invalid");
        }
      }
    }

    if (strength && strengthInput) {
      strengthInput.addEventListener("input", function () {
        var value = strengthInput.value;
        var checks = [/[A-Z]/, /[a-z]/, /[0-9]/, /[^A-Za-z0-9]/, /.{8,}/].filter(function (re) {
          return re.test(value);
        }).length;
        strength.classList.remove("is-weak", "is-medium", "is-strong");
        if (!value) {
          strength.textContent = "";
          return;
        }
        if (checks >= 5) {
          strength.textContent = "Mật khẩu mạnh";
          strength.classList.add("is-strong");
        } else if (checks >= 3) {
          strength.textContent = "Mật khẩu trung bình";
          strength.classList.add("is-medium");
        } else {
          strength.textContent = "Mật khẩu còn yếu";
          strength.classList.add("is-weak");
        }
      });
    }

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      ["username", "password", "email", "repassword"].forEach(function (name) {
        fieldError(name, "");
      });
      setStatus(statusEl, "");

      var data = new FormData(form);
      var username = (data.get("username") || "").toString().trim();
      var password = (data.get("password") || "").toString();
      var email = (data.get("email") || "").toString().trim();
      var repassword = (data.get("repassword") || "").toString();
      var valid = true;
      if (!username) {
        fieldError("username", "Vui lòng nhập tên đăng nhập.");
        valid = false;
      }
      if (!password) {
        fieldError("password", "Vui lòng nhập mật khẩu.");
        valid = false;
      }
      if (action === "Register") {
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
          fieldError("email", "Email chưa đúng định dạng.");
          valid = false;
        }
        if (password.length < 6) {
          fieldError("password", "Mật khẩu cần ít nhất 6 ký tự.");
          valid = false;
        }
        if (password !== repassword) {
          fieldError("repassword", "Mật khẩu nhập lại chưa khớp.");
          valid = false;
        }
      }
      if (!valid) {
        setStatus(statusEl, "Vui lòng kiểm tra các trường được đánh dấu.", "error");
        return;
      }

      if (submit) {
        submit.disabled = true;
        submit.textContent = "Đang xử lý…";
      }
      var payload = {
        action: action,
        csrf_token: (data.get("csrf_token") || "").toString(),
        recaptcha: recaptchaValue(),
        username: username,
        password: password
      };
      if (action === "Register") {
        payload.email = email;
        payload.repassword = repassword;
      }
      postForm(endpoint, payload).then(function (response) {
        if (response.status === "success") {
          setStatus(statusEl, response.msg || "Thành công.", "ok");
          window.setTimeout(function () {
            window.location.href = redirect;
          }, 700);
          return;
        }
        if (response.status === "verify" && response.url) {
          setStatus(statusEl, response.msg || "Cần xác minh thêm.", "ok");
          window.setTimeout(function () {
            window.location.href = response.url;
          }, 700);
          return;
        }
        setStatus(statusEl, response.msg || "Không thể xử lý. Vui lòng thử lại.", "error");
        if (submit) {
          submit.disabled = false;
          submit.textContent = action === "Register" ? "Đăng ký" : "Đăng nhập";
        }
      }).catch(function () {
        setStatus(statusEl, "Không kết nối được máy chủ. Vui lòng thử lại.", "error");
        if (submit) {
          submit.disabled = false;
          submit.textContent = action === "Register" ? "Đăng ký" : "Đăng nhập";
        }
      });
    });
  }

  /* --- account profile ---------------------------------------------------- */

  function setupProfile() {
    var form = document.querySelector("[data-profile-form]");
    if (!form) {
      return;
    }
    var endpoint = form.getAttribute("data-endpoint");
    var statusEl = form.querySelector("[data-profile-status]");
    var submit = form.querySelector("button[type=submit]");
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var data = new FormData(form);
      if (submit) {
        submit.disabled = true;
      }
      setStatus(statusEl, "Đang lưu…");
      postForm(endpoint, {
        action: "ChangeProfile",
        token: (data.get("token") || "").toString(),
        phone: (data.get("phone") || "").toString(),
        fullname: (data.get("fullname") || "").toString(),
        telegram_chat_id: (data.get("telegram_chat_id") || "").toString()
      }).then(function (response) {
        setStatus(statusEl, response.msg || "", response.status === "success" ? "ok" : "error");
        if (submit) {
          submit.disabled = false;
        }
      }).catch(function () {
        setStatus(statusEl, "Không kết nối được máy chủ. Vui lòng thử lại.", "error");
        if (submit) {
          submit.disabled = false;
        }
      });
    });
  }

  /* --- copy buttons ------------------------------------------------------- */

  function setupCopy() {
    document.querySelectorAll("[data-copy]").forEach(function (button) {
      button.addEventListener("click", function () {
        var input = document.querySelector(button.getAttribute("data-copy"));
        if (!input) {
          return;
        }
        input.select();
        if (navigator.clipboard) {
          navigator.clipboard.writeText(input.value).then(function () {
            var old = button.textContent;
            button.textContent = "Đã chép";
            window.setTimeout(function () { button.textContent = old; }, 1500);
          });
        }
      });
    });
  }

  ready(function () {
    setupMenu();
    setupDialogs();
    setupNotice();
    setupGallery();
    setupBuy();
    setupAuth();
    setupProfile();
    setupCopy();
  });
})();
