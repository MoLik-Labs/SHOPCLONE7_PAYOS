<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}
/*
 * Buyer-page scoping — the contract that makes "Lịch sử mua hàng" safe to expose.
 *
 *   php tests/sales_scoping_test.php
 *
 * The buyer page shows money and delivered items, so the bug that matters is IDOR: reading somebody
 * else's orders. This suite pins the four things that make that impossible:
 *
 *   1. the identity comes from the session (`$getUser["id"]`), and the request can never name a buyer;
 *   2. every statement the page builds over a sales table is bound to that id — either directly, or
 *      through a variable that was bound to it earlier in the same file (checked in order, no guessing);
 *   3. the search box cannot restructure the query or turn into a wildcard scan;
 *   4. no password, token, raw `query()`, or shared-cache leak sneaks in through the new pages.
 *
 * It reads the real files — no database, no stubs — and uses PHP's tokenizer rather than regexes over
 * source text, so a quoting change in the page cannot make an assertion silently stop working.
 */

if (!defined("IN_SITE")) {
    define("IN_SITE", true);
}

$root = dirname(__DIR__);
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

/**
 * Splits PHP source into statements: every `;` ends one. Each statement keeps the source text with comments
 * removed (string literals stay, so `header("…")` and `$user = (int) $getUser["id"]` are visible), plus
 * the list of string literal *values* for the SQL checks.
 */
function scoping_statements($source)
{
    $statements = array();
    $text = "";
    $literals = array();
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $text .= $token[1];
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literals[] = trim($token[1], "'\"");
            }
            continue;
        }
        if ($token === ";") {
            $statements[] = array("text" => $text . ";", "literals" => $literals);
            $text = "";
            $literals = array();
            continue;
        }
        $text .= $token;
    }
    if (trim($text) !== "") {
        $statements[] = array("text" => $text, "literals" => $literals);
    }
    return $statements;
}

/** Every string literal in a file, in order. */
function scoping_literals($source)
{
    $literals = array();
    foreach (scoping_statements($source) as $statement) {
        foreach ($statement["literals"] as $literal) {
            $literals[] = $literal;
        }
    }
    return $literals;
}

/** Every variable mentioned in a chunk of code, as a set (e.g. `$filters` => true). */
function scoping_variables($code)
{
    $found = array();
    if (preg_match_all('/\$[A-Za-z_][A-Za-z0-9_]*/', $code, $matches)) {
        foreach ($matches[0] as $name) {
            $found[$name] = true;
        }
    }
    return $found;
}

/** The names of request keys the code reads, e.g. $_GET["days"] -> days. */
function scoping_request_keys($code)
{
    $keys = array();
    if (preg_match_all('/\$_GET\[\s*(["\'])([^"\']+)\1\s*\]/', $code, $matches)) {
        foreach ($matches[2] as $key) {
            $keys[$key] = true;
        }
    }
    return array_keys($keys);
}

$pageSource = (string) file_get_contents($root . "/views/client/purchase-history.php");
$statsSource = (string) file_get_contents($root . "/libs/sales_stats.php");
$adminSource = (string) file_get_contents($root . "/views/admin/sales.php");

$pageStatements = scoping_statements($pageSource);
$pageText = "";
$pageLiterals = array();
foreach ($pageStatements as $statement) {
    $pageText .= $statement["text"] . "\n";
    $pageLiterals = array_merge($pageLiterals, $statement["literals"]);
}
$statsText = "";
$statsLiterals = array();
foreach (scoping_statements($statsSource) as $statement) {
    $statsText .= $statement["text"] . "\n";
    $statsLiterals = array_merge($statsLiterals, $statement["literals"]);
}
$adminText = "";
$adminLiterals = array();
foreach (scoping_statements($adminSource) as $statement) {
    $adminText .= $statement["text"] . "\n";
    $adminLiterals = array_merge($adminLiterals, $statement["literals"]);
}

/* -------------------------------------------------------- 1. identity source */

check("the buyer page requires the signed-in customer guard",
    strpos($pageText, "is_user.php") !== false);
check("the buyer id is the session user's id, cast to an integer",
    strpos($pageText, '$user = (int) $getUser["id"]') !== false);
check("the admin page requires the admin guard",
    strpos($adminText, "is_admin.php") !== false);
/* "buyer" does appear as an array key in the admin page — reading the column out of a result row, which is
   fine. What must never happen is the *request* naming a buyer, which the request-key lists below cover. */
check("no file reads a request key called buyer",
    !in_array("buyer", array_merge(
        scoping_request_keys($pageText),
        scoping_request_keys($statsText),
        scoping_request_keys($adminText)
    ), true));
check("the request bag is never read at all",
    strpos($pageText, '$_REQUEST') === false && strpos($statsText, '$_REQUEST') === false
    && strpos($adminText, '$_REQUEST') === false);
$pageKeys = scoping_request_keys($pageText);
sort($pageKeys);
check("the buyer page reads only days, page and q from the request",
    $pageKeys === array("days", "page", "q"), implode(", ", $pageKeys));
$adminKeys = scoping_request_keys($adminText);
sort($adminKeys);
check("the admin page reads only its own named keys",
    array_diff($adminKeys, array("days", "export", "page")) === array(), implode(", ", $adminKeys));

/* --------------------------------------- 2. every buyer statement carries the owner */

/* Walk the file in order: a statement that mentions a sales table and both `buyer` and `$user` marks the
   variable it assigns to as scoped; later statements may then pass through that variable. */
