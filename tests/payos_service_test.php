<?php
// Run only against a disposable local database named payos_test_*.
if (PHP_SAPI !== 'cli') { exit; }
$testDatabase = getenv('PAYOS_TEST_DB') ?: '';
if (!preg_match('/^payos_test_[a-z0-9_]+$/D', $testDatabase)) {
    fwrite(STDERR, "Set PAYOS_TEST_DB to a disposable payos_test_* database.\n");
    exit(1);
}
$_ENV['DB_HOST'] = '127.0.0.1';
$_ENV['DB_PORT'] = getenv('PAYOS_TEST_PORT') ?: '33317';
$_ENV['DB_DATABASE'] = $testDatabase;
$_ENV['DB_USERNAME'] = getenv('PAYOS_TEST_USER') ?: 'root';
$_ENV['DB_PASSWORD'] = getenv('PAYOS_TEST_PASSWORD') ?: '';
require_once __DIR__ . '/../libs/payos.php';
$db = payos_db();

class FakePayOS extends PayOSClient
{
    public $payments = [];
    public $createCount = 0;
    public $failCreate = false;
    public $timeoutAfterCreate = false;
    public function __construct() { parent::__construct('fixture-client', 'fixture-api', 'fixture-checksum'); }
    public function createPaymentLink(array $data): array
    {
        ++$this->createCount;
        if ($this->failCreate) { throw new RuntimeException('Simulated timeout'); }
        $id = 'fixture-' . $data['orderCode'];
        $this->payments[$data['orderCode']] = ['id' => $id, 'orderCode' => $data['orderCode'], 'amount' => $data['amount'], 'amountPaid' => 0, 'status' => 'PENDING'];
        if ($this->timeoutAfterCreate) { throw new RuntimeException('Timeout after remote creation'); }
        return ['paymentLinkId' => $id, 'checkoutUrl' => 'https://pay.payos.vn/web/' . $id];
    }
    public function getPaymentLink($orderCode): array { return $this->payments[$orderCode]; }
}

$assertions = 0;
function expect($condition, string $message): void {
    global $assertions;
    ++$assertions;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
}
function fails(callable $callback, string $message): void {
    $threw = false;
    try { $callback(); } catch (Throwable $error) { $threw = true; }
    expect($threw, $message);
}
function signedEvent(array $order, array $overrides = []): array {
    $data = array_merge(['orderCode' => (int) $order['order_code'], 'amount' => (int) $order['amount'], 'currency' => 'VND',
        'paymentLinkId' => 'fixture-' . $order['order_code'], 'reference' => 'bank-ref-' . $order['order_code'], 'code' => '00'], $overrides);
    return ['code' => '00', 'success' => true, 'data' => $data, 'signature' => PayOSClient::createDataSignature($data, 'fixture-checksum')];
}

