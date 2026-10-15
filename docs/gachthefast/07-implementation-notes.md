# GachtheFast integration — implementation notes (Phase 2)

**Status:** code complete, **not executed**. No PHP runtime and no MySQL server exist in the
review sandbox, so nothing in this document is a test result. Everything new ships **disabled**:
`GTF_CARD_ENABLED=false` and `GTF_BANK_ENABLED=false`. Nothing has been committed, deployed or
enabled, and no live provider call has been made.

This file is the reference the source comments point at (for example the `command=check`
signature assumption in `libs/gtf_client.php` and the bank decisions in `libs/gtf_bank.php`).

---

## 1. Files

### New (nothing in this list existed before)

| Path | Purpose |
| --- | --- |
| `libs/gtf.php` | Shared core: env config, PDO factory, CSRF token, operator alert, worker authorisation, `GtfSecret` (AES-256-GCM sealing + masking), `GtfRules` (pure decisions), `GtfCredit` (the only money-moving routine). |
| `libs/gtf_client.php` | `GtfTransport` (pinned HTTPS transport) + `GtfCardClient` / `GtfBankClient` behind the `GtfCardGateway` / `GtfBankGateway` interfaces. |
| `libs/gtf_card.php` | `GtfCardService`: create / callback / reconcile / credit / admin credit / admin resolve / queues. |
| `libs/gtf_bank.php` | `GtfBankService`: poll / ingest / process / credit / admin credit / admin resolve / queues. |
| `libs/gtf_bootstrap.php` | Entry-point bootstrap: dotenv, JSON responder, service factories (mirrors `libs/payos_bootstrap.php`, independent of it). |
| `api/callback-gtf-card.php` | Provider → shop callback endpoint. Records the event and triggers verification; **never credits**. |
| `ajaxs/client/gtf.php` | Customer endpoint: `action=card_create|card_status|card_history`. Token + CSRF, POST only. |
| `cron/gtf-card.php` | Reconciliation worker for open card orders (25 per run). |
| `cron/gtf-bank.php` | Polling worker for incoming bank transfers. |
| `scripts/migrate-gtf.php` | Creates the four `gtf_*` tables after verifying every legacy table it touches is InnoDB; `--rollback` drops only `gtf_*`. |
| `views/client/recharge-gtf-card.php` | Customer card top-up page. |
| `views/client/recharge-gtf-bank.php` | Customer bank-transfer top-up page (shows the reference; receiving account pending owner input). |
| `views/admin/recharge-gtf.php` | Admin console: configuration summary, card review queue, bank review queue, recent activity. |
| `ajaxs/admin/gtf.php` | Admin POST endpoint for the console actions. |
| `tests/gtf_rules_test.php` | Offline unit tests (no DB, no network). |
| `tests/gtf_service_test.php` | Disposable-DB state-machine tests with mock providers. |

### Legacy files touched (3, all additive — verified with `git diff --stat` against the base commit)

| Path | Change |
| --- | --- |
| `.env.example` | Appended the `GTF_*` block; both flags `false`, both credentials blank. |
| `views/client/nav.php` | Two extra top-up links, rendered only when the matching flag is on **and** `GTF_PARTNER_KEY`/`GTF_API_BASE` are set. |
| `views/admin/sidebar.php` | One extra "GachtheFast" item next to payOS, plus the item in the sidebar highlight arrays. |

No legacy payment file was modified: `libs/helper.php` (`card24h()`), `api/callback_card.php`,
`cron/bank.php`, `cron/bankWeb2m.php`, `api/webhook_web2m.php`, `libs/payos*.php`,
`libs/legacy_payment_guard.php`, `ajaxs/client/create.php` and `views/client/recharge-*.php`
are untouched. The TheSieuRe and web2m bank paths are therefore byte-identical to the base commit.

### Routing

`index.php:40` resolves `views/{module}/{action}.php`, so the new screens are reachable as
`?action=recharge-gtf-card` and `?action=recharge-gtf-bank` (client) and
`?module=admin&action=recharge-gtf` (admin) without registering anything.

---

## 2. Data model

Created by `scripts/migrate-gtf.php` (all InnoDB; the script refuses to run unless every
legacy table it will write to is InnoDB, because the credit transaction depends on rollback):

| Table | Key | Notes |
| --- | --- | --- |
| `gtf_card_orders` | `UNIQUE request_id` | One row per submitted card. Amount, telco, masked serial, sealed serial/PIN, state, provider status/reference, `credited_at`. |
| `gtf_bank_events` | `UNIQUE order_code` | One row per provider receipt. `pay_amount_raw` keeps the provider string verbatim; `pay_amount` is 0 when the string is not an integer. |
| `gtf_ledger` | `UNIQUE transid` | `GTF_CARD_<request_id>` / `GTF_BANK_<order_code>`. Database-level duplicate guard for the credit path. |
| `gtf_events` | `UNIQUE event_key` | Audit trail of provider callbacks and observed statuses. |

