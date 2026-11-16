<?php
/*
 * One order — Orbit v3.
 *
 *   /product-order/<trans_id>
 *
 * Shows the product bought, what was paid, the delivery note and the account rows (`product_sold`) that
 * belong to this order. The same IP/device checks as before still run before anything is printed.
 *
 * Copying, selecting, exporting and deleting keep the existing handlers
 * (view.php download_order, remove.php removeOrder); no new endpoint is introduced.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . "/../../models/is_user.php";

if (empty($_GET["trans_id"])) {
    redirect(base_url("product-orders/"));
}
$trans_id = check_string($_GET["trans_id"]);
$order = $CMSNT->get_row(
    "SELECT * FROM `product_order` WHERE `trans_id` = '" . $trans_id . "' AND `buyer` = '" . (int) $getUser["id"]
    . "' AND `trash` = 0 "
);
if (!$order) {
    redirect(base_url("product-orders/"));
}
$product = $CMSNT->get_row(" SELECT * FROM `products` WHERE `id` = '" . (int) $order["product_id"] . "' ");

/* The two view guards the shop already relies on, unchanged. */
if (($order["status_view_order"] == 1 || $CMSNT->site("isPurchaseIpVerified") == 1) && $order["ip"] != myip()) {
    exit(__("Địa chỉ IP của bạn không khớp với địa chỉ IP bạn dùng để mua hàng"));
}
if ($order["status_view_order"] == 1 || $CMSNT->site("isPurchaseDeviceVerified") == 1) {
    $Mobile_Detect = new Mobile_Detect();
    if ($order["device"] != $Mobile_Detect->getUserAgent()) {
        exit(__("Trình duyệt của bạn không khớp với trình duyệt lúc bạn mua hàng"));
    }
}

require_once __DIR__ . "/../common/store-shell.php";
require_once __DIR__ . "/../common/pagination.php";

$storeMethods = store_payment_methods($CMSNT);

$storeLimit = isset($_GET["limit"]) ? (int) $_GET["limit"] : 10;
if (!in_array($storeLimit, array(5, 10, 20, 50, 100, 500, 1000, 10000), true)) {
    $storeLimit = 10;
}
$storePage = isset($_GET["page"]) ? (int) $_GET["page"] : 1;
if ($storePage < 1) {
    $storePage = 1;
}

$storeAccount = isset($_GET["account"]) ? trim((string) $_GET["account"]) : "";
$storeAccount = preg_replace('/[%_\\\\]/', "", (string) check_string($storeAccount));
$storeWhere = " `trans_id` = '" . $trans_id . "' ";
if ($storeAccount !== "") {
    $storeWhere .= " AND `account` LIKE '%" . $storeAccount . "%' ";
}

$storeTotalRow = $CMSNT->get_row(" SELECT COUNT(`id`) AS total FROM `product_sold` WHERE " . $storeWhere . " ");
$storeState = orbit_pagination_state($storeTotalRow ? $storeTotalRow["total"] : 0, $storeLimit, $storePage);
$storeRows = (array) $CMSNT->get_list(
    " SELECT * FROM `product_sold` WHERE " . $storeWhere
    . " ORDER BY `id` DESC LIMIT " . (int) $storeState["offset"] . ", " . (int) $storeState["per_page"] . " "
);

$storeProductName = $product ? (string) $product["name"] : (string) $order["product_name"];
$storeNote = "";
if ($product && trim((string) $product["note"]) !== "") {
    $storeNote = base64_decode((string) $product["note"]);
}

$storeShell = array(
    "title" => __("Đơn hàng") . " #" . $order["trans_id"] . " | " . $CMSNT->site("title"),
    "desc" => $CMSNT->site("description"),
    "nav" => store_nav_default($CMSNT, $storeMethods),
    "body_class" => "page-order",
    "noindex" => true,
);
store_shell_open($storeShell);
?>

