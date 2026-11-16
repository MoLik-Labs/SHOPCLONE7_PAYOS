<?php
/*
 * Storefront home — rebuilt on the Orbit v3 design system.
 *
 * Everything below is real data: categories, products, stock, prices, switched-on payment methods and
 * the shop's own contact details. It never prints a rating, a review count or a "trusted by" line the
 * database does not hold. When the catalogue is empty the page says so and offers the support link.
 *
 * Search/filter/sort/pagination are plain GET links and forms, so they work with JavaScript off.
 * The purchase flow continues on the product page, which owns the amount/stock checks.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . "/../../libs/payos.php";
require_once __DIR__ . "/../../libs/gtf.php";
require_once __DIR__ . "/../common/store-shell.php";

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
}

/* --- request (all scalars are bounded before they reach SQL) -------------- */
$storePage = isset($_GET["page"]) ? (int) $_GET["page"] : 1;
$storePage = max(1, min(1000, $storePage));

$storeKeyword = isset($_GET["keyword"]) ? trim((string) $_GET["keyword"]) : "";
if (function_exists("mb_substr") && mb_strlen($storeKeyword) > 80) {
    $storeKeyword = mb_substr($storeKeyword, 0, 80);
} elseif (strlen($storeKeyword) > 80) {
    $storeKeyword = substr($storeKeyword, 0, 80);
}

$storeSlug = isset($_GET["slug"]) ? trim((string) $_GET["slug"]) : "";
$storeCategoryId = isset($_GET["category"]) ? (int) $_GET["category"] : 0;

$storeSort = isset($_GET["sort"]) ? (string) $_GET["sort"] : "";
$storeSorts = array(
    "" => "Mặc định",
    "price-asc" => "Giá thấp đến cao",
    "price-desc" => "Giá cao đến thấp",
    "sold" => "Bán chạy",
    "name" => "Tên A–Z",
);
if (!array_key_exists($storeSort, $storeSorts)) {
    $storeSort = "";
}

/* --- the shop's own limits ------------------------------------------------ */
$storeLimit = (int) $CMSNT->site("max_show_product_home");
if ($storeLimit < 6 || $storeLimit > 60) {
    $storeLimit = 12;
}

$storeCategory = null;
if ($storeSlug !== "") {
    $storeCategory = $CMSNT->get_row(
        "SELECT * FROM `categories` WHERE `slug` = '" . check_string($storeSlug) . "' AND `status` = 1 LIMIT 1"
    );
    if ($storeCategory) {
        $storeCategoryId = (int) $storeCategory["id"];
    } else {
        $storeSlug = "";
    }
}

$storeWhere = " `status` = 1 ";
if ($storeCategoryId > 0) {
    $storeWhere .= " AND `category_id` = " . $storeCategoryId . " ";
}
if ($storeKeyword !== "") {
    /* check_string() escapes quotes; the LIKE wildcards stay available to the buyer. */
    $storeWhere .= " AND `name` LIKE '%" . check_string($storeKeyword) . "%' ";
}

$storeOrder = " ORDER BY `stt` DESC, `id` DESC ";
$storeOrderSetting = (string) $CMSNT->site("order_by_product_home");
if ($storeOrderSetting === "2") {
    $storeOrder = " ORDER BY `price` ASC, `id` DESC ";
} elseif ($storeOrderSetting === "3") {
    $storeOrder = " ORDER BY `price` DESC, `id` DESC ";
}
if ($storeSort === "price-asc") {
    $storeOrder = " ORDER BY `price` ASC, `id` DESC ";
} elseif ($storeSort === "price-desc") {
    $storeOrder = " ORDER BY `price` DESC, `id` DESC ";
} elseif ($storeSort === "sold") {
    $storeOrder = " ORDER BY `sold` DESC, `id` DESC ";
} elseif ($storeSort === "name") {
    $storeOrder = " ORDER BY `name` ASC ";
}

$storeTotal = (int) $CMSNT->num_rows(" SELECT * FROM `products` WHERE " . $storeWhere . " ");
$storeFrom = ($storePage - 1) * $storeLimit;
$storeProducts = (array) $CMSNT->get_list(
    " SELECT * FROM `products` WHERE " . $storeWhere . " " . $storeOrder
    . " LIMIT " . $storeFrom . "," . $storeLimit . " "
);

