# 11 — Operator & developer runbook (GachtheFast card flow)

**Status: nothing is enabled.** The integration ships `GTF_CARD_ENABLED=false` and
`GTF_ALLOW_CREDITING=false`; the bank flow is a fail-closed stub. This runbook documents how to
configure, operate, monitor, reconcile and roll back the flow **after** the release gates in
`12-staging-and-release-plan.md` are satisfied. It is written for the shop operator (daily work) and
for the developer (configuration, migrations, failure handling).

Reading order: `09-qa-test-matrix.md` (what was executed), `10-security-review.md` (findings, incl. the
open P1 SEC-01), `12-staging-and-release-plan.md` (gates, prerequisites, rollback, verdict).

---

## 1. Current state of the integration (as it ships)

| Surface | State | Flag / file |
| --- | --- | --- |
| Client card submission page | **Off** (link hidden, endpoint refuses) | `GTF_CARD_ENABLED=false` |
| Provider callback endpoint | **Reachable but harmless** — records an event, never credits; returns `503 not_configured` while no partner key is set | `api/callback-gtf-card.php` |
| Reconciliation worker | **Inert** — refuses unless the channel is configured | `cron/gtf-card.php` |
| Automatic crediting | **Locked** — a provider-confirmed card is parked as `REVIEW / credit_locked_pending_contract` | `GTF_ALLOW_CREDITING=false` |
| Operator credit (admin UI "Cộng tiền") | **Refuses** while locked (throws a domain error) — this is by design, not a bug | `ajaxs/admin/gtf.php` → `GtfCardService::credit()` |
| Bank transfer / top-up | **Not implemented** — no contract; every operation throws, worker is a no-op | `libs/gtf_bank.php`, `cron/gtf-bank.php` |
| Database tables | **Not created** until the migration is run (the views show "migration not run" instead of failing) | `scripts/migrate-gtf.php` |
| Legacy card path (`card24h()`), TheSieuRe, payOS | **Unchanged** by this work | — |

**Operator expectation to set now:** while the lock is closed, *no card can be paid through GachtheFast*.
Every eligible card lands in the review queue with the reason `credit_locked_pending_contract`. Do not
tell customers the channel is live, and do not leave cards accumulating in review if the channel is not
going to be enabled (see §7).

---

## 2. Configuration reference (all placeholders blank in `.env.example`)

Nothing below needs a code change — everything is environment configuration. Values marked **⬚ blank**
have **no default and no fallback**; the code fails closed (refuses) while they are empty.

| Variable | Ships as | Meaning / rule |
| --- | --- | --- |
| `GTF_CARD_ENABLED` | `false` | Master switch for *customer submissions*. Off ⇒ the nav link is hidden, the page is read-only, `create()` refuses. |
| `GTF_ALLOW_CREDITING` | `false` | Money switch. Off ⇒ no wallet can be credited by this integration; eligible cards park in review. **Enabling this requires the owner's written approval plus SEC-01 fixed.** |
| `GTF_API_BASE` | ⬚ **blank** | Provider HTTPS origin, e.g. `https://<confirmed-host>` with **no path, query, credentials or non-443 port** (enforced at startup). Must be the origin from the provider's written confirmation — the public page only shows `http://{{domain_post}}`. |
| `GTF_CARD_REQUEST_FORMAT` | ⬚ **blank** | `json` or `form` only. The provider page contradicts itself (JSON content-type next to a form-data example), so it must be confirmed, not guessed. |
| `GTF_PARTNER_ID` | ⬚ **blank** | Issued by GachtheFast. Never commit. |
| `GTF_PARTNER_KEY` | ⬚ **blank** | Signing key (`md5(partner_key + code + serial)`). Never commit, never paste into tickets or chat. Rotating it invalidates callback signatures until the provider is updated. |
| `GTF_CARD_MIN_AMOUNT` / `GTF_CARD_MAX_AMOUNT` | `10000` / `2000000` | Accepted denominations (a card amount must also be one of the provider denominations). |
| `GTF_CARD_FEE_PERCENT` | `0` | Shop fee kept before crediting; `net = gross × (1 − fee/100)`, rounded to 2 dp. Must be signed off with the settlement rule. |
| `GTF_TIMEOUT_SECONDS` | `10` | cURL connect/read timeout, clamped 3–30 s. |
| `GTF_CRON_TOKEN` | ⬚ **blank** | Blank ⇒ workers are **CLI-only** (any HTTP call gets `403 forbidden`). If set, send it as the `X-GTF-Cron-Token` header (see SEC-07: avoid the `?token=` query form). |
| `SECRET_KEY` | ⬚ **blank** | Existing application secret, reused to derive the AES-256-GCM key that seals card PIN/serial. **Required before any card can be stored** — without it `create()` refuses. **Rotation caveat in §6.** |
| `GTF_APP_URL` (falls back to `APP_URL`) | ⬚ blank | Used to display/document the callback URL: `{APP_URL}/api/callback-gtf-card.php`. |

