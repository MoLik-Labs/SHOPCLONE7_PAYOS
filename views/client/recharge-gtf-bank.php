<?php

if (!defined('IN_SITE')) {
    exit('The Request Not Found');
}
require_once __DIR__ . '/../../models/is_user.php';
require_once __DIR__ . '/../../libs/gtf.php';
require_once __DIR__ . '/../../libs/gtf_bank.php';

$gtfConfig = gtf_config();
$gtfBankService = new GtfBankService(gtf_db(), $gtfConfig);
$gtfEscape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$body = [
    'title' => 'Nạp tiền ngân hàng GachtheFast | ' . $CMSNT->site('title'),
    'desc' => $CMSNT->site('description'), 'keyword' => $CMSNT->site('keywords'),
    'header' => '<link rel="stylesheet" href="' . $gtfEscape(base_url('public/client/css/wallet.css')) . '">',
    'footer' => '',
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/nav.php';
?>
<section class="py-5 inner-section profile-part">
    <div class="container">
        <div class="home-heading mb-3"><h3>NẠP TIỀN QUA NGÂN HÀNG (GACHTHEFAST)</h3></div>
        <div class="row">
            <div class="col-lg-12">
                <div class="account-card p-4">
                    <div class="alert alert-warning" role="alert">
                        Kênh nạp ngân hàng GachtheFast hiện <strong>chưa khả dụng</strong>.
                        <?= $gtfEscape($gtfBankService->unavailableReason()) ?>
                    </div>
                    <p class="text-muted mb-0">
                        Vui lòng dùng các phương thức nạp tiền khác đang hoạt động. Trang này sẽ được cập nhật
                        khi hợp đồng API ngân hàng chính thức được xác nhận và kiểm thử xong.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>
<?php
require_once __DIR__ . '/footer.php';
