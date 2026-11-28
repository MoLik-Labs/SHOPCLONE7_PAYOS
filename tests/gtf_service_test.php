<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}

/**
 * GachtheFast card state-machine tests against a DISPOSABLE database.
 *
 * No network: the provider is replaced by the mock gateway below. No production data: the
 * harness refuses to run unless the DSN contains the word "test" (override only with
 * GTF_TEST_FORCE=yes on a throwaway database) and it DROPS and recreates its own fixture
 * tables. The legacy tables created here are a FIXTURE, not the shop's real schema: they
 * contain exactly the columns the new code reads and writes, so a green run proves the SQL and
 * the state machine against the fixture only — it does not prove the production schema.
 *
 * The provider origin used here is synthetic (https://api.example.test): the production origin
 * is not confirmed, and the code must never need a real host to be exercised.
 *
 * Usage:
 *   GTF_TEST_DSN='mysql:host=127.0.0.1;dbname=shopclone7_test' \
 *   GTF_TEST_DB_USER=root GTF_TEST_DB_PASS=secret \
 *   php tests/gtf_service_test.php
 *
 * See docs/gachthefast/13-security-hardening-retest.md for the recorded isolated runs and limits.
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

$_ENV['SECRET_KEY'] = 'service-test-secret-key';
$_ENV['GTF_API_BASE'] = 'https://api.example.test';
$_ENV['GTF_CARD_REQUEST_FORMAT'] = 'json';
$_ENV['GTF_PARTNER_ID'] = 'test-partner';
$_ENV['GTF_PARTNER_KEY'] = 'test-key';
$_ENV['GTF_CARD_ENABLED'] = 'true';
$_ENV['GTF_CARD_FEE_PERCENT'] = '0';
$_ENV['GTF_CARD_MIN_AMOUNT'] = '10000';
$_ENV['GTF_CARD_MAX_AMOUNT'] = '2000000';
$_ENV['GTF_ALLOW_CREDITING'] = 'false'; // the lock starts closed, exactly as shipped

require_once dirname(__DIR__) . '/libs/gtf.php';
require_once dirname(__DIR__) . '/libs/gtf_client.php';
require_once dirname(__DIR__) . '/libs/gtf_card.php';
require_once dirname(__DIR__) . '/libs/gtf_bank.php';

$GLOBALS['gtf_pass'] = 0;
$GLOBALS['gtf_fail'] = 0;

function check($label, $condition)
{
    if ($condition) {
        $GLOBALS['gtf_pass']++;
        echo "  ok   {$label}\n";
        return;
    }
    $GLOBALS['gtf_fail']++;
    fwrite(STDERR, "  FAIL {$label}\n");
}

function checkSame($label, $expected, $actual)
{
    check($label . ' [expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ']', $expected === $actual);
}

function checkThrows($label, callable $callback)
{
    $threw = false;
    try {
        $callback();
    } catch (Throwable $error) {
        $threw = true;
    }
    check($label, $threw);
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
        (1,0,0,0,0,0,0,0,0,0,'tok-1'),(2,0,0,0,0,0,0,0,0,0,'tok-2'),(3,0,0,0,1,0,0,0,0,0,'tok-3')");
    $db->exec("INSERT INTO settings (name,value) VALUES ('affiliate_status','0'),('affiliate_ck','0')");
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

/** Mock provider. Never used outside this harness. */
final class FakeCardGateway implements GtfCardGateway
{
    public $submitResult = ['status' => '99'];
    public $submitError = false;
    public $checkResult = ['status' => '99'];
    public $checkError = false;
    public $submits = 0;
    public $checks = 0;
    public $lastCheckTelco = '';
    public $lastCheckAmount = '';
    public $onSubmit = null;

    public function submit(string $telco, string $amount, string $code, string $serial, string $requestId): array
    {
        $this->submits++;
        if ($this->onSubmit !== null) {
            $hook = $this->onSubmit;
            $this->onSubmit = null;
            $hook($requestId);
        }
        if ($this->submitError) {
            throw new RuntimeException('mock transport failure');
        }
        return $this->submitResult;
    }

    public function check(string $telco, string $code, string $serial, string $amount, string $requestId): array
    {
        $this->checks++;
        $this->lastCheckTelco = $telco;
        $this->lastCheckAmount = $amount;
        if ($this->checkError) {
            throw new RuntimeException('mock transport failure');
        }
        return $this->checkResult;
    }
}

