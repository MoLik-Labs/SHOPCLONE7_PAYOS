<?php
/*
 * Social sign-in (Google / GitHub) through Supabase Auth — configuration, PKCE and linking rules.
 *
 * WHY THIS FILE IS PURE
 *   Everything here takes arrays and returns arrays: which settings are valid, how an authorize URL
 *   is built, how a token response is read, whether an identity may be linked to a local account.
 *   None of it opens a socket or a database row, so the security rules can be tested without a
 *   network, a MySQL server or a Supabase project. The side effects live in
 *   views/client/oauth-start.php, views/client/oauth-callback.php and libs/oauth_http.php.
 *
 * WHAT THE SHOP TRUSTS, AND WHAT IT DOES NOT
 *   1. Google/GitHub client secrets never reach this code. Supabase holds them; the shop only holds
 *      the project URL and the publishable/anon key (which is designed to be public).
 *   2. The authorization code is exchanged server-to-server over HTTPS. The response is therefore
 *      authenticated by TLS to the configured project — no JWT signature check is needed, and no
 *      token is trusted from the browser.
 *   3. The PKCE verifier lives in the PHP session, so only the browser that started the flow can
 *      finish it. Supabase does not reliably echo a custom `state` back, so PKCE (plus a single-use
 *      session record) is the CSRF control here — see docs/frontend/11-social-login-performance-security.md.
 *   4. A provider identity is (provider, subject). Email is only used to LINK to an existing local
 *      account when the admin explicitly enabled it, and only when the provider marked it verified.
 *   5. Admin accounts, banned accounts and accounts with 2FA / mail-OTP are never entered through
 *      this path. Social sign-in is a convenience for customers, not a bypass of stronger checks.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

if (!defined("OAUTH_SETTINGS")) {
    /* name in `settings` => default. Kept small: three columns' worth of state, no secrets. */
    define("OAUTH_SETTINGS", array(
        "oauth_enabled" => "0",
        "oauth_google" => "0",
        "oauth_github" => "0",
        "oauth_supabase_url" => "",
        "oauth_supabase_anon_key" => "",
        "oauth_auto_create" => "1",
        "oauth_link_by_email" => "0",
    ));
}

/* ------------------------------------------------------------------ helpers */

if (!function_exists("oauth_base64url")) {
    /** RFC 4648 §5, without padding — the encoding PKCE requires. */
    function oauth_base64url($bytes)
    {
        return rtrim(strtr(base64_encode((string) $bytes), "+/", "-_"), "=");
    }
}

if (!function_exists("oauth_random_token")) {
    /** A cryptographically random token; never falls back to rand()/uniqid(). */
    function oauth_random_token($bytes = 32)
    {
        $bytes = (int) $bytes;
        if ($bytes < 16 || $bytes > 96) {
            $bytes = 32;
        }
        return bin2hex(random_bytes($bytes));
    }
}

if (!function_exists("oauth_providers")) {
    /** The only providers this build understands. Adding one means adding a label and an icon. */
    function oauth_providers()
    {
        return array("google" => "Google", "github" => "GitHub");
    }
}

if (!function_exists("oauth_normalize_provider")) {
    function oauth_normalize_provider($provider)
    {
        $provider = strtolower(trim((string) $provider));
        return isset(oauth_providers()[$provider]) ? $provider : "";
    }
}

if (!function_exists("oauth_provider_enabled")) {
    function oauth_provider_enabled($config, $provider)
    {
        $provider = oauth_normalize_provider($provider);
        if ($provider === "" || empty($config["enabled"])) {
            return false;
        }
        return !empty($config[$provider]);
    }
}

/* ------------------------------------------------------------------- config */

if (!function_exists("oauth_setting_query")) {
    /**
     * The one query that loads all seven values. Identifiers are the constants above — no request
     * text can reach this string — so it is safe to run without a prepared statement in the
     * legacy DB layer.
     */
    function oauth_setting_query()
    {
        $names = array();
        foreach (array_keys(OAUTH_SETTINGS) as $name) {
            $names[] = "'" . $name . "'";
        }
        return "SELECT `name`, `value` FROM `settings` WHERE `name` IN (" . implode(",", $names) . ")";
    }
}

