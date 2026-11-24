<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}
/*
 * Baseline response headers.
 *
 * The storefront was served with no security headers at all: browsers could sniff a mislabelled
 * upload as HTML, the checkout could be framed, and full URLs (with tokens) leaked to third-party
 * sites through the Referer header. This is a source-level check because PHP's CLI SAPI discards
 * header() calls, so the effect cannot be observed from the test harness — only the wiring can.
 */

$root = dirname(__DIR__);
$helper = (string) file_get_contents($root . '/libs/helper.php');
$checks = 0;
$failures = 0;
function check_headers($name, $condition, $detail = '')
{
    global $checks, $failures;
    ++$checks;
    if ($condition) {
        echo "PASS  " . $name . "\n";
        return;
    }
    ++$failures;
    echo "FAIL  " . $name . ($detail !== '' ? "  (" . $detail . ")" : '') . "\n";
}

/* Current links share the canonical-or-legacy origin resolver. */
$urlStart = strpos($helper, 'function url()');
$urlBody = $urlStart === false ? '' : substr($helper, $urlStart, strpos($helper, 'function is_valid_domain_name', $urlStart) - $urlStart);
check_headers('the current URL uses the shared origin resolver',
    strpos($urlBody, 'orbit_request_origin()') !== false && strpos($urlBody, 'REQUEST_SCHEME') === false);
check_headers('the current URL is escaped in the legacy canonical tag',
    strpos((string) file_get_contents($root . '/views/client/header.php'), "htmlspecialchars(url(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')") !== false);

check_headers('absolute links reject a host header that is not a bare host',
    strpos($helper, "'/^[A-Za-z0-9.\\-]+(:[0-9]{1,5})?$/'") !== false);
check_headers('absolute links no longer point at a developer checkout path',
    strpos($helper, 'CMSNT.CO/SHOPCLONE7_ENCRYPTION') === false);
$db = (string) file_get_contents($root . '/libs/db.php');
check_headers('the early session and CSRF cookie setup shares the HTTPS decision',
    strpos($db, 'require_once __DIR__ . "/request_security.php";') !== false
    && strpos($db, '$orbitHttps = orbit_is_https();') !== false
    && strpos($db, 'HTTP_X_FORWARDED_PROTO') === false);

$start = strpos($helper, 'function orbit_security_headers()');
$end = strpos($helper, 'function checkPermission', $start);
$body = $start === false ? '' : substr($helper, $start, $end - $start);

check_headers('the helper exists', $body !== '');
check_headers('a page that already sent output does not trigger a warning',
    strpos($body, 'if(headers_sent()) {') !== false);
check_headers('uploads cannot be sniffed as another type',
    strpos($body, 'X-Content-Type-Options: nosniff') !== false);
check_headers('the storefront cannot be framed by another site',
    strpos($body, 'X-Frame-Options: SAMEORIGIN') !== false);
check_headers('tokens in URLs are not forwarded cross-origin',
    strpos($body, 'Referrer-Policy: strict-origin-when-cross-origin') !== false);
check_headers('device permissions are denied by default',
    strpos($body, 'Permissions-Policy: geolocation=(), microphone=(), camera=()') !== false);

foreach (array('views/client/header.php', 'views/admin/header.php') as $file) {
    $view = (string) file_get_contents($root . '/' . $file);
    $call = strpos($view, 'orbit_security_headers();');
    $echo = strpos($view, 'echo ');
    check_headers($file . ' sends the headers before any output',
        $call !== false && $echo !== false && $call < $echo);
    check_headers($file . ' tolerates a controller that does not load helper.php',
        strpos($view, 'function_exists("orbit_security_headers")') !== false);
}

check_headers('no script policy is claimed before it is actually enforced',
    strpos($helper, 'Content-Security-Policy') === false);

echo "\n" . $checks . " checks, " . $failures . " failure(s)\n";
exit($failures === 0 ? 0 : 1);
