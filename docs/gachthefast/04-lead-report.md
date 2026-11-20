# GachtheFast integration — Lead report

Repository: `NggKhaiz/SHOPCLONE7_PAYOS` · Branch: `arena/01a1066c-shopclone7-payos`
Base commit: `e80be783e8bbf5ba3db9e0b4c99b7617f8ed3283` · Date: 2026-10-04
Working state: **nothing committed, nothing pushed, nothing deployed, no provider contacted, no
migration run, no test executed.** Both new channels are disabled and unreachable by default.

---

## 1. Decision

# `NO-GO` — for enablement and deployment

Gate 1 was approved ("start implementing": both flows **disabled**, migration + rollback script, no
live crediting, no deployment), and that scope is **code complete**. A `GO` for turning either
channel on is blocked by design, because the mission forbids a `GO` while a contract blocker, an
unverified money-credit path, or an untested migration remains:

| GO precondition | Status |
|---|---|
| Gate 1 design + money semantics approved | **PASS** (approved by the owner) |
| Implementation code written, disabled by default, no live path changed | **PASS** |
| Provider contract fully confirmed | **PARTIAL** — the **card** contract is now fully confirmed (the owner supplied the card partner API documentation on 2026-10-04; N5/N6 resolved and the `check` call updated to the documented field set). Still open: the HTTPS host serving `/chargingws/v2`, whether card responses carry `currency`/a reference, and all bank items (N1–N4, N7, Q-B-1) |
| Money-credit path executed at least once against a mock or sandbox | **FAIL** — no PHP runtime or database exists in the review sandbox; Phases 3–5 not started |
| Migration executed and verified on a disposable database | **FAIL** — script written, never run; production schema still unknown (B9) |
| Pre-existing P1 findings triaged by the owner | **FAIL** — P1-1/P1-2/P1-3 (all pre-existing, none introduced here) |

**To flip this to `GO`, in order:** (1) run `tests/gtf_service_test.php` + `tests/gtf_rules_test.php`
green on a disposable database with the fixture schema; (2) get N1–N7/Q-B-1 answered and confirm the
`command=check` signing string; (3) run `scripts/migrate-gtf.php` on a backed-up database and confirm
every required legacy table is InnoDB with the expected keys; (4) owner sign-off on the P1 triage and
on enabling one channel at a time.

## 2. Confirmed provider inventory and owner decisions

### 2.1 Inventory (static, `00-inventory.md` holds the full matrix)

| Finding | Evidence |
|---|---|
| No `cardvn` reference exists anywhere in the tree or history | full-text search over all blobs |
| The only card path is `card24h()` → **base64-obfuscated** URL decoding to `https://thesieure.com/chargingws/v2?sign=` | `libs/helper.php:871-880` (`:876` decoded) |
| Legacy card submit happens **before** any local row is persisted, and its callback credits unsigned `status`/`value`/`amount` | `ajaxs/client/create.php:133`; `api/callback_card.php:33`, `:36`, `:42-49` |
| `THESIEURE` is only a display label for the `banks.short_name` datalist | `config.php:4`, `views/admin/recharge-bank-config.php:141`, `views/admin/recharge-bank-edit.php:80` |
| Legacy bank polling = sieuthicode cron + web2m cron/webhook | `cron/bank.php:16-317`, `cron/bankWeb2m.php:19`, `api/webhook_web2m.php:20-40` |
| payOS is self-contained and clean (row locks, ledger, InnoDB migration) | `libs/payos.php:102-297`, `libs/payos_client.php`, `scripts/migrate-payos.php`, `api/webhook-payos.php` |
| payOS currently replaces the legacy top-up surface (410 gate at 14 entry points) | `libs/legacy_payment_guard.php:8-21`, `views/client/recharge-card.php:7`, `tests/legacy_payment_gate_test.php` |
| `/client/recharge-bank` renders payOS **unconditionally** — there is no route back to the legacy bank UI, even with payOS off | `views/client/recharge-bank.php:5` |
| No schema in the checkout (`*.sql` gitignored, `shopclone7.sql` absent); `users::AddCredits()` is not transactional | `.gitignore:9`; `libs/database/users.php:50-64` |

### 2.2 Owner decisions carried into the code