if (!function_exists("oauth_raw_settings")) {
    /**
     * Read the seven setting values. A missing row is the default, not a fatal error: a fresh shop
     * that never opened the tab behaves exactly like one with every switch off.
     *
     * This used to call site() once per key — seven round trips on every login and register render.
     * It now asks for the whole group in one query (still constant identifiers, never request text).
     */
    function oauth_raw_settings($cmsnt)
    {
        $values = array();
        foreach (OAUTH_SETTINGS as $name => $default) {
            $values[$name] = $default;
        }
        if (!is_object($cmsnt)) {
            return $values;
        }
        if (method_exists($cmsnt, "get_list")) {
            $rows = $cmsnt->get_list(oauth_setting_query());
            foreach ((array) $rows as $row) {
                if (isset($row["name"]) && array_key_exists($row["name"], $values)) {
                    $values[$row["name"]] = (string) $row["value"];
                }
            }
        } elseif (method_exists($cmsnt, "site")) {
            foreach (OAUTH_SETTINGS as $name => $default) {
                $values[$name] = (string) $cmsnt->site($name);
            }
        }
        foreach (OAUTH_SETTINGS as $name => $default) {
            if ($values[$name] === "") {
                $values[$name] = $default;
            }
        }
        return $values;
    }
}

if (!function_exists("oauth_config")) {
    /**
     * Normalised configuration used by the pages. Invalid values are treated as "not configured"
     * rather than passed on, so a typo can never produce a redirect to a half-built URL.
     */
    function oauth_config($cmsnt, $raw = null)
    {
        $raw = is_array($raw) ? $raw : oauth_raw_settings($cmsnt);
        $url = oauth_normalize_url($raw["oauth_supabase_url"]);
        $key = oauth_normalize_key($raw["oauth_supabase_anon_key"]);
        return array(
            "enabled" => $raw["oauth_enabled"] === "1" && $url !== "" && $key !== "",
            "google" => $raw["oauth_google"] === "1",
            "github" => $raw["oauth_github"] === "1",
            "url" => $url,
            "anon_key" => $key,
            "auto_create" => $raw["oauth_auto_create"] !== "0",
            "link_email" => $raw["oauth_link_by_email"] === "1",
            "raw" => $raw,
        );
    }
}

if (!function_exists("oauth_normalize_url")) {
    /**
     * A Supabase project URL: https, a real hostname, no path/query/credentials, no private target.
     * Returns "" for anything else. Refusing http:// is deliberate — the anon key and the auth code
     * travel over this connection.
     */
    function oauth_normalize_url($url)
    {
        $url = rtrim(trim((string) $url), "/");
        if ($url === "" || strlen($url) > 200) {
            return "";
        }
        if (!preg_match('~^https://([A-Za-z0-9](?:[A-Za-z0-9.-]{0,61}[A-Za-z0-9])?)(?::(\d{2,5}))?$~', $url, $match)) {
            return "";
        }
        $host = strtolower($match[1]);
        if (strpos($host, ".") === false) {
            return "";
        }
        foreach (array(".localhost", ".local", ".internal", ".home.arpa") as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                return "";
            }
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $public = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($public === false) {
                return "";
            }
        }
        return $url;
    }
}

if (!function_exists("oauth_normalize_key")) {
    /**
     * Supabase's publishable/anon key. New projects use `sb_publishable_…`; older ones use a JWT.
     * Both are dot/dash/underscore/alphanumeric. A key containing quotes or spaces is refused, which
     * also means it can never disturb the (legacy) SQL that stores settings.
     */
    function oauth_normalize_key($key)
    {
        $key = trim((string) $key);
        if ($key === "" || strlen($key) < 20 || strlen($key) > 512) {
            return "";
        }
        return preg_match('/^[A-Za-z0-9._-]+$/', $key) ? $key : "";
    }
}

