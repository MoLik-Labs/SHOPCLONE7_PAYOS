# Agent C — Payment Architect: design proposal (for Gate 1 approval)

Status: **PROPOSAL — no code written, none may be written before Gate 1.**
Parameters marked `«B#»` are pending owner/provider answers in `02-blockers.md`. The architecture is
deliberately independent of those answers; only the adapter internals and the amount rule depend on them.

Design constraints inherited from the mission: never credit on client-supplied data; bind every event
to one local transaction; exactly-once crediting under duplicate/replay/concurrent/delayed/out-of-order
events; one atomic DB transaction for state + wallet + ledger; ambiguous timeout = unknown; HTTPS with
certificate validation, strict timeouts, bounded responses, no redirects; never log secrets or full
provider responses; credentials outside source control; new methods disabled by default; no deletion of
historical records; keep payOS behaviour unless approved.

---

## 1. Boundaries (separate, testable client/service split)

Mirroring the payOS precedent (`libs/payos_client.php` transport, `libs/payos.php` service), one
provider gets one transport class and one service class per flow:

| Layer | Card flow | Bank flow | Reused from |
|---|---|---|---|
| Transport (pure HTTP, no DB) | `GtfCardClient` | `GtfBankClient` | pattern of `libs/payos_client.php` |
| Service (state machine + money) | `GtfCardService` | `GtfBankService` | pattern of `libs/payos.php` |
| Config | `gtf_config()` reading `$_ENV` only | same | `payos_config()` (`libs/payos.php:11`) |
| DB | injected `PDO` (prepared statements, exceptions) | same | `payos_db()` (`libs/payos.php:25`) |
| Entry points | client AJAX, provider callback, reconciliation worker | same | — |
| Admin config UI | read-only status + enable/disable + limits | same | — |

Non-goals: no change to `libs/db.php` (`mysqli`), no change to `users::AddCredits()`, no refactor of
unrelated providers, no touching of the payOS 410 gate.

## 2. Data model (append-only; parameterised on the schema fixture «B9»)

```
gtf_card_orders                        gtf_bank_orders
  id                BIGINT PK            id                BIGINT PK
  user_id           INT                  user_id           INT
  request_id        VARCHAR UNIQUE       local_ref         VARCHAR UNIQUE   -- we generate; quoted in memo
  provider_tx_id    VARCHAR NULL         provider_ref      VARCHAR NULL     -- «B2» provider id
  telco             VARCHAR              provider_account  VARCHAR NULL     -- «B2» shop account
  declared_amount   DECIMAL(20,2)        declared_amount   DECIMAL(20,2)
  credited_amount   DECIMAL(20,2) NULL   credited_amount   DECIMAL(20,2) NULL
  currency          CHAR(3)              currency          CHAR(3)
  status            VARCHAR              status            VARCHAR
  submitted_at      DATETIME             created_at        DATETIME
  provider_checked_at DATETIME NULL      provider_checked_at DATETIME NULL
  credited_at       DATETIME NULL        credited_at       DATETIME NULL
  attempt_count     INT                  attempt_count     INT
  last_error_code   VARCHAR NULL         last_error_code   VARCHAR NULL

gtf_events (append-only audit; one row per provider observation, replayable)
  id, flow(card|bank), order_id, direction(submit|callback|poll),
  provider_ref, provider_status, provider_amount NULL, request_hash, created_at,
  UNIQUE(flow, order_id, direction, event_key)
```

Rules the schema must enforce (not merely assume):

* `UNIQUE(request_id)` / `UNIQUE(local_ref)` — the database, not a pre-check, prevents duplicates.
* `UNIQUE(flow, provider_ref)` where the provider supplies a stable id «B2/B4» — prevents a second
  local order for the same provider transaction.
* `dongtien.transid` must be `UNIQUE` for the ledger row (`gtf_card:<request_id>` /
  `gtf_bank:<local_ref>`). **Confirmed needed**: legacy `dongtien.transid` has no unique key today.
* Engine must be InnoDB for `users`, `dongtien`, `payment_bank`, `deposit_log`, `aff_log`, `cards`
  (see `scripts/migrate-payos.php:26-31` for the precedent DDL; «B9» to confirm it has been applied).
* No card PIN/serial is persisted in cleartext long-term. Proposed: store the last 4 of the serial and
  a keyed hash (HMAC with `SECRET_KEY`) for de-duplication/audit; **not** the PIN. The historical
  `cards` table keeps whatever it already has (never deleted), and yes/no masking of legacy rows is a
  separate, owner-approved migration. **PIN retention length is an OPEN DECISION.**
