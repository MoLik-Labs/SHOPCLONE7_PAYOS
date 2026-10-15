# 08 — Stop-and-verify review, corrected status, and consolidated owner questionnaire

**Date:** 2026-10-04 · **Branch:** `arena/01a1066c-shopclone7-payos` · **Base commit:** `e80be783`
**Nature of this document:** read-only verification + owner input request. **No code was changed to
produce it.** It supersedes any earlier wording that described the GachtheFast work as "implemented",
"tested" or "code-complete".

---

## 1. Workspace evidence

### 1.1 What the checkout actually contains

The owner's brief states that this checkout is clean and that `docs/gachthefast/` is absent. **That is
not what this checkout shows**, so the evidence is recorded verbatim.

```
$ git branch --show-current
arena/01a1066c-shopclone7-payos          # note: not arena/01a10643-... as stated in the brief

$ git rev-parse HEAD
e80be783e8bbf5ba3db9e0b4c99b7617f8ed3283   ("FIRST")

$ git worktree list
/home/user/SHOPCLONE7_PAYOS  e80be783 [arena/01a1066c-shopclone7-payos]   (single worktree)
$ git stash list
(empty)

$ git status --short
 M .env.example
 M views/admin/sidebar.php
 M views/client/nav.php
?? ajaxs/admin/gtf.php
?? ajaxs/client/gtf.php
?? api/callback-gtf-card.php
?? cron/gtf-bank.php
?? cron/gtf-card.php
?? docs/
?? libs/gtf.php
?? libs/gtf_bank.php
?? libs/gtf_bootstrap.php
?? libs/gtf_card.php
?? libs/gtf_client.php
?? scripts/migrate-gtf.php
?? tests/gtf_rules_test.php
?? tests/gtf_service_test.php
?? views/admin/recharge-gtf.php
?? views/client/recharge-gtf-bank.php
?? views/client/recharge-gtf-card.php

$ git diff --stat
 .env.example            | 40 ++++++++++++++++++++++++++++++++++++++++
 views/admin/sidebar.php |  6 ++++--
 views/client/nav.php    |  7 +++++++
 3 files changed, 51 insertions(+), 2 deletions(-)
```

`docs/gachthefast/` exists here and holds 8 files (see §1.3). The branch name in the brief
(`arena/01a10643-shopclone7-payos`) differs from the branch this session is pinned to
(`arena/01a1066c-shopclone7-payos`); work cannot be moved to another branch. If the review was
performed somewhere else (another sandbox, another snapshot, or a fresh clone), that environment is a
different checkout from this one — the paths and fingerprints below identify exactly where these
changes live.

### 1.2 Changed / new files, with fingerprints (sha1, taken during this review)

New application files (16):

| Path | sha1 |
|---|---|
| `libs/gtf.php` | `2409d1e6f35914674844a9b161639d4068ececf2` |
| `libs/gtf_client.php` | `493efe3090ee29dea21739f039de4be7d875c4d5` |
| `libs/gtf_card.php` | `abb5f6f305fd336b56ef2cb6cb32844897a5f413` |
| `libs/gtf_bank.php` | `3614763c0ed4d1f06afce5e6da78dd5a4e8162f0` |
| `libs/gtf_bootstrap.php` | `8ec0151571d3c3168fb1a4abcca8b137fd3aa954` |
| `api/callback-gtf-card.php` | `7fec65e00893d509ca4f3913f45947027dfb3a66` |
| `ajaxs/client/gtf.php` | `6b5d9f75d7ee593f00580d8cff411e30db7e3b95` |
| `ajaxs/admin/gtf.php` | `b9736b5316744efea4c6fc530bf29f9399de75ed` |
| `cron/gtf-card.php` | `7fba3fce0619e591ec38a75bb93c2af180635578` |
| `cron/gtf-bank.php` | `bd8259c8224176906de57a0fe322214a9b032f0c` |
| `scripts/migrate-gtf.php` | `ee24c5d015be4f6997972ec99329d82be89c37ae` |
| `views/admin/recharge-gtf.php` | `1b30a00a120b6aca8169c8c91209c13f8de9484d` |
| `views/client/recharge-gtf-card.php` | `022ba554371455169fc8a2f338550671d8d217d3` |
| `views/client/recharge-gtf-bank.php` | `5a022412028c306785782a38bf04debfc9110b3e` |
| `tests/gtf_rules_test.php` | `5d8f620adc4ce72406a4d82ed3133614df8d55fa` |
| `tests/gtf_service_test.php` | `75acf5db4ac3135c154a9d725642d2d23ffb9b4f` |

Modified tracked files (3, all additive except two re-emitted sidebar lines):

