<?php

/**
 * MoLik Studio — shop-side licence client.
 *
 * Talks to the licence portal (tools/license-portal) over HTTPS, binds this
 * installation to a hardware fingerprint, and caches the result so a sleeping
 * or unreachable portal cannot take the storefront down.
 *
 * Rules this file deliberately follows:
 *   - the storefront NEVER depends on the licence: only the admin features that
 *     already include models/is_license.php consult it;
 *   - no key, token or portal response ever reaches a log line;
 *   - the portal URL is configured explicitly (HTTPS, port 443, no path) and is
 *     never taken from user input;
 *   - the transport is injectable, so every test runs with a fake and no network;
 *   - nothing here writes to the payment tables or touches payOS/GachtheFast.
 *
 * This library is additive: it does not replace checkLicenseKey() or the CMSNT
 * checks, and loading it changes no behaviour by itself.
 */

/* ------------------------------------------------------------------ request identity */

/**
 * A syntactically valid domain string for the portal.
 *
 * HTTP_HOST is client-controlled. Reject malformed values containing slashes, query strings,
 * whitespace or userinfo and fall back to SERVER_NAME. This is not a host allowlist: deployments
 * must configure their web server to accept only their own domains.
 */
function licence_request_domain(array $server = null)
{
    if ($server === null) {
        $server = $_SERVER;
    }
    $candidate = isset($server['HTTP_HOST']) ? (string) $server['HTTP_HOST'] : '';
    if ($candidate === '' || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?(?::[0-9]{1,5})?$/D', $candidate) !== 1) {
        $candidate = isset($server['SERVER_NAME']) ? (string) $server['SERVER_NAME'] : '';
    }
    if ($candidate === '' || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?(?::[0-9]{1,5})?$/D', $candidate) !== 1) {
        return '';
    }
    return $candidate;
}

/* ------------------------------------------------------------------ states */

const LICENCE_STATE_UNCONFIGURED = 'unconfigured';   // no key entered yet
const LICENCE_STATE_VALID        = 'valid';          // portal confirmed, just now
const LICENCE_STATE_VALID_CACHED = 'valid_cached';   // portal unreachable, still inside the grace window
const LICENCE_STATE_INVALID      = 'invalid';        // portal says the key is unknown
const LICENCE_STATE_REVOKED      = 'revoked';        // portal revoked or the key expired
const LICENCE_STATE_MISMATCH     = 'hwid_mismatch';  // bound to another machine: reset it in the portal
const LICENCE_STATE_UNREACHABLE  = 'unreachable';    // no answer and nothing cached
const LICENCE_STATE_STALE        = 'stale';          // cached success older than the grace window
const LICENCE_STATE_ERROR        = 'error';          // malformed response, misconfiguration

/**
 * Layered environment lookup.
 *
 * Deliberately does NOT call getenv() with no arguments: in some SAPIs (and in
 * the WASM CLI used by the tests) that returns only the base environment and
 * silently ignores request-scoped variables. Each name is looked up on its own,
 * in this order: explicit array, $_ENV, getenv($name), $_SERVER.
 */
function licence_env_value($name, $default = '', array $env = null)
{
    if ($env !== null) {
        if (array_key_exists($name, $env)) {
            $v = $env[$name];
            return ($v === false || $v === null || $v === '') ? $default : (string) $v;
        }
        return $default;
    }
    // getenv() first: it is what the .env loader and the rest of this codebase use,
    // and it reflects runtime overrides (putenv) that $_ENV does not.
    $v = getenv($name);
    if ($v !== false && $v !== null && $v !== '') {
        return (string) $v;
    }
    if (array_key_exists($name, $_ENV)) {
        $v = $_ENV[$name];
        if ($v !== false && $v !== null && $v !== '') {
            return (string) $v;
        }
    }
    if (isset($_SERVER[$name]) && $_SERVER[$name] !== '') {
        return (string) $_SERVER[$name];
    }
    return $default;
}

/**
 * Read the client configuration. Returns null (fail closed) when the portal URL
 * is missing or unsafe — the caller then treats the licence as not configured.
 *
 * @param array|null $env  Optional environment override (tests).
 * @return array|null
 */
