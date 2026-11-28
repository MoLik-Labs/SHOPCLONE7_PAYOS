<?php

if (!defined('IN_SITE')) {
    exit('The Request Not Found');
}
require_once __DIR__ . '/../../models/is_admin.php';
if (!checkPermission($getUser['admin'], 'view_recharge')) {
    http_response_code(403);
    exit('Bạn không có quyền xem quản lý nạp tiền.');
}
require_once __DIR__ . '/../../libs/gtf.php';

$body = ['title' => 'GachtheFast', 'desc' => 'Quản lý nạp tiền GachtheFast', 'keyword' => '', 'header' => '', 'footer' => ''];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/nav.php';

$gtfConfig = gtf_config();
$gtfEscape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$gtfMoney = static function ($value) {
    return number_format((float) $value, 2, ',', '.') . ' đ';
};
$gtfCardOrders = [];
$gtfCardReview = [];
$gtfError = '';
try {
    $gtfCardOrders = gtf_db()->query('SELECT id,request_id,user_id,telco,declared_amount,credited_amount,status,provider_status,serial_masked,reason,created_at,credited_at FROM gtf_card_orders ORDER BY id DESC LIMIT 50')->fetchAll();
    $gtfCardReview = gtf_db()->query("SELECT * FROM gtf_card_orders WHERE credited_at IS NULL AND (status = 'REVIEW' OR status = 'UNKNOWN') ORDER BY id ASC LIMIT 50")->fetchAll();
} catch (Throwable $error) {
    $gtfError = 'Chưa chạy migration GachtheFast hoặc không đọc được dữ liệu.';
}
?>
<div class="main-content app-content mt-0">
    <div class="side-app">
        <div class="main-container container-fluid">
            <div class="page-header">
                <h1 class="page-title">GachtheFast</h1>
            </div>

            <?php if ($gtfError !== ''): ?>
                <div class="alert alert-danger"><?= $gtfEscape($gtfError) ?></div>
            <?php endif; ?>

            <div class="row">
                <div class="col-xl-12">
                    <div class="card custom-card">
                        <div class="card-header"><div class="card-title">CẤU HÌNH</div></div>
                        <div class="card-body">
                            <ul class="mb-0">
                                <li>API base: <strong><?= $gtfConfig['api_base'] === '' ? 'chưa cấu hình' : $gtfEscape($gtfConfig['api_base']) ?></strong></li>
                                <li>Partner ID: <strong><?= $gtfConfig['partner_id'] === '' ? 'chưa cấu hình' : 'đã cấu hình' ?></strong> · Partner Key: <strong><?= $gtfConfig['partner_key'] === '' ? 'chưa cấu hình' : 'đã cấu hình' ?></strong> (giá trị không hiển thị)</li>
                                <li>Nạp thẻ (GTF_CARD_ENABLED): <strong><?= $gtfConfig['card_enabled'] ? 'ON' : 'OFF' ?></strong> · Định dạng request (GTF_CARD_REQUEST_FORMAT): <strong><?= $gtfConfig['card_request_format'] === '' ? 'chưa cấu hình' : $gtfEscape($gtfConfig['card_request_format']) ?></strong></li>
                                <li>Cộng tiền tự động (GTF_ALLOW_CREDITING): <strong><?= GtfCredit::isEnabled($gtfConfig) ? 'ON' : 'OFF' ?></strong><?= GtfCredit::isEnabled($gtfConfig) ? '' : ' — mọi đơn đủ điều kiện đang được đưa vào kiểm tra thủ công, không tự động cộng ví.' ?></li>
                                <li>Phí nạp thẻ: <strong><?= $gtfEscape($gtfConfig['card_fee_percent']) ?>%</strong> · Hạn mức thẻ: <strong><?= $gtfEscape($gtfMoney($gtfConfig['card_min'])) ?> – <?= $gtfEscape($gtfMoney($gtfConfig['card_max'])) ?></strong></li>
                                <li>Callback thẻ: <strong><?= $gtfEscape(base_url('api/callback-gtf-card.php')) ?></strong> · Worker thẻ: <code>php cron/gtf-card.php</code></li>
                                <li>Nạp ngân hàng: <strong>KHOÁ</strong> — chưa có hợp đồng API ngân hàng GachtheFast nào được xác nhận, nên không có bảng dữ liệu, không gọi nhà cung cấp và không cộng tiền. (<code>php cron/gtf-bank.php</code> là no-op)</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-xl-12">
                    <div class="card custom-card">
                        <div class="card-header"><div class="card-title">THẺ CẦN KIỂM TRA THỦ CÔNG (<?= count($gtfCardReview) ?>)</div></div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm align-middle">
                                    <thead><tr><th>#</th><th>Mã yêu cầu</th><th>User</th><th>Mệnh giá</th><th>Trạng thái</th><th>Lý do</th><th>Serial</th><th>Thời gian</th><th>Thao tác</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($gtfCardReview as $gtfRow): ?>
                                        <tr>
                                            <td><?= (int) $gtfRow['id'] ?></td>
                                            <td><?= $gtfEscape($gtfRow['request_id']) ?></td>
                                            <td><?= (int) $gtfRow['user_id'] ?></td>
                                            <td class="text-nowrap"><?= $gtfEscape($gtfMoney($gtfRow['declared_amount'])) ?></td>
                                            <td><?= $gtfEscape($gtfRow['status']) ?></td>
                                            <td><?= $gtfEscape($gtfRow['reason']) ?></td>
                                            <td><?= $gtfEscape($gtfRow['serial_masked']) ?></td>
                                            <td class="text-nowrap"><?= $gtfEscape($gtfRow['created_at']) ?></td>
                                            <td class="text-nowrap">
                                                <button class="btn btn-sm btn-outline-primary gtf-action"
                                                        data-action="card_reconcile" data-order-id="<?= (int) $gtfRow['id'] ?>">Đối soát</button>
                                                <button class="btn btn-sm btn-outline-danger gtf-action"
                                                        data-action="card_resolve" data-order-id="<?= (int) $gtfRow['id'] ?>"
                                                        data-status="REJECTED">Đánh dấu lỗi</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$gtfCardReview): ?>
                                        <tr><td colspan="9" class="text-center text-muted">Không có đơn nào cần xử lý.</td></tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-xl-12">
                    <div class="card custom-card">
                        <div class="card-header"><div class="card-title">50 ĐƠN THẺ GẦN NHẤT</div></div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm align-middle">
                                    <thead><tr><th>#</th><th>Mã yêu cầu</th><th>User</th><th>Nhà mạng</th><th>Mệnh giá</th><th>Đã cộng</th><th>Trạng thái</th><th>NCC</th><th>Lý do</th><th>Thời gian</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($gtfCardOrders as $gtfRow): ?>
                                        <tr>
                                            <td><?= (int) $gtfRow['id'] ?></td>
                                            <td><?= $gtfEscape($gtfRow['request_id']) ?></td>
                                            <td><?= (int) $gtfRow['user_id'] ?></td>
                                            <td><?= $gtfEscape($gtfRow['telco']) ?></td>
                                            <td class="text-nowrap"><?= $gtfEscape($gtfMoney($gtfRow['declared_amount'])) ?></td>
                                            <td class="text-nowrap"><?= $gtfEscape($gtfMoney($gtfRow['credited_amount'] ?: 0)) ?></td>
                                            <td><?= $gtfEscape($gtfRow['status']) ?></td>
                                            <td><?= $gtfEscape($gtfRow['provider_status']) ?></td>
                                            <td><?= $gtfEscape($gtfRow['reason']) ?></td>
                                            <td class="text-nowrap"><?= $gtfEscape($gtfRow['created_at']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$gtfCardOrders): ?>
                                        <tr><td colspan="10" class="text-center text-muted">Chưa có đơn thẻ nào.</td></tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
<script>
(function () {
    'use strict';
    var endpoint = <?= json_encode(base_url('ajaxs/admin/gtf.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var csrf = <?= json_encode(gtf_csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    function send(payload) {
        var body = new URLSearchParams(payload);
        body.set('csrf_token', csrf);
        return fetch(endpoint, {method: 'POST', credentials: 'same-origin', cache: 'no-store', body: body})
            .then(function (response) { return response.json(); })
            .then(function (result) {
                alert(result.msg || (result.status === 'success' ? 'Đã xử lý.' : 'Không thể xử lý.'));
                if (result.status === 'success') { location.reload(); }
            })
            .catch(function () { alert('Không thể xử lý yêu cầu.'); });
    }
    document.querySelectorAll('.gtf-action').forEach(function (button) {
        button.addEventListener('click', function () {
            var payload = {action: button.dataset.action, order_id: button.dataset.orderId};
            if (button.dataset.action === 'card_resolve') {
                payload.status = button.dataset.status;
                payload.note = prompt('Lý do:', '') || '';
            }
            send(payload);
        });
    });
})();
</script>
<?php
require_once __DIR__ . '/footer.php';
