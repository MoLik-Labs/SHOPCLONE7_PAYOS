<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}
/*
 * Buyer order pages — the list (/product-orders) and one order (/product-order/<id>).
 *
 *   php tests/orders_pages_test.php
 *
 * No database and no browser: a stub answers the queries the pages send. The test locks the parts that
 * matter — the buyer scope, the three AJAX contracts, the deleted-order state, labels, and the IP guard
 * on the detail page — without putting real accounts in a fixture.
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
if (!class_exists("Mobile_Detect")) {
    class Mobile_Detect
    {
        public function getUserAgent()
        {
            return "Agent-Test";
        }
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

class OrdersStub
{
    public $verifyIp = "0";
    public $orderTotal = 25;
    public $user;
    public $orders;
    public $sold;
    public $product;

    public function __construct()
    {
        $this->user = array("id" => 7, "username" => "khachhang7", "token" => "tok", "money" => 250000,
            "banned" => 0, "token_forgot_password" => null);
        $this->orders = array(
            array("id" => 11, "trans_id" => "A1B2C3", "buyer" => 7, "product_id" => 101,
                "product_name" => "Netflix Premium 1 tháng", "amount" => 1, "pay" => 120000,
                "create_gettime" => "2026-11-16 08:15:00", "trash" => 0, "note" => "Cho gia đình",
                "ip" => "127.0.0.1", "device" => "Agent-Test", "status_view_order" => 0),
            array("id" => 12, "trans_id" => "D4E5F6", "buyer" => 7, "product_id" => 101,
                "product_name" => "Netflix Premium 1 tháng", "amount" => 2, "pay" => 240000,
                "create_gettime" => "2026-11-15 09:00:00", "trash" => 1, "note" => "",
                "ip" => "127.0.0.1", "device" => "Agent-Test", "status_view_order" => 0),
        );
        $this->sold = array(
            array("id" => 1, "trans_id" => "A1B2C3", "uid" => "U001", "account" => "user1@example.com|pass-1"),
            array("id" => 2, "trans_id" => "A1B2C3", "uid" => "U002", "account" => "user2@example.com|pass-2"),
        );
        $this->product = array("id" => 101, "name" => "Netflix Premium 1 tháng", "slug" => "netflix-premium-1-thang",
            "note" => base64_encode("<p>Đổi mật khẩu sau lần đăng nhập đầu tiên.</p>"));
    }

    public function site($key)
    {
        $map = array(
            "title" => "Orbit Shop",
            "description" => "Shop bán tài khoản & licence",
            "notice_orders" => "<strong>Đơn giao tự động</strong> trong vài giây.",
            "isPurchaseIpVerified" => $this->verifyIp,
            "isPurchaseDeviceVerified" => "0",
            "bank_status" => "0", "momo_status" => "0", "card_status" => "0", "crypto_status" => "0",
            "paypal_status" => "0", "perfectmoney_status" => "0", "toyyibpay_status" => "0",
            "squadco_status" => "0", "flutterwave_status" => "0",
        );
        return isset($map[$key]) ? $map[$key] : "";
    }

    public function get_row($sql)
    {
        if (strpos($sql, "FROM `users`") !== false) {
            return $this->user;
        }
        if (strpos($sql, "FROM `product_order`") !== false) {
            if (strpos($sql, "COUNT(`id`)") !== false) {
                return array("total" => $this->orderTotal);
            }
            if (preg_match("~`trans_id` = '([^']+)'~", $sql, $match)) {
                foreach ($this->orders as $order) {
                    if ($order["trans_id"] === $match[1]) {
                        return $order;
                    }
                }
            }
            return false;
        }
        if (strpos($sql, "FROM `products`") !== false) {
            return $this->product;
        }
        if (strpos($sql, "FROM `product_sold`") !== false) {
            return array("total" => count($this->sold));
        }
        return false;
    }

    public function get_list($sql)
    {
        if (strpos($sql, "FROM `product_order`") !== false) {
            return $this->orders;
        }
        if (strpos($sql, "FROM `product_sold`") !== false) {
            return $this->sold;
        }
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

/* models/is_user.php builds its own `new DB()`; this forwards to the same stub. */
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

function render_page($view, array $get, $stub, $signedIn = true)
{
    $_SESSION = $signedIn ? array("login" => "tok") : array();
    $_COOKIE = array();
    $_GET = $get;
    $CMSNT = $stub;
    $GLOBALS["CMSNT"] = $stub;
    $getUser = $signedIn ? $stub->user : null;
    $GLOBALS["getUser"] = $getUser;
    DB::$stub = $stub;
    ob_start();
    include __DIR__ . "/../" . $view;
    return ob_get_clean();
}

function render_quietly($view, array $get, $stub, $signedIn = true)
{
    $level = error_reporting(E_ALL);
    set_error_handler(function ($severity, $message, $file, $line) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        $html = render_page($view, $get, $stub, $signedIn);
        restore_error_handler();
        error_reporting($level);
        return $html;
    } catch (Throwable $error) {
        restore_error_handler();
        error_reporting($level);
        echo "  FAIL page raised " . $error->getMessage() . " at line " . $error->getLine() . "\n";
        $GLOBALS["fail"]++;
        $GLOBALS["errors"][] = "PHP error: " . $error->getMessage();
        return "";
    }
}