// --- fixture schema --------------------------------------------------------
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
    `noidung` VARCHAR(255) NOT NULL DEFAULT '', `transid` VARCHAR(64) NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `transid` (`transid`)) ENGINE=InnoDB");
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

$cardGateway = new FakeCardGateway();
$card = new GtfCardService($db, $cardGateway, gtf_config());

// --- C0: the credit lock --------------------------------------------------
section('C0 nothing can credit while GTF_ALLOW_CREDITING is unset');
seed($db);
$cardGateway->submitResult = ['status' => '99'];
$cardGateway->checkError = false;
$locked = $card->create(1, ['telco' => 'VIETTEL', 'amount' => '100000', 'serial' => 'SERI123456', 'pin' => 'PIN1234567']);
checkSame('the order is accepted for verification while submissions are enabled', 'pending', $locked['status']);
$cardGateway->checkResult = ['status' => '1', 'value' => '100000', 'amount' => '100000', 'declared_value' => '100000', 'request_id' => $locked['request_id']];
checkSame('a provider-confirmed card is parked, not credited', 'review', $card->reconcile($card->find($locked['request_id']), true));
checkSame('the parked order records why', 'credit_locked_pending_contract', $card->find($locked['request_id'])['reason']);
checkSame('the wallet is untouched while crediting is locked', '0.00', wallet($db, 1));
checkSame('no ledger row is written while crediting is locked', 0, countRows($db, 'gtf_ledger'));
check('the internal credit routine cannot be called directly', (new ReflectionMethod('GtfCardService', 'credit'))->isPrivate());
checkThrows('operator credit refuses while the lock is closed', function () use ($card, $locked) {
    $card->adminCredit((int) $card->find($locked['request_id'])['id'], '100000', 'manual');
});
checkSame('the wallet is still untouched', '0.00', wallet($db, 1));

// unlock for the remaining sections, exactly as an owner decision would
$_ENV['GTF_ALLOW_CREDITING'] = 'true';
$card = new GtfCardService($db, $cardGateway, gtf_config());
check('the lock now reports open', GtfCredit::isEnabled(gtf_config()) === true);

// --- C1: feature flag ------------------------------------------------------
section('C1 the card surface is disabled by default');
seed($db);
$_ENV['GTF_CARD_ENABLED'] = 'false';
$disabled = new GtfCardService($db, $cardGateway, gtf_config());
checkThrows('create() refuses while GTF_CARD_ENABLED=false', function () use ($disabled) {
    $disabled->create(1, ['telco' => 'VIETTEL', 'amount' => '100000', 'serial' => 'SERI123456', 'pin' => 'PIN1234567']);
});
checkSame('nothing was written to the ledger', 0, countRows($db, 'gtf_ledger'));
$_ENV['GTF_CARD_ENABLED'] = 'true';
$card = new GtfCardService($db, $cardGateway, gtf_config());

// --- C2: durable order, no credit while pending -----------------------------
section('C2 the local order is committed before the provider call');
seed($db);
$cardGateway->submitResult = ['status' => '99'];
$created = $card->create(1, ['telco' => 'VIETTEL', 'amount' => '100000', 'serial' => 'SERI123456', 'pin' => 'PIN1234567']);
checkSame('a provider-pending response is reported as pending', 'pending', $created['status']);
checkSame('the local order exists exactly once', 1, countRows($db, 'gtf_card_orders'));
$order = $card->find($created['request_id']);
checkSame('the order is PENDING', 'PENDING', $order['status']);
checkSame('nothing is credited while pending', '0.00', wallet($db, 1));
checkSame('no ledger row exists while pending', 0, countRows($db, 'gtf_ledger'));
check('the plaintext serial is not stored', strpos((string) $order['serial_sealed'], 'SERI123456') === false);
check('only the masked serial is readable', $order['serial_masked'] !== 'SERI123456' && substr((string) $order['serial_masked'], -3) === '456');

