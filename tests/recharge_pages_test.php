<?php
/*
 * Money-in page (payOS) — contract and fail-closed state.
 *
 *   php tests/recharge_pages_test.php
 *
 * The payOS service is not exercised here (that is payos_client_test.php). This test renders the real
 * view with no payOS database available and checks the page still renders, keeps the CSRF field and the
 * same endpoint, disables the submit button, and never claims the shop is ready.
 */

define("IN_SITE", true);

if (!function_exists("BASE_URL")) {
    function BASE_URL($path = "")
    {
        return "https://shop.example/" . ltrim((string) $path, "/");
    }
}
if (!function_exists("base_url")) {
    function base_url($path = "")
    {
        return BASE_URL($path);
    }
}
if (!function_exists("check_string")) {
    function check_string($data)
    {
        return trim(htmlspecialchars(addslashes((string) $data)));
    }
}
if (!function_exists("__")) {
    function __($text)
    {
        return $text;
    }
}
if (!function_exists("format_currency")) {
    function format_currency($amount)
    {
        return number_format((float) $amount, 0, ",", ".") . "đ";
    }
}
if (!function_exists("format_cash")) {
    function format_cash($number, $suffix = "")
    {
        return number_format((float) $number, 0, ",", ".") . (string) $suffix;
    }
}
if (!function_exists("myip")) {
    function myip()
    {
        return "127.0.0.1";
    }
}
if (!function_exists("redirect")) {
    function redirect($url)
    {
        echo "REDIRECT:" . $url;
    }
}
if (!function_exists("generate_csrf_token")) {
    function generate_csrf_token()
    {
        return "csrf-test-token";
    }
}

$pass = 0;
$fail = 0;
$errors = array();

function check($label, $ok)
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ok   " . $label . "\n";
        return;
    }
    $fail++;
    $errors[] = $label;
    echo "  FAIL " . $label . "\n";
}

class RechargeStub
{
    public $user;

    public function __construct()
    {
        $this->user = array("id" => 7, "username" => "khachhang7", "token" => "tok", "money" => 250000,
            "banned" => 0, "token_forgot_password" => null);
    }

    public function site($key)
    {
        $map = array(
            "title" => "Orbit Shop",
            "description" => "Shop bán tài khoản & licence",
            "bank_status" => "0", "momo_status" => "0", "card_status" => "0", "crypto_status" => "0",
            "paypal_status" => "0", "perfectmoney_status" => "0", "toyyibpay_status" => "0",
            "squadco_status" => "0", "flutterwave_status" => "0",
        );
        return isset($map[$key]) ? $map[$key] : "";
    }

    public function get_row($sql)
    {
        return strpos($sql, "FROM `users`") !== false ? $this->user : false;
    }

    public function get_list($sql)
    {
        return array();
    }

    public function num_rows($sql)
    {
        return 0;
    }

    public function update($table, $data, $where = "")
    {
        return true;
    }
}

class DB
{
    public static $stub;

    public function site($key)
    {
        return self::$stub->site($key);
    }

    public function get_row($sql)
    {
        return self::$stub->get_row($sql);
    }

    public function get_list($sql)
    {
        return self::$stub->get_list($sql);
    }

    public function num_rows($sql)
    {
        return self::$stub->num_rows($sql);
    }

    public function update($table, $data, $where = "")
    {
        return self::$stub->update($table, $data, $where);
    }
}

$stub = new RechargeStub();
$GLOBALS["CMSNT"] = $stub;
DB::$stub = $stub;
$_SESSION = array("login" => "tok");
$_COOKIE = array();
$_GET = array("module" => "client", "action" => "recharge-bank");
$getUser = $stub->user;
$GLOBALS["getUser"] = $getUser;

$warnings = array();
set_error_handler(function ($severity, $message, $file, $line) use (&$warnings) {
    $warnings[] = $message . " @ " . basename($file) . ":" . $line;
    return true;
});
ob_start();
include __DIR__ . "/../views/client/recharge-payos.php";
$html = ob_get_clean();
restore_error_handler();

echo "\nrecharge (payOS unavailable)\n";
check("the page renders on the store shell", strpos($html, 'data-theme="light"') !== false
    && strpos($html, "store.css") !== false);
check("one h1 names the action", substr_count($html, "<h1") === 1 && strpos($html, ">Nạp tiền<") !== false);
check("the real balance is printed", strpos($html, "250.000đ") !== false);
check("the shop says payOS is not ready instead of pretending", strpos($html, "chưa sẵn sàng") !== false);
check("the submit is disabled while not ready", strpos($html, 'id="payos-submit"') !== false
    && preg_match('~id="payos-submit"[^>]*disabled~', $html) === 1);
check("the amount keeps its bounds and step", strpos($html, 'min="1000"') !== false
    && strpos($html, 'max="50000000"') !== false && strpos($html, 'step="1"') !== false);
check("the CSRF token rides with the form", strpos($html, 'name="csrf_token"') !== false
    && strpos($html, 'name="action" value="create"') !== false);
check("the endpoint is still ajaxs/client/payos.php", strpos($html, "ajaxs/client/payos.php") !== false);
check("a broken history query shows an honest message", strpos($html, "Chưa thể tải lịch sử nạp tiền") !== false);
check("the three steps are numbered", substr_count($html, "<li><strong>") === 3);
check("no inline style attribute", strpos($html, 'style="') === false);
check("no third-party script or font", stripos($html, "cdnjs") === false && stripos($html, "bootstrap") === false
    && stripos($html, "fonts.googleapis") === false);
check("no PHP warning reached the page", $warnings === array());

if ($warnings) {
    echo "  warnings: " . implode(" | ", $warnings) . "\n";
}

echo "\nrecharge_pages_test: " . $pass . " passed, " . $fail . " failed\n";
if ($fail > 0) {
    echo "failed: " . implode(" | ", $errors) . "\n";
    exit(1);
}
