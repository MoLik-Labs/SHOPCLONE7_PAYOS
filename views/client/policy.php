<?php
/*
 * Policy / terms — the owner's `site('page_policy')` HTML on the store shell.
 * Raw admin-authored HTML, never visitor input.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . "/../common/store-shell.php";

$storeShell = array(
    "title" => __("Chính sách") . " | " . $CMSNT->site("title"),
    "desc" => $CMSNT->site("description"),
    "nav" => store_nav_default($CMSNT),
    "body_class" => "page-content",
);
store_shell_open($storeShell);

$storePolicy = (string) $CMSNT->site("page_policy");
?>
<section class="section section--tight" aria-labelledby="policy-title">
  <div class="container container--narrow">
    <div class="page-head">
      <div>
        <p class="eyebrow">Điều khoản</p>
        <h1 id="policy-title">Chính sách</h1>
        <p class="muted m0">Điều khoản mua hàng, bảo hành và hoàn tiền của shop.</p>
      </div>
    </div>

    <div class="panel">
      <div class="panel__body prose">
<?php if (trim($storePolicy) !== "") { ?>
        <?php echo $storePolicy; ?>
<?php } else { ?>
        <div class="empty">
          <h2 class="empty__title">Shop chưa đăng chính sách</h2>
          <p class="muted">Nội dung do quản trị viên soạn. Bạn vẫn có thể hỏi trực tiếp trước khi mua.</p>
          <p class="empty__actions"><a class="btn btn--primary" href="<?php echo BASE_URL("client/contact"); ?>">Liên hệ shop</a></p>
        </div>
<?php } ?>
      </div>
    </div>

    <p class="muted orders-foot">Cần làm rõ một điều khoản? <a href="<?php echo BASE_URL("client/contact"); ?>">Liên hệ shop</a>.</p>
  </div>
</section>
<?php
store_shell_close($storeShell);
