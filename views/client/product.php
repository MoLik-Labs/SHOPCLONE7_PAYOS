<?php
/*
 * Product page — Orbit v3.
 *
 * Two jobs: show the product honestly (real stock, real price, the admin's own description) and let a
 * signed-in buyer pay from their balance without leaving the page. The purchase endpoint is the same one
 * the legacy modal uses (`ajaxs/client/product.php`: total_payment + buyProduct), so pricing, coupons,
 * stock checks and the exactly-once wallet movement stay in one place.
 *
 * No rating, review count or "sold" figure is invented; `sold` only prints when the shop setting allows it.
 * Without JavaScript the page still shows every fact and the balance, and the buy button explains itself.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . "/../../libs/payos.php";
require_once __DIR__ . "/../../libs/gtf.php";
require_once __DIR__ . "/../common/store-shell.php";

if (empty($_GET["slug"])) {
    redirect(base_url());
}
$storeSlug = check_string($_GET["slug"]);
$storeProduct = $CMSNT->get_row(
    "SELECT * FROM `products` WHERE `slug` = '" . $storeSlug . "' AND `status` = 1 "
);
if (!$storeProduct) {
    redirect(base_url());
}

/* --- who is looking ------------------------------------------------------- */
$getUser = null;
if (isset($_COOKIE["token"])) {
    $getUser = $CMSNT->get_row(" SELECT * FROM `users` WHERE `token` = '" . check_string($_COOKIE["token"]) . "' ");
    if (!$getUser) {
        header("location: " . BASE_URL("client/logout"));
        exit;
    }
    $_SESSION["login"] = $getUser["token"];
}
if (isset($_SESSION["login"])) {
    require_once __DIR__ . "/../../models/is_user.php";
    if (!isset($getUser) || !is_array($getUser)) {
        $getUser = store_current_user();
    }
}

/* --- product facts -------------------------------------------------------- */
$storeCategory = null;
if ((int) $storeProduct["category_id"] > 0) {
    $storeCategory = $CMSNT->get_row(
        "SELECT * FROM `categories` WHERE `id` = '" . (int) $storeProduct["category_id"] . "' "
    );
}
if ((int) $storeProduct["supplier_id"] !== 0) {
    $storeStock = (int) $storeProduct["api_stock"];
} else {
    /* Same source the home list uses: the shop's own stock rows, never a supplier's cached figure. */
    $storeStockRow = $CMSNT->get_row(
        " SELECT COUNT(id) FROM `product_stock` WHERE `product_code` = '" . check_string($storeProduct["code"]) . "' "
    );
    $storeStock = $storeStockRow ? (int) $storeStockRow["COUNT(id)"] : 0;
}
$storePrice = (float) $storeProduct["price"];
$storeDiscount = (float) $storeProduct["discount"];
if ($storeDiscount < 0) {
    $storeDiscount = 0;
}
if ($storeDiscount > 100) {
    $storeDiscount = 100;
}
$storeFinal = orbit_unit_price($storeProduct);

$storeMin = isset($storeProduct["min"]) ? (int) $storeProduct["min"] : 0;
if ($storeMin < 1) {
    $storeMin = 1;
}
$storeMax = isset($storeProduct["max"]) ? (int) $storeProduct["max"] : 0;
if ($storeMax < 1) {
    $storeMax = $storeStock > 0 ? $storeStock : 1;
}
if ($storeStock > 0 && $storeMax > $storeStock) {
    $storeMax = $storeStock;
}
$storeAvailable = $storeStock > 0;
$storeCanBuy = $getUser && $storeAvailable;

$storeImages = array();
if ((string) $CMSNT->site("product_photo_display") === "1" && trim((string) $storeProduct["images"]) !== "") {
    foreach (preg_split("~\r\n|\r|\n~", trim((string) $storeProduct["images"])) as $storeImageRow) {
        if (trim($storeImageRow) !== "") {
            $storeImages[] = dirImageProduct(trim($storeImageRow));
        }
    }
}

$storeDescription = "";
if (trim((string) $storeProduct["description"]) !== "") {
    $storeDescription = base64_decode((string) $storeProduct["description"]);
}