<section class="section section--tight" aria-labelledby="order-title">
  <div class="container">

    <nav class="crumbs" aria-label="Breadcrumb">
      <a href="<?php echo store_e(BASE_URL("client/home")); ?>">Trang chủ</a>
      <span aria-hidden="true">/</span>
      <a href="<?php echo store_e(BASE_URL("product-orders")); ?>">Lịch sử đơn hàng</a>
      <span aria-hidden="true">/</span>
      <span aria-current="page">#<?php echo store_e($order["trans_id"]); ?></span>
    </nav>

    <div class="page-head order-head">
      <div>
        <p class="eyebrow">Đơn hàng</p>
        <h1 id="order-title">#<?php echo store_e($order["trans_id"]); ?></h1>
        <p class="muted m0">
          <?php if ($product) { ?>
          <a href="<?php echo store_e(BASE_URL("product/" . $product["slug"])); ?>"><?php echo store_e($storeProductName); ?></a>
          <?php } else { ?>
          <?php echo store_e($storeProductName); ?>
          <?php } ?>
          · SL <?php echo format_cash($order["amount"]); ?>
          · <?php echo store_e($order["create_gettime"]); ?>
        </p>
      </div>
      <div class="order-head__actions">
        <a class="btn btn--ghost" href="<?php echo store_e(BASE_URL("product-orders")); ?>">← Lịch sử đơn</a>
        <button class="btn" type="button" data-accounts-copy-all>Chép tất cả</button>
      </div>
    </div>

    <div class="stats stats--order">
      <div class="stat stat--accent">
        <p class="stat__label">Đã thanh toán</p>
        <p class="stat__value stat__value--sm"><?php echo format_currency($order["pay"]); ?></p>
        <p class="stat__foot">Trừ từ số dư khi mua</p>
      </div>
      <div class="stat">
        <p class="stat__label">Số lượng</p>
        <p class="stat__value stat__value--sm"><?php echo format_cash($order["amount"]); ?></p>
        <p class="stat__foot">Tài khoản trong đơn</p>
      </div>
      <div class="stat">
        <p class="stat__label">Thời gian</p>
        <p class="stat__value stat__value--sm"><?php echo store_e(date("d/m/Y", strtotime($order["create_gettime"]))); ?></p>
        <p class="stat__foot"><?php echo store_e(date("H:i", strtotime($order["create_gettime"]))); ?></p>
      </div>
    </div>

    <?php if ($storeNote !== "") { ?>
    <div class="panel order-note">
      <div class="panel__head">
        <h2 class="panel__title">Ghi chú từ shop</h2>
      </div>
      <div class="panel__body prose"><?php echo $storeNote; ?></div>
    </div>
    <?php } ?>

    <div class="panel" data-order-detail
      data-view-endpoint="<?php echo store_e(BASE_URL("ajaxs/client/view.php")); ?>"
      data-remove-endpoint="<?php echo store_e(BASE_URL("ajaxs/client/remove.php")); ?>"
      data-token="<?php echo store_e($getUser["token"]); ?>"
      data-trans="<?php echo store_e($order["trans_id"]); ?>"
      data-order-id="<?php echo (int) $order["id"]; ?>"
      data-orders-url="<?php echo store_e(BASE_URL("product-orders")); ?>">
      <div class="panel__head">
        <h2 class="panel__title">Tài khoản trong đơn</h2>
        <span class="pill"><?php echo format_cash($storeState["total"]); ?> tài khoản</span>
      </div>
      <div class="panel__body">
        <div class="orders-head">
          <p class="small muted m0">
            <?php echo orbit_pagination_summary($storeState, "Hiển thị %from%–%to% trong %total% tài khoản"); ?>
          </p>
          <div class="orders-bulk">
            <label class="check">
              <input type="checkbox" data-accounts-all>
              <span>Chọn tất cả</span>
            </label>
            <button class="btn btn--sm" type="button" data-accounts-copy disabled>Chép đã chọn</button>
            <button class="btn btn--sm" type="button" data-accounts-uids disabled>Chép UID</button>
            <button class="btn btn--sm" type="button" data-accounts-download disabled>Tải .txt</button>
          </div>
        </div>

        <form class="toolbar order-account-filter" method="get"
          action="<?php echo store_e(BASE_URL("product-order/" . $order["trans_id"])); ?>">
          <div class="field">
            <label class="field__label" for="order-account">Tìm tài khoản</label>
            <input class="input" id="order-account" type="search" name="account" autocomplete="off"
              value="<?php echo store_e($storeAccount); ?>" placeholder="Nhập một phần tài khoản">
          </div>
          <div class="field field--shrink">
            <label class="field__label" for="order-account-limit">Mỗi trang</label>
            <select class="select" id="order-account-limit" name="limit">
              <?php foreach (array(5, 10, 20, 50, 100, 500, 1000) as $storeLimitOption) { ?>
              <option value="<?php echo (int) $storeLimitOption; ?>"<?php echo $storeLimit === $storeLimitOption ? " selected" : ""; ?>>
                <?php echo format_cash($storeLimitOption); ?>
              </option>
              <?php } ?>
            </select>
          </div>
          <div class="toolbar__actions">
            <button class="btn btn--primary" type="submit">Tìm</button>
            <?php if ($storeAccount !== "") { ?>
            <a class="btn btn--ghost" href="<?php echo store_e(BASE_URL("product-order/" . $order["trans_id"])); ?>">Bỏ lọc</a>
            <?php } ?>
          </div>
        </form>

        <p class="small" role="status" aria-live="polite" data-accounts-status></p>

        <?php if (empty($storeRows)) { ?>
        <div class="empty">
          <p class="empty__title">Không có tài khoản nào</p>
          <p class="m0"><?php echo $storeAccount !== "" ? "Không dòng nào khớp từ khoá tìm kiếm." : "Đơn này chưa có dữ liệu tài khoản. Liên hệ shop để được hỗ trợ."; ?></p>
        </div>
        <?php } else { ?>
        <ul class="account-list">
          <?php foreach ($storeRows as $storeRow) { ?>
          <li class="account-item">
            <label class="check account-item__check">
              <input type="checkbox" data-account-check data-uid="<?php echo store_e($storeRow["uid"]); ?>"
                data-account="<?php echo store_e($storeRow["account"]); ?>">
              <span class="sr-only">Chọn tài khoản <?php echo store_e($storeRow["uid"]); ?></span>
            </label>
            <span class="account-item__uid"><?php echo store_e($storeRow["uid"]); ?></span>
            <code class="account-item__value"><?php echo store_e($storeRow["account"]); ?></code>
            <button class="btn btn--sm btn--ghost" type="button"
              data-copy-value="<?php echo store_e($storeRow["account"]); ?>">Sao chép</button>
          </li>
          <?php } ?>
        </ul>

        <?php orbit_pagination(array(
            "total" => $storeState["total"],
            "per_page" => $storeLimit,
            "page" => $storeState["page"],
            "href" => function ($page) use ($order, $storeLimit, $storeAccount) {
                $query = array("limit" => $storeLimit, "page" => (int) $page);
                if ($storeAccount !== "") {
                    $query["account"] = $storeAccount;
                }
                return BASE_URL("product-order/" . $order["trans_id"]) . "?" . http_build_query($query);
            },
            "aria" => "Phân trang tài khoản",
            "labels" => array("summary" => "Trang %page%/%pages% · %total% tài khoản"),
        )); ?>
        <?php } ?>
      </div>

      <div class="panel__foot">
        <button class="btn" type="button" data-order-download="<?php echo store_e($order["trans_id"]); ?>">
          Tải toàn bộ đơn (.txt)
        </button>
        <button class="btn btn--danger" type="button" data-order-remove="<?php echo (int) $order["id"]; ?>">
          Xóa đơn khỏi lịch sử
        </button>
      </div>
    </div>

    <p class="small faint orders-foot">
      Tài khoản trong đơn là dữ liệu riêng của bạn. Không chia sẻ công khai; nếu cần cấp lại, gửi mã đơn cho
      <a href="<?php echo store_e(BASE_URL("client/contact")); ?>">hỗ trợ</a>.
    </p>
  </div>
</section>

<?php
store_shell_close();
