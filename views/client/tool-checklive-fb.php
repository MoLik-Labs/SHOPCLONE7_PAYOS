<?php
/*
 * Facebook UID live-check — Orbit v3.
 *
 * The check itself is unchanged: the browser asks Facebook for a profile picture and reads the size in
 * the redirect. That is a heuristic, not proof of an active account, and the page says so. Rebuilt on
 * the shared shell; it no longer pulls clipboard.js from a CDN (the copy buttons use the Clipboard API).
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

if ((string) $CMSNT->site("status_menu_tools") !== "1") {
    redirect(BASE_URL(""));
}

require_once __DIR__ . "/../common/store-shell.php";

$storeShell = array(
    "title" => __("Check live UID Facebook") . " | " . $CMSNT->site("title"),
    "desc" => __("Kiểm tra nhanh một danh sách UID Facebook còn ảnh đại diện hay không."),
    "nav" => store_nav_default($CMSNT),
    "body_class" => "page-content",
);
store_shell_open($storeShell);
?>
<section class="section section--tight" aria-labelledby="live-title">
  <div class="container">
    <div class="page-head">
      <div>
        <p class="eyebrow">Công cụ</p>
        <h1 id="live-title">Check live UID Facebook</h1>
        <p class="muted m0">Dán mỗi UID một dòng. Kết quả là ước lượng từ ảnh đại diện công khai, không phải xác nhận tài khoản còn hoạt động.</p>
      </div>
    </div>

    <div class="grid grid--2">
      <div class="panel">
        <div class="panel__head">
          <h2 class="panel__title">Danh sách UID</h2>
          <button class="btn btn--ghost" type="button" data-live-clear>Xoá</button>
        </div>
        <div class="panel__body">
          <label class="field__label" for="live-input">Mỗi dòng một UID (hoặc chuỗi <code>uid|mật khẩu</code>)</label>
          <textarea class="textarea" id="live-input" rows="10" spellcheck="false"
            placeholder="100001234567890"></textarea>
          <div class="hero__actions mt-4">
            <button class="btn btn--primary" type="button" data-live-run>Bắt đầu</button>
            <span class="small muted" data-live-progress role="status" aria-live="polite"></span>
          </div>
          <p class="small muted mt-4">
            Mỗi lần kiểm tra, trình duyệt của bạn gọi thẳng tới Facebook. Shop không thấy và không lưu UID.
          </p>
        </div>
      </div>

      <div class="panel">
        <div class="panel__head">
          <h2 class="panel__title">Kết quả</h2>
          <span class="pill" data-live-total>0</span>
        </div>
        <div class="panel__body">
          <div class="stats">
            <div class="stat">
              <span class="stat__label">Live</span>
              <strong class="stat__value" data-live-ok>0</strong>
            </div>
            <div class="stat">
              <span class="stat__label">Dead</span>
              <strong class="stat__value" data-live-dead>0</strong>
            </div>
          </div>
          <div class="copy-row mt-4">
            <label class="sr-only" for="live-out">Kết quả</label>
            <textarea class="textarea" id="live-out" rows="10" readonly></textarea>
            <button class="btn btn--ghost" type="button" data-copy="#live-out">Sao chép</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
<script>
(function () {
  "use strict";
  var input = document.getElementById("live-input");
  var output = document.getElementById("live-out");
  var progress = document.querySelector("[data-live-progress]");
  var okEl = document.querySelector("[data-live-ok]");
  var deadEl = document.querySelector("[data-live-dead]");
  var totalEl = document.querySelector("[data-live-total]");
  var run = document.querySelector("[data-live-run]");
  var clear = document.querySelector("[data-live-clear]");
  var running = false;

  function uidOf(line) {
    return line.split("|")[0].trim();
  }

  function checkOne(uid) {
    var url = "https://graph.facebook.com/" + encodeURIComponent(uid) + "/picture?type=normal";
    return fetch(url, { mode: "no-cors" }).then(function (response) {
      return { uid: uid, alive: /100x100/.test(response.url || "") };
    }).catch(function () {
      return { uid: uid, alive: false };
    });
  }

  run.addEventListener("click", function () {
    if (running) {
      return;
    }
    var lines = Array.from(new Set(input.value.split(/\r?\n/).map(function (line) {
      return line.trim();
    }).filter(Boolean)));
    if (!lines.length) {
      progress.textContent = "Chưa có UID nào.";
      return;
    }
    running = true;
    run.disabled = true;
    var live = [];
    var dead = [];
    var done = 0;
    progress.textContent = "Đang kiểm tra…";
    totalEl.textContent = String(lines.length);

    function step() {
      if (done >= lines.length) {
        output.value = live.concat(dead).join("\n");
        progress.textContent = "Xong " + done + " UID.";
        running = false;
        run.disabled = false;
        return;
      }
      var line = lines[done];
      done++;
      checkOne(uidOf(line)).then(function (result) {
        if (result.alive) {
          live.push(line);
          okEl.textContent = String(live.length);
        } else {
          dead.push(line);
          deadEl.textContent = String(dead.length);
        }
        progress.textContent = done + "/" + lines.length;
        step();
      });
    }
    step();
  });

  clear.addEventListener("click", function () {
    input.value = "";
    output.value = "";
    okEl.textContent = "0";
    deadEl.textContent = "0";
    totalEl.textContent = "0";
    progress.textContent = "";
  });
})();
</script>
<?php
store_shell_close();
