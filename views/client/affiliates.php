<?php
/*
 * Affiliate programme — Orbit v3.
 *
 * Same data and the same referral link as the legacy page (`?aff=<id>`, which index.php turns into the
 * `aff` cookie), rebuilt on the shared shell: four figures, the link with a copy button, and the list of
 * referred customers with the existing date/size filters. Page size and page number are clamped by the
 * account helpers, so a URL cannot ask for 1 000 rows.
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
$storeLimit = store_account_limit(isset($_GET["limit"]) ? $_GET["limit"] : 10, 10);
$storePage = store_account_page(isset($_GET["page"]) ? $_GET["page"] : 1);
$storeFrom = ($storePage - 1) * $storeLimit;

$storeTime = isset($_GET["time"]) && is_string($_GET["time"]) ? trim($_GET["time"]) : "";
$storeRange = store_account_date_range($storeTime);
if ($storeRange === null) {
    $storeTime = "";
}

$storeShort = isset($_GET["shortByDate"]) ? (string) $_GET["shortByDate"] : "";
$storeWhere = " `ref_id` = " . $storeUserId . " ";
if ($storeRange !== null) {
    $storeWhere .= " AND `create_date` >= '" . addslashes($storeRange[0]) . "'"
        . " AND `create_date` <= '" . addslashes($storeRange[1]) . "' ";
}
if ($storeShort === "1") {
    $storeWhere .= " AND `create_date` LIKE '" . date("Y-m-d") . "%' ";
} elseif ($storeShort === "2") {
    $storeWhere .= " AND YEAR(`create_date`) = " . date("Y") . " AND WEEK(`create_date`, 1) = " . date("W") . " ";
} elseif ($storeShort === "3") {
    $storeWhere .= " AND MONTH(`create_date`) = " . date("n") . " AND YEAR(`create_date`) = " . date("Y") . " ";
} else {
    $storeShort = "";
}

$storeReferrals = $CMSNT->get_list(
    " SELECT `id`, `username`, `create_date`, `ref_amount` FROM `users` WHERE " . $storeWhere
    . " ORDER BY `id` DESC LIMIT " . $storeFrom . ", " . $storeLimit
);
$storeTotal = (int) $CMSNT->num_rows(" SELECT `id` FROM `users` WHERE " . $storeWhere);
$storeReferralCount = (int) $CMSNT->num_rows(" SELECT `id` FROM `users` WHERE `ref_id` = " . $storeUserId);

$storeRate = $CMSNT->site("affiliate_ck");
if ((int) $CMSNT->num_rows(" SELECT `id` FROM `users` WHERE `id` = " . $storeUserId . " ") > 0) {
    $storeOwnRate = getRowRealtime("users", $storeUserId, "ref_ck");
    if ((string) $storeOwnRate !== "" && (float) $storeOwnRate > 0) {
        $storeRate = $storeOwnRate;
    }
}
$storeLink = BASE_URL("?aff=" . $storeUserId);
$storeNote = (string) $CMSNT->site("affiliate_note");

$storeShell = array(
    "title" => __("Cộng tác viên") . " | " . $CMSNT->site("title"),
    "desc" => __("Hoa hồng giới thiệu, liên kết của bạn và danh sách khách đã đăng ký."),
    "robots" => "noindex, follow",
    "nav" => store_nav_default($CMSNT),
    "body_class" => "page-content",
);
store_shell_open($storeShell);
?>
<section class="section section--tight" aria-labelledby="aff-title">
  <div class="container">
    <div class="page-head">
      <div>
        <p class="eyebrow">Cộng tác viên</p>
        <h1 id="aff-title">Giới thiệu shop, nhận hoa hồng</h1>
        <p class="muted m0">Chia sẻ liên kết của bạn. Khách đăng ký và mua hàng qua liên kết đó sẽ được ghi nhận cho bạn.</p>
      </div>
    </div>

    <?php store_affiliate_nav("affiliates"); ?>

    <div class="stats">
      <div class="stat">
        <span class="stat__label">Hoa hồng khả dụng</span>
        <strong class="stat__value"><?php echo format_currency($getUser["ref_price"]); ?></strong>
        <span class="stat__hint">Rút được ngay</span>
      </div>
      <div class="stat">
        <span class="stat__label">Tổng đã nhận</span>
        <strong class="stat__value"><?php echo format_currency($getUser["ref_total_price"]); ?></strong>
        <span class="stat__hint">Cộng dồn từ trước tới nay</span>
      </div>
      <div class="stat">
        <span class="stat__label">Tỷ lệ hoa hồng</span>
        <strong class="stat__value"><?php echo store_e($storeRate); ?>%</strong>
        <span class="stat__hint">Trên mỗi đơn của khách</span>
      </div>
      <div class="stat">
        <span class="stat__label">Lượt nhấp liên kết</span>
        <strong class="stat__value"><?php echo format_cash($getUser["ref_click"]); ?></strong>
        <span class="stat__hint"><?php echo format_cash($storeReferralCount); ?> khách đã đăng ký</span>
      </div>
    </div>

    <div class="panel mt-4">
      <div class="panel__head">
        <h2 class="panel__title">Liên kết giới thiệu của bạn</h2>
        <span class="pill"><?php echo format_cash($storeReferralCount); ?> khách</span>
      </div>
      <div class="panel__body">
        <div class="copy-row">
          <label class="sr-only" for="aff-link">Liên kết giới thiệu</label>
          <input class="input" id="aff-link" type="text" readonly value="<?php echo store_e($storeLink); ?>">
          <button class="btn btn--primary" type="button" data-copy="#aff-link">Sao chép</button>
        </div>
        <div class="hero__actions mt-4">
          <a class="btn btn--ghost" href="<?php echo store_e(BASE_URL("client/affiliate-history")); ?>">Lịch sử hoa hồng</a>
          <a class="btn btn--ghost" href="<?php echo store_e(BASE_URL("client/affiliate-withdraw")); ?>">Rút hoa hồng</a>
        </div>
        <?php if ($storeNote !== "") { ?>
        <div class="prose mt-4"><?php echo $storeNote; ?></div>
        <?php } ?>
      </div>
    </div>

    <div class="panel mt-4">
      <div class="panel__head">
        <h2 class="panel__title">Khách đã giới thiệu</h2>
        <span class="pill"><?php echo format_cash($storeTotal); ?></span>
      </div>
      <form class="toolbar" method="get" action="<?php echo store_e(BASE_URL("client/affiliates")); ?>">
        <div class="toolbar__field">
          <label class="toolbar__label" for="aff-time">Khoảng ngày</label>
          <input class="input" id="aff-time" name="time" type="text" inputmode="numeric"
            placeholder="2026-01-01 to 2026-01-31" value="<?php echo store_e($storeTime); ?>">
        </div>
        <div class="toolbar__field">
          <label class="toolbar__label" for="aff-short">Thời gian</label>
          <select class="select" id="aff-short" name="shortByDate">
            <option value="">Tất cả</option>
            <option value="1"<?php echo $storeShort === "1" ? " selected" : ""; ?>>Hôm nay</option>
            <option value="2"<?php echo $storeShort === "2" ? " selected" : ""; ?>>Tuần này</option>
            <option value="3"<?php echo $storeShort === "3" ? " selected" : ""; ?>>Tháng này</option>
          </select>
        </div>
        <div class="toolbar__field">
          <label class="toolbar__label" for="aff-limit">Số dòng</label>
          <select class="select" id="aff-limit" name="limit">
            <?php foreach (array(5, 10, 20, 50, 100) as $storeSize) { ?>
            <option value="<?php echo $storeSize; ?>"<?php echo $storeLimit === $storeSize ? " selected" : ""; ?>><?php echo $storeSize; ?></option>
            <?php } ?>
          </select>
        </div>
        <div class="toolbar__spacer"></div>
        <button class="btn btn--primary" type="submit">Lọc</button>
        <a class="btn btn--ghost" href="<?php echo store_e(BASE_URL("client/affiliates")); ?>">Bỏ lọc</a>
      </form>

      <?php if (!$storeReferrals) { ?>
      <div class="empty">
        <p class="empty__title">Chưa có khách nào đăng ký qua liên kết của bạn</p>
        <p class="muted m0">Chia sẻ liên kết ở trên — lượt đăng ký đầu tiên sẽ xuất hiện tại đây.</p>
      </div>
      <?php } else { ?>
      <div class="table-wrap">
        <table class="table table--zebra table--responsive">
          <thead>
            <tr>
              <th scope="col">Khách hàng</th>
              <th scope="col">Ngày đăng ký</th>
              <th scope="col" class="num">Hoa hồng</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($storeReferrals as $storeRow) { ?>
            <tr>
              <td data-label="Khách hàng"><span class="cell-main"><?php echo store_e($storeRow["username"]); ?></span></td>
              <td data-label="Ngày đăng ký" class="mono small"><?php echo store_e($storeRow["create_date"]); ?></td>
              <td data-label="Hoa hồng" class="num"><?php echo format_currency($storeRow["ref_amount"]); ?></td>
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
            "shortByDate" => $storeShort,
            "time" => $storeTime,
        ), "client/affiliates"); ?>
      </div>
      <?php } ?>
    </div>
  </div>
</section>
<?php
store_shell_close();
