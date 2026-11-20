<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}
/*
 * Payment callbacks — exactly-once crediting.
 *
 * Every gateway in this shop retries a callback until it gets a 200. Before this suite, each handler
 * read the invoice, credited, and only then marked it paid: two deliveries that overlapped both saw
 * "pending" and both credited. The handlers now claim the invoice with a conditional UPDATE (or, for
 * the ledger, a keyed insert) and treat the ledger key as the tie-breaker.
 *
 * Two layers here:
 *   1. users::AddCreditsChecked() behaviour, against a stub DB — the retry path, the fresh path and
 *      the failure path, including the promise that a retry does not move money twice.
 *   2. Static wiring checks that each callback actually uses that contract and that the specific
 *      holes found in the audit (unsigned `value`, `99` = processing, undefined `$User`, `rate_pm`,
 *      `complted`) stay closed.
 */

define('IN_SITE', true);

class DB
{
    public static $ledger = array();
    public static $money = 100.0;
    public static $totalMoney = 0.0;
    public static $failLedger = false;
    public static $statements = array();
    public $affected = 0;
    public function __construct() {}
    public function connect() { return true; }
    public function dis_connect() { return true; }
    public function site($name)
    {
        return $name === 'timezone' ? 'Asia/Ho_Chi_Minh' : '';
    }
    public function get_row($sql)
    {
        if (stripos($sql, 'FROM `users`') !== false) {
            return array('money' => self::$money);
        }
        return false;
    }
    public function query($sql)
    {
        self::$statements[] = $sql;
        if (strpos($sql, 'INSERT INTO `dongtien`') !== false) {
            if (self::$failLedger) {
                $this->affected = 0;
                return false;
            }
            if (!preg_match('/`transid` = \'([^\']*)\'\)/', $sql, $match)) {
                // The unkeyed insert path is written through insert(), never here.
                $this->affected = 1;
                return true;
            }
            if (in_array($match[1], self::$ledger, true)) {
                $this->affected = 0;
                return true;
            }
            self::$ledger[] = $match[1];
            $this->affected = 1;
            return true;
        }
        $this->affected = 1;
        return true;
    }
    public function affectedRows()
    {
        return $this->affected;
    }
    public function insert($table, $data)
    {
        return true;
    }
    public function update($table, $data, $where)
    {
        return true;
    }
    public function cong($table, $field, $amount, $where)
    {
        if ($field === 'money') {
            self::$money += (float) $amount;
        }
        if ($field === 'total_money') {
            self::$totalMoney += (float) $amount;
        }
        return true;
    }
    public function tru($table, $field, $amount, $where)
    {
        if ($field === 'money') {
            self::$money -= (float) $amount;
        }
        return true;
    }
    public function transaction($callback)
    {
        return $callback();
    }
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$_SERVER['SERVER_NAME'] = 'shop.test';
if (!function_exists('__')) {
    function __($text) { return $text; }
}
require __DIR__ . '/../libs/helper.php';
require __DIR__ . '/../libs/database/users.php';

$failures = 0;
$checks = 0;
function check_payment($name, $condition)
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
function payment_source($file)
{
    return file_get_contents(__DIR__ . '/../' . $file);
}
function payment_has($file, $needle)
{
    return strpos(payment_source($file), $needle) !== false;
}
function payment_lacks($file, $needle)
{
    return strpos(payment_source($file), $needle) === false;
}

/* ---- the ledger contract -------------------------------------------------- */
$account = new users();

DB::$ledger = array();
DB::$money = 100.0;
DB::$totalMoney = 0.0;
$outcome = $account->AddCreditsChecked(7, 50, 'test credit', 'TOPUP_TEST_0001');
check_payment('a fresh keyed credit reports "credited"', $outcome === 'credited');
check_payment('a fresh keyed credit moves the balance once', abs(DB::$money - 150.0) < 0.001);
check_payment('the ledger keeps exactly one row for the key', count(DB::$ledger) === 1);

$outcome = $account->AddCreditsChecked(7, 50, 'test credit', 'TOPUP_TEST_0001');
check_payment('the same retried event reports "duplicate"', $outcome === 'duplicate');
check_payment('a retried event does not move the balance again', abs(DB::$money - 150.0) < 0.001);
check_payment('a retried event does not add a ledger row', count(DB::$ledger) === 1);

$outcome = $account->AddCreditsChecked(7, 25, 'another credit', 'TOPUP_TEST_0002');
check_payment('a different key still credits', $outcome === 'credited' && abs(DB::$money - 175.0) < 0.001);

DB::$failLedger = true;
$outcome = $account->AddCreditsChecked(7, 10, 'broken credit', 'TOPUP_TEST_0003');
DB::$failLedger = false;
check_payment('a broken ledger write reports "failed"', $outcome === 'failed');
check_payment('a broken ledger write moves no money', abs(DB::$money - 175.0) < 0.001);

check_payment('AddCredits() keeps its boolean contract on success', $account->AddCredits(7, 5, 'legacy call', 'TOPUP_TEST_0004') === true);
check_payment('AddCredits() is false for a duplicate, never a second credit', $account->AddCredits(7, 5, 'legacy call', 'TOPUP_TEST_0004') === false);
check_payment('the legacy wrapper moved the balance once', abs(DB::$money - 180.0) < 0.001);

/* ---- card callback -------------------------------------------------------- */
check_payment('card callback claims the row with a conditional UPDATE', payment_has('api/callback_card.php', "WHERE `id` = \" . (int) \$row[\"id\"] . \" AND `status` = 'pending'"));
check_payment('card callback refuses to credit a lost claim', payment_has('api/callback_card.php', 'if($CMSNT->affectedRows() !== 1)'));
check_payment('card callback uses the tri-state credit result', payment_has('api/callback_card.php', 'AddCreditsChecked('));
check_payment('card callback caps the unsigned value at the declared amount', payment_has('api/callback_card.php', 'min($reportedValue, $declared)'));
check_payment('card callback rejects a zero/invalid value before crediting', payment_has('api/callback_card.php', 'if($value <= 0)'));
check_payment('card callback requeues the card when the credit fails', payment_has('api/callback_card.php', '"status" => "pending"'));
check_payment('card callback treats 99 as processing, not failure', payment_has('api/callback_card.php', 'in_array($status, array(2, 3, 4, 100), true)'));
check_payment('card callback answers a processing status without touching the row', payment_has('api/callback_card.php', 'exit("payment.processing")'));
check_payment('card callback no longer writes the old unconditional completion', payment_lacks('api/callback_card.php', '["status" => "completed", "price" => $price'));
check_payment('card callback signs the raw code/serial, not sanitised copies', payment_has('api/callback_card.php', 'md5($CMSNT->site("card_partner_key") . $rawCode . $rawSerial)'));

/* ---- crypto callback ------------------------------------------------------ */
check_payment('crypto callback claims before crediting', payment_has('api/callback_crypto.php', "AND `status` <> 'completed'"));
check_payment('crypto callback refuses to credit a lost claim', payment_has('api/callback_crypto.php', 'affectedRows() === 1'));
check_payment('crypto callback uses the tri-state credit result', payment_has('api/callback_crypto.php', 'AddCreditsChecked('));
check_payment('crypto callback requeues after a failed credit', payment_has('api/callback_crypto.php', 'exit("credit_failed")'));
check_payment('crypto callback refuses a zero-value invoice', payment_has('api/callback_crypto.php', 'exit("số tiền không hợp lệ")'));

/* ---- flutterwave callback ------------------------------------------------- */
check_payment('flutterwave verifies the response shape before reading it', payment_has('api/callback_flutterwave.php', 'if(!is_array($response) || !isset($response["data"]) || !is_array($response["data"]))'));
check_payment('flutterwave claims the invoice before crediting', payment_has('api/callback_flutterwave.php', "AND `status` = 'pending'"));
check_payment('flutterwave refuses to credit a lost claim', payment_has('api/callback_flutterwave.php', 'affectedRows() !== 1'));
check_payment('flutterwave uses the tri-state credit result', payment_has('api/callback_flutterwave.php', 'AddCreditsChecked('));
check_payment('flutterwave defines the user row it notifies about', payment_has('api/callback_flutterwave.php', '$getUser = $CMSNT->get_row(" SELECT * FROM `users`'));

/* ---- perfect money -------------------------------------------------------- */
check_payment('perfect money reads the rate that the admin form saves', payment_has('api/callback-perfectmoney.php', 'site("perfectmoney_rate")'));
check_payment('perfect money no longer reads the nonexistent rate_pm', payment_lacks('api/callback-perfectmoney.php', 'site("rate_pm")'));
check_payment('perfect money instantiates the user service it calls', payment_has('api/callback-perfectmoney.php', '$User = new users();'));
check_payment('perfect money claims the invoice conditionally', payment_has('api/callback-perfectmoney.php', "AND `status` = 0"));
check_payment('perfect money uses the tri-state credit result', payment_has('api/callback-perfectmoney.php', 'AddCreditsChecked('));

/* ---- squadco -------------------------------------------------------------- */
check_payment('squadco credits through the tri-state path', payment_has('api/callback_squadco.php', 'AddCreditsChecked('));
check_payment('squadco stops writing an invoice before the credit', payment_lacks('api/callback_squadco.php', 'insert("payment_squadco"') === false && strpos(payment_source('api/callback_squadco.php'), '$credit = $user->AddCreditsChecked') < strpos(payment_source('api/callback_squadco.php'), 'insert("payment_squadco"'));

/* ---- bank webhook --------------------------------------------------------- */
check_payment('bank webhook credits through the tri-state path', payment_has('api/webhook_web2m.php', 'AddCreditsChecked('));
check_payment('bank webhook no longer skips a retry after a failed credit', payment_lacks('api/webhook_web2m.php', 'num_rows(" SELECT * FROM `payment_bank`'));
check_payment('bank webhook backfills the invoice row for a duplicate delivery', payment_has('api/webhook_web2m.php', 'INSERT INTO `payment_bank`'));
check_payment('bank webhook refuses a body that is not {data: [...]}', payment_has('api/webhook_web2m.php', '!isset($result["data"]) || !is_array($result["data"])'));
check_payment('squadco refuses a body without the Body object', payment_has('api/callback_squadco.php', '!isset($body["Body"]) || !is_array($body["Body"])'));
check_payment('squadco parses the minor-unit amount before using it', payment_has('api/callback_squadco.php', 'orbit_gateway_amount(isset($body["amount"])'));
check_payment('perfect money requires every signed field', payment_has('api/callback-perfectmoney.php', 'foreach (array("PAYMENT_ID", "PAYEE_ACCOUNT"'));
check_payment('bank webhook parses the amount instead of trusting a quoted string', payment_has('api/webhook_web2m.php', 'orbit_gateway_amount(isset($data["amount"])'));
check_payment('bank webhook bounds the batch it walks', payment_has('api/webhook_web2m.php', 'array_slice($result["data"], 0, 200)'));
check_payment('bank webhook refuses a body over 1 MB', payment_has('api/webhook_web2m.php', 'strlen($receivedData) > 1048576'));

/* ---- card top-up form ----------------------------------------------------- */
check_payment('daily card block compares completed, not the old misspelling', payment_lacks('ajaxs/client/create.php', "'complted'"));

/* ---- every audited handler uses the checked credit ------------------------
   AddCredits() cannot tell "already credited" from "database error", so a handler that still calls it
   can only either double-credit or fail closed. */
foreach (array('api/callback_card.php', 'api/callback_crypto.php', 'api/callback_flutterwave.php', 'api/callback-perfectmoney.php', 'api/callback_squadco.php', 'api/webhook_web2m.php') as $handler) {
    check_payment('handler uses AddCreditsChecked exactly once: ' . $handler, substr_count(payment_source($handler), 'AddCreditsChecked(') === 1);
    check_payment('handler never calls the ambiguous AddCredits: ' . $handler, substr_count(payment_source($handler), '->AddCredits(') === 0);
}

/* ---- payOS keeps its stronger row-lock path ------------------------------- */
check_payment('payOS credit still locks the order row', payment_has('libs/payos.php', 'FOR UPDATE'));
check_payment('payOS still refuses a second credit by credited_at', payment_has('libs/payos.php', "if (\$order['credited_at'])"));
check_payment('payOS still binds the webhook to the stored payment link', payment_has('libs/payos.php', 'hash_equals($order[\'payment_link_id\'], (string) $data[\'paymentLinkId\'])'));

echo "\n" . $checks . " checks, " . $failures . " failed\n";
exit($failures === 0 ? 0 : 1);
