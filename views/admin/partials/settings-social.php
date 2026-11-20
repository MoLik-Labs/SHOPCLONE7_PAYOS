<?php
/*
 * The "Đăng nhập mạng xã hội" tab of admin Settings.
 *
 * Injected by views/admin/settings.php; expects:
 *   $CMSNT, $oauthConfig (oauth_config()), $oauthRaw (oauth_raw_settings()), $oauthTableOk (bool),
 *   $oauthCallbackUrl (string), $oauthTest (array|null flash result)
 *
 * The anon key is never printed back: the field starts empty and an empty submit keeps the stored
 * value. That is why an admin can hand the browser to a colleague without leaking the configuration
 * in a form value. (The key is publishable by design, but printing it still buys nothing.)
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

$oauthEscape = function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
};
$oauthUrl = isset($oauthRaw["oauth_supabase_url"]) ? $oauthRaw["oauth_supabase_url"] : "";
$oauthKeySaved = isset($oauthRaw["oauth_supabase_anon_key"]) ? $oauthRaw["oauth_supabase_anon_key"] : "";
$oauthMasked = oauth_mask_secret($oauthKeySaved);
$oauthOn = function ($name) use ($oauthRaw) {
    return isset($oauthRaw[$name]) && $oauthRaw[$name] === "1";
};
?>
<div class="tab-pane text-muted" id="dang-nhap-mang-xa-hoi" role="tabpanel">
  <h4>Đăng nhập bằng Google / GitHub</h4>
  <p class="text-muted">
    Khách đăng nhập bằng tài khoản Google hoặc GitHub thông qua <b>Supabase Auth</b>. Shop chỉ giữ
    Project URL và khóa công khai (anon/publishable key); <b>client secret của Google/GitHub nằm trong
    Supabase</b>, không lưu ở đây và không bao giờ gửi xuống trình duyệt.
  </p>

  <?php if (!$oauthTableOk) { ?>
    <div class="alert alert-danger" role="alert">
      <b>Chưa có bảng <code>oauth_identities</code>.</b> Tính năng sẽ từ chối đăng nhập cho tới khi chạy
      migration trên máy chủ:
      <pre class="mb-0 mt-2">php scripts/migrate-social-login.php</pre>
    </div>
  <?php } ?>

  <?php if (is_array($oauthTest)) { ?>
    <div class="alert <?php echo !empty($oauthTest["ok"]) ? "alert-success" : "alert-warning"; ?>" role="alert">
      <?php if (!empty($oauthTest["ok"])) { ?>
        <b>Kết nối Supabase thành công.</b>
        Google: <?php echo !empty($oauthTest["providers"]["google"]) ? "đang bật" : "đang tắt"; ?> ·
        GitHub: <?php echo !empty($oauthTest["providers"]["github"]) ? "đang bật" : "đang tắt"; ?>.
        <?php if ((!$oauthOn("oauth_google") && !empty($oauthTest["providers"]["google"]))
            || (!$oauthOn("oauth_github") && !empty($oauthTest["providers"]["github"]))) { ?>
          Nhà cung cấp đang bật bên Supabase nhưng chưa bật ở đây.
        <?php } ?>
        <?php if (($oauthOn("oauth_google") && empty($oauthTest["providers"]["google"]))
            || ($oauthOn("oauth_github") && empty($oauthTest["providers"]["github"]))) { ?>
          <br><b>Lưu ý:</b> có nhà cung cấp đang bật ở đây nhưng chưa bật trong Supabase — nút đăng nhập
          sẽ báo lỗi. Hãy bật provider trong Supabase Dashboard → Authentication → Providers.
        <?php } ?>
      <?php } else { ?>
        <b>Không kiểm tra được kết nối.</b>
        <?php
        $oauthTestMessage = isset($oauthTest["message"]) ? (string) $oauthTest["message"] : "server";
        echo $oauthTestMessage === "config"
            ? "Hãy nhập Project URL và anon key trước."
            : "Kiểm tra lại Project URL, anon key và kết nối ra Internet của máy chủ.";
        ?>
      <?php } ?>
    </div>
  <?php } ?>

  <form action="" method="POST">
    <input type="hidden" name="csrf_token" value="<?php echo $oauthEscape(generate_csrf_token()); ?>">
    <div class="row push mb-3">
      <div class="col-md-6">
        <table class="table table-bordered table-striped table-hover">
          <tbody>
            <tr>
              <td>Bật đăng nhập mạng xã hội</td>
              <td>
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" name="oauth_enabled" value="1"
                    id="oauth_enabled" <?php echo $oauthOn("oauth_enabled") ? "checked" : ""; ?>>
                  <label class="form-check-label" for="oauth_enabled">ON/OFF</label>
                </div>
              </td>
            </tr>
            <tr>
              <td>Google</td>
              <td>
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" name="oauth_google" value="1"
                    id="oauth_google" <?php echo $oauthOn("oauth_google") ? "checked" : ""; ?>>
                  <label class="form-check-label" for="oauth_google">ON/OFF</label>
                </div>
              </td>
            </tr>
            <tr>
              <td>GitHub</td>
              <td>
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" name="oauth_github" value="1"
                    id="oauth_github" <?php echo $oauthOn("oauth_github") ? "checked" : ""; ?>>
                  <label class="form-check-label" for="oauth_github">ON/OFF</label>
                </div>
              </td>
            </tr>
            <tr>
              <td>Tự tạo tài khoản khách mới</td>
              <td>
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" name="oauth_auto_create" value="1"
                    id="oauth_auto_create" <?php echo $oauthOn("oauth_auto_create") ? "checked" : ""; ?>>
                  <label class="form-check-label" for="oauth_auto_create">ON/OFF</label>
                </div>
                <small>OFF: chỉ người đã có tài khoản (đã liên kết) mới đăng nhập được.</small>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="col-md-6">
        <table class="table table-bordered table-striped table-hover">
          <tbody>
            <tr>
              <td>Supabase Project URL</td>
              <td>
                <input type="text" class="form-control" name="oauth_supabase_url"
                  inputmode="url" autocomplete="off" spellcheck="false"
                  placeholder="https://abcdefgh.supabase.co"
                  value="<?php echo $oauthEscape($oauthUrl); ?>">
                <small>Chỉ nhận <code>https://</code>, không có dấu <code>/</code> ở cuối.</small>
              </td>
            </tr>
            <tr>
              <td>Anon / publishable key</td>
              <td>
                <input type="text" class="form-control" name="oauth_supabase_anon_key"
                  autocomplete="off" spellcheck="false" placeholder="<?php
                    echo $oauthMasked !== "" ? "Đã lưu " . $oauthEscape($oauthMasked) . " — để trống nếu không đổi"
                        : "sb_publishable_… hoặc anon key";
                  ?>">
                <small>Khóa này công khai theo thiết kế của Supabase; <b>service_role key thì tuyệt đối không
                    dán vào đây</b>.</small>
              </td>
            </tr>
            <tr>
              <td>Liên kết theo email</td>
              <td>
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" name="oauth_link_by_email" value="1"
                    id="oauth_link_by_email" <?php echo $oauthOn("oauth_link_by_email") ? "checked" : ""; ?>>
                  <label class="form-check-label" for="oauth_link_by_email">ON/OFF</label>
                </div>
                <small>
                  Cho phép gắn Google/GitHub vào tài khoản có <b>cùng email</b>, chỉ khi nhà cung cấp báo
                  email đã xác minh. Bật khi bạn tin tưởng email khách đã đăng ký. Tài khoản admin,
                  tài khoản bị khóa và tài khoản bật 2FA/OTP luôn bị từ chối.
                </small>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="col-md-12">
        <table class="table table-bordered table-striped table-hover">
          <tbody>
            <tr>
              <td style="width:260px">Redirect URL cần khai báo trong Supabase</td>
              <td>
                <div class="input-group">
                  <input type="text" class="form-control" id="oauth-callback-url" readonly
                    value="<?php echo $oauthEscape($oauthCallbackUrl); ?>">
                  <button class="btn btn-outline-secondary" type="button" id="oauth-copy-callback">
                    <i class="fa-solid fa-copy me-1"></i>Chép
                  </button>
                </div>
                <small>Supabase Dashboard → Authentication → URL Configuration → Redirect URLs → thêm đúng
                  URL này (không thêm <code>?</code> hay tham số).</small>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <button type="submit" name="SaveSocialSettings" class="btn btn-primary w-100 mb-2">
      <i class="fa fa-fw fa-save me-1"></i> Lưu cấu hình đăng nhập mạng xã hội
    </button>
    <button type="submit" name="TestSocialSettings" class="btn btn-outline-primary w-100 mb-3">
      <i class="fa-solid fa-plug-circle-check me-1"></i> Kiểm tra kết nối (không lưu)
    </button>
  </form>

  <h5 class="mt-4">Các bước bật Google / GitHub</h5>
  <ol class="mb-3">
    <li>Tạo project tại <a href="https://supabase.com/dashboard" target="_blank" rel="noopener nofollow">supabase.com</a>.</li>
    <li>Authentication → Providers: bật <b>Google</b> và/hoặc <b>GitHub</b>, dán Client ID / Client Secret
      lấy từ Google Cloud Console hoặc GitHub Developer Settings (các secret này chỉ nằm trong Supabase).</li>
    <li>Authentication → URL Configuration: thêm <b>Redirect URL</b> ở trên; đặt Site URL đúng domain shop.</li>
    <li>Project Settings → API: chép <b>Project URL</b> và <b>anon/publishable key</b> vào form này, bật
      công tắc tổng và từng nhà cung cấp, rồi bấm <b>Kiểm tra kết nối</b> trước khi Lưu.</li>
    <li>Mở trang đăng nhập ở chế độ ẩn danh, thử từng nút. Nếu lỗi, xem mã lỗi hiển thị trên trang đăng nhập.</li>
  </ol>
  <p class="text-muted mb-0">
    Bảo mật: luồng dùng PKCE, mã đăng nhập được đổi ở server, không có token nào do trình duyệt gửi lên,
    không theo redirect, chỉ <code>https</code>. Chi tiết và các trường hợp bị từ chối:
    <code>docs/frontend/11-social-login-performance-security.md</code>.
  </p>
</div>
<script>
(function () {
  var button = document.getElementById('oauth-copy-callback');
  var field = document.getElementById('oauth-callback-url');
  if (!button || !field) return;
  button.addEventListener('click', function () {
    field.select();
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(field.value);
    } else {
      document.execCommand('copy');
    }
    button.innerHTML = '<i class="fa-solid fa-check me-1"></i>Đã chép';
  });
})();
</script>
