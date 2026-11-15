<?php
/*
 * /client/oauth-callback?code=…
 *
 * The only place a social login becomes a local session. Every decision is written down here:
 *
 *   1. The single-use flow record (PKCE verifier + provider) is removed from the session first, so a
 *      replayed callback can never be exchanged twice.
 *   2. The code is exchanged server-to-server; the browser never supplies a token or an identity.
 *   3. The provider identity is (provider, subject), taken from Supabase's server-owned metadata.
 *   4. Existing link => login. Same email => link only if the admin enabled it and the provider
 *      verified the address. Otherwise => create an ordinary customer account, if that is enabled.
 *   5. Admin, banned and 2FA/OTP accounts are refused — this path never weakens those checks.
 *
 * Failures redirect to the login page with a short code; the code is mapped to a fixed sentence by
 * oauth_error_text(). Provider text, project URL and keys never reach the browser.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . "/../../libs/oauth.php";

/* The redirect URL carried ?code=...; do not let it travel as a Referer to the next page. */
header("Referrer-Policy: no-referrer");
header("Cache-Control: no-store, private");

$oauthConfig = oauth_config($CMSNT);

if (!function_exists("oauth_callback_fail")) {
    function oauth_callback_fail($code)
    {
        $_SESSION["oauth_failures"][] = time();
        error_log("[social-login] " . preg_replace('/[^a-z_]/', "", (string) $code));
        redirect(base_url("client/login") . "?oauth_error=" . rawurlencode((string) $code));
    }
}

/* Five failed attempts per ten minutes per session. Success clears the list. */
$oauthAttempts = 0;
if (isset($_SESSION["oauth_failures"]) && is_array($_SESSION["oauth_failures"])) {
    foreach ($_SESSION["oauth_failures"] as $stamp) {
        if ((int) $stamp > time() - 600) {
            $oauthAttempts++;
        }
    }
}
if ($oauthAttempts >= 5) {
    oauth_callback_fail("rate_limited");
}

$oauthFlow = isset($_SESSION["oauth_flow"]) ? $_SESSION["oauth_flow"] : null;
unset($_SESSION["oauth_flow"]);

if (isset($_GET["error"]) && (string) $_GET["error"] !== "") {
    $oauthError = (string) $_GET["error"];
    oauth_callback_fail($oauthError === "access_denied" ? "denied" : "exchange");
}

$oauthGuard = oauth_flow_guard($oauthFlow, time());
if (!$oauthGuard["ok"]) {
    oauth_callback_fail($oauthGuard["error"]);
}
/* Without this table the first SELECT below would hit the legacy get_row()'s "query failed" exit. */
if (!oauth_table_exists($CMSNT)) {
    oauth_callback_fail("server");
}
if (!oauth_provider_enabled($oauthConfig, $oauthGuard["provider"])) {
    oauth_callback_fail("disabled");
}

$oauthCode = isset($_GET["code"]) ? (string) $_GET["code"] : "";
$oauthResult = oauth_exchange_code($oauthConfig, $oauthGuard["provider"], $oauthCode, $oauthGuard["verifier"]);
if (empty($oauthResult["ok"])) {
    oauth_callback_fail(isset($oauthResult["error"]) ? $oauthResult["error"] : "exchange");
}
$oauthIdentity = $oauthResult["identity"];
$oauthProvider = $oauthIdentity["provider"];
$oauthSubject = $oauthIdentity["subject"];
$oauthEmail = $oauthIdentity["email"];

/*
 * Lookups. All three values have been through oauth_identity_from_supabase_user(): subject is a
 * UUID, provider is an enum member, email matches [A-Za-z0-9._%+-]+@…, so none of them can carry a
 * quote or a control character into the legacy SQL below.
 */
$oauthIdentityRow = $CMSNT->get_row(
    "SELECT * FROM `oauth_identities` WHERE `provider` = '" . $oauthProvider
    . "' AND `subject` = '" . $oauthSubject . "' LIMIT 1"
);
$oauthUserBySubject = null;
if ($oauthIdentityRow && !empty($oauthIdentityRow["user_id"])) {
    $oauthUserBySubject = $CMSNT->get_row(
        "SELECT * FROM `users` WHERE `id` = '" . (int) $oauthIdentityRow["user_id"] . "' LIMIT 1"
    );
}
$oauthUserByEmail = $CMSNT->get_row(
    "SELECT * FROM `users` WHERE `email` = '" . $oauthEmail . "' LIMIT 1"
);

$oauthPlan = oauth_link_plan(
    $oauthIdentity,
    $oauthUserBySubject,
    $oauthUserByEmail,
    array("link_email" => $oauthConfig["link_email"], "auto_create" => $oauthConfig["auto_create"])
);
if ($oauthPlan["action"] === "refuse") {
    oauth_callback_fail($oauthPlan["reason"]);
}

