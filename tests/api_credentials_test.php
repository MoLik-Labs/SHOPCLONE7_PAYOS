<?php
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(404); exit; }
define('IN_SITE', true);
require __DIR__ . '/../libs/api_credentials.php';
$pass=0;
function api_expect($expected, $actual, $label) {
    global $pass;
    if ($expected !== $actual) { throw new RuntimeException($label . ': unexpected result'); }
    ++$pass; echo "PASS  $label\n";
}
$key = str_repeat('a', 32);
$_ENV = []; $_SERVER = []; $_GET = []; $_POST = [];
putenv('API_ALLOW_QUERY_KEYS'); putenv('API_ALLOW_LEGACY_IMPORT_GET');
api_expect(true, orbit_api_allow_query_keys(), 'missing setting preserves legacy compatibility');
api_expect(true, orbit_api_allow_legacy_import_get(), 'missing setting preserves legacy GET import');
$_GET['api_key'] = $key;
api_expect($key, orbit_api_credential(['api_key'], false), 'legacy query key remains accepted');
$_SERVER['HTTP_X_API_KEY'] = str_repeat('b', 32);
api_expect(str_repeat('b',32), orbit_api_credential(['api_key'], false), 'header takes precedence');
$_SERVER['HTTP_X_API_KEY'] = 'bad key';
api_expect('', orbit_api_credential(['api_key'], false), 'invalid header never falls back to query');
$_SERVER['HTTP_X_API_KEY'] = str_repeat('b',129);
api_expect('', orbit_api_credential(['api_key'], false), 'oversized header rejected');
$_SERVER['HTTP_X_API_KEY'] = ['key'];
api_expect('', orbit_api_credential(['api_key'], false), 'array header rejected');
unset($_SERVER['HTTP_X_API_KEY']); $_ENV['API_ALLOW_QUERY_KEYS']='false';
api_expect('', orbit_api_credential(['api_key'], false), 'disabled URL keys rejected');
$_POST['api_key'] = $key;
api_expect($key, orbit_api_credential(['api_key'], true), 'POST body key remains supported for import');
api_expect('', orbit_api_credential(['api_key'], false), 'read endpoints do not silently accept body key');
$_POST['api_key'] = ['key'];
api_expect('', orbit_api_credential(['api_key'], true), 'malformed body cannot fall back');
$_ENV['API_ALLOW_QUERY_KEYS']='typo';
api_expect(false, orbit_api_allow_query_keys(), 'invalid config fails closed');
unset($_ENV['API_ALLOW_QUERY_KEYS']); putenv('API_ALLOW_QUERY_KEYS=0');
api_expect(false, orbit_api_allow_query_keys(), 'process environment works');
putenv('API_ALLOW_QUERY_KEYS'); $_ENV['API_ALLOW_LEGACY_IMPORT_GET']='false';
api_expect(false, orbit_api_allow_legacy_import_get(), 'legacy import GET can be disabled');
$_ENV['API_ALLOW_LEGACY_IMPORT_GET']='true';
api_expect(true, orbit_api_allow_legacy_import_get(), 'legacy import GET can be enabled');
echo "api_credentials_test: $pass passed, 0 failed\n";
