<?php
/*
 * Admin list pages resolve usernames in one query, not one per row.
 *
 * 18 admin tables called getRowRealtime("users", $row["user_id"], "username") inside their loop — an
 * admin page showing 100 orders paid 100 extra round trips. They now use orbit_user_names(), which
 * collects the ids first and issues one `IN (…)` query per 500. This suite checks the helper's
 * behaviour and that no admin view still looks a user up per row.
 */

define('IN_SITE', true);

class DB
{
    public static $userRows = array();
    public static $log = array();
    public function __construct() {}
    public function connect() { return true; }
    public function dis_connect() { return true; }
    public function site($name) { return $name === 'timezone' ? 'Asia/Ho_Chi_Minh' : ''; }
    public function get_row($sql) { return false; }
    public static $tables = array(
        'categories' => array(1 => 'Điện thoại', 2 => 'Laptop'),
        'products' => array(5 => 'Netflix 1 tháng'),
        'suppliers' => array(9 => 'api.example.com'),
        'post_category' => array(4 => 'Tin tức'),
    );
    /* code => count, for orbit_counts_by_code(). */
    public static $codeCounts = array('P1' => 4, 'P2' => 0, "P'3" => 2);
    public function get_list($sql)
    {
        self::$log[] = $sql;
        if (preg_match('/FROM `(product_stock|product_die)` WHERE `product_code` IN \((.*)\) GROUP BY/', $sql, $m)) {
            $rows = array();
            if (preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $m[2], $codes)) {
                foreach ($codes[1] as $code) {
                    $code = str_replace(array("\\'", "\\\\"), array("'", "\\"), $code);
                    if (isset(self::$codeCounts[$code])) {
                        $rows[] = array('product_code' => $code, 'c' => self::$codeCounts[$code]);
                    }
                }
            }
            return $rows;
        }
        if (preg_match('/FROM `([a-z_]+)` WHERE `id` IN \(([0-9,]+)\)/', $sql, $m)) {
            $table = $m[1];
            $wanted = array_map('intval', explode(',', $m[2]));
            $rows = array();
            if ($table === 'users') {
                foreach (self::$userRows as $row) {
                    if (in_array((int) $row['id'], $wanted, true)) {
                        $rows[] = $row;
                    }
                }
                return $rows;
            }
            if (isset(self::$tables[$table])) {
                foreach (self::$tables[$table] as $id => $name) {
                    if (in_array((int) $id, $wanted, true)) {
                        $rows[] = array('id' => $id, 'name' => $name, 'domain' => $name);
                    }
                }
            }
            return $rows;
        }
        return array();
    }

    public function query($sql) { self::$log[] = $sql; return true; }
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
if (!function_exists('__')) {
    function __($text) { return $text; }
}
require __DIR__ . '/../libs/helper.php';

$failures = 0;
$checks = 0;
function check_lookup($name, $condition)
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

DB::$userRows = array(
    array('id' => 3, 'username' => 'an'),
    array('id' => 7, 'username' => 'binh'),
    array('id' => 9, 'username' => 'chi'),
);

/* ---- the helper ----------------------------------------------------------- */
DB::$log = array();
$rows = array(
    array('id' => 1, 'buyer' => 3),
    array('id' => 2, 'buyer' => 7),
    array('id' => 3, 'buyer' => 3),
    array('id' => 4, 'buyer' => 0),
    array('id' => 5, 'buyer' => 404),
);
$names = orbit_user_names($rows, 'buyer');
check_lookup('the map holds exactly the users that exist', count($names) === 2);
check_lookup('the map resolves the right name', $names[3] === 'an' && $names[7] === 'binh');
check_lookup('all rows came from a single query', count(DB::$log) === 1);
check_lookup('the query uses an IN list of the distinct ids', strpos(DB::$log[0], 'IN (3,7,404)') !== false);
check_lookup('an unknown id is simply absent', !isset($names[404]));
check_lookup('a zero id is not looked up', strpos(DB::$log[0], 'IN (3,7,404)') !== false);
check_lookup('orbit_user_name() prints a known name', orbit_user_name($names, 7) === 'binh');
check_lookup('orbit_user_name() prints an empty cell for an unknown id', orbit_user_name($names, 404) === '');

DB::$log = array();
check_lookup('an empty list makes no query', orbit_user_names(array(), 'user_id') === array() && DB::$log === array());
check_lookup('a non-array argument makes no query', orbit_user_names(null, 'user_id') === array() && DB::$log === array());
check_lookup('a list with no usable ids makes no query', orbit_user_names(array(array('id' => 1)), 'buyer') === array() && DB::$log === array());

DB::$log = array();
$big = array();
for ($i = 1; $i <= 1200; ++$i) {
    $big[] = array('user_id' => $i);
}
orbit_user_names($big, 'user_id');
check_lookup('large pages are chunked, not sent as one giant IN', count(DB::$log) === 3);

/* ---- the generic lookup helper -------------------------------------------- */

