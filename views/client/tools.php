<?php
/*
 * Tools index — Orbit v3.
 *
 * Four small utilities the shop offers customers. Everything runs in the browser or on this server;
 * no page on the list loads a third-party iframe. The owner switches the whole area with
 * `status_menu_tools` (off by default).
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

if ((string) $CMSNT->site("status_menu_tools") !== "1") {
    redirect(BASE_URL(""));
}

require_once __DIR__ . "/../common/store-shell.php";

$storeTools = array(
    array("title" => "Mã 2FA", "href" => BASE_URL("tool/get-2fa"),
        "body" => "Tạo mã 6 số từ khoá bí mật Google Authenticator. Khoá chỉ nằm trong trình duyệt của bạn."),
    array("title" => "Check live Facebook", "href" => BASE_URL("tool/check-live-facebook"),
        "body" => "Dán danh sách UID để xem nhanh UID nào còn ảnh đại diện."),
    array("title" => "Biểu tượng cảm xúc", "href" => BASE_URL("tool/icon-facebook"),
        "body" => "Bảng biểu tượng để chép vào bài đăng, tin nhắn."),
    array("title" => "Ảnh khuôn mặt ngẫu nhiên", "href" => BASE_URL("tool/random-face"),
        "body" => "Mở dịch vụ ảnh AI bên ngoài trong tab mới."),
);

$storeShell = array(
    "title" => __("Công cụ") . " | " . $CMSNT->site("title"),
    "desc" => __("Bốn công cụ nhỏ dùng miễn phí cho khách của shop."),
    "nav" => store_nav_default($CMSNT),
    "body_class" => "page-content",
);
store_shell_open($storeShell);
?>
<section class="section section--tight" aria-labelledby="tools-title">
  <div class="container">
    <div class="page-head">
      <div>
        <p class="eyebrow">Tiện ích</p>
        <h1 id="tools-title">Công cụ</h1>
        <p class="muted m0">Bốn công cụ nhỏ, miễn phí. Không công cụ nào gửi dữ liệu bạn nhập ra ngoài trừ khi bạn tự bấm.</p>
      </div>
    </div>

    <div class="grid grid--2">
      <?php foreach ($storeTools as $storeTool) { ?>
      <a class="card card--interactive" href="<?php echo store_e($storeTool["href"]); ?>">
        <div class="card__body">
          <h2 class="card__title"><?php echo store_e($storeTool["title"]); ?></h2>
          <p class="muted m0 mt-2"><?php echo store_e($storeTool["body"]); ?></p>
        </div>
      </a>
      <?php } ?>
    </div>

    <p class="small muted mt-4">
      Công cụ 2FA chạy hoàn toàn trong trình duyệt: khoá bí mật không được gửi lên máy chủ và không
      được ghi lại. Nếu bạn nghi ngờ khoá đã lộ, hãy tạo lại khoá trong ứng dụng xác thực.
    </p>
  </div>
</section>
<?php
store_shell_close();
