<?php
/*
 * Orbit preview builder — renders the real pages with sample data into a static, self-contained preview.
 *
 *   php tools/preview-build.php                  # writes docs/frontend/preview/
 *   php tools/preview-build.php --out=/tmp/prev  # write somewhere else (e.g. to zip and send)
 *   php tools/preview-build.php --root=/path/to/checkout
 *   php tools/preview-build.php --clean          # removes the preview directory
 *
 * Why: the new screens (sales dashboard, customer purchase history, styleguide) live inside the shop and
 * cannot be opened without an installation. This tool renders them through their real code paths — the
 * same views, shell, charts, tables and CSS — with a stubbed database holding sample rows, and rewrites
 * the absolute asset URLs to a local folder. The result opens in any browser, offline, and is what the
 * owner reviews before the pages go live.
 *
 * It is a documentation tool, not part of the app: CLI only, no network, no database, writes only inside
 * docs/frontend/preview/.
 */

/* The STDERR constant exists only in the CLI SAPI; php://stderr exists everywhere. */
if (!defined("STDERR")) {
    define("STDERR", fopen("php://stderr", "wb"));
}

if (PHP_SAPI !== "cli") {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$root = dirname(__DIR__);
$outDir = $root . "/docs/frontend/preview";

/* --root / --out let the owner generate the preview from another checkout, or into a folder to zip
   and send. Both are paths the person running the tool chooses; nothing here reads a request. */
foreach (array_slice(isset($argv) ? $argv : array(), 1) as $argument) {
    if (strpos($argument, "--root=") === 0) {
        $candidate = rtrim(substr($argument, 7), "/");
        if ($candidate !== "" && is_dir($candidate . "/views")) {
            $root = $candidate;
        }
    }
    if (strpos($argument, "--out=") === 0) {
        $candidate = substr($argument, 6);
        if ($candidate !== "") {
            $outDir = $candidate;
        }
    }
}

if (in_array("--clean", $argv, true)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($outDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    if (is_dir($outDir)) {
        rmdir($outDir);
    }
    echo "Removed " . $outDir . "\n";
    exit(0);
}

/* ------------------------------------------------------------- environment */

define("IN_SITE", true);

/* The preview lives in a folder, so every asset URL is relative to it. */
if (!function_exists("BASE_URL")) {
    /**
     * A file reference resolves to the mirrored file; a shop route becomes an inert #preview-… anchor,
     * so a click in the static preview never lands on a 404 inside the folder.
     */
    function BASE_URL($path = "")
    {
        $clean = ltrim(str_replace("public/", "", (string) $path), "/");
        if (preg_match("~\\.(css|js|json|png|jpe?g|svg|webp|gif|ico|woff2?)$~i", $clean)) {
            /* Root-relative shop assets (assets/img/icon-*.svg) already live under the preview's
               assets/ folder, so prefixing them again produced assets/assets/… and a broken icon. */
            return strpos($clean, "assets/") === 0 ? $clean : "assets/" . $clean;
        }
        return "#preview-" . trim(preg_replace("~[^a-z0-9]+~i", "-", $clean), "-");
    }
}
if (!function_exists("base_url_admin")) {
    function base_url_admin($path = "")
    {
        return "#preview-admin-" . str_replace(array("&", "="), "-", (string) $path);
    }
}
if (!function_exists("check_string")) {
    function check_string($data)
    {
        return trim(htmlspecialchars(addslashes((string) $data)));
    }
}
if (!function_exists("redirect")) {
    function redirect($url)
    {
        echo "<!-- preview: would redirect to " . htmlspecialchars((string) $url, ENT_QUOTES, "UTF-8") . " -->";
        return true;
    }
}
if (!function_exists("generate_csrf_token")) {
    function generate_csrf_token()
    {
        return "csrf-preview-token";
    }
}
if (!function_exists("myip")) {
    function myip()
    {
        return "127.0.0.1";
    }
}
if (!function_exists("__")) {
    function __($text)
    {
        return $text;
    }
}
if (!function_exists("gettime")) {
    function gettime()
    {
        return date("Y/m/d H:i:s");
    }
}
if (!function_exists("format_currency")) {
    function format_currency($amount)
    {
        return number_format((float) $amount, 0, ",", ".") . "đ";
    }
}
if (!function_exists("format_cash")) {
    function format_cash($number, $suffix = "")
    {
        return number_format((float) $number, 0, ",", ".") . (string) $suffix;
    }
}
if (!function_exists("getRowRealtime")) {
    /* libs/helper.php reads one column from one row. The preview has no helper.php (it opens a
       database), so answer from the fixture map: only `users.ref_ck` and `wallets.qrcode` are used. */
    function getRowRealtime($table, $id, $row)
    {
        if ($table === "wallets") {
            return "";
        }
        if ($table === "users") {
            $user = isset(DB::$fixtures["user"]) ? DB::$fixtures["user"] : array();
            return isset($user[$row]) ? $user[$row] : 0;
        }
        return 0;
    }
}
if (!function_exists("active_sidebar")) {
    function active_sidebar($action)
    {
        return "";
    }
}
if (!function_exists("show_sidebar")) {
    function show_sidebar($action)
    {
        return "";
    }
}
if (!function_exists("pagination")) {
    function pagination($url, $from, $total, $limit)
    {
        return "";
    }
}

require_once $root . "/tools/preview-data.php";

/* A frozen clock: the invoice countdown is the one value that would change on every rebuild. */
if (!function_exists("orbit_now")) {
    function orbit_now()
    {
        return 1760000000;
    }
}
/* The preview runs outside the shop's bootstrap, so it loads the same libraries the pages use. */
require_once $root . "/libs/clock.php";
require_once $root . "/libs/money.php";
require_once $root . "/libs/sales_stats.php";
require_once $root . "/views/common/pagination.php";
require_once $root . "/views/common/orbit-shell.php";
require_once $root . "/views/common/chart.php";
require_once $root . "/libs/payos.php";

/* Sample payOS configuration: no real credentials, just enough for the page to render as configured.
   The preview has no MySQL, so the history query fails — the page's fail-closed state is what shows. */
putenv("PAYOS_ENABLED=true");
putenv("PAYOS_CLIENT_ID=preview-client");
putenv("PAYOS_API_KEY=preview-key");
putenv("PAYOS_CHECKSUM_KEY=preview-checksum");
putenv("APP_URL=https://shop.example");
putenv("PAYOS_MIN_AMOUNT=10000");
putenv("PAYOS_MAX_AMOUNT=5000000");

/* --------------------------------------------------------------- rendering */

/**
 * Runs a view and returns its HTML. Each view is rendered in a clean copy of the process state.
 */
function preview_render($root, $relativeView, array $session, array $get, array $fixtures)
{
    DB::$fixtures = $fixtures;
    DB::$log = array();
    $_SESSION = $session;
    /* A fixed token keeps the generated HTML byte-identical between builds; a random one made every
       rebuild show up as a diff even when nothing on the page had changed. */
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = "csrf-preview-token";
    }
    /* payOS and GachtheFast mint their own per-form tokens on first use. */
    if (empty($_SESSION["payos_csrf"])) {
        $_SESSION["payos_csrf"] = str_repeat("0", 64);
    }
    if (empty($_SESSION["gtf_csrf"])) {
        $_SESSION["gtf_csrf"] = str_repeat("1", 64);
    }
    $_COOKIE = array();
    $_GET = $get;
    unset($GLOBALS["getUser"]);
    $CMSNT = new DB();
    $GLOBALS["CMSNT"] = $CMSNT;
    /* Each page is a separate request in production. Here one process renders many pages, so a view's
       require_once of models/is_user.php is a no-op after the first page that needed it: resolve the
       signed-in row up front exactly as that model would, or every later page sees no user. */
    $getUser = null;
    if (isset($session["login"])) {
        $getUser = $CMSNT->get_row(
            " SELECT * FROM `users` WHERE `token` = '" . check_string($session["login"]) . "'  "
        );
    }
    /* models/is_user.php publishes the row for the shell; when its require_once is skipped, publish the
       same row here so the balance chip and the buy panel agree about who is signed in. */
    $GLOBALS["getUser"] = $getUser;

    /* Each page is a separate request in production, but this process renders all of them: reset the
       once-per-request chart asset guard, or only the first page links chart.js. */
    orbit_chart_assets(true);

    ob_start();
    include $root . "/" . $relativeView;
    return ob_get_clean();
}

$period = sales_period(30);
$fixtures = preview_fixtures();

/* Two columns every real product row has and the sample rows do not need: the base64 description the
   owner writes in the admin, and the per-order quantity limits. Filled here once rather than repeated. */
$sampleDescription = base64_encode(
    "<p>Tài khoản chính chủ, giao ngay sau khi thanh toán. Bạn nhận thông tin đăng nhập trong mục "
    . "Lịch sử mua hàng và có thể đổi mật khẩu lần đầu.</p>"
    . "<p>Bảo hành trong suốt thời hạn. Nếu tài khoản có sự cố, gửi mã đơn cho hỗ trợ để được cấp lại.</p>"
);
/* The dashboard's sample orders use their own product ids; point them at the storefront rows with the
   same name so the order detail can resolve the product (and its delivery note) like production does. */
$storeProductsByName = array();
foreach ($fixtures["store_products"] as $sampleProduct) {
    $storeProductsByName[$sampleProduct["name"]] = $sampleProduct["id"];
}
foreach ($fixtures["mine_orders"] as $index => $sampleOrder) {
    if (isset($storeProductsByName[$sampleOrder["product_name"]])) {
        $fixtures["mine_orders"][$index]["product_id"] = $storeProductsByName[$sampleOrder["product_name"]];
    }
}

foreach ($fixtures["store_products"] as $index => $sampleProduct) {
    if (!isset($sampleProduct["description"]) || trim((string) $sampleProduct["description"]) === "") {
        $fixtures["store_products"][$index]["description"] = $sampleDescription;
    }
    $fixtures["store_products"][$index]["min"] = isset($sampleProduct["min"]) ? $sampleProduct["min"] : 1;
    $fixtures["store_products"][$index]["max"] = isset($sampleProduct["max"]) ? $sampleProduct["max"] : 10;
    if (!isset($sampleProduct["note"]) || trim((string) $sampleProduct["note"]) === "") {
        $fixtures["store_products"][$index]["note"] = base64_encode(
            "<p>Đổi mật khẩu trong lần đăng nhập đầu tiên và không chia sẻ tài khoản cho người ngoài.</p>"
        );
    }
}

$pages = array();

/* 1. the admin sales dashboard */
$pages["sales.html"] = array(
    "title" => "Thống kê bán hàng (admin) — dữ liệu mẫu",
    "html" => preview_render($root, "views/admin/sales.php", array("admin_login" => "admintok"),
        array("module" => "admin", "action" => "sales", "days" => "30"), $fixtures),
);

/* 2. the customer purchase history */
$pages["purchase-history.html"] = array(
    "title" => "Lịch sử mua hàng (khách) — dữ liệu mẫu",
    "html" => preview_render($root, "views/client/purchase-history.php", array("login" => "tok"),
        array("module" => "client", "action" => "purchase-history", "days" => "90"), $fixtures),
);

/* 3. the storefront home, rebuilt on the v3 light design system */
$pages["home.html"] = array(
    "title" => "Trang chủ cửa hàng — bản xem trước",
    "html" => preview_render($root, "views/client/home.php", array(),
        array("module" => "client", "action" => "home", "sort" => "price-asc"), $fixtures),
);

/* 4. a product page (guest: the panel shows the sign-in path, not a live form) */
$pages["product.html"] = array(
    "title" => "Trang sản phẩm — bản xem trước",
    "html" => preview_render($root, "views/client/product.php", array(),
        array("module" => "client", "action" => "product", "slug" => "netflix-premium-1-thang"), $fixtures),
);

/* 4b. the same page signed in: balance chip, quantity stepper and the buy button as a buyer sees them */
$pages["product-signed.html"] = array(
    "title" => "Trang sản phẩm, đã đăng nhập — bản xem trước",
    "html" => preview_render($root, "views/client/product.php", array("login" => "tok"),
        array("module" => "client", "action" => "product", "slug" => "netflix-premium-1-thang"), $fixtures),
);

/* 5. sign-in and registration */
$pages["login.html"] = array(
    "title" => "Đăng nhập — bản xem trước",
    "html" => preview_render($root, "views/client/login.php", array(),
        array("module" => "client", "action" => "login"), $fixtures),
);
$pages["register.html"] = array(
    "title" => "Đăng ký — bản xem trước",
    "html" => preview_render($root, "views/client/register.php", array(),
        array("module" => "client", "action" => "register"), $fixtures),
);

/* 6. the account overview, signed in with the sample buyer */
$pages["account.html"] = array(
    "title" => "Tài khoản — bản xem trước",
    "html" => preview_render($root, "views/client/profile.php", array("login" => "tok"),
        array("module" => "client", "action" => "profile"), $fixtures),
);

/* 6b. order history and one order, signed in */
$sampleTrans = $fixtures["mine_orders"][0]["trans_id"];
$pages["orders.html"] = array(
    "title" => "Lịch sử đơn hàng — bản xem trước",
    "html" => preview_render($root, "views/client/product-orders.php", array("login" => "tok"),
        array("module" => "client", "action" => "product-orders"), $fixtures),
);
$pages["order.html"] = array(
    "title" => "Chi tiết đơn hàng — bản xem trước",
    "html" => preview_render($root, "views/client/product-order.php", array("login" => "tok"),
        array("module" => "client", "action" => "product-order", "trans_id" => $sampleTrans), $fixtures),
);

/* 6c. money in */
$pages["recharge.html"] = array(
    "title" => "Nạp tiền qua payOS — bản xem trước",
    "html" => preview_render($root, "views/client/recharge-payos.php", array("login" => "tok"),
        array("module" => "client", "action" => "recharge-bank"), $fixtures),
);

/* 6d. the owner's manual instructions and the closed GachtheFast channel */
putenv("PAYOS_ENABLED=false"); /* the manual page defers to payOS when it is on */
$pages["recharge-manual.html"] = array(
    "title" => "Nạp tiền thủ công — bản xem trước",
    "html" => preview_render($root, "views/client/recharge-manual.php", array("login" => "tok"),
        array("module" => "client", "action" => "recharge-manual", "slug" => "bank"), $fixtures),
);
putenv("PAYOS_ENABLED=true");
$pages["recharge-gtf-bank.html"] = array(
    "title" => "Nạp tiền ngân hàng GachtheFast (đang đóng) — bản xem trước",
    "html" => preview_render($root, "views/client/recharge-gtf-bank.php", array("login" => "tok"),
        array("module" => "client", "action" => "recharge-gtf-bank"), $fixtures),
);

/* 6e. the owner's static pages and the blog */
$pages["faq.html"] = array(
    "title" => "Câu hỏi thường gặp — bản xem trước",
    "html" => preview_render($root, "views/client/faq.php", array(), array("module" => "client", "action" => "faq"), $fixtures),
);
$pages["policy.html"] = array(
    "title" => "Chính sách — bản xem trước",
    "html" => preview_render($root, "views/client/policy.php", array(), array("module" => "client", "action" => "policy"), $fixtures),
);
$pages["blogs.html"] = array(
    "title" => "Bài viết — bản xem trước",
    "html" => preview_render($root, "views/client/blogs.php", array(), array("module" => "client", "action" => "blogs", "limit" => 10), $fixtures),
);
$pages["blog.html"] = array(
    "title" => "Một bài viết — bản xem trước",
    "html" => preview_render($root, "views/client/blog.php", array(),
        array("module" => "client", "action" => "blog", "slug" => "bao-hanh-tai-khoan-hoat-dong-the-nao"), $fixtures),
);

/* 6f. the account pages rebuilt this round */
$pages["favorites.html"] = array(
    "title" => "Sản phẩm yêu thích — bản xem trước",
    "html" => preview_render($root, "views/client/favorites.php", array("login" => "tok"),
        array("module" => "client", "action" => "favorites"), $fixtures),
);
$pages["transactions.html"] = array(
    "title" => "Biến động số dư — bản xem trước",
    "html" => preview_render($root, "views/client/transactions.php", array("login" => "tok"),
        array("module" => "client", "action" => "transactions"), $fixtures),
);
$pages["change-password.html"] = array(
    "title" => "Đổi mật khẩu — bản xem trước",
    "html" => preview_render($root, "views/client/change-password.php", array("login" => "tok"),
        array("module" => "client", "action" => "change-password"), $fixtures),
);
$pages["contact.html"] = array(
    "title" => "Liên hệ — bản xem trước",
    "html" => preview_render($root, "views/client/contact.php", array(),
        array("module" => "client", "action" => "contact"), $fixtures),
);

/* 6g. second-step sign-in */
$pages["verify-2fa.html"] = array(
    "title" => "Xác minh 2FA — bản xem trước",
    "html" => preview_render($root, "views/client/verify_2fa.php", array(),
        array("module" => "client", "action" => "verify_2fa", "token" => "preview2fatoken123"), $fixtures),
);
$pages["verify-otp.html"] = array(
    "title" => "Xác minh OTP — bản xem trước",
    "html" => preview_render($root, "views/client/verify_otp.php", array(),
        array("module" => "client", "action" => "verify_otp", "token" => "previewotptoken123"), $fixtures),
);

/* 6h. the affiliate programme */
$pages["affiliates.html"] = array(
    "title" => "Cộng tác viên — bản xem trước",
    "html" => preview_render($root, "views/client/affiliates.php", array("login" => "tok"),
        array("module" => "client", "action" => "affiliates"), $fixtures),
);
$pages["affiliate-history.html"] = array(
    "title" => "Lịch sử hoa hồng — bản xem trước",
    "html" => preview_render($root, "views/client/affiliate-history.php", array("login" => "tok"),
        array("module" => "client", "action" => "affiliate-history"), $fixtures),
);
$pages["affiliate-withdraw.html"] = array(
    "title" => "Rút hoa hồng — bản xem trước",
    "html" => preview_render($root, "views/client/affiliate-withdraw.php", array("login" => "tok"),
        array("module" => "client", "action" => "affiliate-withdraw"), $fixtures),
);

/* 6i. the tools area */
$pages["tools.html"] = array(
    "title" => "Công cụ — bản xem trước",
    "html" => preview_render($root, "views/client/tools.php", array(),
        array("module" => "client", "action" => "tools"), $fixtures),
);
$pages["tool-2fa.html"] = array(
    "title" => "Tạo mã 2FA — bản xem trước",
    "html" => preview_render($root, "views/client/tool-2fa.php", array(),
        array("module" => "client", "action" => "tool-2fa"), $fixtures),
);
$pages["tool-emoji.html"] = array(
    "title" => "Biểu tượng cảm xúc — bản xem trước",
    "html" => preview_render($root, "views/client/tool-icon-facebook.php", array(),
        array("module" => "client", "action" => "tool-icon-facebook"), $fixtures),
);
$pages["tool-checklive.html"] = array(
    "title" => "Check live UID Facebook — bản xem trước",
    "html" => preview_render($root, "views/client/tool-checklive-fb.php", array(),
        array("module" => "client", "action" => "tool-checklive-fb"), $fixtures),
);
$pages["tool-random-face.html"] = array(
    "title" => "Ảnh khuôn mặt ngẫu nhiên — bản xem trước",
    "html" => preview_render($root, "views/client/tool-random-face.php", array(),
        array("module" => "client", "action" => "tool-random-face"), $fixtures),
);

/* 6j. the legacy USDT invoice page an old e-mail link points at */
$pages["payment.html"] = array(
    "title" => "Hoá đơn USDT (kênh cũ) — bản xem trước",
    "html" => preview_render($root, "views/client/payment.php", array(),
        array("module" => "client", "action" => "payment", "trans_id" => "INV7Q2M9X"), $fixtures),
);

/* 6l. the API integration guide (only reachable while the owner keeps the API on).
   The page prints full endpoint URLs, and BASE_URL turns every route into an inert #preview-… anchor so
   a click cannot 404 inside the folder. An input value is text, not a link, so put a readable example
   host back for this one page — otherwise the documentation shows the preview's own plumbing. */
$apiPreviewKey = "9f4c1d2a7b6e8c05d3a1f9e7b2c4d608";
$apiPreviewHost = "https://shop.orbit.example";
$apiPreviewHtml = preview_render($root, "views/client/document-api.php", array("login" => "tok"),
    array("module" => "client", "action" => "document-api"), $fixtures);
$apiPreviewHtml = str_replace(
    array(
        "#preview-api-profile-php-api-key-" . $apiPreviewKey,
        "#preview-api-products-php-api-key-" . $apiPreviewKey,
        "#preview-api-product-php-api-key-" . $apiPreviewKey . "-product-3",
        "#preview-api-api-php-apikey-" . $apiPreviewKey . "-action-get-order-detail-order-id-ORD123",
        "#preview-api-api-php-apikey-" . $apiPreviewKey . "-action-create-order-service-id-3-amount-1",
    ),
    array(
        $apiPreviewHost . "/api/profile.php?api_key=" . $apiPreviewKey,
        $apiPreviewHost . "/api/products.php?api_key=" . $apiPreviewKey,
        $apiPreviewHost . "/api/product.php?api_key=" . $apiPreviewKey . "&product=3",
        $apiPreviewHost . "/api/api.php?apikey=" . $apiPreviewKey . "&action=get-order-detail&order_id=ORD123",
        $apiPreviewHost . "/api/api.php?apikey=" . $apiPreviewKey . "&action=create-order&service_id=3&amount=1",
    ),
    $apiPreviewHtml
);
$pages["document-api.html"] = array(
    "title" => "Tài liệu API — bản xem trước",
    "html" => $apiPreviewHtml,
);

/* 6k. the 404 page, reachable through index.php for a missing route */
$pages["404.html"] = array(
    "title" => "Không tìm thấy trang — bản xem trước",
    "html" => preview_render($root, "views/common/404.php", array(),
        array("module" => "common", "action" => "404"), $fixtures),
);

/* 7. the styleguide (design system reference) */
$pages["styleguide.html"] = array(
    "title" => "Orbit design system — bản xem trước",
    "html" => preview_render($root, "views/common/orbit-styleguide.php", array("admin_login" => "admintok"),
        array("module" => "common", "action" => "orbit-styleguide"), $fixtures),
);

/* 6m. the account pages the sidebar reaches, which had no static review copy before. They render
   with the same fixture user as purchase-history, so the owner can review the signed-in shell on
   every tab without installing the shop. */
$accountPages = array(
    "security.html" => array("client/security.php", "Bảo mật tài khoản"),
    "transactions.html" => array("client/transactions.php", "Biến động số dư"),
    "favorites.html" => array("client/favorites.php", "Sản phẩm yêu thích"),
    "logs.html" => array("client/logs.php", "Nhật ký hoạt động"),
);
foreach ($accountPages as $file => $spec) {
    $pages[$file] = array(
        "title" => $spec[1] . " — bản xem trước",
        "html" => preview_render($root, "views/" . $spec[0], array("login" => "tok"),
            array("module" => "client", "action" => basename($spec[0], ".php")), $fixtures),
    );
}

/* ---------------------------------------------------------------- assembly */

$fix = function ($html) {
    /* The stubs already emit relative asset paths; this only neutralises the demo links. */
    $html = str_replace(array("https://shop.example/", "http://shop.example/", "https://shop.example", "http://shop.example"), "#", $html);
    /* store_asset_version() is a filemtime: a restored checkout or a fresh copy changes every mtime,
       which rewrote all 40 pages on every rebuild even when no CSS or JS had changed. The preview is
       static, so the version only has to distinguish the copied files from a stale cache. */
    $html = preg_replace('/\?v=\d+/', '?v=preview', $html);
    return $html;
};

$banner = '<div class="preview-banner"><strong>Bản xem trước tĩnh</strong> — '
    . 'trang thật chạy trong shop, ở đây dùng dữ liệu mẫu. Không có dữ liệu thật, không gọi mạng, '
    . 'không phát nhạc. Biểu đồ vẽ bằng SVG nội bộ; bảng vẫn đọc được khi tắt JavaScript.</div>' . "\n";

if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create " . $outDir . "\n");
    exit(3);
}
/* public/… is mirrored as assets/…, which is exactly what the BASE_URL stub produces. */
$assetsDir = $outDir . "/assets/client/orbit";
if (!is_dir($assetsDir) && !mkdir($assetsDir, 0777, true) && !is_dir($assetsDir)) {
    fwrite(STDERR, "Cannot create " . $assetsDir . "\n");
    exit(3);
}
if (!is_dir($outDir . "/assets/music")) {
    mkdir($outDir . "/assets/music", 0777, true);
}

