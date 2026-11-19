<?php

if(!defined("IN_SITE")) {
    exit("The Request Not Found");
}
$CMSNT = new DB();
if(isset($_COOKIE["token"])) {
    $getUser = $CMSNT->get_row(" SELECT * FROM `users` WHERE `token` = '" . check_string($_COOKIE["token"]) . "' AND `ctv` = 1 ");
    if(!$getUser) {
        header("location: " . BASE_URL("client/logout"));
        exit;
    }
    $_SESSION["ctv_login"] = $getUser["token"];
}
if(!isset($_SESSION["ctv_login"])) {
    redirect(base_url("ctv/login"));
} else {
    $getUser = $CMSNT->get_row(" SELECT * FROM `users` WHERE `ctv` = 1 AND `token` = '" . $_SESSION["ctv_login"] . "'  ");
    if(!$getUser) {
        redirect(base_url("ctv/login"));
    }
    if($getUser["banned"] != 0) {
        redirect(base_url("common/banned"));
    }
    if($getUser["money"] < 0) {
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