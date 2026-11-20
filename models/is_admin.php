<?php

if(!defined("IN_SITE")) {
    exit("The Request Not Found");
}
$CMSNT = new DB();
if(isset($_COOKIE["token"])) {
    $getUser = $CMSNT->get_row(" SELECT * FROM `users` WHERE `token` = '" . check_string($_COOKIE["token"]) . "' AND `admin` != 0 ");
    if(!$getUser) {
        header("location: " . BASE_URL("client/login"));
        exit;
    }
    $_SESSION["admin_login"] = $getUser["token"];
}
if(!isset($_SESSION["admin_login"])) {
    redirect(base_url("client/login"));
} else {
    /* Quoted like every other token lookup: the session is server-side, but one unescaped literal in
       a SQL string is exactly how an injection survives a later refactor. */
    $getUser = $CMSNT->get_row(" SELECT * FROM `users` WHERE `token` = '" . check_string($_SESSION["admin_login"]) . "'  ");
    if(!$getUser) {
        redirect(base_url("client/login"));
    }
    if($getUser["banned"] != 0) {
        redirect(base_url("common/banned"));
    }
    if($getUser["admin"] <= 0) {
        $ip_address = myip();
        $max_attempts = $CMSNT->site("limit_block_ip_admin_access");
        $lockout_time = 900;
        $attempt = $CMSNT->get_row("SELECT * FROM `failed_attempts` WHERE `ip_address` = '" . $ip_address . "' AND `type` = 'Access Admin' ");
        if($attempt && 0 < (int) $max_attempts && (int) $max_attempts <= (int) $attempt["attempts"] && time() - strtotime($attempt["create_gettime"]) < $lockout_time) {
            $CMSNT->insert("block_ip", ["ip" => $ip_address, "attempts" => $attempt["attempts"], "create_gettime" => gettime(), "banned" => 1, "reason" => __("Truy cập trái phép Admin Panel quá nhiều lần")]);
            $CMSNT->remove("failed_attempts", " `ip_address` = '" . $ip_address . "' ");
            exit(json_encode(["status" => "error", "msg" => __("IP của bạn đã bị khóa. Vui lòng thử lại sau.")]));
        }
        if($attempt) {
            $CMSNT->cong("failed_attempts", "attempts", 1, " `ip_address` = '" . $ip_address . "' ");
        } else {
            $CMSNT->insert("failed_attempts", ["ip_address" => $ip_address, "attempts" => 1, "type" => "Access Admin", "create_gettime" => gettime()]);
        }
        redirect(base_url("client/login"));
    }
    if($getUser["money"] < -500) {
        $User = new users();
        $User->Banned($getUser["id"], "Tài khoản âm tiền, ghi vấn bug");
        redirect(base_url("common/banned"));
    }
    /* Presence only needs minute precision (display_online() uses a 5-minute window). Writing on
       every request made each page view an UPDATE; throttling to once a minute keeps the indicator
       correct and removes that write from almost every request. */
    if (time() - (int) (isset($getUser["time_session"]) ? $getUser["time_session"] : 0) > 60) {
        $CMSNT->update("users", ["time_session" => time()], " `id` = " . (int) $getUser["id"] . " ");
    }
}

?>