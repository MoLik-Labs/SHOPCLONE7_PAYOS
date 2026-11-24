<?php
/* Read/write TEMPORARY tables only, on an explicitly selected disposable local test database. */
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(404); exit; }
if (getenv('ORBIT_DB_TEST') !== '1') { echo "SKIP: ORBIT_DB_TEST is not enabled; no database was touched.\n"; exit(0); }
$name = (string) getenv('ORBIT_DB_TEST_NAME');
$port = (string) getenv('ORBIT_DB_TEST_PORT');
if (!preg_match('/^orbit_test_[a-z0-9_]+$/D', $name) || !preg_match('/^[0-9]{1,5}$/D', $port) || (int) $port < 1 || (int) $port > 65535) {
    fwrite(STDERR, "REFUSED: use a disposable orbit_test_* database on local TCP.\n"); exit(2);
}
if (!extension_loaded('mysqli') || !extension_loaded('pdo_mysql') || !is_file(__DIR__ . '/../vendor/autoload.php')) {
    fwrite(STDERR, "REFUSED: mysqli, pdo_mysql and Composer dependencies are required.\n"); exit(2);
}
define('IN_SITE', true);
require __DIR__ . '/../libs/db.php';
// Do not let a local .env redirect this destructive-capable test to any other host or database.
$_ENV['DB_HOST'] = '127.0.0.1';
$_ENV['DB_PORT'] = $port;
$_ENV['DB_DATABASE'] = $name;
$_ENV['DB_USERNAME'] = (string) getenv('ORBIT_DB_TEST_USER');
$_ENV['DB_PASSWORD'] = (string) getenv('ORBIT_DB_TEST_PASSWORD');
$db = new DB();
$checks = 0;
function db_expect($condition, $name) {
    global $checks;
    ++$checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $name); }
    echo "PASS  {$name}\n";
}
$row = $db->get_row('SELECT DATABASE() AS dbname, @@character_set_connection AS charset, CONNECTION_ID() AS connection_id');
db_expect($row && $row['dbname'] === $name, 'connected to explicitly selected disposable database');
db_expect($row['charset'] === 'utf8mb4', 'connection uses utf8mb4');
db_expect($db->query('CREATE TEMPORARY TABLE orbit_db_fixture (id INT PRIMARY KEY, value_text VARCHAR(80)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4') === true, 'temporary InnoDB fixture created');
db_expect($db->query("INSERT INTO orbit_db_fixture VALUES (1, 'Tiếng Việt 🛰')") === true, 'four-byte UTF-8 can be stored');
db_expect($db->get_row('SELECT value_text FROM orbit_db_fixture WHERE id=1')['value_text'] === 'Tiếng Việt 🛰', 'four-byte UTF-8 round trips');
$result = $db->transaction(function () use ($db) {
    $db->query("UPDATE orbit_db_fixture SET value_text='rollback' WHERE id=1");
    return false;
});
db_expect($result === false && $db->get_row('SELECT value_text FROM orbit_db_fixture WHERE id=1')['value_text'] === 'Tiếng Việt 🛰', 'false result rolls back');
$result = $db->transaction(function () use ($db) {
    $db->query("UPDATE orbit_db_fixture SET value_text='committed' WHERE id=1");
    return true;
});
db_expect($result === true && $db->get_row('SELECT value_text FROM orbit_db_fixture WHERE id=1')['value_text'] === 'committed', 'successful transaction commits');
$db->dis_connect();
db_expect((int) $db->get_row('SELECT 1 AS n')['n'] === 1, 'closed connection can be reopened');

// A second local connection forcibly closes a fixture connection, exercising begin/commit failures.
$killer = new PDO('mysql:host=127.0.0.1;port=' . (int) $port . ';dbname=' . $name, $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$beginDb = new DB();
$id = (int) $beginDb->get_row('SELECT CONNECTION_ID() AS id')['id'];
$killer->exec('KILL CONNECTION ' . $id);
$ran = false;
$result = $beginDb->transaction(function () use (&$ran) { $ran = true; return true; });
db_expect($result === false && !$ran, 'failed begin does not run the callback');
$commitDb = new DB();
$id = (int) $commitDb->get_row('SELECT CONNECTION_ID() AS id')['id'];
$result = $commitDb->transaction(function () use ($killer, $id) { $killer->exec('KILL CONNECTION ' . $id); return true; });
db_expect($result === false, 'failed commit is not reported as success');
echo "db_connection_integration_test: {$checks} passed, 0 failed (local disposable database)\n";
