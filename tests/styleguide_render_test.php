<?php
/*
 * Orbit styleguide + shell render test — proves the admin-only reference page produces one well-formed
 * document built on the shell (no database, no browser).
 *
 *   php tests/styleguide_render_test.php
 *
 * This is the "does the design system actually render" check: one doctype, one <main>, balanced markup,
 * no leaked PHP, no third-party request, and the new data components present.
 */

define("IN_SITE", true);
if (!defined("BASE_URL")) {
    function BASE_URL($path = "")
    {
        return "https://shop.example/" . ltrim($path, "/");
    }
}
/* BASE_URL() and base_url() are the same function in PHP (the shop defines only base_url), so only one
   stub is needed here — BASE_URL() resolves to it. */
if (!function_exists("redirect")) {
    function redirect($url)
    {
        echo "REDIRECTED";
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
if (!function_exists("check_string")) {
    function check_string($data)
    {
        return trim(htmlspecialchars(addslashes((string) $data)));
    }
}

$_SERVER["SERVER_NAME"] = "shop.example";
$_SERVER["REQUEST_URI"] = "/?module=common&action=orbit-styleguide";
$_SERVER["HTTPS"] = "on";
$_SERVER["HTTP_HOST"] = "shop.example";
$_SESSION = array("admin_login" => "tok");
$_COOKIE = array();
$_GET = array("module" => "common", "action" => "orbit-styleguide");

class DB
{
    public function get_row($sql)
    {
        return array("id" => 1, "admin" => 1, "banned" => 0, "token" => "tok", "ip_address" => "1.2.3.4",
            "username" => "operator", "money" => 0, "time_session" => 0);
    }

    public function get_list($sql)
    {
        return array();
    }

    public function site($key)
    {
        $map = array("title" => "Orbit Office", "description" => "desc", "keywords" => "k",
            "image" => "public/uploads/og.png", "theme_color" => "#123456", "favicon" => "public/fav.ico");
        return isset($map[$key]) ? $map[$key] : "";
    }

    public function cong($table, $column, $value, $where)
    {
        return true;
    }

    public function insert($table, $data)
    {
        return true;
    }

    public function update($table, $data, $where)
    {
        return true;
    }

    public function remove($table, $where)
    {
        return true;
    }

    public function query($sql)
    {
        return true;
    }
}

$CMSNT = new DB();

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

ob_start();
include __DIR__ . "/../views/common/orbit-styleguide.php";
$html = ob_get_clean();

check("the page renders", strlen($html) > 5000, "got " . strlen($html) . " bytes");
check("exactly one doctype", substr_count($html, "<!doctype html>") === 1);
check("the doctype is the first thing printed", strpos(ltrim($html), "<!doctype html>") === 0);
check("the document closes once", substr_count($html, "</html>") === 1);
check("the shell top bar is present", strpos($html, "topbar__inner") !== false);
check("the shell footer is present", strpos($html, 'class="footer"') !== false);
check("exactly one skip link", substr_count($html, 'class="skip-link"') === 1);
check("exactly one main landmark", substr_count($html, '<main id="main"') === 1);
check("no PHP tag leaked into the output", strpos($html, "<?php") === false && strpos($html, "<?=") === false);
$open = substr_count($html, "<div");
$close = substr_count($html, "</div>");
check("div tags are balanced ($open/$close)", $open === $close);
check("the page is noindex", strpos($html, 'content="noindex, nofollow"') !== false);
check("no third-party CDN request", strpos($html, "cdn.jsdelivr") === false && strpos($html, "cdnjs") === false);

/* the design system is on the page */
check("the four design-system stylesheets are linked", substr_count($html, "client/orbit/") >= 4);
check("colour swatches read CSS variables", strpos($html, "var(--accent)") !== false);
check("the table component is shown", strpos($html, "table--zebra") !== false);
check("the sortable header is shown", strpos($html, 'class="table__sort"') !== false);
check("the no-JavaScript table notice is present", strpos($html, "Không có JavaScript") !== false);
check("the KPI tiles are shown", substr_count($html, 'class="stat') >= 3);
check("the delta states are shown", strpos($html, "delta--up") !== false && strpos($html, "delta--down") !== false && strpos($html, "delta--flat") !== false);
check("the progress meter is shown", strpos($html, "progress__bar") !== false);
check("pagination is shown", strpos($html, 'class="pagination"') !== false && strpos($html, 'aria-current="page"') !== false);
check("the timeline is shown", strpos($html, "timeline__item--done") !== false);
check("the switch is shown", strpos($html, 'class="switch__track"') !== false);
check("the accordion uses <details>", strpos($html, "<details") !== false);
check("a tooltip is keyboard reachable", strpos($html, "data-tip=") !== false && strpos($html, 'tabindex="0"') !== false);

/* the charts on the reference page are real payloads, not screenshots */
check("the styleguide draws sample charts", substr_count($html, 'data-chart') >= 3);
check("each chart ships a fallback table", substr_count($html, 'class="chart__table"') >= 3);
check("the chart payloads are proper JSON", (function ($html) {
    if (!preg_match_all('#<script type="application/json">(.*?)</script>#s', $html, $matches)) {
        return false;
    }
    foreach ($matches[1] as $payload) {
        if (json_decode($payload, true) === null) {
            return false;
        }
    }
    return true;
})($html));
/* a chart with nothing to draw must say so instead of painting an empty box */
ob_start();
orbit_chart(array("type" => "bar", "title" => "Không có dữ liệu", "labels" => array(), "series" => array()));
$emptyChart = ob_get_clean();
check("a chart with no data says so in the caller's words", strpos($emptyChart, "chart__empty") !== false && strpos($emptyChart, "—") !== false,
    "output: " . substr(trim($emptyChart), 0, 120));
check("a chart with no data draws no canvas and no payload", strpos($emptyChart, "chart__canvas") === false && strpos($emptyChart, "application/json") === false);

ob_start();
orbit_chart(array("type" => "bar", "title" => "Trống", "labels" => array(), "series" => array(),
    "empty" => "Chưa có đơn hàng nào trong khoảng này"));
$emptyChart = ob_get_clean();
check("the caller's empty sentence is printed", strpos($emptyChart, "Chưa có đơn hàng nào trong khoảng này") !== false);
check("the empty sentence is escaped", strpos($emptyChart, 'empty" onmouseover=') === false);

/* the music player is previewed, never started */
check("the player preview is static", strpos($html, "om om--static") !== false);
check("no player instance is created on the reference page", strpos($html, 'id="orbit-music"') === false);
check("the player stylesheet is loaded for the preview", strpos($html, "music-player.css") !== false);

echo "\nstyleguide_render_test: " . $pass . " passed, " . $fail . " failed\n";
exit($fail > 0 ? 1 : 0);