`GTF_ALLOW_CREDITING` and `GTF_CARD_ENABLED` are independent on purpose: you can open the callback and
worker long before money may move. Enabling cards while crediting is locked is a **valid staging step** —
cards are recorded and queued, and nothing is paid until a human acts after the lock is opened.

---

## 3. Enablement ladder (each stage is reversible; do them in order)

| Stage | What changes | Preconditions | Verification | Exit criteria |
| --- | --- | --- | --- | --- |
| **S0 — shipped** | nothing | — | `php cron/gtf-card.php` → `gtf card worker: channel not configured`; the client nav has no card link; the admin page shows the channel as OFF | current state |
| **S1 — staging: DB schema** | run `scripts/migrate-gtf.php` on a **staging** copy | backup + a MyISAM/InnoDB check the script performs itself | `gtf_migration_verify` style check: three tables, InnoDB, unique keys on `gtf_card_orders.request_id` and `gtf_ledger.transid` | tables exist, legacy tables untouched |
| **S2 — staging: card submission, crediting locked** | `GTF_CARD_ENABLED=true`, `GTF_API_BASE`, `GTF_CARD_REQUEST_FORMAT`, `GTF_PARTNER_ID`, `GTF_PARTNER_KEY`, `SECRET_KEY`; `GTF_CRON_TOKEN` optional | provider contract confirmed (Q1–Q3); staging only | submit 1 real low-value card → order becomes `PENDING`/`REVIEW`; run `php cron/gtf-card.php` → counters; confirm no money moved | callback arrives; order reconciles to `REVIEW / credit_locked_pending_contract`; nothing credited |
| **S3 — staging: crediting opened** | `GTF_ALLOW_CREDITING=true` **only after SEC-01 is fixed** | SEC-01 fix + retest; settlement/fee rules signed off | §5 reconciliation drill on the staging card: exactly one credit, one ledger row, order `PAID`, sealed data wiped | staging wallet credited exactly once |
| **S4 — production: schema** | migration on the production copy, in a backed-up window | owner approval for the production migration | §4 procedure | tables exist; no legacy table altered |
| **S5 — production: submissions, crediting still locked** | `GTF_CARD_ENABLED=true` (production credentials) | S4 complete; callback URL registered with the provider; monitoring in place | first real card: event recorded, worker reconciles, order parked in review | operators can see and resolve orders |
| **S6 — production: crediting** | `GTF_ALLOW_CREDITING=true` | S5 stable for the agreed soak period; SEC-02/SEC-03 fixed or explicitly accepted; owner's written go | monitor §5 queries for 24 h | first automatic credit verified end-to-end |

Rollback for any stage: §8. Stage S3/S6 (money) must be accompanied by the review-queue procedure (§5)
so that orders already in flight are not stranded.

---

## 4. Migration and rollback (database)

**Approval status: the production migration is NOT approved.** Gate 1 approved the *script* and its
rollback; production execution still needs the owner's written go for a backed-up window
(`12-…` prerequisite P5).

The script is CLI-only (`scripts/migrate-gtf.php:21-24` — HTTP access returns 404, and `.htaccess`
blocks `scripts/` anyway).

### 4.1 Pre-flight (the script does all of this itself)

* refuses if any required legacy table is missing (`users, settings, dongtien, deposit_log,
  payment_bank, aff_log`);
* refuses if any of those tables is not **InnoDB** (a credit transaction could not roll back safely on
  MyISAM) and prints the table list + the `--convert` hint;
