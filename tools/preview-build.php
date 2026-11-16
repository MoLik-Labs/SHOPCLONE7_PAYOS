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

/* The preview runs outside the shop's bootstrap, so it loads the same libraries the pages use. */
require_once $root . "/libs/sales_stats.php";
require_once $root . "/views/common/pagination.php";
require_once $root . "/views/common/orbit-shell.php";
require_once $root . "/views/common/chart.php";

/* --------------------------------------------------------------- rendering */

/**
 * Runs a view and returns its HTML. Each view is rendered in a clean copy of the process state.
 */
function preview_render($root, $relativeView, array $session, array $get, array $fixtures)
{
    DB::$fixtures = $fixtures;
    DB::$log = array();
    $_SESSION = $session;
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

/* 7. the styleguide (design system reference) */
$pages["styleguide.html"] = array(
    "title" => "Orbit design system — bản xem trước",
    "html" => preview_render($root, "views/common/orbit-styleguide.php", array("admin_login" => "admintok"),
        array("module" => "common", "action" => "orbit-styleguide"), $fixtures),
);

/* ---------------------------------------------------------------- assembly */

$fix = function ($html) {
    /* The stubs already emit relative asset paths; this only neutralises the demo links. */
    $html = str_replace(array("https://shop.example/", "http://shop.example/", "https://shop.example", "http://shop.example"), "#", $html);
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
/* the landing page */
$index = '<!doctype html><html lang="vi" data-theme="light"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Orbit — bản xem trước</title>'
    . '<link rel="stylesheet" href="assets/client/orbit/tokens.css"><link rel="stylesheet" href="assets/client/orbit/base.css">'
    . '<link rel="stylesheet" href="assets/client/orbit/components.css"><link rel="stylesheet" href="assets/client/orbit/shell.css">'
    . '<link rel="stylesheet" href="assets/client/orbit/store.css">'
    . '<link rel="stylesheet" href="assets/preview.css"></head><body>'
    . $banner
    . '<div class="container section stack stack--lg">'
    . '<h1>Orbit — bản xem trước giao diện</h1>'
    . '<p class="lead">Bốn trang dưới đây được sinh ra từ chính mã sẽ chạy trong shop, với dữ liệu mẫu. '
    . 'Mở từng trang để xem bố cục, biểu đồ và bảng. Trang chủ là bản dựng cần duyệt ở giai đoạn 1.</p>'
    . '<div class="grid grid--2">'
    . '<a class="card card--interactive preview-tile" href="home.html">'
    . '<h3>Trang chủ cửa hàng</h3>'
    . '<p class="small muted">Thanh điều hướng, khối giới thiệu, danh mục, lưới sản phẩm, lọc/sắp xếp/phân trang '
    . 'và chân trang — nền sáng, dữ liệu thật từ danh mục.</p></a>'
    . '<a class="card card--interactive preview-tile" href="sales.html">'
    . '<h3>Thống kê bán hàng (admin)</h3>'
    . '<p class="small muted">KPI, biểu đồ doanh thu, sản phẩm bán chạy, khách mua nhiều, '
    . 'nạp tiền theo phương thức, bảng đơn có sắp xếp và xuất CSV.</p></a>'
    . '<a class="card card--interactive preview-tile" href="purchase-history.html">'
    . '<h3>Lịch sử mua hàng (khách)</h3>'
    . '<p class="small muted">Số tiền đã chi, biểu đồ chi tiêu, tìm kiếm đơn của chính mình và phân trang.</p></a>'
    . '<a class="card card--interactive preview-tile" href="product.html">'
    . '<h3>Trang sản phẩm</h3>'
    . '<p class="small muted">Ảnh hoặc ký tự đại diện, giá và tồn kho thật, bảng mua, mô tả do quản trị '
    . 'nhập và phần API/tiếp thị khi shop bật.</p></a>'
    . '<a class="card card--interactive preview-tile" href="product-signed.html">'
    . '<h3>Trang sản phẩm (đã đăng nhập)</h3>'
    . '<p class="small muted">Số dư trong thanh trên, ô số lượng, mã giảm giá và nút thanh toán bằng số dư.</p></a>'
    . '<a class="card card--interactive preview-tile" href="login.html">'
    . '<h3>Đăng nhập & đăng ký</h3>'
    . '<p class="small muted">Biểu mẫu có nhãn, trạng thái trong dòng, nút đăng nhập mạng xã hội khi bật.</p></a>'
    . '<a class="card card--interactive preview-tile" href="account.html">'
    . '<h3>Tài khoản</h3>'
    . '<p class="small muted">Số dư, tổng đã nạp, đã sử dụng, lối tắt tới đơn hàng và biểu mẫu liên hệ.</p></a>'
    . '<a class="card card--interactive preview-tile" href="orders.html">'
    . '<h3>Lịch sử đơn hàng</h3>'
    . '<p class="small muted">Lọc theo mã, khoảng ngày; ghi chú riêng, tải .txt và xóa đơn; đơn đã xóa vẫn hiện.</p></a>'
    . '<a class="card card--interactive preview-tile" href="order.html">'
    . '<h3>Chi tiết đơn hàng</h3>'
    . '<p class="small muted">Tài khoản đã giao, UID, chép từng dòng hoặc tải cả đơn; ghi chú giao hàng của shop.</p></a>'
    . '<a class="card card--interactive preview-tile" href="styleguide.html">'
    . '<h3>Design system</h3>'
    . '<p class="small muted">Màu, chữ, nút, biểu mẫu, thẻ KPI, bảng, phân trang, timeline, '
    . 'công tắc và trình phát nhạc.</p></a>'
    . '</div>'
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
    . "Built at: " . gmdate("Y-m-d H:i") . " UTC\n\n"
    . "Contents: index.html, home.html (storefront home), sales.html (admin), purchase-history.html (buyer),\n"
    . "styleguide.html, assets/, and the review screenshots (*.png).\n"
    . "Every page is rendered from the same code the shop runs, with SAMPLE data — there is no real customer,\n"
    . "order or balance in this folder, no database behind it, and no network call made by it. Route links are\n"
    . "inert (#preview-…) on purpose. The music player is shown but never plays.\n\n"
    . "Do not edit these files: they are overwritten by the next build. Regenerate with the command above;\n"
    . "delete the folder with: php tools/preview-build.php --clean\n");
echo "Preview ready: " . $outDir . "\n";
