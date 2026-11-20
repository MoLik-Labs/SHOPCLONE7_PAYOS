<?php

/**
 * GachtheFast card HTTP transport and provider client.
 *
 * Confirmed by the provider's CARD PARTNER API page (see docs/gachthefast/):
 *   path /chargingws/v2, commands `charging` and `check`,
 *   fields telco, code, serial, amount, request_id, partner_id, sign, command,
 *   sign = md5(partner_key + code + serial), statuses 1, 2, 3, 4, 99, 100.
 *
 * NOT confirmed, and therefore never assumed here:
 *   - the production API origin (the page uses the placeholder http://{{domain_post}} and the
 *     root website domain is not an established API endpoint);
 *   - the accepted POST body format (the page shows a JSON content-type header next to a
 *     form-data example, so it cannot be inferred);
 *   - the `check` response schema, whether responses are authenticated, and the credit rule
 *     for status 2.
 * Both the origin and the body format must be configured explicitly. Neither has a default,
 * and this client refuses to make a request until both are set.
 *
 * Transport rules: HTTPS only, pinned to the configured origin (never another host, never a
 * redirect), POST only (card data is never sent in a query string), strict timeouts, a hard
 * response-size cap, and no PIN, serial, partner key or provider payload ever appears in an
 * exception message or a log line.
 */

if (!defined('IN_SITE')) {
    http_response_code(404);
    exit;
}

/** Injectable so offline tests exercise request building without touching the network. */
interface GtfHttpTransport
{
    /**
     * @param string $path   one of the allow-listed API paths
     * @param array  $fields request fields (never logged, never echoed)
     * @param string $format 'json' or 'form' — configured explicitly, never guessed
     * @return array decoded provider response
     */
    public function post(string $path, array $fields, string $format): array;
}

/**
 * Provider-facing card contract. The service depends on this interface, not on the concrete
 * client, so the offline test suite can inject a deterministic double.
 */
interface GtfCardGateway
{
    public function submit(string $telco, string $amount, string $code, string $serial, string $requestId): array;

    public function check(string $telco, string $code, string $serial, string $amount, string $requestId): array;
}

final class GtfCurlTransport implements GtfHttpTransport
{
    private $origin;
    private $host;
    private $timeout;
    private $allowedPaths;

    /**
     * @param string $origin       explicitly configured API origin, e.g. "https://api.example.test"
     * @param int    $timeout      connect/read timeout in seconds (clamped to 3..30)
     * @param array  $allowedPaths exact request paths this transport may call
     */
    public function __construct($origin, int $timeout, array $allowedPaths)
    {
        $origin = trim((string) $origin);
        if ($origin === '') {
            throw new InvalidArgumentException('The GachtheFast API origin is not configured.');
        }
        if (preg_match('/[\x00-\x20\x7f]/', $origin)) {
            throw new InvalidArgumentException('The GachtheFast API origin contains invalid characters.');
        }
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || strtolower((string) $parts['scheme']) !== 'https') {
            throw new InvalidArgumentException('The GachtheFast API origin must be an HTTPS URL.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('The GachtheFast API origin must not carry credentials, a query or a fragment.');
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new InvalidArgumentException('The GachtheFast API origin must use port 443.');
        }
        if (rtrim(isset($parts['path']) ? (string) $parts['path'] : '', '/') !== '') {
            throw new InvalidArgumentException('The GachtheFast API origin must not carry a path.');
        }
        $host = strtolower((string) $parts['host']);
        if ($host === '' || preg_match('/[^a-z0-9.\-]/', $host) || substr($host, 0, 1) === '.' || substr($host, -1) === '.') {
            throw new InvalidArgumentException('The GachtheFast API origin host is invalid.');
        }
        $paths = [];
        foreach ($allowedPaths as $allowed) {
            $allowed = (string) $allowed;
            if ($allowed !== '' && $allowed[0] === '/') {
                $paths[$allowed] = true;
            }
        }
        if (!$paths) {
            throw new InvalidArgumentException('No GachtheFast API path is allowed.');
        }
        $this->origin = rtrim($origin, '/');
        $this->host = $host;
        $this->timeout = max(3, min(30, $timeout));
        $this->allowedPaths = $paths;
    }

    /** The host this transport is pinned to. Exposed for diagnostics that carry no secret. */
    public function host(): string
    {
        return $this->host;
    }

