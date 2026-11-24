<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}

/**
 * GachtheFast credit path: exactly-once, atomic, and locked by default.
 *
 * This suite exercises the ONLY code that can move money. It runs entirely against a
 * DISPOSABLE database (DSN must contain "test") with a fake provider gateway — no network, no
 * production provider, no real card, no real user, no real balance.
 *
 * Why it is separate from gtf_service_test.php: every scenario here starts from a deliberately
 * enabled GTF_ALLOW_CREDITING, because the credit path cannot be reached honestly while the lock
 * is closed. The lock's own behaviour (fail-closed) is covered in gtf_service_test.php C0/C1.
 *
 * GTF_ALLOW_CREDITING is a test-only switch in this file. It is reset to the shipped default
 * (false) before the summary, and this file never changes .env or any shipped default.
 *
 * Usage:
 *   GTF_TEST_DSN='mysql:host=127.0.0.1;dbname=gtf_qa_test' \
 *   GTF_TEST_DB_USER=root GTF_TEST_DB_PASS=secret \
 *   php tests/gtf_credit_path_test.php
 */

if (!defined('IN_SITE')) {
    define('IN_SITE', true);
}
error_reporting(E_ALL & ~E_DEPRECATED);

$gtfDsn = (string) getenv('GTF_TEST_DSN');
if ($gtfDsn === '') {
    fwrite(STDERR, "SKIP: GTF_TEST_DSN is not set; no database was touched.\n");
    exit(0);
}
if (stripos($gtfDsn, 'test') === false && getenv('GTF_TEST_FORCE') !== 'yes') {
    fwrite(STDERR, "REFUSED: the DSN does not look like a disposable test database (set GTF_TEST_FORCE=yes to override).\n");
    exit(2);
}
foreach (['openssl' => 'card sealing', 'pdo_mysql' => 'database access'] as $gtfExtension => $gtfWhy) {
    if (!extension_loaded($gtfExtension)) {
        fwrite(STDERR, "SKIP: the {$gtfExtension} extension is required for {$gtfWhy}.\n");
        exit(0);
    }
}

$_ENV['SECRET_KEY'] = 'credit-path-test-secret';
$_ENV['GTF_API_BASE'] = 'https://api.example.test';
$_ENV['GTF_CARD_REQUEST_FORMAT'] = 'json';
$_ENV['GTF_PARTNER_ID'] = 'test-partner';
$_ENV['GTF_PARTNER_KEY'] = 'test-key';
$_ENV['GTF_CARD_ENABLED'] = 'true';
$_ENV['GTF_CARD_FEE_PERCENT'] = '0';
$_ENV['GTF_CARD_MIN_AMOUNT'] = '10000';
$_ENV['GTF_CARD_MAX_AMOUNT'] = '2000000';

require_once dirname(__DIR__) . '/libs/gtf.php';
require_once dirname(__DIR__) . '/libs/gtf_client.php';
require_once dirname(__DIR__) . '/libs/gtf_card.php';

$GLOBALS['credit_pass'] = 0;
$GLOBALS['credit_fail'] = 0;

function check($label, $condition)
{
    if ($condition) {
        $GLOBALS['credit_pass']++;
        echo "  ok   {$label}\n";
        return;
    }
    $GLOBALS['credit_fail']++;
    fwrite(STDERR, "  FAIL {$label}\n");
}

function checkSame($label, $expected, $actual)
{
    check($label . ' [expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ']', $expected === $actual);
}

function checkThrows($label, callable $callback, $expectedClass = null)
{
    try {
        $callback();
    } catch (Throwable $error) {
        if ($expectedClass !== null && !($error instanceof $expectedClass)) {
            check($label . ' [threw ' . get_class($error) . ': ' . $error->getMessage() . ']', false);
            return;
        }
        check($label . ' [threw ' . get_class($error) . ']', true);
        return;
    }
    check($label . ' [nothing was thrown]', false);
}

function section($title)
{
    echo "\n== {$title}\n";
}

