<?php
/*
 * Measure one rendered page against the real database.
 *
 *   php tools/perf-probe.php home
 *   php tools/perf-probe.php product netflix-1-thang
 *   php tools/perf-probe.php blogs
 *
 * Prints one JSON line: page, SQL statements, settings bulk loads, HTML bytes, milliseconds,
 * peak memory. Run it before and after a change on staging; timings are noisy, the query count is not.
 *
 * Read-only: only the views in $pages are reachable, and every one of them is a GET page. This tool
 * never writes, never calls a provider and refuses to run outside the CLI.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$pages = array(
    "home" => array("views/client/home.php", array("module" => "client", "action" => "home")),
    "product" => array("views/client/product.php", array("module" => "client", "action" => "product")),
    "blogs" => array("views/client/blogs.php", array("module" => "client", "action" => "blogs")),
    "blog" => array("views/client/blog.php", array("module" => "client", "action" => "blog")),
    "faq" => array("views/client/faq.php", array("module" => "client", "action" => "faq")),
    "policy" => array("views/client/policy.php", array("module" => "client", "action" => "policy")),
);

$name = isset($argv[1]) ? (string) $argv[1] : "home";
if (!isset($pages[$name])) {
    fwrite(STDERR, "Unknown page. Use: " . implode(", ", array_keys($pages)) . "\n");
    exit(2);
}
$view = $pages[$name][0];
$get = $pages[$name][1];
if ($name === "product" || $name === "blog") {
    if (empty($argv[2])) {
        fwrite(STDERR, "This page needs a slug: php tools/perf-probe.php " . $name . " <slug>\n");
        exit(2);
    }
    $get["slug"] = (string) $argv[2];
}

define("IN_SITE", true);
require_once $root . "/libs/db.php";
require_once $root . "/config.php";
require_once $root . "/libs/lang.php";
require_once $root . "/libs/helper.php";
require_once $root . "/libs/database/users.php";

/* The views build absolute URLs from the request; give the CLI a host and a path. */
$appUrl = getenv("APP_URL") ?: "http://localhost";
$parts = parse_url($appUrl);
$_SERVER["HTTP_HOST"] = isset($parts["host"]) ? $parts["host"] : "localhost";
if (!empty($parts["port"])) {
    $_SERVER["HTTP_HOST"] .= ":" . $parts["port"];
}
$_SERVER["HTTPS"] = (isset($parts["scheme"]) && $parts["scheme"] === "https") ? "on" : "off";
$_SERVER["REQUEST_URI"] = "/" . $name;
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["SERVER_NAME"] = $_SERVER["HTTP_HOST"];
$_GET = $get;
$_POST = array();
$_COOKIE = array();
$_REQUEST = $get;

$CMSNT = new DB();
DB::reset_stats();
$started = microtime(true);
ob_start();
require $root . "/" . $view;
$html = ob_get_clean();
$elapsed = (microtime(true) - $started) * 1000;

echo json_encode(array(
    "page" => $name,
    "queries" => DB::$queries,
    "errors" => DB::$errors,
    "settings_loads" => $CMSNT->settings_loads(),
    "bytes" => strlen($html),
    "ms" => round($elapsed, 1),
    "peak_mb" => round(memory_get_peak_usage(true) / 1048576, 1),
), JSON_UNESCAPED_SLASHES) . "\n";