* Secrets come from the environment only. Admin UI shows configured/not-configured booleans, never
  values (contrast: today `views/admin/recharge-card.php:126-127` renders the partner key into HTML).

## 3. Card flow — sequence

```
client POST (token/session, CSRF, server-side validation)     ── validate user, ban, throttle,
   │                                                              telco∈allowed set, denomination∈allowed set,
   │                                                              serial/pin format, per-user pending cap
   ├─ INSERT gtf_card_orders(status='CREATING', request_id=…)  ── durable BEFORE the provider call
   │     COMMIT
   ├─ provider submit /chargingws/v2 command=charging  «B1»    ── HTTPS only, strict timeout, capped body,
   │                                                              no redirects, no secrets in logs
   │   ├─ accepted (99) → status='PENDING'  → reply "queued"
   │   ├─ 3/4/100        → terminal, no money involved → status='REJECTED'…, reply reason
   │   └─ timeout / 5xx / unparsable → status='UNKNOWN'  ── NOT failed. No retry until «B4» resolves.
   │                                         reconcile via check/worker; alert if unresolved
   ▼
provider callback  GET ?status&message&request_id&declared_value&value&amount&code&serial&telco&trans_id
   │  1. verify signature exactly as documented «B3»  (constant-time compare)
   │  2. IP allow-list check «B3» (fail-closed if configured; log-only if owner accepts risk)
   │  3. load order by request_id; reject unknown / wrong user / terminal-state conflicts
   │  4. cross-check status against `check` when the callback is the first evidence «B4»
   ▼
Credit (single InnoDB transaction — see §5)
```

`check`-based reconciliation worker (CLI cron or admin-triggered) is mandatory for every order in
`CREATING`/`UNKNOWN`/`PENDING` older than N seconds, with per-order claim via
`UPDATE … WHERE provider_checked_at IS NULL OR provider_checked_at < NOW() - INTERVAL 15 SECOND`
(the payOS precedent, `libs/payos.php:199-204`), bounded attempts, and an alert when
`attempt_count > threshold`.

## 4. Bank flow — sequence (contract pending «B2»)

Same skeleton, with the unknown middle replaced by whichever the owner confirms:

```
Stage 1  local intent row created (user_id, local_ref, declared_amount)   → durable first
Stage 2  provider:  Variant VA-VA   : mint/attach a per-order receiving reference
                    Variant POLL    : nothing to mint; worker matches memo→local_ref
                    Variant INTENT  : create deposit intent, expose provider URL
Stage 3  notification: push callback (verify per «B2») and/or pull (worker)
Stage 4  match: provider_ref not seen before  AND  credited account == expected account
               AND  amount within [min,max]  AND  memo resolves to exactly one local_ref
Stage 5  credit atomically (§5). Ambiguous matches (two orders same amount at the same
         moment, memo absent/duplicated) must NOT auto-credit — park in `needs_review` + alert.
```

Even before the contract arrives, two rules are fixed by the mission and do not depend on it:
memo/amount alone is never sufficient evidence to credit a *specific user* when the match is ambiguous,
and the legacy practice of deriving the user from free-text memo (`parse_order_id()`,
`libs/helper.php:432`) is not acceptable as the sole binding for a provider we are newly integrating —
the binding must be a locally created record with a provider reference.

## 5. Exactly-once crediting (the core invariant)

One transaction, in this order, for card and bank alike:

1. `BEGIN`
2. `SELECT * FROM gtf_*_orders WHERE id=? FOR UPDATE` → bail out (`commit`, return `already_credited`)
   if `credited_at IS NOT NULL`.
3. Re-verify the event against the **locked** row: provider ref equality (constant-time), account,
   currency, and the amount rule (§6). Any mismatch → `ROLLBACK`, record reason, alert.
4. `SELECT id,banned FROM users WHERE id=? FOR UPDATE` → user must exist and not be banned.
5. Ledger: insert `dongtien` row with `transid = 'gtf_card:'+request_id` (unique) computed **inside**
   the same transaction; `sotientruoc` read under the user lock.
6. `UPDATE users SET money = money + ?, total_money = total_money + ? WHERE id = ?` — arithmetic in SQL,
   never read-modify-write in PHP.
7. Insert `deposit_log` (+ `payment_bank` if the owner wants these to remain the canonical history —
   OPEN DECISION) with the provider `tid`/reference for reconciliation.
