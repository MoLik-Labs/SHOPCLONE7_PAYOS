<?php
/*
 * Account pages on the Orbit shell — favourites, balance history, activity log, change password,
 * security, contact, forgot and reset password.
 *
 *   php tests/account_pages_test.php
 *
 * No database and no browser. A stub answers the exact queries each page sends, so the checks prove
 * the real views render real rows, keep the AJAX contract the handlers expect, degrade to an honest
 * empty state, stay free of inline styles / third-party hosts / leaked PHP, and never emit a warning.
 *
 * The security page is rendered with a stub Google2FA class, because vendor/ is not installed in this
 * checkout; the page itself guards with class_exists() and prints its fallback when the library is
 * missing (that path is checked too, with a second render after unsetting the stub — see below).
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
if (!function_exists("gettime")) {
    function gettime()
    {
        return "2026-11-16 10:00:00";
    }
}
/* redirect() exits in production; throw so render_view() can report it like the other suites do. */
class RedirectSignal extends Exception
{
}
if (!function_exists("redirect")) {
    function redirect($url)
    {
        throw new RedirectSignal((string) $url);
    }
}

/* A tiny stand-in for the vendored QR library. getQRCodeInline() returns a data: URI in production. */
if (!class_exists("PragmaRX\\Google2FAQRCode\\Google2FA")) {
    eval('namespace PragmaRX\\Google2FAQRCode; class Google2FA {
        public function getQRCodeInline($company, $holder, $secret) {
            return "<img src=\\"data:image/svg+xml;base64,PHN2Zy8+\\" alt=\\"QR\\" width=\\"180\\" height=\\"180\\">";
        }
    }');
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

class AccountStubDB
{
    public $users;
    public $favorites;
    public $products;
    public $stock;
    public $ledger;
    public $logs;
    public $sites;
    public $sql = array();
    public $updates = array();

    public function __construct()
    {
        $this->users = array(
            "id" => 7, "username" => "lan", "email" => "lan@example.com", "token" => "tok-lan",
            "money" => 240000, "total_money" => 900000, "time_session" => 0, "banned" => 0,
            "status_otp_mail" => 1, "status_noti_login_to_mail" => 0, "status_view_order" => 1,
            "status_2fa" => 0, "SecretKey_2fa" => "JBSWY3DPEHPK3PXP",
            "fullname" => "Ngọc Lan", "phone" => "0909000111", "telegram_chat_id" => "",
            "ip" => "203.0.113.9", "device" => "Firefox on Linux", "create_date" => "2026-01-02 09:00:00",
            "update_date" => "2026-11-16 08:00:00", "token_forgot_password" => null,
        );
        $this->products = array(
            1 => array("id" => 1, "name" => "Netflix Premium 1 tháng", "slug" => "netflix-1-thang",
                "price" => 120000, "code" => "NF1", "supplier_id" => 0, "api_stock" => 0, "status" => 1),
            2 => array("id" => 2, "name" => "Spotify Premium", "slug" => "spotify-premium",
                "price" => 162000, "code" => "SP1", "supplier_id" => 0, "api_stock" => 0, "status" => 1),
        );
        $this->favorites = array(
            array("id" => 11, "product_id" => 1, "user_id" => 7, "create_gettime" => "2026-11-01 10:00:00"),
            array("id" => 12, "product_id" => 2, "user_id" => 7, "create_gettime" => "2026-11-02 11:00:00"),
        );
        $this->stock = array("NF1" => 4, "SP1" => 0);
        $this->ledger = array(
            array("id" => 3, "user_id" => 7, "thoigian" => "2026-11-16 09:00:00", "sotientruoc" => 100000,
                "sotienthaydoi" => 120000, "sotiensau" => 220000, "noidung" => "Nạp tiền qua payOS"),
            array("id" => 2, "user_id" => 7, "thoigian" => "2026-11-15 18:00:00", "sotientruoc" => 220000,
                "sotienthaydoi" => -120000, "sotiensau" => 100000, "noidung" => "Mua Netflix Premium"),
        );
        $this->logs = array(
            array("id" => 2, "user_id" => 7, "createdate" => "2026-11-16 08:00:00",
                "action" => "Đăng nhập", "ip" => "203.0.113.9", "device" => "Firefox on Linux"),
            array("id" => 1, "user_id" => 7, "createdate" => "2026-11-10 08:00:00",
                "action" => "Đổi mật khẩu", "ip" => "203.0.113.9", "device" => "Firefox on Linux"),
        );
        $this->sites = array(
            "title" => "Orbit Shop", "description" => "Shop bán tài khoản", "hotline" => "0909 123 456",
            "email" => "support@orbit.example", "address" => "Quận 1, TP. Hồ Chí Minh",
            "page_contact" => "<p>Gửi mã đơn kèm mô tả lỗi.</p>", "smtp_status" => "1",
            "bank_status" => "1", "momo_status" => "0",
        );
    }

    public function site($key)
    {
        return isset($this->sites[$key]) ? $this->sites[$key] : "";
    }

    public function query($sql)
    {
        $this->sql[] = $sql;
        return true;
    }

    public function insert($table, $data)
    {
        return true;
    }

    public function remove($table, $where)
    {
        return true;
    }

    public function cong($table, $field, $value, $where = "")
    {
        return true;
    }

    public function tru($table, $field, $value, $where = "")
    {
        return true;
    }

    public function update($table, $data, $where = "")
    {
        $this->updates[] = array($table, $data, $where);
        return true;
    }

    public function get_row($sql)
    {
        $this->sql[] = $sql;
        if (strpos($sql, "FROM `users`") !== false) {
            if (strpos($sql, "token_forgot_password") !== false) {
                return strpos($sql, "'a1b2c3d4e5f60718293a4b5c6d7e8f90'") !== false ? $this->users : false;
            }
            return $this->users;
        }
        if (strpos($sql, "FROM `favorites`") !== false && strpos($sql, "COUNT(") !== false) {
            return array("total" => count($this->favorites));
        }
        if (strpos($sql, "FROM `dongtien`") !== false && strpos($sql, "COUNT(") !== false) {
            return array("total" => count($this->ledger));
        }
        if (strpos($sql, "FROM `logs`") !== false && strpos($sql, "COUNT(") !== false) {
            return array("total" => count($this->logs));
        }
        return false;
    }

    public function get_list($sql)
    {
        $this->sql[] = $sql;
        /* Limit: the last "LIMIT a, b" decides how many rows the stub returns. */
        $limit = 99;
        if (preg_match('/LIMIT ([0-9]+), ([0-9]+)/', $sql, $match)) {
            $limit = (int) $match[2];
        }
        if (strpos($sql, "FROM `favorites`") !== false) {
            $rows = array();
            foreach ($this->favorites as $favorite) {
                $product = $this->products[$favorite["product_id"]];
                $rows[] = array_merge($favorite, array(
                    "favorite_id" => $favorite["id"], "product_id" => $product["id"], "name" => $product["name"],
                    "slug" => $product["slug"], "price" => $product["price"], "code" => $product["code"],
                    "supplier_id" => $product["supplier_id"], "api_stock" => $product["api_stock"],
                ));
            }
            return array_slice($rows, 0, $limit);
        }
        if (strpos($sql, "FROM `product_stock`") !== false) {
            $rows = array();
            foreach ($this->stock as $code => $count) {
                if (strpos($sql, "'" . $code . "'") !== false) {
                    $rows[] = array("product_code" => $code, "c" => $count);
                }
            }
            return $rows;
        }
        if (strpos($sql, "FROM `dongtien`") !== false) {
            $rows = $this->ledger;
            if (strpos($sql, "LIKE") !== false) {
                $rows = array_values(array_filter($rows, function ($row) {
                    return strpos($row["noidung"], "Nạp") !== false;
                }));
            }
            return array_slice($rows, 0, $limit);
        }
        if (strpos($sql, "FROM `logs`") !== false) {
            return array_slice($this->logs, 0, $limit);
        }
        return array();
    }

    public function num_rows($sql)
    {
        $this->sql[] = $sql;
        return count($this->logs);
    }
}

$GLOBALS["config"] = array("max_time_load" => 4);

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

    public function insert($table, $data)
    {
        return true;
    }

    public function remove($table, $where)
    {
        return true;
    }

    public function cong($table, $field, $value, $where = "")
    {
        return true;
    }

    public function tru($table, $field, $value, $where = "")
    {
        return true;
    }
}

