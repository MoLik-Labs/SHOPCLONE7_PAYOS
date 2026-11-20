<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}
/*
 * Social sign-in — contract test (no network, no database, no session).
 *
 *   php tests/oauth_test.php
 *
 * Every rule that protects the account boundary is pinned here:
 *   - PKCE is RFC 7636 (known vector), verifiers are random and long enough;
 *   - the Supabase URL/key normaliser refuses plain HTTP, embedded credentials, paths and private hosts;
 *   - the token exchange sends exactly auth_code + code_verifier, never a client secret;
 *   - a provider identity needs a UUID subject, a provider match and a valid email;
 *   - linking by email requires explicit opt-in AND a verified address;
 *   - admin / banned / 2FA accounts are refused on every path;
 *   - the settings form never echoes the stored key and never writes an unlisted field;
 *   - the redirect target is a fixed list, not whatever the query string says.
 */

define("IN_SITE", true);

if (!defined("BASE_URL")) {
    function BASE_URL($path = "")
    {
        return "https://shop.example/" . ltrim($path, "/");
    }
}

require_once __DIR__ . "/../libs/oauth.php";
require_once __DIR__ . "/../libs/oauth_http.php";

$pass = 0;
$fail = 0;
function check($label, $condition, $detail = "")
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  ok   " . $label . "\n";
    } else {
        $fail++;
        echo "  FAIL " . $label . ($detail !== "" ? "   >> " . $detail : "") . "\n";
    }
}

/* ---------------------------------------------------------------- stubs */

class OAuthSettingsStub
{
    public $rows = array();
    public $calls = 0;
    public $sql = array();

    public function get_list($sql)
    {
        $this->calls++;
        $this->sql[] = $sql;
        return $this->rows;
    }
}

class OAuthTableStub
{
    public $result;
    public $sql = "";

    public function query($sql)
    {
        $this->sql = $sql;
        return $this->result;
    }
}

class OAuthTableResult
{
    private $rows;

    public function __construct($rows)
    {
        $this->rows = $rows;
    }

    public function num_rows()
    {
        return $this->rows;
    }
}

/* --------------------------------------------------------------- PKCE */

/* RFC 7636 Appendix B: the one published verifier/challenge pair. */
$vector = oauth_pkce_pair("dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk");
check("the RFC 7636 verifier yields the RFC challenge",
    isset($vector["challenge"]) && $vector["challenge"] === "E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM");
check("the challenge method is S256", isset($vector["method"]) && $vector["method"] === "S256");
$pairA = oauth_pkce_pair();
$pairB = oauth_pkce_pair();
check("a generated verifier is 43–128 unreserved characters",
    strlen($pairA["verifier"]) >= 43 && strlen($pairA["verifier"]) <= 128
    && preg_match('/^[A-Za-z0-9._~-]+$/', $pairA["verifier"]) === 1);
check("the challenge is the base64url SHA-256 of the verifier",
    $pairA["challenge"] === rtrim(strtr(base64_encode(hash("sha256", $pairA["verifier"], true)), "+/", "-_"), "="));
check("two flows never share a verifier", $pairA["verifier"] !== $pairB["verifier"]);
check("a too-short verifier is refused", oauth_pkce_pair("short") === array());

/* ----------------------------------------------------------- URL / key */

check("a Supabase project URL is accepted", oauth_normalize_url("https://abcdefgh.supabase.co") === "https://abcdefgh.supabase.co");
check("a trailing slash is trimmed", oauth_normalize_url("https://abcdefgh.supabase.co/") === "https://abcdefgh.supabase.co");
check("plain http is refused", oauth_normalize_url("http://abcdefgh.supabase.co") === "");
check("a URL with a path is refused", oauth_normalize_url("https://abcdefgh.supabase.co/rest/v1") === "");
check("a URL with credentials is refused", oauth_normalize_url("https://user:pass@abcdefgh.supabase.co") === "");
check("a URL with a query string is refused", oauth_normalize_url("https://abcdefgh.supabase.co?x=1") === "");
check("localhost is refused", oauth_normalize_url("https://localhost") === "");
check("a loopback IP is refused", oauth_normalize_url("https://127.0.0.1") === "");
check("a private IP is refused", oauth_normalize_url("https://10.0.0.5") === "");
check("an internal hostname suffix is refused", oauth_normalize_url("https://db.internal") === "");
check("a host without a dot is refused", oauth_normalize_url("https://supabase") === "");