if (!function_exists("oauth_table_exists")) {
    /**
     * Is the mapping table installed? Uses query() rather than get_row(): the legacy get_row() exits
     * the request on SQL error, and a missing table must produce a warning in Settings, not a blank
     * admin page.
     */
    function oauth_table_exists($cmsnt)
    {
        if (!is_object($cmsnt) || !method_exists($cmsnt, "query")) {
            return false;
        }
        $result = $cmsnt->query("SHOW TABLES LIKE 'oauth_identities'");
        if (!$result || !is_object($result)) {
            return false;
        }
        if (method_exists($result, "num_rows")) {
            return (int) $result->num_rows() > 0;
        }
        if (isset($result->num_rows)) {
            return (int) $result->num_rows > 0;
        }
        return false;
    }
}

if (!function_exists("oauth_callback_url")) {
    /** The one URL that must be allow-listed in Supabase. Derived, never stored. */
    function oauth_callback_url($config = array())
    {
        return BASE_URL("client/oauth-callback");
    }
}

if (!function_exists("oauth_authorize_url")) {
    function oauth_authorize_url($config, $provider, $redirectTo, $challenge)
    {
        $provider = oauth_normalize_provider($provider);
        if ($provider === "" || $config["url"] === "" || $challenge === "") {
            return "";
        }
        $query = http_build_query(array(
            "provider" => $provider,
            "redirect_to" => $redirectTo,
            "code_challenge" => $challenge,
            "code_challenge_method" => "S256",
        ), "", "&", PHP_QUERY_RFC3986);
        return $config["url"] . "/auth/v1/authorize?" . $query;
    }
}

if (!function_exists("oauth_token_url")) {
    function oauth_token_url($config)
    {
        return $config["url"] . "/auth/v1/token?grant_type=pkce";
    }
}

if (!function_exists("oauth_settings_url")) {
    function oauth_settings_url($config)
    {
        return $config["url"] . "/auth/v1/settings";
    }
}

/* --------------------------------------------------------------------- PKCE */

if (!function_exists("oauth_pkce_pair")) {
    /**
     * RFC 7636 S256 pair. A verifier must be 43–128 characters from the unreserved set; 32 random
     * bytes become 43 base64url characters. $verifier exists for the RFC test vector, not for prod.
     */
    function oauth_pkce_pair($verifier = null)
    {
        if ($verifier === null) {
            $verifier = oauth_base64url(random_bytes(48)); /* 64 chars */
        }
        $verifier = (string) $verifier;
        if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)) {
            return array();
        }
        return array(
            "verifier" => $verifier,
            "challenge" => oauth_base64url(hash("sha256", $verifier, true)),
            "method" => "S256",
        );
    }
}

/* --------------------------------------------------------------- token parsing */

if (!function_exists("oauth_valid_uuid")) {
    function oauth_valid_uuid($value)
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $value);
    }
}

if (!function_exists("oauth_valid_email")) {
    /**
     * Stricter than filter_var on purpose: the address is interpolated into a legacy SQL string
     * after this check, so the allowed set excludes quotes and control characters by construction.
     */
    function oauth_valid_email($email)
    {
        $email = (string) $email;
        if ($email === "" || strlen($email) > 190) {
            return false;
        }
        return (bool) preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/', $email);
    }
}

if (!function_exists("oauth_exchange_requires_email")) {
    /* Kept as a function so the rule is visible in tests and docs. */
    function oauth_exchange_requires_email()
    {
        return true;
    }
}