| # | Decision |
|---|---|
| B6 | CardVN premise **withdrawn** (a mix-up with card24h); no removal work. |
| B7 | TheSieuRe scope delegated → **every legacy bank path preserved byte-identical** (no legacy payment file touched). |
| B8 | Routing **Option B — coexist**: payOS stays the default, GachtheFast is a separate method behind its own flag, the 410 guard is untouched. |
| B11 | Card path **(a) replace** `card24h()`'s provider call — **sequenced after verification**, not executed now (see §7 step 6). |
| D1–D5 | Money semantics: callbacks never credit; only a TLS-verified `command=check` bound to our exact `request_id` may credit; auto-credit only when `value == amount == declared_value == declared_amount` and positive, otherwise `REVIEW`/`UNKNOWN` + alert; bank credits only on a single exact local reference inside the amount window with `UNIQUE(order_code)` dedupe; `GTF_CARD_ENABLED=false` / `GTF_BANK_ENABLED=false` are the only defaults. |
| Scope | Implement both flows disabled, add migration + rollback script, no live crediting, no deployment. |

## 3. Files changed and migrations

**Application impact: 14 new files (2 468 lines), 3 legacy files modified additively (+48/−2), 0 legacy
payment files changed, 0 commits, 0 migrations run.** All new screens are inert while the flags are off.

New code:

| Area | Files |
|---|---|
| Core | `libs/gtf.php` (config, PDO, sealing, pure rules, the single credit routine), `libs/gtf_client.php` (pinned-HTTPS transport + card/bank clients behind gateway interfaces), `libs/gtf_bootstrap.php` |
| Services | `libs/gtf_card.php`, `libs/gtf_bank.php` |
| Entry points | `api/callback-gtf-card.php` (never credits), `ajaxs/client/gtf.php` (token + CSRF), `ajaxs/admin/gtf.php` (permission + CSRF + demo-mode + audit log), `cron/gtf-card.php`, `cron/gtf-bank.php` |
| UI | `views/client/recharge-gtf-card.php`, `views/client/recharge-gtf-bank.php`, `views/admin/recharge-gtf.php` (routed by `index.php:40`, no registration needed) |
| Migration | `scripts/migrate-gtf.php` |
| Tests | `tests/gtf_rules_test.php` (212 lines, offline), `tests/gtf_service_test.php` (500 lines, disposable DB + mock provider) |

Legacy edits (all additive, verified with `git diff --stat` against the base commit):

| File | Change |
|---|---|
| `.env.example` | `GTF_*` block appended; both flags `false`, credentials blank. |
| `views/client/nav.php` | Two top-up links, rendered only when the matching flag **and** credentials are set. |
| `views/admin/sidebar.php` | One "GachtheFast" sidebar item + the sidebar highlight arrays. |

**Migration `scripts/migrate-gtf.php` (never run):** CLI-only; asserts the required columns exist on
`users`, `settings`, `dongtien`, `deposit_log`, `payment_bank`, `promotions`, `aff_log`; refuses to
create anything unless **every** table it will write to is InnoDB (`--convert` performs the
`ALTER TABLE … ENGINE=InnoDB`, which is not reversible → backup required); creates
`gtf_card_orders` (`UNIQUE request_id`), `gtf_bank_events` (`UNIQUE order_code`),
`gtf_ledger` (`UNIQUE transid`), `gtf_events` (`UNIQUE event_key`); reports — never silently
changes — a missing or duplicated `dongtien.transid` unique key; `--rollback` drops **only** the four
`gtf_*` tables. Full column list and semantics: `07-implementation-notes.md` §2.

## 4. Tests run and actual results

### 4.1 Executed: none

**No test was executed. This is an environment limitation, not a pass.** Evidence of the attempts:

| Attempt | Result |
|---|---|
| `php -v` | `php: command not found` — no PHP runtime |
| `apt-get update` / `apt-get install -y php-cli` | `Connection failed` to `deb.debian.org`; `E: Unable to locate package php-cli` |
| `curl https://packagist.org`, `https://getcomposer.org/installer` | `SSL_ERROR_SYSCALL` / HTTP code `000` (no egress) |
| `ls vendor/` | absent — even with a runtime, the existing suites cannot bootstrap |
| `mysqld` / `mariadb` | not installed — no database |

