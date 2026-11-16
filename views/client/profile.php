<?php
/*
 * Account overview — Orbit v3.
 *
 * One page that answers "where is my money, my orders and my profile". Numbers come from the user row;
 * the edit form posts to the same endpoint as before (`ChangeProfile`) and keeps the same field names.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . "/../common/store-shell.php";
require_once __DIR__ . "/../../models/is_user.php";
if (!isset($getUser) || !is_array($getUser)) {
    $getUser = store_current_user();
}

$storeMethods = store_payment_methods($CMSNT);
$storeRecharge = !empty($storeMethods) ? $storeMethods[0]["href"] : BASE_URL("client/login");
$storeSpent = (float) $getUser["total_money"] - (float) $getUser["money"];
if ($storeSpent < 0) {
    $storeSpent = 0;
}
$storeDisplayName = trim((string) $getUser["fullname"]) !== "" ? $getUser["fullname"] : $getUser["username"];

$storeShell = array(
    "title" => __("Tài khoản") . " | " . $CMSNT->site("title"),
    "desc" => $CMSNT->site("description"),
    "nav" => store_nav_default($CMSNT, $storeMethods),
    "body_class" => "page-account",
);
store_shell_open($storeShell);
?>

<section class="section section--tight" aria-labelledby="account-title">
  <div class="container">
    <div class="page-head">
      <div>
        <p class="eyebrow">Tài khoản</p>
        <h1 id="account-title">Xin chào, <?php echo store_e($storeDisplayName); ?></h1>
        <p class="muted m0">Số dư, đơn hàng và thông tin cá nhân của bạn ở một nơi.</p>
      </div>
      <a class="btn" href="<?php echo store_e($storeRecharge); ?>">Nạp tiền</a>
    </div>

    <div class="stats">
      <div class="stat stat--accent">
        <p class="stat__label">Số dư hiện tại</p>
        <p class="stat__value"><?php echo format_currency($getUser["money"]); ?></p>
        <p class="stat__foot">Dùng để thanh toán đơn hàng</p>
      </div>
      <div class="stat">
        <p class="stat__label">Tổng tiền đã nạp</p>
        <p class="stat__value stat__value--sm"><?php echo format_currency($getUser["total_money"]); ?></p>
        <p class="stat__foot">Cộng dồn từ trước đến nay</p>
      </div>
      <div class="stat">
        <p class="stat__label">Đã sử dụng</p>
        <p class="stat__value stat__value--sm"><?php echo format_currency($storeSpent); ?></p>
        <p class="stat__foot">Chênh lệch giữa đã nạp và số dư</p>
      </div>
    </div>

    <nav class="account-nav" aria-label="Khu vực tài khoản">
      <a href="<?php echo BASE_URL("client/purchase-history"); ?>">Lịch sử mua hàng</a>
      <a href="<?php echo BASE_URL("product-orders"); ?>">Đơn hàng</a>
      <a href="<?php echo BASE_URL("client/transactions"); ?>">Biến động số dư</a>
      <a href="<?php echo BASE_URL("client/security"); ?>">Bảo mật</a>
      <a href="<?php echo BASE_URL("client/change-password"); ?>">Đổi mật khẩu</a>
      <a href="<?php echo BASE_URL("client/favorites"); ?>">Yêu thích</a>
    </nav>

    <div class="account-grid">
      <section class="panel" aria-labelledby="profile-title">
        <div class="panel__head">
          <h2 id="profile-title" class="panel__title">Thông tin liên hệ</h2>
        </div>
        <div class="panel__body">
          <form class="stack" data-profile-form
            data-endpoint="<?php echo store_e(BASE_URL("ajaxs/client/auth.php")); ?>">
            <input type="hidden" name="token" value="<?php echo store_e($getUser["token"]); ?>">
            <div class="field">
              <label class="field__label" for="fullname">Họ và tên</label>
              <input class="input" id="fullname" name="fullname" type="text" autocomplete="name"
                value="<?php echo store_e($getUser["fullname"]); ?>">
            </div>
            <div class="field">
              <label class="field__label" for="phone">Số điện thoại</label>
              <input class="input" id="phone" name="phone" type="tel" autocomplete="tel"
                value="<?php echo store_e($getUser["phone"]); ?>">
            </div>
            <div class="field">
              <label class="field__label" for="telegram_chat_id">Telegram Chat ID</label>
              <input class="input" id="telegram_chat_id" name="telegram_chat_id" type="text"
                value="<?php echo store_e($getUser["telegram_chat_id"]); ?>">
              <p class="field__help">Chỉ dùng khi shop gửi thông báo qua Telegram.</p>
            </div>
            <div class="row">
              <button class="btn btn--primary" type="submit">Lưu thay đổi</button>
              <span class="small faint">Email liên hệ shop để đổi.</span>
            </div>
            <p class="auth__status" role="status" aria-live="polite" data-profile-status></p>
          </form>
        </div>
      </section>

      <section class="panel" aria-labelledby="facts-title">
        <div class="panel__head">
          <h2 id="facts-title" class="panel__title">Tài khoản</h2>
        </div>
        <div class="panel__body">
          <dl class="kv">
            <dt>Tên đăng nhập</dt><dd><?php echo store_e($getUser["username"]); ?></dd>
            <dt>Email</dt><dd><?php echo store_e($getUser["email"]); ?></dd>
            <dt>Địa chỉ IP</dt><dd><?php echo store_e($getUser["ip"]); ?></dd>
            <dt>Thiết bị</dt><dd class="truncate"><?php echo store_e($getUser["device"]); ?></dd>
            <dt>Đăng ký lúc</dt><dd><?php echo store_e($getUser["create_date"]); ?></dd>
            <dt>Đăng nhập gần nhất</dt><dd><?php echo store_e($getUser["update_date"]); ?></dd>
          </dl>
        </div>
      </section>
    </div>
  </div>
</section>

<?php
store_shell_close(array(
    "footer_note" => $CMSNT->site("description"),
));
?>
