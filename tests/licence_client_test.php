<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}

/**
 * Orbit Office licence client — offline test suite.
 *
 * Runs with plain PHP 7.4, NO database and NO network: every portal call goes
 * through an injected fake transport, and every state lives in an array.
 *
 *   php tests/licence_client_test.php
 *
 * Prints one line per check and a final summary; exit code 0 = all passed.
 */

require_once __DIR__ . '/../libs/licence_client.php';

$passed = 0;
$failed = 0;
$current = '';

function check($label, $condition, $detail = '')
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   " . $label . "\n";
    } else {
        $failed++;
        echo "  FAIL " . $label . ($detail !== '' ? ' :: ' . $detail : '') . "\n";
    }
    return (bool) $condition;
}

function section($name)
{
    echo "\n[" . $name . "]\n";
}

/** Fake transport: records calls, returns a canned response. */
function fake_transport(array $responses, array &$calls, $throw = false)
{
    return function ($url, $json, $cfg) use ($responses, &$calls, $throw) {
        $calls[] = array('url' => $url, 'json' => $json, 'cfg' => $cfg);
        if ($throw) {
            throw new RuntimeException('transport blew up');
        }
        $key = isset($responses['*']) ? '*' : $url;
        if (!isset($responses[$key])) {
            return array('ok' => false, 'status' => 0, 'body' => '', 'error' => 'network:7');
        }
        $r = $responses[$key];
        $status = (int) $r['status'];
        return array(
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => json_encode($r['body']),
            'error' => '',
        );
    };
}

function active_body()
{
    return array('status' => 'active', 'token' => 'opaque.token.value', 'exp' => time() + 86400);
}

$env = array('LICENCE_PORTAL_URL' => 'https://licence.orbit.example');

/* ------------------------------------------------------------------- config */

section('configuration');

$cfg = licence_config($env);
check('an HTTPS origin is accepted', is_array($cfg) && $cfg['url'] === 'https://licence.orbit.example', json_encode($cfg));
check('the host is normalised to lower case', is_array($cfg) && $cfg['host'] === 'licence.orbit.example');
check('default read timeout is 10s', is_array($cfg) && $cfg['read_timeout'] === 10);
check('default grace window is 30 days', is_array($cfg) && $cfg['grace_days'] === 30);
check('enforcement defaults to off', is_array($cfg) && $cfg['enforce'] === false);
check('trailing slash is accepted', licence_config(array('LICENCE_PORTAL_URL' => 'https://licence.orbit.example/')) !== null);

$bad = array(
    'http://licence.orbit.example'                     => 'plain HTTP',
    'https://licence.orbit.example:8443'               => 'non-443 port',
    'https://licence.orbit.example/api'                => 'a path',
    'https://user:pass@licence.orbit.example'          => 'credentials in the URL',
    'https://licence.orbit.example/?x=1'               => 'a query string',
    'ftp://licence.orbit.example'                      => 'a non-HTTP scheme',
    'licence.orbit.example'                            => 'a bare host',
    ''                                                 => 'an empty value',
);
foreach ($bad as $value => $why) {
    check('rejects ' . $why, licence_config(array('LICENCE_PORTAL_URL' => $value)) === null, $value);
}

check('timeout is clamped to the documented range', licence_config(array('LICENCE_PORTAL_URL' => 'https://a.example', 'LICENCE_TIMEOUT_SECONDS' => '99'))['read_timeout'] === 10);
check('grace is clamped to the documented range', licence_config(array('LICENCE_PORTAL_URL' => 'https://a.example', 'LICENCE_GRACE_DAYS' => '9999'))['grace_days'] === 30);
check('enforcement can be switched on explicitly', licence_config(array('LICENCE_PORTAL_URL' => 'https://a.example', 'LICENCE_ENFORCE' => 'true'))['enforce'] === true);

/* --------------------------------------------------------------- key + hwid */

section('key handling and hardware id');

