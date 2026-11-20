<?php
/*
 * Purchase-flow guards — the checks that keep a supplier from pinning a worker or a customer losing money.
 *
 * There is no database here, so this suite tests (a) the polling helper's contract directly, and (b) the
 * source of the two purchase paths for the patterns that caused the real bugs: an unbounded poll, a
 * poll with no exit at all, an attachment written into the document root, and a list endpoint that
 * returned the last page of its own loop.
 */

define('IN_SITE', true);
require __DIR__ . '/../libs/suppliers.php';

$failures = 0;
$checks = 0;
function check_flow($name, $condition, $detail = '')
{
    global $failures, $checks;
    ++$checks;
    if ($condition) {
        echo "PASS  " . $name . "\n";
        return;
    }
    ++$failures;
    echo "FAIL  " . $name . ($detail !== '' ? " — " . $detail : '') . "\n";
}

/* ---- the helper ---------------------------------------------------------- */

$calls = 0;
$result = orbit_poll_supplier(
    function () use (&$calls) {
        ++$calls;
        return json_encode(array('order' => array('status' => 0)));
    },
    function ($body) { return (int) $body['order']['status'] === 1; },
    4,
    0
);
check_flow('a poll that never becomes ready stops after its attempt budget', $result === null && $calls === 4,
    'calls=' . $calls);

$calls = 0;
$result = orbit_poll_supplier(
    function () use (&$calls) {
        ++$calls;
        return json_encode(array('order' => array('status' => $calls >= 3 ? 1 : 0, 'data' => "a|b")));
    },
    function ($body) { return (int) $body['order']['status'] === 1; },
    10,
    0
);
check_flow('a poll returns as soon as the supplier is ready', is_array($result) && $calls === 3 && $result['order']['data'] === 'a|b');

$started = microtime(true);
$result = orbit_poll_supplier(
    function () { return 'not json at all'; },
    function ($body) { return true; },
    3,
    10
);
check_flow('a non-JSON body is not treated as ready', $result === null);
check_flow('the poll respects its delay instead of spinning', (microtime(true) - $started) >= 0.015);

$calls = 0;
$result = orbit_poll_supplier(
    function () use (&$calls) {
        ++$calls;
        return json_encode(array('data' => array('data' => "x|y")));
    },
    function ($body) { return isset($body['data']); },
    1,
    0
);
check_flow('a single-attempt budget still returns a ready answer', is_array($result) && $calls === 1);

/* ---- the two purchase paths --------------------------------------------- */

$root = dirname(__DIR__);
foreach (array('ajaxs/client/product.php', 'api/api.php') as $file) {
    $source = (string) file_get_contents($root . '/' . $file);
    check_flow($file . ' has no unbounded while-true loop', preg_match('/while\s*\(\s*true\s*\)/', $source) !== 1);
    check_flow($file . ' polls suppliers through the bounded helper',
        substr_count($source, 'orbit_poll_supplier(') === 2);
    check_flow($file . ' refunds when a supplier misses its deadline',
        substr_count($source, 'Nhà cung cấp chưa trả đơn kịp, đã hoàn tiền.') === 2);
    check_flow($file . ' does not claim a refund it could not make',
        substr_count($source, 'chưa hoàn được tiền') >= 2);
    check_flow($file . ' writes the order attachment outside the document root',
        strpos($source, 'sys_get_temp_dir()') !== false && strpos($source, 'file_put_contents($file_txt_name') !== false);
}

foreach (array('ajaxs/client/product.php', 'api/api.php') as $file) {
    $source = (string) file_get_contents($root . '/' . $file);
    check_flow($file . ' asks the ledger what happened to the seller credit',
        substr_count($source, 'AddCreditsChecked($product["user_id"]') === 1
        && strpos($source, '$User->AddCredits($product["user_id"]') === false);
    check_flow($file . ' leaves a trace when the seller credit fails',
        strpos($source, 'Chưa cộng được doanh thu đơn hàng') !== false
        && strpos($source, '$sellerCredit === "failed"') !== false);
}

$quote = (string) file_get_contents($root . '/ajaxs/client/product.php');
check_flow('the guest price quote applies the tier discount to what the guest would pay',
    substr_count($quote, '$pay = $money - $discount;') >= 3);
check_flow('the price quote clamps a discount bigger than the order',
    strpos($quote, '/* A quote must never come out negative') !== false
    && strpos($quote, 'if($money < $discount) {') !== false);
check_flow('the checkout record reflects the delivered quantity, not the requested one',
    strpos($quote, '$CMSNT->cong("products", "sold", $isValue,') !== false
    && strpos($quote, '"amount" => $isValue, "money" => $money') !== false
    && strpos($quote, '"cost" => $product["cost"] * $isValue') !== false);

