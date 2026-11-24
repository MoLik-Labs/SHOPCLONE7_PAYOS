<?php

define("IN_SITE", true);
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../../libs/db.php";
require_once __DIR__ . "/../../libs/lang.php";
require_once __DIR__ . "/../../libs/helper.php";
require_once __DIR__ . "/../../libs/database/users.php";
require_once __DIR__ . "/../../models/is_admin.php";
if(!isset($_POST["action"])) {
    $data = json_encode(["status" => "error", "msg" => "The Request Not Found"]);
    exit($data);
}
if($CMSNT->site("status_demo") != 0) {
    exit(json_encode(["status" => "error", "msg" => __("Chức năng này không thể sử dụng trên website demo")]));
}
orbit_require_csrf();
if($_POST["action"] == "cap_nhat_san_pham_nhanh") {
    if(!checkPermission($getUser["admin"], "edit_product")) {
        exit(json_encode(["status" => "error", "msg" => "Bạn không có quyền sử dụng tính năng này"]));
    }
    $id = (int) check_string($_POST["id"]);
    if(!($product = $CMSNT->get_row(" SELECT * FROM `products` WHERE `id` = " . $id . " "))) {
        exit(json_encode(["status" => "error", "msg" => "Sản phẩm không tồn tại trong hệ thống"]));
    }
    if(!empty($_POST["category_id"])) {
        $isUpdate = $CMSNT->update("products", ["category_id" => check_string($_POST["category_id"])], " `id` = " . $id . " ");
    }
    if(!empty($_POST["status"])) {
        $status = check_string($_POST["status"]);
        if($status == "ON") {
            $status = 1;
        } else {
            $status = 0;
        }
        $isUpdate = $CMSNT->update("products", ["status" => $status], " `id` = " . $id . " ");
    }
    if(!empty($_POST["discount"])) {
        $discount = check_string($_POST["discount"]);
        $isUpdate = $CMSNT->update("products", ["discount" => $discount], " `id` = " . $id . " ");
    }
    if(isset($isUpdate)) {
        $Mobile_Detect = new Mobile_Detect();
        $CMSNT->insert("logs", ["user_id" => $getUser["id"], "ip" => myip(), "device" => $Mobile_Detect->getUserAgent(), "createdate" => gettime(), "action" => "Cập nhật nhanh sản phẩm (ID " . $id . ")"]);
        exit(json_encode(["status" => "success", "msg" => __("Cập nhật thành công!")]));
    }
    exit(json_encode(["status" => "error", "msg" => __("Không có sản phẩm nào được thay đổi")]));
}
if($_POST["action"] == "reset_total_money_users") {
    if(!checkPermission($getUser["admin"], "edit_user")) {
        exit(json_encode(["status" => "error", "msg" => __("Bạn không có quyền sử dụng tính năng này")]));
    }
    $isUpdate = $CMSNT->update("users", ["total_money" => 0], " `total_money` > 0 ");
    if(isset($isUpdate)) {
        $Mobile_Detect = new Mobile_Detect();
        $CMSNT->insert("logs", ["user_id" => $getUser["id"], "ip" => myip(), "device" => $Mobile_Detect->getUserAgent(), "createdate" => gettime(), "action" => "Reset tổng nạp toàn bộ thành viên"]);
        exit(json_encode(["status" => "success", "msg" => "Reset tổng nạp toàn bộ user thành công!"]));
    }
    exit(json_encode(["status" => "error", "msg" => __("Reset thất bại")]));
}
if($_POST["action"] == "update_status_user") {
    if(!checkPermission($getUser["admin"], "edit_user")) {
        exit(json_encode(["status" => "error", "msg" => "Bạn không có quyền sử dụng tính năng này"]));
    }
    if(!($user = $CMSNT->get_row(" SELECT * FROM `users` WHERE `id` = '" . check_string($_POST["id"]) . "' "))) {
        exit(json_encode(["status" => "error", "msg" => "Thành viên không tồn tại trong hệ thống"]));
    }
    $isUpdate = $CMSNT->update("users", ["banned" => !empty($_POST["status"]) ? check_string($_POST["status"]) : 0], " `id` = '" . check_string($_POST["id"]) . "' ");
    if($isUpdate) {
        $Mobile_Detect = new Mobile_Detect();
        $CMSNT->insert("logs", ["user_id" => $getUser["id"], "ip" => myip(), "device" => $Mobile_Detect->getUserAgent(), "createdate" => gettime(), "action" => "Update Status User (" . $user["username"] . " - " . $user["id"] . ")"]);
        exit(json_encode(["status" => "success", "msg" => __("Cập nhật thành công!")]));
    }
    exit(json_encode(["status" => "error", "msg" => __("Cập nhật thất bại")]));
}
if($_POST["action"] == "update_product_code_product_stock") {
    if(!checkPermission($getUser["admin"], "edit_stock_product")) {
        exit(json_encode(["status" => "error", "msg" => "Bạn không có quyền sử dụng tính năng này"]));
    }
    if(!($row = $CMSNT->get_row(" SELECT * FROM `product_stock` WHERE `id` = '" . check_string($_POST["id"]) . "' "))) {
        exit(json_encode(["status" => "error", "msg" => "Tài khoản không tồn tại trong hệ thống"]));
    }
    $isUpdate = $CMSNT->update("product_stock", ["product_code" => !empty($_POST["product_code"]) ? check_string($_POST["product_code"]) : $row["product_code"]], " `id` = '" . check_string($_POST["id"]) . "' ");
    if($isUpdate) {
        $Mobile_Detect = new Mobile_Detect();
        $CMSNT->insert("logs", ["user_id" => $getUser["id"], "ip" => myip(), "device" => $Mobile_Detect->getUserAgent(), "createdate" => gettime(), "action" => "Cập nhật mã kho hàng cho tài khoản (" . $row["uid"] . ")"]);
        exit(json_encode(["status" => "success", "msg" => "Cập nhật kho hàng tài khoản " . $row["uid"] . " thành công!"]));
    }
    exit(json_encode(["status" => "error", "msg" => __("Cập nhật kho hàng tài khoản " . $row["uid"] . " thất bại")]));
}
if($_POST["action"] == "refundOrder") {
    if(!checkPermission($getUser["admin"], "refund_orders_product")) {
        exit(json_encode(["status" => "error", "msg" => "Bạn không có quyền sử dụng tính năng này"]));
    }
    if(empty($_POST["id"])) {
        exit(json_encode(["status" => "error", "msg" => "ID đơn hàng không tồn tại"]));
    }
    $id = check_string($_POST["id"]);
    if(!($product_order = $CMSNT->get_row(" SELECT * FROM `product_order` WHERE `id` = '" . $id . "' "))) {
        exit(json_encode(["status" => "error", "msg" => "Đơn hàng không tồn tại trong hệ thống"]));
    }
    if($product_order["refund"] == 1) {
        exit(json_encode(["status" => "error", "msg" => __("Đơn hàng này đã được hoàn tiền rồi")]));
    }
    $User = new users();
    $refundState = $User->RefundCreditsChecked($product_order["buyer"], $product_order["pay"], __("Hoàn tiền đơn hàng") . " #" . $product_order["trans_id"], "REFUND_ORDER_" . $product_order["trans_id"]);
    /*
     * "duplicate" means this order's refund was already moved (a second click, or a retry after the
     * response was lost) — the buyer has the money, so the order still has to be closed. Only
     * "failed" stops here. The historical amounts stay on the row: every report filters
     * `refund = 0`, so zeroing them only destroyed the record of what the customer actually paid.
     */
    if($refundState !== "failed") {
        $CMSNT->update("product_order", ["refund" => 1, "trash" => 1], " `id` = '" . $id . "' ");
        if($CMSNT->site("cong_tien_nguoi_ban") == 1) {
            $isSellerTaken = $User->RemoveCredits($product_order["seller"], $product_order["pay"], __("Thu hồi hoàn tiền đơn hàng") . " #" . $product_order["trans_id"], "TAKE_REFUND_ORDER_" . $product_order["trans_id"]);
            if(!$isSellerTaken) {
                /* The seller keeps money the shop refunded; leave it visible instead of silent. */
                $CMSNT->insert("logs", ["user_id" => $product_order["seller"], "ip" => myip(), "device" => (new Mobile_Detect())->getUserAgent(), "createdate" => gettime(), "action" => "[Error] " . __("Chưa thu hồi được doanh thu của đơn hoàn tiền") . " #" . $product_order["trans_id"] . " (" . format_currency($product_order["pay"]) . ")"]);
            }
        }
        $user = $CMSNT->get_row(" SELECT * FROM `users` WHERE `id` = '" . $product_order["buyer"] . "' ");
        if($user["ref_id"] != 0) {
            /* The refunded money must not still pay a commission. The purchase wrote the exact
               amount into aff_log with the order trans_id; legacy rows without the tag are skipped
               rather than recomputed at today's rate. */
            $commission = $CMSNT->get_row("SELECT `sotienthaydoi` FROM `aff_log` WHERE `user_id` = " . (int) $user["ref_id"]
                . " AND `reason` LIKE '%#" . addslashes($product_order["trans_id"]) . "' ORDER BY `id` DESC LIMIT 1");
            if($commission && 0 < (float) $commission["sotienthaydoi"]) {
                $User->ClawbackCommission($user["ref_id"], $product_order["buyer"], $commission["sotienthaydoi"], __("Thu hồi hoa hồng đơn hoàn tiền") . " #" . $product_order["trans_id"]);
            }
        }
        $Mobile_Detect = new Mobile_Detect();
        $CMSNT->insert("logs", ["user_id" => $getUser["id"], "ip" => myip(), "device" => $Mobile_Detect->getUserAgent(), "createdate" => gettime(), "action" => __("Hoàn tiền đơn hàng") . " (" . $product_order["trans_id"] . ")"]);
        exit(json_encode(["status" => "success", "msg" => $refundState === "duplicate"
            ? __("Đơn hàng này đã được hoàn tiền trước đó; đã đóng đơn.")
            : __("Hoàn tiền đơn hàng thành công!")]));
    }
    exit(json_encode(["status" => "error", "msg" => __("Chưa hoàn được tiền, vui lòng thử lại.")]));
}
if($_POST["action"] == "update_stt_table_product") {
    if(!checkPermission($getUser["admin"], "edit_product")) {
        exit(json_encode(["status" => "error", "msg" => "Bạn không có quyền sử dụng tính năng này"]));
    }
    $isUpdate = $CMSNT->update("products", ["stt" => !empty($_POST["stt"]) ? check_string($_POST["stt"]) : 0], " `id` = '" . check_string($_POST["id"]) . "' ");
    if($isUpdate) {
        $Mobile_Detect = new Mobile_Detect();
        $CMSNT->insert("logs", ["user_id" => $getUser["id"], "ip" => myip(), "device" => $Mobile_Detect->getUserAgent(), "createdate" => gettime(), "action" => "Cập nhật ưu tiên sản phẩm (ID " . check_string($_POST["id"]) . ")"]);
        exit(json_encode(["status" => "success", "msg" => "Cập nhật ưu tiên sản phẩm ID " . check_string($_POST["id"]) . " thành công!"]));
    }
    exit(json_encode(["status" => "error", "msg" => __("Cập nhật thất bại")]));
}
if($_POST["action"] == "update_category_category") {
    if(!checkPermission($getUser["admin"], "edit_product")) {
        exit(json_encode(["status" => "error", "msg" => "Bạn không có quyền sử dụng tính năng này"]));
    }
    $isUpdate = $CMSNT->update("categories", ["parent_id" => !empty($_POST["category_id"]) ? check_string($_POST["category_id"]) : 0], " `id` = '" . check_string($_POST["id"]) . "' ");
    if($isUpdate) {
        $Mobile_Detect = new Mobile_Detect();
        $CMSNT->insert("logs", ["user_id" => $getUser["id"], "ip" => myip(), "device" => $Mobile_Detect->getUserAgent(), "createdate" => gettime(), "action" => "Cập nhật chuyên mục cha cho chuyên mục (ID " . check_string($_POST["id"]) . ")"]);
        exit(json_encode(["status" => "success", "msg" => __("Cập nhật chuyên mục cha thành công!")]));
    }
    exit(json_encode(["status" => "error", "msg" => __("Cập nhật thất bại")]));
}
if($_POST["action"] == "update_category_product") {
    if(!checkPermission($getUser["admin"], "edit_product")) {
        exit(json_encode(["status" => "error", "msg" => "Bạn không có quyền sử dụng tính năng này"]));
    }
    $isUpdate = $CMSNT->update("products", ["category_id" => !empty($_POST["category_id"]) ? check_string($_POST["category_id"]) : 0], " `id` = '" . check_string($_POST["id"]) . "' ");
    if($isUpdate) {
        $Mobile_Detect = new Mobile_Detect();
        $CMSNT->insert("logs", ["user_id" => $getUser["id"], "ip" => myip(), "device" => $Mobile_Detect->getUserAgent(), "createdate" => gettime(), "action" => "Cập nhật chuyên mục cho sản phẩm (ID " . check_string($_POST["id"]) . ")"]);
        exit(json_encode(["status" => "success", "msg" => __("Cập nhật chuyên mục thành công!")]));
    }
    exit(json_encode(["status" => "error", "msg" => __("Cập nhật thất bại")]));
}
if($_POST["action"] == "updateTableProductAPI") {
    if(!checkPermission($getUser["admin"], "manager_suppliers")) {
        exit(json_encode(["status" => "error", "msg" => "Bạn không có quyền sử dụng tính năng này"]));
    }
    $isUpdate = $CMSNT->update("suppliers", ["status" => !empty($_POST["status"]) ? check_string($_POST["status"]) : 0], " `id` = '" . check_string($_POST["id"]) . "' ");
    if($isUpdate) {
        $Mobile_Detect = new Mobile_Detect();
        $CMSNT->insert("logs", ["user_id" => $getUser["id"], "ip" => myip(), "device" => $Mobile_Detect->getUserAgent(), "createdate" => gettime(), "action" => "Update Supplier (ID " . check_string($_POST["id"]) . ")"]);
        exit(json_encode(["status" => "success", "msg" => __("Cập nhật thành công!")]));
    }
    exit(json_encode(["status" => "error", "msg" => __("Cập nhật thất bại")]));
}
if($_POST["action"] == "updateTableCategory") {
    if(!checkPermission($getUser["admin"], "edit_product")) {
        exit(json_encode(["status" => "error", "msg" => "Bạn không có quyền sử dụng tính năng này"]));
    }
    $isUpdate = $CMSNT->update("categories", ["stt" => !empty($_POST["stt"]) ? check_string($_POST["stt"]) : 0, "status" => !empty($_POST["status"]) ? check_string($_POST["status"]) : 0], " `id` = '" . check_string($_POST["id"]) . "' ");
    if($isUpdate) {
        $Mobile_Detect = new Mobile_Detect();
        $CMSNT->insert("logs", ["user_id" => $getUser["id"], "ip" => myip(), "device" => $Mobile_Detect->getUserAgent(), "createdate" => gettime(), "action" => "Update Table Category (ID " . check_string($_POST["id"]) . ")"]);
        exit(json_encode(["status" => "success", "msg" => __("Cập nhật thành công!")]));
    }
    exit(json_encode(["status" => "error", "msg" => __("Cập nhật thất bại")]));
}
if($_POST["action"] == "update_status_category") {
    if(!checkPermission($getUser["admin"], "edit_product")) {
        exit(json_encode(["status" => "error", "msg" => "Bạn không có quyền sử dụng tính năng này"]));
    }
    $isUpdate = $CMSNT->update("categories", ["status" => !empty($_POST["status"]) ? check_string($_POST["status"]) : 0], " `id` = '" . check_string($_POST["id"]) . "' ");
    if($isUpdate) {
        $Mobile_Detect = new Mobile_Detect();
        $CMSNT->insert("logs", ["user_id" => $getUser["id"], "ip" => myip(), "device" => $Mobile_Detect->getUserAgent(), "createdate" => gettime(), "action" => "Update Status Category (ID " . check_string($_POST["id"]) . ")"]);
        exit(json_encode(["status" => "success", "msg" => __("Cập nhật thành công!")]));
    }
    exit(json_encode(["status" => "error", "msg" => __("Cập nhật thất bại")]));
}
if($_POST["action"] == "update_status_product") {
    if(!checkPermission($getUser["admin"], "edit_product")) {
        exit(json_encode(["status" => "error", "msg" => "Bạn không có quyền sử dụng tính năng này"]));
    }
    $isUpdate = $CMSNT->update("products", ["status" => !empty($_POST["status"]) ? check_string($_POST["status"]) : 0], " `id` = '" . check_string($_POST["id"]) . "' ");
    if($isUpdate) {
        $Mobile_Detect = new Mobile_Detect();
        $CMSNT->insert("logs", ["user_id" => $getUser["id"], "ip" => myip(), "device" => $Mobile_Detect->getUserAgent(), "createdate" => gettime(), "action" => "Update Status Product (ID " . check_string($_POST["id"]) . ")"]);
        exit(json_encode(["status" => "success", "msg" => __("Cập nhật thành công!")]));
    }
    exit(json_encode(["status" => "error", "msg" => __("Cập nhật thất bại")]));
}
if($_POST["action"] == "cancel_email_campaigns") {
    if(!checkPermission($getUser["admin"], "edit_email_campaigns")) {
        exit(json_encode(["status" => "error", "msg" => "Bạn không có quyền sử dụng tính năng này"]));
    }
    $isUpdate = $CMSNT->update("email_campaigns", ["status" => 2], " `id` = '" . check_string($_POST["id"]) . "' ");
    if($isUpdate) {
        exit(json_encode(["status" => "success", "msg" => __("Cập nhật thành công!")]));
    }
    exit(json_encode(["status" => "error", "msg" => __("Cập nhật thất bại")]));
}
if($_POST["action"] == "setDefaultLanguage") {
    if(!checkPermission($getUser["admin"], "edit_lang")) {
        exit(json_encode(["status" => "error", "msg" => "Bạn không có quyền sử dụng tính năng này"]));
    }
    if(empty($_POST["id"])) {
        exit(json_encode(["status" => "error", "msg" => __("Data does not exist")]));
    }
    $id = check_string($_POST["id"]);
    $row = $CMSNT->get_row("SELECT * FROM `languages` WHERE `id` = '" . $id . "' ");
    if(!$row) {
        $data = json_encode(["status" => "error", "msg" => __("Data does not exist")]);
        exit($data);
    }
    $CMSNT->update("languages", ["lang_default" => 0], " `id` > 0 ");
    $isUpdate = $CMSNT->update("languages", ["lang_default" => 1], " `id` = '" . $id . "' ");
    if($isUpdate) {
        $Mobile_Detect = new Mobile_Detect();
        $CMSNT->insert("logs", ["user_id" => $getUser["id"], "ip" => myip(), "device" => $Mobile_Detect->getUserAgent(), "createdate" => gettime(), "action" => "Set default language (" . $row["lang"] . " ID " . $row["id"] . ")"]);
        $data = json_encode(["status" => "success", "msg" => __("Language status change successful")]);
        exit($data);
    }
}
if($_POST["action"] == "changeTranslate") {
    if(!checkPermission($getUser["admin"], "edit_lang")) {
        exit(json_encode(["status" => "error", "msg" => "Bạn không có quyền sử dụng tính năng này"]));
    }
    $isUpdate = $CMSNT->update("translate", ["value" => check_string($_POST["value"])], " `id` = '" . check_string($_POST["id"]) . "' ");
    if($isUpdate) {
        exit(json_encode(["status" => "success", "msg" => __("Update successful!")]));
    }
    exit(json_encode(["status" => "error", "msg" => __("Update failed!")]));
}
if($_POST["action"] == "setDefaultCurrency") {
    if(!checkPermission($getUser["admin"], "edit_currency")) {
        exit(json_encode(["status" => "error", "msg" => "Bạn không có quyền sử dụng tính năng này"]));
    }
    $id = check_string($_POST["id"]);
    $row = $CMSNT->get_row("SELECT * FROM `currencies` WHERE `id` = '" . $id . "' ");
    if(!$row) {
        $data = json_encode(["status" => "error", "msg" => "ID tiền tệ không tồn tại trong hệ thống"]);
        exit($data);
    }
    $CMSNT->update("currencies", ["default_currency" => 0], " `id` > 0 ");
    $isUpdate = $CMSNT->update("currencies", ["default_currency" => 1], " `id` = '" . $id . "' ");
    if($isUpdate) {
        $Mobile_Detect = new Mobile_Detect();
        $CMSNT->insert("logs", ["user_id" => $getUser["id"], "ip" => myip(), "device" => $Mobile_Detect->getUserAgent(), "createdate" => gettime(), "action" => "Set mặc định tiền tệ (" . $row["name"] . " ID " . $row["id"] . ")"]);
        $data = json_encode(["status" => "success", "msg" => "Thay đổi trạng thái tiền tệ thành công"]);
        exit($data);
    }
    exit(json_encode(["status" => "error", "msg" => "Cập nhật thất bại"]));
}
if($_POST["action"] == "logoutALL") {
    if(!checkPermission($getUser["admin"], "edit_user")) {
        exit(json_encode(["status" => "error", "msg" => "Bạn không có quyền sử dụng tính năng này"]));
    }
    foreach ($CMSNT->get_list(" SELECT * FROM `users` WHERE `admin` = 0 ") as $row) {
        /* A session token must not be derivable: six characters plus the current second is a
           dictionary an attacker can walk. The CSPRNG costs nothing here. */
        $CMSNT->update("users", ["token" => bin2hex(random_bytes(32))], " `id` = '" . $row["id"] . "' ");
    }
    $Mobile_Detect = new Mobile_Detect();
    $CMSNT->insert("logs", ["user_id" => $getUser["id"], "ip" => myip(), "device" => $Mobile_Detect->getUserAgent(), "createdate" => gettime(), "action" => __("Log out all members on the system")]);
    if($CMSNT->site("email") != "") {
        $chu_de = "Cảnh báo bảo mật website " . $CMSNT->site("title");
        $noi_dung = "\nHệ thống phát hiện <b>" . $getUser["username"] . "</b> IP <b style=\"color:red;\">" . myip() . "</b> vừa thực hiện đăng xuất tất cả tài khoản trên hệ thống.<br>\nNếu bạn không thực hiện thao tác này, hãy kiểm tra nhật ký và liên hệ quản trị viên website ngay.<br>\n<br>\n<ul>\n<li>Thời gian: " . gettime() . "</li>\n<li>IP: " . myip() . "</li>\n<li>Thiết bị: " . $Mobile_Detect->getUserAgent() . "</li>\n</ul>";
        $bcc = $CMSNT->site("title");
        sendCSM($CMSNT->site("email"), $getUser["username"], $chu_de, $noi_dung, $bcc);
    }
    $data = json_encode(["status" => "success", "msg" => __("Exit all accounts successfully!")]);
    exit($data);
} else {
    exit(json_encode(["status" => "error", "msg" => __("Invalid data")]));
}

?>