Rollback: `php scripts/migrate-gtf.php --rollback` drops **only** these four tables and never
touches legacy rows. `--convert` runs `ALTER TABLE ... ENGINE=InnoDB` on legacy tables and is
explicitly **not reversible** — take a backup first (see `02-blockers.md` for the backup step).

---

## 3. Money rules as implemented

1. **Callbacks never credit.** `GtfCardService::callback()` verifies `md5(partner_key.code.serial)`,
   records the event and calls `reconcile()`, which makes its own `command=check` call. A
   callback cannot move money even with a valid signature, and a forged signature is recorded
   as a `bad_signature` event and rejected.
2. **Only a bound, verified answer credits.** The `check` response must carry our own
   `request_id`; when `currency` is present it must be `VND`; the decision needs
   `value == amount == declared_value == declared_amount` and a positive value. Status `2`,
   unknown statuses, malformed/missing amounts, amount conflicts, unbound answers, unexpected
   currency and unavailable lookups all park the order in `REVIEW` or `UNKNOWN` with an alert.
3. **Exactly one credit per order/receipt.** `GtfCredit::applyWithin()` is the only routine that
   adds money. It inserts the unique ledger row first, then writes `dongtien`, `users.money`,
   `deposit_log` and `payment_bank`, then runs the caller's finalizer which flips the order
   state with `rowCount() === 1` on a `WHERE ... AND credited_at IS NULL` / `status <> 'CREDITED'`
   condition. Any failure rolls the whole thing back, so state and ledger cannot diverge.
4. **Ambiguous outcomes are never "failed".** A submit or check transport failure leaves the
   order `UNKNOWN` (recoverable, alerted) rather than rejected.
5. **Card data is shredded** (`serial_sealed`/`code_sealed` set to NULL) as part of the same
   transaction that credits the order, or when an order reaches a terminal state.
6. **Nothing is deleted.** Rejected, ignored and uncredited rows stay in their table with a
   reason; the admin queues expose them.
7. **Bank ingest never guesses.** A non-integer `pay_amount` is stored with `pay_amount = 0`,
   the raw string in `pay_amount_raw`, status `REVIEW` and reason `non_integer_amount` — in
   particular `66666.00` can never become `6666600`.

## 4. Promotions, fees, debt, affiliate

- Card credit = declared amount − `GTF_CARD_FEE_PERCENT` (`GtfRules::cardNet`, 0..100 %).
- Bank credit = receipt amount + the best matching `promotions` row (`min <= amount`,
  `ORDER BY min DESC LIMIT 1`), via `GtfRules::promotionNet` (refuses a discount above 1000 %).
- The credit routine also settles debt (`debit`) and, when `affiliate_status = 1`, pays the
  referrer commission inside the same transaction, strictly guarded so a bad percentage aborts
  the credit instead of paying an unbounded amount.

## 5. Transport and secret handling

- `GtfTransport` accepts HTTPS only, a host equal to `gachthefast.com` or a subdomain of it, no
  userinfo/query/fragment, no port other than 443; certificate and hostname verification stay
  on; redirects are never followed; connect/read timeouts are 3–30 s; the response body is
  capped at 1 MiB; errors expose only the cURL errno or the HTTP status.
- Card serial/PIN are sealed with AES-256-GCM using a key derived from `SECRET_KEY`
  (`sha256(SECRET_KEY|gtf-card-v1)`), stored as `v1:` + base64(iv|tag|ciphertext). Only the
  masked serial (last three characters) is ever displayed or logged.
- Nothing logs credentials, signed payloads or raw provider responses.

## 6. Assumptions that still need provider confirmation

**Confirmed by the card partner API documentation the owner supplied on 2026-10-04** (see §10):
the endpoint (`/chargingws/v2`), the signature (`md5(partner_key + code + serial)`), the command
values (`charging`, `check`), the full `check` field set (telco, code, serial, amount, request_id,
partner_id, sign, command), the status codes (`1`, `2`, `3`, `4`, `99`, `100`), and that GET and
POST are equally acceptable. Status `1` is what the code auto-credits; `2` stays manual review.

Remaining items:

1. **Card API host** — the document writes `http://{{domain_post}}/chargingws/v2`; the integration
   uses `GTF_API_BASE=https://gachthefast.com` and refuses plain HTTP. Confirm the HTTPS host that
   actually serves `/chargingws/v2` (a subdomain is allowed by the transport allow-list).
