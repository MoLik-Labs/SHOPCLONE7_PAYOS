<?php
/*
 * The only place the social-login code touches the network.
 *
 * Rules (each one is a defect this shop has seen elsewhere):
 *   - HTTPS only, certificate and hostname verified; TLS verification is never disabled.
 *   - Redirects are refused (`CURLOPT_FOLLOWLOCATION` off): a 302 to another host would carry the
 *     bearer header the caller passed in.
 *   - Connect and total timeouts are short; the visitor is waiting on a login, not a download.
 *   - The response body is capped, so a hostile/broken endpoint cannot exhaust PHP memory.
 *   - Nothing is retried and no response text is returned to the browser by this layer; callers get
 *     a status code and a body to parse.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

if (!defined("OAUTH_HTTP_MAX_BYTES")) {
    define("OAUTH_HTTP_MAX_BYTES", 262144); /* 256 KiB is far beyond any GoTrue response */
}

if (!function_exists("oauth_http_request")) {
    /**
     * @return array status (0 on transport failure), body (string), error (code: ""|curl|tls|too_large|timeout)
     */
    function oauth_http_request($method, $url, $headers, $body = null, $timeout = 10)
    {
        $method = strtoupper((string) $method);
        if ($method !== "GET" && $method !== "POST") {
            return array("status" => 0, "body" => "", "error" => "method");
        }
        if (!preg_match('~^https://[A-Za-z0-9](?:[A-Za-z0-9.-]{0,61}[A-Za-z0-9])?(?::\d{2,5})?/.*$~', (string) $url)) {
            return array("status" => 0, "body" => "", "error" => "url");
        }
        if (!function_exists("curl_init")) {
            return array("status" => 0, "body" => "", "error" => "curl");
        }
        $timeout = max(3, min(20, (int) $timeout));
        $buffer = "";
        $tooLarge = false;
        $curl = curl_init();
        $options = array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => array_values((array) $headers),
            CURLOPT_USERAGENT => "OrbitShop/1.0 (+social-login)",
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$buffer, &$tooLarge) {
                if (strlen($buffer) + strlen($chunk) > OAUTH_HTTP_MAX_BYTES) {
                    $tooLarge = true;
                    return 0; /* aborts the transfer */
                }
                $buffer .= $chunk;
                return strlen($chunk);
            },
        );
        if ($method === "POST") {
            $options[CURLOPT_POSTFIELDS] = (string) $body;
        }
        curl_setopt_array($curl, $options);
        curl_exec($curl);
        $errno = curl_errno($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($tooLarge) {
            return array("status" => 0, "body" => "", "error" => "too_large");
        }
        if ($errno === 28) {
            return array("status" => 0, "body" => "", "error" => "timeout");
        }
        if ($errno !== 0) {
            return array("status" => 0, "body" => "", "error" => "curl");
        }
        return array("status" => $status, "body" => $buffer, "error" => "");
    }
}

if (!function_exists("oauth_http_post_json")) {
    function oauth_http_post_json($url, $headers, $body, $timeout = 10)
    {
        return oauth_http_request("POST", $url, $headers, $body, $timeout);
    }
}

if (!function_exists("oauth_http_get_json")) {
    function oauth_http_get_json($url, $headers, $timeout = 8)
    {
        return oauth_http_request("GET", $url, $headers, null, $timeout);
    }
}

if (!function_exists("oauth_provider_status")) {
    /**
     * Ask Supabase which external providers are switched on. Used by the admin "test connection"
     * button, never on the login page. A provider that is configured in the shop but disabled in
     * Supabase produces a button that fails — this is how the admin finds out before a customer does.
     *
     * @return array ok (bool), message (code), providers (array of name => bool)
     */
    function oauth_provider_status($config, $transport = null)
    {
        $providers = array("google" => false, "github" => false);
        if ($config["url"] === "" || $config["anon_key"] === "") {
            return array("ok" => false, "message" => "config", "providers" => $providers);
        }
        if ($transport === null) {
            $transport = "oauth_http_get_json";
        }
        $response = call_user_func($transport, oauth_settings_url($config), array(
            "apikey: " . $config["anon_key"],
            "Accept: application/json",
        ));
        if (!is_array($response) || (int) $response["status"] !== 200) {
            $message = (is_array($response) && isset($response["error"]) && $response["error"] !== "")
                ? $response["error"] : "server";
            return array("ok" => false, "message" => $message === "curl" || $message === "timeout"
                ? "server" : "server", "providers" => $providers);
        }
        $decoded = json_decode((string) $response["body"], true);
        if (!is_array($decoded)) {
            return array("ok" => false, "message" => "invalid_response", "providers" => $providers);
        }
        if (!isset($decoded["external"]) || !is_array($decoded["external"])) {
            /* Reachable but not a GoTrue settings document: report a failed check rather than
               claiming both providers are off. */
            return array("ok" => false, "message" => "invalid_response", "providers" => $providers);
        }
        foreach ($providers as $name => $unused) {
            $providers[$name] = !empty($decoded["external"][$name]);
        }
        return array("ok" => true, "message" => "", "providers" => $providers);
    }
}
