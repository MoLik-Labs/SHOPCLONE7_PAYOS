<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}

/**
 * Offline tests for the GachtheFast integration — pure logic, request building, validation
 * and status normalisation. No network, no database, no vendor/ dependency, no credentials.
 *
 * Every origin/credential here is synthetic ("api.example.test" / "test-*"): the production
 * API origin is NOT confirmed by the provider documentation, so no real host appears in this
 * file and none may be assumed by the client.
 *
 * Run: php tests/gtf_rules_test.php
 *
 * STATUS: NOT RUN in the sandbox this was written in — there is no PHP runtime there. This is
 * NOT a passing test suite until it has actually been executed.
 */

if (!defined('IN_SITE')) {
    define('IN_SITE', true);
}

error_reporting(E_ALL & ~E_DEPRECATED);
$_ENV['SECRET_KEY'] = 'unit-test-secret-key';
$_ENV['GTF_API_BASE'] = 'https://api.example.test';
$_ENV['GTF_CARD_REQUEST_FORMAT'] = 'json';
$_ENV['GTF_PARTNER_ID'] = 'test-partner';
$_ENV['GTF_PARTNER_KEY'] = 'test-key';
$_ENV['GTF_ALLOW_CREDITING'] = 'false'; // the shipped default, pinned so the run is deterministic

require_once dirname(__DIR__) . '/libs/gtf.php';
require_once dirname(__DIR__) . '/libs/gtf_client.php';
require_once dirname(__DIR__) . '/libs/gtf_bank.php';

$GLOBALS['gtf_pass'] = 0;
$GLOBALS['gtf_fail'] = 0;

function check($label, $condition)
{
    if ($condition) {
        $GLOBALS['gtf_pass']++;
        return;
    }
    $GLOBALS['gtf_fail']++;
    fwrite(STDERR, "FAIL: {$label}\n");
}

function checkSame($label, $expected, $actual)
{
    check($label . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')', $expected === $actual);
}

function checkThrows($label, callable $callback)
{
    $threw = false;
    try {
        $callback();
    } catch (Throwable $error) {
        $threw = true;
    }
    check($label, $threw);
}

function section($title)
{
    echo "\n== {$title}\n";
}

/** Deterministic double for GtfHttpTransport: records the request, returns a canned response. */
final class FakeTransport implements GtfHttpTransport
{
    public $calls = [];
    public $response = ['status' => '99'];
    public $error = '';

    public function post(string $path, array $fields, string $format): array
    {
        $this->calls[] = ['path' => $path, 'fields' => $fields, 'format' => $format];
        if ($this->error !== '') {
            throw new RuntimeException($this->error);
        }
        return $this->response;
    }
}

function gtfTestConfig(array $overrides = []): array
{
    return array_merge([
        'api_base' => 'https://api.example.test',
        'card_request_format' => 'json',
        'partner_id' => 'test-partner',
        'partner_key' => 'test-key',
        'timeout' => 10,
        'credit_enabled' => false,
    ], $overrides);
}

// --- signatures -------------------------------------------------------------
section('signature');
checkSame('signature is md5(partner_key + code + serial)', md5('KEY' . '123456789' . 'SERI123'), GtfCardClient::sign('KEY', '123456789', 'SERI123'));
checkSame('signature is order sensitive', false, md5('KEY' . 'SERI123' . '123456789') === GtfCardClient::sign('KEY', '123456789', 'SERI123'));
checkSame('a different partner key changes the signature', true, GtfCardClient::sign('KEY-A', '123456789', 'SERI123') !== GtfCardClient::sign('KEY-B', '123456789', 'SERI123'));

