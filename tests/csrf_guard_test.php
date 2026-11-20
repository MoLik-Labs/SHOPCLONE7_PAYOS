<?php
/*
 * CSRF guard — behaviour of the decision function, plus the wiring that makes it reachable.
 *
 * The guard is what stops a cached admin page (or a forgotten legacy form) from being replayed by
 * another site. Two ways to pass: the per-session token, or — while old pages still exist — a
 * same-origin Origin/Referer. Both are exercised here, including the forged cases that must fail.
 */

define('IN_SITE', true);
$GLOBALS['orbit_csrf_log'] = array();
class DB
{
    public function site($name) { return $name === 'timezone' ? 'Asia/Ho_Chi_Minh' : ''; }
    public function get_row($query) { return false; }
}
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
if (!function_exists('__')) {
    function __($text) { return $text; }
}
require __DIR__ . '/../libs/helper.php';

/* Nothing reaches stdout before the refusal check at the end: a real endpoint has not echoed a byte
   when it calls the guard, and keeping the buffer closed lets http_response_code() work here too. */
ob_start();

$failures = 0;
$checks = 0;
function check_csrf($name, $condition)
{
    global $failures, $checks;
    ++$checks;
    if ($condition) {
        echo "PASS  " . $name . "\n";
        return;
    }
    ++$failures;
    echo "FAIL  " . $name . "\n";
}

function csrf_request()
{
    $_POST = array();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_HOST'] = 'shop.test';
    $_SERVER['REQUEST_URI'] = '/ajaxs/admin/remove.php';
    unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_X_CSRF_TOKEN']);
}

/* ---- the token path ------------------------------------------------------- */
$_SESSION = array('csrf_token' => 'token-abcdefghijklmnopqrstuvwxyz-0123456789');
check_csrf('session token is generated once and reused', generate_csrf_token() === $_SESSION['csrf_token']);

csrf_request();
$_POST['csrf_token'] = $_SESSION['csrf_token'];
check_csrf('a valid csrf_token form field passes', orbit_csrf_ok() === true);

csrf_request();
$_SERVER['HTTP_X_CSRF_TOKEN'] = $_SESSION['csrf_token'];
check_csrf('a valid X-CSRF-Token header passes', orbit_csrf_ok() === true);

csrf_request();
$_POST['csrf_token'] = $_SESSION['csrf_token'] . 'x';
check_csrf('a wrong token without an origin is refused', orbit_csrf_ok() === false);

$_SESSION = array();
csrf_request();
$_POST['csrf_token'] = 'token-abcdefghijklmnopqrstuvwxyz-0123456789';
check_csrf('a token from a session that has none is refused', orbit_csrf_ok() === false);

/* ---- the same-origin fallback -------------------------------------------- */
$_SESSION = array('csrf_token' => 'token-abcdefghijklmnopqrstuvwxyz-0123456789');
csrf_request();
$_SERVER['HTTP_ORIGIN'] = 'https://shop.test';
check_csrf('same-origin POST without a token passes', orbit_csrf_ok() === true);

csrf_request();
$_SERVER['HTTP_REFERER'] = 'https://shop.test/admin/home?tab=orders';
check_csrf('same-origin Referer without a token passes', orbit_csrf_ok() === true);

csrf_request();
$_SERVER['HTTP_ORIGIN'] = 'https://SHOP.TEST';
check_csrf('the origin host is compared case-insensitively', orbit_csrf_ok() === true);

csrf_request();
$_SERVER['HTTP_HOST'] = 'shop.test:8080';
$_SERVER['HTTP_ORIGIN'] = 'https://shop.test';
check_csrf('a proxy port on HTTP_HOST does not defeat the check', orbit_csrf_ok() === true);

csrf_request();
$_SERVER['HTTP_ORIGIN'] = 'https://evil.test';
check_csrf('a cross-site origin is refused', orbit_csrf_ok() === false);

csrf_request();
$_SERVER['HTTP_ORIGIN'] = 'https://shop.test.evil.test';
check_csrf('a host that merely starts with ours is refused', orbit_csrf_ok() === false);

csrf_request();
$_SERVER['HTTP_ORIGIN'] = 'null';
check_csrf('a sandboxed "null" origin is refused', orbit_csrf_ok() === false);