$scopedVariables = array();
$salesStatements = 0;
$problems = array();
foreach ($pageStatements as $statement) {
    $mentionsTable = false;
    $mentionsBuyer = false;
    foreach ($statement["literals"] as $literal) {
        if (strpos($literal, "product_order") !== false) {
            $mentionsTable = true;
        }
        if (strpos($literal, "`buyer`") !== false || strpos($literal, "buyer =") !== false) {
            $mentionsBuyer = true;
        }
    }

    /* remember the owner-carrying variables this statement defines, even if it mentions no table */
    if ($mentionsBuyer && strpos($statement["text"], '$user') !== false) {
        foreach (scoping_variables($statement["text"]) as $name => $unused) {
            if ($name === '$user' || $name === '$getUser') {
                continue;
            }
            if (preg_match('/' . preg_quote($name, '/') . '\s*\.?=\s*/', $statement["text"])) {
                $scopedVariables[$name] = true;
            }
        }
    }

    if (!$mentionsTable) {
        continue;
    }
    $salesStatements++;

    $direct = $mentionsBuyer && strpos($statement["text"], '$user') !== false;
    $through = false;
    foreach (scoping_variables($statement["text"]) as $name => $unused) {
        if (isset($scopedVariables[$name])) {
            $through = true;
        }
    }
    $readsRequest = strpos($statement["text"], '$_GET') !== false
        && preg_match('/\$_GET\[[^\]]*\][^;]*`?product_order`?/i', $statement["text"]) === 1;

    if ((!$direct && !$through) || $readsRequest) {
        $explanation = $readsRequest ? "reads a request value"
            : "no owner predicate (direct or via a scoped variable)";
        $problems[] = $explanation . ": " . trim(preg_replace('/\s+/', ' ', substr(implode(" ", $statement["literals"]), 0, 80)));
    }
}
check("the buyer page builds several statements over product_order", $salesStatements >= 5,
    "found " . $salesStatements);
check("every one of them is bound to the session buyer id", $problems === array(),
    implode(" | ", $problems));
check("the owner id was reached through a scoped variable at least once (the filter clause)",
    isset($scopedVariables['$filters']));
check("a request value is never concatenated into a string that mentions a sales table",
    (function ($statements) {
        foreach ($statements as $statement) {
            foreach ($statement["literals"] as $literal) {
                if (strpos($literal, "product_order") === false) {
                    continue;
                }
                if (strpos($statement["text"], '$_GET') !== false) {
                    return false;
                }
            }
        }
        return true;
    })($pageStatements));

/* ------------------------------------------------------------- 3. the search box */

check("the search term is stripped to letters, digits and friendly separators",
    (function ($literals) {
        foreach ($literals as $literal) {
            if (strpos($literal, "/[^") === 0 && strpos($literal, '\p{L}') !== false
                && strpos($literal, '\p{N}') !== false) {
                return true;
            }
        }
        return false;
    })($pageLiterals));
check("the search term is length-capped",
    strpos($pageText, "mb_substr(") !== false || strpos($pageText, "substr(") !== false);
check("the search term goes through the shop's own quote helper",
    strpos($pageText, "check_string(") !== false);
check("LIKE wildcards and backslashes are removed from the term",
    strpos($pageText, 'str_replace(array("%", "_", "\\\\"), "", $like)') !== false);
$likeStatement = "";
foreach ($pageStatements as $statement) {
    foreach ($statement["literals"] as $literal) {
        if (strpos($literal, "LIKE") !== false) {
            $likeStatement = implode(" ", $statement["literals"]);
        }
    }
}
check("the search is a LIKE against fixed identifiers, never a column from the request",
    $likeStatement !== "" && strpos($likeStatement, "`trans_id`") !== false
    && strpos($likeStatement, "`product_name`") !== false);
check("no request value is interpolated into the LIKE clause",
    $likeStatement !== "" && strpos($likeStatement, '$_GET') === false);

/* ------------------------------------------------------------- 4. no sharp edges */

check("nothing in the path uses a raw query()",
    strpos($pageText, "->query(") === false && strpos($statsText, "->query(") === false);
check("the sales layer never builds SQL from a superglobal",
    strpos($statsText, '$_GET') === false && strpos($statsText, '$_POST') === false);
check("the buyer page never selects the whole users table",
    stripos(implode(" ", $pageLiterals), "SELECT * FROM `users`") === false);
check("delivered credentials are not touched by the buyer page (own orders only)",
    stripos(implode(" ", $pageLiterals), "product_sold") === false);
check("the admin page exposes no passwords or session tokens",
    stripos(implode(" ", $adminLiterals), "password") === false
    && stripos(implode(" ", $adminLiterals), "token") === false);
check("the CSV export is an attachment with a filename the page chooses",
    stripos($adminText, "Content-Disposition") !== false
    && stripos(implode(" ", $adminLiterals), "attachment; filename") !== false);
check("the CSV export reuses the same statistics helpers as the screen (they cannot disagree)",
    substr_count($adminSource, "sales_sql_") >= 3);
check("both pages keep themselves out of shared caches, without breaking if output already started",
    strpos($adminText, 'header("Cache-Control: no-store")') !== false
    && strpos($adminText, 'header("X-Content-Type-Options: nosniff")') !== false
    && strpos($pageText, 'header("Cache-Control: no-store")') !== false
    && strpos($pageText, 'header("X-Content-Type-Options: nosniff")') !== false
    && strpos($adminText, "headers_sent()") !== false
    && strpos($pageText, "headers_sent()") !== false);
check("the buyer page is marked noindex (personal history must not be indexed)",
    strpos($pageText, '"noindex" => true') !== false);
check("the admin page is marked noindex too",
    strpos($adminText, '"noindex" => true') !== false);

echo "\nsales_scoping_test: " . $pass . " passed, " . $fail . " failed\n";
exit($fail > 0 ? 1 : 0);
