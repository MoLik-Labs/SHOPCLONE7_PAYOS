<?php
/*
 * E-mail OTP challenge — Orbit v3.
 *
 * Reached after a correct password when the account has `status_otp_mail` on; the token lives in
 * `token_otp_mail`. The POST action and field names stay `VerifyOTP` / `token_otp_mail`, so the
 * server-side attempt counter (5 tries, then the IP is blocked) behaves exactly as before.
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
    "SELECT `id`, `banned`, `status_otp_mail` FROM `users`"
    . " WHERE `token_otp_mail` = '" . addslashes($storeToken) . "' AND `token_otp_mail` IS NOT NULL"
);
if (!$storeUser || (int) $storeUser["banned"] !== 0 || (int) $storeUser["status_otp_mail"] !== 1) {
    redirect(BASE_URL("client/login"));
}

$storeCaptcha = (string) $CMSNT->site("reCAPTCHA_status") === "1";
$storeShell = array(
    "title" => __("Xác minh OTP") . " | " . $CMSNT->site("title"),
    "desc" => __("Nhập mã OTP đã gửi tới email để hoàn tất đăng nhập."),
    "robots" => "noindex, nofollow",
    "nav" => store_nav_default($CMSNT),
    "body_class" => "page-auth",
    "head" => $storeCaptcha
        ? '<script src="https://www.google.com/recaptcha/api.js" async defer></script>'
        : "",
);
store_shell_open($storeShell);

$verifyAction = "VerifyOTP";
$verifyField = "token_otp_mail";
$verifyToken = $storeToken;
$verifyTitle = "Xác minh OTP";
$verifyCopy = "Shop vừa gửi một mã tới email của bạn. Mã chỉ dùng một lần — kiểm tra cả mục Spam nếu chưa thấy.";
$verifyLabel = "Mã OTP";
$verifyLength = 8;
$verifyCaptcha = $storeCaptcha;
require __DIR__ . "/partials/verify-card.php";

store_shell_close();