2. **Callback/`check` response fields** — whether the response carries `currency` and a provider
   reference (`trans_id`). The code refuses a present-but-not-`VND` currency and records a reference
   when one is present.
3. **`check` failure shape** — what `check` returns for a `request_id` it does not know. Anything that
   is not a documented status becomes `UNKNOWN` + alert, never a credit.
4. **Bank `history-receive`**: the wallet ownership question (N3), pagination (N1), amount format (N2)
   and memo content (N4) are still open; see §10 and `02-blockers.md`.
5. **`pay_amount` integrality** for the bank feed; anything non-integer is parked for a human.
6. **PDO affected-rows semantics** (bank dedupe counts `rowCount() === 1` as "inserted", correct for
   MySQL with `PDO::MYSQL_ATTR_FOUND_ROWS` left at its default).
7. **Provider retention depth** for both feeds (N7) — sets the maximum safe worker downtime.

## 7. Operating the feature

```
# 1. back up the database (not optional: the migration is DDL)

# 2. create the tables (add --convert only if every legacy table is already InnoDB)
php scripts/migrate-gtf.php

# 3. workers (CLI is authorised automatically; over HTTP set GTF_CRON_TOKEN)
php cron/gtf-card.php     # reconcile open card orders, alert on failures
php cron/gtf-bank.php     # poll incoming bank transfers

# 4. rollback (drops only the four gtf_* tables)
php scripts/migrate-gtf.php --rollback
```

Flag semantics worth knowing:

* `GTF_CARD_ENABLED=false` stops **new** card submissions and hides the customer page/links, but
  `api/callback-gtf-card.php` and `cron/gtf-card.php` keep working on purpose so that in-flight
  orders are still resolved instead of being stranded (mission rule: never silently discard a
  pending order).
* `GTF_BANK_ENABLED=false` makes `cron/gtf-bank.php` exit 0 without polling (state `disabled`) and
  `GtfBankService::poll()` throws if called directly; nothing is stored or credited.
* Both workers are CLI-only unless `GTF_CRON_TOKEN` is set and supplied via the
  `X-GTF-CRON-Token` header or a `token` parameter (`gtf_worker_allowed()`).
* Required PHP extensions: `pdo_mysql`, `curl`, `openssl` (card sealing), `mbstring` (bank memos).

Admin operations live in `?module=admin&action=recharge-gtf`: credit a reviewed card or receipt,
re-run reconciliation, park/ignore a row. Every action requires the `edit_recharge` permission,
a CSRF token and a non-demo account, and writes a `logs` row. Card data is read through
`GtfSecret::open()` inside those calls only.

## 8. Tests and their status

| Suite | Needs | Status |
| --- | --- | --- |
| `tests/gtf_rules_test.php` | PHP 8 + curl/openssl | **NOT RUN** (no PHP runtime in the sandbox) |
| `tests/gtf_service_test.php` | PHP 8 + MySQL/MariaDB + a throwaway database | **NOT RUN** (no PHP, no MySQL, no `vendor/`) |
| `scripts/migrate-gtf.php` | MySQL | **NOT RUN** |

`tests/gtf_service_test.php` refuses to run unless the DSN contains `test`, drops only its own
fixture tables, and replaces the provider with mock gateways; the legacy tables it creates are a
**fixture**, not the production schema (blocker B9). Running the suite is a Phase 3 prerequisite
and it must be executed on a disposable database before any enablement.

## 9. Known gaps (deliberately not implemented yet)

1. **Stale sealed card data**: `cron/gtf-card.php` only counts and alerts on old rows still
   holding sealed data; automatic shredding after the retention window is not implemented.
2. **Receiving account for bank transfers**: the customer page always shows the required
   reference and renders `GTF_BANK_RECEIVING_INFO` when it is set; while it is empty the page
   shows an explicit "account not configured — contact the administrator" notice instead. The
   owner must supply account + bank + holder (and confirm them) before the bank channel is
   enabled; no code change is needed once the value is set.
3. **Card history pagination / language files**: the pages are self-contained Vietnamese, no
   `lang` keys added.
4. **payOS coexistence UI**: the legacy bank page still renders payOS unconditionally
   (`views/client/recharge-bank.php:5`); this was intentionally left untouched per the
   "preserve payOS behaviour" rule. Where the GachtheFast bank page should be linked relative
   to payOS remains an owner routing decision (see `02-blockers.md` Q-R-3).

---

## 10. Card partner API documentation received 2026-10-04 (contract now confirmed)

