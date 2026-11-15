<?php
/*
 * Client — "Lịch sử mua hàng" (purchase statistics and a searchable order table).
 *
 *   /client/purchase-history                      last 30 days
 *   /client/purchase-history?days=7|30|90|365
 *   /client/purchase-history?page=2&q=netflix
 *
 * This page is read-only and shows only the signed-in customer's own rows: every query is bound to
 * `buyer = <session user id>` (an integer from the session, never from the request). It is built on the
 * Orbit shell and charts so it matches the design system, and it uses the visitor's own currency
 * preference through format_currency() like the rest of the shop.
 *
 * Why a second page instead of rewriting views/client/product-orders.php: that page carries the
 * delivery/click flow customers already use. Rewriting it now would be a frontend change nobody asked
 * for yet; this page adds the statistics beside it, and the sidebar/footer links are added only after
 * the owner sees it.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . "/../../models/is_user.php";
require_once __DIR__ . "/../common/orbit-shell.php";
require_once __DIR__ . "/../common/chart.php";
require_once __DIR__ . "/../common/pagination.php";

/* The page shows money, so keep it out of any shared cache and stop content sniffing. headers_sent() is
   checked because this view is included by the shop's router, not fetched directly: if the router has
   already produced output, the page still renders and PHP never warns. */
if (!headers_sent()) {
    header("Cache-Control: no-store");
    header("X-Content-Type-Options: nosniff");
}


$user = (int) $getUser["id"];
$days = sales_limit(isset($_GET["days"]) ? $_GET["days"] : 30, 30, 1, 365);
$period = sales_period($days);
$query = isset($_GET["q"]) ? trim((string) $_GET["q"]) : "";
/* Anything that is not a letter, a digit or a friendly separator is dropped before this term is used. */
$query = (string) preg_replace("/[^\p{L}\p{N} _\-\.#]/u", "", $query);
$query = function_exists("mb_substr") ? mb_substr($query, 0, 60) : substr($query, 0, 60);
$perPage = 20;
$requestedPage = isset($_GET["page"]) ? (int) $_GET["page"] : 1;

/* --------------------------------------------------------------- summary */

$mine = sales_row($CMSNT->get_row(
    "SELECT COUNT(`id`) AS orders, COALESCE(SUM(`pay`), 0) AS revenue, COALESCE(SUM(`cost`), 0) AS cost"
    . " FROM `product_order` WHERE `buyer` = " . $user . " AND " . sales_sql_valid()
    . " AND " . sales_sql_range($period["from"], $period["to"])
));
$previous = sales_row($CMSNT->get_row(
    "SELECT COUNT(`id`) AS orders, COALESCE(SUM(`pay`), 0) AS revenue, COALESCE(SUM(`cost`), 0) AS cost"
    . " FROM `product_order` WHERE `buyer` = " . $user . " AND " . sales_sql_valid()
    . " AND " . sales_sql_range($period["previous_from"], $period["previous_to"])
));
$growth = sales_growth($mine["revenue"], $previous["revenue"]);

$dayRows = $CMSNT->get_list(
    "SELECT DATE(`create_gettime`) AS day, COUNT(`id`) AS orders, COALESCE(SUM(`pay`), 0) AS revenue,"
    . " COALESCE(SUM(`cost`), 0) AS cost FROM `product_order` WHERE `buyer` = " . $user
    . " AND " . sales_sql_valid() . " AND " . sales_sql_range($period["from"], $period["to"])
    . " GROUP BY DATE(`create_gettime`) ORDER BY day ASC"
);
$series = sales_series($dayRows, sales_dates($period["from"], $period["to"]));
$labels = array();
$spend = array();
foreach ($series as $entry) {
    $labels[] = $entry["day"];
    $spend[] = (float) $entry["revenue"];
}

$favourite = sales_top_products($CMSNT->get_list(
    "SELECT `product_id`, `product_name`, `amount`, `pay`, `cost` FROM `product_order`"
    . " WHERE `buyer` = " . $user . " AND " . sales_sql_valid()
    . " AND " . sales_sql_range($period["from"], $period["to"]) . " ORDER BY `id` DESC LIMIT 500"
), 1);

/* ---------------------------------------------------------------- orders */

$filters = " WHERE `buyer` = " . $user . " AND " . sales_sql_valid()
    . " AND " . sales_sql_range($period["from"], $period["to"]);