// --- documented request building -------------------------------------------
section('documented request building (fake transport)');
$transport = new FakeTransport();
$client = new GtfCardClient(gtfTestConfig(), $transport);
$transport->response = ['status' => '99'];
$client->submit('viettel', '100000', '123456789', 'SERI123', 'ABCDEF1700000000');
checkSame('exactly one call is made', 1, count($transport->calls));
$call = $transport->calls[0];
checkSame('the documented path is used', '/chargingws/v2', $call['path']);
checkSame('the configured format is forwarded', 'json', $call['format']);
checkSame('the field set matches the documentation exactly', ['telco', 'code', 'serial', 'amount', 'request_id', 'partner_id', 'sign', 'command'], array_keys($call['fields']));
checkSame('command=charging', 'charging', $call['fields']['command']);
checkSame('telco is upper-cased', 'VIETTEL', $call['fields']['telco']);
checkSame('amount is forwarded as a string', '100000', $call['fields']['amount']);
checkSame('request_id is forwarded verbatim', 'ABCDEF1700000000', $call['fields']['request_id']);
checkSame('partner_id is forwarded verbatim', 'test-partner', $call['fields']['partner_id']);
checkSame('sign uses md5(partner_key + code + serial)', md5('test-key' . '123456789' . 'SERI123'), $call['fields']['sign']);

$transport->calls = [];
$client->check('VIETTEL', '123456789', 'SERI123', '100000', 'ABCDEF1700000000');
$call = $transport->calls[0];
checkSame('check uses the same path', '/chargingws/v2', $call['path']);
checkSame('command=check', 'check', $call['fields']['command']);
checkSame('check sends the full documented picture', ['telco', 'code', 'serial', 'amount', 'request_id', 'partner_id', 'sign', 'command'], array_keys($call['fields']));
checkSame('check reuses the documented signature', md5('test-key' . '123456789' . 'SERI123'), $call['fields']['sign']);

$transport->calls = [];
$formClient = new GtfCardClient(gtfTestConfig(['card_request_format' => 'form']), $transport);
$formClient->submit('VIETTEL', '100000', '123456789', 'SERI123', 'ABCDEF1700000000');
checkSame('the explicitly configured format is used for form', 'form', $transport->calls[0]['format']);

section('transport failures are propagated, never swallowed');
$transport = new FakeTransport();
$transport->error = 'Cannot reach the GachtheFast API (transport error 28).';
$client = new GtfCardClient(gtfTestConfig(), $transport);
checkThrows('a transport failure throws instead of returning a fake status', function () use ($client) {
    $client->submit('VIETTEL', '100000', '123456789', 'SERI123', 'ABCDEF1700000000');
});
$transportSource = file_get_contents(dirname(__DIR__) . '/libs/gtf_client.php');
check('provider payloads never reach an exception message', preg_match('/throw new (Runtime|InvalidArgument)Exception\([^;]*\$(body|fields|received)/', $transportSource) === 0);

section('provider answers are passed through untouched for normalisation');
$transport = new FakeTransport();
$transport->response = ['status' => '99', 'value' => '100000'];
$client = new GtfCardClient(gtfTestConfig(), $transport);
$answered = $client->check('VIETTEL', '123456789', 'SERI123', '100000', 'ABCDEF1700000000');
checkSame('no field is invented or rewritten by the client', ['status', 'value'], array_keys($answered));

// --- explicit configuration is required -------------------------------------
section('explicit configuration (no default origin, no default format)');
checkThrows('a blank origin is refused', function () {
    new GtfCardClient(gtfTestConfig(['api_base' => '']), new FakeTransport());
});
checkThrows('a blank request format is refused', function () {
    new GtfCardClient(gtfTestConfig(['card_request_format' => '']), new FakeTransport());
});
foreach (['GET', 'get', 'query', 'post', 'json ', 'xml'] as $badFormat) {
    checkThrows('the format "' . $badFormat . '" is refused', function () use ($badFormat) {
        new GtfCardClient(gtfTestConfig(['card_request_format' => $badFormat]), new FakeTransport());
    });
}
checkThrows('a blank partner id is refused', function () {
    new GtfCardClient(gtfTestConfig(['partner_id' => '']), new FakeTransport());
});
checkThrows('a blank partner key is refused', function () {
    new GtfCardClient(gtfTestConfig(['partner_key' => '']), new FakeTransport());
});