* verifies every required column exists (`SELECT … LIMIT 0`), so a schema surprise aborts before DDL;
* only creates the three new `gtf_*` tables; it never alters or drops legacy tables (except the
  optional explicit `--convert`).

### 4.2 Exact commands

```bash
# 0. BACK UP FIRST (script refuses nothing about backups — this is on the operator)
#    e.g.: mysqldump --single-transaction --routines --triggers shop_db > /backup/shop_db-$(date +%F-%H%M).sql

# 1. pre-flight + create (InnoDB databases only)
php scripts/migrate-gtf.php                 # exit 0 = created; exit 1 = refused (read the message)

# 2. if it refuses because of a MyISAM table, BACK UP, then either convert or stop:
php scripts/migrate-gtf.php --convert       # ALTER TABLE … ENGINE=InnoDB for the listed tables only

# 3. verify (three tables, InnoDB, unique keys, legacy columns intact) — see 12-… §6 for the SQL
mysql -e "SHOW CREATE TABLE gtf_card_orders\G" shop_db
mysql -e "SHOW CREATE TABLE gtf_ledger\G"      shop_db    # transid must be PRIMARY KEY
mysql -e "SHOW CREATE TABLE gtf_events\G"      shop_db

# 4. rollback (SCHEMA ONLY — read §8.2 before ever using this in production)
php scripts/migrate-gtf.php --rollback      # drops ONLY gtf_card_orders, gtf_ledger, gtf_events
```

Observed behaviour on the isolated fixture (2026-10-04): happy path exit 0; second run exit 0
(idempotent); `--rollback` exit 0 and only the three `gtf_*` tables dropped; MyISAM refusal exit 1 with
nothing created; unknown option exit 1; missing table exit 1. Full transcript: `09-qa-test-matrix.md`.

### 4.3 Notes

* `dongtien.transid` having **no** unique key is expected and reported as a note, not an error —
  idempotency is enforced by `gtf_ledger.transid` (PRIMARY KEY), precisely so that legacy history is not
  modified.
* Do **not** add constraints to legacy tables as part of this migration.
* The script is safe to re-run (`CREATE TABLE IF NOT EXISTS`).

---

## 5. Daily operations: reconciliation, review queue, escalations

### 5.1 Scheduled reconciliation worker

```bash
# every 2–5 minutes (cron/systemd timer), as the web user, from the shop root:
php /path/to/shop/cron/gtf-card.php
# output:  gtf card worker: credited=N pending=N review=N failed=N
```

* It only ever calls `command=check`; it never re-submits a card (an ambiguous timeout must be
  reconciled, not retried).
* Per order it respects a 15-second claim, so overlapping runs are safe.
* If `GTF_CRON_TOKEN` is set you *may* call it over HTTP with the `X-GTF-Cron-Token` header; **do not**
  use the `?token=` form and prefer CLI (SEC-07).
* `failed=N` means the provider lookup errored; the order stays open and will be retried next run.

### 5.2 Review queue (human work)

```sql
-- the queue the admin screen shows
SELECT id, request_id, user_id, telco, declared_amount, status, reason, serial_masked, created_at
  FROM gtf_card_orders
 WHERE credited_at IS NULL AND status = 'REVIEW'
 ORDER BY id;
```

For each order, read `reason` and act:

| `reason` | Meaning | Correct action |
| --- | --- | --- |
| `credit_locked_pending_contract` | Card was confirmed by the provider, but crediting is locked | **Do not credit.** If the channel is not being enabled, escalate to the owner (see §7) |
| `status_2_value_differs` | Provider says the card is valid but a **different denomination** | Follow the owner's written settlement rule. This is always manual; never guess the amount |
| `amount_conflict` / `non_positive_value` / `missing_or_malformed:*` | Provider figures disagree with our order | Re-run the provider lookup (admin "Đối soát"); if it still disagrees, open a provider ticket and leave the order in review |
| `check_not_bound_to_order` | Provider reply did not identify our order | Treat as **UNKNOWN**; escalate — this should never happen with a correctly configured channel |
| `unexpected_currency` | Reply was not VND | Escalate to the provider |
| `cannot_open_card_data` / `card_data_unavailable` | Stored card data could not be decrypted (see §6 rotation caveat) | Verify `SECRET_KEY`; if it was rotated, the card must be handled manually |
| `submit_transport_unknown`, `check_unavailable` (status `UNKNOWN`) | Ambiguous outcome — the card **may** already be accepted | Never mark failed. Re-run reconciliation; escalate if it stays UNKNOWN beyond the agreed window |

