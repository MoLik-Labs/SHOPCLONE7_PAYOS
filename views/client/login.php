<?php
/*
 * Sign-in — Orbit v3.
 *
 * The form posts to the same endpoint with the same field names the legacy page used, so rate limits,
 * 2FA hand-off and the CSRF check behave exactly as before. The difference is the surface: labelled
 * fields, one primary action, an inline status line instead of a modal alert, and no jQuery.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . "/../common/store-shell.php";

$storeHead = "";
if ((string) $CMSNT->site("reCAPTCHA_status") === "1") {
    /* Google's widget is the only third-party request this page can make, and only when the owner
       switches it on; the rest of the site stays self-hosted. */
    $storeHead = '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
}
$storeShell = array(
    "title" => __("Đăng nhập") . " | " . $CMSNT->site("title"),
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
      <p class="eyebrow">Tài khoản</p>
      <h1 id="auth-title">Đăng nhập</h1>
      <p class="muted m0">Dùng tài khoản của bạn để xem đơn hàng, số dư và nạp tiền.</p>
    </header>

    <?php $oauthUiPlace = "alert"; require __DIR__ . "/partials/oauth-buttons.php"; ?>

    <form class="auth__form" data-auth-form data-action="Login"
      data-endpoint="<?php echo store_e(BASE_URL("ajaxs/client/auth.php")); ?>"
      data-redirect="<?php echo store_e(BASE_URL("")); ?>" novalidate>
      <input type="hidden" id="csrf_token" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

      <div class="field">
        <label class="field__label" for="page-login-username">Tên đăng nhập</label>
        <input class="input" id="page-login-username" name="username" type="text" autocomplete="username"
          required value="<?php echo $CMSNT->site("status_demo") == 1 ? "admin" : ""; ?>">
        <p class="field__error" data-error-for="username" hidden></p>
      </div>

      <div class="field">
        <div class="field__head">
          <label class="field__label" for="page-login-password">Mật khẩu</label>
          <a class="field__hint" href="<?php echo BASE_URL("client/forgot-password"); ?>">Quên mật khẩu?</a>
        </div>
        <input class="input" id="page-login-password" name="password" type="password" autocomplete="current-password"
          required value="<?php echo $CMSNT->site("status_demo") == 1 ? "admin" : ""; ?>">
        <p class="field__error" data-error-for="password" hidden></p>
      </div>

      <?php if ((string) $CMSNT->site("reCAPTCHA_status") === "1") { ?>
      <div class="g-recaptcha" data-sitekey="<?php echo store_e($CMSNT->site("reCAPTCHA_site_key")); ?>"></div>
      <?php } ?>

      <button class="btn btn--primary btn--block btn--lg" id="btnLoginPage" type="submit">Đăng nhập</button>
      <p class="auth__status" role="status" aria-live="polite" data-auth-status></p>
    </form>

    <p class="auth__switch">Bạn chưa có tài khoản? <a href="<?php echo BASE_URL("client/register"); ?>">Đăng ký ngay</a></p>

    <?php $oauthUiPlace = "buttons"; require __DIR__ . "/partials/oauth-buttons.php"; ?>
  </div>
</section>

<?php
store_shell_close(array(
    "footer_note" => $CMSNT->site("description"),
));
?>
