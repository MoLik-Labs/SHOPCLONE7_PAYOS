<?php

if(!defined("IN_SITE")) {
    exit("The Request Not Found");
}
$CMSNT = new DB();
date_default_timezone_set($CMSNT->site("timezone"));
if($CMSNT->get_row(" SELECT * FROM `block_ip` WHERE `ip` = '" . myip() . "' AND `banned` = 1 ")) {
    require_once __DIR__ . "/../views/common/block-ip.php";
    exit;
}
if(!function_exists("cal_days_in_month")) {
    function cal_days_in_month($calendar, $month, $year)
    {
        return date("t", mktime(0, 0, 0, $month, 1, $year));
    }
}
function removeSpaces($string)
{
    return str_replace(" ", "", $string);
}
function curl_get_contents($url, $timeout = 10)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3");
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $result = curl_exec($ch);
    if(curl_errno($ch)) {
        $result = false;
    }
    curl_close($ch);
    return $result;
}
function remove_html_tags($string)
{
    $string = preg_replace("/<ul[^>]*>/", "", $string);
    $string = preg_replace("/<\\/ul>/", "", $string);
    $string = preg_replace("/<li[^>]*>/", "", $string);
    $string = preg_replace("/<\\/li>/", "", $string);
    $string = preg_replace("/<b[^>]*>/", "", $string);
    $string = preg_replace("/<\\/b>/", "", $string);
    $string = preg_replace("/<i[^>]*>/", "", $string);
    $string = preg_replace("/<\\/i>/", "", $string);
    return $string;
}
function getDiscount($amount, $product_id)
{
    $CMSNT = orbit_db();
    foreach ($CMSNT->get_list("SELECT * FROM `product_discount` WHERE `min` <= '" . $amount . "' AND `product_id` = '" . $product_id . "' ORDER BY `min` DESC ") as $discount) {
        return $discount["discount"];
    }
    return 0;
}
function checkPromotion($amount)
{
    global $CMSNT;
    foreach ($CMSNT->get_list("SELECT * FROM `promotions` WHERE `min` <= '" . $amount . "' ORDER by `min` DESC ") as $promotion) {
        $received = $amount + $amount * $promotion["discount"] / 100;
        return $received;
    }
    return $amount;
}
function admin_msg_success($text, $url, $time)
{
    exit("<script type=\"text/javascript\">Swal.fire({\n        title: \"Thành công!\",\n        text: \"" . $text . "\",\n        icon: \"success\"\n    });\n    setTimeout(function(){ location.href = \"" . $url . "\" }," . $time . ");</script>");
}
function admin_msg_error($text, $url, $time)
{
    exit("<script type=\"text/javascript\">Swal.fire(\"Thất Bại\", \"" . $text . "\",\"error\");\n    setTimeout(function(){ location.href = \"" . $url . "\" }," . $time . ");</script>");
}
function admin_msg_warning($text, $url, $time)
{
    exit("<script type=\"text/javascript\">Swal.fire(\"Thông Báo\", \"" . $text . "\",\"warning\");\n    setTimeout(function(){ location.href = \"" . $url . "\" }," . $time . ");</script>");
}
function debit_processing($user_id)
{
    /* Balances are read and written under one row lock; see users::SettleDebit(). */
    $User = orbit_users();
    return $User->SettleDebit($user_id) === "settled";
}
function checkCoupon($product_id, $coupon, $user_id, $money, $pay)
{
    global $CMSNT;
    if($coupon = $CMSNT->get_row("SELECT * FROM `coupons` WHERE `code` = '" . check_string($coupon) . "' AND `min` <= " . $money . " AND `max` >= " . $money . " AND `used` < `amount` ")) {
        if($CMSNT->num_rows(" SELECT * FROM coupon_used WHERE `coupon_id` = '" . $coupon["id"] . "' ") < $coupon["amount"]) {
            if(!$CMSNT->get_row("SELECT * FROM `coupon_used` WHERE `coupon_id` = '" . $coupon["id"] . "' AND `user_id` = '" . $user_id . "' ")) {
                if($coupon["product_id"] == "") {
                    return $money * $coupon["discount"] / 100;
                }
                if(in_array($product_id, json_decode($coupon["product_id"]))) {
                    return $money * $coupon["discount"] / 100;
                }
                return false;
            }
            return false;
        }
        return false;
    }
    return false;
}
/**
 * Claim a coupon for one order, before the customer is charged — or refuse it.
 *
 * The old flow only *read* the coupon, charged the discounted price, and recorded the use after the
 * order existed. Two concurrent orders from the same customer both passed the read, both got the
 * discount, and the `used` counter could pass `amount`. This reserves the use first and returns the
 * discount in money; a failed reservation means the checkout stops instead of charging full price
 * for a coupon the customer believes is applied.
 *
 * Serialisation uses MySQL GET_LOCK on the coupon id: a per-connection advisory lock, so the
 * "already used by this user" read and the "slots left" read cannot both succeed. Fail-closed: if the
 * lock cannot be taken, the coupon is refused rather than trusted.
 *
 * The reservation is released by orbit_coupon_release_uncommitted() at shutdown unless the caller
 * called orbit_coupon_commit() — every refund/error path exits, so no site has to remember it.
 */
function orbit_coupon_claim($product_id, $code, $user_id, $money, $trans_id)
{
    global $CMSNT;
    $money = (float) $money;
    if ($money <= 0) {
        return false;
    }
    $row = $CMSNT->get_row("SELECT * FROM `coupons` WHERE `code` = '" . check_string($code) . "' AND `min` <= " . $money . " AND `max` >= " . $money . " ");
    if (!$row) {
        return false;
    }
    if ($row["product_id"] != "" && !in_array($product_id, (array) json_decode($row["product_id"]))) {
        return false;
    }
    $couponId = (int) $row["id"];
    $lockName = "orbit_coupon_" . $couponId;
    $lock = $CMSNT->get_row("SELECT GET_LOCK('" . addslashes($lockName) . "', 3) AS `ok`");
    if (!$lock || !isset($lock["ok"]) || (int) $lock["ok"] !== 1) {
        return false;
    }
    $fresh = $CMSNT->get_row("SELECT * FROM `coupons` WHERE `id` = " . $couponId . " AND `used` < `amount` ");
    $alreadyUsed = $CMSNT->get_row("SELECT `id` FROM `coupon_used` WHERE `coupon_id` = " . $couponId . " AND `user_id` = " . (int) $user_id . " ");
    $claimed = false;
    if ($fresh && !$alreadyUsed) {
        $claimed = (bool) $CMSNT->insert("coupon_used", ["coupon_id" => $couponId, "user_id" => (int) $user_id, "trans_id" => $trans_id, "create_gettime" => gettime()]);
        if ($claimed) {
            $CMSNT->cong("coupons", "used", 1, " `id` = " . $couponId . " ");
        }
    }
    $CMSNT->get_row("SELECT RELEASE_LOCK('" . addslashes($lockName) . "') AS `ok`");
    if (!$claimed) {
        return false;
    }
    $GLOBALS["orbit_coupon_claim"] = ["trans_id" => (string) $trans_id, "coupon_id" => $couponId, "committed" => false];
    register_shutdown_function("orbit_coupon_release_uncommitted");
    return $money * (float) $fresh["discount"] / 100;
}
/** The order exists: keep the coupon use that was reserved for it. */
function orbit_coupon_commit()
{
    if (isset($GLOBALS["orbit_coupon_claim"])) {
        $GLOBALS["orbit_coupon_claim"]["committed"] = true;
    }
}
/** Shutdown guard: the request ended without a completed order, so give the coupon slot back. */
function orbit_coupon_release_uncommitted()
{
    if (empty($GLOBALS["orbit_coupon_claim"]) || !empty($GLOBALS["orbit_coupon_claim"]["committed"])) {
        return;
    }
    $claim = $GLOBALS["orbit_coupon_claim"];
    $GLOBALS["orbit_coupon_claim"]["committed"] = true;
    try {
        $db = orbit_db();
        $row = $db->get_row("SELECT `id`,`coupon_id` FROM `coupon_used` WHERE `trans_id` = '" . addslashes($claim["trans_id"]) . "' ");
        if ($row) {
            $db->remove("coupon_used", " `id` = " . (int) $row["id"]);
            $db->cong("coupons", "used", -1, " `id` = " . (int) $row["coupon_id"] . " AND `used` > 0 ");
        }
    } catch (Throwable $error) {
        error_log("[orbit-coupon] could not release an uncommitted claim");
    }
}
/**
 * Authorise a cron/worker endpoint that used to be callable by anyone.
 *
 * CLI is always allowed. Over HTTP the request must present CRON_TOKEN — as an X-Cron-Token header
 * or `?token=`, at least 16 characters. With no token configured, HTTP is refused: a public cron
 * URL lets a stranger make the server poll supplier APIs, run warehouse automations or send the
 * e-mail queue, and those calls cost money and rate limits.
 */
function orbit_cron_allowed($sapi = null)
{
    $sapi = $sapi === null ? PHP_SAPI : (string) $sapi;
    if ($sapi === "cli") {
        return true;
    }
    $token = getenv("CRON_TOKEN");
    if (!is_string($token) || $token === "") {
        $token = isset($_ENV["CRON_TOKEN"]) ? (string) $_ENV["CRON_TOKEN"] : "";
    }
    if (strlen($token) < 16) {
        return false;
    }
    $provided = isset($_SERVER["HTTP_X_CRON_TOKEN"]) ? (string) $_SERVER["HTTP_X_CRON_TOKEN"] : "";
    if ($provided === "" && isset($_GET["token"])) {
        $provided = (string) $_GET["token"];
    }
    return $provided !== "" && hash_equals($token, $provided);
}
/**
 * Verify a login password against the stored hash, accepting the legacy pre-sanitised form.
 *
 * Login used to run the password through check_string() before verifying: addslashes() and
 * htmlspecialchars() rewrote `'`, `"`, `&`, `<` and trimmed spaces, so a password containing any of
 * them was hashed differently from what the user typed. Passwords must be bytes, not HTML. New
 * registrations store the raw password; this function still accepts the old form so existing
 * accounts keep working, and orbit_password_upgrade() moves a bcrypt hash to the raw password after
 * a successful login.
 */
function orbit_password_matches($raw, $stored)
{
    global $CMSNT;
    $raw = (string) $raw;
    $stored = (string) $stored;
    if ($stored === "") {
        return false;
    }
    if ($CMSNT->site("type_password") == "bcrypt") {
        if (password_verify($raw, $stored)) {
            return true;
        }
        $legacy = check_string($raw);
        return $legacy !== $raw && password_verify($legacy, $stored);
    }
    $legacy = check_string($raw);
    if (hash_equals($stored, (string) TypePassword($raw))) {
        return true;
    }
    return $legacy !== $raw && hash_equals($stored, (string) TypePassword($legacy));
}
/** Re-hash a bcrypt password with its raw bytes after a successful legacy login. */
function orbit_password_upgrade($user_id, $raw)
{
    global $CMSNT;
    if ($CMSNT->site("type_password") != "bcrypt") {
        return;
    }
    $stored = getRowRealtime("users", (int) $user_id, "password");
    if (!is_string($stored) || $stored === "" || password_verify((string) $raw, $stored)) {
        return;
    }
    $CMSNT->update("users", ["password" => password_hash((string) $raw, PASSWORD_BCRYPT)], " `id` = " . (int) $user_id . " ");
}
/**
 * Baseline response headers for every rendered page.
 *
 * The site sent none: a browser was free to sniff a mislabelled upload as HTML, frame the storefront
 * for clickjacking, and forward full URLs (with tokens in them) to third parties. Nothing here needs
 * a nonce or a policy decision, so it is safe for every page; call it before the first byte of output.
 */