/* --- order list ---------------------------------------------------------- */

echo "\norders (list)\n";
$stub = new OrdersStub();
$html = render_quietly("views/client/product-orders.php", array("module" => "client", "action" => "product-orders"), $stub);

check("one h1 on the order history", substr_count($html, "<h1") === 1);
check("both orders are listed", substr_count($html, "order-item") >= 2
    && strpos($html, "A1B2C3") !== false && strpos($html, "D4E5F6") !== false);
check("a deleted order is marked and keeps no actions", strpos($html, "order-item--deleted") !== false
    && substr_count($html, "data-order-remove=") === 1);
check("the owner notice is printed", strpos($html, "<strong>Đơn giao tự động</strong>") !== false);
check("the note keeps its save contract", strpos($html, 'data-order-note="11"') !== false);
check("the three AJAX endpoints are on the page",
    strpos($html, "ajaxs/client/update.php") !== false
    && strpos($html, "ajaxs/client/remove.php") !== false
    && strpos($html, "ajaxs/client/view.php") !== false);
check("the buyer token is carried once", strpos($html, 'data-token="tok"') !== false);
check("each row links to its own order", strpos($html, "https://shop.example/product-order/A1B2C3") !== false);
check("the real paid amount is printed", strpos($html, "120.000đ") !== false && strpos($html, "240.000đ") !== false);
check("pagination keeps the filter query", strpos($html, "page=2") !== false && strpos($html, "limit=10") !== false);
check("the empty-price placeholder never appears", strpos($html, "—</strong>") === false);
check("no inline style attribute", strpos($html, 'style="') === false);
check("no third-party script or font", stripos($html, "cdnjs") === false && stripos($html, "jsdelivr") === false
    && stripos($html, "fonts.googleapis") === false);
check("the page loads the store shell", strpos($html, 'data-theme="light"') !== false && strpos($html, "store.css") !== false);

/* filter form is a clean GET to the rewritten route */
check("the filter posts back to /product-orders with a real query",
    strpos($html, 'action="https://shop.example/product-orders"') !== false
    && strpos($html, 'name="trans_id"') !== false && strpos($html, 'type="date"') !== false);

/* --- one order ----------------------------------------------------------- */

echo "\norder (detail)\n";
$detail = render_quietly("views/client/product-order.php",
    array("module" => "client", "action" => "product-order", "trans_id" => "A1B2C3"), $stub);

check("one h1 on the order", substr_count($detail, "<h1") === 1);
check("the order code is the heading", strpos($detail, "#A1B2C3") !== false);
check("the account rows are printed", strpos($detail, "user1@example.com|pass-1") !== false
    && strpos($detail, "user2@example.com|pass-2") !== false);
check("each account has a UID and a copy control", strpos($detail, "U001") !== false
    && substr_count($detail, "data-copy-value=") === 2);
check("the delivery note from the product is printed", strpos($detail, "Đổi mật khẩu sau lần đăng nhập đầu tiên.") !== false);
check("the detail keeps the view/remove contracts",
    strpos($detail, "ajaxs/client/view.php") !== false && strpos($detail, "ajaxs/client/remove.php") !== false);
check("the detail carries the back route and the token",
    strpos($detail, 'data-orders-url="https://shop.example/product-orders"') !== false
    && strpos($detail, 'data-token="tok"') !== false);
check("bulk export controls exist", substr_count($detail, "data-accounts-") >= 4);
check("the whole order can be downloaded and deleted",
    strpos($detail, "data-order-download=") !== false && strpos($detail, "data-order-remove=") !== false);
check("no inline style attribute on the detail", strpos($detail, 'style="') === false);
check("no third-party script or font on the detail", stripos($detail, "clipboard.js") === false
    && stripos($detail, "cdnjs") === false && stripos($detail, "fonts.googleapis") === false);

/* --- IP guard ------------------------------------------------------------ */

echo "\norder guard\n";
/* With the owner's IP check on, the same address that bought the order still sees the delivery. The
   mismatch branch calls exit() by design (unchanged from the legacy page), so it cannot be exercised in
   this process without ending the test; the condition itself is the same two lines as before. */
$guarded = new OrdersStub();
$guarded->verifyIp = "1";
$allowed = render_quietly("views/client/product-order.php",
    array("module" => "client", "action" => "product-order", "trans_id" => "A1B2C3"), $guarded);
check("the owner's IP check lets the buyer's own address through", strpos($allowed, "user1@example.com") !== false
    && strpos($allowed, "không khớp với địa chỉ IP") === false);
check("no guard message leaks into a normal view", strpos($allowed, "không khớp với trình duyệt") === false);

echo "\norders_pages_test: " . $pass . " passed, " . $fail . " failed\n";
if ($fail > 0) {
    echo "failed: " . implode(" | ", $errors) . "\n";
    exit(1);
}
