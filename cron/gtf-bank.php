<?php

/**
 * GachtheFast bank worker — deliberately inert.
 *
 * There is no confirmed GachtheFast bank-transfer contract, so there is nothing this worker
 * may poll, store or credit. It does not call the provider and does not touch the database;
 * it reports the blocker and exits without doing anything. If a future, reviewed
 * implementation makes GtfBankService::isAvailable() true, this file is where the worker
 * loop belongs — still gated on the crediting lock and on an explicit enablement decision.
 *
 * Access: CLI always; over HTTP only with a matching GTF_CRON_TOKEN header/parameter.
 */

define('IN_SITE', true);
require_once __DIR__ . '/../libs/gtf_bootstrap.php';
require_once __DIR__ . '/../libs/gtf_bank.php';

$config = gtf_config();
$isCli = PHP_SAPI === 'cli';
if (!gtf_worker_allowed($config)) {
    http_response_code(403);
    exit('forbidden');
}
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
}

$service = gtf_bank_service();
if (!$service->isAvailable()) {
    echo 'gtf bank worker: no-op — ' . $service->unavailableReason() . "\n";
    exit(0);
}

// Unreachable while the boundary is a stub; kept so a future implementation cannot start
// polling silently after a partial change.
echo "gtf bank worker: unavailable (no reviewed implementation)\n";
exit(1);