| Path | Change |
|---|---|
| `.env.example` | +40 lines: the `GTF_*` block; both flags `false`, credentials blank |
| `views/client/nav.php` | +7 lines: two flag-guarded nav links |
| `views/admin/sidebar.php` | +5/−2: one extra menu item, plus the label `"recharge-gtf"` inserted into the two existing sidebar highlight arrays (the −2 are those same two lines re-emitted, not removed functionality) |

No file under `libs/helper.php`, `api/callback_card.php`, `api/callback_*.php`,
`cron/bank*.php`, `cron/momo.php`, `api/webhook_web2m.php`, `libs/payos*.php`,
`libs/legacy_payment_guard.php`, `ajaxs/client/create.php`, `ajaxs/client/update.php` or
`views/client/recharge-*.php` was modified. No legacy payment file changed at all.

### 1.3 Documentation files present

`docs/gachthefast/` — 8 files, all written in this session, none web-reachable (`.htaccess` blocks `docs/`):

| File | sha1 |
|---|---|
| `00-inventory.md` | `7531f23a7f146bf9c5d0df0cfbb6aaff5add1a5d` |
| `01-contract-matrix.md` | `fabab5ff15a949f716c462b509e0311d314cdc59` |
| `02-blockers.md` | `8e1a6677ced4825c40e6924b8b44c592781e7717` |
| `03-design-proposal.md` | `26b395abbb000165f243662a3a76bd565be07cc8` |
| `04-lead-report.md` | `e6cd2cbc24a2022f5db7887458cbbedcf1818597` |
| `05-owner-request-pack.md` | `3dcaf96e771bbcf8747574bcc3680c5f95022562` |
| `06-owner-inputs-and-gate1.md` | `fe980c9f41f1c35fb83f66be178ac1fe6834224e` |
| `07-implementation-notes.md` | `1108df8541b90fdad363503cda78ceac4af12322` |

**Path to the lead report: `docs/gachthefast/04-lead-report.md`** (currently records the decision as
`NO-GO` for enablement/deployment). After this review it carries a correction notice pointing here.

### 1.4 Exact test commands and their actual output

| # | Command | Actual output |
|---|---|---|
| 1 | `which php php8 php7` | `php: NOT PRESENT` |
| 2 | `php -v` | `/bin/bash: line 1: php: command not found` |
| 3 | `which mysql mysqld mariadb` | not present |
| 4 | `ls -d vendor` | `vendor/: ABSENT` |
| 5 | inline PHP-delimiter heuristic over the 18 new/modified PHP files (single-quote/double-quote/comment/heredoc-aware brace-balance scan, run in this review) | `balance-check: 18/18 files balanced (heuristic, not a PHP linter)` |
| 6 | `sha1sum` over the new files and the docs (above) | printed in §1.2/§1.3 |

**No test was executed. No `php -l` lint ran. No migration ran. No provider call was made.**
`tests/gtf_rules_test.php` and `tests/gtf_service_test.php` exist but have **never been executed**
anywhere: there is no PHP runtime, no MySQL/MariaDB and no `vendor/` in this environment. The
heuristic in row 5 only proves that quotes/braces are balanced — it is not a parser, a linter, or a
test, and it must not be reported as verification.

### 1.5 Correction of earlier wording

Earlier status messages in this session said the GachtheFast work was "code complete" / "complete".
The accurate statement is: **written into this checkout, never executed, never linted by PHP, never
tested, disabled by configuration, and not committed.** Nothing has been deployed or enabled.
Likewise the earlier "N5/N6 resolved" wording (in `02-blockers.md`, `06-*.md`, `07-*.md`) overstated
the provider documentation — see §3.3; those files now carry a correction notice.

---

## 2. Repository / provider map (verified by reading source in this checkout)

### 2.1 Flows and files