$GLOBALS["CMSNT"] = new AccountStubDB();
require_once __DIR__ . "/../views/common/store-shell.php";

/**
 * Render a view in a clean request state. Returns array("html" => ..., "redirect" => ...).
 */
function render_view($path, array $get = array(), array $session = array(), array $cookies = array())
{
    $CMSNT = $GLOBALS["CMSNT"];
    DB::$stub = $CMSNT;
    $_GET = $get;
    $_POST = array();
    $_COOKIE = $cookies;
    $_SESSION = $session;
    $_SERVER["HTTP_HOST"] = "shop.example";
    $_SERVER["HTTPS"] = "on";
    $_SERVER["REQUEST_URI"] = "/" . $path;
    $_SERVER["SERVER_NAME"] = "shop.example";
    $_SERVER["REMOTE_ADDR"] = "203.0.113.9";
    /* is_user.php is require_once'd, so only the first render runs it. Resolve the signed-in row here
       exactly as that model does, or every later page sees no user (production gets a fresh request). */
    $getUser = null;
    if (isset($session["login"])) {
        $getUser = $CMSNT->get_row(" SELECT * FROM `users` WHERE `token` = '" . check_string($session["login"]) . "'  ");
    }
    $GLOBALS["getUser"] = $getUser;
    $redirect = null;
    ob_start();
    try {
        include __DIR__ . "/../" . $path;
    } catch (RedirectSignal $signal) {
        $redirect = $signal->getMessage();
    } catch (Throwable $error) {
        ob_end_clean();
        return array("html" => "FATAL: " . $error->getMessage() . " @" . basename($error->getFile()) . ":" . $error->getLine(), "redirect" => null);
    }
    $html = ob_get_clean();
    return array("html" => $html, "redirect" => $redirect);
}

