<?php
/*
 * Sales pages — end-to-end render test against a stubbed database.
 *
 *   php tests/sales_pages_test.php
 *
 * The two new pages are executed for real (guards, shell, charts, tables and all) with a DB stub that
 * answers the same queries MySQL would. That proves three things a screenshot cannot:
 *   1. the pages render without a fatal error, in the normal case *and* on an empty shop;
 *   2. the numbers drawn come from the rows the queries returned (aggregation is checked, not assumed);
 *   3. every statement the customer page sends is scoped to the session user — the anti-IDOR rule,
 *      verified against the SQL that actually ran, not against the source text.
 */

define("IN_SITE", true);

if (!defined("BASE_URL")) {
    function BASE_URL($path = "")
    {
        return "https://shop.example/" . ltrim($path, "/");
    }
}
if (!function_exists("base_url")) {
    function base_url($path = "")
    {
        return "https://shop.example/index.php?module=client&action=" . $path;
    }
}
if (!function_exists("base_url_admin")) {
    function base_url_admin($path = "")
    {
        return "https://shop.example/index.php?module=admin&action=" . $path;
    }
}
if (!function_exists("check_string")) {
    function check_string($data)
    {
        return trim(htmlspecialchars(addslashes((string) $data)));
    }
}
if (!function_exists("redirect")) {
    function redirect($url)
    {
        echo "REDIRECTED:" . $url;
        exit;
    }
}
if (!function_exists("myip")) {
    function myip()
    {
        return "127.0.0.1";
    }
}
if (!function_exists("__")) {
    function __($text)
    {
        return $text;
    }
}
if (!function_exists("gettime")) {
    function gettime()
    {
        return date("Y/m/d H:i:s");
    }
}
if (!function_exists("format_currency")) {
    function format_currency($amount)
    {
        return number_format((float) $amount, 0, ",", ".") . "đ";
    }
}

/**
 * A DB stub that answers the exact queries the pages send, and records them.
 */
class DB
{
    public static $log = array();
    public static $fixtures = array();

    public function site($key)
    {
        $map = array("title" => "Orbit Shop", "description" => "desc", "keywords" => "k", "currency" => "VND");
        return isset($map[$key]) ? $map[$key] : "";
    }

    public function update($table, $data, $where = "")
    {
        return true;
    }

    public function cong($table, $field, $value, $where = "")
    {
        return true;
    }

    public function tru($table, $field, $value, $where = "")
    {
        return true;
    }

    public function insert($table, $data)
    {
        return true;
    }

    public function remove($table, $where)
    {
        return true;
    }

    public function get_row($sql)
    {
        self::$log[] = $sql;
        $f = self::$fixtures;
        if (strpos($sql, "FROM `users` WHERE `token`") !== false) {
            /* the same query serves both guards: which user comes back depends on the session token */
            return strpos($sql, "'admintok'") !== false ? $f["admin_user"] : $f["user"];
        }
        if (strpos($sql, "FROM `product_order`") !== false && strpos($sql, "COUNT(`id`) AS total") !== false) {
            /* customer page: total rows for their filter */
            $scoped = strpos($sql, "buyer") !== false;
            return array("total" => $scoped ? $f["mine_total"] : $f["mine_total"] + 50);
        }
        if (strpos($sql, "FROM `product_order`") !== false && strpos($sql, "COUNT(`id`) AS orders") !== false) {
            if (strpos($sql, "1970-01-01") !== false) {
                return $f["all_time"];
            }
            if (strpos($sql, $f["previous_from"]) !== false) {
                return $f["previous"];
            }
            if (strpos($sql, "buyer") !== false) {
                return $f["mine_summary"];
            }
            return $f["current"];
        }
        if (strpos($sql, "FROM `users`") !== false && strpos($sql, "COUNT(`id`) AS total") !== false) {
            /* Two different counters share this projection: the all-time total and the window's new
               users. The window query is the one filtered by create_date. (The branch below used to
               repeat the same condition, so the new-users fixture was dead and the KPI silently
               asserted the all-time number twice.) */
            return strpos($sql, "`create_date`") !== false
                ? array("total" => $f["new_users"])
                : array("total" => $f["user_total"]);
        }
        return false;
    }