function seed(PDO $db)
{
    foreach (['gtf_card_orders', 'gtf_ledger', 'gtf_events', 'dongtien', 'deposit_log', 'payment_bank', 'aff_log', 'settings', 'users'] as $table) {
        $db->exec('DELETE FROM `' . $table . '`');
    }
    $db->exec("INSERT INTO users (id,money,total_money,debit,banned,ref_id,ref_ck,ref_price,ref_total_price,ref_amount,token) VALUES
        (1,0,0,0,0,0,0,0,0,0,'tok-1'),(2,0,0,0,0,0,0,0,0,0,'tok-2'),(3,0,0,1,0,0,0,0,0,0,'tok-3')");
    $db->exec("INSERT INTO settings (name,value) VALUES ('affiliate_status','0'),('affiliate_ck','0')");
}

function setupAccount(PDO $db, int $userId, string $money, string $debit)
{
    $statement = $db->prepare('UPDATE users SET money = ?, debit = ? WHERE id = ?');
    $statement->execute([$money, $debit, $userId]);
}

function wallet(PDO $db, int $userId)
{
    $statement = $db->prepare('SELECT money FROM users WHERE id = ?');
    $statement->execute([$userId]);
    return (string) $statement->fetchColumn();
}

function countRows(PDO $db, string $table)
{
    return (int) $db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
}

function bumpChecks(PDO $db, string $requestId, int $seconds)
{
    $statement = $db->prepare('UPDATE gtf_card_orders SET provider_checked_at = DATE_SUB(NOW(), INTERVAL ? SECOND) WHERE request_id = ?');
    $statement->execute([$seconds, $requestId]);
}

/** Mock provider. Never used outside this harness. */
final class FakeCardGateway implements GtfCardGateway
{
    public $submitResult = ['status' => '99'];
    public $submitError = false;
    public $checkResult = ['status' => '99'];
    public $checkError = false;
    public $onCheck = null;

    public function submit(string $telco, string $amount, string $code, string $serial, string $requestId): array
    {
        if ($this->submitError) {
            throw new RuntimeException('mock transport failure');
        }
        return $this->submitResult;
    }

    public function check(string $telco, string $code, string $serial, string $amount, string $requestId): array
    {
        if ($this->onCheck !== null) {
            $hook = $this->onCheck;
            $this->onCheck = null;
            $hook();
        }
        if ($this->checkError) {
            throw new RuntimeException('mock transport failure');
        }
        return $this->checkResult;
    }
}

// --- fixture schema (identical shape to gtf_service_test.php) --------------
$db = new PDO($gtfDsn, (string) getenv('GTF_TEST_DB_USER'), (string) getenv('GTF_TEST_DB_PASS'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$db->exec('DROP TABLE IF EXISTS `gtf_card_orders`,`gtf_ledger`,`gtf_events`,`dongtien`,`deposit_log`,`payment_bank`,`aff_log`');
$db->exec('DROP TABLE IF EXISTS `settings`,`users`');
$db->exec("CREATE TABLE `users` (
    `id` INT NOT NULL AUTO_INCREMENT, `money` DECIMAL(20,2) NOT NULL DEFAULT 0, `total_money` DECIMAL(20,2) NOT NULL DEFAULT 0,
    `debit` DECIMAL(20,2) NOT NULL DEFAULT 0, `banned` TINYINT NOT NULL DEFAULT 0, `ref_id` INT NOT NULL DEFAULT 0,
    `ref_ck` DECIMAL(10,2) NOT NULL DEFAULT 0, `ref_price` DECIMAL(20,2) NOT NULL DEFAULT 0, `ref_total_price` DECIMAL(20,2) NOT NULL DEFAULT 0,
    `ref_amount` DECIMAL(20,2) NOT NULL DEFAULT 0, `token` VARCHAR(64) NOT NULL DEFAULT '', PRIMARY KEY (`id`)) ENGINE=InnoDB");
$db->exec("CREATE TABLE `settings` (`name` VARCHAR(64) NOT NULL, `value` TEXT NULL, PRIMARY KEY (`name`)) ENGINE=InnoDB");
$db->exec("CREATE TABLE `dongtien` (`id` BIGINT NOT NULL AUTO_INCREMENT, `user_id` INT NOT NULL, `sotientruoc` DECIMAL(20,2) NOT NULL DEFAULT 0,
    `sotienthaydoi` DECIMAL(20,2) NOT NULL DEFAULT 0, `sotiensau` DECIMAL(20,2) NOT NULL DEFAULT 0, `thoigian` DATETIME NULL,
    `noidung` VARCHAR(255) NOT NULL DEFAULT '', `transid` VARCHAR(64) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB");
$db->exec("CREATE TABLE `deposit_log` (`id` BIGINT NOT NULL AUTO_INCREMENT, `user_id` INT NOT NULL, `method` VARCHAR(64) NOT NULL DEFAULT '',
    `amount` DECIMAL(20,2) NOT NULL DEFAULT 0, `received` DECIMAL(20,2) NOT NULL DEFAULT 0, `create_time` INT NOT NULL DEFAULT 0,
    `is_virtual` TINYINT NOT NULL DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB");
$db->exec("CREATE TABLE `payment_bank` (`id` BIGINT NOT NULL AUTO_INCREMENT, `tid` VARCHAR(64) NOT NULL DEFAULT '',
    `method` VARCHAR(64) NOT NULL DEFAULT '', `user_id` INT NOT NULL, `description` VARCHAR(255) NOT NULL DEFAULT '',
    `amount` DECIMAL(20,2) NOT NULL DEFAULT 0, `received` DECIMAL(20,2) NOT NULL DEFAULT 0, `create_gettime` DATETIME NULL,
    `create_time` INT NOT NULL DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB");
$db->exec("CREATE TABLE `aff_log` (`id` BIGINT NOT NULL AUTO_INCREMENT, `user_id` INT NOT NULL, `reason` VARCHAR(255) NOT NULL DEFAULT '',
    `sotientruoc` DECIMAL(20,2) NOT NULL DEFAULT 0, `sotienthaydoi` DECIMAL(20,2) NOT NULL DEFAULT 0, `sotienhientai` DECIMAL(20,2) NOT NULL DEFAULT 0,
    `create_gettime` DATETIME NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB");
$db->exec("CREATE TABLE `gtf_card_orders` (`id` BIGINT NOT NULL AUTO_INCREMENT, `request_id` VARCHAR(64) NOT NULL, `user_id` INT NOT NULL,
    `telco` VARCHAR(16) NOT NULL DEFAULT '', `declared_amount` BIGINT NOT NULL DEFAULT 0, `currency` VARCHAR(8) NOT NULL DEFAULT 'VND',
    `status` VARCHAR(16) NOT NULL DEFAULT 'CREATING', `serial_masked` VARCHAR(32) NOT NULL DEFAULT '',
    `serial_sealed` VARBINARY(255) NULL, `code_sealed` VARBINARY(255) NULL, `provider_status` VARCHAR(32) NULL,
    `provider_ref` VARCHAR(64) NULL, `reason` VARCHAR(190) NULL, `credited_amount` DECIMAL(20,2) NULL, `credited_at` DATETIME NULL,
    `attempt_count` INT NOT NULL DEFAULT 0, `submitted_at` DATETIME NULL, `provider_checked_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `request_id` (`request_id`),
    KEY `user_state` (`user_id`, `status`)) ENGINE=InnoDB");
$db->exec("CREATE TABLE `gtf_ledger` (`id` BIGINT NOT NULL AUTO_INCREMENT, `transid` VARCHAR(80) NOT NULL, `user_id` INT NOT NULL,
    `method` VARCHAR(64) NOT NULL DEFAULT '', `gross` DECIMAL(20,2) NOT NULL DEFAULT 0, `credited` DECIMAL(20,2) NOT NULL DEFAULT 0,
    `reason` VARCHAR(255) NOT NULL DEFAULT '', `created_at` DATETIME NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `transid` (`transid`)) ENGINE=InnoDB");
$db->exec("CREATE TABLE `gtf_events` (`id` BIGINT NOT NULL AUTO_INCREMENT, `flow` VARCHAR(16) NOT NULL, `order_id` BIGINT NULL,
    `direction` VARCHAR(16) NOT NULL, `event_key` CHAR(64) NOT NULL, `provider_status` VARCHAR(32) NULL, `provider_ref` VARCHAR(64) NULL,
    `created_at` DATETIME NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `gtf_event_key` (`flow`,`order_id`,`direction`,`event_key`)) ENGINE=InnoDB");

$gateway = new FakeCardGateway();
$newService = function () use ($db, $gateway) {
    return new GtfCardService($db, $gateway, gtf_config());
};

// --- A0: the shipped default -----------------------------------------------
section('A0 the lock is closed in this checkout');
checkSame('GTF_ALLOW_CREDITING defaults to false when the environment is silent', false, GtfCredit::isEnabled(['credit_enabled' => false]));

// --- A1: happy path --------------------------------------------------------
section('A1 provider-confirmed value credits exactly once');
$_ENV['GTF_ALLOW_CREDITING'] = 'true';
seed($db);
$card = $newService();
$gateway->submitResult = ['status' => '99', 'request_id' => 'ignored-submit-echo'];
$gateway->checkError = false;
$created = $card->create(1, ['telco' => 'VIETTEL', 'amount' => '50000', 'serial' => 'SERI123456', 'pin' => 'PIN1234567']);
checkSame('creation is pending, not credited', 'pending', $created['status']);
$gateway->checkResult = ['status' => '1', 'value' => '50000', 'amount' => '50000', 'declared_value' => '50000', 'request_id' => $created['request_id']];
checkSame('verify credits the wallet', 'credited', $card->reconcile($card->find($created['request_id']), true));
$paid = $card->find($created['request_id']);
checkSame('the order ends PAID', 'PAID', $paid['status']);
checkSame('the credited amount is stored', '50000.00', (string) $paid['credited_amount']);
checkSame('the wallet holds the credited amount once', '50000.00', wallet($db, 1));
checkSame('one ledger row backs the credit', 1, countRows($db, 'gtf_ledger'));
checkSame('one deposit_log row backs the credit', 1, countRows($db, 'deposit_log'));
checkSame('the ledger is keyed to the order', 'GTF_CARD_' . $created['request_id'], (string) $db->query('SELECT transid FROM gtf_ledger ORDER BY id DESC LIMIT 1')->fetchColumn());
checkSame('the sealed card data is wiped once the order is settled', true, $paid['serial_sealed'] === null || (string) $paid['serial_sealed'] === '');
checkSame('a second verify of a credited order is refused', 'already_credited', $card->reconcile($paid, true));
checkSame('the wallet did not move a second time', '50000.00', wallet($db, 1));

// --- A2: double credit attempt through the operator path -------------------
section('A2 the ledger key makes a second credit impossible');
checkThrows('operator credit refuses an already-credited order', function () use ($card, $paid) {
    $card->adminCredit((int) $paid['id'], '50000', 'duplicate attempt');
}, 'DomainException');
$ledgerBefore = countRows($db, 'gtf_ledger');
$dongtienBefore = countRows($db, 'dongtien');
$walletBefore = wallet($db, 1);
// Exercise the guard inside gtf_ledger itself, bypassing the PAID check above.
$db->beginTransaction();
try {
    checkThrows('a raw duplicate ledger write is rejected by the primary key', function () use ($db, $paid) {
        GtfCredit::applyWithin($db, [
            'user_id' => 1,
            'credited' => '50000',
            'gross' => '50000',
            'method' => 'GachtheFast',
            'reason' => 'raw duplicate',
            'transid' => 'GTF_CARD_' . $paid['request_id'],
        ], function () {
            return true;
        });
    }, 'PDOException');
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
checkSame('the rejected duplicate wrote no ledger row', $ledgerBefore, countRows($db, 'gtf_ledger'));
checkSame('the rejected duplicate wrote no dongtien row', $dongtienBefore, countRows($db, 'dongtien'));
checkSame('the rejected duplicate moved no money', $walletBefore, wallet($db, 1));

// --- A3: partial failure is atomic ----------------------------------------
section('A3 a failure inside the transaction rolls the whole credit back');
seed($db);
$card = $newService();
$broken = $card->create(1, ['telco' => 'MOBIFONE', 'amount' => '20000', 'serial' => 'SERIABCDEF', 'pin' => 'PINABCDEF1']);
$gateway->checkResult = ['status' => '1', 'value' => '20000', 'amount' => '20000', 'declared_value' => '20000', 'request_id' => $broken['request_id']];
$db->beginTransaction();
try {
    checkThrows('a credit whose final order transition affects no row is aborted', function () use ($db, $broken) {
        GtfCredit::applyWithin($db, [
            'user_id' => 1,
            'credited' => '20000',
            'gross' => '20000',
            'method' => 'GachtheFast',
            'reason' => 'forced finalize failure',
            'transid' => 'GTF_FORCED_' . $broken['request_id'],
        ], function () {
            return false; // simulates "the order was already claimed by someone else"
        });
    }, 'RuntimeException');
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
checkSame('no money was credited by the aborted transaction', '0.00', wallet($db, 1));
checkSame('no ledger row survived the aborted transaction', 0, countRows($db, 'gtf_ledger'));
checkSame('no dongtien row survived the aborted transaction', 0, countRows($db, 'dongtien'));
checkSame('no deposit_log row survived the aborted transaction', 0, countRows($db, 'deposit_log'));
checkSame('the order itself is still open', 'PENDING', $card->find($broken['request_id'])['status']);
checkSame('the order can still be verified afterwards', 'credited', $card->reconcile($card->find($broken['request_id']), true));

// --- A4: an out-of-band operator adjustment does not fool the amount check --
section('A4 the amount check is not defeated by an intervening balance change');
seed($db);
setupAccount($db, 1, '200.00', '100.00');
$card = $newService();
$adjust = $card->create(1, ['telco' => 'VINAPHONE', 'amount' => '30000', 'serial' => 'SERI777777', 'pin' => 'PIN7777777']);
$gateway->checkResult = ['status' => '1', 'value' => '30000', 'amount' => '30000', 'declared_value' => '30000', 'request_id' => $adjust['request_id']];
checkSame('the credit is applied on top of the current balance', 'credited', $card->reconcile($card->find($adjust['request_id']), true));
checkSame('the wallet shows opening balance + credit - pre-existing debt', '30100.00', wallet($db, 1));
checkSame('the debt was repaid in full', '0.00', (string) $db->query('SELECT debit FROM users WHERE id = 1')->fetchColumn());
$ledgerTypes = $db->query('SELECT transid FROM dongtien ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
checkSame('the credit and the debt repayment are two distinct ledger rows', 2, count($ledgerTypes));
checkSame('the debt row is marked with the _DEBIT suffix', true, substr((string) $ledgerTypes[1], -6) === '_DEBIT');

// --- A5: a doctored provider answer ---------------------------------------
section('A5 a differing provider value is refused');
seed($db);
$card = $newService();
$mismatch = $card->create(1, ['telco' => 'ZING', 'amount' => '50000', 'serial' => 'SERI555555', 'pin' => 'PIN5555555']);
$gateway->checkResult = ['status' => '1', 'value' => '50000', 'amount' => '49999', 'declared_value' => '50000', 'request_id' => $mismatch['request_id']];
checkSame('the mismatching answer parks the order in review', 'review', $card->reconcile($card->find($mismatch['request_id']), true));
checkSame('the order records the amount conflict', 'amount_conflict', $card->find($mismatch['request_id'])['reason']);
checkSame('nothing was credited', '0.00', wallet($db, 1));
checkSame('no ledger row was written', 0, countRows($db, 'gtf_ledger'));
checkSame('the credit method cannot be invoked from outside the service', true,
    (new ReflectionMethod('GtfCardService', 'credit'))->isPrivate());
checkSame('still no ledger row', 0, countRows($db, 'gtf_ledger'));
checkSame('the wallet is still untouched', '0.00', wallet($db, 1));
// Recovery path: a later, fully consistent answer may pay the order once — and only once.
$gateway->checkResult = ['status' => '1', 'value' => '50000', 'amount' => '50000', 'declared_value' => '50000', 'request_id' => $mismatch['request_id']];
checkSame('a fully consistent answer can still settle the parked order', 'credited', $card->reconcile($card->find($mismatch['request_id']), true));
checkSame('the wallet moved exactly once', '50000.00', wallet($db, 1));

// --- A6: status 2 is never auto-credited ----------------------------------
section('A6 status 2 remains in review until provider verification is conclusive');
seed($db);
$card = $newService();
$statusTwo = $card->create(1, ['telco' => 'VCOIN', 'amount' => '50000', 'serial' => 'SERI222222', 'pin' => 'PIN2222222']);
$gateway->checkResult = ['status' => '2', 'value' => '50000', 'amount' => '50000', 'declared_value' => '50000', 'request_id' => $statusTwo['request_id']];
checkSame('status 2 is parked for a human', 'review', $card->reconcile($card->find($statusTwo['request_id']), true));
checkSame('the reason is recorded', 'status_2_value_differs', $card->find($statusTwo['request_id'])['reason']);
$orderId = (int) $card->find($statusTwo['request_id'])['id'];
checkSame('operator retry cannot override status 2', 'review', $card->adminCredit($orderId, '50000', 'reviewed by QA'));
checkSame('status 2 cannot move the wallet', '0.00', wallet($db, 1));
checkSame('status 2 cannot write ledger', 0, countRows($db, 'gtf_ledger'));
$gateway->checkResult = ['status' => '1', 'value' => '50000', 'amount' => '50000', 'declared_value' => '50000', 'request_id' => $statusTwo['request_id']];
checkSame('a fresh, matching provider check can settle the review', 'credited', $card->adminCredit($orderId, '50000', 'checked'));
checkSame('confirmed review credits once', '50000.00', wallet($db, 1));
checkThrows('the operator cannot pay the same order twice', function () use ($card, $orderId) {
    $card->adminCredit($orderId, '50000', 'another attempt');
}, 'DomainException');
checkSame('the wallet did not move twice', '50000.00', wallet($db, 1));

// --- A7: two concurrent workers, one credit -------------------------------
section('A7 two workers racing on the same order produce one credit');
seed($db);
$card = $newService();
$race = $card->create(1, ['telco' => 'VINAPHONE', 'amount' => '100000', 'serial' => 'SERI333333', 'pin' => 'PIN3333333']);
$gateway->checkResult = ['status' => '1', 'value' => '100000', 'amount' => '100000', 'declared_value' => '100000', 'request_id' => $race['request_id']];
// Force the lock to be re-claimable for both workers.
bumpChecks($db, $race['request_id'], 60);
checkSame('the first worker claims and credits', 'credited', $card->reconcile($card->find($race['request_id']), false));
bumpChecks($db, $race['request_id'], 60);
checkSame('the second worker finds the order already credited', 'already_credited', $card->reconcile($card->find($race['request_id']), false));
checkSame('the wallet received the credit exactly once', '100000.00', wallet($db, 1));
checkSame('exactly one ledger row exists', 1, countRows($db, 'gtf_ledger'));
$seen = [];
for ($attempt = 0; $attempt < 3; ++$attempt) {
    bumpChecks($db, $race['request_id'], 60);
    $seen[] = $card->reconcile($card->find($race['request_id']), false);
}
checkSame('further workers are all refused', ['already_credited', 'already_credited', 'already_credited'], $seen);
checkSame('the wallet is still at one credit', '100000.00', wallet($db, 1));
checkSame('the ledger still holds one row', 1, countRows($db, 'gtf_ledger'));

// --- A8: admin payment of an order that was since credited by the worker --
section('A8 the operator cannot overwrite a worker credit');
seed($db);
$card = $newService();
$overlap = $card->create(1, ['telco' => 'VINAPHONE', 'amount' => '20000', 'serial' => 'SERI666666', 'pin' => 'PIN6666666']);
$gateway->checkResult = ['status' => '1', 'value' => '20000', 'amount' => '20000', 'declared_value' => '20000', 'request_id' => $overlap['request_id']];
checkSame('the worker credits first', 'credited', $card->reconcile($card->find($overlap['request_id']), true));
checkThrows('the late operator payment is refused', function () use ($card, $overlap) {
    $card->adminCredit((int) $card->find($overlap['request_id'])['id'], '20000', 'late operator');
}, 'DomainException');
checkSame('the wallet holds exactly the worker credit', '20000.00', wallet($db, 1));
checkSame('exactly one ledger row exists', 1, countRows($db, 'gtf_ledger'));
checkSame('the order still points at the original credit source', 'provider_confirmed', $card->find($overlap['request_id'])['reason']);

// --- A9: the operator cannot re-value an order downward -------------------
section('A9 operator cannot override verification or choose an arbitrary amount');
seed($db);
$card = $newService();
$underpay = $card->create(1, ['telco' => 'GARENA', 'amount' => '100000', 'serial' => 'SERI888888', 'pin' => 'PIN8888888']);
$orderId = (int) $card->find($underpay['request_id'])['id'];
checkThrows('unverified pending order cannot be credited by the operator', function () use ($card, $orderId) {
    $card->adminCredit($orderId, '100000', 'unverified');
}, 'DomainException');
$gateway->checkResult = ['status'=>'2', 'value'=>'100000', 'amount'=>'100000', 'declared_value'=>'100000', 'request_id'=>$underpay['request_id']];
checkSame('provider status 2 parks order for review', 'review', $card->reconcile($card->find($underpay['request_id']), true));
foreach (['1000', '100000000', '100001'] as $badGross) {
    checkThrows('operator cannot set a different amount: ' . $badGross, function () use ($card, $orderId, $badGross) {
        $card->adminCredit($orderId, $badGross, 'test');
    }, 'DomainException');
}
checkSame('operator amount attempts did not move wallet', '0.00', wallet($db, 1));
checkSame('operator amount attempts created no ledger', 0, countRows($db, 'gtf_ledger'));

// --- A9b: mutable order data is checked again under the credit row lock -----
section('A9b stale provider checks cannot credit changed or closed orders');
foreach (['status' => 'CANCELLED', 'user_id' => '2', 'declared_amount' => '20000', 'request_id' => 'CHANGED12345',
    'telco' => 'MOBIFONE', 'currency' => 'USD', 'serial_sealed' => 'changed-serial', 'code_sealed' => 'changed-code'] as $field => $replacement) {
    seed($db);
    $card = $newService();
    $createdRace = $card->create(1, ['telco'=>'VIETTEL', 'amount'=>'10000', 'serial'=>'SERIRACE11', 'pin'=>'PINRACE111']);
    $raceRow = $card->find($createdRace['request_id']);
    $gateway->checkResult = ['status'=>'1', 'value'=>'10000', 'amount'=>'10000', 'declared_value'=>'10000', 'request_id'=>$createdRace['request_id']];
    $gateway->onCheck = function () use ($db, $field, $replacement, $raceRow) {
        // Test-controlled field names only; mutation stands in for a concurrent administrator.
        $statement = $db->prepare('UPDATE gtf_card_orders SET `' . $field . '` = ? WHERE id = ?');
        $statement->execute([$replacement, (int) $raceRow['id']]);
    };
    if ($field === 'status') {
        checkSame('cancelled order remains terminal', 'terminal', $card->reconcile($raceRow, true));
    } else {
        checkThrows('changed ' . $field . ' refuses the stale provider check', function () use ($card, $raceRow) {
            $card->reconcile($raceRow, true);
        }, 'RuntimeException');
    }
    checkSame('changed ' . $field . ' cannot credit either user', ['0.00', '0.00'], [wallet($db, 1), wallet($db, 2)]);
    checkSame('changed ' . $field . ' cannot create a ledger row', 0, countRows($db, 'gtf_ledger'));
}

seed($db);
$card = $newService();
$staleReview = $card->create(1, ['telco'=>'VIETTEL', 'amount'=>'10000', 'serial'=>'SERIRACE22', 'pin'=>'PINRACE222']);
$staleRow = $card->find($staleReview['request_id']);
$gateway->checkResult = ['status'=>'1', 'value'=>'20000', 'amount'=>'20000', 'declared_value'=>'20000', 'request_id'=>$staleReview['request_id']];
$gateway->onCheck = function () use ($db, $staleRow) {
    $db->prepare("UPDATE gtf_card_orders SET status='CANCELLED' WHERE id=?")->execute([(int) $staleRow['id']]);
};
$card->reconcile($staleRow, true);
checkSame('a stale review decision cannot reopen a cancelled order', 'CANCELLED', $card->find($staleReview['request_id'])['status']);
checkSame('a stale review decision cannot credit money', '0.00', wallet($db, 1));

seed($db);
$card = $newService();
$staleTimeout = $card->create(1, ['telco'=>'VIETTEL', 'amount'=>'10000', 'serial'=>'SERIRACE33', 'pin'=>'PINRACE333']);
$db->prepare("UPDATE gtf_card_orders SET status='VERIFYING' WHERE request_id=?")->execute([$staleTimeout['request_id']]);
$staleRow = $card->find($staleTimeout['request_id']);
$gateway->checkResult = ['status'=>'1', 'value'=>'10000', 'amount'=>'10000', 'declared_value'=>'10000', 'request_id'=>$staleTimeout['request_id']];
$gateway->checkError = true;
$gateway->onCheck = function () use ($card, $gateway, $staleRow) {
    $gateway->checkError = false;
    if ($card->reconcile($staleRow, true) !== 'credited') { throw new RuntimeException('fixture settlement failed'); }
    $gateway->checkError = true;
};
checkThrows('the stale verification reports its transport error', function () use ($card, $staleRow) { $card->reconcile($staleRow, true); }, 'RuntimeException');
$gateway->checkError = false;
checkSame('a stale verification timeout cannot downgrade a paid order', 'PAID', $card->find($staleTimeout['request_id'])['status']);
checkSame('the prior settled wallet credit remains intact', '10000.00', wallet($db, 1));
checkSame('the prior settled credit has one ledger row', 1, countRows($db, 'gtf_ledger'));

// --- A10: malformed amounts are refused at the boundary -------------------
section('A10 malformed and hostile amounts never reach the ledger');
seed($db);
$card = $newService();
$target = $card->create(1, ['telco' => 'MOBIFONE', 'amount' => '100000', 'serial' => 'SERI121212', 'pin' => 'PIN1212121']);
$malformed = ['', '0', '-1', '1e6', 'ABC', '10.000', ' 10000', '10000 ', '99999999999999999999'];
$rejected = 0;
foreach ($malformed as $candidate) {
    $gateway->checkResult = ['status'=>'1', 'value'=>$candidate, 'amount'=>$candidate, 'declared_value'=>$candidate, 'request_id'=>$target['request_id']];
    if ($card->reconcile($card->find($target['request_id']), true) === 'review') { $rejected++; }
}
checkSame('every malformed or mis-sized provider amount was parked for review', count($malformed), $rejected);
checkSame('nothing was credited during the malformed attempts', '0.00', wallet($db, 1));
checkSame('no ledger row was written', 0, countRows($db, 'gtf_ledger'));
checkSame('the order remains in review', 'REVIEW', $card->find($target['request_id'])['status']);

// --- A11: the shipped lock is restored ------------------------------------
section('A11 the lock is closed again for the rest of the session');
$_ENV['GTF_ALLOW_CREDITING'] = 'false';
$card = $newService();
seed($db);
$locked = $card->create(1, ['telco' => 'VIETTEL', 'amount' => '10000', 'serial' => 'SERI999999', 'pin' => 'PIN9999999']);
$gateway->checkResult = ['status' => '1', 'value' => '10000', 'amount' => '10000', 'declared_value' => '10000', 'request_id' => $locked['request_id']];
checkSame('a provider-confirmed card is parked, not paid', 'review', $card->reconcile($card->find($locked['request_id']), true));
checkSame('the reason names the missing contract', 'credit_locked_pending_contract', $card->find($locked['request_id'])['reason']);
checkSame('the wallet is untouched', '0.00', wallet($db, 1));
checkSame('no ledger row exists under the lock', 0, countRows($db, 'gtf_ledger'));

echo "\ngtf_credit_path_test: {$GLOBALS['credit_pass']} passed, {$GLOBALS['credit_fail']} failed\n";
exit($GLOBALS['credit_fail'] === 0 ? 0 : 1);