| Flow | Implementation | Outbound target (as coded) | Behaviour with `PAYOS_ENABLED` |
|---|---|---|---|
| **Legacy card (thesis: "CardVN")** | `ajaxs/client/create.php:91-140` (`action=nap_the`) → `libs/helper.php:871-880` `card24h()`; page `views/client/recharge-card.php`; callback `api/callback_card.php` | `base64_decode("aHR0cHM6Ly90aGVzaWV1cmUuY29tL2NoYXJnaW5nd3Mvc2lnbj0=")` → **`https://thesieure.com/chargingws/v2?sign=`**, `md5(partner_key . pin . serial)` | ON: `create.php:8` rejects `nap_the` with **410**; `recharge-card.php:7-10` renders the payOS page. OFF: the legacy card page and AJAX run |
| **Legacy bank — SIÊU THỊ CODE** | `cron/bank.php` (8 bank-specific branches) reading `banks` rows | `https://api.sieuthicode.net/...` (8 paths, token per row) | ON: `cron/bank.php:5` → 410. OFF: polling runs |
| **Legacy bank — WEB2M** | `cron/bankWeb2m.php` (8 branches) + `api/webhook_web2m.php` | `https://api.web2m.com/...` | ON: both files stop with 410 (`cron/bankWeb2m.php:6`, `api/webhook_web2m.php:6`). OFF: polling + webhook run |
| **Legacy wallet — MoMo** | `cron/momo.php` | `https://api.web2m.com/historyapimomo1h/...` | ON: 410. OFF: runs |
| **TheSieuRe (bank option)** | `config.php:4` `$config_listbank = ["THESIEURE" => "Ví THESIEURE.COM", ...]` consumed as datalist labels in the admin bank forms | none (label only) | unaffected by payOS |
| **payOS** | `libs/payos.php`, `libs/payos_client.php` (host `https://api-merchant.payos.vn`, `/v2/payment-requests`, `verifyWebhook`), `api/webhook-payos.php`, `scripts/migrate-payos.php`, admin/client views | `api-merchant.payos.vn` | This **is** the ON state; the 410 guard exists so it replaces the legacy surface |
| **payOS payouts** | **not present** — `grep -rni payout` over `libs/ api/ ajaxs/ views/ cron/` returns nothing | — | — |
| **GachtheFast (new, this session)** | `libs/gtf.php`, `libs/gtf_client.php`, `libs/gtf_card.php`, `libs/gtf_bank.php`, `libs/gtf_bootstrap.php`, `api/callback-gtf-card.php`, `ajaxs/client/gtf.php`, `ajaxs/admin/gtf.php`, `cron/gtf-card.php`, `cron/gtf-bank.php`, `scripts/migrate-gtf.php`, 3 views, 2 tests | `GTF_API_BASE` (default `https://gachthefast.com`), host-allowlisted, HTTPS-only | **Independent of payOS**: no GTF file calls payOS or the legacy guard; the only mention of payOS in the new code is a comment in `libs/gtf_bootstrap.php:5`. Both flags default `false` |

### 2.2 Feature-flag behaviour (as coded, not executed)

* `PAYOS_ENABLED=true` → the 410 guard `libs/legacy_payment_guard.php:8-14` is honoured at **11 direct
  call sites**, and `tests/legacy_payment_gate_test.php:17-31` asserts **14 entry-point/action pairs**
  (the extra three being `api/callback_toyyibpay.php`'s inline 410, and the two `ajaxs/client/update.php`
  actions). GachtheFast does not touch this chain.
* `GTF_CARD_ENABLED=false` / `GTF_BANK_ENABLED=false` (defaults, and the only values in `.env.example`)
  → customer pages show "not available", nav links are not rendered, bank worker exits without polling,
  and both services refuse to start a flow.
* `views/client/recharge-bank.php:5` still renders the payOS page unconditionally — a pre-existing
  behaviour, deliberately not changed.

### 2.3 Confirmed source facts vs deployment assumptions

**Confirmed by reading this checkout:** every path and line above; absence of any `cardvn` token in
the working tree **and in every blob of `git rev-list --all`**; absence of payOS payout code; the
exact provider hosts in the legacy files.

**Not confirmed (deployment assumptions, must not be stated as fact):** what the *deployed* server
actually runs (this checkout may not equal production); the production database schema, engines and
keys; whether `scripts/migrate-payos.php` was ever applied; whether production uses this branch, an
older commit, or modified files; whether the live `TheSieuRe`/`Card24h` contract is the one hard-coded
here (the URL is base64-obfuscated precisely so it cannot be audited by text search).

---

## 3. Documentation matrix — confirmed vs unknown

### 3.1 payOS material (supplied by the owner)

| Item | Status |
|---|---|
| Production host `https://api-merchant.payos.vn`; auth headers `x-client-id` + `x-api-key`; `429` on rate limit | **CONFIRMED** |
| Payment link: `POST /v2/payment-requests`, `GET /v2/payment-requests/{id}`, cancel, invoices, download; signature `HMAC_SHA256` over fields sorted alphabetically (`amount`, `cancelUrl`, `description`, `orderCode`, `returnUrl`) | **CONFIRMED** |
| Payment webhook payload + `signature` field; `confirm-webhook` endpoint | **CONFIRMED** |
| Payout module: `POST /v1/payouts`, batch, list, `GET /v1/payouts/{id}`, `estimate-credit`, `GET /v1/payouts-account/balance`, headers `x-idempotency-key` + `x-signature` | **CONFIRMED as documented** |
| Anything about GachtheFast | **NOT COVERED** — the payOS document says nothing about GachtheFast |

**Consequences recorded:** no payOS payout work is in scope and none will be started; no payOS route,
signature check or webhook behaviour will be changed unless the owner separately asks. The repo has
**no** payout implementation today (verified above), so the payout documentation changes nothing in
this task.