Consequently: Phases 3 (backtesting/QA), 4 (code review of the implementation as a running artifact) and
5 (pentest) are **NOT STARTED**; the pre-existing suites
(`tests/payos_client_test.php`, `tests/payos_service_test.php`, `tests/legacy_payment_gate_test.php`,
`tests/legacy_security_test.php`) were **not** re-run, so their `TEST_REPORT.txt` PASS results remain
unverified here.

### 4.2 Written and ready to run (must pass before enablement)

`tests/gtf_rules_test.php` — offline, no DB, no network: signature formulas (`md5(partner_key.code.serial)`
for the card, `md5(partner_key.partner_id)` for the bank), URL pinning (rejects http, foreign hosts,
look-alike hosts, userinfo, query, non-443 ports; accepts a provider subdomain), amount parsing
(`66666.00`, `66,666`, `1e6`, negatives, over-long values all rejected), the full card decision matrix
(statuses `1/2/3/4/99/100`, missing status, value/amount/declared mismatches, zero value, malformed
values), fee/net maths, reference matching (prefix-collision-safe fixed-width references, ambiguous and
absent references), the bank decision matrix, promotion maths, secret sealing/masking/tamper refusal,
plus source-level assertions that neither service uses the legacy non-transactional `AddCredits()`, that
both credit through `GtfCredit::applyWithin`, that the callback body never calls the credit routine, and
that the credit routine refuses to run outside a transaction.

`tests/gtf_service_test.php` — needs PHP 8 + MySQL/MariaDB and **refuses to run unless the DSN contains
`test`**; drops and recreates its own **fixture** schema (not the production schema — B9); replaces the
provider with mock gateways. Cases: C1 flag-off refusal; C2 durable order before the provider call and
no credit while pending; C3 credit only on a bound, matching `check` + sealing/shredding; C4 replayed
callbacks and repeated `credit()` are no-ops; C5 valid-signature callback still cannot credit, forged
signature rejected, unknown order ignored; C6 amount conflict and unbound answer → `REVIEW`, no credit;
C7 submit/check transport failure → `UNKNOWN` (never `REJECTED`) and worker pickup; C8 the unique ledger
key refuses a second credit and the refusal changes nothing; C9 terminal statuses never credit; C10
operator credit is idempotent; B1 flag-off refusal; B2 exact single-reference credit with ledger/receipt
ids; B3 page replay and a conflicting duplicate `order_code`; B4 ambiguous memos, banned/unknown users,
out-of-window amounts → `REVIEW` with the right reason; B5 a fractional `pay_amount` is never
digit-stripped; B6 promotions; B7 operator credit/ignore; B8 the row lock serialises a second writer
(lock-wait assertion on a second connection).

## 5. Security / pentest findings by severity

Method: **static review only** of the existing paths + the new code, cron/configuration posture, and an
outbound-host inventory. **No dynamic testing and no pentest were possible** (no runtime, no authorised
staging target, no credentials). A static search cannot prove the absence of a backdoor; the statements
below are findings, not a clearance.

### P0 — none found.

### P1 — pre-existing (none introduced by this work)

| # | Finding | Evidence | Impact |
|---|---|---|---|
| P1-1 | Legacy card callback credits **unsigned** `status`/`value`/`amount`; the signature covers only `code`+`serial` | `api/callback_card.php:33`, `:42-49` | Over-credit by replay/forgery against a known pending order. |
| P1-2 | Legacy card callback **TOCTOU double-credit race** (read `status='pending'`, then unconditional update + `AddCredits`) | `api/callback_card.php:36` vs `:48-49` | Two concurrent callbacks credit twice. |
| P1-3 | Legacy bank dedupe is `SELECT`-then-`INSERT` on `payment_bank` with no unique key | `cron/bank.php:25`, `cron/bankWeb2m.php:32`, `api/webhook_web2m.php:35` | Cron and webhook can both credit the same transfer. |

These sit on paths the mission keeps, so they must be **fixed or accepted in writing** by the owner
(missions rule: no `GO` with a P1 open). The new flows deliberately do not share any of these mechanisms.

### P2 — hardening gaps (pre-existing unless marked)

