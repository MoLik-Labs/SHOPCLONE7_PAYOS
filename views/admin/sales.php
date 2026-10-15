<?php
/*
 * Admin — Sales analytics.
 *
 *   /admin/sales                 the dashboard (KPIs, charts, tables)
 *   /admin/sales?days=7|30|90    the window
 *   /admin/sales?export=csv      the same numbers as a spreadsheet
 *
 * Data comes from the tables this shop already uses (product_order, deposit_log, users). Nothing is
 * written here — read-only, so it is safe to look at any time.
 *
 * Two deliberate choices:
 *   1. All aggregation goes through libs/sales_stats.php, which is unit-tested without a database. The
 *      CSV export uses the same functions as the screen, so the two can never disagree.
 *   2. Every SQL string is built from validated dates/limits; the export is a filename we choose and a
 *      body we stream, never a path from the request.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . "/../../models/is_admin.php";
require_once __DIR__ . "/../common/orbit-shell.php";
require_once __DIR__ . "/../common/chart.php";

/* The page shows money, so keep it out of any shared cache and stop content sniffing. headers_sent() is
   checked because this view is included by the shop's router, not fetched directly: if the router has
   already produced output, the page still renders and PHP never warns. */
if (!headers_sent()) {
    header("Cache-Control: no-store");
    header("X-Content-Type-Options: nosniff");
}


$days = sales_limit(isset($_GET["days"]) ? $_GET["days"] : 30, 30, 1, 365);
$period = sales_period($days);
$export = isset($_GET["export"]) ? strtolower(trim((string) $_GET["export"])) : "";

/* ------------------------------------------------------------------- data */

$current = sales_row($CMSNT->get_row(sales_sql_summary($period["from"], $period["to"])));
$previous = sales_row($CMSNT->get_row(sales_sql_summary($period["previous_from"], $period["previous_to"])));
$allTime = sales_row($CMSNT->get_row(sales_sql_summary("1970-01-01", date("Y-m-d"))));

$dayRows = $CMSNT->get_list(sales_sql_by_day($period["from"], $period["to"]));
$series = sales_series($dayRows, sales_dates($period["from"], $period["to"]));
$labels = array();
$revenues = array();
$ordersSeries = array();
$profitSeries = array();
foreach ($series as $entry) {
    $labels[] = $entry["day"];
    $revenues[] = (float) $entry["revenue"];
    $ordersSeries[] = (float) $entry["orders"];
    $profitSeries[] = (float) $entry["profit"];
}

$orderRows = $CMSNT->get_list(sales_sql_orders($period["from"], $period["to"], 500));
$topProducts = sales_top_products($orderRows, 8);
$topCustomers = $CMSNT->get_list(sales_sql_top_customers($period["from"], $period["to"], 8));
$depositRows = $CMSNT->get_list(sales_sql_deposits_by_method($period["from"], $period["to"]));
$depositSlices = array();
$depositTotal = 0.0;
foreach ($depositRows as $row) {
    $label = sales_method_label($row["method"]);
    if (!isset($depositSlices[$label])) {
        $depositSlices[$label] = 0.0;
    }
    $depositSlices[$label] += (float) $row["amount"];
    $depositTotal += (float) $row["amount"];
}
$depositSlices = sales_slices(
    array_map(function ($label, $amount) {
        return array("method" => $label, "amount" => $amount);
    }, array_keys($depositSlices), array_values($depositSlices)),
    "method",
    "amount",
    6
);

$userCount = $CMSNT->get_row(sales_sql_counts()["users"]);
$newUsers = $CMSNT->get_row("SELECT COUNT(`id`) AS total FROM `users` WHERE `admin` = 0 AND `create_gettime` >= '"
    . $period["from"] . " 00:00:00' AND `create_gettime` <= '" . $period["to"] . " 23:59:59'");
$newUsersCount = $newUsers && isset($newUsers["total"]) ? (int) $newUsers["total"] : 0;
$allUsers = $userCount && isset($userCount["total"]) ? (int) $userCount["total"] : 0;