// --- C3: verified credit ----------------------------------------------------
section('C3 a bound, agreeing check credits exactly once');
$cardGateway->checkResult = ['status' => '1', 'value' => '100000', 'amount' => '100000', 'declared_value' => '100000', 'request_id' => $created['request_id'], 'currency' => 'VND'];
checkSame('reconcile credits the confirmed order', 'credited', $card->reconcile($card->find($created['request_id']), true));
checkSame('check is sent with the documented telco field', 'VIETTEL', $cardGateway->lastCheckTelco);
checkSame('check is sent with the documented amount field', '100000', $cardGateway->lastCheckAmount);
checkSame('the wallet is credited once', '100000.00', wallet($db, 1));
checkSame('the ledger has one row', 1, countRows($db, 'gtf_ledger'));
checkSame('dongtien has one row', 1, countRows($db, 'dongtien'));
checkSame('payment_bank has one row', 1, countRows($db, 'payment_bank'));
checkSame('the ledger id is bound to the local request', 'GTF_CARD_' . $created['request_id'], (string) $db->query('SELECT transid FROM gtf_ledger')->fetchColumn());
$paid = $card->find($created['request_id']);
checkSame('the order is PAID', 'PAID', $paid['status']);
check('card data is shredded after crediting', $paid['serial_sealed'] === null && $paid['code_sealed'] === null);

// --- C4: replay safety ------------------------------------------------------
section('C4 replays cannot credit a second time');
$sign = GtfCardClient::sign('test-key', 'PIN1234567', 'SERI123456');
$replay = ['request_id' => $created['request_id'], 'callback_sign' => $sign, 'code' => 'PIN1234567', 'serial' => 'SERI123456', 'status' => '1'];
checkSame('a replayed callback is recognised', 'already_credited', $card->callback($replay));
checkSame('a second replay is recognised', 'already_credited', $card->callback($replay));
checkSame('the wallet is unchanged by replays', '100000.00', wallet($db, 1));
checkSame('the ledger still has one row', 1, countRows($db, 'gtf_ledger'));
checkSame('reconciling again is a no-op', 'already_credited', $card->reconcile($card->find($created['request_id']), true));

// --- C5: callbacks are never trusted for money ------------------------------
section('C5 a callback never decides money');
$cardGateway->submitResult = ['status' => '99'];
$second = $card->create(1, ['telco' => 'MOBIFONE', 'amount' => '50000', 'serial' => 'SERI999888', 'pin' => 'PIN9998887']);
$before = wallet($db, 1);
$cardGateway->checkResult = ['status' => '99', 'request_id' => $second['request_id']];
checkSame('a valid signature alone does not credit', 'pending', $card->callback([
    'request_id' => $second['request_id'], 'callback_sign' => GtfCardClient::sign('test-key', 'PIN9998887', 'SERI999888'),
    'code' => 'PIN9998887', 'serial' => 'SERI999888', 'status' => '1', 'value' => '50000',
]));
checkSame('the wallet is untouched after the callback', $before, wallet($db, 1));
checkSame('the order is still open', null, $card->find($second['request_id'])['credited_at']);
$eventCount = countRows($db, 'gtf_events');
$checkCount = $cardGateway->checks;
for ($i = 0; $i < 20; ++$i) {
    checkThrows('a forged signature is rejected', function () use ($card, $second, $i) {
        $card->callback(['request_id' => $second['request_id'], 'callback_sign' => str_repeat('a', 32), 'code' => 'PIN9998887', 'serial' => 'SERI999888', 'status' => (string) $i]);
    });
}
checkSame('forged callbacks do not write events', $eventCount, countRows($db, 'gtf_events'));
checkSame('forged callbacks do not call the provider', $checkCount, $cardGateway->checks);
checkSame('a forged callback credits nothing', $before, wallet($db, 1));
for ($i = 0; $i < 3; ++$i) {
    checkSame('an unknown order is ignored', 'ignored', $card->callback([
        'request_id' => 'ZZZZZZ1700000000', 'callback_sign' => GtfCardClient::sign('test-key', 'PIN9998887', 'SERI999888'),
        'code' => 'PIN9998887', 'serial' => 'SERI999888', 'status' => '1',
    ]));
}
checkSame('unknown orders do not create unbound events', $eventCount, countRows($db, 'gtf_events'));
checkThrows('a signature for another card cannot trigger a check on this order', function () use ($card, $second) {
    $card->callback(['request_id'=>$second['request_id'], 'callback_sign'=>GtfCardClient::sign('test-key', 'PINOTHER99', 'SEROTHER99'),
        'code'=>'PINOTHER99', 'serial'=>'SEROTHER99', 'status'=>'1']);
});
checkSame('cross-card callback did not call the provider', $checkCount, $cardGateway->checks);
checkSame('cross-card callback did not write an event', $eventCount, countRows($db, 'gtf_events'));
for ($i = 0; $i < 5; ++$i) {
    checkSame('immediate replay is throttled', 'busy', $card->callback(['request_id'=>$second['request_id'], 'callback_sign'=>GtfCardClient::sign('test-key', 'PIN9998887', 'SERI999888'),
        'code'=>'PIN9998887', 'serial'=>'SERI999888', 'status'=>(string)$i, 'trans_id'=>'CHANGED'.$i]));
}
checkSame('tampered unsigned status and reference cannot amplify callback event writes', $eventCount, countRows($db, 'gtf_events'));
checkSame('immediate callback replays do not amplify provider checks', $checkCount, $cardGateway->checks);
$db->prepare('UPDATE gtf_card_orders SET provider_checked_at=DATE_SUB(NOW(), INTERVAL 20 SECOND) WHERE request_id=?')->execute([$second['request_id']]);
checkSame('a later callback can be verified after the cooldown', 'pending', $card->callback([
    'request_id'=>$second['request_id'], 'callback_sign'=>GtfCardClient::sign('test-key', 'PIN9998887', 'SERI999888'),
    'code'=>'PIN9998887', 'serial'=>'SERI999888', 'status'=>'1',
]));
checkSame('a later callback triggers one new provider check', $checkCount + 1, $cardGateway->checks);
checkSame('a later callback remains one audit event per order', $eventCount, countRows($db, 'gtf_events'));
checkThrows('nested callback fields are rejected without a PHP warning', function () use ($card, $second) {
    $card->callback(['request_id'=>$second['request_id'], 'callback_sign'=>['bad'], 'code'=>'PIN9998887', 'serial'=>'SERI999888']);
});