if (!function_exists("oauth_identity_from_supabase_user")) {
    /**
     * Turn a GoTrue user object into the identity this shop records. Refuses rather than guesses:
     *  - id must be a UUID (it is the stable subject)
     *  - the provider recorded by Supabase must be the one the browser asked for (no silent
     *    provider swapping; app_metadata is server-owned and not user-editable)
     *  - an email is required, because the local account needs one and support needs a contact
     *  - email_verified is only true when Supabase says the address is confirmed; some providers
     *    (GitHub) can expose an address the user typed, so this is not assumed.
     */
    function oauth_identity_from_supabase_user($user, $expectedProvider)
    {
        if (!is_array($user)) {
            return array("ok" => false, "error" => "invalid_response");
        }
        $provider = oauth_normalize_provider($expectedProvider);
        if ($provider === "") {
            return array("ok" => false, "error" => "provider");
        }
        $actual = "";
        if (isset($user["app_metadata"]["provider"])) {
            $actual = oauth_normalize_provider($user["app_metadata"]["provider"]);
        }
        if ($actual !== "" && $actual !== $provider) {
            return array("ok" => false, "error" => "provider_mismatch");
        }
        $subject = isset($user["id"]) ? (string) $user["id"] : "";
        if (!oauth_valid_uuid($subject)) {
            return array("ok" => false, "error" => "invalid_response");
        }
        $email = isset($user["email"]) ? strtolower(trim((string) $user["email"])) : "";
        if (!oauth_valid_email($email)) {
            return array("ok" => false, "error" => "email_missing");
        }
        $verified = !empty($user["email_confirmed_at"]) || !empty($user["confirmed_at"]);
        $name = "";
        if (isset($user["user_metadata"]["full_name"])) {
            $name = (string) $user["user_metadata"]["full_name"];
        } elseif (isset($user["user_metadata"]["name"])) {
            $name = (string) $user["user_metadata"]["name"];
        } elseif (isset($user["user_metadata"]["user_name"])) {
            $name = (string) $user["user_metadata"]["user_name"];
        }
        return array(
            "ok" => true,
            "identity" => array(
                "provider" => $provider,
                "subject" => strtolower($subject),
                "email" => $email,
                "email_verified" => $verified,
                "name" => trim(substr($name, 0, 120)),
            ),
        );
    }
}

if (!function_exists("oauth_exchange_code")) {
    /**
     * Exchange the one-time code for a session. $transport is injectable so tests can prove what is
     * sent and how every answer is interpreted without touching the network.
     *
     * Returns array(ok, error, identity). Errors are codes; the human text comes from
     * oauth_error_text() so no provider message is ever echoed to the visitor.
     */
    function oauth_exchange_code($config, $provider, $code, $verifier, $transport = null)
    {
        $provider = oauth_normalize_provider($provider);
        if ($provider === "" || $config["url"] === "" || $config["anon_key"] === "") {
            return array("ok" => false, "error" => "config");
        }
        $code = trim((string) $code);
        /* Base64url and base64 alphabets together: the code is only ever a JSON value downstream. */
        if ($code === "" || strlen($code) > 512 || !preg_match('/^[A-Za-z0-9._~+\/=-]+$/', $code)) {
            return array("ok" => false, "error" => "invalid_response");
        }
        if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', (string) $verifier)) {
            return array("ok" => false, "error" => "state");
        }
        if ($transport === null) {
            require_once __DIR__ . "/oauth_http.php";
            $transport = "oauth_http_post_json";
        }
        $payload = json_encode(array("auth_code" => $code, "code_verifier" => $verifier));
        $response = call_user_func(
            $transport,
            oauth_token_url($config),
            array(
                "apikey: " . $config["anon_key"],
                "Authorization: Bearer " . $config["anon_key"],
                "Content-Type: application/json",
                "Accept: application/json",
            ),
            $payload,
            10
        );
        if (!is_array($response) || !isset($response["status"])) {
            return array("ok" => false, "error" => "server");
        }
        if ((int) $response["status"] !== 200) {
            /* 400/401 means the code or verifier was rejected/expired; anything else is transport. */
            $code_status = (int) $response["status"];
            return array("ok" => false, "error" => ($code_status === 400 || $code_status === 401 || $code_status === 403)
                ? "exchange" : "server");
        }
        $decoded = json_decode((string) $response["body"], true);
        if (!is_array($decoded) || !isset($decoded["user"])) {
            return array("ok" => false, "error" => "invalid_response");
        }
        return oauth_identity_from_supabase_user($decoded["user"], $provider);
    }
}

/* -------------------------------------------------------------- account rules */

