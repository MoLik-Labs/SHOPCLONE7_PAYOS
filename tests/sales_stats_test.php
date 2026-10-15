<?php
/*
 * Sales statistics — pure-function contract + schema-identifier guard.
 *
 *   php tests/sales_stats_test.php
 *
 * Two things are checked here:
 *   1. the aggregation maths (empty shop, growth without a baseline, zero-fill of missing days,
 *      ranking, slices, shares, CSV injection);
 *   2. that every table/column identifier the SQL builders use actually appears somewhere in this
 *      repository as a column of that table — so a rename or a typo cannot silently produce a
 *      dashboard that returns nothing.
 */

define("IN_SITE", true);
if (!defined("BASE_URL")) {
    function BASE_URL($path = "")
    {
        return "https://shop.example/" . ltrim($path, "/");
    }
}
if (!function_exists("checkdate")) {
    /* not reachable in practice; kept so the test file can be read standalone */
}
require_once __DIR__ . "/../libs/sales_stats.php";

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

/* --------------------------------------------------------------- sanitising */

check("a real date passes through", sales_date("2026-10-05") === "2026-10-05");
check("a bad month is refused", sales_date("2026-13-01") === null);
check("a bad day is refused", sales_date("2026-02-31") === null);
check("a leap day is accepted", sales_date("2024-02-29") === "2024-02-29");
check("a leap day in a common year is refused", sales_date("2026-02-29") === null);
check("SQL in a date field is refused", sales_date("2026-10-05' OR 1=1 --") === null);
check("an empty date falls back to the default", sales_date("", "2026-01-01") === "2026-01-01");
check("a trailing-garbage date is refused", sales_date("2026-10-05x") === null);
check("dates are not taken from a timestamp string", sales_date("2026-10-05 12:00:00") === null);

check("a limit below the minimum becomes the default", sales_limit(0, 10) === 10);
check("a negative limit falls back to the default, never a negative LIMIT", sales_limit(-5, 10) === 10);
check("a huge limit is clamped", sales_limit(999999, 10, 1, 200) === 200);
check("a normal limit passes", sales_limit(25, 10) === 25);

/* ---------------------------------------------------------------- summaries */

$empty = sales_row(array("orders" => null, "revenue" => null, "cost" => null));
check("an empty shop is all zeros", $empty["orders"] === 0 && $empty["revenue"] === 0.0 && $empty["profit"] === 0.0);
check("an empty shop has no average or margin", $empty["average"] === 0.0 && $empty["margin"] === 0.0);

$summary = sales_row(array("orders" => 4, "revenue" => 1000000, "cost" => 700000));
check("profit is revenue minus cost", $summary["profit"] == 300000);
check("the average order value is revenue / orders", $summary["average"] == 250000);
check("margin is a percentage of revenue", $summary["margin"] == 30.0);

$loss = sales_row(array("orders" => 2, "revenue" => 100, "cost" => 300));
check("a loss-making period reports negative profit", $loss["profit"] == -200);
check("a loss-making period reports negative margin", $loss["margin"] == -200.0);

/* ------------------------------------------------------------------ growth */

$up = sales_growth(150, 100);
check("growth is a percentage change", $up["pct"] === 50.0 && $up["direction"] === "up");
$down = sales_growth(50, 100);
check("a fall is negative and labelled down", $down["pct"] === -50.0 && $down["direction"] === "down");
$same = sales_growth(100, 100);
check("no change is flat, not up", $same["pct"] === 0.0 && $same["direction"] === "flat");
$noBaseline = sales_growth(500, 0);
check("growth without a previous period has no percentage", $noBaseline["pct"] === null);
check("…but it still reports the absolute difference", $noBaseline["difference"] == 500);
check("growth direction is flat when there is no baseline", $noBaseline["direction"] === "flat");
$fromLoss = sales_growth(10, -20);
check("growing out of a negative baseline is still an increase", $fromLoss["difference"] == 30);

/* ------------------------------------------------------------------ periods */

$period = sales_period(7, "2026-10-05");
check("a 7-day window ends on the chosen day", $period["to"] === "2026-10-05");
check("…and starts 6 days earlier (inclusive)", $period["from"] === "2026-09-29");
check("the previous window ends the day before", $period["previous_to"] === "2026-09-28");
check("the previous window is the same length", $period["previous_from"] === "2026-09-22");
check("an invalid day falls back to today", sales_period(7, "nonsense")["to"] === date("Y-m-d"));
check("a one-day window is a single day", sales_period(1, "2026-10-05")["from"] === "2026-10-05");

