# Tests

Every suite is a standalone PHP script. Each one prints its own summary (`<name>: N passed, M failed`) and
exits non-zero when anything failed, so it can be run alone or from a loop:

```
php tests/chart_test.php
```

PHP 7.4 is the target. Nothing here talks to a payment provider, a real card, a real customer or a real
balance; the suites use stubs, synthetic fixtures and disposable databases.

| Suite | Needs | Covers |
| --- | --- | --- |
| `chart_test.php` | nothing | axis steps, series/donut maths, escaping of chart payloads |
| `sales_stats_test.php` | nothing | periods, growth, aggregation SQL, CSV-safe cells |
| `sales_pages_test.php` | nothing | both new pages rendered end to end against a stub DB |
| `sales_scoping_test.php` | nothing | the buyer page can only ever read the session user's rows |
| `pagination_test.php` | nothing | page windows, ellipses, markup, hostile hrefs |
| `sidebar_links_test.php` | nothing | the two sidebar entries, and that the insertion left the markup intact |
| `styleguide_render_test.php` | nothing | the shell + every component on the living reference page |
| `orbit_shell_test.php` | nothing | the shell contract (one doctype, landmarks, no invented copy) |
| `music_player_test.php` | nothing | the player renders nothing while empty; hostile playlist entries dropped |
| `licence_client_test.php` | nothing | the shop-side licence client: offline grace, bad signatures, timeouts |
| `gtf_rules_test.php` | nothing | the card/bank rules, the credit lock, fail-closed behaviour |
| `gtf_service_test.php`, `gtf_credit_path_test.php`, `gtf_credit_path_mutation.php`, `gtf_migration_verify.php` | a **disposable** MySQL database whose DSN contains `test` | exactly-once crediting, races, migration verification. They refuse to run against anything else |
| `gtf_migration_fixture.php`, `gtf_transport_probe.php` | a disposable database | helpers used by the suites above |
| `legacy_payment_gate_test.php`, `legacy_security_test.php`, `payos_*_test.php` | a PHP binary that can spawn sub-processes | the legacy payment entry points answer exactly as before |
| `audit-fixture/run_selftest.py` | a runner for `tools/frontend-audit.php` (see that folder's README) | every audit rule, with a wrong and a right fixture each |

Two notes for whoever runs these next:

* The suites that need a runner (the audit self-test, and anything that spawns a sub-process) are verified
  with a PHP-WASM harness kept outside this repository; each suite's own documentation says what to point at
  it (`--harness`, `HARNESS_RUN`, or `AUDIT_ARGS`).
* When a suite cannot run — no database, no runner — it must be reported as **NOT RUN**, never as passed.