if ($query !== "") {
    /*
     * The search box is not SQL: the whitespace-collapsed term is matched as a bound value through the
     * shop's own quote helper, so a stray quote cannot restructure the query. Identifiers/operators are
     * stripped from the term as well, so "%" or "_" cannot turn a search into a wildcard scan.
     */
    $like = trim(preg_replace('/\s+/', ' ', (string) check_string($query)));
    $like = str_replace(array("%", "_", "\\"), "", $like);
    if ($like !== "") {
        $filters .= " AND (`trans_id` LIKE '%" . $like . "%' OR `product_name` LIKE '%" . $like . "%')";
    }
}
$totalRow = $CMSNT->get_row("SELECT COUNT(`id`) AS total FROM `product_order`" . $filters);
$state = orbit_pagination_state($totalRow ? $totalRow["total"] : 0, $perPage, $requestedPage);
$orders = $CMSNT->get_list(
    "SELECT `id`, `trans_id`, `product_name`, `amount`, `pay`, `cost`, `create_gettime`, `status_view_order`"
    . " FROM `product_order`" . $filters
    . " ORDER BY `id` DESC LIMIT " . (int) $state["offset"] . ", " . (int) $state["per_page"]
);

$orbit = array(
    "title" => "Lịch sử mua hàng",
    "noindex" => true,
    "nav" => array(
        array("label" => "Trang chủ", "href" => BASE_URL("client/home")),
        array("label" => "Lịch sử mua hàng", "href" => BASE_URL("client/purchase-history"), "current" => true),
    ),
    /* defer stays on the <script line on purpose: tools/frontend-audit.php checks that per source line. */
    "scripts" => '<script defer src="' . BASE_URL("public/client/orbit/table.js") . '?v='
        . orbit_shell_asset_version("public/client/orbit/table.js") . '"></script>',
);
orbit_shell_open($orbit);
orbit_chart_assets();
?>

