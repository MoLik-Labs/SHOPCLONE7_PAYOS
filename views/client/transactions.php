<?php
/*
 * Balance history — Orbit v3.
 *
 * The legacy page read `$_GET["reason"]` into `$content` and then used `$reason` (undefined) both in
 * the LIKE filter and in the pagination URL: the search never filtered, and every page produced a PHP
 * warning. This version reads one validated variable, caps its length, and carries it through the
 * pager. The table now states whether a line is a credit or a debit in words as well as colour.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . "/../common/store-shell.php";
require_once __DIR__ . "/../common/account-nav.php";
require_once __DIR__ . "/../../models/is_user.php";
if (!isset($getUser) || !is_array($getUser)) {
    $getUser = store_current_user();
}

$storeLimit = store_account_limit(isset($_GET["limit"]) ? $_GET["limit"] : 20);
$storePage = store_account_page(isset($_GET["page"]) ? $_GET["page"] : 1);
$storeFrom = ($storePage - 1) * $storeLimit;
$storeReason = isset($_GET["reason"]) ? store_account_search($_GET["reason"]) : "";
$storeTime = isset($_GET["time"]) ? (string) $_GET["time"] : "";
$storeShort = isset($_GET["shortByDate"]) ? (int) $_GET["shortByDate"] : 0;

$storeWhere = " `user_id` = " . (int) $getUser["id"] . " ";
if ($storeReason !== "") {
    $storeWhere .= " AND `noidung` LIKE '%" . $storeReason . "%' ";
}
$storeRange = store_account_date_range($storeTime);
if ($storeRange !== null) {
    $storeWhere .= " AND `thoigian` >= '" . $storeRange[0] . "' AND `thoigian` <= '" . $storeRange[1] . "' ";
}
if ($storeShort === 1) {
    $storeWhere .= " AND `thoigian` LIKE '" . date("Y-m-d") . "%' ";
} elseif ($storeShort === 2) {
    $storeWhere .= " AND YEAR(`thoigian`) = " . (int) date("Y") . " AND WEEK(`thoigian`, 1) = " . (int) date("W") . " ";
} elseif ($storeShort === 3) {
    $storeWhere .= " AND YEAR(`thoigian`) = " . (int) date("Y") . " AND MONTH(`thoigian`) = " . (int) date("m") . " ";
}

$storeRows = $CMSNT->get_list(
    "SELECT `id`, `thoigian`, `sotientruoc`, `sotienthaydoi`, `sotiensau`, `noidung`"
    . " FROM `dongtien` WHERE " . $storeWhere . " ORDER BY `id` DESC LIMIT " . $storeFrom . ", " . $storeLimit
);
$storeTotalRow = $CMSNT->get_row("SELECT COUNT(`id`) AS total FROM `dongtien` WHERE " . $storeWhere);
$storeTotal = $storeTotalRow ? (int) $storeTotalRow["total"] : 0;

/* Totals for the rows on this page only — clearly labelled, never presented as an all-time figure. */
$storeIn = 0;
$storeOut = 0;
foreach ($storeRows as $row) {
    $delta = (float) $row["sotienthaydoi"];
    if ($delta > 0) {
        $storeIn += $delta;
    } elseif ($delta < 0) {
        $storeOut += -$delta;
    }
}

$storeShell = array(
    "title" => __("Biến động số dư") . " | " . $CMSNT->site("title"),
    "desc" => $CMSNT->site("description"),
    "nav" => store_nav_default($CMSNT),
    "body_class" => "page-account",
);
store_shell_open($storeShell);
?>
<section class="section section--tight" aria-labelledby="transactions-title">
  <div class="container">
    <div class="page-head">
      <div>
        <p class="eyebrow">Tài khoản</p>
        <h1 id="transactions-title">Biến động số dư</h1>
        <p class="muted m0">Mỗi lần cộng hoặc trừ tiền đều được ghi lại kèm số dư trước và sau.</p>
      </div>
      <p class="stat stat--accent">
        <span class="stat__label">Số dư hiện tại</span>
        <strong class="stat__value stat__value--sm"><?php echo format_currency($getUser["money"]); ?></strong>
      </p>
    </div>

