<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!defined('IN_SITE')) { define('IN_SITE', true); }
require_once __DIR__ . '/../libs/payos_bootstrap.php';

try {
    $db = payos_db();
    $columns = [
        'users' => ['money','total_money','debit','ref_amount','ref_price','ref_total_price'],
        'dongtien' => ['sotientruoc','sotienthaydoi','sotiensau'],
        'payment_bank' => ['amount','received'],
        'deposit_log' => ['amount','received'],
        'aff_log' => ['sotientruoc','sotienthaydoi','sotienhientai'],
    ];
    // Verify the existing installation before applying any DDL.
    foreach ($columns as $table => $fields) {
        $query = $db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $query->execute([$table]);
        if (!$query->fetchColumn()) {
            throw new RuntimeException('Missing required table: ' . $table . '. Restore the existing shop database first.');
        }
        $db->query('SELECT `' . implode('`,`', $fields) . '` FROM `' . $table . '` LIMIT 0');
    }
    foreach ($columns as $table => $fields) {
        $changes = array_map(static function ($field) {
            return 'MODIFY `' . $field . '` DECIMAL(20,2) NOT NULL DEFAULT 0';
        }, $fields);
        $db->exec('ALTER TABLE `' . $table . '` ENGINE=InnoDB, ' . implode(', ', $changes));
    }
    $db->exec("CREATE TABLE IF NOT EXISTS payos_orders (
        order_code BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        user_id INT NOT NULL,
        amount BIGINT UNSIGNED NOT NULL,
        credited_amount DECIMAL(20,2) NOT NULL,
        payment_link_id VARCHAR(128) NULL,
        checkout_url TEXT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'CREATING',
        expires_at INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        credited_at DATETIME NULL,
        last_checked_at DATETIME NULL,
        UNIQUE KEY payos_link (payment_link_id),
        KEY payos_user_created (user_id,created_at),
        KEY payos_status (status,expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "payOS migration complete. Existing balances and payment history preserved.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . "\n");
    exit(1);
}
