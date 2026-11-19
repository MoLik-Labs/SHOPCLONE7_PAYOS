<?php
/*
 * TOTP generator — Orbit v3.
 *
 * The legacy page embedded https://2fa.zone/ in an iframe, which handed the customer's secret to a
 * third-party site. This one implements RFC 6238 in the browser with WebCrypto: the key never leaves
 * the page, there is no network request at all, and it works offline.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

if ((string) $CMSNT->site("status_menu_tools") !== "1") {
    redirect(BASE_URL(""));
}

require_once __DIR__ . "/../common/store-shell.php";

$storeShell = array(
    "title" => __("Tạo mã 2FA") . " | " . $CMSNT->site("title"),
    "desc" => __("Tạo mã 6 số từ khoá Google Authenticator, ngay trong trình duyệt."),
    "nav" => store_nav_default($CMSNT),
    "body_class" => "page-content",
);
store_shell_open($storeShell);
?>
<section class="section section--tight" aria-labelledby="totp-title">
  <div class="container container--narrow">
    <div class="page-head">
      <div>
        <p class="eyebrow">Công cụ</p>
        <h1 id="totp-title">Tạo mã 2FA</h1>
        <p class="muted m0">Dán khoá bí mật (chuỗi Base32 trong ứng dụng xác thực) để xem mã hiện tại.</p>
      </div>
    </div>

    <div class="panel">
      <div class="panel__body">
        <form data-totp-form novalidate>
          <div class="field">
            <label class="field__label" for="totp-secret">Khoá bí mật</label>
            <input class="input" id="totp-secret" name="secret" type="text" autocomplete="off"
              autocapitalize="characters" spellcheck="false" placeholder="Ví dụ: JBSWY3DPEHPK3PXP">
            <p class="field__hint">
              Chỉ nhập nếu bạn hiểu rủi ro. Khoá không rời khỏi trang này — mã được tính bằng
              <code>crypto.subtle</code> trong trình duyệt.
            </p>
            <p class="field__error" data-error-for="secret" hidden></p>
          </div>
          <button class="btn btn--primary" type="submit">Tạo mã</button>
        </form>

        <div class="totp" data-totp-result hidden>
          <p class="stat__label">Mã hiện tại</p>
          <p class="totp__code" data-totp-code aria-live="polite">------</p>
          <div class="totp__meta">
            <span>Còn <strong data-totp-left>30</strong> giây</span>
            <span class="totp__bar"><span data-totp-bar></span></span>
          </div>
          <button class="btn btn--ghost" type="button" data-copy-value="" data-totp-copy>Sao chép mã</button>
          <p class="totp__next">Mã tiếp theo: <code data-totp-next>------</code></p>
        </div>
      </div>
    </div>

    <div class="panel mt-4">
      <div class="panel__body prose">
        <h2 class="panel__title">Dùng thế nào</h2>
        <ol>
          <li>Mở ứng dụng xác thực, chọn tài khoản, bấm “Chuyển khoá” hoặc “Hiện khoá”.</li>
          <li>Sao chép chuỗi Base32 (chữ và số, không có dấu cách).</li>
          <li>Dán vào ô trên rồi bấm <strong>Tạo mã</strong>. Mã đổi mỗi 30 giây.</li>
        </ol>
        <p class="small muted">
          Công cụ dùng thuật toán TOTP chuẩn (SHA-1, 6 số, bước 30 giây) nên khớp với Google
          Authenticator, Authy và 1Password. Nếu mã không khớp, kiểm tra đồng hồ thiết bị đã đúng giờ.
        </p>
      </div>
    </div>
  </div>
</section>
<script>
/* RFC 6238 TOTP, SHA-1, 6 digits, 30-second step — computed locally. */
(function () {
  "use strict";
  var alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
  var form = document.querySelector("[data-totp-form]");
  var result = document.querySelector("[data-totp-result]");
  var codeEl = document.querySelector("[data-totp-code]");
  var nextEl = document.querySelector("[data-totp-next]");
  var leftEl = document.querySelector("[data-totp-left]");
  var barEl = document.querySelector("[data-totp-bar]");
  var copyEl = document.querySelector("[data-totp-copy]");
  var errorEl = document.querySelector('[data-error-for="secret"]');
  var key = null;
  var timer = 0;

  function base32(value) {
    value = value.toUpperCase().replace(/[\s-]/g, "").replace(/=+$/, "");
    var bits = 0;
    var buffer = 0;
    var bytes = [];
    for (var i = 0; i < value.length; i++) {
      var index = alphabet.indexOf(value.charAt(i));
      if (index < 0) {
        return null;
      }
      buffer = (buffer << 5) | index;
      bits += 5;
      if (bits >= 8) {
        bytes.push((buffer >>> (bits - 8)) & 255);
        bits -= 8;
      }
    }
    return bytes.length ? new Uint8Array(bytes) : null;
  }

  function counterBytes(counter) {
    var out = new Uint8Array(8);
    for (var i = 7; i >= 0; i--) {
      out[i] = counter & 255;
      counter = Math.floor(counter / 256);
    }
    return out;
  }

  function code(secretKey, step) {
    return window.crypto.subtle.importKey(
      "raw", secretKey, { name: "HMAC", hash: "SHA-1" }, false, ["sign"]
    ).then(function (imported) {
      return window.crypto.subtle.sign("HMAC", imported, counterBytes(step));
    }).then(function (signature) {
      var bytes = new Uint8Array(signature);
      var offset = bytes[bytes.length - 1] & 15;
      var number = ((bytes[offset] & 127) << 24) | (bytes[offset + 1] << 16)
        | (bytes[offset + 2] << 8) | bytes[offset + 3];
      return String(number % 1000000).padStart(6, "0");
    });
  }

  function tick() {
    if (!key) {
      return;
    }
    var step = Math.floor(Date.now() / 30000);
    var left = 30 - Math.floor((Date.now() % 30000) / 1000);
    leftEl.textContent = String(left);
    barEl.style.width = ((left / 30) * 100).toFixed(1) + "%";
    code(key, step).then(function (value) {
      codeEl.textContent = value.slice(0, 3) + " " + value.slice(3);
      copyEl.setAttribute("data-copy-value", value);
    });
    code(key, step + 1).then(function (value) {
      nextEl.textContent = value.slice(0, 3) + " " + value.slice(3);
    });
  }

  form.addEventListener("submit", function (event) {
    event.preventDefault();
    var secret = document.getElementById("totp-secret").value;
    var bytes = base32(secret);
    if (!bytes || bytes.length < 5) {
      errorEl.textContent = "Khoá chưa đúng: cần chuỗi Base32 (A–Z và 2–7), dài ít nhất 8 ký tự.";
      errorEl.hidden = false;
      result.hidden = true;
      return;
    }
    errorEl.hidden = true;
    key = bytes;
    result.hidden = false;
    window.clearInterval(timer);
    tick();
    timer = window.setInterval(tick, 1000);
  });
})();
</script>
<?php
store_shell_close();
