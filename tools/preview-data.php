<?php
/*
 * Sample data + a database stub for the static preview (tools/preview-build.php).
 *
 * This file never touches a real database: it answers the same queries the pages send with rows that
 * look like a small shop's month — so the preview shows realistic charts, rankings and tables.
 * It is loaded only by the preview builder (CLI).
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

class DB
{
    public static $fixtures = array();
    public static $log = array();

    public function site($key)
    {
        $map = array(
            "title" => "Orbit Shop",
            "description" => "Shop bán tài khoản & licence",
            "keywords" => "shop, licence",
            "currency" => "VND",
            "home_page" => "home",
            "hotline" => "0909 123 456",
            "email" => "support@orbit.example",
            "address" => "Quận 1, TP. Hồ Chí Minh",
            "max_show_product_home" => "6",
            "type_show_product" => "BOX",
            "product_hide_outstock" => "0",
            "product_sold_display" => "1",
            "order_by_product_home" => "1",
            "product_photo_display" => "0",
            "api_status" => "0",
            "affiliate_status" => "0",
            "product_selling" => "1",
            "bank_status" => "1",
            "momo_status" => "0",
            "card_status" => "0",
            "crypto_status" => "0",
            "paypal_status" => "0",
            "perfectmoney_status" => "0",
            "toyyibpay_status" => "0",
            "squadco_status" => "0",
            "flutterwave_status" => "0",
        );
        return isset($map[$key]) ? $map[$key] : "";
    }

    public function update($table, $data, $where = "")
    {
        return true;
    }

    public function cong($table, $field, $value, $where = "")
    {
        return true;
    }

    public function tru($table, $field, $value, $where = "")
    {
        return true;
    }

    public function insert($table, $data)
    {
        return true;
    }

    public function remove($table, $where)
    {
        return true;
    }

    public function query($sql)
    {
        return true;
    }

    public function get_row($sql)
    {
        self::$log[] = $sql;
        $f = self::$fixtures;
        if (strpos($sql, "FROM `users` WHERE `token`") !== false) {
            return strpos($sql, "'admintok'") !== false ? $f["admin_user"] : $f["user"];
        }
        if (strpos($sql, "FROM `product_order`") !== false && strpos($sql, "COUNT(`id`) AS total") !== false) {
            return array("total" => $f["mine_total"]);
        }
        if (strpos($sql, "FROM `product_order`") !== false && strpos($sql, "COUNT(") === false
            && preg_match("~`trans_id` = '([^']+)'~", $sql, $match)) {
            foreach ($f["mine_orders"] as $mineOrder) {
                if ($mineOrder["trans_id"] === $match[1]) {
                    return $mineOrder;
                }
            }
            return false;
        }
        if (strpos($sql, "FROM `payment_manual`") !== false) {
            return $f["payment_manual"];
        }
        if (strpos($sql, "FROM `product_sold`") !== false && strpos($sql, "COUNT(") !== false) {
            return array("total" => count($f["order_accounts"]));
        }
        if (strpos($sql, "FROM `product_order`") !== false && strpos($sql, "COUNT(`id`) AS orders") !== false) {
            if (strpos($sql, "1970-01-01") !== false) {
                return $f["all_time"];
            }
            if (strpos($sql, $f["previous_from"]) !== false) {
                return $f["previous"];
            }
            if (strpos($sql, "buyer") !== false) {
                return $f["mine_summary"];
            }
            return $f["current"];
        }
        if (strpos($sql, "FROM `users`") !== false && strpos($sql, "COUNT(`id`) AS total") !== false) {
            return array("total" => $f["user_total"]);
        }
        if (strpos($sql, "FROM `categories`") !== false && strpos($sql, "`slug`") !== false) {
            foreach ($f["store_categories"] as $category) {
                if (strpos($sql, "'" . $category["slug"] . "'") !== false) {
                    return $category;
                }
            }
            return false;
        }
        if (strpos($sql, "FROM `categories`") !== false) {
            if (preg_match("~`id` = '(\d+)'~", $sql, $match)) {
                foreach ($f["store_categories"] as $category) {
                    if ((int) $category["id"] === (int) $match[1]) {
                        return $category;
                    }
                }
            }
            return false;
        }
        if (strpos($sql, "FROM `products`") !== false && strpos($sql, "`slug`") !== false) {
            foreach ($f["store_products"] as $product) {
                if (strpos($sql, "'" . $product["slug"] . "'") !== false) {
                    return $product;
                }
            }
            return false;
        }
        if (strpos($sql, "FROM `products`") !== false && preg_match("~`id` = '(\d+)'~", $sql, $match)) {
            foreach ($f["store_products"] as $product) {
                if ((int) $product["id"] === (int) $match[1]) {
                    return $product;
                }
            }
            return false;
        }
        if (strpos($sql, "FROM `product_stock`") !== false && strpos($sql, "COUNT(") !== false) {
            if (preg_match("~`product_code` = '([^']+)'~", $sql, $match)) {
                foreach ($f["store_stock_rows"] as $row) {
                    if ($row["product_code"] === $match[1]) {
                        return array("COUNT(id)" => $row["c"]);
                    }
                }
            }
            return array("COUNT(id)" => 0);
        }
        if (strpos($sql, "FROM `currencies`") !== false) {
            return array("id" => 1, "name" => "VND", "code" => "VND", "symbol_left" => "",
                "symbol_right" => "đ", "rate" => 1, "decimal_currency" => 0, "seperator" => "dot",
                "default_currency" => 1, "display" => 1);
        }
        return false;
    }

    public function get_list($sql)
    {
        self::$log[] = $sql;
        $f = self::$fixtures;
        if (strpos($sql, "FROM `product_sold`") !== false) {
            return $f["order_accounts"];
        }
        if (strpos($sql, "FROM `deposit_log`") !== false) {
            return $f["deposits"];
        }
        if (strpos($sql, "GROUP BY DATE(`create_gettime`)") !== false) {
            return strpos($sql, "buyer") !== false ? $f["mine_by_day"] : $f["by_day"];
        }
        if (strpos($sql, "LEFT JOIN `users`") !== false) {
            return $f["top_customers"];
        }
        if (strpos($sql, "GROUP BY `product_id`") !== false) {
            /* The dashboard ranks products over the whole window in SQL. Answering this with the
               raw rows (as this stub used to, via the product_id match below) made the preview show
               40 products with 0 orders each and shares far below the real ones. */
            return $f["top_products"];
        }
        if (strpos($sql, "FROM `product_order`") !== false) {
            return strpos($sql, "`product_id`") !== false ? $f["orders"] : $f["mine_orders"];
        }
        if (strpos($sql, "FROM `payment_manual`") !== false) {
            return array();
        }
        if (strpos($sql, "FROM `categories`") !== false) {
            return $f["store_categories"];
        }
        if (strpos($sql, "GROUP BY `category_id`") !== false) {
            return $f["store_counts"];
        }
        if (strpos($sql, "FROM `product_stock`") !== false) {
            return $f["store_stock_rows"];
        }
        if (strpos($sql, "FROM `products`") !== false) {
            return $f["store_products"];
        }
        return array();
    }

    public function num_rows($sql)
    {
        self::$log[] = $sql;
        if (strpos($sql, "FROM `products`") !== false) {
            return self::$fixtures["store_total"];
        }
        return 0;
    }
}

