<?php
/*
 * Emoji / symbol picker — Orbit v3.
 *
 * The legacy page embedded https://www.smileysapp.com/emojiPicker/ in an iframe. This is a local grid:
 * pick a group, click a symbol, it is copied to the clipboard. No network request, no third-party frame.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

if ((string) $CMSNT->site("status_menu_tools") !== "1") {
    redirect(BASE_URL(""));
}

require_once __DIR__ . "/../common/store-shell.php";

$storeGroups = array(
    "Mặt cười" => array("😀", "😁", "😂", "🤣", "😊", "😍", "😘", "😎", "🤔", "😅", "😭", "😡",
        "🥳", "😴", "🤯", "🫡", "🙃", "😇", "🤩", "😬"),
    "Cử chỉ" => array("👍", "👎", "👏", "🙏", "💪", "✌️", "🤝", "👌", "🤞", "👋", "🫶", "☝️",
        "✍️", "🫰", "🤙", "👊"),
    "Biểu tượng" => array("❤️", "🔥", "⭐", "✅", "❌", "⚡", "💎", "🎯", "📌", "🔔", "💡", "🚀",
        "🏆", "🎁", "💰", "📦", "⏰", "🛡️", "🎉", "✨"),
    "Mũi tên" => array("→", "←", "↑", "↓", "↔", "⇢", "➜", "➤", "▸", "◂", "⤴", "⤵", "⇄", "⇒"),
);

$storeShell = array(
    "title" => __("Biểu tượng cảm xúc") . " | " . $CMSNT->site("title"),
    "desc" => __("Bảng biểu tượng để chép nhanh vào bài đăng, tin nhắn."),
    "nav" => store_nav_default($CMSNT),
    "body_class" => "page-content",
);
store_shell_open($storeShell);
?>
<section class="section section--tight" aria-labelledby="emoji-title">
  <div class="container">
    <div class="page-head">
      <div>
        <p class="eyebrow">Công cụ</p>
        <h1 id="emoji-title">Biểu tượng cảm xúc</h1>
        <p class="muted m0">Bấm vào biểu tượng để chép. Bảng chạy ngay trên máy bạn, không tải gì từ bên ngoài.</p>
      </div>
      <div class="page-head__aside">
        <p class="small muted m0" data-emoji-status role="status" aria-live="polite"></p>
      </div>
    </div>

    <?php $storeGroupIndex = 0; foreach ($storeGroups as $storeGroupName => $storeEmojis) { $storeGroupIndex++; ?>
    <div class="panel<?php echo $storeGroupIndex > 1 ? " mt-4" : ""; ?>">
      <div class="panel__head">
        <h2 class="panel__title"><?php echo store_e($storeGroupName); ?></h2>
        <span class="pill"><?php echo count($storeEmojis); ?></span>
      </div>
      <div class="panel__body">
        <div class="emoji-grid">
          <?php foreach ($storeEmojis as $storeEmoji) { ?>
          <button type="button" data-emoji="<?php echo store_e($storeEmoji); ?>"
            aria-label="Chép <?php echo store_e($storeEmoji); ?>"><?php echo store_e($storeEmoji); ?></button>
          <?php } ?>
        </div>
      </div>
    </div>
    <?php } ?>
  </div>
</section>
<script>
(function () {
  "use strict";
  var status = document.querySelector("[data-emoji-status]");
  /* Fallback for browsers without the Clipboard API: a visually hidden textarea, styled by .sr-only
     (no inline styles, so the page keeps a strict style-src policy open). */
  var field = document.createElement("textarea");
  field.setAttribute("readonly", "");
  field.className = "sr-only";
  document.body.appendChild(field);
  document.querySelectorAll("[data-emoji]").forEach(function (button) {
    button.addEventListener("click", function () {
      var value = button.getAttribute("data-emoji");
      field.value = value;
      field.select();
      var done = function () {
        if (status) {
          status.textContent = "Đã chép " + value;
        }
      };
      if (navigator.clipboard) {
        navigator.clipboard.writeText(value).then(done);
      } else {
        document.execCommand("copy");
        done();
      }
    });
  });
})();
</script>
<?php
store_shell_close();
