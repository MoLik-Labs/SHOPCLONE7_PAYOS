<?php
if (!defined('IN_SITE')) {
    exit('The Request Not Found');
}

require_once __DIR__ . '/../../models/is_user.php';
require_once __DIR__ . '/../../libs/payos.php';

$payosConfig = payos_config();
$payosEscape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$payosMoney = static function ($value) {
    return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',') . ' đ';
};
$payosStatusLabels = [
    'CREATING' => 'Đang tạo', 'PENDING' => 'Chờ thanh toán',
    'UNDERPAID' => 'Chưa đủ tiền', 'PAID' => 'Đã thanh toán',
    'CANCELLED' => 'Đã hủy', 'EXPIRED' => 'Hết hạn',
    'PROCESSING' => 'Đang xử lý', 'FAILED' => 'Chưa hoàn tất',
    'CREATE_FAILED' => 'Chưa nhận kết quả tạo',
];
$payosCheckoutUrl = static function ($value) {
    $parts = parse_url((string) $value);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
        return '';
    }
    $host = strtolower($parts['host'] ?? '');
    return ($host === 'payos.vn' || substr($host, -9) === '.payos.vn') ? (string) $value : '';
};
$payosReady = !empty($payosConfig['enabled']) && !empty($payosConfig['client_id'])
    && !empty($payosConfig['api_key']) && !empty($payosConfig['checksum_key'])
    && !empty($payosConfig['app_url']);
$payosAppUrl = rtrim($payosConfig['app_url'] ?: base_url(), '/');
$payosOrderCode = isset($_GET['orderCode']) && is_string($_GET['orderCode'])
    && preg_match('/^[1-9][0-9]{0,14}$/D', $_GET['orderCode']) ? $_GET['orderCode'] : '';
