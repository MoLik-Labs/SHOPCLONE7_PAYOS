<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}
/*
 * Every script and stylesheet the shop loads comes from this origin.
 *
 * The admin pages used to pull clipboard.js, chart.js, simple-notify, flatpickr, DataTables,
 * CodeMirror, a Google font and the Google Translate widget straight from public CDNs. On a page
 * where the owner is signed in, a third-party script can read the DOM — orders, customer names,
 * payment keys — and on the admin login it can read the password as it is typed. A CDN outage in
 * Vietnam also blanked the admin UI.
 *
 * These assets now live under public/common/vendor (licences next to them). Two families of remote
 * requests are legitimate and allowed:
 *
 *   - payment SDKs (PayPal, Squad) that must talk to their own origin by design;
 *   - Google reCAPTCHA / Analytics, which the owner switches on explicitly in settings.
 *
 * Everything else must be local. This suite fails when a new <script src="https://…"> or
 * <link href="https://…"> appears in views/.
 */

$root = dirname(__DIR__);
$failures = 0;
$checks = 0;

function check_assets($name, $condition, $detail = '')
{
    global $failures, $checks;
    ++$checks;
    if ($condition) {
        echo "PASS  " . $name . "\n";
        return;
    }
    ++$failures;
    echo "FAIL  " . $name . ($detail !== '' ? "  (" . $detail . ")" : '') . "\n";
}

function asset_files($dir)
{
    $out = array();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && substr($file->getFilename(), -4) === '.php') {
            $out[] = $file->getPathname();
        }
    }
    sort($out);
    return $out;
}

/*
 * Third-party origins a <script>/<link> may point at, with the reason. Keep this list short and
 * explicit: a wildcard would let the next CDN slip back in unnoticed.
 */
$allowed = array(
    'www.google.com/recaptcha/' => 'reCAPTCHA, owner opt-in',
    'www.googletagmanager.com/gtag/' => 'Analytics, owner opt-in',
    'checkout.squadco.com/widget/' => 'Squad payment widget',
    'www.paypal.com/sdk/js' => 'PayPal SDK',
);

$offenders = array();
$seenHosts = array();
foreach (asset_files($root . '/views') as $path) {
    $source = file_get_contents($path);
    /*
     * Only things the browser actually fetches: a src="…" attribute (script, image, frame) and a
     * <link href="…"> (stylesheet, preconnect). A plain <a href> is a link a person clicks, not a
     * request this page makes, so help-centre and Zalo links are not counted here.
     */
    $found = array();
    preg_match_all('/\bsrc=\\\\?"(https?:\/\/[^"\\\\\s>]+)/', $source, $srcMatches);
    $found = array_merge($found, $srcMatches[1]);
    preg_match_all('/<link\\b[^>]{0,200}?href=\\\\?"(https?:\/\/[^"\\\\\s>]+)/', $source, $linkMatches);
    $found = array_merge($found, $linkMatches[1]);
    foreach ($found as $url) {
        $host = preg_replace('#^https?://#', '', $url);
        foreach ($allowed as $prefix => $why) {
            if (strpos($host, $prefix) === 0) {
                $seenHosts[$prefix] = true;
                continue 2;
            }
        }
        $offenders[] = str_replace($root . '/', '', $path) . ' -> ' . $url;
    }
}

check_assets('no unapproved third-party script/style tag in views/', count($offenders) === 0, implode('; ', array_slice($offenders, 0, 6)));
foreach ($offenders as $line) {
    echo "      " . $line . "\n";
}
check_assets('the payment SDKs still load from their own origins', isset($seenHosts['www.paypal.com/sdk/js']) && isset($seenHosts['checkout.squadco.com/widget/']));

foreach (array('cdnjs.cloudflare.com', 'cdn.jsdelivr.net', 'cdn.datatables.net', 'fonts.googleapis.com', 'fonts.gstatic.com') as $retired) {
    $hits = 0;
    foreach (asset_files($root . '/views') as $path) {
        if (preg_match('/\b(?:src|href)=\\\\?"https?:\/\/[^"\\\\\s>]*' . preg_quote($retired, '/') . '/', file_get_contents($path))) {
            ++$hits;
        }
    }
    check_assets('retired host is not loaded: ' . $retired, $hits === 0, $hits . ' file(s)');
}

/* The vendored files themselves. Size guards catch an empty or truncated copy. */
$vendors = array(
    'public/common/clipboard.js' => array('ClipboardJS', 1000),
    'public/common/vendor/chartjs/chart.umd.min.js' => array('Chart', 100000),
    'public/common/vendor/simple-notify/simple-notify.min.js' => array('Notify', 5000),
    'public/common/vendor/simple-notify/simple-notify.css' => array('.sn-notifications-container', 2000),
    'public/common/vendor/flatpickr/flatpickr.min.js' => array('flatpickr', 30000),
    'public/common/vendor/flatpickr/flatpickr.min.css' => array('.flatpickr', 8000),
    'public/common/vendor/datatables/js/jquery.dataTables.min.js' => array('DataTable', 50000),
    'public/common/vendor/datatables/js/dataTables.bootstrap5.min.js' => array('DataTable', 4000),
    'public/common/vendor/datatables/js/dataTables.responsive.min.js' => array('responsive', 5000),
    'public/common/vendor/datatables/css/dataTables.bootstrap5.min.css' => array('.dataTable', 5000),
    'public/common/vendor/codemirror/lib/codemirror.min.js' => array('CodeMirror', 100000),
    'public/common/vendor/fonts/roboto-latin-wght-normal.woff2' => array('', 20000),
    'public/common/vendor/fonts/roboto-vietnamese-wght-normal.woff2' => array('', 8000),
    'public/common/vendor/fonts/roboto.css' => array('@font-face', 500),
);
foreach ($vendors as $rel => $spec) {
    $path = $root . '/' . $rel;
    $exists = is_file($path);
    $size = $exists ? filesize($path) : 0;
    $ok = $exists && $size >= $spec[1] && ($spec[0] === '' || strpos(file_get_contents($path), $spec[0]) !== false);
    check_assets('vendored asset is present and complete: ' . $rel, $ok, $size . ' bytes');
}

