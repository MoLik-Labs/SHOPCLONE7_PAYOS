<?php
/*
 * token-based static scan for the patterns that have actually caused bugs in this shop.
 *
 *   php tools/security-scan.php                 # summary + findings
 *   php tools/security-scan.php --fail-on=high  # exit 1 when a HIGH finding exists (CI gate)
 *   php tools/security-scan.php --path=ajaxs    # limit to one folder
 *   php tools/security-scan.php --json
 *
 * This is a heuristic, not a proof. It reads tokens (so comments and strings do not confuse it),
 * tracks the nearest assignment of a request superglobal, and reports where that value reaches SQL,
 * HTML or the filesystem without one of the sanitizers this codebase uses. Every finding prints the
 * file, the line and the code, so a human can confirm it in seconds.
 *
 * Rules
 *   SQL      request value concatenated into a string that is later passed to a DB method
 *   XSS      request value echoed / printed without htmlspecialchars/store_e/check_string/int cast
 *   INCLUDE  request value used in include/require
 *   EXEC     exec/system/shell_exec/passthru/eval/unserialize on anything
 *   PASSWD   md5()/sha1() used to build a password hash
 *   GUARD    a top-level PHP file under views/, ajaxs/, api/ without the IN_SITE guard
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$options = array("fail-on" => "", "path" => "", "json" => false);
foreach (array_slice($argv, 1) as $argument) {
    if (strpos($argument, "--fail-on=") === 0) {
        $options["fail-on"] = strtolower(substr($argument, 10));
    } elseif (strpos($argument, "--path=") === 0) {
        $options["path"] = trim(substr($argument, 7), "/");
    } elseif ($argument === "--json") {
        $options["json"] = true;
    }
}

$targets = array("views", "ajaxs", "api", "libs", "models", "cron", "mod");
if ($options["path"] !== "") {
    $targets = array($options["path"]);
}

$files = array();
foreach ($targets as $target) {
    $base = $root . "/" . $target;
    if (!is_dir($base)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $item) {
        if ($item->isFile() && substr($item->getFilename(), -4) === ".php") {
            $files[] = $item->getPathname();
        }
    }
}
/* Root-level entry points too (index.php, install.php, …). */
foreach (glob($root . "/*.php") as $entry) {
    $files[] = $entry;
}
sort($files);

$request = array('$_GET', '$_POST', '$_COOKIE', '$_REQUEST');
$sanitizers = array("check_string", "htmlspecialchars", "store_e", "intval", "(int)", "addslashes",
    "mysqli_real_escape_string", "preg_replace", "filter_var", "order_quantity", "store_account_search",
    "store_account_limit", "store_account_page", "store_account_date_range", "orbit_post_id_list",
    "store_pager", "stripslashes",
    "json_encode(array_values(array_intersect");
$dbCalls = array("get_row", "get_list", "query", "num_rows", "update", "insert", "remove", "cong", "tru");

$findings = array();
$scanned = 0;

