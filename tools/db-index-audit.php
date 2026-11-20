<?php
/*
 * Read-only index audit for the queries this shop actually sends.
 *
 *   php tools/db-index-audit.php
 *
 * Prints, per table, the indexes that exist and the ones the hot queries need but MySQL cannot use.
 * It NEVER writes: every missing index is printed as the exact ALTER TABLE statement for the owner
 * to review and run. A missing index is usually a full table scan on a page that renders on every
 * visit (products by status, stock by product_code, orders by buyer, settings by name).
 *
 * Connection: the same DB_* environment the app uses (.env or real environment variables).
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
if (is_file($root . "/vendor/autoload.php")) {
    require_once $root . "/vendor/autoload.php";
    if (class_exists("Dotenv\\Dotenv")) {
        try {
            Dotenv\Dotenv::createImmutable($root)->safeLoad();
        } catch (Throwable $error) {
            /* env vars may already be set by the shell; not fatal for a read-only audit */
        }
    }
}

$host = $_ENV["DB_HOST"] ?? getenv("DB_HOST") ?: "localhost";
$port = (int) ($_ENV["DB_PORT"] ?? getenv("DB_PORT") ?: 3306);
$user = $_ENV["DB_USERNAME"] ?? getenv("DB_USERNAME") ?: "";
$pass = $_ENV["DB_PASSWORD"] ?? getenv("DB_PASSWORD") ?: "";
$name = $_ENV["DB_DATABASE"] ?? getenv("DB_DATABASE") ?: "";
if ($name === "") {
    fwrite(STDERR, "DB_DATABASE is not set (fill .env or export DB_*).\n");
    exit(2);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($host, $user, $pass, $name, $port);
} catch (Throwable $error) {
    fwrite(STDERR, "Cannot connect: " . $error->getMessage() . "\n");
    exit(2);
}

/* Leftmost-prefix match is what MySQL can use: an index on (buyer, id) serves `WHERE buyer = ?`. */
$required = array(
    "settings" => array(array("name")),
    "users" => array(array("token"), array("api_key"), array("username")),
    "products" => array(array("status"), array("category_id", "status"), array("slug")),
    "product_stock" => array(array("product_code")),
    "product_order" => array(array("buyer", "id"), array("trans_id"), array("trash")),
    "product_sold" => array(array("trans_id"), array("trans_id", "buyer"), array("buyer")),
    "posts" => array(array("slug"), array("status", "category_id")),
    "post_category" => array(array("status")),
    "payos_orders" => array(array("order_code"), array("status")),
    "block_ip" => array(array("ip", "banned")),
    "failed_attempts" => array(array("ip_address", "type")),
    "coupons" => array(array("code")),
    "currencies" => array(array("display", "default_currency")),
    "dongtien" => array(array("user_id"), array("transid")),
    "coupon_used" => array(array("coupon_id", "user_id"), array("trans_id")),
    "payment_paypal" => array(array("trans_id"), array("user_id")),
    "payment_bank" => array(array("tid"), array("user_id")),
);