    public function post(string $path, array $fields, string $format): array
    {
        if (!isset($this->allowedPaths[$path])) {
            throw new InvalidArgumentException('Invalid GachtheFast API path.');
        }
        if ($format === 'json') {
            $body = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $contentType = 'Content-Type: application/json';
        } elseif ($format === 'form') {
            $body = http_build_query($fields, '', '&');
            $contentType = 'Content-Type: application/x-www-form-urlencoded';
        } else {
            throw new InvalidArgumentException('The GachtheFast request format must be configured explicitly (json or form).');
        }
        if (!is_string($body) || $body === '') {
            throw new RuntimeException('Cannot encode the provider request.');
        }

        $handle = curl_init();
        $received = '';
        $truncated = false;
        curl_setopt_array($handle, [
            CURLOPT_URL => $this->origin . $path,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [$contentType, 'Accept: application/json'],
            CURLOPT_USERAGENT => 'SHOPCLONE7-GTF/1.0',
            CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) use (&$received, &$truncated) {
                $received .= $chunk;
                if (strlen($received) > GTF_MAX_BODY_BYTES) {
                    $truncated = true;
                    return 0; // aborts the transfer and keeps memory bounded
                }
                return strlen($chunk);
            },
        ]);
        if (defined('CURLPROTO_HTTPS')) {
            curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        $ok = curl_exec($handle);
        $errno = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($truncated) {
            throw new RuntimeException('The provider response exceeded the allowed size.');
        }
        if ($ok === false || $errno !== 0) {
            // Only the cURL error number is surfaced: never the payload, URL or body.
            throw new RuntimeException('Cannot reach the GachtheFast API (transport error ' . $errno . ').');
        }
        if ($status !== 200) {
            throw new RuntimeException('The GachtheFast API returned HTTP status ' . $status . '.');
        }
        $decoded = json_decode($received, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('The GachtheFast API returned an unreadable response.');
        }
        return $decoded;
    }
}

final class GtfCardClient implements GtfCardGateway
{
    /** The only path this integration is documented to call. */
    public const ENDPOINT = '/chargingws/v2';

    private $transport;
    private $format;
    private $partnerId;
    private $partnerKey;

    /**
     * @param array                 $config    gtf_config() output (origin + format must be set)
     * @param GtfHttpTransport|null $transport injected by tests; the live transport is built otherwise
     */
    public function __construct(array $config, GtfHttpTransport $transport = null)
    {
        $origin = isset($config['api_base']) ? trim((string) $config['api_base']) : '';
        if ($origin === '') {
            throw new InvalidArgumentException('The GachtheFast API origin is not configured.');
        }
        $format = isset($config['card_request_format']) ? (string) $config['card_request_format'] : '';
        if (!in_array($format, ['json', 'form'], true)) {
            throw new InvalidArgumentException('The GachtheFast request format is not configured.');
        }
        $partnerId = isset($config['partner_id']) ? trim((string) $config['partner_id']) : '';
        $partnerKey = isset($config['partner_key']) ? trim((string) $config['partner_key']) : '';
        if ($partnerId === '' || $partnerKey === '') {
            throw new InvalidArgumentException('The GachtheFast partner credentials are not configured.');
        }
        $this->format = $format;
        $this->partnerId = $partnerId;
        $this->partnerKey = $partnerKey;
        $this->transport = $transport !== null
            ? $transport
            : new GtfCurlTransport($origin, isset($config['timeout']) ? (int) $config['timeout'] : 10, [self::ENDPOINT]);
    }

    /** Documented signature: md5(partner_key + code + serial). */
    public static function sign($partnerKey, $code, $serial): string
    {
        return md5($partnerKey . $code . $serial);
    }

    /** Submits a card for charging. Documented command: charging. */
    public function submit(string $telco, string $amount, string $code, string $serial, string $requestId): array
    {
        return $this->transport->post(self::ENDPOINT, [
            'telco' => strtoupper($telco),
            'code' => $code,
            'serial' => $serial,
            'amount' => $amount,
            'request_id' => $requestId,
            'partner_id' => $this->partnerId,
            'sign' => self::sign($this->partnerKey, $code, $serial),
            'command' => 'charging',
        ], $this->format);
    }

    /**
     * Status lookup. The documented `check` example sends the full card picture again
     * (telco, code, serial, amount, request_id, partner_id, sign, command=check) with the same
     * signature formula. Its response schema and authenticity are NOT confirmed, so callers
     * must treat a failure here as "outcome unknown", never as "failed", and must not credit
     * from it while crediting is locked.
     */
    public function check(string $telco, string $code, string $serial, string $amount, string $requestId): array
    {
        return $this->transport->post(self::ENDPOINT, [
            'telco' => strtoupper($telco),
            'code' => $code,
            'serial' => $serial,
            'amount' => $amount,
            'request_id' => $requestId,
            'partner_id' => $this->partnerId,
            'sign' => self::sign($this->partnerKey, $code, $serial),
            'command' => 'check',
        ], $this->format);
    }
}
