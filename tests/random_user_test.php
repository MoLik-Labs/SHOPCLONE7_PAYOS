<?php
/*
 * Demo-data buyers are picked through the primary key, not by sorting the user table.
 *
 * cron/cron.php generates fake deposits and orders when the owner switches `status_tao_gd_ao` on.
 * It used to do `SELECT * FROM users ORDER BY RAND()` once per generated row — MySQL sorts every
 * user, PHP fetches one, and on a shop with 200 000 members a single tick could spend minutes on a
 * cosmetic feature. orbit_random_user_ids() probes the PK instead: a random id, then the next row
 * that exists. Worst case is 3 probes per wanted id, independent of table size.
 */

define('IN_SITE', true);

class DB
{
    public static $queries = array();
    public static $ids = array();          /* existing user ids, ascending */
    public static $maxOverride = null;
    public static $missingProbe = false;   /* simulate an empty users table */

    public function __construct() {}
    public function connect() { return true; }
    public function dis_connect() { return true; }
    public function site($name) { return $name === 'timezone' ? 'Asia/Ho_Chi_Minh' : ''; }
    public function get_list($sql) { self::$queries[] = $sql; return array(); }
    public function query($sql) { self::$queries[] = $sql; return true; }

    public function get_row($sql)
    {
        self::$queries[] = $sql;
        if (stripos($sql, 'MAX(`id`)') !== false) {
            if (self::$maxOverride !== null) {
                return array('max_id' => self::$maxOverride);
            }
            return array('max_id' => self::$ids ? max(self::$ids) : 0);
        }
        if (preg_match('/WHERE `id` >= ([0-9]+)/', $sql, $m)) {
            if (self::$missingProbe) {
                return false;
            }
            $probe = (int) $m[1];
            foreach (self::$ids as $id) {
                if ($id >= $probe) {
                    return array('id' => $id);
                }
            }
            return false;
        }
        if (stripos($sql, 'FROM `users` ORDER BY `id` ASC LIMIT 1') !== false) {
            if (self::$missingProbe || !self::$ids) {
                return false;
            }
            return array('id' => min(self::$ids));
        }
        return false;
    }
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
if (!function_exists('__')) {
    function __($text) { return $text; }
}
require __DIR__ . '/../libs/helper.php';

$failures = 0;
$checks = 0;
function check_random($name, $condition, $detail = '')
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
function start_sample()
{
    DB::$queries = array();
    return count(DB::$queries);
}

DB::$ids = array(3, 7, 11, 42, 99, 120);
$before = start_sample();
$picked = orbit_random_user_ids(4);
check_random('it returns the number of ids asked for', count($picked) === 4, (string) count($picked));
check_random('every id exists in the users table', count(array_diff($picked, DB::$ids)) === 0, implode(',', $picked));
check_random('the ids are distinct', count(array_unique($picked)) === count($picked));
check_random('it never sorts the user table', strpos(implode("\n", DB::$queries), 'ORDER BY RAND') === false);
check_random('the probes are bounded (at most 3 per wanted id plus MAX)', count(DB::$queries) <= 4 * 3 + 1, (string) count(DB::$queries));
check_random('it uses the primary key', strpos(implode("\n", DB::$queries), '`id` >=') !== false);

DB::$ids = array(5);
$picked = orbit_random_user_ids(5);
check_random('a tiny table still yields its only member', $picked === array(5), implode(',', $picked));

DB::$ids = array();
$before = start_sample();
check_random('an empty user table yields no ids', orbit_random_user_ids(3) === array());
check_random('and costs one MAX plus one fallback lookup', count(DB::$queries) === 2, (string) count(DB::$queries));

DB::$ids = array(1, 2, 3);
DB::$maxOverride = 1000;   /* the id space is much larger than the populated rows */
check_random('probes that land past the last row fall back to the first user', orbit_random_user_ids(2) === array(1));
DB::$maxOverride = null;
DB::$missingProbe = false;

DB::$ids = array(8);
$before = start_sample();
orbit_random_user_ids(0);
check_random('a count below one is treated as one', count(DB::$queries) <= 4, (string) count(DB::$queries));

DB::$ids = range(1, 500);
$before = start_sample();
$picked = orbit_random_user_ids(1000);
check_random('a huge count is clamped to 100', count($picked) <= 100, (string) count($picked));
check_random('and the query count stays proportional to the clamp', count(DB::$queries) <= 301, (string) count(DB::$queries));

/* ---- the cron wiring ----------------------------------------------------- */
$root = dirname(__DIR__);
$cron = (string) file_get_contents($root . '/cron/cron.php');
check_random('the demo-data generator uses the helper', strpos($cron, 'orbit_random_user_ids(') !== false);
check_random('no users table is sorted randomly any more', strpos($cron, '`users` ORDER BY RAND') === false && strpos($cron, '`products` WHERE `status` = 1 ORDER BY RAND') === false);
check_random('buyers are resolved once per tick, not per product', substr_count($cron, 'orbit_random_user_ids(') === 1);
check_random('the fake order loop no longer sorts products randomly', strpos($cron, 'FROM `products` WHERE `status` = 1 ORDER BY') === false);
check_random('the fake buyer list is initialised before either branch', strpos($cron, '$fakeBuyers = orbit_random_user_ids(20);') < strpos($cron, '$fakeBuyer = $fakeBuyers'));
check_random('an empty site still writes a row with buyer 0 rather than crashing', strpos($cron, '$fakeBuyers ?') !== false);

echo "\n" . $checks . " checks, " . $failures . " failure(s)\n";
exit($failures === 0 ? 0 : 1);
