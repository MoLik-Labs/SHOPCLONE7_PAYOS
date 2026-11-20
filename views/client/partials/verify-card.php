<?php
/*
 * Second-step sign-in card, shared by verify_2fa.php and verify_otp.php.
 *
 * Expects:
 *   $verifyAction  "Verify2FA" | "VerifyOTP"      endpoint action (unchanged from the legacy pages)
 *   $verifyField   "token_2fa" | "token_otp_mail" POST field that carries the bearer token
 *   $verifyToken   the token value (escaped here)
 *   $verifyTitle   page heading
 *   $verifyCopy    one sentence explaining where the code comes from
 *   $verifyLabel   label for the code input
 *   $verifyLength  expected code length (6 for TOTP, 6–8 for e-mail OTP)
 *   $verifyCaptcha whether the owner enabled reCAPTCHA
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}
?>
<section class="auth" aria-labelledby="verify-title">
  <div class="auth__card">
    <header class="auth__head">
      <p class="eyebrow">Bảo mật</p>
      <h1 id="verify-title"><?php echo store_e($verifyTitle); ?></h1>
      <p class="muted m0"><?php echo store_e($verifyCopy); ?></p>
    </header>

    <form class="auth__form" data-verify-form
      data-action="<?php echo store_e($verifyAction); ?>"
      data-endpoint="<?php echo store_e(BASE_URL("ajaxs/client/auth.php")); ?>"
      data-redirect="<?php echo store_e(BASE_URL("")); ?>" novalidate>
      <input type="hidden" name="<?php echo store_e($verifyField); ?>" value="<?php echo store_e($verifyToken); ?>">
      <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

      <div class="field">
        <label class="field__label" for="verify-code"><?php echo store_e($verifyLabel); ?></label>
        <input class="input input--code" id="verify-code" name="code" type="text"
          inputmode="numeric" autocomplete="one-time-code" autocapitalize="off" spellcheck="false"
          maxlength="<?php echo (int) $verifyLength; ?>" required autofocus>
        <p class="field__error" data-error-for="code" hidden></p>
      </div>

      <?php if ($verifyCaptcha) { ?>
      <div class="field">
        <div class="g-recaptcha" data-sitekey="<?php echo store_e($CMSNT->site("reCAPTCHA_site_key")); ?>"></div>
      </div>
      <?php } ?>

      <p class="auth__status" data-verify-status role="status" aria-live="polite"></p>

      <button class="btn btn--primary btn--block" type="submit">Xác minh</button>
    </form>

    <p class="auth__switch">
      Không phải bạn?
      <a href="<?php echo store_e(BASE_URL("client/logout")); ?>">Đăng xuất và đăng nhập lại</a>
    </p>
  </div>
</section>
