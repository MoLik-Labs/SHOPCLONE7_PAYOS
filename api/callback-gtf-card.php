<?php

/**
 * GachtheFast card callback.
 *
 * The documented callback signature covers only code+serial, so this endpoint never
 * credits a wallet. It records the event and asks our own verification path (an HTTPS
 * `command=check` bound to our stored request_id) to decide the outcome.
 *
 * Deliberately NOT gated by GTF_CARD_ENABLED: in-flight orders must still be resolved
 * after new submissions are switched off (see the rollout notes).
 */

define('IN_SITE', true);
require_once __DIR__ . '/../libs/gtf_bootstrap.php';
require_once __DIR__ . '/../libs/gtf_card.php';

$config = gtf_config();
if ($config['partner_key'] === '') {
    http_response_code(503);
    echo 'not_configured';
    exit;
}
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    echo 'method_not_allowed';
    exit;
}
$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$input = is_array($input) ? $input : [];
if (count($input) > 40) {
    http_response_code(413);
    echo 'too_many_fields';
    exit;
}

try {
    $result = gtf_card_service()->callback($input);
    // A concurrent/recent check may have seen an older state: request another callback rather than
    // acknowledging an outcome that has not been verified. Provider retry semantics remain unverified.
    if ($result === 'busy') {
        http_response_code(503);
        header('Retry-After: 15');
        header('Cache-Control: no-store');
        echo 'retry_later';
        exit;
    }
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $result === 'credited' ? 'payment.success' : $result;
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    echo 'callback_rejected';
} catch (Throwable $error) {
    // Non-2xx lets the provider retry. Nothing was credited.
    error_log('GachtheFast: card callback failed (' . get_class($error) . ').');
    http_response_code(503);
    echo 'retry_later';
}
