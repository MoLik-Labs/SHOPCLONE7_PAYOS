<?php
/*
 * Cron endpoints are not public endpoints.
 *
 * cron/*.php poll bank APIs, sync supplier balances and stock, run warehouse automations and drain
 * the mail queue. Every one of them was reachable by anyone who guessed the path (the admin pages
 * even print the URL), which let a stranger spend the shop's supplier quota and rate limits. They now
 * require CLI, or an HTTP request carrying CRON_TOKEN.
 */

define('IN_SITE', true);

class DB
{
    public function __construct() {}
    public function connect() { return true; }
    public function dis_connect() { return true; }
    public function site($name) { return $name === 'timezone' ? 'Asia/Ho_Chi_Minh' : ''; }
    public function get_row($sql) { return false; }
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
if (!function_exists('__')) {
    function __($text) { return $text; }
}
require __DIR__ . '/../libs/helper.php';

$failures = 0;
$checks = 0;
function check_cron($name, $condition)
{
    global $failures, $checks;
    ++$checks;
    if ($condition) {
        echo "PASS  " . $name . "\n";
        return;
    }
    ++$failures;
    echo "FAIL  " . $name . "\n";
}
function cron_env($token)
{
    if ($token === null) {
        putenv('CRON_TOKEN');
        unset($_ENV['CRON_TOKEN']);
    } else {
        putenv('CRON_TOKEN=' . $token);
        $_ENV['CRON_TOKEN'] = $token;
    }
    unset($_SERVER['HTTP_X_CRON_TOKEN'], $_GET['token']);
}

/* ---- the decision --------------------------------------------------------- */
cron_env(null);
check_cron('CLI is always allowed', orbit_cron_allowed('cli') === true);
check_cron('HTTP is refused when no token is configured', orbit_cron_allowed('apache2handler') === false);
check_cron('HTTP is refused when the token is empty', orbit_cron_allowed('fpm-fcgi') === false);

cron_env('short');
check_cron('a short token is treated as unconfigured', orbit_cron_allowed('fpm-fcgi') === false);

cron_env('0123456789abcdef0123456789abcdef');
check_cron('a configured token refuses an anonymous request', orbit_cron_allowed('fpm-fcgi') === false);

$_SERVER['HTTP_X_CRON_TOKEN'] = '0123456789abcdef0123456789abcdef';
check_cron('the X-Cron-Token header is accepted', orbit_cron_allowed('fpm-fcgi') === true);

$_SERVER['HTTP_X_CRON_TOKEN'] = '0123456789abcdef0123456789abcdeX';
check_cron('a wrong header token is refused', orbit_cron_allowed('fpm-fcgi') === false);
unset($_SERVER['HTTP_X_CRON_TOKEN']);

$_GET['token'] = '0123456789abcdef0123456789abcdef';
check_cron('a matching ?token= is accepted', orbit_cron_allowed('apache2handler') === true);
$_GET['token'] = 'nope';
check_cron('a wrong ?token= is refused', orbit_cron_allowed('apache2handler') === false);
unset($_GET['token']);

check_cron('the header wins over an empty query string', orbit_cron_allowed('cli') === true);
cron_env(null);

/* ---- wiring --------------------------------------------------------------- */
$cron = array();
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../cron', FilesystemIterator::SKIP_DOTS)) as $file) {
    if (substr($file->getFilename(), -4) === '.php') {
        $cron[] = $file->getPathname();
    }
}
$unguarded = array();
$workerGuarded = 0;
foreach ($cron as $file) {
    $source = file_get_contents($file);
    $name = basename($file);
    if ($name === 'gtf-card.php' || $name === 'gtf-bank.php') {
        if (strpos($source, 'gtf_worker_allowed(') !== false) {
            ++$workerGuarded;
        }
        continue;
    }
    if (strpos($source, 'orbit_cron_allowed()') === false) {
        $unguarded[] = $name;
    }
}
check_cron('every legacy cron file calls the guard' . ($unguarded ? ' (missing: ' . implode(', ', $unguarded) . ')' : ''), $unguarded === array());
check_cron('both GTF workers keep their own token guard', $workerGuarded === 2);
check_cron('the guard is used before the cron work starts', strpos(file_get_contents(__DIR__ . '/../cron/bank.php'), 'orbit_cron_allowed()') < strpos(file_get_contents(__DIR__ . '/../cron/bank.php'), 'api.sieuthicode.net'));
check_cron('the documentation tells operators to set CRON_TOKEN', strpos(file_get_contents(__DIR__ . '/../.env.example'), 'CRON_TOKEN') !== false);

echo "\n" . $checks . " checks, " . $failures . " failed\n";
exit($failures === 0 ? 0 : 1);