$copied = array();
foreach (array("tokens.css", "base.css", "components.css", "shell.css", "store.css", "chart.css", "music-player.css",
               "chart.js", "table.js", "music-player.js", "store.js") as $asset) {
    $source = $root . "/public/client/orbit/" . $asset;
    if (is_file($source) && copy($source, $assetsDir . "/" . $asset)) {
        $copied[] = $asset;
    }
}
/* The storefront preloads one local woff2; the preview must carry the family or it falls back silently. */
$fontDir = $outDir . "/assets/client/fonts/be-vietnam-pro";
if (is_dir($root . "/public/client/fonts/be-vietnam-pro")) {
    if (!is_dir($fontDir)) {
        mkdir($fontDir, 0777, true);
    }
    foreach (glob($root . "/public/client/fonts/be-vietnam-pro/*") as $font) {
        copy($font, $fontDir . "/" . basename($font));
    }
}
$musicPlaylist = $root . "/public/music/playlist.json";
if (is_file($musicPlaylist)) {
    copy($musicPlaylist, $outDir . "/assets/music/playlist.json");
}

/* A tiny extras stylesheet: the banner + a light/dark toggle for reviewing both themes. */
file_put_contents($outDir . "/assets/preview.css", <<<CSS
.preview-banner {
  position: sticky; top: 0; z-index: 100;
  background: var(--warn-soft); color: var(--text);
  border-bottom: 1px solid var(--warn);
  padding: 8px 16px; font-size: 13px;
  backdrop-filter: blur(6px);
}
.preview-banner strong { margin-right: 6px; }
.preview-nav { display: flex; gap: 16px; padding: 12px 16px; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.preview-nav a { font-size: 13px; }
.preview-tile { padding: var(--space-5); text-decoration: none; display: block; }
.preview-tile h3 { margin-bottom: var(--space-2); }
.preview-tile p { margin: 0; }
CSS);

foreach ($pages as $file => $page) {
    $html = $fix($page["html"]);
    /* inject the banner right after <body …> and the toggle stylesheet before </head> */
    $html = preg_replace('#(<body[^>]*>)#', '$1' . "\n" . $banner, $html, 1);
    $html = str_replace("<title>", '<title>[preview] ', $html);
    $html = str_replace("</head>", '<link rel="stylesheet" href="assets/preview.css">' . "\n</head>", $html);
    file_put_contents($outDir . "/" . $file, $html);
    echo "wrote " . $file . " (" . number_format(strlen($html) / 1024, 1) . " KB)\n";
}
/* ------------------------------------------------------------- landing page
 * Generated from the pages that were actually written, so adding a page above
 * cannot leave the index out of date. A page with no description still gets a
 * tile: the file name is better than a dead link nobody notices.
 */
$tileText = array(
    "home.html" => array("Trang chủ cửa hàng", "Thanh điều hướng, khối giới thiệu, danh mục, lưới sản phẩm, lọc/sắp xếp/phân trang và chân trang."),
    "sales.html" => array("Thống kê bán hàng (admin)", "KPI, biểu đồ doanh thu, sản phẩm bán chạy, khách mua nhiều, nạp tiền theo phương thức, bảng đơn và xuất CSV."),
    "purchase-history.html" => array("Lịch sử mua hàng (khách)", "Số tiền đã chi, biểu đồ chi tiêu, tìm kiếm đơn của chính mình và phân trang."),
    "product.html" => array("Trang sản phẩm", "Ảnh hoặc ký tự đại diện, giá và tồn kho thật, bảng mua, mô tả do quản trị nhập."),
    "product-signed.html" => array("Trang sản phẩm (đã đăng nhập)", "Số dư trong thanh trên, ô số lượng, mã giảm giá và nút thanh toán bằng số dư."),
    "login.html" => array("Đăng nhập & đăng ký", "Biểu mẫu có nhãn, trạng thái trong dòng, nút đăng nhập mạng xã hội khi bật."),
    "register.html" => array("Đăng ký", "Tạo tài khoản mới trên cùng khung với trang đăng nhập."),
    "account.html" => array("Tài khoản", "Số dư, tổng đã nạp, đã sử dụng, lối tắt tới đơn hàng và biểu mẫu liên hệ."),
    "favorites.html" => array("Sản phẩm yêu thích", "Danh sách đã lưu, tồn kho theo mã hàng, bỏ lưu ngay tại dòng."),
    "transactions.html" => array("Biến động số dư", "Số dư trước và sau mỗi giao dịch, lọc theo lý do và khoảng ngày."),
    "logs.html" => array("Nhật ký hoạt động", "Đăng nhập, đổi mật khẩu, nạp tiền — kèm IP và thiết bị."),
    "change-password.html" => array("Đổi mật khẩu", "Ba trường, cảnh báo đăng xuất mọi thiết bị sau khi đổi."),
    "security.html" => array("Bảo mật", "Mã QR 2FA nội bộ, khoá dự phòng, công tắc thông báo phụ thuộc SMTP."),
    "contact.html" => array("Liên hệ", "Hotline, email, địa chỉ do quản trị nhập và nội dung hỗ trợ."),
    "forgot-password.html" => array("Quên mật khẩu", "Gửi liên kết đặt lại, kèm trạng thái khi SMTP chưa bật."),
    "reset-password.html" => array("Đặt lại mật khẩu", "Đặt mật khẩu mới bằng liên kết có hạn."),
    "verify-2fa.html" => array("Xác minh 2FA", "Bước đăng nhập thứ hai bằng mã từ ứng dụng xác thực."),
    "verify-otp.html" => array("Xác minh OTP", "Bước đăng nhập thứ hai bằng mã gửi qua email."),
    "affiliates.html" => array("Cộng tác viên", "Hoa hồng, liên kết giới thiệu và danh sách khách đã đăng ký."),
    "affiliate-history.html" => array("Lịch sử hoa hồng", "Từng khoản cộng hoa hồng kèm số dư trước và sau."),
    "affiliate-withdraw.html" => array("Rút hoa hồng", "Biểu mẫu rút, ngân hàng do quản trị nhập, lịch sử yêu cầu."),
    "tools.html" => array("Công cụ", "Bốn tiện ích miễn phí, mở khi quản trị bật menu Công cụ."),
    "tool-2fa.html" => array("Tạo mã 2FA (cục bộ)", "TOTP chạy trong trình duyệt bằng WebCrypto, không gửi khoá đi đâu."),
    "tool-emoji.html" => array("Biểu tượng cảm xúc", "Bảng biểu tượng cục bộ, bấm để chép."),
    "tool-checklive.html" => array("Check live UID Facebook", "Kiểm tra ảnh đại diện công khai, kèm phần giải thích giới hạn."),
    "tool-random-face.html" => array("Ảnh khuôn mặt ngẫu nhiên", "Giới thiệu dịch vụ ngoài và mở trong tab mới, không nhúng khung."),
    "orders.html" => array("Lịch sử đơn hàng", "Lọc theo mã, khoảng ngày; ghi chú riêng, tải .txt và xóa đơn."),
    "order.html" => array("Chi tiết đơn hàng", "Tài khoản đã giao, UID, chép từng dòng hoặc tải cả đơn."),
    "payment.html" => array("Hoá đơn USDT (kênh cũ)", "Trang email hoá đơn cũ trỏ tới: số tiền, địa chỉ, đếm ngược, cảnh báo kênh đã đóng."),
    "document-api.html" => array("Tài liệu tích hợp API", "Khoá API kèm nút đổi khoá, ba điểm cuối đọc dữ liệu, ví dụ tạo đơn bằng JSON."),
    "recharge.html" => array("Nạp tiền qua payOS", "Số dư, số tiền trong giới hạn, ba bước thanh toán, trạng thái giao dịch."),
    "recharge-manual.html" => array("Nạp tiền thủ công", "Hướng dẫn do quản trị nhập, điền sẵn tên đăng nhập và mã khách."),
    "recharge-gtf-bank.html" => array("Kênh GachtheFast (đóng)", "Trang giải thích vì sao kênh chưa dùng được và lối sang phương thức khác."),
    "blogs.html" => array("Bài viết", "Danh sách bài viết, lọc theo chuyên mục, sắp xếp, phân trang."),
    "blog.html" => array("Một bài viết", "Nội dung do quản trị soạn trên nền sáng, kèm danh sách bài khác."),
    "faq.html" => array("Câu hỏi thường gặp", "Trang trả lời do quản trị nhập, có trạng thái trống khi chưa có nội dung."),
    "policy.html" => array("Chính sách", "Điều khoản mua hàng, bảo hành và hoàn tiền do quản trị nhập."),
    "styleguide.html" => array("Design system", "Màu, chữ, nút, biểu mẫu, thẻ KPI, bảng, phân trang, timeline, công tắc."),
    "404.html" => array("Không tìm thấy trang (404)", "Cùng khung, cùng chân trang; có đường quay lại cửa hàng."),
);

$indexHead = '<!doctype html><html lang="vi" data-theme="light"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Orbit — bản xem trước</title>'
    . '<link rel="stylesheet" href="assets/client/orbit/tokens.css"><link rel="stylesheet" href="assets/client/orbit/base.css">'
    . '<link rel="stylesheet" href="assets/client/orbit/components.css"><link rel="stylesheet" href="assets/client/orbit/shell.css">'
    . '<link rel="stylesheet" href="assets/client/orbit/store.css">'
    . '<link rel="stylesheet" href="assets/preview.css"></head><body>';
$index = $indexHead . $banner
    . '<div class="container section stack stack--lg">'
    . '<h1>Orbit — bản xem trước giao diện</h1>'
    . '<p class="lead">Mọi trang dưới đây được sinh ra từ chính mã sẽ chạy trong shop, với dữ liệu mẫu. '
    . 'Mở từng trang để xem bố cục, biểu đồ và bảng. Danh sách này được tạo từ đúng những tệp đã ghi.</p>'
    . '<div class="grid grid--2">';
foreach (array_keys($pages) as $pageFile) {
    $tile = isset($tileText[$pageFile]) ? $tileText[$pageFile] : array($pageFile, "");
    $index .= '<a class="card card--interactive preview-tile" href="' . $pageFile . '">'
        . '<h3>' . $tile[0] . '</h3>'
        . '<p class="small muted">' . $tile[1] . '</p></a>';
}
$index .= '</div>'
    . '<p class="small faint">Sinh bằng <span class="mono">php tools/preview-build.php</span>. '
    . 'Không chứa dữ liệu thật, không gọi mạng, không có trình phát nhạc nào tự chạy.</p>'
    . '</div></body></html>';
file_put_contents($outDir . "/index.html", $fix($index));
echo "wrote index.html\n";

/* Root-relative images (assets/img/icon-*.svg) are copied out of the checkout on demand, so the preview
   stays self-contained without duplicating the whole 14 MB assets folder. */
foreach (glob($outDir . "/*.html") as $page) {
    $html = (string) file_get_contents($page);
    if (preg_match_all('~(?:href|src)="(assets/[^"]+)"~i', $html, $matches)) {
        foreach (array_unique($matches[1]) as $reference) {
            $relative = preg_split('/[?#]/', $reference);
            $relative = $relative[0];
            if (strpos($relative, "assets/client/orbit/") === 0 || strpos($relative, "assets/client/fonts/") === 0) {
                continue; /* already mirrored above */
            }
            $source = $root . "/" . $relative;
            if (!is_file($source)) {
                /* BASE_URL("public/…") renders as assets/…; the mirrored file still lives in public/. */
                if (strpos($relative, "assets/") === 0) {
                    $source = $root . "/public/" . substr($relative, strlen("assets/"));
                }
            }
            if (!is_file($source)) {
                continue; /* the asset check below reports it */
            }
            $target = $outDir . "/" . $relative;
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0777, true) && !is_dir(dirname($target))) {
                fwrite(STDERR, "Cannot create " . dirname($target) . "\n");
                exit(3);
            }
            copy($source, $target);
        }
    }
}

