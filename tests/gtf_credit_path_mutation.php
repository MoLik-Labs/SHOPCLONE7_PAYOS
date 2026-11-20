<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}

/**
 * Self-check for gtf_credit_path_test.php: proves the exactly-once assertions are load-bearing.
 *
 * The credit suite asserts that a duplicate ledger write is rejected and that a replayed
 * reconcile cannot pay twice. This file re-runs those two probes against a deliberately
 * DEGRADED schema (no UNIQUE key on gtf_ledger.transid) and asserts that the guard disappears —
 * i.e. the protection really comes from the unique key, and the suite would notice if the key
 * were dropped by a future migration.
 *
 * If a probe here ever reports "the mutation was NOT detected", the corresponding credit-suite
 * assertion is vacuous and must not be quoted as evidence.
 *
 * Usage: same gate as the credit suite (GTF_TEST_DSN must contain "test").
 */

if (!defined('IN_SITE')) {
    define('IN_SITE', true);
}
error_reporting(E_ALL & ~E_DEPRECATED);

$gtfDsn = (string) getenv('GTF_TEST_DSN');
if ($gtfDsn === '' || (stripos($gtfDsn, 'test') === false && getenv('GTF_TEST_FORCE') !== 'yes')) {
    fwrite(STDERR, "REFUSED: set GTF_TEST_DSN to a disposable test database.\n");
    exit(2);
}

$_ENV['SECRET_KEY'] = 'mutation-test-secret';
$_ENV['GTF_API_BASE'] = 'https://api.example.test';
$_ENV['GTF_CARD_REQUEST_FORMAT'] = 'json';
$_ENV['GTF_PARTNER_ID'] = 'test-partner';
$_ENV['GTF_PARTNER_KEY'] = 'test-key';
$_ENV['GTF_CARD_ENABLED'] = 'true';
$_ENV['GTF_CARD_FEE_PERCENT'] = '0';
$_ENV['GTF_ALLOW_CREDITING'] = 'true';

require_once dirname(__DIR__) . '/libs/gtf.php';

$GLOBALS['mutation_pass'] = 0;
$GLOBALS['mutation_fail'] = 0;
function mutationCheck($label, $condition)
{
    if ($condition) {
        $GLOBALS['mutation_pass']++;
        echo "  detected {$label}\n";
        return;
    }
    $GLOBALS['mutation_fail']++;
    fwrite(STDERR, "  NOT DETECTED {$label}\n");
}

$db = new PDO($gtfDsn, (string) getenv('GTF_TEST_DB_USER'), (string) getenv('GTF_TEST_DB_PASS'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$db->exec('DROP TABLE IF EXISTS `gtf_ledger`,`users`,`dongtien`,`deposit_log`,`payment_bank`,`aff_log`,`settings`');
$db->exec("CREATE TABLE `users` (`id` INT NOT NULL AUTO_INCREMENT, `money` DECIMAL(20,2) NOT NULL DEFAULT 0,
    `total_money` DECIMAL(20,2) NOT NULL DEFAULT 0, `debit` DECIMAL(20,2) NOT NULL DEFAULT 0, `banned` TINYINT NOT NULL DEFAULT 0,
    `ref_id` INT NOT NULL DEFAULT 0, `ref_ck` DECIMAL(10,2) NOT NULL DEFAULT 0, `ref_price` DECIMAL(20,2) NOT NULL DEFAULT 0,
    `ref_total_price` DECIMAL(20,2) NOT NULL DEFAULT 0, `ref_amount` DECIMAL(20,2) NOT NULL DEFAULT 0,
    `token` VARCHAR(64) NOT NULL DEFAULT '', PRIMARY KEY (`id`)) ENGINE=InnoDB");
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
// THE MUTATION: no UNIQUE key on transid.
$db->exec("CREATE TABLE `gtf_ledger` (`id` BIGINT NOT NULL AUTO_INCREMENT, `transid` VARCHAR(80) NOT NULL, `user_id` INT NOT NULL,
    `method` VARCHAR(64) NOT NULL DEFAULT '', `gross` DECIMAL(20,2) NOT NULL DEFAULT 0, `credited` DECIMAL(20,2) NOT NULL DEFAULT 0,
    `reason` VARCHAR(255) NOT NULL DEFAULT '', `created_at` DATETIME NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB");
$db->exec("INSERT INTO users (id,money,total_money,debit,banned,ref_id,ref_ck,ref_price,ref_total_price,ref_amount,token) VALUES (1,0,0,0,0,0,0,0,0,0,'tok')");
$db->exec("INSERT INTO settings (name,value) VALUES ('affiliate_status','0'),('affiliate_ck','0')");

$params = [
    'user_id' => 1,
    'credited' => '10000',
    'gross' => '10000',
    'method' => 'GachtheFast',
    'reason' => 'mutation probe',
    'transid' => 'GTF_CARD_MUTATIONPROBE1',
];

// Probe 1: with the unique key present, the second identical write fails. (Reference schema.)
$db->exec('ALTER TABLE `gtf_ledger` ADD UNIQUE KEY `transid` (`transid`)');
$db->beginTransaction();
$first = GtfCredit::applyWithin($db, $params, function () {
    return true;
});
$db->commit();
$duplicateRejectedWithKey = false;
$db->beginTransaction();
try {
    GtfCredit::applyWithin($db, $params, function () {
        return true;
    });
} catch (PDOException $error) {
    $duplicateRejectedWithKey = true;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

// Probe 2: drop the key and repeat — the duplicate must now be accepted.
$db->exec('ALTER TABLE `gtf_ledger` DROP INDEX `transid`');
$db->beginTransaction();
$acceptedWithoutKey = false;
try {
    GtfCredit::applyWithin($db, $params, function () {
        return true;
    });
    $acceptedWithoutKey = true;
    $db->rollBack();
} catch (PDOException $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

echo "mutation self-check\n";
mutationCheck('probe 1: with the unique key, a duplicate credit is rejected (' . ($first === 'credited' ? 'first write = ' . $first : 'unexpected') . ')', $duplicateRejectedWithKey);
mutationCheck('probe 2: without the unique key, the same duplicate is accepted (so the suite is not vacuous)', $acceptedWithoutKey);

echo "\ngtf_credit_path_mutation: {$GLOBALS['mutation_pass']} detected, {$GLOBALS['mutation_fail']} NOT detected\n";
exit($GLOBALS['mutation_fail'] === 0 ? 0 : 1);