/* --- categories, one count query ----------------------------------------- */
$storeCategories = (array) $CMSNT->get_list(
    " SELECT * FROM `categories` WHERE `status` = 1 AND `parent_id` != 0 ORDER BY `stt` DESC "
);
$storeCategoryNames = array();
$storeCategoryCounts = array();
foreach ($storeCategories as $storeCategoryRow) {
    $storeCategoryNames[(int) $storeCategoryRow["id"]] = $storeCategoryRow;
}
$storeCountRows = (array) $CMSNT->get_list(
    " SELECT `category_id`, COUNT(*) AS `c` FROM `products` WHERE `status` = 1 GROUP BY `category_id` "
);
foreach ($storeCountRows as $storeCountRow) {
    $storeCategoryCounts[(int) $storeCountRow["category_id"]] = (int) $storeCountRow["c"];
}

/* --- stock, one query for the whole grid (never getStock() in a loop) ---- */
$storeCodes = array();
foreach ($storeProducts as $storeProduct) {
    if ((int) $storeProduct["supplier_id"] === 0 && (string) $storeProduct["code"] !== "") {
        $storeCodes[(string) $storeProduct["code"]] = true;
    }
}
$storeStock = array();
if (!empty($storeCodes)) {
    $storeQuoted = array();
    foreach (array_keys($storeCodes) as $storeCode) {
        $storeQuoted[] = "'" . str_replace(array("\\", "'"), array("\\\\", "\\'"), $storeCode) . "'";
    }
    $storeStockRows = (array) $CMSNT->get_list(
        " SELECT `product_code`, COUNT(*) AS `c` FROM `product_stock` WHERE `product_code` IN ("
        . implode(",", $storeQuoted) . ") GROUP BY `product_code` "
    );
    foreach ($storeStockRows as $storeStockRow) {
        $storeStock[(string) $storeStockRow["product_code"]] = (int) $storeStockRow["c"];
    }
}
$storeStockOf = function ($product) use ($storeStock) {
    if ((int) $product["supplier_id"] !== 0) {
        return (int) $product["api_stock"];
    }
    $code = (string) $product["code"];
    return isset($storeStock[$code]) ? (int) $storeStock[$code] : 0;
};

/* The shop may hide sold-out rows; filter after the batched stock read. */
$storeVisible = array();
foreach ($storeProducts as $storeProduct) {
    if ((string) $CMSNT->site("product_hide_outstock") === "1" && $storeStockOf($storeProduct) === 0) {
        continue;
    }
    $storeProduct["_stock"] = $storeStockOf($storeProduct);
    $storeVisible[] = $storeProduct;
}

$storePayments = store_payment_methods($CMSNT);
$storePrimaryRecharge = !empty($storePayments) ? $storePayments[0]["href"] : BASE_URL("client/contact");
$storeHeroProducts = array_slice($storeVisible, 0, 5);

$storeQuery = array("keyword" => $storeKeyword, "sort" => $storeSort);
if ($storeSlug !== "") {
    $storeQuery["slug"] = $storeSlug;
} elseif ($storeCategoryId > 0) {
    $storeQuery["category"] = $storeCategoryId;
}

$storeTitle = trim((string) $CMSNT->site("title"));
$storeDesc = trim((string) $CMSNT->site("description"));
$storeLead = $storeDesc !== ""
    ? $storeDesc
    : "Chọn sản phẩm, thanh toán và xem lại đơn hàng trong tài khoản của bạn.";

$storeHeading = "Sản phẩm";
$storeHeadingNote = "Toàn bộ sản phẩm đang bán.";
if ($storeKeyword !== "") {
    $storeHeading = "Kết quả cho “" . $storeKeyword . "”";
    $storeHeadingNote = "Tìm theo tên sản phẩm.";
} elseif ($storeCategory) {
    $storeHeading = $storeCategory["name"];
    $storeHeadingNote = isset($storeCategory["description"]) && $storeCategory["description"] !== ""
        ? $storeCategory["description"]
        : "Sản phẩm trong danh mục này.";
}

$storeImageOf = function ($product) {
    $images = isset($product["images"]) ? trim((string) $product["images"]) : "";
    if ($images === "") {
        return "";
    }
    $lines = preg_split("/\r\n|\n|\r/", $images);
    $first = trim((string) $lines[0]);
    if ($first === "" || preg_match("#^https?://#i", $first)) {
        return "";
    }
    if (!is_file(__DIR__ . "/../../assets/storage/images/products/" . $first)) {
        return "";
    }
    return dirImageProduct($first);
};

