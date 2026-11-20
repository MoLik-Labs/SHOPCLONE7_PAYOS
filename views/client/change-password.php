<?php
/*
 * Change password — Orbit v3.
 *
 * Same endpoint and field names as before (`ajaxs/client/auth.php`, `ChangePasswordProfile`,
 * token/password/newpassword/renewpassword), so the server contract is untouched. A successful change
 * rotates the token and signs every session out; the page says so before the button is pressed.
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

$storeShell = array(
    "title" => __("Thay đổi mật khẩu") . " | " . $CMSNT->site("title"),
    "desc" => $CMSNT->site("description"),
    "nav" => store_nav_default($CMSNT),
    "noindex" => true,
    "body_class" => "page-account",
);
store_shell_open($storeShell);
?>
<section class="section section--tight" aria-labelledby="password-title">
  <div class="container container--narrow">
    <div class="page-head">
      <div>
        <p class="eyebrow">Tài khoản</p>
        <h1 id="password-title">Đổi mật khẩu</h1>
        <p class="muted m0">Dùng mật khẩu riêng cho shop này, không dùng lại mật khẩu email hay ngân hàng.</p>
      </div>
    </div>

<?php store_account_nav("change-password"); ?>

    <div class="panel">
      <div class="panel__head">
        <h2 class="panel__title">Mật khẩu đăng nhập</h2>
      </div>
      <div class="panel__body">
        <form class="stack" data-password-form
          data-endpoint="<?php echo store_e(BASE_URL("ajaxs/client/auth.php")); ?>"
          data-redirect="<?php echo store_e(BASE_URL("client/login")); ?>">
          <input type="hidden" name="token" value="<?php echo store_e($getUser["token"]); ?>">
          <div class="field">
            <label class="field__label" for="current-password">Mật khẩu hiện tại</label>
            <input class="input" id="current-password" name="password" type="password"
              autocomplete="current-password" required>
          </div>
          <div class="field">
            <label class="field__label" for="new-password">Mật khẩu mới</label>
            <input class="input" id="new-password" name="newpassword" type="password"
              autocomplete="new-password" minlength="5" required aria-describedby="new-password-help">
            <p class="field__help" id="new-password-help">Tối thiểu 5 ký tự, theo yêu cầu hiện tại của shop.</p>
          </div>
          <div class="field">
            <label class="field__label" for="new-password-confirm">Nhập lại mật khẩu mới</label>
            <input class="input" id="new-password-confirm" name="renewpassword" type="password"
              autocomplete="new-password" minlength="5" required>
          </div>
          <div class="row">
            <button class="btn btn--primary" type="submit">Cập nhật mật khẩu</button>
            <a class="btn btn--ghost" href="<?php echo BASE_URL("client/security"); ?>">Thiết lập bảo mật</a>
          </div>
          <p class="auth__status" role="status" aria-live="polite" data-password-status></p>
        </form>
      </div>
    </div>

    <div class="notice notice--warn">
      <strong>Đổi xong bạn sẽ được đăng xuất.</strong>
      Đây là hành vi có từ trước: mật khẩu đổi thì phiên cũ không còn dùng được, nên hãy đăng nhập lại bằng mật khẩu mới.
    </div>
  </div>
</section>
<?php
store_shell_close($storeShell);