$publishable = "sb_publishable_" . str_repeat("A1b2C3", 6);
check("a publishable key is accepted", oauth_normalize_key($publishable) === $publishable);
$jwt = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9." . str_repeat("a", 40) . "." . str_repeat("b", 40);
check("a JWT-style anon key is accepted", oauth_normalize_key($jwt) === $jwt);
check("a key with a quote is refused", oauth_normalize_key("abc'def" . str_repeat("x", 30)) === "");
check("a key with a space is refused", oauth_normalize_key("abc def" . str_repeat("x", 30)) === "");
check("a key with markup is refused", oauth_normalize_key("<script>" . str_repeat("x", 30)) === "");
check("a short key is refused", oauth_normalize_key("abc") === "");

/* -------------------------------------------------------------- config */

$settingsStub = new OAuthSettingsStub();
$settingsStub->rows = array(
    array("name" => "oauth_enabled", "value" => "1"),
    array("name" => "oauth_google", "value" => "1"),
    array("name" => "oauth_github", "value" => "0"),
    array("name" => "oauth_supabase_url", "value" => "https://abcdefgh.supabase.co"),
    array("name" => "oauth_supabase_anon_key", "value" => $publishable),
    array("name" => "oauth_auto_create", "value" => "1"),
    array("name" => "oauth_link_by_email", "value" => "0"),
    array("name" => "title", "value" => "not ours"),
);
$config = oauth_config($settingsStub);
check("the whole setting group is read with one query", $settingsStub->calls === 1);
check("only the seven documented names are requested",
    substr_count($settingsStub->sql[0], "oauth_") === 7
    && strpos($settingsStub->sql[0], "`title`") === false);
check("the feature is enabled when the master switch, URL and key are present", $config["enabled"] === true);
check("Google is enabled and GitHub is not", oauth_provider_enabled($config, "google") === true
    && oauth_provider_enabled($config, "github") === false);
check("an unknown provider is never enabled", oauth_provider_enabled($config, "facebook") === false);
check("auto-create defaults on and email linking defaults off",
    $config["auto_create"] === true && $config["link_email"] === false);
$settingsStub->calls = 0;
$reused = oauth_config($settingsStub, oauth_raw_settings($settingsStub));
check("a caller that already loaded the rows causes no second query",
    $settingsStub->calls === 1 && $reused["enabled"] === true);

$offSettings = new OAuthSettingsStub();
$offSettings->rows = array();
$off = oauth_config($offSettings);
check("a fresh shop with no rows behaves as disabled", $off["enabled"] === false
    && $off["google"] === false && $off["github"] === false);
check("a missing row yields the documented default, not a notice",
    $off["raw"]["oauth_auto_create"] === "1" && $off["raw"]["oauth_link_by_email"] === "0");

$httpSettings = new OAuthSettingsStub();
$httpSettings->rows = array(
    array("name" => "oauth_enabled", "value" => "1"),
    array("name" => "oauth_supabase_url", "value" => "http://abcdefgh.supabase.co"),
    array("name" => "oauth_supabase_anon_key", "value" => $publishable),
);
check("an unsafe URL keeps the feature disabled even when switched on",
    oauth_config($httpSettings)["enabled"] === false);

/* --------------------------------------------------- authorize + token URLs */

$authorize = oauth_authorize_url($config, "google", "https://shop.example/client/oauth-callback", "CHALLENGE123");
check("the authorize URL points at the project's /auth/v1/authorize",
    strpos($authorize, "https://abcdefgh.supabase.co/auth/v1/authorize?") === 0);
check("it asks for the chosen provider", strpos($authorize, "provider=google") !== false);
check("it sends the PKCE challenge and method",
    strpos($authorize, "code_challenge=CHALLENGE123") !== false
    && strpos($authorize, "code_challenge_method=S256") !== false);