**Recording rule:** the admin actions (`card_credit`, `card_resolve`, `card_reconcile`) currently write a
shop `logs` row only for the first two (SEC-06). Until that is fixed, every reconciliation action must
also be recorded in the operating log/ticket with: order id, `request_id`, action, amount, actor, reason.

### 5.3 Manual actions (admin screen: *Nạp tiền → GachtheFast*)

| Button | Effect | Caveat |
| --- | --- | --- |
| **Đối soát** | Re-queries the provider for that order and applies the outcome | Writes no `logs` row (SEC-06) — record it manually |
| **Cộng tiền** | Operator credit for a review order | Refuses while crediting is locked. **After unlock: SEC-01 means the typed amount is what moves** — do not enable crediting until it is fixed; meanwhile always type the order's declared amount |
| **Đánh dấu lỗi** | Terminal `REJECTED`, wipes sealed data | Only when the card is genuinely invalid/settled outside the system |

### 5.4 What "ambiguous" means (and why nothing auto-fails)

Any transport error (timeout, TLS failure, 5xx) leaves the order open as `UNKNOWN`. This is deliberate: a
card must never be marked failed while the provider may still have accepted it. Those orders are visible
in the review queue and retried by the worker. A card is only terminal when the provider says so in an
authoritative `check` reply (`3` invalid, `4` maintenance, `100` submission failed).

---

## 6. Secrets and card-data handling

* **Never** place keys in source, tickets, logs or chat. All GTF secrets live only in the deployment
  `.env`; `.env.example` keeps them blank.
* **`SECRET_KEY` seals card data.** Changing it without care makes existing sealed PIN/serial values
  unreadable (`cannot_open_card_data` → order parked in review, no credit). **Rotation rule:** rotate
  only when no order holds sealed data — check
  `SELECT COUNT(*) FROM gtf_card_orders WHERE serial_sealed IS NOT NULL OR code_sealed IS NOT NULL;`
  and drain the queue first. If a rotation is unavoidable, export the affected orders and handle them
  manually; there is no re-seal script in this release.
* Sealed data is wiped automatically when an order reaches a terminal state (`REJECTED`, `MAINTENANCE`,
  `CANCELLED`) or is credited (`PAID`). Data on orders stuck in review is **not** wiped by any
  automatic job — the worker only *alerts* once such an order is older than 30 days
  (`cron/gtf-card.php`). If cards are not going to be processed, resolve or cancel those orders so the
  data is shredded (§7).
* Serial masking (last 3 characters) is what appears in the UI, order rows and logs.
* Rotate `GTF_PARTNER_KEY` only in coordination with the provider — callback signatures are computed
  with it.

---

## 7. Monitoring and alerting

### 7.1 Alerts raised by the code

`gtf_alert()` sends to the admin channel (`sendMessAdmin`) when available, otherwise to the PHP error
log with the prefix `GachtheFast alert:`. It fires on:

* submit outcome unknown (transport failure during submission);
* an undocumented provider status on submit;
* an order entering manual review for a **new** reason (first time only — it will not spam);
* stale sealed card data (> 30 days, once per worker run).

Additionally the PHP error log gets `GachtheFast: …` lines for callback/worker/client failures (class
names and order/request ids only — never card data or keys).

### 7.2 Monitoring queries (run on a schedule, alert on thresholds)