if (!function_exists("oauth_link_plan")) {
    /**
     * Decide what a verified identity may do, from rows the caller already fetched.
     *
     *   $identity       = the array oauth_identity_from_supabase_user() returned
     *   $userBySubject  = users row linked to this (provider, subject), or null
     *   $userByEmail    = users row with the same email, or null
     *   $options        = array("link_email" => bool, "auto_create" => bool)
     *
     * Actions: login | link | create | refuse (with a reason code).
     * Admin rows are refused even for login: an operator's account must not be reachable with a
     * Google password, and this is also what stops an email collision from becoming privilege
     * escalation. 2FA/mail-OTP accounts are refused for the same reason.
     */
    function oauth_link_plan($identity, $userBySubject, $userByEmail, $options = array())
    {
        if (!is_array($identity) || empty($identity["provider"]) || empty($identity["subject"])) {
            return array("action" => "refuse", "reason" => "invalid_response", "user_id" => null);
        }
        $guarded = function ($user) {
            if (empty($user)) {
                return "";
            }
            if ((int) (isset($user["admin"]) ? $user["admin"] : 0) !== 0) {
                return "account_admin";
            }
            if ((int) (isset($user["banned"]) ? $user["banned"] : 0) !== 0) {
                return "account_banned";
            }
            if (!empty($user["status_2fa"]) || !empty($user["status_otp_mail"])) {
                return "account_2fa";
            }
            return "";
        };

        if (!empty($userBySubject)) {
            $reason = $guarded($userBySubject);
            if ($reason !== "") {
                return array("action" => "refuse", "reason" => $reason,
                    "user_id" => isset($userBySubject["id"]) ? (int) $userBySubject["id"] : null);
            }
            return array("action" => "login", "reason" => "",
                "user_id" => isset($userBySubject["id"]) ? (int) $userBySubject["id"] : null);
        }

        if (!empty($userByEmail)) {
            $reason = $guarded($userByEmail);
            if ($reason !== "") {
                return array("action" => "refuse", "reason" => $reason,
                    "user_id" => isset($userByEmail["id"]) ? (int) $userByEmail["id"] : null);
            }
            if (empty($options["link_email"])) {
                return array("action" => "refuse", "reason" => "email_exists", "user_id" => null);
            }
            if (empty($identity["email_verified"])) {
                return array("action" => "refuse", "reason" => "email_unverified", "user_id" => null);
            }
            return array("action" => "link", "reason" => "",
                "user_id" => isset($userByEmail["id"]) ? (int) $userByEmail["id"] : null);
        }

        if (!empty($options["auto_create"])) {
            return array("action" => "create", "reason" => "", "user_id" => null);
        }
        return array("action" => "refuse", "reason" => "no_account", "user_id" => null);
    }
}

if (!function_exists("oauth_username_base")) {
    /**
     * A readable username from the address (or display name), strictly [a-z0-9_], at most 24 chars.
     * The local part is kept, a +tag is flattened rather than dropped so two addresses rarely collide.
     */
    function oauth_username_base($email, $name = "")
    {
        $clean = function ($value) {
            $value = strtolower(trim((string) $value));
            $value = preg_replace('/[^a-z0-9]+/', "_", $value);
            $value = trim((string) $value, "_");
            return $value;
        };
        $source = strtolower(trim((string) $email));
        $at = strpos($source, "@");
        if ($at !== false) {
            $source = substr($source, 0, $at);
        }
        $source = $clean($source);
        if ($source === "" || !preg_match('/[a-z0-9]/', $source)) {
            /* The address may be numeric-only (12345@…) or unusable; the display name is a better base. */
            $source = $clean($name);
        }
        if ($source === "" || !preg_match('/[a-z0-9]/', $source)) {
            $source = "user";
        }
        return substr($source, 0, 24);
    }
}

if (!function_exists("oauth_username_unique")) {
    /**
     * Try the base, then base_xxxx with random lowercase/digit suffixes. $exists is a callable that
     * answers "is this username taken?" so the caller owns the query (and tests stay pure).
     */
    function oauth_username_unique($base, $exists, $suffixFactory = null)
    {
        $base = oauth_username_base($base);
        if (!is_callable($exists)) {
            return $base;
        }
        if (!call_user_func($exists, $base)) {
            return $base;
        }
        $factory = is_callable($suffixFactory) ? $suffixFactory : function () {
            return substr(oauth_random_token(6), 0, 5); /* 10 hex chars */
        };
        for ($i = 0; $i < 6; $i++) {
            $candidate = substr($base, 0, 19) . "_" . substr((string) call_user_func($factory), 0, 5);
            $candidate = preg_replace('/[^a-z0-9_]/', '', strtolower($candidate));
            if ($candidate !== "" && !call_user_func($exists, $candidate)) {
                return $candidate;
            }
        }
        return substr($base, 0, 14) . "_" . substr(oauth_random_token(8), 0, 9);
    }
}

