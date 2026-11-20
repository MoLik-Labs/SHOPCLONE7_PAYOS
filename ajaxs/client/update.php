<?php

define("IN_SITE", true);
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../../libs/db.php";
require_once __DIR__ . "/../../libs/legacy_payment_guard.php";
if (($_POST['action'] ?? '') === 'confirmPaypal') {
    reject_legacy_payment_when_payos_enabled();
}
require_once __DIR__ . "/../../libs/lang.php";
require_once __DIR__ . "/../../libs/helper.php";
/*
 * Every caller is a page on this domain (favourites, order detail, the buy modal). The token in the
 * body is a credential, not an anti-forgery proof: if it ever leaks — a shared screenshot, a pasted
 * URL — a foreign page could replay it. orbit_require_csrf() accepts the csrf.js header, a
 * `csrf_token` field, or an Origin/Referer that names this host, and refuses everything else.
 */
orbit_require_csrf();
require_once __DIR__ . "/../../libs/database/users.php";
if(!isset($_POST["action"])) {
    $data = json_encode(["status" => "error", "msg" => __("The Request Not Found")]);
    exit($data);
}
if($_POST["action"] == "saveNoteOrder") {
    if(empty($_POST["id"])) {
        exit(json_encode(["status" => "error", "msg" => __("Đơn hàng không tồn tại trong hệ thống")]));
    }
    if(empty($_POST["token"])) {
        exit(json_encode(["status" => "error", "msg" => __("Vui lòng đăng nhập để sử dụng tính năng này")]));
    }
    if(!($getUser = $CMSNT->get_row("SELECT * FROM `users` WHERE `token` = '" . check_string($_POST["token"]) . "' AND `banned` = 0 "))) {
        exit(json_encode(["status" => "error", "msg" => __("Vui lòng đăng nhập để sử dụng tính năng này")]));
    }
    $id = check_string($_POST["id"]);
    if(!($order = $CMSNT->get_row("SELECT * FROM `product_order` WHERE `id` = '" . $id . "' AND `buyer` = " . $getUser["id"] . " "))) {
        exit(json_encode(["status" => "error", "msg" => __("Đơn hàng không tồn tại trong hệ thống")]));
    }
    $isUpdate = $CMSNT->update("product_order", ["note" => !empty($_POST["note"]) ? check_string($_POST["note"]) : NULL], " `id` = '" . $order["id"] . "' ");
    if($isUpdate) {
        exit(json_encode(["status" => "success", "msg" => __("Cập nhật ghi chú thành công!")]));
    }
    exit(json_encode(["status" => "error", "msg" => __("Cập nhật ghi chú thất bại!")]));
}
if($_POST["action"] == "toggleFavorite") {
    if(empty($_POST["id"])) {
        exit(json_encode(["status" => "error", "msg" => __("Sản phẩm không hợp lệ")]));
    }
    $id = check_string($_POST["id"]);
    if(!($product = $CMSNT->get_row("SELECT * FROM `products` WHERE `id` = '" . $id . "' AND `status` = 1 "))) {
        exit(json_encode(["status" => "error", "msg" => __("Sản phẩm không tồn tại trong hệ thống")]));
    }
    if(empty($_POST["token"])) {
        exit(json_encode(["status" => "error", "msg" => __("Vui lòng đăng nhập để sử dụng tính năng này")]));
    }
    if(!($getUser = $CMSNT->get_row("SELECT * FROM `users` WHERE `token` = '" . check_string($_POST["token"]) . "' AND `banned` = 0 "))) {
        exit(json_encode(["status" => "error", "msg" => __("Vui lòng đăng nhập để sử dụng tính năng này")]));
    }
    if($isFavorite = $CMSNT->get_row(" SELECT * FROM `favorites` WHERE `user_id` = '" . $getUser["id"] . "' AND `product_id` = '" . $product["id"] . "' ")) {
        $CMSNT->remove("favorites", " `id` = '" . $isFavorite["id"] . "' ");
        exit(json_encode(["status" => "success", "button" => false, "msg" => __("Đã xóa sản phẩm khỏi danh sách yêu thích")]));
    }
    $CMSNT->insert("favorites", ["product_id" => $product["id"], "user_id" => $getUser["id"], "create_gettime" => gettime()]);
    exit(json_encode(["status" => "success", "button" => true, "msg" => __("Đã thêm sản phẩm vào danh sách yêu thích")]));
}
if($_POST["action"] == "changeLanguage") {
    if(empty($_POST["id"])) {
        exit(json_encode(["status" => "error", "msg" => __("Data does not exist")]));
    }
    $id = check_string($_POST["id"]);
    $row = $CMSNT->get_row("SELECT * FROM `languages` WHERE `id` = '" . $id . "' ");
    if(!$row) {
        exit(json_encode(["status" => "error", "msg" => __("Data does not exist")]));
    }
    $isUpdate = setLanguage($id);
    if($isUpdate) {
        $data = json_encode(["status" => "success", "msg" => __("Change language successfully")]);
        exit($data);
    }
}
if($_POST["action"] == "changeCurrency") {
    if(empty($_POST["id"])) {
        exit(json_encode(["status" => "error", "msg" => __("Data does not exist")]));
    }
    $id = check_string($_POST["id"]);
    $row = $CMSNT->get_row("SELECT * FROM `currencies` WHERE `id` = '" . $id . "' ");
    if(!$row) {
        exit(json_encode(["status" => "error", "msg" => __("Data does not exist")]));
    }
    $isUpdate = setCurrency($id);
    if($isUpdate) {
        $data = json_encode(["status" => "success", "msg" => __("Successful currency change")]);
        exit($data);
    }
}
if($CMSNT->site("status_demo") != 0) {
    $data = json_encode(["status" => "error", "msg" => __("This function cannot be used because this is a demo site")]);
    exit($data);
}
if($_POST["action"] == "confirmPaypal" && isset($_POST["order"])) {
    if($CMSNT->site("paypal_status") != 1) {
        exit(json_encode(["status" => "error", "msg" => __("Chức năng này đang được bảo trì")]));
    }
    if(empty($_POST["token"])) {
        exit(json_encode(["status" => "error", "msg" => __("Vui lòng đăng nhập")]));
    }
    if(!($getUser = $CMSNT->get_row("SELECT * FROM `users` WHERE `token` = '" . check_string($_POST["token"]) . "' AND `banned` = 0 "))) {
        exit(json_encode(["status" => "error", "msg" => __("Vui lòng đăng nhập")]));
    }
    $clientId = $CMSNT->site("paypal_clientId");
    $clientSecret = $CMSNT->site("paypal_clientSecret");
    $environment = new PayPalCheckoutSdk\Core\ProductionEnvironment($clientId, $clientSecret);
    $client = new PayPalCheckoutSdk\Core\PayPalHttpClient($environment);
    $orderData = $_POST["order"];
    $request = new PayPalCheckoutSdk\Orders\OrdersGetRequest($orderData["id"]);
    try {
        $response = $client->execute($request);
        if($response->statusCode != 200) {
            exit(json_encode(["status" => "error", "msg" => __("Đã xảy ra lỗi!")]));
        }
        $order = $response->result;
        if($order->status != "COMPLETED") {
            exit(json_encode(["status" => "error", "msg" => __("Đơn hàng không hợp lệ hoặc chưa thanh toán")]));
        }
        $orderDetail = isset($order->purchase_units[0]) ? $order->purchase_units[0] : null;
        if(!$orderDetail || !isset($orderDetail->amount->value) || !is_numeric($orderDetail->amount->value)) {
            exit(json_encode(["status" => "error", "msg" => __("Đơn hàng không hợp lệ hoặc chưa thanh toán")]));
        }
        $paypalAmount = (float) $orderDetail->amount->value;
        $paypalRate = (float) $CMSNT->site("paypal_rate");
        /* A non-positive or absurd amount must never mint currency. */
        if($paypalAmount <= 0 || $paypalAmount > 100000 || $paypalRate <= 0) {
            exit(json_encode(["status" => "error", "msg" => __("Số tiền thanh toán không hợp lệ")]));
        }
        /*
         * The order id is chosen by the client, so bind it to a nonce this page issued to this
         * session. Without it any completed charge under the same merchant account could be claimed.
         */
        $paypalCustom = isset($orderDetail->custom_id) && is_string($orderDetail->custom_id) ? $orderDetail->custom_id : "";
        $paypalBound = false;
        $nonces = isset($_SESSION["paypal_nonces"]) && is_array($_SESSION["paypal_nonces"]) ? $_SESSION["paypal_nonces"] : array();
        foreach($nonces as $nonce) {
            if(is_string($nonce) && hash_equals((int) $getUser["id"] . ":" . $nonce, $paypalCustom)) {
                $paypalBound = true;
                break;
            }
        }
        if(!$paypalBound) {
            exit(json_encode(["status" => "error", "msg" => __("Giao dịch không thuộc phiên thanh toán này, vui lòng mở lại trang nạp tiền")]));
        }
        if(0 < $CMSNT->num_rows("SELECT * FROM `payment_paypal` WHERE `trans_id` = '" . addslashes((string) $order->id) . "' ")) {
            exit(json_encode(["status" => "error", "msg" => __("Giao dịch này đã được xử lý")]));
        }
        $price = $paypalRate * $paypalAmount;
        $isInsert = $CMSNT->insert("payment_paypal", ["user_id" => $getUser["id"], "trans_id" => $order->id, "amount" => $paypalAmount, "price" => $price, "create_date" => gettime(), "create_time" => time()]);
        if($isInsert) {
            $user = new users();
            $isCong = $user->AddCredits($getUser["id"], $price, __("Nạp tiền tự động qua PayPal") . " - " . $order->id, "TOPUP_PAYPAL_" . $order->id);
            if($isCong) {
                $my_text = $CMSNT->site("noti_recharge");
                $my_text = str_replace("{domain}", $_SERVER["SERVER_NAME"], $my_text);
                $my_text = str_replace("{username}", $getUser["username"], $my_text);
                $my_text = str_replace("{method}", "PayPal", $my_text);
                $my_text = str_replace("{amount}", $orderDetail->amount->value, $my_text);
                $my_text = str_replace("{price}", $price, $my_text);
                $my_text = str_replace("{time}", gettime(), $my_text);
                sendMessAdmin($my_text);
                exit(json_encode(["status" => "success", "msg" => __("Nạp tiền thành công")]));
            }
            /*
             * The invoice row was written but the credit failed: remove it so the buyer can retry,
             * and leave a trace. Reporting "already credited" would strand the payment.
             */
            $CMSNT->remove("payment_paypal", " `trans_id` = '" . addslashes((string) $order->id) . "' ");
            $CMSNT->insert("logs", ["user_id" => $getUser["id"], "ip" => myip(), "device" => (new Mobile_Detect())->getUserAgent(), "createdate" => gettime(), "action" => "[Error] PayPal " . $order->id . " đã ghi nhận nhưng chưa cộng được tiền"]);
            exit(json_encode(["status" => "error", "msg" => __("Chưa cộng được tiền, vui lòng thử lại hoặc liên hệ hỗ trợ")]));
        }
    } catch (PayPalHttp\HttpException $e) {
        exit(json_encode(["status" => "error", "msg" => $e->getMessage()]));
    } catch (Exception $e) {
        exit(json_encode(["status" => "error", "msg" => $e->getMessage()]));
    }
}
exit(json_encode(["status" => "error", "msg" => __("Invalid data")]));

?>
