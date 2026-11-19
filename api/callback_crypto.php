<?php

define("IN_SITE", true);
require_once __DIR__ . "/../libs/db.php";
require_once __DIR__ . "/../libs/legacy_payment_guard.php";
reject_legacy_payment_when_payos_enabled();
require_once __DIR__ . "/../libs/lang.php";
require_once __DIR__ . "/../libs/helper.php";
require_once __DIR__ . "/../libs/database/users.php";
$CMSNT = new DB();
require_active_legacy_payment($CMSNT, 'crypto_status', 'crypto_token');
$Mobile_Detect = new Mobile_Detect();
if(empty($_GET["request_id"])) {
    exit("request_id empty");
}
if(empty($_GET["token"])) {
    exit("token empty");
}
if(empty($_GET["status"])) {
    exit("status empty");
}
$request_id = isset($_GET["request_id"]) ? check_string($_GET["request_id"]) : NULL;
$token = isset($_GET["token"]) ? check_string($_GET["token"]) : NULL;
$received = isset($_GET["received"]) ? check_string($_GET["received"]) : NULL;
$status = isset($_GET["status"]) ? check_string($_GET["status"]) : NULL;
$from_address = isset($_GET["from_address"]) ? check_string($_GET["from_address"]) : NULL;
$transaction_id = isset($_GET["transaction_id"]) ? check_string($_GET["transaction_id"]) : NULL;
if(!hash_equals((string) $CMSNT->site("crypto_token"), (string) $token)) {
    exit("Token xác minh không chính xác");
}
if(!($row = $CMSNT->get_row(" SELECT * FROM `payment_crypto` WHERE `request_id` = '" . $request_id . "' "))) {
    exit("Hoá đơn không tồn tại");
}
$amount = $row["received"];
$received = checkPromotion($amount);
if(!is_numeric($amount) || (float) $amount <= 0 || !is_numeric($received) || (float) $received <= 0) {
    exit("số tiền không hợp lệ");
}
$getUser = $CMSNT->get_row(" SELECT * FROM `users` WHERE `id` = '" . $row["user_id"] . "' ");
if($row["status"] == "completed") {
    exit("Hoá đơn này đã được xử lý rồi");
}
if($status == "expired") {
    $CMSNT->query("UPDATE `payment_crypto` SET `status` = 'expired', `update_gettime` = '" . addslashes(gettime())
        . "' WHERE `id` = " . (int) $row["id"] . " AND `status` <> 'completed'");
    exit("cập nhật trạng thái expired");
}
if($status == "completed") {
    /* The status change is the claim: a retried callback that arrives while the first one is still
       crediting finds the row already out of `pending` and must not add a second credit. */
    $CMSNT->query("UPDATE `payment_crypto` SET `status` = 'completed', `update_gettime` = '" . addslashes(gettime())
        . "' WHERE `id` = " . (int) $row["id"] . " AND `status` <> 'completed'");
    if($CMSNT->affectedRows() === 1) {
        $User = new users();
        $credit = $User->AddCreditsChecked($row["user_id"], $received, "Crypto Recharge #" . $row["trans_id"], "TOPUP_CRYPTO_" . $row["trans_id"]);
        if($credit === "failed") {
            /* Credit failed: let the provider's retry try again instead of leaving a paid invoice open. */
            $CMSNT->update("payment_crypto", ["status" => "pending"], " `id` = " . (int) $row["id"] . " AND `status` = 'completed' ");
            exit("credit_failed");
        }
        if($credit === "credited") {
            $my_text = $CMSNT->site("noti_recharge");
            $my_text = str_replace("{domain}", $_SERVER["SERVER_NAME"], $my_text);
            $my_text = str_replace("{username}", $getUser ? $getUser["username"] : $row["user_id"], $my_text);
            $my_text = str_replace("{method}", "Crypto", $my_text);
            $my_text = str_replace("{amount}", format_currency($amount), $my_text);
            $my_text = str_replace("{price}", format_currency($received), $my_text);
            $my_text = str_replace("{time}", gettime(), $my_text);
            sendMessAdmin($my_text);
            $CMSNT->insert("deposit_log", ["user_id" => $getUser ? $getUser["id"] : $row["user_id"], "method" => "USDT", "amount" => $amount, "received" => $received, "create_time" => time(), "is_virtual" => 0]);
            exit("Cập nhật trạng thái completed thành công!");
        }
        exit("Hóa đơn này đã được cộng tiền rồi");
    }
}

?>