/* The tool checks its own output: every local stylesheet/script/image the pages reference must exist on
   disk, or the preview opens broken for whoever receives it. */
$references = array();
foreach (glob($outDir . "/*.html") as $page) {
    if (preg_match_all('~(?:href|src)="([^"]+)"~i', (string) file_get_contents($page), $matches)) {
        foreach ($matches[1] as $reference) {
            /* Every asset-looking URL is checked, not only the ones that start with assets/: the
               first check only looked at assets/… and therefore blessed a page whose stylesheets
               were inert "#preview-…" anchors. */
            $path = preg_split('/[?#]/', $reference);
            $path = $path[0];
            if (!preg_match('#\.(css|js|json|png|jpe?g|svg|webp|gif|woff2?|ico)$#i', $path)) {
                continue; /* a link to a shop route, not an asset */
            }
            if (preg_match('#^(https?:)?//#i', $reference) || stripos($reference, 'data:') === 0) {
                continue; /* external/self-contained by design; the preview itself never requests it */
            }
            $references[$reference] = $path;
        }
    }
}
$missing = array();
foreach ($references as $reference => $path) {
    $local = ltrim($path, "/");
    if ($local === "" || $local[0] === "#" || !is_file($outDir . "/" . $local)) {
        $missing[] = $reference;
    }
}
if ($missing) {
    fwrite(STDERR, "asset check FAILED — " . count($missing) . " reference(s) do not resolve: " . implode(", ", $missing) . "\n");
    exit(4);
}
echo "asset check: every referenced stylesheet/script/image resolves\n";