check("it carries the exact callback URL",
    strpos($authorize, urlencode("https://shop.example/client/oauth-callback")) !== false);
check("it contains no key material", strpos($authorize, "sb_publishable") === false
    && strpos($authorize, "anon") === false);
check("an unknown provider builds no URL",
    oauth_authorize_url($config, "facebook", "https://shop.example/cb", "x") === "");
check("the token endpoint is the PKCE grant",
    oauth_token_url($config) === "https://abcdefgh.supabase.co/auth/v1/token?grant_type=pkce");
check("the callback URL comes from BASE_URL, not the request",
    oauth_callback_url($config) === "https://shop.example/client/oauth-callback");

/* ------------------------------------------------------------ exchange */

$captured = array();
$transport = function ($url, $headers, $body, $timeout) use (&$captured) {
    $captured = array("url" => $url, "headers" => $headers, "body" => $body, "timeout" => $timeout);
    return array(
        "status" => 200,
        "body" => json_encode(array("user" => array(
            "id" => "3f0d4e8a-1b2c-4d5e-8f90-123456789abc",
            "email" => "User@Example.COM",
            "email_confirmed_at" => "2026-10-01T10:00:00Z",
            "app_metadata" => array("provider" => "google"),
            "user_metadata" => array("full_name" => "Nguyen Van A"),
        ))),
        "error" => "",
    );
};
$identity = oauth_exchange_code($config, "google", "abc123code", $pairA["verifier"], $transport);
check("a valid exchange returns an identity", !empty($identity["ok"]));
check("the exchange POSTs to the PKCE token endpoint",
    $captured["url"] === "https://abcdefgh.supabase.co/auth/v1/token?grant_type=pkce");
$sent = json_decode($captured["body"], true);
check("the body sends exactly auth_code and code_verifier",
    array_keys($sent) === array("auth_code", "code_verifier")
    && $sent["auth_code"] === "abc123code" && $sent["code_verifier"] === $pairA["verifier"]);
check("the anon key is the apikey header, not the body",
    in_array("apikey: " . $publishable, $captured["headers"], true)
    && strpos($captured["body"], "publishable") === false);
check("the email is normalised to lower case", $identity["identity"]["email"] === "user@example.com");
check("the subject is the provider UUID", $identity["identity"]["subject"] === "3f0d4e8a-1b2c-4d5e-8f90-123456789abc");
check("a confirmed address is marked verified", $identity["identity"]["email_verified"] === true);
check("the display name is carried over", $identity["identity"]["name"] === "Nguyen Van A");

$wrongProvider = function () {
    return array("status" => 200, "body" => json_encode(array("user" => array(
        "id" => "3f0d4e8a-1b2c-4d5e-8f90-123456789abc",
        "email" => "user@example.com",
        "email_confirmed_at" => "2026-10-01T10:00:00Z",
        "app_metadata" => array("provider" => "github"),
    ))), "error" => "");
};
$mismatch = oauth_exchange_code($config, "google", "abc123code", $pairA["verifier"], $wrongProvider);
check("a GitHub subject is refused when Google was requested",
    empty($mismatch["ok"]) && $mismatch["error"] === "provider_mismatch");

$noEmail = function () {
    return array("status" => 200, "body" => json_encode(array("user" => array(
        "id" => "3f0d4e8a-1b2c-4d5e-8f90-123456789abc",
        "email" => "",
        "app_metadata" => array("provider" => "google"),
    ))), "error" => "");
};
$missingEmail = oauth_exchange_code($config, "google", "abc123code", $pairA["verifier"], $noEmail);
check("a provider account without an email is refused",
    empty($missingEmail["ok"]) && $missingEmail["error"] === "email_missing");

$badSubject = function () {
    return array("status" => 200, "body" => json_encode(array("user" => array(
        "id" => "not-a-uuid", "email" => "user@example.com",
        "app_metadata" => array("provider" => "google"),
    ))), "error" => "");
};
check("a non-UUID subject is refused",
    oauth_exchange_code($config, "google", "abc123code", $pairA["verifier"], $badSubject)["error"] === "invalid_response");