The owner supplied the GachtheFast **card partner API** documentation. What it states, and what the
code does with it:

| Provider statement | Code consequence |
| --- | --- |
| `POST http://{{domain_post}}/chargingws/v2`, GET or POST both acceptable | `GTF_CARD_SUBMIT_STYLE` now selects `json` (POST + JSON body, default), `form` (POST urlencoded) or `query` (GET). The `http://` in the document is *not* used — the transport is HTTPS-only against `GTF_API_BASE`. |
| Signature `md5(partner_key + code + serial)` | Already implemented in `GtfCardClient::sign()`; the callback uses the same formula. |
| `command=charging` fields: telco, code, serial, amount, request_id, partner_id, sign | Implemented 1:1. |
| `command=check` example sends **telco, code, serial, amount, request_id, partner_id, sign** — same signature formula | `GtfCardClient::check()` now sends that exact field set (previously it sent only request_id/partner_id/sign/command) and `GtfCardService::reconcile()` passes the stored telco and declared amount. |
| Status codes `1` (success, correct denomination), `2` (success, different denomination), `3` (card error), `4` (maintenance), `99` (pending), `100` (submission failed, reason in the response message) | Matches `GtfRules::cardDecision()` exactly: only `1` auto-credits; `2` and unknown statuses park in `REVIEW`; `3`/`4`/`100` are terminal without credit; `99` stays pending. |
| Response carries a `message` on failure | Only the documented status codes drive decisions; the message text is never parsed for money. |

Still to confirm with the provider (see §6): the HTTPS host, whether the response carries `currency`
and `trans_id`, and what `check` returns for an unknown `request_id`.

---

> **Correction notice (2026-10-04, added during the stop-and-verify review).** Statements in this
> file that describe the GachtheFast work as "implemented", "resolved", "tested" or "code complete"
> are superseded. The accurate status is: code written into this checkout, **never executed, never
> PHP-linted, never tested, disabled by configuration, uncommitted**. In particular the card items
> earlier marked resolved are only **documented** — the production API host, the accepted body
> format, the `check` response schema and whether `check`/callbacks are authenticated or
> authoritative remain **unconfirmed**, and the status-`2` credit rule remains a **provisional
> conservative policy, not a provider-confirmed rule**. See
> `08-verification-and-owner-questionnaire.md` §1.5 and §3.3 for the full correction and the owner
> questions that must be answered first.


---

## 11. Remediation pass — crediting lock, explicit origin/format, fail-closed bank (2026-10-04)

Second pass over the same checkout, after the owner brief *"implement GachtheFast safely"*. The
goal was to remove every place where unconfirmed contract facts had been baked into the code, and to
guarantee that no code path in this checkout can move money while the contract is unknown.

### 11.1 What the code does now

| Surface | Behaviour after the pass |
| --- | --- |
| API origin | Read from `GTF_API_BASE` only. **No default, no hostname anywhere in the code or in `.env.example`.** With a blank value the card client and its entry points refuse to run. |
| Request body format | Read from `GTF_CARD_REQUEST_FORMAT` and must be exactly `json` or `form`. **No default, no fallback, no `query`/GET**, because the provider document shows a JSON header over a form-data example. |
| Transport | HTTPS-only, port 443, pinned to the configured host, POST only, redirects refused, TLS verification on, response size capped (1 MiB), only cURL error numbers surfaced (never URL/body/payload). |
| Crediting | `GtfCredit::isEnabled()` reads `GTF_ALLOW_CREDITING` (**default `false`**). `applyWithin()` throws while it is closed. An order that would have been credited is instead parked as `REVIEW` with reason `credit_locked_pending_contract`. |
| Decision vocabulary | `GtfRules::cardDecision()` returns `credit_candidate` (never `auto_credit`) for a confirmed status `1`; `2`, conflicts, malformed and unknown statuses → `manual_review`; `3`/`4`/`100` → terminal without credit; `99` → pending. |
| Bank flow | `GtfBankService` is a fail-closed stub: `isAvailable()` is `false`, `unavailableReason()` explains the missing contract, and every data/money method throws. No client, no schema, no reference format, no matching rule, no `GtfCredit` call. |
| Bank removal | `gtf_bank_events` is gone from the schema script, the admin view, the client nav, the admin AJAX handler, the worker (now a no-op) and `.env.example`. |