function licence_config(array $env = null)
{
    $value = function ($name, $default = '') use ($env) {
        return licence_env_value($name, $default, $env);
    };

    $raw = trim($value('LICENCE_PORTAL_URL', ''));
    if ($raw === '') {
        return null;
    }

    $parts = parse_url($raw);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }
    if (strtolower($parts['scheme']) !== 'https') {
        return null;                                   // never plain HTTP
    }
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        return null;                                   // no credentials, no query, no fragment
    }
    if (isset($parts['port']) && (int) $parts['port'] !== 443) {
        return null;                                   // the client pins 443
    }
    $path = isset($parts['path']) ? $parts['path'] : '';
    if ($path !== '' && $path !== '/') {
        return null;                                   // origin only
    }

    $timeout = (int) $value('LICENCE_TIMEOUT_SECONDS', '10');
    if ($timeout < 3 || $timeout > 30) {
        $timeout = 10;
    }
    $grace = (int) $value('LICENCE_GRACE_DAYS', '30');
    if ($grace < 0 || $grace > 365) {
        $grace = 30;
    }

    return array(
        'url'             => 'https://' . $parts['host'],
        'host'            => strtolower($parts['host']),
        'connect_timeout' => 5,
        'read_timeout'    => $timeout,
        'grace_days'      => $grace,
        'max_response'    => 65536,
        'enforce'         => licence_bool($value('LICENCE_ENFORCE', 'false')),
    );
}

/** Parse a permissive boolean env value. */
function licence_bool($value)
{
    return in_array(strtolower(trim((string) $value)), array('1', 'true', 'yes', 'on'), true);
}

/** Uppercase, remove separators: ORBIT-xxxx-... and orbit xxxx are the same key. */
function licence_normalise_key($key)
{
    return preg_replace('/[^0-9A-Z]/', '', strtoupper((string) $key));
}

/** Safe display form: never shows the middle of the key. */
function licence_mask_key($key)
{
    $n = licence_normalise_key($key);
    if (strlen($n) < 12) {
        return $n === '' ? '' : '****';
    }
    return 'ORBIT-' . substr($n, 5, 5) . '-****-****-' . substr($n, -2);
}

/**
 * Hardware fingerprint: stable for one installation, different on another host.
 * The salt is stored with the shop configuration and never leaves the server.
 */
function licence_hwid($salt, array $env = null)
{
    $pick = function ($name, $fallback = '') use ($env) {
        return licence_env_value($name, $fallback, $env);
    };
    $parts = array(
        'host' => $pick('LICENCE_HWID_HOST', php_uname('n')),
        'os'   => $pick('LICENCE_HWID_OS', PHP_OS),
        'arch' => $pick('LICENCE_HWID_ARCH', php_uname('m')),
        'php'  => $pick('LICENCE_HWID_PHP', PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION),
        'path' => $pick('LICENCE_HWID_PATH', __DIR__),
        'salt' => (string) $salt,
    );
    return 'hwid-' . substr(hash('sha256', implode('|', $parts)), 0, 40);
}

/** Fresh install salt (32 hex chars). Store it once and keep it. */
function licence_install_salt()
{
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes(16));
    }
    return bin2hex(openssl_random_pseudo_bytes(16));
}

/* ----------------------------------------------------------------- transport */

/**
 * POST JSON to the portal.
 *
 * @param string        $endpoint  '/v1/activate' or '/v1/validate'
 * @param array         $payload
 * @param array         $cfg       licence_config() result
 * @param callable|null $transport Optional fake: function ($url, $json, $cfg) => array
 *                                 with keys ok/status/body/error. Used by tests; when
 *                                 provided, no network call happens at all.
 * @return array {ok:bool, status:int, body:string, error:string}
 */
function licence_http_post_json($endpoint, array $payload, array $cfg, callable $transport = null)
{
    if (!in_array($endpoint, array('/v1/activate', '/v1/validate'), true)) {
        return array('ok' => false, 'status' => 0, 'body' => '', 'error' => 'endpoint_not_allowed');
    }
    $url = $cfg['url'] . $endpoint;
    $json = json_encode($payload);
    if ($json === false) {
        return array('ok' => false, 'status' => 0, 'body' => '', 'error' => 'encode_failed');
    }

    if ($transport !== null) {
        return $transport($url, $json, $cfg);
    }

    // Defence in depth: re-check the origin before any socket is opened.
    $parts = parse_url($url);
    if ($parts === false || strtolower($parts['host']) !== $cfg['host'] || strtolower($parts['scheme']) !== 'https') {
        return array('ok' => false, 'status' => 0, 'body' => '', 'error' => 'origin_mismatch');
    }
    if (isset($parts['port']) && (int) $parts['port'] !== 443) {
        return array('ok' => false, 'status' => 0, 'body' => '', 'error' => 'port_not_allowed');
    }
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl_missing');
    }

    $body = '';
    $tooBig = false;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json', 'Accept: application/json'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,               // a redirect is never followed
        CURLOPT_SSL_VERIFYPEER => true,                // certificate must validate
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => (int) $cfg['connect_timeout'],
        CURLOPT_TIMEOUT        => (int) $cfg['read_timeout'],
        CURLOPT_USERAGENT      => 'OrbitOffice-LicenceClient/1.0',
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body, &$tooBig, $cfg) {
            $body .= $chunk;
            if (strlen($body) > (int) $cfg['max_response']) {
                $tooBig = true;
                return 0;                              // abort: the response is bounded
            }
            return strlen($chunk);
        },
    ));
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($tooBig) {
        return array('ok' => false, 'status' => $status, 'body' => '', 'error' => 'response_too_large');
    }
    if ($ok === false || $status === 0) {
        return array('ok' => false, 'status' => 0, 'body' => '', 'error' => 'network:' . $errno . ($error !== '' ? ':' . $error : ''));
    }
    return array('ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string) $body, 'error' => '');
}

