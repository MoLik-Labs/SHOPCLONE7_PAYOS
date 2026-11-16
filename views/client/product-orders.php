<?php
/*
 * Buyer order history — Orbit v3.
 *
 *   /product-orders                      every order of the signed-in buyer
 *   /product-orders?trans_id=…           one order code
 *   /product-orders?from=2026-11-01&to=2026-11-16
 *   /product-orders?shortByDate=1|2|3    today / this week / this month
 *
 * Every query is bound to `buyer = <session id>`; the request can only narrow that set. Deleted orders
 * stay visible (as the legacy page did) so a buyer can tell an order was removed instead of lost.
 * Notes, per-order download and deletion still call the same three AJAX handlers as before
 * (update.php saveNoteOrder, view.php download_order, remove.php removeOrder).
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . "/../../models/is_user.php";
require_once __DIR__ . "/../common/store-shell.php";
require_once __DIR__ . "/../common/pagination.php";

$storeMethods = store_payment_methods($CMSNT);

/* --- filters -------------------------------------------------------------- */

$storeLimit = isset($_GET["limit"]) ? (int) $_GET["limit"] : 10;
if (!in_array($storeLimit, array(5, 10, 20, 50, 100, 500, 1000), true)) {
    $storeLimit = 10;
}
$storePage = isset($_GET["page"]) ? (int) $_GET["page"] : 1;
if ($storePage < 1) {
    $storePage = 1;
}

$storeBuyer = (int) $getUser["id"];
$storeWhere = " `buyer` = " . $storeBuyer . " ";
$storeTransId = isset($_GET["trans_id"]) ? trim((string) $_GET["trans_id"]) : "";
$storeTransId = (string) preg_replace("/[^A-Za-z0-9_-]/", "", $storeTransId);
if ($storeTransId !== "") {
    $storeWhere .= " AND `trans_id` = '" . check_string($storeTransId) . "' ";
}

$storeDate = function ($value) {
    $value = trim((string) $value);
    if (!preg_match("/^(\d{4})-(\d{2})-(\d{2})$/", $value, $match)) {
        return "";
    }
    return checkdate((int) $match[2], (int) $match[3], (int) $match[1]) ? $value : "";
};
$storeFrom = isset($_GET["from"]) ? $storeDate($_GET["from"]) : "";
$storeTo = isset($_GET["to"]) ? $storeDate($_GET["to"]) : "";
if ($storeFrom !== "" || $storeTo !== "") {
    if ($storeFrom !== "") {
        $storeWhere .= " AND `create_gettime` >= '" . $storeFrom . " 00:00:00' ";
    }
    if ($storeTo !== "") {
        $storeWhere .= " AND `create_gettime` <= '" . $storeTo . " 23:59:59' ";
    }
} elseif (!empty($_GET["time"])) {
    /* Legacy link format: "2026-11-01 to 2026-11-16". */
    $storeLegacy = str_replace("/", "-", trim((string) $_GET["time"]));
    $storeParts = array_map("trim", explode(" to ", $storeLegacy));
    if (count($storeParts) === 2) {
        $storeStart = $storeDate($storeParts[0]);
        $storeEnd = $storeDate($storeParts[1]);
        if ($storeStart !== "" && $storeEnd !== "") {
            $storeWhere .= " AND `create_gettime` >= '" . $storeStart . " 00:00:00'"
                . " AND `create_gettime` <= '" . $storeEnd . " 23:59:59' ";
        }
    }
}

$storeShortBy = isset($_GET["shortByDate"]) ? (string) $_GET["shortByDate"] : "";
if ($storeShortBy === "1") {
    $storeWhere .= " AND `create_gettime` LIKE '%" . date("Y-m-d") . "%' ";
} elseif ($storeShortBy === "2") {
    $storeWhere .= " AND YEAR(`create_gettime`) = " . (int) date("Y") . " AND WEEK(`create_gettime`, 1) = " . (int) date("W") . " ";
} elseif ($storeShortBy === "3") {
    $storeWhere .= " AND MONTH(`create_gettime`) = " . (int) date("m") . " AND YEAR(`create_gettime`) = " . (int) date("Y") . " ";
} else {
    $storeShortBy = "";
}

$storeTotalRow = $CMSNT->get_row(" SELECT COUNT(`id`) AS total FROM `product_order` WHERE " . $storeWhere . " ");
$storeState = orbit_pagination_state($storeTotalRow ? $storeTotalRow["total"] : 0, $storeLimit, $storePage);
$storeOrders = (array) $CMSNT->get_list(
    " SELECT * FROM `product_order` WHERE " . $storeWhere
    . " ORDER BY `id` DESC LIMIT " . (int) $storeState["offset"] . ", " . (int) $storeState["per_page"] . " "
);

