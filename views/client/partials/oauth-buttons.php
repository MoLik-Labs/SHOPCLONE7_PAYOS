<?php
/*
 * Shared by login.php and register.php.
 *
 * Expects $CMSNT and includes libs/oauth.php itself, so the two pages cannot drift. Prints:
 *   - an error banner when ?oauth_error= carries a known code (never raw text from the query string)
 *   - one button per enabled provider, or nothing at all when no provider is on
 *
 * $oauthUiPlace limits the output when a page wants the banner above the form and the buttons below
 * it: "alert", "buttons" or "both" (default).
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . "/../../../libs/oauth.php";

$oauthUiConfig = oauth_config($CMSNT);
$oauthUiError = "";
if (isset($_GET["oauth_error"]) && oauth_known_error($_GET["oauth_error"])) {
    $oauthUiError = '<div class="alert alert-warning oauth-alert" role="alert">'
        . htmlspecialchars(oauth_error_text((string) $_GET["oauth_error"]), ENT_QUOTES, "UTF-8")
        . '</div>';
}
$oauthUiButtons = oauth_buttons_markup($oauthUiConfig);
$oauthUiPlace = isset($oauthUiPlace) ? $oauthUiPlace : "both";

if ($oauthUiPlace !== "buttons") {
    echo $oauthUiError;
}
if ($oauthUiPlace !== "alert") {
    echo $oauthUiButtons;
}
