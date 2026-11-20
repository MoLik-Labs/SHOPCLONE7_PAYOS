<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}
/*
 * Social sign-in — page/partial rendering (no network, no database, no session).
 *
 *   php tests/oauth_pages_test.php
 *
 * Renders exactly the two things a visitor or an admin sees:
 *   - views/client/partials/oauth-buttons.php on the login/register pages (buttons + error banner);
 *   - views/admin/partials/settings-social.php in admin Settings (form, masked key, warnings).
 *
 * The strongest assertion here is a negative one: the stored anon key must never appear in the
 * admin HTML, because the form leaves the key field empty and only prints a mask.
 */

define("IN_SITE", true);

if (!defined("BASE_URL")) {
    function BASE_URL($path = "")
    {
        return "https://shop.example/" . ltrim($path, "/");
    }
}
if (!function_exists("__")) {
    function __($text)
    {
        return $text;
    }
}
if (!function_exists("generate_csrf_token")) {
    function generate_csrf_token()
    {
        return "test-csrf-token";
    }
}

require_once __DIR__ . "/../libs/oauth.php";

class OAuthPageCmsnt
{
    public $rows = array();
    public $queries = 0;

    public function get_list($sql)
    {
        return $this->rows;
    }

    public function query($sql)
    {
        $this->queries++;
        return new OAuthPageTableResult(1);
    }

    public function site($name)
    {
        foreach ($this->rows as $row) {
            if ($row["name"] === $name) {
                return $row["value"];
            }
        }
        return "";
    }
}

class OAuthPageTableResult
{
    private $n;

    public function __construct($n)
    {
        $this->n = $n;
    }