$storeTitle = $storeProduct["name"] . " | " . $CMSNT->site("title");
$storeMetaDesc = trim(strip_tags((string) $storeProduct["short_desc"]));
if ($storeMetaDesc === "") {
    $storeMetaDesc = (string) $CMSNT->site("description");
} elseif (function_exists("mb_substr") && mb_strlen($storeMetaDesc) > 160) {
    $storeMetaDesc = mb_substr($storeMetaDesc, 0, 160, "UTF-8");
} elseif (strlen($storeMetaDesc) > 160) {
    $storeMetaDesc = substr($storeMetaDesc, 0, 160);
}
$storeShell = array(
    "title" => $storeTitle,
    "desc" => $storeMetaDesc,
    "nav" => store_nav_default($CMSNT),
    "footer_note" => $CMSNT->site("description"),
    "body_class" => "page-product",
);

store_shell_open($storeShell);
?>

<div class="container pt-4">
  <nav class="crumbs" aria-label="Đường dẫn">
    <a href="<?php echo BASE_URL("client/home"); ?>">Trang chủ</a>
    <?php if ($storeCategory) { ?>
    <span aria-hidden="true">/</span>
    <a href="<?php echo BASE_URL("category/" . $storeCategory["slug"]); ?>"><?php echo store_e($storeCategory["name"]); ?></a>
    <?php } ?>
    <span aria-hidden="true">/</span>
    <span aria-current="page"><?php echo store_e($storeProduct["name"]); ?></span>
  </nav>
</div>