section('origin pinning (the transport validates the configured origin itself)');
$accepted = 0;
foreach (['https://api.example.test', 'https://api.example.test:443', 'https://api.example.test/'] as $goodOrigin) {
    try {
        new GtfCurlTransport($goodOrigin, 10, ['/chargingws/v2']);
        $accepted++;
    } catch (Throwable $error) {
        fwrite(STDERR, "  rejected unexpectedly: {$goodOrigin}\n");
    }
}
checkSame('three legitimate forms of the configured origin are accepted', 3, $accepted);

$badOrigins = [
    'empty' => '',
    'plain http' => 'http://api.example.test',
    'no scheme' => 'api.example.test',
    'userinfo' => 'https://user:pass@api.example.test',
    'query string' => 'https://api.example.test/?x=1',
    'fragment' => 'https://api.example.test/#x',
    'odd port' => 'https://api.example.test:8443',
    'path' => 'https://api.example.test/api',
    'embedded space' => 'https://api.example.test /x',
    'leading dot' => 'https://.api.example.test',
    'trailing dot' => 'https://api.example.test.',
];
foreach ($badOrigins as $label => $badOrigin) {
    checkThrows('the origin is refused: ' . $label, function () use ($badOrigin) {
        new GtfCurlTransport($badOrigin, 10, ['/chargingws/v2']);
    });
}
check('the transport reports the host it is pinned to', (new GtfCurlTransport('https://api.example.test', 10, ['/chargingws/v2']))->host() === 'api.example.test');
checkThrows('no allow-listed path means no transport', function () {
    new GtfCurlTransport('https://api.example.test', 10, []);
});
checkThrows('a path outside the allow-list is refused', function () {
    (new GtfCurlTransport('https://api.example.test', 10, ['/chargingws/v2']))->post('/other', [], 'json');
});

// --- status normalisation ---------------------------------------------------
section('status normalisation (documented statuses may be categorised, none may credit)');
$order = ['declared_amount' => '100000', 'request_id' => 'ABCDEF1700000000'];
$ok = ['status' => '1', 'value' => '100000', 'amount' => '100000', 'declared_value' => '100000'];
checkSame('status 1 with agreeing amounts is only a credit candidate', 'credit_candidate', GtfRules::cardDecision($ok, $order)['decision']);
checkSame('the candidate carries the confirmed gross amount', '100000', GtfRules::cardDecision($ok, $order)['gross']);
checkSame('status 2 is manual review', 'manual_review', GtfRules::cardDecision(array_merge($ok, ['status' => '2']), $order)['decision']);
checkSame('status 2 never carries a gross amount', false, isset(GtfRules::cardDecision(array_merge($ok, ['status' => '2']), $order)['gross']));
checkSame('status 3 is terminal', 'terminal', GtfRules::cardDecision(['status' => '3'], $order)['decision']);
checkSame('status 3 maps to REJECTED', 'REJECTED', GtfRules::cardDecision(['status' => '3'], $order)['status']);
checkSame('status 4 maps to MAINTENANCE', 'MAINTENANCE', GtfRules::cardDecision(['status' => '4'], $order)['status']);
checkSame('status 99 stays pending', 'pending', GtfRules::cardDecision(['status' => '99'], $order)['decision']);
checkSame('status 100 is terminal', 'terminal', GtfRules::cardDecision(['status' => '100'], $order)['decision']);
checkSame('status 100 maps to REJECTED', 'REJECTED', GtfRules::cardDecision(['status' => '100'], $order)['status']);
checkSame('an unknown status is unknown', 'unknown', GtfRules::cardDecision(['status' => '7'], $order)['decision']);
checkSame('a missing status is unknown', 'unknown', GtfRules::cardDecision([], $order)['decision']);
checkSame('a higher provider value is a conflict', 'manual_review', GtfRules::cardDecision(array_merge($ok, ['value' => '200000']), $order)['decision']);
checkSame('a lower provider value is a conflict', 'manual_review', GtfRules::cardDecision(array_merge($ok, ['value' => '50000']), $order)['decision']);
checkSame('amount/value disagreement is a conflict', 'manual_review', GtfRules::cardDecision(array_merge($ok, ['amount' => '99999']), $order)['decision']);
checkSame('declared_value disagreement is a conflict', 'manual_review', GtfRules::cardDecision(array_merge($ok, ['declared_value' => '50000']), $order)['decision']);
checkSame('a malformed value is manual review', 'manual_review', GtfRules::cardDecision(array_merge($ok, ['value' => '100000.00']), $order)['decision']);
checkSame('a missing value is manual review', 'manual_review', GtfRules::cardDecision(['status' => '1'], $order)['decision']);
checkSame('a zero value is manual review', 'manual_review', GtfRules::cardDecision(array_merge($ok, ['value' => '0', 'amount' => '0', 'declared_value' => '0']), $order)['decision']);
foreach (['99', '2', '3', '4', '100', '7', ''] as $notCreditable) {
    $decision = $notCreditable === '' ? GtfRules::cardDecision([], $order) : GtfRules::cardDecision(array_merge($ok, ['status' => $notCreditable]), $order);
    check('status "' . $notCreditable . '" is never a credit candidate', $decision['decision'] !== 'credit_candidate');
}

