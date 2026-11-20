<?php
/*
 * Legacy USDT invoice — Orbit v3.
 *
 * This is the page an old crypto invoice link points at (`client/payment?trans_id=…`, mailed by
 * libs/mails/deposit-order.php). It is read-only: it shows the amount, the wallet address and the QR,
 * counts the invoice down, and polls `loadStatusInvoice` until the backend marks it paid or expired.
 *
 * The legacy page pulled clipboard.js from a CDN, used jQuery polling and printed raw values. This one
 * uses the shared shell, the Clipboard API, and `fetch` against the same endpoint and action, so the
 * server behaviour is unchanged. When payOS is the live channel, the page says so and offers the
 * payOS recharge route instead of inviting a transfer to an address the shop no longer watches.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . "/../../libs/payos.php";
require_once __DIR__ . "/../common/store-shell.php";

$storeTransId = isset($_GET["trans_id"]) && is_string($_GET["trans_id"]) ? trim($_GET["trans_id"]) : "";
if ($storeTransId === "" || !preg_match('/^[A-Za-z0-9_-]{4,64}$/', $storeTransId)) {
    redirect(BASE_URL(""));
}
$storeInvoice = $CMSNT->get_row(
    "SELECT * FROM `invoices` WHERE `trans_id` = '" . addslashes($storeTransId) . "'"
);
if (!$storeInvoice) {
    redirect(BASE_URL(""));
}

$storeRetired = payos_config()["enabled"];
$storeStatus = (string) $storeInvoice["status"];
$storePaid = $storeStatus === "completed";
$storeExpired = $storeStatus === "expired";
$storeLife = (int) $CMSNT->site("invoice_life_time") - (orbit_now() - (int) strtotime($storeInvoice["create_gettime"]));
$storeLife = max(0, min(86400, $storeLife));
$storeAmount = (float) $storeInvoice["received"];
$storeAddress = trim((string) $storeInvoice["address"]);
$storeNetwork = trim((string) $storeInvoice["network"]);
$storeQr = $storeAddress !== "" ? getRowRealtime("wallets", $storeInvoice["wallet_id"], "qrcode") : "";
$storeBack = trim((string) $storeInvoice["return_url"]);
if ($storeBack === "" || !preg_match('#^https?://#i', $storeBack)) {
    $storeBack = BASE_URL("client/recharge-payos");
}

$storeShell = array(
    "title" => __("Thanh toán hoá đơn") . " | " . $CMSNT->site("title"),
    "desc" => __("Hoá đơn chuyển USDT đang chờ thanh toán."),
    "robots" => "noindex, nofollow",
    "nav" => store_nav_default($CMSNT),
    "body_class" => "page-content",
);
store_shell_open($storeShell);
?>
<section class="section section--tight" aria-labelledby="invoice-title">
  <div class="container container--narrow">
    <div class="page-head">
      <div>
        <p class="eyebrow">Hoá đơn <?php echo store_e($storeTransId); ?></p>
        <h1 id="invoice-title"><?php echo $storePaid ? "Đã thanh toán" : ($storeExpired ? "Hoá đơn đã hết hạn" : "Chờ chuyển khoản"); ?></h1>
        <p class="muted m0">
          <?php echo $storePaid
              ? "Số dư đã được cộng. Bạn có thể tiếp tục mua hàng."
              : ($storeExpired
                  ? "Hoá đơn quá thời gian giữ. Tạo hoá đơn mới để tiếp tục."
                  : "Gửi đúng số lượng trong một lần, không trừ phí vào số tiền này."); ?>
        </p>
      </div>
    </div>

    <?php if ($storeRetired && !$storePaid) { ?>
    <div class="alert alert--warn" role="status">
      <strong>Kênh USDT đã ngừng nhận.</strong>
      Shop đang nhận thanh toán qua payOS. Đừng chuyển thêm cho địa chỉ bên dưới.
      <a href="<?php echo store_e(BASE_URL("client/recharge-payos")); ?>">Nạp tiền qua payOS</a>
    </div>
    <?php } ?>

    <div class="panel">
      <div class="panel__body">
        <div class="invoice">
          <div class="invoice__qr">
            <?php if ($storeQr !== "") { ?>
            <img src="<?php echo store_e($storeQr); ?>" width="200" height="200"
              alt="Mã QR ví nhận USDT" loading="lazy">
            <?php } else { ?>
            <div class="empty">
              <p class="empty__title">Không có mã QR</p>
              <p class="muted m0">Dùng địa chỉ ví bên cạnh để chuyển.</p>
            </div>
            <?php } ?>
          </div>

          <dl class="kv">
            <div>
              <dt>Số tiền</dt>
              <dd class="invoice__amount">
                <?php echo store_e(rtrim(rtrim(number_format($storeAmount, 8, ".", ""), "0"), ".")); ?>
                <span class="muted">USDT</span>
              </dd>
            </div>
            <div>
              <dt>Mạng</dt>
              <dd><?php echo $storeNetwork !== "" ? store_e($storeNetwork) : "—"; ?></dd>
            </div>
            <div>
              <dt>Địa chỉ nhận</dt>
              <dd class="copy-row">
                <input class="input" id="invoice-address" type="text" readonly
                  value="<?php echo store_e($storeAddress); ?>">
                <button class="btn btn--ghost" type="button" data-copy="#invoice-address">Sao chép</button>
              </dd>
            </div>
            <?php if (!$storePaid && !$storeExpired) { ?>
            <div>
              <dt>Thời gian còn lại</dt>
              <dd><strong data-invoice-left><?php echo (int) $storeLife; ?></strong>
        <span class="muted small">giây</span></dd>
            </div>
            <?php } ?>
          </dl>
        </div>

        <?php if (!$storePaid && !$storeExpired) { ?>
        <p class="small muted mt-4">
          Trang tự cập nhật khi hệ thống nhận được giao dịch. Bạn có thể đóng tab này và mở lại bằng liên kết trong email.
        </p>
        <?php } ?>

        <div class="hero__actions mt-4">
          <a class="btn btn--primary" href="<?php echo store_e($storeBack); ?>">Quay lại shop</a>
          <?php if ($storePaid || $storeExpired) { ?>
          <a class="btn btn--ghost" href="<?php echo store_e(BASE_URL("client/recharge-payos")); ?>">Nạp tiền</a>
          <?php } ?>
        </div>
      </div>
    </div>
  </div>
</section>
<script>
(function () {
  "use strict";
  var paid = <?php echo $storePaid ? "true" : "false"; ?>;
  var expired = <?php echo $storeExpired ? "true" : "false"; ?>;
  var left = <?php echo (int) $storeLife; ?>;
  var leftEl = document.querySelector("[data-invoice-left]");
  var endpoint = "<?php echo store_e(BASE_URL("ajaxs/client/view.php")); ?>";
  var transId = "<?php echo store_e($storeTransId); ?>";

  if (!paid && !expired) {
    window.setInterval(function () {
      if (leftEl && left > 0) {
        left -= 1;
        leftEl.textContent = String(left);
      }
    }, 1000);

    window.setInterval(function () {
      var body = new URLSearchParams();
      body.append("action", "loadStatusInvoice");
      body.append("trans_id", transId);
      fetch(endpoint, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
        body: body.toString()
      }).then(function (response) {
        return response.json();
      }).then(function (result) {
        var status = result && result.data ? result.data.status : "";
        if (status === "completed" || status === "expired") {
          window.location.reload();
        }
      }).catch(function () {
        /* offline: the countdown keeps running, the next poll retries */
      });
    }, 5000);
  }
})();
</script>
<?php
store_shell_close();
