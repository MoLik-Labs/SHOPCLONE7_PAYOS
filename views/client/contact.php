<?php
/*
 * Contact — Orbit v3.
 *
 * The owner-authored contact block (`site('page_contact')`, raw HTML by design) plus the three
 * settings the shop already publishes. When a field is empty it is not printed at all — no placeholder
 * phone number or invented address. Visible to guests and customers alike.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . "/../common/store-shell.php";

$storeHotline = trim((string) $CMSNT->site("hotline"));
$storeEmail = trim((string) $CMSNT->site("email"));
$storeAddress = trim((string) $CMSNT->site("address"));
$storeContent = (string) $CMSNT->site("page_contact");
$storeMethods = store_payment_methods($CMSNT);

$storeShell = array(
    "title" => __("Liên hệ") . " | " . $CMSNT->site("title"),
    "desc" => $CMSNT->site("description"),
    "nav" => store_nav_default($CMSNT, $storeMethods),
    "body_class" => "page-content",
);
store_shell_open($storeShell);
?>
<section class="section section--tight" aria-labelledby="contact-title">
  <div class="container container--narrow">
    <div class="page-head">
      <div>
        <p class="eyebrow">Hỗ trợ</p>
        <h1 id="contact-title">Liên hệ shop</h1>
        <p class="muted m0">Gửi mã đơn kèm mô tả để được kiểm tra nhanh nhất.</p>
      </div>
    </div>

    <div class="stats">
<?php if ($storeHotline !== "") { ?>
      <div class="stat stat--accent">
        <p class="stat__label">Hotline</p>
        <p class="stat__value stat__value--sm"><a href="tel:<?php echo store_e(preg_replace('/[^0-9+]/', "", $storeHotline)); ?>"><?php echo store_e($storeHotline); ?></a></p>
        <p class="stat__foot">Gọi trực tiếp theo giờ shop mở</p>
      </div>
<?php } ?>
<?php if ($storeEmail !== "") { ?>
      <div class="stat">
        <p class="stat__label">Email</p>
        <p class="stat__value stat__value--sm"><a href="mailto:<?php echo store_e($storeEmail); ?>"><?php echo store_e($storeEmail); ?></a></p>
        <p class="stat__foot">Phù hợp khi cần gửi ảnh hoặc hoá đơn</p>
      </div>
<?php } ?>
<?php if ($storeAddress !== "") { ?>
      <div class="stat">
        <p class="stat__label">Địa chỉ</p>
        <p class="stat__value stat__value--sm"><?php echo store_e($storeAddress); ?></p>
        <p class="stat__foot">Theo thông tin shop đã đăng</p>
      </div>
<?php } ?>
    </div>

    <div class="panel">
      <div class="panel__head">
        <h2 class="panel__title">Thông tin shop công bố</h2>
      </div>
      <div class="panel__body prose">
<?php if (trim($storeContent) !== "") { ?>
        <?php echo $storeContent; ?>
<?php } else { ?>
        <div class="empty">
          <h3 class="empty__title">Shop chưa đăng nội dung liên hệ</h3>
          <p class="muted">Dùng hotline hoặc email ở trên nếu có. Phần này do quản trị viên soạn.</p>
        </div>
<?php } ?>
      </div>
    </div>

    <p class="muted orders-foot">Câu trả lời nhanh có trong <a href="<?php echo BASE_URL("client/faq"); ?>">câu hỏi thường gặp</a> và <a href="<?php echo BASE_URL("client/policy"); ?>">chính sách mua hàng</a>.</p>
  </div>
</section>
<?php
store_shell_close($storeShell);
