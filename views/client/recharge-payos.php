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
require_once __DIR__ . '/../common/store-shell.php';

$storeShell = array(
    'title' => 'Nạp tiền qua payOS | ' . $CMSNT->site('title'),
    'desc' => $CMSNT->site('description'),
    'nav' => store_nav_default($CMSNT),
    'body_class' => 'page-recharge',
    'noindex' => true,
);
store_shell_open($storeShell);
?>
<section class="section section--tight" aria-labelledby="recharge-title">
  <div class="container">

    <div class="page-head">
      <div>
        <p class="eyebrow">Ví của bạn</p>
        <h1 id="recharge-title">Nạp tiền</h1>
        <p class="muted m0">Chuyển khoản ngân hàng qua payOS; tiền vào số dư sau khi hệ thống xác nhận giao dịch.</p>
      </div>
      <p class="recharge-balance">
        <span>Số dư hiện tại</span>
        <strong><?= $payosEscape(format_currency($getUser['money'])) ?></strong>
      </p>
    </div>

    <?php if (!$payosReady): ?>
    <div class="alert alert--warn" role="alert">
      Nạp tiền qua payOS hiện chưa sẵn sàng. Vui lòng liên hệ quản trị viên hoặc quay lại sau.
    </div>
    <?php endif; ?>

    <div class="recharge-grid">
      <div class="panel">
        <div class="panel__head">
          <h2 class="panel__title">Chuyển khoản ngân hàng</h2>
          <span class="pill">payOS</span>
        </div>
        <div class="panel__body">
          <p class="muted">Nhập số tiền cần nạp, mở trang thanh toán payOS và quét mã QR bằng ứng dụng ngân hàng của bạn.</p>
          <form id="payos-form" class="recharge-form" method="post" action="<?= $payosEscape($payosAppUrl . '/ajaxs/client/payos.php') ?>">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="csrf_token" value="<?= $payosEscape(payos_csrf_token()) ?>">
            <div class="field">
              <label class="field__label" for="payos-amount">Số tiền nạp (VND)</label>
              <input class="input" id="payos-amount" name="amount" type="number" inputmode="numeric"
                min="<?= (int) $payosConfig['min_amount'] ?>" max="<?= (int) $payosConfig['max_amount'] ?>"
                step="1" required placeholder="Ví dụ: 100000" aria-describedby="payos-amount-help" <?= $payosReady ? '' : 'disabled' ?>>
              <p class="field__help" id="payos-amount-help">Từ <?= $payosEscape($payosMoney($payosConfig['min_amount'])) ?> đến <?= $payosEscape($payosMoney($payosConfig['max_amount'])) ?> mỗi giao dịch.</p>
            </div>
            <button class="btn btn--primary" id="payos-submit" type="submit" <?= $payosReady ? '' : 'disabled' ?>>Tạo thanh toán payOS</button>
          </form>
          <div id="payos-form-message" class="alert recharge-message" role="status" aria-live="polite" hidden></div>
          <p class="small faint m0">Tiền được cộng vào số dư sau khi hệ thống xác nhận giao dịch. Vui lòng chuyển đúng số tiền và nội dung trên trang payOS.</p>
        </div>
      </div>

      <aside class="panel panel--muted recharge-steps">
        <div class="panel__head">
          <h2 class="panel__title">Ba bước</h2>
        </div>
        <div class="panel__body">
          <ol class="steps">
            <li><strong>Nhập số tiền</strong><span>Chọn số tiền trong giới hạn và bấm “Tạo thanh toán payOS”.</span></li>
            <li><strong>Quét mã và chuyển khoản</strong><span>Mở trang payOS, quét QR bằng ứng dụng ngân hàng và hoàn tất.</span></li>
            <li><strong>Quay lại kiểm tra</strong><span>Trạng thái tự cập nhật; số tiền đã cộng hiện trong lịch sử bên dưới.</span></li>
          </ol>
          <p class="small faint m0">Khi đã chuyển khoản, hãy kiểm tra trạng thái trước khi tạo giao dịch mới.</p>
        </div>
      </aside>
    </div>

    <div id="payos-payment-panel" class="panel recharge-status" <?= $payosOrderCode === '' ? 'hidden' : '' ?>>
      <div class="panel__head">
        <h2 class="panel__title">Trạng thái giao dịch</h2>
        <span class="pill pill--accent mono" id="payos-current-code"><?= $payosEscape($payosOrderCode) ?></span>
      </div>
      <div class="panel__body">
        <div id="payos-payment-message" class="alert recharge-message" role="status" aria-live="polite">Đang kiểm tra trạng thái thanh toán…</div>
        <div class="recharge-status__actions">
          <button type="button" id="payos-check-status" class="btn btn--sm">Kiểm tra lại</button>
          <a id="payos-resume-payment" class="btn btn--sm btn--primary" rel="noopener noreferrer" hidden>Mở thanh toán</a>
        </div>
      </div>
    </div>

    <div class="panel recharge-history">
      <div class="panel__head">
        <h2 class="panel__title">Lịch sử nạp tiền</h2>
        <span class="small faint">20 giao dịch gần nhất</span>
      </div>
      <div class="panel__body">
        <?php if ($payosHistoryError): ?>
        <div class="alert alert--warn" role="alert">Chưa thể tải lịch sử nạp tiền. Vui lòng thử lại sau.</div>
        <?php endif; ?>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead>
              <tr>
                <th scope="col">Mã giao dịch</th>
                <th scope="col" class="num">Số tiền</th>
                <th scope="col" class="num">Đã cộng</th>
                <th scope="col">Trạng thái</th>
                <th scope="col">Thời gian tạo</th>
                <th scope="col"><span class="sr-only">Thao tác</span></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($payosHistory as $payosOrder): ?>
              <?php
              $payosStatus = strtoupper($payosOrder['status']);
              $payosLink = $payosCheckoutUrl($payosOrder['checkout_url']);
              $payosCredited = $payosStatus === 'PAID' && !empty($payosOrder['credited_at']);
              ?>
              <tr>
                <td class="mono"><?= $payosEscape($payosOrder['order_code']) ?></td>
                <td class="num"><?= $payosEscape($payosMoney($payosOrder['amount'])) ?></td>
                <td class="num"><?= $payosEscape($payosMoney($payosCredited ? $payosOrder['credited_amount'] : 0)) ?></td>
                <td><span class="pill<?= $payosCredited ? ' pill--ok' : '' ?>"><?= $payosEscape($payosCredited ? 'Đã cộng tiền' : ($payosStatusLabels[$payosStatus] ?? 'Đang xử lý')) ?></span></td>
                <td class="small faint"><?= $payosEscape($payosOrder['created_at']) ?></td>
                <td>
                  <div class="table__actions">
                    <?php if ($payosLink !== '' && in_array($payosStatus, ['PENDING', 'UNDERPAID'], true)): ?>
                    <a class="btn btn--sm" href="<?= $payosEscape($payosLink) ?>" rel="noopener noreferrer">Thanh toán</a>
                    <?php endif; ?>
                    <a class="btn btn--sm btn--ghost" href="<?= $payosEscape($payosAppUrl . '/?action=recharge-bank&orderCode=' . rawurlencode($payosOrder['order_code'])) ?>">Kiểm tra</a>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$payosHistory && !$payosHistoryError): ?>
              <tr>
                <td colspan="6">
                  <div class="empty">
                    <p class="empty__title">Chưa có giao dịch nào</p>
                    <p class="m0">Giao dịch nạp tiền qua payOS sẽ hiện ở đây sau khi bạn tạo.</p>
                  </div>
                </td>
              </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
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
        element.className = 'alert recharge-message' + (kind === 'success' ? ' alert--ok' : kind === 'warning' ? ' alert--warn' : kind === 'danger' ? ' alert--danger' : '');
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

<?php
store_shell_close();