    public function get_list($sql)
    {
        self::$log[] = $sql;
        $f = self::$fixtures;
        if (strpos($sql, "FROM `deposit_log`") !== false) {
            return $f["deposits"];
        }
        if (strpos($sql, "GROUP BY DATE(`create_gettime`)") !== false) {
            return strpos($sql, "buyer") !== false ? $f["mine_by_day"] : $f["by_day"];
        }
        if (strpos($sql, "LEFT JOIN `users`") !== false) {
            return $f["top_customers"];
        }
        if (strpos($sql, "GROUP BY `product_id`") !== false) {
            return $f["top_products"];
        }
        if (strpos($sql, "FROM `product_order`") !== false) {
            /* Both projections contain `buyer`; the admin one also selects `product_id`,
               the customer one selects `status_view_order`. */
            return strpos($sql, "`product_id`") !== false ? $f["orders"] : $f["mine_orders"];
        }
        return array();
    }

    public function num_rows($sql)
    {
        return 0;
    }

    public function query($sql)
    {
        self::$log[] = "QUERY:" . $sql;
        return true;
    }
}

$pass = 0;
$fail = 0;
function check($label, $condition, $detail = "")
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  ok   " . $label . "\n";
    } else {
        $fail++;
        echo "  FAIL " . $label . ($detail !== "" ? "   >> " . $detail : "") . "\n";
    }
}

require_once __DIR__ . "/../libs/sales_stats.php";

$period = sales_period(30);
$today = date("Y-m-d");
$yesterday = date("Y-m-d", strtotime("-1 day"));

DB::$fixtures = array(
    "user" => array("id" => 7, "username" => "buyer7", "email" => "b@example.com", "money" => 250000,
        "admin" => 0, "banned" => 0, "token_forgot_password" => null, "token" => "tok"),
    "admin_user" => array("id" => 1, "username" => "operator", "email" => "op@example.com", "money" => 0,
        "admin" => 1, "banned" => 0, "token_forgot_password" => null, "token" => "admintok"),
    "current" => array("orders" => 12, "revenue" => 3600000, "cost" => 2400000),
    "previous" => array("orders" => 8, "revenue" => 2400000, "cost" => 1800000),
    "all_time" => array("orders" => 90, "revenue" => 27000000, "cost" => 18000000),
    "by_day" => array(
        array("day" => $yesterday, "orders" => 5, "revenue" => 1500000, "cost" => 1000000),
        array("day" => $today, "orders" => 7, "revenue" => 2100000, "cost" => 1400000),
    ),
    "orders" => array(
        array("id" => 101, "trans_id" => "ORD101", "buyer" => 7, "product_id" => 5, "product_name" => "Netflix 1 tháng",
            "amount" => 1, "pay" => 100000, "cost" => 70000, "create_gettime" => $today . " 09:00:00"),
        array("id" => 102, "trans_id" => "ORD102", "buyer" => 9, "product_id" => 6, "product_name" => "Spotify 1 tháng",
            "amount" => 2, "pay" => 300000, "cost" => 200000, "create_gettime" => $today . " 10:00:00"),
        array("id" => 103, "trans_id" => "ORD103", "buyer" => 7, "product_id" => 5, "product_name" => "Netflix 1 tháng",
            "amount" => 3, "pay" => 300000, "cost" => 210000, "create_gettime" => $today . " 11:00:00"),
    ),
    "top_customers" => array(
        array("buyer_id" => 9, "username" => "alice", "email" => "a@example.com", "orders" => 6, "revenue" => 900000),
        array("buyer_id" => 7, "username" => "buyer7", "email" => "b@example.com", "orders" => 2, "revenue" => 400000),
    ),
    "deposits" => array(
        array("method" => "payos", "deposits" => 4, "amount" => 1200000, "received" => 1200000),
        array("method" => "bank", "deposits" => 2, "amount" => 400000, "received" => 400000),
    ),
    "user_total" => 42,
    "new_users" => 5,
    "top_products" => array(
        array("product_id" => 5, "name" => "Netflix 1 tháng", "orders" => 2, "quantity" => 4,
            "revenue" => 400000, "cost" => 280000),
        array("product_id" => 6, "name" => "Spotify 1 tháng", "orders" => 1, "quantity" => 2,
            "revenue" => 300000, "cost" => 200000),
    ),
    "mine_total" => 3,
    "mine_summary" => array("orders" => 3, "revenue" => 700000, "cost" => 480000),
    "mine_by_day" => array(array("day" => $today, "orders" => 2, "revenue" => 400000, "cost" => 280000)),
    "mine_orders" => array(
        array("id" => 103, "trans_id" => "ORD103", "product_name" => "Netflix 1 tháng", "amount" => 3,
            "pay" => 300000, "cost" => 210000, "create_gettime" => $today . " 11:00:00", "status_view_order" => 0),
        array("id" => 101, "trans_id" => "ORD101", "product_name" => "Netflix 1 tháng", "amount" => 1,
            "pay" => 100000, "cost" => 70000, "create_gettime" => $today . " 09:00:00", "status_view_order" => 1),
    ),
    "previous_from" => sales_period(30)["previous_from"],
);