### 3.2 GachtheFast **bank** material

| Item | Status |
|---|---|
| Endpoint `POST {domain}/client/api/wallet/history-receive`; body `partner_id`, `sign = md5(partner_key . partner_id)`, `limit` 1..1000; success `{"status":"success","data":[{order_code,pay_amount,message,created_at}]}`; failure `{"status":"100","message":"Invalid signature"}` | **RECORDED, NOT CONFIRMED AS OFFICIAL** — this is what the owner pasted in an earlier round; it is reproduced verbatim in `06-owner-inputs-and-gate1.md` §2. No official bank document has been supplied, and **nothing of it is confirmed for the bank flow** |
| Host / scheme / production vs sandbox | **UNKNOWN** |
| Whose wallet/account the history belongs to (owner-controlled or not) | **UNKNOWN** (Q-B-1, never answered) |
| Authentication beyond a constant, replayable `sign` (no nonce/timestamp/request binding) | **UNKNOWN** |
| Ordering, pagination beyond `limit`, cursor/offset, lookback window, rate limits, missed-poll recovery | **UNKNOWN** |
| `pay_amount` units and format; fractional/empty/formatted cases | **UNKNOWN** |
| Memo format; whether one memo can carry several references; matching rules | **UNKNOWN** |
| Whether the payOS webhook documentation describes the GachtheFast bank API | **NO — it does not.** The two are unrelated services |

### 3.3 GachtheFast **card** material (supplied CARD PARTNER API page)

| Item | Status |
|---|---|
| Path `/chargingws/v2`; commands `charging` and `check`; fields `telco`, `code`, `serial`, `amount`, `request_id`, `partner_id`, `sign`, `command` | **CONFIRMED (documented)** |
| Signature `md5(partner_key . code . serial)` on both commands | **CONFIRMED (documented)** |
| Status meanings `1` success/correct denomination, `2` success/different value, `3` card error, `4` maintenance, `99` pending, `100` submission failed (reason in the message) | **CONFIRMED (documented)** |
| Production API hostname | **UNKNOWN** — the page uses `http://{{domain_post}}`; the root website domain (or a subdomain of it) is **not** an established API endpoint |
| Body format | **CONTRADICTORY** — the page says GET or POST is acceptable; the rendered example shows `Content-Type: application/json` while displaying form-data. No silent choice may be treated as correct |
| Complete `check` response schema (unknown `request_id`, currency, transaction reference, amount/value fields) | **UNKNOWN** |
| Whether `check` responses or callbacks are authenticated and authoritative for crediting | **UNKNOWN** — the described signature covers only `code`+`serial`, not `request_id`, status or amount. Therefore callback/reconciliation security is **not** resolved |
| Credit rule for status `2` (which value the shop must credit), commission, under-/over-value outcomes | **UNKNOWN → OWNER DECISION** |
| Rate limits, retry behaviour, sandbox | **UNKNOWN** |
| Sample IDs/keys/cards/serials in the public example | **Examples only — never to be used as data** |

**Code consequence, stated plainly:** the written code treats status `1` as the only auto-credit case
and parks status `2`, unknown statuses, malformed/missing amounts and amount disagreements for manual
review. That is a **provisional conservative policy chosen by us, not a provider-confirmed rule**,
and it may only be relied on after the owner confirms the credit rules. No auto-credit may be enabled
until then.

---

## 4. Owner questions — consolidated list (asked once; nothing already supplied is re-asked)

Please answer by number. **Do not paste credentials, card data, bank logins or customer rows into
chat** — account details are to be configured out of band in `.env`; secrets stay out of Git and out
of this conversation.

### A. GachtheFast bank / top-up

1. **Official bank API documentation** (the full document, not a snippet): exact endpoint(s),
   host + scheme, authentication/signature, request and response examples, status meanings, unique
   reference per transfer, and whether the flow is **webhook (push) or polling (pull)**.
2. **`history-receive` contract completion** (if that endpoint is part of it): whose account's
   history it returns; field schema; ordering; maximum page/`limit`; cursor or offset support;
   lookback window; rate limits; and the recovery strategy if polls are missed (e.g. worker down for
   a day).
3. **`pay_amount` units and exact format**, including whether fractional, empty, or thousands/currency
   decorated values can occur, and what they mean.
4. **Memo/reference rules:** can one transfer memo contain several top-up references, and how are
   references matched (prefix + user id, per-order token, exact string)?
5. **Do incoming funds land in an account the owner controls?** If yes, confirm how the float is
   withdrawn/settled, and send the receiving-account details **out of band** for the customer page
   (never in chat, never hard-coded).
6. **Sandbox/test capability** for the bank flow (host + test credentials delivered out of band), or a
   written decision that only a documented-only verification will ever be possible.