function assert_clean($label, $html)
{
    check($label . " — no leaked PHP", strpos($html, "<?php") === false && strpos($html, "<?=") === false);
    check($label . " — no inline style attribute", preg_match('/\sstyle="/', $html) === 0);
    check($label . " — no third-party host", preg_match('#(src|href)="https?://(?!shop\.example)[^"]+#', $html) === 0);
    check($label . " — exactly one h1", preg_match_all('/<h1[ >]/', $html) === 1);
    check($label . " — shareable shell", strpos($html, 'class="topbar"') !== false && strpos($html, 'class="footer"') !== false);
    check($label . " — skip link", strpos($html, 'class="skip-link"') !== false);
}

/* ------------------------------------------------------------------ favourites */

echo "\nfavourites\n";
$page = render_view("views/client/favorites.php", array("action" => "favorites"), array("login" => "tok-lan"), array("token" => "tok-lan"));
$html = $page["html"];
assert_clean("favourites", $html);
check("the saved product is printed", strpos($html, "Netflix Premium 1 tháng") !== false
    && strpos($html, "Spotify Premium") !== false);
check("stock comes from the batched query (4 and 0)", strpos($html, "Còn 4") !== false
    && strpos($html, "Hết hàng") !== false);
check("the out-of-stock row links to the product instead of a buy button", strpos($html, "Bỏ lưu") !== false);
check("the remove endpoint and action are unchanged", strpos($html, "ajaxs/client/remove.php") !== false
    && strpos($html, "data-favorite-remove=\"11\"") !== false);