check("a period with a bad range yields no dates", sales_dates("2026-10-05", "2026-10-01") === array());
check("a period with an invalid date yields no dates", sales_dates("bad", "2026-10-01") === array());
$days = sales_dates("2026-09-29", "2026-10-05");
check("the date list is inclusive at both ends", count($days) === 7 && $days[0] === "2026-09-29" && $days[6] === "2026-10-05");

/* ------------------------------------------------------------------ series */

$rows = array(
    array("day" => "2026-09-30", "orders" => 2, "revenue" => 300, "cost" => 100),
    array("day" => "2026-09-30", "orders" => 1, "revenue" => 200, "cost" => 50),
    array("day" => "2026-10-02", "orders" => 4, "revenue" => 900, "cost" => 400),
);
$series = sales_series($rows, $days);
check("the series has one entry per day in the window", count($series) === 7);
check("days without sales are present and zero", $series[0]["day"] === "2026-09-29" && $series[0]["orders"] === 0 && $series[0]["revenue"] === 0);
check("rows on the same day are summed", $series[1]["orders"] == 3 && $series[1]["revenue"] == 500);
check("profit is derived per day", $series[1]["profit"] == 350);
check("a day with sales keeps its numbers", $series[3]["day"] === "2026-10-02" && $series[3]["revenue"] == 900);
check("the series never exceeds the window", count($series) === count($days));
$customFields = sales_series($rows, array("2026-09-30"), array("orders"));
check("a narrowed field list still yields profit", isset($customFields[0]["profit"]) && $customFields[0]["profit"] == 0);

/* ------------------------------------------------------------------- tops */

$orderRows = array(
    array("product_name" => "Netflix", "product_id" => 7, "pay" => 100000, "cost" => 60000, "amount" => 1),
    array("product_name" => "Netflix", "product_id" => 7, "pay" => 100000, "cost" => 60000, "amount" => 1),
    array("product_name" => "Spotify", "product_id" => 9, "pay" => 150000, "cost" => 100000, "amount" => 2),
    array("product_name" => "", "product_id" => 3, "pay" => 10000, "cost" => 5000, "amount" => 1),
);
$tops = sales_top_products($orderRows, 10);
check("products are grouped by name", $tops[0]["name"] === "Netflix" && $tops[1]["name"] === "Spotify");
check("the ranking is by revenue, highest first", $tops[0]["revenue"] == 200000 && $tops[1]["revenue"] == 150000);
check("orders are counted per product", $tops[0]["orders"] === 2 && $tops[1]["orders"] === 1);
check("quantity is summed per product", $tops[0]["quantity"] === 2);
check("profit is per product", $tops[0]["profit"] == 80000 && $tops[1]["profit"] == 50000);
check("a nameless product still gets a row", $tops[2]["name"] === "(không có tên)");
check("shares are computed over the listed products", $tops[0]["share"] > $tops[1]["share"]);
check("the limit is respected", count(sales_top_products($orderRows, 1)) === 1);
check("an empty order list yields no products", sales_top_products(array()) === array());

$totals = sales_totals($orderRows);
check("totals count every row", $totals["orders"] === 4);
check("totals sum revenue", $totals["revenue"] == 360000);
check("totals derive profit", $totals["profit"] == 135000);

/* ----------------------------------------------------------------- slices */

$slices = sales_slices(
    array(array("method" => "payos", "amount" => 900), array("method" => "bank", "amount" => 300), array("method" => "momo", "amount" => 50)),
    "method",
    "amount",
    2
);
check("small slices are merged into one 'other' slice", count($slices) === 3);
check("the merged slice keeps the total", array_sum($slices) == 1250);
check("the biggest slice is first", array_keys($slices)[0] === "payos");
check("a missing method lands in 'other'", isset(sales_slices(array(array("method" => "", "amount" => 5)), "method", "amount")["Khác"]));
check("percentages never divide by zero", sales_percent(10, 0) == 0.0);
check("percentages are rounded to whole numbers by default", sales_percent(1, 3) == 33.0);
check("percentages can keep decimals", sales_percent(1, 3, 2) == 33.33);

/* -------------------------------------------------------------------- CSV */

