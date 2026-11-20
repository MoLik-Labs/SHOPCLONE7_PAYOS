<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}
/*
 * reCAPTCHA verification.
 *
 * Four copies of the check lived in ajaxs/client/auth.php, each fetching
 *
 *   https://www.google.com/recaptcha/api/siteverify?secret=<secret>&response=<token>
 *
 * with no timeout and no TLS context, then reading ->success from whatever came back. Now one helper
 * posts the secret in the body, verifies the certificate, gives up after 8 s and fails closed.
 * These checks need no network: they cover the disabled path, the empty-token path and the source.
 */

define('IN_SITE', true);
class DB
{
    public static $sites = array();
    public function __construct() {}
    public function connect() { return true; }
    public function dis_connect() { return true; }
    public function site($name)
    {
        if ($name === 'timezone') {
            return 'Asia/Ho_Chi_Minh';
        }
        return isset(self::$sites[$name]) ? self::$sites[$name] : '';
    }
    public function get_row($query) { return false; }
    public function get_list($query) { return array(); }
    public function insert($table, $data) { return true; }
    public function update($table, $data, $where = '') { return true; }
    public function remove($table, $where) { return true; }
    public function query($query) { return true; }
    public function num_rows($query) { return 0; }
}
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
require __DIR__ . '/../libs/helper.php';

$checks = 0;
$failures = array();
function rc_is($expected, $actual, $name)
{
    global $checks, $failures;
    ++$checks;
    if ($expected === $actual) {
        echo "PASS  " . $name . "\n";
        return;
    }
    $failures[] = $name . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')';
    echo "FAIL  " . $name . "\n";
}

/* ---- disabled: nothing to check, nobody blocked ----------------------------- */
DB::$sites['reCAPTCHA_status'] = "0";
rc_is(false, orbit_recaptcha_required(), 'reCAPTCHA off is reported as not required');
rc_is(true, orbit_recaptcha_ok(""), 'an empty token is accepted while reCAPTCHA is off');
rc_is(true, orbit_recaptcha_ok(array("x")), 'an array token is harmless while reCAPTCHA is off');

/* ---- enabled: bad input is refused without a network call ------------------ */
DB::$sites['reCAPTCHA_status'] = "1";
DB::$sites['reCAPTCHA_secret_key'] = "test-secret";
rc_is(true, orbit_recaptcha_required(), 'reCAPTCHA on is reported as required');
rc_is(false, orbit_recaptcha_ok(""), 'an empty token is refused');
rc_is(false, orbit_recaptcha_ok("   "), 'a whitespace token is refused');
rc_is(false, orbit_recaptcha_ok(array("x")), 'an array token is refused, not a TypeError');

/* Enabled but no secret configured: refuse instead of letting everyone through. */
DB::$sites['reCAPTCHA_secret_key'] = "";
rc_is(false, orbit_recaptcha_ok("some-token"), 'a missing secret refuses the token');

/* ---- the secret is posted, not put in a URL -------------------------------- */
$helper = (string) file_get_contents(__DIR__ . '/../libs/helper.php');
$start = strpos($helper, 'function orbit_recaptcha_ok');
$end = strpos($helper, 'function orbit_recaptcha_required', $start);
$body = $start === false ? '' : substr($helper, $start, $end - $start);
rc_is(true, strpos($body, 'CURLOPT_POSTFIELDS => http_build_query(array("secret" => $secret, "response" => $token))') !== false,
    'the secret travels in the POST body, not in the URL');
rc_is(true, strpos($body, 'CURLOPT_TIMEOUT => 8') !== false && strpos($body, 'CURLOPT_CONNECTTIMEOUT => 4') !== false,
    'the verification call is bounded');
rc_is(true, strpos($body, 'CURLOPT_SSL_VERIFYPEER => true') !== false && strpos($body, 'CURLOPT_SSL_VERIFYHOST => 2') !== false,
    'the verification call verifies TLS');
rc_is(true, strpos($body, '"siteverify?secret="') === false, 'the secret is never concatenated into a URL');

/* ---- the auth handler uses the helper everywhere --------------------------- */
$auth = (string) file_get_contents(__DIR__ . '/../ajaxs/client/auth.php');
rc_is(4, substr_count($auth, 'orbit_recaptcha_ok('), 'all four captcha gates call the helper');
rc_is(false, strpos($auth, 'api/siteverify?secret=') !== false, 'no handler builds the verify URL by hand');
rc_is(false, strpos($auth, 'json_decode($verify)') !== false, 'no handler reads an unchecked response');

echo "\n" . $checks . " checks, " . count($failures) . " failure(s)\n";
exit($failures ? 1 : 0);
