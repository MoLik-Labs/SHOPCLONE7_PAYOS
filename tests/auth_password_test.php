<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}
/*
 * Passwords are bytes, not HTML.
 *
 * Login ran the password through check_string() — addslashes + htmlspecialchars + trim — before
 * hashing. A password containing ', ", &, < or a leading space could never match what registration
 * stored (registration sanitised it the same way, but the user types the original). New hashes use
 * the raw value and the legacy form is still accepted, then upgraded, so nobody is locked out.
 *
 * The suite hashes real values with password_hash() (no fixture hashes are hard-coded) and checks
 * the wiring that decides which form of the password reaches the hash.
 */

define('IN_SITE', true);

class DB
{
    public static $mode = 'bcrypt';
    public static $stored = '';
    public static $updates = array();
    public function __construct() {}
    public function connect() { return true; }
    public function dis_connect() { return true; }
    public function site($name)
    {
        if ($name === 'timezone') {
            return 'Asia/Ho_Chi_Minh';
        }
        if ($name === 'type_password') {
            return self::$mode;
        }
        return '';
    }
    public function get_row($sql)
    {
        if (stripos($sql, 'FROM `block_ip`') !== false) {
            return false;
        }
        if (stripos($sql, 'FROM `users`') !== false) {
            return array('password' => self::$stored);
        }
        return false;
    }
    public function update($table, $data, $where)
    {
        if (isset($data['password'])) {
            self::$stored = $data['password'];
            self::$updates[] = $data['password'];
        }
        return true;
    }
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
if (!function_exists('__')) {
    function __($text) { return $text; }
}
require __DIR__ . '/../libs/helper.php';

$failures = 0;
$checks = 0;
function check_auth($name, $condition)
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
function auth_source($file) { return file_get_contents(__DIR__ . '/../' . $file); }

/* A password that check_string() would rewrite. */
$raw = "p&ss'word\"<x> 123";

/* ---- bcrypt --------------------------------------------------------------- */
DB::$mode = 'bcrypt';
DB::$stored = password_hash($raw, PASSWORD_BCRYPT);
DB::$updates = array();
check_auth('a raw password verifies against its own bcrypt hash', orbit_password_matches($raw, DB::$stored) === true);
check_auth('a wrong password does not verify', orbit_password_matches($raw . 'x', DB::$stored) === false);
check_auth('an empty stored hash never verifies', orbit_password_matches($raw, '') === false);

$legacy = check_string($raw);
check_auth('check_string() really does rewrite this password', $legacy !== $raw);
DB::$stored = password_hash($legacy, PASSWORD_BCRYPT);
check_auth('the legacy sanitised password still verifies', orbit_password_matches($raw, DB::$stored) === true);

$oldLegacy = trim(htmlspecialchars(addslashes($raw), ENT_COMPAT, 'UTF-8'));
check_auth('old PHP legacy single-quote encoding differs from the current form', $oldLegacy !== $legacy);
DB::$stored = password_hash($oldLegacy, PASSWORD_BCRYPT);
check_auth('bcrypt accepts a pre-PHP-8.1 legacy password', orbit_password_matches($raw, DB::$stored) === true);
$invalidRaw = "bad\xFF'password";
$substituteLegacy = trim(htmlspecialchars(addslashes($invalidRaw), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
check_auth('bcrypt accepts PHP 8.1 legacy substitution for malformed UTF-8',
    orbit_password_matches($invalidRaw, password_hash($substituteLegacy, PASSWORD_BCRYPT)) === true);

DB::$updates = array();
orbit_password_upgrade(7, $raw);
check_auth('a legacy login rewrites the hash with the raw password', count(DB::$updates) === 1 && password_verify($raw, DB::$stored));
check_auth('the upgraded hash no longer needs the legacy form', password_verify($legacy, DB::$stored) === false);

DB::$updates = array();
orbit_password_upgrade(7, $raw);
check_auth('a login already on the raw password does not rewrite the hash', count(DB::$updates) === 0);

/* ---- md5 / sha1 ----------------------------------------------------------- */
DB::$mode = 'md5';
DB::$stored = md5($raw);
check_auth('md5 mode verifies the raw password', orbit_password_matches($raw, md5($raw)) === true);
check_auth('md5 mode accepts a legacy sanitised hash', orbit_password_matches($raw, md5(check_string($raw))) === true);
check_auth('md5 mode accepts a pre-PHP-8.1 legacy password', orbit_password_matches($raw, md5($oldLegacy)) === true);
check_auth('md5 accepts PHP 8.1 legacy substitution for malformed UTF-8', orbit_password_matches($invalidRaw, md5($substituteLegacy)) === true);
check_auth('md5 mode rejects a wrong password', orbit_password_matches($raw . 'x', md5($raw)) === false);
DB::$updates = array();
orbit_password_upgrade(7, $raw);
check_auth('non-bcrypt hashes are not rewritten', count(DB::$updates) === 0);

DB::$mode = 'bcrypt';
DB::$stored = password_hash($raw, PASSWORD_BCRYPT);

/* ---- wiring --------------------------------------------------------------- */
$auth = auth_source('ajaxs/client/auth.php');
check_auth('login no longer sanitises the password', strpos($auth, 'check_string($_POST["password"])') === false);
check_auth('login verifies through the compatibility helper', substr_count($auth, 'orbit_password_matches($password, $getUser["password"])') >= 2);
check_auth('login upgrades a legacy hash after success', strpos($auth, 'orbit_password_upgrade($getUser["id"], $password);') !== false);
check_auth('registration hashes the raw password', strpos($auth, '$password = isset($_POST["password"]) && is_string($_POST["password"]) ? $_POST["password"] : "";') !== false);
check_auth('registration rejects passwords shorter than the UI minimum', strpos($auth, 'if(strlen($password) < 6)') !== false);
check_auth('the registration form exposes the same six-character minimum', strpos(auth_source('views/client/register.php'), 'minlength="6"') !== false);
check_auth('a password change verifies the current password with the helper', strpos($auth, 'if(!orbit_password_matches($password, $getUser["password"])) {') !== false);
check_auth('a password change stores the raw new password', strpos($auth, 'TypePassword($newPassword)') !== false);
check_auth('the forgot-password reset stores the raw password', strpos($auth, '$password = isset($_POST["newpassword"]) && is_string($_POST["newpassword"]) ? $_POST["newpassword"] : "";') !== false);
check_auth('a zero lockout limit no longer bans after one failure', substr_count($auth, '0 < (int) $max_attempts') === 3);

$adminUsers = auth_source('views/admin/users.php');
$adminEdit = auth_source('views/admin/user-edit.php');
check_auth('the admin add-user form hashes the posted password, not the username', strpos($adminUsers, 'TypePassword($newUserPassword)') !== false && strpos($adminUsers, 'TypePassword(check_string($_POST["username"]))') === false);
check_auth('the admin add-user form refuses a short password', strpos($adminUsers, 'strlen($newUserPassword) < 5') !== false);
check_auth('the admin add-user token comes from the CSPRNG', strpos($adminUsers, '"token" => bin2hex(random_bytes(32))') !== false);
check_auth('the admin add-user api key comes from the CSPRNG', strpos($adminUsers, '"api_key" => bin2hex(random_bytes(16))') !== false);
check_auth('an admin password reset stores the raw value', strpos($adminEdit, 'TypePassword($newPassword)') !== false && strpos($adminEdit, 'check_string($_POST["password"])') === false);

echo "\n" . $checks . " checks, " . $failures . " failed\n";
exit($failures === 0 ? 0 : 1);