if (!function_exists("oauth_user_payload")) {
    /**
     * The columns a social account is created with.
     *
     * Deliberately absent: admin, money, ref_id, status flags. Registration in the shop writes no
     * privileged column, and neither does this — a new social account is an ordinary customer.
     * The password is a random bcrypt hash, not a usable password: the account is entered through
     * the provider, and password login with an unknown value still fails closed.
     */
    function oauth_user_payload($identity, $username, $now, $ip, $device, $secret = "")
    {
        $identity = (array) $identity;
        $token = oauth_random_token(32) . time();
        if ($secret === "") {
            $secret = oauth_random_token(10);
        }
        return array(
            "ref_id" => 0,
            "utm_source" => "social",
            "token" => $token,
            "username" => (string) $username,
            "email" => isset($identity["email"]) ? (string) $identity["email"] : "",
            "password" => password_hash(oauth_random_token(32), PASSWORD_BCRYPT),
            "ip" => (string) $ip,
            "device" => substr((string) $device, 0, 255),
            "create_date" => (string) $now,
            "update_date" => (string) $now,
            "time_session" => time(),
            "api_key" => md5(oauth_random_token(16)),
            "SecretKey_2fa" => (string) $secret,
        );
    }
}

/* ----------------------------------------------------------------- settings UI */

if (!function_exists("oauth_mask_secret")) {
    function oauth_mask_secret($value)
    {
        $value = (string) $value;
        $length = strlen($value);
        if ($length === 0) {
            return "";
        }
        if ($length <= 12) {
            return str_repeat("•", $length);
        }
        return substr($value, 0, 4) . "…" . substr($value, -4);
    }
}

if (!function_exists("oauth_settings_validate")) {
    /**
     * Validate the admin form and return array(values, errors, changed_keys).
     *
     * An empty key field means "keep the stored key": the input is never pre-filled, so the key does
     * not appear in the admin HTML after it has been saved. All other empty values fall back to the
     * stored value too, except the checkboxes, which are genuinely binary.
     */
    function oauth_settings_validate($input, $existing = array())
    {
        $existing = array_merge(array("url" => "", "anon_key" => ""), (array) $existing);
        $input = (array) $input;
        $errors = array();
        $values = array();
        $values["oauth_enabled"] = !empty($input["oauth_enabled"]) ? "1" : "0";
        $values["oauth_google"] = !empty($input["oauth_google"]) ? "1" : "0";
        $values["oauth_github"] = !empty($input["oauth_github"]) ? "1" : "0";
        $values["oauth_auto_create"] = !empty($input["oauth_auto_create"]) ? "1" : "0";
        $values["oauth_link_by_email"] = !empty($input["oauth_link_by_email"]) ? "1" : "0";

        $urlInput = trim((string) (isset($input["oauth_supabase_url"]) ? $input["oauth_supabase_url"] : ""));
        if ($urlInput === "") {
            $values["oauth_supabase_url"] = (string) $existing["url"];
        } else {
            $url = oauth_normalize_url($urlInput);
            if ($url === "") {
                $errors["url"] = "invalid_url";
            } else {
                $values["oauth_supabase_url"] = $url;
            }
        }

        $keyInput = trim((string) (isset($input["oauth_supabase_anon_key"]) ? $input["oauth_supabase_anon_key"] : ""));
        if ($keyInput === "") {
            $values["oauth_supabase_anon_key"] = (string) $existing["anon_key"];
        } else {
            $key = oauth_normalize_key($keyInput);
            if ($key === "") {
                $errors["key"] = "invalid_key";
            } else {
                $values["oauth_supabase_anon_key"] = $key;
            }
        }

        if (empty($errors["url"]) && empty($errors["key"])) {
            $ready = $values["oauth_supabase_url"] !== "" && $values["oauth_supabase_anon_key"] !== "";
            $anyProvider = $values["oauth_google"] === "1" || $values["oauth_github"] === "1";
            if ($values["oauth_enabled"] === "1" && !$ready) {
                $errors["enabled"] = "missing_config";
            }
            if ($anyProvider && $values["oauth_enabled"] !== "1") {
                $errors["enabled"] = "provider_without_master";
            }
            if ($values["oauth_enabled"] === "1" && !$anyProvider) {
                $errors["enabled"] = "no_provider";
            }
        }
        return array("values" => $values, "errors" => $errors);
    }
}

