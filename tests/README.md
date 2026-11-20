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

## Harness

The sandbox images do not ship a PHP binary. The project keeps a PHP-WASM runner under
`tools/dev/`, so every suite can be executed with Node:

```
npm install --prefix tools/dev                     # once per workspace restore
node tools/dev/phpcl.mjs tests/storefront_test.php
bash tools/dev/run.sh                              # lint + security scan + every suite
bash tools/dev/run.sh --tests-only tests/csrf_guard_test.php
```

`run.sh` prints `PASS`/`FAIL` per suite and exits non-zero on the first failure. A suite that cannot
run (database or sub-process missing) is reported as **NOT RUN**, never as passed.

## Suites added in rounds 12–13d

All of these need nothing but the harness above. Each one's header comment names the bug or contract
it pins.

| Suite | Covers |
| --- | --- |
| `csrf_guard_test.php` | every `ajaxs/{client,admin}/*.php` write endpoint calls the guard, checks its own `*_csrf` token, or is the documented `api_key` exception |
| `cron_guard_test.php` | cron workers refuse HTTP without `CRON_TOKEN` (CLI still allowed), constant-time compare |
| `auth_password_test.php` | raw-byte password hashing, legacy sanitised hash upgrade, no `check_string()` on passwords |
| `order_quantity_test.php` | digits-only quantity parsing before it reaches `LIMIT`; min/max and hostile inputs |
| `coupon_claim_test.php` | reservation before charge, one-slot coupon under two orders, discount clamp, commission clawback |
| `db_hardening_test.php` | driver error mode, utf8mb4, no `exit()` on SQL error, tri-state credit/refund/debit, row locks, duplicate audit |
| `payment_callback_test.php` | exactly-once crediting and retry semantics for card, crypto, Flutterwave, Perfect Money, Squad, bank |
| `email_campaign_test.php` | campaign creation, `INSERT … SELECT` for everyone, forged ids, chunked queue writes, one transaction |
| `admin_user_lookup_test.php` | generic name lookup, whitelist refusal, 500-id chunks, grouped optgroups/counts, no per-row user lookups |
| `affiliate_rate_test.php` | one `ref_ck` read per referrer, memoisation, fallback rate, no `ref_ck` left in purchase/cron sources |
| `purchase_flow_guards_test.php` | delivered-vs-requested quantities, partial refund wiring, conditional debit, API_20 refunds, quote clamp |
| `partial_delivery_test.php` | proration arithmetic (full/partial/over-delivery, rounding, hostile numbers) and both checkout wirings |
| `random_user_test.php` | bounded PK probing for demo buyers, sparse/empty id spaces, deterministic fill, cron wiring |
| `auth_guard_test.php` | sign-in lockout semantics, reset of `login_attempts`, forgot-password enumeration, logout cookie handling |
| `upload_guard_test.php` | upload type follows the bytes, old files deleted only after a successful store, path-traversal refusals, both product forms and the stock importer |
| `security_headers_test.php` | baseline nosniff / frame / referrer / permissions headers and their wiring in both page layouts |
| `stock_claim_test.php` | local stock is delivered exactly once (claim by delete, rollback on a failed sold insert), and both checkouts use the helper |
| `amount_guard_test.php` | withdrawal and gateway amount parsing (strings, exponents, NAN/INF), atomic commission debit, restore on a failed insert, legacy card/crypto amounts |
| `count_query_test.php` | the `num_rows()` COUNT rewrite: plain scans rewritten, DISTINCT/GROUP BY/LIMIT/JOIN/subquery/expression untouched |
| `settings_cache_test.php`, `lang_cache_test.php` | one settings load and one language load per request; cache invalidation |
| `self_hosted_assets_test.php` | every admin asset resolves on disk, licences present, no unapproved remote host |
| `account_pages_test.php`, `client_pages_test.php`, `content_pages_test.php`, `orders_pages_test.php`, `recharge_pages_test.php`, `oauth_pages_test.php`, `storefront_test.php` | every customer-facing page renders on the shell: one `h1`, no inline style, escaped output, honest empty states, no PHP warning |
