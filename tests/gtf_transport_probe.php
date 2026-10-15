<?php

/**
 * GachtheFast transport probe — exercises the REAL GtfCurlTransport/GtfCardClient against an
 * ISOLATED, loopback-only HTTPS endpoint. This is the only test that opens a socket, and it
 * must never point at a production provider.
 *
 * Gated, exactly like the database suite:
 *   GTF_PROBE_BASE  — required, e.g. https://127.0.0.1   (nothing runs without it)
 *   GTF_PROBE_CA    — optional PEM used as the trust anchor for the disposable endpoint
 *   GTF_PROBE_EXPECT_TLS_FAILURE=1 — negative mode: the endpoint's certificate must be
 *                                     REJECTED, proving TLS verification is not disabled
 *
 * The endpoints answer according to the `request_id` sent in the request body, so the probe
 * needs no control channel:
 *   req-echo 200 echo of method/path/format/body   req-ok 200 {"status":"1"}
 *   req-bad  200 non-JSON                          req-empty 200 empty body
 *   req-json-array 200 [1,2,3]                     req-big 200 >1 MiB body
 *   req-302  302 + valid JSON at the target        req-500 500   req-404 404
 *
 * STATUS: the sandbox run of 2026-10-04 executed this probe against a local endpoint; the
 * endpoint itself is not part of the repository, so a run without GTF_PROBE_BASE skips.
 */

if (!defined('IN_SITE')) {
    define('IN_SITE', true);
}
error_reporting(E_ALL & ~E_DEPRECATED);

$gtfProbeBase = trim((string) getenv('GTF_PROBE_BASE'));
if ($gtfProbeBase === '') {
    fwrite(STDERR, "SKIP: GTF_PROBE_BASE is not set; no socket was opened.\n");
    exit(0);
}
if (!extension_loaded('curl')) {
    fwrite(STDERR, "SKIP: the curl extension is required for the transport probe.\n");
    exit(0);
}

$gtfProbeCa = trim((string) getenv('GTF_PROBE_CA'));
if ($gtfProbeCa !== '' && file_exists($gtfProbeCa)) {
    // Trust anchor for the disposable local endpoint only. The transport itself never sets
    // CURLOPT_CAINFO and never disables verification; this only tells libcurl which test CA
    // to trust for the loopback certificate.
    putenv('CURL_CA_BUNDLE=' . $gtfProbeCa);
    putenv('SSL_CERT_FILE=' . $gtfProbeCa);
}
$gtfExpectTlsFailure = getenv('GTF_PROBE_EXPECT_TLS_FAILURE') === '1';

require_once dirname(__DIR__) . '/libs/gtf.php';
require_once dirname(__DIR__) . '/libs/gtf_client.php';

$GLOBALS['gtf_probe_pass'] = 0;
$GLOBALS['gtf_probe_fail'] = 0;

function probe_check($label, $condition, $detail = '')
{
    if ($condition) {
        $GLOBALS['gtf_probe_pass']++;
        echo "  ok   {$label}\n";
        return;
    }
    $GLOBALS['gtf_probe_fail']++;
    fwrite(STDERR, "  FAIL {$label}" . ($detail !== '' ? " ({$detail})" : '') . "\n");
}

function probe_client(array $overrides = [])
{
    $config = array_merge([
        'api_base' => trim((string) getenv('GTF_PROBE_BASE')),
        'card_request_format' => 'json',
        'partner_id' => 'probe-partner',
        'partner_key' => 'probe-key',
        'timeout' => 10,
    ], $overrides);
    return new GtfCardClient($config);
}

function probe_call(GtfCardClient $client, $requestId)
{
    try {
        return ['ok' => true, 'value' => $client->check('VIETTEL', '123456789', 'SERI123456', '100000', (string) $requestId)];
    } catch (Throwable $error) {
        return ['ok' => false, 'error' => get_class($error), 'message' => $error->getMessage()];
    }
}

echo "transport probe against {$gtfProbeBase}\n";

// --- negative mode: the endpoint's certificate must be rejected -------------------------
if ($gtfExpectTlsFailure) {
    echo "\n== TLS verification must reject an untrusted certificate\n";
    $result = probe_call(probe_client(), 'req-ok');
    probe_check('an untrusted certificate is refused', $result['ok'] === false);
    if ($result['ok'] === false) {
        probe_check('the failure is a transport error, not a parsed provider answer', stripos($result['message'], 'transport error') !== false, $result['message']);
        probe_check('the failure message carries no response body', stripos($result['message'], 'req-ok') === false && stripos($result['message'], 'status') === false, $result['message']);
    }
    echo "\ngtf_transport_probe (tls-negative): {$GLOBALS['gtf_probe_pass']} passed, {$GLOBALS['gtf_probe_fail']} failed\n";
    exit($GLOBALS['gtf_probe_fail'] === 0 ? 0 : 1);
}

