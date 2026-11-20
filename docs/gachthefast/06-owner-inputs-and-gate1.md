# Owner inputs of 2026-10-04 (second round) and their effect on the plan

This document records what the owner supplied, what it resolves, what it does **not** resolve, the
resulting design decisions, and the single approval that is now outstanding (Gate 1).

---

## 1. Owner inputs, verbatim effect

| Ref | Owner input | Reading |
|---|---|---|
| R5.1 / B11 | **(a) Replace** the card path with GachtheFast card processing | `card24h()`'s provider call is the thing GachtheFast card replaces. Mission rule 10 therefore applies: settle in-flight `cards` rows before cutting over. |
| R1 / B1 | `https://gachthefast.com/` | The provider **is** reachable over HTTPS on `gachthefast.com`. Combined with the supplied card document's path `/chargingws/v2`, the card base URL becomes `https://gachthefast.com/chargingws/v2`. (The answer ended with a dangling "and", so if a separate sandbox/API host exists it is still to be sent.) |
| R4 / B2 | A bank API snippet titled *"the `gachthefast` API corresponding to `api_check_lich_su_nhantien.php`"* (see §2) | The **incoming bank-transfer** feature is a **pull / receive-history** API on the provider side. This is the first contract evidence for the bank flow. |
| — | *"Please check the files yourself and determine the most optimal solution"* | Technical delegation for the remaining design choices. The money-semantics choices derived from it are in §4 and require one approval (Gate 1, §6). |

## 2. Supplied bank API — contract as documented

```
POST https://{domain}/client/api/wallet/history-receive      # {domain} = the site the wallet belongs to
Content-Type: application/x-www-form-urlencoded

partner_id  = <created in the provider's "API connection" section;
                the same partner_id serves all modules: Softcard, Recharge, Charging>
sign        = md5(partner_key . partner_id)
limit       = 1..1000   (numeric, optional)

SUCCESS  {"status":"success","message":"Data retrieved successfully",
          "data":[{"order_code":"T68A05FAE2EEAB","pay_amount":"66666",
                   "message":"Aiuu is so handsome","created_at":"2025-08-16 17:38:38"}, ...]}
FAILURE  {"status":"100","message":"Invalid signature"}
```

### 2.1 What this resolves

* Endpoint path, HTTP method, content type, request fields, and response schema for **reading received
  transfers**.
* Credential names: `partner_id` (non-secret-ish identifier) and `partner_key` (secret).
* Signature construction: `md5(partner_key . partner_id)` — **static**, no timestamp, no nonce, no
  request binding.
* A stable per-transfer identifier: `order_code` (`T…`). This is usable as the idempotency key.
* `pay_amount` arrives as a **string** ("66666") → must be parsed as a decimal string / integer VND,
  never as a float.