if (!function_exists("oauth_settings_rows")) {
    /** The exact seven rows the save handler writes. Anything else in $_POST is ignored. */
    function oauth_settings_rows($values)
    {
        $rows = array();
        foreach (OAUTH_SETTINGS as $name => $default) {
            $rows[$name] = isset($values[$name]) ? (string) $values[$name] : $default;
        }
        return $rows;
    }
}

if (!function_exists("oauth_identity_payload")) {
    /** The row inserted into oauth_identities. Values are already validated by the caller. */
    function oauth_identity_payload($identity, $userId, $now)
    {
        return array(
            "provider" => (string) $identity["provider"],
            "subject" => (string) $identity["subject"],
            "user_id" => (int) $userId,
            "email" => (string) $identity["email"],
            "email_verified" => !empty($identity["email_verified"]) ? 1 : 0,
            "created_at" => (string) $now,
            "last_login_at" => (string) $now,
        );
    }
}

if (!function_exists("oauth_flow_guard")) {
    /**
     * Validate the single-use flow record the start page left in the session.
     *
     * The record is read once and deleted by the callback before this runs, so a second use of the
     * same code finds nothing. Ten minutes is the window; the verifier is 43–128 unreserved chars.
     */
    function oauth_flow_guard($flow, $now, $ttl = 600)
    {
        if (!is_array($flow)) {
            return array("ok" => false, "error" => "state");
        }
        $provider = oauth_normalize_provider(isset($flow["provider"]) ? $flow["provider"] : "");
        $verifier = isset($flow["verifier"]) ? (string) $flow["verifier"] : "";
        $started = isset($flow["time"]) ? (int) $flow["time"] : 0;
        if ($provider === "" || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)) {
            return array("ok" => false, "error" => "state");
        }
        if ($started <= 0 || $now - $started > (int) $ttl || $started > $now + 60) {
            return array("ok" => false, "error" => "state");
        }
        return array("ok" => true, "error" => "", "provider" => $provider, "verifier" => $verifier);
    }
}

if (!function_exists("oauth_known_error")) {
    function oauth_known_error($code)
    {
        $known = array("disabled", "provider", "state", "denied", "exchange", "invalid_response",
            "provider_mismatch", "email_missing", "email_unverified", "email_exists", "no_account",
            "account_admin", "account_banned", "account_2fa", "rate_limited", "config", "server");
        return in_array((string) $code, $known, true);
    }
}

if (!function_exists("oauth_error_text")) {
    /**
     * One sentence per failure code. Provider/server text is never included: it can contain the
     * project URL or a stack trace, and the visitor cannot act on it anyway.
     */
    function oauth_error_text($code)
    {
        $map = array(
            "disabled" => "Đăng nhập bằng Google/GitHub hiện chưa được bật.",
            "provider" => "Phương thức đăng nhập không hợp lệ.",
            "state" => "Phiên đăng nhập đã hết hạn. Vui lòng thử lại.",
            "denied" => "Bạn đã huỷ đăng nhập.",
            "exchange" => "Mã đăng nhập không còn hiệu lực. Vui lòng thử lại.",
            "invalid_response" => "Nhà cung cấp đăng nhập trả về dữ liệu không hợp lệ.",
            "provider_mismatch" => "Tài khoản không thuộc nhà cung cấp đã chọn.",
            "email_missing" => "Tài khoản Google/GitHub không có email công khai. Hãy dùng email/mật khẩu.",
            "email_unverified" => "Email của tài khoản mạng xã hội chưa được xác minh.",
            "email_exists" => "Email này đã có tài khoản tại shop. Hãy đăng nhập bằng mật khẩu.",
            "no_account" => "Chưa có tài khoản cho email này. Vui lòng đăng ký trước.",
            "account_admin" => "Không thể dùng đăng nhập mạng xã hội cho tài khoản quản trị.",
            "account_banned" => "Tài khoản đang bị khoá.",
            "account_2fa" => "Tài khoản đang bật 2FA/OTP. Hãy đăng nhập bằng mật khẩu để xác minh.",
            "rate_limited" => "Bạn thử quá nhiều lần. Vui lòng chờ vài phút.",
            "config" => "Đăng nhập mạng xã hội chưa được cấu hình đầy đủ.",
            "server" => "Không kết nối được tới dịch vụ đăng nhập. Vui lòng thử lại sau.",
        );
        return isset($map[$code]) ? $map[$code] : "Không thể đăng nhập. Vui lòng thử lại.";
    }
}

