<?php
/*
 * Argument shim for the audit self-test.
 *
 * tools/frontend-audit.php is a CLI script: it reads $argv. Some PHP runtimes used to verify this checkout
 * (the PHP 7.4 WASM harness in particular) start scripts with the "wasm" SAPI, where $argv does not exist.
 * This shim turns the AUDIT_ARGS environment variable into $argv and then includes the real tool, so the
 * audit is exercised exactly as written — nothing in tools/ is modified for testing.
 *
 * Called by tests/audit-fixture/run_selftest.py, which sets AUDIT_ARGS="--path=tests/audit-fixture --json".
 */

$args = getenv("AUDIT_ARGS");
if ($args === false || trim($args) === "") {
    fwrite(STDERR, "AUDIT_ARGS is empty\n");
    exit(2);
}
$argv = array_merge(array("frontend-audit.php"), preg_split("/\s+/", trim($args)));
$argv = array_values(array_filter($argv, function ($part) {
    return $part !== "" && $part !== null;
}));
/* Find the tool whether this file is being run from the repository (tests/audit-fixture/…) or injected
   into the harness at the filesystem root with the checkout mounted at /app. */
$candidates = array(
    dirname(__DIR__, 2) . "/tools/frontend-audit.php",
    getcwd() . "/tools/frontend-audit.php",
    "/app/tools/frontend-audit.php",
);
foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        require $candidate;
        exit(0);
    }
}
fwrite(STDERR, "audit_runner: frontend-audit.php not found near " . __DIR__ . "\n");
exit(3);
