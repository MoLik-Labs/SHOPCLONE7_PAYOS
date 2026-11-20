<?php

/**
 * Bootstrap for every GachtheFast entry point (client AJAX, provider callback, worker).
 * Mirrors libs/payos_bootstrap.php but stays independent of the payOS code path.
 */

if (!defined('IN_SITE')) {
    http_response_code(404);
    exit;
}
ini_set('display_errors', '0');
require_once __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../')->safeLoad();
require_once __DIR__ . '/gtf.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');

function gtf_json(array $body, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function gtf_card_service(): GtfCardService
{
    $config = gtf_config();
    if ($config['api_base'] === '') {
        throw new RuntimeException('The GachtheFast API origin is not configured.');
    }
    if ($config['card_request_format'] === '') {
        throw new RuntimeException('The GachtheFast card request format is not configured.');
    }
    return new GtfCardService(gtf_db(), new GtfCardClient($config), $config);
}

/**
 * The bank boundary is a fail-closed stub: it has no provider client and no confirmed
 * contract, so it is constructed without any transport and reports itself unavailable.
 */
function gtf_bank_service(): GtfBankService
{
    return new GtfBankService(gtf_db(), gtf_config());
}