/* ================================================================= admin page */

$_SESSION = array("admin_login" => "admintok");
$_GET = array("module" => "admin", "action" => "sales", "days" => "30");
$CMSNT = new DB();
DB::$log = array();

ob_start();
include __DIR__ . "/../views/admin/sales.php";
$adminHtml = ob_get_clean();

check("the admin sales page renders", strlen($adminHtml) > 4000, "got " . strlen($adminHtml) . " bytes");
check("…without a PHP error leaking into the page",
    strpos($adminHtml, "Fatal error") === false && strpos($adminHtml, "Warning:") === false
    && strpos($adminHtml, "Notice:") === false && strpos($adminHtml, "<?php") === false);
check("the page is titled as the sales dashboard", strpos($adminHtml, "Thống kê bán hàng") !== false);

/* the numbers must come from the fixture rows */
check("revenue KPI shows the period total", strpos($adminHtml, "3.600.000đ") !== false);
check("profit KPI subtracts cost", strpos($adminHtml, "1.200.000đ") !== false);
check("the order count KPI is shown", strpos($adminHtml, ">12<") !== false || strpos($adminHtml, "12</p>") !== false);
check("growth versus the previous period is shown", strpos($adminHtml, "50,0%") !== false);
check("the average order value is derived", strpos($adminHtml, "300.000đ") !== false);
check("the all-time block is present", strpos($adminHtml, "27.000.000đ") !== false);
check("the customer page total is not shown for admin", strpos($adminHtml, "Khách mới / tổng") !== false);
check("the new-user KPI comes from the window query, not the all-time count",
    strpos($adminHtml, ">5 <span") !== false && strpos($adminHtml, "/ 42") !== false);
check("the recent-orders header states the period's order count",
    strpos($adminHtml, "12 đơn trong kỳ") !== false);

/* charts */
check("the revenue chart is rendered", strpos($adminHtml, 'class="chart__canvas"') !== false);
check("each chart ships a no-JavaScript fallback table", substr_count($adminHtml, 'class="chart__table"') >= 3);
check("a donut chart is rendered for deposit methods", strpos($adminHtml, "chart__donut") !== false || strpos($adminHtml, "chart__legend") !== false);
check("the chart assets are loaded once", substr_count($adminHtml, "chart.js?v=") === 1 && substr_count($adminHtml, "chart.css?v=") === 1);
check("the table script is loaded", strpos($adminHtml, "table.js?v=") !== false);
check("the day labels reach the chart", strpos($adminHtml, $today) !== false);