$storePagerQuery = array(
    "limit" => $storeLimit,
    "trans_id" => $storeTransId,
    "from" => $storeFrom,
    "to" => $storeTo,
    "shortByDate" => $storeShortBy,
);
if ($storeFrom !== "" || $storeTo !== "") {
    $storePagerQuery["from"] = $storeFrom;
    $storePagerQuery["to"] = $storeTo;
} elseif (!empty($_GET["time"])) {
    $storePagerQuery["time"] = (string) $_GET["time"];
}
$storePageHref = function ($page) use ($storePagerQuery) {
    return BASE_URL("product-orders") . "?" . http_build_query(array_merge($storePagerQuery, array("page" => (int) $page)));
};

$storeShell = array(
    "title" => __("Lịch sử đơn hàng") . " | " . $CMSNT->site("title"),
    "desc" => $CMSNT->site("description"),
    "nav" => store_nav_default($CMSNT, $storeMethods),
    "body_class" => "page-orders",
    "noindex" => true,
);
store_shell_open($storeShell);
?>

<section class="section section--tight" aria-labelledby="orders-title">
  <div class="container">

    <div class="page-head">
      <div>
        <p class="eyebrow">Tài khoản</p>
        <h1 id="orders-title">Lịch sử đơn hàng</h1>
        <p class="muted m0">Mọi đơn bạn đã mua, kèm ghi chú riêng và tệp tài khoản để tải lại.</p>
      </div>
      <a class="btn" href="<?php echo store_e(BASE_URL("client/purchase-history")); ?>">Thống kê chi tiêu</a>
    </div>

    <?php if ((string) $CMSNT->site("notice_orders") !== "") { ?>
    <div class="alert alert--info orders-notice" role="note">
      <?php echo $CMSNT->site("notice_orders"); ?>
    </div>
    <?php } ?>

    <form class="toolbar orders-filter" method="get" action="<?php echo store_e(BASE_URL("product-orders")); ?>">
      <div class="field">
        <label class="field__label" for="order-code">Mã đơn hàng</label>
        <input class="input" id="order-code" type="text" name="trans_id" autocomplete="off"
          value="<?php echo store_e($storeTransId); ?>" placeholder="VD: 8F3A21C9">
      </div>
      <div class="field">
        <label class="field__label" for="order-from">Từ ngày</label>
        <input class="input" id="order-from" type="date" name="from" value="<?php echo store_e($storeFrom); ?>">
      </div>
      <div class="field">
        <label class="field__label" for="order-to">Đến ngày</label>
        <input class="input" id="order-to" type="date" name="to" value="<?php echo store_e($storeTo); ?>">
      </div>
      <div class="field">
        <label class="field__label" for="order-short">Khoảng thời gian</label>
        <select class="select" id="order-short" name="shortByDate">
          <option value="">Tất cả</option>
          <option value="1"<?php echo $storeShortBy === "1" ? " selected" : ""; ?>>Hôm nay</option>
          <option value="2"<?php echo $storeShortBy === "2" ? " selected" : ""; ?>>Tuần này</option>
          <option value="3"<?php echo $storeShortBy === "3" ? " selected" : ""; ?>>Tháng này</option>
        </select>
      </div>
      <div class="field field--shrink">
        <label class="field__label" for="order-limit">Mỗi trang</label>
        <select class="select" id="order-limit" name="limit">
          <?php foreach (array(5, 10, 20, 50, 100, 500, 1000) as $storeLimitOption) { ?>
          <option value="<?php echo (int) $storeLimitOption; ?>"<?php echo $storeLimit === $storeLimitOption ? " selected" : ""; ?>>
            <?php echo format_cash($storeLimitOption); ?>
          </option>
          <?php } ?>
        </select>
      </div>
      <div class="toolbar__actions">
        <button class="btn btn--primary" type="submit">Lọc đơn</button>
        <?php if ($storeTransId !== "" || $storeFrom !== "" || $storeTo !== "" || $storeShortBy !== "" || !empty($_GET["time"])) { ?>
        <a class="btn btn--ghost" href="<?php echo store_e(BASE_URL("product-orders")); ?>">Bỏ lọc</a>
        <?php } ?>
      </div>
    </form>

    <div class="orders-head">
      <p class="small muted m0">
        <?php echo orbit_pagination_summary($storeState, "Hiển thị %from%–%to% trong %total% đơn"); ?>
      </p>
      <?php if (!empty($storeOrders)) { ?>
      <div class="orders-bulk">
        <label class="check">
          <input type="checkbox" data-orders-all>
          <span>Chọn tất cả</span>
        </label>
        <button class="btn btn--sm btn--danger" type="button" data-orders-delete disabled>Xóa đơn đã chọn</button>
      </div>
      <?php } ?>
    </div>

    <?php if (empty($storeOrders)) { ?>
    <div class="empty">
      <p class="empty__title">Chưa có đơn hàng nào</p>
      <p class="m0">Đơn bạn mua sẽ xuất hiện ở đây kèm mã đơn và tệp tài khoản. Bạn có thể bắt đầu từ trang sản phẩm.</p>
      <p class="m0"><a class="btn btn--primary" href="<?php echo store_e(BASE_URL("client/home")); ?>#san-pham">Xem sản phẩm</a></p>
    </div>
    <?php } else { ?>
    <div data-orders
      data-update-endpoint="<?php echo store_e(BASE_URL("ajaxs/client/update.php")); ?>"
      data-remove-endpoint="<?php echo store_e(BASE_URL("ajaxs/client/remove.php")); ?>"
      data-view-endpoint="<?php echo store_e(BASE_URL("ajaxs/client/view.php")); ?>"
      data-token="<?php echo store_e($getUser["token"]); ?>">

      <p class="small" role="status" aria-live="polite" data-orders-status></p>

      <ul class="order-list">
        <?php foreach ($storeOrders as $storeOrder) {
            $storeDeleted = (int) $storeOrder["trash"] === 1;
            ?>
        <li class="order-item<?php echo $storeDeleted ? " order-item--deleted" : ""; ?>" data-order-row>
          <div class="order-item__check">
            <?php if (!$storeDeleted) { ?>
            <label class="check">
              <input type="checkbox" value="<?php echo (int) $storeOrder["id"]; ?>" data-order-check
                data-trans="<?php echo store_e($storeOrder["trans_id"]); ?>">
              <span class="sr-only">Chọn đơn <?php echo store_e($storeOrder["trans_id"]); ?></span>
            </label>
            <?php } ?>
          </div>

          <div class="order-item__main">
            <a class="order-item__code" href="<?php echo store_e(BASE_URL("product-order/" . $storeOrder["trans_id"])); ?>">
              <?php echo store_e($storeOrder["trans_id"]); ?>
            </a>
            <span class="order-item__product"><?php echo store_e($storeOrder["product_name"]); ?></span>
            <span class="order-item__meta">
              SL <?php echo format_cash($storeOrder["amount"]); ?>
              · <?php echo store_e($storeOrder["create_gettime"]); ?>
              <?php if ($storeDeleted) { ?><span class="pill pill--danger">Đã xóa khỏi lịch sử</span><?php } ?>
            </span>
          </div>

          <div class="order-item__money">
            <span class="small faint">Thanh toán</span>
            <strong><?php echo format_currency($storeOrder["pay"]); ?></strong>
          </div>

          <div class="order-item__actions">
            <?php if (!$storeDeleted) { ?>
            <a class="btn btn--sm" href="<?php echo store_e(BASE_URL("product-order/" . $storeOrder["trans_id"])); ?>">Xem</a>
            <button class="btn btn--sm btn--ghost" type="button"
              data-order-download="<?php echo store_e($storeOrder["trans_id"]); ?>">Tải .txt</button>
            <button class="btn btn--sm btn--ghost btn--danger" type="button"
              data-order-remove="<?php echo (int) $storeOrder["id"]; ?>">Xóa</button>
            <?php } else { ?>
            <span class="small faint">Không còn thao tác</span>
            <?php } ?>
          </div>

          <div class="order-item__note">
            <label class="small faint" for="note-<?php echo (int) $storeOrder["id"]; ?>">Ghi chú riêng</label>
            <input class="input input--sm" id="note-<?php echo (int) $storeOrder["id"]; ?>" type="text"
              value="<?php echo store_e($storeOrder["note"]); ?>" maxlength="200"
              placeholder="VD: tài khoản dùng cho gia đình"
              <?php echo $storeDeleted ? "disabled" : 'data-order-note="' . (int) $storeOrder["id"] . '"'; ?>>
          </div>
        </li>
        <?php } ?>
      </ul>
    </div>

    <?php orbit_pagination(array(
        "total" => $storeState["total"],
        "per_page" => $storeLimit,
        "page" => $storeState["page"],
        "href" => $storePageHref,
        "aria" => "Phân trang đơn hàng",
        "labels" => array("summary" => "Trang %page%/%pages% · %total% đơn"),
    )); ?>
    <?php } ?>

    <p class="small faint orders-foot">
      Đơn đã xóa vẫn hiện ở đây để bạn biết nó từng tồn tại; nội dung tài khoản không còn mở được.
      Cần hỗ trợ? <a href="<?php echo store_e(BASE_URL("client/contact")); ?>">Liên hệ shop</a>.
    </p>
  </div>
</section>

<?php
store_shell_close();