/** Decode a portal response body; returns null when it is not a JSON object. */
function licence_parse_body($body)
{
    if (!is_string($body) || $body === '' || strlen($body) > 262144) {
        return null;
    }
    // The contract is a JSON *object*; a list or a scalar is a malformed response.
    if (substr(ltrim($body), 0, 1) !== '{') {
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/* --------------------------------------------------------------------- store */

/**
 * Persistence for the cached licence state. In the shop this is backed by the
 * settings table; in tests by an array. Only string scalars are ever stored.
 */
class LicenceStore
{
    private $getter;
    private $setter;

    public function __construct(callable $getter, callable $setter)
    {
        $this->getter = $getter;
        $this->setter = $setter;
    }

    public function get($name, $default = '')
    {
        $value = call_user_func($this->getter, $name);
        return $value === null || $value === false ? $default : (string) $value;
    }

    public function set($name, $value)
    {
        call_user_func($this->setter, $name, (string) $value);
    }

    public function forget($name)
    {
        call_user_func($this->setter, $name, '');
    }

    /** Store backed by a plain array — used by the CLI tool and the tests. */
    public static function fromArray(array &$data)
    {
        return new self(
            function ($name) use (&$data) {
                return isset($data[$name]) ? $data[$name] : null;
            },
            function ($name, $value) use (&$data) {
                $data[$name] = $value;
            }
        );
    }
}

/* -------------------------------------------------------------------- client */

/** The licence key currently entered in the shop (settings table: license_key). */
function licence_current_key(LicenceStore $store)
{
    return trim($store->get('license_key', ''));
}

function licence_now()
{
    return time();
}

/**
 * Ask the portal to activate/validate. Returns a state array:
 *   state, message, http_status, checked_at, expires_at, cache (token), online(bool)
 */
function licence_request(LicenceStore $store, array $cfg, $mode, callable $transport = null)
{
    $key = licence_current_key($store);
    $now = licence_now();

    if ($key === '') {
        return licence_result(LICENCE_STATE_UNCONFIGURED, 'No licence key has been entered yet.', 0, $now);
    }

    $salt = $store->get('licence_install_salt', '');
    if ($salt === '') {
        $salt = licence_install_salt();
        $store->set('licence_install_salt', $salt);
    }
    $hwid = licence_hwid($salt);

    $payload = array(
        'key'   => $key,
        'hwid'  => $hwid,
        'domain' => licence_request_domain(),
        'php'   => PHP_VERSION,
        'version' => '1.0.0',
    );

    $response = licence_http_post_json($mode === 'activate' ? '/v1/activate' : '/v1/validate', $payload, $cfg, $transport);
    $data = licence_parse_body($response['body']);

    if ($response['status'] === 200 && is_array($data) && isset($data['status']) && $data['status'] === 'active') {
        $store->set('licence_last_check', (string) $now);
        $store->set('licence_state', LICENCE_STATE_VALID);
        if (isset($data['token'])) {
            $store->set('licence_token', (string) $data['token']);   // opaque: verified by the portal
        }
        if (isset($data['exp']) && (int) $data['exp'] > 0) {
            $store->set('licence_expires', (string) (int) $data['exp']);
        }
        return licence_result(LICENCE_STATE_VALID, 'Licence active.', 200, $now, true);
    }

    // The portal answered: its verdict is authoritative.
    if ($response['status'] > 0) {
        $error = is_array($data) && isset($data['error']) ? (string) $data['error'] : 'unexpected_response';
        switch ($error) {
            case 'unknown_key':
            case 'invalid_key_format':
            case 'invalid_request':
                $state = LICENCE_STATE_INVALID;
                $message = 'This licence key was not recognised. Check it, or contact your licence provider.';
                break;
            case 'revoked':
                $state = LICENCE_STATE_REVOKED;
                $message = 'This licence has been revoked. Contact your licence provider.';
                break;
            case 'expired':
                $state = LICENCE_STATE_REVOKED;
                $message = 'This licence has expired. Contact your licence provider to renew it.';
                break;
            case 'hwid_mismatch':
                $state = LICENCE_STATE_MISMATCH;
                $message = 'This licence is bound to another machine. Sign in to the licence portal and use Reset hardware ID.';
                break;
            default:
                $state = LICENCE_STATE_ERROR;
                $message = 'The licence portal returned an unexpected response.';
        }
        $store->set('licence_state', $state);
        return licence_result($state, $message, (int) $response['status'], $now, true);
    }

    // No answer: fall back to the cached success inside the grace window.
    return licence_cached_state($store, $cfg, $response['error'], $now);
}

/** Evaluate the cached state when the portal cannot be reached. */
function licence_cached_state(LicenceStore $store, array $cfg, $reason, $now = null)
{
    $now = $now === null ? licence_now() : $now;
    $last = (int) $store->get('licence_last_check', '0');
    $state = $store->get('licence_state', '');
    $graceSeconds = (int) $cfg['grace_days'] * 86400;

    if ($last <= 0 || $state !== LICENCE_STATE_VALID) {
        return licence_result(LICENCE_STATE_UNREACHABLE, 'The licence portal could not be reached and no valid check is cached.', 0, $now);
    }
    // A cached timestamp in the future means the clock moved backwards (or the stored
    // row was edited): the grace window must not grow, so fail closed instead of
    // granting extra offline days. A few minutes of skew are tolerated.
    if ($last > $now + 300) {
        return licence_result(LICENCE_STATE_STALE, 'The cached licence check is dated in the future; refusing to extend the grace window.', 0, $now);
    }
    if ($graceSeconds === 0 || ($now - $last) > $graceSeconds) {
        return licence_result(LICENCE_STATE_STALE, 'The licence portal could not be reached and the cached check has expired.', 0, $now);
    }
    $days = max(0, (int) floor(($graceSeconds - ($now - $last)) / 86400));
    return licence_result(
        LICENCE_STATE_VALID_CACHED,
        'The licence portal could not be reached; running on the cached check for another ' . $days . ' day(s).',
        0,
        $now,
        false,
        $reason
    );
}

function licence_result($state, $message, $httpStatus = 0, $checkedAt = 0, $online = false, $error = '')
{
    return array(
        'state'       => $state,
        'message'     => $message,
        'http_status' => (int) $httpStatus,
        'checked_at'  => (int) $checkedAt,
        'online'      => (bool) $online,
        'error'       => (string) $error,
    );
}

/** Force an activation (used when a key is first entered). */
function licence_activate(LicenceStore $store, array $cfg, callable $transport = null)
{
    return licence_request($store, $cfg, 'activate', $transport);
}

/** Routine check (cron / admin page load). */
function licence_check(LicenceStore $store, array $cfg, callable $transport = null)
{
    return licence_request($store, $cfg, 'validate', $transport);
}

/**
 * Should the admin features that include models/is_license.php unlock?
 * Deliberately conservative: unknown states stay locked.
 */
function licence_gate_allows(array $state)
{
    if (!is_array($state) || !isset($state['state'])) {
        return false;
    }
    return in_array($state['state'], array(LICENCE_STATE_VALID, LICENCE_STATE_VALID_CACHED), true);
}

/**
 * Human-readable summary for the admin notice. Never contains the key itself.
 */
function licence_summary(array $state)
{
    $labels = array(
        LICENCE_STATE_VALID        => 'Active',
        LICENCE_STATE_VALID_CACHED => 'Active (cached)',
        LICENCE_STATE_UNCONFIGURED => 'Not configured',
        LICENCE_STATE_INVALID      => 'Invalid key',
        LICENCE_STATE_REVOKED      => 'Revoked / expired',
        LICENCE_STATE_MISMATCH     => 'Wrong machine',
        LICENCE_STATE_UNREACHABLE  => 'Portal unreachable',
        LICENCE_STATE_STALE        => 'Cached check expired',
        LICENCE_STATE_ERROR        => 'Unexpected response',
    );
    $label = isset($labels[$state['state']]) ? $labels[$state['state']] : 'Unknown';
    return $label . ' — ' . $state['message'];
}