$oauthUser = null;
$oauthNow = gettime();

if ($oauthPlan["action"] === "login") {
    $oauthUser = $oauthUserBySubject;
} elseif ($oauthPlan["action"] === "link") {
    $oauthUser = $oauthUserByEmail;
} elseif ($oauthPlan["action"] === "create") {
    $oauthExists = function ($username) use ($CMSNT) {
        return (bool) $CMSNT->get_row("SELECT `id` FROM `users` WHERE `username` = '" . $username . "' LIMIT 1");
    };
    $oauthUsername = oauth_username_unique(oauth_username_base($oauthEmail, $oauthIdentity["name"]), $oauthExists);
    $oauthSecret = oauth_random_token(10);
    $oauthPayload = oauth_user_payload(
        $oauthIdentity,
        $oauthUsername,
        $oauthNow,
        myip(),
        class_exists("Mobile_Detect") ? (new Mobile_Detect())->getUserAgent() : "",
        $oauthSecret
    );
    if (!$CMSNT->insert("users", $oauthPayload)) {
        oauth_callback_fail("server");
    }
    $oauthUser = $CMSNT->get_row(
        "SELECT * FROM `users` WHERE `token` = '" . $oauthPayload["token"] . "' LIMIT 1"
    );
    if (!$oauthUser) {
        oauth_callback_fail("server");
    }
}

/* Re-check the final row: the plan looked at one row, this is the row about to be logged in. */
if (!$oauthUser) {
    oauth_callback_fail("no_account");
}
if ((int) $oauthUser["admin"] !== 0) {
    oauth_callback_fail("account_admin");
}
if ((int) $oauthUser["banned"] !== 0) {
    oauth_callback_fail("account_banned");
}
if (!empty($oauthUser["status_2fa"]) || !empty($oauthUser["status_otp_mail"])) {
    oauth_callback_fail("account_2fa");
}

$oauthUserId = (int) $oauthUser["id"];

if ($oauthIdentityRow && (int) $oauthIdentityRow["user_id"] === $oauthUserId) {
    $CMSNT->update("oauth_identities", array(
        "email" => $oauthEmail,
        "email_verified" => $oauthIdentity["email_verified"] ? 1 : 0,
        "last_login_at" => $oauthNow,
    ), " `id` = '" . (int) $oauthIdentityRow["id"] . "' ");
} else {
    /* Covers `link` and `create`; for an orphaned identity row this also re-points the mapping. */
    $oauthLinked = $CMSNT->insert(
        "oauth_identities",
        oauth_identity_payload($oauthIdentity, $oauthUserId, $oauthNow)
    );
    if (!$oauthLinked) {
        /* UNIQUE(provider, subject) already exists: an earlier link whose user row was deleted.
           Re-point that mapping instead of leaving it on a dead account, otherwise every future
           login would create another orphan and never be linked. */
        $oauthOrphan = $CMSNT->get_row(
            "SELECT `id` FROM `oauth_identities` WHERE `provider` = '" . $oauthProvider
            . "' AND `subject` = '" . $oauthSubject . "' LIMIT 1"
        );
        $oauthRepointed = $oauthOrphan && $CMSNT->update(
            "oauth_identities",
            array(
                "user_id" => $oauthUserId,
                "email" => $oauthEmail,
                "email_verified" => $oauthIdentity["email_verified"] ? 1 : 0,
                "last_login_at" => $oauthNow,
            ),
            " `id` = '" . (int) $oauthOrphan["id"] . "' "
        );
        if (!$oauthRepointed) {
            /* The table is missing (migration not run) or the write failed. Fail closed; the visitor
               can still use email/password, and the admin sees the schema warning in Settings. */
            oauth_callback_fail("server");
        }
    }
}

$CMSNT->update("users", array(
    "ip" => myip(),
    "time_request" => time(),
    "time_session" => time(),
    "device" => class_exists("Mobile_Detect") ? (new Mobile_Detect())->getUserAgent() : "",
), " `id` = '" . $oauthUserId . "' ");

$CMSNT->insert("logs", array(
    "user_id" => $oauthUserId,
    "ip" => myip(),
    "device" => class_exists("Mobile_Detect") ? (new Mobile_Detect())->getUserAgent() : "",
    "createdate" => $oauthNow,
    "action" => "[Social] " . __("Đăng nhập bằng") . " " . oauth_providers()[$oauthProvider],
));

$_SESSION["oauth_failures"] = array();
unset($_SESSION["oauth_flow"]);
set_auth_cookie($oauthUser["token"], time() + (int) $CMSNT->site("session_login"));
$_SESSION["login"] = $oauthUser["token"];

$oauthTarget = isset($oauthFlow["return"]) && $oauthFlow["return"] !== ""
    ? $oauthFlow["return"]
    : "client/home";
redirect(base_url($oauthTarget));