<section class="product-hero" aria-labelledby="product-title">
  <div class="container product-hero__inner">
    <div class="product-gallery">
      <?php if (!empty($storeImages)) { ?>
      <div class="product-gallery__stage">
        <img id="gallery-main" src="<?php echo store_e(BASE_URL($storeImages[0])); ?>"
          alt="<?php echo store_e($storeProduct["name"]); ?>" width="800" height="600" decoding="async"
          <?php echo count($storeImages) === 1 ? "" : 'data-gallery-main'; ?>>
        <?php if ($storeDiscount > 0) { ?>
        <span class="product-gallery__badge">-<?php echo (int) $storeDiscount; ?>%</span>
        <?php } ?>
      </div>
      <?php if (count($storeImages) > 1) { ?>
      <div class="product-gallery__thumbs" data-gallery-thumbs>
        <?php foreach ($storeImages as $storeImageIndex => $storeImage) { ?>
        <button class="product-gallery__thumb<?php echo $storeImageIndex === 0 ? " is-active" : ""; ?>" type="button"
          data-gallery-src="<?php echo store_e(BASE_URL($storeImage)); ?>"
          aria-label="Ảnh <?php echo $storeImageIndex + 1; ?>">
          <img src="<?php echo store_e(BASE_URL($storeImage)); ?>" alt="" width="96" height="72" loading="lazy" decoding="async">
        </button>
        <?php } ?>
      </div>
      <?php } ?>
      <?php } else { ?>
      <div class="product-gallery__stage product-gallery__stage--mark">
        <span aria-hidden="true"><?php echo store_e(store_first_char($storeProduct["name"])); ?></span>
        <?php if ($storeDiscount > 0) { ?>
        <span class="product-gallery__badge">-<?php echo (int) $storeDiscount; ?>%</span>
        <?php } ?>
      </div>
      <?php } ?>
    </div>

    <div class="product-info">
      <?php if ($storeCategory) { ?>
      <p class="eyebrow"><?php echo store_e($storeCategory["name"]); ?></p>
      <?php } ?>
      <h1 id="product-title"><?php echo store_e($storeProduct["name"]); ?></h1>
      <?php if (trim((string) $storeProduct["short_desc"]) !== "") { ?>
      <p class="product-info__lead"><?php echo nl2br(store_e(trim((string) $storeProduct["short_desc"]))); ?></p>
      <?php } ?>

      <ul class="product-facts">
        <li class="stock<?php echo $storeAvailable ? ($storeStock <= 5 ? " stock--low" : "") : " stock--out"; ?>">
          <?php echo $storeAvailable ? "Còn " . format_cash($storeStock) : "Tạm hết hàng"; ?>
        </li>
        <?php if ((int) $storeProduct["sold"] > 0 && (string) $CMSNT->site("product_sold_display") === "1") { ?>
        <li class="faint">Đã bán <?php echo format_cash($storeProduct["sold"]); ?></li>
        <?php } ?>
        <?php if ($storeMin > 1 || $storeMax > 0) { ?>
        <li class="faint">Mua từ <?php echo format_cash($storeMin); ?> đến <?php echo format_cash($storeMax); ?></li>
        <?php } ?>
      </ul>

      <p class="product-price">
        <?php if ($storeDiscount > 0) { ?>
        <span class="price--old"><?php echo format_currency($storePrice); ?></span>
        <?php } ?>
        <span class="product-price__now"><?php echo format_currency($storeFinal); ?></span>
      </p>

      <div class="buy-panel" id="mua" data-buy
        data-endpoint="<?php echo store_e(BASE_URL("ajaxs/client/product.php")); ?>"
        data-product="<?php echo (int) $storeProduct["id"]; ?>"
        data-min="<?php echo (int) $storeMin; ?>"
        data-max="<?php echo (int) $storeMax; ?>"
        data-stock="<?php echo (int) $storeStock; ?>"
        data-token="<?php echo $getUser ? store_e($getUser["token"]) : ""; ?>"
        data-api-key="<?php echo $getUser ? store_e($getUser["api_key"]) : ""; ?>"
        data-order-url="<?php echo store_e(BASE_URL("product-order/")); ?>">
        <div class="buy-panel__head">
          <h2 class="buy-panel__title">Mua ngay</h2>
          <?php if ($getUser) { ?>
          <span class="buy-panel__balance">Số dư <strong><?php echo format_currency($getUser["money"]); ?></strong></span>
          <?php } ?>
        </div>

        <div class="buy-panel__row">
          <label class="field__label" for="amount">Số lượng</label>
          <div class="stepper">
            <button class="stepper__btn" type="button" data-step="-1" aria-label="Giảm số lượng">−</button>
            <input class="input stepper__input" id="amount" name="amount" type="number" inputmode="numeric"
              min="<?php echo (int) $storeMin; ?>" max="<?php echo (int) $storeMax; ?>"
              value="<?php echo (int) $storeMin; ?>" <?php echo $storeCanBuy ? "" : "disabled"; ?>>
            <button class="stepper__btn" type="button" data-step="1" aria-label="Tăng số lượng">+</button>
          </div>
        </div>

        <div class="buy-panel__row">
          <label class="field__label" for="coupon">Mã giảm giá <span class="faint">(nếu có)</span></label>
          <input class="input" id="coupon" name="coupon" type="text" autocomplete="off"
            placeholder="Nhập mã giảm giá" <?php echo $storeCanBuy ? "" : "disabled"; ?>>
        </div>

        <dl class="buy-summary">
          <div><dt>Tạm tính</dt><dd data-buy-money>—</dd></div>
          <div><dt>Giảm giá</dt><dd data-buy-discount>—</dd></div>
          <div class="buy-summary__total"><dt>Thanh toán</dt><dd data-buy-pay>—</dd></div>
        </dl>

        <?php if ($storeCanBuy) { ?>
        <button class="btn btn--primary btn--block btn--lg" type="button" data-buy-submit>
          Thanh toán bằng số dư
        </button>
        <?php } elseif (!$getUser) { ?>
        <a class="btn btn--primary btn--block btn--lg" href="<?php echo BASE_URL("client/login"); ?>">Đăng nhập để mua</a>
        <p class="small faint m0">Đơn hàng và nội dung sản phẩm được lưu trong tài khoản của bạn.</p>
        <?php } else { ?>
        <button class="btn btn--block btn--lg" type="button" disabled>Tạm hết hàng</button>
        <p class="small faint m0">Sản phẩm đang được bổ sung. Bạn có thể quay lại sau hoặc liên hệ shop.</p>
        <?php } ?>

        <p class="buy-panel__status" role="status" aria-live="polite" data-buy-status></p>
      </div>

      <ul class="product-assurances">
        <li>Kho và giá hiển thị theo dữ liệu của shop tại thời điểm mở trang.</li>
        <li>Thanh toán trừ trực tiếp số dư tài khoản, không lưu thông tin thẻ.</li>
        <li>Cần hỗ trợ? <a href="<?php echo BASE_URL("client/contact"); ?>">Liên hệ shop</a>.</li>
      </ul>
    </div>
  </div>