```sql
-- a) open orders by state — alert if REVIEW or UNKNOWN keeps growing
SELECT status, COUNT(*) AS n FROM gtf_card_orders WHERE credited_at IS NULL GROUP BY status;

-- b) stuck orders (no progress for >10 minutes while a worker runs every 2–5 min)
SELECT id, request_id, user_id, status, reason, attempt_count, updated_at
  FROM gtf_card_orders
 WHERE credited_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
 ORDER BY id;

-- c) ambiguous outcomes (should trend to zero)
SELECT COUNT(*) FROM gtf_card_orders WHERE status = 'UNKNOWN';

-- d) credits applied today (reconcile against the provider's settlement report)
SELECT COUNT(*) AS n, COALESCE(SUM(credited),0) AS total
  FROM gtf_ledger WHERE created_at >= CURDATE();

-- e) audit noise / hostile callbacks (SEC-02) — alert on abnormal growth
SELECT COUNT(*) FROM gtf_events WHERE order_id IS NULL;                     -- unbound events
SELECT COUNT(*) FROM gtf_events WHERE direction = 'callback' AND provider_status = 'bad_signature';

-- f) card data still held (should only be open orders)
SELECT COUNT(*) FROM gtf_card_orders WHERE serial_sealed IS NOT NULL OR code_sealed IS NOT NULL;
SELECT id, request_id, status, created_at FROM gtf_card_orders
 WHERE credited_at IS NULL AND (serial_sealed IS NOT NULL OR code_sealed IS NOT NULL)
   AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);

-- g) double-credit canary — must only ever return zero rows
SELECT transid, COUNT(*) FROM gtf_ledger GROUP BY transid HAVING COUNT(*) > 1;
```

Suggested thresholds: (a) alert if `REVIEW+UNKNOWN > 20`; (b) alert on any row older than 30 minutes;
(c) alert on any increase; (e) alert if unbound events grow faster than real traffic; (g) alert on any
row (it should be impossible — `transid` is the primary key).

### 7.3 Daily reconciliation against the provider

Compare (d) with the provider's card report (count + amounts). Any difference: do **not** adjust wallets
by hand; identify the orders, park them in review, and settle through the review procedure so the ledger
and the order stay consistent.

---

## 8. Rollback — stopping the flow without losing anything

### 8.1 Configuration rollback (the normal, always-available action)

```dotenv
GTF_CARD_ENABLED=false          # customers can no longer submit cards
# GTF_ALLOW_CREDITING=false     # leave the money lock closed (do not open it while investigating)
```

Effects, in order: the card page becomes read-only and the nav link disappears; `create()` refuses new
submissions; **already-submitted cards keep working** — `api/callback-gtf-card.php` is intentionally not
gated by `GTF_CARD_ENABLED`, so genuine callbacks still arrive and are recorded, and
`php cron/gtf-card.php` keeps reconciling in-flight orders to a terminal state. Nothing is deleted; no
pending order is discarded; wallets are untouched.

### 8.2 Schema rollback — **only before any real order exists**

`php scripts/migrate-gtf.php --rollback` **drops** `gtf_card_orders`, `gtf_ledger` and `gtf_events`.
Those tables *are* payment records once the channel has been used: dropping them deletes order history,
the credit ledger and the audit events. Therefore:

* Allowed: on staging, or on production **only** when the tables have never held a real order.
* Forbidden: after go-live activity. Instead, (1) disable submissions (§8.1), (2) drain the queue, then
  (3) if the tables must be removed, **export them first** and get owner sign-off:
  `mysqldump shop_db gtf_card_orders gtf_ledger gtf_events > /backup/gtf_archive-$(date +%F).sql`.

The legacy payment tables (`users`, `dongtien`, `deposit_log`, `payment_bank`, `aff_log`, `settings`) are
never touched by the migration or its rollback — verified on the fixture (`gtf_migration_verify
(rolledback): 9 passed, 0 failed`).

### 8.3 Code rollback

The GachtheFast files are additive (`libs/gtf*.php`, `api/callback-gtf-card.php`,
`ajaxs/{client,admin}/gtf.php`, `cron/gtf-*.php`, `scripts/migrate-gtf.php`, `views/*gtf*`). Reverting
them leaves the three `gtf_*` tables in place (harmless) and keeps legacy/payOS paths untouched. The
three modified files (`.env.example`, `views/admin/sidebar.php`, `views/client/nav.php`) revert cleanly
because the GTF additions are additive lines.

### 8.4 What a rollback must never do