* A transfer carries a free-text `message` (the customer's remittance memo) and a `created_at`.

### 2.2 What it still does NOT resolve (bank flow)

| # | Open item | Why it matters |
|---|---|---|
| Q-B-1 | **Whose wallet is this?** Confirm: does the endpoint return the history of **the shop's own GachtheFast wallet / collection account** (i.e. customers transfer money into a GachtheFast-managed account and we read it back)? Or is it the provider's own account? | This determines whether crediting a user is backed by money the shop actually controls, and how/when the shop withdraws it. Business-model confirmation required — it is not a code detail. |
| Q-B-2 | **Account scoping.** Is the result automatically scoped to our `partner_id`/account, or does it include all accounts? Can it be scoped to a specific bank account/number? | Prevents crediting a transfer that did not land in the shop's account. |
| Q-B-3 | **No time/cursor filter.** Only `limit` (≤1000) is documented. Is the list newest-first? Is there an `offset`/`from`/`to`? | If more than 1000 transfers arrive between two polls, they can be missed. `order_code` dedupe protects against double-credit, but **not** against a missed credit. |
| Q-B-4 | **Rate limits / minimum poll interval** and the provider's expectation for polling frequency. | Determines the cron cadence and whether an "alert on backlog" (rather than fast retry) is required. |
| Q-B-5 | **Fees / net vs gross.** Is `pay_amount` the amount received before provider fees, or net? Are withdrawals charged separately? | Determines the number the shop shows as "received" vs "credited". |
| Q-B-6 | **Whether an `OUT`/withdrawal direction can ever appear in this list**, and what the shop's policy is if it does. | A credit feed that can contain debits is a money bug waiting to happen; the code will ignore anything it cannot classify as an incoming credit and park it for review. |
| Q-B-7 | **Callback/notification alternative.** Is a push callback available for receipts (with its own signature/IP list), or is polling the only supported model? | Polling-only means crediting latency = poll interval; it also means no third party needs to reach the shop. |
| Q-B-8 | The **existing snippet is a read-only listing** — nothing in it *creates* a deposit intent or a per-order reference. Confirm the shop is expected to publish one fixed receiving reference (prefix + user id / per-order token) that customers type into the transfer memo. | This is the reference-matching convention; it decides whether we can generate a per-order token or must keep using a user-id prefix scheme. |

### 2.3 Security properties of the supplied bank contract (recorded for Agent F)

* The request signature is a **constant** for a given `(partner_key, partner_id)` pair. It carries no
  timestamp, nonce, or request body binding, so the header is **replayable indefinitely** by anyone who
  observes it. Mitigations that follow: call the endpoint only from server-side code, always over TLS to
  the pinned host with certificate validation, never expose `partner_id`/`sign` to a browser, never log
  the signature, and treat the endpoint as read-only. Residual risk is disclosure of *our* receive
  history, not a change of state.
* `status` is a **string** in the failure case (`"100"`) and a **word** in the success case
  (`"success"`). Note the collision: `100` means *invalid signature* here while it means *submission
  failure* in the card document. The two modules must not share a status parser.
* `pay_amount` must be treated as an untrusted string: validate `^[0-9]{1,15}$`, then compare as an
  integer; never `floatval`, never `==` against a loose type.

## 3. Card contract — what the owner's answers changed

| Item | Before | Now |
|---|---|---|
| Hostname / scheme (B1) | UNKNOWN (`http://{{domain_post}}`) | **`https://gachthefast.com`** → card base `https://gachthefast.com/chargingws/v2`. Plaintext fallback is excluded by design: the client refuses any non-HTTPS base URL. |
| B11 target | ambiguous | **Replace** `card24h()`'s provider call. Cutover plan required for in-flight `cards` rows. |
| B3 callback authentication | UNKNOWN | still UNKNOWN → handled by design (§4): **callbacks never credit**. |
| B4 `check` authority | UNKNOWN | still UNKNOWN → handled by design (§4): `check` is the **only** thing that can credit, over verified TLS, with strict binding checks; if the response does not match, no credit and a manual-review row is created. |

## 4. Money-semantics decisions taken under the owner's delegation ("most optimal solution")

These are the decisions that let implementation proceed **without** guessing provider semantics. Each
one is fail-safe: it can refuse to credit, it can require a human, but it can never over-credit or lose
an event.

### D1 — A provider callback never credits.

The callback (whose envelope authentication is still unknown, and whose documented signature covers only
`code`+`serial`) is treated as **an untrusted trigger**. On receipt we only: verify the documented
signature, record the event, and schedule verification. No wallet mutation happens on the callback path.

### D2 — Only an HTTPS response to `command=check` can credit a card.

`check` is issued **by us** to the pinned host `https://gachthefast.com` with certificate validation, a
strict timeout, a bounded response, and no redirects. Because the response arrives over a channel we
authenticated, and we additionally require `request_id` to equal our stored `trans_id` byte-for-byte,
the credit decision rests on our own outbound call rather than on anything an attacker can post to us.
If `check` is unavailable or ambiguous → **outcome unknown**: no credit, order stays pending, an alert
is raised and the reconciliation worker retries.

### D3 — Auto-credit only when the outcome is unambiguous.

| Condition | Action |
|---|---|
| card status `1`, and every provider-reported amount field agrees with our stored declared amount, and `check` returns our exact `request_id` | **auto-credit** (declared amount, minus the configured `card_ck` fee) |
| card status `2` (success at a *different* value), or any disagreement between the provider's amount fields, or any amount above our configured maximum | **no auto-credit** → `MANUAL_REVIEW` + admin alert. An operator sees provider status, provider value(s) and the declared amount, and credits explicitly. |
| card status `3` (invalid) | no credit; order terminal; existing per-day invalid-card counter still applies |
| card status `4` (maintenance) | no credit; order stays retryable; card data retained only as long as needed |
| card status `100` (submission failure) | no credit; order terminal unless a later `check` proves acceptance |
| unknown/new status code | **no credit** → `MANUAL_REVIEW` + alert |
| transport timeout on submit | **outcome unknown** → order stays `UNKNOWN`, never marked failed; reconciled by `check` |
| response missing/extra/mistyped fields | no credit → `MANUAL_REVIEW` + alert |

Rationale: status `2` is exactly the case whose field semantics are unknown (B5), so it is never
auto-credited. This removes B5 as an implementation blocker while keeping 100% of the risk on the side
of "a human looks at it" rather than "money was created". The admin value-policy answer (B5) can later
upgrade status `2` to auto-credit if the owner chooses.

### D4 — The bank flow is poll-only and keyed on `order_code`.

* A reconciliation worker (CLI-only, or token-protected — never the legacy unauthenticated
  `cron/bank.php` pattern) calls `history-receive` with `limit=1000` on a fixed interval.
* Every returned row is inserted into `gtf_bank_events` with `UNIQUE(order_code)`. A row already seen
  is skipped; a duplicate can therefore never double-credit.
* A row is credited **only** if: it is an incoming credit; its `message` resolves to **exactly one**
  local pending order reference; the account/method is the expected one; and its amount is a positive
  integer VND within the configured window. Anything else (unknown reference, ambiguous match, amount
  out of window, non-credit direction, malformed amount) is recorded in `MANUAL_REVIEW` with the
  provider row preserved, and the operator is alerted.
* Auto-credit of a bank row happens inside the same single transaction pattern as payOS
  (`03-design-proposal.md` §5), with `UNIQUE(dongtien.transid = 'gtf_bank:' + order_code)`.
* Because only `limit` is documented (Q-B-3), the worker alarms when it ever sees a full page of
  unseen rows older than the last poll, since that is the only signal available that events may have
  been skipped.

### D5 — Nothing is enabled until the provider questions are answered.

`GTF_CARD_ENABLED=false`, `GTF_BANK_ENABLED=false` ship as the only possible defaults (mission rule 9).
Because D1/D2/D3 make the card flow credit only from our own verified `check`, the card flow is the
lower-risk of the two, but it still stays disabled until: the card callback field list / `check`
semantics are confirmed (B3/B4) and a sandbox or supervised minimum-value test is authorised (B10).
The bank flow stays disabled until Q-B-1…Q-B-8 are answered — most importantly Q-B-1 (whose wallet the
receive-history belongs to).

## 5. Revised blocker status

| Item | Status |
|---|---|
| B1 hostname/HTTPS | **CLOSED** — `https://gachthefast.com` (sandbox host, if any, still unknown) |
| B2 bank contract | **PARTIALLY RESOLVED** — read/poll contract documented (§2); Q-B-1…Q-B-8 open. Blocks *enablement*, not design or coding. |
| B3 callback auth | **MITIGATED BY DESIGN** (D1) — still wanted for defence in depth and for `check` corroboration |
| B4 `check` authority | **MITIGATED BY DESIGN** (D2) — still wanted; if `check` proves unsigned/unreliable, auto-credit must be turned off and everything goes to manual review |
| B5 amount policy | **MITIGATED BY DESIGN** (D3) — auto-credit restricted to unambiguous cases; status `2` → human |
| B6 CardVN | **CLOSED** — premise withdrawn |
| B7 TheSieuRe scope | **CLOSED** — preserve all legacy bank paths unchanged |
| B8 routing | **CLOSED** — Option B approved |
| B9 schema fixture | **OPEN** — still no DDL; the migration will therefore create its own new InnoDB tables and *verify* (not assume) the legacy tables' engine/index state before touching them |
| B10 sandbox | **OPEN** — blocks Phase 3/5 execution, not coding |
| B11 `card24h()` fate | **CLOSED** — replace it (with an in-flight `cards` cutover) |

## 6. Gate 1 — the single approval now outstanding

Before code, the mission requires the owner to approve the design and confirm the money-moving
semantics. Please confirm all four:

1. **Routing** — Option B: payOS stays the default; GachtheFast card and bank are separate methods,
   each behind its own flag, both `false` by default; the existing 410 guard is untouched.
2. **Card** — GachtheFast card *replaces* `card24h()`'s provider call, with in-flight `cards` rows
   settled first; a callback never credits; only a TLS-verified `command=check` matching our exact
   `trans_id` may credit; status `2`, unknown statuses, amount disagreements and timeouts go to
   manual review.
3. **Bank** — poll-based `history-receive`, deduplicated on `order_code`, credited only for an exact
   single local reference match with a valid in-window incoming amount; everything else to manual
   review; and confirmation of **Q-B-1** (the wallet whose history we read is the shop's own
   collection account).
4. **Scope of this phase** — implement both flows **disabled**, add the migration and rollback script,
   and stop before any live crediting; testing stays offline/local (a PHP runtime + disposable database
   is still needed to actually execute the test suite).

---

## 7. Phase 2 additions — Gate 1 approved, code written, questions raised by the code

Gate 1 was approved (both flows implemented **disabled**, migration + rollback script, no live
crediting, no deployment). The implementation is written and unexecuted; `07-implementation-notes.md`
lists every file and every assumption. Two follow-ups belong here.

### 7.1 Provider questions raised while implementing (N1–N7)

Full text with code behaviour in `02-blockers.md`. **Updated 2026-10-04 after the card partner API
documentation arrived:** **N5** (the `check` signature and its full field set) and **N6** (the card
status set) are **RESOLVED** and the code now sends the documented `check` call. Still open:
**N1** pagination/cursor beyond `limit`; **N2** whether `pay_amount` can ever be fractional or
decorated; **N3** whose wallet `history-receive` returns (this is **Q-B-1** — the owner's earlier
reply pasted unrelated payOS documentation, so it is still unanswered); **N4** whether `message`
can hold more than one `GTF########` reference; **N6 remainder** whether card responses carry
`currency`/a provider reference and what `check` returns for an unknown `request_id`; **N7** how
deep the provider's receipt history goes, which sets the required polling cadence. Newly added:
the HTTPS host that serves `/chargingws/v2` (the document writes `http://`; the integration is
HTTPS-only).

### 7.2 Sequencing change to the approved card cutover (needs owner awareness)

The approved card decision is **(a) replace** `card24h()`'s provider call with GachtheFast. Phase 2
implements the GachtheFast card path as a **separate, disabled channel** and deliberately leaves
`card24h()` untouched. Reason: re-pointing a live money path while the new provider contract is
still unverified would put real customer top-ups on a path that has never been executed once.
Proposed cutover sequence once enablement is approved:

1. run `scripts/migrate-gtf.php` on the backed-up database;
2. verify the card channel against the provider's sandbox or a minimum-value live card
   (B10 / Phase 5 authorisation);
3. enable `GTF_CARD_ENABLED=true` and announce the new method;
4. only then re-point `card24h()` — or better, retire the legacy card AJAX (`nap_the`) and route the
   existing card page to the new flow — after confirming no `cards`/`card_orders` rows are in flight
   (`pending`/`processing` states) or by leaving the legacy path read-only until they settle;
5. keep the legacy code in place (not deleted) for at least one full settlement cycle.

### 7.3 Routing follow-up (Q-R-3)

`views/client/recharge-bank.php:5` renders the payOS page unconditionally, even when payOS is
disabled, so the legacy bank UI is unreachable today. Please confirm how the new GachtheFast bank
page should be surfaced: (a) a separate sidebar/nav method next to payOS (implemented), (b) a
fallback link inside the payOS page when payOS is off, or (c) the legacy `?action=recharge-bank`
route when payOS is off (this one changes payOS-era routing and needs explicit approval).

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
