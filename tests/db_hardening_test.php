<?php
/*
 * database layer — wiring checks (no MySQL in this sandbox).
 *
 *   php tests/db_hardening_test.php
 *
 * These are source-level assertions, not a live database test. They pin the three things that used
 * to make a broken query invisible or fatal:
 *
 *   1. PHP 8.1+ throws mysqli_sql_exception by default; this class is written for "false on error"
 *      so it must pin MYSQLI_REPORT_OFF before it connects.
 *   2. `set names 'utf8'` is the 3-byte charset; emoji and 4-byte characters need utf8mb4.
 *   3. A SQL error used to `exit("Câu truy vấn bị sai")` — a blank page and no log line. It must
 *      return false/[]/0 and log the driver message instead.
 */

$source = (string) file_get_contents(__DIR__ . "/../libs/db.php");

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

check("the driver error mode is pinned, not inherited from php.ini",
    strpos($source, "mysqli_report(MYSQLI_REPORT_OFF);") !== false);
check("the report pin happens inside connect(), before connecting",
    strpos($source, "mysqli_report(MYSQLI_REPORT_OFF);") < strpos($source, "mysqli_real_connect("));
check("a dead server fails fast", strpos($source, "MYSQLI_OPT_CONNECT_TIMEOUT") !== false);
check("connection errors are logged, not printed",
    strpos($source, 'error_log("[orbit-db] connect failed: ') !== false);
check("the visitor gets a 503, not a stack trace",
    strpos($source, 'http_response_code(503)') !== false);

check("the connection is utf8mb4", strpos($source, 'mysqli_set_charset($this->ketnoi, "utf8mb4")') !== false);
check("the 3-byte charset is gone", strpos($source, "set names 'utf8'") === false);

check("no SQL error exits the process any more", strpos($source, "Câu truy vấn bị sai") === false);
check("failed statements are counted", strpos($source, "public static \$errors = 0;") !== false);
check("failed statements are logged", strpos($source, 'error_log($detail);') !== false);
check("SQL text is logged only under DB_DEBUG",
    strpos($source, 'getenv("DB_DEBUG") === "1"') !== false);
check("the log line names the caller", strpos($source, "basename(\$frame[\"file\"])") !== false);

$getList = substr($source, strpos($source, "public function get_list"), 400);
check("get_list degrades to an empty array", strpos($getList, "return [];") !== false);
$getRow = substr($source, strpos($source, "public function get_row"), 400);
check("get_row degrades to false", strpos($getRow, "return false;") !== false);

check("every statement still goes through the counter", substr_count($source, "++self::\$queries;") === 1);
check("the wrapper calls mysqli_query exactly once", substr_count($source, "mysqli_query(\$this->ketnoi, \$sql)") === 1);
check("settings lookups are escaped", strpos($source, 'mysqli_real_escape_string($this->ketnoi, $key)') !== false);

/* ---------------------------------------------------------------- money movement */

$users = (string) file_get_contents(__DIR__ . "/../libs/database/users.php");
check("the ledger and the balance move in one transaction",
    substr_count($users, 'return $this->transaction(') === 1 && substr_count($users, '$ok = $this->transaction(') === 2);
check("AddCredits delegates to the tri-state credit path",
    substr_count($users, 'AddCreditsChecked($user_id, $amount, $reason, $transid)') === 1);
check("RefundCredits delegates to the tri-state refund path",
    substr_count($users, 'RefundCreditsChecked($user_id, $amount, $reason, $transid)') === 1);
check("the transaction locks the user row before claiming a ledger entry",
    substr_count($users, "FOR UPDATE") === 3);
check("both tri-state helpers declare their outcome property",
    strpos($users, 'private $credit_outcome') !== false && strpos($users, 'private $refund_outcome') !== false);
check("AddCredits documents the ledger/balance pairing",
    strpos($users, "Ledger row + balance move together") !== false);
check("the balance snapshot is read on the transaction's own connection",
    substr_count($users, '$this->get_row("SELECT `money` FROM `users` WHERE `id` = ') === 3);
$credits = substr($users, strpos($users, "public function AddCredits"), strpos($users, "public function Banned") - strpos($users, "public function AddCredits"));
check("the user id is an integer in the three balance statements", strpos($credits, "`id` = '") === false);

check("a transaction rolls back on an exception",
    strpos($source, '@mysqli_rollback($this->ketnoi);') !== false);
check("a transaction commits only on a truthy result",
    strpos($source, '@mysqli_commit($this->ketnoi);') !== false && strpos($source, 'if ($result) {') !== false);
check("non-numeric amounts are refused before SQL",
    strpos($source, 'if (!is_numeric($sotien)) {') !== false);

$adminForm = (string) file_get_contents(__DIR__ . "/../views/admin/user-edit.php");
check("the admin credit form parses a strict float",
    substr_count($adminForm, "FILTER_VALIDATE_FLOAT") === 2);
check("…and rejects non-finite values", substr_count($adminForm, '!is_finite($amount)') === 2);
check("the admin form no longer sends check_string() amounts to cong/tru",
    strpos($adminForm, '$amount = check_string($_POST["amount"]);') === false);

/* The helper must reuse the request's connection instead of opening a second one per call. */
$helper = (string) file_get_contents(__DIR__ . "/../libs/helper.php");
check("only the bootstrap and the lazy fallback open a connection",
    substr_count($helper, "\$CMSNT = new DB();") === 2);
check("the rest of helper.php reuses it", substr_count($helper, "orbit_db()") >= 12);
check("orbit_db() never calls itself", strpos($helper, "if (!(\$CMSNT instanceof DB)) {\n        \$CMSNT = new DB();") !== false);

echo "\n" . $pass . " passed, " . $fail . " failed.\n";
exit($fail === 0 ? 0 : 1);
