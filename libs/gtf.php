<?php

/**
 * GachtheFast (GTF) shared core: configuration, database access, secret sealing,
 * pure decision rules and the single money-moving credit routine.
 *
 * Design rules enforced here (see docs/gachthefast/):
 *  - nothing is enabled unless its own environment flag says so, and the provider origin
 *    and the request body format must both be configured explicitly — no origin and no
 *    body format is ever assumed, hard-coded or guessed;
 *  - no function in this file credits money from provider input alone;
 *  - crediting is additionally locked behind GTF_ALLOW_CREDITING (default false) until the
 *    provider contract and the credit rules are confirmed, so a wallet cannot be credited
 *    by this code in its current, unverified state;
 *  - the only credit routine writes payment state, wallet and ledger inside one
 *    InnoDB transaction and is guarded by a UNIQUE key on gtf_ledger.transid;
 *  - card secrets are sealed with a key derived from SECRET_KEY and shredded once
 *    an order reaches a terminal state.
 */

if (!defined('IN_SITE')) {
    http_response_code(404);
    exit;
}

const GTF_CARD_TELCOS = ['VIETTEL', 'VINAPHONE', 'MOBIFONE', 'VNMOBI', 'ZING', 'VCOIN', 'GARENA'];
const GTF_CARD_DENOMINATIONS = [10000, 20000, 30000, 50000, 100000, 200000, 500000, 1000000, 2000000];
const GTF_CARD_TERMINAL = ['REJECTED', 'EXPIRED', 'CANCELLED', 'MAINTENANCE'];
const GTF_MAX_BODY_BYTES = 1048576;

function gtf_env($name, $default = '')
{
    $value = $_ENV[$name] ?? getenv($name);
    if ($value === false || $value === null) {
        return $default;
    }
    return $value;
}

function gtf_bool($name, $default = false)
{
    $raw = gtf_env($name, $default ? 'true' : 'false');
    if (is_bool($raw)) {
        return $raw;
    }
    return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
}

function gtf_config(): array
{
    $key = trim((string) gtf_env('GTF_PARTNER_KEY'));
    // The provider origin and the request body format are contract facts that are NOT
    // confirmed. Both must therefore be configured explicitly; when either is blank the
    // card client refuses to run. No origin and no format has a default.
    $origin = rtrim(trim((string) gtf_env('GTF_API_BASE')), '/');
    $format = strtolower(trim((string) gtf_env('GTF_CARD_REQUEST_FORMAT')));
    if (!in_array($format, ['json', 'form'], true)) {
        $format = '';
    }
    return [
        'card_enabled' => gtf_bool('GTF_CARD_ENABLED', false),
        'credit_enabled' => gtf_bool('GTF_ALLOW_CREDITING', false),
        'api_base' => $origin,
        'card_request_format' => $format,
        'partner_id' => trim((string) gtf_env('GTF_PARTNER_ID')),
        'partner_key' => $key,
        'card_min' => (int) gtf_env('GTF_CARD_MIN_AMOUNT', '10000'),
        'card_max' => (int) gtf_env('GTF_CARD_MAX_AMOUNT', '2000000'),
        'card_fee_percent' => (float) gtf_env('GTF_CARD_FEE_PERCENT', '0'),
        'timeout' => max(3, min(30, (int) gtf_env('GTF_TIMEOUT_SECONDS', '10'))),
        'cron_token' => trim((string) gtf_env('GTF_CRON_TOKEN')),
        'secret_key' => trim((string) gtf_env('SECRET_KEY')),
        'app_url' => rtrim(trim((string) gtf_env('GTF_APP_URL', (string) gtf_env('APP_URL', ''))), '/'),
    ];
}