$storeIconOf = function ($category) {
    $icon = isset($category["icon"]) ? (string) $category["icon"] : "";
    if ($icon === "" || !is_file(__DIR__ . "/../../" . $icon)) {
        return "";
    }
    return $icon;
};

$storeShell = array(
    "title" => $storeTitle !== "" ? $storeTitle : "Shop",
    "desc" => $storeDesc,
    "search" => true,
    "search_value" => $storeKeyword,
    /* Sections own their own .container; the main landmark must stay full-bleed so the
       alternating bands can run edge to edge. */
    "main_class" => "",
    "nav" => store_nav_default($CMSNT, $storePayments),
    "footer_note" => $storeDesc,
);
store_shell_open($storeShell);
?>

<?php if (trim((string) $CMSNT->site("notice_home")) !== "") { ?>
<div class="container pt-4">
  <div class="notice" role="status"><?php echo $CMSNT->site("notice_home"); ?></div>
</div>
<?php } ?>

<section class="container hero" aria-labelledby="hero-title">
  <div class="hero__text">
    <p class="eyebrow">Sản phẩm đang bán</p>
    <h1 class="hero__title" id="hero-title"><?php echo store_e($storeTitle !== "" ? $storeTitle : "Shop"); ?></h1>
    <p class="hero__lead lead"><?php echo store_e($storeLead); ?></p>
    <div class="hero__actions">
      <a class="btn btn--primary btn--lg" href="#san-pham">Xem sản phẩm</a>
      <a class="btn btn--lg" href="<?php echo store_e($storePrimaryRecharge); ?>">Nạp tiền</a>
    </div>
    <ul class="hero__facts">
      <li class="hero__fact"><strong><?php echo format_cash($storeTotal); ?></strong><span>sản phẩm đang bán</span></li>
      <li class="hero__fact"><strong><?php echo format_cash(count($storeCategories)); ?></strong><span>danh mục</span></li>
      <?php if (!empty($storePayments)) { ?>
      <li class="hero__fact"><strong><?php echo format_cash(count($storePayments)); ?></strong><span>phương thức nạp tiền</span></li>
      <?php } ?>
    </ul>
  </div>

  <aside class="hero__panel" aria-labelledby="hero-panel-title">
    <div class="hero__panel-head">
      <h2 id="hero-panel-title">Đang bán</h2>
      <span class="pill"><?php echo format_cash(count($storeVisible)); ?> sản phẩm</span>
    </div>
    <?php if (!empty($storeHeroProducts)) { ?>
    <ul class="hero__list">
      <?php foreach ($storeHeroProducts as $storeHeroProduct) { ?>
      <li>
        <a class="hero__item" href="<?php echo BASE_URL("product/" . $storeHeroProduct["slug"]); ?>">
          <span class="hero__item-name"><?php echo store_e($storeHeroProduct["name"]); ?></span>
          <span class="hero__item-meta">
            <?php
            echo $storeHeroProduct["_stock"] > 0
                ? "Còn " . format_cash($storeHeroProduct["_stock"])
                : "Tạm hết hàng";
            if ((int) $storeHeroProduct["sold"] > 0 && (string) $CMSNT->site("product_sold_display") === "1") {
                echo ' · Đã bán ' . format_cash($storeHeroProduct["sold"]);
            }
            ?>
          </span>
          <span class="hero__item-price">
            <?php
            $storeHeroPrice = (float) $storeHeroProduct["price"]
                * (1 - ((float) $storeHeroProduct["discount"] / 100));
            echo store_e(format_currency($storeHeroPrice));
            ?>
            <small><?php echo (int) $storeHeroProduct["discount"] > 0 ? "đã giảm " . (int) $storeHeroProduct["discount"] . "%" : "giá hiện tại"; ?></small>
          </span>
        </a>
      </li>
      <?php } ?>
    </ul>
    <?php } else { ?>
    <div class="card__body">
      <p class="muted small m0">Chưa có sản phẩm nào đang bán. Vui lòng quay lại sau hoặc liên hệ shop.</p>
    </div>
    <?php } ?>
    <div class="hero__panel-foot">
      <span>Giá cập nhật từ danh mục sản phẩm</span>
      <a href="#san-pham">Tất cả →</a>
    </div>
  </aside>
</section>

