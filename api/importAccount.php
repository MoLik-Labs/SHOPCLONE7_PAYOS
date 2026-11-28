<?php

define("IN_SITE", true);
require_once __DIR__ . "/../libs/db.php";
require_once __DIR__ . "/../libs/lang.php";
require_once __DIR__ . "/../libs/helper.php";
require_once __DIR__ . "/../libs/api_credentials.php";
require_once __DIR__ . "/../libs/database/users.php";
orbit_api_no_store();
$CMSNT = new DB();
$method = isset($_SERVER["REQUEST_METHOD"]) ? strtoupper((string) $_SERVER["REQUEST_METHOD"]) : "GET";
$modernImport = array_key_exists("HTTP_X_API_KEY", $_SERVER);
if (($method === "GET" && !orbit_api_allow_legacy_import_get()) || ($modernImport && $method !== "POST")) {
    http_response_code(405);
    header("Allow: POST");
    exit(json_encode(["status" => "error", "msg" => "Chỉ chấp nhận POST cho yêu cầu này."]));
}
if ($modernImport && (isset($_GET["api_key"]) || isset($_GET["account"]))) {
    http_response_code(400);
    exit(json_encode(["status" => "error", "msg" => "Không đặt khoá hoặc tài khoản trong URL."]));
}
$api_key = check_string(orbit_api_credential(["api_key"], true));
if($api_key === "") {
    exit(json_encode(["status" => "error", "msg" => __("Vui lòng nhập API KEY")]));
}
// Header-authenticated imports use POST data exclusively, never cookies or URL parameters.
$input = $modernImport ? $_POST : $_REQUEST;
if(empty($input["code"])) {
    exit(json_encode(["status" => "error", "msg" => __("Vui lòng nhập Mã Kho Hàng")]));
}
if(empty($input["account"])) {
    exit(json_encode(["status" => "error", "msg" => __("Vui lòng nhập Tài khoản cần thêm")]));
}
if ($modernImport && (!is_string($input["code"]) || !is_string($input["account"])
    || strlen($input["code"]) > 255 || strlen($input["account"]) > 16384
    || (isset($input["filter"]) && $input["filter"] !== "1"))) {
    http_response_code(400);
    exit(json_encode(["status" => "error", "msg" => "Dữ liệu không hợp lệ; chỉ hỗ trợ filter=1."]));
}
$code = check_string($input["code"]);
$account = check_string($input["account"]);
$value_add = 0;
$value_update = 0;
list($uid) = explode("|", $account);
$filter = isset($input["filter"]) ? check_string($input["filter"]) : 1;
if ($modernImport && ($code === "" || trim($uid) === "")) {
    http_response_code(400);
    exit(json_encode(["status" => "error", "msg" => "Mã kho và UID không được để trống."]));
}
if(!($getUser = $CMSNT->get_row("SELECT * FROM `users` WHERE `api_key` = '" . $api_key . "' AND `banned` = 0 AND `admin` != 0 "))) {
    exit(json_encode(["status" => "error", "msg" => __("API KEY không chính xác")]));
}
if(!checkPermission($getUser["admin"], "edit_stock_product")) {
    exit(json_encode(["status" => "error", "msg" => __("API KEY không có quyền sử dụng tính năng này")]));
}
if ($modernImport) {
    // Never move/overwrite an existing UID through the new interface. A unique DB constraint is
    // still required to prevent simultaneous inserts after two requests both see no existing row.
    $errorsBefore = DB::$errors;
    $existing = $CMSNT->get_row(" SELECT * FROM `product_stock` WHERE `uid` = '" . $uid . "' ");
    if (DB::$errors !== $errorsBefore) {
        http_response_code(503);
        exit(json_encode(["status" => "error", "msg" => "Không thể kiểm tra kho; vui lòng kiểm tra lại trạng thái."]));
    }
    if ($existing) {
        http_response_code(409);
        exit(json_encode(["status" => "error", "msg" => "UID đã tồn tại; không tự động ghi đè hoặc di chuyển kho."]));
    }
    $inserted = $CMSNT->insert("product_stock", ["product_code" => $code, "seller" => $getUser["id"], "uid" => $uid, "account" => $account, "type" => "API", "create_gettime" => gettime()]);
    if (!$inserted) {
        http_response_code(503);
        exit(json_encode(["status" => "error", "msg" => "Không xác định được kết quả nhập kho; hãy kiểm tra trước khi thử lại."]));
    }
    exit(json_encode(["status" => "success", "msg" => "Đã thêm 1 tài khoản | Cập nhật 0 tài khoản"]));
}
if($filter == 1) {
    $errorsBefore = DB::$errors;
    $existing = $CMSNT->get_row(" SELECT * FROM `product_stock` WHERE `uid` = '" . $uid . "' ");
    if (DB::$errors !== $errorsBefore) {
        http_response_code(503);
        exit(json_encode(["status" => "error", "msg" => "Không thể kiểm tra kho; vui lòng kiểm tra lại trạng thái."]));
    }
    if($existing) {
        $isUpdate = $CMSNT->update("product_stock", ["product_code" => $code, "seller" => $getUser["id"], "uid" => $uid, "account" => $account, "type" => "API", "create_gettime" => gettime()], " `uid` = '" . $uid . "' ");
        if(!$isUpdate) {
            http_response_code(503);
            exit(json_encode(["status" => "error", "msg" => "Không xác định được kết quả cập nhật; hãy kiểm tra kho trước khi thử lại."]));
        }
        $value_update++;
    } else {
        $isAdd = $CMSNT->insert("product_stock", ["product_code" => $code, "seller" => $getUser["id"], "uid" => $uid, "account" => $account, "type" => "API", "create_gettime" => gettime()]);
        if(!$isAdd) {
            http_response_code(503);
            exit(json_encode(["status" => "error", "msg" => "Không xác định được kết quả nhập kho; hãy kiểm tra trước khi thử lại."]));
        }
        $value_add++;
    }
} else {
    $isAdd = $CMSNT->insert("product_stock", ["product_code" => $code, "seller" => $getUser["id"], "uid" => $uid, "account" => $account, "type" => "API", "create_gettime" => gettime()]);
    if(!$isAdd) {
        http_response_code(503);
        exit(json_encode(["status" => "error", "msg" => "Không xác định được kết quả nhập kho; hãy kiểm tra trước khi thử lại."]));
    }
    $value_add++;
}
exit(json_encode(["status" => "success", "msg" => "Đã thêm " . $value_add . " tài khoản | Cập nhật " . $value_update . " tài khoản"]));

?>