// Fixture tables include only fields touched by this integration, using the legacy FLOAT types before migration.
foreach (['payos_orders','users','settings','promotions','dongtien','payment_bank','deposit_log','aff_log'] as $table) {
    $db->exec('DROP TABLE IF EXISTS `' . $table . '`');
}
$db->exec('CREATE TABLE users (id INT PRIMARY KEY, banned INT DEFAULT 0, money FLOAT DEFAULT 0,total_money FLOAT DEFAULT 0,debit FLOAT DEFAULT 0,ref_amount FLOAT DEFAULT 0,ref_price FLOAT DEFAULT 0,ref_total_price FLOAT DEFAULT 0,ref_ck FLOAT DEFAULT 0,ref_id INT DEFAULT 0) ENGINE=InnoDB');
$db->exec('CREATE TABLE settings (name VARCHAR(255), value TEXT) ENGINE=InnoDB');
$db->exec('CREATE TABLE promotions (min FLOAT,discount FLOAT) ENGINE=InnoDB');
$db->exec('CREATE TABLE dongtien (id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,sotientruoc FLOAT,sotienthaydoi FLOAT,sotiensau FLOAT,thoigian DATETIME,noidung TEXT,transid VARCHAR(255)) ENGINE=InnoDB');
$db->exec('CREATE TABLE payment_bank (id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,tid VARCHAR(255),method VARCHAR(55),description TEXT,amount INT,received INT,create_gettime DATETIME,create_time INT) ENGINE=InnoDB');
$db->exec('CREATE TABLE deposit_log (id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,method VARCHAR(255),amount FLOAT,received FLOAT,create_time INT,is_virtual INT) ENGINE=InnoDB');
$db->exec('CREATE TABLE aff_log (id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,reason TEXT,sotientruoc FLOAT,sotienthaydoi FLOAT,sotienhientai FLOAT,create_gettime DATETIME) ENGINE=InnoDB');
require __DIR__ . '/../scripts/migrate-payos.php';
require __DIR__ . '/../scripts/migrate-payos.php';
$db->exec("INSERT INTO settings VALUES ('status','1'),('affiliate_status','1'),('affiliate_ck','5')");
$db->exec('INSERT INTO users (id,money,total_money,debit,ref_id) VALUES (1,100,100,200,2),(2,0,0,0,0),(3,0,0,0,0),(4,0,0,0,0),(5,0,0,0,0),(6,0,0,0,0)');
$db->exec('INSERT INTO promotions VALUES (1000,10)');
$client = new FakePayOS();
$config = ['enabled' => true, 'app_url' => 'https://shop.example.com/store', 'min_amount' => 1000, 'max_amount' => 50000000, 'expire_minutes' => 15];
$service = new PayOSService($db, $client, $config);
foreach (['-1000','1000.5','1e4','0',[], '999', '50000001'] as $invalid) {
    fails(static function () use ($service, $invalid) { $service->create(1, $invalid); }, 'Reject invalid amount');
}
$order = $service->create(1, '1000');
expect($order['status'] === 'PENDING', 'Create pending payment');
expect($order['credited_amount'] === '1100.00', 'Freeze promotion at creation');
expect($service->find($order['order_code'], 3) === false, 'Owner check');
expect($service->create(1, '1000')['order_code'] === $order['order_code'] && $client->createCount === 1, 'Reuse pending checkout for double click');
$payload = signedEvent($order);
$tampered = $payload;
$tampered['data']['amount'] = 99999;
fails(static function () use ($service, $tampered) { $service->webhook($tampered); }, 'Reject tampered signed amount');
fails(static function () use ($service, $order) { $service->webhook(signedEvent($order, ['currency' => 'USD'])); }, 'Reject wrong currency');
fails(static function () use ($service, $order) { $service->webhook(signedEvent($order, ['paymentLinkId' => 'other-link'])); }, 'Reject wrong link');
$unknown = signedEvent(array_merge($order, ['order_code' => '888888888888']));
expect($service->webhook($unknown) === 'ignored', 'Accept signed dashboard sample without credit');
$client->payments[$order['order_code']]['status'] = 'UNDERPAID';
$client->payments[$order['order_code']]['amountPaid'] = 400;
expect($service->webhook(signedEvent($order, ['amount' => 400])) === 'pending', 'Partial payment does not credit');
expect($db->query('SELECT money FROM users WHERE id=1')->fetchColumn() === '100.00', 'Partial leaves money unchanged');
$client->payments[$order['order_code']]['status'] = 'PAID';
fails(static function () use ($service, $payload) { $service->webhook($payload); }, 'Reject paid state with insufficient paid amount');
$client->payments[$order['order_code']]['amountPaid'] = 1000;
$client->payments[$order['order_code']]['amount'] = 999;
fails(static function () use ($service, $payload) { $service->webhook($payload); }, 'Reject mismatched remote amount');
$client->payments[$order['order_code']]['amount'] = 1000;
$db->exec("CREATE TRIGGER fail_payos_test BEFORE INSERT ON payment_bank FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected storage failure'");
fails(static function () use ($service, $payload) { $service->webhook($payload); }, 'Fail transaction on ledger write failure');
expect($db->query('SELECT money FROM users WHERE id=1')->fetchColumn() === '100.00', 'Rollback wallet');
expect((int) $db->query('SELECT COUNT(*) FROM dongtien')->fetchColumn() === 0, 'Rollback ledger');
expect($service->find($order['order_code'])['credited_at'] === null, 'Rollback credit marker');
$db->exec('DROP TRIGGER fail_payos_test');
expect($service->webhook($payload) === 'credited', 'Retry succeeds after rollback');
expect($db->query('SELECT money FROM users WHERE id=1')->fetchColumn() === '1000.00', 'Wallet includes promotion minus debt');
expect($db->query('SELECT total_money FROM users WHERE id=1')->fetchColumn() === '1200.00', 'Total credited includes promotion');
expect($db->query('SELECT debit FROM users WHERE id=1')->fetchColumn() === '0.00', 'Debt settled');
expect($db->query('SELECT ref_price FROM users WHERE id=2')->fetchColumn() === '55.00', 'Affiliate commission');
expect($service->webhook($payload) === 'already_credited', 'Duplicate webhook idempotency');
expect((int) $db->query('SELECT COUNT(*) FROM deposit_log')->fetchColumn() === 1, 'Single deposit log');
expect((int) $db->query('SELECT COUNT(*) FROM aff_log')->fetchColumn() === 1, 'Single affiliate log');
expect((int) $db->query('SELECT COUNT(*) FROM dongtien')->fetchColumn() === 2, 'Single deposit and debt ledger');