foreach ($files as $file) {
    $source = file_get_contents($file);
    $relative = ltrim(str_replace($root, "", $file), "/");
    /* Skip third-party and generated files. */
    if (preg_match('#^(vendor|public/|tools/dev/node_modules)#', $relative) && strpos($relative, "tools/") !== 0) {
        continue;
    }
    $tokens = @token_get_all($source);
    if (!$tokens) {
        continue;
    }
    $scanned++;

    /* GUARD: a web-facing file must define IN_SITE or be index.php/install.php. */
    if (preg_match('#^(views|ajaxs|api|models)/#', $relative)
        && strpos($source, 'define("IN_SITE"') === false && strpos($source, "define('IN_SITE'") === false
        && strpos($source, "defined(\"IN_SITE\")") === false && strpos($source, "defined('IN_SITE')") === false
        && strpos($source, "http_response_code(") === false) {
        $findings[] = array("rule" => "GUARD", "severity" => "high", "file" => $relative, "line" => 1,
            "code" => "no IN_SITE guard", "note" => "File can be requested directly.");
    }

    /* Track variables assigned from a request superglobal: var → true (raw). */
    $tainted = array();
    $line = 1;
    $count = count($tokens);
    $statement = "";
    $statementLine = 0;
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        $text = is_array($token) ? $token[1] : $token;
        if (is_array($token) && $token[0] === T_WHITESPACE) {
            $line += substr_count($text, "\n");
            $statement .= $text;
            continue;
        }
        /* A close tag ends a statement just like ";" does: everything before it is one echo. */
        $isCloseTag = is_array($token) && defined("T_CLOSE_TAG") && $token[0] === T_CLOSE_TAG;
        if ($text === ";" || $text === "{" || $isCloseTag) {
            /* A finished statement is where echo/print can be judged as a whole: the tainted variable
               is usually appended after the echo keyword. */
            if (preg_match('/\b(echo|print)\b/', $statement)) {
                /* Comparisons and isset()/empty() consume a value; they do not print it. Strip those
                   shapes first so `echo $x === "1" ? "on" : "off"` is not reported. */
                $printed = preg_replace('/\b(isset|empty)\([^()]*(\([^()]*\)[^()]*)*\)/', " ", $statement);
                $printed = preg_replace('/\$\w+\s*(===|!==|==|!=|<=|>=|<|>)\s*(\"[^\"]*\"|\'[^\']*\'|[^?:;\s]+)/', " ", $printed);
                $hasDirty = false;
                foreach ($tainted as $variable => $isDirty) {
                    /* Match the whole variable name: $x must not match $xLabel. */
                    if ($isDirty && preg_match('/' . preg_quote($variable, "/") . '(?![A-Za-z0-9_])/', $printed)) {
                        $hasDirty = true;
                    }
                }
                $hasClean = false;
                foreach ($sanitizers as $sanitizer) {
                    if (strpos($statement, $sanitizer) !== false) {
                        $hasClean = true;
                    }
                }
                if ($hasDirty && !$hasClean) {
                    $findings[] = array("rule" => "XSS", "severity" => "high", "file" => $relative,
                        "line" => $statementLine, "code" => substr(preg_replace('/\s+/', " ", $statement), 0, 120),
                        "note" => "Request value printed without escaping.");
                }
            }
            $statement = "";
            $statementLine = 0;
            $line += substr_count($text, "\n");
            if ($isCloseTag) {
                $line += 1;
            }
            continue;
        }
        if (is_array($token) && defined("T_OPEN_TAG") && $token[0] === T_OPEN_TAG) {
            $statement = "";
            $statementLine = 0;
            $line += substr_count($text, "\n");
            continue;
        }
        if (trim($statement) === "") {
            $statementLine = $line;
        }
        $statement .= $text;

        /* --- assignments: $x = $_POST[...] / check_string($_POST[...]) etc. ------------------- */
        if (is_array($token) && $token[0] === T_VARIABLE) {
            $next = $i + 1;
            while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                $next++;
            }
            if ($next < $count && $tokens[$next] === "=") {
                $expr = "";
                for ($j = $next + 1; $j < $count && $j < $next + 24; $j++) {
                    $part = $tokens[$j];
                    if (is_string($part) && ($part === ";" || $part === ",")) {
                        break;
                    }
                    $expr .= is_array($part) ? $part[1] : $part;
                }
                $isRequest = false;
                foreach ($request as $super) {
                    if (strpos($expr, $super) !== false) {
                        $isRequest = true;
                    }
                }
                $isClean = false;
                foreach ($sanitizers as $sanitizer) {
                    if (strpos($expr, $sanitizer) !== false) {
                        $isClean = true;
                    }
                }
                if ($isRequest) {
                    $tainted[$token[1]] = !$isClean;
                }
            }
        }

        /* --- SQL: a DB call whose argument mentions a tainted variable ------------------------ */
        if (is_array($token) && $token[0] === T_STRING && in_array($token[1], $dbCalls, true)) {
            /* Collect the call's arguments up to the matching close paren. */
            $depth = 0;
            $argument = "";
            $started = false;
            for ($j = $i + 1; $j < $count && $j < $i + 400; $j++) {
                $part = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                if ($part === "(") {
                    $depth++;
                    $started = true;
                    continue;
                }
                if ($part === ")") {
                    $depth--;
                    if ($started && $depth === 0) {
                        break;
                    }
                    continue;
                }
                if ($started) {
                    $argument .= $part;
                }
            }
            $argumentIsClean = false;
            foreach ($sanitizers as $sanitizer) {
                if (strpos($argument, $sanitizer) !== false) {
                    $argumentIsClean = true;
                }
            }
            foreach ($tainted as $variable => $isDirty) {
                if ($isDirty && !$argumentIsClean
                    && preg_match('/' . preg_quote($variable, "/") . '(?![A-Za-z0-9_])/', $argument)) {
                    $findings[] = array("rule" => "SQL", "severity" => "high", "file" => $relative,
                        "line" => $line, "code" => $token[1] . "() uses " . $variable,
                        "note" => "Request value reaches SQL; confirm it is escaped.");
                    break;
                }
            }
        }


        /* --- INCLUDE / EXEC / PASSWD --------------------------------------------------------- */
        if (is_array($token) && in_array($token[1], array("include", "include_once", "require", "require_once"), true)) {
            $rest = "";
            for ($j = $i + 1; $j < $count && $j < $i + 20; $j++) {
                $part = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                if ($part === ";") {
                    break;
                }
                $rest .= $part;
            }
            foreach ($request as $super) {
                if (strpos($rest, $super) !== false) {
                    $findings[] = array("rule" => "INCLUDE", "severity" => "critical", "file" => $relative,
                        "line" => $line, "code" => substr($rest, 0, 120), "note" => "Request value selects an include.");
                }
            }
        }
        if (is_array($token) && $token[0] === T_STRING
            && in_array($token[1], array("exec", "system", "shell_exec", "passthru", "popen", "proc_open", "eval"), true)) {
            $findings[] = array("rule" => "EXEC", "severity" => "high", "file" => $relative, "line" => $line,
                "code" => $token[1] . "(…)", "note" => "Arbitrary command/code execution primitive.");
        }
        if (is_array($token) && $token[0] === T_STRING && in_array($token[1], array("md5", "sha1"), true)
            && strpos($statement, "password") !== false && strpos($statement, "TypePassword") === false) {
            $findings[] = array("rule" => "PASSWD", "severity" => "high", "file" => $relative, "line" => $line,
                "code" => substr($token[1] . "(…)", 0, 80), "note" => "Fast hash used near a password.");
        }
    }
}