$key = 'ORBIT-4V92X-YXEJV-S0QRQ-YXF4S-73';
check('normalisation strips separators', licence_normalise_key(strtolower($key)) === licence_normalise_key($key));
check('normalisation output is alphanumeric only', preg_match('/^[0-9A-Z]+$/', licence_normalise_key($key)) === 1);
$masked = licence_mask_key($key);
check('masking hides the middle of the key', strpos($masked, 'YXEJV') === false && strpos($masked, '****') !== false, $masked);
check('masking keeps a recognisable prefix', strpos($masked, 'ORBIT-4V92X') === 0, $masked);
check('a short value is fully masked', licence_mask_key('abc') === '****');

$saltA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$saltB = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
$hwidEnv = array('LICENCE_HWID_HOST' => 'shop-a.example', 'LICENCE_HWID_PATH' => '/var/www/shop', 'LICENCE_HWID_OS' => 'Linux', 'LICENCE_HWID_ARCH' => 'x86_64', 'LICENCE_HWID_PHP' => '7.4');
$h1 = licence_hwid($saltA, $hwidEnv);
check('hwid has the expected shape', preg_match('/^hwid-[0-9a-f]{40}$/', $h1) === 1, $h1);
check('hwid is stable for the same inputs', $h1 === licence_hwid($saltA, $hwidEnv));
check('hwid changes with the salt', $h1 !== licence_hwid($saltB, $hwidEnv));
check('hwid changes with the host', $h1 !== licence_hwid($saltA, array_merge($hwidEnv, array('LICENCE_HWID_HOST' => 'shop-b.example'))));
check('generated salts are 32 hex chars and unique', preg_match('/^[0-9a-f]{32}$/', licence_install_salt()) === 1 && licence_install_salt() !== licence_install_salt());

/* ---------------------------------------------------------------- transport */

section('transport pinning and bounds');

check('a valid request domain is preserved', licence_request_domain(array('HTTP_HOST' => 'shop.example:443')) === 'shop.example:443');
check('a malformed Host falls back to SERVER_NAME', licence_request_domain(array('HTTP_HOST' => 'evil.example/path', 'SERVER_NAME' => 'shop.example')) === 'shop.example');
check('a malformed fallback is refused', licence_request_domain(array('HTTP_HOST' => "bad\r\nHost: evil", 'SERVER_NAME' => 'bad/path')) === '');
check('an absent request domain is empty', licence_request_domain(array()) === '');

$calls = array();
$t = fake_transport(array('*' => array('status' => 200, 'body' => active_body())), $calls);
$cfg = licence_config($env);
$r = licence_http_post_json('/v1/activate', array('key' => $key), $cfg, $t);
check('an allowed endpoint is sent to the configured origin', count($calls) === 1 && $calls[0]['url'] === 'https://licence.orbit.example/v1/activate', json_encode($calls[0]['url']));
check('the payload travels as JSON', json_decode($calls[0]['json'], true)['key'] === $key);

$calls = array();
$t = fake_transport(array('*' => array('status' => 200, 'body' => active_body())), $calls);
$r = licence_http_post_json('/v1/../../etc/passwd', array(), $cfg, $t);
check('an unexpected endpoint is refused before any call', $r['error'] === 'endpoint_not_allowed' && count($calls) === 0);
check('the refusal is not reported as success', $r['ok'] === false);

check('a forged config host is refused before any socket', licence_http_post_json('/v1/validate', array(), array('url' => 'https://evil.example', 'host' => 'licence.orbit.example', 'connect_timeout' => 1, 'read_timeout' => 1, 'max_response' => 100, 'grace_days' => 0, 'enforce' => false))['error'] === 'origin_mismatch');

check('a malformed body is not a response', licence_parse_body('{not json') === null);
check('an empty body is not a response', licence_parse_body('') === null);
check('an oversized body is refused', licence_parse_body(str_repeat('x', 262145)) === null);
check('a JSON array is not a response object', licence_parse_body('[1,2,3]') === null);
check('a JSON object decodes', licence_parse_body('{"status":"active"}') === array('status' => 'active'));

/* ------------------------------------------------------------------- states */

section('activation and validation');

$a = array();                       // the fake "settings table"
$store = LicenceStore::fromArray($a);
$calls = array();
$t = fake_transport(array('*' => array('status' => 200, 'body' => active_body())), $calls);

