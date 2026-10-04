<?php

/**
 * Standalone payOS payment-requests client for PHP 7.4+.
 * Signature rules: https://github.com/payOSHQ/payos-lib-php/blob/main/src/Crypto/CryptoProvider.php
 */
class PayOSClient
{
    private const API_BASE = 'https://api-merchant.payos.vn';
    private const MAX_RESPONSE_BYTES = 1048576;

    private $clientId;
    private $apiKey;
    private $checksumKey;

    public function __construct($clientId, $apiKey, $checksumKey)
    {
        foreach ([$clientId, $apiKey, $checksumKey] as $credential) {
            if (!is_string($credential) || trim($credential) === '' || preg_match('/[\x00-\x20\x7f]/', $credential)) {
                throw new InvalidArgumentException('Invalid payOS configuration.');
            }
        }
        $this->clientId = $clientId;
        $this->apiKey = $apiKey;
        $this->checksumKey = $checksumKey;
    }

    public function __debugInfo(): array
    {
        return ['configured' => true];
    }

    public function createPaymentLink(array $data): array
    {
        $data['orderCode'] = self::positiveInteger($data['orderCode'] ?? null);
        $data['amount'] = self::positiveInteger($data['amount'] ?? null);
        if (!isset($data['description']) || !is_string($data['description']) || trim($data['description']) === '') {
            throw new InvalidArgumentException('Invalid payOS payment description.');
        }
        foreach (['returnUrl', 'cancelUrl'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) ||
                !filter_var($data[$field], FILTER_VALIDATE_URL) ||
                !in_array(strtolower((string) parse_url($data[$field], PHP_URL_SCHEME)), ['http', 'https'], true)) {
                throw new InvalidArgumentException('Invalid payOS return or cancel URL.');
            }
        }
        if (isset($data['expiredAt'])) {
            $data['expiredAt'] = self::positiveInteger($data['expiredAt']);
            if ($data['expiredAt'] > 2147483647) {
                throw new InvalidArgumentException('Invalid payOS expiration time.');
            }
        }
        $data['signature'] = self::createPaymentSignature($data, $this->checksumKey);
        $result = $this->request('POST', '/v2/payment-requests', $data);
        if (!isset($result['orderCode'], $result['amount']) ||
            (string) $result['orderCode'] !== (string) $data['orderCode'] ||
            (string) $result['amount'] !== (string) $data['amount']) {
            throw new RuntimeException('Unexpected payOS payment response.');
        }
        return $result;
    }

    public function getPaymentLink($orderCode): array
    {
        $orderCode = self::positiveInteger($orderCode);
        $result = $this->request('GET', '/v2/payment-requests/' . $orderCode);
        if (!isset($result['orderCode']) || (string) $result['orderCode'] !== (string) $orderCode) {
            throw new RuntimeException('Unexpected payOS payment response.');
        }
        return $result;
    }

    /** Verifies signed data only; callers must match it to their stored order. */
    public function verifyWebhook(array $payload): array
    {
        return $this->verifySignedData($payload);
    }

    public static function createPaymentSignature(array $data, $checksumKey): string
    {
        self::validateChecksumKey($checksumKey);
        $pairs = [];
        foreach (['amount', 'cancelUrl', 'description', 'orderCode', 'returnUrl'] as $field) {
            if (!array_key_exists($field, $data) || (!is_string($data[$field]) && !is_int($data[$field]))) {
                throw new InvalidArgumentException('Invalid payOS signature fields.');
            }
            $pairs[] = $field . '=' . $data[$field];
        }
        return hash_hmac('sha256', implode('&', $pairs), $checksumKey);
    }

    public static function createDataSignature(array $data, $checksumKey): string
    {
        self::validateChecksumKey($checksumKey);
        return hash_hmac('sha256', self::canonicalizeData($data), $checksumKey);
    }

    public static function canonicalizeData(array $data): string
    {
        $data = self::normalizeObjects($data);
        ksort($data);
        $pairs = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (!self::isList($value)) {
                    $serialized = '[object Object]';
                } else {
                    foreach ($value as &$item) {
                        if (is_array($item)) {
                            ksort($item);
                        }
                    }
                    unset($item);
                    try {
                        $serialized = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    } catch (JsonException $exception) {
                        throw new InvalidArgumentException('Invalid payOS signature data.');
                    }
                }
            } elseif ($value === null || $value === 'undefined' || $value === 'null') {
                $serialized = '';
            } elseif (is_bool($value)) {
                $serialized = $value ? 'true' : 'false';
            } elseif (is_scalar($value) && (!is_float($value) || is_finite($value))) {
                $serialized = (string) $value;
            } else {
                throw new InvalidArgumentException('Invalid payOS signature data.');
            }
            $pairs[] = $key . '=' . $serialized;
        }
        return implode('&', $pairs);
    }

    private static function normalizeObjects($value, int $depth = 0)
    {
        if ($depth > 64) {
            throw new InvalidArgumentException('Invalid payOS signature data.');
        }
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::normalizeObjects($item, $depth + 1);
            }
        }
        return $value;
    }

    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    private static function validateChecksumKey($checksumKey): void
    {
        if (!is_string($checksumKey) || trim($checksumKey) === '') {
            throw new InvalidArgumentException('Invalid payOS configuration.');
        }
    }

    private static function positiveInteger($value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)) {
            $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($integer !== false) {
                return $integer;
            }
        }
        throw new InvalidArgumentException('Invalid payOS integer value.');
    }

    private function verifySignedData(array $payload): array
    {
        if (!isset($payload['data'], $payload['signature']) || !is_array($payload['data']) ||
            $payload['data'] === [] || self::isList($payload['data']) ||
            !is_string($payload['signature']) || !preg_match('/^[a-f0-9]{64}$/D', $payload['signature'])) {
            throw new InvalidArgumentException('Invalid payOS signed payload.');
        }
        $expected = self::createDataSignature($payload['data'], $this->checksumKey);
        if (!hash_equals($expected, $payload['signature'])) {
            throw new InvalidArgumentException('Invalid payOS signature.');
        }
        return $payload['data'];
    }

    private function request(string $method, string $path, ?array $data = null): array
    {
        $body = null;
        if ($data !== null) {
            try {
                $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException('Invalid payOS request data.');
            }
        }
        $response = $this->sendHttpRequest($method, $path, $body);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('payOS request failed. Please retry later.');
        }
        try {
            $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid payOS response.');
        }
        if (!is_array($payload) || !isset($payload['code']) || $payload['code'] !== '00') {
            throw new RuntimeException('payOS could not process the request.');
        }
        try {
            return $this->verifySignedData($payload);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('Invalid payOS response signature.');
        }
    }

    /** Override only in offline tests; returns HTTP status and unparsed body. */
    protected function sendHttpRequest(string $method, string $path, ?string $body): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The cURL extension is required for payOS.');
        }
        $handle = curl_init(self::API_BASE . $path);
        if ($handle === false) {
            throw new RuntimeException('Unable to connect to payOS.');
        }
        $responseBody = '';
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'x-client-id: ' . $this->clientId,
                'x-api-key: ' . $this->apiKey,
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody): int {
                if (strlen($responseBody) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        try {
            if (!curl_setopt_array($handle, $options) || curl_exec($handle) === false) {
                throw new RuntimeException('Unable to connect to payOS. Please retry later.');
            }
            return ['status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body' => $responseBody];
        } finally {
            curl_close($handle);
        }
    }
}