/* Collapse identical findings (echo statements can be reported twice for two variables). */
$unique = array();
foreach ($findings as $finding) {
    $key = $finding["rule"] . "|" . $finding["file"] . "|" . $finding["line"] . "|" . $finding["code"];
    $unique[$key] = $finding;
}
$findings = array_values($unique);
usort($findings, function ($a, $b) {
    $order = array("critical" => 0, "high" => 1, "medium" => 2, "low" => 3);
    return array($order[$a["severity"]], $a["file"], $a["line"]) <=> array($order[$b["severity"]], $b["file"], $b["line"]);
});

$counts = array("critical" => 0, "high" => 0, "medium" => 0, "low" => 0);
$byRule = array();
foreach ($findings as $finding) {
    $counts[$finding["severity"]]++;
    $byRule[$finding["rule"]] = isset($byRule[$finding["rule"]]) ? $byRule[$finding["rule"]] + 1 : 1;
}

if ($options["json"]) {
    echo json_encode(array("scanned" => $scanned, "counts" => $counts, "rules" => $byRule,
        "findings" => $findings), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
} else {
    foreach ($findings as $finding) {
        echo strtoupper($finding["severity"]) . "  " . $finding["rule"] . "  " . $finding["file"]
            . ":" . $finding["line"] . "  " . $finding["code"] . "\n";
        echo "        " . $finding["note"] . "\n";
    }
    echo "\nSCAN " . $scanned . " files: " . $counts["critical"] . " critical, " . $counts["high"] . " high, "
        . $counts["medium"] . " medium, " . $counts["low"] . " low\n";
    if (!empty($byRule)) {
        ksort($byRule);
        foreach ($byRule as $rule => $number) {
            echo "  " . str_pad($rule, 8) . $number . "\n";
        }
    }
}

if ($options["fail-on"] !== "") {
    $threshold = array("critical" => 4, "high" => 3, "medium" => 2, "low" => 1);
    $limit = isset($threshold[$options["fail-on"]]) ? $threshold[$options["fail-on"]] : 0;
    foreach ($counts as $severity => $number) {
        if ($number > 0 && $threshold[$severity] >= $limit) {
            exit(1);
        }
    }
}
exit(0);