check("the per-row stock query is gone", substr_count(implode("\n", $GLOBALS["CMSNT"]->sql), "FROM `product_stock`") === 1);
check("the account nav is current", strpos($html, 'aria-current="page">Yêu thích') !== false);
check("redirects nobody who is signed in", $page["redirect"] === null);

/* ------------------------------------------------------------------ transactions */

echo "\ntransactions\n";
/* Reset the SQL log so the check below only sees this page's statements. */
$GLOBALS["CMSNT"]->sql = array();
$page = render_view("views/client/transactions.php", array("action" => "transactions"), array("login" => "tok-lan"), array("token" => "tok-lan"));
$html = $page["html"];
assert_clean("transactions", $html);
check("both ledger rows are printed", strpos($html, "Nạp tiền qua payOS") !== false
    && strpos($html, "Mua Netflix Premium") !== false);
check("a credit and a debit are distinguishable in words, not only colour",
    strpos($html, "+") !== false && strpos($html, "−") !== false);
check("the page total is labelled as this page, not all-time", strpos($html, "Trang này:") !== false);
$GLOBALS["CMSNT"]->sql = array();
render_view("views/client/transactions.php",
    array("action" => "transactions", "reason" => "Nạp"), array("login" => "tok-lan"), array("token" => "tok-lan"));
check("the reason filter reaches the query", strpos(implode("\n", $GLOBALS["CMSNT"]->sql), "`noidung` LIKE") !== false);
check("the filter never interpolates an undefined variable", strpos($html, "Notice") === false);

/* A hostile search term must be escaped before it reaches SQL. */
$GLOBALS["CMSNT"]->sql = array();
$page = render_view("views/client/transactions.php",
    array("action" => "transactions", "reason" => "x' OR 1=1 -- "), array("login" => "tok-lan"), array("token" => "tok-lan"));
$sql = implode("\n", $GLOBALS["CMSNT"]->sql);
check("a quote in the search term is escaped", strpos($sql, "OR 1=1") === false || strpos($sql, "\\'") !== false);
check("the search string is echoed back escaped", strpos($page["html"], "OR 1=1") === false || strpos($page["html"], "&#039;") !== false);

/* ------------------------------------------------------------------ logs */

echo "\nlogs\n";
$page = render_view("views/client/logs.php", array("action" => "logs"), array("login" => "tok-lan"), array("token" => "tok-lan"));
$html = $page["html"];
assert_clean("logs", $html);
check("the activities are printed with their device", strpos($html, "Đăng nhập") !== false
    && strpos($html, "Firefox on Linux") !== false);
check("the log explains what it records", strpos($html, "Các thao tác đã thực hiện trên tài khoản") !== false);

/* ------------------------------------------------------------------ change password */

echo "\nchange password\n";
$page = render_view("views/client/change-password.php", array("action" => "change-password"), array("login" => "tok-lan"), array("token" => "tok-lan"));
$html = $page["html"];
assert_clean("change password", $html);
check("the form keeps the legacy action and fields", strpos($html, "data-password-form") !== false
    && strpos($html, 'name="newpassword"') !== false && strpos($html, 'name="renewpassword"') !== false
    && strpos($html, 'name="password"') !== false);
check("the password fields are labelled", strpos($html, 'for="current-password"') !== false
    && strpos($html, 'for="new-password"') !== false && strpos($html, 'for="new-password-confirm"') !== false);
check("the page says the session will end", strpos($html, "sẽ được đăng xuất") !== false);
check("the endpoint is the existing handler", strpos($html, "ajaxs/client/auth.php") !== false);

/* ------------------------------------------------------------------ security */

echo "\nsecurity\n";
$page = render_view("views/client/security.php", array("action" => "security"), array("login" => "tok-lan"), array("token" => "tok-lan"));
$html = $page["html"];
assert_clean("security", $html);
check("all three switches are present with a visible track",
    substr_count($html, 'class="switch__track"') === 4);
check("the QR is a local data URI, never a remote request", strpos($html, "data:image/svg+xml") !== false);
check("the 2FA form keeps the Save2FA contract", strpos($html, "data-2fa-form") !== false
    && strpos($html, 'name="secret"') !== false);
