<?php
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(404); exit; }
define('IN_SITE', true);
class DB { public function site($name) { return $name === 'timezone' ? 'UTC' : ''; } public function get_row($sql) { return false; } }
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require __DIR__ . '/../libs/helper.php';
$checks = 0;
function expect_origin($expected, $actual, $name) {
    global $checks; ++$checks;
    if ($expected !== $actual) { throw new RuntimeException($name . ': got ' . var_export($actual, true)); }
    echo "PASS  $name\n";
}
$_SERVER['HTTP_HOST'] = 'attacker.example'; $_SERVER['SERVER_NAME'] = 'attacker.example'; $_SERVER['REQUEST_URI'] = '/client/login?x=1';
$_ENV['APP_URL'] = 'https://shop.example:8443/';
expect_origin('https://shop.example:8443', orbit_canonical_origin(), 'accept canonical HTTPS origin and optional port');
expect_origin('https://shop.example:8443/?action=reset', base_url('?action=reset'), 'base URL ignores hostile Host');
expect_origin('https://shop.example:8443/client/login?x=1', url(), 'current URL ignores hostile Host');
$_ENV['APP_URL'] = 'https://[::1]:8443';
expect_origin('https://[::1]:8443', orbit_canonical_origin(), 'accept bracketed IPv6');
foreach (['', 'http://shop.example', 'https://user:pass@shop.example', 'https://shop.example/path', 'https://shop.example?x=1',
          'https://shop.example#fragment', "https://shop.example\n.attacker.example", 'https://a..example', 'https://-bad.example',
          'https://shop.example:0', 'https://shop.example:99999', 'https://shop.example\\@attacker.example'] as $value) {
    $_ENV['APP_URL'] = $value;
    expect_origin('', orbit_canonical_origin(), 'refuse invalid origin ' . json_encode($value));
}
$_ENV['APP_URL'] = 'not a URL';
expect_origin('http://localhost', orbit_request_origin(), 'invalid configured URL cannot fall back to attacker host');
unset($_ENV['APP_URL']); putenv('APP_URL=https://process.example');
expect_origin('https://process.example', orbit_canonical_origin(), 'environment-only configuration is supported');
expect_origin('https://process.example/path', base_url('path'), 'environment-only origin wins over Host');
putenv('APP_URL');
$_SERVER['HTTP_HOST'] = 'shop.example'; unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT']);
expect_origin('http://shop.example', orbit_request_origin(), 'legacy non-sensitive pages still work when no origin is configured');
echo "canonical_origin_test: $checks passed, 0 failed\n";