function orbit_security_headers()
{
    if(headers_sent()) {
        return;
    }
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: SAMEORIGIN");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
}
function checkPermission($admin_id, $role)
{
    global $CMSNT;
    if($admin_id == 99999) {
        return true;
    }
    $row = $CMSNT->get_row(" SELECT * FROM `admin_role` WHERE `id` = '" . $admin_id . "' ");
    if(!$row) {
        return false;
    }
    /* A role row with empty or corrupt JSON decoded to null; PHP 8 throws on in_array(null). */
    $roles = json_decode(isset($row["role"]) ? (string) $row["role"] : "", true);
    return is_array($roles) && in_array($role, $roles);
}
function orbit_db()
{
    global $CMSNT;
    /* Reuse the request's connection. `new DB()` opened (and later closed) a second connection —
       a TCP + auth + TLS handshake for a single COUNT. */
    if (!($CMSNT instanceof DB)) {
        $CMSNT = new DB();
    }
    return $CMSNT;
}
function orbit_users()
{
    static $instance = null;
    if ($instance === null) {
        $instance = new users();
    }
    return $instance;
}
function orbit_post_id_list($value)
{
    /* Admin forms post `product_id[]`/`role[]`. json_encode() alone is NOT an SQL escape — a quote in
       one of the values would still break out. Keep only plausible ids, as strings, to preserve the
       strict comparisons the edit forms use against row ids. */
    $ids = array();
    foreach ((array) $value as $id) {
        if (is_scalar($id) && preg_match('/^[1-9][0-9]{0,18}$/', (string) $id)) {
            $ids[] = (string) $id;
        }
    }
    return $ids;
}
function getStock($code)
{
    $row = orbit_db()->get_row(" SELECT COUNT(id) FROM `product_stock` WHERE  `product_code` = '" . addslashes((string) $code) . "' ");
    return $row ? $row["COUNT(id)"] : 0;
}
function currencyDefault()
{
    $row = orbit_db()->get_row(" SELECT `code` FROM `currencies` WHERE `display` = 1 AND `default_currency` = 1");
    return $row ? $row["code"] : "VND";
}
function dirImageProduct($image)
{
    $path = "assets/storage/images/products/" . $image;
    return $path;
}
function generate_csrf_token()
{
    if(!isset($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = base64_encode(openssl_random_pseudo_bytes(32));
    }
    return $_SESSION["csrf_token"];
}
function verify_csrf_token($token)
{
    /* hash_equals: constant-time, and a missing session token is always a failure. */
    return isset($_SESSION["csrf_token"]) && is_string($_SESSION["csrf_token"])
        && is_string($token) && $token !== ""
        && hash_equals($_SESSION["csrf_token"], $token);
}
/**
 * The host this request was sent to, lower-cased, or null when it cannot be parsed.
 *
 * HTTP_HOST is the only source: it is what the browser put in the URL, so a cross-site page
 * cannot make it name our domain while the request carries a foreign Origin.
 */
function orbit_csrf_host()
{
    $host = isset($_SERVER["HTTP_HOST"]) ? trim((string) $_SERVER["HTTP_HOST"]) : "";
    if ($host === "" || strlen($host) > 255 || !preg_match('/^([A-Za-z0-9.\-]+|\[[0-9A-Fa-f:.]+\])(:[0-9]{1,5})?$/', $host)) {
        return null;
    }
    $parts = parse_url("http://" . $host);
    if (!is_array($parts) || empty($parts["host"])) {
        return null;
    }
    return strtolower($parts["host"]);
}
/**
 * Where the browser says the request came from: the Origin header, else the Referer.
 *
 * Returns the lower-cased host, or null for a missing header or the literal "null" origin that
 * sandboxed frames and file:// pages send. The scheme is deliberately not part of the comparison:
 * TLS may be terminated by a proxy (Render, Cloudflare) while PHP still sees http.
 */
function orbit_csrf_source()
{
    $raw = "";
    if (!empty($_SERVER["HTTP_ORIGIN"]) && is_string($_SERVER["HTTP_ORIGIN"])) {
        $raw = trim($_SERVER["HTTP_ORIGIN"]);
    } elseif (!empty($_SERVER["HTTP_REFERER"]) && is_string($_SERVER["HTTP_REFERER"])) {
        $raw = trim($_SERVER["HTTP_REFERER"]);
    }
    if ($raw === "" || strtolower($raw) === "null" || strlen($raw) > 2048) {
        return null;
    }
    $parts = parse_url($raw);
    if (!is_array($parts) || empty($parts["host"]) || empty($parts["scheme"])) {
        return null;
    }
    if (!in_array(strtolower($parts["scheme"]), array("http", "https"), true)) {
        return null;
    }
    return strtolower($parts["host"]);
}
/**
 * True when the request proves it is not a cross-site forgery.
 *
 * First choice: the per-session token, sent as the `X-CSRF-Token` header by public/common/csrf.js
 * or as a `csrf_token` form field. Older pages that have not shipped the script yet fall back to
 * a same-origin Origin/Referer — every browser that can forge a cross-site POST sends a foreign
 * Origin, so this still refuses the attack while never breaking a page that we have not touched.
 * The fallback is logged once per request so it can be retired when the access log is quiet.
 */
function orbit_csrf_ok()
{
    $token = "";
    if (isset($_POST["csrf_token"]) && is_string($_POST["csrf_token"])) {
        $token = $_POST["csrf_token"];
    } elseif (isset($_SERVER["HTTP_X_CSRF_TOKEN"]) && is_string($_SERVER["HTTP_X_CSRF_TOKEN"])) {
        $token = $_SERVER["HTTP_X_CSRF_TOKEN"];
    }
    if ($token !== "" && verify_csrf_token($token)) {
        return true;
    }
    $source = orbit_csrf_source();
    $self = orbit_csrf_host();
    if ($source !== null && $self !== null && hash_equals($self, $source)) {
        error_log("[orbit-csrf] same-origin request without a token: " . (isset($_SERVER["REQUEST_URI"]) ? $_SERVER["REQUEST_URI"] : "-"));
        return true;
    }
    return false;
}
/**
 * Stop a state-changing request that did not come from our own pages.
 *
 * `$exemptActions` lists pre-authentication actions (login, register, password reset) that a
 * visitor must be able to reach before a session exists. Everything else under ajaxs/ is guarded.
 */
function orbit_require_csrf($exemptActions = array())
{
    if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
        return;
    }
    $action = isset($_POST["action"]) && is_string($_POST["action"]) ? $_POST["action"] : "";
    if ($action !== "" && in_array($action, $exemptActions, true)) {
        return;
    }
    if (orbit_csrf_ok()) {
        return;
    }
    http_response_code(403);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(array("status" => "error", "msg" => __("Phiên làm việc đã hết, vui lòng tải lại trang")), JSON_UNESCAPED_UNICODE);
    exit;
}
function display_camp($status)
{
    if($status == 0) {
        return "<span class=\"badge bg-info\">Processing</span>";
    }
    if($status == 1) {
        return "<span class=\"badge bg-success\">Completed</span>";
    }
    if($status == 2) {
        return "<span class=\"badge bg-danger\">Cancel</span>";
    }
    return "<span class=\"badge bg-warning\">Khác</span>";
}
function display_withdraw($data)
{
    if($data == "pending") {
        $show = "<span class=\"badge bg-warning\">Pending</span>";
    } elseif($data == "cancel") {
        $show = "<span class=\"badge bg-danger\">Cancel</span>";
    } elseif($data == "completed") {
        $show = "<span class=\"badge bg-success\">Completed</span>";
    }
    return $show;
}
function setCurrency($id)
{
    global $CMSNT;
    if($row = $CMSNT->get_row("SELECT * FROM `currencies` WHERE `id` = '" . $id . "' AND `display` = 1 ")) {
        $isSet = setcookie("currency", $row["id"], time() + 946080000, "/");
        if($isSet) {
            return true;
        }
        return false;
    }
    return false;
}
function getCurrency()
{
    global $CMSNT;
    if(isset($_COOKIE["currency"])) {
        $currency = check_string($_COOKIE["currency"]);
        $rowcurrency = $CMSNT->get_row("SELECT * FROM `currencies` WHERE `id` = '" . $currency . "' AND `display` = 1 ");
        if($rowcurrency) {
            return $rowcurrency["id"];
        }
    }
    $rowcurrency = $CMSNT->get_row("SELECT * FROM `currencies` WHERE `default_currency` = 1 ");
    if($rowcurrency) {
        return $rowcurrency["id"];
    }
    return false;
}
function display_invoice($data)
{
    if($data == "waiting") {
        $show = "<span class=\"badge bg-warning\">Waiting</span>";
    } elseif($data == "expired") {
        $show = "<span class=\"badge bg-danger\">Expired</span>";
    } elseif($data == "completed") {
        $show = "<span class=\"badge bg-success\">Completed</span>";
    }
    return $show;
}
function isValidTRC20Address($address)
{
    $curl = curl_init();
    curl_setopt_array($curl, [CURLOPT_URL => "https://walletvalidator.com/usdt-trc20-wallet-validator/", CURLOPT_RETURNTRANSFER => true, CURLOPT_ENCODING => "", CURLOPT_MAXREDIRS => 10, CURLOPT_TIMEOUT => 0, CURLOPT_FOLLOWLOCATION => true, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, CURLOPT_CUSTOMREQUEST => "POST", CURLOPT_POSTFIELDS => ["validate" => $address]]);
    $response = curl_exec($curl);
    curl_close($curl);
    $response = json_decode($response, true);
    if($response["ok"]) {
        return true;
    }
    return false;
}
function url()
{
    isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] != "off" ? "https" : "http";
    $_SERVER["SERVER_NAME"];
    $_SERVER["REQUEST_URI"];
    return $_SERVER["REQUEST_SCHEME"] . "://" . $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];
}
function is_valid_domain_name($domain_name)
{
    return preg_match("/^([a-z\\d](-*[a-z\\d])*)(\\.([a-z\\d](-*[a-z\\d])*))*\$/i", $domain_name) && preg_match("/^.{1,253}\$/", $domain_name) && preg_match("/^[^\\.]{1,63}(\\.[^\\.]{1,63})*\$/", $domain_name);
}
function display_domains($data)
{
    if($data == 1) {
        $show = "<span class=\"badge bg-success\">" . __("Hoạt Động") . "</span>";
    } elseif($data == 0) {
        $show = "<span class=\"badge bg-warning\">" . __("Đang Xây Dựng") . "</span>";
    } elseif($data == 2) {
        $show = "<span class=\"badge bg-danger\">" . __("Huỷ") . "</span>";
    }
    return $show;
}
function addRef($user_id, $price, $note = "")
{
    $CMSNT = orbit_db();
    if($CMSNT->site("status_ref") != 1) {
        return false;
    }
    $getUser = $CMSNT->get_row(" SELECT * FROM `users` WHERE `id` = '" . $user_id . "' ");
    if($getUser["ref_id"] != 0) {
        $price = $price * $CMSNT->site("ck_ref") / 100;
        $CMSNT->cong("users", "ref_money", $price, " `id` = '" . $getUser["ref_id"] . "' ");
        $CMSNT->cong("users", "ref_total_money", $price, " `id` = '" . $getUser["ref_id"] . "' ");
        $CMSNT->cong("users", "ref_amount", $price, " `id` = '" . $getUser["id"] . "' ");
        $CMSNT->insert("log_ref", ["user_id" => $getUser["ref_id"], "reason" => $note, "sotientruoc" => getRowRealtime("users", $getUser["ref_id"], "ref_money") - $price, "sotienthaydoi" => $price, "sotienhientai" => getRowRealtime("users", $getUser["ref_id"], "ref_money"), "create_gettime" => gettime()]);
        return true;
    }
    return false;
}
function sendMessAdmin($my_text)
{
    if($my_text != "") {
        return sendMessTelegram($my_text);
    }
    return false;
}
function sendMessTelegram($my_text, $token = "", $chat_id = "")
{
    $CMSNT = orbit_db();
    if($chat_id == "") {
        $chat_id = $CMSNT->site("telegram_chat_id");
    }
    if($token == "") {
        $token = $CMSNT->site("telegram_token");
    }
    if($my_text == "") {
        return false;
    }
    if($CMSNT->site("telegram_status") == 1 && $token != "" && $chat_id != "") {
        $telegram_url = "https://api.telegram.org/bot" . $token . "/sendMessage";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $telegram_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(["chat_id" => $chat_id, "text" => $my_text, "parse_mode" => "HTML"]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }
    return false;
}
function getFlag($flag)
{
    if(empty($flag)) {
        return "";
    }
    return "<img width=\"30px;\" src=\"https://flagicons.lipis.dev/flags/4x3/" . $flag . ".svg\">";
}
function claimSpin($user_id, $trans_id, $total_money)
{
    $CMSNT = orbit_db();
    $USER = orbit_users();
    if($CMSNT->site("status_spin") == 1 && $CMSNT->site("condition_spin") <= $total_money) {
        $USER->AddSpin($user_id, 1, "Nhập 1 SPIN từ đơn hàng #" . $trans_id);
    }
}
function getRandomWeightedElement(array $weightedValues)
{
    if (array_sum($weightedValues) <= 0 || count(array_filter($weightedValues, function ($weight) { return !is_numeric($weight) || $weight < 0 || (int) $weight != $weight; })) > 0) {
        throw new InvalidArgumentException('Weights must be non-negative integers with a positive total.');
    }
    $Rand = random_int(1, (int) array_sum($weightedValues));
    foreach ($weightedValues as $key => $value) {
        $Rand -= $value;
        if($Rand <= 0) {
            return $key;
        }
    }
}
function checkFormatCard($type, $seri, $pin)
{
    $seri = strlen($seri);
    $pin = strlen($pin);
    $data = [];
    if($type == "Viettel" || $type == "viettel" || $type == "VT" || $type == "VIETTEL") {
        if($seri != 11 && $seri != 14) {
            $data = ["status" => false, "msg" => "Độ dài seri không phù hợp"];
            return $data;
        }
        if($pin != 13 && $pin != 15) {
            $data = ["status" => false, "msg" => "Độ dài mã thẻ không phù hợp"];
            return $data;
        }
    }
    if($type == "Mobifone" || $type == "mobifone" || $type == "Mobi" || $type == "MOBIFONE") {
        if($seri != 15) {
            $data = ["status" => false, "msg" => "Độ dài seri không phù hợp"];
            return $data;
        }
        if($pin != 12) {
            $data = ["status" => false, "msg" => "Độ dài mã thẻ không phù hợp"];
            return $data;
        }
    }
    if($type == "VNMB" || $type == "Vnmb" || $type == "VNM" || $type == "VNMOBI") {
        if($seri != 16) {
            $data = ["status" => false, "msg" => "Độ dài seri không phù hợp"];
            return $data;
        }
        if($pin != 12) {
            $data = ["status" => false, "msg" => "Độ dài mã thẻ không phù hợp"];
            return $data;
        }
    }
    if($type == "Vinaphone" || $type == "vinaphone" || $type == "Vina" || $type == "VINAPHONE") {
        if($seri != 14) {
            $data = ["status" => false, "msg" => "Độ dài seri không phù hợp"];
            return $data;
        }
        if($pin != 14) {
            $data = ["status" => false, "msg" => "Độ dài mã thẻ không phù hợp"];
            return $data;
        }
    }
    if($type == "Garena" || $type == "garena") {
        if($seri != 9) {
            $data = ["status" => false, "msg" => "Độ dài seri không phù hợp"];
            return $data;
        }
        if($pin != 16) {
            $data = ["status" => false, "msg" => "Độ dài mã thẻ không phù hợp"];
            return $data;
        }
    }
    if($type == "Zing" || $type == "zing" || $type == "ZING") {
        if($seri != 12) {
            $data = ["status" => false, "msg" => "Độ dài seri không phù hợp"];
            return $data;
        }
        if($pin != 9) {
            $data = ["status" => false, "msg" => "Độ dài mã thẻ không phù hợp"];
            return $data;
        }
    }
    if($type == "Vcoin" || $type == "VTC") {
        if($seri != 12) {
            $data = ["status" => false, "msg" => "Độ dài seri không phù hợp"];
            return $data;
        }
        if($pin != 12) {
            $data = ["status" => false, "msg" => "Độ dài mã thẻ không phù hợp"];
            return $data;
        }
    }
    $data = ["status" => true, "msg" => "Success"];
    return $data;
}
function active_sidebar_client($action)
{
    foreach ($action as $row) {
        if(isset($_GET["action"]) && $_GET["action"] == $row) {
            return "mobile-menu-active";
        }
    }
    return "";
}
function show_sidebar_client($action)
{
    foreach ($action as $row) {
        if(isset($_GET["action"]) && $_GET["action"] == $row) {
            return "active open";
        }
    }
    return "";
}
function show_sidebar($action)
{
    foreach ($action as $row) {
        if(isset($_GET["action"]) && $_GET["action"] == $row) {
            return "active open";
        }
    }
    return "";
}
function parse_order_id($des, $MEMO_PREFIX)
{
    $re = "/" . $MEMO_PREFIX . "\\d+/im";
    preg_match_all($re, $des, $matches, PREG_SET_ORDER, 0);
    if(count($matches) == 0) {
        return NULL;
    }
    $orderCode = $matches[0][0];
    $prefixLength = strlen($MEMO_PREFIX);
    $orderId = (int) substr($orderCode, $prefixLength);
    return $orderId;
}
function display_status_toyyibpay($status)
{
    if($status == 0) {
        return "<b style=\"color:#db7e06;\">" . __("Waiting") . "</b>";
    }
    if($status == "confirming") {
        return "<b style=\"color:blue;\">" . __("Confirming") . "</b>";
    }
    if($status == "confirmed") {
        return "<b style=\"color:green;\">" . __("Confirmed") . "</b>";
    }
    if($status == "refunded") {
        return "<b style=\"color:pink;\">" . __("Refunded") . "</b>";
    }
    if($status == "expired") {
        return "<b style=\"color:red;\">" . __("Expired") . "</b>";
    }
    if($status == 2) {
        return "<b style=\"color:red;\">" . __("Failed") . "</b>";
    }
    if($status == "partially_paid") {
        return "<b style=\"color:green;\">" . __("Partially Paid") . "</b>";
    }
    if($status == 1) {
        return "<b style=\"color:green;\">" . __("Finished") . "</b>";
    }
}
function display_service($status)
{
    if($status == 0) {
        return "<b style=\"color:blue;\">Đang chờ xử lý</b>";
    }
    if($status == 1) {
        return "<b style=\"color:green;\">Hoàn tất</b>";
    }
    if($status == 2) {
        return "<b style=\"color:red;\">Huỷ</b>";
    }
    return "<b style=\"color:yellow;\">Khác</b>";
}
function display_card($status)
{
    if($status == "pending") {
        return "<span class=\"badge bg-info\">" . __("Đang chờ xử lý") . "</span>";
    }
    if($status == "completed") {
        return "<span class=\"badge bg-success\">" . __("Thành công") . "</span>";
    }
    if($status == "error") {
        return "<span class=\"badge bg-danger\">" . __("Thất bại") . "</span>";
    }
    return "<span class=\"badge bg-warning\">Khác</span>";
}
function display_invoice_text($status)
{
    if($status == 0) {
        return __("Đang chờ thanh toán");
    }
    if($status == 1) {
        return __("Đã thanh toán");
    }
    if($status == 2) {
        return __("Huỷ bỏ");
    }
    return __("Khác");
}
/**
 * Resolve a page of usernames in one query instead of one per row.
 *
 * `getRowRealtime("users", $row["user_id"], "username")` inside a table loop costs one round trip per
 * row; an admin list of 100 orders paid 100 extra queries. Pass the rows and the key that holds the
 * user id, then read the map with orbit_user_name().
 *
 *   $names = orbit_user_names($orders, "buyer");
 *   echo orbit_user_name($names, $order["buyer"]);
 */
function orbit_user_names($rows, $key = "user_id")
{
    global $CMSNT;
    if (!is_array($rows) || !$rows) {
        return array();
    }
    $ids = array();
    foreach ($rows as $row) {
        if (is_array($row) && isset($row[$key]) && 0 < (int) $row[$key]) {
            $ids[(int) $row[$key]] = true;
        }
    }
    if (!$ids) {
        return array();
    }
    $names = array();
    foreach (array_chunk(array_keys($ids), 500) as $chunk) {
        foreach ($CMSNT->get_list("SELECT `id`,`username` FROM `users` WHERE `id` IN (" . implode(",", $chunk) . ")") as $user) {
            $names[(int) $user["id"]] = (string) $user["username"];
        }
    }
    return $names;
}
/**
 * Resolve a display column for a list of rows in one query per 500 ids.
 *
 * Admin tables showed a category, supplier or product name by calling getRowRealtime() inside the
 * row loop — one SELECT per row. The table and column come from this whitelist, never from a request,
 * so the identifiers interpolated into SQL are fixed strings.
 *
 * @param string $table  users|categories|suppliers|post_category|products
 * @param array  $rows   rows already loaded for the page
 * @param string $key    key in $rows that holds the foreign id
 * @param string $column column to read (must be allowed for that table)
 * @return array<int,string>
 */
function orbit_lookup_names($table, $rows, $key, $column = "name")
{
    global $CMSNT;
    static $allowed = array(
        "users" => array("username"),
        "categories" => array("name"),
        "suppliers" => array("domain"),
        "post_category" => array("name"),
        "products" => array("name", "code"),
    );
    if (!is_array($rows) || !$rows || !isset($allowed[$table]) || !in_array($column, $allowed[$table], true)) {
        return array();
    }
    $ids = array();
    foreach ($rows as $row) {
        if (is_array($row) && isset($row[$key]) && 0 < (int) $row[$key]) {
            $ids[(int) $row[$key]] = true;
        }
    }
    if (!$ids) {
        return array();
    }
    $names = array();
    foreach (array_chunk(array_keys($ids), 500) as $chunk) {
        $sql = "SELECT `id`,`" . $column . "` FROM `" . $table . "` WHERE `id` IN (" . implode(",", $chunk) . ")";
        foreach ($CMSNT->get_list($sql) as $row) {
            $names[(int) $row["id"]] = (string) $row[$column];
        }
    }
    return $names;
}
/** Safe lookup into a map from orbit_lookup_names(); unknown ids print as an empty cell. */
function orbit_lookup_name($names, $id)
{
    return is_array($names) && isset($names[(int) $id]) ? $names[(int) $id] : "";
}
/** Backwards-compatible name for the users variant of orbit_lookup_names(). */
function orbit_user_name($names, $id)
{
    return orbit_lookup_name($names, $id);
}
/**
 * A handful of real user ids, for the demo data generator in cron/cron.php.
 *
 * The generator used to call `SELECT * FROM users ORDER BY RAND()` once per product — MySQL sorts
 * the whole user table and PHP fetches one row, repeated for every product on every tick. On a shop
 * with 200 000 members that is minutes of work for a cosmetic feature. This picks ids through the
 * primary key instead: a random id probe, then the next existing row. `$count` probes at most, so the
 * cost is bounded by the count, not by the table.
 *
 * @return int[]  distinct ids; empty when the site has no users
 */
function orbit_random_user_ids($count = 20)
{
    global $CMSNT;
    $count = (int) $count;
    if ($count < 1) {
        $count = 1;
    }
    if ($count > 100) {
        $count = 100;
    }
    $max = $CMSNT->get_row("SELECT MAX(`id`) AS `max_id` FROM `users`");
    $maxId = $max && isset($max["max_id"]) ? (int) $max["max_id"] : 0;
    $ids = array();
    if ($maxId > 0) {
        /* Random PK probes give a different sample each tick without sorting the table. */
        for ($attempt = 0; $attempt < $count * 3 && count($ids) < $count; ++$attempt) {
            $probe = random_int(1, $maxId);
            $row = $CMSNT->get_row("SELECT `id` FROM `users` WHERE `id` >= " . $probe . " ORDER BY `id` ASC LIMIT 1");
            if ($row && isset($row["id"])) {
                $ids[(int) $row["id"]] = true;
            }
        }
        if (!$ids && $maxId > 0) {
            $row = $CMSNT->get_row("SELECT `id` FROM `users` ORDER BY `id` ASC LIMIT 1");
            if ($row && isset($row["id"])) {
                $ids[(int) $row["id"]] = true;
            }
        }
        /*
         * A small shop with sparse ids can miss every probe (three members among a million ids).
         * Walk forward from the last hit until the requested number is found, wrapping once; the
         * walk stops when it reaches an id it already has, so a table with fewer members than
         * `$count` costs one wrap and returns everyone instead of looping.
         */
        if (0 < count($ids) && count($ids) < $count) {
            $last = max(array_keys($ids));
            $first = min(array_keys($ids));
            $guard = $count + 2;
            while (count($ids) < $count && 0 < $guard--) {
                $row = $CMSNT->get_row("SELECT `id` FROM `users` WHERE `id` > " . (int) $last . " ORDER BY `id` ASC LIMIT 1");
                if (!$row || !isset($row["id"])) {
                    $row = $CMSNT->get_row("SELECT `id` FROM `users` ORDER BY `id` ASC LIMIT 1");
                    if (!$row || !isset($row["id"])) {
                        break;
                    }
                }
                $next = (int) $row["id"];
                if (isset($ids[$next]) || $next === (int) $first) {
                    $ids[$next] = true;
                    break;
                }
                $ids[$next] = true;
                $last = $next;
            }
        }
    }
    if (!$ids) {
        $row = $CMSNT->get_row("SELECT `id` FROM `users` ORDER BY `id` ASC LIMIT 1");
        if ($row && isset($row["id"])) {
            $ids[(int) $row["id"]] = true;
        }
    }
    return array_keys($ids);
}
/**
 * How many times each coupon has been redeemed, in one grouped query.
 *
 * The coupon list ran `num_rows(SELECT * FROM coupon_used WHERE coupon_id = ?)` once per row.
 *
 * @return array<int,int> coupon id => redemption count
 */
function orbit_coupon_usage_counts()
{
    $counts = array();
    foreach (orbit_db()->get_list("SELECT `coupon_id`, COUNT(*) AS `c` FROM `coupon_used` GROUP BY `coupon_id` ") as $row) {
        $counts[(int) $row["coupon_id"]] = (int) $row["c"];
    }
    return $counts;
}
/**
 * How many products each category has, in one grouped query.
 *
 * The category screens printed a count per row with `num_rows(...)`; `num_rows()` also counts by
 * selecting the whole result set, which makes the per-row cost worse than a plain COUNT.
 *
 * @return array<int,int> category id => product count
 */
function orbit_category_product_counts()
{
    $counts = array();
    foreach (orbit_db()->get_list("SELECT `category_id`, COUNT(*) AS `c` FROM `products` GROUP BY `category_id` ") as $row) {
        $counts[(int) $row["category_id"]] = (int) $row["c"];
    }
    return $counts;
}
/**
 * Every product, grouped by category id, for the `<optgroup>` selects in the admin.
 *
 * Four admin pages rendered a category optgroup by running one `SELECT * FROM products WHERE
 * category_id = ?` per category. The grouped read is one query for the page; a category with no
 * products simply has no key.
 *
 * @return array<int,array<int,array{id:int,name:string}>>
 */
function orbit_products_by_category()
{
    $groups = array();
    foreach (orbit_db()->get_list("SELECT `id`, `name`, `category_id` FROM `products` ") as $row) {
        $groups[(int) $row["category_id"]][] = array("id" => (int) $row["id"], "name" => (string) $row["name"]);
    }
    return $groups;
}
/**
 * Per-code row counts for the stock and dead-stock tables, in one query per 500 codes.
 *
 * The admin product list printed a live stock count and a dead-stock count for every row, which was
 * two COUNT queries per product (plus the list query itself). The table name comes from a fixed
 * whitelist so it can never carry request data.
 *
 * @param string $table "product_stock" or "product_die"
 * @param array  $codes product codes to count
 * @return array<string,int> code => count; missing codes are absent, not zero
 */
function orbit_counts_by_code($table, $codes)
{
    if (!in_array($table, array("product_stock", "product_die"), true)) {
        return array();
    }
    $codes = array_values(array_unique(array_filter(array_map("strval", (array) $codes), "strlen")));
    $counts = array();
    if (!$codes) {
        return $counts;
    }
    foreach (array_chunk($codes, 500) as $chunk) {
        $quoted = array();
        foreach ($chunk as $code) {
            $quoted[] = "'" . addslashes($code) . "'";
        }
        $rows = orbit_db()->get_list(
            "SELECT `product_code`, COUNT(*) AS `c` FROM `" . $table . "` WHERE `product_code` IN ("
            . implode(",", $quoted) . ") GROUP BY `product_code`"
        );
        foreach ($rows as $row) {
            $counts[(string) $row["product_code"]] = (int) $row["c"];
        }
    }
    return $counts;
}
/**
 * Parse a withdrawal amount from a form, or return false.
 *
 * `"abc" < 50000` is false in PHP 8 — the operands are compared as strings — so a plain comparison
 * let a non-number through to a numeric SQL update and the member saw a generic error. This accepts
 * only a finite, positive number and lets the caller do its min/balance checks as floats.
 *
 * @param mixed $raw
 * @return float|false
 */
function orbit_withdraw_amount($raw)
{
    return orbit_positive_amount($raw);
}
/**
 * A finite, strictly positive number from a form, or false.
 *
 * Shared by the legacy recharge forms: `"abc" < 50000` is false in PHP 8, so a raw comparison let a
 * non-number through to numeric SQL and to a provider request.
 *
 * @param mixed $raw
 * @return float|false
 */
function orbit_positive_amount($raw)
{
    if (is_int($raw) || is_float($raw)) {
        $value = (float) $raw;
    } elseif (is_string($raw) && is_numeric($raw)) {
        $value = (float) $raw;
    } else {
        return false;
    }
    if (!is_finite($value) || $value <= 0) {
        return false;
    }
    return $value;
}
/**
 * Deliver one locally stocked account exactly once.
 *
 * The checkout loop inserted into `product_sold` and then removed the stock row in a second
 * statement. Two buyers checking out at the same time both selected the same stock row (the
 * SELECT ... LIMIT ran before either delete), both got the account, and the second delete removed
 * nothing. The claim is now a delete inside a transaction: only the request whose DELETE affects a
 * row may insert the sold record, and a failed insert rolls the stock row back into place.
 *
 * @param DB $db
 * @param array $stockRow one row from `product_stock`
 * @param array $sold data for the `product_sold` row
 * @return bool true when this request delivered the account
 */
function orbit_deliver_stock($db, $stockRow, array $sold)
{
    if(!is_array($stockRow) || empty($stockRow["id"])) {
        return false;
    }
    return (bool) $db->transaction(function () use ($db, $stockRow, $sold) {
        $isDelete = $db->query("DELETE FROM `product_stock` WHERE `id` = " . (int) $stockRow["id"]);
        if(!$isDelete || $db->affectedRows() !== 1) {
            return false;
        }
        return (bool) $db->insert("product_sold", $sold);
    });
}
/**
 * Move a sold row back into stock exactly once (the reverse of orbit_deliver_stock).
 *
 * @param DB $db
 * @param array $soldRow one row from `product_sold`
 * @param array $stock data for the `product_stock` row
 * @return bool true when this request returned the account to stock
 */
function orbit_return_stock($db, $soldRow, array $stock)
{
    if(!is_array($soldRow) || empty($soldRow["id"])) {
        return false;
    }
    return (bool) $db->transaction(function () use ($db, $soldRow, $stock) {
        $isDelete = $db->query("DELETE FROM `product_sold` WHERE `id` = " . (int) $soldRow["id"]);
        if(!$isDelete || $db->affectedRows() !== 1) {
            return false;
        }
        return (bool) $db->insert("product_stock", $stock);
    });
}
/**
 * Return every account a failed checkout delivered back to local stock.
 *
 * Used when the order row could not be written: the buyer is refunded, so the accounts must not stay
 * reserved in `product_sold` where nobody can see or resell them.
 *
 * @param DB $db
 * @param string $trans_id
 * @param int $supplier_id 0 for locally stocked accounts
 * @return int number of accounts returned
 */
function orbit_release_sold($db, $trans_id, $supplier_id = 0)
{
    $moved = 0;
    $sql = " SELECT * FROM `product_sold` WHERE `trans_id` = '" . addslashes((string) $trans_id) . "' AND `supplier_id` = " . (int) $supplier_id . " ";
    foreach ($db->get_list($sql) as $sold) {
        $stock = array(
            "product_code" => $sold["product_code"],
            "seller" => $sold["seller"],
            "uid" => $sold["uid"],
            "account" => $sold["account"],
            "status" => $sold["status"],
            "create_gettime" => gettime(),
            "type" => "Back",
            "time_check_live" => $sold["time_check_live"],
        );
        if(orbit_return_stock($db, $sold, $stock)) {
            ++$moved;
        }
    }
    return $moved;
}
/**
 * A finite, strictly positive bank/MoMo amount, or false.
 *
 * These values arrive from a third-party gateway as strings such as "1,500,000". `check_string()`
 * leaves them alone, and `"1,500,000" < 10000` is a *string* comparison in PHP 8 (comma sorts below
 * "1"), so every malformed row would have been treated as "in range" and credited. Parse, then
 * compare as numbers.
 *
 * @param mixed $raw
 * @return float|false
 */
function orbit_gateway_amount($raw)
{
    if (is_string($raw)) {
        $raw = trim($raw);
        /* "1.500.000" / "1,500,000" are thousand groups, not a decimal point. */
        if (preg_match('/^\d{1,3}(?:[.,]\d{3})+$/', $raw)) {
            $raw = str_replace(array(",", "."), "", $raw);
        } else {
            $raw = str_replace(array(",", " "), "", $raw);
        }
    }
    return orbit_positive_amount($raw);
}
/**
 * Render an amount as a plain decimal string for SQL and provider requests.
 *
 * `(string) 1e6` is "1.0E+6", which no amount column and no gateway accepts. This keeps uppercase
 * exponents out and trims the insignificant zeros.
 */
function orbit_amount_string($value)
{
    $value = (float) $value;
    if (!is_finite($value)) {
        return "0";
    }
    $text = rtrim(rtrim(sprintf("%.8F", $value), "0"), ".");
    return $text === "" ? "0" : $text;
}
/**
 * What the buyer should pay for a partial delivery, in whole currency units.
 *
 * A supplier occasionally returns fewer accounts than were ordered. The buyer paid `$pay` for
 * `$ordered`; charging that full amount for `$delivered` would keep money for goods that were never
 * handed over. The delivered share is prorated on the order total, not the unit price, so coupon
 * and member discounts stay proportionally distributed. Returns `$pay` unchanged when the order is
 * complete (or over-delivered), and 0 when nothing was delivered.
 */
function orbit_prorated_pay($pay, $ordered, $delivered)
{
    $pay = (int) round((float) $pay);
    $ordered = (int) $ordered;
    $delivered = (int) $delivered;
    if ($pay <= 0 || $ordered <= 0) {
        return max(0, $pay);
    }
    if ($delivered <= 0) {
        return 0;
    }
    if ($delivered >= $ordered) {
        return $pay;
    }
    $share = (int) round($pay * $delivered / $ordered);
    if ($share < 0) {
        $share = 0;
    }
    return $share > $pay ? $pay : $share;
}
/**
 * The affiliate commission rate for one referrer, in percent.
 *
 * Every purchase and every recharge used to run this twice:
 *
 *     $ck = $CMSNT->site("affiliate_ck");
 *     if (getRowRealtime("users", $getUser["ref_id"], "ref_ck") != 0) { … read it again … }
 *
 * Two SELECTs per credited transaction, and a cron run that processes 200 bank transfers paid for 400.
 * A member-specific `ref_ck` wins only when it is non-zero, exactly as before; the answer is memoised
 * for the request so repeat transactions from the same referrer cost nothing.
 */
function orbit_affiliate_rate($refId)
{
    global $CMSNT;
    static $rates = array();
    $refId = (int) $refId;
    $fallback = (float) $CMSNT->site("affiliate_ck");
    if ($refId < 1) {
        return $fallback;
    }
    if (!array_key_exists($refId, $rates)) {
        $row = $CMSNT->get_row("SELECT `ref_ck` FROM `users` WHERE `id` = " . $refId);
        $rates[$refId] = ($row && (float) $row["ref_ck"] != 0.0) ? (float) $row["ref_ck"] : $fallback;
    }
    return $rates[$refId];
}
/**
 * Normalise the `listUser[]` field of "create email campaign".
 *
 * The select posts user ids, but a request is not a select: an array can hold strings, nested arrays,
 * or a quote. Return only positive integer ids (deduplicated) and a flag for anything that is neither
 * an id nor the blank placeholder. A forged value makes the caller refuse the campaign instead of
 * silently mailing every customer or writing a 0 into `email_sending`.
 *
 * @return array{ids:int[], invalid:bool}
 */
function orbit_campaign_selection($postedIds)
{
    $ids = array();
    $invalid = false;
    if (!is_array($postedIds)) {
        return array("ids" => array(), "invalid" => $postedIds !== null && $postedIds !== "");
    }
    foreach ($postedIds as $id) {
        if (is_int($id)) {
            if (0 < $id) {
                $ids[$id] = true;
            }
        } elseif (is_string($id)) {
            $id = trim($id);
            if ($id === "") {
                continue; /* the "everyone" placeholder */
            }
            if (preg_match('/^[0-9]{1,9}$/D', $id) && 0 < (int) $id) {
                $ids[(int) $id] = true;
            } else {
                $invalid = true;
            }
        } else {
            $invalid = true;
        }
    }
    return array("ids" => array_keys($ids), "invalid" => $invalid);
}
/**
 * Keep only ids that exist. One `IN (…)` query per 500 ids, instead of a SELECT per recipient.
 */
function orbit_campaign_recipient_ids($CMSNT, array $ids)
{
    $valid = array();
    $ids = array_values(array_unique(array_map("intval", $ids)));
    $ids = array_values(array_filter($ids, function ($id) {
        return 0 < $id;
    }));
    foreach (array_chunk($ids, 500) as $chunk) {
        foreach ($CMSNT->get_list("SELECT `id` FROM `users` WHERE `id` IN (" . implode(",", $chunk) . ")") as $row) {
            $valid[] = (int) $row["id"];
        }
    }
    return $valid;
}
/**
 * Queue a campaign for an explicit list of user ids.
 *
 * The old path inserted one row per recipient and read "the newest campaign id" inside the loop. That
 * is N+2 round trips, and if another admin created a campaign at the same moment the recipients were
 * attached to *that* campaign. Here the ids are inserted in batches of 200 inside one statement.
 */
function orbit_campaign_queue($CMSNT, $campaignId, array $userIds)
{
    $campaignId = (int) $campaignId;
    if ($campaignId < 1) {
        return 0;
    }
    $now = addslashes(gettime());
    $queued = 0;
    $userIds = array_values(array_unique(array_map("intval", $userIds)));
    foreach (array_chunk($userIds, 200) as $chunk) {
        $rows = array();
        foreach ($chunk as $userId) {
            if (0 < $userId) {
                $rows[] = "(" . $campaignId . "," . $userId . ",0,'" . $now . "','" . $now . "')";
            }
        }
        if (!$rows) {
            continue;
        }
        $sql = "INSERT INTO `email_sending` (`camp_id`,`user_id`,`status`,`create_gettime`,`update_gettime`) VALUES " . implode(",", $rows);
        if ($CMSNT->query($sql)) {
            $queued += count($rows);
        }
    }
    return $queued;
}
/**
 * Queue a campaign for every mailable member in one INSERT … SELECT (no row set is fetched into PHP).
 */
function orbit_campaign_queue_all($CMSNT, $campaignId)
{
    $campaignId = (int) $campaignId;
    if ($campaignId < 1) {
        return 0;
    }
    $now = addslashes(gettime());
    $sql = "INSERT INTO `email_sending` (`camp_id`,`user_id`,`status`,`create_gettime`,`update_gettime`)"
        . " SELECT " . $campaignId . ", `id`, 0, '" . $now . "', '" . $now . "'"
        . " FROM `users` WHERE `banned` = 0 AND `email` IS NOT NULL AND `email` <> ''";
    if (!$CMSNT->query($sql)) {
        return 0;
    }
    return (int) $CMSNT->affectedRows();
}
/**
 * Create an email campaign and its recipient queue.
 *
 * @param array  $campaign      column => value (already sanitised by the caller)
 * @param mixed  $postedUserIds raw `listUser[]` from the form; empty means "everyone"
 * @return int  the new campaign id, or 0 when nothing usable was created
 */
function orbit_campaign_create($CMSNT, array $campaign, $postedUserIds = array())
{
    $selection = orbit_campaign_selection($postedUserIds);
    if ($selection["invalid"]) {
        return 0;
    }
    $campaignId = 0;
    $created = $CMSNT->transaction(function () use ($CMSNT, $campaign, $selection, &$campaignId) {
        if (!$CMSNT->insert("email_campaigns", $campaign)) {
            return false;
        }
        $campaignId = (int) $CMSNT->insertId();
        if ($campaignId < 1) {
            return false;
        }
        $queued = $selection["ids"]
            ? orbit_campaign_queue($CMSNT, $campaignId, orbit_campaign_recipient_ids($CMSNT, $selection["ids"]))
            : orbit_campaign_queue_all($CMSNT, $campaignId);
        return 0 < $queued;
    });
    return $created ? $campaignId : 0;
}
function getRowRealtime($table, $id, $row)
{
    global $CMSNT;
    if($data = $CMSNT->get_row("SELECT `" . $row . "` FROM `" . $table . "` WHERE `id` = '" . $id . "' ")) {
        return $data[$row];
    }
    return false;
}
function get_url()
{
    if(isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] === "on") {
        $url = "https://";
    } else {
        $url = "http://";
    }
    $url .= $_SERVER["HTTP_HOST"];
    $url .= $_SERVER["REQUEST_URI"];
    return $url;
}
function base_url($url = "")
{
    global $domain_block;
    $a = (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] === "on" ? "https" : "http") . "://" . $_SERVER["HTTP_HOST"];
    if($a == "http://localhost") {
        $a = "http://localhost/CMSNT.CO/SHOPCLONE7_ENCRYPTION";
    }
    return $a . "/" . $url;
}
function base_url_admin($url = "")
{
    $a = (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] === "on" ? "https" : "http") . "://" . $_SERVER["HTTP_HOST"];
    if($a == "http://localhost") {
        $a = "http://localhost/CMSNT.CO/SHOPCLONE7_ENCRYPTION";
    }
    return $a . "/?module=admin&action=" . $url;
}
function TypePassword($password)
{
    $CMSNT = orbit_db();
    if($CMSNT->site("type_password") == "md5") {
        return md5($password);
    }
    if($CMSNT->site("type_password") == "bcrypt") {
        return password_hash($password, PASSWORD_BCRYPT);
    }
    if($CMSNT->site("type_password") == "sha1") {
        return sha1($password);
    }
    return $password;
}
function getUser($id, $row)
{
    $CMSNT = orbit_db();
    return $CMSNT->get_row("SELECT * FROM `users` WHERE `id` = '" . $id . "' ")[$row];
}
function validateUsername($username)
{
    if(preg_match("/^[a-zA-Z][a-zA-Z0-9]{2,19}\$/", $username)) {
        return true;
    }
    return false;
}
function validateEmail($data)
{
    $pattern = "/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}\$/";
    if(preg_match($pattern, $data)) {
        return true;
    }
    return false;
}
function validatePhone($data)
{
    if(preg_match("/^\\+?(\\d.*){3,}\$/", $data, $matches)) {
        return true;
    }
    return false;
}
function gettime()
{
    return date("Y/m/d H:i:s", time());
}
function format_currency2($amount)
{
    $CMSNT = orbit_db();
    $currency = $CMSNT->site("currency");
    if($currency == "USD") {
        return "\$" . number_format($amount / $CMSNT->site("usd_rate"), 2, ".", "");
    }
    if($currency == "VND") {
        return format_cash($amount) . "đ";
    }
    if($currency == "THB") {
        return format_cash($amount / 0) . " THB";
    }
}
function format_currency($amount)
{
    /* The two currency look-ups are cached for the request: a product grid used to ask the database
       for the same default-currency row once per price it printed. */
    static $rows = array();
    $CMSNT = orbit_db();
    if(isset($_COOKIE["currency"])) {
        $currency = check_string($_COOKIE["currency"]);
        $cacheKey = "id:" . $currency;
        if(!array_key_exists($cacheKey, $rows)) {
            $rows[$cacheKey] = $CMSNT->get_row("SELECT * FROM `currencies` WHERE `id` = '" . $currency . "' AND `display` = 1 ");
        }
        $rowCurrency = $rows[$cacheKey];
        if($rowCurrency) {
            return format_currency_row($amount, $rowCurrency);
        }
    }
    if(!array_key_exists("default", $rows)) {
        $rows["default"] = $CMSNT->get_row("SELECT * FROM `currencies` WHERE `default_currency` = 1 ");
    }
    $rowCurrency = $rows["default"];
    if($rowCurrency) {
        return format_currency_row($amount, $rowCurrency);
    }
    return format_cash($amount) . "đ";
}
function format_currency_row($amount, $rowCurrency)
{
    $seperator = "";
    if($rowCurrency["seperator"] == "comma") {
        $seperator = ",";
    }
    if($rowCurrency["seperator"] == "space") {
        $seperator = "";
    }
    if($rowCurrency["seperator"] == "dot") {
        $seperator = ".";
    }
    $rate = (float) $rowCurrency["rate"];
    if($rate <= 0) {
        $rate = 1;
    }
    $decimals = isset($rowCurrency["decimal_currency"]) ? (int) $rowCurrency["decimal_currency"] : 0;
    return $rowCurrency["symbol_left"] . number_format($amount / $rate, $decimals, ".", $seperator) . $rowCurrency["symbol_right"];
}
function myip()
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!filter_var($remote, FILTER_VALIDATE_IP)) {
        return '0.0.0.0';
    }
    $trusted = array_filter(array_map('trim', explode(',', $_ENV['TRUSTED_PROXY_IPS'] ?? '')));
    if (!in_array($remote, $trusted, true)) {
        return $remote;
    }
    $chain = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    for ($i = count($chain) - 1; $i >= 0; --$i) {
        $candidate = trim($chain[$i]);
        if (!filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $remote;
        }
        if (!in_array($candidate, $trusted, true)) {
            return $candidate;
        }
    }
    return $remote;
}
function check_string($data)
{
    return trim(htmlspecialchars(addslashes($data)));
}
/**
 * A quantity that may be interpolated into SQL without quotes (LIMIT, arithmetic, stock checks).
 *
 * `check_string()` is not enough for a number: "1 PROCEDURE ANALYSE(...)" is not numeric, passes a
 * loose comparison against min/max and would reach `LIMIT ...` untouched. Only a run of digits is a
 * quantity; everything else is refused before any query is built.
 *
 * @return int|false
 */