8. Promotion/commission/debt-offset, reusing the payOS semantics exactly (`libs/payos.php:272-289`)
   so two channels cannot produce different numbers for the same amount.
9. `UPDATE gtf_*_orders SET status='PAID', credited_at=NOW(), credited_amount=?, provider_ref=?`
   **with a guard** `AND credited_at IS NULL`; rowCount must be 1 or the transaction is rolled back.
10. Insert `gtf_events` audit row.
11. `COMMIT`

Why this is exactly-once: the only path that credits is step 5-9, all inside one transaction, gated by
a row lock on the order and a unique ledger key. A duplicate callback, a replayed callback, a
delayed/out-of-order callback, a concurrent worker, and a webhook racing the status page all end up
blocked on step 2/step 9 and return `already_credited` without touching the wallet.

Idempotency is therefore enforced **at the database level** (unique `transid`, unique `provider_ref`,
row lock, guarded update) and not by a pre-check — the failure mode of the current legacy paths
(`api/callback_card.php:36` read-then-write; `cron/bank.php:25` / `api/webhook_web2m.php:35`
`SELECT`-then-`INSERT` on `payment_bank`) is explicitly not carried forward.

## 6. Amount rule (blocked on «B5»)

Deterministic table, to be filled by the owner:

| Provider outcome | Credited amount | Fee/commission |
|---|---|---|
| card status `1` | `«B5»` (declared value) | `card_ck`% or 0, per owner |
| card status `2` | `«B5»` — **which field wins is undecided** | per owner |
| card `3`/`4`/`100` | 0 (no credit) | — |
| bank incoming | `«B2»` amount vs received vs fee | per owner |
| under-payment | `«B5»` (proposal: do not auto-credit below min; park for review) | — |
| over-payment | `«B5»` (proposal: credit declared amount only, excess parked for manual review — matches the payOS precedent, `HUONG_DAN_PAYOS.txt` "Chuyển dư") | — |

## 7. Migration, rollback, and old-provider cutover

Forward migration (`scripts/migrate-gtf.php`, CLI-only, re-runnable, STDOUT summary, no data deletion):

1. Pre-flight assertions (tables exist, engines are InnoDB, required columns present) — fail closed.
2. Create `gtf_card_orders`, `gtf_bank_orders`, `gtf_events`.
3. Add/adjust the missing unique keys (`dongtien.transid`, legacy `payment_bank` index) **only** if the
   pre-flight shows no existing duplicates; otherwise report and stop.
4. Print the exact rollback statements. Never `DROP` a table that may contain history.

Rollback: `PAYOS_ENABLED`/`GTF_*_ENABLED` flags return to `false`; **callbacks and reconciliation stay
up** for in-flight orders (the payOS precedent, `HUONG_DAN_PAYOS.txt:74`, does exactly this). New
order creation stops; nothing is deleted; no pending order is discarded.

CardVN cutover plan (executable only after «B6» identifies the target):

1. Inventory in-flight rows in the old provider's table(s) and settle them before disabling submission.
2. Stop new submissions; keep the old callback endpoint reachable and idempotent until the in-flight
   set is empty.
3. Remove only the confirmed provider's client-side route, AJAX branch, admin config branch, callback
   endpoint, and helper function.
4. **Never** delete historical rows; mark them and leave the admin history readable.
5. If the target turns out to be `card24h()`/TheSieuRe, steps 1-5 apply to
   `libs/helper.php:871`, `ajaxs/client/create.php:91-144`, `api/callback_card.php`,
   `views/client/recharge-card.php`, `views/admin/recharge-card.php` — **and only after «B6» says so.**

TheSieuRe preservation: whatever «B7» names (label / `cron/bank.php` / web2m pair / `banks` rows)
is left byte-identical; the new bank flow gets its own settings keys and its own flag so it cannot
shadow it.

## 8. Routing matrix — three options for «B8»

| | Option A — mutually exclusive | Option B — coexist (RECOMMENDED for review) | Option C — GachtheFast inherits the replacement role |
|---|---|---|---|
| `PAYOS_ENABLED=true` | GachtheFast card/bank blocked (410) like today's legacy paths; payOS only | payOS remains default; GachtheFast visible as a separate, explicitly labelled method with its own flag | GachtheFast replaces the legacy top-up surface as payOS did |
| `PAYOS_ENABLED=false` | GachtheFast available if its own flag is on | GachtheFast available alongside the preserved legacy route | GachtheFast only |
| Legacy 410 gate | untouched | untouched | must be extended deliberately, never weakened |
| Client bank route | payOS view only | new dedicated view; `views/client/recharge-bank.php:5` untouched | new view replaces the shim |
| Risk | lowest, but forecloses the stated goal | matches "keep TheSieuRe + add GachtheFast + preserve payOS" | largest blast radius on money routes |