DB::$log = array();
$categoryRows = array(
    array('id' => 1, 'category_id' => 1),
    array('id' => 2, 'category_id' => 2),
    array('id' => 3, 'category_id' => 1),
    array('id' => 4, 'category_id' => 0),
);
$categories = orbit_lookup_names('categories', $categoryRows, 'category_id');
check_lookup('a category map resolves every distinct id', $categories[1] === 'Điện thoại' && $categories[2] === 'Laptop' && count($categories) === 2);
check_lookup('the category map costs one query', count(DB::$log) === 1);
check_lookup('orbit_lookup_name() falls back to an empty cell', orbit_lookup_name($categories, 99) === '');
check_lookup('a zero foreign key is not queried', strpos(DB::$log[0], 'IN (1,2)') !== false);

DB::$log = array();
check_lookup('an unknown table is refused', orbit_lookup_names('settings', $categoryRows, 'category_id') === array() && DB::$log === array());
check_lookup('an unknown column is refused', orbit_lookup_names('categories', $categoryRows, 'category_id', 'value') === array() && DB::$log === array());
check_lookup('supplier domains resolve through the same helper',
    orbit_lookup_names('suppliers', array(array('supplier_id' => 9)), 'supplier_id', 'domain') === array(9 => 'api.example.com'));


/* ---- no admin page still looks a user up per row -------------------------- */
$offenders = array();
$files = glob(__DIR__ . '/../views/admin/*.php');
foreach ($files as $file) {
    $lines = file($file);
    $inLoop = false;
    $depthAfterLoop = 0;
    foreach ($lines as $index => $line) {
        if (preg_match('/foreach\s*\(/', $line)) {
            $inLoop = true;
        }
        if ($inLoop && strpos($line, 'getRowRealtime(') !== false) {
            $offenders[] = basename($file) . ':' . ($index + 1);
        }
        /* A crude "end of loop" signal: the closing brace at the loop's own indent. Good enough for
           a single-line test; a false positive only sends a human to look. */
        if ($inLoop && preg_match('/^\s{0,8}\}\s*$/', $line)) {
            $inLoop = false;
        }
    }
}
check_lookup('no admin view looks a username up inside a loop' . ($offenders ? ' (' . implode(', ', $offenders) . ')' : ''), $offenders === array());

$prefetched = 0;
foreach ($files as $file) {
    if (strpos(file_get_contents($file), 'orbit_user_names(') !== false) {
        ++$prefetched;
    }
}
check_lookup('18 admin tables prefetch their usernames', $prefetched === 19 || $prefetched === 18);

/* ---- stock and dead-stock counts ---------------------------------------- */
DB::$log = array();
$counts = orbit_counts_by_code('product_stock', array('P1', 'P2', 'P1', ''));
check_lookup('the count helper returns one entry per matching code',
    $counts === array('P1' => 4, 'P2' => 0));
check_lookup('the count helper de-duplicates codes and skips blanks',
    strpos(DB::$log[0], "'P1','P2'") !== false || strpos(DB::$log[0], "'P1', 'P2'") !== false);
check_lookup('the count helper quotes a code containing a quote',
    orbit_counts_by_code('product_stock', array("P'3")) === array("P'3" => 2));

DB::$log = array();
check_lookup('a table outside the whitelist is refused without a query',
    orbit_counts_by_code('users', array('x')) === array() && count(DB::$log) === 0);
DB::$log = array();
check_lookup('no codes means no query', orbit_counts_by_code('product_die', array()) === array() && count(DB::$log) === 0);

DB::$log = array();
$many = array();
for ($i = 0; $i < 501; ++$i) {
    $many[] = 'C' . $i;
}
orbit_counts_by_code('product_die', $many);
check_lookup('501 codes are split into two bounded queries', count(DB::$log) === 2);

$adminProducts = (string) file_get_contents(__DIR__ . '/../views/admin/products.php');
check_lookup('the product list batches its live stock counts',
    strpos($adminProducts, 'orbit_counts_by_code("product_stock"') !== false
    && strpos($adminProducts, 'getStock($product["code"])') === false);
check_lookup('the product list batches its dead-stock counts',
    strpos($adminProducts, 'orbit_counts_by_code("product_die"') !== false
    && strpos($adminProducts, 'COUNT(id) FROM product_die') === false);

$apiSuppliers = (string) file_get_contents(__DIR__ . '/../views/admin/product-api.php');
check_lookup('the supplier list counts categories and products in grouped queries',
    substr_count($apiSuppliers, 'GROUP BY') >= 3
    && strpos($apiSuppliers, "COUNT(id) FROM `categories` WHERE `supplier_id`") === false
    && strpos($apiSuppliers, "COUNT(id) FROM `products` WHERE `supplier_id`") === false);

$task = (string) file_get_contents(__DIR__ . '/../cron/task.php');
check_lookup('the warehouse automation resolves its product codes in one lookup',
    strpos($task, 'orbit_lookup_names("products", $taskProductRows, "id", "code")') !== false
    && strpos($task, "SELECT * FROM `products` WHERE `id` = '") === false);

echo "\n" . $checks . " checks, " . $failures . " failed\n";
exit($failures === 0 ? 0 : 1);