$licences = array(
    'public/common/vendor/chartjs/LICENSE.md',
    'public/common/vendor/simple-notify/LICENSE',
    'public/common/vendor/flatpickr/LICENSE.md',
    'public/common/vendor/datatables/LICENSE-datatables.txt',
    'public/common/vendor/datatables/LICENSE-responsive.txt',
    'public/common/vendor/codemirror/LICENSE',
    'public/common/vendor/fonts/LICENSE-roboto.txt',
);
foreach ($licences as $rel) {
    check_assets('licence shipped with vendored code: ' . $rel, is_file($root . '/' . $rel), '');
}

/* The admin login page shows a password field: it must not carry a copy helper at all. */
$login = file_get_contents($root . '/views/admin/login.php');
check_assets('admin login loads no clipboard helper', strpos($login, 'clipboard') === false && strpos($login, 'ClipboardJS') === false);

/* The pages that ask for a copy button still get the local implementation, and no other page pays
   for a script it never calls. */
$copyPages = array();
$deadLoads = array();
foreach (asset_files($root . '/views/admin') as $path) {
    $source = file_get_contents($path);
    $uses = strpos($source, 'new ClipboardJS(') !== false;
    $loads = strpos($source, 'public/common/clipboard.js') !== false;
    if ($uses) {
        $copyPages[] = $path;
    }
    if ($loads && !$uses) {
        $deadLoads[] = basename($path);
    }
}
check_assets('admin pages with copy buttons are found', count($copyPages) >= 6, count($copyPages) . ' page(s)');
$missing = array();
foreach ($copyPages as $path) {
    if (strpos(file_get_contents($path), 'public/common/clipboard.js') === false) {
        $missing[] = basename($path);
    }
}
check_assets('every copy button page loads the local helper', count($missing) === 0, implode(', ', $missing));
check_assets('no admin page loads the helper without using it', count($deadLoads) === 0, implode(', ', $deadLoads));

$clipboard = file_get_contents($root . '/public/common/clipboard.js');
check_assets('local clipboard helper supports data-clipboard-target', strpos($clipboard, 'data-clipboard-target') !== false);
check_assets('local clipboard helper falls back without navigator.clipboard', strpos($clipboard, 'execCommand') !== false && strpos($clipboard, 'navigator.clipboard') !== false);

/* Every asset a view asks BASE_URL() for must exist on disk. Two families of pages used to point at
   files that were never in the checkout (AdminLTE's bs-custom-file-input, a DataTables plugin bundle
   under public/theme/assets/js/plugins), so the browser logged a 404 and inline JS threw. */
$missingAssets = array();
foreach (asset_files($root . '/views') as $path) {
    $source = file_get_contents($path);
    $refs = array();
    if (preg_match_all('/base_url\(\s*"([^"]+)"\s*\)/i', $source, $m)) {
        $refs = array_merge($refs, $m[1]);
    }
    if (preg_match_all('/base_url\(\s*"([^"]+\/)"\s*\)\s*\.\s*"([^"]+)"/i', $source, $j)) {
        foreach ($j[1] as $i => $dir) {
            $refs[] = $dir . $j[2][$i];
        }
    }
    foreach ($refs as $rel) {
        $rel = rtrim(preg_replace('/\?.*$/', '', $rel), '\\');
        if (strpos($rel, 'public/') !== 0 || substr($rel, -1) === '/') {
            continue;
        }
        if (!is_file($root . '/' . $rel)) {
            $missingAssets[] = str_replace($root . '/', '', $path) . ' -> ' . $rel;
        }
    }
}
check_assets('every asset a view references exists on disk', count($missingAssets) === 0, implode('; ', array_slice($missingAssets, 0, 6)));
foreach ($missingAssets as $line) {
    echo "      " . $line . "\n";
}

/* Admin typography: the Google Fonts request was replaced by two woff2 subsets. */
$header = file_get_contents($root . '/views/admin/header.php');
check_assets('admin header loads self-hosted Roboto', strpos($header, 'public/common/vendor/fonts/roboto.css') !== false);
check_assets('admin header no longer preconnects to Google Fonts', strpos($header, 'fonts.googleapis.com') === false && strpos($header, 'fonts.gstatic.com') === false);
$roboto = file_get_contents($root . '/public/common/vendor/fonts/roboto.css');
check_assets('Roboto css declares a variable weight range', strpos($roboto, 'font-weight: 100 900') !== false);
check_assets('Roboto css covers Vietnamese', strpos($roboto, 'U+1EA0-1EF9') !== false);

/* Google Translate stays available, but only as the snippet the owner configured. */
$footer = file_get_contents($root . '/views/admin/footer.php');
check_assets('admin footer gates the translate widget on the language setting', strpos($footer, 'language_type') !== false);
check_assets('admin footer has no hardcoded translate CDN', strpos($footer, '<script src=\\"https://cdn.gtranslate.net') === false);

echo "\n" . $checks . " checks, " . $failures . " failure(s)\n";
exit($failures === 0 ? 0 : 1);
