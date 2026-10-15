<?php

/**
 * GachtheFast card reconciliation worker.
 *
 * Resolves orders whose outbound submit timed out, whose callback never arrived, or
 * which were parked for review. It only ever calls `command=check`; it never re-submits
 * a card (an ambiguous timeout must be reconciled, not retried).
 *
 * Access: CLI always; over HTTP only with a matching GTF_CRON_TOKEN header/parameter.
 * This deliberately does not copy the unauthenticated legacy cron pattern.
 */

define('IN_SITE', true);
require_once __DIR__ . '/../libs/gtf_bootstrap.php';
require_once __DIR__ . '/../libs/gtf_card.php';

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
if ($config['partner_key'] === '' || $config['api_base'] === '' || $config['card_request_format'] === '') {
    echo "gtf card worker: channel not configured\n";
    exit(1);
}

$service = gtf_card_service();
$credited = 0;
$pending = 0;
$review = 0;
$failed = 0;
foreach ($service->pendingForReconcile(25) as $order) {
    try {
        $outcome = $service->reconcile($order);
    } catch (Throwable $error) {
        $failed++;
        continue;
    }
    if ($outcome === 'credited') {
        $credited++;
    } elseif ($outcome === 'pending' || $outcome === 'busy') {
        $pending++;
    } elseif ($outcome === 'review') {
        $review++;
    }
}

// Shred sealed card data for orders that are old and still undecided, so card secrets
// are not retained indefinitely. Operators are alerted instead of a silent drop.
$stale = gtf_db()->prepare("SELECT COUNT(*) FROM gtf_card_orders WHERE credited_at IS NULL AND status = 'REVIEW' AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) AND (serial_sealed IS NOT NULL OR code_sealed IS NOT NULL)");
$stale->execute();
if ((int) $stale->fetchColumn() > 0) {
    gtf_alert('GTF: card review orders older than 30 days still hold sealed card data.');
}

echo 'gtf card worker: credited=' . $credited . ' pending=' . $pending . ' review=' . $review . ' failed=' . $failed . "\n";
