<?php
/*
 * Sign-in and account recovery.
 *
 * Three defects were found by reading the flow:
 *
 *  1. `limit_block_client_login` was compared with `<=`, so the shipped default of 0 banned an
 *     account on its first wrong password. Nothing ever reset `login_attempts`, so an account that
 *     survived a few typos stayed one mistake away from a lock.
 *  2. "This email does not exist" turned the forgot-password form into an account-enumeration oracle.
 *  3. Logout destroyed the session but removed the auth cookie with the legacy call (no SameSite /
 *     Secure) and left `$_SESSION["login"]` populated until shutdown.
 *
 * These are source-level checks: the handlers need a database and an HTTP request to run.
 */

$root = dirname(__DIR__);
$auth = (string) file_get_contents($root . '/ajaxs/client/auth.php');
$helper = (string) file_get_contents($root . '/libs/helper.php');

$checks = 0;
$failures = 0;
function check_auth($name, $condition, $detail = '')
{
    global $checks, $failures;
    ++$checks;
    if ($condition) {
        echo "PASS  " . $name . "\n";
        return;
    }
    ++$failures;
    echo "FAIL  " . $name . ($detail !== '' ? "  (" . $detail . ")" : '') . "\n";
}

/* ---- lockout --------------------------------------------------------------- */
check_auth('a zero limit means "never lock", not "lock immediately"',
    substr_count($auth, '$maxClientAttempts > 0 && $maxClientAttempts <= (int) $getUser["login_attempts"]') === 2);
check_auth('the raw setting is no longer compared with <=',
    strpos($auth, 'if($CMSNT->site("limit_block_client_login") <= $getUser["login_attempts"])') === false);
check_auth('every successful sign-in resets the failure counter',
    substr_count($auth, '"login_attempts" => 0') === 3);
check_auth('wrong passwords still count per IP',
    substr_count($auth, '"type" => "Login"') >= 2);
check_auth('a locked IP is still recorded',
    strpos($auth, 'insert("block_ip"') !== false);

/* ---- enumeration ----------------------------------------------------------- */
$forgotStart = strpos($auth, '$_POST["action"] == "ForgotPassword"');
$forgotEnd = strpos($auth, '$_POST["action"] == "ChangePassword"', $forgotStart);
$forgot = substr($auth, $forgotStart, $forgotEnd - $forgotStart);
check_auth('an unknown address gets the same answer as a known one',
    strpos($forgot, 'không tồn tại trong hệ thống') === false);
check_auth('the neutral answer does not promise that mail was sent',
    substr_count($forgot, 'Nếu địa chỉ Email tồn tại trong hệ thống') === 2);
check_auth('both neutral exits are success responses',
    preg_match_all('/Nếu địa chỉ Email tồn tại trong hệ thống.*?"status" => "success"/s', $forgot) === 2);
check_auth('the rate limit also answers neutrally',
    strpos($forgot, 'time() - (int) $getUser["time_forgot_password"] < 60') !== false);

/* ---- sessions -------------------------------------------------------------- */
check_auth('logout is centralised in one helper',
    strpos($helper, 'function orbit_logout()') !== false);
$logoutStart = strpos($helper, 'function orbit_logout()');
$logout = substr($helper, $logoutStart, strpos($helper, 'function redirect(', $logoutStart) - $logoutStart);
check_auth('logout clears the in-memory login flag',
    strpos($logout, 'unset($_SESSION["login"])') !== false);
check_auth('logout expires the auth cookie with the attributes it was set with',
    strpos($logout, '"httponly" => true, "samesite" => "Lax"') !== false);
check_auth('logout destroys the session after clearing it',
    strpos($logout, 'session_destroy();') > strpos($logout, '$_SESSION = array();'));

foreach (array('views/client/logout.php', 'views/admin/logout.php') as $file) {
    $view = (string) file_get_contents($root . '/' . $file);
    check_auth($file . ' uses the shared logout helper',
        strpos($view, 'orbit_logout();') !== false && strpos($view, 'setcookie(') === false);
}

check_auth('a password reset rotates the stored auth token',
    strpos($auth, '"token_forgot_password" => NULL, "password" => TypePassword($password), "token" => $token') !== false);
check_auth('the reset link expires',
    strpos($auth, '> 900') !== false);
check_auth('the reset link refuses a banned account',
    strpos($auth, '$getUser[' . "'banned'" . '] != 0') !== false);

echo "\n" . $checks . " checks, " . $failures . " failure(s)\n";
exit($failures === 0 ? 0 : 1);
