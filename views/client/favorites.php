<?php
/*
 * Favourites — Orbit v3.
 *
 * One JOIN returns the saved products (a deleted product simply stops appearing), and one grouped
 * query returns the stock for the whole page. The old page asked for the product row twice and then
 * ran COUNT(product_stock) per row; a 20-item page cost 60 queries, this costs 3.
 *
 * Removing an item keeps the existing endpoint and action (`ajaxs/client/remove.php`,
 * `removeFavorite`) so the button behaves exactly as before.
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

$storeLimit = store_account_limit(isset($_GET["limit"]) ? $_GET["limit"] : 10, 10);
$storePage = store_account_page(isset($_GET["page"]) ? $_GET["page"] : 1);
$storeFrom = ($storePage - 1) * $storeLimit;

$storeFavorites = $CMSNT->get_list(
    "SELECT f.`id` AS favorite_id, f.`create_gettime`, p.`id` AS product_id, p.`name`, p.`slug`,"
    . " p.`price`, p.`code`, p.`supplier_id`, p.`api_stock`"
    . " FROM `favorites` f INNER JOIN `products` p ON p.`id` = f.`product_id`"
    . " WHERE f.`user_id` = " . (int) $getUser["id"] . " AND p.`status` = 1"
    . " ORDER BY f.`id` DESC LIMIT " . $storeFrom . ", " . $storeLimit
);
$storeFavoriteTotal = $CMSNT->get_row(
    "SELECT COUNT(f.`id`) AS total FROM `favorites` f INNER JOIN `products` p ON p.`id` = f.`product_id`"
    . " WHERE f.`user_id` = " . (int) $getUser["id"] . " AND p.`status` = 1"
);
$storeFavoriteTotal = $storeFavoriteTotal ? (int) $storeFavoriteTotal["total"] : 0;

/* One stock query for every product on the page. */
$storeStock = array();
$storeManualCodes = array();
foreach ($storeFavorites as $favorite) {
    if ((int) $favorite["supplier_id"] === 0 && (string) $favorite["code"] !== "") {
        $storeManualCodes[] = "'" . addslashes($favorite["code"]) . "'";
    }
}
if (!empty($storeManualCodes)) {
    foreach ($CMSNT->get_list(
        "SELECT `product_code`, COUNT(`id`) AS `c` FROM `product_stock`"
        . " WHERE `product_code` IN (" . implode(", ", array_unique($storeManualCodes)) . ") GROUP BY `product_code`"
    ) as $stockRow) {
        $storeStock[(string) $stockRow["product_code"]] = (int) $stockRow["c"];
    }
}

$storeShell = array(
    "title" => __("Sản phẩm yêu thích") . " | " . $CMSNT->site("title"),
    "desc" => $CMSNT->site("description"),
    "nav" => store_nav_default($CMSNT),
    "noindex" => true,
    "body_class" => "page-account",
);
store_shell_open($storeShell);
?>
<section class="section section--tight" aria-labelledby="favorites-title">
  <div class="container">
    <div class="page-head">
      <div>
        <p class="eyebrow">Tài khoản</p>
        <h1 id="favorites-title">Sản phẩm yêu thích</h1>
        <p class="muted m0">Những sản phẩm bạn đã lưu. Giá và tồn kho luôn là số hiện tại.</p>
      </div>
      <a class="btn" href="<?php echo BASE_URL("client/home"); ?>">Tiếp tục mua sắm</a>
    </div>

<?php store_account_nav("favorites"); ?>

    <div class="panel">
      <div class="panel__head">
        <h2 class="panel__title">Đã lưu (<?php echo (int) $storeFavoriteTotal; ?>)</h2>
        <span class="muted small">Trang <?php echo (int) $storePage; ?></span>
      </div>
      <div class="panel__body">
<?php if (empty($storeFavorites)) { ?>
        <div class="empty">
          <h3 class="empty__title">Chưa có sản phẩm nào được lưu</h3>
          <p class="muted">Nhấn vào biểu tượng trái tim trên trang sản phẩm để lưu lại cho lần sau.</p>
          <p class="empty__actions"><a class="btn btn--primary" href="<?php echo BASE_URL("client/home"); ?>">Xem sản phẩm</a></p>
        </div>
<?php } else { ?>
        <div class="table-wrap">
          <table class="table table--zebra table--responsive">
            <caption class="sr-only">Sản phẩm yêu thích của bạn</caption>
            <thead>
              <tr>
                <th scope="col">Sản phẩm</th>
                <th scope="col">Tồn kho</th>
                <th scope="col" class="num">Giá</th>
                <th scope="col">Đã lưu</th>
                <th scope="col"><span class="sr-only">Thao tác</span></th>
              </tr>
            </thead>
            <tbody>
<?php foreach ($storeFavorites as $favorite) {
    $isApi = (int) $favorite["supplier_id"] !== 0;
    $stock = $isApi ? (int) $favorite["api_stock"] : (isset($storeStock[(string) $favorite["code"]]) ? $storeStock[(string) $favorite["code"]] : 0);
    $href = BASE_URL("product/" . $favorite["slug"]);
    ?>
              <tr>
                <td data-label="Sản phẩm">
                  <span class="cell-stack">
                    <a class="cell-main" href="<?php echo store_e($href); ?>"><?php echo store_e($favorite["name"]); ?></a>
                    <span class="cell-sub"><?php echo $isApi ? "Giao tự động từ nhà cung cấp" : "Giao từ kho của shop"; ?></span>
                  </span>
                </td>
                <td data-label="Tồn kho">
<?php if ($stock > 0) { ?>
                  <span class="pill pill--ok">Còn <?php echo (int) $stock; ?></span>
<?php } else { ?>
                  <span class="pill pill--danger">Hết hàng</span>
<?php } ?>
                </td>
                <td class="num" data-label="Giá"><?php echo format_currency($favorite["price"]); ?></td>
                <td class="mono small" data-label="Đã lưu"><?php echo store_e($favorite["create_gettime"]); ?></td>
                <td class="table__actions">
<?php if ($stock > 0) { ?>
                  <a class="btn btn--sm btn--primary" href="<?php echo store_e($href); ?>">Mua</a>
<?php } else { ?>
                  <a class="btn btn--sm" href="<?php echo store_e($href); ?>">Xem</a>
<?php } ?>
                  <button class="btn btn--sm btn--ghost" type="button"
                    data-favorite-remove="<?php echo (int) $favorite["favorite_id"]; ?>">Bỏ lưu</button>
                </td>
              </tr>
<?php } ?>
            </tbody>
          </table>
        </div>
<?php } ?>
      </div>
<?php if ($storeFavoriteTotal > $storeLimit) { ?>
      <div class="panel__foot">
        <?php echo store_pager($storePage, $storeFavoriteTotal, $storeLimit,
            array("limit" => $storeLimit), "client/favorites"); ?>
      </div>
<?php } ?>
    </div>

    <p class="orders-foot muted small">Đơn đã mua vẫn xem được trong <a href="<?php echo BASE_URL("client/purchase-history"); ?>">lịch sử mua hàng</a> kể cả khi bạn bỏ lưu sản phẩm.</p>

    <form data-favorites-form data-endpoint="<?php echo store_e(BASE_URL("ajaxs/client/remove.php")); ?>">
      <input type="hidden" name="token" value="<?php echo store_e($getUser["token"]); ?>">
      <p class="auth__status" role="status" aria-live="polite" data-favorites-status></p>
    </form>
  </div>
</section>
<?php
store_shell_close($storeShell);