### B. GachtheFast card

7. **Exact official HTTPS API hostname** for `/chargingws/v2`, whether a separate sandbox exists, and
   the provider's rate limits.
8. **Supported method and body encoding** — resolve the documented contradiction (GET vs POST,
   JSON vs form-data) with the value the provider actually accepts in production.
9. **Complete response schemas** for `charging` and `check`: every field, including what `check`
   returns for an unknown `request_id`, whether `currency` and a provider transaction reference are
   present, and the exact amount/value fields returned.
10. **Are `check` responses and/or callbacks authenticated and authoritative?** Which callback
    verification options exist (signature over more fields, source-IP allow-list, retry/backoff
    behaviour)?
11. **Credit rule per status — an owner decision:** especially status `2` (which amount the shop
    credits: declared, provider `value`, provider `amount`, minus commission?), plus the policy for
    under-value and over-value cards and the commission percentage to apply.

### C. Existing integrations and cutover

12. **Which deployed path is actually "CardVN"?** No `cardvn` token exists in this checkout or in any
    git history blob; the only card path here is `card24h()` → `thesieure.com/chargingws/v2`. Either
    identify the deployed file(s)/path (a listing or diff) or confirm that this checkout equals the
    deployed source.
13. **Which exact TheSieuRe bank option must remain?** `THESIEURE` exists here only as a display label
    in `config.php:4`; the actual bank processing is `cron/bank.php` (sieuthicode) and
    `cron/bankWeb2m.php` + `api/webhook_web2m.php` (web2m). Name the files/rows/settings that must
    stay untouched.
14. **Must GachtheFast work while `PAYOS_ENABLED=true`?** (Today the guard hard-stops the legacy
    surface; GachtheFast is written as an independent channel with its own flags.)
15. **Do you approve the conservative cutover?** Verify GachtheFast in a sandbox first; only then stop
    new submissions to the confirmed legacy card provider; reconcile **all** existing pending
    transactions; then remove its submission path while retaining the history and reconciliation
    handling for settled rows.

### D. Schema, tests, findings

16. **Schema-only DDL** for at least: `users, dongtien, payment_bank, deposit_log, aff_log, cards,
    banks, log_bank_auto, settings, promotions` — columns, keys, indexes and table engines. **No
    customer rows.**
17. **Where can PHP + MySQL tests actually run?** local disposable environment, CI, or isolated
    staging — and who runs them. "Untested but acceptable" is not offered as a release option.
18. **Disposition of the P1 findings** listed in §5 (fix now / accept in writing / defer as its own
    task). Each is documented there with file, line, evidence, preconditions, impact and a
    recommended fix; P1 items remain release-blocking until the disposition is recorded.

### E. Frontend (Prompts 01–05)

19. **Are Prompts 01–05 competing references for one page, or five separate requested pages**, and
    which one applies to which route on this site?
20. **Final site identity and content:** name/brand, audience, products, page copy, logo, navigation
    structure, links, contact details, and the target routes for each page.
21. **Where should the frontend live** — inside this PHP app, a built frontend integrated with the PHP
    app, or a separate project? (The five prompts specify conflicting stacks: React + Vite + Tailwind
    vs standalone inline HTML with no build step.)
22. **Which exact copyright text/elements must be removed, and where do they appear?** Third-party
    licence/attribution notices, the CMSNT licensing check and vendor notices will not be removed
    without a specific, authorized, compliant instruction.
23. **Asset rights:** confirm the right to use the specified videos, images and fonts
    (`d8j0ntlcm91z4.cloudfront.net`, `d2ol7oe51mr4n9.cloudfront.net`, `db.onlinewebfonts.com`,
    `static.figma.com`), or supply assets the owner controls. Purchasing the site does not necessarily
    transfer rights to separately licensed assets.

---

## 5. Risks and P1 findings

Method: static reading of this checkout only. No dynamic testing, no pentest, no external scan. A
static search cannot prove the absence of a backdoor.

### P1 (pre-existing, on legacy paths that remain active when `PAYOS_ENABLED=false`)