function order_quantity($value)
{
    if (!is_int($value) && !is_string($value)) {
        return false;
    }
    $value = trim((string) $value);
    if ($value === "" || !preg_match('/^[0-9]{1,9}$/D', $value)) {
        return false;
    }
    return (int) $value;
}
function format_cash($number, $suffix = "")
{
    return number_format($number, 0, ",", ".") . (string) $suffix;
}
function create_slug($str)
{
    $unicode = ["a" => "á|à|ả|ã|ạ|ă|ắ|ằ|ẳ|ẵ|ặ|â|ấ|ầ|ẩ|ẫ|ậ", "A" => "Á|À|Ả|Ã|Ạ|Ă|Ắ|Ằ|Ẳ|Ẵ|Ặ|Â|Ấ|Ầ|Ẩ|Ẫ|Ậ", "d" => "đ", "D" => "Đ", "e" => "é|è|ẻ|ẽ|ẹ|ê|ế|ề|ể|ễ|ệ", "E" => "É|È|Ẻ|Ẽ|Ẹ|Ê|Ế|Ề|Ể|Ễ|Ệ", "i" => "í|ì|ỉ|ĩ|ị", "I" => "Í|Ì|Ỉ|Ĩ|Ị", "o" => "ó|ò|ỏ|õ|ọ|ô|ố|ồ|ổ|ỗ|ộ|ơ|ớ|ờ|ở|ỡ|ợ", "O" => "Ó|Ò|Ỏ|Õ|Ọ|Ô|Ố|Ồ|Ổ|Ỗ|Ộ|Ơ|Ớ|Ờ|Ở|Ỡ|Ợ", "u" => "ú|ù|ủ|ũ|ụ|ư|ứ|ừ|ử|ữ|ự", "U" => "Ú|Ù|Ủ|Ũ|Ụ|Ư|Ứ|Ừ|Ử|Ữ|Ự", "y" => "ý|ỳ|ỷ|ỹ|ỵ", "Y" => "Ý|Ỳ|Ỷ|Ỹ|Ỵ"];
    foreach ($unicode as $nonUnicode => $uni) {
        $str = preg_replace("/(" . $uni . ")/i", $nonUnicode, $str);
    }
    $str = preg_replace("/[^\\w\\s-]/", "", $str);
    $str = preg_replace("/\\s+/", "-", $str);
    return strtolower($str);
}
function checkAddon($id_addon)
{
    $CMSNT = orbit_db();
    $domain = str_replace("www.", "", $_SERVER["HTTP_HOST"]);
    if($CMSNT->get_row("SELECT * FROM `addons` WHERE `id` = '" . $id_addon . "' ")["purchase_key"] == md5($domain . "|" . $id_addon)) {
        return true;
    }
    return false;
}
function curl_get2($url)
{
    $arrContextOptions = ["ssl" => ["verify_peer" => true, "verify_peer_name" => true], "http" => ["timeout" => 10]];
    return file_get_contents($url, false, stream_context_create($arrContextOptions));
}
function curl_get($url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
}
function curl_dataPost($url, $dataPost)
{
    $curl = curl_init();
    curl_setopt_array($curl, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_ENCODING => "", CURLOPT_MAXREDIRS => 10, CURLOPT_TIMEOUT => 0, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, CURLOPT_CUSTOMREQUEST => "POST", CURLOPT_POSTFIELDS => $dataPost]);
    $response = curl_exec($curl);
    curl_close($curl);
    return $response;
}
function curl_post($url, $method, $postinfo, $cookie_file_path)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, false);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_COOKIE, $cookie_file_path);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file_path);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file_path);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.0; en-US; rv:1.7.12) Gecko/20050915 Firefox/1.0.7");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_REFERER, $_SERVER["REQUEST_URI"]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if($method == "POST") {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postinfo);
    }
    $html = curl_exec($ch);
    curl_close($ch);
    return $html;
}
function convertTokenToCookie($token)
{
    $html = json_decode(file_get_contents("https://api.facebook.com/method/auth.getSessionforApp?access_token=" . $token . "&format=json&new_app_id=350685531728&generate_session_cookies=1"), true);
    $cookie = $html["session_cookies"][0]["name"] . "=" . $html["session_cookies"][0]["value"] . ";" . $html["session_cookies"][1]["name"] . "=" . $html["session_cookies"][1]["value"] . ";" . $html["session_cookies"][2]["name"] . "=" . $html["session_cookies"][2]["value"] . ";" . $html["session_cookies"][3]["name"] . "=" . $html["session_cookies"][3]["value"];
    return $cookie;
}
function senInboxCSM($cookie, $noiDungTinNhan, $idAnh, $idNguoiNhan)
{
    preg_match("/c_user=([0-9]+);/", $cookie, $idNguoiGui);
    $idNguoiGui = $idNguoiGui[1];
    $html = curl_post("https://m.facebook.com", "GET", "", $cookie);
    $re = "/<input type=\"hidden\" name=\"fb_dtsg\" value=\"(.*?)\" autocomplete=\"off\" \\/>/";
    preg_match($re, $html, $dtsg);
    $dtsg = $dtsg[1];
    $ex = explode("|", $idNguoiNhan);
    foreach ($ex as $idNguoiNhan) {
        $html1 = curl_post("https://m.facebook.com/messages/read/?fbid=" . $idNguoiNhan . "&_rdr", "GET", "", $cookie);
        $re = "/tids=(.*?)\\&/";
        preg_match($re, $html1, $tid);
        if(isset($tid[1])) {
            $tid = urldecode($tid[1]);
            $data = ["fb_dtsg" => (string) $dtsg, "body" => (string) $noiDungTinNhan, "send" => "Gá»­i", "photo_ids[" . $idAnh . "]" => (string) $idAnh, "tids" => (string) $tid, "referrer" => "", "ctype" => "", "cver" => "legacy"];
        } else {
            $data = ["fb_dtsg" => (string) $dtsg, "body" => (string) $noiDungTinNhan, "Send" => "Gá»­i", "ids[0]" => (string) $idNguoiNhan, "photo" => "", "waterfall_source" => "message"];
        }
        $html = curl_post("https://m.facebook.com/messages/send/?icm=1&refid=12", "POST", http_build_query($data), $cookie);
        $re = preg_match("/send_success/", $html, $rep);
        if(isset($rep[0])) {
            return true;
        }
        return false;
    }
}
function random($string, $int)
{
    $length = (int) $int;
    if (!is_string($string) || $string === '' || $length < 0 || $length > 4096) {
        throw new InvalidArgumentException('Invalid random string parameters.');
    }
    $result = '';
    $last = strlen($string) - 1;
    for ($i = 0; $i < $length; ++$i) {
        $result .= $string[random_int(0, $last)];
    }
    return $result;
}
/**
 * Whether this request arrived over TLS, including when TLS terminates at a proxy.
 *
 * `$_SERVER["HTTPS"]` alone is empty behind Render/Cloudflare, so the auth cookie was issued without
 * Secure on exactly the hosts that only speak HTTPS. APP_URL is a fallback for a misconfigured proxy.
 */
