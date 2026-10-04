<?php

require_once __DIR__ . '/payos_client.php';

function payos_env($name, $default = '')
{
    $value = $_ENV[$name] ?? getenv($name);
    return $value === false ? $default : $value;
}

function payos_config(): array
{
    return [
        'enabled' => filter_var(payos_env('PAYOS_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN),
        'client_id' => trim(payos_env('PAYOS_CLIENT_ID')),
        'api_key' => trim(payos_env('PAYOS_API_KEY')),
        'checksum_key' => trim(payos_env('PAYOS_CHECKSUM_KEY')),
        'app_url' => rtrim(trim(payos_env('APP_URL')), '/'),
        'min_amount' => (int) payos_env('PAYOS_MIN_AMOUNT', '1000'),
        'max_amount' => (int) payos_env('PAYOS_MAX_AMOUNT', '50000000'),
        'expire_minutes' => (int) payos_env('PAYOS_EXPIRE_MINUTES', '15'),
    ];
}

function payos_db(): PDO
{
    static $connection;
    if (!$connection) {
        $host = payos_env('DB_HOST', 'localhost');
        $database = payos_env('DB_DATABASE');
        $port = (int) payos_env('DB_PORT', '3306');
        if (preg_match('/[;\x00-\x1f]/', $host . $database) || $port < 1 || $port > 65535) {
            throw new RuntimeException('Invalid database configuration.');
        }
        $connection = new PDO('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4',
            payos_env('DB_USERNAME'), payos_env('DB_PASSWORD'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
    }
    return $connection;
}

function payos_csrf_token(): string
{
    if (empty($_SESSION['payos_csrf'])) {
        $_SESSION['payos_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['payos_csrf'];
}

function payos_service(): PayOSService
{
    $config = payos_config();
    if ($config['client_id'] === '' || $config['api_key'] === '' || $config['checksum_key'] === '') {
        throw new RuntimeException('payOS credentials are not configured.');
    }
    return new PayOSService(payos_db(), new PayOSClient($config['client_id'], $config['api_key'], $config['checksum_key']), $config);
}

class PayOSService
{
    private $db;
    private $client;
    private $config;

    public function __construct(PDO $db, PayOSClient $client, array $config)
    {
        $this->db = $db;
        $this->client = $client;
        $this->config = $config;
    }

    private function query(string $sql, array $args = []): PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($args);
        return $statement;
    }

    private function setting(string $name, $default = '')
    {
        $value = $this->query('SELECT value FROM settings WHERE name = ? LIMIT 1', [$name])->fetchColumn();
        return $value === false ? $default : $value;
    }

    public function find($orderCode, $userId = null)
    {
        if (!is_scalar($orderCode) || !preg_match('/^[1-9][0-9]{0,14}$/D', (string) $orderCode)) {
            return false;
        }
        $sql = 'SELECT * FROM payos_orders WHERE order_code = ?';
        $args = [(string) $orderCode];
        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $args[] = (int) $userId;
        }
        return $this->query($sql, $args)->fetch();
    }

    public function create(int $userId, $amount): array
    {
        $config = $this->config;
        if (!$config['enabled']) {
            throw new DomainException('Nạp tiền payOS chưa được bật.');
        }
        if (!is_scalar($amount) || !preg_match('/^[1-9][0-9]{0,8}$/D', (string) $amount) ||
            (int) $amount < max(1, $config['min_amount']) || (int) $amount > $config['max_amount']) {
            throw new DomainException('Số tiền phải là số nguyên VND trong hạn mức cho phép.');
        }
        $amount = (int) $amount;
        $url = $config['app_url'];
        if (!filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https' ||
            parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null || parse_url($url, PHP_URL_USER) !== null) {
            throw new RuntimeException('APP_URL must be a fixed HTTPS URL.');
        }
        if ($this->setting('status', '0') !== '1') {
            throw new DomainException('Website đang bảo trì, vui lòng thử lại sau.');
        }
        $this->db->beginTransaction();
        try {
            $user = $this->query('SELECT id, banned FROM users WHERE id = ? FOR UPDATE', [$userId])->fetch();
            if (!$user || (int) $user['banned'] !== 0) {
                throw new DomainException('Tài khoản không thể tạo thanh toán.');
            }
            $existing = $this->query("SELECT * FROM payos_orders WHERE user_id = ? AND amount = ? AND status IN ('CREATING','PENDING','PROCESSING','UNDERPAID') AND expires_at > ? ORDER BY created_at DESC LIMIT 1", [$userId, $amount, time()])->fetch();
            if ($existing) {
                $this->db->commit();
                if (!$existing['checkout_url']) {
                    throw new DomainException('Yêu cầu đang được xử lý. Vui lòng chờ rồi kiểm tra lịch sử.');
                }
                return $existing;
            }
            $recent = $this->query('SELECT COUNT(*) FROM payos_orders WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)', [$userId])->fetchColumn();
            if ((int) $recent >= 5) {
                throw new DomainException('Bạn tạo yêu cầu quá nhanh. Vui lòng thử lại sau một phút.');
            }
            $discount = $this->query('SELECT discount FROM promotions WHERE min <= ? ORDER BY min DESC LIMIT 1', [$amount])->fetchColumn();
            $discount = $discount === false ? 0 : (float) $discount;
            if (!is_finite($discount) || $discount < 0 || $discount > 1000) {
                throw new RuntimeException('Invalid promotion configuration.');
            }
            $received = number_format($amount * (1 + $discount / 100), 2, '.', '');
            $orderCode = random_int(100000000000, 999999999999);
            $expires = time() + max(5, min(60, $config['expire_minutes'])) * 60;
            $this->query("INSERT INTO payos_orders (order_code,user_id,amount,credited_amount,status,expires_at,created_at,updated_at) VALUES (?,?,?,?,'CREATING',?,NOW(),NOW())", [$orderCode, $userId, $amount, $received, $expires]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        try {
            $returnUrl = $url . '/index.php?module=client&action=recharge-payos';
            $result = $this->client->createPaymentLink([
                'orderCode' => $orderCode, 'amount' => $amount,
                'description' => 'NAP' . substr((string) $orderCode, -6),
                'returnUrl' => $returnUrl, 'cancelUrl' => $returnUrl, 'expiredAt' => $expires,
            ]);
            $checkout = $result['checkoutUrl'] ?? '';
            $host = strtolower((string) parse_url($checkout, PHP_URL_HOST));
            if (!filter_var($checkout, FILTER_VALIDATE_URL) || parse_url($checkout, PHP_URL_SCHEME) !== 'https' ||
                !($host === 'payos.vn' || substr($host, -9) === '.payos.vn') || empty($result['paymentLinkId'])) {
                throw new RuntimeException('Invalid payOS checkout response.');
            }
            $this->query("UPDATE payos_orders SET payment_link_id = ?, checkout_url = ?, status = IF(credited_at IS NULL,'PENDING',status), updated_at = NOW() WHERE order_code = ? AND (payment_link_id IS NULL OR payment_link_id = ?)", [$result['paymentLinkId'], $checkout, $orderCode, $result['paymentLinkId']]);
        } catch (Throwable $error) {
            // A timeout can happen after payOS created the link. Keep the order for reconciliation.
            $this->query("UPDATE payos_orders SET status = 'CREATE_FAILED', updated_at = NOW() WHERE order_code = ? AND status = 'CREATING'", [$orderCode]);
            throw new RuntimeException('Chưa nhận được liên kết thanh toán. Kiểm tra lịch sử trước khi thử lại.');
        }
        return $this->find($orderCode, $userId);
    }

    public function webhook(array $payload): string
    {
        $data = $this->client->verifyWebhook($payload);
        if (($data['code'] ?? null) !== '00') {
            return 'ignored';
        }
        $order = $this->find($data['orderCode'] ?? '');
        // The dashboard sends a signed sample order when registering the webhook.
        if (!$order) {
            return 'ignored';
        }
        if (($data['currency'] ?? null) !== 'VND' || empty($data['paymentLinkId']) || empty($data['reference']) ||
            !is_numeric($data['amount'] ?? null) || (float) $data['amount'] <= 0 ||
            ($order['payment_link_id'] && !hash_equals($order['payment_link_id'], (string) $data['paymentLinkId']))) {
            throw new InvalidArgumentException('Webhook does not match the payment.');
        }
        if ($order['credited_at']) {
            return 'already_credited';
        }
        return $this->synchronize($order, (string) $data['paymentLinkId']);
    }

    public function refresh(array $order): array
    {
        if (!$order['credited_at'] && !in_array($order['status'], ['CANCELLED','EXPIRED','FAILED'], true)) {
            $claimed = $this->query('UPDATE payos_orders SET last_checked_at = NOW() WHERE order_code = ? AND (last_checked_at IS NULL OR last_checked_at < DATE_SUB(NOW(), INTERVAL 15 SECOND))', [$order['order_code']])->rowCount();
            if ($claimed) {
                $this->synchronize($order);
            }
        }
        return $this->find($order['order_code'], $order['user_id']);
    }

    private function synchronize(array $order, ?string $webhookLinkId = null): string
    {
        $remote = $this->client->getPaymentLink($order['order_code']);
        $linkId = (string) ($remote['id'] ?? $remote['paymentLinkId'] ?? '');
        if (!$linkId || (string) ($remote['orderCode'] ?? '') !== (string) $order['order_code'] ||
            (string) ($remote['amount'] ?? '') !== (string) $order['amount'] ||
            ($order['payment_link_id'] && !hash_equals($order['payment_link_id'], $linkId)) ||
            ($webhookLinkId !== null && !hash_equals($webhookLinkId, $linkId)) ||
            (isset($remote['currency']) && $remote['currency'] !== 'VND')) {
            throw new RuntimeException('payOS reconciliation mismatch.');
        }
        $status = $remote['status'] ?? '';
        if (!in_array($status, ['PENDING','UNDERPAID','PAID','PROCESSING','CANCELLED','EXPIRED','FAILED'], true)) {
            throw new RuntimeException('Unknown payOS status.');
        }
        if ($status === 'PAID') {
            if (!isset($remote['amountPaid']) || !is_numeric($remote['amountPaid']) || (float) $remote['amountPaid'] < (int) $order['amount']) {
                throw new RuntimeException('payOS payment is incomplete.');
            }
            return $this->credit($order['order_code'], $linkId);
        }
        if (isset($remote['checkoutUrl']) && is_string($remote['checkoutUrl'])) {
            $checkout = $remote['checkoutUrl'];
            $host = strtolower((string) parse_url($checkout, PHP_URL_HOST));
            if (filter_var($checkout, FILTER_VALIDATE_URL) && parse_url($checkout, PHP_URL_SCHEME) === 'https' &&
                !parse_url($checkout, PHP_URL_USER) && ($host === 'payos.vn' || substr($host, -9) === '.payos.vn')) {
                $this->query('UPDATE payos_orders SET checkout_url = ? WHERE order_code = ?', [$checkout, $order['order_code']]);
            }
        }
        $this->query('UPDATE payos_orders SET status = ?, payment_link_id = ?, updated_at = NOW() WHERE order_code = ? AND credited_at IS NULL', [$status, $linkId, $order['order_code']]);
        return 'pending';
    }

    private function ledger(int $userId, string $amount, string $reason, string $transaction): void
    {
        $this->query('INSERT INTO dongtien (user_id,sotientruoc,sotienthaydoi,sotiensau,thoigian,noidung,transid) SELECT id,money,?,money + ?,NOW(),?,? FROM users WHERE id = ?', [$amount, $amount, $reason, $transaction, $userId]);
    }

    private function credit($orderCode, string $linkId): string
    {
        $this->db->beginTransaction();
        try {
            $order = $this->query('SELECT * FROM payos_orders WHERE order_code = ? FOR UPDATE', [$orderCode])->fetch();
            if ($order['credited_at']) {
                $this->db->commit();
                return 'already_credited';
            }
            if ($order['payment_link_id'] && !hash_equals($order['payment_link_id'], $linkId)) {
                throw new RuntimeException('Payment link changed.');
            }
            $user = $this->query('SELECT * FROM users WHERE id = ? FOR UPDATE', [$order['user_id']])->fetch();
            if (!$user) {
                throw new RuntimeException('Payment account missing.');
            }
            $userId = (int) $user['id'];
            $received = $order['credited_amount'];
            $transid = 'PAYOS_' . $orderCode;
            $reason = 'Nạp tiền payOS #' . $orderCode;
            $this->ledger($userId, $received, $reason, $transid);
            $this->query('UPDATE users SET money = money + ?, total_money = total_money + ? WHERE id = ?', [$received, $received, $userId]);
            $this->query("INSERT INTO payment_bank (tid,method,user_id,description,amount,received,create_gettime,create_time) VALUES (?,'payOS',?,?,?,?,NOW(),?)", [$transid, $userId, $reason, $order['amount'], $received, time()]);
            $this->query("INSERT INTO deposit_log (user_id,method,amount,received,create_time,is_virtual) VALUES (?,'payOS',?,?,?,0)", [$userId, $order['amount'], $received, time()]);
            if ($this->setting('affiliate_status', '0') === '1' && (int) $user['ref_id'] > 0 && (int) $user['ref_id'] !== $userId) {
                $ref = $this->query('SELECT id,ref_ck,ref_price FROM users WHERE id = ? FOR UPDATE', [$user['ref_id']])->fetch();
                if ($ref) {
                    $percent = (float) $ref['ref_ck'] ?: (float) $this->setting('affiliate_ck', '0');
                    if ($percent < 0 || $percent > 100 || !is_finite($percent)) {
                        throw new RuntimeException('Invalid affiliate configuration.');
                    }
                    $commission = number_format((float) $received * $percent / 100, 2, '.', '');
                    $this->query('INSERT INTO aff_log (user_id,reason,sotientruoc,sotienthaydoi,sotienhientai,create_gettime) SELECT id,?,ref_price,?,ref_price + ?,NOW() FROM users WHERE id = ?', ['Hoa hồng payOS #' . $orderCode, $commission, $commission, $ref['id']]);
                    $this->query('UPDATE users SET ref_price = ref_price + ?, ref_total_price = ref_total_price + ? WHERE id = ?', [$commission, $commission, $ref['id']]);
                    $this->query('UPDATE users SET ref_amount = ref_amount + ? WHERE id = ?', [$commission, $userId]);
                }
            }
            $debt = $this->query('SELECT LEAST(debit,GREATEST(money,0)) FROM users WHERE id = ?', [$userId])->fetchColumn();
            if ((float) $debt > 0) {
                $this->ledger($userId, '-' . $debt, 'Thanh toán số tiền ghi nợ', $transid . '_DEBIT');
                $this->query('UPDATE users SET money = money - ?, debit = debit - ? WHERE id = ?', [$debt, $debt, $userId]);
            }
            $this->query("UPDATE payos_orders SET status = 'PAID', payment_link_id = ?, credited_at = NOW(), updated_at = NOW() WHERE order_code = ?", [$linkId, $orderCode]);
            $this->db->commit();
            return 'credited';
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
}
