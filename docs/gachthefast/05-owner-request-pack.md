# Owner request pack — the exact inputs that unblock implementation

Give me these five things and Phase 2 can start. Items marked **chat-safe** can be pasted here;
items marked **secret** must **not** be pasted into chat — put them in `.env` on the deployment host.

---

## R1 — GachtheFast card API: base URL and transport (chat-safe)

Fill in the blanks:

```
Card API base URL (production):  https://____________________
Card API base URL (sandbox/test): https://____________________   (or "none")
HTTPS for API calls?              yes / no
HTTPS for inbound callbacks?      yes / no
Does the callback URL have to be publicly reachable, or is the callback optional
  (i.e. can we rely entirely on command=check)?   publicly reachable / optional / unknown
Is the API base URL on the same host as the merchant panel (gachthefast.com)
  or a separate API host?         same / separate: ____________________
```

The supplied document only shows `http://{{domain_post}}`. I will not send any card PIN or credential
over plaintext, so if the API is HTTP-only the card flow cannot be built as specified.

## R2 — Card API gaps the document does not answer (chat-safe)

| # | Needed | Your answer |
|---|---|---|
| R2.1 | Full **callback** field list, and the exact string that is signed for callbacks. Is `request_id` (our id) echoed back? Is `trans_id` (your id) returned? | |
| R2.2 | Is there a **provider-published callback IP range/allow-list**? If yes, the range(s). | |
| R2.3 | Is `command=check` **authoritative** for final status? Is its response signed/authenticated, and how? | |
| R2.4 | For status `2`, which field is the real credited value — `amount`, `value`, or `declared_value`? | |
| R2.5 | Under-payment policy (card value below declared amount) and over-payment policy (card value above declared amount): what should the shop do? | |
| R2.6 | Is re-sending the same `request_id` after a timeout **safe** (deduplicated by you) or does it risk a double submission? | |
| R2.7 | Retry behaviour for callbacks: how many attempts, over what interval, and do retries carry the same bytes? | |
| R2.8 | Accepted `telco` tokens and denomination list (the shop UI currently offers `VIETTEL, VINAPHONE, MOBIFONE, VNMOBI, ZING, VCOIN, GARENA` and 10.000–2.000.000 VND). | |
| R2.9 | Callback timeout/SLA and whether `check` has a rate limit. | |
| R2.10 | Sandbox credentials or a documented way to test without a real card. | |

## R3 — Incoming bank-transfer API document (chat-safe)

Send the document; at minimum it must cover:

```
endpoint + exact hostname + scheme (HTTPS?)       ____________________
method + content-type + required headers          ____________________
credentials (names only — values go in .env)      ____________________
signature/authentication scheme + canonicalization ____________________
what creates a transfer: per-order virtual account / static account we poll / hosted intent
                                                   ____________________
unique transfer reference used for idempotency    ____________________
status model (pending/credited/failed/…)          ____________________
notification: push callback / pull poll / both    ____________________
callback fields + how they are authenticated      ____________________
authoritative reconciliation query                ____________________
amount/fee/partial/min/max rules                  ____________________
retries, replay behaviour, limits, sandbox        ____________________
callback source IP ranges (if any)                ____________________
```

Plus one written line confirming the direction: **incoming deposits/top-ups into the shop**, not
payouts to customers.

## R4 — Schema-only fixture (chat-safe: DDL contains no customer data)

On the deployment host, run the equivalent of:

```
mysqldump --no-data --skip-comments --routines=false --triggers=false \
  -h <host> -u <user> -p <database> \
  users dongtien payment_bank deposit_log aff_log cards banks log_bank_auto settings promotions \
  > schema-only.sql
```

Add `payos_orders` if `scripts/migrate-payos.php` has already been run. Then paste or attach
`schema-only.sql`. **Do not include rows, and do not include `.env`, credentials, or a full dump.**

If you prefer not to run mysqldump, these answers are enough to proceed:

1. Are `users`, `dongtien`, `payment_bank`, `deposit_log`, `aff_log`, `cards`, `banks` all **InnoDB**?
2. Is there a **UNIQUE** key on `dongtien.transid`? (Today it appears not to be unique.)
3. Is there a unique key on `payment_bank` for `(tid, description)` or similar?
4. Have the `DECIMAL(20,2)` conversions from `scripts/migrate-payos.php` been applied to production?

## R5 — Decisions already delegated/approved, restated for the record

* **Routing: approved — Option B (coexist).** payOS stays the default; GachtheFast gets its own flag
  and its own labelled route; the existing 410 guard is not modified.
* **TheSieuRe: preserved.** Nothing in `config.php`, `cron/bank.php`, `cron/bankWeb2m.php`,
  `api/webhook_web2m.php`, or the `banks` rows will be changed.
* **CardVN: withdrawn** (it was Card24h). Nothing will be deleted as a result of that item.

### R5.1 — Still open because only you can answer it (see B11)

Now that CardVN is withdrawn, what should happen to the existing card provider path
(`libs/helper.php:871 card24h()` → `thesieure.com/chargingws/v2`)?

* **(a) Replace** its provider call with GachtheFast card processing (requires settling in-flight
  `cards` rows first — mission rule 10);
* **(b) Keep it and add** GachtheFast card as a second, separately flagged channel; or
* **(c) Leave it untouched** and add nothing to the card surface for now.

---

## What happens after you send these

1. Agent B finalises the contract matrix (no `UNKNOWN` left on any money-affecting field).
2. Agent C closes the design (§2 schema, §6 amount policy) and you approve Gate 1.
3. Agent D implements behind `GTF_CARD_ENABLED=false` / `GTF_BANK_ENABLED=false`.
4. Agent E runs the offline state-machine backtests against a mock (needs a PHP 7.4+ runtime and a
   **disposable** test database — this sandbox has neither, so I will also need either that environment
   or your go-ahead to run the suite on a test host).
5. Agents F/G review and test; Agent H prepares the staged rollout; nothing is enabled until you say so.

## Never send in chat

`partner_key`, `partner_id` if it is treated as a secret, API keys, tokens, checksum keys, database
passwords, `.env` contents, real card PIN/serial values, production data exports, or customer records.
Put secrets in `.env` on the host; email/paste only field names, docs, policies, and schema (DDL).