Removed surfaces (verified absent afterwards by `grep`): `GtfBankClient`, `GtfBankGateway`,
`gtf_bank_events`, `bank_enabled`, `bank_min`/`bank_max` (GTF keys), `bank_receiving_info`,
`card_submit_style`, `GTF_CARD_SUBMIT_STYLE`, `GTF_BANK_REVIEW`, `GTF_BANK_ENABLED`, the bank rules
(`bankReference`, `matchReference`, `bankDecision`, `promotionNet`) and the `auto_credit` decision
name. Legacy/CMSNT `bank_min`/`bank_max` and the `promotions` table outside the GTF surface are
untouched.

### 11.2 Tests written for the new behaviour (still UNEXECUTED)

* `tests/gtf_rules_test.php` — pure offline: signature formula and order sensitivity; the exact
  documented field set for `charging`/`check` against a fake transport; `json` vs `form`; transport
  failures propagating instead of turning into fake statuses; constructor refusals for blank origin,
  blank/invalid format (`GET`, `get`, `query`, `post`, `xml`), blank partner id/key; a full
  origin accept/reject matrix for the transport (http, no scheme, userinfo, query, fragment, non-443
  port, path, control characters, leading/trailing dot, empty, no allow-listed path, off-list path);
  the whole status table including every non-creditable status; amount validation; fee maths; the
  credit lock (`isEnabled` false by default and with the packaged config); the bank boundary source
  properties (no HTTP call, no money routine after comment stripping, no invented contract fields);
  sealing/masking; and source-level guarantees (no provider hostname, no GET, TLS on, no redirects,
  no request-input origin, credit routine behind the lock, callback never calls the credit routine).
* `tests/gtf_service_test.php` — card state machine against a **disposable** MySQL fixture (refuses
  to run unless the DSN contains `test`): feature flag off refuses submissions; the order row is
  committed before the provider call and the serial is sealed in it; pending credits nothing;
  `command=check` must be bound to our own `request_id` before any decision; a bound agreeing status
  `1` credits exactly once (wallet, `gtf_ledger`, `dongtien`, `payment_bank` all updated); replays,
  duplicate callbacks and a second `credit()` are no-ops; forged callbacks and unknown orders never
  credit; conflicts and unbound answers park in `REVIEW`; transport timeouts stay `UNKNOWN` and are
  recoverable; the UNIQUE ledger key refuses a duplicate transaction; `status=2` goes to `REVIEW`;
  the operator credit is idempotent; and with `GTF_ALLOW_CREDITING` unset **nothing** credits while
  the order is parked as `credit_locked_pending_contract`; the bank stub refuses every operation.

### 11.3 Verification actually performed in this pass

* **Correction (QA pass, 2026-10-04): the runtime gap below was closed.** A WASM build of the real
  PHP 7.4.33 CLI plus a disposable MySQL 5.6.24 were provided in the sandbox, and the suites were
  executed: lint 232/232, rules 124/0, service 93/0, credit-path 75/0, mutation 2/2, transport probe
  18/0 + 3/0, migration lifecycle and all refusals green. Full commands, outputs, defects and gaps:
  `09-qa-test-matrix.md`. The bullets that follow describe the **earlier** review pass and are kept
  for the record; they are superseded for the "not run" claims.
* **Not run:** no `php -l`, no test execution, no migration. The sandbox has no PHP runtime, no
  MySQL/MariaDB server, no `vendor/`, no `docker` and no `composer`
  (`command -v php php7.4 php8 php8.1 php8.2 php8.3 hhvm docker composer` → all missing).
* **Run:** a delimiter heuristic (comment/string-aware brace, paren and bracket balance) over the 17
  touched PHP files → all balanced. **This is a heuristic, not a lint and not a test pass.**
* **Run:** `grep` sweeps confirming no `gachthefast.com`, no removed symbol and no dead bank key
  remains anywhere in `libs/ api/ ajaxs/ cron/ scripts/ views/ tests/ .env.example`.

Exact commands for whoever has a runtime (expect the rules test to pass, the service test to need a
throwaway database):

```bash
php -l libs/gtf.php && php -l libs/gtf_client.php && php -l libs/gtf_card.php && \
php -l libs/gtf_bank.php && php -l libs/gtf_bootstrap.php && php -l cron/gtf-card.php && \
php -l cron/gtf-bank.php && php -l scripts/migrate-gtf.php

php tests/gtf_rules_test.php                     # no network, no database, no credentials

GTF_TEST_DSN='mysql:host=127.0.0.1;dbname=shopclone7_test' \
GTF_TEST_DB_USER=root GTF_TEST_DB_PASS=... php tests/gtf_service_test.php
```

The service test harness drops and recreates its own fixture tables and refuses to run unless the DSN
contains `test` (override with `GTF_TEST_FORCE=yes` only on a throwaway database). A green run proves
the SQL and the state machine **against that fixture**, not against the production schema.
