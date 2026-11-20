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
require_active_legacy_payment($CMSNT, 'flutterwave_status', 'flutterwave_secretKey');
$Mobile_Detect = new Mobile_Detect();
if($_SERVER["REQUEST_METHOD"] === "POST") {
    $data = json_decode(file_get_contents("php://input"), true);
    if(!empty($data) && isset($data["id"])) {
        $idData = check_string($data["id"]);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.flutterwave.com/v3/transactions/" . $idData . "/verify");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $CMSNT->site("flutterwave_secretKey")]);
        $response = curl_exec($ch);
        curl_close($ch);
        /* A failed verify, a timeout, or an HTML error page decodes to null: nothing may be read
           from it and nothing may be credited. */
        $response = json_decode($response, true);
        if(!is_array($response) || !isset($response["data"]) || !is_array($response["data"])) {
            exit("verify_failed");
        }
        $verified = $response["data"];
        $id = check_string(isset($verified["id"]) ? $verified["id"] : "");
        $txRef = check_string(isset($verified["tx_ref"]) ? $verified["tx_ref"] : "");
        $currency = check_string(isset($verified["currency"]) ? $verified["currency"] : "");
        $amount = check_string(isset($verified["amount"]) ? $verified["amount"] : 0);
        $txStatus = isset($verified["status"]) ? $verified["status"] : "";
        $price = $amount * $CMSNT->site("flutterwave_rate");
        if($txStatus == "successful" && $txRef !== "" && ($row = $CMSNT->get_row(" SELECT * FROM `payment_flutterwave` WHERE `tx_ref` = '" . $txRef . "' AND `currency` = '" . $currency . "' AND `status` = 'pending'  "))) {
            $getUser = $CMSNT->get_row(" SELECT * FROM `users` WHERE `id` = '" . $row["user_id"] . "' ");
            if(!$getUser) {
                exit("user_not_found");
            }
            /* Claim the invoice before crediting; the retry then finds `status` = 'success'. */
            $CMSNT->query("UPDATE `payment_flutterwave` SET `status` = 'success', `price` = '" . addslashes((string) $price)
                . "', `update_gettime` = '" . addslashes(gettime()) . "', `amount` = '" . addslashes((string) $amount)
                . "' WHERE `id` = " . (int) $row["id"] . " AND `status` = 'pending'");
            if($CMSNT->affectedRows() !== 1) {
                exit("already_processed");
            }
            $user = new users();
            $credit = $user->AddCreditsChecked($row["user_id"], $price, __("Recharge Flutterwave") . " #" . $id, "TOPUP_Flutterwave_" . $txRef);
            if($credit === "failed") {
                /* Credit failed after the claim: release it so the provider's retry can finish the job. */
                $CMSNT->update("payment_flutterwave", ["status" => "pending"], " `id` = " . (int) $row["id"] . " AND `status` = 'success' ");
                exit("credit_failed");
            }
            if($credit === "credited") {
                $CMSNT->insert("deposit_log", ["user_id" => $getUser["id"], "method" => "Flutterwave", "amount" => $price, "received" => $price, "create_time" => time(), "is_virtual" => 0]);
                $my_text = $CMSNT->site("noti_recharge");
                $my_text = str_replace("{domain}", $_SERVER["SERVER_NAME"], $my_text);
                $my_text = str_replace("{username}", $getUser["username"], $my_text);
                $my_text = str_replace("{method}", "Flutterwave", $my_text);
                $my_text = str_replace("{amount}", $price, $my_text);
                $my_text = str_replace("{price}", $price, $my_text);
                $my_text = str_replace("{time}", gettime(), $my_text);
                sendMessAdmin($my_text);
            }
        }
    }
}

?>