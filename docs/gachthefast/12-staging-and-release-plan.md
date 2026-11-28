# 12 — Staging-first release plan, prerequisites, rollback and verdict

> Historical release assessment. SEC-01 and parts of SEC-02/03 were subsequently changed and retested
> with fixtures; see `13-security-hardening-retest.md`. The **NO-GO** remains: provider contract,
> callback retry semantics, actual hosting/schema, independent review and release approval are unresolved.

**Verdict: NO-GO.** The GachtheFast integration must **not** be enabled in production. Six items are
**BLOCKED** on owner input (below), one P1 security defect is open (SEC-01), and the provider contract
is unconfirmed. Nothing was deployed, no production database or provider was contacted, and no
production-readiness claim is made. New integrations stay disabled until the provider contract, schema,
tests, security review and owner approvals are complete (owner instruction, restated verbatim in §2).

This document is the release *packet*: prerequisites, the staging programme, the rollback contract, the
unresolved-risk register and the exact commands behind the evidence. Companion documents:
`09-qa-test-matrix.md` (executed tests), `10-security-review.md` (findings), `11-operator-runbook.md`
(daily operations).

---

## 1. Where the release stands

| Gate | State | Evidence |
| --- | --- | --- |
| Code complete for the contract-confirmed parts | ✅ done (uncommitted working tree) | `git status`; `07-implementation-notes.md` §11 |
| PHP 7.4 syntax across the whole checkout | ✅ **232/232 pass** | §5.1 |
| Behavioural tests (card rules, state machine, credit path) | ✅ green, isolated & deterministic | §5.1 (`124/0`, `93/0`, `75/0`, mutation `2/2`) |
| Migration script, refusals and rollback on an isolated fixture | ✅ green | §5.1 |
| Legacy regression (410 guard, security, payOS client) | ✅ green (14/14 probes, 45 checks) | §5.1 |
| payOS database suite | ⚠️ partial — 39 assertions pass, then the shipped file needs `proc_open` (unavailable in the review runtime); its concurrency section was replicated with two real processes and passed | §5.1 |
| Independent security review | ⚠️ **open findings** — 1×P1 (SEC-01), 3×P2 (SEC-02/03/04), 4×P3 | `10-security-review.md` |
| Provider contract (host, body format, `check` authority, status-2 rule) | ❌ **unanswered** | `08-…` §8.4 Q1–Q7 |
| Schema approval + production migration approval | ❌ **not approved** | `12-…` P5 |
| Owner sign-off for enablement/cutover | ❌ **not given** | §4 |
| Staging environment exercised end-to-end (real provider sandbox) | ❌ **not available** | §3 |
| Independent pentest (project phase 5) | ❌ not started | — |

---

## 2. Why this is NO-GO (and what would change it)

The owner's own rule is that new integrations stay disabled until the **provider contract, schema,
tests, security review and owner approvals** are all complete. Four of those five are still open:

1. **Provider contract** — the origin, the accepted POST body format, whether `command=check` is
   authentic and authoritative, and the status-2 settlement rule are all unconfirmed. The code refuses
   to call anything without an explicitly configured origin **and** format, which is why nothing can be
   enabled by mistake.
2. **Schema** — the DDL has only ever been exercised on an isolated fixture; production is unapproved
   and unverified (`*.sql` is git-ignored and was never supplied).
3. **Security review** — complete as a read-only pass, with an open **P1** on the operator credit path
   plus three P2s. P1 blocks release by the owner's rule.
4. **Owner approvals** — no written go for the production migration, for enabling card submissions, for
   opening crediting, or for the legacy cutover.
5. **Tests** — the code-level tests are green, but they cannot substitute for a staging run against the
   real provider sandbox, which is gated on item 1.

**BLOCKED items (owner action required):**

| # | Blocked on | Needed for |
| --- | --- | --- |
| B1 | Contract answers Q1–Q7 (`08-…` §8.4) | configuring `GTF_API_BASE`/`GTF_CARD_REQUEST_FORMAT`; any provider call |
| B2 | Disposition of the legacy P1s (P1-1 unauthenticated credit, P1-2 TOCTOU, P1-3 bank dedupe) | overall release safety while `PAYOS_ENABLED=false` |
| B3 | Written approval for the production migration (backed-up window) | S4 |
| B4 | Settlement/fee rule + who bears rejected cards | crediting amounts |
| B5 | Legacy card provider cutover plan (stop new legacy submissions, handle in-flight orders) | traffic switch |
| B6 | A staging environment with a provider sandbox account | S2/S3 |