$second = $service->create(3, '2000');
$client->payments[$second['order_code']]['status'] = 'PAID';
$client->payments[$second['order_code']]['amountPaid'] = 2500;
$synced = $service->refresh($second);
expect($synced['status'] === 'PAID' && $synced['credited_at'] !== null, 'Server reconciliation recovers missed webhook');
expect($db->query('SELECT money FROM users WHERE id=3')->fetchColumn() === '2200.00', 'Overpayment credits booked amount only');
$client->failCreate = true;
fails(static function () use ($service) { $service->create(4, '3000'); }, 'API timeout reported');
expect($db->query('SELECT status FROM payos_orders WHERE user_id=4')->fetchColumn() === 'CREATE_FAILED', 'Retain timed-out order for reconciliation');
$client->failCreate = false;
$client->timeoutAfterCreate = true;
fails(static function () use ($service) { $service->create(5, '4000'); }, 'Timeout after remote creation reported');
$ambiguous = $db->query('SELECT * FROM payos_orders WHERE user_id=5')->fetch();
$client->payments[$ambiguous['order_code']]['status'] = 'PAID';
$client->payments[$ambiguous['order_code']]['amountPaid'] = 4000;
expect($service->webhook(signedEvent($ambiguous)) === 'credited', 'Webhook recovers timeout after remote creation');
expect($db->query('SELECT money FROM users WHERE id=5')->fetchColumn() === '4400.00', 'Recovered timeout credits correct owner');
$client->timeoutAfterCreate = false;
$raceOrder = $service->create(6, '5000');
$workers = [];
for ($i = 0; $i < 2; ++$i) {
    $process = proc_open([PHP_BINARY, '-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=pdo_mysql', __DIR__ . '/payos_race_worker.php', $raceOrder['order_code']],
        [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start concurrency test.'); }
    fclose($pipes[0]);
    $workers[] = [$process,$pipes];
}
$results = [];
foreach ($workers as [$process,$pipes]) {
    $results[] = trim(stream_get_contents($pipes[1]));
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    expect(proc_close($process) === 0, 'Concurrent worker succeeded: ' . $errors);
}
sort($results);
expect($results === ['already_credited','credited'], 'Concurrent requests credit once');
expect($db->query('SELECT money FROM users WHERE id=6')->fetchColumn() === '5500.00', 'Concurrent wallet exactly once');
expect((int) $db->query('SELECT COUNT(*) FROM deposit_log WHERE user_id=6')->fetchColumn() === 1, 'Concurrent ledger exactly once');
echo 'PASS: ' . $assertions . " payOS service/database assertions.\n";