// --- C6: conflicts are parked ----------------------------------------------
section('C6 amount conflicts and unbound answers are parked');
$cardGateway->checkResult = ['status' => '1', 'value' => '200000', 'amount' => '200000', 'declared_value' => '200000', 'request_id' => $second['request_id']];
checkSame('a larger provider value is not credited', 'review', $card->reconcile($card->find($second['request_id']), true));
checkSame('the wallet is untouched on conflict', $before, wallet($db, 1));
checkSame('the order is parked', 'REVIEW', $card->find($second['request_id'])['status']);
checkSame('the reason is recorded', 'amount_conflict', $card->find($second['request_id'])['reason']);
check('card data is kept while undecided', $card->find($second['request_id'])['code_sealed'] !== null);

$cardGateway->submitResult = ['status' => '99'];
$third = $card->create(1, ['telco' => 'VINAPHONE', 'amount' => '20000', 'serial' => 'SERI777666', 'pin' => 'PIN7776665']);
$cardGateway->checkResult = ['status' => '1', 'value' => '20000', 'amount' => '20000', 'declared_value' => '20000', 'request_id' => $created['request_id']];
checkSame('an answer bound to another order cannot credit', 'review', $card->reconcile($card->find($third['request_id']), true));
checkSame('the unbound reason is recorded', 'check_not_bound_to_order', $card->find($third['request_id'])['reason']);
checkSame('the wallet is untouched by an unbound answer', $before, wallet($db, 1));

// --- C7: ambiguity never becomes failure ------------------------------------
section('C7 ambiguous outcomes stay recoverable');
$cardGateway->submitError = true;
$unknown = $card->create(1, ['telco' => 'ZING', 'amount' => '10000', 'serial' => 'SERI555444', 'pin' => 'PIN5554443']);
checkSame('a submit timeout is reported as unknown', 'unknown', $unknown['status']);
checkSame('the order is UNKNOWN, never REJECTED', 'UNKNOWN', $card->find($unknown['request_id'])['status']);
checkSame('a submit timeout credits nothing', $before, wallet($db, 1));
$cardGateway->submitError = false;
$cardGateway->checkError = true;
checkThrows('a check timeout surfaces as an error', function () use ($card, $unknown) {
    $card->reconcile($card->find($unknown['request_id']), true);
});
checkSame('the order stays UNKNOWN', 'UNKNOWN', $card->find($unknown['request_id'])['status']);
checkSame('the worker queue picks the order up', 'UNKNOWN', (string) $card->pendingForReconcile(50)[0]['status']);