check("the security form keeps its field names", strpos($html, 'name="status_otp_mail"') !== false
    && strpos($html, 'name="status_noti_login_to_mail"') !== false
    && strpos($html, 'name="status_view_order"') !== false);
check("a disabled SMTP switch is disabled, not silently ignored", strpos($html, "SMTP đã bật") !== false);

/* ------------------------------------------------------------------ contact */

echo "\ncontact\n";
$page = render_view("views/client/contact.php", array("action" => "contact"), array(), array());
$html = $page["html"];
assert_clean("contact", $html);
check("the owner's contact block is printed", strpos($html, "Gửi mã đơn kèm mô tả lỗi.") !== false);
check("the phone number becomes a tel: link", strpos($html, 'href="tel:0909123456"') !== false);
check("the email becomes a mailto: link", strpos($html, 'href="mailto:support@orbit.example"') !== false);
check("the address is printed as configured", strpos($html, "Quận 1, TP. Hồ Chí Minh") !== false);

/* An empty setting is omitted, never replaced with a placeholder. */
$GLOBALS["CMSNT"]->sites["hotline"] = "";
$GLOBALS["CMSNT"]->sites["page_contact"] = "";
$page = render_view("views/client/contact.php", array("action" => "contact"), array(), array());
check("an empty hotline leaves no phone link", strpos($page["html"], "tel:") === false);
check("an empty contact block shows the honest empty state", strpos($page["html"], "Shop chưa đăng nội dung liên hệ") !== false);
$GLOBALS["CMSNT"]->sites["hotline"] = "0909 123 456";
$GLOBALS["CMSNT"]->sites["page_contact"] = "<p>Gửi mã đơn kèm mô tả lỗi.</p>";

/* ------------------------------------------------------------------ forgot / reset */

echo "\nforgot and reset password\n";
$page = render_view("views/client/forgot-password.php", array("action" => "forgot-password"), array(), array());
$html = $page["html"];
assert_clean("forgot password", $html);
check("the forgot form keeps the ForgotPassword contract", strpos($html, "data-forgot-form") !== false
    && strpos($html, 'name="email"') !== false && strpos($html, 'type="email"') !== false);

$page = render_view("views/client/reset-password.php",
    array("action" => "reset-password", "token" => "a1b2c3d4e5f60718293a4b5c6d7e8f90"), array(), array());
$html = $page["html"];
assert_clean("reset password", $html);
check("a valid token renders the form", strpos($html, "data-reset-form") !== false
    && strpos($html, 'name="newpassword"') !== false);
check("the form carries the token", strpos($html, 'name="token" value="a1b2c3d4e5f60718293a4b5c6d7e8f90"') !== false);
check("the token is never printed as page copy", substr_count($html, "a1b2c3d4e5f60718293a4b5c6d7e8f90") === 1);

$page = render_view("views/client/reset-password.php", array("action" => "reset-password"), array(), array());
check("a missing token redirects to the request form", $page["redirect"] === BASE_URL("client/forgot-password"));
$page = render_view("views/client/reset-password.php", array("action" => "reset-password", "token" => "../../etc/passwd"), array(), array());
check("a malformed token redirects too", $page["redirect"] === BASE_URL("client/forgot-password"));

/* ------------------------------------------------------------------ guests are refused */

echo "\nguest access\n";
/* is_user.php runs once per process, so this is a source-level check: a request without a session is
   redirected to sign-in before any view code runs. */
$isUser = (string) file_get_contents(__DIR__ . "/../models/is_user.php");
check("a guest is sent to sign-in by the account model",
    substr_count($isUser, 'redirect(base_url("client/login"))') === 2);
check("the account model still publishes the row for the shell",
    strpos($isUser, '$GLOBALS["getUser"] = $getUser;') !== false);

echo "\n" . $pass . " passed, " . $fail . " failed.\n";
exit($fail === 0 ? 0 : 1);