* never delete or rewrite legacy payment rows, orders or histories;
* never drop the `gtf_*` tables while they hold real orders/credits;
* never leave the callback endpoint unreachable: a provider retry after a rollback must still be
  accepted (or explicitly disabled at the provider) — otherwise a card accepted by the provider becomes
  unrecorded locally;
* never turn off the worker while orders are open — stop *submissions* first, drain the queue, then
  retire the worker.

---

## 9. Failure modes and immediate remedies

| Symptom | Likely cause | Action |
| --- | --- | --- |
| `503 not_configured` on the callback URL | `GTF_PARTNER_KEY` not set | expected before S2; after S2 re-check the env and the provider's callback registration |
| Nav link/page missing | `GTF_CARD_ENABLED=false` (or `GTF_API_BASE`/`GTF_CARD_REQUEST_FORMAT` blank) | expected; see §3 |
| "Cộng tiền" fails with a domain error | crediting locked (`GTF_ALLOW_CREDITING=false`) | expected fail-closed behaviour |
| Admin page shows "Chưa chạy migration GachtheFast" | tables missing | run §4 (after approval) |
| Orders stuck at `CREATING`/`PENDING` | worker not scheduled, or provider unreachable | run `php cron/gtf-card.php`, read the counters and the error log |
| Orders piling at `UNKNOWN` | provider/network trouble | check provider status; they will retry; reconcile manually if urgent |
| `cannot_open_card_data` | `SECRET_KEY` changed | §6 rotation rule; handle those cards manually |
| Callback returns 400 | signature mismatch (wrong `GTF_PARTNER_KEY`, or a stale key after rotation) | coordinate with the provider; do not "fix" by loosening validation |
| Worker over HTTP returns 403 | `GTF_CRON_TOKEN` blank (CLI-only) or wrong token | use CLI, or set/rotate the token and send the header |
| `write` timeouts / lock waits in the log | long rows locked by another credit | retry; the credit fails closed and moves no money |

---

## 10. Developer notes

* **Layout:** `libs/gtf.php` (config, rules, sealing, the single credit routine `GtfCredit::applyWithin`),
  `libs/gtf_client.php` (transport + provider client), `libs/gtf_card.php` (state machine:
  create/callback/reconcile/credit/admin/review), `libs/gtf_bank.php` (fail-closed stub),
  `libs/gtf_bootstrap.php` (entry-point bootstrap), `ajaxs/*/gtf.php` (HTTP), `cron/gtf-*.php` (workers),
  `scripts/migrate-gtf.php` (schema).
* **Invariants that must not be broken** (each is pinned by a test):
  1. a callback never credits — only our own `command=check` reply, bound to `request_id`, may;
  2. a credit is one transaction and is idempotent via `gtf_ledger.transid` (PRIMARY KEY);
  3. an amount must satisfy `value == amount == declared_value == order.declared_amount > 0`;
  4. status `2`, unknown statuses and ambiguous transport outcomes never auto-credit;
  5. the transport is HTTPS-only, host-pinned, POST-only, redirect-free, size-capped, and never logs
     card data, keys or provider payloads;
  6. the bank boundary makes no request and credits nothing.
* **Tests** (all offline or disposable-DB; nothing touches production):

  ```bash
  php tests/gtf_rules_test.php                       # no DB, no network
  GTF_TEST_DSN='mysql:host=127.0.0.1;dbname=gtf_test' GTF_TEST_DB_USER=… GTF_TEST_DB_PASS=… \
      php tests/gtf_service_test.php                 # refuses any DSN without "test"
  … php tests/gtf_credit_path_test.php               # exercises the credit path (test-only unlock)
  … php tests/gtf_credit_path_mutation.php           # proves the exactly-once guard is load-bearing
  GTF_PROBE_BASE=https://127.0.0.1 GTF_PROBE_CA=/path/cert.pem php tests/gtf_transport_probe.php
  php tests/legacy_payment_gate_test.php             # 14 legacy entry points must stop at 410
  ```
* **Do not**: add a default origin or body format, add a GET submission path, follow redirects, disable
  TLS verification, log provider payloads, credit from callback data, or relax the review rules to "make
  a card go through".
* Before touching the credit path, re-read `10-security-review.md` SEC-01 (operator amount binding) and
  SEC-02/SEC-03 (callback write/replay controls).