$revenueGrowth = sales_growth($current["revenue"], $previous["revenue"]);
$ordersGrowth = sales_growth($current["orders"], $previous["orders"]);
$profitGrowth = sales_growth($current["profit"], $previous["profit"]);

/* ------------------------------------------------------------ CSV export */

if ($export === "csv") {
    $fileName = "sales-" . $period["from"] . "-to-" . $period["to"] . ".csv";
    header("Content-Type: text/csv; charset=utf-8");
    /* The filename is built from validated dates only — never from request text. */
    header("Content-Disposition: attachment; filename=\"" . $fileName . "\"");
    header("X-Content-Type-Options: nosniff");
    header("Cache-Control: no-store");
    header("Pragma: no-cache");

    $out = fopen("php://output", "w");
    /* BOM so Excel opens UTF-8 Vietnamese correctly. */
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array("Kỳ báo cáo", $period["from"] . " → " . $period["to"] . " (" . $period["days"] . " ngày)"), ";");
    fputcsv($out, array(), ";");
    fputcsv($out, array("Chỉ số", "Kỳ này", "Kỳ trước", "Tăng/giảm %"), ";");
    fputcsv($out, array("Doanh thu", $current["revenue"], $previous["revenue"],
        $revenueGrowth["pct"] === null ? "" : $revenueGrowth["pct"]), ";");
    fputcsv($out, array("Chi phí", $current["cost"], $previous["cost"], ""), ";");
    fputcsv($out, array("Lợi nhuận", $current["profit"], $previous["profit"],
        $profitGrowth["pct"] === null ? "" : $profitGrowth["pct"]), ";");
    fputcsv($out, array("Đơn hàng", $current["orders"], $previous["orders"],
        $ordersGrowth["pct"] === null ? "" : $ordersGrowth["pct"]), ";");
    fputcsv($out, array("Giá trị đơn trung bình", round($current["average"], 2), round($previous["average"], 2), ""), ";");
    fputcsv($out, array("Biên lợi nhuận %", round($current["margin"], 2), round($previous["margin"], 2), ""), ";");
    fputcsv($out, array(), ";");
    fputcsv($out, array("Doanh thu theo ngày"), ";");
    fputcsv($out, array("Ngày", "Đơn hàng", "Doanh thu", "Chi phí", "Lợi nhuận"), ";");
    foreach ($series as $entry) {
        fputcsv($out, array($entry["day"], (int) $entry["orders"], $entry["revenue"], $entry["cost"], $entry["profit"]), ";");
    }
    fputcsv($out, array(), ";");
    fputcsv($out, array("Sản phẩm bán chạy"), ";");
    fputcsv($out, array("Sản phẩm", "Đơn hàng", "Số lượng", "Doanh thu", "Lợi nhuận"), ";");
    foreach ($topProducts as $product) {
        fputcsv($out, array(
            sales_csv_cell($product["name"]),
            (int) $product["orders"],
            (int) $product["quantity"],
            $product["revenue"],
            $product["profit"],
        ), ";");
    }
    fputcsv($out, array(), ";");
    fputcsv($out, array("Nạp tiền theo phương thức"), ";");
    fputcsv($out, array("Phương thức", "Số lệnh", "Số tiền", "Thực nhận"), ";");
    foreach ($depositRows as $row) {
        fputcsv($out, array(sales_csv_cell(sales_method_label($row["method"])), (int) $row["deposits"],
            (float) $row["amount"], (float) $row["received"]), ";");
    }
    fputcsv($out, array(), ";");
    fputcsv($out, array("Đơn hàng trong kỳ"), ";");
    fputcsv($out, array("Mã đơn", "Khách", "Sản phẩm", "Số lượng", "Thanh toán", "Chi phí", "Lợi nhuận", "Thời gian"), ";");
    foreach ($orderRows as $row) {
        fputcsv($out, array(
            sales_csv_cell($row["trans_id"]),
            (int) $row["buyer"],
            sales_csv_cell($row["product_name"]),
            (int) $row["amount"],
            (float) $row["pay"],
            (float) $row["cost"],
            (float) $row["pay"] - (float) $row["cost"],
            sales_csv_cell($row["create_gettime"]),
        ), ";");
    }
    fclose($out);
    exit;
}