if (!function_exists("oauth_return_paths")) {
    /** Where a social login may land. A relative path from the request can never become a redirect. */
    function oauth_return_paths()
    {
        return array(
            "client/home", "client/product-orders", "client/purchase-history",
            "client/recharge", "client/profile", "client/security",
        );
    }
}

if (!function_exists("oauth_normalize_return")) {
    function oauth_normalize_return($path)
    {
        $path = trim((string) $path);
        $path = preg_replace('/[?#].*$/', "", $path);
        return in_array($path, oauth_return_paths(), true) ? $path : "";
    }
}

if (!function_exists("oauth_buttons_markup")) {
    /**
     * The buttons on the login/register pages. Returns "" when nothing is enabled, so an
     * unconfigured shop renders exactly the page it rendered before this feature existed.
     *
     * Markup notes: same-origin links only (no third-party script, no popup), rel="nofollow" so a
     * crawler does not start OAuth flows, and the label is printed from oauth_providers() — the
     * provider names are the only brand strings here.
     */
    function oauth_buttons_markup($config)
    {
        $links = array();
        foreach (oauth_providers() as $provider => $label) {
            if (!oauth_provider_enabled($config, $provider)) {
                continue;
            }
            $url = BASE_URL("client/oauth-start") . "?provider=" . rawurlencode($provider);
            $icon = $provider === "google"
                ? '<svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true" focusable="false"><path fill="#4285F4" d="M45.1 24.5c0-1.6-.1-2.8-.4-4.1H24v7.8h12.1c-.2 2-1.5 5-4.3 7l-.1.1 6.2 4.8.4.1c4-3.7 6.8-9.1 6.8-15.7z"/><path fill="#34A853" d="M24 46c5.9 0 10.9-1.9 14.5-5.3l-6.9-5.4c-1.9 1.3-4.4 2.2-7.6 2.2-5.8 0-10.7-3.8-12.4-9l-.1.1-6.5 5-.1.1C8.5 41.2 15.6 46 24 46z"/><path fill="#FBBC05" d="M11.6 28.5c-.5-1.4-.8-2.9-.8-4.5s.3-3.1.8-4.5v-.1l-6.6-5.1-.1.1C3.3 17.7 2.5 20.8 2.5 24s.8 6.3 2.4 9.6l6.7-5.1z"/><path fill="#EA4335" d="M24 10.7c4.1 0 6.9 1.8 8.5 3.3l6.2-6C34.9 4.4 29.9 2 24 2 15.6 2 8.5 6.8 4.9 14.4l6.7 5.1c1.7-5.2 6.6-8.8 12.4-8.8z"/></svg>'
                : '<svg width="18" height="18" viewBox="0 0 16 16" aria-hidden="true" focusable="false" fill="currentColor"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27s1.36.09 2 .27c1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8z"/></svg>';
            $links[] = '<a class="btn btn-light w-100 mb-2 oauth-button" rel="nofollow" href="'
                . htmlspecialchars($url, ENT_QUOTES, "UTF-8") . '">' . $icon
                . '<span>Đăng nhập với ' . htmlspecialchars($label, ENT_QUOTES, "UTF-8") . '</span></a>';
        }
        if (empty($links)) {
            return "";
        }
        return '<div class="oauth-buttons">'
            . '<div class="oauth-buttons__divider"><span>hoặc</span></div>'
            . implode("", $links)
            . '</div>';
    }
}