function orbit_is_https()
{
    if(!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") {
        return true;
    }
    if(isset($_SERVER["HTTP_X_FORWARDED_PROTO"]) && strtolower((string) $_SERVER["HTTP_X_FORWARDED_PROTO"]) === "https") {
        return true;
    }
    if(isset($_SERVER["SERVER_PORT"]) && (int) $_SERVER["SERVER_PORT"] === 443) {
        return true;
    }
    return stripos((string) ($_ENV["APP_URL"] ?? ""), "https://") === 0;
}
function set_auth_cookie($token, $expires)
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $secure = orbit_is_https();
    return setcookie('token', $token, ['expires' => (int) $expires, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
}
/**
 * Drop the signed-in session completely.
 *
 * Logging out only destroyed the session server-side; the auth cookie was removed with the legacy
 * three-argument setcookie() (no SameSite / Secure) and $_SESSION["login"] was left in memory until
 * PHP's shutdown. Delete the cookie with the attributes it was created with, clear the array, expire
 * the session cookie and destroy the session.
 */
function orbit_logout()
{
    if(isset($_SESSION["login"])) {
        unset($_SESSION["login"]);
    }
    $_SESSION = array();
    if(ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), "", array(
            "expires" => time() - 42000,
            "path" => $params["path"] ? $params["path"] : "/",
            "domain" => $params["domain"],
            "secure" => !empty($params["secure"]),
            "httponly" => !empty($params["httponly"]),
            "samesite" => "Lax",
        ));
    }
    setcookie("token", "", array("expires" => time() - 42000, "path" => "/", "secure" => orbit_is_https(), "httponly" => true, "samesite" => "Lax"));
    session_destroy();
}
function redirect($url)
{
    header("Location: " . $url);
    exit;
}
function active_sidebar($action)
{
    foreach ($action as $row) {
        if(isset($_GET["action"]) && $_GET["action"] == $row) {
            return "active";
        }
    }
    return "";
}
function menuopen_sidebar($action)
{
    foreach ($action as $row) {
        if(isset($_GET["action"]) && $_GET["action"] == $row) {
            return "menu-open";
        }
    }
    return "";
}
function input_post($key)
{
    return isset($_POST[$key]) ? trim($_POST[$key]) : false;
}
function input_get($key)
{
    return isset($_GET[$key]) ? trim($_GET[$key]) : false;
}
function is_submit($key)
{
    return isset($_POST["request_name"]) && $_POST["request_name"] == $key;
}
function display_mark($data)
{
    if(1 <= $data) {
        $show = "<span class=\"badge bg-success\">Có</span>";
    } elseif($data == 0) {
        $show = "<span class=\"badge bg-danger\">Không</span>";
    }
    return $show;
}
function display_banned($banned)
{
    if($banned != 1) {
        return "<span class=\"badge bg-success\">Active</span>";
    }
    return "<span class=\"badge bg-danger\">Banned</span>";
}
function display_online($time)
{
    if(time() - $time <= 300) {
        return "<span class=\"badge bg-success\">Online</span>";
    }
    return "<span class=\"badge bg-danger\">Offline</span>";
}
function display_flag($data)
{
    return "<img src=\"https://flagcdn.com/40x30/" . $data . ".png\" >";
}
function display_live($data)
{
    if($data == "LIVE") {
        $show = "<span class=\"badge bg-success\">LIVE</span>";
    } elseif($data == "DIE") {
        $show = "<span class=\"badge bg-danger\">DIE</span>";
    }
    return $show;
}
function display_checklive($data)
{
    if($data == 1) {
        $show = "<span class=\"badge bg-success\">Có</span>";
    } elseif($data == 0) {
        $show = "<span class=\"badge bg-danger\">Không</span>";
    }
    return $show;
}
function card24h($telco, $amount, $serial, $pin, $trans_id)
{
    global $CMSNT;
    $partner_id = $CMSNT->site("card_partner_id");
    $partner_key = $CMSNT->site("card_partner_key");
    $url = base64_decode("aHR0cHM6Ly90aGVzaWV1cmUuY29tL2NoYXJnaW5nd3MvdjI/c2lnbj0=") . md5($partner_key . $pin . $serial) . "&telco=" . $telco . "&code=" . $pin . "&serial=" . $serial . "&amount=" . $amount . "&request_id=" . $trans_id . "&partner_id=" . $partner_id . "&command=charging";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $data = curl_exec($ch);
    curl_close($ch);
    return json_decode($data, true);
}
function display_status_product($data)
{
    if($data == 1) {
        $show = "<span class=\"badge bg-success\">Hiển thị</span>";
    } elseif($data == 0) {
        $show = "<span class=\"badge bg-danger\">Ẩn</span>";
    }
    return $show;
}
function display_role($data)
{
    if($data == 1) {
        $show = "<span class=\"badge badge-danger\">Admin</span>";
    } elseif($data == 0) {
        $show = "<span class=\"badge badge-info\">Member</span>";
    }
    return $show;
}
function msg_success($text, $url, $time)
{
    exit("<script type=\"text/javascript\">swal(\"Thành Công\", \"" . $text . "\",\"success\");\n    setTimeout(function(){ location.href = \"" . $url . "\" }," . $time . ");</script>");
}
function msg_error($text, $url, $time)
{
    exit("<script type=\"text/javascript\">swal(\"Thất Bại\", \"" . $text . "\",\"error\");\n    setTimeout(function(){ location.href = \"" . $url . "\" }," . $time . ");</script>");
}
function msg_warning($text, $url, $time)
{
    exit("<script type=\"text/javascript\">swal(\"Thông Báo\", \"" . $text . "\",\"warning\");\n    setTimeout(function(){ location.href = \"" . $url . "\" }," . $time . ");</script>");
}
function paginationBoostrap($url, $start, $total, $kmess)
{
    $out[] = "<ul class=\"pagination\">";
    $neighbors = 2;
    if($total <= $start) {
        $start = max(0, $total - ($total % $kmess == 0 ? $kmess : $total % $kmess));
    } else {
        $start = max(0, (int) $start - (int) $start % (int) $kmess);
    }
    $base_link = "<li class=\"page-item\"><a class=\"page-link\" href=\"" . strtr($url, ["%" => "%%"]) . "page=%d" . "\">%s</a></li>";
    $out[] = $start == 0 ? "" : sprintf($base_link, $start / $kmess, "<i class=\"far fa-hand-point-left\"></i>");
    if($kmess * $neighbors < $start) {
        $out[] = sprintf($base_link, 1, "1");
    }
    if($kmess * ($neighbors + 1) < $start) {
        $out[] = "<li class=\"page-item\"><a class=\"page-link\">...</a></li>";
    }
    for ($nCont = $neighbors; 1 <= $nCont; $nCont--) {
        if($kmess * $nCont <= $start) {
            $tmpStart = $start - $kmess * $nCont;
            $out[] = sprintf($base_link, $tmpStart / $kmess + 1, $tmpStart / $kmess + 1);
        }
    }
    $out[] = "<li class=\"page-item active\"><a class=\"page-link\">" . ($start / $kmess + 1) . "</a></li>";
    $tmpMaxPages = (int) (($total - 1) / $kmess) * $kmess;
    for ($nCont = 1; $nCont <= $neighbors; $nCont++) {
        if($start + $kmess * $nCont <= $tmpMaxPages) {
            $tmpStart = $start + $kmess * $nCont;
            $out[] = sprintf($base_link, $tmpStart / $kmess + 1, $tmpStart / $kmess + 1);
        }
    }
    if($start + $kmess * ($neighbors + 1) < $tmpMaxPages) {
        $out[] = "<li class=\"page-item\"><a class=\"page-link\">...</a></li>";
    }
    if($start + $kmess * $neighbors < $tmpMaxPages) {
        $out[] = sprintf($base_link, $tmpMaxPages / $kmess + 1, $tmpMaxPages / $kmess + 1);
    }
    if($start + $kmess < $total) {
        $display_page = $total < $start + $kmess ? $total : $start / $kmess + 2;
        $out[] = sprintf($base_link, $display_page, "<i class=\"far fa-hand-point-right\"></i>\n        ");
    }
    $out[] = "</ul>";
    return implode("", $out);
}
function check_img($img)
{
    return isset($_FILES[$img]) && valid_image_upload($_FILES[$img]);
}
/**
 * The extension that matches the bytes of a validated image upload.
 *
 * Every admin form stored its upload as `.png` no matter what it was: a JPEG saved as `image.png` is
 * then served as `image/png`, and with `nosniff` a browser may refuse to render it. The extension now
 * comes from getimagesize(), the same call valid_image_upload() already made.
 *
 * @param array $file one entry of $_FILES
 * @return string|false png|jpg|gif
 */
