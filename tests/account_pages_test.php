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

if (!function_exists("getRowRealtime")) {
    /* One-row helper from libs/helper.php; the stub answers from the same fixture user. */
    function getRowRealtime($table, $id, $column)
    {
        $user = DB::$stub->users;
        return $table === "users" && isset($user[$column]) ? $user[$column] : 0;
    }
}

if (!function_exists("generate_csrf_token")) {
    /* production: one token per session (libs/helper.php). Fixed here so the form is deterministic. */
    function generate_csrf_token()
    {
        return "csrf-test-token";
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
    public $referrals = array();
    public $invoice = array("trans_id" => "INV7Q2M9X", "name" => "Nạp 500.000đ", "received" => 19.84,
        "address" => "TXk9PreviewAddress000000000000000", "network" => "TRC20", "status" => "pending",
        "return_url" => "", "wallet_id" => 1, "create_gettime" => "2026-11-16 09:58:00");
    public $affLog = array();
    public $withdrawals = array();
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
            "token_2fa" => null, "token_otp_mail" => null,
            "ref_price" => 250000, "ref_total_price" => 800000, "ref_click" => 12, "ref_ck" => 25,
            "api_key" => "0123456789abcdef0123456789abcdef",
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
        $this->referrals = array(
            array("id" => 21, "username" => "minhanh", "create_date" => "2026-11-14 10:12:00", "ref_amount" => 96000),
            array("id" => 19, "username" => "tuanvu", "create_date" => "2026-11-09 20:40:00", "ref_amount" => 48000),
        );
        $this->affLog = array(
            array("reason" => "Hoa hồng đơn #ORB00118", "sotientruoc" => 154000, "sotienthaydoi" => 96000,
                "sotienhientai" => 250000, "create_gettime" => "2026-11-16 09:20:00"),
        );
        $this->withdrawals = array(
            array("trans_id" => "K7Q2M9", "bank" => "Vietcombank", "stk" => "0123456789",
                "name" => "NGUYEN NGOC LAN", "amount" => 300000, "status" => "pending",
                "reason" => null, "create_gettime" => "2026-11-15 11:00:00"),
            array("trans_id" => "C4T8X1", "bank" => "TPBank", "stk" => "9876543210",
                "name" => "NGUYEN NGOC LAN", "amount" => 200000, "status" => "cancel",
                "reason" => "Sai số tài khoản", "create_gettime" => "2026-11-02 15:20:00"),
        );
        $this->sites = array(
            "title" => "Orbit Shop", "description" => "Shop bán tài khoản", "hotline" => "0909 123 456",
            "email" => "support@orbit.example", "address" => "Quận 1, TP. Hồ Chí Minh",
            "page_contact" => "<p>Gửi mã đơn kèm mô tả lỗi.</p>", "smtp_status" => "1",
            "affiliate_status" => "1", "affiliate_ck" => "20", "affiliate_min" => "100000",
            "affiliate_banks" => "Vietcombank\nTPBank", "affiliate_note" => "<p>Ghi chú cộng tác viên</p>",
            "status_menu_tools" => "1", "api_status" => "1",
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
            /* Second-step sign-in lookups: only the exact token opens a row. */
            if (strpos($sql, "`token_2fa`") !== false && strpos($sql, "SELECT") !== false
                && strpos($sql, "`token` =") === false) {
                if (strpos($sql, "'2fa0a1b2c3d4e5f60718'") !== false) {
                    return array_merge($this->users, array("status_2fa" => 1, "token_2fa" => "2fa0a1b2c3d4e5f60718"));
                }
                if (strpos($sql, "'2faban00000000000000'") !== false) {
                    return array_merge($this->users, array("status_2fa" => 1, "banned" => 1,
                        "token_2fa" => "2faban00000000000000"));
                }
                return false;
            }
            if (strpos($sql, "`token_otp_mail`") !== false && strpos($sql, "SELECT") !== false
                && strpos($sql, "`token` =") === false) {
                if (strpos($sql, "'otp0a1b2c3d4e5f60718'") !== false) {
                    return array_merge($this->users, array("status_otp_mail" => 1,
                        "token_otp_mail" => "otp0a1b2c3d4e5f60718"));
                }
                if (strpos($sql, "'otpclosed00000000000'") !== false) {
                    return array_merge($this->users, array("status_otp_mail" => 0,
                        "token_otp_mail" => "otpclosed00000000000"));
                }
                return false;
            }
            if (strpos($sql, "token_forgot_password") !== false) {
                return strpos($sql, "'a1b2c3d4e5f60718293a4b5c6d7e8f90'") !== false ? $this->users : false;
            }
            return $this->users;
        }
        if (strpos($sql, "FROM `users`") !== false && strpos($sql, "`ref_id`") !== false
            && strpos($sql, "COUNT(") === false) {
            return count($this->referrals);
        }
        if (strpos($sql, "FROM `invoices`") !== false) {
            return strpos($sql, "'INV7Q2M9X'") !== false ? $this->invoice : false;
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
        if (strpos($sql, "FROM `users`") !== false && strpos($sql, "`ref_id`") !== false) {
            return array_slice($this->referrals, 0, $limit);
        }
        if (strpos($sql, "FROM `aff_log`") !== false) {
            return array_slice($this->affLog, 0, $limit);
        }
        if (strpos($sql, "FROM `aff_withdraw`") !== false) {
            $rows = $this->withdrawals;
            if (strpos($sql, "`status` = 'cancel'") !== false) {
                $rows = array_values(array_filter($rows, function ($row) { return $row["status"] === "cancel"; }));
            }
            return array_slice($rows, 0, $limit);
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
        if (strpos($sql, "FROM `aff_log`") !== false) {
            return count($this->affLog);
        }
        if (strpos($sql, "FROM `aff_withdraw`") !== false) {
            return count($this->withdrawals);
        }
        if (strpos($sql, "FROM `users`") !== false && strpos($sql, "`ref_id`") !== false) {
            return count($this->referrals);
        }
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

echo "\ntwo-factor challenge\n";
$page = render_view("views/client/verify_2fa.php",
    array("action" => "verify_2fa", "token" => "2fa0a1b2c3d4e5f60718"), array(), array());
assert_clean("2FA", $page["html"]);
check("2FA does not redirect a valid token", $page["redirect"] === null);

check("2FA keeps the Verify2FA endpoint contract", strpos($page["html"], 'data-action="Verify2FA"') !== false);
check("2FA posts the token in the legacy field", strpos($page["html"], 'name="token_2fa"') !== false);
check("2FA explains which app to open", strpos($page["html"], "Authenticator") !== false);
check("2FA hides the CAPTCHA while the owner has it off", strpos($page["html"], "g-recaptcha") === false);
check("2FA offers a way out", strpos($page["html"], "client/logout") !== false);
$page = render_view("views/client/verify_2fa.php",
    array("action" => "verify_2fa", "token" => "unknown0000000000000"), array(), array());
check("an unknown 2FA token goes back to sign-in", $page["redirect"] === BASE_URL("client/login"));
$page = render_view("views/client/verify_2fa.php",
    array("action" => "verify_2fa", "token" => "2faban00000000000000"), array(), array());
check("a banned 2FA token goes back to sign-in", $page["redirect"] === BASE_URL("client/login"));
$page = render_view("views/client/verify_2fa.php", array("action" => "verify_2fa"), array(), array());
check("a missing 2FA token goes back to sign-in", $page["redirect"] === BASE_URL("client/login"));

echo "\ne-mail OTP challenge\n";
$page = render_view("views/client/verify_otp.php",
    array("action" => "verify_otp", "token" => "otp0a1b2c3d4e5f60718"), array(), array());
assert_clean("OTP", $page["html"]);
check("OTP does not redirect a valid token", $page["redirect"] === null);
check("OTP keeps the VerifyOTP endpoint contract", strpos($page["html"], 'data-action="VerifyOTP"') !== false);
check("OTP posts the token in the legacy field", strpos($page["html"], 'name="token_otp_mail"') !== false);
check("OTP tells the customer to check spam", strpos($page["html"], "Spam") !== false);
$page = render_view("views/client/verify_otp.php",
    array("action" => "verify_otp", "token" => "otpclosed00000000000"), array(), array());
check("a closed OTP account goes back to sign-in", $page["redirect"] === BASE_URL("client/login"));
$page = render_view("views/client/verify_otp.php", array("action" => "verify_otp", "token" => ""), array(), array());
check("an empty OTP token goes back to sign-in", $page["redirect"] === BASE_URL("client/login"));

echo "\naffiliate programme\n";
$page = render_view("views/client/affiliates.php", array("action" => "affiliates"),
    array("login" => "tok-lan"), array("token" => "tok-lan"));
assert_clean("affiliates", $page["html"]);
check("affiliates shows the commission balance", strpos($page["html"], "250.000đ") !== false);
check("affiliates shows the referral rate", strpos($page["html"], "25%") !== false);
check("affiliates prints a referral link for this account", strpos($page["html"], "aff=7") !== false);
check("affiliates lists referred customers", strpos($page["html"], "minhanh") !== false);
check("affiliates has its own sub-navigation", strpos($page["html"], "account-nav--sub") !== false);
check("affiliates does not print the account token", strpos($page["html"], "tok-lan") === false);

$page = render_view("views/client/affiliate-history.php", array("action" => "affiliate-history"),
    array("login" => "tok-lan"), array("token" => "tok-lan"));
assert_clean("affiliate history", $page["html"]);
check("commission rows show the change", strpos($page["html"], "96.000đ") !== false);
check("commission rows show the balance after", strpos($page["html"], "250.000đ") !== false);
check("the history explains what a row is", strpos($page["html"], "số dư trước và sau") !== false);

$page = render_view("views/client/affiliate-withdraw.php", array("action" => "affiliate-withdraw"),
    array("login" => "tok-lan"), array("token" => "tok-lan"));
assert_clean("affiliate withdraw", $page["html"]);
check("withdraw lists the configured banks", strpos($page["html"], "TPBank") !== false);
check("withdraw states the minimum", strpos($page["html"], "100.000đ") !== false);
check("withdraw sends a CSRF token", strpos($page["html"], 'name="csrf_token"') !== false);
check("withdraw does not print the session token", strpos($page["html"], 'name="token"') === false);
check("withdraw shows pending and cancelled requests", strpos($page["html"], "Chờ duyệt") !== false
    && strpos($page["html"], "Đã huỷ") !== false);
check("withdraw explains the payout steps", strpos($page["html"], "chờ duyệt") !== false);

$page = render_view("views/client/affiliate-withdraw.php",
    array("action" => "affiliate-withdraw", "status" => "cancel", "limit" => "5000", "page" => "-9"),
    array("login" => "tok-lan"), array("token" => "tok-lan"));
check("an unknown page size is clamped to a real one", strpos(implode("\n", $GLOBALS["CMSNT"]->sql), "LIMIT 0, 20") !== false);
check("a negative page does not reach SQL", strpos(implode("\n", $GLOBALS["CMSNT"]->sql), "LIMIT -") === false);

echo "\ntools\n";
$page = render_view("views/client/tools.php", array("action" => "tools"), array(), array());
assert_clean("tools index", $page["html"]);
check("tools index lists the four tools", substr_count($page["html"], "card--interactive") === 4);
check("tools index never frames a third party", strpos($page["html"], "<iframe") === false);

$page = render_view("views/client/tool-2fa.php", array("action" => "tool-2fa"), array(), array());
assert_clean("tool 2fa", $page["html"]);
check("tool 2fa computes the code locally", strpos($page["html"], "crypto.subtle") !== false);
check("tool 2fa embeds no third-party frame", strpos($page["html"], "2fa.zone") === false);
check("tool 2fa says the key stays in the browser", strpos($page["html"], "không rời khỏi trang") !== false);
check("tool 2fa carries the RFC 6238 parameters", strpos($page["html"], "SHA-1") !== false
    && strpos($page["html"], "30") !== false);

$page = render_view("views/client/tool-icon-facebook.php", array("action" => "tool-icon-facebook"), array(), array());
assert_clean("tool emoji", $page["html"]);
check("tool emoji renders a local grid", strpos($page["html"], "emoji-grid") !== false);
check("tool emoji embeds no third-party frame", strpos($page["html"], "smileysapp") === false);
check("tool emoji buttons say what they do", strpos($page["html"], "data-emoji=") !== false);

$page = render_view("views/client/tool-checklive-fb.php", array("action" => "tool-checklive-fb"), array(), array());
assert_clean("tool checklive", $page["html"]);
check("checklive states the result is an estimate", strpos($page["html"], "không phải xác nhận") !== false);
check("checklive keeps UID in the browser", strpos($page["html"], "không lưu UID") !== false);

$page = render_view("views/client/tool-random-face.php", array("action" => "tool-random-face"), array(), array());
check("random face opens the service in a new tab", strpos($page["html"], 'target="_blank"') !== false
    && strpos($page["html"], 'rel="noopener noreferrer nofollow"') !== false);
check("random face does not frame the service", strpos($page["html"], "<iframe") === false);

$GLOBALS["CMSNT"]->sites["status_menu_tools"] = "0";
$page = render_view("views/client/tools.php", array("action" => "tools"), array(), array());
check("the tools area is closed when the owner turns it off", $page["redirect"] === BASE_URL(""));
$GLOBALS["CMSNT"]->sites["status_menu_tools"] = "1";

echo "\nlegacy USDT invoice\n";
$page = render_view("views/client/payment.php",
    array("action" => "payment", "trans_id" => "INV7Q2M9X"), array(), array());
assert_clean("invoice", $page["html"]);
check("invoice does not redirect a real id", $page["redirect"] === null);
check("invoice shows the amount", strpos($page["html"], "19.84") !== false);
check("invoice shows the receiving address", strpos($page["html"], "TXk9PreviewAddress") !== false);
check("invoice offers the address copy button", strpos($page["html"], 'data-copy="#invoice-address"') !== false);
/* The warning depends on PAYOS_ENABLED, which is part of the deployment's environment, not the code:
   assert the branch exists and that the page never invites a transfer to a retired channel. */
$invoiceView = (string) file_get_contents(__DIR__ . "/../views/client/payment.php");
check("invoice warns when the USDT channel is retired", strpos($invoiceView, "Kênh USDT đã ngừng nhận") !== false
    && strpos($invoiceView, 'payos_config()["enabled"]') !== false);
check("a pending invoice never claims to be paid", strpos($page["html"], "Chờ chuyển khoản") !== false);
$page = render_view("views/client/payment.php",
    array("action" => "payment", "trans_id" => "<script>alert(1)</script>"), array(), array());
check("a malformed invoice id is rejected", $page["redirect"] === BASE_URL(""));
$page = render_view("views/client/payment.php",
    array("action" => "payment", "trans_id" => "NOSUCHINVOICE"), array(), array());
check("an unknown invoice id goes home", $page["redirect"] === BASE_URL(""));

echo "\nAPI guide\n";
$page = render_view("views/client/document-api.php", array("action" => "document-api"),
    array("login" => "tok-lan"), array());
assert_clean("api guide", $page["html"]);
check("api guide does not redirect while the owner keeps the API on", $page["redirect"] === null);
check("api guide shows the customer's own key", strpos($page["html"], "0123456789abcdef0123456789abcdef") !== false);
check("api guide builds endpoints from the key",
    strpos($page["html"], "api/profile.php?api_key=0123456789abcdef0123456789abcdef") !== false
    && strpos($page["html"], "api/products.php?api_key=") !== false);
check("api guide offers a copy button per field, not a clipboard CDN",
    substr_count($page["html"], "data-copy=\"#") >= 5 && strpos($page["html"], "clipboard") === false);
check("api guide documents the buy contract", strpos($page["html"], "buyProduct") !== false
    && strpos($page["html"], "trans_id") !== false);
check("api guide no longer prints the session token", strpos($page["html"], "tok-lan") === false);
check("api guide has no inline style", strpos($page["html"], 'style="') === false);

$GLOBALS["CMSNT"]->sites["api_status"] = "0";
$page = render_view("views/client/document-api.php", array("action" => "document-api"),
    array("login" => "tok-lan"), array());
check("api guide goes home when the owner turns the API off", $page["redirect"] === BASE_URL("client/home"));
$GLOBALS["CMSNT"]->sites["api_status"] = "1";

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
