# Gate 0 — Blocker register (owner decisions required)

**Latest status: Gate 1 was approved and Phase 2 code exists — see the "Phase 2 update" section
near the end of this file for the current state of every item.** Nothing has been guessed; the
items still marked BLOCKED block *enablement*, not the writing of disabled code.

Original Gate 0 summary follows.

---

## Gate 0 update — owner responses received 2026-10-04

The owner answered four questions. Net effect: two blockers resolved, one delegated, one narrowed, one
converted into a new question, and the provider/contract items remain open.

| Item | Owner response | New status |
|---|---|---|
| **B6** CardVN target | *"I mistook it for card24h."* | **PREMISE WITHDRAWN** — the request was based on a name confusion, and there is no `CardVN` code to remove. **New question B11** below: what happens to the `card24h()`/TheSieuRe **card** path now that GachtheFast card processing is being added? Until B11 is answered, **nothing is deleted**. |
| **B7** TheSieuRe scope | *"You can adjust and fix everything yourself…"* (delegated) | **CLOSED by conservative delegation.** Decision recorded: **preserve every existing bank path byte-identical** — `config.php:4` label, `cron/bank.php`, `cron/bankWeb2m.php`, `api/webhook_web2m.php`, `banks` rows, `log_bank_auto`. The new bank flow gets its own flag and settings keys so it cannot shadow them. Rationale: "preserving existing payment behavior takes priority over speed"; if the owner later wants a legacy bank path retired, that is a separate, explicit cutover. |
| **B8** Routing matrix | **Option B — coexist** (selected) | **RESOLVED / APPROVED.** payOS remains the default; GachtheFast becomes a separate, explicitly labelled method behind its own flag; the 14-point 410 guard is left untouched. Design `03-design-proposal.md` §8 Option B is now the approved target. |
| **B2** Bank contract | *"I have the bank API doc"* (will provide) | **STILL BLOCKED — awaiting the actual document.** A statement of possession is not a contract. No bank code may be written until the document arrives (endpoint, HTTPS host, auth/signature, callback fields, unique reference, status set, idempotency, limits). |
| **B1** Hostname + HTTPS | not answered (folded into the bank-doc question) | **STILL BLOCKED** — see B11/Q2. |
| **B3/B4/B5/B9/B10** | not answered | **STILL BLOCKED** (unchanged, see below). |

### B11 (new) — fate of the `card24h()` / TheSieuRe card path

* **Question.** Now that the CardVN premise is withdrawn, does GachtheFast card processing **replace**
  the provider call inside `card24h()` (`libs/helper.php:871-880` → `thesieure.com/chargingws/v2`),
  or does `card24h()` remain intact as a **parallel** channel alongside GachtheFast?
* **Why it blocks.** This single decision determines the whole card implementation surface: whether we
  add a new adapter next to the existing one, or cut the existing one over (which then also requires an
  in-flight `cards` cutover plan, per mission rule 10 — no pending order may be silently discarded).
  Deleting or re-pointing the wrong path moves real money routes.
* **Unblocks with.** One of: *(a)* replace the `card24h()` provider call with GachtheFast; *(b)* keep
  `card24h()` and add GachtheFast card as a second, separately flagged channel; *(c)* leave `card24h()`
  untouched for now and add GachtheFast card only later.

### Explicitly NOT resolved by "fix everything yourself"

The owner's delegation covers *product/configuration choices* (which bank paths stay, how channels are
labelled and routed). It does **not** and cannot supply provider facts. These remain hard blockers
because no amount of owner delegation can invent them without risking wrong credits:

* the production hostname and whether HTTPS covers API **and** callbacks (B1);
* how bank-transfer events are authenticated and uniquely identified (B2);
* the card callback's authentication beyond `code`+`serial` (B3);
* whether `command=check` is authoritative and how its response is authenticated (B4);
* which value is credited for card status `2` and the under/over-payment policy (B5);
* the schema-only fixture, engines and index state (B9);
* sandbox/test capability (B10).

Each item states: the question, why it blocks, what we already checked, and exactly what answer
unblocks it.

---

## B1 — GachtheFast production hostname and HTTPS support (card + bank)

* **Question.** What is the exact production API hostname for GachtheFast, and does it support HTTPS
  for **both** API calls and inbound callbacks?
* **Why it blocks.** The supplied card document uses `http://{{domain_post}}`. Our rules forbid sending
  card PINs/credentials over plaintext and forbid certificate-bypass. Without a confirmed HTTPS
  hostname and a confirmed certificate identity there is no compliant transport to write.
* **Checked.** `gachthefast.com` serves HTTPS publicly; its merchant API page
  (`/merchant/list`) redirects to a login. No API hostname is published.
