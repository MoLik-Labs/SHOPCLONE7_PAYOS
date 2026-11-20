<?php
/*
 * Manual money-in — the owner's own instructions (bank transfer, wallet, …).
 *
 *   /recharge-manual/<slug>
 *
 * The row comes from `payment_manual` and its content is HTML written by the owner, with the same
 * {fanpage}/{email}/{hotline}/{id}/{username} placeholders as before. Only the frame is new: store shell,
 * balance, and a panel that lets the owner's content keep its own markup.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . '/../../libs/payos.php';
if (payos_config()['enabled']) {
    require __DIR__ . '/recharge-payos.php';
    return;
}

if (empty($_GET["slug"])) {
    redirect(base_url());
}
$slug = check_string($_GET["slug"]);
$payment_manual1 = $CMSNT->get_row(
    "SELECT * FROM `payment_manual` WHERE `slug` = '" . $slug . "' AND `display` = 1 "
);
if (!$payment_manual1) {
    redirect(base_url());
}

require_once __DIR__ . "/../../models/is_user.php";
require_once __DIR__ . "/../common/store-shell.php";

$content = base64_decode((string) $payment_manual1["content"]);
$content = str_replace("{fanpage}", (string) $CMSNT->site("fanpage"), $content);
$content = str_replace("{email}", (string) $CMSNT->site("email"), $content);
$content = str_replace("{hotline}", (string) $CMSNT->site("hotline"), $content);
$content = str_replace("{id}", (string) $getUser["id"], $content);
$content = str_replace("{username}", (string) $getUser["username"], $content);

$manualTitle = trim((string) $payment_manual1["title"]) !== ""
    ? (string) $payment_manual1["title"] : __("Nạp tiền");
$manualDesc = trim(strip_tags((string) $payment_manual1["description"]));

$storeShell = array(
    "title" => $manualTitle . " | " . $CMSNT->site("title"),
    "desc" => $manualDesc !== "" ? $manualDesc : $CMSNT->site("description"),
    "nav" => store_nav_default($CMSNT),
    "body_class" => "page-recharge",
    "noindex" => true,
);
store_shell_open($storeShell);
?>

<section class="section section--tight" aria-labelledby="recharge-title">
  <div class="container">
    <div class="page-head">
      <div>
        <p class="eyebrow">Ví của bạn</p>
        <h1 id="recharge-title"><?php echo store_e($manualTitle); ?></h1>
        <?php if ($manualDesc !== "") { ?>
        <p class="muted m0"><?php echo store_e($manualDesc); ?></p>
        <?php } ?>
      </div>
      <p class="recharge-balance">
        <span>Số dư hiện tại</span>
        <strong><?php echo format_currency($getUser["money"]); ?></strong>
      </p>
    </div>

    <div class="panel recharge-manual">
      <div class="panel__body prose">
        <?php echo $content; ?>
      </div>
    </div>

    <p class="small faint orders-foot">
      Sau khi chuyển khoản, gửi mã giao dịch cho shop nếu số dư chưa cập nhật.
      Cần hỗ trợ? <a href="<?php echo store_e(BASE_URL("client/contact")); ?>">Liên hệ shop</a>.
    </p>
  </div>
</section>

<?php
store_shell_close();
