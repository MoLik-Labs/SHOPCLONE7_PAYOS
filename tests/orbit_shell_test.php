<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}
/*
 * Orbit shell — rendering contract (no database, no framework).
 *
 *   php tests/orbit_shell_test.php
 *
 * Checked: escaping of every CMS/page value, "no copy is invented", the CMS raw blocks being passed
 * through unchanged, the nav semantics, the noindex switch, double-include safety, and that the shell
 * pulls in the music player (which itself renders nothing until the playlist has a licensed track).
 */

define("IN_SITE", true);

if (!defined("BASE_URL")) {
    function BASE_URL($path = "")
    {
        return "https://shop.example/" . ltrim($path, "/");
    }
}

class OrbitShellTestCmsnt
{
    public $values = array();

    public function site($key)
    {
        return isset($this->values[$key]) ? $this->values[$key] : "";
    }
}

global $CMSNT;
$CMSNT = new OrbitShellTestCmsnt();
$CMSNT->values = array(
    "title" => 'Orbit "Office" <script>alert(1)</script>',
    "description" => "desc & <b>bold</b>",
    "keywords" => "a,b",
    "image" => "public/uploads/og.png",
    "theme_color" => "#123456",
    "favicon" => "public/uploads/fav.png",
);

$pass = 0;
$fail = 0;
function check($label, $condition)
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  ok   " . $label . "\n";
    } else {
        $fail++;
        echo "  FAIL " . $label . "\n";
    }
}

require_once __DIR__ . "/../views/common/orbit-shell.php";
require_once __DIR__ . "/../views/common/orbit-shell.php"; /* double include must be harmless */

check("the shell loads without redeclaring anything", function_exists("orbit_shell_open") && function_exists("orbit_shell_close"));

$options = array(
    "nav" => array(
        array("label" => "Trang chủ", "href" => "https://shop.example/client/home"),
        array("label" => "<em>Nạp</em> tiền", "href" => "https://shop.example/client/recharge", "current" => true),
        array("label" => "ignored — no href"),
    ),
    "footer_links" => array(array("label" => "Support", "href" => "https://t.me/example")),
    "head" => '<link rel="stylesheet" href="https://shop.example/x.css">',
    "scripts" => '<script src="https://shop.example/x.js" defer></script>',
    "body_class" => "page-test",
    "noindex" => true,
);

ob_start();
orbit_shell_open($options);
$head = ob_get_clean();

ob_start();
orbit_shell_close($options);
$foot = ob_get_clean();

/* --- document ------------------------------------------------------------- */
check("the document opens with doctype before anything else", strpos($head, "<!doctype html>") === 0);
check("the html element carries the light storefront default", strpos($head, 'data-theme="light"') !== false);
check("the lang attribute is present", strpos($head, 'lang="vi"') !== false);
check("a viewport meta is present (mobile first)", strpos($head, 'name="viewport"') !== false);
check("noindex is emitted when asked", strpos($head, 'name="robots" content="noindex, nofollow"') !== false);

/* --- escaping ------------------------------------------------------------- */
check("the site title cannot inject markup into <title>", strpos($head, "<script>alert(1)</script>") === false);
check("…but it is still printed, escaped", strpos($head, "&lt;script&gt;alert(1)&lt;/script&gt;") !== false);
check("a nav label cannot inject markup", strpos($head, "<em>Nạp</em>") === false && strpos($head, "&lt;em&gt;Nạp&lt;/em&gt;") !== false);
check("the meta description is escaped", strpos($head, "&amp; &lt;b&gt;bold&lt;/b&gt;") !== false);
check("a nav entry without an href is skipped", strpos($head, "ignored") === false
    && substr_count($head, 'href="https://shop.example/client/home"') === 3 /* brand + desktop + mobile sheet */);
check("the current page is marked for assistive tech", strpos($head, 'aria-current="page"') !== false);
check("the fixed studio favicon and configured theme colour are present", strpos($head, "assets/brand/molik-icon.svg") !== false && strpos($head, "#123456") !== false);
check("a relative OG image is resolved through BASE_URL", strpos($head, "https://shop.example/public/uploads/og.png") !== false);
check("page-owned head markup is passed through raw (documented contract)", strpos($head, '<link rel="stylesheet" href="https://shop.example/x.css">') !== false);
check("the skip link points at the main landmark", strpos($head, 'class="skip-link" href="#main"') !== false);
check("the main landmark carries the id", strpos($head, '<main id="main"') !== false);
check("the shell links the five design-system stylesheets and theme script", substr_count($head, "public/client/orbit/") === 6
    && strpos($head, "store.css") !== false && strpos($head, "theme.js") !== false);
check("stylesheets and theme script carry cache-busting versions", substr_count($head, "?v=") === 6);
check("no third-party request appears in the shell head", strpos($head, "cdn") === false && strpos($head, "cdn.jsdelivr") === false);

/* --- footer --------------------------------------------------------------- */
check("the footer prints the provided links only", strpos($foot, "https://t.me/example") !== false && strpos($foot, "facebook") === false);
check("the footer repeats the shell's brand instead of a page label",
    substr_count($head . $foot, 'class="topbar__brand"') === 2
    && strpos($foot, 'Orbit &quot;Office&quot; &lt;script&gt;alert(1)&lt;/script&gt;') !== false);
check("page-owned scripts are passed through raw", strpos($foot, 'src="https://shop.example/x.js"') !== false);
check("the document is closed once", substr_count($foot, "</html>") === 1);
check("the footer closes what open() opened", strpos($foot, "</main>") !== false && strpos($foot, "</body>") !== false);

/* --- music player integration --------------------------------------------- */
check("an empty playlist adds no player markup", strpos($foot, 'id="orbit-music"') === false);
check("an empty playlist adds no player script", strpos($foot, "music-player.js") === false);

/* --- no invented copy ----------------------------------------------------- */
$plain = orbit_shell_config(array());
check("an unset brand falls back to the CMS title", $plain["brand_html"] === "");
check("the shell invents no footer text", count(orbit_shell_config(array("footer_links" => array()))["footer_links"]) === 0);
check("defaults are never empty strings for structural fields", $plain["lang"] === "vi" && $plain["main_class"] === "container section");
$overrideCheck = orbit_shell_config(array("title" => "", "lang" => ""));
check("an empty override keeps the CMS value instead of blanking it", $overrideCheck["title"] === $CMSNT->values["title"]);

/* --- a shop without a CMS object ------------------------------------------ */
global $CMSNT;
$saved = $CMSNT;
$CMSNT = null;
$bare = orbit_shell_config(array());
check("the shell works when no CMS object exists",
    is_string($bare["title"]) && $bare["title"] !== "" && is_array($bare["nav"]));
$CMSNT = $saved;

echo "\norbit_shell_test: " . $pass . " passed, " . $fail . " failed\n";
exit($fail > 0 ? 1 : 0);