$missing = 0;
$checked = 0;
foreach ($required as $table => $wanted) {
    $result = $db->query("SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $db->real_escape_string($table) . "'
        ORDER BY INDEX_NAME, SEQ_IN_INDEX");
    if (!$result) {
        echo "SKIP  " . $table . " (table not present)\n";
        continue;
    }
    $indexes = array();
    while ($row = $result->fetch_assoc()) {
        $indexes[$row["INDEX_NAME"]][] = $row["COLUMN_NAME"];
    }
    $result->free();
    echo $table . ": " . (count($indexes) ? implode(", ", array_map(function ($name, $cols) {
        return $name . "(" . implode(",", $cols) . ")";
    }, array_keys($indexes), $indexes)) : "(no index)") . "\n";
    foreach ($wanted as $columns) {
        ++$checked;
        $served = false;
        foreach ($indexes as $cols) {
            if (array_slice($cols, 0, count($columns)) === $columns) {
                $served = true;
                break;
            }
        }
        if (!$served) {
            ++$missing;
            echo "  MISSING (" . implode(", ", $columns) . ")\n";
            echo "    ALTER TABLE `" . $table . "` ADD INDEX `idx_" . $table . "_" . implode("_", $columns)
                . "` (" . implode(", ", array_map(function ($c) { return "`" . $c . "`"; }, $columns)) . ");\n";
        }
    }
}
/* Exactly-once crediting, advisory only.
   Every writer of `dongtien` supplies a keyed `transid` (`PAYOS_…`, `GTF_…`, `REFUND_ORDER_…`), but the
   table has a plain index, so a retried request that slips past its in-transaction guard could still
   add a second row. A unique index turns that into a failed insert instead of a second credit — but it
   can only be added when the history has no duplicates, so this block reports them. Nothing is changed. */
echo "\nLedger idempotency (advisory)\n";
try {
    $duplicates = $db->query("SELECT `transid`, COUNT(*) AS `n` FROM `dongtien`
        WHERE `transid` IS NOT NULL GROUP BY `transid` HAVING `n` > 1 ORDER BY `n` DESC LIMIT 20");
    if ($duplicates && $duplicates->num_rows > 0) {
        $duplicateCount = 0;
        while ($row = $duplicates->fetch_assoc()) {
            ++$duplicateCount;
            echo "  DUPLICATE transid " . $row["transid"] . " × " . $row["n"] . "\n";
        }
        echo "  " . $duplicateCount . " duplicated transid value(s). Resolve them before adding a unique index;\n";
        echo "  do NOT add UNIQUE while duplicates exist.\n";
    } else {
        echo "  no duplicated transid — a unique index would hold.\n";
        echo "  ALTER TABLE `dongtien` ADD UNIQUE INDEX `uniq_dongtien_transid` (`transid`);\n";
        echo "  (run in a maintenance window; keep the plain index on `transid` if your MySQL version needs it)\n";
    }
    if ($duplicates) {
        $duplicates->free();
    }
} catch (Throwable $error) {
    echo "  SKIP  dongtien (table not present or not readable)\n";
}

/* A coupon is one-per-member. The claim path serialises with GET_LOCK, but only a unique key makes
   that true for every writer (admin imports, manual SQL). Report duplicates before suggesting it. */
echo "\nCoupon redemption idempotency (advisory)\n";
try {
    $duplicates = $db->query("SELECT `coupon_id`, `user_id`, COUNT(*) AS `n` FROM `coupon_used`
        GROUP BY `coupon_id`, `user_id` HAVING `n` > 1 ORDER BY `n` DESC LIMIT 20");
    if ($duplicates && $duplicates->num_rows > 0) {
        $duplicateCount = 0;
        while ($row = $duplicates->fetch_assoc()) {
            ++$duplicateCount;
            echo "  DUPLICATE coupon " . $row["coupon_id"] . " / user " . $row["user_id"] . " × " . $row["n"] . "\n";
        }
        echo "  " . $duplicateCount . " duplicated coupon/user pair(s). Resolve them before adding a unique index;\n";
        echo "  do NOT add UNIQUE while duplicates exist.\n";
    } else {
        echo "  no duplicated coupon/user pair — a unique index would hold.\n";
        echo "  ALTER TABLE `coupon_used` ADD UNIQUE INDEX `uniq_coupon_used` (`coupon_id`, `user_id`);\n";
    }
    if ($duplicates) {
        $duplicates->free();
    }
} catch (Throwable $error) {
    echo "  SKIP  coupon_used (table not present or not readable)\n";
}

/* Recharge idempotency: the PayPal confirmation is a read-then-insert, so two simultaneous posts can
   both pass the duplicate check. `payment_bank` dedupes on (tid, description) with a SELECT first.
   A unique key on `trans_id` / `tid` makes a second row impossible; report duplicates first. */
echo "\nRecharge idempotency (advisory)\n";
foreach (array(
    "payment_paypal" => array("trans_id", "uniq_payment_paypal_trans_id"),
    "payment_bank" => array("tid", "uniq_payment_bank_tid"),
) as $table => $spec) {
    try {
        $column = $db->real_escape_string($spec[0]);
        $duplicates = $db->query("SELECT `" . $column . "`, COUNT(*) AS `n` FROM `" . $table . "`
            WHERE `" . $column . "` IS NOT NULL AND `" . $column . "` <> ''
            GROUP BY `" . $column . "` HAVING `n` > 1 ORDER BY `n` DESC LIMIT 10");
        if ($duplicates && $duplicates->num_rows > 0) {
            $duplicateCount = 0;
            while ($row = $duplicates->fetch_assoc()) {
                ++$duplicateCount;
                echo "  DUPLICATE " . $table . "." . $spec[0] . " " . $row[$spec[0]] . " x " . $row["n"] . "\n";
            }
            echo "  " . $duplicateCount . " duplicated " . $table . " key(s). Resolve them before adding a unique index;\n";
            echo "  do NOT add UNIQUE while duplicates exist.\n";
        } else {
            echo "  " . $table . ": no duplicated " . $spec[0] . " - a unique index would hold.\n";
            echo "  ALTER TABLE `" . $table . "` ADD UNIQUE INDEX `" . $spec[1] . "` (`" . $spec[0] . "`);\n";
        }
        if ($duplicates) {
            $duplicates->free();
        }
    } catch (Throwable $error) {
        echo "  SKIP  " . $table . " (table not present or not readable)\n";
    }
}

$db->close();
echo "\n" . $checked . " index requirements checked, " . $missing . " missing.\n";
echo $missing === 0 ? "All hot-query indexes are present.\n" : "Review the ALTER statements above; nothing was changed.\n";
exit($missing === 0 ? 0 : 1);
