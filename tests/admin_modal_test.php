<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}
/*
 * Admin modal endpoints — the two that write money.
 *
 * `ajaxs/admin/modal/coupon-view.php` was copied from `withdraw-edit.php` and kept the withdrawal
 * handler: a crafted POST reached `RefundCommission()` with a *coupon* row (no `user_id`, and
 * `amount` is the coupon's usage limit), then updated `aff_withdraw` using the coupon id. An admin
 * with only `view_coupon` could therefore mark a withdrawal completed, or cancel one without
 * refunding the member.
 *
 * The withdrawal handler itself refunded before it wrote the row, and read `status` without a lock:
 * two concurrent submits both passed "not cancelled yet" and refunded twice.
 *
 * The log table had the mirror-image problem: `views/admin/logs.php` printed `$row["action"]` raw, and
 * four producers built that string from an unescaped request value — stored HTML in the admin panel.
 *
 * These are source-level checks; the handlers need a database and an HTTP request to execute.
 */

$root = dirname(__DIR__);
$coupon = (string) file_get_contents($root . '/ajaxs/admin/modal/coupon-view.php');
$withdraw = (string) file_get_contents($root . '/ajaxs/admin/modal/withdraw-edit.php');
/* The views are PHP that echoes HTML, so their quotes are backslash-escaped in the source. Compare
   against the text a browser would see, otherwise every check for `type="submit"` is a false alarm. */
$couponHtml = str_replace(array('\\"', "\\'"), array('"', "'"), $coupon);
$withdrawHtml = str_replace(array('\\"', "\\'"), array('"', "'"), $withdraw);

$checks = 0;
$failures = 0;
function check_modal($name, $condition, $detail = '')
{
    global $checks, $failures;
    ++$checks;
    if ($condition) {
        echo "PASS  " . $name . "\n";
        return;
    }
    ++$failures;
    echo "FAIL  " . $name . ($detail !== '' ? "  (" . $detail . ")" : '') . "\n";
}

/* ---- coupon-view is read-only ---------------------------------------------- */
check_modal('the coupon modal has no POST handler left',
    strpos($coupon, 'isset($_POST["btnSubmit"])') === false);
check_modal('the coupon modal never writes aff_withdraw',
    strpos($coupon, 'aff_withdraw') === false);
check_modal('the coupon modal never refunds a commission',
    strpos($coupon, 'RefundCommission') === false && strpos($coupon, 'RestoreCommission') === false);
check_modal('the coupon modal still requires view_coupon',
    strpos($coupon, 'checkPermission($getUser["admin"], "view_coupon")') !== false);
check_modal('the coupon modal still loads the admin guard',
    strpos($coupon, 'models/is_admin.php') !== false);
check_modal('the coupon modal still lists the uses it exists to show',
    strpos($coupon, 'FROM `coupon_used` WHERE `coupon_id`') !== false);
check_modal('the only form button is a close button',
    strpos($couponHtml, 'data-bs-dismiss="modal"') !== false && strpos($couponHtml, 'type="submit"') === false);

/* ---- withdrawal cancel is claim-first -------------------------------------- */
$claimPos = strpos($withdrawHtml, "AND `status` <> 'cancel'");
/* the call, not the comment that explains the race it used to have */
$refundPos = strpos($withdrawHtml, '$User->RefundCommission(');
check_modal('the withdrawal row is claimed with a conditional UPDATE',
    $claimPos !== false);
check_modal('the claim happens before the refund',
    $claimPos !== false && $refundPos !== false && $claimPos < $refundPos);
check_modal('a second submit that lost the claim stops before refunding',
    strpos($withdraw, 'if($CMSNT->affectedRows() !== 1)') !== false);
check_modal('the status is validated against the three states the shop understands',
    strpos($withdraw, 'in_array($newStatus, array("pending", "completed", "cancel"), true)') !== false);
check_modal('an unknown status is refused before any write',
    (strpos($withdraw, '$newStatus = check_string($_POST["status"]);') <
        strpos($withdraw, '$isUpdate = $CMSNT->update("aff_withdraw"')));
check_modal('a failed refund puts the row back so it can be retried',
    strpos($withdraw, 'Hoàn tiền thất bại, đơn rút được giữ nguyên') !== false);
check_modal('the modal keeps its own permission check',
    strpos($withdraw, 'checkPermission($getUser["admin"], "edit_withdraw_affiliate")') !== false);

/* ---- the admin guard ------------------------------------------------------- */
$adminGuard = (string) file_get_contents($root . '/models/is_admin.php');
check_modal('the session token reaches SQL escaped, like every other token lookup',
    strpos($adminGuard, 'check_string($_SESSION["admin_login"])') !== false
    && strpos($adminGuard, '= \'" . $_SESSION["admin_login"] . "\'') === false);

/* ---- log rows are escaped on output ---------------------------------------- */
$adminLogs = (string) file_get_contents($root . '/views/admin/logs.php');
$clientLogs = (string) file_get_contents($root . '/views/client/logs.php');
check_modal('the admin log table escapes the action it prints',
    strpos($adminLogs, 'htmlspecialchars((string) $row["action"], ENT_QUOTES, "UTF-8")') !== false
    && strpos($adminLogs, 'echo $row["action"];') === false);
check_modal('the client log table escapes the action it prints',
    strpos($clientLogs, 'store_e($row["action"])') !== false);
/* Four producers wrote the raw request value into the log line; the render fix covers them, and the
   producers no longer store unescaped HTML either. */
foreach (array('currency-list', 'language-list', 'recharge-bank-config', 'recharge-bank-edit') as $view) {
    $source = (string) file_get_contents($root . '/views/admin/' . $view . '.php');
    if (preg_match_all('/insert\("logs".*?\n/', $source, $matches)) {
        foreach ($matches[0] as $line) {
            check_modal($view . ' escapes the request value it writes to the log',
                strpos($line, '$_POST[') === false || strpos($line, 'check_string($_POST[') !== false);
        }
    }
}

echo "\n" . $checks . " checks, " . $failures . " failed\n";
exit($failures > 0 ? 1 : 0);
