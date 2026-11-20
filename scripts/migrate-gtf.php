<?php

/**
 * GachtheFast schema migration.
 *
 * Creates only NEW tables (gtf_*) and never modifies or deletes existing payment data.
 * It refuses to proceed when the legacy tables that the credit transaction writes to are
 * not transactional, because a rollback could then leave a wallet without its ledger row.
 *
 * Usage:
 *   php scripts/migrate-gtf.php               # create tables (fails closed on unsafe engines)
 *   php scripts/migrate-gtf.php --convert     # also convert the required legacy tables to InnoDB
 *   php scripts/migrate-gtf.php --rollback    # drop ONLY the gtf_* tables
 *
 * Always back up the database before running with --convert: DDL cannot be rolled back.
 *
 * Scope note: this migration covers the CARD flow only. There is no bank table because there
 * is no confirmed GachtheFast bank-transfer contract, and none may be invented here.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!defined('IN_SITE')) {
    define('IN_SITE', true);
}

require_once __DIR__ . '/../libs/gtf_bootstrap.php';

$requiredTables = [
    'users' => ['money', 'total_money', 'debit', 'ref_id', 'ref_ck', 'ref_price', 'ref_amount', 'banned', 'token'],
    'settings' => ['name', 'value'],
    'dongtien' => ['user_id', 'sotientruoc', 'sotienthaydoi', 'sotiensau', 'thoigian', 'noidung', 'transid'],
    'deposit_log' => ['user_id', 'method', 'amount', 'received', 'create_time', 'is_virtual'],
    'payment_bank' => ['tid', 'method', 'user_id', 'description', 'amount', 'received', 'create_gettime', 'create_time'],
    'aff_log' => ['user_id', 'reason', 'sotientruoc', 'sotienthaydoi', 'sotienhientai', 'create_gettime'],
];

$gtfTables = ['gtf_card_orders', 'gtf_ledger', 'gtf_events'];

$ddl = [
    "CREATE TABLE IF NOT EXISTS gtf_card_orders (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        request_id VARCHAR(64) NOT NULL,
        user_id INT NOT NULL,
        telco VARCHAR(16) NOT NULL,
        declared_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
        credited_amount DECIMAL(20,2) NULL,
        currency CHAR(3) NOT NULL DEFAULT 'VND',
        status VARCHAR(24) NOT NULL DEFAULT 'CREATING',
        provider_status VARCHAR(16) NULL,
        provider_ref VARCHAR(64) NULL,
        serial_masked VARCHAR(32) NULL,
        serial_sealed TEXT NULL,
        code_sealed TEXT NULL,
        reason VARCHAR(191) NULL,
        attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
        submitted_at DATETIME NULL,
        provider_checked_at DATETIME NULL,
        credited_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY gtf_card_request (request_id),
        KEY gtf_card_user (user_id, created_at),
        KEY gtf_card_status (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    // Database-level idempotency guard for the credit transaction. This does NOT depend
    // on any unique key on the legacy dongtien table.
    "CREATE TABLE IF NOT EXISTS gtf_ledger (
        transid VARCHAR(96) NOT NULL PRIMARY KEY,
        user_id INT NOT NULL,
        method VARCHAR(32) NOT NULL,
        gross DECIMAL(20,2) NOT NULL DEFAULT 0,
        credited DECIMAL(20,2) NOT NULL DEFAULT 0,
        reason VARCHAR(191) NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS gtf_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        flow VARCHAR(8) NOT NULL,
        order_id BIGINT UNSIGNED NULL,
        direction VARCHAR(16) NOT NULL,
        event_key VARCHAR(64) NOT NULL,
        provider_status VARCHAR(32) NULL,
        provider_ref VARCHAR(64) NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY gtf_event_key (flow, order_id, direction, event_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

function gtf_engine(PDO $db, string $table)
{
    $statement = $db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $statement->execute([$table]);
    $engine = $statement->fetchColumn();
    return $engine === false ? null : (string) $engine;
}

try {
    $db = gtf_db();
    $action = isset($argv[1]) ? (string) $argv[1] : '';

    if ($action === '--rollback') {
        echo "Rolling back GachtheFast schema (new tables only).\n";
        foreach ($gtfTables as $table) {
            $db->exec('DROP TABLE IF EXISTS `' . $table . '`');
            echo "  dropped {$table}\n";
        }
        echo "Legacy payment tables and all existing history were left untouched.\n";
        exit(0);
    }
    if ($action !== '' && $action !== '--convert') {
        fwrite(STDERR, "Unknown option: {$action}\n");
        exit(1);
    }
    $convert = $action === '--convert';

    foreach ($requiredTables as $table => $columns) {
        if (gtf_engine($db, $table) === null) {
            throw new RuntimeException('Missing required table: ' . $table . '. Restore the shop database first.');
        }
        $db->query('SELECT `' . implode('`,`', $columns) . '` FROM `' . $table . '` LIMIT 0');
    }

    $unsafe = [];
    foreach (array_keys($requiredTables) as $table) {
        if (strcasecmp((string) gtf_engine($db, $table), 'InnoDB') !== 0) {
            $unsafe[] = $table;
        }
    }
    if ($unsafe) {
        if (!$convert) {
            fwrite(STDERR, "These tables are not InnoDB, so the GachtheFast credit transaction could not roll back safely:\n");
            foreach ($unsafe as $table) {
                fwrite(STDERR, "  - {$table} (engine: " . gtf_engine($db, $table) . ")\n");
            }
            fwrite(STDERR, "Back up the database, then re-run with: php scripts/migrate-gtf.php --convert\n");
            exit(1);
        }
        foreach ($unsafe as $table) {
            $db->exec('ALTER TABLE `' . $table . '` ENGINE=InnoDB');
            echo "converted {$table} to InnoDB\n";
        }
    }

    // A unique key on dongtien.transid is desirable but NOT required: idempotency is
    // enforced by gtf_ledger.transid. Report it instead of silently changing history.
    $hasUnique = $db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dongtien' AND COLUMN_NAME = 'transid' AND NON_UNIQUE = 0");
    $hasUnique->execute();
    $redundant = $db->prepare("SELECT COUNT(*) FROM (SELECT transid FROM dongtien WHERE transid IS NOT NULL GROUP BY transid HAVING COUNT(*) > 1) AS duplicates");
    $redundant->execute();
    $duplicates = (int) $redundant->fetchColumn();
    if ((int) $hasUnique->fetchColumn() === 0) {
        echo $duplicates === 0
            ? "note: dongtien.transid has no unique key. GachtheFast idempotency uses gtf_ledger.transid; adding a unique key to dongtien is optional.\n"
            : "warning: dongtien.transid has no unique key and contains {$duplicates} duplicate value(s). Review before ever adding one.\n";
    }

    foreach ($ddl as $statement) {
        $db->exec($statement);
    }
    echo "GachtheFast migration complete. New tables: " . implode(', ', $gtfTables) . "\n";
    echo "No existing payment row, balance or index was modified" . ($convert ? ' except the engine conversions listed above' : '') . ".\n";
    echo "Rollback: php scripts/migrate-gtf.php --rollback (drops only the gtf_* tables).\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . "\n");
    exit(1);
}
