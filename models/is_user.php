<?php

if(!defined("IN_SITE")) {
    exit("The Request Not Found");
}
$CMSNT = new DB();
if(isset($_COOKIE["token"])) {
    $getUser = $CMSNT->get_row(" SELECT * FROM `users` WHERE `token` = '" . check_string($_COOKIE["token"]) . "' ");
    if(!$getUser) {
        header("location: " . BASE_URL("client/logout"));
        exit;
    }
    $_SESSION["login"] = $getUser["token"];
    $orbitUserLoaded = true;
}
if(!isset($_SESSION["login"])) {
    redirect(base_url("client/login"));
} else {
    /* The cookie line above already fetched this exact row: do not pay for the same SELECT twice. */
    if (empty($orbitUserLoaded)) {
        $getUser = $CMSNT->get_row(" SELECT * FROM `users` WHERE `token` = '" . check_string($_SESSION["login"]) . "'  ");
        if(!$getUser) {
            redirect(base_url("client/login"));
        }
    }
    /* One copy for everything that runs after this file: the shell's balance chip reads it, and a view
       that is re-rendered in the same process (tests, previews) still sees the signed-in user. */
    $GLOBALS["getUser"] = $getUser;
    if($getUser["banned"] != 0) {
        redirect(base_url("common/banned"));
    }
    if($getUser["money"] < -500) {
        $User = new users();
        $User->Banned($getUser["id"], "Tài khoản âm tiền, ghi vấn bug");
        redirect(base_url("common/banned"));
    }
    if(!empty($getUser["token_forgot_password"])) {
        $CMSNT->update("users", ["token_forgot_password" => NULL], " `id` = '" . $getUser["id"] . "' ");
    }
    /* Presence only needs minute precision (display_online() uses a 5-minute window). Writing on
       every request made each page view an UPDATE; throttling to once a minute keeps the indicator
       correct and removes that write from almost every request. */
    if (time() - (int) (isset($getUser["time_session"]) ? $getUser["time_session"] : 0) > 60) {
        $CMSNT->update("users", ["time_session" => time()], " `id` = " . (int) $getUser["id"] . " ");
    }
}

?>