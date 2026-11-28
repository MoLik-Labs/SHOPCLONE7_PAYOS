<?php
if (!defined('IN_SITE')) { exit('The Request Not Found'); }

/** Only exact, operator-configured proxy addresses may supply forwarding headers. */
function orbit_trusted_proxy_ips()
{
    $setting = $_ENV['TRUSTED_PROXY_IPS'] ?? getenv('TRUSTED_PROXY_IPS');
    return array_filter(array_map('trim', explode(',', $setting === false ? '' : (string) $setting)));
}

function orbit_is_https()
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (filter_var($remote, FILTER_VALIDATE_IP) && in_array($remote, orbit_trusted_proxy_ips(), true)
        && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && strtolower(trim((string) $_SERVER['HTTP_X_FORWARDED_PROTO'])) === 'https') {
        return true;
    }
    // A configured canonical HTTPS URL can force Secure cookies behind a TLS terminator.
    $appUrl = $_ENV['APP_URL'] ?? getenv('APP_URL');
    return stripos($appUrl === false ? '' : (string) $appUrl, 'https://') === 0;
}