// --- C8: database-level idempotency -----------------------------------------
section('C8 the unique ledger key is the last line of defence');
$holder = $card->find($third['request_id']);
$db->beginTransaction();
checkThrows('a second credit with the same transid is refused', function () use ($db, $created) {
    GtfCredit::applyWithin($db, ['user_id' => 1, 'credited' => '20000', 'gross' => '20000', 'method' => 'GachtheFast', 'reason' => 'duplicate', 'transid' => 'GTF_CARD_' . $created['request_id']], function () {
        return true;
    });
});
if ($db->inTransaction()) {
    $db->rollBack();
}
checkSame('the refused credit changed nothing', '100000.00', wallet($db, 1));
checkSame('the ledger still holds one row for that order', 1, (int) $db->query("SELECT COUNT(*) FROM gtf_ledger WHERE transid = 'GTF_CARD_" . $created['request_id'] . "'")->fetchColumn());

// --- C9: terminal statuses ---------------------------------------------------
section('C9 terminal provider statuses never credit');
$cardGateway->checkError = false;
$cardGateway->checkResult = ['status' => '3', 'request_id' => $holder['request_id']];
checkSame('status 3 is terminal', 'terminal', $card->reconcile($card->find($holder['request_id']), true));
checkSame('the order is REJECTED', 'REJECTED', $card->find($holder['request_id'])['status']);
checkSame('nothing is credited', $before, wallet($db, 1));

// --- C10: operator credit ----------------------------------------------------
section('C10 an operator rechecks instead of choosing a credit amount');
seed($db);
$cardGateway->submitResult = ['status' => '99'];
$parked = $card->create(1, ['telco' => 'VIETTEL', 'amount' => '100000', 'serial' => 'SERI121212', 'pin' => 'PIN1212121']);
$cardGateway->checkResult = ['status' => '2', 'value' => '90000', 'amount' => '90000', 'declared_value' => '90000', 'request_id' => $parked['request_id']];
checkSame('status 2 goes to manual review', 'review', $card->reconcile($card->find($parked['request_id']), true));
$cardRow = $card->find($parked['request_id']);
checkSame('operator cannot override ambiguous provider result', 'review', $card->adminCredit((int) $cardRow['id'], '100000', 'checked by hand'));
checkSame('the wallet is unchanged', '0.00', wallet($db, 1));
$cardGateway->checkResult = ['status' => '1', 'value' => '100000', 'amount' => '100000', 'declared_value' => '100000', 'request_id' => $parked['request_id']];
checkSame('a fresh matching provider result credits once', 'credited', $card->adminCredit((int) $cardRow['id'], '100000', 'checked'));
checkThrows('the operator recheck cannot run twice', function () use ($card, $cardRow) {
    $card->adminCredit((int) $cardRow['id'], '100000', 'again');
});
checkSame('the duplicate changed nothing', '100000.00', wallet($db, 1));

// --- C11: the bank boundary --------------------------------------------------
section('C11 the bank boundary refuses every operation');
$bank = new GtfBankService($db, gtf_config());
checkSame('the bank flow is not available', false, $bank->isAvailable());
checkThrows('polling is refused', function () use ($bank) {
    $bank->poll(100);
});
checkThrows('bank crediting is refused', function () use ($bank) {
    $bank->credit(1, 1, '100000');
});
checkThrows('bank review queue is refused', function () use ($bank) {
    $bank->reviewQueue(10);
});
check('the refusal explains the blocker', strpos($bank->unavailableReason(), 'hợp đồng') !== false);