<?php if (!empty($storeCategories)) { ?>
<section class="section band" id="danh-muc" aria-labelledby="danh-muc-title">
  <div class="container">
    <div class="section-head">
      <div>
        <h2 id="danh-muc-title">Danh mục</h2>
        <p class="muted">Chọn nhóm sản phẩm bạn cần.</p>
      </div>
    </div>
    <div class="grid grid--categories">
      <?php foreach ($storeCategories as $storeCategoryRow) {
          $storeIcon = $storeIconOf($storeCategoryRow);
          $storeCount = isset($storeCategoryCounts[(int) $storeCategoryRow["id"]])
              ? (int) $storeCategoryCounts[(int) $storeCategoryRow["id"]] : 0;
          ?>
      <a class="category-card" href="<?php echo BASE_URL("category/" . $storeCategoryRow["slug"]); ?>">
        <?php if ($storeIcon !== "") { ?>
        <img class="category-card__icon" src="<?php echo store_e(BASE_URL($storeIcon)); ?>" alt="" width="36" height="36" loading="lazy" decoding="async">
        <?php } else { ?>
        <span class="category-card__mark" aria-hidden="true"><?php echo store_e(store_first_char($storeCategoryRow["name"])); ?></span>
        <?php } ?>
        <span class="category-card__name"><?php echo store_e($storeCategoryRow["name"]); ?></span>
        <span class="category-card__meta"><?php echo format_cash($storeCount); ?> sản phẩm</span>
      </a>
      <?php } ?>
    </div>
  </div>
</section>
<?php } ?>

