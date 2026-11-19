<?php
/*
 * Withdraw affiliate commission — Orbit v3.
 *
 * The request form posts `WithdrawCommission` to ajaxs/client/create.php with the same field names
 * (bank, stk, name, amount); the endpoint still owns the minimum, the balance check and the ledger
 * write. Two changes on this side: a CSRF token is sent, and the session token is no longer printed
 * into the markup — create.php reads the signed-in cookie/session instead.
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
$storeStatus = isset($_GET["status"]) ? (string) $_GET["status"] : "";
$storeStatus = in_array($storeStatus, array("pending", "cancel", "completed"), true) ? $storeStatus : "";
$storeTransId = isset($_GET["transid"]) ? store_account_search($_GET["transid"], 16) : "";
$storeTime = isset($_GET["time"]) && is_string($_GET["time"]) ? trim($_GET["time"]) : "";
$storeRange = store_account_date_range($storeTime);
if ($storeRange === null) {
    $storeTime = "";
}
$storeShort = isset($_GET["shortByDate"]) ? (string) $_GET["shortByDate"] : "";

$storeWhere = " `user_id` = " . $storeUserId . " ";
if ($storeStatus !== "") {
    $storeWhere .= " AND `status` = '" . addslashes($storeStatus) . "' ";
}
if ($storeTransId !== "") {
    $storeWhere .= " AND `trans_id` LIKE '%" . $storeTransId . "%' ";
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
    " SELECT `trans_id`, `bank`, `stk`, `name`, `amount`, `status`, `reason`, `create_gettime`"
    . " FROM `aff_withdraw` WHERE " . $storeWhere . " ORDER BY `id` DESC LIMIT " . $storeFrom . ", " . $storeLimit
);
$storeTotal = (int) $CMSNT->num_rows(" SELECT `id` FROM `aff_withdraw` WHERE " . $storeWhere);

$storeBanks = array();
foreach (preg_split('/\r\n|\r|\n/', (string) $CMSNT->site("affiliate_banks")) as $storeBank) {
    $storeBank = trim($storeBank);
    if ($storeBank !== "") {
        $storeBanks[] = $storeBank;
    }
}
$storeMinimum = (float) $CMSNT->site("affiliate_min");

$storeShell = array(
    "title" => __("Rút hoa hồng") . " | " . $CMSNT->site("title"),
    "desc" => __("Gửi yêu cầu rút số dư hoa hồng về tài khoản ngân hàng của bạn."),
    "robots" => "noindex, follow",
    "nav" => store_nav_default($CMSNT),
    "body_class" => "page-content",
);
store_shell_open($storeShell);
?>
<section class="section section--tight" aria-labelledby="aff-withdraw-title">
  <div class="container">
    <div class="page-head">
      <div>
        <p class="eyebrow">Cộng tác viên</p>
        <h1 id="aff-withdraw-title">Rút hoa hồng</h1>
        <p class="muted m0">Yêu cầu được ghi nhận ngay và chuyển cho quản trị viên xử lý.</p>
      </div>
      <div class="page-head__aside">
        <span class="stat__label">Khả dụng</span>
        <strong class="stat__value"><?php echo format_currency($getUser["ref_price"]); ?></strong>
      </div>
    </div>

    <?php store_affiliate_nav("affiliate-withdraw"); ?>

    <div class="stats">
      <div class="stat">
        <span class="stat__label">Hoa hồng khả dụng</span>
        <strong class="stat__value"><?php echo format_currency($getUser["ref_price"]); ?></strong>
        <span class="stat__hint">Số có thể rút</span>
      </div>
      <div class="stat">
        <span class="stat__label">Tổng đã nhận</span>
        <strong class="stat__value"><?php echo format_currency($getUser["ref_total_price"]); ?></strong>
        <span class="stat__hint">Cộng dồn từ trước tới nay</span>
      </div>
      <div class="stat">
        <span class="stat__label">Lượt nhấp liên kết</span>
        <strong class="stat__value"><?php echo format_cash($getUser["ref_click"]); ?></strong>
        <span class="stat__hint">Từ liên kết giới thiệu</span>
      </div>
    </div>

    <div class="grid grid--2 mt-4">
      <div class="panel">
        <div class="panel__head">
          <h2 class="panel__title">Tạo yêu cầu rút</h2>
          <span class="pill">Tối thiểu <?php echo format_currency($storeMinimum); ?></span>
        </div>
        <form class="panel__body" data-withdraw-form
          data-endpoint="<?php echo store_e(BASE_URL("ajaxs/client/create.php")); ?>" novalidate>
          <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

          <div class="field">
            <label class="field__label" for="wd-bank">Ngân hàng</label>
            <?php if (!$storeBanks) { ?>
            <p class="muted m0">Shop chưa cấu hình danh sách ngân hàng. Vui lòng liên hệ hỗ trợ.</p>
            <?php } else { ?>
            <select class="select" id="wd-bank" name="bank" required>
              <option value="">Chọn ngân hàng nhận tiền</option>
              <?php foreach ($storeBanks as $storeBank) { ?>
              <option value="<?php echo store_e($storeBank); ?>"><?php echo store_e($storeBank); ?></option>
              <?php } ?>
            </select>
            <?php } ?>
          </div>

          <div class="field">
            <label class="field__label" for="wd-stk">Số tài khoản</label>
            <input class="input" id="wd-stk" name="stk" type="text" inputmode="numeric" autocomplete="off"
              maxlength="32" required>
          </div>

          <div class="field">
            <label class="field__label" for="wd-name">Tên chủ tài khoản</label>
            <input class="input" id="wd-name" name="name" type="text" autocomplete="off" maxlength="64" required>
            <p class="field__hint">Ghi đúng như trên thẻ ngân hàng, không dấu hay có dấu đều được.</p>
          </div>

          <div class="field">
            <label class="field__label" for="wd-amount">Số tiền muốn rút</label>
            <input class="input" id="wd-amount" name="amount" type="text" inputmode="numeric"
              placeholder="Ví dụ: 200000" required>
            <p class="field__hint">Tối thiểu <?php echo format_currency($storeMinimum); ?>, tối đa bằng số khả dụng.</p>
          </div>

          <p class="auth__status" data-withdraw-status role="status" aria-live="polite"></p>

          <button class="btn btn--primary btn--block" type="submit"
            <?php echo $storeBanks ? "" : "disabled"; ?>>Gửi yêu cầu</button>
        </form>
      </div>

      <div class="panel">
        <div class="panel__head">
          <h2 class="panel__title">Cách shop xử lý</h2>
        </div>
        <div class="panel__body prose">
          <ol>
            <li>Yêu cầu được ghi ở trạng thái <strong>chờ duyệt</strong> và số dư hoa hồng bị trừ ngay.</li>
            <li>Quản trị viên chuyển khoản trong giờ làm việc, rồi cập nhật trạng thái sang <strong>hoàn tất</strong>.</li>
            <li>Nếu thông tin sai, yêu cầu bị <strong>huỷ</strong> và tiền được cộng lại vào số dư hoa hồng.</li>
          </ol>
          <p class="small muted">Cần kiểm tra một yêu cầu cũ? Mã giao dịch nằm trong bảng bên dưới.</p>
          <a class="btn btn--ghost" href="<?php echo store_e(BASE_URL("client/affiliate-history")); ?>">Xem lịch sử hoa hồng</a>
        </div>
      </div>
    </div>

    <div class="panel mt-4">
      <div class="panel__head">
        <h2 class="panel__title">Lịch sử rút tiền</h2>
        <span class="pill"><?php echo format_cash($storeTotal); ?> yêu cầu</span>
      </div>

      <form class="toolbar" method="get" action="<?php echo store_e(BASE_URL("client/affiliate-withdraw")); ?>">
        <div class="toolbar__field">
          <label class="toolbar__label" for="wd-trans">Mã giao dịch</label>
          <input class="input" id="wd-trans" name="transid" type="search" maxlength="16"
            value="<?php echo store_e(stripslashes($storeTransId)); ?>">
        </div>
        <div class="toolbar__field">
          <label class="toolbar__label" for="wd-status">Trạng thái</label>
          <select class="select" id="wd-status" name="status">
            <option value="">Tất cả</option>
            <option value="pending"<?php echo $storeStatus === "pending" ? " selected" : ""; ?>>Chờ duyệt</option>
            <option value="completed"<?php echo $storeStatus === "completed" ? " selected" : ""; ?>>Hoàn tất</option>
            <option value="cancel"<?php echo $storeStatus === "cancel" ? " selected" : ""; ?>>Đã huỷ</option>
          </select>
        </div>
        <div class="toolbar__field">
          <label class="toolbar__label" for="wd-time">Khoảng ngày</label>
          <input class="input" id="wd-time" name="time" type="text" inputmode="numeric"
            placeholder="2026-01-01 to 2026-01-31" value="<?php echo store_e($storeTime); ?>">
        </div>
        <div class="toolbar__field">
          <label class="toolbar__label" for="wd-limit">Số dòng</label>
          <select class="select" id="wd-limit" name="limit">
            <?php foreach (array(5, 10, 20, 50, 100) as $storeSize) { ?>
            <option value="<?php echo $storeSize; ?>"<?php echo $storeLimit === $storeSize ? " selected" : ""; ?>><?php echo $storeSize; ?></option>
            <?php } ?>
          </select>
        </div>
        <div class="toolbar__spacer"></div>
        <button class="btn btn--primary" type="submit">Lọc</button>
        <a class="btn btn--ghost" href="<?php echo store_e(BASE_URL("client/affiliate-withdraw")); ?>">Bỏ lọc</a>
      </form>

      <?php if (!$storeRows) { ?>
      <div class="empty">
        <p class="empty__title">Chưa có yêu cầu rút nào</p>
        <p class="muted m0">Yêu cầu đầu tiên của bạn sẽ xuất hiện ở đây kèm mã giao dịch.</p>
      </div>
      <?php } else { ?>
      <div class="table-wrap">
        <table class="table table--zebra table--responsive">
          <thead>
            <tr>
              <th scope="col">Mã</th>
              <th scope="col">Thời gian</th>
              <th scope="col" class="num">Số tiền</th>
              <th scope="col">Nhận về</th>
              <th scope="col">Trạng thái</th>
              <th scope="col">Lý do</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($storeRows as $storeRow) {
                $storeStatusLabel = "Chờ duyệt";
                $storeStatusClass = "pill";
                if ($storeRow["status"] === "completed") {
                    $storeStatusLabel = "Hoàn tất";
                    $storeStatusClass = "pill pill--ok";
                } elseif ($storeRow["status"] === "cancel") {
                    $storeStatusLabel = "Đã huỷ";
                    $storeStatusClass = "pill pill--danger";
                }
            ?>
            <tr>
              <td data-label="Mã" class="mono small"><?php echo store_e($storeRow["trans_id"]); ?></td>
              <td data-label="Thời gian" class="mono small"><?php echo store_e($storeRow["create_gettime"]); ?></td>
              <td data-label="Số tiền" class="num"><?php echo format_currency($storeRow["amount"]); ?></td>
              <td data-label="Nhận về" class="small"><?php echo store_e($storeRow["bank"] . " · " . $storeRow["stk"]); ?></td>
              <td data-label="Trạng thái"><span class="<?php echo $storeStatusClass; ?>"><?php echo $storeStatusLabel; ?></span></td>
              <td data-label="Lý do" class="small muted"><?php echo store_e((string) $storeRow["reason"]); ?></td>
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
            "status" => $storeStatus,
            "transid" => stripslashes($storeTransId),
            "shortByDate" => $storeShort,
            "time" => $storeTime,
        ), "client/affiliate-withdraw"); ?>
      </div>
      <?php } ?>
    </div>
  </div>
</section>
<?php
store_shell_close();
