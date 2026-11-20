<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}

// Run each legacy entry point in a separate PHP process with payOS enabled.
if (isset($argv[1]) && $argv[1] === '--probe') {
    error_reporting(E_ALL & ~E_DEPRECATED);
    $_ENV['PAYOS_ENABLED'] = 'true';
    if (isset($argv[3])) {
        $_POST['action'] = $argv[3];
    }
    register_shutdown_function(function () {
        echo "\nHTTP_STATUS=" . http_response_code() . "\n";
    });
    require dirname(__DIR__) . '/' . $argv[2];
    exit;
}

$endpoints = [
    ['api/callback_card.php'], ['api/callback_crypto.php'],
    ['api/callback_squadco.php'], ['api/callback_flutterwave.php'],
    ['api/callback-perfectmoney.php'], ['api/callback_toyyibpay.php'],
    ['api/webhook_web2m.php'], ['cron/bank.php'],
    ['cron/bankWeb2m.php'], ['cron/momo.php'],
    ['ajaxs/client/create.php', 'nap_the'],
    ['ajaxs/client/create.php', 'CreateInvoiceCrypto'],
    ['ajaxs/client/create.php', 'CreateToyyibpay'],
    ['ajaxs/client/update.php', 'confirmPaypal'],
];
foreach ($endpoints as $endpoint) {
    $process = proc_open(array_merge([PHP_BINARY, __FILE__, '--probe'], $endpoint), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot launch legacy payment probe.');
    }
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || strpos($output, 'HTTP_STATUS=410') === false || $errors !== '') {
        throw new RuntimeException('Legacy endpoint did not stop before DB/API activity: ' . implode(' ', $endpoint) . ' ' . $errors);
    }
}
echo "14 legacy payment entry points/actions stop with HTTP 410 when payOS is enabled.\n";