| ID | File:line | Evidence | Preconditions | Impact | Recommended fix | Release impact |
|---|---|---|---|---|---|---|
| **P1-1** | `api/callback_card.php:33,42-49` | Callback signature verifies only `md5(partner_key . code . serial)` (`:33`), then credits using the **unauthenticated** `$_GET['status']`, `$_GET['value']`, `$_GET['amount']` (`:42-49`); legacy `card24h()` also calls `http(s)://thesieure.com` with no timeout bound | `PAYOS_ENABLED=false`, `card_status=1`, and knowledge of a pending `trans_id` (+ a card's code/serial pair) | Forged/replayed callback can credit an arbitrary amount to a wallet | Verify a signature covering the whole envelope, or corroborate with an authoritative server-side check, plus an IP allow-list | **Release-blocking** until the owner documents a disposition |
| **P1-2** | `api/callback_card.php:36` vs `:48-49` | `SELECT ... WHERE status='pending'` then an unconditional `UPDATE ... status='completed'` + `AddCredits()` — no row lock, no unique key on the credit | Two concurrent/replayed callbacks for the same pending row | Wallet credited twice | Row lock + guarded update (`rowCount() === 1`) + unique ledger key | **Release-blocking** until dispositioned |
| **P1-3** | `cron/bank.php:25`, `cron/bankWeb2m.php:32`, `api/webhook_web2m.php:35` | Dedupe is `SELECT`-then-`INSERT` against `payment_bank (tid, description)` with no unique key | Cron and webhook processing the same transfer concurrently | Duplicate credit | Unique key on the provider reference + row lock | **Release-blocking** until dispositioned |

### P2 (hardening; pre-existing unless marked)

| ID | Finding | Evidence |
|---|---|---|
| P2-1 | Provider URL base64-obfuscated in application code (defeats egress review) | `libs/helper.php:876` |
| P2-2 | Legacy card cURL call has no timeout and no response-size bound | `libs/helper.php:874-878` |
| P2-3 | Card partner key rendered into admin HTML | `views/admin/recharge-card.php:124-127` |
| P2-4 | `cron/bank.php` is web-reachable without a shared secret or rate guard; admin UI prints its URL | `views/admin/recharge-bank-edit.php:66-70`, `views/admin/recharge-bank-config.php:76-78` |
| P2-5 | Legacy SQL built by string interpolation via `check_string()` (`trim(htmlspecialchars(addslashes()))`) | `libs/helper.php:661-664` |
| P2-6 | Legacy card fee credits one of two different source fields depending on config | `api/callback_card.php:43-47` |
| P2-7 | `checkPromotion()` iterates `ORDER BY min DESC` but returns on the first row | `libs/helper.php:57-65` |
| **P2-8 (new code, unexecuted)** | Stale sealed card data after 30 days is only counted and alerted on; automatic shredding is not implemented | `cron/gtf-card.php:54-60` |

### P0
None found in this static review.

### Unverifiable by construction
Because there is no runtime and no database here, **no claim about the new code's runtime behaviour,
security or accounting correctness is possible**, and none is made. The new money-credit path has
never executed once.

---

## 6. Recommended next phase — gated plan

Legend: **CONFIRMED** = established from this checkout or the supplied documentation ·
**UNKNOWN** = provider/deployment fact not established · **OWNER DECISION** = policy choice only the
owner can make.

| # | Item | Status | Hard blocker for *implementation*? | Hard blocker for *enablement*? |
|---|---|---|---|---|
| 1 | Repo inventory (paths, hosts, guard, payOS surface) | CONFIRMED | — | — |
| 2 | CardVN identity / removal target | UNKNOWN (no `cardvn` exists here) | **Yes** — nothing may be removed | **Yes** |
| 3 | TheSieuRe scope (which bank option must remain) | OWNER DECISION | **Yes** — no TheSieuRe change may be made | **Yes** |
| 4 | payOS preservation | CONFIRMED (unchanged; no payout work in scope) | — | — |
| 5 | GTF card: host, body format, `check` authority, response schema | UNKNOWN | No further coding (a disabled draft exists) | **Yes — no card auto-credit** |
| 6 | GTF card: credit rule per status (esp. `2`), commission | OWNER DECISION | No | **Yes — no card auto-credit** |
| 7 | GTF bank: official contract, auth, unique reference, polling/recovery | UNKNOWN | **Yes** — bank design is provisional | **Yes — no bank auto-credit** |
| 8 | GTF bank: owner-controlled account + customer-page details (out of band) | UNKNOWN | No | **Yes** |
| 9 | Schema-only DDL | UNKNOWN | No (draft exists) | **Yes** — migration/atomicity unproven |
| 10 | PHP + MySQL test environment | UNKNOWN | No | **Yes** — no release without executed tests |
| 11 | P1-1/P1-2/P1-3 disposition | OWNER DECISION | No | **Yes** — release-blocking until recorded |
| 12 | Conservative cutover approval (sandbox → stop new legacy submissions → reconcile pending → remove path) | OWNER DECISION | **Yes** — no cutover work | **Yes** |
| 13 | Frontend prompts mapping, brand content, integration target, asset rights | UNKNOWN / OWNER DECISION | **Yes** — no frontend work | **Yes** |

**Hard blockers for any further implementation work** (questions 1, 7, 9, 12, 13, 19–23 — the
implementation-stage blockers) must be answered and the design approved before code resumes.
**Hard blockers for production enablement** are the remaining bank/card facts (2–11) plus executed,
green tests on a disposable database and an authorised staging/sandbox check.

Explicit gates that stay closed until answered:

* **No bank auto-credit** until the bank contract, authentication, unique-reference handling and
  polling/recovery behaviour are known.
* **No card auto-credit** until the exact host, authoritative verification method, response semantics
  and credit rules are known.
* **No CardVN removal and no TheSieuRe change** until the deployed source mapping and the
  pending-transaction plan are confirmed.
* **No frontend implementation** until the page-to-prompt mapping, brand content, integration target
  and asset rights are clarified.

---

## 7. Work performed in this review

* **Read-only.** No file under `libs/`, `api/`, `ajaxs/`, `cron/`, `scripts/`, `tests/`, `views/` or
  root was created, modified or deleted by this review; the fingerprints in §1.2 are the state as
  found.
* **Commands run:** `git branch --show-current`, `git rev-parse HEAD`, `git log --oneline -3`,
  `git status --short`, `git diff --stat`, `git worktree list`, `git stash list`, `git grep` across
  all history for `cardvn`, targeted `grep`/`sed` reads of the legacy flows named in §2, `which php`,
  `php -v`, `which mysql mysqld mariadb`, `ls -d vendor`, `sha1sum`, and the inline PHP delimiter
  heuristic (§1.4 row 5). Their outputs are quoted above.
* **Not performed:** no code change, no migration, no schema change, no enablement, no cutover, no
  commit, no push, no provider API call, no network call to GachtheFast/payOS, no credential handling,
  no test execution (impossible here).
* **Documentation-only writes:** this file, plus a short correction notice appended to
  `02-blockers.md`, `04-lead-report.md`, `06-owner-inputs-and-gate1.md` and `07-implementation-notes.md`
  pointing at §3.3/§1.5. No other file was touched.
* **Awaiting:** the owner's answers to §4 before any implementation resumes.


---

## 8. Post-remediation verification record (2026-10-04, second pass)

This section supersedes §1.2–§1.5 **for the files that changed** in the remediation pass described in
`07-implementation-notes.md` §11. **Status update (2026-10-04, QA pass):** the code is still written,
disabled and uncommitted, but it has now been **PHP-linted and executed** in the sandbox — see
`09-qa-test-matrix.md` for every command, its actual output, the three defects found, the coverage gaps
and the blockers. The earlier blanket claim that it was "never executed and never PHP-linted" is
superseded; what remains true is that nothing was enabled, no real provider was contacted and no
production database was touched.

### 8.1 Fingerprints of the changed files (sha1, current working tree)

Run `sha1sum libs/gtf.php libs/gtf_client.php libs/gtf_card.php libs/gtf_bank.php
libs/gtf_bootstrap.php cron/gtf-card.php cron/gtf-bank.php scripts/migrate-gtf.php
views/admin/recharge-gtf.php views/client/recharge-gtf-card.php views/client/recharge-gtf-bank.php
views/client/nav.php ajaxs/admin/gtf.php ajaxs/client/gtf.php api/callback-gtf-card.php
tests/gtf_rules_test.php tests/gtf_service_test.php .env.example` in the sandbox to obtain the
current values; they are intentionally not transcribed here because they change with every edit and a
stale hash in a document is worse than none.

### 8.2 What was verified, and how

| Check | Method | Result |
| --- | --- | --- |
| Brace/paren/bracket balance of all 17 touched PHP files | comment/string-aware heuristic script | all balanced — **heuristic only, not a lint, not a test** |
| No provider hostname anywhere outside the docs | `grep -ri gachthefast\.com` over `libs/ api/ ajaxs/ cron/ scripts/ views/ tests/ .env.example` | only documentation, never code |
| Removed surface really removed | `grep` for `GtfBankClient`, `GtfBankGateway`, `gtf_bank_events`, `bank_enabled`, `card_submit_style`, `GTF_BANK_REVIEW`, `GTF_BANK_ENABLED`, `bank_receiving_info`, `auto_credit` | none left in application code |
| No credential values committed | `grep -E 'GTF_PARTNER_(ID|KEY)=\S' .env.example` | no match (both blank) |
| Credit lock defaults closed | read of `gtf_config()` / `GtfCredit::isEnabled()` + source assertion in the test | `GTF_ALLOW_CREDITING` absent → `credit_enabled=false` |
| PHP syntax / runtime behaviour | real `php -l` under a WASM build of the **PHP 7.4.33** CLI, every PHP file in the checkout | **PASS** — 232 files, 0 syntax errors (see `09-qa-test-matrix.md` §3.1) |
| Test suite execution | executed against synthetic fixtures and a **disposable** database (MySQL 5.6.24): rules, service (C0–C14), credit-path, mutation self-check, transport probe (positive + TLS-negative), pre-existing regressions | **PASS / PARTIAL** — every executed assertion green; the two `proc_open`-dependent shipped files cannot complete in this sandbox and are recorded as PARTIAL with their substance replicated (see `09-qa-test-matrix.md` §2 rows 17–18) |
| Migration execution | executed only against the isolated fixture: happy path, idempotency, rollback, MyISAM refusal + `--convert`, missing-column refusal, missing-table refusal, unknown-option refusal | **PASS on the fixture; NOT RUN on any real database** — production schema and backup are still unauthorised (see `09-qa-test-matrix.md` §3.8–§3.10) |

### 8.3 Exact reproduction commands

The executed commands and their actual output are recorded in **`09-qa-test-matrix.md` §3** (runtime
and harness provenance in §1, including the WASM interpreter, the disposable MySQL, the test-only
autoload stub that stands in for the missing `vendor/`, and the loopback-only TLS endpoint). The
executed runs do **not** lift the decision: the lead report stays **NO-GO** because the contract
questions in §8.4 are unanswered and the production migration, security review and pentest have not
happened — see the blockers in `09-qa-test-matrix.md` §7.

### 8.4 Owner questions that remain open after this pass

The 23 questions in §4 stand, with these sharpened status notes. Everything in this table is a
**hard blocker for enablement**; the "code needed?" column says whether the answer unblocks further
implementation or only an enablement decision.

| # | Question that is still open | Needed for |
| --- | --- | --- |
| Q1 | Production **HTTPS origin** of the card API (the document only shows `http://{{domain_post}}`) | configuring `GTF_API_BASE`; nothing can be enabled without it |
| Q2 | Accepted **body format** for `POST /chargingws/v2` — JSON body or form-data (the document is self-contradictory) | configuring `GTF_CARD_REQUEST_FORMAT` |
| Q3 | Is `command=check` **authenticated and authoritative**? Which request id must it echo, and what does it return for an unknown id? | allowing any automatic credit at all |
| Q4 | The **credit rule for status `2`** (different denomination): what amount is actually paid? | whether status `2` can ever auto-credit; today it is manual review |
| Q5 | Settlement/commission rules and who bears a rejected card | amount maths and fee configuration |
| Q6 | **Overflow recovery**: which endpoint is the source of truth for a `99`/timeout order, and how long does the provider keep it queryable? | the worker's reconciliation policy |
| Q7 | Are callbacks **signed with anything beyond `md5(partner_key+code+serial)`**, and from which source IPs? | callback hardening (today callbacks never credit) |
| Q8 | The **bank API contract** entirely (no documentation exists): endpoint, auth, `history-receive` semantics, unique reference, `pay_amount` format, memo rules, pagination, settlement/reversal | unblocking the bank flow; the stub stays fail-closed until then |
| Q9 | Schema approval: the DDL in `scripts/migrate-gtf.php` (card-only: `gtf_card_orders`, `gtf_ledger`, `gtf_events`) and InnoDB/unique-key confirmation on the target database | running the migration |
| Q10 | Approved **sandbox/test environment** (PHP 7.4 + MySQL) where the two test files can run | the NO-GO can only lift with executed, green tests |
| Q11 | P1-1/P1-2/P1-3 disposition in the legacy providers (unauthenticated crediting, TOCTOU double credit, non-unique bank dedupe) | release safety; untouched by this pass |
| Q12 | **Cutover plan** for the legacy card provider (`card24h()` → GachtheFast): when new legacy submissions stop, how in-flight pending transactions are reconciled, and rollback | switching traffic |
| Q13 | Frontend mapping/brand/asset rights (unchanged, deferred) | any frontend work |

### 8.4bis Where these questions now block release

Every open question above maps to a named prerequisite in `12-staging-and-release-plan.md` §4
(Q1–Q7 → P1; Q9 → P5; Q10 → B6; Q11 → B2; Q12 → P11; Q13 → deferred) and to a BLOCKED owner item in
§2 there. The security findings that gate the money stages (SEC-01/02/03) are in
`10-security-review.md`; the executed test evidence is in `09-qa-test-matrix.md` (see its §10 for the
release-verification re-run).

### 8.5 Explicitly still disabled / not enabled by this pass

* Card submissions: `GTF_CARD_ENABLED` (default `false`) — off.
* Any wallet credit, including a provider-confirmed card: `GTF_ALLOW_CREDITING` (default `false`) — locked;
  qualifying orders are parked in `REVIEW` with `credit_locked_pending_contract`.
* Bank flow: no contract, no client, no schema, no route — unavailable and refusing.
* Legacy card provider (`card24h()` / TheSieuRe), legacy bank providers, payOS: untouched.
* No migration executed, no cron installed, no route/`nav` enabled, no frontend change.
