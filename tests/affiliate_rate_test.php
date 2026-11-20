<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}
/*
 * Affiliate commission rate — one query per referrer, not two per transaction.
 *
 * Every purchase and every recharge computed the rate like this:
 *
 *     $ck = $CMSNT->site("affiliate_ck");
 *     if (getRowRealtime("users", $getUser["ref_id"], "ref_ck") != 0) {
 *         $ck = getRowRealtime("users", $getUser["ref_id"], "ref_ck");
 *     }
 *
 * Two SELECTs per credited sale (the second repeated the first), and cron/bank.php repeated the
 * pair eight times in eight branches. orbit_affiliate_rate() reads the member row once, keeps the
 * shop-wide rate unless the member has a non-zero override, and remembers the answer per request.
 */

define('IN_SITE', true);

class DB
{
    public static $queries = array();
    public static $rates = array(3 => 25, 7 => 0);
    public static $settings = array('affiliate_ck' => 10);
    public static $explode = false;

    public function __construct() {}
    public function connect() { return true; }
    public function dis_connect() { return true; }
    public function site($name)
    {
        return isset(self::$settings[$name]) ? self::$settings[$name] : '';
    }
    public function get_row($sql)
    {
        self::$queries[] = $sql;
        if (preg_match('/FROM `users` WHERE `id` = ([0-9]+)/', $sql, $m)) {
            $id = (int) $m[1];
            return array_key_exists($id, self::$rates) ? array('ref_ck' => self::$rates[$id]) : false;
        }
        return false;
    }
    public function get_list($sql) { self::$queries[] = $sql; return array(); }
    public function query($sql) { self::$queries[] = $sql; return true; }
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
if (!function_exists('__')) {
    function __($text) { return $text; }
}
require __DIR__ . '/../libs/helper.php';

$failures = 0;
$checks = 0;
function check_rate($name, $condition, $detail = '')
{
    global $failures, $checks;
    ++$checks;
    if ($condition) {
        echo "PASS  " . $name . "\n";
        return;
    }
    ++$failures;
    echo "FAIL  " . $name . ($detail !== '' ? "  (" . $detail . ")" : '') . "\n";
}

/* helper.php issues one block_ip lookup when it is included; measure deltas, not totals. */
$base = count(DB::$queries);

check_rate('a member without an override uses the shop-wide rate', orbit_affiliate_rate(7) === 10.0);
check_rate('that cost exactly one query', count(DB::$queries) - $base === 1, (string) (count(DB::$queries) - $base));

$afterFirst = count(DB::$queries);
check_rate('the same referrer is not queried again', orbit_affiliate_rate(7) === 10.0 && count(DB::$queries) === $afterFirst);

check_rate('a member with an override gets their own rate', orbit_affiliate_rate(3) === 25.0);
$afterSecond = count(DB::$queries);
check_rate('the override cost one query', $afterSecond - $afterFirst === 1, (string) ($afterSecond - $afterFirst));
check_rate('the same member is answered from memory afterwards', orbit_affiliate_rate(3) === 25.0 && count(DB::$queries) === $afterSecond);
check_rate('the member lookup selects only ref_ck',
    strpos(DB::$queries[$afterFirst], 'SELECT `ref_ck` FROM `users`') === 0, DB::$queries[$afterFirst]);

$afterSecond = count(DB::$queries);
check_rate('no referrer means the shop-wide rate and no query', orbit_affiliate_rate(0) === 10.0 && count(DB::$queries) === $afterSecond);
check_rate('a negative referrer id is treated the same way', orbit_affiliate_rate(-9) === 10.0 && count(DB::$queries) === $afterSecond);

check_rate('an unknown member falls back to the shop-wide rate', orbit_affiliate_rate(999) === 10.0);
check_rate('the unknown member was still looked up once', count(DB::$queries) === $afterSecond + 1);

DB::$settings['affiliate_ck'] = '12.5';
check_rate('a fractional shop rate is passed through', orbit_affiliate_rate(411) === 12.5);
check_rate('a fractional shop rate with no referrer', orbit_affiliate_rate(0) === 12.5);

/* The wiring: no file may rebuild the old double read. */
$root = dirname(__DIR__);
foreach (array('ajaxs/client/product.php', 'api/api.php', 'cron/bank.php', 'cron/momo.php') as $file) {
    $source = (string) file_get_contents($root . '/' . $file);
    check_rate($file . ' no longer reads ref_ck itself', strpos($source, 'ref_ck') === false);
}
$product = (string) file_get_contents($root . '/ajaxs/client/product.php');
check_rate('the purchase path uses the shared rate helper', strpos($product, 'orbit_affiliate_rate($getUser["ref_id"])') !== false);

/* The helper's own source: one get_row, no getRowRealtime. */
$helper = (string) file_get_contents($root . '/libs/helper.php');
$start = strpos($helper, 'function orbit_affiliate_rate');
$block = substr($helper, $start, strpos($helper, 'function', $start + 10) - $start);
check_rate('the helper performs one lookup', substr_count($block, 'get_row(') === 1);
check_rate('the helper never calls getRowRealtime', strpos($block, 'getRowRealtime') === false);
check_rate('the helper memoises per referrer', strpos($block, 'array_key_exists($refId, $rates)') !== false);

echo "\n" . $checks . " checks, " . $failures . " failure(s)\n";
exit($failures === 0 ? 0 : 1);
