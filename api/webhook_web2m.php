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
require_active_legacy_payment($CMSNT, 'bank_status', 'token_webhook_web2m');
$Mobile_Detect = new Mobile_Detect();
if($CMSNT->site("status") != 1 && !isset($_SESSION["admin_login"])) {
    exit("status_website_off");
}
if(!$CMSNT->site("token_webhook_web2m")) {
    exit("token_webhook_empty");
}
$accessToken = $CMSNT->site("token_webhook_web2m");
$receivedData = file_get_contents("php://input");
if(isset($_SERVER["HTTP_AUTHORIZATION"]) && strpos($_SERVER["HTTP_AUTHORIZATION"], "Bearer ") === 0) {
    $bearerToken = substr($_SERVER["HTTP_AUTHORIZATION"], 7);
    if(hash_equals($accessToken, $bearerToken)) {
        $result = json_decode($receivedData, true);
        foreach ($result["data"] as $data) {
            $tid = check_string($data["transactionNum"]);
            $description = check_string($data["description"]);
            $amount = check_string($data["amount"]);
            $method = check_string($data["bank"]);
            $user_id = parse_order_id($description, $CMSNT->site("prefix_autobank"));
            if(empty($tid)) {
            } elseif($amount < $CMSNT->site("bank_min") || $CMSNT->site("bank_max") < $amount) {
            } else {
                if($data["type"] == "IN" && ($getUser = $CMSNT->get_row(" SELECT * FROM `users` WHERE `id` = '" . $user_id . "' "))) {
                    $received = checkPromotion($amount);
                    $accountNumber = isset($data["accountNumber"]) ? check_string($data["accountNumber"]) : "";
                    $transKey = "TOPUP_" . $accountNumber . "_" . $tid;
                    /* Credit first, keyed by the bank reference. The old order wrote payment_bank and
                       then credited, so a failed credit left a row that made every retry skip — the
                       customer stayed uncredited. A failed credit now writes nothing. */
                    $user = new users();
                    $credit = $user->AddCreditsChecked($getUser["id"], $received, "Nạp tiền tự động qua " . $method . " (#" . $tid . " - " . $description . " - " . $amount . ")", $transKey);
                    if($credit === "credited") {
                        $CMSNT->insert("payment_bank", ["tid" => $tid, "method" => $method, "user_id" => $getUser["id"], "description" => $description, "amount" => $amount, "received" => $received, "create_gettime" => gettime(), "create_time" => time()]);
                        debit_processing($getUser["id"]);
                        $CMSNT->insert("deposit_log", ["user_id" => $getUser["id"], "method" => $method, "amount" => $amount, "received" => $received, "create_time" => time(), "is_virtual" => 0]);
                        $my_text = $CMSNT->site("noti_recharge");
                        $my_text = str_replace("{domain}", $_SERVER["SERVER_NAME"], $my_text);
                        $my_text = str_replace("{username}", $getUser["username"], $my_text);
                        $my_text = str_replace("{method}", $method, $my_text);
                        $my_text = str_replace("{amount}", format_currency($amount), $my_text);
                        $my_text = str_replace("{price}", format_currency($received), $my_text);
                        $my_text = str_replace("{time}", gettime(), $my_text);
                        sendMessAdmin($my_text);
                    } elseif($credit === "duplicate") {
                        /* Already booked by an earlier delivery that died before writing the invoice row. */
                        $CMSNT->query("INSERT INTO `payment_bank` (`tid`,`method`,`user_id`,`description`,`amount`,`received`,`create_gettime`,`create_time`)
                            SELECT '" . addslashes($tid) . "', '" . addslashes($method) . "', " . (int) $getUser["id"] . ", '"
                            . addslashes($description) . "', '" . addslashes((string) $amount) . "', '" . addslashes((string) $received)
                            . "', '" . addslashes(gettime()) . "', " . time() . "
                            FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `payment_bank` WHERE `tid` = '" . addslashes($tid) . "' AND `description` = '" . addslashes($description) . "')");
                    }
                }
                if($CMSNT->get_row(" SELECT COUNT(id) FROM `log_bank_auto` WHERE `tid` = '" . $tid . "' AND `description` = '" . $description . "'  ")["COUNT(id)"] == 0) {
                    $CMSNT->insert("log_bank_auto", ["tid" => $tid, "method" => $method, "description" => $description, "type" => $data["type"], "amount" => $amount, "create_gettime" => gettime()]);
                }
            }
        }
        $response = ["status" => true, "msg" => "OK"];
        echo json_encode($response);
    } else {
        http_response_code(401);
        echo "Chữ ký không hợp lệ.";
    }
} else {
    http_response_code(401);
    echo "Access Token không được cung cấp hoặc không hợp lệ.";
}
function writeLog($message, $logFile = "webhook.log")
{
    $timestamp = date("Y-m-d H:i:s");
    $logMessage = "[" . $timestamp . "] " . $message . PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

?>
