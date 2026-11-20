<?php
/*
 * Money amounts: validate the number before comparing, debit once, and give the money back if the
 * request fails.
 *
 * The withdrawal form compared `$_POST["amount"]` as a string ("abc" is never less than 50000 in
 * PHP 8), then debited `ref_price` with a second statement after a separate balance check — so two
 * concurrent requests could both pass and drive the commission balance negative, which the flow
 * then treated as fraud and banned the member. And if the `aff_withdraw` insert failed, the debit
 * stayed with nothing to show for it.
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
function check_withdraw($name, $condition, $detail = '')
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

/* ---- shared amount helpers ------------------------------------------------ */
check_withdraw('a plain decimal string never uses an exponent',
    orbit_amount_string(1000000) === '1000000' && orbit_amount_string(1.5) === '1.5'
    && orbit_amount_string(0.00000001) === '0.00000001' && orbit_amount_string('1e6') === '1000000');
check_withdraw('a non-finite amount renders as zero, not NAN text',
    orbit_amount_string(NAN) === '0' && orbit_amount_string(INF) === '0');
check_withdraw('the card and crypto flows share the positive parser',
    orbit_positive_amount('abc') === false && orbit_positive_amount('20000') === 20000.0);

/* Gateway amounts: "1,500,000" is a string in PHP 8, so `"1,500,000" < 10000` compares as text
   and a malformed or huge transfer can fall inside the configured range by accident. */
$gateway = array(
    array('1500000', 1500000.0),
    array('1,500,000', 1500000.0),
    array('1.500.000', 1500000.0),
    array(' 25 000 ', 25000.0),
    array(50000, 50000.0),
    array('abc', false),
    array('', false),
    array(null, false),
    array('0', false),
    array('-1', false),
);
foreach ($gateway as $case) {
    list($raw, $expected) = $case;
    check_withdraw('a gateway amount is parsed or refused: ' . var_export($raw, true),
        orbit_gateway_amount($raw) === $expected, var_export(orbit_gateway_amount($raw), true));
}
check_withdraw('a gateway amount with a real decimal still keeps it',
    orbit_gateway_amount('12.75') === 12.75);

foreach (array('cron/bank.php' => 8, 'cron/bankWeb2m.php' => 9, 'cron/momo.php' => 1) as $file => $assignments) {
    $source = (string) file_get_contents(__DIR__ . '/../' . $file);
    check_withdraw($file . ' parses every gateway amount before comparing it',
        substr_count($source, 'orbit_gateway_amount(') === $assignments,
        substr_count($source, 'orbit_gateway_amount(') . ' of ' . $assignments);
    check_withdraw($file . ' no longer stores the raw gateway amount',
        strpos($source, 'check_string($data["amount"])') === false
        && strpos($source, 'check_string($data["Amount"])') === false);
    if ($file === 'cron/bank.php') {
        check_withdraw($file . ' compares the parsed amount numerically',
            strpos($source, '$amount !== false && $amount >= (float) $CMSNT->site("bank_min")') !== false);
    } elseif ($file === 'cron/bankWeb2m.php') {
        check_withdraw($file . ' compares the parsed amount numerically',
            strpos($source, '$amount === false || $amount < (float) $CMSNT->site("bank_min")') !== false);
    } else {
        check_withdraw($file . ' refuses a malformed amount before crediting',
            strpos($source, 'if($amount !== false && ($getUser = $CMSNT->get_row(') !== false);
    }
}

$recharge = (string) file_get_contents(__DIR__ . '/../ajaxs/client/create.php');
$cardBlock = substr($recharge, strpos($recharge, 'if($_POST["action"] == "nap_the")'), strpos($recharge, 'if($_POST["action"] == "CreateInvoiceCrypto")') - strpos($recharge, 'if($_POST["action"] == "nap_the")'));
$cryptoBlock = substr($recharge, strpos($recharge, 'if($_POST["action"] == "CreateInvoiceCrypto")'), strpos($recharge, 'if($_POST["action"] == "CreateToyyibpay")') - strpos($recharge, 'if($_POST["action"] == "CreateInvoiceCrypto")'));
check_withdraw('the card amount is validated and cast to an integer',
    strpos($cardBlock, 'orbit_positive_amount($_POST["amount"])') !== false
    && strpos($cardBlock, '$amount = (int) $cardAmount;') !== false
    && strpos($cardBlock, '$_POST["amount"] <= 0') === false);
check_withdraw('the crypto amount is validated before the min/max comparison',
    strpos($cryptoBlock, 'orbit_positive_amount($_POST["amount"])') !== false
    && strpos($cryptoBlock, '$cryptoAmountValue < (float) $CMSNT->site("crypto_min")') !== false
    && strpos($cryptoBlock, '$amount = orbit_amount_string($cryptoAmountValue);') !== false);

