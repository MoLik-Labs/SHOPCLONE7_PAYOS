<?php
/*
 * Affiliate commission ledger — Orbit v3.
 *
 * Every row of `aff_log` for the signed-in customer, with the balance before/after so the figure can be
 * checked against the wallet page. Filters: reason keyword, date range, quick range, page size.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

if ((string) $CMSNT->site("affiliate_status") !== "1") {
    redirect(BASE_URL(""));
}

require_once __DIR__ . "/../common/store-shell.php";
require_once __DIR__ . "/../common/account-nav.php";
require_once __DIR__ . "/../../models/is_user.php";
if (!isset($getUser) || !is_array($getUser)) {
    $getUser = store_current_user();
}

$storeUserId = (int) $getUser["id"];
$storeLimit = store_account_limit(isset($_GET["limit"]) ? $_GET["limit"] : 20);
$storePage = store_account_page(isset($_GET["page"]) ? $_GET["page"] : 1);
$storeFrom = ($storePage - 1) * $storeLimit;
$storeReason = isset($_GET["reason"]) ? store_account_search($_GET["reason"]) : "";
$storeTime = isset($_GET["time"]) && is_string($_GET["time"]) ? trim($_GET["time"]) : "";
$storeRange = store_account_date_range($storeTime);
if ($storeRange === null) {
    $storeTime = "";
}
$storeShort = isset($_GET["shortByDate"]) ? (string) $_GET["shortByDate"] : "";

$storeWhere = " `user_id` = " . $storeUserId . " ";
if ($storeReason !== "") {
    $storeWhere .= " AND `reason` LIKE '%" . $storeReason . "%' ";
}
if ($storeRange !== null) {
    $storeWhere .= " AND `create_gettime` >= '" . addslashes($storeRange[0]) . "'"
        . " AND `create_gettime` <= '" . addslashes($storeRange[1]) . "' ";
}
if ($storeShort === "1") {
    $storeWhere .= " AND `create_gettime` LIKE '" . date("Y-m-d") . "%' ";
} elseif ($storeShort === "2") {
    $storeWhere .= " AND YEAR(`create_gettime`) = " . date("Y") . " AND WEEK(`create_gettime`, 1) = " . date("W") . " ";
} elseif ($storeShort === "3") {
    $storeWhere .= " AND MONTH(`create_gettime`) = " . date("n") . " AND YEAR(`create_gettime`) = " . date("Y") . " ";
} else {
    $storeShort = "";
}

$storeRows = $CMSNT->get_list(
    " SELECT `reason`, `sotientruoc`, `sotienthaydoi`, `sotienhientai`, `create_gettime` FROM `aff_log`"
    . " WHERE " . $storeWhere . " ORDER BY `id` DESC LIMIT " . $storeFrom . ", " . $storeLimit
);
$storeTotal = (int) $CMSNT->num_rows(" SELECT `id` FROM `aff_log` WHERE " . $storeWhere);

$storeShell = array(
    "title" => __("Lịch sử hoa hồng") . " | " . $CMSNT->site("title"),
    "desc" => __("Từng lần cộng hoa hồng và số dư sau mỗi lần."),
    "robots" => "noindex, follow",
    "nav" => store_nav_default($CMSNT),
    "body_class" => "page-content",
);
store_shell_open($storeShell);
?>
<section class="section section--tight" aria-labelledby="aff-history-title">
  <div class="container">
    <div class="page-head">
      <div>
        <p class="eyebrow">Cộng tác viên</p>
        <h1 id="aff-history-title">Lịch sử hoa hồng</h1>
        <p class="muted m0">Mỗi dòng là một lần cộng hoa hồng, kèm số dư trước và sau để bạn đối chiếu.</p>
      </div>
      <div class="page-head__aside">
        <span class="stat__label">Hoa hồng khả dụng</span>
        <strong class="stat__value"><?php echo format_currency($getUser["ref_price"]); ?></strong>
      </div>
    </div>

    <?php store_affiliate_nav("affiliate-history"); ?>

    <div class="panel">
      <div class="panel__head">
        <h2 class="panel__title">Biến động hoa hồng</h2>
        <span class="pill"><?php echo format_cash($storeTotal); ?> dòng</span>
      </div>

      <form class="toolbar" method="get" action="<?php echo store_e(BASE_URL("index.php")); ?>">
        <input type="hidden" name="module" value="client">
        <input type="hidden" name="action" value="affiliate-history">
        <div class="toolbar__field">
          <label class="toolbar__label" for="affh-reason">Lý do</label>
          <input class="input" id="affh-reason" name="reason" type="search" maxlength="64"
            value="<?php echo store_e(stripslashes($storeReason)); ?>" placeholder="Ví dụ: hoa hồng">
        </div>
        <div class="toolbar__field">
          <label class="toolbar__label" for="affh-time">Khoảng ngày</label>
          <input class="input" id="affh-time" name="time" type="text" inputmode="numeric"
            placeholder="2026-01-01 to 2026-01-31" value="<?php echo store_e($storeTime); ?>">
        </div>
        <div class="toolbar__field">
          <label class="toolbar__label" for="affh-short">Thời gian</label>
          <select class="select" id="affh-short" name="shortByDate">
            <option value="">Tất cả</option>
            <option value="1"<?php echo $storeShort === "1" ? " selected" : ""; ?>>Hôm nay</option>
            <option value="2"<?php echo $storeShort === "2" ? " selected" : ""; ?>>Tuần này</option>
            <option value="3"<?php echo $storeShort === "3" ? " selected" : ""; ?>>Tháng này</option>
          </select>
        </div>
        <div class="toolbar__field">
          <label class="toolbar__label" for="affh-limit">Số dòng</label>
          <select class="select" id="affh-limit" name="limit">
            <?php foreach (array(5, 10, 20, 50, 100) as $storeSize) { ?>
            <option value="<?php echo $storeSize; ?>"<?php echo $storeLimit === $storeSize ? " selected" : ""; ?>><?php echo $storeSize; ?></option>
            <?php } ?>
          </select>
        </div>
        <div class="toolbar__spacer"></div>
        <button class="btn btn--primary" type="submit">Lọc</button>
        <a class="btn btn--ghost" href="<?php echo store_e(BASE_URL("client/affiliate-history")); ?>">Bỏ lọc</a>
      </form>

      <?php if (!$storeRows) { ?>
      <div class="empty">
        <p class="empty__title">Chưa có khoản hoa hồng nào</p>
        <p class="muted m0">Khi khách bạn giới thiệu mua hàng, khoản cộng sẽ hiện ở đây.</p>
        <a class="btn btn--ghost" href="<?php echo store_e(BASE_URL("client/affiliates")); ?>">Về trang cộng tác viên</a>
      </div>
      <?php } else { ?>
      <div class="table-wrap">
        <table class="table table--zebra table--responsive">
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
            <?php foreach ($storeRows as $storeRow) { ?>
            <tr>
              <td data-label="Thời gian" class="mono small"><?php echo store_e($storeRow["create_gettime"]); ?></td>
              <td data-label="Lý do"><?php echo store_e($storeRow["reason"]); ?></td>
              <td data-label="Trước" class="num"><?php echo format_currency($storeRow["sotientruoc"]); ?></td>
              <td data-label="Thay đổi" class="num"><?php echo store_account_badge($storeRow["sotienthaydoi"]); ?></td>
              <td data-label="Sau" class="num"><?php echo format_currency($storeRow["sotienhientai"]); ?></td>
            </tr>
            <?php } ?>
          </tbody>
        </table>
      </div>
      <?php } ?>

      <?php if ($storeTotal > $storeLimit) { ?>
      <div class="panel__foot">
        <?php echo store_pager($storePage, $storeTotal, $storeLimit, array(
            "limit" => $storeLimit,
            "reason" => stripslashes($storeReason),
            "shortByDate" => $storeShort,
            "time" => $storeTime,
        ), "client/affiliate-history"); ?>
      </div>
      <?php } ?>
    </div>
  </div>
</section>
<?php
store_shell_close();