* **Unblocks with.** A literal HTTPS base URL (or a written statement that only `http://` exists, which
  would mean the feature cannot be built as specified), plus confirmation that the certificate chain is
  publicly trusted.

## B2 — Incoming bank-transfer contract (entire feature)

* **Question.** How is the bank-transfer event authenticated? What creates or identifies a transfer,
  how is its status checked, and what unique transaction/reference prevents duplicate credit?
* **Why it blocks.** No bank document was supplied at all. The three plausible provider models
  (per-order virtual account we mint, static shop account polled for credits, provider-hosted deposit
  intent with return URL) imply **different tables, different state machines, and different
  reconciliation**. Implementing without this is inventing money semantics.
* **Checked.** Repo has no GachtheFast code/config. Public docs cover only a customer depositing *into*
  GachtheFast. The brief already states the incoming contract is not provided.
* **Unblocks with.** The bank-transfer section of the partner API documentation (endpoint, auth,
  create/query, callback fields + signature, idempotency key, status set, amount/fee rules), plus a
  written confirmation that the intended direction is **incoming deposits/top-ups to the shop**, not
  payouts.

## B3 — Card callback authentication beyond `code` + `serial`

* **Question.** Are callback fields beyond `code` and `serial` authenticated? Is there a
  provider-published callback IP range or another verification method?
* **Why it blocks.** The documented signature covers only `code`+`serial`. Under that scheme the
  money-affecting fields (`status`, `value`, `declared_value`, `amount`, `request_id`, `trans_id`,
  `telco`) are **unauthenticated**, so a forged callback cannot be distinguished from a real one by
  signature alone. Either the provider authenticates the rest, or we must rely on source-IP allow-list
  plus out-of-band verification via `command=check` — and neither is documented.
* **Unblocks with.** Either (a) the provider's full callback field list + the exact signed string for
  callbacks (ideally including `request_id`/`amount`/`status`), or (b) a published, stable callback IP
  range **and** confirmation that `check` is the authoritative verification (see B4).

## B4 — Authoritativeness of `command=check`

* **Question.** Is `command=check` an authoritative reconciliation method over HTTPS, and how is its
  response authenticated?
* **Why it blocks.** Rule 5 requires an ambiguous timeout to be reconciled by a *documented
  authoritative mechanism* before retry or credit. If `check` responses are unsigned and untrusted,
  the only safe outcome on timeout is "unknown, needs human review", which changes the operational
  design (queue + alerting instead of automatic reconciliation).
* **Unblocks with.** Written confirmation of `check`'s authority, its HTTPS endpoint, its response
  schema, and whether/how the response is signed or bound to `request_id`.

## B5 — Card value semantics for status `2` and partial/over/under payment

* **Questions.** For status `2`, and where `amount`, `value` and `declared_value` differ, which value
  is credited? What is the shop policy for underpayment/overpayment (including fees/commission
  `settings.card_ck`)?
* **Why it blocks.** This is the exact number that moves into `users.money` and `dongtien`. Crediting
  the wrong field is a silent money bug. The existing legacy code has *two different* rules in two
  places (`api/callback_card.php:43-47` applies `card_ck`; `:44` credits `amount` when the fee is 0)
  and the new contract may not match either.
* **Unblocks with.** A one-line policy per case, signed off by the owner, expressed as the field to
  credit plus the fee rule.

## B6 — Location and scope of the CardVN implementation

* **Question.** Where is the actual CardVN implementation in the deployed source? Is the
  `card24h()`/TheSieuRe card path in this checkout the code the owner intends to replace?
* **Why it blocks.** There is **no `cardvn` token anywhere** in the tree or in git history. The only
  card path is `libs/helper.php:871 card24h()` → `thesieure.com/chargingws/v2`. Deleting it could
  destroy the very path the owner asked to keep, and there is no alternative candidate to delete.
  Removal cannot be "confirmed" from the workspace copy.
* **Unblocks with.** Either a file listing of the deployed tree showing the CardVN files, or a written
  statement that the checkout equals the deployed source and that `card24h()`'s provider call is what
  "CardVN" refers to (and, if so, whether that card route — not just the bank label — is to be
  replaced by GachtheFast).

## B7 — Exactly which TheSieuRe bank route/configuration must remain