| # | Finding | Evidence |
|---|---|---|
| P2-1 | Provider URL **base64-obfuscated** in application code → egress destinations cannot be reviewed by text search | `libs/helper.php:876` |
| P2-2 | Legacy card submit: no cURL timeout, no response-size bound (TLS verification left to library defaults) | `libs/helper.php:874-878` |
| P2-3 | Card **partner key rendered into admin HTML** | `views/admin/recharge-card.php:124-127` |
| P2-4 | `cron/bank.php` is web-reachable with no shared secret/rate guard; the admin UI prints its URL | `views/admin/recharge-bank-edit.php:66-70`, `views/admin/recharge-bank-config.php:76-78` |
| P2-5 | Legacy SQL built by interpolation via `check_string()` (`trim(htmlspecialchars(addslashes()))`) | `libs/helper.php:661-664` |
| P2-6 | Legacy card fee credits one of two different fields depending on config | `api/callback_card.php:43-47` |
| P2-7 | `checkPromotion()` iterates `ORDER BY min DESC` but returns on the first row | `libs/helper.php:57-65` |
| P2-8 **(new, ours)** | Stale sealed card data is only counted and alerted on; automatic shredding after a retention window is not implemented | `cron/gtf-card.php` |

### P3 — observations, no action requested

* `libs/helper.php:1295-1345` `CMSNT_check_license()` is legitimate WHMCS-style licensing logic
  (base64/JSON local key, phone-home to `client.cmsnt.co`). **Untouched, per the mission rule.**
  `views/admin/product-api-*.php` → `api.cmsnt.co/checkdomain.php` is likewise legacy supplier
  registration. `update.php` is defanged (`exit("Chức năng khóa")` before any use) and performs no
  network call.
* No `eval`, `assert`, `create_function`, `shell_exec`, `exec`, `system`, `passthru`, `popen`,
  `proc_open` or variable-include constructs were found in application code — a static observation,
  not a backdoor clearance.
* Outbound-host inventory (non-vendor): legacy bank/wallet providers (`api.sieuthicode.net`,
  `api.web2m.com`, …), payOS (`api-merchant.payos.vn`), `api.vietqr.io` (admin bank list), CMSNT
  licensing, CDNs. No unexplained egress host.

### Controls the new code adds (all disabled until configured)

Callbacks never credit; credit requires our own TLS-verified `check` bound to our `request_id`; exact
integer comparison of every amount field; single atomic transaction across order state, wallet,
`dongtien`, `deposit_log`, `payment_bank`, debt and affiliate, guarded by `UNIQUE transid`;
`SELECT … FOR UPDATE` row locks; state flips conditioned on `rowCount() === 1`; card PIN/serial sealed
with AES-256-GCM and shredded on credit/terminal; masked-only display and logging; fixed HTTPS host with
certificate/hostname verification, no redirects, 3–30 s timeouts, 1 MiB body cap; secrets only in
`.env` (blank placeholders committed); admin actions behind permission + CSRF + non-demo + audit log;
workers CLI-only or `hash_equals` token-protected.

## 6. Remaining risks and unresolved provider questions

**Resolved 2026-10-04:** the owner supplied the GachtheFast **card partner API** documentation, which
confirmed the card endpoint, signature, `command` values, the full `command=check` field set and the
status table (`1/2/3/4/99/100`); N5 and N6 are closed and the `check` call was updated to match.

**Open provider questions — these block enablement, not the code:**

1. **N3 / Q-B-1 (most acute):** does `history-receive` return the history of **our own** GachtheFast
   wallet? The owner's last reply on this pasted unrelated payOS documentation, so it is still
   unanswered. If it is not our wallet, the bank design must change.
2. **Card HTTPS host:** the card partner API document writes `http://{{domain_post}}/chargingws/v2`
   while the integration is HTTPS-only against `GTF_API_BASE`. Confirm the HTTPS host that serves it.
3. **N1:** bank pagination/cursor beyond `limit`, and whether the list is newest-first. The current code
   can only ever see one page, so a poll gap larger than the page can miss credits (dedupe prevents
   double credit, not a missed one).
4. **N2:** can the bank `pay_amount` be fractional/decorated? Anything non-integer is parked today.
5. **N4:** can the bank `message` contain more than one `GTF########` reference, or a foreign one?
6. **Card responses:** do they carry `currency` and a provider reference, and what does
   `command=check` return for an `request_id` it does not know? (Status codes are now confirmed:
   `1/2/3/4/99/100`.)