$api = (string) file_get_contents($root . '/api/api.php');
check_flow('the checkout debits only when the balance covers it (web)',
    strpos($quote, 'DebitCreditsChecked($getUser["id"], $pay,') !== false
    && strpos($quote, '$debitState === "insufficient"') !== false
    && strpos($quote, 'RemoveCredits($getUser["id"], $pay,') === false);
check_flow('the checkout debits only when the balance covers it (API)',
    strpos($api, 'DebitCreditsChecked($getUser["id"], $pay,') !== false
    && strpos($api, '$debitState === "insufficient"') !== false
    && strpos($api, 'RemoveCredits($getUser["id"], $pay,') === false);
check_flow('the API_20 path refunds when the supplier returns no accounts',
    strpos($api, 'Nhà cung cấp nhận đơn nhưng không trả tài khoản') !== false
    && strpos($api, 'Nhà cung cấp nhận đơn nhưng không trả tài khoản') > strpos($api, '"REFUND_" . $trans_id'));
check_flow('the API_20 path refunds a supplier error reported after the charge',
    strpos($api, 'Nhà cung cấp báo lỗi sau khi đã trừ tiền') !== false);
check_flow('an unparseable supplier response is treated as an empty array',
    strpos($api, 'if (!is_array($result)) {') !== false);

$adminUpdate = (string) file_get_contents($root . '/ajaxs/admin/update.php');
check_flow('an admin refund is idempotent and closes the order on a retry',
    strpos($adminUpdate, '$refundState !== "failed"') !== false
    && strpos($adminUpdate, '$refundState === "duplicate"') !== false
    && strpos($adminUpdate, 'RemoveCredits($product_order["buyer"]') === false);
check_flow('an admin refund keeps the historical order amounts',
    strpos($adminUpdate, '"refund" => 1, "trash" => 1, "pay" => 0') === false);
check_flow('a failed seller clawback is logged, not swallowed',
    strpos($adminUpdate, 'Chưa thu hồi được doanh thu của đơn hoàn tiền') !== false);
check_flow('an order row that cannot be written is refunded (web)',
    strpos($quote, 'Hoàn tiền do không tạo được đơn hàng') !== false);
check_flow('an order row that cannot be written is refunded (API)',
    strpos($api, 'Hoàn tiền do không tạo được đơn hàng') !== false);

$apiSold = (string) file_get_contents($root . '/api/api.php');
check_flow('the API counts delivered units, not requested ones, as sold',
    strpos($apiSold, '$CMSNT->cong("products", "sold", $isValue,') !== false
    && strpos($apiSold, '"amount" => $isValue, "create_time" => time(), "is_virtual" => 0') !== false);

$api = (string) file_get_contents($root . '/api/api.php');
check_flow('get-services returns every category, not the last one built',
    strpos($api, '"data" => $data_category') !== false);
check_flow('the action API accepts api_key as well as apikey',
    strpos($api, '$_GET["api_key"]') !== false && strpos($api, '$apiKey') !== false);
check_flow('a missing action no longer reaches an undefined index',
    strpos($api, '$apiAction = isset($_GET["action"])') !== false);

$guide = (string) file_get_contents($root . '/views/client/document-api.php');
check_flow('the API guide documents the create-order endpoint that exists',
    strpos($guide, 'api/api.php?apikey=') !== false && strpos($guide, 'action=create-order') !== false);
check_flow('the API guide no longer advertises a missing buy_product route',
    strpos($guide, 'api/buy_product') === false);

/* ---- PayPal confirmation -------------------------------------------------- */
$update = (string) file_get_contents($root . '/ajaxs/client/update.php');
$paypalView = (string) file_get_contents($root . '/views/client/recharge-paypal.php');
$paypalStart = strpos($update, '"confirmPaypal"');
$paypal = substr($update, $paypalStart, strpos($update, 'exit(json_encode(["status" => "error", "msg" => __("Invalid data")]))', $paypalStart) - $paypalStart);
check_flow('a PayPal charge is bound to a nonce issued to this session',
    strpos($paypal, '$_SESSION["paypal_nonces"]') !== false && strpos($paypal, 'hash_equals((int) $getUser["id"] . ":" . $nonce, $paypalCustom)') !== false);
check_flow('the nonce is put into the order the page creates',
    strpos($paypalView, 'custom_id:') !== false && strpos($paypalView, '$paypalNonce') !== false);
check_flow('the payment amount is validated before it is converted to money',
    strpos($paypal, 'if($paypalAmount <= 0 || $paypalAmount > 100000 || $paypalRate <= 0)') !== false);
check_flow('a missing purchase unit is refused instead of read blindly',
    strpos($paypal, 'isset($order->purchase_units[0])') !== false);
check_flow('a failed credit removes the invoice row so a retry can succeed',
    strpos($paypal, '$CMSNT->remove("payment_paypal",') !== false
    && strpos($paypal, 'chưa cộng được tiền') !== false);

echo "\n" . $checks . " checks, " . $failures . " failed\n";
exit($failures > 0 ? 1 : 0);
