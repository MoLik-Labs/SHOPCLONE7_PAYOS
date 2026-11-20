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
if (!orbit_cron_allowed()) {
    http_response_code(403);
    exit("cron_forbidden");
}
$user = new users();
curl_get2(base_url("cron/cron.php"));
if($CMSNT->site("check_time_cron_momo") < time() && time() - $CMSNT->site("check_time_cron_momo") < 5) {
    exit("[ÉT O ÉT ] Thao tác quá nhanh, vui lòng đợi");
}
$CMSNT->update("settings", ["value" => time()], " `name` = 'check_time_cron_momo' ");
if($CMSNT->site("momo_status") == 1) {
    if($CMSNT->site("momo_token") == "") {
        exit("Vui lòng cấu hình Token MOMO");
    }
    $result = curl_get2("https://api.web2m.com/historyapimomo1h/" . trim($CMSNT->site("momo_token")));
    $result = json_decode($result, true);
    foreach ($result["momoMsg"]["tranList"] as $data) {
        if($data["status"] != 2) {
        } else {
            $partnerId = $data["partnerId"];
            $description = $data["comment"];
            $tid = $data["tranId"];
            $partnerName = $data["partnerName"];
            $amount = orbit_gateway_amount($data["amount"]);
            $user_id = parse_order_id($description, $CMSNT->site("prefix_autobank"));
            if($amount !== false && ($getUser = $CMSNT->get_row(" SELECT * FROM `users` WHERE `id` = '" . $user_id . "' ")) && $CMSNT->num_rows(" SELECT * FROM `payment_momo` WHERE `tid` = '" . $tid . "' ") == 0) {
                $received = checkPromotion($amount);
                $insertSv2 = $CMSNT->insert("payment_momo", ["tid" => $tid, "method" => "MOMO", "user_id" => $getUser["id"], "description" => $description, "amount" => $amount, "received" => $received, "create_gettime" => gettime(), "create_time" => time()]);
                if($insertSv2) {
                    $isCong = $user->AddCredits($getUser["id"], $received, "Nạp tiền tự động qua ví MOMO (#" . $tid . " - " . $description . " - " . $amount . ")", $tid);
                    if($isCong) {
                        if($CMSNT->site("affiliate_status") == 1 && $getUser["ref_id"] != 0) {
                            $ck = orbit_affiliate_rate($getUser["ref_id"]);
                            $price = $received * $ck / 100;
                            $user->AddCommission($getUser["ref_id"], $getUser["id"], $price, __("Hoa hồng thành viên " . $getUser["username"]));
                        }
                        debit_processing($getUser["id"]);
                        $CMSNT->insert("deposit_log", ["user_id" => $getUser["id"], "method" => "MOMO", "amount" => $amount, "received" => $received, "create_time" => time(), "is_virtual" => 0]);
                        $my_text = $CMSNT->site("noti_recharge");
                        $my_text = str_replace("{domain}", $_SERVER["SERVER_NAME"], $my_text);
                        $my_text = str_replace("{username}", $getUser["username"], $my_text);
                        $my_text = str_replace("{method}", "MOMO", $my_text);
                        $my_text = str_replace("{amount}", format_currency($amount), $my_text);
                        $my_text = str_replace("{price}", format_currency($received), $my_text);
                        $my_text = str_replace("{time}", gettime(), $my_text);
                        sendMessAdmin($my_text);
                        echo "[<b style=\"color:green\">-</b>] Xử lý thành công 1 hoá đơn." . PHP_EOL;
                    }
                }
            }
        }
    }
}
/*
 * This called cron/cron.php over HTTP from the same server. The cron guard refuses an unauthenticated
 * HTTP call, and on CLI the request did not carry CRON_TOKEN either — the call could only ever time
 * out (10 s) or be rejected. Including it keeps the intent (run the shared cron after MoMo) and works
 * in both the CLI and the token-authenticated HTTP path, because it is the same request.
 */
require __DIR__ . "/cron.php";

?>