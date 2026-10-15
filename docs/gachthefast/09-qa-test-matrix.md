# 09 — QA / backtesting report and test matrix (2026-10-04)

**Scope of this pass:** the frozen GachtheFast implementation (`libs/gtf*.php`, `api/callback-gtf-card.php`,
`ajaxs/{client,admin}/gtf.php`, `cron/gtf-*.php`, `scripts/migrate-gtf.php`, the three `recharge-gtf*`
views) plus the pre-existing regression suites. Isolated and deterministic only: fake HTTP transports,
synthetic fixtures, a disposable database, and a loopback-only HTTPS endpoint. **No production provider
was contacted, no real card/credential/user/balance was used, nothing was enabled, no migration touched a
real database.**

**Decision at the end of this pass: still NO-GO** (`04-lead-report.md`). Every money path is still locked
(`GTF_CARD_ENABLED=false`, `GTF_ALLOW_CREDITING=false`) and the open contract questions in
`08-verification-and-owner-questionnaire.md` §8.4 are unanswered. This document records what was
*executed*; it does not change the decision.

---

## 1. Runtime and harness provenance (read this before quoting any result)

| Component | What actually ran | Caveats that must travel with the numbers |
| --- | --- | --- |
| PHP | **7.4.33 CLI**, `@php-wasm/node` 3.1.54 (WebAssembly build of the real interpreter), `curl 7.69.1 / OpenSSL 1.1.1t`, extensions include `pdo_mysql, mysqli, curl, openssl, mbstring, gd, zip, soap, imagick` | It is a WASM build, not a distro/packaged PHP. `proc_open()` **cannot spawn processes**, which is the only functional gap this pass hit. |
| Database | **MySQL 5.6.24** (npm package `mysql-server-5.6-linux-x64`), disposable datadir, TCP `127.0.0.1:3316`, started with `--skip-grant-tables` | 2015-era engine; no privilege layer; not the production version. Every database touched is a throwaway created by the test harness (`gtf_test`, `gtf_mut_test`, `gtf_mig_test_*`, `payos_test_qa`). |
| Fixture schema | Created by the test files themselves; `gtf_test`/`gtf_mut_test` carry only the columns the code reads/writes | **This is a FIXTURE, not the shop's schema.** The production DDL is not in the repository (`*.sql` is git-ignored and was never supplied). |
| Composer dependencies | The checkout has **no `vendor/`** and no network access to Packagist, so the affected entry points could not load `vendor/autoload.php` (`libs/gtf_bootstrap.php:13`, `libs/payos_bootstrap.php:8`, `libs/db.php:6`). A **test-only Dotenv/autoload stub** was injected into the interpreter's filesystem (`SHIM_VENDOR=1` in the runner). | The repository is untouched: the stub lives only inside the WASM filesystem and is **not** a substitute for `composer install`. In a real environment the genuine library must provide `.env` loading. |
| TLS endpoint | Loopback-only Python HTTPS server on `127.0.0.1:443` with a self-signed test CA (SAN `IP:127.0.0.1`), trusted **inside the test harness only** | Never a provider. The shipped client keeps `CURLOPT_SSL_VERIFYPEER=true` / `SSL_VERIFYHOST=2`; the negative probe proves verification is genuinely enforced. |
| Runner | `/tmp/phpwasm/php74.mjs` (harness-local, not in the repository): modes `lint`, `run`, `eval`, `envrun`, `probe`, `lintlist`; mirrors the repository into `/app` inside the interpreter | Recreate with `cd /tmp/phpwasm && npm install --no-audit --no-fund @php-wasm/node`. Invocations below are the exact ones used. |

---

## 2. Test matrix