<div class="stack stack--lg">

  <header class="row row--between">
    <div>
      <h1 style="margin-bottom: var(--space-1)">Lịch sử mua hàng</h1>
      <p class="muted" style="margin:0"><?php echo htmlspecialchars($period["from"] . " → " . $period["to"], ENT_QUOTES, "UTF-8"); ?></p>
    </div>
    <div class="segmented" role="group" aria-label="Khoảng thời gian">
      <?php foreach (array(7 => "7 ngày", 30 => "30 ngày", 90 => "90 ngày") as $option => $label) { ?>
        <a href="<?php echo BASE_URL("client/purchase-history&amp;days=" . (int) $option); ?>"
           <?php echo $days === $option ? 'aria-current="page"' : ""; ?>><?php echo $label; ?></a>
      <?php } ?>
    </div>
  </header>

  <section class="stats" aria-label="Tổng quan của bạn">
    <div class="stat stat--accent">
      <p class="stat__label">Đã chi</p>
      <p class="stat__value"><?php echo format_currency($mine["revenue"]); ?></p>
      <p class="stat__foot">
        <span class="delta delta--<?php echo $growth["direction"]; ?>">
          <?php echo $growth["pct"] === null ? "chưa có kỳ trước" : htmlspecialchars(number_format($growth["pct"], 1, ",", ".") . "%", ENT_QUOTES, "UTF-8"); ?>
        </span>
        <span class="delta__note">so với kỳ trước</span>
      </p>
    </div>
    <div class="stat">
      <p class="stat__label">Số đơn</p>
      <p class="stat__value"><?php echo number_format($mine["orders"]); ?></p>
      <p class="stat__foot">Giá trị trung bình <?php echo format_currency($mine["average"]); ?></p>
    </div>
    <div class="stat">
      <p class="stat__label">Sản phẩm mua nhiều nhất</p>
      <p class="stat__value stat__value--sm"><?php echo $favourite ? htmlspecialchars($favourite[0]["name"], ENT_QUOTES, "UTF-8") : "—"; ?></p>
      <p class="stat__foot"><?php echo $favourite ? "Đã mua " . number_format($favourite[0]["orders"]) . " lần" : "Chưa có dữ liệu"; ?></p>
    </div>
    <div class="stat">
      <p class="stat__label">Số dư hiện tại</p>
      <p class="stat__value stat__value--sm"><?php echo format_currency($getUser["money"]); ?></p>
      <p class="stat__foot"><a href="<?php echo base_url("client/recharge-bank"); ?>">Nạp thêm tiền</a></p>
    </div>
  </section>

  <section class="card">
    <div class="card__body">
      <?php orbit_chart(array(
          "type" => "area",
          "title" => "Chi tiêu theo ngày",
          "caption" => "Chỉ tính đơn đã mua thành công",
          "labels" => $labels,
          "series" => array(array("name" => "Chi tiêu", "values" => $spend, "color" => "accent")),
          "height" => 220,
          "smooth" => true,
          "format" => "currency",
          "suffix" => "đ",
          "formatter" => function ($value) {
              return format_currency($value);
          },
          "label_head" => "Ngày",
          "empty" => "Bạn chưa mua gì trong khoảng này",
          "summary" => "Chi tiêu theo ngày của bạn",
      )); ?>
    </div>
  </section>

  <section class="card">
    <div class="card__header">
      <h2 class="card__title">Đơn hàng của bạn</h2>
      <span class="toolbar__count"><?php echo number_format($state["total"]); ?> đơn</span>
    </div>

    <div class="toolbar">
      <form class="row" method="get" action="<?php echo BASE_URL("index.php"); ?>" role="search">
        <input type="hidden" name="module" value="client">
        <input type="hidden" name="action" value="purchase-history">
        <input type="hidden" name="days" value="<?php echo (int) $days; ?>">
        <div class="toolbar__field">
          <label class="toolbar__label" for="q">Tìm theo mã đơn hoặc tên sản phẩm</label>
          <div class="search">
            <input class="input" type="search" id="q" name="q"
                   value="<?php echo htmlspecialchars($query, ENT_QUOTES, "UTF-8"); ?>"
                   placeholder="VD: NETFLIX hoặc mã đơn">
            <button class="btn" type="submit">Tìm</button>
          </div>
        </div>
        <?php if ($query !== "") { ?>
          <a class="btn btn--ghost btn--sm" href="<?php echo BASE_URL("client/purchase-history&amp;days=" . (int) $days); ?>">Xoá lọc</a>
        <?php } ?>
      </form>
    </div>

    <?php if ($orders) { ?>
      <div class="table-wrap">
        <table class="table table--zebra table--responsive" data-table data-sortable>
          <caption>
            <?php echo orbit_pagination_summary($state, "Hiển thị %from%–%to% trong %total% đơn"); ?>
            <?php echo $query !== "" ? " · lọc theo “" . htmlspecialchars($query, ENT_QUOTES, "UTF-8") . "”" : ""; ?>
          </caption>
          <thead>
            <tr>
              <th><button class="table__sort" type="button">Mã đơn</button></th>
              <th><button class="table__sort" type="button">Sản phẩm</button></th>
              <th class="num">Số lượng</th>
              <th class="num"><button class="table__sort" type="button">Thanh toán</button></th>
              <th><button class="table__sort" type="button">Thời gian</button></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orders as $order) { ?>
              <tr>
                <td class="mono"><?php echo htmlspecialchars((string) $order["trans_id"], ENT_QUOTES, "UTF-8"); ?></td>
                <td>
                  <span class="cell-main"><?php echo htmlspecialchars((string) $order["product_name"], ENT_QUOTES, "UTF-8"); ?></span>
                  <span class="cell-sub mono">#<?php echo (int) $order["id"]; ?></span>
                </td>
                <td class="num" data-sort-value="<?php echo (int) $order["amount"]; ?>"><?php echo number_format($order["amount"]); ?></td>
                <td class="num" data-sort-value="<?php echo (float) $order["pay"]; ?>"><?php echo format_currency($order["pay"]); ?></td>
                <td data-sort-value="<?php echo htmlspecialchars((string) $order["create_gettime"], ENT_QUOTES, "UTF-8"); ?>">
                  <?php echo htmlspecialchars((string) $order["create_gettime"], ENT_QUOTES, "UTF-8"); ?>
                </td>
                <td class="right">
                  <a class="btn btn--sm" href="<?php echo base_url("client/product-order&trans_id=" . urlencode((string) $order["trans_id"])); ?>">Xem</a>
                </td>
              </tr>
            <?php } ?>
          </tbody>
        </table>
      </div>
      <?php orbit_pagination(array(
          "page" => $state["page"],
          "per_page" => $perPage,
          "total" => $state["total"],
          "aria" => "Phân trang đơn hàng",
          "href" => function ($page) use ($days, $query) {
              return BASE_URL("client/purchase-history&amp;days=" . (int) $days
                  . ($query !== "" ? "&amp;q=" . urlencode($query) : "") . "&amp;page=" . (int) $page);
          },
      )); ?>
    <?php } else { ?>
      <div class="card__body">
        <div class="empty">
          <p class="empty__title"><?php echo $query !== "" ? "Không tìm thấy đơn nào khớp" : "Bạn chưa có đơn hàng nào"; ?></p>
          <p class="small"><?php echo $query !== ""
              ? "Thử một từ khoá khác, hoặc mở rộng khoảng thời gian."
              : "Đơn hàng của bạn sẽ xuất hiện ở đây kèm mã đơn và sản phẩm đã mua."; ?></p>
          <div class="empty__actions">
            <a class="btn btn--primary" href="<?php echo base_url("client/home"); ?>">Xem sản phẩm</a>
          </div>
        </div>
      </div>
    <?php } ?>
  </section>

  <p class="small faint">
    Số liệu tính theo thời gian máy chủ. Đơn hoàn tiền hoặc đã xoá không được tính.
    Cần giúp đỡ? <a href="<?php echo base_url("client/contact"); ?>">Liên hệ hỗ trợ</a>.
  </p>

</div>

<?php orbit_shell_close($orbit); ?>
