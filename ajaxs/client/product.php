<?php

define("IN_SITE", true);
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../../libs/db.php";
require_once __DIR__ . "/../../libs/lang.php";
require_once __DIR__ . "/../../libs/helper.php";
require_once __DIR__ . "/../../libs/sendEmail.php";
require_once __DIR__ . "/../../libs/suppliers.php";
require_once __DIR__ . "/../../libs/database/users.php";
$Mobile_Detect = new Mobile_Detect();
if($CMSNT->site("status") != 1) {
    $data = json_encode(["status" => "error", "msg" => __("Hệ thống đang bảo trì!")]);
    exit($data);
}
if(!isset($_REQUEST["action"])) {
    $data = json_encode(["status" => "error", "msg" => __("The Request Not Found")]);
    exit($data);
}
if($_REQUEST["action"] == "buyProduct") {
    if($CMSNT->site("status_demo") != 0) {
        exit(json_encode(["status" => "error", "msg" => __("This function cannot be used because this is a demo site")]));
    }
    if(empty($_REQUEST["api_key"])) {
        exit(json_encode(["status" => "error", "msg" => __("Vui lòng đăng nhập")]));
    }
    if(!($getUser = $CMSNT->get_row("SELECT * FROM `users` WHERE `api_key` = '" . check_string($_REQUEST["api_key"]) . "' "))) {
        exit(json_encode(["status" => "error", "msg" => __("Vui lòng đăng nhập")]));
    }
    if($getUser["banned"] != 0) {
        exit(json_encode(["status" => "error", "msg" => __("Tài khoản của bạn đã bị cấm")]));
    }
    /* Existing completed orders can be looked up before the throttle. This is not a durable
       checkout claim: a request can stop between debit, supplier delivery and order insertion.
       Do not assume retrying the same key safely reconciles every interrupted purchase. */
    $clientOrderKey = isset($_REQUEST["client_order_id"]) && is_string($_REQUEST["client_order_id"])
        ? trim($_REQUEST["client_order_id"]) : "";
    $trans_id = "";
    if($clientOrderKey !== "") {
        if(preg_match('/^[A-Za-z0-9-]{8,64}$/', $clientOrderKey) !== 1) {
            exit(json_encode(["status" => "error", "msg" => __("Mã đơn hàng không hợp lệ")]));
        }
        $trans_id = orbit_order_id($getUser["id"], $clientOrderKey);
        $existingOrder = $CMSNT->get_row("SELECT * FROM `product_order` WHERE `trans_id` = '" . addslashes($trans_id) . "' AND `buyer` = " . (int) $getUser["id"] . " ");
        if($existingOrder) {
            if(isset($_REQUEST["id"]) && (string) $existingOrder["product_id"] !== (string) check_string($_REQUEST["id"])) {
                exit(json_encode(["status" => "error", "msg" => __("Mã đơn hàng đã được dùng cho sản phẩm khác")]));
            }
            if((int) $existingOrder["trash"] === 1) {
                /* The order was refunded or deleted: never reuse its trans_id for a second charge. */
                exit(json_encode(["status" => "error", "msg" => __("Đơn hàng trước đã được huỷ hoặc hoàn tiền, vui lòng mở lại trang để mua lại.")]));
            }
            exit(json_encode(["status" => "success", "msg" => __("Đơn hàng này đã được tạo trước đó."), "trans_id" => $trans_id, "data" => []]));
        }
    }
    /* This response describes this request only. It may be sent ONLY before coupon reservation,
       wallet debit, stock mutation or supplier calls; it is not a global idempotency claim. */
    $rejectBeforeProcessing = function ($message) use ($clientOrderKey) {
        $response = ["status" => "error", "msg" => $message];
        if ($clientOrderKey !== "") {
            $response["outcome"] = "not_started";
            $response["request_id"] = $clientOrderKey;
        }
        exit(json_encode($response));
    };
    if(time() - $getUser["time_request"] < $CMSNT->site("thoi_gian_mua_cach_nhau")) {
        $rejectBeforeProcessing(__("Thao tác quá nhanh, vui lòng chờ"));
    }
    if(empty($_REQUEST["id"])) {
        $rejectBeforeProcessing(__("Sản phẩm không hợp lệ"));
    }
    if(!($product = $CMSNT->get_row("SELECT * FROM `products` WHERE `id` = '" . check_string($_REQUEST["id"]) . "' AND `status` = 1 "))) {
        $rejectBeforeProcessing(__("Sản phẩm không tồn tại trong hệ thống"));
    }
    if(empty($_REQUEST["amount"])) {
        $rejectBeforeProcessing(__("Vui lòng nhập số lượng cần mua"));
    }
    /* Digits only: this value becomes `LIMIT <n>` below, so a non-number must never reach SQL. */
    $amount = order_quantity($_REQUEST["amount"]);
    if($amount === false) {
        $rejectBeforeProcessing(__("Số lượng mua không hợp lệ"));
    }
    /* Same reading of the two columns as the storefront: a minimum below 1 is 1, a maximum below 1
       means "no limit" (the stock check below is the real ceiling). Taken literally, max = 0 made a
       product impossible to buy from the modal even though the product page offered it. */
    $minQuantity = (int) $product["min"];
    if($minQuantity < 1) {
        $minQuantity = 1;
    }
    $maxQuantity = (int) $product["max"];
    if($amount < $minQuantity) {
        $rejectBeforeProcessing(__("Số lượng cần mua tối thiểu là") . " " . format_cash($minQuantity));
    }
    if($maxQuantity > 0 && $amount > $maxQuantity) {
        $rejectBeforeProcessing(__("Số lượng cần mua tối đa là") . " " . format_cash($maxQuantity));
    }
    if($product["supplier_id"] == 0) {
        if($CMSNT->get_row(" SELECT COUNT(id) FROM `product_stock` WHERE `product_code` = '" . $product["code"] . "' ")["COUNT(id)"] < $amount) {
            $rejectBeforeProcessing(__("Số lượng còn lại trong hệ thống không đủ"));
        }
    } elseif(!($supplier = $CMSNT->get_row(" SELECT * FROM `suppliers` WHERE `id` = '" . $product["supplier_id"] . "' AND `status` = 1 "))) {
        $rejectBeforeProcessing(__("Sản phẩm này đang bảo trì, không thể mua hàng vào lúc này"));
    }
    if($trans_id === "") {
        $trans_id = random("QWERTYUOPASDFGHJKZXCVBNM123456789", 4) . uniqid();
    }
    $price = orbit_unit_price($product);
    $money = $amount * $price;
    if($getUser["discount"] == 0) {
        $discount = $money * getDiscount($amount, $product["id"]) / 100;
    } else {
        $discount = $money * $getUser["discount"] / 100;
    }
    /* A total discount over 100% would make $pay negative, and RemoveCredits(-x) adds money. */
    if($money < $discount) {
        $discount = $money;
    }
    $pay = $money - $discount;
    if(!empty($_REQUEST["coupon"])) {
        /* Reserve the coupon before charging. The old read-then-record ordering let two concurrent
           orders both use a one-slot coupon; this fails the checkout instead. */
        $discount_coupon = orbit_coupon_claim($product["id"], $_REQUEST["coupon"], $getUser["id"], $money, $trans_id);
        if($discount_coupon === false) {
            exit(json_encode(["status" => "error", "msg" => __("Mã giảm giá không hợp lệ hoặc đã được sử dụng")]));
        }
        $discount = $discount + $discount_coupon;
        if($money < $discount) {
            $discount = $money;
        }
        $pay = $money - $discount;
    }
    if(getRowRealtime("users", $getUser["id"], "money") < $pay) {
        // A coupon may already have been reserved; its release at shutdown is not guaranteed.
        if (empty($_REQUEST["coupon"])) {
            $rejectBeforeProcessing(__("Số dư không đủ, vui lòng nạp thêm"));
        }
        exit(json_encode(["status" => "error", "msg" => __("Số dư không đủ, vui lòng nạp thêm")]));
    }
    $User = new users();
    /* The debit itself re-checks the balance under the user row lock; a concurrent purchase can no
       longer overdraw the wallet (and trigger the fraud ban) between the check above and this line. */
    $debitState = $User->DebitCreditsChecked($getUser["id"], $pay, __("Thanh toán đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "ORDER_" . $trans_id);
    if($debitState !== "debited") {
        exit(json_encode(["status" => "error", "msg" => $debitState === "insufficient" ? __("Số dư không đủ, vui lòng nạp thêm") : __("Vui lòng thử lại")]));
    }
    if($debitState === "debited") {
        if(getRowRealtime("users", $getUser["id"], "money") < -500) {
            $User->Banned($getUser["id"], __("Gian lận khi mua tài khoản"));
            exit(json_encode(["status" => "error", "msg" => __("Bạn đã bị khoá tài khoản vì gian lận")]));
        }
        $api_trans_id = NULL;
        $isValue = 0;
        $partialMessage = "";
        if($product["supplier_id"] == 0) {
            $order_by = "ORDER BY id ASC";
            if($product["order_by"] == 1) {
                $order_by = "ORDER BY time_check_live DESC";
            } elseif($product["order_by"] == 2) {
                $order_by = "ORDER BY id ASC";
            } elseif($product["order_by"] == 3) {
                $order_by = "ORDER BY id DESC";
            } elseif($product["order_by"] == 4) {
                $order_by = "ORDER BY RAND()";
            }
            foreach ($CMSNT->get_list(" SELECT * FROM `product_stock` WHERE `product_code` = '" . $product["code"] . "' " . $order_by . " LIMIT " . $amount . " ") as $product_stock) {
                if(orbit_deliver_stock($CMSNT, $product_stock, ["type" => $product_stock["type"], "product_code" => $product_stock["product_code"], "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product_stock["seller"], "uid" => $product_stock["uid"], "account" => $product_stock["account"], "create_gettime" => gettime(), "time_check_live" => $product_stock["time_check_live"]])) {
                    $isValue++;
                }
            }
        } else {
            if($supplier["type"] == "SHOPCLONE6") {
                $data = curl_get2($supplier["domain"] . "/api/BResource.php?username=" . $supplier["username"] . "&password=" . $supplier["password"] . "&id=" . $product["api_id"] . "&amount=" . $amount);
                $data = json_decode($data, true);
                if(!is_array($data)) {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["status" => "error", "msg" => __("Nhà cung cấp trả về dữ liệu không hợp lệ, đã hoàn tiền")]));
                }
                if($data["msg"] == "Số dư không đủ, vui lòng nạp thêm") {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["status" => "error", "msg" => __('Tài nguyên không đủ')]));
                }
                if ($data["msg"] == "Số dư API không đủ, vui lòng liên hệ admin") {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["status" => "error", "msg" => __('Tài nguyên không đủ')]));
                }

                if($data["status"] == "error") {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["status" => "error", "msg" => __($data["msg"])]));
                }
                $api_trans_id = $data["data"]["trans_id"];
                foreach ((array) $data["data"]["lists"] as $account) {
                    /* Over-delivery is not a windfall for the buyer: never accept more than ordered. */
                    if ($isValue >= $amount) {
                        break;
                    }
                    $account = check_string($account["account"]);
                    list($uid) = explode("|", $account);
                    $isInsertAPI = $CMSNT->insert("product_sold", ["type" => $supplier["domain"], "product_code" => NULL, "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product["user_id"], "uid" => $uid, "account" => $account, "create_gettime" => gettime()]);
                    if($isInsertAPI) {
                        $isValue++;
                    }
                }
            }
            if($supplier["type"] == "SHOPCLONE7") {
                $data = buy_API_SHOPCLONE7($supplier["domain"], $supplier["coupon"], $supplier["api_key"], $product["api_id"], $amount);
                $data = json_decode($data, true);
                if(!is_array($data)) {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["status" => "error", "msg" => __("Nhà cung cấp trả về dữ liệu không hợp lệ, đã hoàn tiền")]));
                }
                
                if($data["msg"] == "Số dư không đủ, vui lòng nạp thêm") {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["status" => "error", "msg" => __('Tài nguyên không đủ')]));
                }

                if ($data["msg"] == "Số dư API không đủ, vui lòng liên hệ admin") {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["status" => "error", "msg" => __('Tài nguyên không đủ')]));
                }
                if($data["status"] == "error") {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["status" => "error", "msg" => __($data["msg"])]));
                }
                $api_trans_id = $data["trans_id"];
                foreach ((array) $data["data"] as $account) {
                    /* Over-delivery is not a windfall for the buyer: never accept more than ordered. */
                    if ($isValue >= $amount) {
                        break;
                    }
                    $account = check_string($account);
                    list($uid) = explode("|", $account);
                    $isInsertAPI = $CMSNT->insert("product_sold", ["type" => $supplier["domain"], "product_code" => NULL, "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product["user_id"], "uid" => $uid, "account" => $account, "create_gettime" => gettime()]);
                    if($isInsertAPI) {
                        $isValue++;
                    }
                }
            }
            if($supplier["type"] == "API_1") {
                $dataPost = ["api_key" => $supplier["api_key"], "id_product" => $product["api_id"], "quantity" => $amount];
                $response = buy_API_1($supplier["domain"], $dataPost);
                $data = json_decode($response, true);
                if(!$data["status"]) {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["status" => "error", "msg" => __($data["msg"])]));
                }
                $api_trans_id = $data["order_id"];
                $response = order_API_1($supplier["domain"], $supplier["api_key"], $api_trans_id);
                $result = json_decode($response, true);
                foreach ((array) $result["data"] as $account) {
                    /* Over-delivery is not a windfall for the buyer: never accept more than ordered. */
                    if ($isValue >= $amount) {
                        break;
                    }
                    $account = check_string($account["full_info"]);
                    list($uid) = explode("|", $account);
                    $isInsertAPI = $CMSNT->insert("product_sold", ["type" => $supplier["domain"], "product_code" => NULL, "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product["user_id"], "uid" => $uid, "account" => $account, "create_gettime" => gettime()]);
                    if($isInsertAPI) {
                        $isValue++;
                    }
                }
            }
            if($supplier["type"] == "API_4") {
                $response = buy_API_4($supplier["domain"], $supplier["token"], $product["api_id"], $amount);
                $result = json_decode($response, true);
                if(!isset($result["data"])) {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["status" => "error", "msg" => __($result["message"]["messageVNI"])]));
                }
                $api_trans_id = NULL;
                foreach ((array) $result["data"] as $account) {
                    /* Over-delivery is not a windfall for the buyer: never accept more than ordered. */
                    if ($isValue >= $amount) {
                        break;
                    }
                    $account = check_string($account);
                    list($uid) = explode("|", $account);
                    $isInsertAPI = $CMSNT->insert("product_sold", ["type" => $supplier["domain"], "product_code" => NULL, "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product["user_id"], "uid" => $uid, "account" => $account, "create_gettime" => gettime()]);
                    if($isInsertAPI) {
                        $isValue++;
                    }
                }
            }
            if($supplier["type"] == "API_6") {
                $response = curl_get2($supplier["domain"] . "/api.php?apikey=" . $supplier["api_key"] . "&action=create-order&service_id=" . $product["api_id"] . "&amount=" . $amount);
                $data = json_decode($response, true);
                if($data["code"] != 200) {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["status" => "error", "msg" => __($data["message"])]));
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
                if($data_account === null) {
                    /* Tell the customer what actually happened: a failed refund must not be reported
                       as a successful one, or support gets a "you kept my money" ticket with no trail. */
                    $isRefunded = $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["status" => "error", "msg" => $isRefunded
                        ? __("Nhà cung cấp chưa trả đơn kịp, đã hoàn tiền. Vui lòng thử lại sau ít phút.")
                        : __("Nhà cung cấp chưa trả đơn kịp và hệ thống chưa hoàn được tiền. Vui lòng liên hệ hỗ trợ kèm mã đơn.")]));
                }
                $lines = explode(PHP_EOL, isset($data_account["order"]["data"]) ? (string) $data_account["order"]["data"] : "");
                foreach ($lines as $account) {
                    if(empty($account)) {
                    } else {
                        $account = check_string($account);
                        list($uid) = explode("|", $account);
                        if(!isset(explode("|", $account)[1])) {
                        } else {
                            $isInsertAPI = $CMSNT->insert("product_sold", ["type" => $supplier["domain"], "product_code" => NULL, "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product["user_id"], "uid" => $uid, "account" => $account, "create_gettime" => gettime()]);
                            if($isInsertAPI) {
                                $isValue++;
                            }
                        }
                    }
                }
            }
            if($supplier["type"] == "API_9") {
                $dataPost = ["type_id" => $product["api_id"], "quantity" => $amount];
                $response = buy_API_9($supplier["domain"], $supplier["api_key"], $dataPost);
                $result = json_decode($response, true);
                if($result["error"] != 0) {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["status" => "error", "msg" => __($result["error"])]));
                }
                $api_trans_id = $result["data"]["buy_id"];
                foreach ((array) $result["data"]["data"] as $account) {
                    /* Over-delivery is not a windfall for the buyer: never accept more than ordered. */
                    if ($isValue >= $amount) {
                        break;
                    }
                    $account = check_string($account);
                    list($uid) = explode("|", $account);
                    $isInsertAPI = $CMSNT->insert("product_sold", ["type" => $supplier["domain"], "product_code" => NULL, "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product["user_id"], "uid" => $uid, "account" => $account, "create_gettime" => gettime()]);
                    if($isInsertAPI) {
                        $isValue++;
                    }
                }
            }
            if($supplier["type"] == "API_14") {
                $response = buy_API_14($supplier["domain"], $supplier["token"], $product["api_id"], $amount);
                $data = json_decode($response, true);
                if($data["error_code"] == 1) {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["status" => "error", "msg" => __($data["message"])]));
                }
                $api_trans_id = $data["order_id"];
                /* Bounded poll: same budget as API_6 — no worker can be pinned by a slow supplier. */
                $data_account = orbit_poll_supplier(
                    function () use ($supplier, $api_trans_id) {
                        return getOrder_API_14($supplier["domain"], $supplier["token"], $api_trans_id);
                    },
                    function ($body) {
                        return isset($body["data"]);
                    }
                );
                if($data_account === null) {
                    /* Tell the customer what actually happened: a failed refund must not be reported
                       as a successful one. */
                    $isRefunded = $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["status" => "error", "msg" => $isRefunded
                        ? __("Nhà cung cấp chưa trả đơn kịp, đã hoàn tiền. Vui lòng thử lại sau ít phút.")
                        : __("Nhà cung cấp chưa trả đơn kịp và hệ thống chưa hoàn được tiền. Vui lòng liên hệ hỗ trợ kèm mã đơn.")]));
                }
                $lines = explode(PHP_EOL, isset($data_account["data"]["data"]) ? (string) $data_account["data"]["data"] : "");
                foreach ($lines as $account) {
                    if($account == "") {
                    } else {
                        $account = check_string($account);
                        list($uid) = explode("|", $account);
                        $isInsertAPI = $CMSNT->insert("product_sold", ["type" => $supplier["domain"], "product_code" => NULL, "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product["user_id"], "uid" => $uid, "account" => $account, "create_gettime" => gettime()]);
                        if($isInsertAPI) {
                            $isValue++;
                        }
                    }
                }
            }
            if($supplier["type"] == "API_17") {
                $data = buy_API_17($supplier["domain"], $supplier["username"], $supplier["password"], $product["api_id"], $amount);
                $data = json_decode($data, true);
                if(!is_array($data)) {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["status" => "error", "msg" => __("Nhà cung cấp trả về dữ liệu không hợp lệ, đã hoàn tiền")]));
                }
                if($data["status"] == "error") {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["status" => "error", "msg" => __($data["msg"])]));
                }
                $api_trans_id = $data["data"]["trans_id"];
                foreach ((array) $data["data"]["lists"] as $account) {
                    /* Over-delivery is not a windfall for the buyer: never accept more than ordered. */
                    if ($isValue >= $amount) {
                        break;
                    }
                    $account = check_string($account["account"]);
                    list($uid) = explode("|", $account);
                    $isInsertAPI = $CMSNT->insert("product_sold", ["type" => $supplier["domain"], "product_code" => NULL, "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product["user_id"], "uid" => $uid, "account" => $account, "create_gettime" => gettime()]);
                    if($isInsertAPI) {
                        $isValue++;
                    }
                }
            }
            if($supplier["type"] == "API_18") {
                $response = buy_API_18($supplier["domain"], $supplier["api_key"], $product["api_id"], $amount);
                $data = json_decode($response, true);
                if(isset($data["error"])) {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["status" => "error", "msg" => __("Số lượng còn lại trong hệ thống không đủ")]));
                }
                $api_trans_id = $data["Data"]["TransId"];
                foreach ((array) $data["Data"]["Emails"] as $account) {
                    /* Over-delivery is not a windfall for the buyer: never accept more than ordered. */
                    if ($isValue >= $amount) {
                        break;
                    }
                    $isInsertAPI = $CMSNT->insert("product_sold", ["type" => $supplier["domain"], "product_code" => NULL, "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product["user_id"], "uid" => $account["Email"], "account" => $account["Email"] . "|" . $account["Password"] . "|" . $account["RefreshToken"] . "|" . $account["AccessToken"] . "|" . $account["ClientId"], "create_gettime" => gettime()]);
                    if($isInsertAPI) {
                        $isValue++;
                    }
                }
            }
            if($supplier["type"] == "API_19") {
                $response = buy_API_19($supplier["domain"], $supplier["api_key"], $product["api_id"], $amount);
                $data = json_decode($response, true);
                if($data["error_code"] != 200) {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["status" => "error", "msg" => __($data["message"])]));
                }
                $api_trans_id = $data["data"]["order_code"];
                foreach ((array) $data["data"]["list_data"] as $account) {
                    /* Over-delivery is not a windfall for the buyer: never accept more than ordered. */
                    if ($isValue >= $amount) {
                        break;
                    }
                    $isInsertAPI = $CMSNT->insert("product_sold", ["type" => $supplier["domain"], "product_code" => NULL, "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product["user_id"], "uid" => explode("|", $account)[0], "account" => $account, "create_gettime" => gettime()]);
                    if($isInsertAPI) {
                        $isValue++;
                    }
                }
            }
            if($supplier["type"] == "API_20") {
                $response = curl_get($supplier["domain"] . "api/buyProducts?kioskToken=" . $supplier["api_key"] . "&userToken=" . $supplier["token"] . "&quantity=" . $amount);
                $data = json_decode($response, true);
                if(!$data["success"]) {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["status" => "error", "msg" => __($data["description"])]));
                }
                $api_trans_id = $data["order_id"];
                sleep(1);
                $response = curl_get($supplier["domain"] . "api/getProducts?orderId=" . $api_trans_id . "&userToken=" . $supplier["token"]);
                $result = json_decode($response, true);
                if($result["success"]) {
                    if(isset($result["data"])) {
                        foreach ((array) $result["data"] as $account) {
                            /* Over-delivery is not a windfall for the buyer: never accept more than ordered. */
                            if ($isValue >= $amount) {
                                break;
                            }
                            $account = check_string($account["product"]);
                            list($uid) = explode("|", $account);
                            $isInsertAPI = $CMSNT->insert("product_sold", ["type" => $supplier["domain"], "product_code" => NULL, "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product["user_id"], "uid" => $uid, "account" => $account, "create_gettime" => gettime()]);
                            if($isInsertAPI) {
                                $isValue++;
                            }
                        }
                    } else {
                        exit(json_encode(["status" => "error", "msg" => __($data)]));
                    }
                } else {
                    exit(json_encode(["status" => "error", "msg" => __($data["description"])]));
                }
            }
            if($supplier["type"] == "API_21") {
                $response = buy_API_21($supplier["domain"], $supplier["token"], $product["api_id"], $amount);
                $data = json_decode($response, true);
                if(!$data["status"]) {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["status" => "error", "msg" => __($data["message"])]));
                }
                $api_trans_id = NULL;
                foreach ((array) $data["data"] as $account) {
                    /* Over-delivery is not a windfall for the buyer: never accept more than ordered. */
                    if ($isValue >= $amount) {
                        break;
                    }
                    $isInsertAPI = $CMSNT->insert("product_sold", ["type" => $supplier["domain"], "product_code" => NULL, "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product["user_id"], "uid" => explode("|", $account)[0], "account" => $account, "create_gettime" => gettime()]);
                    if($isInsertAPI) {
                        $isValue++;
                    }
                }
            }
            if($supplier["type"] == "API_22") {
                $response = buy_API_22($supplier["domain"], $supplier["token"], $product["api_id"], $amount);
                $data = json_decode($response, true);
                if($data["status"] != "success") {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["status" => "error", "msg" => __($data["message"])]));
                }
                $api_trans_id = NULL;
                foreach ((array) $data["data"] as $account) {
                    /* Over-delivery is not a windfall for the buyer: never accept more than ordered. */
                    if ($isValue >= $amount) {
                        break;
                    }
                    $isInsertAPI = $CMSNT->insert("product_sold", ["type" => $supplier["domain"], "product_code" => NULL, "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product["user_id"], "uid" => $account["email"], "account" => $account["email"] . "|" . $account["password"] . "|" . $account["refresh_token"] . "|" . $account["client_id"], "create_gettime" => gettime()]);
                    if($isInsertAPI) {
                        $isValue++;
                    }
                }
            }
            if($supplier["type"] == "API_23") {
                $response = buy_API_23($supplier["domain"], $supplier["api_key"], $product["api_id"], $amount);
                $data = json_decode($response, true);
                if(isset($data["Code"]) && $data["Code"] == 1) {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["status" => "error", "msg" => __("Số lượng còn lại trong hệ thống không đủ")]));
                }
                $api_trans_id = $data["PurchaseId"];
                foreach ((array) $data["Accounts"] as $account) {
                    /* Over-delivery is not a windfall for the buyer: never accept more than ordered. */
                    if ($isValue >= $amount) {
                        break;
                    }
                    $isInsertAPI = $CMSNT->insert("product_sold", ["type" => $supplier["domain"], "product_code" => NULL, "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product["user_id"], "uid" => $account["Email"], "account" => $account["Email"] . "|" . $account["Password"] . "|" . $account["RefreshToken"] . "|" . $account["ClientId"], "create_gettime" => gettime()]);
                    if($isInsertAPI) {
                        $isValue++;
                    }
                }
            }
            if($supplier["type"] == "API_24") {
                $response = buy_API_24($supplier["domain"], $supplier["api_key"], $product["api_id"], $amount);
                $data = json_decode($response, true);
                if(!isset($data["data"])) {
                    $User->RefundCredits($getUser["id"], $pay, "[Error] " . __("Hoàn tiền đơn hàng mua tài khoản") . " <b>" . $product["name"] . "</b> - #" . $trans_id, "REFUND_" . $trans_id);
                    exit(json_encode(["status" => "error", "msg" => __("Số lượng còn lại trong hệ thống không đủ")]));
                }
                foreach ((array) $data["data"] as $account) {
                    /* Over-delivery is not a windfall for the buyer: never accept more than ordered. */
                    if ($isValue >= $amount) {
                        break;
                    }
                    $api_trans_id = $account["order_id"];
                    $isInsertAPI = $CMSNT->insert("product_sold", ["type" => $supplier["domain"], "product_code" => NULL, "supplier_id" => $product["supplier_id"], "trans_id" => $trans_id, "buyer" => $getUser["id"], "seller" => $product["user_id"], "uid" => $account["email"], "account" => $account["email"] . "|" . $account["password"], "create_gettime" => gettime()]);
                    if($isInsertAPI) {
                        $isValue++;
                    }
                }
            }
        }
        if(0 < $isValue) {
            /*
             * A supplier can confirm fewer accounts than were ordered. The buyer was charged for the
             * full quantity, so charge only the delivered share and refund the difference under its
             * own `PARTIAL_` transid: it can never collide with the full `REFUND_` row, and a retried
             * request finds the same row ("duplicate" = already refunded) instead of paying twice.
             */
            if($product["supplier_id"] != 0 && $isValue < $amount) {
                $payDelivered = orbit_prorated_pay($pay, $amount, $isValue);
                $refund = $pay - $payDelivered;
                if(0 < $refund) {
                    $refundState = $User->RefundCreditsChecked($getUser["id"], $refund, "[Error] " . __("Hoàn tiền phần chưa giao") . " #" . $trans_id, "PARTIAL_" . $trans_id);
                    if($refundState === "failed") {
                        $CMSNT->insert("logs", ["user_id" => $getUser["id"], "ip" => myip(), "device" => $Mobile_Detect->getUserAgent(), "createdate" => gettime(), "action" => "[Error] " . __("Chưa hoàn được tiền phần chưa giao của đơn hàng") . " #" . $trans_id . " (" . $isValue . "/" . $amount . ")"]);
                        $partialMessage = __("Chỉ giao được") . " " . $isValue . "/" . $amount . " " . __("sản phẩm; phần còn lại chưa hoàn được tiền, vui lòng liên hệ hỗ trợ.");
                    } else {
                        $partialMessage = __("Chỉ giao được") . " " . $isValue . "/" . $amount . " " . __("sản phẩm, đã hoàn tiền phần còn lại.");
                    }
                }
                $pay = $payDelivered;
                $amount = $isValue;
            }
            if($product["supplier_id"] == 0 && $isValue < $amount) {
                orbit_release_sold($CMSNT, $trans_id);
                $User->RefundCredits($getUser["id"], $pay, "Hoàn tiền đơn hàng mua tài khoản #" . $trans_id, "REFUND_" . $trans_id);
                exit(json_encode(["status" => "error", "msg" => __("Số lượng còn lại trong hệ thống không đủ")]));
            } else {
                $isInsertOrder = $CMSNT->insert("product_order", ["trans_id" => $trans_id, "api_transid" => $api_trans_id, "supplier_id" => $product["supplier_id"], "product_id" => $product["id"], "product_name" => $product["name"], "buyer" => $getUser["id"], "seller" => $product["user_id"], "amount" => $isValue, "money" => $money, "pay" => $pay, "cost" => $product["cost"] * $isValue, "create_gettime" => gettime(), "update_gettime" => gettime(), "trash" => 0, "status_view_order" => $getUser["status_view_order"], "ip" => myip(), "device" => $Mobile_Detect->getUserAgent()]);
                if($isInsertOrder) {
                    /* The order exists; the reserved coupon use is now permanent. */
                    orbit_coupon_commit();
                    if($pay > 0 && $CMSNT->site("cong_tien_nguoi_ban") == 1) {
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
                    if($CMSNT->site("affiliate_status") == 1 && $getUser["ref_id"] != 0) {
                        $ck = orbit_affiliate_rate($getUser["ref_id"]);
                        $price = $pay * $ck / 100;
                        /* Tag the log row with the order so a refund can claw back the exact amount. */
                        $User->AddCommission($getUser["ref_id"], $getUser["id"], $price, __("Hoa hồng thành viên " . $getUser["username"]) . " #" . $trans_id);
                    }
                    $CMSNT->cong("products", "sold", $isValue, " `id` = '" . $product["id"] . "' ");
                    $accounts = [];
                    $file_txt_email = "";
                    foreach ($CMSNT->get_list("SELECT * FROM `product_sold` WHERE `trans_id` = '" . $trans_id . "' ") as $account_sold) {
                        $accounts[] = preg_replace("/\r/", "", $account_sold["account"]);
                        $file_txt_email .= PHP_EOL . htmlspecialchars_decode($account_sold["account"]);
                    }
                    $CMSNT->update("users", ["time_request" => time()], " `id` = '" . $getUser["id"] . "' ");
                    $CMSNT->insert("order_log", ["buyer" => $getUser["id"], "product_name" => $product["name"], "pay" => $pay, "amount" => $isValue, "create_time" => time(), "is_virtual" => 0]);
                    if($CMSNT->site("email_temp_subject_buy_order") != "") {
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
                    if($CMSNT->site("noti_buy_product") != "") {
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
                    exit(json_encode(["status" => "success", "msg" => __("Tạo đơn hàng thành công!") . ($partialMessage !== "" ? " " . $partialMessage : ""), "trans_id" => $trans_id, "data" => $accounts]));
                } else {
                    /*
                     * The buyer has already paid and the accounts are reserved, but the order row could
                     * not be written. Without this the money was simply gone. Refund under the order's
                     * own key; `failed` leaves an [Error] line the owner can act on, `duplicate` means
                     * a retry already refunded it.
                     */
                    $refundState = $User->RefundCreditsChecked($getUser["id"], $pay, "[Error] " . __("Hoàn tiền do không tạo được đơn hàng") . " #" . $trans_id, "REFUND_" . $trans_id);
                    if ($refundState !== "failed") {
                        /*
                         * The refund went through, so the accounts must not stay reserved in
                         * `product_sold` where nobody can see or resell them. Local stock goes back;
                         * supplier accounts cannot be un-bought, so leave a trace for the owner.
                         */
                        $returned = orbit_release_sold($CMSNT, $trans_id);
                        if ($returned === 0 && $product["supplier_id"] != 0) {
                            $CMSNT->insert("logs", ["user_id" => $getUser["id"], "ip" => myip(), "device" => $Mobile_Detect->getUserAgent(), "createdate" => gettime(), "action" => "[Error] " . __("Đơn hàng đã hoàn tiền nhưng tài khoản nhà cung cấp vẫn còn trong product_sold") . " #" . $trans_id]);
                        }
                    }
                    if ($refundState === "failed") {
                        $CMSNT->insert("logs", ["user_id" => $getUser["id"], "ip" => myip(), "device" => $Mobile_Detect->getUserAgent(), "createdate" => gettime(), "action" => "[Error] " . __("Không tạo được đơn hàng và chưa hoàn được tiền") . " #" . $trans_id . " (" . format_currency($pay) . ")"]);
                    }
                    exit(json_encode(["status" => "error", "msg" => $refundState === "failed"
                        ? __("Không tạo được đơn hàng và chưa hoàn được tiền. Vui lòng liên hệ hỗ trợ kèm mã đơn.") . " #" . $trans_id
                        : __("Không tạo được đơn hàng, đã hoàn tiền. Vui lòng thử lại.")]));
                }
            }
        } else {
            /* The local shortfall was already restored to stock above; report the refund honestly. */
            $refundState = $User->RefundCreditsChecked($getUser["id"], $pay, __("[Error 2] Hoàn tiền đơn hàng mua tài khoản") . " #" . $trans_id, "REFUND_" . $trans_id);
            exit(json_encode(["status" => "error", "msg" => $refundState === "failed"
                ? __("Số lượng còn lại trong hệ thống không đủ và chưa hoàn được tiền. Vui lòng liên hệ hỗ trợ kèm mã đơn.") . " #" . $trans_id
                : __("Số lượng còn lại trong hệ thống không đủ, đã hoàn tiền.")]));
        }
    } else {
        exit(json_encode(["status" => "error", "msg" => "ERROR 2 - " . __("Vui lòng thử lại")]));
    }
} else {
    if($_REQUEST["action"] == "total_payment") {
        if(empty($_REQUEST["id"])) {
            exit(json_encode(["status" => "error", "msg" => __("Sản phẩm không tồn tại trong hệ thống")]));
        }
        $id = check_string($_REQUEST["id"]);
        if(empty($_REQUEST["amount"])) {
            exit(json_encode(["status" => "error", "msg" => __("Vui lòng nhập số lượng cần mua")]));
        }
        $amount = order_quantity($_REQUEST["amount"]);
        if($amount === false) {
            exit(json_encode(["status" => "error", "msg" => __("Số lượng mua không hợp lệ")]));
        }
        if(!($product = $CMSNT->get_row("SELECT * FROM `products` WHERE `id` = '" . $id . "' AND `status` = 1 "))) {
            exit(json_encode(["status" => "error", "msg" => __("Sản phẩm không khả dụng")]));
        }
        $discount = 0;
        $price = orbit_unit_price($product);
        $money = $amount * $price;
        $pay = $money;
        if(!empty($_REQUEST["token"])) {
            $token = check_string($_REQUEST["token"]);
            if($getUser = $CMSNT->get_row(" SELECT * FROM `users` WHERE `token` = '" . $token . "' AND `banned` = 0 ")) {
                if($getUser["discount"] == 0) {
                    $discount = $money * getDiscount($amount, $product["id"]) / 100;
                } else {
                    $discount = $money * $getUser["discount"] / 100;
                }
                $pay = $money - $discount;
                if(!empty($_REQUEST["coupon"])) {
                    $discount_coupon = checkCoupon($product["id"], check_string($_REQUEST["coupon"]), $getUser["id"], $money, $pay);
                    $discount = $discount + $discount_coupon;
                    $pay = $money - $discount;
                }
            }
        } else {
            $discount = $money * getDiscount($amount, $product["id"]) / 100;
            /* The guest quote used to show the discount but keep `pay` at the full price. */
            $pay = $money - $discount;
        }
        /* A quote must never come out negative, whatever percentage the settings or a coupon add up to. */
        if($money < $discount) {
            $discount = $money;
        }
        $pay = $money - $discount;
        exit(json_encode(["status" => "success", "money" => format_currency($money), "discount" => format_currency($discount), "discount_number" => $discount, "pay" => format_currency($pay)]));
    }
    exit(json_encode(["status" => "error", "msg" => __("Request does not exist")]));
}

?>