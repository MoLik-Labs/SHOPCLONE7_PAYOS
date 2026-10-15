<?php

/**
 * Post-conditions for the migration test.
 *
 *   GTF_MIGRATION_EXPECT=created    gtf_* exist, are InnoDB, and carry their unique keys;
 *                                   the legacy fixture tables are untouched
 *   GTF_MIGRATION_EXPECT=refused    no gtf_* table exists (the migration failed closed)
 *   GTF_MIGRATION_EXPECT=rolledback only the gtf_* tables were dropped
 */

if (!defined('IN_SITE')) {
    define('IN_SITE', true);
}
error_reporting(E_ALL & ~E_DEPRECATED);

$dsn = (string) getenv('GTF_TEST_DSN');
$expect = (string) getenv('GTF_MIGRATION_EXPECT');
if ($dsn === '' || stripos($dsn, 'test') === false || !in_array($expect, ['created', 'converted', 'refused', 'rolledback'], true)) {
    fwrite(STDERR, "REFUSED: set GTF_TEST_DSN (disposable) and GTF_MIGRATION_EXPECT=created|converted|refused|rolledback.\n");
    exit(2);
}

$GLOBALS['migration_pass'] = 0;
$GLOBALS['migration_fail'] = 0;
function verify_check($label, $condition, $detail = '')
{
    if ($condition) {
        $GLOBALS['migration_pass']++;
        echo "  ok   {$label}\n";
        return;
    }
    $GLOBALS['migration_fail']++;
    fwrite(STDERR, "  FAIL {$label}" . ($detail !== '' ? " ({$detail})" : '') . "\n");
}

$db = new PDO($dsn, (string) getenv('GTF_TEST_DB_USER'), (string) getenv('GTF_TEST_DB_PASS'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$gtfTables = ['gtf_card_orders', 'gtf_ledger', 'gtf_events'];
$exists = static function (PDO $db, string $table): bool {
    $statement = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $statement->execute([$table]);
    return (int) $statement->fetchColumn() > 0;
};

if ($expect === 'converted') {
    foreach ($gtfTables as $table) {
        verify_check("table {$table} exists after --convert", $exists($db, $table));
    }
    foreach (['users', 'settings', 'dongtien', 'deposit_log', 'payment_bank', 'aff_log'] as $table) {
        verify_check("--convert moved {$table} to InnoDB", strcasecmp((string) $db->query("SELECT engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$table}'")->fetchColumn(), 'InnoDB') === 0);
    }
} elseif ($expect === 'created') {
    foreach ($gtfTables as $table) {
        verify_check("table {$table} exists", $exists($db, $table));
    }
    $engineRows = $db->query("SELECT table_name, engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('" . implode("','", $gtfTables) . "')")->fetchAll();
    verify_check('exactly the three gtf tables were created', count($engineRows) === 3, (string) count($engineRows));
    foreach ($engineRows as $row) {
        verify_check("{$row['table_name']} is InnoDB", strcasecmp((string) $row['engine'], 'InnoDB') === 0, (string) $row['engine']);
    }
    $expectedUnique = [
        'gtf_card_orders' => ['request_id'],
        'gtf_ledger' => ['transid'],
        // As built. Note: this key cannot dedupe a replay that arrives with a different (or
        // NULL) order binding — recorded as DEF-1 in the QA matrix, not a test failure.
        'gtf_events' => ['flow', 'order_id', 'direction', 'event_key'],
    ];
    foreach ($expectedUnique as $table => $columns) {
        $uniqueIndexes = [];
        foreach ($db->query('SHOW INDEX FROM `' . $table . '`')->fetchAll() as $index) {
            if ((int) $index['Non_unique'] === 0) {
                $uniqueIndexes[(string) $index['Key_name']][(int) $index['Seq_in_index']] = (string) $index['Column_name'];
            }
        }
        $found = false;
        foreach ($uniqueIndexes as $indexColumns) {
            ksort($indexColumns);
            if (array_values($indexColumns) === $columns) {
                $found = true;
            }
        }
        verify_check("{$table} carries the unique key (" . implode(',', $columns) . ')', $found, json_encode($uniqueIndexes));
    }
    foreach (['users' => 'token', 'dongtien' => 'transid', 'deposit_log' => 'is_virtual', 'payment_bank' => 'tid', 'aff_log' => 'sotienhientai'] as $table => $column) {
        verify_check("the legacy table {$table} still has {$column}", (bool) $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'")->fetch());
    }
} elseif ($expect === 'refused') {
    foreach ($gtfTables as $table) {
        verify_check("no gtf table exists after the refusal ({$table})", !$exists($db, $table));
    }
    verify_check('the legacy fixture is still intact', (bool) $db->query('SHOW COLUMNS FROM `users` LIKE \'money\'')->fetch());
} else {
    foreach ($gtfTables as $table) {
        verify_check("rollback dropped {$table}", !$exists($db, $table));
    }
    foreach (['users', 'dongtien', 'deposit_log', 'payment_bank', 'aff_log', 'settings'] as $table) {
        verify_check("rollback left {$table} alone", $exists($db, $table));
    }
}

echo "\ngtf_migration_verify ({$expect}): {$GLOBALS['migration_pass']} passed, {$GLOBALS['migration_fail']} failed\n";
exit($GLOBALS['migration_fail'] === 0 ? 0 : 1);
