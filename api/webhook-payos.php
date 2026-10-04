<?php

define('IN_SITE', true);
require_once __DIR__ . '/../libs/payos_bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    payos_json(['code' => 'METHOD_NOT_ALLOWED'], 405);
}
$body = file_get_contents('php://input', false, null, 0, 1048577);
if ($body === false || strlen($body) > 1048576) {
    payos_json(['code' => 'INVALID_BODY'], 413);
}
try {
    $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Invalid payload.');
    }
    $result = payos_service()->webhook($payload);
    payos_json(['code' => '00', 'desc' => $result]);
} catch (JsonException $error) {
    payos_json(['code' => 'INVALID_JSON'], 400);
} catch (InvalidArgumentException $error) {
    payos_json(['code' => 'INVALID_SIGNATURE_OR_DATA'], 400);
} catch (Throwable $error) {
    // Non-2xx lets payOS retry. Never acknowledge a failed database transaction.
    error_log('payOS: webhook processing failed (' . get_class($error) . ')');
    payos_json(['code' => 'RETRY_LATER'], 503);
}
