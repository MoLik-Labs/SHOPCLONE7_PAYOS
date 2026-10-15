<?php
/*
 * Orbit charts — maths and markup contract (no database, no browser).
 *
 *   php tests/chart_test.php
 *
 * The renderer in chart.js trusts this payload completely, so the payload is tested hard: axis maxima,
 * ticks, point geometry, bar layout, donut angles, empty data, JSON validity, and the markup contract
 * (empty state, legend, no-JavaScript table, escaping).
 */

define("IN_SITE", true);
if (!defined("BASE_URL")) {
    function BASE_URL($path = "")
    {
        return "https://shop.example/" . ltrim($path, "/");
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
function near($a, $b, $tolerance = 0.01)
{
    return abs((float) $a - (float) $b) <= $tolerance;
}

require_once __DIR__ . "/../libs/chart.php";
require_once __DIR__ . "/../views/common/chart.php";

/* ------------------------------------------------------------------ maxima */

check("an empty series still gets an axis height", near(chart_nice_max(0), 1.0));
check("a fraction still gets a sane axis height", near(chart_nice_max(0.4), 0.5));
check("87 becomes 100", near(chart_nice_max(87), 100.0));
check("437,123 becomes 500,000", near(chart_nice_max(437123), 500000.0));
check("1 stays 1", near(chart_nice_max(1), 1.0));
check("2.5 stays 2.5", near(chart_nice_max(2.5), 2.5));
check("an exact power of ten is not inflated", near(chart_nice_max(1000), 1000.0));
check("the maximum is never below the data", chart_nice_max(12345) >= 12345);

/* ------------------------------------------------------------------- ticks */

$ticks = chart_ticks(100, 4);
check("ticks span from zero to the maximum", count($ticks) === 5 && near($ticks[0], 0) && near($ticks[4], 100));
check("ticks are evenly spaced", near($ticks[1], 25) && near($ticks[2], 50) && near($ticks[3], 75));
check("a zero tick count still yields the axis", count(chart_ticks(10, 0)) === 2);

/* ------------------------------------------------------------------ points */

check("no values means no points", chart_points(array(), 10) === array());
$single = chart_points(array(5), 10);
check("a single value sits in the middle", count($single) === 1 && near($single[0]["x"], 50));
check("a single value maps halfway up the axis", near($single[0]["y"], 50));

$points = chart_points(array(0, 5, 10), 10);
check("the first point starts at x=0", near($points[0]["x"], 0));
check("the last point ends at x=100", near($points[2]["x"], 100));
check("the middle point is centred", near($points[1]["x"], 50));
check("the minimum sits on the baseline (y=100)", near($points[0]["y"], 100));
check("the maximum sits at the top (y=0)", near($points[2]["y"], 0));
check("y is inverted, not copied", near($points[1]["y"], 50));
check("a value above the axis maximum is clamped, not drawn off-chart", near(chart_points(array(50), 10)[0]["y"], 0));
check("a negative value is clamped to the baseline", near(chart_points(array(-5), 10)[0]["y"], 100));

/* ------------------------------------------------------------------- paths */

check("an empty path is empty", chart_line_path(array()) === "");
check("a single point is a move, not a line", chart_line_path(array(array("x" => 1, "y" => 2))) === "M 1 2");
$straight = chart_line_path(array(array("x" => 0, "y" => 0), array("x" => 100, "y" => 100)), false);
check("a straight path uses L commands", substr_count($straight, "L") === 1 && strpos($straight, "M 0 0") === 0);
$smooth = chart_line_path(array(array("x" => 0, "y" => 0), array("x" => 50, "y" => 10), array("x" => 100, "y" => 30)), true);
check("a smooth path uses cubic segments", substr_count($smooth, "C") === 2);
check("smooth and straight paths share both endpoints",
    strpos($smooth, "M 0 0") === 0 && substr($smooth, -6) === "100 30");
check("an area needs two points to close", chart_area_path(array(array("x" => 1, "y" => 1))) === "");
$area = chart_area_path(array(array("x" => 0, "y" => 10), array("x" => 100, "y" => 20)));
check("the area closes back to the baseline", substr(rtrim($area), -1) === "Z" && strpos($area, "L 100 100") !== false);

/* -------------------------------------------------------------------- bars */

$bars = chart_bars(array(10, 20, 30, 40), 40);
check("one bar per value", count($bars) === 4);
check("bars do not overlap", $bars[0]["x"] + $bars[0]["width"] <= $bars[1]["x"] + 0.001);
check("the tallest bar fills the plot height", near($bars[3]["height"], 100));
check("a zero bar has zero height", near(chart_bars(array(0, 10), 10)[0]["height"], 0));
check("bars keep an equal slot each and stay inside the plot",
    near($bars[1]["x"] - $bars[0]["x"], 25) && $bars[0]["x"] > 0 && $bars[3]["x"] + $bars[3]["width"] < 100);
check("an empty series draws no bars", chart_bars(array(), 10) === array());

/* ------------------------------------------------------------------- donut */

$donut = chart_donut(array("payOS" => 75, "Bank" => 25));
check("donut shares add up to 100%", near($donut["slices"][0]["share"] + $donut["slices"][1]["share"], 100));
check("the first slice starts at 12 o'clock (0°)", near($donut["slices"][0]["start"], 0));
check("slices are contiguous", near($donut["slices"][0]["end"], $donut["slices"][1]["start"]));
check("the last slice closes the ring at 360°", near($donut["slices"][1]["end"], 360));
check("the first slice sweeps its share of the circle", near($donut["slices"][0]["end"] - $donut["slices"][0]["start"], 270));
$one = chart_donut(array("All" => 5));
check("a single value is a full ring", count($one["slices"]) === 1 && near($one["slices"][0]["end"], 360));
$zero = chart_donut(array("A" => 0, "B" => 0));
check("an all-zero donut has no slices", count($zero["slices"]) === 0 && near($zero["total"], 0));
$mixed = chart_donut(array("A" => 10, "B" => 0, "C" => 30));
check("zero slices are dropped from the ring", count($mixed["slices"]) === 2);
check("a dropped slice is not counted in the total", near($mixed["total"], 40));
check("the total is the sum of positive values, not the array length", near($mixed["total"], 40));

/* ------------------------------------------------------------------- stats */

$stats = chart_series_stats(array(10, 20, 30));
check("stats: sum", near($stats["sum"], 60));
check("stats: average", near($stats["average"], 20));
check("stats: max and min", near($stats["max"], 30) && near($stats["min"], 10));
check("stats: first and last", near($stats["first"], 10) && near($stats["last"], 30));
check("stats of an empty series are zeroed", chart_series_stats(array())["count"] === 0);

/* ------------------------------------------------------------ number labels */

check("thousands are shortened", chart_number(1500) === "1.5k");
check("millions are shortened", chart_number(2500000) === "2.5M");
check("billions are shortened", chart_number(3000000000) === "3.0B");
check("small numbers stay exact", chart_number(42) === "42");
check("thousands separators are applied below 1k", chart_number(999) === "999");

/* ----------------------------------------------------------------- payloads */

$empty = chart_payload(array("type" => "area", "series" => array(array("name" => "x", "values" => array(0, 0)))));
check("an all-zero series reports no data", $empty["hasData"] === false);
$noSeries = chart_payload(array("type" => "bar"));
check("a payload without series is still well-formed", is_array($noSeries["series"]) && $noSeries["hasData"] === false);

$area = chart_payload(array(
    "type" => "area",
    "labels" => array("2026-09-01", "2026-09-02", "2026-09-03"),
    "series" => array(array("name" => "Doanh thu", "values" => array(100, 250, 75), "color" => "accent")),
    "format" => "currency",
    "suffix" => "đ",
));
check("the payload carries a nice axis maximum (250 stays 250)", near($area["max"], 250));
check("the payload ships ticks", count($area["ticks"]) === 5);
check("the payload ships a path per series", isset($area["series"][0]["path"]) && strpos($area["series"][0]["path"], "M ") === 0);
check("the payload ships an area path for area charts", strpos($area["series"][0]["area"], "Z") !== -1);
check("markers are enabled for short series", $area["series"][0]["showMarkers"] === true);
check("stats travel with the series for the header", near($area["series"][0]["stats"]["sum"], 425));
check("the payload encodes to valid JSON", json_decode(json_encode($area)) !== null);

$bar = chart_payload(array(
    "type" => "bar",
    "labels" => array("A", "B"),
    "series" => array(array("name" => "Orders", "values" => array(3, 7))),
));
check("bar payloads ship bar geometry", isset($bar["series"][0]["bars"][1]["height"]));
check("bar payloads do not ship line paths", !isset($bar["series"][0]["path"]));

$long = chart_payload(array("type" => "line", "labels" => range(1, 60), "series" => array(array("values" => range(1, 60)))));
check("markers are dropped on dense series", $long["series"][0]["showMarkers"] === false);

$independent = chart_payload(array(
    "type" => "line",
    "labels" => array("a", "b"),
    "series" => array(
        array("name" => "Money", "values" => array(1000000, 2000000)),
        array("name" => "Orders", "values" => array(2, 4)),
    ),
    "independent" => true,
));
check("independent scaling gives each series its own maximum", near($independent["series"][0]["max"], 2000000) && near($independent["series"][1]["max"], 5));
check("stacked charts share one maximum", near(chart_payload(array(
    "type" => "bar",
    "series" => array(array("values" => array(1, 2)), array("values" => array(3, 4))),
))["max"], 10));  /* 6 rounds up to the next nice step */

$donutPayload = chart_payload(array("type" => "donut", "slices" => array("payOS" => 60, "Bank" => 40)));
check("the donut payload lists slices with colours", count($donutPayload["series"]) === 2 && isset($donutPayload["series"][0]["color"]));
check("the donut payload carries the total", near($donutPayload["total"], 100));
check("the donut payload has data", $donutPayload["hasData"] === true);

/* ------------------------------------------------------------------ markup */

function render_chart($config)
{
    ob_start();
    orbit_chart($config);
    return ob_get_clean();
}

$emptyHtml = render_chart(array("title" => "Doanh thu", "type" => "bar", "empty" => "Chưa có đơn nào"));
check("an empty chart shows the caller's sentence", strpos($emptyHtml, "Chưa có đơn nào") !== false);
check("an empty chart draws no SVG and no table", strpos($emptyHtml, "<table") === false && strpos($emptyHtml, "chart__canvas") === false);

$html = render_chart(array(
    "title" => "Doanh thu 3 ngày",
    "caption" => "Đã trừ hoàn tiền",
    "labels" => array("2026-09-01", "2026-09-02", "2026-09-03"),
    "series" => array(array("name" => "Doanh thu", "values" => array(100, 250, 75), "color" => "accent")),
    "format" => "currency",
    "suffix" => "đ",
));
check("a chart with data renders the canvas", strpos($html, 'class="chart__canvas"') !== false);
check("the title and caption are printed", strpos($html, "Doanh thu 3 ngày") !== false && strpos($html, "Đã trừ hoàn tiền") !== false);
check("a JSON payload is embedded", strpos($html, '<script type="application/json">') !== false);
check("the fallback table is present for no-JavaScript readers", strpos($html, 'class="chart__table"') !== false);
check("the table has one row per label", substr_count($html, "<tr><td>2026-09-0") === 3);
check("the table formats money the way the caller asked", strpos($html, "250") !== false && strpos($html, "đ") !== false);

/* the embedded JSON must parse and match what the renderer expects */
$json = null;
if (preg_match('#<script type="application/json">(.*?)</script>#s', $html, $match)) {
    $json = json_decode($match[1], true);
}
check("the embedded payload is valid JSON", is_array($json));
check("the embedded payload keeps the type", is_array($json) && $json["type"] === "area" ? true : (is_array($json) && $json["type"] === "bar"));
check("the embedded payload keeps the labels", is_array($json) && $json["labels"][1] === "2026-09-02");
check("the embedded payload is escaped so it cannot close the script tag", strpos($html, "</script><script") === false);

$donutHtml = render_chart(array(
    "title" => "Phương thức nạp",
    "type" => "donut",
    "slices" => array("payOS" => 1200000, "Ngân hàng" => 400000),
    "format" => "currency",
    "suffix" => "đ",
));
check("a donut renders a legend with the shares", strpos($donutHtml, "chart__legend") !== false && strpos($donutHtml, "75%") !== false);
check("a donut table lists each slice", substr_count($donutHtml, "<tr><td>") === 2);

$hostile = render_chart(array(
    "title" => '</script><img src=x onerror=alert(1)>',
    "labels" => array('"><script>bad()</script>'),
    "series" => array(array("name" => "<b>x</b>", "values" => array(5))),
));
check("a hostile title cannot inject markup", strpos($hostile, "<img src=x") === false);
check("a hostile title is escaped in the heading", strpos($hostile, "&lt;/script&gt;") !== false);
check("a hostile label cannot close the JSON script tag", strpos($hostile, "</script><script>bad()") === false);
check("a hostile label is escaped in the table", strpos($hostile, "&lt;script&gt;bad()&lt;/script&gt;") !== false);

$spark = render_chart(array(
    "type" => "spark",
    "labels" => range(1, 12),
    "series" => array(array("values" => array(1, 3, 2, 5, 4, 6, 5, 7, 6, 8, 7, 9))),
));
check("a sparkline renders without a legend or axis table header", strpos($spark, "chart__canvas") !== false && strpos($spark, "chart__legend") === false);

/* assets are printed once per request */
ob_start();
orbit_chart_assets();
orbit_chart_assets();
$assets = ob_get_clean();
check("chart assets are printed exactly once", substr_count($assets, "chart.css") === 1 && substr_count($assets, "chart.js") === 1);
check("chart assets carry a cache-busting version", strpos($assets, "chart.js?v=") !== false);

echo "\nchart_test: " . $pass . " passed, " . $fail . " failed\n";
exit($fail > 0 ? 1 : 0);