<section class="section" id="san-pham" aria-labelledby="san-pham-title">
  <div class="container">
    <div class="section-head">
      <div>
        <h2 id="san-pham-title"><?php echo store_e($storeHeading); ?></h2>
        <p class="muted"><?php echo store_e($storeHeadingNote); ?></p>
      </div>
      <?php if ($storeKeyword !== "" || $storeCategory) { ?>
      <a class="section-head__link" href="<?php echo BASE_URL("client/home"); ?>#san-pham">Xóa bộ lọc</a>
      <?php } ?>
    </div>

    <div class="catalog-bar">
      <div class="chips" role="list" aria-label="Danh mục sản phẩm">
        <a class="chip" role="listitem" href="<?php echo BASE_URL("client/home"); ?>#san-pham"
          <?php echo $storeCategoryId === 0 && $storeKeyword === "" ? 'aria-current="page"' : ""; ?>>Tất cả</a>
        <?php foreach ($storeCategories as $storeCategoryRow) {
            $storeChipActive = $storeCategoryId === (int) $storeCategoryRow["id"];
            ?>
        <a class="chip" role="listitem" href="<?php echo BASE_URL("category/" . $storeCategoryRow["slug"]); ?>#san-pham"
          <?php echo $storeChipActive ? 'aria-current="page"' : ""; ?>><?php echo store_e($storeCategoryRow["name"]); ?></a>
        <?php } ?>
      </div>
      <form class="catalog-bar__sort cluster" method="get" action="<?php echo BASE_URL("client/home"); ?>#san-pham">
        <?php if ($storeKeyword !== "") { ?>
        <input type="hidden" name="keyword" value="<?php echo store_e($storeKeyword); ?>">
        <?php } ?>
        <?php if ($storeSlug !== "") { ?>
        <input type="hidden" name="slug" value="<?php echo store_e($storeSlug); ?>">
        <?php } elseif ($storeCategoryId > 0) { ?>
        <input type="hidden" name="category" value="<?php echo (int) $storeCategoryId; ?>">
        <?php } ?>
        <label class="sr-only" for="sort">Sắp xếp</label>
        <select class="select" id="sort" name="sort">
          <?php foreach ($storeSorts as $storeSortValue => $storeSortLabel) { ?>
          <option value="<?php echo store_e($storeSortValue); ?>"<?php echo $storeSort === $storeSortValue ? " selected" : ""; ?>><?php echo store_e($storeSortLabel); ?></option>
          <?php } ?>
        </select>
        <button class="btn" type="submit">Áp dụng</button>
      </form>
    </div>

    <?php if (empty($storeVisible)) { ?>
    <div class="empty">
      <p class="empty__title">Không có sản phẩm phù hợp</p>
      <p class="muted small">Thử từ khóa khác hoặc xem toàn bộ sản phẩm.</p>
      <div class="empty__actions">
        <a class="btn btn--primary" href="<?php echo BASE_URL("client/home"); ?>#san-pham">Xem tất cả</a>
        <a class="btn btn--ghost" href="<?php echo BASE_URL("client/contact"); ?>">Liên hệ</a>
      </div>
    </div>
    <?php } else { ?>
    <div class="product-grid<?php echo (string) $CMSNT->site("type_show_product") === "LIST" ? " product-grid--list" : ""; ?>">
      <?php foreach ($storeVisible as $storeProduct) {
          $storeProductImage = $storeImageOf($storeProduct);
          $storeProductCategory = isset($storeCategoryNames[(int) $storeProduct["category_id"]])
              ? $storeCategoryNames[(int) $storeProduct["category_id"]] : null;
          $storePrice = (float) $storeProduct["price"];
          $storeDiscount = (float) $storeProduct["discount"];
          $storeFinal = $storePrice * (1 - ($storeDiscount / 100));
          $storeStockCount = (int) $storeProduct["_stock"];
          ?>
      <article class="product-card">
        <a class="product-card__media" href="<?php echo BASE_URL("product/" . $storeProduct["slug"]); ?>" tabindex="-1" aria-hidden="true">
          <?php if ($storeProductImage !== "") { ?>
          <img src="<?php echo store_e(BASE_URL($storeProductImage)); ?>" alt="" width="400" height="225" loading="lazy" decoding="async">
          <?php } else { ?>
          <span class="product-card__mark"><?php echo store_e(store_first_char($storeProduct["name"])); ?></span>
          <?php } ?>
          <?php if ($storeDiscount > 0) { ?>
          <span class="product-card__badge">-<?php echo (int) $storeDiscount; ?>%</span>
          <?php } ?>
        </a>
        <div class="product-card__body">
          <?php if ($storeProductCategory) { ?>
          <span class="product-card__category"><?php echo store_e($storeProductCategory["name"]); ?></span>
          <?php } ?>
          <h3 class="product-card__title">
            <a href="<?php echo BASE_URL("product/" . $storeProduct["slug"]); ?>"><?php echo store_e($storeProduct["name"]); ?></a>
          </h3>
          <p class="product-card__desc clamp-2"><?php echo store_e(trim(strip_tags((string) $storeProduct["short_desc"]))); ?></p>
          <div class="product-card__meta">
            <span class="stock<?php echo $storeStockCount === 0 ? " stock--out" : ($storeStockCount <= 5 ? " stock--low" : ""); ?>">
              <?php echo $storeStockCount > 0 ? "Còn " . format_cash($storeStockCount) : "Tạm hết hàng"; ?>
            </span>
            <?php if ((int) $storeProduct["sold"] > 0 && (string) $CMSNT->site("product_sold_display") === "1") { ?>
            <span class="meta-sep" aria-hidden="true">·</span>
            <span class="xs faint">Đã bán <?php echo format_cash($storeProduct["sold"]); ?></span>
            <?php } ?>
          </div>
          <div class="product-card__foot">
            <span class="price-block">
              <?php if ($storeDiscount > 0) { ?>
              <span class="price--old"><?php echo store_e(format_currency($storePrice)); ?></span>
              <?php } ?>
              <span class="price"><?php echo store_e(format_currency($storeFinal)); ?></span>
            </span>
            <?php if ($storeStockCount > 0) { ?>
            <a class="btn" href="<?php echo BASE_URL("product/" . $storeProduct["slug"]); ?>">Xem</a>
            <?php } else { ?>
            <span class="btn" aria-disabled="true">Tạm hết</span>
            <?php } ?>
          </div>
        </div>
      </article>
      <?php } ?>
    </div>

    <?php
    echo store_pager($storePage, $storeTotal, $storeLimit, $storeQuery);
    ?>
    <?php } ?>
  </div>
</section>

<section class="section band" id="cach-mua" aria-labelledby="cach-mua-title">
  <div class="container">
    <div class="section-head">
      <div>
        <h2 id="cach-mua-title">Cách mua</h2>
        <p class="muted">Ba bước, tất cả nằm trong tài khoản của bạn.</p>
      </div>
    </div>
    <div class="steps">
      <div class="step">
        <h3>Chọn sản phẩm</h3>
        <p>Mở sản phẩm, chọn số lượng trong giới hạn shop cho phép và thêm vào đơn.</p>
      </div>
      <div class="step">
        <h3>Thanh toán</h3>
        <p>Trả bằng số dư tài khoản hoặc nạp tiền qua phương thức shop đang bật.</p>
      </div>
      <div class="step">
        <h3>Nhận hàng</h3>
        <p>Đơn và nội dung sản phẩm được lưu trong mục lịch sử đơn hàng để tra cứu lại.</p>
      </div>
    </div>
  </div>