</section>

<?php if ($storeDescription !== "") { ?>
<section class="section section--tight" aria-labelledby="product-details-title">
  <div class="container container--narrow">
    <h2 id="product-details-title">Chi tiết sản phẩm</h2>
    <div class="prose product-description">
      <?php echo $storeDescription; ?>
    </div>
  </div>
</section>
<?php } ?>

<?php if ((string) $CMSNT->site("api_status") === "1") { ?>
<section class="section section--tight" aria-labelledby="product-api-title">
  <div class="container container--narrow">
    <div class="panel">
      <div class="panel__head">
        <h2 id="product-api-title" class="panel__title">Tích hợp API</h2>
        <span class="pill">GET / POST</span>
      </div>
      <div class="panel__body">
        <?php if ($getUser) { ?>
        <p class="small muted">Dùng đúng API key của tài khoản bạn. Không chia sẻ khoá này cho người khác.</p>
        <div class="copy-row">
          <label class="sr-only" for="api-key">API key</label>
          <input class="input" id="api-key" type="text" readonly value="<?php echo store_e($getUser["api_key"]); ?>">
          <button class="btn" type="button" data-copy="#api-key">Sao chép</button>
        </div>
        <div class="copy-row">
          <label class="sr-only" for="api-product">Lấy chi tiết sản phẩm</label>
          <input class="input" id="api-product" type="text" readonly
            value="<?php echo store_e(BASE_URL("api/product.php?api_key=" . $getUser["api_key"] . "&product=" . (int) $storeProduct["id"])); ?>">
          <button class="btn" type="button" data-copy="#api-product">Sao chép</button>
        </div>
        <dl class="kv">
          <dt>Mua hàng</dt><dd><code>POST <?php echo store_e(BASE_URL("api/buy_product")); ?></code></dd>
          <dt>Tham số</dt><dd><code>action=buyProduct · id=<?php echo (int) $storeProduct["id"]; ?> · amount · coupon · api_key</code></dd>
        </dl>
        <?php } else { ?>
        <p class="m0">Vui lòng <a href="<?php echo BASE_URL("client/login"); ?>">đăng nhập</a> để xem API key của bạn.</p>
        <?php } ?>
      </div>
    </div>
  </div>
</section>
<?php } ?>

<?php if ((string) $CMSNT->site("affiliate_status") === "1") { ?>
<section class="section section--tight" aria-labelledby="product-aff-title">
  <div class="container container--narrow">
    <div class="panel">
      <div class="panel__head">
        <h2 id="product-aff-title" class="panel__title">Tiếp thị liên kết</h2>
      </div>
      <div class="panel__body">
        <?php if ($getUser) { ?>
        <p class="m0 muted small">Chia sẻ liên kết dưới đây. Khi người khác mua qua liên kết của bạn, hoa hồng được ghi nhận theo tỷ lệ trong tài khoản.</p>
        <div class="copy-row">
          <label class="sr-only" for="aff-link">Liên kết giới thiệu</label>
          <input class="input" id="aff-link" type="text" readonly
            value="<?php echo store_e(base_url("product/" . $storeProduct["slug"]) . "&aff=" . (int) $getUser["id"]); ?>">
          <button class="btn" type="button" data-copy="#aff-link">Sao chép</button>
        </div>
        <p class="small m0"><a href="<?php echo BASE_URL("?action=affiliates"); ?>">Xem chương trình tiếp thị</a></p>
        <?php } else { ?>
        <p class="m0">Vui lòng <a href="<?php echo BASE_URL("client/login"); ?>">đăng nhập</a> để lấy liên kết giới thiệu.</p>
        <?php } ?>
      </div>
    </div>
  </div>
</section>
<?php } ?>

<div class="buy-bar" data-buy-bar>
  <div class="buy-bar__price">
    <span class="faint xs">Giá hiện tại</span>
    <strong><?php echo format_currency($storeFinal); ?></strong>
  </div>
  <a class="btn btn--primary" href="#mua"><?php echo $storeCanBuy ? "Mua ngay" : ($storeAvailable ? "Đăng nhập" : "Tạm hết"); ?></a>
</div>

<?php
store_shell_close(array(
    "footer_note" => $CMSNT->site("description"),
));
?>