// --- C12: out-of-order provider events ---------------------------------------
section('C12 a callback that arrives before the submit result still credits exactly once');
seed($db);
$cardGateway->checkError = false;
$cardGateway->submitError = false;
$cardGateway->submitResult = ['status' => '99'];
$outOfOrder = $card->create(1, ['telco' => 'VIETTEL', 'amount' => '100000', 'serial' => 'SERI313131', 'pin' => 'PIN3131313']);
$outOfOrderId = (int) $card->find($outOfOrder['request_id'])['id'];
$cardGateway->checkResult = ['status' => '1', 'value' => '100000', 'amount' => '100000', 'declared_value' => '100000', 'request_id' => $outOfOrder['request_id']];
checkSame('the provider callback credits the order it is bound to', 'credited', $card->callback([
    'request_id' => $outOfOrder['request_id'],
    'callback_sign' => GtfCardClient::sign('test-key', 'PIN3131313', 'SERI313131'),
    'code' => 'PIN3131313', 'serial' => 'SERI313131', 'status' => '1',
]));
checkSame('the callback moved the wallet once', '100000.00', wallet($db, 1));
// The submit response for that same card arrives late, claiming the card is still pending.
checkSame('a late submit/check answer cannot downgrade the paid order', 'already_credited', $card->reconcile($card->find($outOfOrder['request_id']), true));
$cardGateway->checkResult = ['status' => '3', 'request_id' => $outOfOrder['request_id']];
checkSame('a late terminal status cannot reverse a paid order', 'already_credited', $card->reconcile($card->find($outOfOrder['request_id']), true));
checkSame('the order is still PAID', 'PAID', $card->find($outOfOrder['request_id'])['status']);
checkSame('the wallet still holds exactly one credit', '100000.00', wallet($db, 1));
checkSame('the ledger still holds exactly one row for it', 1, (int) $db->query("SELECT COUNT(*) FROM gtf_ledger WHERE transid = 'GTF_CARD_" . $outOfOrder['request_id'] . "'")->fetchColumn());

// A callback can complete while the initial submit() is still waiting for a response.
section('C12b late submit answers cannot overwrite an already-paid order');
foreach (['99', '1', '2', '3', '4', '100', 'undocumented', 'timeout'] as $lateStatus) {
    seed($db);
    $cardGateway->submitError = $lateStatus === 'timeout';
    $cardGateway->submitResult = ['status' => $lateStatus];
    $cardGateway->onSubmit = function ($requestId) use ($cardGateway, $card) {
        $cardGateway->checkResult = ['status'=>'1', 'value'=>'100000', 'amount'=>'100000', 'declared_value'=>'100000', 'request_id'=>$requestId];
        if ($card->reconcile($card->find($requestId), true) !== 'credited') { throw new RuntimeException('fixture callback did not credit'); }
    };
    $late = $card->create(1, ['telco'=>'VIETTEL', 'amount'=>'100000', 'serial'=>'SERILATE12', 'pin'=>'PINLATE123']);
    $lateRow = $card->find($late['request_id']);
    checkSame('late ' . $lateStatus . ' returns confirmed success', 'success', $late['status']);
    checkSame('late ' . $lateStatus . ' leaves PAID intact', 'PAID', $lateRow['status']);
    checkSame('late ' . $lateStatus . ' leaves one wallet credit', '100000.00', wallet($db, 1));
    checkSame('late ' . $lateStatus . ' leaves one ledger row', 1, countRows($db, 'gtf_ledger'));
}
$cardGateway->submitError = false;
$cardGateway->submitResult = ['status' => '99'];