section('amount validation');
foreach (['66666', '100000', '123456789012345'] as $goodAmount) {
    check('valid amount: ' . $goodAmount, GtfRules::validAmountString($goodAmount));
}
foreach (['66666.00', '66,666', '1e6', '-1000', '', ' 100', '1234567890123456', 66666, null] as $badAmount) {
    check('rejected amount: ' . var_export($badAmount, true), !GtfRules::validAmountString($badAmount));
}

section('card net maths');
checkSame('0% fee keeps the amount', '100000.00', GtfRules::cardNet('100000', 0.0));
checkSame('2% fee is deducted', '98000.00', GtfRules::cardNet('100000', 2.0));
checkThrows('a fee above 100% is refused', function () {
    GtfRules::cardNet('100000', 101.0);
});

// --- crediting lock ---------------------------------------------------------
section('crediting is locked by default');
checkSame('the default credit lock is closed', false, GtfCredit::isEnabled(['credit_enabled' => false]));
checkSame('the lock opens only when explicitly enabled', true, GtfCredit::isEnabled(['credit_enabled' => true]));
check('the packaged configuration ships with the lock closed', GtfCredit::isEnabled() === false);
check('the decision vocabulary no longer claims an automatic credit', strpos(file_get_contents(dirname(__DIR__) . '/libs/gtf.php'), 'auto_credit') === false);

// --- bank boundary ----------------------------------------------------------
section('bank boundary is fail-closed and invents nothing');
$bankSource = file_get_contents(dirname(__DIR__) . '/libs/gtf_bank.php');
check('the bank boundary performs no HTTP call', stripos($bankSource, 'curl_') === false && stripos($bankSource, 'file_get_contents') === false);
if (function_exists('php_strip_whitespace')) {
    $bankCode = php_strip_whitespace(dirname(__DIR__) . '/libs/gtf_bank.php');
    $workerCode = php_strip_whitespace(dirname(__DIR__) . '/cron/gtf-bank.php');
    check('the bank boundary calls no crediting routine', strpos($bankCode, 'GtfCredit') === false);
    check('the bank worker calls no credit or bank-poll routine', strpos($workerCode, 'GtfCredit') === false && strpos($workerCode, '->poll(') === false);
} else {
    fwrite(STDERR, "SKIP: the tokenizer is unavailable, comment stripping was not performed\n");
}
foreach (['history-receive', 'pay_amount', 'order_code', 'partner_id', 'partner_key', 'webhook'] as $invented) {
    check('the bank boundary contains no invented contract detail: ' . $invented, stripos($bankSource, $invented) === false);
}
check('the bank boundary always reports itself unavailable', strpos($bankSource, 'public function isAvailable(): bool') !== false && strpos($bankSource, "return false;") !== false);
$bankWorker = file_get_contents(dirname(__DIR__) . '/cron/gtf-bank.php');
check('the bank worker does not poll when unavailable', strpos($bankWorker, 'isAvailable()') !== false && strpos($bankWorker, '->poll(') === false);

