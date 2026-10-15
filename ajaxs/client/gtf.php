<?php

/**
 * GachtheFast client endpoints (card submission and status lookup).
 *
 * Independent of the legacy payment guard and of payOS: GachtheFast has its own feature
 * flags and never inherits the 410 replacement rule (Gate 1, routing option B).
 */

define('IN_SITE', true);
require_once __DIR__ . '/../../libs/gtf_bootstrap.php';
require_once __DIR__ . '/../../libs/gtf_card.php';
session_start();

try {
    $token = $_SESSION['login'] ?? $_COOKIE['token'] ?? '';
    if (!is_string($token) || $token === '' || strlen($token) > 255) {
        gtf_json(['status' => 'error', 'msg' => 'Vui lòng đăng nhập.'], 401);
    }
    $query = gtf_db()->prepare('SELECT id,banned FROM users WHERE token = ? LIMIT 1');
    $query->execute([$token]);
    $account = $query->fetch();
    if (!$account || (int) $account['banned'] !== 0) {
        gtf_json(['status' => 'error', 'msg' => 'Tài khoản không hợp lệ.'], 403);
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? '');

    if ($action === 'card_create' && $method === 'POST') {
        if (empty($_SESSION['gtf_csrf']) || !is_string($_POST['csrf_token'] ?? null) ||
            !hash_equals($_SESSION['gtf_csrf'], $_POST['csrf_token'])) {
            gtf_json(['status' => 'error', 'msg' => 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.'], 403);
        }
        session_write_close();
        $result = gtf_card_service()->create((int) $account['id'], $_POST);
        gtf_json(['status' => 'success', 'result' => $result['status'], 'requestId' => $result['request_id'], 'msg' => $result['msg']]);
    }

    if ($action === 'card_status' && $method === 'GET') {
        session_write_close();
        $requestId = isset($_GET['requestId']) && is_string($_GET['requestId']) ? $_GET['requestId'] : '';
        $order = gtf_card_service()->find($requestId, (int) $account['id']);
        if (!$order) {
            gtf_json(['status' => 'error', 'msg' => 'Không tìm thấy thẻ.'], 404);
        }
        gtf_json(['status' => 'success', 'card' => array_intersect_key($order, array_flip([
            'request_id', 'telco', 'declared_amount', 'credited_amount', 'status', 'serial_masked', 'created_at', 'credited_at',
        ]))]);
    }

    if ($action === 'card_history' && $method === 'GET') {
        session_write_close();
        gtf_json(['status' => 'success', 'cards' => gtf_card_service()->history((int) $account['id'], 10)]);
    }

    gtf_json(['status' => 'error', 'msg' => 'Yêu cầu không hợp lệ.'], 405);
} catch (DomainException $error) {
    gtf_json(['status' => 'error', 'msg' => $error->getMessage()], 400);
} catch (Throwable $error) {
    error_log('GachtheFast: client request failed (' . get_class($error) . ').');
    gtf_json(['status' => 'error', 'msg' => 'Chưa thể xử lý GachtheFast. Vui lòng thử lại sau.'], 503);
}
