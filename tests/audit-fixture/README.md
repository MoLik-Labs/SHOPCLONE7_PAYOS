# Frontend-audit self-test fixture

Every file here exists to be *wrong* on purpose (or deliberately right), one rule per file, so
`tools/frontend-audit.php --path=tests/audit-fixture` can be checked against `expected.json`.

Run it (from the repository root):

    python3 tests/audit-fixture/run_selftest.py

The driver runs the audit through the PHP harness, compares the reported rule→file pairs **and their counts**
with `expected.json`, and prints `PASS`/`FAIL` per fixture. `audit_runner.php` is only an argument shim: some
runtimes used to verify this checkout (PHP-WASM, SAPI `wasm`) start scripts without `$argv`, so the shim turns
`AUDIT_ARGS` into `$argv` and includes the real tool — nothing in `tools/` is modified for testing. Point the
driver at another runner with `--harness`, or set `HARNESS_RUN`.

Why both wrong and right fixtures: a rule that stops firing is a silent loss of coverage, and a rule that
starts firing on correct code is a false alarm. `bad/` and `ok/` hold the two music-player fixtures, whose
rules only apply to files with those names; `ok-namespace.js` and `bad-w3c-hotlink.css` pin the one host
exemption (an XML namespace is an identifier, a stylesheet hotlink is a request). Nothing here is loaded by
the shop — the folder is test data only.
