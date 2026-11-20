<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../libs/payos_client.php';

class OfflinePayOSClient extends PayOSClient
{
    public $response = ['status' => 200, 'body' => '{}'];
    public $requests = [];

    protected function sendHttpRequest(string $method, string $path, ?string $body): array
    {
        $this->requests[] = ['method' => $method, 'path' => $path, 'body' => $body];
        return $this->response;
    }
}

$checks = 0;
$failures = [];

function checkSame($expected, $actual, string $name): void
{
    global $checks, $failures;
    ++$checks;
    if ($expected !== $actual) {
        $failures[] = $name;
    }
}

function checkThrows(callable $action, string $class, string $name, array $forbidden = []): void
{
    global $checks, $failures;
    ++$checks;
    try {
        $action();
        $failures[] = $name . ' (no exception)';
    } catch (Throwable $exception) {
        if (!($exception instanceof $class)) {
            $failures[] = $name . ' (wrong exception type)';
        }
        foreach ($forbidden as $secret) {
            if (strpos($exception->getMessage(), $secret) !== false) {
                $failures[] = $name . ' (sensitive detail in error)';
            }
        }
    }
}

function signedResponse(array $data, string $key): array
{
    return ['status' => 200, 'body' => json_encode([
        'code' => '00',
        'data' => $data,
        'signature' => PayOSClient::createDataSignature($data, $key),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
}

$key = 'test-checksum-key';
$request = [
    'returnUrl' => 'https://shop.example/return?note=a%20b',
    'description' => 'DH123',
    'amount' => 25000,
    'cancelUrl' => 'https://shop.example/cancel?a=1&b=2',
    'orderCode' => 123,
    'buyerName' => 'This optional field is not part of the signature',
];

// Expected digests were computed independently using .NET HMACSHA256.
checkSame('86344fcae7dcc2835f3c15d935daf707e90b265ee16da80595a463957e4bcf89',
    PayOSClient::createPaymentSignature($request, $key), 'create signature uses five unencoded fields');
$changedOptional = $request;
$changedOptional['buyerName'] = 'Changed';
$changedOptional['items'] = [['name' => 'Mì', 'quantity' => 1, 'price' => 25000]];
checkSame(PayOSClient::createPaymentSignature($request, $key),
    PayOSClient::createPaymentSignature($changedOptional, $key), 'optional fields do not change request signature');

$canonicalData = [
    'zZero' => 0,
    'items' => [
        ['url' => 'https://shop.example/a/b', 'quantity' => 2, 'name' => 'Mì / cà phê'],
        ['quantity' => 1, 'name' => 'Trà'],
    ],
    'bTrue' => true,
    'aUndefined' => 'undefined',
    'aTextNull' => 'null',
    'nested' => ['z' => 2, 'a' => 1],
    'bFalse' => false,
    'aNull' => null,
];
$canonical = 'aNull=&aTextNull=&aUndefined=&bFalse=false&bTrue=true&items=[{"name":"Mì / cà phê","quantity":2,"url":"https://shop.example/a/b"},{"name":"Trà","quantity":1}]&nested=[object Object]&zZero=0';
checkSame($canonical, PayOSClient::canonicalizeData($canonicalData), 'canonical null bool unicode slash and arrays');
checkSame('ec7d12559dd9ed3bae8688d8595c9b552aefe804c76fef6266b25246c74e7b88',
    PayOSClient::createDataSignature($canonicalData, $key), 'canonical signature independent fixture');
checkSame('items=[{"a":1,"b":2}]&nested=[object Object]',
    PayOSClient::canonicalizeData(['nested' => (object) ['a' => 1], 'items' => [(object) ['b' => 2, 'a' => 1]]]),
    'nested objects match SDK normalization');
checkSame('items=[]', PayOSClient::canonicalizeData(['items' => []]), 'empty list stays JSON array');

// Published payOS fixture: https://payos.vn/docs/tich-hop-webhook/kiem-tra-du-lieu-voi-signature/
$officialKey = '1a54716c8f0efb2744fb28b6e38b25da7f67a925d98bc1c18bd8faaecadd7675';
$officialData = [
    'orderCode' => 123,
    'amount' => 3000,
    'description' => 'VQRIO123',
    'accountNumber' => '12345678',
    'reference' => 'TF230204212323',
    'transactionDateTime' => '2023-02-04 18:25:00',
    'currency' => 'VND',
    'paymentLinkId' => '124c33293c43417ab7879e14c8d9eb18',
    'code' => '00',
    'desc' => 'Thành công',
    'counterAccountBankId' => '',
    'counterAccountBankName' => '',
    'counterAccountName' => '',
    'counterAccountNumber' => '',
    'virtualAccountName' => '',
    'virtualAccountNumber' => '',
];
$officialPayload = [
    'code' => '00', 'success' => true, 'data' => $officialData,
    'signature' => '412e915d2871504ed31be63c8f62a149a4410d34c4c42affc9006ef9917eaa03',
];
$officialClient = new PayOSClient('test-client', 'test-api', $officialKey);
checkSame($officialPayload['signature'], PayOSClient::createDataSignature($officialData, $officialKey), 'official webhook digest');
checkSame($officialData, $officialClient->verifyWebhook($officialPayload), 'official webhook accepted');
$tampered = $officialPayload;
$tampered['data']['amount']++;
checkThrows(function () use ($officialClient, $tampered) { $officialClient->verifyWebhook($tampered); },
    InvalidArgumentException::class, 'tampered amount rejected');
$tampered = $officialPayload;
$tampered['data']['code'] = '01';
checkThrows(function () use ($officialClient, $tampered) { $officialClient->verifyWebhook($tampered); },
    InvalidArgumentException::class, 'tampered signed code rejected');
foreach ([[], ['data' => []], ['data' => $officialData, 'signature' => []],
    ['data' => $officialData, 'signature' => str_repeat('0', 64)],
    ['data' => $officialData, 'signature' => $officialPayload['signature'] . "\n"]] as $invalidPayload) {
    checkThrows(function () use ($officialClient, $invalidPayload) { $officialClient->verifyWebhook($invalidPayload); },
        InvalidArgumentException::class, 'malformed webhook rejected');
}

$client = new OfflinePayOSClient('test-client', 'test-api', $key);
$createdData = ['orderCode' => 123, 'amount' => 25000, 'status' => 'PENDING',
    'paymentLinkId' => 'payment-123', 'checkoutUrl' => 'https://pay.payos.vn/web/payment-123'];
$client->response = signedResponse($createdData, $key);
$stringIntegerRequest = $request;
$stringIntegerRequest['amount'] = '25000';
$stringIntegerRequest['orderCode'] = '123';
$stringIntegerRequest['signature'] = 'caller-supplied-invalid-signature';
checkSame($createdData, $client->createPaymentLink($stringIntegerRequest), 'create verifies signed response');
checkSame('POST', $client->requests[0]['method'], 'create HTTP method');
checkSame('/v2/payment-requests', $client->requests[0]['path'], 'create endpoint');
$sent = json_decode($client->requests[0]['body'], true);
checkSame(25000, $sent['amount'], 'amount encoded as integer');
checkSame(123, $sent['orderCode'], 'orderCode encoded as integer');
checkSame(PayOSClient::createPaymentSignature($request, $key), $sent['signature'], 'caller signature replaced');

$paidData = ['orderCode' => 123, 'amount' => 25000, 'amountPaid' => 25000,
    'status' => 'PAID', 'transactions' => [['reference' => 'REF-1', 'amount' => 25000]]];
$client->response = signedResponse($paidData, $key);
checkSame($paidData, $client->getPaymentLink('123'), 'GET verifies response with transaction array');
checkSame('GET', $client->requests[1]['method'], 'get HTTP method');
checkSame('/v2/payment-requests/123', $client->requests[1]['path'], 'get endpoint');
checkSame(null, $client->requests[1]['body'], 'GET has no body');

$beforeInvalid = count($client->requests);
foreach ([0, -1, 1.5, true, '1e3', '01', '1/other', '999999999999999999999999999999'] as $invalidInteger) {
    checkThrows(function () use ($client, $invalidInteger) { $client->getPaymentLink($invalidInteger); },
        InvalidArgumentException::class, 'invalid orderCode rejected before HTTP');
}
$invalidRequest = $request;
$invalidRequest['amount'] = -100;
checkThrows(function () use ($client, $invalidRequest) { $client->createPaymentLink($invalidRequest); },
    InvalidArgumentException::class, 'negative amount rejected');
$invalidRequest = $request;
$invalidRequest['returnUrl'] = 'javascript:alert(1)';
checkThrows(function () use ($client, $invalidRequest) { $client->createPaymentLink($invalidRequest); },
    InvalidArgumentException::class, 'non-HTTP return URL rejected');
checkSame($beforeInvalid, count($client->requests), 'validation failures make no network requests');

foreach ([
    ['status' => 302, 'body' => 'redirect'],
    ['status' => 500, 'body' => 'test-api test-checksum-key'],
    ['status' => 200, 'body' => '<html>test-api</html>'],
    ['status' => 200, 'body' => '{"code":"01","desc":"test-api test-checksum-key"}'],
    ['status' => 200, 'body' => '{"code":"00","data":{"orderCode":123},"signature":"invalid"}'],
] as $badResponse) {
    $client->response = $badResponse;
    checkThrows(function () use ($client) { $client->getPaymentLink(123); }, RuntimeException::class,
        'invalid HTTP/API/signature response rejected', ['test-api', $key]);
}
$client->response = signedResponse(['orderCode' => 999, 'amount' => 25000], $key);
checkThrows(function () use ($client, $request) { $client->createPaymentLink($request); },
    RuntimeException::class, 'signed response for different order rejected');
$client->response = signedResponse(['orderCode' => 123, 'amount' => 1], $key);
checkThrows(function () use ($client, $request) { $client->createPaymentLink($request); },
    RuntimeException::class, 'signed response for different amount rejected');
checkThrows(function () { new PayOSClient("client\r\nx-injected: value", 'api', 'key'); },
    InvalidArgumentException::class, 'credential header injection rejected');
checkThrows(function () use ($request) { PayOSClient::createPaymentSignature($request, ''); },
    InvalidArgumentException::class, 'empty checksum key rejected');

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, 'OK: ' . $checks . " payOS client checks passed (offline).\n");
