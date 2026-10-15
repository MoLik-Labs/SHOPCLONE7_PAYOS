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
        return false;
    }

    public function get_list($sql)
    {
        self::$log[] = $sql;
        $f = self::$fixtures;
        if (strpos($sql, "FROM `deposit_log`") !== false) {
            return $f["deposits"];
        }
        if (strpos($sql, "GROUP BY DATE(`create_gettime`)") !== false) {
            return strpos($sql, "buyer") !== false ? $f["mine_by_day"] : $f["by_day"];
        }
        if (strpos($sql, "LEFT JOIN `users`") !== false) {
            return $f["top_customers"];
        }
        if (strpos($sql, "FROM `product_order`") !== false) {
            return strpos($sql, "`product_id`") !== false ? $f["orders"] : $f["mine_orders"];
        }
        return array();
    }

    public function num_rows($sql)
    {
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

        $mineOrders = array();
        foreach ($orders as $order) {
            if ((int) $order["buyer"] === 7) {
                $mineOrders[] = array(
                    "id" => $order["id"],
                    "trans_id" => $order["trans_id"],
                    "product_name" => $order["product_name"],
                    "amount" => $order["amount"],
                    "pay" => $order["pay"],
                    "cost" => $order["cost"],
                    "create_gettime" => $order["create_gettime"],
                    "status_view_order" => 0,
                );
            }
        }
        if (!$mineOrders) {
            $mineOrders[] = array("id" => 9001, "trans_id" => "ORD9001", "product_name" => "Netflix Premium 1 tháng",
                "amount" => 1, "pay" => 120000, "cost" => 84000, "create_gettime" => $today . " 09:15:00",
                "status_view_order" => 0);
        }

        return array(
            "user" => array("id" => 7, "username" => "khachhang7", "email" => "khach7@example.com", "money" => 250000,
                "admin" => 0, "banned" => 0, "token_forgot_password" => null, "token" => "tok"),
            "admin_user" => array("id" => 1, "username" => "orbit", "email" => "ops@example.com", "money" => 0,
                "admin" => 1, "banned" => 0, "token_forgot_password" => null, "token" => "admintok"),
            "current" => array("orders" => $currentOrders, "revenue" => $currentRevenue, "cost" => $currentCost),
            "previous" => array("orders" => (int) round($currentOrders * 0.8), "revenue" => $previousRevenue, "cost" => $previousCost),
            "all_time" => array("orders" => 1840, "revenue" => 412000000, "cost" => 289000000),
            "by_day" => $byDay,
            "orders" => array_reverse($orders),
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
            "new_users" => 23,
            "mine_total" => count($mineOrders),
            "mine_summary" => array(
                "orders" => count($mineOrders),
                "revenue" => array_sum(array_column($mineOrders, "pay")),
                "cost" => array_sum(array_column($mineOrders, "cost")),
            ),
            "mine_by_day" => array_map(function ($row) {
                return array("day" => $row["day"], "orders" => (int) ceil($row["orders"] / 5),
                    "revenue" => (int) round($row["revenue"] / 5), "cost" => (int) round($row["cost"] / 5));
            }, array_slice($byDay, 60 - 30)),
            "mine_orders" => array_slice($mineOrders, 0, 20),
            "previous_from" => $period["previous_from"],
        );
    }
}