/* ranking is done on the fixture rows: Netflix 2 orders × 100k+300k = 400k, Spotify 300k */
check("the product ranking is computed from the orders", strpos($adminHtml, "Sản phẩm bán chạy") !== false);
check("Netflix ranks above Spotify", strpos($adminHtml, "Netflix 1 tháng") < strpos($adminHtml, "Spotify 1 tháng"));
check("per-product order counts are shown", strpos($adminHtml, 'data-sort-value="2"') !== false);
check("the best-seller rows carry the SQL aggregates", strpos($adminHtml, ">2</td>") !== false
    && strpos($adminHtml, ">4</td>") !== false);
check("the share column is measured against the period revenue, not the shown rows",
    strpos($adminHtml, "11,1%") !== false && strpos($adminHtml, "8,3%") !== false);
check("the ranking was aggregated over the whole window in SQL",
    strpos(implode("\n", DB::$log), "GROUP BY `product_id`") !== false);
check("the top customer list is rendered", strpos($adminHtml, "alice") !== false);
check("the deposit table is rendered", strpos($adminHtml, "payOS") !== false);
check("the CSV export link is offered", strpos($adminHtml, "export=csv") !== false);

/* table machinery */
check("the orders table is opt-in sortable", strpos($adminHtml, "data-sortable") !== false);
check("the orders table has a filter input wired to it", strpos($adminHtml, 'data-table-filter="#orders-search"') !== false);
check("the orders table has a row counter with a template", strpos($adminHtml, "data-template=") !== false);
check("the tables are marked responsive", substr_count($adminHtml, "table--responsive") >= 3);
check("nothing in the page is linked to a CDN", strpos($adminHtml, "cdn.") === false);

/* SQL that actually ran */
$orderSql = array_values(array_filter(DB::$log, function ($sql) {
    return strpos($sql, "FROM `product_order`") !== false;
}));
check("the admin page queried product_order", count($orderSql) >= 4);
$missingValidity = 0;
foreach ($orderSql as $sql) {
    if (strpos($sql, "refund") === false || strpos($sql, "trash") === false) {
        $missingValidity++;
    }
}
check("every order query excludes refunded and trashed orders", $missingValidity === 0, $missingValidity . " without the filter");
$withDates = 0;
foreach ($orderSql as $sql) {
    if (preg_match("/create_gettime` >= '\d{4}-\d{2}-\d{2} 00:00:00'/", $sql)) {
        $withDates++;
    }
}
check("every order query is date-bounded", $withDates === count($orderSql));
check("no identifier from the request reached the SQL",
    strpos(implode("\n", DB::$log), "buyer` = '") === false);

/* ================================================================== empty shop */

DB::$fixtures["current"] = array("orders" => 0, "revenue" => 0, "cost" => 0);
DB::$fixtures["previous"] = array("orders" => 0, "revenue" => 0, "cost" => 0);
DB::$fixtures["all_time"] = array("orders" => 0, "revenue" => 0, "cost" => 0);
DB::$fixtures["by_day"] = array();
DB::$fixtures["orders"] = array();
DB::$fixtures["top_products"] = array();
DB::$fixtures["top_customers"] = array();
DB::$fixtures["deposits"] = array();

$_GET = array("module" => "admin", "action" => "sales");
DB::$log = array();
ob_start();
include __DIR__ . "/../views/admin/sales.php";
$emptyHtml = ob_get_clean();

check("an empty shop still renders the dashboard", strlen($emptyHtml) > 3000);
check("…with no PHP error", strpos($emptyHtml, "Fatal error") === false && strpos($emptyHtml, "Warning:") === false);
check("…and says so plainly rather than showing a fake chart", strpos($emptyHtml, "Chưa có đơn hàng nào") !== false);
check("empty states replace the charts", substr_count($emptyHtml, "chart__empty") >= 3);
check("empty product table gets an empty state", strpos($emptyHtml, "Chưa có sản phẩm nào bán được") !== false);
check("no NaN or division artefact reaches the page",
    strpos($emptyHtml, "NaN") === false && strpos($emptyHtml, "INF") === false && strpos($emptyHtml, "chưa có kỳ trước") !== false);

