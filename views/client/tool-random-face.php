<?php
/*
 * Random face — Orbit v3.
 *
 * The image itself comes from thispersondoesnotexist.com, a third-party service. The legacy page put
 * it in a full-height iframe, which both cost a third-party connection on load and framed a site the
 * shop does not control. This page states what the service is and opens it in a new tab on request.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

if ((string) $CMSNT->site("status_menu_tools") !== "1") {
    redirect(BASE_URL(""));
}

require_once __DIR__ . "/../common/store-shell.php";

$storeShell = array(
    "title" => __("Ảnh khuôn mặt ngẫu nhiên") . " | " . $CMSNT->site("title"),
    "desc" => __("Mở dịch vụ ảnh khuôn mặt do AI tạo, mỗi lần tải là một người khác."),
    "nav" => store_nav_default($CMSNT),
    "body_class" => "page-content",
);
store_shell_open($storeShell);
?>
<section class="section section--tight" aria-labelledby="face-title">
  <div class="container container--narrow">
    <div class="page-head">
      <div>
        <p class="eyebrow">Công cụ</p>
        <h1 id="face-title">Ảnh khuôn mặt ngẫu nhiên</h1>
        <p class="muted m0">Dịch vụ bên ngoài: mỗi lần tải trang là một khuôn mặt do AI tạo, không phải người thật.</p>
      </div>
    </div>

    <div class="panel">
      <div class="panel__body prose">
        <p>
          Ảnh do <strong>thispersondoesnotexist.com</strong> tạo bằng mô hình sinh ảnh. Shop không
          lưu, không kiểm duyệt và không bảo đảm nội dung của dịch vụ đó.
        </p>
        <p class="small muted">
          Trang này không nhúng khung của dịch vụ. Khi bạn bấm nút dưới đây, một tab mới mở ra và chỉ
          lúc đó trình duyệt mới kết nối tới máy chủ của họ.
        </p>
        <p>
          <a class="btn btn--primary" href="https://thispersondoesnotexist.com/" target="_blank"
            rel="noopener noreferrer nofollow">Mở trong tab mới</a>
          <a class="btn btn--ghost" href="<?php echo store_e(BASE_URL("client/tools")); ?>">Về danh sách công cụ</a>
        </p>
      </div>
    </div>
  </div>
</section>
<?php
store_shell_close();
