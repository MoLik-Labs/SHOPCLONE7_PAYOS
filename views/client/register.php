<?php
/*
 * Registration — Orbit v3.
 *
 * Same endpoint and field names as before (`ajaxs/client/auth.php`, action Register). The password
 * strength hint is advisory only; the server still validates length and the confirmation field.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . "/../common/store-shell.php";

$storeHead = "";
if ((string) $CMSNT->site("reCAPTCHA_status") === "1") {
    $storeHead = '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
}
$storeShell = array(
    "title" => __("Đăng ký tài khoản") . " | " . $CMSNT->site("title"),
    "desc" => $CMSNT->site("description"),
    "nav" => store_nav_default($CMSNT),
    "body_class" => "page-auth",
    "head" => $storeHead,
);
store_shell_open($storeShell);
?>

<section class="auth" aria-labelledby="auth-title">
  <div class="auth__card">
    <header class="auth__head">
      <p class="eyebrow">Tài khoản mới</p>
      <h1 id="auth-title">Đăng ký tài khoản</h1>
      <p class="muted m0">Tạo tài khoản để mua hàng, nạp tiền và tra cứu đơn.</p>
    </header>

    <?php $oauthUiPlace = "alert"; require __DIR__ . "/partials/oauth-buttons.php"; ?>

    <form class="auth__form" data-auth-form data-action="Register"
      data-endpoint="<?php echo store_e(BASE_URL("ajaxs/client/auth.php")); ?>"
      data-redirect="<?php echo store_e(BASE_URL("")); ?>" novalidate>
      <input type="hidden" id="csrf_token" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

      <div class="field">
        <label class="field__label" for="register-username">Tên đăng nhập</label>
        <input class="input" id="register-username" name="username" type="text" autocomplete="username" required>
        <p class="field__help">Không dấu, dùng để đăng nhập.</p>
        <p class="field__error" data-error-for="username" hidden></p>
      </div>

      <div class="field">
        <label class="field__label" for="register-email">Email</label>
        <input class="input" id="register-email" name="email" type="email" autocomplete="email" required>
        <p class="field__error" data-error-for="email" hidden></p>
      </div>

      <div class="field">
        <label class="field__label" for="register-password">Mật khẩu</label>
        <input class="input" id="register-password" name="password" type="password" autocomplete="new-password"
          minlength="6" required data-password-strength-input>
        <p class="field__help" data-password-strength role="status"></p>
        <p class="field__error" data-error-for="password" hidden></p>
      </div>

      <div class="field">
        <label class="field__label" for="register-password-confirm">Nhập lại mật khẩu</label>
        <input class="input" id="register-password-confirm" name="repassword" type="password"
          autocomplete="new-password" required>
        <p class="field__error" data-error-for="repassword" hidden></p>
      </div>

      <?php if ((string) $CMSNT->site("reCAPTCHA_status") === "1") { ?>
      <div class="g-recaptcha" data-sitekey="<?php echo store_e($CMSNT->site("reCAPTCHA_site_key")); ?>"></div>
      <?php } ?>

      <button class="btn btn--primary btn--block btn--lg" id="btnRegister" type="submit">Đăng ký</button>
      <p class="auth__status" role="status" aria-live="polite" data-auth-status></p>
    </form>

    <p class="auth__switch">Bạn đã có tài khoản? <a href="<?php echo BASE_URL("client/login"); ?>">Đăng nhập</a></p>

    <?php $oauthUiPlace = "buttons"; require __DIR__ . "/partials/oauth-buttons.php"; ?>
  </div>
</section>

<?php
store_shell_close(array(
    "footer_note" => $CMSNT->site("description"),
));
?>