/* ------------------------------------------------------------------- view */

$orbit = array(
    "title" => "Thống kê bán hàng",
    "noindex" => true,
    "main_class" => "container section",
    "head" => "",
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
      <h1 style="margin-bottom: var(--space-1)">Thống kê bán hàng</h1>
      <p class="muted" style="margin:0"><?php echo htmlspecialchars($period["from"], ENT_QUOTES, "UTF-8"); ?> →
        <?php echo htmlspecialchars($period["to"], ENT_QUOTES, "UTF-8"); ?>
        · so với <?php echo htmlspecialchars($period["previous_from"] . " → " . $period["previous_to"], ENT_QUOTES, "UTF-8"); ?></p>
    </div>
    <div class="btn-group">
      <div class="segmented" role="group" aria-label="Khoảng thời gian">
        <?php foreach (array(7 => "7 ngày", 30 => "30 ngày", 90 => "90 ngày") as $option => $label) { ?>
          <a href="<?php echo base_url_admin("sales&days=" . (int) $option); ?>"
             <?php echo $days === $option ? 'aria-current="page"' : ""; ?>><?php echo $label; ?></a>
        <?php } ?>
      </div>
      <a class="btn btn--ghost" href="<?php echo base_url_admin("sales&days=" . (int) $days . "&export=csv"); ?>">Xuất CSV</a>
    </div>
  </header>

  <?php if (!$current["orders"] && !$previous["orders"]) { ?>
    <div class="alert">
      <p class="alert__title">Chưa có đơn hàng nào trong <?php echo (int) $days; ?> ngày qua</p>
      <p>Bảng dưới đây sẽ tự đầy lên khi có đơn đầu tiên. Doanh thu tính từ bảng <span class="mono">product_order</span>.</p>
    </div>
  <?php } ?>

  <!-- KPI -->
  <section class="stats" aria-label="Chỉ số chính">
    <div class="stat stat--accent">
      <p class="stat__label">Doanh thu</p>
      <p class="stat__value"><?php echo format_currency($current["revenue"]); ?></p>
      <p class="stat__foot">
        <span class="delta delta--<?php echo $revenueGrowth["direction"]; ?>">
          <?php echo $revenueGrowth["pct"] === null ? "chưa có kỳ trước" : htmlspecialchars(number_format($revenueGrowth["pct"], 1, ",", "."), ENT_QUOTES, "UTF-8") . "%"; ?>
        </span>
        <span class="delta__note">so với kỳ trước (<?php echo format_currency($previous["revenue"]); ?>)</span>
      </p>
    </div>
    <div class="stat stat--ok">
      <p class="stat__label">Lợi nhuận</p>
      <p class="stat__value"><?php echo format_currency($current["profit"]); ?></p>
      <p class="stat__foot">
        <span class="delta delta--<?php echo $profitGrowth["direction"]; ?>">
          <?php echo $profitGrowth["pct"] === null ? "chưa có kỳ trước" : htmlspecialchars(number_format($profitGrowth["pct"], 1, ",", "."), ENT_QUOTES, "UTF-8") . "%"; ?>
        </span>
        <span class="delta__note">biên <?php echo number_format($current["margin"], 1, ",", "."); ?>%</span>
      </p>
    </div>
    <div class="stat">
      <p class="stat__label">Đơn hàng</p>
      <p class="stat__value"><?php echo number_format($current["orders"]); ?></p>
      <p class="stat__foot">
        <span class="delta delta--<?php echo $ordersGrowth["direction"]; ?>">
          <?php echo $ordersGrowth["pct"] === null ? "chưa có kỳ trước" : htmlspecialchars(number_format($ordersGrowth["pct"], 1, ",", "."), ENT_QUOTES, "UTF-8") . "%"; ?>
        </span>
        <span class="delta__note">giá trị TB <?php echo format_currency($current["average"]); ?></span>
      </p>
    </div>
    <div class="stat">
      <p class="stat__label">Khách mới / tổng</p>
      <p class="stat__value"><?php echo number_format($newUsersCount); ?> <span class="muted" style="font-size:var(--text-base)">/ <?php echo number_format($allUsers); ?></span></p>
      <p class="stat__foot">Chi phí hàng bán kỳ này: <?php echo format_currency($current["cost"]); ?></p>
    </div>
  </section>

  <!-- Revenue trend -->
  <section class="card">
    <div class="card__body">
      <?php orbit_chart(array(
          "type" => "area",
          "title" => "Doanh thu theo ngày",
          "caption" => "Đã trừ đơn hoàn tiền và đơn bị xoá",
          "labels" => $labels,
          "series" => array(array("name" => "Doanh thu", "values" => $revenues, "color" => "accent")),
          "height" => 260,
          "smooth" => true,
          "format" => "currency",
          "suffix" => "đ",
          "formatter" => function ($value) {
              return format_currency($value);
          },
          "label_head" => "Ngày",
          "empty" => "Chưa có doanh thu trong khoảng này",
          "summary" => "Doanh thu theo ngày, " . $period["days"] . " ngày gần nhất",
      )); ?>
    </div>
  </section>

  <!-- Two smaller charts -->
  <section class="grid grid--2">
    <div class="card">
      <div class="card__body">
        <?php orbit_chart(array(
            "type" => "bar",
            "title" => "Số đơn theo ngày",
            "labels" => $labels,
            "series" => array(array("name" => "Đơn hàng", "values" => $ordersSeries, "color" => "info")),
            "height" => 200,
            "format" => "number",
            "formatter" => function ($value) {
                return number_format((float) $value, 0, ",", ".");
            },
            "label_head" => "Ngày",
            "empty" => "Chưa có đơn hàng",
        )); ?>
      </div>
    </div>
    <div class="card">
      <div class="card__body">
        <?php orbit_chart(array(
            "type" => "donut",
            "title" => "Tiền nạp theo phương thức",
            "caption" => $depositTotal > 0
                ? "Tổng " . format_currency($depositTotal) . " · chỉ tính tiền nạp thật"
                : "",
            "slices" => $depositSlices,
            "height" => 220,
            "format" => "currency",
            "formatter" => function ($value) {
                return format_currency($value);
            },
            "empty" => "Chưa có lệnh nạp tiền trong khoảng này",
        )); ?>
      </div>
    </div>
  </section>

  <!-- Top products -->
  <section class="card">
    <div class="card__header">
      <h2 class="card__title">Sản phẩm bán chạy</h2>
      <span class="pill"><?php echo count($topProducts); ?> sản phẩm</span>
    </div>
    <?php if ($topProducts) { ?>
      <div class="table-wrap">
        <table class="table table--zebra table--responsive" data-table>
          <caption>Xếp theo doanh thu trong <?php echo (int) $days; ?> ngày</caption>
          <thead>
            <tr>
              <th>Sản phẩm</th>
              <th class="num">Đơn</th>
              <th class="num">Số lượng</th>
              <th class="num">Doanh thu</th>
              <th class="num">Lợi nhuận</th>
              <th>Tỷ trọng</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($topProducts as $product) { ?>
              <tr>
                <td>
                  <span class="cell-main"><?php echo htmlspecialchars($product["name"], ENT_QUOTES, "UTF-8"); ?></span>
                  <span class="cell-sub mono">#<?php echo (int) $product["product_id"]; ?></span>
                </td>
                <td class="num" data-sort-value="<?php echo (int) $product["orders"]; ?>"><?php echo number_format($product["orders"]); ?></td>
                <td class="num" data-sort-value="<?php echo (int) $product["quantity"]; ?>"><?php echo number_format($product["quantity"]); ?></td>
                <td class="num" data-sort-value="<?php echo (float) $product["revenue"]; ?>"><?php echo format_currency($product["revenue"]); ?></td>
                <td class="num" data-sort-value="<?php echo (float) $product["profit"]; ?>"><?php echo format_currency($product["profit"]); ?></td>
                <td>
                  <div class="progress-row">
                    <div class="progress" role="img" aria-label="<?php echo htmlspecialchars(number_format($product["share"], 1), ENT_QUOTES, "UTF-8"); ?> phần trăm">
                      <span class="progress__bar" style="width: <?php echo min(100, round($product["share"], 1)); ?>%"></span>
                    </div>
                    <span class="progress-row__value"><?php echo number_format($product["share"], 1, ",", "."); ?>%</span>
                  </div>
                </td>
              </tr>
            <?php } ?>
          </tbody>
        </table>
      </div>
    <?php } else { ?>
      <div class="card__body">
        <div class="empty">
          <p class="empty__title">Chưa có sản phẩm nào bán được</p>
          <p class="small">Khi có đơn hàng, sản phẩm bán chạy sẽ được xếp hạng ở đây.</p>
        </div>
      </div>
    <?php } ?>
  </section>

  <section class="grid grid--2">
    <!-- Top customers -->
    <div class="card">
      <div class="card__header"><h2 class="card__title">Khách mua nhiều</h2></div>
      <?php if ($topCustomers) { ?>
        <div class="table-wrap">
          <table class="table table--compact table--responsive" data-table>
            <thead>
              <tr><th>Khách hàng</th><th class="num">Đơn</th><th class="num">Doanh thu</th></tr>
            </thead>
            <tbody>
              <?php foreach ($topCustomers as $customer) { ?>
                <tr>
                  <td>
                    <span class="cell-main"><?php echo htmlspecialchars($customer["username"] !== null ? $customer["username"] : ("#" . (int) $customer["buyer_id"]), ENT_QUOTES, "UTF-8"); ?></span>
                    <span class="cell-sub"><?php echo (int) $customer["buyer_id"]; ?></span>
                  </td>
                  <td class="num" data-sort-value="<?php echo (int) $customer["orders"]; ?>"><?php echo number_format($customer["orders"]); ?></td>
                  <td class="num" data-sort-value="<?php echo (float) $customer["revenue"]; ?>"><?php echo format_currency($customer["revenue"]); ?></td>
                </tr>
              <?php } ?>
            </tbody>
          </table>
        </div>
      <?php } else { ?>
        <div class="card__body"><p class="muted small" style="margin:0">Chưa có khách hàng nào trong khoảng này.</p></div>
      <?php } ?>
    </div>

    <!-- Deposits table -->
    <div class="card">
      <div class="card__header"><h2 class="card__title">Nạp tiền theo phương thức</h2></div>
      <?php if ($depositRows) { ?>
        <div class="table-wrap">
          <table class="table table--compact table--responsive" data-table>
            <thead>
              <tr><th>Phương thức</th><th class="num">Lệnh</th><th class="num">Số tiền</th><th class="num">Thực nhận</th></tr>
            </thead>
            <tbody>
              <?php foreach ($depositRows as $row) { ?>
                <tr>
                  <td><?php echo htmlspecialchars(sales_method_label($row["method"]), ENT_QUOTES, "UTF-8"); ?></td>
                  <td class="num" data-sort-value="<?php echo (int) $row["deposits"]; ?>"><?php echo number_format($row["deposits"]); ?></td>
                  <td class="num" data-sort-value="<?php echo (float) $row["amount"]; ?>"><?php echo format_currency($row["amount"]); ?></td>
                  <td class="num" data-sort-value="<?php echo (float) $row["received"]; ?>"><?php echo format_currency($row["received"]); ?></td>
                </tr>
              <?php } ?>
            </tbody>
          </table>
        </div>
      <?php } else { ?>
        <div class="card__body"><p class="muted small" style="margin:0">Chưa có lệnh nạp tiền nào trong khoảng này.</p></div>
      <?php } ?>
    </div>
  </section>

  <!-- Recent orders -->
  <section class="card">
    <div class="card__header">
      <h2 class="card__title">Đơn hàng gần đây</h2>
      <div class="row">
        <span class="toolbar__count" id="orders-count"></span>
        <a class="btn btn--sm btn--ghost" href="<?php echo base_url_admin("product-orders"); ?>">Tất cả đơn hàng</a>
      </div>
    </div>
    <?php if ($orderRows) { ?>
      <div class="toolbar">
        <div class="toolbar__field">
          <label class="toolbar__label" for="orders-search">Tìm trong bảng</label>
          <input class="input" type="search" id="orders-search" placeholder="Mã đơn, sản phẩm, khách…">
        </div>
        <div class="toolbar__spacer"></div>
        <span class="toolbar__count" id="orders-count-inline" data-template="Hiển thị %shown% / %total% đơn"></span>
      </div>
      <div class="table-wrap">
        <table class="table table--zebra table--sticky table--responsive"
               data-table data-sortable data-table-filter="#orders-search" data-table-count="#orders-count-inline">
          <thead>
            <tr>
              <th><button class="table__sort" type="button">Mã đơn</button></th>
              <th><button class="table__sort" type="button">Sản phẩm</button></th>
              <th><button class="table__sort" type="button">Khách</button></th>
              <th><button class="table__sort" type="button">Thời gian</button></th>
              <th class="num"><button class="table__sort" type="button">Thanh toán</button></th>
              <th class="num"><button class="table__sort" type="button">Lợi nhuận</button></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_slice($orderRows, 0, 100) as $row) { ?>
              <tr>
                <td class="mono"><span class="cell-main"><?php echo htmlspecialchars((string) $row["trans_id"], ENT_QUOTES, "UTF-8"); ?></span></td>
                <td>
                  <span class="cell-main"><?php echo htmlspecialchars((string) $row["product_name"], ENT_QUOTES, "UTF-8"); ?></span>
                  <span class="cell-sub">SL <?php echo (int) $row["amount"]; ?></span>
                </td>
                <td>#<?php echo (int) $row["buyer"]; ?></td>
                <td data-sort-value="<?php echo htmlspecialchars((string) $row["create_gettime"], ENT_QUOTES, "UTF-8"); ?>">
                  <span class="cell-main"><?php echo htmlspecialchars((string) $row["create_gettime"], ENT_QUOTES, "UTF-8"); ?></span>
                </td>
                <td class="num" data-sort-value="<?php echo (float) $row["pay"]; ?>"><?php echo format_currency($row["pay"]); ?></td>
                <td class="num" data-sort-value="<?php echo (float) $row["pay"] - (float) $row["cost"]; ?>"><?php echo format_currency((float) $row["pay"] - (float) $row["cost"]); ?></td>
              </tr>
            <?php } ?>
          </tbody>
        </table>
      </div>
      <div class="pagination-bar">
        <span class="pagination-bar__info">Hiển thị tối đa 100 đơn gần nhất trong <?php echo (int) $days; ?> ngày ·
          dùng <strong>Xuất CSV</strong> để lấy toàn bộ</span>
        <a class="btn btn--sm" href="<?php echo base_url_admin("sales&days=" . (int) $days . "&export=csv"); ?>">Tải CSV</a>
      </div>
    <?php } else { ?>
      <div class="card__body">
        <div class="empty">
          <p class="empty__title">Chưa có đơn hàng</p>
          <p class="small">Đơn hàng mới sẽ xuất hiện ở đây ngay khi khách thanh toán.</p>
        </div>
      </div>
    <?php } ?>
  </section>

  <section class="card">
    <div class="card__body">
      <dl class="kv">
        <dt>Tổng doanh thu (mọi thời gian)</dt><dd class="price"><?php echo format_currency($allTime["revenue"]); ?></dd>
        <dt>Tổng đơn (mọi thời gian)</dt><dd><?php echo number_format($allTime["orders"]); ?></dd>
        <dt>Tổng lợi nhuận (mọi thời gian)</dt><dd class="price"><?php echo format_currency($allTime["profit"]); ?></dd>
      </dl>
      <p class="small faint" style="margin-top:var(--space-4)">
        Cách tính: doanh thu = tổng <span class="mono">pay</span>, chi phí = tổng <span class="mono">cost</span>,
        lợi nhuận = doanh thu − chi phí, chỉ tính đơn <span class="mono">refund = 0</span> và
        <span class="mono">trash = 0</span>. Múi giờ theo máy chủ.
      </p>
    </div>
  </section>

</div>

<?php orbit_shell_close($orbit); ?>