// --- sealing ----------------------------------------------------------------
section('secret sealing and masking');
if (extension_loaded('openssl')) {
    $sealed = GtfSecret::seal('1234567890123');
    check('the sealed value contains no plaintext', strpos($sealed, '1234567890123') === false);
    checkSame('the sealed value round-trips', '1234567890123', GtfSecret::open($sealed));
    checkThrows('a tampered sealed value is refused', function () use ($sealed) {
        GtfSecret::open(substr($sealed, 0, -4) . 'AAAA');
    });
    checkSame('masking keeps only the last three characters', '**********123', GtfSecret::mask('1234567890123'));
} else {
    fwrite(STDERR, "SKIP: openssl is not loaded, secret sealing was not exercised\n");
}

// --- source-level guarantees ------------------------------------------------
section('source-level guarantees');
$libDir = dirname(__DIR__) . '/libs/';
$clientSource = file_get_contents($libDir . 'gtf_client.php');
$cardSource = file_get_contents($libDir . 'gtf_card.php');
$coreSource = file_get_contents($libDir . 'gtf.php');
check('no provider hostname is hard-coded in the client', stripos($clientSource, 'gachthefast.com') === false);
check('no provider hostname is hard-coded in the core', stripos($coreSource, 'gachthefast.com') === false);
check('no provider hostname is hard-coded in the card service', stripos($cardSource, 'gachthefast.com') === false);
check('the client cannot issue a GET request', strpos($clientSource, 'CURLOPT_HTTPGET') === false && stripos($clientSource, "'GET'") === false);
check('the origin can never come from request input', strpos($clientSource, '$_GET') === false && strpos($clientSource, '$_POST') === false && strpos($clientSource, '$_REQUEST') === false);
check('the client never disables TLS verification', strpos($clientSource, 'CURLOPT_SSL_VERIFYPEER => true') !== false);
check('the client never follows redirects', strpos($clientSource, 'CURLOPT_FOLLOWLOCATION => false') !== false);
check('transport errors carry only the cURL error number', strpos($clientSource, "transport error ' . \$errno") !== false);
check('the card service never uses the legacy non-transactional AddCredits()', strpos($cardSource, 'AddCredits') === false);
check('the card service credits through the guarded ledger routine', strpos($cardSource, 'GtfCredit::applyWithin') !== false);
check('the core credit routine refuses outside a transaction', strpos($coreSource, 'GtfCredit requires an open transaction') !== false);
check('the core credit routine is behind the explicit lock', strpos($coreSource, 'public static function isEnabled') !== false && strpos($coreSource, "credit_enabled") !== false && strpos($coreSource, 'crediting is locked') !== false);
$callbackStart = strpos($cardSource, 'public function callback(array $query)');
$callbackEnd = $callbackStart === false ? false : strpos($cardSource, "\n    public function ", $callbackStart + 10);
$callbackBody = ($callbackStart === false || $callbackEnd === false) ? '' : substr($cardSource, $callbackStart, $callbackEnd - $callbackStart);
check('the callback path never calls the credit routine', $callbackBody !== '' && strpos($callbackBody, '->credit(') === false && strpos($callbackBody, 'applyWithin') === false);
check('the callback path verifies the signature before it reconciles', strpos($callbackBody, 'hash_equals') !== false);
$envExample = file_get_contents(dirname(__DIR__) . '/.env.example');
check('the example environment contains no provider hostname', stripos($envExample, 'gachthefast.com') === false);
check('the example environment ships both flags off', strpos($envExample, "GTF_CARD_ENABLED=false") !== false && strpos($envExample, "GTF_ALLOW_CREDITING=false") !== false);
check('the example environment ships an empty origin and format', strpos($envExample, "GTF_API_BASE=\n") !== false && strpos($envExample, "GTF_CARD_REQUEST_FORMAT=\n") !== false);
check('no credential values are committed', preg_match('/GTF_PARTNER_(ID|KEY)=\S/', $envExample) === 0);

echo "\ngtf_rules_test: {$GLOBALS['gtf_pass']} passed, {$GLOBALS['gtf_fail']} failed\n";
exit($GLOBALS['gtf_fail'] === 0 ? 0 : 1);