Recommendation: **Option B**, with `GTF_CARD_ENABLED=false` and `GTF_BANK_ENABLED=false` by default and
each flag enabled separately during rollout. Option A is acceptable only if the owner confirms
GachtheFast is meant as a payOS replacement. This is a routing decision the owner must make explicitly.

## 9. Secrets and configuration

`.env` additions (blank placeholders only, added to `.env.example`, never committed with values):

```
GTF_API_BASE=                      # exact HTTPS origin «B1», no trailing slash
GTF_CARD_ENABLED=false
GTF_BANK_ENABLED=false
GTF_PARTNER_ID=                    # «B1/B2»
GTF_PARTNER_KEY=
GTF_CALLBACK_IPS=                  # optional allow-list «B3»; empty = disabled check
GTF_CARD_MIN_AMOUNT= / GTF_CARD_MAX_AMOUNT=
GTF_BANK_MIN_AMOUNT= / GTF_BANK_MAX_AMOUNT=
GTF_TIMEOUT_SECONDS=10
```

Rules: values validated for charset/length at construction (the `PayOSClient` precedent,
`libs/payos_client.php:18-22`); the transport rejects a base URL that is not `https`, contains
userinfo/query, or is not on the exact expected host; `__debugInfo()`/exception messages never carry
credentials; logs record provider event keys and status codes only, never payloads.

## 10. Operations: alerts, reconciliation, failure handling

| Signal | Trigger | Action |
|---|---|---|
| `gtf_orders_unknown` | order in `UNKNOWN` > 120 s | page/Telegram; auto-reconcile via `check` |
| `gtf_callback_signature_failures` | rate of failed signature checks | alert; possible forgery probe |
| `gtf_reconcile_backlog` | count of uncredited orders > threshold | alert operator |
| `gtf_amount_mismatch` | provider amount ≠ local expected | block credit, park for review, alert |
| `gtf_duplicate_provider_ref` | unique-key violation on provider ref | investigate; possible provider double-send |
| `gtf_stale_pending` | `PENDING` > provider SLA | reconcile, then manual review |
| Wallet integrity | scheduled: `SUM(gtf credited) == SUM(dongtien Δ where transid LIKE 'gtf_%')` per day | alert on drift |

Failure policy: any exception inside the credit transaction ⇒ rollback ⇒ **non-2xx** to the provider so
it retries (the payOS precedent, `api/webhook-payos.php:24-28`); a rollback never partially credits.

## 11. Threat assumptions (input to Agents F/G)

1. The card callback signature does not cover money fields until «B3» is answered → treat callbacks as
   *untrusted claims* that must be corroborated by `check` before crediting.
2. Provider-facing requests may be intercepted if the endpoint is plaintext «B1» → refuse to send.
3. `command=check` may be spoofable «B4» → corroboration value only until proven otherwise.
4. Attackers can replay our own callback URL verbatim, in parallel, hours later → row lock + unique
   ledger key + terminal-state guard.
5. Attackers may control a card's *reported* value (status 2) → only provider-confirmed value is used,
   never a client- or callback-supplied amount alone «B5».
6. Legacy `banks` rows and webhook tokens are pre-existing shared secrets; the new provider must not
   reuse them, and this work must not expose them (they are rendered into admin HTML today).
7. `cron/*.php` is web-reachable without a guard (`00-inventory.md` §3.3) → the new reconciliation
   worker must require CLI or a shared secret, and must be idempotent.

## 12. Unresolved decisions (Gate 1 checklist)

- [ ] «B1» hostname + HTTPS
- [ ] «B2» bank contract + direction confirmation
- [ ] «B3» callback field authentication / IP range
- [ ] «B4» `check` authority + response authentication
- [ ] «B5» amount/fee/under/over policy table (§6)
- [ ] «B6» CardVN target confirmation
- [ ] «B7» TheSieuRe scope to preserve
- [ ] «B8» routing option A/B/C (§8)
- [ ] «B9» schema-only fixture: engines, indexes, `dongtien.transid` uniqueness
- [ ] «B10» sandbox/test capability
- [ ] Whether new flows also write `payment_bank`/`deposit_log` (history continuity) or only the new tables
- [ ] Whether a legacy `cards` row must be created per GachtheFast card order (admin history continuity)
- [ ] PIN retention policy and legacy-row masking decision
