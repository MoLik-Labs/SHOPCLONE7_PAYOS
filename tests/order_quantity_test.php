<?php
/*
 * order_quantity() — the only accepted form of a quantity that reaches SQL unquoted.
 *
 *   php tests/order_quantity_test.php
 *
 * Why this test exists: `buyProduct` (ajaxs/client/product.php) and the public API
 * (api/api.php) put the requested amount into `LIMIT <amount>`. check_string() escapes quotes but
 * leaves a payload like "1 PROCEDURE ANALYSE(...)" intact; a loose min/max comparison also lets it
 * through. This pins the strict validator so that can never come back silently.
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
function quantity_is($expected, $actual, $name)
{
    global $checks, $failures;
    ++$checks;
    if ($expected !== $actual) {
        $failures[] = $name . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')';
    }
}

foreach (array('1' => 1, '5' => 5, '007' => 7, '42' => 42) as $raw => $expected) {
    quantity_is($expected, order_quantity($raw), 'accepts ' . $raw);
}
quantity_is(7, order_quantity(7), 'accepts an integer');
quantity_is(5, order_quantity(' 5 '), 'trims surrounding whitespace');
quantity_is(0, order_quantity('0'), 'leaves zero for the caller\'s minimum check');

$refused = array(
    '1 PROCEDURE ANALYSE(EXTRACTVALUE(1,CONCAT(0x3a,VERSION())),1)',
    '1 UNION SELECT password FROM users',
    '1e3', '10.5', '-1', '+1', '0x1A', '1,2', '1 2', '1;DROP TABLE users',
    '9999999999', 'abc', '', '   ', '١٢', '1' . "\n" . '2',
);
foreach ($refused as $raw) {
    quantity_is(false, order_quantity($raw), 'refuses ' . var_export($raw, true));
}
quantity_is(false, order_quantity(null), 'refuses null');
quantity_is(false, order_quantity(true), 'refuses a boolean');
quantity_is(false, order_quantity(5.0), 'refuses a float');
quantity_is(false, order_quantity(array('5')), 'refuses an array');

/* The endpoints must call the validator before the amount can reach SQL. */
$productSource = file_get_contents(__DIR__ . '/../ajaxs/client/product.php');
$apiSource = file_get_contents(__DIR__ . '/../api/api.php');
quantity_is(true, strpos($productSource, 'order_quantity($_REQUEST["amount"])') !== false, 'buyProduct validates');
quantity_is(true, strpos($productSource, 'LIMIT " . $amount') !== false, 'buyProduct still uses the validated amount');
quantity_is(true, strpos($apiSource, 'order_quantity($_GET["amount"])') !== false, 'public API validates');
quantity_is(true, strpos($apiSource, 'LIMIT " . $amount') !== false, 'public API still uses the validated amount');

echo ($failures === array() ? 'order_quantity_test: ' : "FAILURES:\n- " . implode("\n- ", $failures) . "\norder_quantity_test: ")
    . $checks . ' checks, ' . count($failures) . " failures\n";
exit($failures === array() ? 0 : 1);
