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
            return "assets/" . $clean;
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

    ob_start();
    include $root . "/" . $relativeView;
    return ob_get_clean();
}

$period = sales_period(30);
$fixtures = preview_fixtures();

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

/* 3. the styleguide (design system reference) */
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
foreach (array("tokens.css", "base.css", "components.css", "shell.css", "chart.css", "music-player.css",
               "chart.js", "table.js", "music-player.js") as $asset) {
    $source = $root . "/public/client/orbit/" . $asset;
    if (is_file($source) && copy($source, $assetsDir . "/" . $asset)) {
        $copied[] = $asset;
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
$index = '<!doctype html><html lang="vi" data-theme="dark"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Orbit — bản xem trước</title>'
    . '<link rel="stylesheet" href="assets/client/orbit/tokens.css"><link rel="stylesheet" href="assets/client/orbit/base.css">'
    . '<link rel="stylesheet" href="assets/client/orbit/components.css"><link rel="stylesheet" href="assets/client/orbit/shell.css">'
    . '<link rel="stylesheet" href="assets/preview.css"></head><body>'
    . $banner
    . '<div class="container section stack stack--lg">'
    . '<h1>Orbit — bản xem trước giao diện</h1>'
    . '<p class="lead">Ba trang dưới đây được sinh ra từ chính mã sẽ chạy trong shop, với dữ liệu mẫu. '
    . 'Mở từng trang để xem bố cục, biểu đồ và bảng.</p>'
    . '<div class="grid grid--3">'
    . '<a class="card card--interactive" href="sales.html" style="padding:var(--space-5);text-decoration:none">'
    . '<h3 style="margin-bottom:var(--space-2)">Thống kê bán hàng (admin)</h3>'
    . '<p class="small muted" style="margin:0">KPI, biểu đồ doanh thu, sản phẩm bán chạy, khách mua nhiều, '
    . 'nạp tiền theo phương thức, bảng đơn có sắp xếp và xuất CSV.</p></a>'
    . '<a class="card card--interactive" href="purchase-history.html" style="padding:var(--space-5);text-decoration:none">'
    . '<h3 style="margin-bottom:var(--space-2)">Lịch sử mua hàng (khách)</h3>'
    . '<p class="small muted" style="margin:0">Số tiền đã chi, biểu đồ chi tiêu, tìm kiếm đơn của chính mình '
    . 'và phân trang.</p></a>'
    . '<a class="card card--interactive" href="styleguide.html" style="padding:var(--space-5);text-decoration:none">'
    . '<h3 style="margin-bottom:var(--space-2)">Design system</h3>'
    . '<p class="small muted" style="margin:0">Màu, chữ, nút, biểu mẫu, thẻ KPI, bảng, phân trang, '
    . 'timeline, công tắc và trình phát nhạc.</p></a>'
    . '</div>'
    . '<p class="small faint">Sinh bằng <span class="mono">php tools/preview-build.php</span>. '
    . 'Không chứa dữ liệu thật, không gọi mạng, không có trình phát nhạc nào tự chạy.</p>'
    . '</div></body></html>';
file_put_contents($outDir . "/index.html", $fix($index));
echo "wrote index.html\n";

/* The tool checks its own output: every local stylesheet/script/image the pages reference must exist on
   disk, or the preview opens broken for whoever receives it. */
$references = array();
foreach (glob($outDir . "/*.html") as $page) {
    if (preg_match_all('~(?:href|src)="(assets/[^"?#]+)"~i', (string) file_get_contents($page), $matches)) {
        foreach ($matches[1] as $reference) {
            $references[$reference] = true;
        }
    }
}
$missing = array();
foreach (array_keys($references) as $reference) {
    if (!preg_match('#\.(css|js|json|png|jpe?g|svg|webp|gif|woff2?|ico)$#i', $reference)) {
        continue; /* a link to a shop route, not an asset */
    }
    if (!is_file($outDir . "/" . $reference)) {
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
    . "Contents: index.html, sales.html (admin), purchase-history.html (buyer), styleguide.html, assets/.\n"
    . "Every page is rendered from the same code the shop runs, with SAMPLE data — there is no real customer,\n"
    . "order or balance in this folder, no database behind it, and no network call made by it. Route links are\n"
    . "inert (#preview-…) on purpose. The music player is shown but never plays.\n\n"
    . "Do not edit these files: they are overwritten by the next build. Regenerate with the command above;\n"
    . "delete the folder with: php tools/preview-build.php --clean\n");
echo "Preview ready: " . $outDir . "\n";
