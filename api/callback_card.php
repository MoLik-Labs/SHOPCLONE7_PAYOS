<?php

define("IN_SITE", true);
require_once __DIR__ . "/../libs/db.php";
require_once __DIR__ . "/../libs/legacy_payment_guard.php";
reject_legacy_payment_when_payos_enabled();
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../libs/lang.php";
require_once __DIR__ . "/../libs/helper.php";
require_once __DIR__ . "/../libs/database/users.php";
$User = new users();
$CMSNT = new DB();
require_active_legacy_payment($CMSNT, 'card_status', 'card_partner_key');
$Mobile_Detect = new Mobile_Detect();
if($CMSNT->site("status") != 1 && !isset($_SESSION["admin_login"])) {
    exit("status_website_off");
}
if($CMSNT->site("card_status") != 1) {
    exit("status_card_off");
}
if(isset($_GET["request_id"]) && isset($_GET["callback_sign"])) {
    $status = isset($_GET["status"]) ? (int) $_GET["status"] : 0;
    $message = check_string(isset($_GET["message"]) ? $_GET["message"] : "");
    $request_id = check_string($_GET["request_id"]);
    /* The signature covers partner_key + code + serial only, so `value` is NOT authenticated by it.
       It is still the only place the real face value arrives, so it is accepted as a number and
       capped at the denomination the customer declared when they submitted the card. */
    $reportedValue = isset($_GET["value"]) && is_numeric($_GET["value"]) ? (float) $_GET["value"] : 0.0;
    $amount = isset($_GET["amount"]) ? (int) $_GET["amount"] : 0;
    /* Signature input stays exactly as the network sent it; sanitising can rewrite `&`, quotes or
       backslashes and would turn a valid signature into a mismatch. */
    $rawCode = isset($_GET["code"]) && is_string($_GET["code"]) ? $_GET["code"] : "";
    $rawSerial = isset($_GET["serial"]) && is_string($_GET["serial"]) ? $_GET["serial"] : "";
    $code = check_string($rawCode);
    $serial = check_string($rawSerial);
    $telco = check_string(isset($_GET["telco"]) ? $_GET["telco"] : "");
    $trans_id = check_string(isset($_GET["trans_id"]) ? $_GET["trans_id"] : "");
    $callback_sign = check_string($_GET["callback_sign"]);
    if(!hash_equals(md5($CMSNT->site("card_partner_key") . $rawCode . $rawSerial), (string) $callback_sign)) {
        exit("callback_sign_error");
    }
    if(!($row = $CMSNT->get_row(" SELECT * FROM `cards` WHERE `trans_id` = '" . $request_id . "' AND `status` = 'pending' "))) {
        exit("request_id_error");
    }
    if(!($getUser = $CMSNT->get_row(" SELECT * FROM `users` WHERE `id` = '" . $row["user_id"] . "' AND `banned` = 0 "))) {
        exit("user không hợp lệ");
    }
    if($status == 1) {
        /* Face value comes from the callback but may never exceed what the customer declared. */
        $declared = (float) $row["amount"];
        $value = $declared > 0 ? min($reportedValue, $declared) : $reportedValue;
        if($value <= 0) {
            exit("payment.error");
        }
        $cardCut = (float) $CMSNT->site("card_ck");
        $price = $cardCut > 0 ? $value - $value * $cardCut / 100 : $value;
        $price = round($price, 2);
        /* Claim first: only the request that moves the row out of `pending` may credit. Two retried
           callbacks used to both see `pending`, both credit, and only then both update. */
        $CMSNT->query("UPDATE `cards` SET `status` = 'completed', `price` = '" . addslashes((string) $price)
            . "', `update_date` = '" . addslashes(gettime()) . "' WHERE `id` = " . (int) $row["id"] . " AND `status` = 'pending'");
        if($CMSNT->affectedRows() !== 1) {
            exit("thẻ này đã được cộng tiền rồi");
        }
        $credit = $User->AddCreditsChecked($row["user_id"], $price, "Nạp thẻ cào Seri " . $row["serial"] . " - Pin " . $row["pin"], "TOPUP_CARD_" . $row["pin"]);
        if($credit === "failed") {
            /* Put it back so the network's retry can credit it — a paid card must not end up stuck. */
            $CMSNT->update("cards", ["status" => "pending"], " `id` = " . (int) $row["id"] . " AND `status` = 'completed' ");
            exit("credit_failed");
        }
        if($credit === "credited") {
            $my_text = $CMSNT->site("noti_recharge");
            $my_text = str_replace("{domain}", $_SERVER["SERVER_NAME"], $my_text);
            $my_text = str_replace("{username}", $getUser["username"], $my_text);
            $my_text = str_replace("{method}", $telco, $my_text);
            $my_text = str_replace("{amount}", format_currency($amount), $my_text);
            $my_text = str_replace("{price}", format_currency($price), $my_text);
            $my_text = str_replace("{time}", gettime(), $my_text);
            sendMessAdmin($my_text);
            $CMSNT->insert("deposit_log", ["user_id" => $getUser["id"], "method" => "Thẻ cào", "amount" => $value, "received" => $price, "create_time" => time(), "is_virtual" => 0]);
        }
        exit("payment.success");
    }
    /* 99 means "still processing" in the card network's own status list; a callback carrying it must
       not mark a pending card as failed — only a final non-success does. */
    if(in_array($status, array(2, 3, 4, 100), true)) {
        $CMSNT->query("UPDATE `cards` SET `status` = 'error', `price` = 0, `update_date` = '"
            . addslashes(gettime()) . "', `reason` = 'Thẻ cào không hợp lệ hoặc đã được sử dụng' WHERE `id` = "
            . (int) $row["id"] . " AND `status` = 'pending'");
        exit("payment.error");
    }
    exit("payment.processing");
}

?>
