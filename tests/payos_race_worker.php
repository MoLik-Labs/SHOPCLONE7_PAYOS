<?php
if (PHP_SAPI !== 'cli' || !preg_match('/^payos_test_[a-z0-9_]+$/D', getenv('PAYOS_TEST_DB') ?: '')) { exit(1); }
$_ENV['DB_HOST'] = '127.0.0.1';
$_ENV['DB_PORT'] = getenv('PAYOS_TEST_PORT') ?: '33317';
$_ENV['DB_DATABASE'] = getenv('PAYOS_TEST_DB');
$_ENV['DB_USERNAME'] = getenv('PAYOS_TEST_USER') ?: 'root';
$_ENV['DB_PASSWORD'] = getenv('PAYOS_TEST_PASSWORD') ?: '';
require_once __DIR__ . '/../libs/payos.php';
class RacePayOS extends PayOSClient {
    public $order;
    public function __construct(array $order) { parent::__construct('fixture-client','fixture-api','fixture-checksum'); $this->order = $order; }
    public function getPaymentLink($code): array {
        usleep(100000);
        return ['orderCode' => (int) $code, 'id' => $this->order['payment_link_id'], 'amount' => (int) $this->order['amount'], 'amountPaid' => (int) $this->order['amount'], 'status' => 'PAID'];
    }
}
$query = payos_db()->prepare('SELECT * FROM payos_orders WHERE order_code = ?');
$query->execute([$argv[1]]);
$order = $query->fetch();
if (!$order) { exit(1); }
$service = new PayOSService(payos_db(), new RacePayOS($order), []);
$data = ['orderCode' => (int) $order['order_code'], 'paymentLinkId' => $order['payment_link_id'], 'amount' => (int) $order['amount'], 'currency' => 'VND', 'reference' => 'race-test', 'code' => '00'];
echo $service->webhook(['data' => $data, 'signature' => PayOSClient::createDataSignature($data, 'fixture-checksum')]);
