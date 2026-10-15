<?php
/*
 * Sidebar navigation — the two links round 7 added, rendered for real.
 *
 *   php tests/sidebar_links_test.php
 *
 * The admin and client sidebars are giant echo chains, so the risk is not "is the label spelled right" but
 * "did the insertion break the markup". This test includes both files with stubs, then checks that:
 *
 *   - the new links are present exactly once, with the right href, label and icon;
 *   - the active-state helper is told about the new page (so the link highlights);
 *   - every <a> the sidebars printed is closed, and no PHP leaked into the output.
 *
 * The stubs record what they were called with, so the assertions describe the wiring, not a screenshot.
 */

define("IN_SITE", true);

$GLOBALS["called"] = array("active_sidebar" => array(), "active_sidebar_client" => array(), "show_sidebar" => array(),
    "checkPermission" => array());

if (!function_exists("base_url")) {
    function base_url($path = "")
    {
        return "https://shop.example/" . ltrim((string) $path, "/");
    }
}
if (!function_exists("base_url_admin")) {
    function base_url_admin($path = "")
    {
        return "https://shop.example/?module=admin&amp;action=" . ltrim((string) $path, "/");
    }
}
if (!function_exists("__")) {
    function __($text)
    {
        return $text;
    }
}
if (!function_exists("active_sidebar")) {
    function active_sidebar($actions)
    {
        $GLOBALS["called"]["active_sidebar"][] = $actions;
        return in_array("sales", (array) $actions, true) ? "active" : "";
    }
}
if (!function_exists("active_sidebar_client")) {
    function active_sidebar_client($actions)
    {
        $GLOBALS["called"]["active_sidebar_client"][] = $actions;
        return in_array("purchase-history", (array) $actions, true) ? "active" : "";
    }
}
if (!function_exists("show_sidebar")) {
    function show_sidebar($actions)
    {
        $GLOBALS["called"]["show_sidebar"][] = $actions;
        return "";
    }
}

/**
 * The shop grants an admin full rights when the role id is 99999; the stub answers every permission the
 * sidebars ask about, and records the questions so the test can see which menus were considered.
 */
if (!function_exists("checkPermission")) {
    function checkPermission($admin_id, $role)
    {
        $GLOBALS["called"]["checkPermission"][] = $role;
        return true;
    }
}

class DB
{
    public function get_row($sql)
    {
        /* counts are read either by alias or by "COUNT(id)"; both answer 0 so no badge is printed */
        return array("total" => 0, "COUNT(id)" => 0);
    }

    public function get_list($sql)
    {
        return array();
    }

    public function site($key)
    {
        return "";
    }
}

$CMSNT = new DB();
$getUser = array("id" => 1, "admin" => 1, "username" => "orbit");

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

/**
 * Renders a sidebar the way the shop does: the view reads $CMSNT and $getUser from the global scope, so the
 * include runs with those two published.
 */
function render_sidebar($file)
{
    global $CMSNT, $getUser;
    ob_start();
    include $file;
    return (string) ob_get_clean();
}

$root = dirname(__DIR__);
$admin = render_sidebar($root . "/views/admin/sidebar.php");
$client = render_sidebar($root . "/views/client/sidebar.php");

/* ------------------------------------------------------------------- admin */

check("the admin sidebar renders", strlen($admin) > 5000, strlen($admin) . " bytes");
check("the sales link is printed exactly once", substr_count($admin, 'action=sales"') === 1,
    substr_count($admin, 'action=sales"') . " occurrence(s)");
check("the sales link carries the shop's label", substr_count($admin, "Thống kê bán hàng") === 1);
check("the sales link carries the chart icon", strpos($admin, "bx bx-line-chart") !== false);
check("the sales link is a real anchor to the sales route",
    strpos($admin, 'href="https://shop.example/?module=admin&amp;action=sales"') !== false);
check("the active-state helper is asked about the sales page",
    in_array(array("sales"), $GLOBALS["called"]["active_sidebar"], true));
$activeSales = 0;
foreach ($GLOBALS["called"]["active_sidebar"] as $call) {
    if (in_array("sales", $call, true)) {
        $activeSales++;
    }
}
check("the sales page highlight is wired exactly once", $activeSales === 1, $activeSales . " call(s)");

/* ------------------------------------------------------------------ client */

check("the client sidebar renders", strlen($client) > 500);
check("the purchase-history link is printed exactly once",
    substr_count($client, "client/purchase-history") === 1,
    substr_count($client, "client/purchase-history") . " occurrence(s)");
check("the purchase-history label is present exactly once",
    substr_count($client, "Lịch sử mua hàng") === 1);
check("the purchase-history link carries the chart-line icon",
    strpos($client, "fas fa-chart-line") !== false);
check("the active-state helper is asked about the purchase-history page",
    in_array(array("purchase-history"), $GLOBALS["called"]["active_sidebar_client"], true));

/* ------------------------------------------------------------- markup sanity */

foreach (array("admin" => $admin, "client" => $client) as $name => $html) {
    $open = substr_count($html, "<a ");
    $close = substr_count($html, "</a>");
    check("the " . $name . " sidebar closes every anchor it opens (" . $open . "/" . $close . ")", $open === $close);
    check("no PHP tag leaked into the " . $name . " sidebar output",
        strpos($html, "<?php") === false && strpos($html, "<?=") === false);
    check("no echo statement leaked as text in the " . $name . " sidebar",
        strpos($html, "echo \"") === false);
    check("no doubled query separator in the " . $name . " sidebar links",
        strpos($html, "&amp;&amp;") === false);
}

/* the client sidebar is a list of anchors only — the insertion must not have added a stray tag */
$divOpen = substr_count($client, "<div");
$divClose = substr_count($client, "</div>");
check("the client sidebar adds no unbalanced div (" . $divOpen . "/" . $divClose . ")", $divOpen === $divClose);

echo "\nsidebar_links_test: " . $pass . " passed, " . $fail . " failed\n";
exit($fail > 0 ? 1 : 0);