if (!function_exists("preview_fixtures")) {
    /**
     * A believable month for a small account shop: steady sales with a weekend dip, one product
     * dominating, two payment methods, and a growth month-on-month.
     */
    function preview_fixtures()
    {
        $today = date("Y-m-d");
        $period = sales_period(90);

        /* 30 days of daily revenue: a gentle upward trend with a quiet mid-month patch */
        $byDay = array();
        $orders = array();
        $productTotals = array();
        $orderId = 9000;
        $products = array(
            array("id" => 5, "name" => "Netflix Premium 1 tháng", "price" => 120000, "cost" => 84000, "weight" => 4),
            array("id" => 6, "name" => "Spotify Premium 3 tháng", "price" => 180000, "cost" => 126000, "weight" => 3),
            array("id" => 7, "name" => "Canva Pro 1 năm", "price" => 250000, "cost" => 175000, "weight" => 2),
            array("id" => 8, "name" => "Office 365 Family", "price" => 450000, "cost" => 330000, "weight" => 1),
            array("id" => 9, "name" => "YouTube Premium 2 tháng", "price" => 150000, "cost" => 105000, "weight" => 2),
        );
        $buyers = array(7, 9, 12, 18, 23, 31, 42);

        for ($i = 29; $i >= 0; $i--) {
            $day = date("Y-m-d", strtotime("-" . $i . " day"));
            $weekday = (int) date("N", strtotime($day));
            $base = 3 + ($i < 10 ? 2 : 0) + ($weekday >= 6 ? 1 : 0);
            $count = max(1, $base + (($i * 7) % 5 === 0 ? 2 : 0));
            if ($i >= 12 && $i <= 15) {
                $count = max(1, $count - 3); /* a quiet stretch, so the chart has shape */
            }
            $revenue = 0;
            $cost = 0;
            for ($n = 0; $n < $count; $n++) {
                $product = $products[($i + $n) % count($products)];
                $amount = 1 + (($i + $n) % 3 === 0 ? 1 : 0);
                $pay = $product["price"] * $amount;
                $orderCost = $product["cost"] * $amount;
                $revenue += $pay;
                $cost += $orderCost;
                if (!isset($productTotals[$product["id"]])) {
                    $productTotals[$product["id"]] = array(
                        "product_id" => $product["id"], "name" => $product["name"],
                        "orders" => 0, "quantity" => 0, "revenue" => 0, "cost" => 0,
                    );
                }
                $productTotals[$product["id"]]["orders"]++;
                $productTotals[$product["id"]]["quantity"] += $amount;
                $productTotals[$product["id"]]["revenue"] += $pay;
                $productTotals[$product["id"]]["cost"] += $orderCost;
                if ($i < 8 && count($orders) < 40) {
                    $orders[] = array(
                        "id" => ++$orderId,
                        "trans_id" => "ORD" . $orderId,
                        "buyer" => $buyers[($i + $n) % count($buyers)],
                        "product_id" => $product["id"],
                        "product_name" => $product["name"],
                        "amount" => $amount,
                        "pay" => $pay,
                        "cost" => $orderCost,
                        "create_gettime" => $day . " " . str_pad((string) (8 + ($n * 2) % 12), 2, "0", STR_PAD_LEFT) . ":15:00",
                    );
                }
            }
            $byDay[] = array("day" => $day, "orders" => $count, "revenue" => $revenue, "cost" => $cost);
        }

        $currentRevenue = 0;
        $currentCost = 0;
        $currentOrders = 0;
        foreach (array_slice($byDay, 0, 30) as $row) {
            $currentRevenue += $row["revenue"];
            $currentCost += $row["cost"];
            $currentOrders += $row["orders"];
        }
        $previousRevenue = (int) round($currentRevenue * 0.78);
        $previousCost = (int) round($currentCost * 0.8);

        /* Month-wide totals per product, matching what sales_sql_top_products() aggregates. */
        $topProducts = array_values($productTotals);
        usort($topProducts, function ($a, $b) {
            return $b["revenue"] - $a["revenue"];
        });
        $topProducts = array_slice($topProducts, 0, 8);

        $mineOrders = array();
        foreach ($orders as $order) {
            if ((int) $order["buyer"] === 7) {
                $mineOrders[] = array(
                    "id" => $order["id"],
                    "trans_id" => $order["trans_id"],
                    "buyer" => 7,
                    "product_id" => $order["product_id"],
                    "product_name" => $order["product_name"],
                    "amount" => $order["amount"],
                    "pay" => $order["pay"],
                    "cost" => $order["cost"],
                    "create_gettime" => $order["create_gettime"],
                    "trash" => 0,
                    "note" => "",
                    "ip" => "203.0.113.7",
                    "device" => "Chrome · macOS",
                    "status_view_order" => 0,
                );
            }
        }
        if (!$mineOrders) {
            $mineOrders[] = array("id" => 9001, "trans_id" => "ORD9001", "buyer" => 7, "product_id" => 101,
                "product_name" => "Netflix Premium 1 tháng", "amount" => 1, "pay" => 120000, "cost" => 84000,
                "create_gettime" => $today . " 09:15:00", "trash" => 0, "note" => "",
                "ip" => "203.0.113.7", "device" => "Chrome · macOS", "status_view_order" => 0);
        }
        /* One deleted order and one private note, so the preview shows both states honestly. */
        if (count($mineOrders) > 1) {
            $mineOrders[1]["note"] = "Tài khoản dùng cho gia đình";
            $mineOrders[1]["trash"] = 1;
        }

        return array(
            "user" => array("id" => 7, "username" => "khachhang7", "email" => "khach7@example.com", "money" => 250000,
                "total_money" => 900000, "fullname" => "Khách Hàng 7", "phone" => "0909 000 007",
                "telegram_chat_id" => "khachhang7", "api_key" => "preview-api-key",
                "ip" => "203.0.113.7", "device" => "Chrome · macOS",
                "create_date" => "2025-11-02 09:14:00", "update_date" => "2026-10-01 18:02:00",
                "admin" => 0, "banned" => 0, "token_forgot_password" => null, "token" => "tok"),
            "admin_user" => array("id" => 1, "username" => "orbit", "email" => "ops@example.com", "money" => 0,
                "admin" => 1, "banned" => 0, "token_forgot_password" => null, "token" => "admintok"),
            "current" => array("orders" => $currentOrders, "revenue" => $currentRevenue, "cost" => $currentCost),
            "previous" => array("orders" => (int) round($currentOrders * 0.8), "revenue" => $previousRevenue, "cost" => $previousCost),
            "all_time" => array("orders" => 1840, "revenue" => 412000000, "cost" => 289000000),
            "by_day" => $byDay,
            "orders" => array_reverse($orders),
            "top_products" => $topProducts,
            "top_customers" => array(
                array("buyer_id" => 9, "username" => "minhanh", "email" => "m@example.com", "orders" => 14, "revenue" => 3120000),
                array("buyer_id" => 12, "username" => "quanghuy", "email" => "q@example.com", "orders" => 11, "revenue" => 2480000),
                array("buyer_id" => 7, "username" => "khachhang7", "email" => "khach7@example.com", "orders" => 9, "revenue" => 1860000),
                array("buyer_id" => 18, "username" => "thaovy", "email" => "t@example.com", "orders" => 7, "revenue" => 1240000),
                array("buyer_id" => 23, "username" => "ductri", "email" => "d@example.com", "orders" => 5, "revenue" => 820000),
                array("buyer_id" => 31, "username" => "hoangnam", "email" => "h@example.com", "orders" => 4, "revenue" => 610000),
            ),
            "deposits" => array(
                array("method" => "payos", "deposits" => 38, "amount" => 8600000, "received" => 8600000),
                array("method" => "bank", "deposits" => 21, "amount" => 4200000, "received" => 4190000),
                array("method" => "momo", "deposits" => 9, "amount" => 1350000, "received" => 1350000),
                array("method" => "card", "deposits" => 6, "amount" => 720000, "received" => 692000),
            ),
            "user_total" => 486,
            "store_categories" => array(
                array("id" => 1, "name" => "Tài khoản giải trí", "slug" => "tai-khoan-giai-tri",
                    "icon" => "", "parent_id" => 1, "description" => "Tài khoản dùng ngay, giao tự động."),
                array("id" => 2, "name" => "Phần mềm & licence", "slug" => "phan-mem-licence",
                    "icon" => "", "parent_id" => 1, "description" => "Bản quyền phần mềm chính hãng."),
                array("id" => 3, "name" => "Nạp & tiện ích", "slug" => "nap-tien-tien-ich",
                    "icon" => "", "parent_id" => 1, "description" => ""),
            ),
            "store_counts" => array(
                array("category_id" => 1, "c" => 4),
                array("category_id" => 2, "c" => 3),
                array("category_id" => 3, "c" => 1),
            ),
            "store_products" => array(
                array("id" => 101, "name" => "Netflix Premium 1 tháng", "slug" => "netflix-premium-1-thang",
                    "price" => 120000, "discount" => 0, "short_desc" => "Tài khoản riêng, xem trên 1 thiết bị.",
                    "images" => "", "sold" => 128, "supplier_id" => 0, "api_stock" => 0, "code" => "NF1",
                    "category_id" => 1, "status" => 1, "stt" => 40),
                array("id" => 102, "name" => "Spotify Premium 3 tháng", "slug" => "spotify-premium-3-thang",
                    "price" => 180000, "discount" => 10, "short_desc" => "Nâng cấp chính chủ, giữ nguyên playlist.",
                    "images" => "", "sold" => 86, "supplier_id" => 0, "api_stock" => 0, "code" => "SP3",
                    "category_id" => 1, "status" => 1, "stt" => 39),
                array("id" => 103, "name" => "YouTube Premium 2 tháng", "slug" => "youtube-premium-2-thang",
                    "price" => 150000, "discount" => 0, "short_desc" => "Không quảng cáo, phát nền.",
                    "images" => "", "sold" => 64, "supplier_id" => 0, "api_stock" => 0, "code" => "YT2",
                    "category_id" => 1, "status" => 1, "stt" => 38),
                array("id" => 104, "name" => "Canva Pro 1 năm", "slug" => "canva-pro-1-nam",
                    "price" => 250000, "discount" => 0, "short_desc" => "Mở khoá toàn bộ mẫu thiết kế.",
                    "images" => "", "sold" => 41, "supplier_id" => 0, "api_stock" => 0, "code" => "CV1",
                    "category_id" => 1, "status" => 1, "stt" => 37),
                array("id" => 105, "name" => "Office 365 Family 1 năm", "slug" => "office-365-family-1-nam",
                    "price" => 450000, "discount" => 15, "short_desc" => "Word, Excel, PowerPoint và 1 TB OneDrive.",
                    "images" => "", "sold" => 29, "supplier_id" => 0, "api_stock" => 0, "code" => "OF1",
                    "category_id" => 2, "status" => 1, "stt" => 36),
                array("id" => 106, "name" => "Adobe Creative Cloud 1 năm", "slug" => "adobe-cc-1-nam",
                    "price" => 890000, "discount" => 0, "short_desc" => "Bộ ứng dụng thiết kế đầy đủ.",
                    "images" => "", "sold" => 12, "supplier_id" => 0, "api_stock" => 0, "code" => "AD1",
                    "category_id" => 2, "status" => 1, "stt" => 35),
                array("id" => 107, "name" => "Windows 11 Pro", "slug" => "windows-11-pro",
                    "price" => 390000, "discount" => 0, "short_desc" => "Khoá kích hoạt vĩnh viễn.",
                    "images" => "", "sold" => 18, "supplier_id" => 0, "api_stock" => 0, "code" => "WN1",
                    "category_id" => 2, "status" => 1, "stt" => 34),
                array("id" => 108, "name" => "Thẻ nạp 100.000đ", "slug" => "the-nap-100k",
                    "price" => 100000, "discount" => 0, "short_desc" => "Mã thẻ giao ngay sau khi thanh toán.",
                    "images" => "", "sold" => 230, "supplier_id" => 0, "api_stock" => 0, "code" => "TC1",
                    "category_id" => 3, "status" => 1, "stt" => 33),
            ),
            "store_stock_rows" => array(
                array("product_code" => "NF1", "c" => 24), array("product_code" => "SP3", "c" => 9),
                array("product_code" => "YT2", "c" => 15), array("product_code" => "CV1", "c" => 6),
                array("product_code" => "OF1", "c" => 4), array("product_code" => "AD1", "c" => 2),
                array("product_code" => "WN1", "c" => 11), array("product_code" => "TC1", "c" => 100),
            ),
            "store_total" => 8,
            "new_users" => 23,
            "mine_total" => count($mineOrders),
            "payment_manual" => array("id" => 3, "slug" => "bank", "display" => 1,
                "title" => "Chuyển khoản ngân hàng",
                "description" => "Hướng dẫn chuyển khoản và xác nhận số dư",
                "content" => base64_encode(
                    "<p><strong>Ngân hàng:</strong> Vietcombank — Chi nhánh Sài Gòn</p>"
                    . "<p><strong>Số tài khoản:</strong> 0000 1111 2222 3333</p>"
                    . "<p><strong>Chủ tài khoản:</strong> ORBIT OFFICE</p>"
                    . "<p><strong>Nội dung chuyển khoản:</strong> {username} - {id}</p>"
                    . "<p>Ghi đúng nội dung để hệ thống đối chiếu. Sau khi chuyển, gửi ảnh giao dịch cho "
                    . "<a href=\"mailto:{email}\">{email}</a> nếu số dư chưa cập nhật trong 15 phút.</p>"
                )),
            "order_accounts" => array(
                array("id" => 1, "trans_id" => $mineOrders[0]["trans_id"], "uid" => "NF-001",
                    "account" => "preview01@orbit.example|mat-khau-mau-1"),
                array("id" => 2, "trans_id" => $mineOrders[0]["trans_id"], "uid" => "NF-002",
                    "account" => "preview02@orbit.example|mat-khau-mau-2"),
                array("id" => 3, "trans_id" => $mineOrders[0]["trans_id"], "uid" => "NF-003",
                    "account" => "preview03@orbit.example|mat-khau-mau-3"),
            ),
            "mine_summary" => array(
                "orders" => count($mineOrders),
                "revenue" => array_sum(array_column($mineOrders, "pay")),
                "cost" => array_sum(array_column($mineOrders, "cost")),
            ),
            /* $byDay already holds exactly the 30 days that were generated. The old slice asked for
               "the 30 days after index 30" — off the end of the array — so the buyer chart had no
               points and rendered its empty state next to a KPI that showed real money. */
            "mine_by_day" => array_map(function ($row) {
                return array("day" => $row["day"], "orders" => (int) ceil($row["orders"] / 5),
                    "revenue" => (int) round($row["revenue"] / 5), "cost" => (int) round($row["cost"] / 5));
            }, array_slice($byDay, -30)),
            "mine_orders" => array_slice($mineOrders, 0, 20),
            "previous_from" => $period["previous_from"],
        );
    }
}