$expired = function () {
    return array("status" => 400, "body" => '{"error":"invalid_grant"}', "error" => "");
};
check("a rejected code is reported as an expired exchange",
    oauth_exchange_code($config, "google", "abc123code", $pairA["verifier"], $expired)["error"] === "exchange");
$down = function () {
    return array("status" => 0, "body" => "", "error" => "timeout");
};
check("a transport failure is a server error, not a login",
    oauth_exchange_code($config, "google", "abc123code", $pairA["verifier"], $down)["error"] === "server");

$neverCalled = false;
$spy = function () use (&$neverCalled) {
    $neverCalled = true;
    return array("status" => 200, "body" => "{}", "error" => "");
};
check("a short verifier is refused before any network call",
    oauth_exchange_code($config, "google", "abc123code", "short", $spy)["error"] === "state" && $neverCalled === false);
check("an empty code is refused before any network call",
    oauth_exchange_code($config, "google", "", $pairA["verifier"], $spy)["error"] === "invalid_response" && $neverCalled === false);
check("HTML in the code is refused",
    oauth_exchange_code($config, "google", "<script>", $pairA["verifier"], $spy)["error"] === "invalid_response" && $neverCalled === false);

/* ----------------------------------------------------------- linking rules */

$user = array("id" => 7, "admin" => 0, "banned" => 0, "status_2fa" => 0, "status_otp_mail" => 0);
$admin = array("id" => 1, "admin" => 1, "banned" => 0, "status_2fa" => 0, "status_otp_mail" => 0);
$banned = array("id" => 9, "admin" => 0, "banned" => 1, "status_2fa" => 0, "status_otp_mail" => 0);
$twofa = array("id" => 11, "admin" => 0, "banned" => 0, "status_2fa" => 1, "status_otp_mail" => 0);
$identityCore = $identity["identity"];

$plan = oauth_link_plan($identityCore, $user, null, array());
check("an existing link logs in", $plan["action"] === "login" && $plan["user_id"] === 7);
check("an existing link to an admin account is refused",
    oauth_link_plan($identityCore, $admin, null, array())["reason"] === "account_admin");
check("an existing link to a banned account is refused",
    oauth_link_plan($identityCore, $banned, null, array())["reason"] === "account_banned");
check("an existing link to a 2FA account is refused",
    oauth_link_plan($identityCore, $twofa, null, array())["reason"] === "account_2fa");

check("an email match without opt-in is refused",
    oauth_link_plan($identityCore, null, $user, array("link_email" => false))["reason"] === "email_exists");
check("an email match with opt-in but an unverified address is refused",
    oauth_link_plan(array_merge($identityCore, array("email_verified" => false)), null, $user,
        array("link_email" => true))["reason"] === "email_unverified");
check("an email match with opt-in and a verified address links",
    oauth_link_plan($identityCore, null, $user, array("link_email" => true))["action"] === "link");
check("email linking can never attach to an admin account",
    oauth_link_plan($identityCore, null, $admin, array("link_email" => true))["reason"] === "account_admin");
check("a new verified identity creates an account when allowed",
    oauth_link_plan($identityCore, null, null, array("auto_create" => true))["action"] === "create");
check("a new identity is refused when auto-create is off",
    oauth_link_plan($identityCore, null, null, array("auto_create" => false))["reason"] === "no_account");
check("a malformed identity is refused without touching rows",
    oauth_link_plan(array("provider" => "google"), $user, null, array())["action"] === "refuse");

/* --------------------------------------------------------------- username */

check("a username is derived from the local part", oauth_username_base("Nguyen.Van+A@example.com") === "nguyen_van_a");
check("an address without usable letters falls back to a name",
    oauth_username_base("###", "Viktor") === "viktor");
