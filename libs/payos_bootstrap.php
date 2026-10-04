<?php

if (!defined('IN_SITE')) {
    http_response_code(404);
    exit;
}
ini_set('display_errors', '0');
require_once __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../')->safeLoad();
require_once __DIR__ . '/payos.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');

function payos_json(array $body, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
