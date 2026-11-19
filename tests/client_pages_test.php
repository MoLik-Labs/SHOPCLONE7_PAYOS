<?php
/*
 * Client pages — product, sign-in, registration, account overview.
 *
 *   php tests/client_pages_test.php
 *
 * No database and no browser. A stub answers the exact queries each page sends, so the test proves the
 * pages print real rows, keep the endpoint contract the AJAX handlers expect, give every field a label,
 * emit no PHP notice, and never load a third-party script unless the owner switched reCAPTCHA on.
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
if (!function_exists("getStock")) {
    function getStock($code)
    {
        global $CMSNT;
        return $CMSNT->stockCount($code);
    }
}
if (!function_exists("dirImageProduct")) {
    function dirImageProduct($image)
    {
        return "assets/storage/images/products/" . $image;
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
if (!function_exists("myip")) {
    function myip()
    {
        return "127.0.0.1";
    }
}

class ClientPagesTestCmsnt
{
    public $values = array();
    public $product = array();
    public $category = array();
    public $user = array();
    public $stock = array();
    public $queries = array();

    public function site($key)
    {
        return isset($this->values[$key]) ? $this->values[$key] : "";
    }

    public function get_row($sql)
    {
        $this->queries[] = $sql;
        if (strpos($sql, "FROM `product_stock`") !== false) {
            preg_match("~`product_code` = '([^']+)'~", $sql, $match);
            $code = isset($match[1]) ? $match[1] : "";
            return array("COUNT(id)" => $this->stockCount($code));
        }
        if (strpos($sql, "FROM `products`") !== false) {
            return $this->product;
        }
        if (strpos($sql, "FROM `categories`") !== false) {
            return $this->category;
        }
        if (strpos($sql, "FROM `users`") !== false) {
            return $this->user;
        }
        return null;
    }

    public function get_list($sql)
    {
        $this->queries[] = $sql;
        return array();
    }

    public function num_rows($sql)
    {
        $this->queries[] = $sql;
        return 0;
    }

    public function update($table, $data, $where = "")
    {
        $this->queries[] = "UPDATE `" . $table . "`";
        return true;
    }

    public function stockCount($code)
    {
        return isset($this->stock[$code]) ? $this->stock[$code] : 0;
    }
}

/* models/is_user.php builds its own `new DB()`; this shim forwards to the same stub. */
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

$pass = 0;
$fail = 0;
$errors = array();

function check($label, $condition, $detail = "")
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  ok   " . $label . "\n";
    } else {
        $fail++;
        echo "  FAIL " . $label . ($detail !== "" ? " — " . $detail : "") . "\n";
    }
}

function render_view($view, $get, $session, &$cmsnt)
{
    global $errors;
    $_GET = $get;
    $_SESSION = $session;
    $_COOKIE = array();
    $GLOBALS["CMSNT"] = $cmsnt;
    $CMSNT = $cmsnt; /* views are normally included at top level by index.php */
    DB::$stub = $cmsnt;

    $before = error_get_last();
    ob_start();
    include __DIR__ . "/../" . $view;
    $html = ob_get_clean();
    $after = error_get_last();
    if ($after && (!$before || $after["message"] !== $before["message"])) {
        $errors[] = basename($view) . ": " . $after["message"];
    }
    return $html;
}

set_error_handler(function ($severity, $message, $file, $line) use (&$errors) {
    $errors[] = $message . " at " . basename($file) . ":" . $line;
    return true;
});

