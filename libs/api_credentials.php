<?php
if (!defined('IN_SITE')) { exit('The Request Not Found'); }

/** Missing settings preserve existing integrations; explicit unknown values fail closed. */
function orbit_api_legacy_setting($name)
{
    $value = $_ENV[$name] ?? getenv($name);
    if ($value === false || $value === null) { return true; }
    if (!is_string($value) && !is_numeric($value)) { return false; }
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}
function orbit_api_allow_query_keys() { return orbit_api_legacy_setting('API_ALLOW_QUERY_KEYS'); }
function orbit_api_allow_legacy_import_get() { return orbit_api_legacy_setting('API_ALLOW_LEGACY_IMPORT_GET'); }

/** Header always wins, including when malformed. Never fall back to a URL key after a bad header. */
function orbit_api_credential($names, $allowPost)
{
    if (array_key_exists('HTTP_X_API_KEY', $_SERVER)) {
        $key = $_SERVER['HTTP_X_API_KEY'];
        return is_string($key) && preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $key) === 1 ? $key : '';
    }
    if ($allowPost) {
        foreach ($names as $name) {
            if (array_key_exists($name, $_POST)) {
                $key = $_POST[$name];
                return is_string($key) && $key !== '' && strlen($key) <= 512 ? $key : '';
            }
        }
    }
    if (!orbit_api_allow_query_keys()) { return ''; }
    foreach ($names as $name) {
        if (isset($_GET[$name]) && $_GET[$name] !== '') {
            $key = $_GET[$name];
            return is_string($key) && strlen($key) <= 512 ? $key : '';
        }
    }
    return '';
}

/** API data may contain balances or delivered goods: prevent browser/proxy caching. */
function orbit_api_no_store()
{
    if (!headers_sent()) {
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
}
