<?php

define('IN_SITE', true);
require_once __DIR__ . '/../../libs/payos_bootstrap.php';
session_start();

try {
    $token = $_SESSION['login'] ?? $_COOKIE['token'] ?? '';
    if (!is_string($token) || $token === '' || strlen($token) > 255) {
        payos_json(['status' => 'error', 'msg' => 'Vui lòng đăng nhập.'], 401);
    }
    $query = payos_db()->prepare('SELECT id,banned FROM users WHERE token = ? LIMIT 1');
    $query->execute([$token]);
    $account = $query->fetch();
    if (!$account || (int) $account['banned'] !== 0) {
        payos_json(['status' => 'error', 'msg' => 'Tài khoản không hợp lệ.'], 403);
    }
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? '');
    if ($action === 'create' && $method === 'POST') {
        if (empty($_SESSION['payos_csrf']) || !is_string($_POST['csrf_token'] ?? null) ||
            !hash_equals($_SESSION['payos_csrf'], $_POST['csrf_token'])) {
            payos_json(['status' => 'error', 'msg' => 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.'], 403);
        }
        session_write_close();
        $order = payos_service()->create((int) $account['id'], $_POST['amount'] ?? '');
        payos_json(['status' => 'success', 'checkoutUrl' => $order['checkout_url'], 'orderCode' => (string) $order['order_code']]);
    }
    if ($action === 'status' && $method === 'GET') {
        session_write_close();
        $service = payos_service();
        $order = $service->find($_GET['orderCode'] ?? '', $account['id']);
        if (!$order) {
            payos_json(['status' => 'error', 'msg' => 'Không tìm thấy yêu cầu nạp tiền.'], 404);
        }
        $syncPending = false;
        try {
            $order = $service->refresh($order);
        } catch (RuntimeException $error) {
            $syncPending = true;
            error_log('payOS: reconciliation unavailable for order ' . $order['order_code']);
        }
        payos_json(['status' => 'success', 'syncPending' => $syncPending, 'payment' => array_intersect_key($order, array_flip([
            'order_code','status','amount','credited_amount','credited_at','checkout_url',
        ]))]);
    }
    payos_json(['status' => 'error', 'msg' => 'Yêu cầu không hợp lệ.'], 405);
} catch (DomainException $error) {
    payos_json(['status' => 'error', 'msg' => $error->getMessage()], 400);
} catch (Throwable $error) {
    error_log('payOS: client request failed (' . get_class($error) . ')');
    payos_json(['status' => 'error', 'msg' => 'Chưa thể xử lý payOS. Vui lòng kiểm tra cấu hình hoặc thử lại sau.'], 503);
}