// --- C13: concurrent crediting ------------------------------------------------
section('C13 two database connections cannot credit the same order twice');
seed($db);
$db2 = new PDO($gtfDsn, (string) getenv('GTF_TEST_DB_USER'), (string) getenv('GTF_TEST_DB_PASS'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$card2 = new GtfCardService($db2, $cardGateway, gtf_config());

$race = $card->create(1, ['telco' => 'MOBIFONE', 'amount' => '50000', 'serial' => 'SERI414141', 'pin' => 'PIN4141414']);
$raceRowOnConn1 = $card->find($race['request_id']);
$raceRowOnConn2 = $card2->find($race['request_id']);
check('both workers see the order as creditable', $raceRowOnConn1['credited_at'] === null && $raceRowOnConn2['credited_at'] === null);
$cardGateway->checkResult = ['status' => '1', 'value' => '50000', 'amount' => '50000', 'declared_value' => '50000', 'request_id' => $race['request_id']];
checkSame('worker 1 credits the order', 'credited', $card->reconcile($cardRow = $card->find($race['request_id']), true));
check('worker 1 marked it paid', $card->find($race['request_id'])['credited_at'] !== null);
checkSame('worker 2, holding a stale row, is refused', 'already_credited', $card2->reconcile($raceRowOnConn2, true));
checkSame('the wallet received exactly one credit', '50000.00', wallet($db, 1));
checkSame('the ledger holds exactly one row for the raced order', 1, (int) $db->query("SELECT COUNT(*) FROM gtf_ledger WHERE transid = 'GTF_CARD_" . $race['request_id'] . "'")->fetchColumn());

// A held row lock must make a concurrent credit fail cleanly, not half-apply.
$locked = $card->create(1, ['telco' => 'VINAPHONE', 'amount' => '20000', 'serial' => 'SERI515151', 'pin' => 'PIN5151515']);
$lockedRow = $card->find($locked['request_id']);
$cardGateway->checkResult = ['status'=>'1', 'value'=>'20000', 'amount'=>'20000', 'declared_value'=>'20000', 'request_id'=>$locked['request_id']];
$before = wallet($db, 1);
$db2->beginTransaction();
$db2->prepare('SELECT id FROM gtf_card_orders WHERE id = ? FOR UPDATE')->execute([(int) $lockedRow['id']]);
$db->exec('SET innodb_lock_wait_timeout = 1');
checkThrows('a credit blocked by another connection fails instead of waiting forever', function () use ($card, $lockedRow) {
    $card->reconcile($lockedRow, true);
});
$db->exec('SET innodb_lock_wait_timeout = 50');
checkSame('the blocked credit moved no money', $before, wallet($db, 1));
checkSame('the blocked credit wrote no ledger row', 0, (int) $db->query("SELECT COUNT(*) FROM gtf_ledger WHERE transid = 'GTF_CARD_" . $locked['request_id'] . "'")->fetchColumn());
$db2->rollBack();
checkSame('once the lock is released the credit succeeds', 'credited', $card->reconcile($card->find($locked['request_id']), true));
checkSame('and it is still exactly once', 1, (int) $db->query("SELECT COUNT(*) FROM gtf_ledger WHERE transid = 'GTF_CARD_" . $locked['request_id'] . "'")->fetchColumn());

// --- C14: redaction ------------------------------------------------------------
section('C14 card secrets never reach stored text, events or errors');
$sensitive = ['SERI313131', 'PIN3131313', 'SERI414141', 'PIN4141414', 'SERI123456', 'PIN1234567'];
$leaks = [];
foreach ($db->query('SELECT request_id,status,reason,serial_masked,credited_amount FROM gtf_card_orders')->fetchAll() as $row) {
    $haystack = (string) $row['request_id'] . '|' . (string) $row['reason'] . '|' . (string) $row['serial_masked'] . '|' . (string) $row['status'];
    foreach ($sensitive as $needle) {
        if (strpos($haystack, $needle) !== false) {
            $leaks[] = $needle . ' in order ' . $row['request_id'];
        }
    }
}
check('no raw card data appears in order rows', $leaks === [], implode(', ', $leaks));
$leaks = [];
foreach ($db->query('SELECT reason FROM gtf_ledger')->fetchAll() as $row) {
    foreach ($sensitive as $needle) {
        if (strpos((string) $row['reason'], $needle) !== false) {
            $leaks[] = $needle;
        }
    }
}
check('no raw card data appears in ledger reasons', $leaks === [], implode(', ', $leaks));
$masked = (string) $db->query("SELECT serial_masked FROM gtf_card_orders WHERE request_id = '" . $race['request_id'] . "'")->fetchColumn();
check('only the last three characters of the serial are readable', substr($masked, -3) === '141' && strpos($masked, 'SERI') === false, $masked);
$callback = $card->callback([
    'request_id' => $race['request_id'],
    'callback_sign' => GtfCardClient::sign('test-key', 'PIN4141414', 'SERI414141'),
    'code' => 'PIN4141414', 'serial' => 'SERI414141', 'status' => "1\x00<svg>", 'trans_id' => "TR\x01ANS\x7f-1",
]);
checkSame('a hostile callback is still only "already_credited"', 'already_credited', $callback);
$polluted = [];
foreach ($db->query('SELECT provider_status,provider_ref FROM gtf_events')->fetchAll() as $row) {
    foreach (['provider_status' => (string) $row['provider_status'], 'provider_ref' => (string) $row['provider_ref']] as $column => $value) {
        if ($value !== '' && preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            $polluted[] = $column . '=' . $value;
        }
    }
}
check('event rows carry only sanitised provider text', $polluted === [], implode(', ', $polluted));

echo "\ngtf_service_test: {$GLOBALS['gtf_pass']} passed, {$GLOBALS['gtf_fail']} failed\n";
exit($GLOBALS['gtf_fail'] === 0 ? 0 : 1);
