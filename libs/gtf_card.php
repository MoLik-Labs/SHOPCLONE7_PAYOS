<?php

/**
 * GachtheFast card processing.
 *
 * Status: the documented request/response handling below is implemented, but NOTHING here can
 * credit a wallet yet: crediting is locked behind GTF_ALLOW_CREDITING (default false) until the
 * provider origin, the response contract and the credit rules are confirmed. A status the
 * provider documents as successful only becomes a `credit_candidate`; with the lock on, the
 * order is parked for manual review instead of being credited.
 *
 * Money rules:
 *  - a local order row is committed BEFORE the outbound provider call, so a fast
 *    callback can never orphan or duplicate a payment;
 *  - an inbound callback NEVER credits. It only records the event and triggers a
 *    verification call that we initiate ourselves;
 *  - only a status returned by our own HTTPS `command=check` call, bound to our exact
 *    request_id, can credit a wallet, and only when every amount field agrees with the
 *    stored declared amount;
 *  - status 2, unknown statuses, missing/malformed fields, amount conflicts and
 *    transport timeouts never auto-credit: they stay pending or go to manual review.
 */

if (!defined('IN_SITE')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/gtf_client.php';

final class GtfCardService
{
    private $db;
    private $client;
    private $config;

    public function __construct(PDO $db, GtfCardGateway $client, array $config)
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

    public function find($requestId, $userId = null)
    {
        if (!is_scalar($requestId) || !preg_match('/^[A-Za-z0-9]{4,64}$/D', (string) $requestId)) {
            return false;
        }
        $sql = 'SELECT * FROM gtf_card_orders WHERE request_id = ?';
        $args = [(string) $requestId];
        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $args[] = (int) $userId;
        }
        return $this->query($sql, $args)->fetch();
    }

    public function history(int $userId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        return $this->query(
            'SELECT request_id,telco,declared_amount,credited_amount,status,serial_masked,created_at,credited_at
             FROM gtf_card_orders WHERE user_id = ? ORDER BY id DESC LIMIT ' . $limit,
            [$userId]
        )->fetchAll();
    }

    /** Creates the durable local order and submits the card to the provider. */
    public function create(int $userId, array $input): array
    {
        if (!$this->config['card_enabled']) {
            throw new DomainException('Nạp thẻ GachtheFast chưa được bật.');
        }
        if ($this->config['partner_id'] === '' || $this->config['partner_key'] === '') {
            throw new DomainException('Kênh GachtheFast chưa được cấu hình.');
        }
        if ($this->config['api_base'] === '' || $this->config['card_request_format'] === '') {
            throw new DomainException('Kênh GachtheFast chưa được cấu hình đầy đủ (API origin và định dạng request phải được cấu hình rõ ràng).');
        }
        $telco = strtoupper(trim((string) ($input['telco'] ?? '')));
        if (!in_array($telco, GTF_CARD_TELCOS, true)) {
            throw new DomainException('Nhà mạng không hợp lệ.');
        }
        $amount = trim((string) ($input['amount'] ?? ''));
        if (!preg_match('/^[0-9]{1,12}$/D', $amount) || !in_array((int) $amount, GTF_CARD_DENOMINATIONS, true)) {
            throw new DomainException('Mệnh giá không hợp lệ.');
        }
        if ((int) $amount < (int) $this->config['card_min'] || (int) $amount > (int) $this->config['card_max']) {
            throw new DomainException('Mệnh giá nằm ngoài hạn mức cho phép.');
        }
        $serial = trim((string) ($input['serial'] ?? ''));
        $code = trim((string) ($input['pin'] ?? ''));
        foreach (['serial' => $serial, 'mã thẻ' => $code] as $label => $value) {
            if (!preg_match('/^[A-Za-z0-9]{6,24}$/D', $value)) {
                throw new DomainException('Định dạng ' . $label . ' không hợp lệ.');
            }
        }

        $requestId = $this->newRequestId();
        $this->db->beginTransaction();
        try {
            $user = $this->query('SELECT id,banned FROM users WHERE id = ? FOR UPDATE', [$userId])->fetch();
            if (!$user || (int) $user['banned'] !== 0) {
                throw new DomainException('Tài khoản không thể nạp thẻ.');
            }
            $recent = (int) $this->query('SELECT COUNT(*) FROM gtf_card_orders WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)', [$userId])->fetchColumn();
            if ($recent >= 5) {
                throw new DomainException('Bạn thao tác quá nhanh, vui lòng thử lại sau một phút.');
            }
            $open = (int) $this->query("SELECT COUNT(*) FROM gtf_card_orders WHERE user_id = ? AND status IN ('CREATING','PENDING','UNKNOWN','VERIFYING','REVIEW')", [$userId])->fetchColumn();
            if ($open >= 10) {
                throw new DomainException('Bạn có quá nhiều thẻ đang chờ xử lý.');
            }
            $this->query(
                "INSERT INTO gtf_card_orders (request_id,user_id,telco,declared_amount,currency,status,serial_masked,serial_sealed,code_sealed,created_at,updated_at)
                 VALUES (?,?,?,?,'VND','CREATING',?,?,?,NOW(),NOW())",
                [$requestId, $userId, $telco, $amount, GtfSecret::mask($serial), GtfSecret::seal($serial), GtfSecret::seal($code)]
            );
            $orderId = (int) $this->db->lastInsertId();
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        try {
            $response = $this->client->submit($telco, $amount, $code, $serial, $requestId);
        } catch (Throwable $error) {
            // A callback may already have finalised the order while submit was in flight.
            $changed = $this->query("UPDATE gtf_card_orders SET status = 'UNKNOWN', reason = 'submit_transport_unknown', attempt_count = attempt_count + 1, submitted_at = NOW(), updated_at = NOW() WHERE id = ? AND status = 'CREATING' AND credited_at IS NULL", [$orderId])->rowCount();
            if ($changed !== 1) {
                return $this->currentSubmissionResult($orderId, $requestId);
            }
            gtf_alert('GTF card submit outcome unknown for order ' . $orderId . ' (request ' . $requestId . ').');
            return ['status' => 'unknown', 'request_id' => $requestId, 'msg' => 'Chưa nhận được kết quả từ GachtheFast. Vui lòng kiểm tra lại trong lịch sử.'];
        }

        $providerStatus = isset($response['status']) && is_scalar($response['status']) ? (string) $response['status'] : '';
        $changed = $this->query("UPDATE gtf_card_orders SET provider_status = ?, submitted_at = NOW(), updated_at = NOW() WHERE id = ? AND status = 'CREATING' AND credited_at IS NULL", [$providerStatus === '' ? null : $providerStatus, $orderId])->rowCount();
        if ($changed !== 1) {
            return $this->currentSubmissionResult($orderId, $requestId);
        }

        if ($providerStatus === '99') {
            $changed = $this->query("UPDATE gtf_card_orders SET status = 'PENDING', updated_at = NOW() WHERE id = ? AND status = 'CREATING' AND credited_at IS NULL", [$orderId])->rowCount();
            if ($changed !== 1) { return $this->currentSubmissionResult($orderId, $requestId); }
            return ['status' => 'pending', 'request_id' => $requestId, 'msg' => 'Đẩy thẻ lên thành công, vui lòng chờ xử lý.'];
        }
        if ($providerStatus === '1' || $providerStatus === '2') {
            $changed = $this->query("UPDATE gtf_card_orders SET status = 'VERIFYING', updated_at = NOW() WHERE id = ? AND status = 'CREATING' AND credited_at IS NULL", [$orderId])->rowCount();
            if ($changed !== 1) { return $this->currentSubmissionResult($orderId, $requestId); }
            try {
                $outcome = $this->reconcile($this->find($requestId), true);
            } catch (Throwable $error) {
                // The card may already be accepted: the order stays open for the worker.
                $outcome = 'pending';
            }
            if ($outcome === 'credited' || $outcome === 'already_credited') {
                return ['status' => 'success', 'request_id' => $requestId, 'msg' => 'Nạp thẻ thành công.'];
            }
            if ($outcome === 'terminal') { return $this->currentSubmissionResult($orderId, $requestId); }
            return ['status' => 'pending', 'request_id' => $requestId, 'msg' => 'Thẻ đã được ghi nhận, đang chờ xác minh. Vui lòng kiểm tra lại trong lịch sử.'];
        }
        $terminal = [
            '3' => ['REJECTED', 'card_invalid', 'Thẻ không hợp lệ hoặc đã được sử dụng.'],
            '4' => ['MAINTENANCE', 'provider_maintenance', 'Nhà cung cấp đang bảo trì, vui lòng thử lại sau.'],
            '100' => ['REJECTED', 'submission_failed', 'GachtheFast từ chối nhận thẻ.'],
        ];
        if (isset($terminal[$providerStatus])) {
            list($status, $reason, $message) = $terminal[$providerStatus];
            $changed = $this->query("UPDATE gtf_card_orders SET status = ?, reason = ?, serial_sealed = NULL, code_sealed = NULL, updated_at = NOW() WHERE id = ? AND status = 'CREATING' AND credited_at IS NULL", [$status, $reason, $orderId])->rowCount();
            if ($changed !== 1) { return $this->currentSubmissionResult($orderId, $requestId); }
            return ['status' => 'error', 'request_id' => $requestId, 'msg' => $message];
        }
        $changed = $this->query("UPDATE gtf_card_orders SET status = 'UNKNOWN', reason = ?, updated_at = NOW() WHERE id = ? AND status = 'CREATING' AND credited_at IS NULL", ['unexpected_submit_status', $orderId])->rowCount();
        if ($changed !== 1) { return $this->currentSubmissionResult($orderId, $requestId); }
        gtf_alert('GTF card submit returned an undocumented status for order ' . $orderId . '.');
        return ['status' => 'unknown', 'request_id' => $requestId, 'msg' => 'Chưa xác định được kết quả nạp thẻ. Vui lòng kiểm tra lại trong lịch sử.'];
    }

    /** Another process updated this order while submit was running: never overwrite it. */
    private function currentSubmissionResult(int $orderId, string $requestId): array
    {
        $row = $this->query('SELECT status,credited_at FROM gtf_card_orders WHERE id = ?', [$orderId])->fetch();
        if (!$row) { throw new RuntimeException('Card order disappeared after submit.'); }
        if ($row['credited_at']) {
            return ['status' => 'success', 'request_id' => $requestId, 'msg' => 'Nạp thẻ thành công.'];
        }
        if (in_array($row['status'], GTF_CARD_TERMINAL, true)) {
            return ['status' => 'error', 'request_id' => $requestId, 'msg' => 'Đơn nạp thẻ đã kết thúc. Vui lòng kiểm tra lịch sử.'];
        }
        return ['status' => 'pending', 'request_id' => $requestId, 'msg' => 'Đơn đang được xử lý hoặc chờ xác minh. Vui lòng kiểm tra lịch sử.'];
    }

    /**
     * Handles a provider callback. The documented callback signature covers only
     * code+serial, therefore nothing in the callback is trusted for money: the event
     * is recorded and verification is triggered.
     */
    public function callback(array $query): string
    {
        foreach (['request_id', 'callback_sign', 'code', 'serial'] as $field) {
            if (!isset($query[$field]) || !is_string($query[$field])) {
                throw new InvalidArgumentException('Callback is missing required fields.');
            }
        }
        $requestId = trim($query['request_id']);
        $sign = trim($query['callback_sign']);
        $code = trim($query['code']);
        $serial = trim($query['serial']);
        if (!preg_match('/^[A-Za-z0-9]{4,64}$/D', $requestId)
            || !preg_match('/^[a-f0-9]{32}$/D', $sign)
            || !preg_match('/^[A-Za-z0-9]{6,24}$/D', $code)
            || !preg_match('/^[A-Za-z0-9]{6,24}$/D', $serial)) {
            throw new InvalidArgumentException('Callback fields are invalid.');
        }
        if ($this->config['partner_key'] === '') {
            throw new RuntimeException('The GachtheFast channel is not configured.');
        }
        if (!hash_equals(GtfCardClient::sign($this->config['partner_key'], $code, $serial), $sign)) {
            // Unauthenticated requests must not create unbounded DB rows.
            throw new InvalidArgumentException('Callback signature does not match.');
        }
        $order = $this->find($requestId);
        if (!$order) {
            return 'ignored';
        }
        if ($order['credited_at']) {
            return 'already_credited';
        }
        if (in_array($order['status'], GTF_CARD_TERMINAL, true)) {
            return 'already_final';
        }
        // The provider signature covers card data but not request_id. Bind it to the stored order.
        $storedCode = GtfSecret::open($order['code_sealed']);
        $storedSerial = GtfSecret::open($order['serial_sealed']);
        if (!hash_equals($storedCode, $code) || !hash_equals($storedSerial, $serial)) {
            throw new InvalidArgumentException('Callback card does not match the order.');
        }
        // Callback status/reference are unsigned. One event per known order is enough to record
        // receipt without allowing tampered values to create an unbounded stream of audit rows.
        $this->recordEvent((int) $order['id'], 'callback', '', $requestId, null, null);
        try {
            return $this->reconcile($order, false);
        } catch (Throwable $error) {
            // Non-2xx lets the provider retry; nothing was credited.
            error_log('GachtheFast: card callback verification failed for request ' . $requestId);
            throw new RuntimeException('Unable to verify the card outcome right now.');
        }
    }

    /**
     * Verifies one order against `command=check` and applies the approved decision.
     * @return string credited|pending|review|terminal|busy|already_credited|unknown
     */
    public function reconcile(array $order, bool $force = false): string
    {
        if (!$order) {
            return 'ignored';
        }
        if ($order['credited_at']) {
            return 'already_credited';
        }
        if (in_array($order['status'], GTF_CARD_TERMINAL, true)) {
            return 'terminal';
        }
        if (!$force) {
            $claimed = $this->query(
                'UPDATE gtf_card_orders SET provider_checked_at = NOW() WHERE id = ? AND (provider_checked_at IS NULL OR provider_checked_at < DATE_SUB(NOW(), INTERVAL 15 SECOND))',
                [(int) $order['id']]
            )->rowCount();
            if ($claimed !== 1) {
                return 'busy';
            }
        } else {
            $this->query('UPDATE gtf_card_orders SET provider_checked_at = NOW() WHERE id = ?', [(int) $order['id']]);
        }

        try {
            $code = GtfSecret::open($order['code_sealed']);
            $serial = GtfSecret::open($order['serial_sealed']);
        } catch (Throwable $error) {
            $this->markReview((int) $order['id'], 'cannot_open_card_data');
            return 'review';
        }
        if ($code === '' || $serial === '') {
            $this->markReview((int) $order['id'], 'card_data_unavailable');
            return 'review';
        }

        try {
            $remote = $this->client->check(
                (string) $order['telco'],
                $code,
                $serial,
                (string) $order['declared_amount'],
                (string) $order['request_id']
            );
        } catch (Throwable $error) {
            if (in_array($order['status'], ['CREATING', 'UNKNOWN', 'VERIFYING'], true)) {
                $this->query("UPDATE gtf_card_orders SET status = 'UNKNOWN', reason = 'check_unavailable', attempt_count = attempt_count + 1, updated_at = NOW() WHERE id = ? AND credited_at IS NULL AND status IN ('CREATING','UNKNOWN','VERIFYING')", [(int) $order['id']]);
            }
            throw new RuntimeException('The provider status lookup is unavailable.');
        }

        $status = isset($remote['status']) ? (string) $remote['status'] : '';
        // Binding check: the response must identify our own order before it can credit.
        if (!isset($remote['request_id']) || !hash_equals((string) $order['request_id'], (string) $remote['request_id'])) {
            $this->query('UPDATE gtf_card_orders SET provider_status = ?, updated_at = NOW() WHERE id = ?', [$status === '' ? null : $status, (int) $order['id']]);
            $this->markReview((int) $order['id'], 'check_not_bound_to_order');
            return 'review';
        }
        if (isset($remote['currency']) && strtoupper((string) $remote['currency']) !== 'VND') {
            $this->markReview((int) $order['id'], 'unexpected_currency');
            return 'review';
        }
        if (isset($remote['trans_id']) && is_scalar($remote['trans_id'])) {
            $this->query('UPDATE gtf_card_orders SET provider_ref = ?, updated_at = NOW() WHERE id = ?', [preg_replace('/[^A-Za-z0-9_-]/', '', (string) $remote['trans_id']), (int) $order['id']]);
        }

        $decision = GtfRules::cardDecision($remote, $order);
        $this->query('UPDATE gtf_card_orders SET provider_status = ?, updated_at = NOW() WHERE id = ?', [$status === '' ? null : $status, (int) $order['id']]);

        if ($decision['decision'] === 'pending') {
            $this->query("UPDATE gtf_card_orders SET status = IF(status = 'CREATING','PENDING',status), updated_at = NOW() WHERE id = ?", [(int) $order['id']]);
            return 'pending';
        }
        if ($decision['decision'] === 'terminal') {
            $this->finishWithoutCredit((int) $order['id'], $decision['status'], $decision['reason']);
            return 'terminal';
        }
        if ($decision['decision'] === 'credit_candidate') {
            if (!GtfCredit::isEnabled($this->config)) {
                // Fail closed: no wallet may be credited while the contract and the credit
                // rules are unconfirmed. The order is parked with an explicit reason instead.
                $this->markReview((int) $order['id'], 'credit_locked_pending_contract');
                return 'review';
            }
            return $this->credit($order, $decision['gross'], 'provider_confirmed');
        }
        $this->markReview((int) $order['id'], $decision['reason']);
        return 'review';
    }

    /** Only reconcile() may credit, after a fresh provider check. */
    private function credit(array $order, string $gross, string $source): string
    {
        if (!GtfCredit::isEnabled($this->config)) {
            throw new DomainException('Cộng tiền GachtheFast đang bị khoá cho tới khi hợp đồng và quy tắc cộng tiền được xác nhận.');
        }
        if (!GtfRules::validAmountString($gross)) {
            throw new RuntimeException('Refusing to credit a malformed amount.');
        }
        $this->db->beginTransaction();
        try {
            $locked = $this->query('SELECT * FROM gtf_card_orders WHERE id = ? FOR UPDATE', [(int) $order['id']])->fetch();
            if (!$locked) {
                throw new RuntimeException('Card order disappeared.');
            }
            if ($locked['credited_at']) {
                $this->db->commit();
                return 'already_credited';
            }
            if (in_array($locked['status'], GTF_CARD_TERMINAL, true)) {
                $this->db->commit();
                return 'terminal';
            }
            if (!in_array($locked['status'], ['CREATING', 'PENDING', 'UNKNOWN', 'VERIFYING', 'REVIEW'], true)
                || !hash_equals((string) $locked['request_id'], (string) $order['request_id'])
                || (int) $locked['user_id'] !== (int) $order['user_id']
                || (int) $locked['declared_amount'] !== (int) $gross
                || (string) $locked['telco'] !== (string) $order['telco']
                || (string) $locked['currency'] !== (string) $order['currency']
                || !hash_equals((string) $locked['serial_sealed'], (string) $order['serial_sealed'])
                || !hash_equals((string) $locked['code_sealed'], (string) $order['code_sealed'])) {
                throw new RuntimeException('Order changed after verification.');
            }
            $net = GtfRules::cardNet($gross, (float) $this->config['card_fee_percent']);
            $transid = 'GTF_CARD_' . $locked['request_id'];
            $reason = 'Nạp thẻ GachtheFast #' . $locked['request_id'] . ' (seri ' . (string) $locked['serial_masked'] . ')';
            $result = GtfCredit::applyWithin($this->db, [
                'user_id' => (int) $locked['user_id'],
                'credited' => $net,
                'gross' => $gross,
                'method' => 'GachtheFast',
                'reason' => $reason,
                'transid' => $transid,
            ], function (PDO $db) use ($locked, $net, $source) {
                $update = $db->prepare("UPDATE gtf_card_orders SET status = 'PAID', credited_amount = ?, credited_at = NOW(), reason = ?, serial_sealed = NULL, code_sealed = NULL, updated_at = NOW() WHERE id = ? AND credited_at IS NULL");
                $update->execute([$net, $source, (int) $locked['id']]);
                return $update->rowCount() === 1;
            });
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /** Backward-compatible admin action: recheck the provider, never directly credit a typed amount. */
    public function adminCredit(int $orderId, string $gross, string $note): string
    {
        if (!GtfCredit::isEnabled($this->config)) {
            throw new DomainException('Cộng tiền GachtheFast đang bị khoá cho tới khi hợp đồng và quy tắc cộng tiền được xác nhận.');
        }
        $order = $this->query('SELECT * FROM gtf_card_orders WHERE id = ?', [$orderId])->fetch();
        if (!$order) {
            throw new DomainException('Không tìm thấy đơn nạp thẻ.');
        }
        if ($order['credited_at']) {
            throw new DomainException('Đơn này đã được cộng tiền.');
        }
        if (!in_array($order['status'], ['REVIEW', 'UNKNOWN'], true) || empty($order['provider_checked_at'])) {
            throw new DomainException('Đơn chưa đủ điều kiện đối soát lại.');
        }
        if (!GtfRules::validAmountString($gross) || (int) $gross !== (int) $order['declared_amount']) {
            throw new DomainException('Số tiền phải khớp mệnh giá của đơn.');
        }
        // The posted amount and note never authorise a credit; status 2 or uncertainty remains review.
        return $this->reconcile($order, true);
    }

    public function adminResolve(int $orderId, string $status, string $note): void
    {
        if (!in_array($status, ['REJECTED', 'MAINTENANCE', 'CANCELLED', 'REVIEW'], true)) {
            throw new DomainException('Trạng thái không hợp lệ.');
        }
        $note = substr(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $note), 0, 120);
        $this->query("UPDATE gtf_card_orders SET status = ?, reason = ?, serial_sealed = IF(? <> 'REVIEW', NULL, serial_sealed), code_sealed = IF(? <> 'REVIEW', NULL, code_sealed), updated_at = NOW() WHERE id = ? AND credited_at IS NULL", [$status, $note, $status, $status, $orderId]);
    }

    /** Orders that need a human: review queue, stale pending, unknown outcomes. */
    public function reviewQueue(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return $this->query(
            "SELECT * FROM gtf_card_orders
             WHERE credited_at IS NULL AND (status = 'REVIEW' OR status = 'UNKNOWN' OR (status IN ('CREATING','VERIFYING') AND created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)))
             ORDER BY id ASC LIMIT " . $limit
        )->fetchAll();
    }

    public function pendingForReconcile(int $limit = 25): array
    {
        $limit = max(1, min(200, $limit));
        return $this->query(
            "SELECT * FROM gtf_card_orders
             WHERE credited_at IS NULL AND status IN ('CREATING','PENDING','UNKNOWN','VERIFYING')
             ORDER BY id ASC LIMIT " . $limit
        )->fetchAll();
    }

    private function finishWithoutCredit(int $orderId, string $status, string $reason): void
    {
        $this->query(
            "UPDATE gtf_card_orders SET status = ?, reason = ?, serial_sealed = NULL, code_sealed = NULL, updated_at = NOW() WHERE id = ? AND credited_at IS NULL AND status IN ('CREATING','PENDING','UNKNOWN','VERIFYING','REVIEW')",
            [$status, $reason, $orderId]
        );
    }

    private function markReview(int $orderId, string $reason): void
    {
        $changed = $this->query(
            "UPDATE gtf_card_orders SET status = 'REVIEW', reason = ?, updated_at = NOW()
             WHERE id = ? AND credited_at IS NULL AND status IN ('CREATING','PENDING','UNKNOWN','VERIFYING','REVIEW')
             AND (status <> 'REVIEW' OR reason IS NULL OR reason <> ?)",
            [$reason, $orderId, $reason]
        )->rowCount();
        if ($changed === 1) {
            gtf_alert('GTF card order ' . $orderId . ' needs manual review (' . $reason . ').');
        }
    }

    private function recordEvent($orderId, string $direction, string $status, string $requestId, $providerRef, $providerStatus): void
    {
        $key = substr(hash('sha256', $direction . '|' . $status . '|' . $requestId . '|' . (string) $providerRef), 0, 64);
        try {
            $this->query(
                'INSERT INTO gtf_events (flow,order_id,direction,event_key,provider_status,provider_ref,created_at)
                 VALUES (?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE id = id',
                ['card', $orderId, $direction, $key, $providerStatus === '' ? null : $providerStatus, $providerRef === '' ? null : $providerRef]
            );
        } catch (Throwable $error) {
            error_log('GachtheFast: could not record card event for request ' . $requestId);
        }
    }

    private function newRequestId(): string
    {
        $alphabet = 'QWERTYUIOPASDFGHJKLZXCVBNM';
        $prefix = '';
        for ($i = 0; $i < 6; ++$i) {
            $prefix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $prefix . time();
    }
}
