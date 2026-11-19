<?php
define("IN_SITE", true);
require_once __DIR__ . "/libs/db.php";
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/libs/lang.php";
require_once __DIR__ . "/libs/helper.php";
require_once __DIR__ . "/libs/database/users.php";
$CMSNT = new DB();
/* Cookie flags shared by the tracking cookies: not readable from JavaScript, not sent cross-site. */
$orbitCookieBase = array(
    "expires" => time() + 2592000,
    "path" => "/",
    "secure" => !empty($_SERVER["HTTPS"]),
    "httponly" => true,
    "samesite" => "Lax",
);
if($CMSNT->site("status") != 1 && !isset($_SESSION["admin_login"])) {
    /* Limit queries run before the maintenance screen, so keep it to one indexed lookup. */
    $maintenanceToken = isset($_COOKIE["token"]) && preg_match('/^[A-Za-z0-9]{16,64}$/', $_COOKIE["token"]) ? $_COOKIE["token"] : "";
    /* The regex above admits only [A-Za-z0-9], so the value cannot break out of the quotes. */
    if($maintenanceToken !== "" && ($getUser = $CMSNT->get_row(" SELECT * FROM `users` WHERE `token` = '" . addslashes($maintenanceToken) . "' AND `admin` != 0 "))) {
        $_SESSION["admin_login"] = $getUser["token"];
    } else {
        require_once __DIR__ . "/views/common/maintenance.php";
        exit;
    }
}
if(isset($_GET["utm_source"])) {
    $utm_source = substr(preg_replace("/[^A-Za-z0-9._-]/", "", (string) $_GET["utm_source"]), 0, 64);
    if ($utm_source !== "") {
        setcookie("utm_source", $utm_source, $orbitCookieBase);
    }
}
if(isset($_GET["aff"])) {
    $aff = (int) $_GET["aff"];
    if ($aff > 0) {
        setcookie("aff", (string) $aff, $orbitCookieBase);
        if($user_ref = $CMSNT->get_row("SELECT `id` FROM `users` WHERE `id` = " . $aff . " ")) {
            $CMSNT->cong("users", "ref_click", 1, " `id` = " . (int) $user_ref["id"] . " ");
        }
    }
}
$module = !empty($_GET["module"]) ? check_path($_GET["module"]) : "client";
$home = $module == "client" ? $CMSNT->site("home_page") : "home";
$action = !empty($_GET["action"]) ? check_path($_GET["action"]) : $home;
if($action == "footer" || $action == "header" || $action == "sidebar" || $action == "nav" || $action == "widget-tools") {
    require_once __DIR__ . "/views/common/404.php";
    exit;
}
$path = "views/" . $module . "/" . $action . ".php";
if(file_exists($path)) {
    require_once __DIR__ . "/" . $path;
    exit;
}
require_once __DIR__ . "/views/common/404.php";
exit;

?>