/* And the second half of "visually appealing": every class the pages use must have a rule, or the page
   renders as unstyled HTML somewhere. The stylesheets available to the preview are the design system plus
   preview.css. */
$css = "";
foreach (glob($outDir . "/assets/client/orbit/*.css") as $sheet) {
    $css .= (string) file_get_contents($sheet);
}
$css .= (string) @file_get_contents($outDir . "/assets/preview.css");
$defined = array();
if (preg_match_all("~\\.([A-Za-z][A-Za-z0-9_-]*)~", $css, $classes)) {
    foreach ($classes[1] as $class) {
        $defined[$class] = true;
    }
}
$usedClasses = array();
foreach (glob($outDir . "/*.html") as $page) {
    $html = (string) file_get_contents($page);
    if (preg_match_all('~class="([^"]*)"~', $html, $matches)) {
        foreach ($matches[1] as $attribute) {
            foreach (preg_split("~\\s+~", trim($attribute)) as $class) {
                if ($class !== "") {
                    $usedClasses[$class] = basename($page);
                }
            }
        }
    }
}
$undefined = array();
foreach ($usedClasses as $class => $page) {
    if (!isset($defined[$class])) {
        $undefined[$class] = $page;
    }
}
if ($undefined) {
    fwrite(STDERR, "class check FAILED — " . count($undefined) . " class(es) have no rule: "
        . implode(", ", array_map(function ($class, $page) {
            return "." . $class . " (" . $page . ")";
        }, array_keys($undefined), $undefined)) . "\n");
    exit(5);
}
echo "class check: " . count($usedClasses) . " class(es) used, all defined\n";
echo "copied " . count($copied) . " asset(s): " . implode(", ", $copied) . "\n";
/* A note next to the output, so whoever receives the folder knows what it is and how to rebuild it. */
file_put_contents($outDir . "/GENERATED.txt",
    "Orbit — static UI preview (generated)\n"
    . "=====================================\n\n"
    . "Built by: php tools/preview-build.php\n"
    . "Built from the current checkout (no timestamp, so a rebuild stays byte-identical).\n\n"
    . "Contents: index.html, home.html (storefront home), sales.html (admin), purchase-history.html (buyer),\n"
    . "styleguide.html, assets/, and the review screenshots (*.png).\n"
    . "Every page is rendered from the same code the shop runs, with SAMPLE data — there is no real customer,\n"
    . "order or balance in this folder, no database behind it, and no network call made by it. Route links are\n"
    . "inert (#preview-…) on purpose. The music player is shown but never plays.\n\n"
    . "Do not edit these files: they are overwritten by the next build. Regenerate with the command above;\n"
    . "delete the folder with: php tools/preview-build.php --clean\n");
echo "Preview ready: " . $outDir . "\n";