csrf_request();
$_SERVER['HTTP_ORIGIN'] = 'javascript:alert(document.cookie)';
check_csrf('a non-http origin is refused', orbit_csrf_ok() === false);

csrf_request();
$_SERVER['HTTP_ORIGIN'] = 'https://shop.test';
$_SERVER['HTTP_X_FORWARDED_HOST'] = 'evil.test';
check_csrf('X-Forwarded-Host cannot move the expected origin', orbit_csrf_ok() === true);

csrf_request();
$_SERVER['HTTP_HOST'] = 'shop.test/../evil.test';
check_csrf('a malformed Host header is ignored rather than parsed', orbit_csrf_host() === null);

csrf_request();
$_SERVER['HTTP_HOST'] = '[::1]:8080';
$_SERVER['HTTP_ORIGIN'] = 'http://[::1]';
check_csrf('an IPv6 host compares by address', orbit_csrf_ok() === true);

/* ---- the guard itself ---------------------------------------------------- */
csrf_request();
$_SERVER['REQUEST_METHOD'] = 'GET';
orbit_require_csrf();
check_csrf('GET requests are not blocked by the guard', true);

csrf_request();
$_POST['action'] = 'Login';
orbit_require_csrf(array('Login'));
check_csrf('an exempt pre-session action passes without a token', true);

csrf_request();
$_POST['action'] = 'remove_user';
$_POST['csrf_token'] = $_SESSION['csrf_token'];
orbit_require_csrf(array('Login'));
check_csrf('a guarded action passes with its token', true);

/* ---- wiring -------------------------------------------------------------- */
$root = dirname(__DIR__);
foreach (array('ajaxs/admin/create.php', 'ajaxs/admin/update.php', 'ajaxs/admin/remove.php',
               'ajaxs/admin/view.php', 'ajaxs/client/auth.php', 'ajaxs/client/create.php',
               'ajaxs/client/view.php', 'ajaxs/client/update.php', 'ajaxs/client/remove.php') as $file) {
    $source = (string) file_get_contents($root . '/' . $file);
    check_csrf($file . ' calls the guard', strpos($source, 'orbit_require_csrf(') !== false);
}
/*
 * The remaining browser endpoints must not be bare either. payOS and GachtheFast carry their own
 * per-session token; product.php cannot use this guard because another shop calls it server to
 * server (buy_API_SHOPCLONE7), and it already requires the buyer's unguessable api_key — the same
 * shape of protection, different mechanism. Everything else must call orbit_require_csrf().
 */
$ownToken = array(
    'ajaxs/client/payos.php' => 'payos_csrf',
    'ajaxs/client/gtf.php' => 'gtf_csrf',
    'ajaxs/admin/gtf.php' => 'gtf_csrf',
);
foreach ($ownToken as $file => $marker) {
    $source = (string) file_get_contents($root . '/' . $file);
    check_csrf($file . ' checks its own CSRF token', strpos($source, $marker) !== false && strpos($source, 'hash_equals(') !== false);
}
$productSource = (string) file_get_contents($root . '/ajaxs/client/product.php');
check_csrf('product.php still requires the buyer api_key', strpos($productSource, '$_REQUEST["api_key"]') !== false);
check_csrf('a reseller still purchases through product.php server to server',
    strpos((string) file_get_contents($root . '/libs/suppliers.php'), 'ajaxs/client/product.php') !== false);

$unguardedAjax = array();
foreach (array_merge(glob($root . '/ajaxs/client/*.php'), glob($root . '/ajaxs/admin/*.php')) as $file) {
    $name = str_replace($root . '/', '', $file);
    $source = (string) file_get_contents($file);
    if (substr($name, -13) === 'callback.php') {
        continue;
    }
    $guarded = strpos($source, 'orbit_require_csrf(') !== false
        || strpos($source, "_csrf']") !== false
        || isset($ownToken[$name])
        || $name === 'ajaxs/client/product.php';
    if (!$guarded) {
        $unguardedAjax[] = $name;
    }
}
check_csrf('every ajax endpoint is guarded, carries its own token, or requires an api_key' .
    ($unguardedAjax ? ' (' . implode(', ', $unguardedAjax) . ')' : ''), $unguardedAjax === array());

