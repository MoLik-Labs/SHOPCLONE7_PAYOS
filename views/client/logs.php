<?php
/*
 * Activity log — Orbit v3.
 *
 * Read-only list of what happened to this account (sign-ins, password changes, purchases). The IP and
 * device columns are the ones the shop wrote at the time; nothing is inferred here.
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
$storeSearch = isset($_GET["q"]) ? store_account_search($_GET["q"]) : "";

$storeWhere = " `user_id` = " . (int) $getUser["id"] . " ";
if ($storeSearch !== "") {
    $storeWhere .= " AND `action` LIKE '%" . $storeSearch . "%' ";
}
$storeRows = $CMSNT->get_list(
    "SELECT `id`, `action`, `ip`, `device`, `createdate` FROM `logs`"
    . " WHERE " . $storeWhere . " ORDER BY `id` DESC LIMIT " . $storeFrom . ", " . $storeLimit
);
$storeTotalRow = $CMSNT->get_row("SELECT COUNT(`id`) AS total FROM `logs` WHERE " . $storeWhere);
$storeTotal = $storeTotalRow ? (int) $storeTotalRow["total"] : 0;

$storeShell = array(
    "title" => __("Nhật ký hoạt động") . " | " . $CMSNT->site("title"),
    "desc" => $CMSNT->site("description"),
    "nav" => store_nav_default($CMSNT),
    "body_class" => "page-account",
);
store_shell_open($storeShell);
?>
<section class="section section--tight" aria-labelledby="logs-title">
  <div class="container">
    <div class="page-head">
      <div>
        <p class="eyebrow">Tài khoản</p>
        <h1 id="logs-title">Nhật ký hoạt động</h1>
        <p class="muted m0">Các thao tác đã thực hiện trên tài khoản, mới nhất trước.</p>
      </div>
    </div>

<?php store_account_nav("logs"); ?>

    <form class="toolbar" method="get" action="<?php echo BASE_URL("index.php"); ?>" role="search">
      <input type="hidden" name="action" value="logs">
      <div class="toolbar__field">
        <label class="toolbar__label" for="logs-q">Tìm trong nhật ký</label>
        <input class="input" id="logs-q" type="search" name="q" maxlength="64"
          value="<?php echo store_e(stripslashes($storeSearch)); ?>" placeholder="Ví dụ: đăng nhập">
      </div>
      <div class="toolbar__field">
        <label class="toolbar__label" for="logs-limit">Số dòng</label>
        <select class="select" id="logs-limit" name="limit">
<?php foreach (array(10, 20, 50, 100) as $size) { ?>
          <option value="<?php echo $size; ?>"<?php echo $storeLimit === $size ? " selected" : ""; ?>><?php echo $size; ?></option>
<?php } ?>
        </select>
      </div>
      <div class="toolbar__spacer"></div>
      <button class="btn btn--primary" type="submit">Tìm</button>
      <a class="btn btn--ghost" href="<?php echo BASE_URL("client/logs"); ?>">Bỏ lọc</a>
    </form>

    <div class="panel">
      <div class="panel__head">
        <h2 class="panel__title"><?php echo (int) $storeTotal; ?> mục</h2>
        <span class="muted small">Trang <?php echo (int) $storePage; ?></span>
      </div>
      <div class="panel__body">
<?php if (empty($storeRows)) { ?>
        <div class="empty">
          <h3 class="empty__title">Chưa có hoạt động nào</h3>
          <p class="muted">Nhật ký ghi lại đăng nhập, đổi mật khẩu và các thao tác quan trọng.</p>
        </div>
<?php } else { ?>
        <div class="table-wrap">
          <table class="table table--zebra table--responsive">
            <caption class="sr-only">Nhật ký hoạt động của tài khoản</caption>
            <thead>
              <tr>
                <th scope="col">Thời gian</th>
                <th scope="col">Hoạt động</th>
                <th scope="col">IP</th>
                <th scope="col">Thiết bị</th>
              </tr>
            </thead>
            <tbody>
<?php foreach ($storeRows as $row) { ?>
              <tr>
                <td class="mono small" data-label="Thời gian"><?php echo store_e($row["createdate"]); ?></td>
                <td data-label="Hoạt động"><?php echo store_e($row["action"]); ?></td>
                <td class="mono small" data-label="IP"><?php echo store_e($row["ip"]); ?></td>
                <td class="small truncate" data-label="Thiết bị"><?php echo store_e($row["device"]); ?></td>
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
            "action" => "logs",
            "limit" => $storeLimit,
            "q" => stripslashes($storeSearch),
        )); ?>
      </div>
<?php } ?>
    </div>

    <p class="orders-foot muted small">Nếu có hoạt động bạn không nhận ra, hãy <a href="<?php echo BASE_URL("client/change-password"); ?>">đổi mật khẩu</a> ngay và báo <a href="<?php echo BASE_URL("client/contact"); ?>">hỗ trợ</a>.</p>
  </div>
</section>
<?php
store_shell_close($storeShell);