/* ---- the amount parser ---------------------------------------------------- */
$accepted = array(
    array('50000', 50000.0),
    array('50000.5', 50000.5),
    array(' 75000 ', 75000.0),
    array('1e6', 1000000.0),
    array(25000, 25000.0),
    array(12.75, 12.75),
);
foreach ($accepted as $case) {
    list($raw, $expected) = $case;
    check_withdraw('a valid amount is accepted: ' . var_export($raw, true),
        orbit_withdraw_amount($raw) === $expected, var_export(orbit_withdraw_amount($raw), true));
}

$rejected = array('abc', '', '  ', '0', '0.0', '-5', '-0.5', null, true, false, array(1), '5,000', '12abc');
foreach ($rejected as $raw) {
    check_withdraw('an invalid amount is refused: ' . var_export($raw, true),
        orbit_withdraw_amount($raw) === false, var_export(orbit_withdraw_amount($raw), true));
}
check_withdraw('NAN and INF are refused',
    orbit_withdraw_amount(NAN) === false && orbit_withdraw_amount(INF) === false);
check_withdraw('a string larger than PHP_INT_MAX is still finite',
    orbit_withdraw_amount('1e308') === 1.0E+308);

/* ---- source wiring -------------------------------------------------------- */
$root = dirname(__DIR__);
$create = (string) file_get_contents($root . '/ajaxs/client/create.php');
$users = (string) file_get_contents($root . '/libs/database/users.php');

check_withdraw('the withdrawal validates through the shared parser',
    strpos($create, 'orbit_withdraw_amount($_POST["amount"])') !== false);
$withdrawBlock = substr($create, strpos($create, 'WithdrawCommission'), strpos($create, 'if($_POST["action"] == "nap_the")') - strpos($create, 'WithdrawCommission'));
check_withdraw('the withdrawal block no longer compares the raw request value',
    strpos($withdrawBlock, '$_POST["amount"] <') === false
    && strpos($withdrawBlock, '$getUser["ref_price"] < $_POST["amount"]') === false);
check_withdraw('the withdrawal still enforces the configured minimum',
    strpos($withdrawBlock, '$amountValue < (float) $CMSNT->site("affiliate_min")') !== false);
check_withdraw('the withdrawal debits through the checked path',
    strpos($create, 'RemoveCommissionChecked($getUser["id"], $amount,') !== false
    && strpos($create, '$User->RemoveCommission($getUser["id"]') === false);
check_withdraw('an insufficient result is reported as such, not as fraud',
    strpos($create, '$withdrawState === "insufficient"') !== false
    && strpos($create, "Gian lận khi rút", strpos($create, '$withdrawState === "removed"')) !== false);
check_withdraw('a failed insert restores the commission',
    strpos($create, 'RestoreCommission($getUser["id"], $amount,') !== false);
check_withdraw('a failed restore leaves an error trace',
    strpos($create, 'Không tạo được lệnh rút và chưa hoàn lại được hoa hồng') !== false);

check_withdraw('RemoveCommission keeps its boolean contract',
    strpos($users, 'return $this->RemoveCommissionChecked($user_id, $amount, $reason, true) === "removed";') !== false);
check_withdraw('the checked withdrawal locks the user row before reading the balance',
    strpos($users, 'SELECT `ref_price` FROM `users` WHERE `id` = " . $user_id . " FOR UPDATE') !== false);
check_withdraw('the checked withdrawal refuses a short balance inside the transaction',
    strpos($users, 'if(!$allowNegative && $before < $amount)') !== false
    && strpos($users, '$this->commission_outcome = "insufficient";') !== false);
$removeStart = strpos($users, 'public function RemoveCommissionChecked');
$restoreStart = strpos($users, 'public function RestoreCommission');
$removeBody = substr($users, $removeStart, strpos($users, 'public function RemoveCommission(') - $removeStart);
$restoreBody = substr($users, $restoreStart, strpos($users, 'public function ClawbackCommission') - $restoreStart);
check_withdraw('the aff_log row and the debit share one transaction',
    strpos($removeBody, '$this->transaction(') !== false
    && strpos($removeBody, '"sotienthaydoi" => $amount') !== false
    && strpos($removeBody, 'parent::tru("users", "ref_price", $amount') !== false);
check_withdraw('the restore logs and credits in one transaction',
    strpos($restoreBody, '$this->transaction(') !== false
    && strpos($restoreBody, '"sotienthaydoi" => $amount') !== false
    && strpos($restoreBody, 'parent::cong("users", "ref_price", $amount') !== false);
check_withdraw('a restore credits ref_price as well as logging it',
    strpos($users, 'parent::cong("users", "ref_price", $amount, " `id` = " . $user_id . " ")') !== false);

echo "\n" . $checks . " checks, " . $failures . " failure(s)\n";
exit($failures === 0 ? 0 : 1);