$state = licence_check($store, $cfg, $t);
check('no key means "unconfigured"', $state['state'] === LICENCE_STATE_UNCONFIGURED);
check('no key means the transport is never called', count($calls) === 0);
check('an unconfigured gate stays locked', licence_gate_allows($state) === false);

$a['license_key'] = $key;
$state = licence_activate($store, $cfg, $t);
check('activation succeeds against a 200 active response', $state['state'] === LICENCE_STATE_VALID, json_encode($state));
check('the hardware id is sent', isset(json_decode($calls[0]['json'], true)['hwid']));
check('the gate unlocks', licence_gate_allows($state) === true);
check('a cached check timestamp is stored', (int) $a['licence_last_check'] > 0);
check('the portal token is cached', $a['licence_token'] === 'opaque.token.value');
check('an install salt was generated once', strlen($a['licence_install_salt']) === 32);
check('the summary never contains the key', strpos(licence_summary($state), $key) === false && strpos(licence_summary($state), 'YXEJV') === false, licence_summary($state));

$verdicts = array(
    'unknown_key'     => array('status' => 404, 'state' => LICENCE_STATE_INVALID,      'allowed' => false),
    'invalid_key_format' => array('status' => 400, 'state' => LICENCE_STATE_INVALID,   'allowed' => false),
    'revoked'         => array('status' => 403, 'state' => LICENCE_STATE_REVOKED,      'allowed' => false),
    'expired'         => array('status' => 403, 'state' => LICENCE_STATE_REVOKED,      'allowed' => false),
    'hwid_mismatch'   => array('status' => 403, 'state' => LICENCE_STATE_MISMATCH,     'allowed' => false),
);
foreach ($verdicts as $error => $expect) {
    $a2 = array('license_key' => $key);
    $s2 = LicenceStore::fromArray($a2);
    $calls2 = array();
    $t2 = fake_transport(array('*' => array('status' => $expect['status'], 'body' => array('error' => $error))), $calls2);
    $st = licence_check($s2, $cfg, $t2);
    check('the portal verdict "' . $error . '" maps to ' . $expect['state'], $st['state'] === $expect['state'], json_encode($st));
    check('"' . $error . '" keeps the gate locked', licence_gate_allows($st) === $expect['allowed']);
}

// a 200 that is not "active" must not unlock anything
$a3 = array('license_key' => $key);
$s3 = LicenceStore::fromArray($a3);
$calls3 = array();
$t3 = fake_transport(array('*' => array('status' => 200, 'body' => array('status' => 'pending'))), $calls3);
$st = licence_check($s3, $cfg, $t3);
check('a 200 without status=active does not unlock', licence_gate_allows($st) === false, json_encode($st));
check('an unknown status is reported as an error state', $st['state'] === LICENCE_STATE_ERROR);

$calls4 = array();
$t4 = fake_transport(array('*' => array('status' => 200, 'body' => active_body())), $calls4, true);
$a4 = array('license_key' => $key);
$s4 = LicenceStore::fromArray($a4);
$threw = false;
try {
    licence_check($s4, $cfg, $t4);
} catch (RuntimeException $e) {
    $threw = true;
}
check('a thrown transport error propagates (caller keeps serving the shop)', $threw === true);

/* -------------------------------------------------------------- grace window */

section('offline grace window');

$now = time();
$graceCfg = $cfg;
$graceCfg['grace_days'] = 30;

$a5 = array(
    'license_key' => $key,
    'licence_state' => LICENCE_STATE_VALID,
    'licence_last_check' => $now - 5 * 86400,
    'licence_install_salt' => $saltA,
);
$s5 = LicenceStore::fromArray($a5);
$st = licence_cached_state($s5, $graceCfg, 'network', $now);
check('a 5-day-old success is still inside a 30-day grace window', $st['state'] === LICENCE_STATE_VALID_CACHED, json_encode($st));
check('a cached success keeps the gate open', licence_gate_allows($st) === true);
check('the remaining grace is reported', strpos($st['message'], 'day') !== false, $st['message']);