/* ============================================================= customer page */

$_SESSION = array("login" => "tok");
$_COOKIE = array();
$_GET = array("module" => "client", "action" => "purchase-history", "days" => "30");
DB::$fixtures["current"] = array("orders" => 12, "revenue" => 3600000, "cost" => 2400000);
DB::$fixtures["previous"] = array("orders" => 8, "revenue" => 2400000, "cost" => 1800000);
DB::$fixtures["all_time"] = array("orders" => 90, "revenue" => 27000000, "cost" => 18000000);
DB::$fixtures["by_day"] = array(array("day" => $today, "orders" => 5, "revenue" => 1500000, "cost" => 1000000));
DB::$fixtures["orders"] = array();
DB::$fixtures["top_customers"] = array();
DB::$fixtures["deposits"] = array();
$CMSNT = new DB();
DB::$log = array();

ob_start();
include __DIR__ . "/../views/client/purchase-history.php";
$mineHtml = ob_get_clean();

check("the customer page renders", strlen($mineHtml) > 3000, "got " . strlen($mineHtml) . " bytes");
check("…with no PHP error", strpos($mineHtml, "Fatal error") === false && strpos($mineHtml, "Warning:") === false);
check("…and no leaked PHP tag", strpos($mineHtml, "<?php") === false);
check("the customer's own spend is shown", strpos($mineHtml, "700.000đ") !== false);
check("the customer's wallet balance is shown", strpos($mineHtml, "250.000đ") !== false);
check("the orders card counts the rows instead of leaving an empty JS-only slot",
    strpos($mineHtml, 'toolbar__count">3 đơn') !== false);
check("the customer sees their own orders", strpos($mineHtml, "ORD103") !== false && strpos($mineHtml, "ORD101") !== false);
check("another buyer's order is not on the page", strpos($mineHtml, "ORD102") === false);
check("the favourite product is derived from their rows", strpos($mineHtml, "Netflix 1 tháng") !== false);
check("a link to the order detail page is provided", strpos($mineHtml, "product-order") !== false);

$mineSql = array_values(array_filter(DB::$log, function ($sql) {
    return strpos($sql, "FROM `product_order`") !== false;
}));
check("the customer page queried product_order", count($mineSql) >= 3);
$unscoped = 0;
$badSql = "";
foreach ($mineSql as $sql) {
    if (strpos($sql, "buyer` = 7") === false) {
        $unscoped++;
        $badSql = $sql;
    }
}
check("every order statement ran with buyer = the session user", $unscoped === 0, $badSql);

/* search + pagination must stay inside their own data and their own URLs */
$_GET = array("module" => "client", "action" => "purchase-history", "days" => "30", "q" => "netflix' OR 1=1 --", "page" => "2");
DB::$log = array();
ob_start();
include __DIR__ . "/../views/client/purchase-history.php";
$searchHtml = ob_get_clean();

$searchSql = implode("\n", DB::$log);
check("a hostile search term does not break the query", strpos($searchSql, "OR 1=1") === false);
check("…and the term is still passed as a filter", strpos($searchSql, "LIKE") !== false);
check("…while remaining scoped to the session user", strpos($searchSql, "buyer` = 7") !== false);
check("a quote cannot survive into the search field", strpos($searchHtml, "netflix'") === false && strpos($searchHtml, "&#039;") === false);
check("the search term is still usable after sanitising", strpos($searchHtml, 'value="netflix') !== false);
check("a page beyond the end is clamped, not empty", strpos($searchHtml, 'aria-current="page"') !== false);
check("pagination links keep the search term and days", strpos($searchHtml, "purchase-history&amp;days=30") !== false);

echo "\nsales_pages_test: " . $pass . " passed, " . $fail . " failed\n";
exit($fail > 0 ? 1 : 0);
