<?php

if (!defined('IN_SITE')) {
    exit('The Request Not Found');
}
require_once __DIR__ . '/../../models/is_user.php';
require_once __DIR__ . '/../../libs/gtf.php';

$gtfConfig = gtf_config();
$gtfReady = !empty($gtfConfig['card_enabled']) && !empty($gtfConfig['partner_id']) && !empty($gtfConfig['partner_key']) && $gtfConfig['api_base'] !== '' && $gtfConfig['card_request_format'] !== '';
$gtfAutoCredit = GtfCredit::isEnabled($gtfConfig);
$gtfEscape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$gtfMoney = static function ($value) {
    return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',') . ' đ';
};
$gtfStatusLabels = [
    'CREATING' => 'Đang gửi', 'PENDING' => 'Chờ xử lý', 'VERIFYING' => 'Đang xác minh',
    'UNKNOWN' => 'Chưa rõ kết quả', 'REVIEW' => 'Chờ admin kiểm tra', 'PAID' => 'Thành công',
    'REJECTED' => 'Thẻ lỗi', 'MAINTENANCE' => 'Nhà cung cấp bảo trì', 'CANCELLED' => 'Đã hủy',
];
$gtfCards = [];
try {
    // Reads only this user's own rows; safe to attempt before the migration has run.
    $gtfCardStatement = gtf_db()->prepare('SELECT request_id,telco,declared_amount,credited_amount,status,serial_masked,created_at,credited_at FROM gtf_card_orders WHERE user_id = ? ORDER BY id DESC LIMIT 10');
    $gtfCardStatement->execute([(int) $getUser['id']]);
    $gtfCards = $gtfCardStatement->fetchAll();
} catch (Throwable $error) {
    $gtfCards = [];
}
$body = [
    'title' => 'Nạp thẻ GachtheFast | ' . $CMSNT->site('title'),
    'desc' => $CMSNT->site('description'), 'keyword' => $CMSNT->site('keywords'),
    'header' => '<link rel="stylesheet" href="' . $gtfEscape(base_url('public/client/css/wallet.css')) . '">',
    'footer' => '',
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/nav.php';
?>
<section class="py-5 inner-section profile-part">
    <div class="container">
        <div class="home-heading mb-3"><h3>NẠP THẺ CÀO QUA GACHTHEFAST</h3></div>
        <div class="row">
            <div class="col-lg-7">
                <div class="account-card p-4">
                    <?php if (!$gtfReady): ?>
                        <div class="alert alert-warning" role="alert">Kênh nạp thẻ GachtheFast hiện chưa sẵn sàng. Vui lòng liên hệ quản trị viên.</div>
                    <?php endif; ?>
                    <form id="gtf-card-form">
                        <input type="hidden" name="action" value="card_create">
                        <input type="hidden" name="csrf_token" value="<?= $gtfEscape(gtf_csrf_token()) ?>">
                        <label class="form-label" for="gtf-telco">Loại thẻ</label>
                        <select class="form-control mb-3" id="gtf-telco" name="telco" required <?= $gtfReady ? '' : 'disabled' ?>>
                            <option value="">-- Chọn loại thẻ --</option>
                            <?php foreach (GTF_CARD_TELCOS as $gtfTelco): ?>
                                <option value="<?= $gtfEscape($gtfTelco) ?>"><?= $gtfEscape($gtfTelco) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label class="form-label" for="gtf-amount">Mệnh giá</label>
                        <select class="form-control mb-3" id="gtf-amount" name="amount" required <?= $gtfReady ? '' : 'disabled' ?>>
                            <option value="">-- Chọn mệnh giá --</option>
                            <?php foreach (GTF_CARD_DENOMINATIONS as $gtfDenomination): ?>
                                <?php if ($gtfDenomination >= (int) $gtfConfig['card_min'] && $gtfDenomination <= (int) $gtfConfig['card_max']): ?>
                                    <option value="<?= (int) $gtfDenomination ?>"><?= $gtfEscape($gtfMoney($gtfDenomination)) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <label class="form-label" for="gtf-serial">Serial</label>
                        <input class="form-control mb-3" id="gtf-serial" name="serial" autocomplete="off" required <?= $gtfReady ? '' : 'disabled' ?>>
                        <label class="form-label" for="gtf-pin">Mã thẻ</label>
                        <input class="form-control mb-3" id="gtf-pin" name="pin" autocomplete="off" required <?= $gtfReady ? '' : 'disabled' ?>>
                        <button class="btn btn-inline" id="gtf-submit" type="submit" <?= $gtfReady ? '' : 'disabled' ?>>Nạp thẻ</button>
                    </form>
                    <div id="gtf-message" class="alert mt-3" role="status" aria-live="polite" hidden></div>
                    <p class="text-muted mt-3 mb-0">Thẻ được gửi tới GachtheFast và chỉ được cộng tiền sau khi hệ thống tự xác minh lại kết quả bằng <code>command=check</code>.<?= $gtfAutoCredit ? '' : ' Hiện tại mọi đơn đủ điều kiện đều được chuyển cho quản trị viên kiểm tra trước khi cộng ví.' ?> Không nhập thẻ không rõ nguồn gốc.</p>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="account-card p-4">
                    <h4 class="mb-3">Lưu ý</h4>
                    <p class="text-muted mb-0">Nếu kết quả chưa rõ, đơn sẽ nằm ở trạng thái “Chưa rõ kết quả” hoặc “Chờ admin kiểm tra” và không tự cộng tiền. Vui lòng không gửi lại thẻ.</p>
                </div>
            </div>
            <div class="col-md-12">
                <div class="home-heading mb-3 mt-3"><h3>LỊCH SỬ NẠP THẺ GACHTHEFAST</h3></div>
                <div class="account-card p-3">
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0">
                            <thead><tr><th>Mã yêu cầu</th><th>Loại thẻ</th><th>Mệnh giá</th><th>Đã cộng</th><th>Serial</th><th>Trạng thái</th><th>Thời gian</th></tr></thead>
                            <tbody>
                            <?php foreach ($gtfCards as $gtfCard): ?>
                                <tr>
                                    <td><?= $gtfEscape($gtfCard['request_id']) ?></td>
                                    <td><?= $gtfEscape($gtfCard['telco']) ?></td>
                                    <td class="text-nowrap"><?= $gtfEscape($gtfMoney($gtfCard['declared_amount'])) ?></td>
                                    <td class="text-nowrap"><?= $gtfEscape($gtfMoney($gtfCard['credited_amount'] ?: 0)) ?></td>
                                    <td><?= $gtfEscape($gtfCard['serial_masked']) ?></td>
                                    <td><span class="badge <?= $gtfCard['credited_at'] ? 'bg-success' : 'bg-secondary' ?>"><?= $gtfEscape($gtfStatusLabels[strtoupper($gtfCard['status'])] ?? 'Đang xử lý') ?></span></td>
                                    <td class="text-nowrap"><?= $gtfEscape($gtfCard['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$gtfCards): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">Bạn chưa có giao dịch nạp thẻ GachtheFast.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<script>
(function () {
    'use strict';
    var settings = <?= json_encode([
        'endpoint' => base_url('ajaxs/client/gtf.php'),
        'ready' => (bool) $gtfReady,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
    var form = document.getElementById('gtf-card-form');
    var submit = document.getElementById('gtf-submit');
    var message = document.getElementById('gtf-message');
    function show(text, kind) {
        message.textContent = text;
        message.className = 'alert mt-3 alert-' + kind;
        message.hidden = false;
    }
    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!settings.ready) {
                show('Kênh nạp thẻ chưa sẵn sàng.', 'warning');
                return;
            }
            submit.disabled = true;
            show('Đang gửi thẻ tới GachtheFast…', 'info');
            fetch(settings.endpoint, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store', body: new FormData(form)
            }).then(function (response) {
                return response.json().catch(function () {
                    throw new Error('Phản hồi không hợp lệ. Vui lòng tải lại trang.');
                }).then(function (result) {
                    if (!response.ok || result.status !== 'success') {
                        throw new Error(result.msg || 'Không thể xử lý yêu cầu.');
                    }
                    return result;
                });
            }).then(function (result) {
                show(result.msg || 'Đã gửi thẻ.', result.result === 'success' ? 'success' : 'info');
                if (result.result === 'success') {
                    form.reset();
                }
            }).catch(function (error) {
                show(error.message, 'danger');
            }).then(function () {
                submit.disabled = false;
            });
        });
    }
})();
</script>
<?php
require_once __DIR__ . '/footer.php';