* **Question.** Which exact TheSieuRe bank route/configuration must remain?
* **Why it blocks.** `THESIEURE` exists only as a **display label** (`config.php:4`, consumed as
  datalist options in `views/admin/recharge-bank-config.php:141` and `views/admin/recharge-bank-edit.php:80`).
  The actual legacy bank processing is `cron/bank.php` (host `api.sieuthicode.net`, per-`banks`-row
  token, bank-specific branches) and `cron/bankWeb2m.php` + `api/webhook_web2m.php`
  (host `api.web2m.com`). "Keep TheSieuRe" could mean keep the label, keep the sieuthicode cron, keep
  the web2m pair, or keep the ability to add `short_name='THESIEURE'` rows.
* **Unblocks with.** A named list: which cron files / webhook endpoints / `banks` rows / settings keys
  must survive untouched, and which may be retired.

## B8 — Routing matrix when payOS is enabled

* **Question.** Should GachtheFast card and bank methods work when payOS is enabled, or should payOS
  continue to replace legacy top-up routes?
* **Why it blocks.** Today `PAYOS_ENABLED=true` hard-stops every legacy money entry point with **410**
  (`libs/legacy_payment_guard.php:8`, 14 entry points asserted by `tests/legacy_payment_gate_test.php`),
  and `views/client/recharge-bank.php:5` unconditionally renders the payOS view, so even with payOS
  **disabled** there is no route back to a legacy bank page. GachtheFast cannot be slotted in without
  an explicit decision, and the guard must not be weakened implicitly.
* **Unblocks with.** A choice from the three options in `03-design-proposal.md` §8 (A: mutually
  exclusive with payOS, B: coexisting channels with payOS as default, C: GachtheFast replaces the
  legacy top-up surface the same way payOS did).

## B9 — Schema, indexes, and storage engines for the money tables

* **Question.** What are the actual table schemas, indexes, and storage engines for card, bank, wallet,
  and ledger records?
* **Why it blocks.** Rule 4 requires an atomic transaction across payment state, wallet, and ledger,
  which is only sound on transactional (InnoDB) tables with known keys. The checkout contains **no
  SQL**: `*.sql` is gitignored (`.gitignore:9`) and `shopclone7.sql` (referenced in
  `CHANGE_MANIFEST.txt`) is absent. `scripts/migrate-payos.php:26-31` *converts* five tables to InnoDB
  and `DECIMAL(20,2)`, but whether that migration has actually been applied to production is unknown,
  as is the index layout of `cards`, `payment_bank`, `log_bank_auto`, `banks`.
* **Unblocks with.** A **schema-only** export (DDL + indexes + ENGINE, no rows):
  `users, dongtien, payment_bank, deposit_log, aff_log, cards, banks, log_bank_auto, settings,
  promotions, payos_orders`. Do **not** send production rows or credentials.

## B10 — Sandbox / test capability (Phase 3 & 5 prerequisite)

* **Question.** Is there a sandbox endpoint, a test credential set, or written permission to run a
  minimum-value production test?
* **Why it blocks.** Phase 3 requires a deterministic mocked provider (we can build that locally), but
  Phase 5 requires *some* authorised environment. Without a sandbox, no external verification of the
  contract is possible and the provider contract stays "as documented" rather than "as observed".
* **Unblocks with.** Sandbox host + test credentials delivered out-of-band (never in chat/Git), or an
  explicit decision to stay on a local mock and accept "documented-only" verification.

---

## Phase 2 update — Gate 1 approved, implementation written but never executed

Gate 1 was approved with the explicit scope *"implement both flows disabled, migration + rollback
script, no live crediting, no deployment"*. The code now exists (`07-implementation-notes.md`
lists every file) and **no legacy payment file has been modified**. Blockers were re-evaluated
against what the code actually needs:

