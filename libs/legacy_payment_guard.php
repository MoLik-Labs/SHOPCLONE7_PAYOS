<?php

if (!defined('IN_SITE')) {
    http_response_code(403);
    exit;
}

function reject_legacy_payment_when_payos_enabled()
{
    if (filter_var($_ENV['PAYOS_ENABLED'] ?? getenv('PAYOS_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN)) {
        http_response_code(410);
        exit('This payment channel has been replaced by payOS.');
    }
}

function require_active_legacy_payment($database, $statusName, $secretName)
{
    if ((string) $database->site($statusName) !== '1' || trim((string) $database->site($secretName)) === '') {
        http_response_code(403);
        exit('Payment channel is disabled or not configured.');
    }
}