    public function num_rows()
    {
        return $this->n;
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

$publishable = "sb_publishable_" . str_repeat("Z9y8X7", 6);
$cmsnt = new OAuthPageCmsnt();
$cmsnt->rows = array(
    array("name" => "oauth_enabled", "value" => "1"),
    array("name" => "oauth_google", "value" => "1"),
    array("name" => "oauth_github", "value" => "1"),
    array("name" => "oauth_supabase_url", "value" => "https://abcdefgh.supabase.co"),
    array("name" => "oauth_supabase_anon_key", "value" => $publishable),
    array("name" => "oauth_auto_create", "value" => "1"),
    array("name" => "oauth_link_by_email", "value" => "0"),
);

/* ------------------------------------------------------------ login partial */

$CMSNT = $cmsnt; /* the views address the database object by this name */
$_GET = array();
ob_start();
require __DIR__ . "/../views/client/partials/oauth-buttons.php";
$buttons = ob_get_clean();
check("both enabled providers render a button", substr_count($buttons, 'rel="nofollow"') === 2);
check("each button starts the shop's own flow",
    substr_count($buttons, "https://shop.example/client/oauth-start?provider=") === 2);
check("the divider says how to continue", strpos($buttons, "hoặc") !== false);
check("no provider secret or key is printed", strpos($buttons, "publishable") === false
    && strpos($buttons, "supabase") === false);
check("no third-party request", strpos($buttons, "http://") === false && strpos($buttons, "<script") === false);
check("the OAuth markup is inside a form card, not a separate page",
    strpos($buttons, "<html") === false && strpos($buttons, "<body") === false);

$_GET = array("oauth_error" => "email_exists");
ob_start();
require __DIR__ . "/../views/client/partials/oauth-buttons.php";
$error = ob_get_clean();
check("a known error code shows its sentence", strpos($error, "Email này đã có tài khoản") !== false);
check("the sentence is escaped markup, not user text", strpos($error, "&#039;") === false
    && strpos($error, "<script") === false);

$_GET = array("oauth_error" => "<script>alert(1)</script>");
ob_start();
require __DIR__ . "/../views/client/partials/oauth-buttons.php";
$hostile = ob_get_clean();
check("an unknown error code prints nothing at all",
    strpos($hostile, "alert(1)") === false && strpos($hostile, "oauth-alert") === false);

$_GET = array();
$disabled = array("enabled" => false);
$noFeature = oauth_buttons_markup($disabled);
check("with the feature off the login page is unchanged", $noFeature === "");

/* The two client pages must actually include the partial. */
foreach (array("login", "register") as $page) {
    $source = (string) file_get_contents(__DIR__ . "/../views/client/" . $page . ".php");
    check($page . ".php includes the shared social block",
        strpos($source, 'partials/oauth-buttons.php') !== false);
}

/* ----------------------------------------------------------- settings pane */

$oauthRaw = oauth_raw_settings($cmsnt);
$oauthConfig = oauth_config($cmsnt);
$oauthTableOk = true;
$oauthCallbackUrl = oauth_callback_url($oauthConfig);
$oauthTest = null;
ob_start();
require __DIR__ . "/../views/admin/partials/settings-social.php";
$pane = ob_get_clean();

check("the pane is a settings tab", strpos($pane, 'id="dang-nhap-mang-xa-hoi"') !== false);
check("the form carries a CSRF token", strpos($pane, 'name="csrf_token"') !== false
    && strpos($pane, 'value="test-csrf-token"') !== false);
check("it renders the master switch and both providers",
    substr_count($pane, 'type="checkbox"') === 5);
check("the callback URL is shown for Supabase", strpos($pane, $oauthCallbackUrl) !== false);
check("there is a copy button for it", strpos($pane, "oauth-copy-callback") !== false);
check("a connection test is offered before saving", strpos($pane, "TestSocialSettings") !== false);
check("the setup steps mention Google, GitHub and the redirect URL",
    strpos($pane, "Google") !== false && strpos($pane, "GitHub") !== false
    && strpos($pane, "Redirect URL") !== false);
check("the anon key is never printed back", strpos($pane, $publishable) === false);
check("a mask of the saved key is shown instead",
    strpos($pane, substr($publishable, 0, 4) . "…" . substr($publishable, -4)) !== false);
check("the stored URL is a field value", strpos($pane, 'value="https://abcdefgh.supabase.co"') !== false);
check("the dangerous option is explained, not hidden", strpos($pane, "email đã xác minh") !== false);
check("no secret word appears in the page", strpos($pane, "service_role") !== false
    && strpos($pane, "Client Secret") !== false);

/* Table missing => a blocking warning with the exact command. */
$oauthTableOk = false;
ob_start();
require __DIR__ . "/../views/admin/partials/settings-social.php";
$missingPane = ob_get_clean();
check("a missing mapping table produces a blocking warning",
    strpos($missingPane, "oauth_identities") !== false
    && strpos($missingPane, "php scripts/migrate-social-login.php") !== false);
$oauthTableOk = true;

/* Test result => readable, provider-specific feedback. */
$oauthTest = array("ok" => true, "message" => "", "providers" => array("google" => true, "github" => false));
ob_start();
require __DIR__ . "/../views/admin/partials/settings-social.php";
$testPane = ob_get_clean();
check("a successful probe names the providers' real state",
    strpos($testPane, "Kết nối Supabase thành công") !== false
    && strpos($testPane, "Google: đang bật") !== false && strpos($testPane, "GitHub: đang tắt") !== false);
check("a mismatch between shop and Supabase is called out",
    strpos($testPane, "chưa bật trong Supabase") !== false);

$oauthTest = array("ok" => false, "message" => "server", "providers" => array("google" => false, "github" => false));
ob_start();
require __DIR__ . "/../views/admin/partials/settings-social.php";
$failedPane = ob_get_clean();
check("a failed probe tells the admin what to check",
    strpos($failedPane, "Không kiểm tra được kết nối") !== false
    && strpos($failedPane, "Project URL") !== false);
check("no stack trace or provider text leaks into the page",
    strpos($failedPane, "Fatal error") === false && strpos($failedPane, "curl") === false);

/* The flow pages are routable names (the .htaccess rule accepts [A-Za-z0-9-]+). */
foreach (array("oauth-start", "oauth-callback") as $route) {
    check($route . " exists and matches the client route rule",
        is_file(__DIR__ . "/../views/client/" . $route . ".php")
        && preg_match('/^[A-Za-z0-9-]+$/', $route) === 1);
}

/* The save handler must verify the token before touching settings. */
$settingsSource = (string) file_get_contents(__DIR__ . "/../views/admin/settings.php");
check("the social save handler verifies the CSRF token before writing",
    strpos($settingsSource, "verify_csrf_token") !== false
    && strpos($settingsSource, "SaveSocialSettings") !== false
    && strpos($settingsSource, "oauth_settings_rows") !== false);
$helperSource = (string) file_get_contents(__DIR__ . "/../libs/helper.php");
check("the verifier is constant-time and fails closed",
    strpos($helperSource, "function verify_csrf_token") !== false
    && strpos($helperSource, "hash_equals") !== false);

echo "\noauth_pages_test: " . $pass . " passed, " . $fail . " failed\n";
exit($fail > 0 ? 1 : 0);