---

## 3. Staging-first programme

Nothing reaches production until S0–S3 exit successfully on staging **and** the §4 approvals exist.
Staging means: a copy of the shop database, a copy of the code, real HTTPS, and the provider's
**sandbox/test** credentials (never production cards, never customers' data).

| Phase | Entry criteria | Work | Exit criteria (all must hold) | Abort/rollback |
| --- | --- | --- | --- | --- |
| **S0 — freeze & baseline** | code frozen at the reviewed revision | record `sha1sum` of every GTF file; take a staging DB backup; confirm `GTF_*` flags off | checksums recorded; backup restorable (test the restore) | n/a |
| **S1 — schema on staging** | S0 | `php scripts/migrate-gtf.php` on staging; verify tables/engines/keys; re-run twice (idempotency); run `--rollback` **on a scratch copy** to prove the rollback works | three `gtf_*` tables exist with InnoDB + unique keys; legacy tables byte-identical (row counts + checksums before/after); rollback drill documented | `--rollback` on staging (allowed: no real orders) |
| **S2 — submissions on staging, crediting locked** | S1; contract answers B1 | set `GTF_CARD_ENABLED=true` + provider sandbox credentials; register the sandbox callback URL; submit provider sandbox cards covering statuses `1,2,3,4,99,100` and an induced timeout | for each status: the order reaches the documented state; **nothing is credited**; callback events recorded; worker counters move; sealed data wipes on terminal states | `GTF_CARD_ENABLED=false` |
| **S3 — crediting on staging** | S2; **SEC-01 fixed and retested**; B4 | set `GTF_ALLOW_CREDITING=true` on staging; replay the status-1 card; run the race drill (two workers, one order) | exactly one credit, one `gtf_ledger` row, one `deposit_log` row, order `PAID`; duplicate attempt refused; wallet arithmetic matches the settlement rule | `GTF_ALLOW_CREDITING=false` (credits already made are **not** reversed automatically — the ledger stays) |
| **S4 — production schema** | S3; **B3 approval**; staging soak report | repeat S1 on production in a backed-up window, with operators on standby | as S1, on production | `--rollback` **only if** the tables have never held a real order (§5.4) |
| **S5 — production submissions, crediting locked** | S4; callback URL registered with the provider; monitoring live | `GTF_CARD_ENABLED=true`; first real low-value card; verify the full path by hand | order recorded, reconciled, parked in `REVIEW`; sealed data intact for the pending order; no money moved | `GTF_CARD_ENABLED=false`; callbacks and worker continue |
| **S6 — production crediting** | S5 stable for the agreed soak; SEC-02/SEC-03 fixed or explicitly accepted in writing; **owner's written go** | open the lock; watch §11-runbook monitoring for 24 h | first automatic credit verified against the provider report; no anomalies in the §7 queries | close the lock; resolve every open order through the review queue |

**Soak criteria (suggested):** S5 ≥ 3 days with ≥ 20 real cards and zero unexplained `UNKNOWN` orders;
staging S3 shows zero double-credit canary hits; the provider settlement report matches `gtf_ledger` to
the cent for two consecutive days.

---

## 4. Deployment prerequisites (exact, in order)

Every box must be ticked and signed; nothing here may be inferred or waived by engineering.

| # | Prerequisite | Owner of the item | Sign-off evidence |
| --- | --- | --- | --- |
| P1 | Provider **contract answers** Q1–Q7 recorded in `06-owner-inputs-and-gate1.md` (origin, body format, `check` authority, status-2 rule, overflow behaviour, callback authentication) | Owner (via GachtheFast) | written answers attached to the repo docs |
| P2 | **SEC-01 fixed** (operator credit bound to the verified amount + state precondition) **and retested** | Engineering | updated `10-security-review.md` retest row; `gtf_credit_path_test` A9 rewritten to assert the fixed behaviour |
| P3 | SEC-02/SEC-03 fixed or explicitly accepted in writing before enabling submissions (they are reachable while disabled) | Owner + engineering | signed acceptance or the fix + retest |
| P4 | `composer install` in the target environment and a **native PHP 7.4 CI run** of all suites, including the two files this sandbox could not complete (`payos_service_test.php`, `legacy_payment_gate_test.php`) | Engineering | CI log; both files must exit 0 unmodified |
| P5 | **Written approval** for the production migration + a verified, restorable backup | Owner | approval note + restore drill log |
| P6 | `SECRET_KEY` present and stable; no order holds sealed data at rotation time | Engineering | config review (see runbook §6) |
| P7 | Provider callback URL registered: `{APP_URL}/api/callback-gtf-card.php`, and the provider told that callbacks may be `POST` | Owner (via provider) | provider confirmation |
| P8 | Monitoring wired: worker cron every 2–5 min, alerts routed to a human, the §7 queries scheduled | Engineering | cron entry + alert test |
| P9 | Secrets placed only in the deployment `.env` (all blanks in `.env.example` confirmed) | Engineering | config review |
| P10 | Legal/commercial: fee percentage, settlement rule, customer-facing wording, refund policy for rejected cards | Owner | written decision |
| P11 | Cutover plan for the legacy card provider (`card24h()`): when new legacy submissions stop, how in-flight orders are reconciled, who watches the queue | Owner + engineering | approved plan (owner's original Q12) |
| P12 | Written go for **each** stage (S4, S5, S6) | Owner | dated approval per stage |

---

## 5. Verification evidence (this release packet)

### 5.1 Freshly executed runs (2026-10-04, second session)

Runtime: real **PHP 7.4.33** CLI (WASM build; `curl 7.69.1 / OpenSSL 1.1.1t`), disposable **MySQL
5.6.24**, loopback-only TLS endpoint, fake provider gateway. No production system was involved.
Harness scripts live outside the repository (`/tmp/phpwasm`, `/tmp/gtfprobe`, `/tmp/review`).

| # | Command (abridged; harness details in `09-qa-test-matrix.md` §1) | Actual output |
| --- | --- | --- |
| 1 | `php -l` over every PHP file in the checkout (232 files) | `php -l summary: 232 passed, 0 failed (PHP 7.4.33)` |
| 2 | `php tests/gtf_rules_test.php` | `gtf_rules_test: 124 passed, 0 failed` |
| 3 | `GTF_TEST_DSN=…dbname=gtf_test php tests/gtf_service_test.php` | `gtf_service_test: 93 passed, 0 failed` |
| 4 | `GTF_TEST_DSN=…dbname=gtf_test php tests/gtf_credit_path_test.php` | `gtf_credit_path_test: 75 passed, 0 failed` |
| 5 | `GTF_TEST_DSN=…dbname=gtf_mut_test php tests/gtf_credit_path_mutation.php` | `2 detected, 0 NOT detected` (with the unique key the duplicate credit is rejected; without it, accepted — the guard is load-bearing) |
| 6 | `php tests/legacy_security_test.php` | `Legacy security regression checks passed.` |
| 7 | `php tests/payos_client_test.php` | `OK: 45 payOS client checks passed (offline).` |
| 8 | `GTF_PROBE_BASE=https://127.0.0.1 php tests/gtf_transport_probe.php` | `gtf_transport_probe: 18 passed, 0 failed` (incl. *a redirect is NOT followed*, oversized/malformed/empty refused, 500/404 refused) |
| 9 | same, `GTF_PROBE_EXPECT_TLS_FAILURE=1` with an untrusted certificate | `gtf_transport_probe (tls-negative): 3 passed, 0 failed` |
| 10 | safety gate: a DSN without the substring `test` | `REFUSED: set GTF_TEST_DSN to a disposable test database.` |
| 11 | migration lifecycle on the isolated fixture: fixture → `migrate-gtf.php` → verify → re-run → `--rollback` → verify | `gtf_migration_verify (created): 15 passed, 0 failed`; second run exit 0 (idempotent); `--rollback` dropped exactly 3 tables (exit 0); `gtf_migration_verify (rolledback): 9 passed, 0 failed` |
| 12 | `migrate-gtf.php` on a MyISAM schema, then `gtf_migration_verify … refused` | refusal lists all 6 tables, **exit 1**, nothing created; `verify (refused): 4 passed, 0 failed` |
| 13 | `migrate-gtf.php` with an unknown option / on a database without the legacy tables | `Unknown option: --force` exit 1; `Migration failed: Missing required table: users.` exit 1 |
| 14 | `tests/legacy_payment_gate_test.php` — its 14 entry-point/action probes, executed individually (the shipped file needs `proc_open`) | **14/14 `HTTP_STATUS=410`** (list driven from the test source, so no hand-typed paths) |
| 15 | `PAYOS_TEST_DB=payos_test_qa php tests/payos_service_test.php` | 39 assertions pass, then `proc_open()` unsupported in this runtime → `exit 255` (**harness limit, not a product failure**) |
| 16 | payOS concurrency section replicated with two real parallel worker processes | `credited` + `already_credited`; wallet `5500.00` exactly once; `deposit_log=1`; `dongtien=1` |
| 17 | security-review checks (fixture `gtf_review_test`, fake gateway) | `sec_review_checks: 37 expectations confirmed, 0 failed` |

### 5.2 Same evidence in a normal environment (for CI/staging)

The WASM harness exists only because this sandbox has no native PHP/MySQL. On a normal host the same
commands run with a stock PHP 7.4 CLI:

```bash
finder() { find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l > /tmp/lint.txt; tail -1 /tmp/lint.txt; }
php tests/gtf_rules_test.php
GTF_TEST_DSN='mysql:host=127.0.0.1;dbname=gtf_test' GTF_TEST_DB_USER=… GTF_TEST_DB_PASS=… php tests/gtf_service_test.php
GTF_TEST_DSN='mysql:host=127.0.0.1;dbname=gtf_test' GTF_TEST_DB_USER=… GTF_TEST_DB_PASS=… php tests/gtf_credit_path_test.php
GTF_TEST_DSN='mysql:host=127.0.0.1;dbname=gtf_mut_test' GTF_TEST_DB_USER=… GTF_TEST_DB_PASS=… php tests/gtf_credit_path_mutation.php
GTF_PROBE_BASE=https://127.0.0.1 GTF_PROBE_CA=/path/chain.pem php tests/gtf_transport_probe.php
php tests/legacy_payment_gate_test.php            # needs a native build (proc_open)
PAYOS_TEST_DB=payos_test_qa php tests/payos_service_test.php   # needs a native build (proc_open)
php tests/legacy_security_test.php && php tests/payos_client_test.php
```

**P4 requires items 14 and 15 to exit 0 unmodified in CI** — they could not be completed here, and this
packet does not pretend otherwise.

### 5.3 What the evidence does not cover

No provider sandbox run, no production schema, no production data, no admin browser session, no
independent pentest, and no load/soak test. Those are staging gates (S2–S3) and phase 4–5 items.

---

## 6. Post-migration verification checklist (run on staging and production)

```sql
-- 1. the three tables exist, are InnoDB, and carry their keys
SHOW CREATE TABLE gtf_card_orders\G   -- UNIQUE KEY gtf_card_request (request_id)
SHOW CREATE TABLE gtf_ledger\G        -- transid VARCHAR(96) NOT NULL PRIMARY KEY  (idempotency guard)
SHOW CREATE TABLE gtf_events\G        -- UNIQUE KEY gtf_event_key (flow, order_id, direction, event_key)

-- 2. legacy tables untouched (row counts must match the pre-migration snapshot)
SELECT 'users', COUNT(*) FROM users UNION ALL SELECT 'dongtien', COUNT(*) FROM dongtien
UNION ALL SELECT 'deposit_log', COUNT(*) FROM deposit_log UNION ALL SELECT 'payment_bank', COUNT(*) FROM payment_bank
UNION ALL SELECT 'aff_log', COUNT(*) FROM aff_log UNION ALL SELECT 'settings', COUNT(*) FROM settings;

-- 3. no GTF pricing/wallet side effects before enablement
SELECT COUNT(*) FROM gtf_card_orders;   -- expected 0 before S5
SELECT COUNT(*) FROM gtf_ledger;        -- must be 0 until crediting is enabled
```

Then: load the admin page (it must render without the "migration not run" banner), load the client card
page (read-only, no link in the nav), and confirm the callback URL answers `503 not_configured` while no
partner key is set (it must never 500).

---

## 7. Unresolved-risk register (carry into staging)

| # | Risk | Severity | Blocking | Where |
| --- | --- | --- | --- | --- |
| R1 | Operator credit is unbound (amount + state) — SEC-01 | **P1** | enabling **crediting** | `10-…` SEC-01 |
| R2 | Unauthenticated write amplification into `gtf_events`; NULL-bound events defeat the unique key — SEC-02 | P2 | enabling submissions | `10-…` SEC-02 |
| R3 | Callback replay unbound; `force=true` bypasses the reconcile throttle — SEC-03 | P2 | enabling submissions | `10-…` SEC-03 |
| R4 | Credit correctness depends on unverified contract properties (`check` authority, optional currency) — SEC-04 | P2 | enabling crediting | `10-…` SEC-04 |
| R5 | Silenced audit-event failures; `card_reconcile` writes no `logs` row; cron token over `?token=` — SEC-05/06/07 | P3 | none (fix during staging) | `10-…` |
| R6 | Legacy P1s still live while `PAYOS_ENABLED=false` (unchanged by this work) | P1-legacy | owner disposition | `09-…` §4, `04-…` |
| R7 | Production schema unverified; fixture ≠ production | High | S4 | `09-…` GAP-6 |
| R8 | Two shipped test files cannot complete in this runtime (`proc_open`); CI must run them unmodified | Medium | P4 | `09-…` §2 rows 17–18 |
| R9 | `vendor/` absent in the checkout (test-only stub used here); entry points need `composer install` | Medium | P4 | `09-…` §1 |
| R10 | Card data on orders stuck in review is not shredded automatically (alert after 30 days only) | Medium | operational | `11-…` §6 |
| R11 | Bank flow has no contract; stays fail-closed | — | scope decision | `08-…` Q8 |
| R12 | Licence-gate integrity observation (`checkLicenseKey()` is a stub in this checkout) | Informational | owner's awareness | `10-…` SEC-10 |

---

## 8. Rollback contract (must hold for every stage)

1. **Stop new submissions, keep everything else running:**
   `GTF_CARD_ENABLED=false` (and leave `GTF_ALLOW_CREDITING=false`).
   Result: customers cannot submit; **callbacks still arrive and are recorded** (the endpoint is
   deliberately not gated by the flag); the worker still reconciles in-flight orders to a terminal state;
   no record is deleted.
2. **Drain:** run `php cron/gtf-card.php` (or wait for the schedule) and work the review queue
   (`11-…` §5.2) until no order is `CREATING`/`PENDING`/`VERIFYING`/`UNKNOWN`.
3. **Verify no money is in flight:** `SELECT COUNT(*) FROM gtf_ledger;` and compare the day's credits
   with the provider's report. Reconcile before retiring anything.
4. **Schema removal — only if the tables have never held a real order.** Otherwise archive first:
   `mysqldump shop_db gtf_card_orders gtf_ledger gtf_events > /backup/gtf_archive-$(date +%F).sql`,
   then remove with owner sign-off. **Never** run `--rollback` on tables holding real orders: it drops
   them, and those rows are payment records.
5. **Code rollback:** revert the additive GTF files (and the three modified files) — the `gtf_*` tables
   may stay; legacy/payOS paths are untouched either way.
6. **Never:** delete or rewrite legacy payment rows; drop `gtf_*` while it holds orders/credits; leave
   the callback endpoint unreachable while the provider may still retry; stop the worker while orders
   are open.

A staging rehearsal of steps 1–3 (and a scratch-copy rehearsal of step 4) is a **required exit criterion
of S1**.

---

## 9. Verdict and next actions

**GO / NO-GO / BLOCKED → `NO-GO`** for enabling the integration, and `BLOCKED` on the six owner items in
§2 (B1–B6) plus SEC-01/P2 fixes for the money stages. No production deployment was performed; no
production-readiness claim is made.

Ordered next actions:

1. Owner answers Q1–Q7 (B1) and dispositions the legacy P1s (B2).
2. Engineering fixes **SEC-01** and retests (P2), then SEC-02/SEC-03 (P3).
3. Engineering provisions a staging environment with a provider sandbox account (B6) and runs S0–S3.
4. Owner approves the production migration window (B3/P5) and, separately, each enablement stage
   (S4/S5/S6).
5. Schedule the independent security review and the authorised pentest (project phases 4–5) before S6.

Until then: feature flags off, no migration on production, no provider traffic, lead report stays
**NO-GO**.