<?php store_account_nav("transactions"); ?>

    <form class="toolbar" method="get" action="<?php echo BASE_URL("index.php"); ?>" role="search">
      <input type="hidden" name="action" value="transactions">
      <div class="toolbar__field">
        <label class="toolbar__label" for="tx-reason">Lý do</label>
        <input class="input" id="tx-reason" type="search" name="reason" maxlength="64"
          value="<?php echo store_e(stripslashes($storeReason)); ?>" placeholder="Ví dụ: nạp tiền">
      </div>
      <div class="toolbar__field">
        <label class="toolbar__label" for="tx-time">Khoảng ngày</label>
        <input class="input" id="tx-time" type="text" name="time"
          value="<?php echo store_e($storeRange !== null ? substr($storeRange[0], 0, 10) . " to " . substr($storeRange[1], 0, 10) : ""); ?>"
          placeholder="2026-01-01 to 2026-01-31" inputmode="numeric" autocomplete="off">
      </div>
      <div class="toolbar__field">
        <label class="toolbar__label" for="tx-short">Thời gian</label>
        <select class="select" id="tx-short" name="shortByDate">
          <option value="0">Tất cả</option>
          <option value="1"<?php echo $storeShort === 1 ? " selected" : ""; ?>>Hôm nay</option>
          <option value="2"<?php echo $storeShort === 2 ? " selected" : ""; ?>>Tuần này</option>
          <option value="3"<?php echo $storeShort === 3 ? " selected" : ""; ?>>Tháng này</option>
        </select>
      </div>
      <div class="toolbar__field">
        <label class="toolbar__label" for="tx-limit">Số dòng</label>
        <select class="select" id="tx-limit" name="limit">
<?php foreach (array(10, 20, 50, 100) as $size) { ?>
          <option value="<?php echo $size; ?>"<?php echo $storeLimit === $size ? " selected" : ""; ?>><?php echo $size; ?></option>
<?php } ?>
        </select>
      </div>
      <div class="toolbar__spacer"></div>
      <button class="btn btn--primary" type="submit">Lọc</button>
      <a class="btn btn--ghost" href="<?php echo BASE_URL("client/transactions"); ?>">Bỏ lọc</a>
    </form>

    <div class="panel">
      <div class="panel__head">
        <h2 class="panel__title"><?php echo (int) $storeTotal; ?> dòng</h2>
        <p class="muted small m0">Trang này: +<?php echo format_currency($storeIn); ?> · −<?php echo format_currency($storeOut); ?></p>
      </div>
      <div class="panel__body">
<?php if (empty($storeRows)) { ?>
        <div class="empty">
          <h3 class="empty__title">Không có dòng nào khớp bộ lọc</h3>
          <p class="muted">Thử bỏ lọc hoặc chọn khoảng thời gian rộng hơn.</p>
          <p class="empty__actions"><a class="btn" href="<?php echo BASE_URL("client/transactions"); ?>">Xem tất cả</a></p>
        </div>
<?php } else { ?>
        <div class="table-wrap">
          <table class="table table--zebra table--responsive">
            <caption class="sr-only">Biến động số dư của bạn</caption>
            <thead>
              <tr>
                <th scope="col">Thời gian</th>
                <th scope="col">Lý do</th>
                <th scope="col" class="num">Trước</th>
                <th scope="col" class="num">Thay đổi</th>
                <th scope="col" class="num">Sau</th>
              </tr>
            </thead>
            <tbody>
<?php foreach ($storeRows as $row) { ?>
              <tr>
                <td class="mono small" data-label="Thời gian"><?php echo store_e($row["thoigian"]); ?></td>
                <td data-label="Lý do"><?php echo store_e($row["noidung"]); ?></td>
                <td class="num" data-label="Trước"><?php echo format_currency($row["sotientruoc"]); ?></td>
                <td class="num" data-label="Thay đổi"><?php echo store_account_badge($row["sotienthaydoi"]); ?></td>
                <td class="num" data-label="Sau"><?php echo format_currency($row["sotiensau"]); ?></td>
              </tr>
<?php } ?>
            </tbody>
          </table>
        </div>
<?php } ?>
      </div>
<?php if ($storeTotal > $storeLimit) { ?>
      <div class="panel__foot">
        <?php echo store_pager($storePage, $storeTotal, $storeLimit, array(
            "action" => "transactions",
            "limit" => $storeLimit,
            "reason" => stripslashes($storeReason),
            "time" => $storeRange !== null ? substr($storeRange[0], 0, 10) . " to " . substr($storeRange[1], 0, 10) : "",
            "shortByDate" => $storeShort > 0 ? $storeShort : "",
        ), "client/transactions"); ?>
      </div>
<?php } ?>
    </div>

    <p class="orders-foot muted small">Số dư chỉ thay đổi qua các giao dịch trong danh sách này. Nếu thấy sai, hãy gửi mã đơn cho <a href="<?php echo BASE_URL("client/contact"); ?>">hỗ trợ</a>.</p>
  </div>
</section>
<?php
store_shell_close($storeShell);
