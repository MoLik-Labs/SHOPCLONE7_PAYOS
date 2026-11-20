<?php
/*
 * Partial deliveries: what the buyer should actually pay.
 *
 * Suppliers occasionally return fewer accounts than ordered. The checkout used to keep the full
 * payment and record the full quantity as sold, so the buyer paid for accounts that never existed.
 * orbit_prorated_pay() splits the order total over the delivered share; the checkout refunds the
 * rest under a `PARTIAL_<trans_id>` ledger row (which can never collide with the full `REFUND_` row
 * of the failed-order path). These are pure-arithmetic checks on that split.
 */

define('IN_SITE', true);

class DB
{
    public function __construct() {}
    public function connect() { return true; }
    public function dis_connect() { return true; }
    public function site($name) { return $name === 'timezone' ? 'Asia/Ho_Chi_Minh' : ''; }
    public function get_list($sql) { return array(); }
    public function get_row($sql) { return false; }
    public function query($sql) { return true; }
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
if (!function_exists('__')) {
    function __($text) { return $text; }
}
require __DIR__ . '/../libs/helper.php';

$checks = 0;
$failures = 0;
function check_partial($name, $actual, $expected)
{
    global $checks, $failures;
    ++$checks;
    if ($actual === $expected) {
        echo "PASS  " . $name . "\n";
        return;
    }
    ++$failures;
    echo "FAIL  " . $name . "  (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n";
}

/* A full delivery is charged in full, whatever the arithmetic would say. */
check_partial('full delivery charges the full total', orbit_prorated_pay(100000, 10, 10), 100000);
check_partial('over-delivery never raises the price', orbit_prorated_pay(100000, 10, 12), 100000);

/* The delivered share, rounded to whole currency units. */
check_partial('four of ten units charge 40% of the total', orbit_prorated_pay(100000, 10, 4), 40000);
check_partial('one of three rounds to the nearest unit', orbit_prorated_pay(99999, 3, 1), 33333);
check_partial('two of three rounds up when that is nearer', orbit_prorated_pay(100000, 3, 2), 66667);
check_partial('a one-unit order cannot be prorated further', orbit_prorated_pay(50000, 1, 1), 50000);

/* Boundaries. */
check_partial('nothing delivered means no charge', orbit_prorated_pay(100000, 10, 0), 0);
check_partial('a negative delivered count is treated as zero', orbit_prorated_pay(100000, 10, -3), 0);
check_partial('an uncharged order stays free', orbit_prorated_pay(0, 10, 4), 0);
check_partial('a negative total cannot become a charge', orbit_prorated_pay(-500, 10, 4), 0);
check_partial('a zero quantity returns the total untouched', orbit_prorated_pay(7000, 0, 0), 7000);
check_partial('numeric strings from POST are accepted', orbit_prorated_pay("100000", "10", "4"), 40000);

/* The property the checkout relies on: the refund is never negative, never more than paid, and
   the two parts add back to the original payment. */
$ok = true;
$worst = '';
foreach (array(0, 1, 7, 99, 1000, 12345, 99999999) as $pay) {
    for ($ordered = 1; $ordered <= 12; ++$ordered) {
        for ($delivered = 0; $delivered <= $ordered + 1; ++$delivered) {
            $charged = orbit_prorated_pay($pay, $ordered, $delivered);
            $refund = $pay - $charged;
            if ($charged < 0 || $charged > $pay || $refund < 0) {
                $ok = false;
                $worst = "pay=$pay ordered=$ordered delivered=$delivered charged=$charged";
            }
            if ($delivered >= $ordered && $charged !== $pay) {
                $ok = false;
                $worst = "full delivery undercharged: $worst";
            }
            if ($delivered === $ordered && $refund !== 0) {
                $ok = false;
                $worst = "full delivery refunded: $worst";
            }
        }
    }
}
check_partial('charged + refund always equals the payment, in every combination', $ok, true);
if (!$ok) {
    echo "      " . $worst . "\n";
}

/* ------------------------------------------------------------ checkout wiring */

$root = dirname(__DIR__);
foreach (array('ajaxs/client/product.php' => array('status', 'msg'), 'api/api.php' => array('code', 'message')) as $file => $keys) {
    $source = (string) file_get_contents($root . '/' . $file);
    $checks += 7;

    $ok = strpos($source, 'orbit_prorated_pay($pay, $amount, $isValue)') !== false;
    echo ($ok ? 'PASS' : 'FAIL') . "  $file charges the delivered share\n";
    $failures += $ok ? 0 : 1;

    $ok = strpos($source, 'RefundCreditsChecked($getUser["id"], $refund,') !== false
        && strpos($source, '"PARTIAL_" . $trans_id') !== false;
    echo ($ok ? 'PASS' : 'FAIL') . "  $file refunds the difference under its own ledger key\n";
    $failures += $ok ? 0 : 1;

    $ok = strpos($source, 'Chưa hoàn được tiền phần chưa giao của đơn hàng') !== false;
    echo ($ok ? 'PASS' : 'FAIL') . "  $file leaves a trace when the partial refund fails\n";
    $failures += $ok ? 0 : 1;

    $ok = strpos($source, 'if ($refundState === "failed")') !== false
        || strpos($source, 'if($refundState === "failed")') !== false;
    echo ($ok ? 'PASS' : 'FAIL') . "  $file distinguishes a failed refund from a duplicate one\n";
    $failures += $ok ? 0 : 1;

    $ok = strpos($source, '$pay = $payDelivered;') !== false
        && strpos($source, '$amount = $isValue;') !== false;
    echo ($ok ? 'PASS' : 'FAIL') . "  $file records what was delivered, not what was asked for\n";
    $failures += $ok ? 0 : 1;

    $ok = strpos($source, '$product["supplier_id"] == 0 && $isValue < $amount') !== false
        && strpos($source, '$product["supplier_id"] != 0 && $isValue < $amount') !== false;
    echo ($ok ? 'PASS' : 'FAIL') . "  $file keeps the full-refund path for self-stocked shortfalls\n";
    $failures += $ok ? 0 : 1;

    $ok = strpos($source, '"PARTIAL_" . $trans_id') < strpos($source, '$pay = $payDelivered;');
    echo ($ok ? 'PASS' : 'FAIL') . "  $file refunds before it commits the lower price\n";
    $failures += $ok ? 0 : 1;
}

$web = (string) file_get_contents($root . '/ajaxs/client/product.php');
$checks += 3;
$ok = strpos($web, '$CMSNT->cong("products", "sold", $isValue,') !== false;
echo ($ok ? 'PASS' : 'FAIL') . "  the web checkout counts delivered units as sold\n";
$failures += $ok ? 0 : 1;
$ok = strpos($web, '"amount" => $isValue, "money" => $money') !== false;
echo ($ok ? 'PASS' : 'FAIL') . "  the web order row records the delivered quantity\n";
$failures += $ok ? 0 : 1;
$ok = strpos($web, '"cost" => $product["cost"] * $isValue') !== false;
$failures += $ok ? 0 : 1;
echo ($ok ? 'PASS' : 'FAIL') . "  the web order cost uses the delivered quantity\n";

/* A supplier that answers with a non-list body must not raise a warning for every row. */
foreach (array('api/api.php', 'ajaxs/client/product.php') as $file) {
    $source = (string) file_get_contents($root . '/' . $file);
    $checks += 2;
    $ok = substr_count($source, 'foreach ((array) $') >= 8;
    echo ($ok ? 'PASS' : 'FAIL') . "  $file iterates supplier payloads as arrays\n";
    $failures += $ok ? 0 : 1;

    $ok = substr_count($source, 'if ($isValue >= $amount)') >= 8;
    echo ($ok ? 'PASS' : 'FAIL') . "  $file never delivers more accounts than were ordered\n";
    $failures += $ok ? 0 : 1;
}

echo "\n" . $checks . " checks, " . $failures . " failure(s)\n";
exit($failures === 0 ? 0 : 1);
