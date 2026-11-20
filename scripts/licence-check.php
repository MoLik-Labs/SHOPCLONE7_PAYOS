<?php

/**
 * Orbit Office — licence check / activation from the command line.
 *
 * Usage (run from the shop root, as the web user):
 *   php scripts/licence-check.php            # check the saved key against the portal
 *   php scripts/licence-check.php --activate # bind this machine to the key (first run, or after a reset)
 *   php scripts/licence-check.php --json     # machine-readable output
 *
 * Safety:
 *   - CLI only, like the migration script;
 *   - never prints the licence key (masked form only) and never the portal token;
 *   - writes nothing except the licence cache keys in the settings table;
 *   - --activate only ever *binds this machine*; the portal decides, this script
 *     does not force anything the portal refuses.
 *
 * NOTE: this script is additive. It does not change models/is_license.php,
 * checkLicenseKey() or any CMSNT check.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}

if (!defined('IN_SITE')) {
    define('IN_SITE', true);
}

require_once __DIR__ . '/../libs/db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../libs/lang.php';
require_once __DIR__ . '/../libs/helper.php';
require_once __DIR__ . '/../libs/licence_client.php';

$CMSNT = new DB();

$argv = isset($argv) ? $argv : array();
$json = in_array('--json', $argv, true);
$activate = in_array('--activate', $argv, true);

/** Minimal settings-backed store; uses the existing DB layer ($CMSNT). */
function licence_cli_store()
{
    global $CMSNT;
    return new LicenceStore(
        function ($name) use ($CMSNT) {
            $row = $CMSNT->get_row("SELECT `value` FROM `settings` WHERE `name` = '" . check_string($name) . "' ");
            return $row ? $row['value'] : null;
        },
        function ($name, $value) use ($CMSNT) {
            $name = check_string($name);
            $value = check_string($value);
            $exists = $CMSNT->get_row("SELECT `id` FROM `settings` WHERE `name` = '" . $name . "' ");
            if ($exists) {
                $CMSNT->query("UPDATE `settings` SET `value` = '" . $value . "' WHERE `name` = '" . $name . "' ");
            } else {
                $CMSNT->query("INSERT INTO `settings` (`name`, `value`) VALUES ('" . $name . "', '" . $value . "') ");
            }
        }
    );
}

$cfg = licence_config();
if ($cfg === null) {
    $message = 'LICENCE_PORTAL_URL is not configured (or not a bare HTTPS origin). Set it in the deployment .env first.';
    if ($json) {
        echo json_encode(array('ok' => false, 'state' => 'error', 'message' => $message)) . "\n";
    } else {
        echo "Licence client: " . $message . "\n";
    }
    exit(2);
}

$store = licence_cli_store();
$key = licence_current_key($store);

$result = $activate ? licence_activate($store, $cfg) : licence_check($store, $cfg);

$output = array(
    'state'      => $result['state'],
    'message'    => $result['message'],
    'online'     => $result['online'],
    'http'       => $result['http_status'],
    'checked_at' => $result['checked_at'],
    'gate'       => licence_gate_allows($result) ? 'unlocked' : 'locked',
    'key'        => $key === '' ? '(none)' : licence_mask_key($key),
    'hwid'       => licence_hwid($store->get('licence_install_salt', 'unknown')),
);

if ($json) {
    echo json_encode($output) . "\n";
} else {
    echo "Licence key:  " . $output['key'] . "\n";
    echo "Hardware ID:  " . $output['hwid'] . "\n";
    echo "State:        " . $output['state'] . ($result['online'] ? ' (portal answered)' : ' (offline)') . "\n";
    echo "Message:      " . $result['message'] . "\n";
    echo "Gate:         " . $output['gate'] . "\n";
    echo "\nExit codes: 0 = active, 1 = not active, 2 = misconfigured.\n";
}

exit(licence_gate_allows($result) ? 0 : 1);
