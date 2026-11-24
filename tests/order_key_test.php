<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}
/*
 * Checkout correlation keys.
 *
 * The server derives a deterministic trans_id from (buyer, key). These checks pin the derivation and
 * wiring only: the order lookup is not an atomic claim and does not prove retry safety after a crash.
 */

define('IN_SITE', true);
class DB
{
    public function site($name) { return $name === 'timezone' ? 'Asia/Ho_Chi_Minh' : ''; }
    public function get_row($query) { return false; }
    public function get_list($query) { return array(); }
}
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
require __DIR__ . '/../libs/helper.php';

$checks = 0;
$failures = array();
function key_is($expected, $actual, $name)
{
    global $checks, $failures;
    ++$checks;
    if ($expected === $actual) {
        echo "PASS  " . $name . "\n";
        return;
    }
    $failures[] = $name . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')';
    echo "FAIL  " . $name . "\n";
}

$first = orbit_order_id(7, 'a1b2c3d4-e5f6');
key_is($first, orbit_order_id(7, 'a1b2c3d4-e5f6'), 'the same buyer and key derive the same order id');
key_is(true, $first !== orbit_order_id(8, 'a1b2c3d4-e5f6'), 'another buyer cannot collide with the same key');
key_is(true, $first !== orbit_order_id(7, 'a1b2c3d4-e5f7'), 'another key derives another order id');
key_is(true, strpos($first, 'W') === 0 && strlen($first) === 31, 'the id is a fixed-width W-prefixed token');
key_is(true, preg_match('/^[A-Za-z0-9_-]{4,64}$/', $first) === 1, 'the id is accepted by the order lookup');

$product = (string) file_get_contents(__DIR__ . '/../ajaxs/client/product.php');
$api = (string) file_get_contents(__DIR__ . '/../api/api.php');
$js = (string) file_get_contents(__DIR__ . '/../public/client/orbit/store.js');

key_is(true, strpos($product, '$trans_id = orbit_order_id($getUser["id"], $clientOrderKey);') !== false,
    'the storefront derives the order id from the key');
key_is(true, strpos($product, '$existingOrder = $CMSNT->get_row("SELECT * FROM `product_order` WHERE `trans_id` = ') !== false,
    'the storefront looks for the order it already created');
key_is(true, strpos($api, '$trans_id = orbit_order_id($getUser["id"], $clientOrderKey);') !== false,
    'the public API derives the order id from the key');
key_is(true, strpos($api, '$existingOrder = $CMSNT->get_row("SELECT * FROM `product_order` WHERE `trans_id` = ') !== false,
    'the public API looks for a previously completed order');
key_is(true, strpos($product, 'if($trans_id === "") {') !== false && strpos($api, 'if ($trans_id === "") {') !== false,
    'a request without a key still gets a random id');
key_is(true, strpos($js, 'client_order_id: orderKey') !== false, 'the buy panel sends its key');
key_is(1, substr_count($js, 'orderKey = newOrderKey();'), 'the panel generates one key for its attempt');
key_is(true, strpos($product, '(int) $existingOrder["trash"] === 1') !== false, 'a cancelled order cannot be revived by replaying its key');
key_is(true, strpos($api, '(int) $existingOrder["trash"] === 1') !== false, 'the API refuses to reuse a cancelled order key');
key_is(true, strpos($product, 'Mã đơn hàng đã được dùng cho sản phẩm khác') !== false,
    'a key cannot be replayed against a different product');

echo "\n" . $checks . " checks, " . count($failures) . " failure(s)\n";
exit($failures ? 1 : 0);
