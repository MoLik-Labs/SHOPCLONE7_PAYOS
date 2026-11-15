<?php
/*
 * /client/oauth-start?provider=google|github
 *
 * Creates a PKCE pair, keeps the verifier in the server session and sends the browser to Supabase.
 * Nothing is rendered and nothing is trusted from the query string except the provider name, which
 * must be one of the two known values and must be enabled in the settings.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . "/../../libs/oauth.php";

$oauthConfig = oauth_config($CMSNT);
$oauthProvider = oauth_normalize_provider(isset($_GET["provider"]) ? $_GET["provider"] : "");
$oauthReturn = oauth_normalize_return(isset($_GET["next"]) ? $_GET["next"] : "");

if (!oauth_provider_enabled($oauthConfig, $oauthProvider)) {
    redirect(base_url("client/login") . "?oauth_error=disabled");
}

$oauthPair = oauth_pkce_pair();
$oauthAuthorize = $oauthPair
    ? oauth_authorize_url($oauthConfig, $oauthProvider, oauth_callback_url($oauthConfig), $oauthPair["challenge"])
    : "";
if ($oauthAuthorize === "") {
    redirect(base_url("client/login") . "?oauth_error=config");
}

$oauthNow = time();
/*
 * One flow per session. Starting a second one replaces the first, which is correct: the newest tab is
 * the one the visitor is looking at. `failures` survives the replacement so a loop of attempts still
 * hits the rate limit in the callback.
 */
$oauthFailures = array();
if (isset($_SESSION["oauth_failures"]) && is_array($_SESSION["oauth_failures"])) {
    foreach ($_SESSION["oauth_failures"] as $stamp) {
        if ((int) $stamp > $oauthNow - 600) {
            $oauthFailures[] = (int) $stamp;
        }
    }
}
$_SESSION["oauth_failures"] = $oauthFailures;
$_SESSION["oauth_flow"] = array(
    "provider" => $oauthProvider,
    "verifier" => $oauthPair["verifier"],
    "return" => $oauthReturn,
    "time" => $oauthNow,
);

header("Cache-Control: no-store, private");
header("Pragma: no-cache");
header("Referrer-Policy: no-referrer");
header("X-Content-Type-Options: nosniff");
redirect($oauthAuthorize);
