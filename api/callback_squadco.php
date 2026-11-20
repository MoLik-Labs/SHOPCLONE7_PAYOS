<?php

define("IN_SITE", true);
require_once __DIR__ . "/../libs/db.php";
require_once __DIR__ . "/../libs/legacy_payment_guard.php";
reject_legacy_payment_when_payos_enabled();
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../libs/lang.php";
require_once __DIR__ . "/../libs/helper.php";
require_once __DIR__ . "/../libs/database/users.php";
$CMSNT = new DB();
require_active_legacy_payment($CMSNT, 'squadco_status', 'squadco_Secret_Key');
$Mobile_Detect = new Mobile_Detect();
$headers = getallheaders();
if(strtoupper($_SERVER["REQUEST_METHOD"]) != "POST" || !isset($headers["x-squad-encrypted-body"])) {
    exit("Yêu cầu không hợp lệ");
}
$input = @file_get_contents("php://input");
if(!hash_equals(strtoupper(hash_hmac("sha512", $input, $CMSNT->site("squadco_Secret_Key"))), (string) $headers["x-squad-encrypted-body"])) {
    exit("Xác minh webhook thất bại");
}
$body = json_decode($input, true);
/* The HMAC covers the raw body, so a well-formed signature from Squad still has to carry the shape
   this handler reads; anything else is refused instead of reaching an undefined index. */
if(!is_array($body) || !isset($body["Body"]) || !is_array($body["Body"])) {
    exit("Dữ liệu không hợp lệ");
}
$body = $body["Body"];
/* Squad sends the minor unit (kobo). Parse it as an amount: 0, "", "abc" and negatives are not a
   payment, and the invoice lookup below must never run with a value 0 would match. */
$rawAmount = orbit_gateway_amount(isset($body["amount"]) ? $body["amount"] : null);
if($rawAmount === false || $rawAmount <= 0) {
    exit("số tiền không hợp lệ");
}
$amount = floor($rawAmount / 100);
if($amount <= 0) {
    exit("số tiền không hợp lệ");
}
if(isset($body["currency"]) && $body["currency"] != $CMSNT->site("squadco_currency_code")) {
    exit("Currency không hợp lệ");
}
$transactionRef = isset($body["transaction_ref"]) ? check_string($body["transaction_ref"]) : "";
if($transactionRef === "") {
    exit("transaction_ref empty");
}
if($CMSNT->get_row(" SELECT * FROM `payment_squadco` WHERE `transaction_ref` = '" . $transactionRef . "' AND `amount` = '" . $amount . "' ")) {
    exit("Đơn này xử lý rồi");
}
$email = isset($body["email"]) ? check_string($body["email"]) : "";
if($email === "") {
    exit("Email user không tồn tại trong hệ thống");
}
if(!($getUser = $CMSNT->get_row(" SELECT * FROM `users` WHERE `email` = '" . $email . "' "))) {
    exit("Email user không tồn tại trong hệ thống");
}
$rate = (float) $CMSNT->site("squadco_rate");
$price = round($amount * $rate, 2);
if($amount <= 0 || $price <= 0) {
    exit("số tiền không hợp lệ");
}
/* Credit first: the ledger key `TOPUP_Squadco_<ref>` is the real dedupe, and a credit that fails
   leaves no row behind, so the provider's retry is free to run instead of being rejected forever. */
$user = new users();
$credit = $user->AddCreditsChecked($getUser["id"], $price, __("Recharge Squadco") . " #" . $transactionRef, "TOPUP_Squadco_" . $transactionRef);
if($credit === "failed") {
    exit("credit_failed");
}
$isInsert = $CMSNT->insert("payment_squadco", ["user_id" => $getUser["id"], "transaction_ref" => $transactionRef, "amount" => $amount, "price" => $price, "create_gettime" => gettime()]);
if($isInsert && $credit === "credited") {
    $CMSNT->insert("deposit_log", ["user_id" => $getUser["id"], "method" => "Squadco", "amount" => $price, "received" => $price, "create_time" => time(), "is_virtual" => 0]);
    $my_text = $CMSNT->site("noti_recharge");
    $my_text = str_replace("{domain}", $_SERVER["SERVER_NAME"], $my_text);
    $my_text = str_replace("{username}", getRowRealtime("users", $getUser["id"], "username"), $my_text);
    $my_text = str_replace("{method}", "Squadco", $my_text);
    $my_text = str_replace("{amount}", $price, $my_text);
    $my_text = str_replace("{price}", $price, $my_text);
    $my_text = str_replace("{time}", gettime(), $my_text);
    sendMessAdmin($my_text);
}

?>
