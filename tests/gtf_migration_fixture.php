<?php

/**
 * Disposable FIXTURE for the migration test — NOT the shop's real schema.
 *
 * Creates the legacy tables the GachtheFast credit transaction writes to, with exactly the
 * columns `scripts/migrate-gtf.php` pre-flights, plus the two payment-ledger tables the
 * transaction touches. Used only against a throwaway database named by GTF_TEST_DSN.
 *
 *   GTF_MIGRATION_ENGINE=InnoDB|MyISAM   engine for the legacy tables (MyISAM triggers the
 *                                        migration's transactional-safety refusal)
 *   GTF_MIGRATION_DROP_COLUMN=1          omit users.token (triggers the column pre-flight)
 */

if (!defined('IN_SITE')) {
    define('IN_SITE', true);
}
error_reporting(E_ALL & ~E_DEPRECATED);

$dsn = (string) getenv('GTF_TEST_DSN');
if ($dsn === '' || stripos($dsn, 'test') === false) {
    fwrite(STDERR, "REFUSED: set GTF_TEST_DSN to a disposable test database.\n");
    exit(2);
}
$engine = getenv('GTF_MIGRATION_ENGINE') === 'MyISAM' ? 'MyISAM' : 'InnoDB';
$dropColumn = getenv('GTF_MIGRATION_DROP_COLUMN') === '1';

$db = new PDO($dsn, (string) getenv('GTF_TEST_DB_USER'), (string) getenv('GTF_TEST_DB_PASS'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$tables = ['gtf_card_orders', 'gtf_ledger', 'gtf_events', 'users', 'settings', 'dongtien', 'deposit_log', 'payment_bank', 'aff_log'];
foreach ($tables as $table) {
    $db->exec('DROP TABLE IF EXISTS `' . $table . '`');
}

$tokenColumn = $dropColumn ? '' : ', `token` VARCHAR(64) NOT NULL DEFAULT \'\'';
$db->exec("CREATE TABLE `users` (
    `id` INT NOT NULL AUTO_INCREMENT, `money` DECIMAL(20,2) NOT NULL DEFAULT 0, `total_money` DECIMAL(20,2) NOT NULL DEFAULT 0,
    `debit` DECIMAL(20,2) NOT NULL DEFAULT 0, `banned` TINYINT NOT NULL DEFAULT 0, `ref_id` INT NOT NULL DEFAULT 0,
    `ref_ck` DECIMAL(10,2) NOT NULL DEFAULT 0, `ref_price` DECIMAL(20,2) NOT NULL DEFAULT 0, `ref_total_price` DECIMAL(20,2) NOT NULL DEFAULT 0,
    `ref_amount` DECIMAL(20,2) NOT NULL DEFAULT 0{$tokenColumn}, PRIMARY KEY (`id`)) ENGINE={$engine} DEFAULT CHARSET=utf8mb4");
$db->exec("CREATE TABLE `settings` (`name` VARCHAR(64) NOT NULL, `value` TEXT NULL, PRIMARY KEY (`name`)) ENGINE={$engine} DEFAULT CHARSET=utf8mb4");
$db->exec("CREATE TABLE `dongtien` (`id` BIGINT NOT NULL AUTO_INCREMENT, `user_id` INT NOT NULL, `sotientruoc` DECIMAL(20,2) NOT NULL DEFAULT 0,
    `sotienthaydoi` DECIMAL(20,2) NOT NULL DEFAULT 0, `sotiensau` DECIMAL(20,2) NOT NULL DEFAULT 0, `thoigian` DATETIME NULL,
    `noidung` VARCHAR(255) NOT NULL DEFAULT '', `transid` VARCHAR(64) NOT NULL, PRIMARY KEY (`id`)) ENGINE={$engine} DEFAULT CHARSET=utf8mb4");
$db->exec("CREATE TABLE `deposit_log` (`id` BIGINT NOT NULL AUTO_INCREMENT, `user_id` INT NOT NULL, `method` VARCHAR(64) NOT NULL DEFAULT '',
    `amount` DECIMAL(20,2) NOT NULL DEFAULT 0, `received` DECIMAL(20,2) NOT NULL DEFAULT 0, `create_time` INT NOT NULL DEFAULT 0,
    `is_virtual` TINYINT NOT NULL DEFAULT 0, PRIMARY KEY (`id`)) ENGINE={$engine} DEFAULT CHARSET=utf8mb4");
$db->exec("CREATE TABLE `payment_bank` (`id` BIGINT NOT NULL AUTO_INCREMENT, `tid` VARCHAR(64) NOT NULL DEFAULT '',
    `method` VARCHAR(64) NOT NULL DEFAULT '', `user_id` INT NOT NULL, `description` VARCHAR(255) NOT NULL DEFAULT '',
    `amount` DECIMAL(20,2) NOT NULL DEFAULT 0, `received` DECIMAL(20,2) NOT NULL DEFAULT 0, `create_gettime` DATETIME NULL,
    `create_time` INT NOT NULL DEFAULT 0, PRIMARY KEY (`id`)) ENGINE={$engine} DEFAULT CHARSET=utf8mb4");
$db->exec("CREATE TABLE `aff_log` (`id` BIGINT NOT NULL AUTO_INCREMENT, `user_id` INT NOT NULL, `reason` VARCHAR(255) NOT NULL DEFAULT '',
    `sotientruoc` DECIMAL(20,2) NOT NULL DEFAULT 0, `sotienthaydoi` DECIMAL(20,2) NOT NULL DEFAULT 0, `sotienhientai` DECIMAL(20,2) NOT NULL DEFAULT 0,
    `create_gettime` DATETIME NULL, PRIMARY KEY (`id`)) ENGINE={$engine} DEFAULT CHARSET=utf8mb4");

// Present in every real shop DB; affiliate_status is '0' so credits never touch aff_log.
$db->exec("INSERT INTO `settings` (`name`,`value`) VALUES ('affiliate_status','0'),('affiliate_ck','0')");

echo "fixture ready (engine={$engine}" . ($dropColumn ? ', users.token omitted' : '') . ")\n";