check("a username is at most 24 characters", strlen(oauth_username_base(str_repeat("a", 40))) === 24);
$taken = array("nguyen" => true);
$unique = oauth_username_unique("nguyen", function ($name) use ($taken) {
    return !empty($taken[$name]);
}, function () {
    return "abcde";
});
check("a taken username gets a deterministic suffix", $unique === "nguyen_abcde");
check("a free username is kept as-is", oauth_username_unique("fresh", function () {
    return false;
}) === "fresh");
check("a generated username is still a valid local username",
    preg_match('/^[a-z0-9_]{1,24}$/', $unique) === 1);

/* ----------------------------------------------------------- new account */

$payload = oauth_user_payload($identityCore, "user_abcde", "2026-10-05 10:00:00", "203.0.113.9",
    str_repeat("Mozilla ", 40), "SECRET2FA");
check("a new account carries no privileged column",
    !array_key_exists("admin", $payload) && !array_key_exists("money", $payload)
    && !array_key_exists("ref_ck", $payload));
check("the account is created with the provider email", $payload["email"] === "user@example.com");
check("the username is exactly what was generated", $payload["username"] === "user_abcde");
check("the password column holds an unusable bcrypt hash, not the provider token",
    strpos($payload["password"], "$2y$") === 0 && password_verify("anything", $payload["password"]) === false);
check("the device string is bounded", strlen($payload["device"]) === 255);
check("the session token is long and random", strlen($payload["token"]) > 40 && $payload["token"] !== $payload["api_key"]);
check("the 2FA secret is the caller's, not a constant", $payload["SecretKey_2fa"] === "SECRET2FA");
check("the creation time is the shop's own format", $payload["create_date"] === "2026-10-05 10:00:00");

/* ---------------------------------------------------------------- flow */

$now = time();
$flow = array("provider" => "google", "verifier" => $pairA["verifier"], "return" => "client/home", "time" => $now);
check("a fresh flow passes the guard", oauth_flow_guard($flow, $now)["ok"] === true);
check("a flow older than ten minutes is refused",
    oauth_flow_guard(array_merge($flow, array("time" => $now - 601)), $now)["error"] === "state");
check("a flow from the future is refused",
    oauth_flow_guard(array_merge($flow, array("time" => $now + 120)), $now)["error"] === "state");
check("a flow with an unknown provider is refused",
    oauth_flow_guard(array_merge($flow, array("provider" => "facebook")), $now)["error"] === "state");
check("a flow without a verifier is refused",
    oauth_flow_guard(array("provider" => "google", "time" => $now), $now)["error"] === "state");
check("no flow at all is a state error", oauth_flow_guard(null, $now)["error"] === "state");

check("a known return page is kept", oauth_normalize_return("client/purchase-history") === "client/purchase-history");
check("an arbitrary return path is dropped", oauth_normalize_return("https://evil.example/") === "");
check("path traversal is dropped", oauth_normalize_return("../../etc/passwd") === "");
check("a return value cannot smuggle a query string", oauth_normalize_return("client/home?x=1") === "client/home");

/* -------------------------------------------------------------- settings */

$stored = array("url" => "https://old.supabase.co", "anon_key" => $publishable);
$saved = oauth_settings_validate(array(
    "oauth_enabled" => "1", "oauth_google" => "1", "oauth_auto_create" => "1",
    "oauth_supabase_url" => "https://new-project.supabase.co",
    "oauth_supabase_anon_key" => "",
    "unexpected_field" => "x",
), $stored);
check("a valid form saves", empty($saved["errors"]));
check("a blank key field keeps the stored key", $saved["values"]["oauth_supabase_anon_key"] === $publishable);
check("an unlisted POST field is not returned for writing",
    !array_key_exists("unexpected_field", oauth_settings_rows($saved["values"])));
check("exactly the seven documented settings rows are written",
    count(oauth_settings_rows($saved["values"])) === 7);
check("unchecked boxes are saved as 0, not empty strings",
    $saved["values"]["oauth_github"] === "0" && $saved["values"]["oauth_link_by_email"] === "0");
check("a URL with a path is rejected", !empty(oauth_settings_validate(
    array("oauth_supabase_url" => "https://x.supabase.co/rest"), $stored)["errors"]["url"]));
check("a quote in the key is rejected", !empty(oauth_settings_validate(
    array("oauth_supabase_anon_key" => "abc\"def" . str_repeat("x", 30)), $stored)["errors"]["key"]));