// --- the wire format actually sent ------------------------------------------------------
echo "\n== what the transport puts on the wire (json + form)\n";
$echo = probe_call(probe_client(), 'req-echo');
probe_check('the request round-trips', $echo['ok'] === true, $echo['ok'] ? '' : $echo['message']);
if ($echo['ok']) {
    $seen = $echo['value'];
    probe_check('the documented path is used', ($seen['echo_path'] ?? '') === '/chargingws/v2', (string) ($seen['echo_path'] ?? ''));
    probe_check('the request is a POST', ($seen['echo_method'] ?? '') === 'POST');
    probe_check('json format sends application/json', stripos((string) ($seen['echo_content_type'] ?? ''), 'application/json') !== false, (string) ($seen['echo_content_type'] ?? ''));
    $body = json_decode((string) ($seen['echo_body'] ?? ''), true);
    probe_check('the body is the documented JSON field set', is_array($body) && array_keys($body) === ['telco', 'code', 'serial', 'amount', 'request_id', 'partner_id', 'sign', 'command'], is_string($seen['echo_body'] ?? null) ? substr((string) $seen['echo_body'], 0, 120) : '');
    probe_check('command=check on the wire', is_array($body) && ($body['command'] ?? '') === 'check');
    probe_check('the signature on the wire is md5(partner_key+code+serial)', is_array($body) && ($body['sign'] ?? '') === md5('probe-key' . '123456789' . 'SERI123456'));
    $seenHeaders = [];
    foreach ((array) ($seen['echo_headers'] ?? []) as $headerName => $headerValue) {
        $seenHeaders[strtolower((string) $headerName)] = (string) $headerValue;
    }
    probe_check('the client identifies itself', stripos($seenHeaders['user-agent'] ?? '', 'SHOPCLONE7-GTF') !== false, $seenHeaders['user-agent'] ?? '(absent)');
}

$formEcho = probe_call(probe_client(['card_request_format' => 'form']), 'req-echo');
probe_check('the form format round-trips', $formEcho['ok'] === true, $formEcho['ok'] ? '' : $formEcho['message']);
if ($formEcho['ok']) {
    probe_check('form format sends x-www-form-urlencoded', stripos((string) ($formEcho['value']['echo_content_type'] ?? ''), 'x-www-form-urlencoded') !== false, (string) ($formEcho['value']['echo_content_type'] ?? ''));
    probe_check('the form body carries the documented fields', strpos((string) ($formEcho['value']['echo_body'] ?? ''), 'command=check') !== false && strpos((string) ($formEcho['value']['echo_body'] ?? ''), 'partner_id=probe-partner') !== false);
}

// --- response handling --------------------------------------------------------------------
echo "\n== response handling\n";
$ok = probe_call(probe_client(), 'req-ok');
probe_check('a 200 with a JSON object is returned to the caller', $ok['ok'] === true && ($ok['value']['status'] ?? null) === '1', $ok['ok'] ? '' : $ok['message']);

$bad = probe_call(probe_client(), 'req-bad');
probe_check('malformed JSON is refused', $bad['ok'] === false && stripos($bad['message'], 'unreadable response') !== false, $bad['ok'] ? 'accepted' : $bad['message']);

$empty = probe_call(probe_client(), 'req-empty');
probe_check('an empty body is refused', $empty['ok'] === false && stripos($empty['message'], 'unreadable response') !== false, $empty['ok'] ? 'accepted' : $empty['message']);

$big = probe_call(probe_client(), 'req-big');
probe_check('an oversized response is refused', $big['ok'] === false && stripos($big['message'], 'exceeded the allowed size') !== false, $big['ok'] ? 'accepted' : $big['message']);

$redirect = probe_call(probe_client(), 'req-302');
probe_check('a redirect is NOT followed', $redirect['ok'] === false && stripos($redirect['message'], 'HTTP status 302') !== false, $redirect['ok'] ? 'followed: ' . json_encode($redirect['value']) : $redirect['message']);

$serverError = probe_call(probe_client(), 'req-500');
probe_check('a 500 is refused', $serverError['ok'] === false && stripos($serverError['message'], 'HTTP status 500') !== false, $serverError['ok'] ? 'accepted' : $serverError['message']);

$notFound = probe_call(probe_client(), 'req-404');
probe_check('a 404 is refused', $notFound['ok'] === false && stripos($notFound['message'], 'HTTP status 404') !== false, $notFound['ok'] ? 'accepted' : $notFound['message']);

$jsonArray = probe_call(probe_client(), 'req-json-array');
if ($jsonArray['ok'] && $jsonArray['value'] === [1, 2, 3]) {
    echo "  NOTE a JSON list is accepted by the transport; the status normaliser sees no status\n";
    echo "       and treats the outcome as unknown (no credit). Recorded as GAP-1, not a failure.\n";
} else {
    probe_check('a JSON list is either refused or passed through unchanged', $jsonArray['ok'] === false, $jsonArray['ok'] ? json_encode($jsonArray['value']) : '');
}

echo "\ngtf_transport_probe: {$GLOBALS['gtf_probe_pass']} passed, {$GLOBALS['gtf_probe_fail']} failed\n";
exit($GLOBALS['gtf_probe_fail'] === 0 ? 0 : 1);
