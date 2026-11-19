<?php

define("IN_SITE", true);
require_once __DIR__ . "/../libs/db.php";
require_once __DIR__ . "/../libs/legacy_payment_guard.php";
reject_legacy_payment_when_payos_enabled();
require_once __DIR__ . "/../libs/lang.php";
require_once __DIR__ . "/../libs/helper.php";
require_once __DIR__ . "/../libs/database/users.php";
$CMSNT = new DB();
require_active_legacy_payment($CMSNT, 'perfectmoney_status', 'perfectmoney_pass');
$Mobile_Detect = new Mobile_Detect();
if($_SERVER["REQUEST_METHOD"] == "POST") {
    $perfectmoney_pass = $CMSNT->site("perfectmoney_pass");
    $string = $_POST["PAYMENT_ID"] . ":" . $_POST["PAYEE_ACCOUNT"] . ":" . $_POST["PAYMENT_AMOUNT"] . ":" . $_POST["PAYMENT_UNITS"] . ":" . $_POST["PAYMENT_BATCH_NUM"] . ":" . $_POST["PAYER_ACCOUNT"] . ":" . strtoupper(md5($perfectmoney_pass)) . ":" . $_POST["TIMESTAMPGMT"];
    $hash = strtoupper(md5($string));
    if(isset($_POST['V2_HASH']) && is_string($_POST['V2_HASH']) && hash_equals($hash, $_POST["V2_HASH"])) {
        $invoice_id = check_string($_POST["PAYMENT_ID"]);
        $amount = check_string($_POST["PAYMENT_AMOUNT"]);
        /* `rate_pm` is not a setting this shop has — it evaluated to 0 and credited nothing.
           `perfectmoney_rate` is the field the admin form saves. */
        $rate = (float) $CMSNT->site("perfectmoney_rate");
        if(!is_numeric($amount) || (float) $amount <= 0 || !is_finite($rate) || $rate <= 0) {
            exit("số tiền hoặc tỷ giá không hợp lệ");
        }
        $amount = (float) $amount;
        $total_money = round($rate * $amount, 2);
        if($row = $CMSNT->get_row("SELECT * FROM `payment_pm` WHERE `payment_id` = '" . $invoice_id . "' AND `status` = 0 ")) {
            /* Claim the invoice before crediting so a retried POST cannot credit twice. */
            $CMSNT->query("UPDATE `payment_pm` SET `amount` = '" . addslashes((string) $amount) . "', `price` = '"
                . addslashes((string) $total_money) . "', `update_date` = '" . addslashes(gettime()) . "', `update_time` = "
                . time() . ", `status` = 1 WHERE `id` = " . (int) $row["id"] . " AND `status` = 0");
            if($CMSNT->affectedRows() !== 1) {
                exit("đã xử lý");
            }
            $getUser = $CMSNT->get_row(" SELECT * FROM `users` WHERE `id` = '" . $row["user_id"] . "' ");
            $User = new users();
            $credit = $User->AddCreditsChecked($row["user_id"], $total_money, __("Nạp tiền tự động qua Perfect Money") . " #" . $invoice_id, "TOPUP_PM_" . $invoice_id);
            if($credit === "failed") {
                /* Release the claim so the gateway's retry can credit it. */
                $CMSNT->update("payment_pm", ["status" => 0], " `id` = " . (int) $row["id"] . " AND `status` = 1 ");
                exit("credit_failed");
            }
            if($credit === "credited") {
                $my_text = $CMSNT->site("noti_recharge");
                $my_text = str_replace("{domain}", $_SERVER["SERVER_NAME"], $my_text);
                $my_text = str_replace("{username}", $getUser ? $getUser["username"] : $row["user_id"], $my_text);
                $my_text = str_replace("{method}", "Perfect Money", $my_text);
                $my_text = str_replace("{amount}", (string) $amount, $my_text);
                $my_text = str_replace("{price}", (string) $total_money, $my_text);
                $my_text = str_replace("{time}", gettime(), $my_text);
                sendMessAdmin($my_text);
                $CMSNT->insert("deposit_log", ["user_id" => $getUser ? $getUser["id"] : $row["user_id"], "method" => "Perfect Money", "amount" => $total_money, "received" => $total_money, "create_time" => time(), "is_virtual" => 0]);
            }
            exit("cộng tiền thành công");
        }
    }
}

?>