$CMSNT = new ClientPagesTestCmsnt();
$CMSNT->values = array(
    "title" => "Orbit Shop",
    "description" => "Shop bán tài khoản & licence",
    "keywords" => "shop",
    "status_demo" => "0",
    "reCAPTCHA_status" => "0",
    "reCAPTCHA_site_key" => "",
    "product_photo_display" => "1",
    "product_sold_display" => "1",
    "api_status" => "0",
    "affiliate_status" => "0",
    "bank_status" => "0",
    "momo_status" => "0",
    "card_status" => "0",
    "crypto_status" => "0",
    "paypal_status" => "0",
    "perfectmoney_status" => "0",
    "toyyibpay_status" => "0",
    "squadco_status" => "0",
    "flutterwave_status" => "0",
);
$CMSNT->product = array(
    "id" => 101,
    "name" => "Netflix Premium 1 tháng",
    "slug" => "netflix-premium",
    "price" => 120000,
    "discount" => 10,
    "short_desc" => "Tài khoản riêng, xem trên 1 thiết bị.",
    "description" => base64_encode("<p>Hướng dẫn sử dụng thật.</p>"),
    "images" => "",
    "sold" => 128,
    "supplier_id" => 0,
    "api_stock" => 0,
    "code" => "NF1",
    "category_id" => 1,
    "min" => 1,
    "max" => 5,
    "status" => 1,
);
$CMSNT->category = array("id" => 1, "name" => "Tài khoản giải trí", "slug" => "tai-khoan-giai-tri");
$CMSNT->user = array(
    "id" => 7, "username" => "khachhang7", "fullname" => "Nguyễn Văn A", "email" => "k7@example.com",
    "phone" => "0909 000 111", "telegram_chat_id" => "", "money" => 250000, "total_money" => 900000,
    "token" => "tok", "api_key" => "APIKEY123", "admin" => 0, "banned" => 0, "ip" => "127.0.0.1",
    "device" => "Chrome", "create_date" => "2026-01-01 09:00:00", "update_date" => "2026-02-01 10:00:00",
    "discount" => 0, "ref_ck" => 0,
);
$CMSNT->stock = array("NF1" => 24);

/* --- product: guest ------------------------------------------------------ */
echo "\nproduct (guest)\n";
$html = render_view("views/client/product.php", array("slug" => "netflix-premium"), array(), $CMSNT);
check("one h1 on the product", substr_count($html, "<h1") === 1);
check("the product name is printed", strpos($html, "Netflix Premium 1 tháng") !== false);
check("the real price and the discounted price are both printed",
    strpos($html, "120.000đ") !== false && strpos($html, "108.000đ") !== false);
check("the stock figure is real", strpos($html, "Còn 24") !== false);
check("the sold figure follows the shop setting", strpos($html, "Đã bán 128") !== false);
check("a guest is offered sign-in instead of a buy button",
    strpos($html, "Đăng nhập để mua") !== false && strpos($html, "data-buy-submit") === false);
check("the purchase panel keeps the endpoint contract",
    strpos($html, "ajaxs/client/product.php") !== false && strpos($html, 'data-product="101"') !== false);
check("the admin description is rendered", strpos($html, "Hướng dẫn sử dụng thật.") !== false);
check("no fake rating or review count", stripos($html, "reviews") === false && strpos($html, "icofont-star") === false);
check("no inline style attribute", strpos($html, 'style="') === false);
check("no third-party script or font",
    stripos($html, "cdnjs") === false && stripos($html, "jsdelivr") === false
    && stripos($html, "fonts.googleapis") === false && stripos($html, "google.com/recaptcha") === false);
check("the product page loads the store shell", strpos($html, 'data-theme="light"') !== false
    && strpos($html, "store.css") !== false);
preg_match('~<span class="topbar__name">([^<]*)</span>~', $html, $brandName);
check("the header keeps the shop name, not the product name",
    isset($brandName[1]) && $brandName[1] === "Orbit Shop");

/* --- product: signed in -------------------------------------------------- */
echo "\nproduct (signed in)\n";
$html = render_view("views/client/product.php", array("slug" => "netflix-premium"), array("login" => "tok"), $CMSNT);
check("a signed-in buyer gets the buy button", strpos($html, "data-buy-submit") !== false);
check("the panel carries the api key for the endpoint", strpos($html, 'data-api-key="APIKEY123"') !== false);
check("the balance is printed", strpos($html, "Số dư") !== false && strpos($html, "250.000đ") !== false);
check("quantity bounds come from the product", strpos($html, 'min="1"') !== false && strpos($html, 'max="5"') !== false);
check("every control has a label", strpos($html, 'for="amount"') !== false && strpos($html, 'for="coupon"') !== false);

