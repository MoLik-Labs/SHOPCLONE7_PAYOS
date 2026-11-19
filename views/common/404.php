<?php
/*
 * 404 — Orbit v3.
 *
 * Reached through index.php when a module/action is missing, and for the reserved section names
 * (header, footer, nav, …) that are partials and must never render on their own. Rendering it on the
 * shared shell keeps the header, footer and typography identical to every other page; the old file
 * pulled Google fonts, two MaxCDN shims and a Cloudflare beacon from third-party hosts.
 */

if (!defined("IN_SITE")) {
    http_response_code(404);
    exit("The Request Not Found");
}

require_once __DIR__ . "/store-shell.php";

http_response_code(404);
$storeMethods = store_payment_methods($CMSNT);
$storeShell = array(
    "title" => __("Không tìm thấy trang") . " | " . $CMSNT->site("title"),
    "desc" => __("Trang bạn tìm có thể đã được đổi tên, chuyển đi hoặc tạm thời không mở."),
    "robots" => "noindex, follow",
    "nav" => store_nav_default($CMSNT, $storeMethods),
    "body_class" => "page-content",
);
store_shell_open($storeShell);
?>
<section class="section section--tight" aria-labelledby="notfound-title">
  <div class="container container--narrow">
    <p class="eyebrow">Lỗi 404</p>
    <h1 id="notfound-title">Không tìm thấy trang này</h1>
    <p class="muted measure">
      Có thể đường dẫn đã đổi tên, hoặc trang đã được chuyển đi. Bạn kiểm tra lại địa chỉ,
      hoặc bắt đầu lại từ những chỗ dưới đây.
    </p>

    <div class="hero__actions">
      <a class="btn btn--primary" href="<?php echo store_e(BASE_URL("")); ?>">Về trang chủ</a>
      <a class="btn btn--ghost" href="<?php echo store_e(BASE_URL("client/product")); ?>">Xem sản phẩm</a>
      <a class="btn btn--ghost" href="<?php echo store_e(BASE_URL("client/contact")); ?>">Liên hệ shop</a>
    </div>

    <div class="panel mt-4">
      <div class="panel__body">
        <h2 class="panel__title">Bạn đang tìm một đơn hàng?</h2>
        <p class="muted m0">
          Đăng nhập rồi mở <a href="<?php echo store_e(BASE_URL("client/orders")); ?>">Đơn hàng của tôi</a>
          — mã đơn nằm trong mục đó, kèm tài khoản đã giao. Nếu vẫn không thấy, gửi mã đơn cho shop qua
          trang liên hệ.
        </p>
      </div>
    </div>
  </div>
</section>
<?php
store_shell_close();
