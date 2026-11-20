<?php
header("Content-type: application/json; charset=utf-8");
define("IN_SITE", true);
require_once __DIR__ . "/../libs/db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../libs/lang.php";
require_once __DIR__ . "/../libs/helper.php";
require_once __DIR__ . "/../libs/sendEmail.php";
require_once __DIR__ . "/../libs/suppliers.php";
require_once __DIR__ . "/../libs/database/users.php";
$Mobile_Detect = new Mobile_Detect();
$CMSNT = new DB();
/* Two names, one credential: the read-only endpoints (api/profile.php, api/products.php, api/product.php)
   call it `api_key`, this one historically called it `apikey`. Accept either so an integrator can carry
   one parameter name across the whole API. */
$apiKey = "";
if (!empty($_GET["apikey"])) {
    $apiKey = check_string($_GET["apikey"]);
} elseif (!empty($_GET["api_key"])) {
    $apiKey = check_string($_GET["api_key"]);
}
if ($apiKey === "") {
    exit(json_encode(["code" => 201, "msg" => __("Thiếu apikey")]));
}
if (!($getUser = $CMSNT->get_row("SELECT * FROM `users` WHERE `api_key` = '" . $apiKey . "' AND `banned` = 0 "))) {
    exit(json_encode(["code" => 201, "msg" => __("apikey không hợp lệ")]));
}
$apiAction = isset($_GET["action"]) && is_string($_GET["action"]) ? $_GET["action"] : "";
if ($apiAction == "get-balance") { // lấy sô dư
    $user = [];
    $user = ["username" => $getUser["username"], "balance" => $getUser["money"]];
    echo json_encode($user, JSON_PRETTY_PRINT);
} elseif ($apiAction == "get-services") { // lấy danh sách sản phẩm
    $data_category = [];
    foreach ($CMSNT->get_list(" SELECT * FROM `categories` WHERE `parent_id` != 0 AND `status` = 1 ") as $category) {
        $data_product = [];
        foreach ($CMSNT->get_list(" SELECT * FROM `products` WHERE `category_id` = '" . $category["id"] . "' AND `status` = 1 ") as $product) {
            $stock = $product["supplier_id"] != 0 ? $product["api_stock"] : getStock($product["code"]);
            $data_product[] = [
                "service_id" => $product["id"],
                "service_name" => $product["name"],
                "quantity" => (int) $stock,
                "description" => $product["short_desc"],
                "price" => (int) $product["price"],
            ];
        }
        $data_category[] = $data_product;
    }
    /* This used to return $data_product — the rows of the LAST category only — while $data_category
       (every category, each with its own products) was built and thrown away. */
    exit(json_encode(["code" => 0, "msg" => __("Lấy dữ liệu thành công!"), "data" => $data_category], JSON_PRETTY_PRINT));
} elseif ($apiAction == "create-order") { // Tạo đơn


    if (empty($_GET["service_id"])) {
        exit(json_encode(["code" => 201, "message" => __("Sản phẩm không hợp lệ")]));
    }
    if (!($product = $CMSNT->get_row("SELECT * FROM `products` WHERE `id` = '" . check_string($_GET["service_id"]) . "' AND `status` = 1 "))) {
        exit(json_encode(["code" => 201, "message" => __("Sản phẩm không tồn tại trong hệ thống")]));
    }
    if (empty($_GET["amount"])) {
        exit(json_encode(["code" => 201, "message" => __("Vui lòng nhập số lượng cần mua")]));
    }

    /* Digits only: this value becomes `LIMIT <n>` below, so a non-number must never reach SQL. */
    $amount = order_quantity($_GET["amount"]);
    if ($amount === false) {
        exit(json_encode(["code" => 201, "message" => __("Số lượng mua không hợp lệ")]));
    }
    if ($product["supplier_id"] == 0) {
        if ($CMSNT->get_row(" SELECT COUNT(id) FROM `product_stock` WHERE `product_code` = '" . $product["code"] . "' ")["COUNT(id)"] < $amount) {
            exit(json_encode(["code" => 201, "message" => __("Số lượng còn lại trong hệ thống không đủ")]));
        }
    } else {
        if ($product["api_stock"] < $amount) {
            exit(json_encode(["code" => 201, "message" => __("Số lượng còn lại trong hệ thống không đủ")]));
        }
        if (!($supplier = $CMSNT->get_row(" SELECT * FROM `suppliers` WHERE `id` = '" . $product["supplier_id"] . "' AND `status` = 1 "))) {
            exit(json_encode(["code" => 201, "message" => __("Sản phẩm này đang bảo trì, không thể mua hàng vào lúc này")]));
        }
    }
    $trans_id = random("QWERTYUOPASDFGHJKZXCVBNM123456789", 3) . uniqid();
    $price = $product["discount"] == 0 ? $product["price"] : $product["price"] - $product["price"] * $product["discount"] / 100;
    $money = $amount * $price;
    if ($getUser["discount"] == 0) {
        $discount = $money * getDiscount($amount, $product["id"]) / 100;
    } else {
        $discount = $money * $getUser["discount"] / 100;
    }
    /* A total discount over 100% would make $pay negative, and RemoveCredits(-x) adds money. */
    if ($money < $discount) {
        $discount = $money;
    }
    $pay = $money - $discount;
    if (!empty($_GET["coupon"])) {
        /* Reserve the coupon before charging, then release it again unless the order is written. */
        $discount_coupon = orbit_coupon_claim($product["id"], $_GET["coupon"], $getUser["id"], $money, $trans_id);
        if ($discount_coupon === false) {
            exit(json_encode(["code" => 201, "message" => __("Mã giảm giá không hợp lệ hoặc đã được sử dụng")]));
        }
        $discount = $discount + $discount_coupon;
        if ($money < $discount) {
            $discount = $money;
        }
        $pay = $money - $discount;
    }
    if (getRowRealtime("users", $getUser["id"], "money") < $pay) {
        exit(json_encode(["code" => 201, "message" => __("Số dư không đủ, vui lòng nạp thêm")]));
    }
    $User = new users();
    $isTru = $User->RemoveCredits($getUser["id"], $pay, __("Thanh toán đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "ORDER_" . $trans_id);


    if ($isTru) {
        if (getRowRealtime("users", $getUser["id"], "money") < -500) {
            $User->Banned($getUser["id"], __("Gian lận khi mua tài khoản"));
            exit(json_encode(["code" => 201, "message" => __("Bạn đã bị khoá tài khoản vì gian lận")]));
        }
        $api_trans_id = NULL;
        $isValue = 0;
        $partialMessage = "";
        if ($product["supplier_id"] == 0) {
            foreach ($CMSNT->get_list(" SELECT * FROM `product_stock` WHERE `product_code` = '" . $product["code"] . "' ORDER BY time_check_live DESC LIMIT " . $amount . " ") as $product_stock) {
                $isInsertSold = $CMSNT->insert("product_sold", ["product_code" => $product_stock["product_code"], "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product_stock["seller"], "uid" => $product_stock["uid"], "account" => $product_stock["account"], "create_gettime" => gettime(), "time_check_live" => $product_stock["time_check_live"]]);
                if ($isInsertSold) {
                    $isValue++;
                    $CMSNT->remove("product_stock", " `id` = '" . $product_stock["id"] . "' ");
                }
            }
        } else {
            if ($supplier["type"] == "SHOPCLONE6") {
                $data = curl_get2($supplier["domain"] . "/api/BResource.php?username=" . $supplier["username"] . "&password=" . $supplier["password"] . "&id=" . $product["api_id"] . "&amount=" . $amount);
                $data = json_decode($data, true);
                if (!is_array($data)) {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["code" => 201, "message" => __("Nhà cung cấp trả về dữ liệu không hợp lệ, đã hoàn tiền")]));
                }
                if ($data["status"] == "error") {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["code" => 201, "message" => __($data["msg"])]));
                }
                $api_trans_id = $data["data"]["trans_id"];
                foreach ($data["data"]["lists"] as $account) {
                    $account = check_string($account["account"]);
                    list($uid) = explode("|", $account);
                    $isInsertAPI = $CMSNT->insert("product_sold", ["product_code" => NULL, "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product["user_id"], "uid" => $uid, "account" => $account, "create_gettime" => gettime()]);
                    if ($isInsertAPI) {
                        $isValue++;
                    }
                }
            }
            if ($supplier["type"] == "SHOPCLONE7") {
                $data = buy_API_SHOPCLONE7($supplier["domain"], $supplier["coupon"], $supplier["api_key"], $product["api_id"], $amount);
                $data = json_decode($data, true);
                if (!is_array($data)) {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["code" => 201, "message" => __("Nhà cung cấp trả về dữ liệu không hợp lệ, đã hoàn tiền")]));
                }
                if ($data["status"] == "error") {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["code" => 201, "message" => __($data["msg"])]));
                }
                $api_trans_id = $data["trans_id"];
                foreach ($data["data"] as $account) {
                    $account = check_string($account);
                    list($uid) = explode("|", $account);
                    $isInsertAPI = $CMSNT->insert("product_sold", ["product_code" => NULL, "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product["user_id"], "uid" => $uid, "account" => $account, "create_gettime" => gettime()]);
                    if ($isInsertAPI) {
                        $isValue++;
                    }
                }
            }
            if ($supplier["type"] == "API_1") {
                $dataPost = ["api_key" => $supplier["api_key"], "id_product" => $product["api_id"], "quantity" => $amount];
                $response = buy_API_1($supplier["domain"], $dataPost);
                $data = json_decode($response, true);
                if (!$data["status"]) {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["code" => 201, "message" => __($data["msg"])]));
                }
                $api_trans_id = $data["order_id"];
                $response = order_API_1($supplier["domain"], $supplier["api_key"], $api_trans_id);
                $result = json_decode($response, true);
                foreach ($result["data"] as $account) {
                    $account = check_string($account["full_info"]);
                    list($uid) = explode("|", $account);
                    $isInsertAPI = $CMSNT->insert("product_sold", ["product_code" => NULL, "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product["user_id"], "uid" => $uid, "account" => $account, "create_gettime" => gettime()]);
                    if ($isInsertAPI) {
                        $isValue++;
                    }
                }
            }
            if ($supplier["type"] == "API_4") {
                $response = buy_API_4($supplier["domain"], $supplier["token"], $product["api_id"], $amount);
                $result = json_decode($response, true);
                if (!isset($result["data"])) {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["code" => 201, "message" => __($result["message"]["messageVNI"])]));
                }
                $api_trans_id = NULL;
                foreach ($result["data"] as $account) {
                    $account = check_string($account);
                    list($uid) = explode("|", $account);
                    $isInsertAPI = $CMSNT->insert("product_sold", ["product_code" => NULL, "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product["user_id"], "uid" => $uid, "account" => $account, "create_gettime" => gettime()]);
                    if ($isInsertAPI) {
                        $isValue++;
                    }
                }
            }
            if ($supplier["type"] == "API_6") {
                $response = curl_get2($supplier["domain"] . "/api.php?apikey=" . $supplier["api_key"] . "&action=create-order&service_id=" . $product["api_id"] . "&amount=" . $amount);
                $data = json_decode($response, true);
                if ($data["code"] != 200) {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["code" => 201, "message" => __($data["message"])]));
                }
                $api_trans_id = $data["order_id"];
                /* Bounded poll: a supplier that never reports "ready" must not spin forever. */
                $data_account = orbit_poll_supplier(
                    function () use ($supplier, $api_trans_id) {
                        return curl_get2($supplier["domain"] . "/api.php?apikey=" . $supplier["api_key"] . "&action=get-order-detail&order_id=" . $api_trans_id);
                    },
                    function ($body) {
                        return isset($body["order"]["status"]) && (int) $body["order"]["status"] === 1;
                    }
                );
                if ($data_account === null) {
                    /* Tell the customer what actually happened: a failed refund must not be reported
                       as a successful one. */
                    $isRefunded = $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["code" => 201, "message" => $isRefunded
                        ? __("Nhà cung cấp chưa trả đơn kịp, đã hoàn tiền. Vui lòng thử lại sau ít phút.")
                        : __("Nhà cung cấp chưa trả đơn kịp và hệ thống chưa hoàn được tiền. Vui lòng liên hệ hỗ trợ kèm mã đơn.")]));
                }
                $lines = explode(PHP_EOL, isset($data_account["order"]["data"]) ? (string) $data_account["order"]["data"] : "");
                foreach ($lines as $account) {
                    if ($account != "") {
                        $account = check_string($account);
                        list($uid) = explode("|", $account);
                        $isInsertAPI = $CMSNT->insert("product_sold", ["product_code" => NULL, "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product["user_id"], "uid" => $uid, "account" => $account, "create_gettime" => gettime()]);
                        if ($isInsertAPI) {
                            $isValue++;
                        }
                    }
                }
            }
            if ($supplier["type"] == "API_9") {
                $dataPost = ["type_id" => $product["api_id"], "quantity" => $amount];
                $response = buy_API_9($supplier["domain"], $supplier["api_key"], $dataPost);
                $result = json_decode($response, true);
                if ($result["error"] != 0) {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["code" => 201, "message" => __($result["error"])]));
                }
                $api_trans_id = $result["data"]["buy_id"];
                foreach ($result["data"]["data"] as $account) {
                    $account = check_string($account);
                    list($uid) = explode("|", $account);
                    $isInsertAPI = $CMSNT->insert("product_sold", ["product_code" => NULL, "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product["user_id"], "uid" => $uid, "account" => $account, "create_gettime" => gettime()]);
                    if ($isInsertAPI) {
                        $isValue++;
                    }
                }
            }
            if ($supplier["type"] == "API_14") {
                $response = buy_API_14($supplier["domain"], $supplier["token"], $product["api_id"], $amount);
                $data = json_decode($response, true);
                if ($data["error_code"] == 1) {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["code" => 201, "message" => __($data["message"])]));
                }
                $api_trans_id = $data["order_id"];
                /* This loop had no exit at all: an empty `if (!isset(...)) { }` fell straight back into
                   the next iteration, so every API_14 order through the public API hung after the
                   customer had been charged. Same bounded poll as the web flow, refund on timeout. */
                $data_account = orbit_poll_supplier(
                    function () use ($supplier, $api_trans_id) {
                        return getOrder_API_14($supplier["domain"], $supplier["token"], $api_trans_id);
                    },
                    function ($body) {
                        return isset($body["data"]);
                    }
                );
                if ($data_account === null) {
                    /* Tell the customer what actually happened: a failed refund must not be reported
                       as a successful one. */
                    $isRefunded = $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["code" => 201, "message" => $isRefunded
                        ? __("Nhà cung cấp chưa trả đơn kịp, đã hoàn tiền. Vui lòng thử lại sau ít phút.")
                        : __("Nhà cung cấp chưa trả đơn kịp và hệ thống chưa hoàn được tiền. Vui lòng liên hệ hỗ trợ kèm mã đơn.")]));
                }
                $lines = explode(PHP_EOL, isset($data_account["data"]["data"]) ? (string) $data_account["data"]["data"] : "");
                foreach ($lines as $account) {
                    if ($account != "") {
                        $account = check_string($account);
                        list($uid) = explode("|", $account);
                        $isInsertAPI = $CMSNT->insert("product_sold", ["product_code" => NULL, "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product["user_id"], "uid" => $uid, "account" => $account, "create_gettime" => gettime()]);
                        if ($isInsertAPI) {
                            $isValue++;
                        }
                    }
                }
            }
            if ($supplier["type"] == "API_18") {
                $response = buy_API_18($supplier["domain"], $supplier["api_key"], $product["api_id"], $amount);
                $data = json_decode($response, true);
                if (isset($data["error"])) {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["code" => 201, "message" => __("Số lượng còn lại trong hệ thống không đủ")]));
                }
                $api_trans_id = $data["Data"]["TransId"];
                foreach ($data["Data"]["Emails"] as $account) {
                    $isInsertAPI = $CMSNT->insert("product_sold", ["product_code" => NULL, "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product["user_id"], "uid" => $account["Email"], "account" => $account["Email"] . "|" . $account["Password"], "create_gettime" => gettime()]);
                    if ($isInsertAPI) {
                        $isValue++;
                    }
                }
            }
            if ($supplier["type"] == "API_19") {
                $response = buy_API_19($supplier["domain"], $supplier["api_key"], $product["api_id"], $amount);
                $data = json_decode($response, true);
                if ($data["error_code"] != 200) {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["code" => 201, "message" => __($data["message"])]));
                }
                $api_trans_id = $data["data"]["order_code"];
                foreach ($data["data"]["list_data"] as $account) {
                    $isInsertAPI = $CMSNT->insert("product_sold", ["product_code" => NULL, "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product["user_id"], "uid" => explode("|", $account)[0], "account" => $account, "create_gettime" => gettime()]);
                    if ($isInsertAPI) {
                        $isValue++;
                    }
                }
            }
            if ($supplier["type"] == "API_20") {
                $response = curl_get($supplier["domain"] . "api/buyProducts?kioskToken=" . $supplier["api_key"] . "&userToken=" . $supplier["token"] . "&quantity=" . $amount);
                $data = json_decode($response, true);
                if (!$data["success"]) {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["code" => 201, "message" => __($data["description"])]));
                }
                $api_trans_id = $data["order_id"];
                sleep(1);
                $response = curl_get($supplier["domain"] . "api/getProducts?orderId=" . $api_trans_id . "&userToken=" . $supplier["token"]);
                $result = json_decode($response, true);
                if (!is_array($result)) {
                    $result = [];
                }
                if (!empty($result["success"])) {
                    if (isset($result["data"])) {
                        foreach ($result["data"] as $account) {
                            $account = check_string($account["product"]);
                            list($uid) = explode("|", $account);
                            $isInsertAPI = $CMSNT->insert("product_sold", ["product_code" => NULL, "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product["user_id"], "uid" => $uid, "account" => $account, "create_gettime" => gettime()]);
                            if ($isInsertAPI) {
                                $isValue++;
                            }
                        }
                    } else {
                        /* The supplier accepted the order but returned no accounts: refund, and say
                           whether the refund actually went through instead of a bare error. */
                        $isRefunded = $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                        $CMSNT->insert("logs", ["user_id" => $getUser["id"], "ip" => myip(), "device" => $Mobile_Detect->getUserAgent(), "createdate" => gettime(), "action" => "[Error] " . __("Nhà cung cấp nhận đơn nhưng không trả tài khoản") . " #" . $trans_id]);
                        exit(json_encode(["code" => 201, "message" => $isRefunded
                            ? __("Nhà cung cấp không trả được tài khoản, đã hoàn tiền. Vui lòng thử lại sau ít phút.")
                            : __("Nhà cung cấp không trả được tài khoản và hệ thống chưa hoàn được tiền. Vui lòng liên hệ hỗ trợ kèm mã đơn.")]));
                    }
                } else {
                    $isRefunded = $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    $CMSNT->insert("logs", ["user_id" => $getUser["id"], "ip" => myip(), "device" => $Mobile_Detect->getUserAgent(), "createdate" => gettime(), "action" => "[Error] " . __("Nhà cung cấp báo lỗi sau khi đã trừ tiền") . " #" . $trans_id . ": " . (isset($result["description"]) ? $result["description"] : "")]);
                    exit(json_encode(["code" => 201, "message" => $isRefunded
                        ? __("Nhà cung cấp báo lỗi, đã hoàn tiền. Vui lòng thử lại sau ít phút.")
                        : __("Nhà cung cấp báo lỗi và hệ thống chưa hoàn được tiền. Vui lòng liên hệ hỗ trợ kèm mã đơn.")]));
                }
            }
        }
        if (0 < $isValue) {
            /*
             * A supplier can confirm fewer accounts than were ordered. The buyer was charged for the
             * full quantity, so charge only the delivered share and refund the difference under its
             * own `PARTIAL_` transid: it can never collide with the full `REFUND_` row, and a retried
             * request finds the same row ("duplicate" = already refunded) instead of paying twice.
             */
            if ($product["supplier_id"] != 0 && $isValue < $amount) {
                $payDelivered = orbit_prorated_pay($pay, $amount, $isValue);
                $refund = $pay - $payDelivered;
                if (0 < $refund) {
                    $refundState = $User->RefundCreditsChecked($getUser["id"], $refund, "[Error] " . __("Hoàn tiền phần chưa giao") . " #" . $trans_id, "PARTIAL_" . $trans_id);
                    if ($refundState === "failed") {
                        $CMSNT->insert("logs", ["user_id" => $getUser["id"], "ip" => myip(), "device" => $Mobile_Detect->getUserAgent(), "createdate" => gettime(), "action" => "[Error] " . __("Chưa hoàn được tiền phần chưa giao của đơn hàng") . " #" . $trans_id . " (" . $isValue . "/" . $amount . ")"]);
                        $partialMessage = __("Chỉ giao được") . " " . $isValue . "/" . $amount . " " . __("sản phẩm; phần còn lại chưa hoàn được tiền, vui lòng liên hệ hỗ trợ.");
                    } else {
                        $partialMessage = __("Chỉ giao được") . " " . $isValue . "/" . $amount . " " . __("sản phẩm, đã hoàn tiền phần còn lại.");
                    }
                }
                $pay = $payDelivered;
                $amount = $isValue;
            }
            if ($product["supplier_id"] == 0 && $isValue < $amount) {
                foreach ($CMSNT->get_list(" SELECT * FROM `product_sold` WHERE `trans_id` = '" . $trans_id . "' AND `supplier_id` = 0 ") as $product_sold) {
                    $isInsertStock = $CMSNT->insert("product_stock", ["product_code" => $product_sold["product_code"], "seller" => $product_sold["seller"], "uid" => $product_sold["uid"], "account" => $product_sold["account"], "status" => $product_sold["status"], "create_gettime" => gettime(), "type" => "Back", "time_check_live" => $product_sold["time_check_live"]]);
                    if ($isInsertStock) {
                        $CMSNT->remove("product_sold", " `id` = '" . $product_sold["id"] . "' ");
                    }
                }
                $User->RefundCredits($getUser["id"], $pay, "Hoàn tiền đơn hàng mua tài khoản #" . $trans_id, "REFUND_" . $trans_id);
                exit(json_encode(["code" => 201, "message" => __("Số lượng còn lại trong hệ thống không đủ")]));
            } else {
                $isInsertOrder = $CMSNT->insert("product_order", ["trans_id" => $trans_id, "api_transid" => $api_trans_id, "supplier_id" => $product["supplier_id"], "product_id" => $product["id"], "product_name" => $product["name"], "buyer" => $getUser["id"], "seller" => $product["user_id"], "amount" => $isValue, "money" => $money, "pay" => $pay, "cost" => $product["cost"] * $isValue, "create_gettime" => gettime(), "update_gettime" => gettime(), "trash" => 0, "status_view_order" => $getUser["status_view_order"], "ip" => myip(), "device" => $Mobile_Detect->getUserAgent()]);
                if ($isInsertOrder) {
                    /* The order exists; the reserved coupon use is now permanent. */
                    orbit_coupon_commit();
                    if($CMSNT->site("cong_tien_nguoi_ban") == 1) {
                        /*
                         * "duplicate" means this transaction already paid the seller (a retried request),
                         * which is not an error. "failed" means the buyer was charged and the seller was
                         * not; that must leave a trace for the owner instead of vanishing.
                         */
                        $sellerCredit = $User->AddCreditsChecked($product["user_id"], $pay, __("Doanh thu đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "DOANH_THU_" . $trans_id);
                        if ($sellerCredit === "failed") {
                            $CMSNT->insert("logs", ["user_id" => $product["user_id"], "ip" => myip(), "device" => $Mobile_Detect->getUserAgent(), "createdate" => gettime(), "action" => "[Error] " . __("Chưa cộng được doanh thu đơn hàng") . " #" . $trans_id]);
                        }
                    }
                    $CMSNT->cong("products", "sold", $isValue, " `id` = '" . $product["id"] . "' ");
                    $accounts = [];
                    $file_txt_email = "";
                    foreach ($CMSNT->get_list("SELECT * FROM `product_sold` WHERE `trans_id` = '" . $trans_id . "' ") as $account_sold) {
                        $accounts[] = preg_replace("/\r/", "", $account_sold["account"]);
                        $file_txt_email .= PHP_EOL . htmlspecialchars_decode($account_sold["account"]);
                    }
                    $CMSNT->insert("order_log", ["buyer" => $getUser["id"], "product_name" => $product["name"], "pay" => $pay, "amount" => $isValue, "create_time" => time(), "is_virtual" => 0]);
                    if ($CMSNT->site("email_temp_subject_buy_order") != "") {
                        $content = $CMSNT->site("email_temp_content_buy_order");
                        $content = str_replace("{domain}", $_SERVER["SERVER_NAME"], $content);
                        $content = str_replace("{title}", $CMSNT->site("title"), $content);
                        $content = str_replace("{username}", $getUser["username"], $content);
                        $content = str_replace("{ip}", myip(), $content);
                        $content = str_replace("{device}", $Mobile_Detect->getUserAgent(), $content);
                        $content = str_replace("{time}", gettime(), $content);
                        $content = str_replace("{product}", $product["name"], $content);
                        $content = str_replace("{amount}", format_cash($amount), $content);
                        $content = str_replace("{trans_id}", $trans_id, $content);
                        $content = str_replace("{pay}", format_currency($pay), $content);
                        $subject = $CMSNT->site("email_temp_subject_buy_order");
                        $subject = str_replace("{domain}", $_SERVER["SERVER_NAME"], $subject);
                        $subject = str_replace("{title}", $CMSNT->site("title"), $subject);
                        $subject = str_replace("{username}", $getUser["username"], $subject);
                        $subject = str_replace("{ip}", myip(), $subject);
                        $subject = str_replace("{device}", $Mobile_Detect->getUserAgent(), $subject);
                        $subject = str_replace("{time}", gettime(), $subject);
                        $subject = str_replace("{product}", $product["name"], $subject);
                        $subject = str_replace("{amount}", format_cash($amount), $subject);
                        $subject = str_replace("{trans_id}", $trans_id, $subject);
                        $subject = str_replace("{pay}", format_currency($pay), $subject);
                        $bcc = $CMSNT->site("title");
                        /* The attachment used to be written into the document root: a crash between
                           write and unlink left a file of sold accounts behind a web-served path. */
                        $file_txt_name = rtrim(sys_get_temp_dir(), "/\\") . "/" . $trans_id . ".txt";
                        sendCSM($getUser["email"], $getUser["username"], $subject, $content, $bcc,
                            file_put_contents($file_txt_name, $file_txt_email) !== false ? $file_txt_name : "");
                        if (is_file($file_txt_name)) {
                            unlink($file_txt_name);
                        }
                    }
                    if ($CMSNT->site("noti_buy_product") != "") {
                        $my_text = $CMSNT->site("noti_buy_product");
                        $my_text = str_replace("{domain}", $_SERVER["SERVER_NAME"], $my_text);
                        $my_text = str_replace("{username}", $getUser["username"], $my_text);
                        $my_text = str_replace("{product}", $product["name"], $my_text);
                        $my_text = str_replace("{amount}", format_cash($amount), $my_text);
                        $my_text = str_replace("{trans_id}", $trans_id, $my_text);
                        $my_text = str_replace("{pay}", format_currency($pay), $my_text);
                        $my_text = str_replace("{ip}", myip(), $my_text);
                        $my_text = str_replace("{time}", gettime(), $my_text);
                        sendMessAdmin($my_text);
                    }
                    exit(json_encode(["code" => 200, "message" => __("Tạo đơn hàng thành công!") . ($partialMessage !== "" ? " " . $partialMessage : ""), "order_id" => $trans_id, "data" => implode(PHP_EOL, $accounts)]));
                } else {
                    exit(json_encode(["code" => 500, "message" => "ERROR 1 - " . __("System error")]));
                }
            }
        } else {
            $User->RefundCredits($getUser["id"], $pay, __("[Error 2] Hoàn tiền đơn hàng mua tài khoản") . " #" . $trans_id, "REFUND_" . $trans_id);
            exit(json_encode(["code" => 201, "message" => __("Số lượng còn lại trong hệ thống không đủ")]));
        }
    } else {
        exit(json_encode(["code" => 500, "message" => "ERROR 2 - " . __("Vui lòng thử lại")]));
    }
} elseif ($apiAction == 'get-order-detail') {
    if (empty(check_string($_GET["order_id"]))) {
        exit(json_encode([
            "code" => 201,
            "message" => __("Mã đơn hàng không hợp lệ"),
            "order" => ["status" => 0, "data" => []]
        ]));
    }

    $accounts = [];
   if (!$CMSNT->get_row("SELECT * FROM `product_sold` WHERE `trans_id` = '" . check_string($_GET["order_id"]) . "' AND `buyer` = '" . $getUser['id'] . "'")) {
    exit(json_encode([
        "code" => 201,
        "message" => __("Mã đơn hàng không hợp lệ"),
        "order" => ["status" => 0, "data" => []]
    ]));
}

    
    foreach ($CMSNT->get_list("SELECT * FROM `product_sold` WHERE `trans_id` = '" . check_string($_GET["order_id"]) . "' AND `buyer` = '".$getUser['id']."'") as $account_sold) {
        $accounts[] = preg_replace("/\r/", "", $account_sold["account"]);
    }

    exit(json_encode([
        "code" => 200,
        "status" => 1,
        "message" => __("Lấy đơn hàng thành công!"),
        "order" => ["status" => 1, "data" => implode(PHP_EOL, $accounts)]
    ]));
} else {
    exit(json_encode(["code" => 404, "message" => 'No action']));
}