function orbit_image_extension(array $file)
{
    if(!valid_image_upload($file)) {
        return false;
    }
    $info = @getimagesize($file["tmp_name"]);
    $map = array(IMAGETYPE_PNG => "png", IMAGETYPE_JPEG => "jpg", IMAGETYPE_GIF => "gif");
    return isset($map[$info[2]]) ? $map[$info[2]] : false;
}
/**
 * Store a validated image upload under `assets/storage/` and return its site-relative path.
 *
 * The path is absolute when moving the file (a relative one depends on the caller's working
 * directory — the sitemap bug), the extension matches the real type, and the file is stored before
 * the caller removes the one it replaces, so a failed upload never leaves a setting pointing at a
 * deleted file.
 *
 * @param array $file one entry of $_FILES
 * @param string $prefix filename prefix such as "logo_light_"
 * @param string $subdir directory under assets/storage, e.g. "images" or "flags"
 * @return string|false "assets/storage/<subdir>/<name>"
 */
function orbit_store_image(array $file, $prefix, $subdir = "images")
{
    /* Arguments first: a bad prefix or directory must be refused before any filesystem work. */
    $prefix = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $prefix);
    $subdir = trim((string) $subdir, "/");
    if($prefix === "" || !preg_match('/^[A-Za-z0-9_\-]+(\/[A-Za-z0-9_\-]+)*$/D', $subdir)) {
        return false;
    }
    $extension = orbit_image_extension($file);
    if($extension === false) {
        return false;
    }
    $root = dirname(__DIR__) . "/assets/storage/" . $subdir;
    if(!is_dir($root) && !@mkdir($root, 0755, true)) {
        error_log("[orbit] could not create the upload directory " . $root);
        return false;
    }
    $name = $prefix . random("0123456789QWERTYUIOPASDGHJKLZXCVBNM", 4) . "." . $extension;
    if(!move_uploaded_file($file["tmp_name"], $root . "/" . $name)) {
        error_log("[orbit] could not store the upload " . $name);
        return false;
    }
    @chmod($root . "/" . $name, 0644);
    return "assets/storage/" . $subdir . "/" . $name;
}
/**
 * Delete a previously stored upload, but only inside `assets/storage/`.
 *
 * The forms called `unlink($row["icon"])` with whatever the database held (or "" for an unset
 * setting, which warns), and a crafted value could point anywhere the PHP user can write. Only
 * paths that resolve inside the upload directory are removed, and a missing file is not an error.
 *
 * @param string $relativePath value as stored in the database
 * @return bool
 */
