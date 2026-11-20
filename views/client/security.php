<?php
/*
 * Account security — Orbit v3.
 *
 * Three switches the shop already supports, plus Google Authenticator enrolment:
 *   - OTP by e-mail on sign-in;
 *   - an e-mail notice after a successful sign-in;
 *   - "only the browser and IP that bought the order may view it".
 *
 * The endpoints are unchanged (`changeSecurity`, `Save2FA` in `ajaxs/client/auth.php`, same field
 * names). The QR image is generated locally by the vendored library into a data: URI — no third-party
 * request and no secret leaves the server. If the library is missing, the page says so instead of
 * printing a broken image.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . "/../common/store-shell.php";
require_once __DIR__ . "/../common/account-nav.php";
require_once __DIR__ . "/../../models/is_user.php";
if (!isset($getUser) || !is_array($getUser)) {
    $getUser = store_current_user();
}

$storeSmtp = $CMSNT->site("smtp_status") == 1;
$storeSecret = (string) $getUser["SecretKey_2fa"];
$storeQr = "";
$storeQrError = false;
if (class_exists("PragmaRX\\Google2FAQRCode\\Google2FA") && $storeSecret !== "") {
    try {
        $google2fa = new PragmaRX\Google2FAQRCode\Google2FA();
        $storeQr = (string) $google2fa->getQRCodeInline($CMSNT->site("title"), $getUser["email"], $storeSecret);
    } catch (Throwable $error) {
        $storeQrError = true;
        error_log("[orbit-2fa] cannot render the QR code: " . $error->getMessage());
    }
} elseif ($storeSecret !== "") {
    $storeQrError = true;
}

$storeShell = array(
    "title" => __("Bảo mật tài khoản") . " | " . $CMSNT->site("title"),
    "desc" => $CMSNT->site("description"),
    "nav" => store_nav_default($CMSNT),
    "noindex" => true,
    "body_class" => "page-account",
);
store_shell_open($storeShell);
?>
<section class="section section--tight" aria-labelledby="security-title">
  <div class="container container--narrow">
    <div class="page-head">
      <div>
        <p class="eyebrow">Tài khoản</p>
        <h1 id="security-title">Bảo mật tài khoản</h1>
        <p class="muted m0">Thêm một lớp xác minh và nhận thông báo khi có người đăng nhập.</p>
      </div>
    </div>

<?php store_account_nav("security"); ?>

<?php if (!$storeSmtp) { ?>
    <div class="notice notice--warn">
      <strong>Shop chưa cấu hình SMTP.</strong>
      Các công tắc gửi mail bên dưới sẽ không lưu được cho tới khi quản trị viên bật SMTP. Xác thực
      Google Authenticator vẫn dùng được.
    </div>
<?php } ?>

    <form class="stack" data-security-form
      data-endpoint="<?php echo store_e(BASE_URL("ajaxs/client/auth.php")); ?>">
      <input type="hidden" name="token" value="<?php echo store_e($getUser["token"]); ?>">

      <div class="panel">
        <div class="panel__head">
          <h2 class="panel__title">Thông báo qua email</h2>
          <span class="pill<?php echo $storeSmtp ? " pill--ok" : ""; ?>"><?php echo $storeSmtp ? "SMTP đã bật" : "SMTP chưa bật"; ?></span>
        </div>
        <div class="panel__body">
          <label class="switch">
            <input type="checkbox" name="status_otp_mail" value="1"<?php echo $getUser["status_otp_mail"] == 1 ? " checked" : ""; ?><?php echo $storeSmtp ? "" : " disabled"; ?>>
            <span class="switch__track" aria-hidden="true"></span>
            <span class="switch__label">
              <strong>Mã OTP khi đăng nhập</strong>
              <span class="muted small">Gửi mã tới <?php echo store_e($getUser["email"]); ?> sau khi nhập đúng mật khẩu.</span>
            </span>
          </label>
          <label class="switch">
            <input type="checkbox" name="status_noti_login_to_mail" value="1"<?php echo $getUser["status_noti_login_to_mail"] == 1 ? " checked" : ""; ?><?php echo $storeSmtp ? "" : " disabled"; ?>>
            <span class="switch__track" aria-hidden="true"></span>
            <span class="switch__label">
              <strong>Email báo đăng nhập</strong>
              <span class="muted small">Nhận mail mỗi lần tài khoản đăng nhập thành công.</span>
            </span>
          </label>
          <label class="switch">
            <input type="checkbox" name="status_view_order" value="1"<?php echo $getUser["status_view_order"] == 1 ? " checked" : ""; ?>>
            <span class="switch__track" aria-hidden="true"></span>
            <span class="switch__label">
              <strong>Chỉ trình duyệt và IP đã mua mới xem được đơn</strong>
              <span class="muted small">Bật nếu bạn thường xem đơn trên cùng một thiết bị.</span>
            </span>
          </label>
        </div>
      </div>

      <div class="row">
        <button class="btn btn--primary" type="submit">Lưu thay đổi</button>
        <a class="btn btn--ghost" href="<?php echo BASE_URL("client/change-password"); ?>">Đổi mật khẩu</a>
      </div>
      <p class="auth__status" role="status" aria-live="polite" data-security-status></p>
    </form>

    <form class="stack" data-2fa-form
      data-endpoint="<?php echo store_e(BASE_URL("ajaxs/client/auth.php")); ?>">
      <input type="hidden" name="token" value="<?php echo store_e($getUser["token"]); ?>">
      <div class="panel">
        <div class="panel__head">
          <h2 class="panel__title">Google Authenticator</h2>
          <span class="pill<?php echo $getUser["status_2fa"] == 1 ? " pill--ok" : ""; ?>">
            <?php echo $getUser["status_2fa"] == 1 ? "Đang bật" : "Đang tắt"; ?>
          </span>
        </div>
        <div class="panel__body">
          <p class="muted">Quét mã bằng ứng dụng Google Authenticator, sau đó nhập mã 6 số để lưu.</p>
<?php if ($storeQr !== "") { ?>
          <div class="qr"><?php echo $storeQr; ?></div>
<?php } elseif ($storeQrError) { ?>
          <div class="alert alert--warn">
            <p class="alert__title">Không tạo được mã QR</p>
            <p class="muted m0">Thư viện tạo mã QR chưa sẵn sàng. Bạn vẫn có thể nhập khoá thủ công:
              <code class="key"><?php echo store_e($storeSecret); ?></code>
            </p>
          </div>
<?php } else { ?>
          <div class="alert alert--warn">
            <p class="alert__title">Chưa có khoá 2FA</p>
            <p class="muted m0">Liên hệ quản trị viên để tạo khoá cho tài khoản này.</p>
          </div>
<?php } ?>
          <div class="field">
            <label class="field__label" for="twofactor-secret">Mã xác minh 6 số</label>
            <input class="input" id="twofactor-secret" name="secret" type="text" inputmode="numeric"
              autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" required>
            <p class="field__help">Mã đổi mỗi 30 giây. Nhập mã hiện tại trên ứng dụng.</p>
          </div>
          <label class="switch">
            <input type="checkbox" name="status_2fa" value="1"<?php echo $getUser["status_2fa"] == 1 ? " checked" : ""; ?>>
            <span class="switch__track" aria-hidden="true"></span>
            <span class="switch__label">
              <strong>Bật xác thực hai lớp</strong>
              <span class="muted small">Tắt sẽ tạo khoá mới; lần bật sau bạn quét lại mã QR.</span>
            </span>
          </label>
        </div>
      </div>
      <div class="row">
        <button class="btn btn--primary" type="submit">Lưu xác thực</button>
      </div>
      <p class="auth__status" role="status" aria-live="polite" data-2fa-status></p>
    </form>

    <p class="orders-foot muted small">Đổi mật khẩu định kỳ và không chia sẻ mã xác minh cho bất kỳ ai, kể cả người tự nhận là nhân viên shop.</p>
  </div>
</section>
<?php
store_shell_close($storeShell);
