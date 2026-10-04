<?php
if (!defined('IN_SITE')) {
    exit('The Request Not Found');
}
require_once __DIR__ . '/../../models/is_admin.php';
if (!checkPermission($getUser['admin'], 'view_recharge')) {
    http_response_code(403);
    exit('Bạn không có quyền xem quản lý nạp tiền.');
}
require_once __DIR__ . '/../../libs/payos.php';

$payosConfig = payos_config();
$payosEscape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$payosMoney = static function ($value) {
    return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',') . ' đ';
};
$payosFields = [
    'client_id' => 'PAYOS_CLIENT_ID', 'api_key' => 'PAYOS_API_KEY',
    'checksum_key' => 'PAYOS_CHECKSUM_KEY', 'app_url' => 'APP_URL',
];
$payosStatusLabels = [
    'CREATING' => 'Đang tạo', 'PENDING' => 'Chờ thanh toán',
    'UNDERPAID' => 'Chưa đủ tiền', 'PAID' => 'Đã thanh toán',
    'CANCELLED' => 'Đã hủy', 'EXPIRED' => 'Hết hạn',
    'PROCESSING' => 'Đang xử lý', 'FAILED' => 'Chưa hoàn tất',
    'CREATE_FAILED' => 'Chưa nhận kết quả tạo',
];
$payosRows = [];
$payosDatabaseReady = true;
try {
    $payosStatement = payos_db()->query('SELECT order_code, user_id, amount, credited_amount, status, created_at, credited_at FROM payos_orders ORDER BY created_at DESC, order_code DESC LIMIT 50');
    $payosRows = $payosStatement->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $error) {
    $payosDatabaseReady = false;
}
$payosConfigured = !empty($payosConfig['enabled']) && $payosDatabaseReady;
foreach ($payosFields as $payosKey => $payosLabel) {
    $payosConfigured = $payosConfigured && !empty($payosConfig[$payosKey]);
}
$payosWebhook = !empty($payosConfig['app_url']) ? rtrim($payosConfig['app_url'], '/') . '/api/webhook-payos.php' : '';
$body = ['title' => 'Nạp tiền payOS', 'desc' => 'Quản lý nạp tiền payOS', 'keyword' => '', 'header' => '', 'footer' => ''];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/nav.php';
?>
<div class="main-content app-content">
    <div class="container-fluid">
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">Nạp tiền qua payOS</h1>
            <span class="badge <?= $payosConfigured ? 'bg-success' : 'bg-warning text-dark' ?>"><?= $payosConfigured ? 'Đã có cấu hình' : 'Chưa sẵn sàng' ?></span>
        </div>
        <div class="row">
            <div class="col-xl-5">
                <div class="card custom-card">
                    <div class="card-header"><div class="card-title">Trạng thái cấu hình</div></div>
                    <div class="card-body">
                        <p class="text-muted">Trang này chỉ hiển thị tình trạng cấu hình; khóa kết nối được quản lý trên máy chủ.</p>
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle">
                                <tbody>
                                    <tr><th>PAYOS_ENABLED</th><td><?= !empty($payosConfig['enabled']) ? 'Đã bật' : 'Đang tắt' ?></td></tr>
                                    <?php foreach ($payosFields as $payosKey => $payosLabel): ?>
                                        <tr><th><code><?= $payosEscape($payosLabel) ?></code></th><td><span class="badge <?= !empty($payosConfig[$payosKey]) ? 'bg-success-transparent' : 'bg-warning-transparent' ?>"><?= !empty($payosConfig[$payosKey]) ? 'Đã khai báo' : 'Chưa khai báo' ?></span></td></tr>
                                    <?php endforeach; ?>
                                    <tr><th>Bảng giao dịch</th><td><?= $payosDatabaseReady ? 'Đã truy cập được' : 'Chưa truy cập được; kiểm tra database và migration' ?></td></tr>
                                    <tr><th>Giới hạn mỗi giao dịch</th><td><?= $payosEscape($payosMoney($payosConfig['min_amount'])) ?> – <?= $payosEscape($payosMoney($payosConfig['max_amount'])) ?></td></tr>
                                    <tr><th>Thời hạn thanh toán</th><td><?= (int) $payosConfig['expire_minutes'] ?> phút</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <label class="form-label" for="payos-webhook-url">Webhook URL</label>
                        <input id="payos-webhook-url" class="form-control" type="text" readonly value="<?= $payosEscape($payosWebhook) ?>" placeholder="Khai báo APP_URL để tạo URL webhook">
                        <p class="text-muted mt-2 mb-0">“Đã khai báo” chỉ xác nhận có giá trị cấu hình, chưa xác nhận khóa kết nối hợp lệ với payOS.</p>
                    </div>
                </div>
            </div>
            <div class="col-xl-7">
                <div class="card custom-card">
                    <div class="card-header"><div class="card-title">Thiết lập payOS</div></div>
                    <div class="card-body">
                        <ol class="ps-3">
                            <li class="mb-3">Tạo kênh thanh toán tại <a href="https://my.payos.vn/" target="_blank" rel="noopener noreferrer">payOS</a>, liên kết tài khoản ngân hàng và lấy Client ID, API Key, Checksum Key.</li>
                            <li class="mb-3">Khai báo biến môi trường <code>PAYOS_CLIENT_ID</code>, <code>PAYOS_API_KEY</code>, <code>PAYOS_CHECKSUM_KEY</code>, <code>APP_URL</code> trên máy chủ theo <code>HUONG_DAN_PAYOS.txt</code>. URL phải là địa chỉ HTTPS công khai của website, gồm thư mục con nếu có.</li>
                            <li class="mb-3">Sao lưu database rồi chạy migration từ thư mục website: <code>php scripts/migrate-payos.php</code>.</li>
                            <li class="mb-3">Đăng ký Webhook URL ở bên trái trong kênh payOS. Endpoint phải nhận được POST từ payOS; không đặt trang đăng nhập hoặc thử thách trình duyệt trước webhook.</li>
                            <li class="mb-3">Đặt <code>PAYOS_ENABLED=1</code>, tải lại trang và kiểm tra bằng một giao dịch nhỏ. Đối chiếu số dư, lịch sử nạp tiền và webhook trước khi sử dụng chính thức.</li>
                        </ol>
                        <div class="alert alert-info mb-0">Hệ thống xác nhận giao dịch bằng chữ ký webhook và dữ liệu đối soát trực tiếp từ payOS. Tham số trên trang quay về không quyết định việc cộng tiền. Mỗi giao dịch được cộng số dư một lần.</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card custom-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div class="card-title">50 giao dịch payOS gần nhất</div>
                <a class="btn btn-sm btn-outline-primary" href="<?= $payosEscape(base_url_admin('recharge-payos')) ?>">Làm mới</a>
            </div>
            <div class="card-body">
                <?php if (!$payosDatabaseReady): ?>
                    <div class="alert alert-warning">Chưa thể đọc bảng payos_orders. Kiểm tra cấu hình database và chạy migration theo hướng dẫn.</div>
                <?php endif; ?>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle text-nowrap">
                        <thead><tr><th>Mã giao dịch</th><th>ID thành viên</th><th>Số tiền</th><th>Đã cộng</th><th>Trạng thái</th><th>Thời gian tạo</th><th>Thời gian cộng tiền</th></tr></thead>
                        <tbody>
                        <?php foreach ($payosRows as $payosRow): ?>
                            <?php $payosCredited = $payosRow['status'] === 'PAID' && !empty($payosRow['credited_at']); ?>
                            <tr>
                                <td><?= $payosEscape($payosRow['order_code']) ?></td>
                                <td><?= (int) $payosRow['user_id'] ?></td>
                                <td><?= $payosEscape($payosMoney($payosRow['amount'])) ?></td>
                                <td><?= $payosEscape($payosMoney($payosCredited ? $payosRow['credited_amount'] : 0)) ?></td>
                                <td><span class="badge <?= $payosCredited ? 'bg-success-transparent' : 'bg-light text-dark' ?>"><?= $payosEscape($payosCredited ? 'Đã cộng tiền' : ($payosStatusLabels[$payosRow['status']] ?? 'Đang xử lý')) ?></span></td>
                                <td><?= $payosEscape($payosRow['created_at']) ?></td>
                                <td><?= $payosEscape($payosRow['credited_at'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$payosRows && $payosDatabaseReady): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">Chưa có giao dịch payOS.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