</section>

<?php if (!empty($storePayments)) { ?>
<section class="section" id="thanh-toan" aria-labelledby="thanh-toan-title">
  <div class="container">
    <div class="section-head">
      <div>
        <h2 id="thanh-toan-title">Nạp tiền</h2>
        <p class="muted">Các phương thức shop đang mở.</p>
      </div>
      <a class="section-head__link" href="<?php echo store_e($storePrimaryRecharge); ?>">Nạp ngay</a>
    </div>
    <div class="payment-grid">
      <?php foreach ($storePayments as $storePayment) {
          $storePaymentIcon = isset($storePayment["icon"]) && $storePayment["icon"] !== ""
              && is_file(__DIR__ . "/../../" . $storePayment["icon"]);
          ?>
      <a class="payment" href="<?php echo store_e($storePayment["href"]); ?>">
        <?php if ($storePaymentIcon) { ?>
        <img src="<?php echo store_e(BASE_URL($storePayment["icon"])); ?>" alt="" width="28" height="28" loading="lazy" decoding="async">
        <?php } else { ?>
        <span class="payment__mark" aria-hidden="true"><?php echo store_e(store_first_char($storePayment["label"])); ?></span>
        <?php } ?>
        <span>
          <strong><?php echo store_e($storePayment["label"]); ?></strong>
          <span>Nạp vào số dư</span>
        </span>
      </a>
      <?php } ?>
    </div>
  </div>
</section>
<?php } ?>

<section class="section band" id="ho-tro" aria-labelledby="ho-tro-title">
  <div class="container">
    <div class="section-head">
      <div>
        <h2 id="ho-tro-title">Hỗ trợ</h2>
        <p class="muted">Liên hệ đúng kênh shop đang dùng.</p>
      </div>
      <a class="section-head__link" href="<?php echo BASE_URL("client/contact"); ?>">Trang liên hệ</a>
    </div>
    <div class="support">
      <div class="support__panel">
        <ul class="support__list">
          <?php
          $storeSupport = array(
              "Hotline" => $CMSNT->site("hotline"),
              "Email" => $CMSNT->site("email"),
              "Địa chỉ" => $CMSNT->site("address"),
          );
          $storeSupportShown = 0;
          foreach ($storeSupport as $storeSupportLabel => $storeSupportValue) {
              if (trim((string) $storeSupportValue) === "") {
                  continue;
              }
              $storeSupportShown++;
              ?>
          <li>
            <span><?php echo store_e($storeSupportLabel); ?></span>
            <strong><?php echo store_e($storeSupportValue); ?></strong>
          </li>
          <?php } ?>
          <?php if ($storeSupportShown === 0) { ?>
          <li><span>Kênh liên hệ</span><strong>Chưa cấu hình trong phần cài đặt</strong></li>
          <?php } ?>
        </ul>
        <?php if ($storeSupportShown > 0) { ?>
        <p class="muted small m0">Thông tin lấy từ phần cài đặt của shop, không phải nội dung mẫu.</p>
        <?php } ?>
      </div>
      <div class="support__panel">
        <h3 class="mb-2">Câu hỏi thường gặp</h3>
        <p class="muted small">Xem hướng dẫn mua hàng, nạp tiền và xử lý đơn trước khi liên hệ.</p>
        <div class="cluster mt-4">
          <a class="btn btn--ghost" href="<?php echo BASE_URL("client/faq"); ?>">Đọc FAQ</a>
          <a class="btn btn--ghost" href="<?php echo BASE_URL("client/document-api"); ?>">Tài liệu API</a>
        </div>
      </div>
    </div>
  </div>
</section>

<?php if ((string) $CMSNT->site("popup_status") === "1" && trim((string) $CMSNT->site("popup_noti")) !== "") { ?>
<dialog class="store-dialog" data-notice="store-home-notice" aria-labelledby="store-notice-title">
  <div class="store-dialog__head">
    <h2 id="store-notice-title">Thông báo</h2>
    <button class="store-dialog__close" type="button" data-dialog-close aria-label="Đóng thông báo">×</button>
  </div>
  <div class="store-dialog__body"><?php echo $CMSNT->site("popup_noti"); ?></div>
  <div class="store-dialog__foot">
    <button class="btn btn--primary" type="button" data-dialog-close>Đã hiểu</button>
  </div>
</dialog>
<?php } ?>

<?php
store_shell_close(array(
    "footer_note" => $storeDesc,
));
