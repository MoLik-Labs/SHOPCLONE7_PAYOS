<?php
/*
 * Reset password — Orbit v3.
 *
 * The link carries `?token=…` (the same value `ForgotPassword` stored in `token_forgot_password`).
 * A missing or unknown token redirects away instead of printing an empty form; the server re-checks
 * the token and its 15-minute window when the form is submitted.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . "/../common/store-shell.php";

$storeToken = isset($_GET["token"]) && is_string($_GET["token"]) ? trim($_GET["token"]) : "";
if ($storeToken === "" || !preg_match('/^[A-Za-z0-9]{16,128}$/', $storeToken)) {
    redirect(BASE_URL("client/forgot-password"));
}
$storeUser = $CMSNT->get_row(
    "SELECT `id`, `username`, `token_forgot_password` FROM `users`"
    . " WHERE `token_forgot_password` = '" . addslashes($storeToken) . "' AND `token_forgot_password` IS NOT NULL"
);
if (!$storeUser) {
    redirect(BASE_URL("client/forgot-password"));
}

$storeShell = array(
    "title" => __("Đặt lại mật khẩu") . " | " . $CMSNT->site("title"),
    "desc" => $CMSNT->site("description"),
    "noindex" => true,
    "body_class" => "page-auth",
);
store_shell_open($storeShell);
?>
<section class="auth" aria-labelledby="reset-title">
  <div class="container container--narrow">
    <div class="auth__card">
      <p class="eyebrow">Tài khoản</p>
      <h1 id="reset-title" class="auth__head">Đặt lại mật khẩu</h1>
      <p class="muted">Tạo mật khẩu mới cho <strong><?php echo store_e($storeUser["username"]); ?></strong>. Sau khi lưu, mọi thiết bị phải đăng nhập lại.</p>

      <form class="stack auth__form" data-reset-form
        data-endpoint="<?php echo store_e(BASE_URL("ajaxs/client/auth.php")); ?>"
        data-redirect="<?php echo store_e(BASE_URL("client/login")); ?>">
        <input type="hidden" name="token" value="<?php echo store_e($storeToken); ?>">
        <div class="field">
          <label class="field__label" for="reset-password">Mật khẩu mới</label>
          <input class="input" id="reset-password" name="newpassword" type="password"
            autocomplete="new-password" minlength="5" required aria-describedby="reset-help">
          <p class="field__help" id="reset-help">Tối thiểu 5 ký tự, theo yêu cầu hiện tại của shop.</p>
        </div>
        <div class="field">
          <label class="field__label" for="reset-confirm">Nhập lại mật khẩu mới</label>
          <input class="input" id="reset-confirm" name="renewpassword" type="password"
            autocomplete="new-password" minlength="5" required>
        </div>
        <button class="btn btn--primary btn--block" type="submit">Lưu mật khẩu mới</button>
        <p class="auth__status" role="status" aria-live="polite" data-reset-status></p>
      </form>

      <p class="auth__switch muted"><a href="<?php echo BASE_URL("client/login"); ?>">Quay lại đăng nhập</a></p>
    </div>
  </div>
</section>
<?php
store_shell_close($storeShell);