function orbit_delete_upload($relativePath)
{
    $relativePath = (string) $relativePath;
    if($relativePath === "" || strpos($relativePath, "assets/storage/") !== 0) {
        return false;
    }
    $base = realpath(dirname(__DIR__) . "/assets/storage");
    $target = realpath(dirname(__DIR__) . "/" . $relativePath);
    if($base === false || $target === false || strpos($target, $base . DIRECTORY_SEPARATOR) !== 0) {
        return false;
    }
    return @unlink($target);
}
function valid_image_upload(array $file)
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !isset($file['name'], $file['tmp_name']) || !is_string($file['name']) || !is_string($file['tmp_name'])) {
        return false;
    }
    if (!is_uploaded_file($file['tmp_name']) || filesize($file['tmp_name']) > 5 * 1024 * 1024) {
        return false;
    }
    $types = ['png' => IMAGETYPE_PNG, 'jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG, 'gif' => IMAGETYPE_GIF];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $info = @getimagesize($file['tmp_name']);
    return isset($types[$extension]) && $info !== false && $info[2] === $types[$extension] && $info[0] > 0 && $info[1] > 0 && $info[0] <= 10000 && $info[1] <= 10000;
}
function timeAgo($time_ago)
{
    $time_ago = empty($time_ago) ? 0 : $time_ago;
    if($time_ago == 0) {
        return "--";
    }
    $time_ago = date("Y-m-d H:i:s", $time_ago);
    $time_ago = strtotime($time_ago);
    $cur_time = time();
    $time_elapsed = $cur_time - $time_ago;
    $seconds = $time_elapsed;
    $minutes = round($time_elapsed / 60);
    $hours = round($time_elapsed / 3600);
    $days = round($time_elapsed / 86400);
    $weeks = round($time_elapsed / 604800);
    $months = round($time_elapsed / 2600640);
    $years = round($time_elapsed / 31207680);
    if($seconds <= 60) {
        return $seconds . " " . __("giây trước");
    }
    if($minutes <= 60) {
        return $minutes . " " . __("phút trước");
    }
    if($hours <= 24) {
        return $hours . " " . __("tiếng trước");
    }
    if($days <= 7) {
        if($days == 1) {
            return __("Hôm qua");
        }
        return $days . " " . __("ngày trước");
    }
    if($weeks <= 0) {
        return $weeks . " " . __("tuần trước");
    }
    if($months <= 12) {
        return $months . " " . __("tháng trước");
    }
    return $years . " " . __("năm trước");
}
function timeAgo2($time_ago)
{
    $time_ago = date("Y-m-d H:i:s", $time_ago);
    $time_ago = strtotime($time_ago);
    $time_elapsed = $time_ago;
    $seconds = $time_elapsed;
    $minutes = round($time_elapsed / 60);
    $hours = round($time_elapsed / 3600);
    $days = round($time_elapsed / 86400);
    $weeks = round($time_elapsed / 604800);
    $months = round($time_elapsed / 2600640);
    $years = round($time_elapsed / 31207680);
    if($seconds <= 60) {
        return $seconds . " giây";
    }
    if($minutes <= 60) {
        return $minutes . " phút";
    }
    if($hours <= 24) {
        return $hours . " tiếng";
    }
    if($days <= 7) {
        if($days == 1) {
            return $days . " ngày";
        }
        return $days . " ngày";
    }
    if($weeks <= 0) {
        return $weeks . " tuần";
    }
    if($months <= 12) {
        return $months . " tháng";
    }
    return $years . " năm";
}
function CheckLiveClone($uid)
{
    $json = json_decode(curl_get("https://graph2.facebook.com/v3.3/" . $uid . "/picture?redirect=0"), true);
    if($json["data"]) {
        if(empty($json["data"]["height"]) && empty($json["data"]["width"])) {
            return "DIE";
        }
        return "LIVE";
    }
    return "LIVE";
}
function dirToArray($dir)
{
    $result = [];
    $cdir = scandir($dir);
    foreach ($cdir as $key => $value) {
        if(!$value[["." => true, ".." => true]]) {
            if(is_dir($dir . DIRECTORY_SEPARATOR . $value)) {
                $result[$value] = dirToArray($dir . DIRECTORY_SEPARATOR . $value);
            } else {
                $result[] = $value;
            }
        }
    }
    return $result;
}
function realFileSize($path)
{
    if(!file_exists($path)) {
        return false;
    }
    $size = filesize($path);
    if(!($file = fopen($path, "rb"))) {
        return false;
    }
    if(0 <= $size && fseek($file, 0, SEEK_END) === 0) {
        fclose($file);
        return $size;
    }
    $size = PHP_INT_MAX - 1;
    if(fseek($file, PHP_INT_MAX - 1) !== 0) {
        fclose($file);
        return false;
    }
    $length = 1048576;
    while (!feof($file)) {
        $read = fread($file, $length);
        $size = bcadd($size, $length);
    }
    $size = bcsub($size, $length);
    $size = bcadd($size, strlen($read));
    fclose($file);
    return $size;
}
function FileSizeConvert($bytes)
{
    $result = NULL;
    $bytes = (float) $bytes;
    $arBytes = [["UNIT" => "TB", "VALUE" => pow(1024, 4)], ["UNIT" => "GB", "VALUE" => pow(1024, 3)], ["UNIT" => "MB", "VALUE" => pow(1024, 2)], ["UNIT" => "KB", "VALUE" => 1024], ["UNIT" => "B", "VALUE" => 1]];
    foreach ($arBytes as $arItem) {
        if($arItem["VALUE"] <= $bytes) {
            $result = $bytes / $arItem["VALUE"];
            $result = str_replace(".", ",", strval(round($result, 2))) . " " . $arItem["UNIT"];
            return $result;
        }
    }
}
function GetCorrectMTime($filePath)
{
    $time = filemtime($filePath);
    $isDST = date("I", $time) == 1;
    $systemDST = date("I") == 1;
    $adjustment = 0;
    if(!$isDST && $systemDST) {
        $adjustment = 3600;
    } elseif($isDST && !$systemDST) {
        $adjustment = -3600;
    } else {
        $adjustment = 0;
    }
    return $time + $adjustment;
}
function DownloadFile($file)
{
    if(file_exists($file)) {
        header("Content-Description: File Transfer");
        header("Content-Type: application/octet-stream");
        header("Content-Disposition: attachment; filename=" . basename($file));
        header("Content-Transfer-Encoding: binary");
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Pragma: public");
        header("Content-Length: " . filesize($file));
        ob_clean();
        flush();
        readfile($file);
        exit;
    }
}
function getFileType($url)
{
    $filename = explode(".", $url);
    $extension = end($filename);
    switch ($extension) {
        case "pdf":
            $type = $extension;
            break;
        case "docx":
        case "doc":
            $type = "word";
            break;
        case "xls":
        case "xlsx":
            $type = "excel";
            break;
        case "mp3":
        case "ogg":
        case "wav":
            $type = "audio";
            break;
        case "mp4":
        case "mov":
            $type = "video";
            break;
        case "zip":
        case "7z":
        case "rar":
            $type = "archive";
            break;
        case "jpg":
        case "jpeg":
        case "png":
            $type = "image";
            break;
        default:
            $type = "alt";
            return $type;
    }
}
function getLocation($ip)
{
    if($ip = "::1") {
        $data = ["country" => "VN"];
        return $data;
    }
    $url = "http://ipinfo.io/" . $ip;
    $location = json_decode(file_get_contents($url), true);
    return $location;
}
function pagination($url, $start, $total, $kmess)
{
    $out[] = " <div class=\"pagination-style-1\"><ul class=\"pagination mb-0\">";
    $neighbors = 2;
    if($total <= $start) {
        $start = max(0, $total - ($total % $kmess == 0 ? $kmess : $total % $kmess));
    } else {
        $start = max(0, (int) $start - (int) $start % (int) $kmess);
    }
    $base_link = "<li class=\"page-item  \"><a class=\"page-link\" href=\"" . strtr($url, ["%" => "%%"]) . "page=%d" . "\">%s</a></li>";
    $out[] = $start == 0 ? "" : sprintf($base_link, $start / $kmess, "<i class=\"ri-arrow-left-s-line align-middle\"></i>");
    if($kmess * $neighbors < $start) {
        $out[] = sprintf($base_link, 1, "1");
    }
    if($kmess * ($neighbors + 1) < $start) {
        $out[] = "<li class=\"page-item disabled\"><a class=\"page-link\">...</a></li>";
    }
    for ($nCont = $neighbors; 1 <= $nCont; $nCont--) {
        if($kmess * $nCont <= $start) {
            $tmpStart = $start - $kmess * $nCont;
            $out[] = sprintf($base_link, $tmpStart / $kmess + 1, $tmpStart / $kmess + 1);
        }
    }
    $out[] = "<li class=\"page-item active\"><a class=\"page-link\">" . ($start / $kmess + 1) . "</a></li>";
    $tmpMaxPages = (int) (($total - 1) / $kmess) * $kmess;
    for ($nCont = 1; $nCont <= $neighbors; $nCont++) {
        if($start + $kmess * $nCont <= $tmpMaxPages) {
            $tmpStart = $start + $kmess * $nCont;
            $out[] = sprintf($base_link, $tmpStart / $kmess + 1, $tmpStart / $kmess + 1);
        }
    }
    if($start + $kmess * ($neighbors + 1) < $tmpMaxPages) {
        $out[] = "<li class=\"page-item disabled\"><a class=\"page-link\">...</a></li>";
    }
    if($start + $kmess * $neighbors < $tmpMaxPages) {
        $out[] = sprintf($base_link, $tmpMaxPages / $kmess + 1, $tmpMaxPages / $kmess + 1);
    }
    if($start + $kmess < $total) {
        $display_page = $total < $start + $kmess ? $total : $start / $kmess + 2;
        $out[] = sprintf($base_link, $display_page, "<i class=\"ri-arrow-right-s-line align-middle\"></i>");
    }
    $out[] = "</ul></div>";
    return implode("", $out);
}
function pagination_client($url, $start, $total, $kmess)
{
    $out[] = " <div class=\"paging_simple_numbers\"><ul class=\"pagination\">";
    $neighbors = 2;
    if($total <= $start) {
        $start = max(0, $total - ($total % $kmess == 0 ? $kmess : $total % $kmess));
    } else {
        $start = max(0, (int) $start - (int) $start % (int) $kmess);
    }
    $base_link = "<li class=\"paginate_button page-item previous \"><a class=\"page-link\" href=\"" . strtr($url, ["%" => "%%"]) . "page=%d" . "\">%s</a></li>";
    $out[] = $start == 0 ? "" : sprintf($base_link, $start / $kmess, "<i class=\"fas fa-long-arrow-alt-left\"></i>");
    if($kmess * $neighbors < $start) {
        $out[] = sprintf($base_link, 1, "1");
    }
    if($kmess * ($neighbors + 1) < $start) {
        $out[] = "<li class=\"paginate_button page-item previous disabled\"><a class=\"page-link\">...</a></li>";
    }
    for ($nCont = $neighbors; 1 <= $nCont; $nCont--) {
        if($kmess * $nCont <= $start) {
            $tmpStart = $start - $kmess * $nCont;
            $out[] = sprintf($base_link, $tmpStart / $kmess + 1, $tmpStart / $kmess + 1);
        }
    }
    $out[] = "<li class=\"paginate_button page-item previous\"><a class=\"page-link active\">" . ($start / $kmess + 1) . "</a></li>";
    $tmpMaxPages = (int) (($total - 1) / $kmess) * $kmess;
    for ($nCont = 1; $nCont <= $neighbors; $nCont++) {
        if($start + $kmess * $nCont <= $tmpMaxPages) {
            $tmpStart = $start + $kmess * $nCont;
            $out[] = sprintf($base_link, $tmpStart / $kmess + 1, $tmpStart / $kmess + 1);
        }
    }
    if($start + $kmess * ($neighbors + 1) < $tmpMaxPages) {
        $out[] = "<li class=\"paginate_button page-item previous disabled\"><a class=\"page-link\">...</a></li>";
    }
    if($start + $kmess * $neighbors < $tmpMaxPages) {
        $out[] = sprintf($base_link, $tmpMaxPages / $kmess + 1, $tmpMaxPages / $kmess + 1);
    }
    if($start + $kmess < $total) {
        $display_page = $total < $start + $kmess ? $total : $start / $kmess + 2;
        $out[] = sprintf($base_link, $display_page, "<i class=\"fas fa-long-arrow-alt-right\"></i>");
    }
    $out[] = "</ul></div>";
    return implode("", $out);
}
function roundMoney($amount)
{
    return round($amount, -2);
}
function check_path($path)
{
    return preg_replace("/[^A-Za-z0-9_-]/", "", check_string(basename($path)));
}
function CMSNT_check_license($licensekey, $localkey = "")
{
    global $config;
    $whmcsurl = "https://client.cmsnt.co/";
    $licensing_secret_key = $config["project"];
    $localkeydays = 15;
    $allowcheckfaildays = 5;
    $check_token = time() . md5(mt_rand(100000000, mt_getrandmax()) . $licensekey);
    $checkdate = date("Ymd");
    $domain = $_SERVER["SERVER_NAME"];
    $usersip = isset($_SERVER["SERVER_ADDR"]) ? $_SERVER["SERVER_ADDR"] : $_SERVER["LOCAL_ADDR"];
    $dirpath = dirname(__FILE__);
    $verifyfilepath = "modules/servers/licensing/verify.php";
    $localkeyvalid = false;
    if($localkey) {
        $localkey = str_replace("\n", "", $localkey);
        $localdata = substr($localkey, 0, strlen($localkey) - 32);
        $md5hash = substr($localkey, strlen($localkey) - 32);
        if($md5hash == md5($localdata . $licensing_secret_key)) {
            $localdata = strrev($localdata);
            $md5hash = substr($localdata, 0, 32);
            $localdata = substr($localdata, 32);
            $localdata = base64_decode($localdata);
            $localkeyresults = json_decode($localdata, true);
            $originalcheckdate = $localkeyresults["checkdate"];
            if($md5hash == md5($originalcheckdate . $licensing_secret_key)) {
                $localexpiry = date("Ymd", mktime(0, 0, 0, date("m"), date("d") - $localkeydays, date("Y")));
                if($localexpiry < $originalcheckdate) {
                    $localkeyvalid = true;
                    $results = $localkeyresults;
                    $validdomains = explode(",", $results["validdomain"]);
                    if(!in_array($_SERVER["SERVER_NAME"], $validdomains)) {
                        $localkeyvalid = false;
                        $localkeyresults["status"] = "Invalid";
                        $results = [];
                    }
                    $validips = explode(",", $results["validip"]);
                    if(!in_array($usersip, $validips)) {
                        $localkeyvalid = false;
                        $localkeyresults["status"] = "Invalid";
                        $results = [];
                    }
                    $validdirs = explode(",", $results["validdirectory"]);
                    if(!in_array($dirpath, $validdirs)) {
                        $localkeyvalid = false;
                        $localkeyresults["status"] = "Invalid";
                        $results = [];
                    }
                }
            }
        }
    }
    if(!$localkeyvalid) {
        $responseCode = 0;
        $postfields = ["licensekey" => $licensekey, "domain" => $domain, "ip" => $usersip, "dir" => $dirpath];
        if($check_token) {
            $postfields["check_token"] = $check_token;
        }
        $query_string = "";
        foreach ($postfields as $k => $v) {
            $query_string .= $k . "=" . urlencode($v) . "&";
        }
        if(function_exists("curl_exec")) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $whmcsurl . $verifyfilepath);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $query_string);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $data = curl_exec($ch);
            $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $responseCodePattern = "/^HTTP\\/\\d+\\.\\d+\\s+(\\d+)/";
            $fp = @fsockopen($whmcsurl, 80, $errno, $errstr, 5);
            if($fp) {
                $newlinefeed = "\r\n";
                $header = "POST " . $whmcsurl . $verifyfilepath . " HTTP/1.0" . $newlinefeed;
                $header .= "Host: " . $whmcsurl . $newlinefeed;
                $header .= "Content-type: application/x-www-form-urlencoded" . $newlinefeed;
                $header .= "Content-length: " . strlen($query_string) . $newlinefeed;
                $header .= "Connection: close" . $newlinefeed . $newlinefeed;
                $header .= $query_string;
                $data = $line = "";
                @stream_set_timeout($fp, 20);
                @fputs($fp, $header);
                $status = @socket_get_status($fp);
                while (!@feof($fp) && $status) {
                    $line = @fgets($fp, 1024);
                    $patternMatches = [];
                    if(!$responseCode && preg_match($responseCodePattern, trim($line), $patternMatches)) {
                        $responseCode = empty($patternMatches[1]) ? 0 : $patternMatches[1];
                    }
                    $data .= $line;
                    $status = @socket_get_status($fp);
                }
                @fclose($fp);
            }
        }
        if($responseCode != 200) {
            $localexpiry = date("Ymd", mktime(0, 0, 0, date("m"), date("d") - ($localkeydays + $allowcheckfaildays), date("Y")));
            if($localexpiry < $originalcheckdate) {
                $results = $localkeyresults;
            } else {
                $results = [];
                $results["status"] = "Invalid";
                $results["description"] = "Remote Check Failed";
                return $results;
            }
        } else {
            preg_match_all("/<(.*?)>([^<]+)<\\/\\1>/i", $data, $matches);
            $results = [];
            foreach ($matches[1] as $k => $v) {
                $results[$v] = $matches[2][$k];
            }
        }
        if(!is_array($results)) {
            exit("Invalid License Server Response");
        }
        if(isset($results["md5hash"]) && $results["md5hash"] != md5($licensing_secret_key . $check_token)) {
            $results["status"] = "Invalid";
            $results["description"] = "MD5 Checksum Verification Failed";
            return $results;
        }
        if($results["status"] == "Active") {
            $results["checkdate"] = $checkdate;
            $data_encoded = json_encode($results);
            $data_encoded = base64_encode($data_encoded);
            $data_encoded = md5($checkdate . $licensing_secret_key) . $data_encoded;
            $data_encoded = strrev($data_encoded);
            $data_encoded = $data_encoded . md5($data_encoded . $licensing_secret_key);
            $data_encoded = wordwrap($data_encoded, 80, "\n", true);
            $results["localkey"] = $data_encoded;
        }
        $results["remotecheck"] = true;
    }
    unset($postfields);
    unset($data);
    unset($matches);
    unset($whmcsurl);
    unset($licensing_secret_key);
    unset($checkdate);
    unset($usersip);
    unset($localkeydays);
    unset($allowcheckfaildays);
    unset($md5hash);
    return $results;
}
function checkLicenseKey($licensekey)
{
    $results["msg"] = "Kích hoạt giấy phép thành công!";
    $results["status"] = "Active";
    return $results;
}

?>