7. **N7:** provider receipt-history depth → required polling cadence; also Q-B-4 rate limits.
8. **Q-R-3:** where the GachtheFast bank page should be surfaced relative to payOS
   (`recharge-bank.php:5` unconditionally renders payOS).

**Ranked residual risks:** (1) unverified money-credit code — nothing has executed; (2) wrong wallet /
unanswered Q-B-1; (3) unverified production schema and InnoDB/keys (B9) — the atomic guarantee depends
on them; (4) card cutover stranding in-flight `cards` rows if the sequencing in §7 is skipped;
(5) pre-existing P1-1/P1-2/P1-3 still live on the legacy paths; (6) duplicate provider questions in the
memo, mitigated by parking; (7) no sandbox (B10) → verification can only ever be "as documented" plus a
minimum-value live test.

## 7. Deployment and rollback steps (prepared, not executed)

1. **Back up** database + `.env` outside the repository (never in Git). Confirm the PHP version and that
   `pdo_mysql`, `curl`, `openssl`, `mbstring` are available.
2. **Migrate on staging first:** `php scripts/migrate-gtf.php` (add `--convert` only with a backup and
   only if a required table is not InnoDB). Confirm the four `gtf_*` tables and that the script reported
   no `dongtien.transid` key problem. Verify `--rollback` on the staging copy before touching production.
3. **Configure secrets privately** in `.env` (`GTF_PARTNER_ID`, `GTF_PARTNER_KEY`, `GTF_CRON_TOKEN`);
   keep both flags `false`. Confirm the admin page shows "configured" and no secret value.
4. **Register the callback URL** with the provider (`{APP_URL}/api/callback-gtf-card.php`) and confirm
   the receiver answers without leaking details.
5. **Run the Phase 3 suites** on a disposable database (`tests/gtf_rules_test.php`,
   `tests/gtf_service_test.php`) and the pre-existing suite; all must be green.
6. **Enable one channel at a time, starting with cards:** `GTF_CARD_ENABLED=true`, schedule
   `cron/gtf-card.php` (e.g. every 2 minutes) with alerting, watch the review queue for 24–48 h, then
   `GTF_BANK_ENABLED=true` with `cron/gtf-bank.php`. Only after a clean verification window, perform the
   approved `card24h()` cutover: disable the legacy card AJAX path **after** its in-flight rows settle
   (no `pending`/`processing` rows) and leave the legacy code in place for one settlement cycle.
7. **Monitor:** alerts on `UNKNOWN`/`REVIEW` transitions, card reconciliation failures, a full bank page
   (possible missed rows), non-integer amounts, and any `gtf_ledger`/provider mismatch in the daily
   reconciliation (`provider reference → payment state → wallet → ledger`).
8. **Rollback:** set the affected flag back to `false` (the workers then refuse to run and the customer
   pages/links disappear); keep callbacks and reconciliation running while any order is in flight; never
   delete payment history or `gtf_*` rows — `scripts/migrate-gtf.php --rollback` drops only the four new
   tables and must be used only after confirming nothing is in flight and the ledger has been exported.

## 8. Required owner approvals

| # | Approval | Status |
|---|---|---|
| A1 | Gate 1 — design + money semantics + "implement disabled" scope | **APPROVED** |
| A2 | **Q-B-1** — the receive-history wallet is the shop's own collection account (plus Q-B-2…Q-B-8) | **OUTSTANDING** |
| A3 | Card callback/`check` hardening details: signed envelope or IP range, and the `check` signing string (N5) | **OUTSTANDING** |
| A4 | N1/N2/N4/N6/N7 provider answers (pagination, amount format, memo content, status set, retention) | **OUTSTANDING** |
| A5 | Receiving-account details for the customer bank page (`GTF_BANK_RECEIVING_INFO` = account + bank + holder, delivered out-of-band). The page shows the required reference and, while this is empty, an explicit "contact the administrator" notice — no code change is needed once it is set | **OUTSTANDING** |
| A6 | Schema-only fixture / confirmation of the live schema (B9) | **OUTSTANDING** |
| A7 | Sandbox or minimum-value live test authorisation (B10) — required before any enablement | **OUTSTANDING** |
| A8 | Triage of the pre-existing P1-1/P1-2/P1-3 findings: fix first or accept in writing | **OUTSTANDING** |
| A9 | Routing follow-up Q-R-3 (how the bank page is surfaced relative to payOS) | **OUTSTANDING** |
| A10 | Authorisation to commit/push (none performed) and, later, to deploy and flip a flag | **NOT REQUESTED** |

