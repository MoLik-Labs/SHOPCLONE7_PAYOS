<?php
/* Destructive fixture setup in a NEW, empty, explicitly named local test database only. */
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(404); exit; }
if (getenv('ORBIT_MONEY_TEST') !== '1') { echo "SKIP: ORBIT_MONEY_TEST is not enabled; no database was touched.\n"; exit(0); }
$name = (string) getenv('ORBIT_MONEY_TEST_NAME');
$port = (string) getenv('ORBIT_MONEY_TEST_PORT');
if (!preg_match('/^orbit_test_money_[a-z0-9_]+$/D', $name) || !preg_match('/^[0-9]{1,5}$/D', $port) || (int) $port < 1 || (int) $port > 65535) {
    fwrite(STDERR, "REFUSED: use a new disposable orbit_test_money_* database on local TCP.\n"); exit(2);
}
if (!extension_loaded('mysqli') || !extension_loaded('pdo_mysql') || !is_file(__DIR__ . '/../vendor/autoload.php')) {
    fwrite(STDERR, "REFUSED: mysqli, pdo_mysql and Composer dependencies are required.\n"); exit(2);
}
define('IN_SITE', true);
require __DIR__ . '/../libs/db.php';
$_ENV['DB_HOST'] = '127.0.0.1'; $_ENV['DB_PORT'] = $port; $_ENV['DB_DATABASE'] = $name;
$_ENV['DB_USERNAME'] = (string) getenv('ORBIT_MONEY_TEST_USER');
$_ENV['DB_PASSWORD'] = (string) getenv('ORBIT_MONEY_TEST_PASSWORD');
function gettime() { return date('Y-m-d H:i:s'); }
require __DIR__ . '/../libs/database/users.php';

if (($argv[1] ?? '') === '--worker') {
    $marker = (string) ($argv[2] ?? '');
    $key = (string) ($argv[3] ?? '');
    if (!preg_match('/^ORDER_RACE_[12]$/D', $key) || strpos($marker, sys_get_temp_dir() . '/orbit-race-') !== 0) { exit(2); }
    $worker = new users();
    echo "READY\n"; flush();
    $deadline = microtime(true) + 10;
    do {
        clearstatcache(true, $marker);
        if (is_file($marker) && file_get_contents($marker) === 'go') { break; }
        usleep(10000);
    } while (microtime(true) < $deadline);
    if (!is_file($marker) || file_get_contents($marker) !== 'go') { fwrite(STDERR, "fixture barrier timeout\n"); exit(2); }
    echo $worker->DebitCreditsChecked(2, 75, 'fixture debit', $key) . "\n";
    exit(0);
}

$pdo = new PDO('mysql:host=127.0.0.1;port=' . (int) $port . ';dbname=' . $name . ';charset=utf8mb4', $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tables = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchColumn();
if ($tables !== 0) { fwrite(STDERR, "REFUSED: fixture database is not empty. No table was changed.\n"); exit(2); }
$pdo->exec('CREATE TABLE users (id INT PRIMARY KEY, money DECIMAL(20,2) NOT NULL DEFAULT 0, total_money DECIMAL(20,2) NOT NULL DEFAULT 0) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE dongtien (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, sotientruoc DECIMAL(20,2), sotienthaydoi DECIMAL(20,2), sotiensau DECIMAL(20,2), thoigian DATETIME, noidung TEXT, transid VARCHAR(128) UNIQUE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$pdo->exec('INSERT INTO users (id,money,total_money) VALUES (1,100,0),(2,100,0)');
$checks = 0;
function money_expect($condition, $message) {
    global $checks;
    ++$checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    echo "PASS  {$message}\n";
}
function balance(PDO $db, $id) { return (string) $db->query('SELECT money FROM users WHERE id=' . (int) $id)->fetchColumn(); }
$wallet = new users();
money_expect($wallet->DebitCreditsChecked(1, 60, 'fixture debit', 'ORDER_1') === 'debited' && balance($pdo, 1) === '40.00', 'debit and ledger are committed together');
money_expect($wallet->DebitCreditsChecked(1, 60, 'fixture debit', 'ORDER_2') === 'insufficient' && balance($pdo, 1) === '40.00', 'insufficient balance does not debit');
money_expect($wallet->DebitCreditsChecked(1, 0, 'free fixture', 'ORDER_FREE') === 'debited', 'zero-price order gets a ledger claim');
money_expect($wallet->DebitCreditsChecked(1, 0, 'free fixture', 'ORDER_FREE') === 'duplicate', 'zero-price duplicate does not claim twice');
money_expect($wallet->RefundCreditsChecked(1, 60, 'fixture refund', 'REFUND_1') === 'credited' && balance($pdo, 1) === '100.00', 'refund restores the wallet');
money_expect($wallet->RefundCreditsChecked(1, 60, 'fixture refund', 'REFUND_1') === 'duplicate' && balance($pdo, 1) === '100.00', 'duplicate refund cannot credit twice');
$pdo->exec("CREATE TRIGGER orbit_fixture_fail_update BEFORE UPDATE ON users FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure'");
money_expect($wallet->DebitCreditsChecked(1, 10, 'fixture failure', 'ORDER_FAIL') === 'failed' && balance($pdo, 1) === '100.00', 'failed balance update reports failure');
money_expect((int) $pdo->query("SELECT COUNT(*) FROM dongtien WHERE transid='ORDER_FAIL'")->fetchColumn() === 0, 'failed balance update rolls back the ledger');
$pdo->exec('DROP TRIGGER orbit_fixture_fail_update');

$marker = tempnam(sys_get_temp_dir(), 'orbit-race-');
if ($marker === false) { throw new RuntimeException('Cannot create fixture barrier'); }
$workers = [];
try {
    for ($i = 1; $i <= 2; ++$i) {
        $proc = proc_open([PHP_BINARY, __FILE__, '--worker', $marker, 'ORDER_RACE_' . $i], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        if (!is_resource($proc)) { throw new RuntimeException('Cannot start fixture worker'); }
        fclose($pipes[0]);
        $workers[] = [$proc, $pipes];
    }
    foreach ($workers as $workerProcess) {
        if (trim((string) fgets($workerProcess[1][1])) !== 'READY') { throw new RuntimeException('Fixture worker did not start'); }
    }
    file_put_contents($marker, 'go');
    $results = [];
    foreach ($workers as $workerProcess) {
        [$proc, $pipes] = $workerProcess;
        $results[] = trim(stream_get_contents($pipes[1]));
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($proc) !== 0) { throw new RuntimeException('Fixture worker failed: ' . $stderr); }
    }
    $workers = [];
    sort($results);
    money_expect($results === ['debited','insufficient'], 'simultaneous purchases cannot overdraw one wallet');
    money_expect(balance($pdo, 2) === '25.00', 'concurrent wallet balance is correct');
    money_expect((int) $pdo->query("SELECT COUNT(*) FROM dongtien WHERE user_id=2 AND transid LIKE 'ORDER_RACE_%'")->fetchColumn() === 1, 'one concurrent debit ledger row exists');
} finally {
    @unlink($marker);
    foreach ($workers as $workerProcess) {
        foreach ([1,2] as $stream) { if (is_resource($workerProcess[1][$stream])) { fclose($workerProcess[1][$stream]); } }
        if (is_resource($workerProcess[0])) { proc_terminate($workerProcess[0]); proc_close($workerProcess[0]); }
    }
}
echo "db_money_integration_test: {$checks} passed, 0 failed (local disposable database)\n";
