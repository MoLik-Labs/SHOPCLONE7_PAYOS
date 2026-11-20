<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}
/*
 * Email campaigns: one query for the campaign id, batched recipients, no forged ids.
 *
 * The admin "create campaign" handler used to run, for every selected member,
 *
 *     SELECT * FROM users WHERE id = '<raw post value>'
 *     INSERT INTO email_sending (…, camp_id => (SELECT id … ORDER BY id DESC LIMIT 1), …)
 *
 * Two problems beyond the N+1: the raw post value reached SQL, and "the newest campaign" can belong
 * to another admin who saved at the same moment, so recipients were queued onto the wrong campaign.
 *
 * The handler now calls orbit_campaign_create(): one INSERT, the id from the same connection
 * (insertId), then one multi-row INSERT per 200 recipients — or one INSERT … SELECT for "everyone".
 */

define('IN_SITE', true);

class DB
{
    public static $log = array();
    public static $insertOk = true;
    public static $queryOk = true;
    public static $nextId = 41;
    public static $affected = 0;
    public static $existing = array(3, 7, 12);
    public static $userRows = array();

    public function __construct() {}
    public function connect() { return true; }
    public function dis_connect() { return true; }
    public function site($name) { return $name === 'timezone' ? 'Asia/Ho_Chi_Minh' : ''; }
    public function get_row($sql) { self::$log[] = $sql; return false; }
    public function get_list($sql)
    {
        self::$log[] = $sql;
        if (preg_match('/FROM `users` WHERE `id` IN \(([0-9,]+)\)/', $sql, $m)) {
            $wanted = array_map('intval', explode(',', $m[1]));
            $rows = array();
            foreach (self::$existing as $id) {
                if (in_array((int) $id, $wanted, true)) {
                    $rows[] = array('id' => (int) $id);
                }
            }
            return $rows;
        }
        return array();
    }
    public function query($sql)
    {
        self::$log[] = $sql;
        return self::$queryOk;
    }
    public function insertId() { return self::$nextId; }
    public function affectedRows() { return self::$affected; }
    public function insert($table, $data)
    {
        self::$log[] = 'INSERT ' . $table;
        if ($table === 'email_campaigns') {
            return self::$insertOk;
        }
        return true;
    }
    public function transaction($callback)
    {
        self::$log[] = 'BEGIN';
        $result = $callback();
        self::$log[] = $result ? 'COMMIT' : 'ROLLBACK';
        return $result;
    }
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
if (!function_exists('__')) {
    function __($text) { return $text; }
}
require __DIR__ . '/../libs/helper.php';

$failures = 0;
$checks = 0;
function check_campaign($name, $condition, $detail = '')
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
function reset_log()
{
    DB::$log = array();
    DB::$insertOk = true;
    DB::$queryOk = true;
    DB::$nextId = 41;
    DB::$affected = 3;
}

/* ---------------------------------------------------------------- selection */

$sel = orbit_campaign_selection(array('3', '7', 7, '12'));
check_campaign('numeric ids survive and duplicates collapse', $sel['ids'] === array(3, 7, 12) && $sel['invalid'] === false);

$sel = orbit_campaign_selection(array(''));
check_campaign('the blank "everyone" placeholder is not an id', $sel['ids'] === array() && $sel['invalid'] === false);

$sel = orbit_campaign_selection(array('3', "1 OR 1=1"));
check_campaign('an injected string marks the whole selection invalid', $sel['invalid'] === true && $sel['ids'] === array(3));

$sel = orbit_campaign_selection(array('3', array('7')));
check_campaign('a nested array is refused', $sel['invalid'] === true);

$sel = orbit_campaign_selection(array('-5', '0'));
check_campaign('zero and negative ids are not members', $sel['invalid'] === true && $sel['ids'] === array());

$sel = orbit_campaign_selection('7');
check_campaign('a scalar instead of an array is refused', $sel['invalid'] === true);

/* ------------------------------------------------------------ existing ids */

reset_log();
$ids = orbit_campaign_recipient_ids(new DB(), array(3, 7, 999));
check_campaign('only ids that exist are queued', $ids === array(3, 7), implode(',', $ids));
check_campaign('one query resolves the whole selection', count(DB::$log) === 1 && strpos(DB::$log[0], 'IN (3,7,999)') !== false);

reset_log();
$many = range(1, 1200);
DB::$existing = $many;
orbit_campaign_recipient_ids(new DB(), $many);
check_campaign('1200 ids resolve in exactly 3 queries (500 per chunk)', count(DB::$log) === 3, (string) count(DB::$log));

/* ---------------------------------------------------------------- queueing */

reset_log();
DB::$affected = 3;
$queued = orbit_campaign_queue(new DB(), 55, range(1, 250));
check_campaign('250 recipients become two INSERT statements', count(DB::$log) === 2 && $queued === 250);
check_campaign('each row carries the campaign id and a user id', strpos(DB::$log[0], '(55,1,0,') !== false && strpos(DB::$log[0], '(55,200,0,') !== false);
check_campaign('no per-recipient INSERT remains', count(array_filter(DB::$log, function ($sql) {
    return strpos($sql, 'INSERT INTO `email_sending`') === 0 && substr_count($sql, '(') === 1;
})) === 0);

reset_log();
DB::$affected = 12;
$queued = orbit_campaign_queue_all(new DB(), 55);
check_campaign('everyone is queued with one INSERT … SELECT', count(DB::$log) === 1 && strpos(DB::$log[0], 'SELECT 55, `id`') !== false && $queued === 12);
check_campaign('the everyone query skips banned and blank emails', strpos(DB::$log[0], '`banned` = 0') !== false && strpos(DB::$log[0], "`email` <> ''") !== false);

/* ----------------------------------------------------------------- create */

$campaign = array('name' => 'Sale', 'subject' => 'Hi', 'status' => 0);

reset_log();
DB::$nextId = 77;
$id = orbit_campaign_create(new DB(), $campaign, array('3', '7'));
check_campaign('create returns the id of its own INSERT', $id === 77, (string) $id);
check_campaign('create resolves recipients before queueing', strpos(implode("\n", DB::$log), 'IN (3,7)') !== false);
check_campaign('create never looks up "the newest campaign"', strpos(implode("\n", DB::$log), 'ORDER BY id DESC') === false);
check_campaign('create opens and commits one transaction', in_array('BEGIN', DB::$log, true) && in_array('COMMIT', DB::$log, true));

reset_log();
$id = orbit_campaign_create(new DB(), $campaign, array());
check_campaign('an empty selection means everyone', $id === 41 && strpos(DB::$log[2], 'INSERT INTO `email_sending`') === 0 && strpos(DB::$log[2], 'FROM `users`') !== false);

reset_log();
DB::$insertOk = false;
check_campaign('a failed campaign insert returns 0', orbit_campaign_create(new DB(), $campaign, array('3')) === 0);
check_campaign('a failed campaign insert queues nobody', strpos(implode("\n", DB::$log), 'email_sending') === false);

reset_log();
DB::$nextId = 0;
check_campaign('a missing AUTO_INCREMENT id aborts before queueing', orbit_campaign_create(new DB(), $campaign, array('3')) === 0
    && strpos(implode("\n", DB::$log), 'email_sending') === false);

reset_log();
DB::$affected = 0;
check_campaign('a campaign with no deliverable recipient is not created', orbit_campaign_create(new DB(), $campaign, array()) === 0);

reset_log();
check_campaign('a forged recipient list creates nothing', orbit_campaign_create(new DB(), $campaign, array("3' OR '1'='1")) === 0
    && strpos(implode("\n", DB::$log), 'INSERT email_campaigns') === false);

/* ------------------------------------------------------------------ wiring */

$add = file_get_contents(__DIR__ . '/../views/admin/email-campaign-add.php');
$list = file_get_contents(__DIR__ . '/../views/admin/email-campaigns.php');
check_campaign('both create paths call the helper', strpos($add, 'orbit_campaign_create(') !== false && strpos($list, 'orbit_campaign_create(') !== false);
check_campaign('no view still races on the newest campaign id', strpos($add, 'ORDER BY id DESC LIMIT 1') === false && strpos($list, 'ORDER BY id DESC LIMIT 1') === false);
check_campaign('no view still loops a get_row per posted user', !preg_match('/foreach\s*\(\s*\$_POST\["listUser"\]/', $add) && !preg_match('/foreach\s*\(\s*\$_POST\["listUser"\]/', $list));

$cron = file_get_contents(__DIR__ . '/../cron/sending_email.php');
check_campaign('the campaign cron renders the template once, not over HTTP per recipient',
    strpos($cron, 'file_get_contents(base_url("libs/mails/notification.php")') === false
    && substr_count($cron, 'include __DIR__ . "/../libs/mails/notification.php"') === 1);
check_campaign('the e-mail template may be included as well as fetched',
    strpos(file_get_contents(__DIR__ . '/../libs/mails/notification.php'), 'if (!defined("IN_SITE"))') !== false);

$dbSource = file_get_contents(__DIR__ . '/../libs/db.php');
check_campaign('the database layer exposes insertId()', strpos($dbSource, 'public function insertId()') !== false);
check_campaign('UPDATE writes NULL as NULL, not as the string \'NULL\'', strpos($dbSource, 'if ($value === NULL)') !== false && strpos($dbSource, 'return "NULL";') !== false);

echo "\n" . $checks . " checks, " . $failures . " failure(s)\n";
exit($failures === 0 ? 0 : 1);