check("enabling without a URL/key is refused", !empty(oauth_settings_validate(
    array("oauth_enabled" => "1", "oauth_google" => "1"), array("url" => "", "anon_key" => ""))["errors"]["enabled"]));
check("a provider without the master switch is refused", !empty(oauth_settings_validate(
    array("oauth_google" => "1"), $stored)["errors"]["enabled"]));
check("the master switch without a provider is refused", !empty(oauth_settings_validate(
    array("oauth_enabled" => "1"), $stored)["errors"]["enabled"]));
check("a short key is masked completely", oauth_mask_secret("abcd") === "••••");
check("a long key shows only its edges", oauth_mask_secret($publishable) === substr($publishable, 0, 4)
    . "…" . substr($publishable, -4));

/* --------------------------------------------------------------- errors */

check("every known error code has its own sentence",
    oauth_error_text("email_exists") !== oauth_error_text("server")
    && oauth_known_error("email_exists"));
check("an unknown code gets the generic sentence", oauth_error_text("drop table") ===
    "Không thể đăng nhập. Vui lòng thử lại.");
check("no error sentence contains markup", strpos(oauth_error_text("<b>"), "<") === false);

/* -------------------------------------------------------------- settings table */

$tableStub = new OAuthTableStub();
$tableStub->result = new OAuthTableResult(1);
check("the mapping table is detected", oauth_table_exists($tableStub) === true);
$tableStub->result = new OAuthTableResult(0);
check("a missing mapping table is reported as missing", oauth_table_exists($tableStub) === false);
$tableStub->result = false;
check("a failed probe is not treated as present", oauth_table_exists($tableStub) === false);

/* --------------------------------------------------------------- buttons */

$buttons = oauth_buttons_markup($config);
check("an enabled provider renders one button", substr_count($buttons, 'rel="nofollow"') === 1);
check("the disabled provider is absent", strpos($buttons, "GitHub") === false && strpos($buttons, "github") === false);
check("the button links to the shop's own start page",
    strpos($buttons, 'href="https://shop.example/client/oauth-start?provider=google"') !== false);
check("the button is not a crawler target", strpos($buttons, 'rel="nofollow"') !== false);
check("no third-party script is loaded", strpos($buttons, "<script") === false && strpos($buttons, "http://") === false);
check("nothing renders when the feature is off", oauth_buttons_markup(oauth_config($offSettings)) === "");

/* ------------------------------------------------------- provider status */

$statusSpy = function ($url, $headers) {
    return array("status" => 200, "error" => "", "body" => json_encode(array(
        "external" => array("google" => true, "github" => false, "email" => true),
    )));
};
$status = oauth_provider_status($config, $statusSpy);
check("the settings endpoint is probed with the project key", $status["ok"] === true
    && $status["providers"]["google"] === true && $status["providers"]["github"] === false);
$statusDown = function () {
    return array("status" => 0, "error" => "timeout", "body" => "");
};
check("an unreachable project is a failed check, never a success",
    oauth_provider_status($config, $statusDown)["ok"] === false);
$statusWrongShape = function () {
    return array("status" => 200, "error" => "", "body" => '{"ok":true}');
};
check("a 200 that is not a GoTrue settings document is not reported as success",
    oauth_provider_status($config, $statusWrongShape)["ok"] === false);

$plusTransport = function () {
    return array("status" => 200, "error" => "", "body" => json_encode(array("user" => array(
        "id" => "3f0d4e8a-1b2c-4d5e-8f90-123456789abc",
        "email" => "user@example.com",
        "email_confirmed_at" => "2026-10-01T10:00:00Z",
        "app_metadata" => array("provider" => "google"),
    ))));
};
check("a base64 code containing + / = is not rejected before the exchange",
    !empty(oauth_exchange_code($config, "google", "ab+cd/ef==", $pairA["verifier"], $plusTransport)["ok"]));

echo "\noauth_test: " . $pass . " passed, " . $fail . " failed\n";
exit($fail > 0 ? 1 : 0);
