<?php
/*
 * Forgot password — Orbit v3.
 *
 * Posts the same `ForgotPassword` action to `ajaxs/client/auth.php`. The server decides whether SMTP
 * is configured and always answers with one sentence; this page does not reveal whether an e-mail
 * exists in the database beyond what the server already said before the redesign.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . "/../common/store-shell.php";

$storeMethods = store_payment_methods($CMSNT);
$storeShell = array(
    "title" => __("Quên mật khẩu") . " | " . $CMSNT->site("title"),
    "desc" => $CMSNT->site("description"),
    "nav" => store_nav_default($CMSNT, $storeMethods),
    "body_class" => "page-auth",
);
store_shell_open($storeShell);
?>
<section class="auth" aria-labelledby="forgot-title">
  <div class="container container--narrow">
    <div class="auth__card">
      <p class="eyebrow">Tài khoản</p>
      <h1 id="forgot-title" class="auth__head">Quên mật khẩu</h1>
      <p class="muted">Nhập email đã đăng ký. Shop gửi một liên kết đặt lại mật khẩu, liên kết hết hạn sau 15 phút.</p>

      <form class="stack auth__form" data-forgot-form
        data-endpoint="<?php echo store_e(BASE_URL("ajaxs/client/auth.php")); ?>">
        <div class="field">
          <label class="field__label" for="forgot-email">Email</label>
          <input class="input" id="forgot-email" name="email" type="email" autocomplete="email"
            inputmode="email" required>
        </div>
        <button class="btn btn--primary btn--block" type="submit">Gửi liên kết</button>
        <p class="auth__status" role="status" aria-live="polite" data-forgot-status></p>
      </form>

      <p class="auth__switch muted">
        Nhớ mật khẩu rồi? <a href="<?php echo BASE_URL("client/login"); ?>">Đăng nhập</a>.
        Chưa có tài khoản? <a href="<?php echo BASE_URL("client/register"); ?>">Đăng ký</a>.
      </p>
    </div>

    <p class="muted small center">Không nhận được mail? Kiểm tra thư mục spam, hoặc <a href="<?php echo BASE_URL("client/contact"); ?>">liên hệ shop</a>.</p>
  </div>
</section>
<?php
store_shell_close($storeShell);