$a5['licence_last_check'] = $now - 31 * 86400;
$st = licence_cached_state(LicenceStore::fromArray($a5), $graceCfg, 'network', $now);
check('a 31-day-old success is stale', $st['state'] === LICENCE_STATE_STALE, json_encode($st));
check('a stale cache locks the gate', licence_gate_allows($st) === false);

// clock-skew defence: a timestamp in the future must not extend the grace window
$a5['licence_last_check'] = $now + 30 * 86400;
$st = licence_cached_state(LicenceStore::fromArray($a5), $graceCfg, 'network', $now);
check('a cached check dated in the future is refused', $st['state'] === LICENCE_STATE_STALE, json_encode($st));
check('a future-dated cache keeps the gate closed', licence_gate_allows($st) === false);

$a5['licence_last_check'] = $now + 60;                 // a minute of clock skew is normal
$st = licence_cached_state(LicenceStore::fromArray($a5), $graceCfg, 'network', $now);
check('a minute of clock skew is tolerated', $st['state'] === LICENCE_STATE_VALID_CACHED, json_encode($st));
check('the reported remaining grace never goes negative', strpos($st['message'], '-') === false, $st['message']);

$a5['licence_last_check'] = $now - 86400;
$noGrace = $graceCfg;
$noGrace['grace_days'] = 0;
$st = licence_cached_state(LicenceStore::fromArray($a5), $noGrace, 'network', $now);
check('grace_days=0 disables offline use entirely', $st['state'] === LICENCE_STATE_STALE);

$a6 = array('license_key' => $key, 'licence_state' => LICENCE_STATE_VALID, 'licence_install_salt' => $saltA);
$st = licence_cached_state(LicenceStore::fromArray($a6), $graceCfg, 'network', $now);
check('no stored check time means unreachable', $st['state'] === LICENCE_STATE_UNREACHABLE);

$a7 = array('license_key' => $key, 'licence_state' => LICENCE_STATE_REVOKED, 'licence_last_check' => $now - 60, 'licence_install_salt' => $saltA);
$st = licence_cached_state(LicenceStore::fromArray($a7), $graceCfg, 'network', $now);
check('a revoked state is never resurrected by the cache', $st['state'] === LICENCE_STATE_UNREACHABLE && licence_gate_allows($st) === false);

// end-to-end: activate online, then lose the portal
$a8 = array('license_key' => $key);
$s8 = LicenceStore::fromArray($a8);
$calls8 = array();
$ok = fake_transport(array('*' => array('status' => 200, 'body' => active_body())), $calls8);
$st = licence_activate($s8, $cfg, $ok);
check('activation stores a valid state', $st['state'] === LICENCE_STATE_VALID);
$calls9 = array();
$nobody = fake_transport(array('*' => array('status' => 0, 'body' => array())), $calls9);
$st = licence_check($s8, $cfg, $nobody);
check('a later outage falls back to the cached success', $st['state'] === LICENCE_STATE_VALID_CACHED, json_encode($st));
check('the fallback is marked as not online', $st['online'] === false);
check('the fallback keeps the gate open', licence_gate_allows($st) === true);

// and a revoked verdict overrides the cache immediately
$calls10 = array();
$revoked = fake_transport(array('*' => array('status' => 403, 'body' => array('error' => 'revoked'))), $calls10);
$st = licence_check($s8, $cfg, $revoked);
check('a revoked verdict overrides the cached success', $st['state'] === LICENCE_STATE_REVOKED && licence_gate_allows($st) === false);

/* ------------------------------------------------------------- store contract */

section('store contract');

$data = array();
$s = LicenceStore::fromArray($data);
$s->set('licence_state', 'valid');
check('the store round-trips a value', $s->get('licence_state') === 'valid');
check('a missing value returns the default', $s->get('nope', 'fallback') === 'fallback');
$s->forget('licence_state');
check('forget empties a value', $s->get('licence_state', '') === '');
check('the store never stores the licence key itself', !array_key_exists('licence_token', $data));

echo "\nlicence_client_test: " . $passed . " passed, " . $failed . " failed\n";
exit($failed === 0 ? 0 : 1);
