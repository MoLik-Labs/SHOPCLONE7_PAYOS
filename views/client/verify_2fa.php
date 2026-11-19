<?php
/*
 * Two-factor challenge — Orbit v3.
 *
 * Reached after a correct password when the account has TOTP enabled; `auth.php` redirects here with
 * `?token=…`, the value it stored in `token_2fa`. The form posts `Verify2FA` with the same field names
 * as the legacy page, so the attempt counter, the 5-try IP block and the cookie hand-off are unchanged.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . "/../common/store-shell.php";

$storeToken = isset($_GET["token"]) && is_string($_GET["token"]) ? trim($_GET["token"]) : "";
if ($storeToken === "" || !preg_match('/^[A-Za-z0-9]{16,128}$/', $storeToken)) {
    redirect(BASE_URL("client/login"));
}
$storeUser = $CMSNT->get_row(
    "SELECT `id`, `banned`, `status_2fa` FROM `users`"
    . " WHERE `token_2fa` = '" . addslashes($storeToken) . "' AND `token_2fa` IS NOT NULL"
);
/* An expired token, a closed 2FA switch or a banned account all mean: start over. No detail is
   printed — that would tell an attacker which half of the guess was right. */
if (!$storeUser || (int) $storeUser["banned"] !== 0 || (int) $storeUser["status_2fa"] !== 1) {
    redirect(BASE_URL("client/login"));
}

$storeCaptcha = (string) $CMSNT->site("reCAPTCHA_status") === "1";
$storeShell = array(
    "title" => __("Xác minh 2FA") . " | " . $CMSNT->site("title"),
    "desc" => __("Nhập mã từ ứng dụng xác thực để hoàn tất đăng nhập."),
    "robots" => "noindex, nofollow",
    "nav" => store_nav_default($CMSNT),
    "body_class" => "page-auth",
    "head" => $storeCaptcha
        ? '<script src="https://www.google.com/recaptcha/api.js" async defer></script>'
        : "",
);
store_shell_open($storeShell);

$verifyAction = "Verify2FA";
$verifyField = "token_2fa";
$verifyToken = $storeToken;
$verifyTitle = "Xác minh 2FA";
$verifyCopy = "Mở ứng dụng xác thực (Authenticator) và nhập mã 6 số đang hiển thị cho tài khoản của bạn.";
$verifyLabel = "Mã xác minh";
$verifyLength = 6;
$verifyCaptcha = $storeCaptcha;
require __DIR__ . "/partials/verify-card.php";

store_shell_close();
