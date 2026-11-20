<?php
/*
 * FAQ — the owner's answer page (`site('page_faq')`) on the store shell.
 *
 * The content is raw HTML typed in the admin (tables, lists, links). It is not escaped here, exactly
 * like the legacy page: it is authored by an administrator, never by a visitor.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . "/../common/store-shell.php";

$storeShell = array(
    "title" => __("Câu hỏi thường gặp") . " | " . $CMSNT->site("title"),
    "desc" => $CMSNT->site("description"),
    "nav" => store_nav_default($CMSNT),
    "body_class" => "page-content",
);
store_shell_open($storeShell);

$storeFaq = (string) $CMSNT->site("page_faq");
?>
<section class="section section--tight" aria-labelledby="faq-title">
  <div class="container container--narrow">
    <div class="page-head">
      <div>
        <p class="eyebrow">Hỗ trợ</p>
        <h1 id="faq-title">Câu hỏi thường gặp</h1>
        <p class="muted m0">Những câu khách hỏi nhiều nhất về đơn hàng, bảo hành và thanh toán.</p>
      </div>
    </div>

    <div class="panel">
      <div class="panel__body prose">
<?php if (trim($storeFaq) !== "") { ?>
        <?php echo $storeFaq; ?>
<?php } else { ?>
        <div class="empty">
          <h2 class="empty__title">Shop chưa đăng câu hỏi nào</h2>
          <p class="muted">Phần này do quản trị viên soạn. Trong lúc chờ, bạn có thể liên hệ trực tiếp.</p>
          <p class="empty__actions"><a class="btn btn--primary" href="<?php echo BASE_URL("client/contact"); ?>">Liên hệ shop</a></p>
        </div>
<?php } ?>
      </div>
    </div>

    <p class="muted orders-foot">Chưa thấy câu trả lời? <a href="<?php echo BASE_URL("client/contact"); ?>">Liên hệ shop</a>.</p>
  </div>
</section>
<?php
store_shell_close($storeShell);
