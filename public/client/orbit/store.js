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

  /* --- API key rotation --------------------------------------------------- */

  function setupApiKey() {
    var button = document.querySelector("[data-api-key-rotate]");
    if (!button) {
      return;
    }
    button.addEventListener("click", function () {
      if (button.disabled) {
        return;
      }
      if (!window.confirm("Đổi khoá API? Khoá cũ ngừng hoạt động ngay và mọi tích hợp phải cập nhật khoá mới.")) {
        return;
      }
      var endpoint = button.getAttribute("data-endpoint") || "";
      var label = button.textContent;
      button.disabled = true;
      button.textContent = "Đang đổi…";
      postForm(endpoint, { action: "changeAPIKey" }).then(function (response) {
        if (response && response.status === "success") {
          button.textContent = "Đã đổi khoá";
          window.setTimeout(function () {
            window.location.reload();
          }, 700);
          return;
        }
        button.disabled = false;
        button.textContent = label;
        window.alert((response && response.msg) || "Không đổi được khoá API. Vui lòng thử lại.");
      }, function () {
        button.disabled = false;
        button.textContent = label;
        window.alert("Không kết nối được máy chủ. Vui lòng thử lại.");
      });
    });
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

  /* --- second-step sign-in (2FA / e-mail OTP) ----------------------------- */

  function setupVerify() {
    var form = document.querySelector("[data-verify-form]");
    if (!form) {
      return;
    }
    var endpoint = form.getAttribute("data-endpoint");
    var action = form.getAttribute("data-action");
    var redirect = form.getAttribute("data-redirect") || "/";
    var tokenField = form.querySelector('input[type="hidden"][name^="token_"]');
    var tokenInput = form.querySelector('[name="code"]');
    var statusEl = form.querySelector("[data-verify-status]");
    var submit = form.querySelector("button[type=submit]");

    if (tokenInput) {
      /* One-time codes are digits; keep a paste of "123 456" working, drop everything else. */
      tokenInput.addEventListener("input", function () {
        var clean = tokenInput.value.replace(/\D/g, "").slice(0, Number(tokenInput.maxLength) || 8);
        if (clean !== tokenInput.value) {
          tokenInput.value = clean;
        }
      });
    }

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var code = tokenInput ? tokenInput.value.trim() : "";
      var errorEl = form.querySelector('[data-error-for="code"]');
      setStatus(statusEl, "");
      if (errorEl) {
        errorEl.textContent = "";
        errorEl.hidden = true;
      }
      if (!code) {
        if (errorEl) {
          errorEl.textContent = "Vui lòng nhập mã xác minh.";
          errorEl.hidden = false;
        }
        if (tokenInput) {
          tokenInput.setAttribute("aria-invalid", "true");
          tokenInput.focus();
        }
        return;
      }
      if (tokenInput) {
        tokenInput.removeAttribute("aria-invalid");
      }

      var payload = { action: action, code: code, recaptcha: recaptchaValue() };
      if (tokenField) {
        payload[tokenField.name] = tokenField.value;
      }
      if (submit) {
        submit.disabled = true;
        submit.textContent = "Đang xác minh…";
      }
      postForm(endpoint, payload).then(function (response) {
        if (response.status === "success") {
          setStatus(statusEl, response.msg || "Xác minh thành công.", "ok");
          window.setTimeout(function () {
            window.location.href = redirect;
          }, 700);
          return;
        }
        if (response.status === "verify" && response.url) {
          window.location.href = response.url;
          return;
        }
        setStatus(statusEl, response.msg || "Mã chưa đúng. Vui lòng thử lại.", "error");
        if (submit) {
          submit.disabled = false;
          submit.textContent = "Xác minh";
        }
      }).catch(function () {
        setStatus(statusEl, "Không kết nối được máy chủ. Vui lòng thử lại.", "error");
        if (submit) {
          submit.disabled = false;
          submit.textContent = "Xác minh";
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

  /* --- account: favourites, password, security, password reset ------------ */

  /* One submit helper: disables the button, posts the named action, prints the server's sentence. */
  function bindAccountForm(selector, statusSelector, action, build) {
    var form = document.querySelector(selector);
    if (!form) {
      return;
    }
    var endpoint = form.getAttribute("data-endpoint");
    var statusEl = form.querySelector(statusSelector);
    var submit = form.querySelector("button[type=submit]");
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var data = new FormData(form);
      var fields = build(data);
      fields.action = action;
      if (submit) {
        submit.disabled = true;
      }
      setStatus(statusEl, "Đang lưu…");
      postForm(endpoint, fields).then(function (response) {
        setStatus(statusEl, response.msg || "", response.status === "success" ? "ok" : "error");
        if (response.status === "success" && form.getAttribute("data-redirect")) {
          window.setTimeout(function () {
            window.location.href = form.getAttribute("data-redirect");
          }, 1200);
        }
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

  function value(data, name) {
    return (data.get(name) || "").toString();
  }

  function setupAccountForms() {
    bindAccountForm("[data-password-form]", "[data-password-status]", "ChangePasswordProfile", function (data) {
      return {
        token: value(data, "token"),
        password: value(data, "password"),
        newpassword: value(data, "newpassword"),
        renewpassword: value(data, "renewpassword")
      };
    });

    bindAccountForm("[data-security-form]", "[data-security-status]", "changeSecurity", function (data) {
      var form = document.querySelector("[data-security-form]");
      function on(name) {
        var field = form && form.querySelector('[name="' + name + '"]');
        return field && field.checked ? "1" : "0";
      }
      return {
        token: value(data, "token"),
        status_otp_mail: on("status_otp_mail"),
        status_noti_login_to_mail: on("status_noti_login_to_mail"),
        status_view_order: on("status_view_order")
      };
    });

    bindAccountForm("[data-2fa-form]", "[data-2fa-status]", "Save2FA", function (data) {
      var form = document.querySelector("[data-2fa-form]");
      var toggle = form && form.querySelector('[name="status_2fa"]');
      return {
        token: value(data, "token"),
        secret: value(data, "secret"),
        status_2fa: toggle && toggle.checked ? "1" : "0"
      };
    });

    bindAccountForm("[data-forgot-form]", "[data-forgot-status]", "ForgotPassword", function (data) {
      return { email: value(data, "email") };
    });

    bindAccountForm("[data-reset-form]", "[data-reset-status]", "ChangePassword", function (data) {
      return {
        token: value(data, "token"),
        newpassword: value(data, "newpassword"),
        renewpassword: value(data, "renewpassword")
      };
    });

    /* Favourites: the remove button sits in a table row; the token is in a hidden form on the page. */
    var favoritesForm = document.querySelector("[data-favorites-form]");
    if (favoritesForm) {
      var tokenField = favoritesForm.querySelector('[name="token"]');
      var favoritesStatus = document.querySelector("[data-favorites-status]");
      document.querySelectorAll("[data-favorite-remove]").forEach(function (button) {
        button.addEventListener("click", function () {
          button.disabled = true;
          setStatus(favoritesStatus, "Đang bỏ lưu…");
          postForm(favoritesForm.getAttribute("data-endpoint"), {
            action: "removeFavorite",
            id: button.getAttribute("data-favorite-remove"),
            token: tokenField ? tokenField.value : ""
          }).then(function (response) {
            if (response.status === "success") {
              var row = button.closest("tr");
              if (row) {
                row.parentNode.removeChild(row);
              }
              setStatus(favoritesStatus, response.msg || "Đã bỏ lưu.", "ok");
            } else {
              setStatus(favoritesStatus, response.msg || "Không bỏ lưu được.", "error");
              button.disabled = false;
            }
          }).catch(function () {
            setStatus(favoritesStatus, "Không kết nối được máy chủ. Vui lòng thử lại.", "error");
            button.disabled = false;
          });
        });
      });
    }
  }

  /* --- affiliate withdrawal ----------------------------------------------- */

  function setupWithdraw() {
    var form = document.querySelector("[data-withdraw-form]");
    if (!form) {
      return;
    }
    var endpoint = form.getAttribute("data-endpoint");
    var statusEl = form.querySelector("[data-withdraw-status]");
    var submit = form.querySelector("button[type=submit]");

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var data = new FormData(form);
      var fields = {};
      ["bank", "stk", "name", "amount", "csrf_token"].forEach(function (name) {
        fields[name] = (data.get(name) || "").toString().trim();
      });
      setStatus(statusEl, "");
      var missing = "";
      if (!fields.bank) {
        missing = "Vui lòng chọn ngân hàng nhận tiền.";
      } else if (!fields.stk) {
        missing = "Vui lòng nhập số tài khoản.";
      } else if (!fields.name) {
        missing = "Vui lòng nhập tên chủ tài khoản.";
      } else if (!/^[0-9]+$/.test(fields.amount) || Number(fields.amount) <= 0) {
        missing = "Số tiền chỉ gồm chữ số và lớn hơn 0.";
      }
      if (missing) {
        setStatus(statusEl, missing, "error");
        return;
      }
      if (submit) {
        submit.disabled = true;
        submit.textContent = "Đang gửi…";
      }
      postForm(endpoint, {
        action: "WithdrawCommission",
        csrf_token: fields.csrf_token,
        bank: fields.bank,
        stk: fields.stk,
        name: fields.name,
        amount: fields.amount
      }).then(function (response) {
        var ok = response.status === "success";
        setStatus(statusEl, response.msg || (ok ? "Đã gửi yêu cầu." : "Không thể gửi yêu cầu."), ok ? "ok" : "error");
        if (ok) {
          window.setTimeout(function () {
            window.location.reload();
          }, 900);
        } else if (submit) {
          submit.disabled = false;
          submit.textContent = "Gửi yêu cầu";
        }
      }).catch(function () {
        setStatus(statusEl, "Không kết nối được máy chủ. Vui lòng thử lại.", "error");
        if (submit) {
          submit.disabled = false;
          submit.textContent = "Gửi yêu cầu";
        }
      });
    });
  }

  /* --- order history ------------------------------------------------------ */

  function downloadText(filename, text) {
    var blob = new Blob([text], { type: "text/plain;charset=utf-8" });
    var url = URL.createObjectURL(blob);
    var link = document.createElement("a");
    link.href = url;
    link.download = filename || "don-hang.txt";
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.setTimeout(function () {
      URL.revokeObjectURL(url);
    }, 1000);
  }

  function setupOrders() {
    var root = document.querySelector("[data-orders]");
    if (!root) {
      return;
    }
    var token = root.getAttribute("data-token") || "";
    var statusEl = root.querySelector("[data-orders-status]");
    var updateUrl = root.getAttribute("data-update-endpoint");
    var removeUrl = root.getAttribute("data-remove-endpoint");
    var viewUrl = root.getAttribute("data-view-endpoint");
    var allBox = root.querySelector("[data-orders-all]");
    var bulkDelete = root.querySelector("[data-orders-delete]");
    var checks = function () {
      return Array.prototype.slice.call(root.querySelectorAll("[data-order-check]"));
    };
    var selected = function () {
      return checks().filter(function (box) {
        return box.checked;
      });
    };
    var syncBulk = function () {
      if (bulkDelete) {
        bulkDelete.disabled = selected().length === 0;
      }
    };
    if (allBox) {
      allBox.addEventListener("change", function () {
        checks().forEach(function (box) {
          box.checked = allBox.checked;
        });
        syncBulk();
      });
    }
    root.addEventListener("change", function (event) {
      if (event.target && event.target.hasAttribute("data-order-check")) {
        syncBulk();
      }
    });

    root.querySelectorAll("[data-order-note]").forEach(function (input) {
      var previous = input.value;
      input.addEventListener("change", function () {
        var id = input.getAttribute("data-order-note");
        setStatus(statusEl, "Đang lưu ghi chú…");
        postForm(updateUrl, { action: "saveNoteOrder", id: id, note: input.value, token: token })
          .then(function (response) {
            if (response.status === "success") {
              previous = input.value;
              setStatus(statusEl, "Đã lưu ghi chú.", "ok");
            } else {
              input.value = previous;
              setStatus(statusEl, response.msg || "Không lưu được ghi chú.", "error");
            }
          })
          .catch(function () {
            input.value = previous;
            setStatus(statusEl, "Không kết nối được máy chủ. Ghi chú chưa được lưu.", "error");
          });
      });
    });

    root.addEventListener("click", function (event) {
      var button = event.target.closest("button");
      if (!button) {
        return;
      }
      if (button.hasAttribute("data-order-download")) {
        var trans = button.getAttribute("data-order-download");
        button.disabled = true;
        postForm(viewUrl, { action: "download_order", trans_id: trans, token: token })
          .then(function (response) {
            button.disabled = false;
            if (response.status === "success" && response.accounts != null) {
              downloadText(response.filename || trans + ".txt", response.accounts);
              setStatus(statusEl, "Đã tải đơn hàng.", "ok");
            } else {
              setStatus(statusEl, response.msg || "Không tải được đơn hàng.", "error");
            }
          })
          .catch(function () {
            button.disabled = false;
            setStatus(statusEl, "Không kết nối được máy chủ. Vui lòng thử lại.", "error");
          });
      }
      if (button.hasAttribute("data-order-remove")) {
        var id = button.getAttribute("data-order-remove");
        if (!window.confirm("Xóa đơn này khỏi lịch sử của bạn? Nội dung tài khoản sẽ không mở lại được.")) {
          return;
        }
        button.disabled = true;
        postForm(removeUrl, { action: "removeOrder", id: id, token: token })
          .then(function (response) {
            if (response.status === "success") {
              window.location.reload();
              return;
            }
            button.disabled = false;
            setStatus(statusEl, response.msg || "Không xóa được đơn hàng.", "error");
          })
          .catch(function () {
            button.disabled = false;
            setStatus(statusEl, "Không kết nối được máy chủ. Vui lòng thử lại.", "error");
          });
      }
      if (button.hasAttribute("data-orders-delete")) {
        var chosen = selected();
        if (!chosen.length) {
          return;
        }
        if (!window.confirm("Xóa " + chosen.length + " đơn đã chọn khỏi lịch sử?")) {
          return;
        }
        setStatus(statusEl, "Đang xóa…");
        var chain = Promise.resolve();
        chosen.forEach(function (box) {
          chain = chain.then(function () {
            return postForm(removeUrl, { action: "removeOrder", id: box.value, token: token });
          });
        });
        chain
          .then(function () {
            window.location.reload();
          })
          .catch(function () {
            setStatus(statusEl, "Có đơn chưa xóa được. Tải lại trang để xem kết quả.", "error");
          });
      }
    });
  }

  function setupOrderDetail() {
    var root = document.querySelector("[data-order-detail]");
    if (!root) {
      return;
    }
    var token = root.getAttribute("data-token") || "";
    var viewUrl = root.getAttribute("data-view-endpoint");
    var removeUrl = root.getAttribute("data-remove-endpoint");
    var trans = root.getAttribute("data-trans") || "";
    var pageStatus = root.querySelector("[data-accounts-status]");
    var allBox = root.querySelector("[data-accounts-all]");
    var copyButton = root.querySelector("[data-accounts-copy]");
    var uidButton = root.querySelector("[data-accounts-uids]");
    var downloadButton = root.querySelector("[data-accounts-download]");
    var checks = function () {
      return Array.prototype.slice.call(root.querySelectorAll("[data-account-check]"));
    };
    var selected = function () {
      return checks().filter(function (box) {
        return box.checked;
      });
    };
    var sync = function () {
      var count = selected().length;
      [copyButton, uidButton, downloadButton].forEach(function (button) {
        if (button) {
          button.disabled = count === 0;
        }
      });
    };
    if (allBox) {
      allBox.addEventListener("change", function () {
        checks().forEach(function (box) {
          box.checked = allBox.checked;
        });
        sync();
      });
    }
    root.addEventListener("change", function (event) {
      if (event.target && event.target.hasAttribute("data-account-check")) {
        sync();
      }
    });

    var copyText = function (text, okMessage) {
      if (!text) {
        return;
      }
      if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function () {
          setStatus(pageStatus, okMessage, "ok");
        });
        return;
      }
      downloadText("tai-khoan.txt", text);
      setStatus(pageStatus, "Trình duyệt không cho chép; tệp .txt đã được tải.", "ok");
    };

    if (copyButton) {
      copyButton.addEventListener("click", function () {
        copyText(selected().map(function (box) {
          return box.getAttribute("data-account");
        }).join("\n"), "Đã chép " + selected().length + " tài khoản.");
      });
    }
    if (uidButton) {
      uidButton.addEventListener("click", function () {
        copyText(selected().map(function (box) {
          return box.getAttribute("data-uid");
        }).join("\n"), "Đã chép " + selected().length + " UID.");
      });
    }
    if (downloadButton) {
      downloadButton.addEventListener("click", function () {
        var lines = selected().map(function (box) {
          return box.getAttribute("data-account");
        }).join("\n");
        downloadText(trans + "-" + selected().length + ".txt", lines);
        setStatus(pageStatus, "Đã tải " + selected().length + " tài khoản.", "ok");
      });
    }
    var copyAll = document.querySelector("[data-accounts-copy-all]");
    if (copyAll) {
      copyAll.addEventListener("click", function () {
        var everything = checks().map(function (box) {
          return box.getAttribute("data-account");
        }).join("\n");
        copyText(everything, "Đã chép tài khoản trên trang này.");
      });
    }

    var downloadAll = root.querySelector("[data-order-download]");
    if (downloadAll) {
      downloadAll.addEventListener("click", function () {
        downloadAll.disabled = true;
        postForm(viewUrl, { action: "download_order", trans_id: trans, token: token })
          .then(function (response) {
            downloadAll.disabled = false;
            if (response.status === "success" && response.accounts != null) {
              downloadText(response.filename || trans + ".txt", response.accounts);
              setStatus(pageStatus, "Đã tải toàn bộ đơn.", "ok");
            } else {
              setStatus(pageStatus, response.msg || "Không tải được đơn hàng.", "error");
            }
          })
          .catch(function () {
            downloadAll.disabled = false;
            setStatus(pageStatus, "Không kết nối được máy chủ. Vui lòng thử lại.", "error");
          });
      });
    }
    var removeButton = root.querySelector("[data-order-remove]");
    if (removeButton) {
      removeButton.addEventListener("click", function () {
        if (!window.confirm("Xóa đơn này khỏi lịch sử của bạn? Nội dung tài khoản sẽ không mở lại được.")) {
          return;
        }
        removeButton.disabled = true;
        postForm(removeUrl, {
          action: "removeOrder",
          id: root.getAttribute("data-order-id"),
          token: token
        }).then(function (response) {
          if (response.status === "success") {
            window.location.href = root.getAttribute("data-orders-url") || window.location.href;
            return;
          }
          removeButton.disabled = false;
          setStatus(pageStatus, response.msg || "Không xóa được đơn hàng.", "error");
        }).catch(function () {
          removeButton.disabled = false;
          setStatus(pageStatus, "Không kết nối được máy chủ. Vui lòng thử lại.", "error");
        });
      });
    }
  }

  /* --- copy buttons ------------------------------------------------------- */

  function setupCopy() {
    /* 2FA tool: the code changes every 30 s, so the button reads the live value at click time. */
    document.querySelectorAll("[data-totp-copy]").forEach(function (button) {
      button.addEventListener("click", function () {
        var literal = button.getAttribute("data-copy-value") || "";
        if (!literal || !navigator.clipboard) {
          return;
        }
        navigator.clipboard.writeText(literal).then(function () {
          var was = button.textContent;
          button.textContent = "Đã chép";
          window.setTimeout(function () { button.textContent = was; }, 1500);
        });
      });
    });

    document.querySelectorAll("[data-copy]").forEach(function (button) {
      button.addEventListener("click", function () {
        var literal = button.getAttribute("data-copy-value");
        if (literal !== null) {
          if (navigator.clipboard) {
            navigator.clipboard.writeText(literal).then(function () {
              var was = button.textContent;
              button.textContent = "Đã chép";
              window.setTimeout(function () { button.textContent = was; }, 1500);
            });
          }
          return;
        }
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
    setupVerify();
    setupProfile();
    setupAccountForms();
    setupWithdraw();
    setupOrders();
    setupOrderDetail();
    setupCopy();
    setupApiKey();
  });
})();
