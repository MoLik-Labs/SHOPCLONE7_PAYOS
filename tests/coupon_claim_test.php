<?php
/*
 * Coupon reservation — one slot, one user, one order.
 *
 * checkCoupon() only *read* the coupon; the discount was applied, the order was charged, and the use
 * was recorded afterwards. Two concurrent checkouts could both read "valid" and both use a one-slot
 * coupon. orbit_coupon_claim() reserves the slot first and gives it back at shutdown unless the order
 * was written. This suite exercises that contract against a stub DB, plus the wiring in the two
 * purchase paths and the affiliate clawback the refund path was missing.
 */

define('IN_SITE', true);

class DB
{
    public static $coupons = array();
    public static $usedRows = array();
    public static $nextId = 1;
    public static $lockCalls = 0;
    public static $lockDenied = false;
    public $affected = 0;
    public function __construct() {}
    public function connect() { return true; }
    public function dis_connect() { return true; }
    public function site($name) { return $name === 'timezone' ? 'Asia/Ho_Chi_Minh' : ''; }

    public function get_row($sql)
    {
        if (stripos($sql, 'FROM `block_ip`') !== false) {
            return false;
        }
        if (stripos($sql, 'GET_LOCK(') !== false) {
            ++self::$lockCalls;
            return array('ok' => self::$lockDenied ? 0 : 1);
        }
        if (stripos($sql, 'RELEASE_LOCK(') !== false) {
            return array('ok' => 1);
        }
        if (preg_match('/FROM `coupons` WHERE `code` = \'([^\']*)\' AND `min` <= ([0-9.]+) AND `max` >= ([0-9.]+)/', $sql, $m)) {
            foreach (self::$coupons as $coupon) {
                if ($coupon['code'] === stripslashes($m[1]) && (float) $coupon['min'] <= (float) $m[2] && (float) $m[3] <= (float) $coupon['max']) {
                    return $coupon;
                }
            }
            return false;
        }
        if (preg_match('/FROM `coupons` WHERE `id` = (\d+)/', $sql, $m)) {
            foreach (self::$coupons as $coupon) {
                if ((int) $coupon['id'] === (int) $m[1] && $coupon['used'] < $coupon['amount']) {
                    return $coupon;
                }
            }
            return false;
        }
        if (preg_match('/FROM `coupon_used` WHERE `coupon_id` = (\d+) AND `user_id` = (\d+)/', $sql, $m)) {
            foreach (self::$usedRows as $row) {
                if ((int) $row['coupon_id'] === (int) $m[1] && (int) $row['user_id'] === (int) $m[2]) {
                    return $row;
                }
            }
            return false;
        }
        if (preg_match('/FROM `coupon_used` WHERE `trans_id` = \'([^\']*)\'/', $sql, $m)) {
            foreach (self::$usedRows as $row) {
                if ($row['trans_id'] === stripslashes($m[1])) {
                    return $row;
                }
            }
            return false;
        }
        return false;
    }
    public function get_list($sql) { return array(); }
    public function insert($table, $data)
    {
        if ($table === 'coupon_used') {
            $data['id'] = self::$nextId++;
            self::$usedRows[] = $data;
            return true;
        }
        return true;
    }
    public function remove($table, $where)
    {
        if ($table === 'coupon_used' && preg_match('/`id` = (\d+)/', $where, $m)) {
            foreach (self::$usedRows as $key => $row) {
                if ((int) $row['id'] === (int) $m[1]) {
                    unset(self::$usedRows[$key]);
                    self::$usedRows = array_values(self::$usedRows);
                    return true;
                }
            }
        }
        return true;
    }
    public function cong($table, $field, $amount, $where)
    {
        if ($table === 'coupons' && preg_match('/`id` = (\d+)/', $where, $m)) {
            foreach (self::$coupons as $key => $coupon) {
                if ((int) $coupon['id'] === (int) $m[1]) {
                    self::$coupons[$key][$field] += (float) $amount;
                    return true;
                }
            }
        }
        return true;
    }
    public function num_rows($sql) { return 0; }
    public function update($table, $data, $where) { return true; }
    public function query($sql) { $this->affected = 1; return true; }
    public function affectedRows() { return $this->affected; }
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
if (!function_exists('__')) {
    function __($text) { return $text; }
}
require __DIR__ . '/../libs/helper.php';

$failures = 0;
$checks = 0;
function check_coupon($name, $condition)
{
    global $failures, $checks;
    ++$checks;
    if ($condition) {
        echo "PASS  " . $name . "\n";
        return;
    }
    ++$failures;
    echo "FAIL  " . $name . "\n";
}
function coupon_reset()
{
    DB::$coupons = array(
        array('id' => 1, 'code' => 'SAVE10', 'discount' => 10, 'min' => 0, 'max' => 100000000, 'amount' => 2, 'used' => 0, 'product_id' => ''),
        array('id' => 2, 'code' => 'ONLY5', 'discount' => 20, 'min' => 0, 'max' => 100000000, 'amount' => 1, 'used' => 0, 'product_id' => '[7]'),
        array('id' => 3, 'code' => 'GONE', 'discount' => 50, 'min' => 0, 'max' => 100000000, 'amount' => 1, 'used' => 1, 'product_id' => ''),
        array('id' => 4, 'code' => 'TIGHT', 'discount' => 10, 'min' => 500000, 'max' => 900000, 'amount' => 5, 'used' => 0, 'product_id' => ''),
    );
    DB::$usedRows = array();
    DB::$nextId = 1;
    DB::$lockCalls = 0;
    DB::$lockDenied = false;
    unset($GLOBALS['orbit_coupon_claim']);
}
function coupon_source($file) { return file_get_contents(__DIR__ . '/../' . $file); }

/* ---- the reservation ------------------------------------------------------ */
coupon_reset();
$discount = orbit_coupon_claim(7, 'SAVE10', 42, 100000, 'TRANS_1');
check_coupon('a valid coupon returns its discount', $discount == 10000.0);
check_coupon('the reservation writes one coupon_used row', count(DB::$usedRows) === 1);
check_coupon('the reservation increments the used counter', (int) DB::$coupons[0]['used'] === 1);
check_coupon('the reservation is serialised with GET_LOCK', DB::$lockCalls === 1);
check_coupon('a second claim by the same user is refused', orbit_coupon_claim(7, 'SAVE10', 42, 100000, 'TRANS_2') === false);
check_coupon('a refused claim does not add a row', count(DB::$usedRows) === 1);

$discount = orbit_coupon_claim(7, 'SAVE10', 43, 100000, 'TRANS_3');
check_coupon('another user may take the last slot', $discount == 10000.0 && (int) DB::$coupons[0]['used'] === 2);
check_coupon('a coupon with no slots left is refused', orbit_coupon_claim(7, 'SAVE10', 44, 100000, 'TRANS_4') === false);
check_coupon('an exhausted coupon (used == amount) is refused', orbit_coupon_claim(7, 'GONE', 44, 100000, 'TRANS_5') === false);
check_coupon('a coupon outside its min/max is refused', orbit_coupon_claim(7, 'TIGHT', 44, 100000, 'TRANS_6') === false);
check_coupon('a coupon for another product is refused', orbit_coupon_claim(8, 'ONLY5', 44, 100000, 'TRANS_7') === false);
check_coupon('a coupon for the listed product is accepted', orbit_coupon_claim(7, 'ONLY5', 44, 100000, 'TRANS_8') == 20000.0);
check_coupon('an unknown code is refused', orbit_coupon_claim(7, 'NOPE', 45, 100000, 'TRANS_9') === false);
check_coupon('a zero-money order cannot reserve a coupon', orbit_coupon_claim(7, 'SAVE10', 46, 0, 'TRANS_10') === false);

DB::$lockDenied = true;
check_coupon('a coupon is refused when the lock cannot be taken', orbit_coupon_claim(7, 'SAVE10', 47, 100000, 'TRANS_11') === false);
DB::$lockDenied = false;

/* ---- commit vs release ---------------------------------------------------- */
DB::$coupons[0]['amount'] = 100;
DB::$coupons[0]['used'] = 0;
DB::$usedRows = array();
unset($GLOBALS['orbit_coupon_claim']);
orbit_coupon_claim(7, 'SAVE10', 50, 100000, 'TRANS_20');
orbit_coupon_commit();
orbit_coupon_release_uncommitted();
check_coupon('a committed claim survives the shutdown guard', count(DB::$usedRows) === 1 && (int) DB::$coupons[0]['used'] === 1);

DB::$coupons[0]['used'] = 0;
DB::$usedRows = array();
unset($GLOBALS['orbit_coupon_claim']);
orbit_coupon_claim(7, 'SAVE10', 51, 100000, 'TRANS_21');
orbit_coupon_release_uncommitted();
check_coupon('an uncommitted claim is released at shutdown', count(DB::$usedRows) === 0);
check_coupon('releasing gives the slot back', (int) DB::$coupons[0]['used'] === 0);
orbit_coupon_release_uncommitted();
check_coupon('the shutdown guard runs only once', (int) DB::$coupons[0]['used'] === 0);

/* ---- wiring in the purchase paths ----------------------------------------- */
$product = coupon_source('ajaxs/client/product.php');
$api = coupon_source('api/api.php');
check_coupon('web checkout reserves the coupon before charging', substr_count($product, 'orbit_coupon_claim(') === 1);
check_coupon('API checkout reserves the coupon before charging', substr_count($api, 'orbit_coupon_claim(') === 1);
check_coupon('web checkout commits after the order row exists', strpos($product, 'orbit_coupon_commit();') > strpos($product, 'if($isInsertOrder) {'));
check_coupon('API checkout commits after the order row exists', strpos($api, 'orbit_coupon_commit();') > strpos($api, 'if ($isInsertOrder) {'));
check_coupon('only the price quote still calls the read-only checkCoupon (web)', substr_count($product, 'checkCoupon(') === 1);
check_coupon('the old read-only coupon arithmetic is gone from the API', strpos($api, '$discount_coupon = checkCoupon(') === false);
check_coupon('the old post-order coupon write is gone from web checkout', strpos($product, 'cong("coupons", "used", 1') === false);
check_coupon('the old post-order coupon write is gone from the API', strpos($api, 'cong("coupons", "used", 1') === false);
check_coupon('a 100%+ discount cannot make the payable amount negative (web)', substr_count($product, 'if($money < $discount)') === 2);
check_coupon('a 100%+ discount cannot make the payable amount negative (API)', substr_count($api, 'if ($money < $discount)') === 2);

/* ---- affiliate clawback --------------------------------------------------- */
$users = coupon_source('libs/database/users.php');
$adminUpdate = coupon_source('ajaxs/admin/update.php');
check_coupon('the purchase tags its commission with the order id', strpos($product, '") . " #" . $trans_id') !== false || strpos($product, '__("Hoa hồng thành viên " . $getUser["username"]) . " #" . $trans_id') !== false);
check_coupon('refunding an order claws the commission back', strpos($adminUpdate, 'ClawbackCommission(') !== false);
check_coupon('the clawback reads the exact logged amount, not today\'s rate', strpos($adminUpdate, 'LIKE \'%#') !== false);
check_coupon('the clawback reverses ref_price, ref_total_price and ref_amount', substr_count($users, 'parent::cong("users", "ref_price", -$amount') === 1 && substr_count($users, 'parent::cong("users", "ref_total_price", -$amount') === 1 && substr_count($users, 'parent::cong("users", "ref_amount", -$amount') === 1);
check_coupon('the empty affiliate block in the refund path is gone', strpos($adminUpdate, 'if($user["ref_id"] != 0) {') !== false && strpos($adminUpdate, "if(\$user[\"ref_id\"] != 0) {\n        }") === false);

/* ---- the N+1 sites that were fixed ---------------------------------------- */
$apiPage = coupon_source('views/admin/product-api.php');
check_coupon('supplier stats are one grouped query, not three per row', strpos($apiPage, 'GROUP BY supplier_id') !== false && substr_count($apiPage, 'SUM(pay) AS total_pay') === 1);
check_coupon('campaign progress is one grouped query', strpos(coupon_source('views/admin/email-campaigns.php'), 'GROUP BY `camp_id`') !== false);
check_coupon('email log resolves users in one query', strpos(coupon_source('views/admin/email-sending-view.php'), 'FROM `users` WHERE `id` IN (') !== false);
check_coupon('stock import pre-loads existing uids', strpos(coupon_source('views/admin/product-stock.php'), '$existingUids = []') !== false);

echo "\n" . $checks . " checks, " . $failures . " failed\n";
exit($failures === 0 ? 0 : 1);
