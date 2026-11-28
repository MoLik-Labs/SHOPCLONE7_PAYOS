<?php

define("IN_SITE", true);
require_once __DIR__ . "/../libs/db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../libs/lang.php";
require_once __DIR__ . "/../libs/helper.php";
require_once __DIR__ . "/../libs/api_credentials.php";
orbit_api_no_store();
$CMSNT = new DB();
$apiKey = orbit_api_credential(["api_key"], false);
if($apiKey === "") {
    exit(json_encode(["status" => "error", "msg" => __("Thiếu api_key")]));
}
if(!($getUser = $CMSNT->get_row("SELECT * FROM `users` WHERE `api_key` = '" . check_string($apiKey) . "' AND `banned` = 0 "))) {
    exit(json_encode(["status" => "error", "msg" => __("api_key không hợp lệ")]));
}
$user = [];
$data = [];
$user = ["username" => $getUser["username"], "money" => $getUser["money"]];
$data = ["status" => "success", "msg" => "Lấy dữ liệu thành công!", "data" => $user];
echo json_encode($data, JSON_PRETTY_PRINT);

?>