$payosHistory = [];
$payosInitialPayment = null;
$payosHistoryError = false;
try {
    $payosDb = payos_db();
    $payosStatement = $payosDb->prepare('SELECT order_code, amount, credited_amount, status, checkout_url, created_at, credited_at FROM payos_orders WHERE user_id = ? ORDER BY created_at DESC, order_code DESC LIMIT 20');
    $payosStatement->execute([(int) $getUser['id']]);
    $payosHistory = $payosStatement->fetchAll(PDO::FETCH_ASSOC);
    if ($payosOrderCode !== '') {
        $payosStatement = $payosDb->prepare('SELECT order_code, amount, credited_amount, status, checkout_url, created_at, credited_at FROM payos_orders WHERE user_id = ? AND order_code = ? LIMIT 1');
        $payosStatement->execute([(int) $getUser['id'], $payosOrderCode]);
        $payosInitialPayment = $payosStatement->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Throwable $error) {
    $payosHistoryError = true;
    $payosReady = false;
}
$body = [
    'title' => 'Nạp tiền qua payOS | ' . $CMSNT->site('title'),
    'desc' => $CMSNT->site('description'), 'keyword' => $CMSNT->site('keywords'),
    'header' => '<link rel="stylesheet" href="' . $payosEscape(base_url('public/client/css/wallet.css')) . '">',
    'footer' => '',
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/nav.php';
?>
<section class="py-5 inner-section profile-part">
    <div class="container">
        <div class="home-heading mb-3"><h3>NẠP TIỀN QUA payOS</h3></div>
        <div class="row">
            <div class="col-lg-7">
                <div class="account-card p-4">
                    <h4 class="mb-3">Chuyển khoản ngân hàng</h4>
                    <p class="mb-3">Nhập số tiền cần nạp, mở trang thanh toán payOS và quét mã QR bằng ứng dụng ngân hàng của bạn.</p>
                    <?php if (!$payosReady): ?>
                        <div class="alert alert-warning" role="alert">Nạp tiền qua payOS hiện chưa sẵn sàng. Vui lòng liên hệ quản trị viên hoặc quay lại sau.</div>
                    <?php endif; ?>
                    <form id="payos-form" method="post" action="<?= $payosEscape($payosAppUrl . '/ajaxs/client/payos.php') ?>">
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="csrf_token" value="<?= $payosEscape(payos_csrf_token()) ?>">
                        <label class="form-label" for="payos-amount">Số tiền nạp (VND)</label>
                        <input class="form-control mb-2" id="payos-amount" name="amount" type="number" inputmode="numeric"
                            min="<?= (int) $payosConfig['min_amount'] ?>" max="<?= (int) $payosConfig['max_amount'] ?>"
                            step="1" required placeholder="Ví dụ: 100000" aria-describedby="payos-amount-help" <?= $payosReady ? '' : 'disabled' ?>>
                        <p id="payos-amount-help" class="text-muted mb-3">Từ <?= $payosEscape($payosMoney($payosConfig['min_amount'])) ?> đến <?= $payosEscape($payosMoney($payosConfig['max_amount'])) ?> mỗi giao dịch.</p>
                        <button class="btn btn-inline" id="payos-submit" type="submit" <?= $payosReady ? '' : 'disabled' ?>>Tạo thanh toán payOS</button>
                    </form>
                    <div id="payos-form-message" class="alert mt-3" role="status" aria-live="polite" hidden></div>
                    <p class="text-muted mt-3 mb-0">Tiền được cộng vào số dư sau khi hệ thống xác nhận giao dịch. Vui lòng chuyển đúng số tiền và nội dung trên trang payOS.</p>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="account-card p-4">
                    <h4 class="mb-3">Thanh toán trong 3 bước</h4>
                    <ol class="ps-3 mb-3" style="list-style: decimal;">
                        <li class="mb-2">Nhập số tiền và chọn “Tạo thanh toán payOS”.</li>
                        <li class="mb-2">Quét mã QR và hoàn tất chuyển khoản.</li>
                        <li>Quay lại đây để xem trạng thái và số tiền đã cộng.</li>
                    </ol>
                    <p class="text-muted mb-0">Bạn có thể mở lại giao dịch đang chờ trong lịch sử bên dưới. Khi đã chuyển khoản, hãy kiểm tra trạng thái trước khi tạo giao dịch mới.</p>
                </div>
                <div id="payos-payment-panel" class="account-card p-4" <?= $payosOrderCode === '' ? 'hidden' : '' ?>>
                    <h4 class="mb-3">Trạng thái giao dịch</h4>
                    <p>Mã giao dịch: <strong id="payos-current-code"><?= $payosEscape($payosOrderCode) ?></strong></p>
                    <div id="payos-payment-message" class="alert alert-info mt-3" role="status" aria-live="polite">Đang kiểm tra trạng thái thanh toán…</div>
                    <button type="button" id="payos-check-status" class="btn btn-sm btn-outline-primary">Kiểm tra lại</button>
                    <a id="payos-resume-payment" class="btn btn-sm btn-outline-primary" rel="noopener noreferrer" hidden>Mở thanh toán</a>
                </div>
            </div>
        </div>
        <div class="home-heading mb-3"><h3>LỊCH SỬ NẠP TIỀN payOS</h3></div>
        <div class="account-card p-3">
            <p class="text-muted mb-3">20 giao dịch gần nhất của bạn.</p>
            <?php if ($payosHistoryError): ?>
                <div class="alert alert-warning" role="alert">Chưa thể tải lịch sử nạp tiền. Vui lòng thử lại sau.</div>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0">
                    <thead><tr><th>Mã giao dịch</th><th>Số tiền</th><th>Đã cộng</th><th>Trạng thái</th><th>Thời gian tạo</th><th>Thao tác</th></tr></thead>
                    <tbody>
                    <?php foreach ($payosHistory as $payosOrder): ?>
                        <?php
                        $payosStatus = strtoupper($payosOrder['status']);
                        $payosLink = $payosCheckoutUrl($payosOrder['checkout_url']);
                        $payosCredited = $payosStatus === 'PAID' && !empty($payosOrder['credited_at']);
                        ?>
                        <tr>
                            <td><?= $payosEscape($payosOrder['order_code']) ?></td>
                            <td class="text-nowrap"><?= $payosEscape($payosMoney($payosOrder['amount'])) ?></td>
                            <td class="text-nowrap"><?= $payosEscape($payosMoney($payosCredited ? $payosOrder['credited_amount'] : 0)) ?></td>
                            <td><span class="badge <?= $payosCredited ? 'bg-success' : 'bg-secondary' ?>"><?= $payosEscape($payosCredited ? 'Đã cộng tiền' : ($payosStatusLabels[$payosStatus] ?? 'Đang xử lý')) ?></span></td>
                            <td class="text-nowrap"><?= $payosEscape($payosOrder['created_at']) ?></td>
                            <td class="text-nowrap">
                                <?php if ($payosLink !== '' && in_array($payosStatus, ['PENDING', 'UNDERPAID'], true)): ?>
                                    <a class="btn btn-sm btn-outline-primary mb-1" href="<?= $payosEscape($payosLink) ?>" rel="noopener noreferrer">Thanh toán</a>
                                <?php endif; ?>
                                <a class="btn btn-sm btn-outline-secondary mb-1" href="<?= $payosEscape($payosAppUrl . '/?action=recharge-bank&orderCode=' . rawurlencode($payosOrder['order_code'])) ?>">Kiểm tra</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$payosHistory && !$payosHistoryError): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">Bạn chưa có giao dịch nạp tiền qua payOS.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
<script>
(function () {
    'use strict';
    var settings = <?= json_encode([
        'endpoint' => $payosAppUrl . '/ajaxs/client/payos.php',
        'returnUrl' => $payosAppUrl . '/?action=recharge-bank',
        'orderCode' => $payosOrderCode,
        'initialPayment' => $payosInitialPayment,
        'minAmount' => (int) $payosConfig['min_amount'],
        'maxAmount' => (int) $payosConfig['max_amount'],
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
    var form = document.getElementById('payos-form');
    var submit = document.getElementById('payos-submit');
    var formMessage = document.getElementById('payos-form-message');
    var paymentMessage = document.getElementById('payos-payment-message');
    var checkButton = document.getElementById('payos-check-status');
    var resumePayment = document.getElementById('payos-resume-payment');
    var pollingTimer = null;
    var deadline = 0;
    var checking = false;
    var knownCredited = Boolean(settings.initialPayment && settings.initialPayment.status === 'PAID' && settings.initialPayment.credited_at);

    function showMessage(element, message, kind) {
        element.textContent = message;
        element.className = 'alert mt-3 alert-' + kind;
        element.hidden = false;
    }
    function money(amount) {
        return Number(amount || 0).toLocaleString('vi-VN') + ' đ';
    }
    function checkoutUrl(value) {
        try {
            var url = new URL(value);
            if (url.protocol !== 'https:' || url.username || url.password ||
                !(url.hostname === 'payos.vn' || url.hostname.endsWith('.payos.vn'))) {
                return null;
            }
            return url.href;
        } catch (error) {
            return null;
        }
    }
    async function request(url, options, timeoutMs) {
        var controller = new AbortController();
        var timeout = setTimeout(function () { controller.abort(); }, timeoutMs || 40000);
        try {
            var response = await fetch(url, Object.assign({credentials: 'same-origin', cache: 'no-store', signal: controller.signal}, options || {}));
            var result;
            try { result = await response.json(); } catch (error) {
                throw new Error('Phiên đăng nhập hoặc kết nối đã thay đổi. Hãy tải lại trang và thử lại.');
            }
            if (!response.ok || result.status !== 'success') {
                throw new Error(result.msg || result.message || 'Không thể xử lý yêu cầu. Vui lòng thử lại.');
            }
            return result;
        } finally {
            clearTimeout(timeout);
        }
    }
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (submit.disabled || !form.reportValidity()) { return; }
        var amount = document.getElementById('payos-amount').value;
        if (!/^\d+$/.test(amount) || Number(amount) < settings.minAmount || Number(amount) > settings.maxAmount) {
            showMessage(formMessage, 'Vui lòng nhập số tiền nguyên trong giới hạn cho phép.', 'warning');
            return;
        }
        submit.disabled = true;
        submit.textContent = 'Đang tạo thanh toán…';
        showMessage(formMessage, 'Đang kết nối với payOS…', 'info');
        try {
            var result = await request(settings.endpoint, {method: 'POST', body: new URLSearchParams(new FormData(form))});
            var url = checkoutUrl(result.checkoutUrl);
            if (!url) { throw new Error('Chưa nhận được liên kết thanh toán hợp lệ. Hãy kiểm tra lịch sử trước khi thử lại.'); }
            window.location.assign(url);
        } catch (error) {
            showMessage(formMessage, error.name === 'AbortError'
                ? 'Kết nối đang chậm. Hãy tải lại lịch sử để kiểm tra giao dịch trước khi tạo lại.'
                : error.message, 'danger');
            submit.disabled = false;
            submit.textContent = 'Tạo thanh toán payOS';
        }
    });

    function renderPayment(payment) {
        var status = String(payment.status || '').toUpperCase();
        var resumeUrl = checkoutUrl(payment.checkout_url);
        resumePayment.hidden = !resumeUrl || (status !== 'PENDING' && status !== 'UNDERPAID');
        if (!resumePayment.hidden) { resumePayment.href = resumeUrl; }
        if (status === 'PAID' && payment.credited_at) {
            showMessage(paymentMessage, 'Thanh toán thành công. Đã cộng ' + money(payment.credited_amount) + ' vào số dư của bạn.', 'success');
            if (!knownCredited) {
                knownCredited = true;
                window.location.replace(settings.returnUrl + '&orderCode=' + encodeURIComponent(settings.orderCode));
            }
            return true;
        }
        if (status === 'CANCELLED' || status === 'EXPIRED' || status === 'FAILED') {
            var messages = {
                CANCELLED: 'Giao dịch đã hủy. Bạn có thể tạo một thanh toán mới.',
                EXPIRED: 'Giao dịch đã hết hạn. Nếu đã chuyển khoản, vui lòng liên hệ quản trị viên kèm mã giao dịch.',
                FAILED: 'Giao dịch chưa hoàn tất. Nếu đã chuyển khoản, vui lòng liên hệ quản trị viên kèm mã giao dịch.'
            };
            showMessage(paymentMessage, messages[status], 'warning');
            return true;
        }
        if (status === 'CREATE_FAILED') {
            showMessage(paymentMessage, 'Chưa nhận được kết quả tạo thanh toán. Hệ thống đang kiểm tra lại. Hãy kiểm tra hoặc liên hệ quản trị viên với mã giao dịch trước khi tạo mới.', 'warning');
            return false;
        }
        showMessage(paymentMessage, status === 'UNDERPAID'
            ? 'Giao dịch chưa nhận đủ tiền. Hãy mở lại trang thanh toán để kiểm tra hướng dẫn.'
            : 'Đang chờ xác nhận thanh toán. Trạng thái tự cập nhật mỗi 5 giây.', 'info');
        return false;
    }
    async function checkStatus() {
        if (checking || !settings.orderCode) { return; }
        clearTimeout(pollingTimer);
        if (Date.now() >= deadline) {
            showMessage(paymentMessage, 'Đã dừng kiểm tra tự động sau 5 phút. Bấm “Kiểm tra lại” để cập nhật; giao dịch vẫn được ghi nhận khi có xác nhận thanh toán.', 'info');
            return;
        }
        checking = true;
        checkButton.disabled = true;
        var terminal = false;
        try {
            var result = await request(settings.endpoint + '?action=status&orderCode=' + encodeURIComponent(settings.orderCode), undefined, Math.min(40000, deadline - Date.now()));
            if (!result.payment || String(result.payment.order_code) !== settings.orderCode) {
                throw new Error('Chưa tìm thấy giao dịch thuộc tài khoản của bạn.');
            }
            terminal = renderPayment(result.payment);
        } catch (error) {
            showMessage(paymentMessage, error.name === 'AbortError'
                ? 'Kết nối đang chậm. Hệ thống sẽ thử kiểm tra lại.' : error.message, 'warning');
        } finally {
            checking = false;
            checkButton.disabled = false;
        }
        if (!terminal && Date.now() < deadline) {
            pollingTimer = setTimeout(checkStatus, Math.min(5000, deadline - Date.now()));
        } else if (!terminal) {
            showMessage(paymentMessage, 'Đã dừng kiểm tra tự động sau 5 phút. Bấm “Kiểm tra lại” để cập nhật; giao dịch vẫn được ghi nhận khi có xác nhận thanh toán.', 'info');
        }
    }
    checkButton.addEventListener('click', function () {
        deadline = Date.now() + 300000;
        checkStatus();
    });
    if (settings.orderCode) {
        if (knownCredited) {
            renderPayment(settings.initialPayment);
        } else {
            deadline = Date.now() + 300000;
            checkStatus();
        }
    }
    window.addEventListener('pagehide', function () { clearTimeout(pollingTimer); });
})();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