/* --- product: out of stock ----------------------------------------------- */
echo "\nproduct (out of stock)\n";
$CMSNT->stock = array("NF1" => 0);
$html = render_view("views/client/product.php", array("slug" => "netflix-premium"), array("login" => "tok"), $CMSNT);
check("out of stock disables buying", strpos($html, "Tạm hết hàng") !== false && strpos($html, "data-buy-submit") === false);
$CMSNT->stock = array("NF1" => 24);

/* --- sign-in ------------------------------------------------------------- */
echo "\nlogin\n";
$html = render_view("views/client/login.php", array(), array(), $CMSNT);
check("one h1 on sign-in", substr_count($html, "<h1") === 1);
check("both fields are labelled", strpos($html, 'for="page-login-username"') !== false
    && strpos($html, 'for="page-login-password"') !== false);
check("the CSRF token is rendered", strpos($html, 'name="csrf_token"') !== false
    && strpos($html, "csrf-test-token") !== false);
check("the form keeps the legacy endpoint and action",
    strpos($html, "ajaxs/client/auth.php") !== false && strpos($html, 'data-action="Login"') !== false);
check("the submit keeps its legacy id", strpos($html, 'id="btnLoginPage"') !== false);
check("reCAPTCHA is absent when the owner has not enabled it", strpos($html, "g-recaptcha") === false
    && stripos($html, "google.com/recaptcha") === false);
check("no inline style attribute on sign-in", strpos($html, 'style="') === false);
check("the OAuth block renders nothing when no provider is on", strpos($html, "oauth-button") === false);

/* --- registration -------------------------------------------------------- */
echo "\nregister\n";
$html = render_view("views/client/register.php", array(), array(), $CMSNT);
check("one h1 on registration", substr_count($html, "<h1") === 1);
foreach (array("username", "email", "password", "repassword") as $field) {
    check("registration field " . $field . " is present", strpos($html, 'name="' . $field . '"') !== false);
}
check("registration keeps the legacy action", strpos($html, 'data-action="Register"') !== false);
check("the strength hint is wired to the password field", strpos($html, "data-password-strength-input") !== false);
check("registration has no inline style attribute", strpos($html, 'style="') === false);

/* --- account ------------------------------------------------------------- */
echo "\nprofile\n";
$html = render_view("views/client/profile.php", array(), array("login" => "tok"), $CMSNT);
check("one h1 on the account page", substr_count($html, "<h1") === 1);
check("the buying name is greeted", strpos($html, "Xin chào, Nguyễn Văn A") !== false);
check("the real balance is printed", strpos($html, "250.000đ") !== false);
check("the total topped-up figure is printed", strpos($html, "900.000đ") !== false);
check("spent is the difference, not an invented number", strpos($html, "650.000đ") !== false);
check("account navigation links to real pages",
    strpos($html, "client/purchase-history") !== false && strpos($html, "product-orders") !== false);
check("the profile form posts the legacy action", strpos($html, "ChangeProfile") === false
    && strpos($html, 'data-profile-form') !== false);
check("profile fields are labelled", strpos($html, 'for="fullname"') !== false
    && strpos($html, 'for="phone"') !== false);
check("the token is carried in the form", strpos($html, 'name="token" value="tok"') !== false);
check("no inline style attribute on the account page", strpos($html, 'style="') === false);

/* --- retired routes ------------------------------------------------------ */
echo "\nwidget_tools (retired)\n";
$html = render_view("views/client/widget_tools.php", array(), array("login" => "tok"), $CMSNT);
check("the retired tools widget redirects to the real page",
    strpos($html, "REDIRECT:https://shop.example/client/tools") !== false, $html);
check("no legacy tool card markup is printed", strpos($html, "box-product-main") === false);

/* --- warnings ------------------------------------------------------------ */
check("no PHP warning or notice reached any page", empty($errors), implode(" | ", $errors));

echo "\nclient_pages_test: " . $pass . " passed, " . $fail . " failed\n";
exit($fail > 0 ? 1 : 0);