function gtf_db(): PDO
{
    static $connection;
    if (!$connection) {
        $host = gtf_env('DB_HOST', 'localhost');
        $database = gtf_env('DB_DATABASE');
        $port = (int) gtf_env('DB_PORT', '3306');
        if (preg_match('/[;\x00-\x1f]/', $host . $database) || $port < 1 || $port > 65535) {
            throw new RuntimeException('Invalid database configuration.');
        }
        $connection = new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4',
            gtf_env('DB_USERNAME'),
            gtf_env('DB_PASSWORD'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
    return $connection;
}

function gtf_csrf_token(): string
{
    if (empty($_SESSION['gtf_csrf'])) {
        $_SESSION['gtf_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['gtf_csrf'];
}

/** Operator alert. Uses the shop's existing admin channel when it is loaded. */
function gtf_alert(string $text): void
{
    try {
        if (function_exists('sendMessAdmin')) {
            sendMessAdmin('[GachtheFast] ' . $text);
            return;
        }
    } catch (Throwable $error) {
        // fall through to the error log
    }
    error_log('GachtheFast alert: ' . $text);
}

/** True only when the caller is allowed to run a reconciliation worker. */
function gtf_worker_allowed(array $config): bool
{
    if (PHP_SAPI === 'cli') {
        return true;
    }
    $token = (string) $config['cron_token'];
    if ($token === '') {
        return false;
    }
    $provided = isset($_SERVER['HTTP_X_GTF_CRON_TOKEN']) ? (string) $_SERVER['HTTP_X_GTF_CRON_TOKEN'] : '';
    if ($provided === '' && isset($_GET['token'])) {
        $provided = (string) $_GET['token'];
    }
    return $provided !== '' && hash_equals($token, $provided);
}

/**
 * Seals card data so that reconciliation can still call `command=check` while the
 * order is open. The plaintext never reaches the database, logs or HTML.
 */
final class GtfSecret
{
    public static function seal($plaintext)
    {
        $config = gtf_config();
        if ($config['secret_key'] === '') {
            throw new RuntimeException('SECRET_KEY is required to store card data.');
        }
        $key = hash('sha256', $config['secret_key'] . '|gtf-card-v1', true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt((string) $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('Cannot seal card data.');
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function open($sealed)
    {
        if (!is_string($sealed) || $sealed === '') {
            return '';
        }
        if (strpos($sealed, 'v1:') !== 0) {
            throw new RuntimeException('Unsupported sealed value.');
        }
        $config = gtf_config();
        if ($config['secret_key'] === '') {
            throw new RuntimeException('SECRET_KEY is required to read card data.');
        }
        $raw = base64_decode(substr($sealed, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Corrupted sealed value.');
        }
        $key = hash('sha256', $config['secret_key'] . '|gtf-card-v1', true);
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('Cannot open sealed value.');
        }
        return $plain;
    }

    /** Masks a serial/PIN for logs and admin screens: keeps the last 3 characters. */
    public static function mask($value)
    {
        $value = (string) $value;
        $length = strlen($value);
        if ($length <= 3) {
            return str_repeat('*', $length);
        }
        return str_repeat('*', min(12, $length - 3)) . substr($value, -3);
    }
}

/** Pure rules. No database, no network, no globals: unit-testable. */
final class GtfRules
{
    /** @return bool */
    public static function validAmountString($value)
    {
        return is_string($value) && preg_match('/^[0-9]{1,15}$/D', $value) === 1;
    }

    public static function toMinor($value): string
    {
        return ltrim((string) (int) $value, '+');
    }

    /**
     * Maps the provider's card status to a local decision.
     * Returns ['decision' => credit_candidate|manual_review|terminal|pending|unknown, ...].
     * Anything that is not an unambiguous success goes to a human.
     */
    public static function cardDecision(array $remote, array $order): array
    {
        $status = isset($remote['status']) ? (string) $remote['status'] : '';
        if ($status === '') {
            return ['decision' => 'unknown', 'reason' => 'missing_status'];
        }
        if ($status === '99') {
            return ['decision' => 'pending', 'reason' => 'provider_pending'];
        }
        if ($status === '3') {
            return ['decision' => 'terminal', 'status' => 'REJECTED', 'reason' => 'card_invalid'];
        }
        if ($status === '4') {
            return ['decision' => 'terminal', 'status' => 'MAINTENANCE', 'reason' => 'provider_maintenance'];
        }
        if ($status === '100') {
            return ['decision' => 'terminal', 'status' => 'REJECTED', 'reason' => 'submission_failed'];
        }
        if ($status === '1' || $status === '2') {
            foreach (['value', 'amount', 'declared_value'] as $field) {
                if (!isset($remote[$field]) || !self::validAmountString((string) $remote[$field])) {
                    return ['decision' => 'manual_review', 'reason' => 'missing_or_malformed:' . $field];
                }
            }
            $declared = (string) $order['declared_amount'];
            $value = (string) $remote['value'];
            $amount = (string) $remote['amount'];
            $declaredRemote = (string) $remote['declared_value'];
            if ($status === '2') {
                return ['decision' => 'manual_review', 'reason' => 'status_2_value_differs'];
            }
            if ((int) $value !== (int) $amount || (int) $value !== (int) $declared || (int) $declaredRemote !== (int) $declared) {
                return ['decision' => 'manual_review', 'reason' => 'amount_conflict'];
            }
            if ((int) $value <= 0) {
                return ['decision' => 'manual_review', 'reason' => 'non_positive_value'];
            }
            // Categorised as a credit candidate only: the caller still has to pass the
            // GTF_ALLOW_CREDITING lock before any money moves (see GtfCredit::isEnabled).
            return ['decision' => 'credit_candidate', 'gross' => $value, 'reason' => 'confirmed_declared_value'];
        }
        return ['decision' => 'unknown', 'reason' => 'unknown_status'];
    }

    /** Net amount credited for a card, after the configured shop fee. */
    public static function cardNet(string $gross, float $feePercent): string
    {
        if ($feePercent < 0 || $feePercent > 100 || !is_finite($feePercent)) {
            throw new RuntimeException('Invalid card fee configuration.');
        }
        $fee = (float) $gross * $feePercent / 100;
        $net = (float) $gross - $fee;
        if ($net < 0) {
            $net = 0;
        }
        return number_format($net, 2, '.', '');
    }
}

/**
 * The only routine in the entire GachtheFast integration that adds money to a wallet.
 * It must be called from inside an already-open transaction that holds a row lock on
 * the local order. `$finalize` must flip the order status and return true; the credit
 * is rolled back if it returns false, so a status can never diverge from the ledger.
 */
final class GtfCredit
{
    /**
     * Crediting stays locked until the owner has confirmed the provider contract and the
     * credit rules and has explicitly set GTF_ALLOW_CREDITING=true. Until then this routine
     * refuses, so no wallet can be credited by unverified code.
     */
    public static function isEnabled(array $config = null): bool
    {
        if ($config === null) {
            $config = gtf_config();
        }
        return !empty($config['credit_enabled']);
    }

    public static function applyWithin(PDO $db, array $params, callable $finalize): string
    {
        if (!self::isEnabled()) {
            throw new RuntimeException('GachtheFast crediting is locked until the provider contract and the credit rules are confirmed.');
        }
        $userId = (int) $params['user_id'];
        $credited = (string) $params['credited'];
        $gross = (string) $params['gross'];
        $method = (string) $params['method'];
        $reason = (string) $params['reason'];
        $transid = (string) $params['transid'];
        if (!$db->inTransaction()) {
            throw new RuntimeException('GtfCredit requires an open transaction.');
        }
        if (!is_numeric($credited) || (float) $credited < 0) {
            throw new RuntimeException('Invalid credited amount.');
        }
        // Double-entry guard that does not depend on the legacy dongtien schema.
        $statement = $db->prepare('INSERT INTO gtf_ledger (transid,user_id,method,gross,credited,reason,created_at) VALUES (?,?,?,?,?,?,NOW())');
        $statement->execute([$transid, $userId, $method, $gross, $credited, $reason]);

        $user = $db->prepare('SELECT id,banned,ref_id,ref_ck FROM users WHERE id = ? FOR UPDATE');
        $user->execute([$userId]);
        $row = $user->fetch();
        if (!$row) {
            throw new RuntimeException('Payment account is missing.');
        }
        $ledger = $db->prepare('INSERT INTO dongtien (user_id,sotientruoc,sotienthaydoi,sotiensau,thoigian,noidung,transid) SELECT id,money,?,money + ?,NOW(),?,? FROM users WHERE id = ?');
        $ledger->execute([$credited, $credited, $reason, $transid, $userId]);
        $wallet = $db->prepare('UPDATE users SET money = money + ?, total_money = total_money + ? WHERE id = ?');
        $wallet->execute([$credited, $credited, $userId]);
        $db->prepare('INSERT INTO deposit_log (user_id,method,amount,received,create_time,is_virtual) VALUES (?,?,?,?,?,0)')
            ->execute([$userId, $method, $gross, $credited, time()]);
        $db->prepare('INSERT INTO payment_bank (tid,method,user_id,description,amount,received,create_gettime,create_time) VALUES (?,?,?,?,?,?,NOW(),?)')
            ->execute([$transid, $method, $userId, $reason, $gross, $credited, time()]);

        self::affiliate($db, $row, $credited, $transid);
        self::debt($db, $userId, $transid);

        if (!call_user_func($finalize, $db)) {
            throw new RuntimeException('Final payment-state transition failed.');
        }
        return 'credited';
    }

    private static function affiliate(PDO $db, array $user, string $credited, string $transid): void
    {
        $setting = $db->prepare('SELECT value FROM settings WHERE name = ? LIMIT 1');
        $setting->execute(['affiliate_status']);
        if ((string) $setting->fetchColumn() !== '1') {
            return;
        }
        $referrerId = (int) $user['ref_id'];
        if ($referrerId <= 0 || $referrerId === (int) $user['id']) {
            return;
        }
        $referrer = $db->prepare('SELECT id,ref_ck FROM users WHERE id = ? FOR UPDATE');
        $referrer->execute([$referrerId]);
        $referrerRow = $referrer->fetch();
        if (!$referrerRow) {
            return;
        }
        // The referrer's own rate wins; otherwise the site-wide rate applies.
        $percent = (float) $referrerRow['ref_ck'];
        if ($percent <= 0) {
            $setting->execute(['affiliate_ck']);
            $percent = (float) $setting->fetchColumn();
        }
        if ($percent < 0 || $percent > 100 || !is_finite($percent)) {
            throw new RuntimeException('Invalid affiliate configuration.');
        }
        $commission = number_format((float) $credited * $percent / 100, 2, '.', '');
        $db->prepare('INSERT INTO aff_log (user_id,reason,sotientruoc,sotienthaydoi,sotienhientai,create_gettime) SELECT id,?,ref_price,?,ref_price + ?,NOW() FROM users WHERE id = ?')
            ->execute(['Hoa hồng GachtheFast ' . $transid, $commission, $commission, $referrerId]);
        $db->prepare('UPDATE users SET ref_price = ref_price + ?, ref_total_price = ref_total_price + ? WHERE id = ?')
            ->execute([$commission, $commission, $referrerId]);
        $db->prepare('UPDATE users SET ref_amount = ref_amount + ? WHERE id = ?')
            ->execute([$commission, (int) $user['id']]);
    }

    private static function debt(PDO $db, int $userId, string $transid): void
    {
        $statement = $db->prepare('SELECT LEAST(debit,GREATEST(money,0)) FROM users WHERE id = ?');
        $statement->execute([$userId]);
        $debt = (string) $statement->fetchColumn();
        if ((float) $debt <= 0) {
            return;
        }
        $db->prepare('INSERT INTO dongtien (user_id,sotientruoc,sotienthaydoi,sotiensau,thoigian,noidung,transid) SELECT id,money,?,money - ?,NOW(),?,? FROM users WHERE id = ?')
            ->execute(['-' . $debt, $debt, 'Thanh toán số tiền ghi nợ ' . $transid, $transid . '_DEBIT', $userId]);
        $db->prepare('UPDATE users SET money = money - ?, debit = debit - ? WHERE id = ?')
            ->execute([$debt, $debt, $userId]);
    }
}