| Item | Status after Phase 2 |
|---|---|
| **B1** hostname + HTTPS | **RESOLVED** — owner supplied `https://gachthefast.com`; `GTF_API_BASE` accepts only that host and its subdomains, HTTPS-only. |
| **B2** bank contract | **PARTIALLY RESOLVED.** The owner supplied `POST /client/api/wallet/history-receive` (`partner_id`, `sign = md5(partner_key.partner_id)`, `limit`, `status: "success"`, `data[].{order_code,pay_amount,message,created_at}`). Fields not supplied are now enumerated as N1–N7 below, and the receiving-account question is still unanswered. |
| **B3** callback authentication | **NARROWED, still open.** Decision D2 removes the risk from the code side: a callback can never credit, so the unsigned fields cannot move money even if forged. Provider confirmation that no *additional* verification exists (e.g. IP range) is still wanted for accounting/replay purposes. |
| **B4** `command=check` authority | **NARROWED, still open.** D2 makes `check` the only credit path and requires exact binding to our `request_id`; whether `check` itself returns a signed response is still undocumented (see N5/N6). |
| **B5** card value semantics | **RESOLVED by D3** (owner-approved): credit only when `value == amount == declared_value == declared_amount` and positive; status `2`, conflicts, malformed amounts → manual review. |
| **B6** CardVN | **PREMISE WITHDRAWN** (owner: a mix-up with card24h). |
| **B7** TheSieuRe scope | **CLOSED** by conservative delegation — every legacy bank path stays byte-identical. |
| **B8** routing | **RESOLVED / APPROVED** — Option B (coexist; payOS stays default; the 410 guard is untouched). |
| **B9** schema/indexes/engines | **STILL BLOCKED.** The migration verifies InnoDB and reports index state, but it has never been run. The Phase 3 suite builds its own fixture schema, which does **not** prove the production schema. |
| **B10** sandbox | **STILL BLOCKED.** No authorised sandbox, no test credentials; verification stays "as documented". |
| **B11** `card24h()` fate | **RESOLVED — replace (option a)**, but **sequenced**: the replacement is deliberately *not* executed in this phase. Re-pointing a live money path while the new provider is still unverified would put real top-ups on the new path before any test run. The cutover is staged behind enablement (see `03-design-proposal.md` §7 and the cutover plan draft). |

### N1–N7 — provider questions raised by the implementation

These do not block *code being written* (everything is disabled and defensive), but they must be
answered before either flag is turned on.

* **N1 — pagination.** Does `history-receive` return at most `limit` rows, and are they the newest
  rows? Is there a cursor/`before`/`page` parameter, or an item-level unique id beyond
  `order_code`? The current code asks for the newest `limit` rows and relies on
  `UNIQUE order_code` for dedupe, so it cannot see back further than one page.
* **N2 — amount format.** Can `pay_amount` ever be fractional, empty, or carry a decorator
  (thousands separators, currency suffix)? *Code behaviour today:* anything not matching
  `^[0-9]{1,15}$` is stored with `pay_amount = 0`, the raw string preserved and status `REVIEW`
  (never digit-stripped).
* **N3 — whose wallet?** (Q-B-1, still unanswered.) Does `history-receive` return receipts for the
  wallet identified by `partner_id` — i.e. the shop's own GachtheFast wallet — or for a different
  account? If it is not our wallet, the whole polling design must change.
* **N4 — memo content.** Can `message` contain more than one `GTF########` reference, or a
  reference that does not belong to us? The code auto-credits only on exactly one match inside the
  configured amount window, and parks everything else.
* **N5 — `check` signature. RESOLVED 2026-10-04.** The provider's card partner API documents the
  `command=check` example with the full field set (telco, code, serial, amount, request_id,
  partner_id, sign, command) and the same signature formula `md5(partner_key + code + serial)`; the
  code now sends exactly that. Only the HTTPS host remains to be confirmed.
* **N6 — statuses. RESOLVED 2026-10-04.** The documented card status set is exactly
  `1,2,3,4,99,100` and matches the implemented decision table (`1` credits, `2` manual review,
  `3` card error, `4` maintenance, `99` pending, `100` submission failed). Still open: whether the
  response carries `currency` and a provider reference, and what `check` returns for an unknown
  `request_id`.
* **N7 — retention depth.** How long does the provider keep the receipt history? That sets the
  maximum downtime the polling worker may have before a top-up is invisible to us, and therefore
  how often the worker must run.

### Additional routing question raised by Phase 2 (Q-R-3)

`views/client/recharge-bank.php:5` unconditionally renders the payOS page, so with payOS
**disabled** there is still no route back to a legacy bank page. GachtheFast's bank page is a new,
separate route, but the owner should confirm where it should be linked from:

* (a) linked from the sidebar/nav as a separate method next to payOS (what the code does now);
* (b) reached from within the payOS page as a fallback link when payOS is disabled;
* (c) registered under the legacy `?action=recharge-bank` route when payOS is off (this changes
  payOS-era behaviour and needs the owner's explicit approval).

---

## Items that are **not** blocked

| Item | Status |
|---|---|
| Repository/provider inventory (Agent A) | **DONE** — `00-inventory.md` |
| Contract matrix for the supplied card document (Agent B) | **DONE** — `01-contract-matrix.md`, with all gaps marked UNKNOWN |
| payOS behaviour as it exists today | **ESTABLISHED** — see `00-inventory.md` §3; no changes proposed until B8 is answered |
| Design proposal for Gate 1 review (Agent C) | **PREPARED** — `03-design-proposal.md`, parameterised on B1–B10 |
| QA / security / pentest / release (Phases 3–6) | **NOT STARTED** — gated on Gate 1; cannot be meaningfully performed with no implementation and no runtime in this sandbox |

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