---

### Agent / phase status

| Phase | Agent | Status |
|---|---|---|
| 0 — inventory | A | **DONE** → `00-inventory.md` |
| 0 — contract analysis | B | **DONE for what was supplied**; gaps marked UNKNOWN → `01-contract-matrix.md` |
| Gate 0 | Lead | **PASSED after owner round 2** → `02-blockers.md` |
| 1 — design | C | **DONE, Gate 1 approved** → `03-design-proposal.md`, `06-owner-inputs-and-gate1.md` |
| 2 — implementation | D | **CODE COMPLETE, UNEXECUTED, DISABLED** → `07-implementation-notes.md` |
| 3 — backtesting / QA | E | **NOT STARTED** — suites written; no runtime/DB in this sandbox |
| 4 — security review | F | **NOT STARTED as an independent review of running code**; static pre-review in §5; P2-8 is the only new-code finding |
| 5 — pentest | G | **NOT STARTED** — no authorised target (B10) |
| 6 — release / docs | H | **PREPARED** — this report + §7 plan; deployment requires A10 |

---

## 9. Scope note — the five frontend prompts (PROMPT 01–05)

Prompts 01–05 arrived together with the provider documentation. They describe a **different codebase**
(React + TypeScript + Vite + Tailwind `index.html` deliverables: a "Mainframe" hero, a 404 screen, a
"Viktor" portfolio hero, an inline-HTML "Nexeus" page and an inline-HTML "Orbit" page, all with
CloudFront-hosted video assets and Figma/Google fonts). None of them match this repository, which is a
PHP monolith with no React, Vite, TypeScript, Tailwind or `index.html` build.

They are therefore treated as a **separate workstream**, not as part of the GachtheFast payment work,
and nothing has been generated from them in this branch yet. Needed before that work can start:

1. **A separate directory/repository** for the landing pages (they cannot be dropped into this
   checkout without a JS toolchain and a new build pipeline).
2. **Confirmation on live assets:** the CloudFront video/image URLs, the
   `db.onlinewebfonts.com`/`static.figma.com` font URLs and the `d8j0ntlcm91z4.cloudfront.net` hosts
   are third-party hotlinks. Are they licensed for this site, or should self-hosted copies be produced?
3. **The "remove any existing copyright-related elements"** request needs a concrete list: which
   strings, logos, footer notices or licence headers in *this* repo are meant (note that the CMSNT
   licensing phone-home is deliberately left untouched by the payment work).
4. **Where they belong:** public marketing pages served by the PHP app, or a standalone static site?

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

## Release-packet status (added 2026-10-04, documentation pass)

The decision in this report is **unchanged: NO-GO**. What is new since it was written:

* `09-qa-test-matrix.md` — executed test evidence, §10 re-verification run (232/0 lint, 124/0 rules,
  93/0 service, 75/0 credit path, mutation 2/2, transport probe 18/0 + 3/0, migration lifecycle and
  refusals, 14/14 legacy 410 probes, 37/0 security checks).
* `10-security-review.md` — independent read-only review: **1×P1 (SEC-01)**, 3×P2, 4×P3; no backdoor,
  obfuscation, dynamic code or unexplained outbound destination; **no "backdoor-free" certification**.
* `11-operator-runbook.md` — configuration, enablement ladder, reconciliation, monitoring queries,
  secret/rotation rules (incl. the `SECRET_KEY` sealing caveat) and the rollback contract.
* `12-staging-and-release-plan.md` — staging-first programme (S0–S6), the exact deployment
  prerequisites (P1–P12), six BLOCKED owner items, and the rollback steps that stop new submissions
  without losing valid callbacks or deleting payment records.

Nothing was deployed; no production database or provider was contacted; no production-readiness claim
is made.
