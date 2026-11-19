<?php
/*
 * API integration guide — Orbit v3.
 *
 * The legacy page loaded ClipboardJS from cdnjs and printed the session token into a button's
 * onclick handler just to rotate the API key. Both are gone: copying is handled by the shell's
 * `[data-copy]` helper, and rotation posts `changeAPIKey` with the CSRF token, resolved from the
 * session on the server. Key format is unchanged (32 lower-case hex), so existing API clients keep
 * working when a customer rotates.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . "/../common/store-shell.php";
require_once __DIR__ . "/../common/account-nav.php";
require_once __DIR__ . "/../../models/is_user.php";
if ((string) $CMSNT->site("api_status") !== "1") {
    redirect(BASE_URL("client/home"));
}
if (!isset($getUser) || !is_array($getUser)) {
    $getUser = store_current_user();
}

$storeApiKey = (string) $getUser["api_key"];
$storeApiReady = $storeApiKey !== "";
$storeApiEndpoints = array(
    array(
        "method" => "GET",
        "label" => __("Thông tin tài khoản"),
        "url" => BASE_URL("api/profile.php?api_key=" . urlencode($storeApiKey)),
        "id" => "api-profile",
    ),
    array(
        "method" => "GET",
        "label" => __("Danh sách chuyên mục và sản phẩm"),
        "url" => BASE_URL("api/products.php?api_key=" . urlencode($storeApiKey)),
        "id" => "api-products",
    ),
    array(
        "method" => "GET",
        "label" => __("Chi tiết một sản phẩm"),
        "url" => BASE_URL("api/product.php?api_key=" . urlencode($storeApiKey) . "&product=3"),
        "id" => "api-product",
    ),
    array(
        "method" => "GET",
        "label" => __("Mã đơn đã tạo (trạng thái và nội dung)"),
        "url" => BASE_URL("api/api.php?apikey=" . urlencode($storeApiKey) . "&action=get-order-detail&order_id=ORD123"),
        "id" => "api-order-detail",
    ),
);

$storeShell = array(
    "title" => __("Tài liệu tích hợp API") . " | " . $CMSNT->site("title"),
    "desc" => __("Khoá API và các điểm cuối để tích hợp shop vào hệ thống của bạn."),
    "nav" => store_nav_default($CMSNT),
    "body_class" => "page-account",
);
store_shell_open($storeShell);
?>
<section class="section section--tight" aria-labelledby="api-title">
  <div class="container">
    <div class="page-head">
      <div>
        <p class="eyebrow">Dành cho đối tác</p>
        <h1 id="api-title">Tài liệu tích hợp API</h1>
        <p class="muted m0">Dùng khoá API để lấy sản phẩm và tạo đơn từ hệ thống của bạn. Khoá này thay cho mật khẩu — không chia sẻ và không dán vào mã nguồn công khai.</p>
      </div>
    </div>

    <div class="panel">
      <div class="panel__head">
        <h2 class="panel__title">Khoá API của bạn</h2>
      </div>
      <div class="panel__body">
<?php if (!$storeApiReady) { ?>
        <p class="muted m0"><?php echo __("Tài khoản chưa có khoá API. Bấm nút bên dưới để tạo."); ?></p>
<?php } else { ?>
        <div class="copy-row">
          <input class="input mono" id="api-key" type="text" readonly value="<?php echo store_e($storeApiKey); ?>"
            aria-label="<?php echo store_e(__("Khoá API")); ?>">
          <button class="btn btn--ghost" type="button" data-copy="#api-key">Sao chép</button>
        </div>
        <p class="field__hint">Nếu khoá bị lộ, hãy đổi ngay: khoá cũ ngừng hoạt động tức thì và mọi tích hợp phải cập nhật khoá mới.</p>
<?php } ?>
        <button class="btn btn--primary" type="button" data-api-key-rotate
          data-endpoint="<?php echo store_e(BASE_URL("ajaxs/client/auth.php")); ?>">
          <?php echo $storeApiReady ? __("Đổi khoá API") : __("Tạo khoá API"); ?>
        </button>
      </div>
    </div>

    <div class="panel mt-4">
      <div class="panel__head">
        <h2 class="panel__title">Đọc dữ liệu</h2>
      </div>
      <div class="panel__body">
        <p class="muted">Mọi yêu cầu gửi khoá qua tham số <code class="mono">api_key</code>. Kết quả trả về JSON.</p>
<?php foreach ($storeApiEndpoints as $storeEndpoint) { ?>
        <div class="field">
          <label class="field__label" for="<?php echo store_e($storeEndpoint["id"]); ?>">
            <span class="pill mono"><?php echo store_e($storeEndpoint["method"]); ?></span>
            <?php echo store_e($storeEndpoint["label"]); ?>
          </label>
          <div class="copy-row">
            <input class="input mono" id="<?php echo store_e($storeEndpoint["id"]); ?>" type="text" readonly
              value="<?php echo store_e($storeEndpoint["url"]); ?>">
            <button class="btn btn--ghost" type="button" data-copy="#<?php echo store_e($storeEndpoint["id"]); ?>">Sao chép</button>
          </div>
        </div>
<?php } ?>
      </div>
    </div>

    <div class="panel mt-4">
      <div class="panel__head">
        <h2 class="panel__title">Tạo đơn hàng</h2>
      </div>
      <div class="panel__body">
        <div class="field">
          <p class="field__label">
            <span class="pill mono">GET</span>
            <span class="mono mono--wrap"><?php echo store_e(BASE_URL("api/api.php?apikey=" . urlencode($storeApiKey) . "&action=create-order&service_id=3&amount=1")); ?></span>
          </p>
          <div class="copy-row">
            <label class="sr-only" for="api-buy"><?php echo store_e(__("Địa chỉ tạo đơn hàng")); ?></label>
            <input class="input mono" id="api-buy" type="text" readonly
              value="<?php echo store_e(BASE_URL("api/api.php?apikey=" . urlencode($storeApiKey) . "&action=create-order&service_id=3&amount=1")); ?>">
            <button class="btn btn--ghost" type="button" data-copy="#api-buy">Sao chép</button>
          </div>
        </div>
        <dl class="kv">
          <dt><span class="mono">apikey</span></dt><dd>Khoá API ở trên (<span class="mono">api_key</span> cũng được chấp nhận)</dd>
          <dt><span class="mono">action</span></dt><dd><span class="mono">create-order</span></dd>
          <dt><span class="mono">service_id</span></dt><dd>ID sản phẩm cần mua (lấy từ danh sách ở trên)</dd>
          <dt><span class="mono">amount</span></dt><dd>Số lượng cần mua, trong khoảng min–max của sản phẩm</dd>
          <dt><span class="mono">coupon</span></dt><dd>Mã giảm giá, bỏ trống nếu không có</dd>
        </dl>
        <p class="field__hint">Yêu cầu tạo đơn làm thay đổi số dư, nên đừng đặt nó trong một liên kết mở sẵn hay gọi lại tự động: mỗi lần gọi thành công là một đơn thật. Hãy lưu <span class="mono">order_id</span> và tra cứu bằng <span class="mono">get-order-detail</span> khi cần.</p>
        <p class="field__label mt-4">Phản hồi thành công</p>
        <pre class="code-sample mono">{
    "code": 200,
    "message": "Tạo đơn hàng thành công!",
    "order_id": "JF465f728224ce11",
    "data": "1000040304952|GUTJXYIFPWLHCNDOMBRKVAQESZ\n1000087676467|IVMRLABECWTQYUXHPOFNJDSZGK"
}</pre>
        <p class="field__hint">Giữ <span class="mono">order_id</span> để tra cứu đơn. Hệ thống không gửi lại tài khoản đã bán, nên hãy lưu phản hồi ngay khi nhận. Nếu nhà cung cấp chưa trả đơn trong thời hạn, đơn được hoàn tiền và phản hồi là <span class="mono">"code": 201</span>.</p>
      </div>
    </div>
  </div>
</section>
<?php store_shell_close(); ?>
