<?php
/*
 * GachtheFast bank transfer — a closed channel.
 *
 * The page exists so a bookmarked/shared link explains why it is unavailable instead of looking broken.
 * No provider call, no balance movement: `GtfBankService` refuses by contract while no bank API is
 * confirmed, and its reason is printed here.
 */

if (!defined('IN_SITE')) {
    exit('The Request Not Found');
}

require_once __DIR__ . '/../../models/is_user.php';
require_once __DIR__ . '/../../libs/gtf.php';
require_once __DIR__ . '/../../libs/gtf_bank.php';
require_once __DIR__ . '/../common/store-shell.php';

$gtfReason = 'Chưa có hợp đồng API nạp ngân hàng GachtheFast nào được xác nhận, nên tính năng này bị khoá.';
try {
    $gtfBankService = new GtfBankService(gtf_db(), gtf_config());
    $gtfReason = $gtfBankService->unavailableReason();
} catch (Throwable $error) {
    /* The page must still explain itself when the service cannot even be built (no DB configured). */
}

$storeMethods = store_payment_methods($CMSNT);
$storeOther = !empty($storeMethods) ? $storeMethods[0]["href"] : BASE_URL("client/home");

$storeShell = array(
    'title' => __("Nạp tiền qua ngân hàng") . " | " . $CMSNT->site("title"),
    'desc' => $CMSNT->site("description"),
    'nav' => store_nav_default($CMSNT, $storeMethods),
    'body_class' => 'page-recharge',
    'noindex' => true,
);
store_shell_open($storeShell);
?>

<section class="section section--tight" aria-labelledby="recharge-title">
  <div class="container">
    <div class="page-head">
      <div>
        <p class="eyebrow">Ví của bạn</p>
        <h1 id="recharge-title">Nạp tiền qua ngân hàng</h1>
        <p class="muted m0">Kênh GachtheFast.</p>
      </div>
      <p class="recharge-balance">
        <span>Số dư hiện tại</span>
        <strong><?php echo format_currency($getUser["money"]); ?></strong>
      </p>
    </div>

    <div class="panel">
      <div class="panel__head">
        <h2 class="panel__title">Kênh này đang đóng</h2>
        <span class="pill pill--warn">Chưa khả dụng</span>
      </div>
      <div class="panel__body">
        <div class="alert alert--warn" role="alert">
          <p class="alert__title">Chưa thể nạp qua ngân hàng GachtheFast</p>
          <p class="m0">Kênh nạp ngân hàng GachtheFast hiện chưa khả dụng. <?php echo store_e($gtfReason); ?></p>
        </div>
        <p class="muted">Không có khoản tiền nào được ghi nhận từ trang này. Vui lòng dùng một phương thức
          đang hoạt động; số dư chỉ thay đổi khi hệ thống xác nhận giao dịch.</p>
        <a class="btn btn--primary" href="<?php echo store_e($storeOther); ?>">Chọn phương thức khác</a>
      </div>
    </div>
  </div>
</section>

<?php
store_shell_close();
