<?php

/**
 * GachtheFast admin actions: manual review of card orders.
 *
 * Every action here touches money or closes a payment, so it requires an authenticated admin
 * session, the `edit_recharge` permission, an explicit CSRF token, and it is logged. Credits
 * go through the same single-transaction, uniquely-keyed routine as the automatic path, so an
 * operator cannot double-credit a settled order — and they refuse entirely while the
 * GTF_ALLOW_CREDITING lock is on.
 *
 * There are deliberately NO bank actions: the bank flow has no confirmed contract, so it has
 * nothing to review, and `GtfBankService` refuses every operation.
 */

define('IN_SITE', true);
require_once __DIR__ . '/../../libs/gtf_bootstrap.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../libs/db.php';
require_once __DIR__ . '/../../libs/lang.php';
require_once __DIR__ . '/../../libs/helper.php';
require_once __DIR__ . '/../../libs/database/users.php';
require_once __DIR__ . '/../../libs/gtf_card.php';
require_once __DIR__ . '/../../models/is_admin.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    gtf_json(['status' => 'error', 'msg' => 'Yêu cầu không hợp lệ.'], 405);
}
if (!checkPermission($getUser['admin'], 'edit_recharge')) {
    gtf_json(['status' => 'error', 'msg' => 'Bạn không có quyền sử dụng tính năng này.'], 403);
}
if (empty($_SESSION['gtf_csrf']) || !is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['gtf_csrf'], $_POST['csrf_token'])) {
    gtf_json(['status' => 'error', 'msg' => 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.'], 403);
}
if ((string) $CMSNT->site('status_demo') !== '0') {
    gtf_json(['status' => 'error', 'msg' => 'Chức năng này không thể sử dụng trên website demo.'], 403);
}

$action = (string) ($_POST['action'] ?? '');
$orderId = (int) ($_POST['order_id'] ?? 0);
$note = (string) ($_POST['note'] ?? '');
$Mobile_Detect = new Mobile_Detect();

try {
    if (in_array($action, ['card_credit', 'card_resolve', 'card_reconcile'], true)) {
        if ($orderId <= 0) {
            throw new DomainException('Đơn không hợp lệ.');
        }
        $cardService = gtf_card_service();
    }
    switch ($action) {
        case 'card_credit':
            $amount = trim((string) ($_POST['amount'] ?? ''));
            if (!preg_match('/^[0-9]{1,15}$/D', $amount) || (int) $amount <= 0) {
                throw new DomainException('Số tiền cộng không hợp lệ.');
            }
            $result = $cardService->adminCredit($orderId, $amount, $note);
            $CMSNT->insert('logs', ['user_id' => $getUser['id'], 'ip' => myip(), 'device' => $Mobile_Detect->getUserAgent(), 'createdate' => gettime(), 'action' => 'GachtheFast card review recheck #' . $orderId . ' (' . $result . ')']);
            gtf_json(['status' => 'success', 'msg' => 'Kết quả đối soát: ' . $result]);

        case 'card_resolve':
            $cardService->adminResolve($orderId, strtoupper(trim((string) ($_POST['status'] ?? ''))), $note);
            $CMSNT->insert('logs', ['user_id' => $getUser['id'], 'ip' => myip(), 'device' => $Mobile_Detect->getUserAgent(), 'createdate' => gettime(), 'action' => 'GachtheFast card review resolve #' . $orderId]);
            gtf_json(['status' => 'success', 'msg' => 'Đã cập nhật trạng thái đơn.']);

        case 'card_reconcile':
            $order = gtf_db()->prepare('SELECT * FROM gtf_card_orders WHERE id = ?');
            $order->execute([$orderId]);
            $row = $order->fetch();
            if (!$row) {
                throw new DomainException('Không tìm thấy đơn.');
            }
            $outcome = $cardService->reconcile($row, true);
            $CMSNT->insert('logs', ['user_id' => $getUser['id'], 'ip' => myip(), 'device' => $Mobile_Detect->getUserAgent(), 'createdate' => gettime(), 'action' => 'GachtheFast card reconcile #' . $orderId . ' (' . $outcome . ')']);
            gtf_json(['status' => 'success', 'msg' => 'Kết quả đối soát: ' . $outcome]);

    }

    gtf_json(['status' => 'error', 'msg' => 'Hành động không tồn tại.'], 400);
} catch (DomainException $error) {
    gtf_json(['status' => 'error', 'msg' => $error->getMessage()], 400);
} catch (Throwable $error) {
    error_log('GachtheFast: admin action failed (' . get_class($error) . ').');
    gtf_json(['status' => 'error', 'msg' => 'Không thể xử lý yêu cầu. Vui lòng xem nhật ký hệ thống.'], 503);
}