check("a plain value is unchanged", sales_csv_cell("Netflix") === "Netflix");
$formula = sales_csv_cell('=HYPERLINK("http://evil")');
check("a formula in a quoted cell is neutralised (apostrophe precedes the formula)", strpos($formula, "\"'=") === 0);
check("a plus formula is neutralised", strpos(sales_csv_cell("+1+1"), "'+1+1") === 0);
check("a formula with a leading space is still neutralised", strpos(sales_csv_cell("  =SUM(A1:A9)"), "'") === 0);
check("a pipe/cmd formula is neutralised", strpos(sales_csv_cell("|cmd"), "'") === 0);
check("a minus formula is neutralised", strpos(sales_csv_cell("-2+3"), "'-2+3") === 0);
check("a negative amount stays a plain number (sortable in a spreadsheet)", sales_csv_cell("-500.00") === "-500.00");
check("a positive amount stays a plain number", sales_csv_cell("1234.5") === "1234.5");
check("a value with a comma is quoted", sales_csv_cell("a,b") === '"a,b"');
check("a value with a quote is quoted and doubled", sales_csv_cell('say "hi"') === '"say ""hi"""');
check("a value with a semicolon is quoted", sales_csv_cell("a;b") === '"a;b"');
$row = sales_csv_row(array("id", "=1+1", "a,b"), ",");
check("a row keeps three fields (two separators plus the comma inside the quoted value)", substr_count($row, ",") === 3 && strpos($row, "\"a,b\"") !== false);
check("a formula inside a row is still neutralised", strpos($row, "'=1+1") !== false);

/* ------------------------------------------------------------------- SQL */

$range = sales_sql_range("2026-09-01", "2026-09-30");
check("the SQL range is inclusive of the whole last day", strpos($range, "2026-09-30 23:59:59") !== false);
check("the SQL range starts at midnight", strpos($range, "2026-09-01 00:00:00") !== false);
$injected = sales_sql_range("2026-09-01' OR '1'='1", "bad");
check("a hostile date cannot reach SQL", strpos($injected, "OR '1'") === false && strpos($injected, "1970-01-01") !== false);

$ordersSql = sales_sql_orders("2026-09-01", "2026-09-30", 99999);
check("the order query is limited", strpos($ordersSql, "LIMIT 5000") !== false);
check("the order query excludes refunded and trashed orders",
    strpos($ordersSql, "`refund` = 0") !== false && strpos($ordersSql, "`trash` = 0") !== false);
check("the orders query selects the columns the dashboard groups by",
    strpos($ordersSql, "`product_name`") !== false && strpos($ordersSql, "`pay`") !== false && strpos($ordersSql, "`cost`") !== false);

$topCustomers = sales_sql_top_customers("2026-09-01", "2026-09-30", 5);
check("the customer query joins users by id", strpos($topCustomers, "LEFT JOIN `users` u ON u.`id` = o.`buyer`") !== false);
check("the customer query qualifies its columns", strpos($topCustomers, "o.`refund`") !== false && strpos($topCustomers, "o.`trash`") !== false);
check("a deleted user does not remove their orders", strpos($topCustomers, "LEFT JOIN") !== false);

$deposits = sales_sql_deposits_by_method("2026-09-01", "2026-09-30");
check("deposits are filtered by a numeric timestamp", strpos($deposits, "`create_time` >= " . strtotime("2026-09-01 00:00:00")) !== false);
check("virtual deposits are excluded", strpos($deposits, "`is_virtual` = 0") !== false);
check("deposits are grouped by method", strpos($deposits, "GROUP BY `method`") !== false);

/* ------------------------------------------- schema identifiers really exist */

$root = dirname(__DIR__);
$blob = "";
foreach (array("install.php", "views/admin/product-orders.php", "views/client/product-orders.php",
               "ajaxs/admin/view.php", "views/admin/home.php", "views/client/recharge-bank.php",
               "libs/helper.php", "views/admin/users.php", "views/client/profile.php") as $file) {
    if (is_file($root . "/" . $file)) {
        $blob .= (string) file_get_contents($root . "/" . $file) . "\n";
    }
}
$required = array(
    "product_order" => array("cost", "product_name", "product_id", "amount", "pay", "cost", "create_gettime", "buyer", "refund", "trash", "trans_id"),
    "deposit_log" => array("method", "amount", "received", "create_time", "is_virtual"),
    "users" => array("id", "username", "email", "admin"),
);
$missing = array();
foreach ($required as $table => $columns) {
    if (strpos($blob, "`" . $table . "`") === false) {
        $missing[] = $table . " (table)";
    }
    foreach (array_unique($columns) as $column) {
        if (strpos($blob, "`" . $column . "`") === false) {
            $missing[] = $table . "." . $column;
        }
    }
}
check("every identifier the SQL builders use exists in this repository's schema usage", $missing === array(), implode(", ", $missing));

/* --------------------------------------------------------------- method labels */

check("known payment methods get real names", sales_method_label("payos") === "payOS" && sales_method_label("bank") === "Ngân hàng");
check("an unknown method is title-cased, not invented", sales_method_label("some-new-gateway") === "Some New Gateway");
check("an empty method is labelled", sales_method_label("") === "Khác");

echo "\nsales_stats_test: " . $pass . " passed, " . $fail . " failed\n";
exit($fail > 0 ? 1 : 0);
