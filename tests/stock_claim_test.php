<?php
/*
 * Local stock delivery must be exactly once.
 *
 * Both checkout paths selected stock rows, inserted the sold record and then deleted the stock row.
 * Two concurrent buyers both selected the same row before either delete, so both received the same
 * account, and the second delete silently removed nothing. orbit_deliver_stock() claims the row with
 * a DELETE inside a transaction and only inserts the sold record when the DELETE affected a row, so
 * the loser of the race delivers nothing and is refunded by the normal partial-delivery path.
 *
 * This is a unit test of the helper against an in-memory fake, plus source wiring for the two
 * checkout entry points.
 */

define('IN_SITE', true);

class DB
{
    public $stock = array();
    public $sold = array();
    public $failSold = false;
    public $affected = 0;
    public $transactions = 0;

    public function __construct()
    {
        $this->stock[5] = array('id' => 5, 'account' => 'buyer@example.test');
        $this->stock[6] = array('id' => 6, 'account' => 'second@example.test');
    }
    public function transaction($callback)
    {
        ++$this->transactions;
        $stock = $this->stock;
        $sold = $this->sold;
        $result = $callback();
        if (!$result) {
            $this->stock = $stock;
            $this->sold = $sold;
        }
        return $result;
    }
    public function query($sql)
    {
        if (preg_match('/DELETE FROM `(product_stock|product_sold)` WHERE `id` = (\d+)/', $sql, $match)) {
            $id = (int) $match[2];
            if ($match[1] === 'product_stock') {
                if (isset($this->stock[$id])) {
                    unset($this->stock[$id]);
                    $this->affected = 1;
                } else {
                    $this->affected = 0;
                }
            } else {
                $before = count($this->sold);
                $this->sold = array_values(array_filter($this->sold, function ($row) use ($id) {
                    return (int) $row['id'] !== $id;
                }));
                $this->affected = count($this->sold) < $before ? 1 : 0;
            }
            return true;
        }
        return false;
    }
    public function affectedRows()
    {
        return $this->affected;
    }
    public function site($name)
    {
        return $name === 'timezone' ? 'Asia/Ho_Chi_Minh' : '';
    }
    public function get_list($sql) { return array(); }
    public function get_row($sql) { return false; }
    public function num_rows($sql) { return 0; }
    public function insert($table, $data)
    {
        if ($table === 'product_sold') {
            if ($this->failSold) {
                return false;
            }
            $this->sold[] = $data;
            return true;
        }
        if ($table === 'product_stock') {
            $this->stock[] = $data;
            return true;
        }
        return false;
    }
}

if (!function_exists('__')) {
    function __($text) { return $text; }
}
require __DIR__ . '/../libs/helper.php';

$checks = 0;
$failures = 0;
function check_stock($name, $condition, $detail = '')
{
    global $checks, $failures;
    ++$checks;
    if ($condition) {
        echo "PASS  " . $name . "\n";
        return;
    }
    ++$failures;
    echo "FAIL  " . $name . ($detail !== '' ? "  (" . $detail . ")" : '') . "\n";
}

$row = array('id' => 5, 'account' => 'buyer@example.test');
$soldData = array('product_code' => 'DEMO', 'buyer' => 7, 'uid' => $row['account']);

$db = new DB();
check_stock('the first claim delivers the account',
    orbit_deliver_stock($db, $row, $soldData) === true && count($db->sold) === 1);
check_stock('…and removes the stock row',
    !isset($db->stock[5]) && isset($db->stock[6]));

$second = orbit_deliver_stock($db, $row, array('product_code' => 'DEMO', 'buyer' => 8, 'uid' => $row['account']));
check_stock('a second buyer cannot deliver the same stock row',
    $second === false && count($db->sold) === 1, 'sold=' . count($db->sold));
check_stock('the losing claim does not remove the winner\'s sold row or stock',
    isset($db->stock[6]));

$db = new DB();
$db->failSold = true;
check_stock('a failed sold insert reports failure',
    orbit_deliver_stock($db, $row, $soldData) === false);
check_stock('…and the stock row is rolled back into place (the account is not lost)',
    isset($db->stock[5]) && count($db->sold) === 0);

$db = new DB();
$db->sold[] = array('id' => 99, 'product_code' => 'DEMO', 'uid' => 'buyer@example.test');
$stockData = array('product_code' => 'DEMO', 'account' => 'buyer@example.test');
check_stock('a returned account goes back to stock once',
    orbit_return_stock($db, array('id' => 99), $stockData) === true && count($db->stock) === 3);
check_stock('a repeated return cannot duplicate the stock row',
    orbit_return_stock($db, array('id' => 99), $stockData) === false && count($db->stock) === 3);

$db = new DB();
check_stock('a claim with no row id is refused without touching the database',
    orbit_deliver_stock($db, array('account' => 'x'), $soldData) === false && $db->transactions === 0);
check_stock('a return with no row id is refused',
    orbit_return_stock($db, null, $stockData) === false);

/* ---- wiring --------------------------------------------------------------- */
foreach (array('ajaxs/client/product.php', 'api/api.php') as $file) {
    $source = (string) file_get_contents(__DIR__ . '/../' . $file);
    check_stock($file . ' delivers local stock through the claiming helper',
        strpos($source, 'orbit_deliver_stock($CMSNT, $product_stock,') !== false
        && strpos($source, 'remove("product_stock"') === false);
    check_stock($file . ' returns failed local stock through the claiming helper',
        strpos($source, 'orbit_return_stock($CMSNT, $product_sold,') !== false
        && strpos($source, 'remove("product_sold"') === false);
}

echo "\n" . $checks . " checks, " . $failures . " failure(s)\n";
exit($failures === 0 ? 0 : 1);