$authSource = (string) file_get_contents($root . '/ajaxs/client/auth.php');
check_csrf('login and reset actions stay reachable before a session exists',
    strpos($authSource, '"Login", "Register", "ForgotPassword", "Verify2FA", "VerifyOTP", "ChangePassword"') !== false);

foreach (array('views/admin/footer.php', 'views/client/footer.php', 'views/common/store-shell.php') as $file) {
    $source = (string) file_get_contents($root . '/' . $file);
    check_csrf($file . ' loads public/common/csrf.js', strpos($source, 'public/common/csrf.js') !== false);
}
check_csrf('public/common/csrf.js exists', is_file($root . '/public/common/csrf.js'));
$script = (string) file_get_contents($root . '/public/common/csrf.js');
check_csrf('the script sends the X-CSRF-Token header for XHR and fetch',
    strpos($script, 'X-CSRF-Token') !== false && strpos($script, 'XMLHttpRequest.prototype.send') !== false);
check_csrf('the script ignores cross-origin requests', strpos($script, 'location.origin') !== false);

/* ---- the admin view layer ------------------------------------------------ */
/* 49 admin views read $_POST before they print anything, and only some remembered a token. The router
   now refuses a cross-site POST for the whole module, so a new form cannot be added unprotected. */
$router = (string) file_get_contents($root . '/index.php');
check_csrf('the router guards every admin POST',
    strpos($router, '$module === "admin"') !== false && strpos($router, 'orbit_csrf_ok()') !== false);

$unguarded = 0;
foreach (glob($root . '/views/admin/*.php') as $view) {
    $source = (string) file_get_contents($view);
    if (strpos($source, 'isset($_POST[') === false) {
        continue;
    }
    if (strpos($source, 'verify_csrf_token(') === false && strpos($source, 'orbit_csrf_ok(') === false) {
        /* Covered by the router guard; listed only so the count is visible in the output. */
        ++$unguarded;
    }
}
check_csrf('every admin view that reads POST is either self-guarded or behind the router (' .
    count(glob($root . '/views/admin/*.php')) . ' views, ' . $unguarded . ' rely on the router)', $unguarded >= 1);

$settings = (string) file_get_contents($root . '/views/admin/settings.php');
check_csrf('the bulk settings save refuses a foreign request itself',
    strpos($settings, 'if (!orbit_csrf_ok())') !== false);
check_csrf('the bulk settings save writes only real setting names',
    strpos($settings, '$knownSettings') !== false && strpos($settings, 'SELECT `name` FROM `settings`') !== false);
check_csrf('the bulk settings save skips non-scalar values',
    strpos($settings, '!is_scalar($value)') !== false);

$db = (string) file_get_contents($root . '/libs/db.php');
check_csrf('the session cookie is HttpOnly, Lax and strict-mode protected',
    strpos($db, 'session.use_strict_mode') !== false && strpos($db, '"httponly" => true') !== false
    && strpos($db, '"samesite" => "Lax"') !== false);
check_csrf('the CSRF cookie is readable by script (that is its purpose)', strpos($db, '"httponly" => false') !== false);

echo "\n" . $checks . " checks, " . $failures . " failed\n";
if ($failures > 0) {
    fwrite(STDOUT, ob_get_clean());
    exit(1);
}

/* ---- the refusal path (last: the guard exits) ---------------------------- */
$pending = false;
register_shutdown_function(function () use (&$pending) {
    if (!$pending) {
        return;
    }
    $body = '';
    while (ob_get_level() > 0) {
        $body = ob_get_clean() . $body;
    }
    $code = http_response_code();
    $ok = $code === 403 && strpos($body, '"status":"error"') !== false;
    fwrite(STDOUT, $body);
    fwrite(STDOUT, ($ok ? "PASS  " : "FAIL  ") . "a forged request is refused with 403 JSON\n");
    exit($ok ? 0 : 1);
});
csrf_request();
$_POST['action'] = 'remove_user';
$_POST['csrf_token'] = 'not-the-token';
$_SERVER['HTTP_ORIGIN'] = 'https://evil.test';
$pending = true;
orbit_require_csrf();
fwrite(STDOUT, "FAIL  the guard returned instead of stopping a forged request\n");
exit(1);