| # | Test | Result | Evidence |
| --- | --- | --- | --- |
| 1 | PHP 7.4 lint, **every** PHP file in the checkout (232 files) | **PASS** | §3.1 — `232 passed, 0 failed (PHP 7.4.33)`, exit 0 |
| 2 | `gtf_rules_test.php` — documented statuses `1,2,3,4,99,100`, unknown/malformed statuses, amount validation, origin pinning, format pinning, credit lock, bank fail-closed, secret sealing, source guarantees | **PASS** | §3.2 — `124 passed, 0 failed`, exit 0 |
| 3 | `gtf_service_test.php` — callback signing/replay, wrong ids, amount/value mismatch, unknown status, submit/check timeouts, secret redaction, status-2 policy, two-connection locking (C0–C14) | **PASS** | §3.3 — `93 passed, 0 failed`, exit 0 |
| 4 | `gtf_credit_path_test.php` — the credit path itself: happy path, replay, rollback atomicity, operator path, malformed amounts, re-lock (A0–A11) | **PASS** | §3.4 — `75 passed, 0 failed`, exit 0 |
| 5 | `gtf_credit_path_mutation.php` — proves the exactly-once assertions are load-bearing (drops the unique key and checks the guard disappears) | **PASS** | §3.5 — `2 detected, 0 NOT detected`, exit 0 |
| 6 | `gtf_transport_probe.php` positive — real `GtfCurlTransport`/`GtfCardClient` against the loopback endpoint: wire format (json + form), POST only, path, signature, malformed JSON, empty body, oversized body, **redirect not followed**, 500, 404 | **PASS** | §3.6 — `18 passed, 0 failed`, exit 0 |
| 7 | `gtf_transport_probe.php` negative — the endpoint presents an **untrusted** certificate | **PASS** | §3.7 — `3 passed, 0 failed`, exit 0 (TLS verification is enforced; failure surfaces as a transport error with no body) |
| 8 | Migration happy path (`scripts/migrate-gtf.php`) on the InnoDB fixture | **PASS** | §3.8 — exit 0, creates `gtf_card_orders`, `gtf_ledger`, `gtf_events` |
| 9 | Migration post-conditions on the InnoDB fixture (tables, InnoDB engine, unique keys, legacy columns untouched) | **PASS** | §3.8 — `gtf_migration_verify (created): 15 passed, 0 failed` |
| 10 | Migration idempotency (second run) | **PASS** | §3.8 — exit 0, no duplicate/erroneous DDL |
| 11 | Migration rollback + post-conditions | **PASS** | §3.8 — dropped only the three `gtf_*` tables; `gtf_migration_verify (rolledback): 9 passed, 0 failed` |
| 12 | Migration refusal on a **MyISAM** legacy schema, then `--convert` | **PASS** | §3.9 — refusal message listing all six tables, **exit 1**, no table created; `--convert` moved all six to InnoDB and completed (`converted: 9 passed, 0 failed`) |
| 13 | Migration refusal when a required column is missing (`users.token`) | **PASS** | §3.10 — `Migration failed: … Unknown column 'token'`, **exit 1**, nothing created |
| 14 | Migration refusal on a database with no legacy tables, and on an unknown option | **PASS** | §3.10 — both **exit 1**, nothing created |
| 15 | `legacy_security_test.php` (pre-existing regression) | **PASS** | §3.11 — “Legacy security regression checks passed.”, exit 0 |
| 16 | `payos_client_test.php` (pre-existing regression, offline) | **PASS** | §3.11 — `OK: 45 payOS client checks passed (offline).`, exit 0 |
| 17 | `payos_service_test.php` (pre-existing regression, database + concurrency) | **PARTIAL — harness-blocked, then replicated** | §3.12 — 39 assertions passed, then the shipped file calls `proc_open()` (line 136) which the WASM PHP cannot do: **NOT RUN** as shipped. Its concurrency section was reproduced with two real parallel worker processes: results `already_credited` + `credited`, wallet `5500.00` exactly once, one `deposit_log`, one `dongtien`, order `PAID` — **PASS (replicated, not a run of the shipped file)** |
| 18 | `legacy_payment_gate_test.php` (pre-existing regression) | **PARTIAL — harness-blocked, then replicated** | §3.13 — the shipped file is **NOT RUN** (it spawns `PHP_BINARY` subprocesses). Its 14 entry-point/action probes were executed individually, each in its own process: **14/14 return `HTTP_STATUS=410`** |
| 19 | `payos_race_worker.php` | **PASS (as part of #17)** | §3.12 — executed twice concurrently with a disposable order |
| 20 | Bank adapter: disabled and fail-closed, no invented contract, no HTTP call, no crediting routine reachable | **PASS** | §3.2 (bank section of the rules suite) + §3.4 A11 |
| 21 | Secrets: no PIN/serial in orders, ledger, events, or error messages; masked serial only | **PASS** | §3.3 C14 + §3.6 (error strings carry no response body) |
| 22 | Production provider contract (real hostname, real response schema, real status semantics, real callbacks) | **NOT RUN — impossible** | No provider contract was supplied; testing it would require a live provider, which this pass is forbidden to contact |
| 23 | Migration against the **production** database | **NOT APPLICABLE / NOT RUN** | No authorisation, no production DB, no backup; only the isolated fixture was exercised |
| 24 | Bank transfer flow (endpoint, `history-receive`, auth, settlement) | **NOT RUN — impossible** | No GachtheFast bank API contract exists; the adapter is a fail-closed stub and must stay that way |
| 25 | Admin UI endpoints (`ajaxs/admin/gtf.php`) end-to-end (session, permission, CSRF, demo-mode guard) | **NOT RUN** | Needs the full CMSNT bootstrap + admin session; reviewed by reading only. The service layer behind it **is** tested (#4, A6/A9) |
| 26 | Production-schema equivalence of the fixture | **NOT RUN** | Production DDL unavailable; see GAP-6 |

---

## 3. Evidence log — exact commands and actual output

Runner prefix used throughout (harness-local, not in the repository). `R` is the checkout root.

```bash
cd /tmp/phpwasm && R=/home/user/SHOPCLONE7_PAYOS && export SHIM_VENDOR=1
```

### 3.1 PHP 7.4 lint, all PHP files

```bash
cd $R && find . -name '*.php' -not -path './.git/*' -print > /tmp/phplist.txt   # 232 files
mapfile -t FILES < /tmp/phplist.txt && PATHS=(); for f in "${FILES[@]}"; do PATHS+=("$R/${f#./}"); done
cd /tmp/phpwasm && node php74.mjs lint "${PATHS[@]}"
```

```
php -l summary: 232 passed, 0 failed (PHP 7.4.33)
lint exit=0
```

(232 = the checkout's files including the four test files added by this pass. The earlier count of 228 in
older notes predates them.)

### 3.2 Offline rules suite

```bash
cd /tmp/phpwasm && node php74.mjs run $R/tests/gtf_rules_test.php
```

```
gtf_rules_test: 124 passed, 0 failed
exit=0
```

### 3.3 Database state-machine suite (disposable `gtf_test`)

```bash
cd /tmp/phpwasm && node php74.mjs envrun \
  '{"GTF_TEST_DSN":"mysql:host=127.0.0.1;port=3316;dbname=gtf_test","GTF_TEST_DB_USER":"root","GTF_TEST_DB_PASS":""}' \
  $R/tests/gtf_service_test.php
```

```
== C13 two database connections cannot credit the same order twice
  ok   worker 1 credits the order [expected 'credited', got 'credited']
  ok   worker 2, holding a stale row, is refused [expected 'already_credited', got 'already_credited']
  ok   a credit blocked by another connection fails instead of waiting forever
  ok   the blocked credit moved no money [expected '50000.00', got '50000.00']
  ok   once the lock is released the credit succeeds [expected 'credited', got 'credited']
== C14 card secrets never reach stored text, events or errors
  ok   no raw card data appears in order rows
  ok   only the last three characters of the serial are readable
gtf_service_test: 93 passed, 0 failed
exit=0
```

### 3.4 Credit-path suite (test-only `GTF_ALLOW_CREDITING=true`, disposable `gtf_test`)

```bash
cd /tmp/phpwasm && node php74.mjs envrun \
  '{"GTF_TEST_DSN":"mysql:host=127.0.0.1;port=3316;dbname=gtf_test","GTF_TEST_DB_USER":"root","GTF_TEST_DB_PASS":""}' \
  $R/tests/gtf_credit_path_test.php
```

```
  ok   a second verify of a credited order is refused [expected 'already_credited', got 'already_credited']
  ok   operator credit refuses an already-credited order [threw DomainException]
  ok   a credit whose value disagrees with the stored amount is refused [threw RuntimeException]
  ok   every malformed or mis-sized amount was refused [expected 9, got 9]
  ok   a provider-confirmed card is parked, not paid (A11, lock restored)
gtf_credit_path_test: 75 passed, 0 failed
exit=0
```

### 3.5 Mutation self-check — the exactly-once assertions can fail

```bash
cd /tmp/phpwasm && node php74.mjs envrun \
  '{"GTF_TEST_DSN":"mysql:host=127.0.0.1;port=3316;dbname=gtf_mut_test","GTF_TEST_DB_USER":"root","GTF_TEST_DB_PASS":""}' \
  $R/tests/gtf_credit_path_mutation.php
```

```
  detected probe 1: with the unique key, a duplicate credit is rejected (first write = credited)
  detected probe 2: without the unique key, the same duplicate is accepted (so the suite is not vacuous)
gtf_credit_path_mutation: 2 detected, 0 NOT detected
exit=0
```

### 3.6 Transport probe — wire format and response handling (loopback endpoint)

```bash
sudo python3 /tmp/gtfprobe/server.py /tmp/gtfprobe/cert.pem /tmp/gtfprobe/key.pem   # 127.0.0.1:443
cd /tmp/phpwasm && node php74.mjs probe $R/tests/gtf_transport_probe.php \
  '{"GTF_PROBE_BASE":"https://127.0.0.1","GTF_PROBE_CA":"/etc/ssl/certs/ca-certificates.crt"}' \
  /tmp/gtfprobe/cert.pem:/etc/ssl/certs/ca-certificates.crt
```

```
  ok   the documented path is used
  ok   the request is a POST
  ok   json format sends application/json
  ok   the body is the documented JSON field set
  ok   the signature on the wire is md5(partner_key+code+serial)
  ok   form format sends x-www-form-urlencoded
  ok   malformed JSON is refused
  ok   an empty body is refused
  ok   an oversized response is refused
  ok   a redirect is NOT followed
  ok   a 500 is refused
  ok   a 404 is refused
gtf_transport_probe: 18 passed, 0 failed
exit=0
```

Endpoint log for the same run (every request loopback, all POST, none to a real host):

```
probe request path=/chargingws/v2 method=POST request_id=req-echo bytes=191
probe request path=/chargingws/v2 method=POST request_id=req-ok bytes=189
probe request path=/chargingws/v2 method=POST request_id=req-bad bytes=190
probe request path=/chargingws/v2 method=POST request_id=req-big bytes=190
probe request path=/chargingws/v2 method=POST request_id=req-302 bytes=190
probe request path=/chargingws/v2 method=POST request_id=req-500 bytes=190
probe request path=/chargingws/v2 method=POST request_id=req-404 bytes=190
```

### 3.7 Transport probe — negative TLS mode (endpoint presents an untrusted certificate)

```bash
sudo python3 /tmp/gtfprobe/server.py /tmp/gtfprobe/other.pem /tmp/gtfprobe/other-key.pem
cd /tmp/phpwasm && node php74.mjs probe $R/tests/gtf_transport_probe.php \
  '{"GTF_PROBE_BASE":"https://127.0.0.1","GTF_PROBE_CA":"/etc/ssl/certs/ca-certificates.crt","GTF_PROBE_EXPECT_TLS_FAILURE":"1"}' \
  /tmp/gtfprobe/cert.pem:/etc/ssl/certs/ca-certificates.crt
```

```
  ok   an untrusted certificate is refused
  ok   the failure is a transport error, not a parsed provider answer
  ok   the failure message carries no response body
gtf_transport_probe (tls-negative): 3 passed, 0 failed
exit=0
```

### 3.8 Migration lifecycle on the isolated InnoDB fixture (`gtf_mig_test_a`)

```bash
cd /tmp/phpwasm
node php74.mjs envrun '{"GTF_TEST_DSN":"mysql:host=127.0.0.1;port=3316;dbname=gtf_mig_test_a","GTF_TEST_DB_USER":"root","GTF_TEST_DB_PASS":""}' $R/tests/gtf_migration_fixture.php
node php74.mjs envrun '{"DB_HOST":"127.0.0.1","DB_PORT":"3316","DB_USERNAME":"root","DB_PASSWORD":"","DB_DATABASE":"gtf_mig_test_a"}' $R/scripts/migrate-gtf.php
node php74.mjs envrun '{"GTF_TEST_DSN":"mysql:host=127.0.0.1;port=3316;dbname=gtf_mig_test_a","GTF_TEST_DB_USER":"root","GTF_TEST_DB_PASS":"","GTF_MIGRATION_EXPECT":"created"}' $R/tests/gtf_migration_verify.php
node php74.mjs envrun '{"DB_HOST":"127.0.0.1","DB_PORT":"3316","DB_USERNAME":"root","DB_PASSWORD":"","DB_DATABASE":"gtf_mig_test_a"}' $R/scripts/migrate-gtf.php          # second run
node php74.mjs envrun '{"DB_HOST":"127.0.0.1","DB_PORT":"3316","DB_USERNAME":"root","DB_PASSWORD":"","DB_DATABASE":"gtf_mig_test_a"}' $R/scripts/migrate-gtf.php --rollback
node php74.mjs envrun '{"GTF_TEST_DSN":"mysql:host=127.0.0.1;port=3316;dbname=gtf_mig_test_a","GTF_TEST_DB_USER":"root","GTF_TEST_DB_PASS":"","GTF_MIGRATION_EXPECT":"rolledback"}' $R/tests/gtf_migration_verify.php
```

```
fixture ready (engine=InnoDB)
GachtheFast migration complete. New tables: gtf_card_orders, gtf_ledger, gtf_events
gtf_migration_verify (created): 15 passed, 0 failed          # engines, unique keys, legacy columns intact
GachtheFast migration complete. New tables: gtf_card_orders, gtf_ledger, gtf_events   # idempotent
Rolling back GachtheFast schema (new tables only).
  dropped gtf_card_orders / gtf_ledger / gtf_events
gtf_migration_verify (rolledback): 9 passed, 0 failed        # every legacy table left alone
```

Recorded exit codes: fixture `0`, migration `0`, second migration `0`, rollback `0`.

### 3.9 Migration refusal on MyISAM + `--convert` (`gtf_mig_test_my2`)

```
fixture ready (engine=MyISAM)
These tables are not InnoDB, so the GachtheFast credit transaction could not roll back safely:
  - users (engine: MyISAM)   … - aff_log (engine: MyISAM)
Back up the database, then re-run with: php scripts/migrate-gtf.php --convert
exit=1
gtf_migration_verify (refused): 4 passed, 0 failed            # nothing was created
converted users to InnoDB … converted aff_log to InnoDB
GachtheFast migration complete. New tables: gtf_card_orders, gtf_ledger, gtf_events
gtf_migration_verify (converted): 9 passed, 0 failed
```

### 3.10 Other migration refusals

```
# required column missing (users.token omitted from the fixture)
Migration failed: SQLSTATE[42S22]: Column not found: 1054 Unknown column 'token' in 'field list'
exit=1        → gtf_migration_verify (refused): 4 passed, 0 failed

# a database with no legacy tables at all
Migration failed: Missing required table: users. Restore the shop database first.
exit=1

# an option the script does not know
Unknown option: --force
exit=1
```

### 3.11 Pre-existing offline regressions

```bash
cd /tmp/phpwasm && node php74.mjs run $R/tests/legacy_security_test.php
cd /tmp/phpwasm && node php74.mjs run $R/tests/payos_client_test.php
```

```
Legacy security regression checks passed.
exit=0
OK: 45 payOS client checks passed (offline).
exit=0
```

### 3.12 payOS service suite — partial, then the concurrency section replicated

```bash
cd /tmp/phpwasm && export SHIM_VENDOR=1 PHP_COUNT_GLOBAL=assertions
node php74.mjs envrun '{"PAYOS_TEST_DB":"payos_test_qa","PAYOS_TEST_PORT":"3316","PAYOS_TEST_USER":"root","PAYOS_TEST_PASSWORD":"","DB_HOST":"127.0.0.1","DB_PORT":"3316","DB_DATABASE":"payos_test_qa","DB_USERNAME":"root","DB_PASSWORD":""}' $R/tests/payos_service_test.php
```

```
payOS migration complete. Existing balances and payment history preserved.   (x2 — migration is idempotent)
PHP Warning:  proc_open(): proc_open() failed - Success … on line 136
ASSERTIONS_REACHED=39
UNCAUGHT: RuntimeException: Cannot start concurrency test.
exit=255
```

The 39 assertions before the harness limit are the whole offline + database part of the suite; the file
then spawns `tests/payos_race_worker.php` twice. That section was reproduced by hand with two real
parallel processes on the order the suite had just created:

```bash
# two concurrent workers, real OS processes, two MySQL connections
( cd /tmp/phpwasm && node php74.mjs envrun "$E" $R/tests/payos_race_worker.php $OC > /tmp/worker_a.out 2>&1 ) &
( cd /tmp/phpwasm && node php74.mjs envrun "$E" $R/tests/payos_race_worker.php $OC > /tmp/worker_b.out 2>&1 ) &
wait
```

```
worker A exit=0 -> already_credited
worker B exit=0 -> credited
sorted results: already_credited credited
wallet_user6=5500.00 (expected 5500.00)
deposit_log_user6=1 (expected 1)
dongtien_user6=1 (expected 1)
order_status=PAID (expected PAID)
```

### 3.13 Legacy payment gate — 14 probes executed individually

```bash
cd /tmp/phpwasm && export SHIM_VENDOR=1
for each pair: node php74.mjs run $R/tests/legacy_payment_gate_test.php --probe <file> [action]
```

```
ok   api/callback_card.php                      HTTP_STATUS=410
ok   api/callback_crypto.php                    HTTP_STATUS=410
ok   api/callback_squadco.php                   HTTP_STATUS=410
ok   api/callback_flutterwave.php               HTTP_STATUS=410
ok   api/callback-perfectmoney.php              HTTP_STATUS=410
ok   api/callback_toyyibpay.php                 HTTP_STATUS=410
ok   api/webhook_web2m.php                      HTTP_STATUS=410
ok   cron/bank.php                              HTTP_STATUS=410
ok   cron/bankWeb2m.php                         HTTP_STATUS=410
ok   cron/momo.php                              HTTP_STATUS=410
ok   ajaxs/client/create.php nap_the            HTTP_STATUS=410
ok   ajaxs/client/create.php CreateInvoiceCrypto HTTP_STATUS=410
ok   ajaxs/client/create.php CreateToyyibpay    HTTP_STATUS=410
ok   ajaxs/client/update.php confirmPaypal      HTTP_STATUS=410
legacy probes: 14 passed, 0 failed
```

---

## 4. Defects found by this pass

| ID | Severity | Finding | Evidence | Suggested fix (not applied — needs an owner decision) |
| --- | --- | --- | --- | --- |
| DEF-1 | Low–Medium (no money impact) | The `gtf_events` uniqueness relies on `(flow, order_id, direction, event_key)`. A callback that arrives **before the order is known** stores `order_id = NULL`, and SQL treats NULL values as distinct — so the same callback replayed while the order is unknown inserts a **new row every time**. The intent of `ON DUPLICATE KEY UPDATE id = id` is therefore not met for unbound callbacks (unbounded event rows under provider retries or a hostile sender). Events never credit, so no balance is at risk. | `scripts/migrate-gtf.php` (`UNIQUE KEY gtf_event_key (flow, order_id, direction, event_key)`); discovered when the migration verifier asserted a single-column key and failed; `gtf_migration_verify (created)` now pins the actual index | Make the dedupe key independent of the order (e.g. `UNIQUE KEY (flow, event_key)`, or `order_id NOT NULL DEFAULT 0`) |
| DEF-2 | Medium (operator error → wrong money) | `GtfCardService::adminCredit($orderId, $gross, $note)` credits the **operator-typed** amount without clamping it to the order's verified declared amount (`$source === 'admin_review'` skips the equality check), and `ajaxs/admin/gtf.php` passes the posted amount straight through. A typo under-pays or over-pays the customer; the fee percentage is applied to the typed figure. | `gtf_credit_path_test.php` A9: a 100 000₫ card credited with `1000` moved exactly `1000.00` | Default the field to the stored declared amount, validate it against the order (or require an explicit break-glass reason + second admin), and record the delta |
| DEF-3 | Low (workflow / defence in depth) | `adminCredit` accepts **any** non-credited order, not only orders parked in `REVIEW` — a fresh, never-verified `CREATING`/`PENDING` order can be paid by an operator. The admin UI only lists the review queue, but the endpoint trusts the posted `order_id` (permission + CSRF still required). If the card later settles through the provider, the credit path would see a stale row and refuse (exactly-once holds), but the operator may have paid an order whose outcome was unknown. | `gtf_credit_path_test.php` A9 (second half) | Restrict the admin credit to `REVIEW`/`UNKNOWN`/`PENDING` orders, require `provider_checked_at` to be set, and log the actor (the AJAX already writes a log row) |

No **FAIL** rows were produced by this pass: every executed assertion passed. The three defects above were
found by reading the code while designing assertions (not by a failing assertion), which is why they are
recorded separately from the matrix.

## 5. Coverage gaps

| ID | Gap | Why it matters |
| --- | --- | --- |
| GAP-1 | The transport accepts a **JSON list** (`[1,2,3]`) as a well-formed response; the status normaliser then sees no status and treats the outcome as unknown (no credit). | Not a money risk today; a decision is needed whether the transport should reject non-object responses outright |
| GAP-2 | No test can validate the **real provider contract**: actual hostname, actual body format, actual `check` schema, actual callback authentication, actual status-2 settlement rule. | The entire automatic path rests on contract facts that are still unconfirmed (`08-…` §8.4, Q1–Q7) |
| GAP-3 | The **bank** flow has no contract, so there is nothing to test beyond “disabled and fail-closed”. | `libs/gtf_bank.php` refuses every operation; that is asserted, not proven useful |
| GAP-4 | The shipped `payos_service_test.php` cannot complete here (`proc_open`); its concurrency section was replicated by hand. | A CI environment with a normal PHP build must run the file as-is before release |
| GAP-5 | The shipped `legacy_payment_gate_test.php` cannot complete here (`proc_open`); its 14 probes were executed individually. | Same: re-run the file as-is in CI |
| GAP-6 | The database fixtures are **not** the production schema (production DDL was never supplied; `*.sql` is ignored). Column types, indexes and collations in the real shop database are unverified. | The migration's pre-flight is the only guard; a green fixture run cannot prove the real DDL |
| GAP-7 | The admin AJAX endpoints (`ajaxs/admin/gtf.php`) were not executed (they need the CMSNT session/permission/CSRF bootstrap). | CSRF, permission and demo-mode guards were verified by reading, not by running |
| GAP-8 | No test covers the `views/*-gtf*.php` templates (rendering, escaping, flag visibility). | A UI defect would not be caught by this pass |

## 6. Requirement-by-requirement mapping (the QA brief)

| Requirement | Status | Where |
| --- | --- | --- |
| Documented statuses `1, 2, 3, 4, 99, 100` | **PASS** | rules suite (normalisation) + service suite (submit/check paths) |
| Unknown / malformed statuses | **PASS** | rules suite (`missing_status`, `unknown_status`) + service suite (undocumented submit status → `UNKNOWN`, no credit) |
| Invalid signatures | **PASS** | service suite C5 (forged callback → refused, event recorded, nothing credited); the documented weakness (signature covers only `code`+`serial`) is asserted in the rules suite |
| Wrong / mismatched IDs | **PASS** | service suite C6 (`check_not_bound_to_order` → review, no credit) |
| Amount / value mismatches | **PASS** | service suite C6/C7 + credit-path A5 (`amount_conflict`, no credit) |
| Duplicate events | **PASS** | service suite C4 (replayed callback), credit-path A1/A2 (second credit refused), mutation self-check |
| Out-of-order events | **PASS** | service suite C12 (callback before the submit result: exactly one credit; late downgrade impossible) |
| Timeouts | **PASS** | service suite C7 (`submit`/`check` transport failure → `UNKNOWN`, nothing credited) |
| Concurrent callbacks / workers | **PASS** | service suite C13 (two connections: stale row refused; lock timeout fails cleanly, no money moved) + credit-path A7 |
| Pending/ambiguous cases never credit | **PASS** | service C2/C6/C7/C8/C12 + credit-path A11 (lock restored: provider-confirmed card parked as `credit_locked_pending_contract`) |
| Credit path exactly-once and atomic before it can be releasable | **PASS (test-only enablement)** | credit-path A1–A10 + mutation self-check. Note: in the shipped configuration this path is **unreachable** (`GTF_ALLOW_CREDITING=false`) |
| Invalid API hosts / no arbitrary URL / no GET / no redirects / no TLS weakening | **PASS** | rules suite (origin/format pinning, no `$_GET`/`$_POST` origin) + transport probe (POST only, 302 not followed, untrusted cert refused) |
| Disabled flags | **PASS** | service suite C0/C1, credit-path A0/A11 |
| Bad request formats | **PASS** | rules suite (`json`/`form` only, no default) |
| Redirects, malformed JSON, oversized responses | **PASS** | transport probe `req-302`, `req-bad`, `req-big` (+ empty body, 500, 404) |
| Secret redaction | **PASS** | service suite C14 (no raw PIN/serial in orders/ledger/events; masked serial last 3 chars) + transport probe (error text carries no body/URL) |
| Bank adapter stays disabled and fails closed | **PASS** | rules suite bank section (no HTTP call, no crediting routine, no invented contract, `isAvailable() === false`) |
| PHP 7.4 lint + tests + applicable regressions | **PASS / PARTIAL** | §2 rows 1–21; the two `proc_open`-dependent shipped files are PARTIAL (rows 17–18) |
| Each command + actual output recorded; unavailable ≠ passed | **PASS** | §3; the NOT RUN rows (22–26) are listed as NOT RUN |

## 7. Release blockers (all still open)

| ID | Blocker | Owner action |
| --- | --- | --- |
| B1 | Provider contract unconfirmed: HTTPS origin (Q1), accepted body format (Q2), whether `command=check` is authenticated/authoritative (Q3), status-2 settlement rule (Q4), overflow recovery (Q6), callback authentication (Q7) | Answer `08-…` §8.4 Q1–Q7 |
| B2 | Automatic crediting stays impossible (`GTF_ALLOW_CREDITING=false`) until B1 is answered **and** the settlement/reconciliation rules are signed off | Explicit enablement decision after B1 |
| B3 | The migration has only been exercised on an isolated fixture; the production schema (Q9) is unverified and no production run is authorised | Approve schema + a backed-up window |
| B4 | Legacy P1 findings (P1-1 unauthenticated credit, P1-2 TOCTOU, P1-3 non-unique dedupe) are undispositioned and still live while `PAYOS_ENABLED=false` | Triage/disposition |
| B5 | No independent security review and no authorised pentest (project phases 4–5) | Schedule them |
| B6 | The bank flow has no contract (Q8) and must stay disabled | Supply the contract or drop the feature |
| B7 | No cutover plan for the legacy card provider (`card24h()` → GachtheFast), including in-flight orders (Q12) | Supply the plan |
| B8 | Two shipped regression suites cannot run to completion in this sandbox (`proc_open`); their substance was replicated by hand (rows 17–18) | Re-run both files unchanged in a CI environment with a native PHP build before release |
| B9 | Composer dependencies are absent from the checkout, so entry points were exercised with a test-only autoload stub | Run `composer install` and re-run the suites in CI |

## 8. What this pass does NOT prove (explicit non-claims)

* It does **not** prove the code works against the real GachtheFast API — no provider was contacted.
* It does **not** prove the production database will accept the migration — only a fixture was used.
* It does **not** clear any P1 finding from the legacy providers, and no “backdoor-free” or
  “production-ready” claim is made.
* It does **not** enable anything: `GTF_CARD_ENABLED=false`, `GTF_ALLOW_CREDITING=false`,
  `GTF_API_BASE` empty, `GTF_CARD_REQUEST_FORMAT` empty, bank adapter fail-closed.
* It does **not** replace phases 4–5 (independent security review, authorised pentest).

## 9. Artifacts added or changed by this QA pass

| File | Role |
| --- | --- |
| `tests/gtf_credit_path_test.php` | New — the only suite that exercises the credit path (A0–A11) |
| `tests/gtf_credit_path_mutation.php` | New — proves the exactly-once assertions are load-bearing |
| `tests/gtf_migration_fixture.php` | New — isolated legacy-schema fixture (engine/column knobs) |
| `tests/gtf_migration_verify.php` | New — migration post-conditions for created / converted / refused / rolledback |
| `docs/gachthefast/09-qa-test-matrix.md` | This report |

No production code, configuration, feature flag or database was modified by this pass.

---

## 10. Release-verification re-run (2026-10-04, second session — release preparation)

Every suite below was executed **again** during release preparation, in the same isolated harness
(WASM PHP 7.4.33 + disposable MySQL 5.6.24 + loopback-only TLS endpoint + fake provider gateway). The
release packet that consumes these results is `12-staging-and-release-plan.md` §5; the security findings
are in `10-security-review.md`.

| # | Check | Result | Exit |
| --- | --- | --- | --- |
| 1 | `php -l` over every PHP file in the checkout (232 files) | **232 passed, 0 failed** | 0 |
| 2 | `tests/gtf_rules_test.php` | **124 passed, 0 failed** | 0 |
| 3 | `tests/gtf_service_test.php` (disposable DB, C0–C14) | **93 passed, 0 failed** | 0 |
| 4 | `tests/gtf_credit_path_test.php` (A0–A11) | **75 passed, 0 failed** | 0 |
| 5 | `tests/gtf_credit_path_mutation.php` | **2 detected, 0 NOT detected** | 0 |
| 6 | `tests/legacy_security_test.php` | `Legacy security regression checks passed.` | 0 |
| 7 | `tests/payos_client_test.php` | `OK: 45 payOS client checks passed (offline).` | 0 |
| 8 | `tests/gtf_transport_probe.php` (loopback TLS, positive) | **18 passed, 0 failed** (redirect not followed; oversized/malformed/empty/500/404 refused) | 0 |
| 9 | `tests/gtf_transport_probe.php` (`GTF_PROBE_EXPECT_TLS_FAILURE=1`, untrusted cert) | **3 passed, 0 failed** | 0 |
| 10 | DSN safety gate (a database whose name lacks `test`) | `REFUSED: set GTF_TEST_DSN to a disposable test database.` | (gate fires before any connection) |
| 11 | Migration lifecycle on an isolated InnoDB fixture: fixture → migrate → verify → re-run → `--rollback` → verify | created `15 passed, 0 failed`; second run idempotent (exit 0, no error); rollback dropped exactly the three `gtf_*` tables; `rolledback 9 passed, 0 failed` | 0 / 0 / 0 |
| 12 | Migration refusal on a MyISAM fixture, then post-condition check | all six tables listed as non-InnoDB, **exit 1**, nothing created; `refused 4 passed, 0 failed` | 1 |
| 13 | Migration with an unknown option / a database missing the legacy tables | `Unknown option: --force` (exit 1); `Migration failed: Missing required table: users. Restore the shop database first.` (exit 1) | 1 / 1 |
| 14 | `tests/legacy_payment_gate_test.php` — its 14 entry-point/action probes, each in its own process | **14/14 `HTTP_STATUS=410`** | 0 |
| 15 | `tests/payos_service_test.php` (disposable `payos_test_qa`) | 39 assertions pass, then `proc_open()` unsupported here → `Cannot start concurrency test.` | 255 (harness limit) |
| 16 | payOS concurrency section, replicated with two real parallel worker processes | `credited` + `already_credited`; wallet `5500.00` exactly once; `deposit_log = 1`; `dongtien = 1` | 0 / 0 |
| 17 | Security-review checks (`/tmp/review/gtf_review_checks.php`, 37 expectations incl. SEC-01..SEC-03 reproduction) | **37 confirmed, 0 failed** — see `10-…` §2–§4 | 0 |

**Harness notes added during this run (for whoever repeats it):**

* The disposable database **must** contain the substring `test` in its name, otherwise the fixtures
  refuse (this is the guard working, not a failure). Use e.g. `gtf_mig_test_rel_a`.
* When driving the legacy gate probes individually, take the endpoint list **from the test source**
  (`tests/legacy_payment_gate_test.php`), not by hand: the file is `api/callback-perfectmoney.php`
  (hyphen), and a hand-typed underscore variant fails with a `require()` fatal that looks like a
  product failure but is not.
* `payos_service_test.php` and `legacy_payment_gate_test.php` still cannot complete unmodified in this
  runtime because the WASM PHP has no working `proc_open()`; their substance was executed as above, and
  **both must be run unmodified in native CI** (prerequisite P4 in `12-…`).

**Still NOT RUN (unchanged, and not to be reported as passed):** the live provider contract, the
production schema and production migration, the bank flow (no contract), the admin AJAX endpoints with a
real admin session, and any load/soak test. No production system was contacted in any run.
