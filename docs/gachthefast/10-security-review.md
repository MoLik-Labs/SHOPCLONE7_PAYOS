# 10 — Independent security / backdoor review of the GachtheFast payment diff

**Date:** 2026-10-04 · **Reviewer:** independent pass (Arena agent) · **Mode: READ-ONLY** — no file in the
repository was modified, created or deleted by this review, nothing was committed, no feature was
enabled, no production provider or production database was contacted, and no CMSNT licence check was
touched.

**Verdict:** the diff introduces **no backdoor, no obfuscation, no dynamic code, no hidden route, no
unexplained outbound destination, no new credential exposure and no new file/OS-level persistence.**
It also ships **one P1 money-integrity defect on the operator credit path** and several P2/P3
weaknesses in the callback/reconciliation trust path. Per the brief, **P1 blocks release** — the
crediting feature must not be enabled until SEC-01 is fixed and retested. The current shipped state
remains safe to keep deployed *disabled*, because every credit path is additionally locked behind
`GTF_ALLOW_CREDITING=false`.

**This review does not certify the code as "backdoor-free".** A static search can only report what it
searched and found; a negative result is evidence of absence only within the searched surface, at the
reviewed revision. `docs/gachthefast/04-lead-report.md` already reserves that judgement for the
independent review + authorised pentest phases (project phases 4–5), which have not happened.

---

## 1. Scope, method and runtime provenance

| Item | Detail |
| --- | --- |
| Reviewed revision | working tree of branch `arena/01a1066c-shopclone7-payos`, HEAD `e80be783`, **uncommitted** (3 modified files: `.env.example`, `views/admin/sidebar.php`, `views/client/nav.php`; 24 untracked new files) |
| Reviewed surface | the whole GachtheFast diff (`libs/gtf*.php`, `api/callback-gtf-card.php`, `ajaxs/{client,admin}/gtf.php`, `cron/gtf-*.php`, `scripts/migrate-gtf.php`, three `recharge-gtf*` views) plus the supporting infrastructure it depends on (`models/is_admin.php`, `models/is_user.php`, `models/is_license.php`, `libs/helper.php`, `libs/legacy_payment_guard.php`, `.htaccess`, `config.php`) and the payOS files for regression comparison |
| Dynamic method | real **PHP 7.4.33 CLI** (WASM build; `curl 7.69.1 / OpenSSL 1.1.1t`) against a **disposable MySQL 5.6.24** and a **loopback-only HTTPS endpoint**; a fake provider gateway replaced all network providers |
| Harness | `/tmp/phpwasm/php74.mjs` (modes `lint|run|lintlist|envrun|runhost|probe|eval`), `/tmp/review/*` review scripts, `/tmp/gtfprobe/*` loopback TLS endpoint — all outside the repository |
| Not executed | real GachtheFast API, real payOS API, production schema, production data, admin browser session (the admin AJAX endpoints were assessed by reading) |
| Non-execution disclosure | the checkout has no `vendor/`; a **test-only Dotenv stub** was injected into the interpreter filesystem to let the bootstraps load. It is not a substitute for `composer install` and is not in the repository |

**Findings index**

| ID | Severity | Title | Reachable in the shipped configuration? |
| --- | --- | --- | --- |
| SEC-01 | **P1** | Operator credit: no state precondition and no binding to the verified card value | No — requires `GTF_ALLOW_CREDITING=true` plus an admin session. **Blocks enabling crediting.** |
| SEC-02 | P2 | Unauthenticated write amplification into `gtf_events`; composite unique key cannot dedupe NULL-bound events | Yes |
| SEC-03 | P2 | Callback replay is unbound to order/status/amount and bypasses the reconcile throttle → provider-call amplification | Partly — a valid signature is needed for the provider call; row writes are unauthenticated |
| SEC-04 | P2 | The credit decision depends on contract properties that cannot be verified from this checkout (`check` binding/authenticity, optional `currency`) | Yes (design-level; blocks enabling crediting) |
| SEC-05 | P3 | Audit-event write failures are swallowed silently | Yes |
| SEC-06 | P3 | `card_reconcile` admin action performs a state-changing provider call with no audit-log row | Only with an admin session |
| SEC-07 | P3 | Worker token accepted in a query string; the worker mutates state on `GET` | Yes (only when a token is configured) |
| SEC-08 | P3 | Request ids are partly predictable — must never be treated as a secret | Yes (documentation/design) |
| SEC-09 | Info | Legacy P1s remain live while `PAYOS_ENABLED=false` (pre-existing, outside this diff, undispositioned) | Yes |
| SEC-10 | Info | The CMSNT licence gate in this checkout is a stub (`checkLicenseKey()` returns success; the real `CMSNT_check_license()` has zero callers) | Yes — reported, **not altered** |

---

## 2. P1 — release blocker

### SEC-01 · Operator credit is unbound: any amount, any order, no verified-provider link

* **Severity:** **P1** (money integrity, privileged path). Not a P0: it needs an authenticated admin
  (`edit_recharge`) and a CSRF token, and it is unreachable while `GTF_ALLOW_CREDITING=false`.
* **Files / lines:**
  * `libs/gtf_card.php:367-383` — `adminCredit()`: only checks that the order exists, is not already
    credited and that `$gross !== ''`; there is **no state precondition** (a `CREATING` order that was
    never verified and never submitted anywhere can be paid).
  * `libs/gtf_card.php:341` — `if ($source !== 'admin_review' && (int) $locked['declared_amount'] !== (int) $gross)`
    — the amount-agreement check is **skipped for the operator path**, so the operator-typed figure is
    what moves.
  * `ajaxs/admin/gtf.php:49-58` — the endpoint passes the posted amount straight through (validated
    only as `^[0-9]{1,15}$` and `> 0`).
  * `views/admin/recharge-gtf.php:163-168` — the credit button pre-fills the declared amount but the
    value comes from a free-text `prompt()`, so the operator can overwrite it.
* **Evidence (dynamic, this session):** `/tmp/review/gtf_review_checks.php` §R9 against the disposable
  fixture, with `GTF_ALLOW_CREDITING=true`:

  ```
  ok   an operator can credit a never-verified order (no state precondition)
  ok   the operator-typed 1000 moves, not the verified 100000 card value (delta=1000)
  ok   the ledger records the operator-typed gross
  ```

  A 100 000 ₫ card order in state `PENDING` (never submitted, never checked) was credited with 1 000 ₫.
  The card value is never consulted.
* **Preconditions:** `GTF_ALLOW_CREDITING=true` (shipped default is `false`); an admin account with
  `edit_recharge` (or any way to make one issue the request, e.g. XSS/social engineering against an
  admin — the CSRF token check at `ajaxs/admin/gtf.php:32` is correct and cannot be bypassed
  cross-site, but the *amount* is fully operator-controlled).
* **Impact:** wrong money, both directions — under-crediting a genuine card (customer complaint) or
  over-crediting up to 10^15 VND and creating a withdrawal liability. It is **not** a
  double-credit or replay defect: the `gtf_ledger` primary key (`transid`) still makes a second credit
  impossible (SEC-01 is integrity, not idempotency).
* **Fix (not applied — read-only review):**
  1. move the amount decision server-side: ignore the posted amount unless it equals the order's
     verified/declared value, i.e. delete the `$source !== 'admin_review'` exemption and pass
     `$order['declared_amount']` (or an explicitly justified adjustment recorded as a delta);
  2. require an explicit state precondition (`REVIEW`/`UNKNOWN`, with `provider_checked_at` set),
     refusing `CREATING`/`PENDING` orders outright;
  3. keep the amount field read-only in the UI, require a written reason for any deviation, and log
     both the actor and the delta (see SEC-06).
* **Retest result:** reproduced, not yet fixed → **retest after fix required** (re-run §R9 plus the
  existing `tests/gtf_credit_path_test.php` A9, which currently documents the *actual* behaviour).

---

## 3. P2 — fix before the feature is enabled (not release blockers while disabled)

### SEC-02 · Unauthenticated write amplification into `gtf_events`, and a dedupe key that cannot dedupe unbound events

* **Severity:** P2 (integrity of the audit trail + unbounded growth; no crediting impact).
* **Files / lines:** `libs/gtf_card.php:198-201` (a failed signature still calls `recordEvent`),
  `libs/gtf_card.php:435-447` (the insert, and the `ON DUPLICATE KEY UPDATE id = id` intent),
  `scripts/migrate-gtf.php:79-89` — `order_id BIGINT UNSIGNED NULL` (line 82) with
  `UNIQUE KEY gtf_event_key (flow, order_id, direction, event_key)` (line 88),
  `api/callback-gtf-card.php:30-36` (the only bound: 40 fields per request).
* **Evidence (dynamic, this session):**
  * §R1 — 25 forged callbacks (no valid key available to the attacker): all 25 rejected with
    `InvalidArgumentException`, **yet 25 rows were added** and no provider call was made.
  * §R2 — three callbacks with the *identical* `request_id` added **three** rows: MySQL unique keys
    treat `NULL`s as distinct, so the `ON DUPLICATE KEY` guard cannot fire while the order is unknown
    (`order_id = NULL`).
  * §R3 — five replays of a captured signature against unknown orders added five more rows.
  * End-to-end probe of the real entry point (`api/callback-gtf-card.php`, forged signature), three
    consecutive requests:

    ```
    callback_rejected   HTTP_STATUS=400   GTF_EVENTS_BEFORE=41  GTF_EVENTS_AFTER=42
    callback_rejected   HTTP_STATUS=400   GTF_EVENTS_BEFORE=42  GTF_EVENTS_AFTER=43
    callback_rejected   HTTP_STATUS=400   GTF_EVENTS_BEFORE=43  GTF_EVENTS_AFTER=44
    ```

* **Preconditions:** the endpoint is web-reachable and deliberately not gated by `GTF_CARD_ENABLED`
  (`api/callback-gtf-card.php:10-11`); it only requires `partner_key` to be configured. No signature,
  session or rate limit is needed to write a row. `GET` is accepted (line 24), so a third-party page
  can drive it with an `<img>`/`fetch` too.
* **Impact:** an attacker can append rows to a money-adjacent table without limit (disk/backup growth,
  log noise), and the rows are indistinguishable in shape from genuine provider events — which weakens
  the very audit trail that incident response would rely on. Reachable while the channel is disabled,
  i.e. before any enablement decision.
* **Fix:** validate `request_id` (format + length) and the presence/shape of the signature **before**
  writing anything; do not persist an event when the signature fails (or persist it in a separate,
  rate-limited `gtf_rejected_events` table/counter); make the dedupe key order-independent
  (`UNIQUE KEY (flow, event_key)` or `order_id NOT NULL DEFAULT 0`); add a per-IP/global rate limit and
  require `POST` for callbacks (the documented API supports POST).
* **Retest result:** reproduced → **retest required** after the fix (the existing
  `tests/gtf_migration_verify.php` already pins the current index shape, so it must be updated in the
  same change).

### SEC-03 · Callback replay is unbound and skips the reconcile throttle

* **Severity:** P2 (outbound provider-call amplification, attacker-influenced audit data; no credit).
* **Files / lines:** `libs/gtf_card.php:186-223` (`callback()` trusts `request_id`, `code`, `serial`,
  `status`, `trans_id` as *parameters*), `libs/gtf_card.php:198` (`md5(partner_key + code + serial)` —
  the documented signature covers **only** `code`+`serial`), `libs/gtf_card.php:217`
  (`return $this->reconcile($order, true);` — `force = true`), `libs/gtf_card.php:240-250`
  (the 15-second `provider_checked_at` claim that `force` bypasses).
* **Evidence (dynamic, this session):** §R4 — with a genuine signature value for a *known* order,
  8 replayed callbacks (each with a different attacker-chosen `status`/`trans_id`) produced
  **8 outbound `command=check` calls**, zero ledger rows and no credit. The recorded
  `provider_status`/`provider_ref` in those events are attacker-chosen strings (sanitised to
  `[A-Za-z0-9_-]`, so no stored-XSS, but not authenticated).
* **Preconditions:** the attacker must have one valid `md5(partner_key+code+serial)` value (e.g. one
  observed callback; the signature is deterministic and covers nothing else); the target order must be
  open and not terminal. An unknown order writes a row (SEC-02) but makes no provider call.
* **Impact:** (a) an attacker can drive our client to hammer the provider at will — provider rate
  limits/blacklisting and wasted upstream quota; (b) the recorded `provider_status` on that order is
  attacker-influenced, which matters because the **status-2 policy** (`GtfRules::cardDecision`,
  `libs/gtf.php:248-250`) parks such orders for a human who will read that field; (c) no credit
  happens, because the credit decision requires the re-queried, request-id-bound result.
* **Fix:** bind the callback to the order (reject when the order is unknown instead of recording a
  free-form event), drop `force = true` in `callback()` and let the same 15-second claim apply, keep
  the throttle when a signature is replayed repeatedly, and rate-limit the endpoint per source.
* **Retest result:** reproduced → **retest after fix**.

### SEC-04 · The credit decision rests on contract properties that are unconfirmable from the repository

* **Severity:** P2 (design/assurance; blocks enabling any crediting).
* **Files / lines:** `libs/gtf_card.php:279-295` — the only binding on a `check` response is that it
  echoes our `request_id` (`:281-285`); the currency check is conditional
  (`:286-289`: `isset($remote['currency']) && ...`) so a response **without** a currency field passes;
  `GtfRules::cardDecision` (`libs/gtf.php:220-262`) then credits when every amount field agrees.
* **Evidence:** reading `libs/gtf_client.php:11-24` — the client's own header states that the `check`
  response schema, whether responses are authenticated, and the status-2 credit rule are **not
  confirmed**. There is no signature/MAC on the provider's response; the trust anchor is solely TLS to
  the configured origin. `docs/gachthefast/08-…` §8.4 Q1–Q7 records the same gap.
* **Preconditions:** any enablement of `GTF_ALLOW_CREDITING`.
* **Impact:** if the real `check` endpoint is not authoritative (e.g. it answers with a caller-set
  `request_id` echo and card data from another session, or documents an unauthenticated path), the
  entire automatic credit path is built on an assumption nobody has verified. Wrong credits would be
  *silent* — every bound amount would agree because we send them.
* **Fix:** obtain the contract answers; then, before enabling: verify the `check` binding against a
  provider sandbox, treat a missing `currency` as a mismatch, and add an independent reconciliation
  (provider settlement report) before any auto-credit is switched on.
* **Retest result:** not testable here (no provider contract) → **NOT RUN, by construction**.

---

## 4. P3 / informational

| ID | Finding | File / line | Evidence | Impact | Fix | Retest |
| --- | --- | --- | --- | --- | --- | --- |
| SEC-05 | A failed audit-event insert is swallowed (`error_log` only) while reconciliation continues | `libs/gtf_card.php:444-446` | code read; the `catch (Throwable)` swallows every failure | if `gtf_events` is missing/broken, the audit trail silently vanishes and the flow still proceeds | count failures, alert after N, and surface a health flag | not dynamically forced; **NOT RUN** |
| SEC-06 | `card_reconcile` (state-changing, provider-calling, potentially crediting when unlocked) writes **no** audit-log row, unlike `card_credit`/`card_resolve` | `ajaxs/admin/gtf.php:75-79` vs `:58-73` | code read | incomplete operator audit trail for a money-adjacent action | log the action like its siblings | not executable without an admin session → **NOT RUN** |
| SEC-07 | Worker token accepted from `?token=` and the worker mutates state on `GET` | `libs/gtf.php:134-136` (`$_GET['token']`), `cron/gtf-card.php:19-31` | code read; `hash_equals` is used correctly and an empty token means "CLI only" | the token can leak into access logs/browser history/`Referer`; a leaked token lets an attacker drive reconciliation | accept the `X-GTF-Cron-Token` header only, require `POST`, rotate on suspicion | **NOT RUN** (would need an HTTP-configured token) |
| SEC-08 | `request_id` = 6 random letters + `time()` → partly predictable | `libs/gtf_card.php:449-457` | code read; the id is validated to `^[A-Za-z0-9]{4,64}$` everywhere it is used | the id must never be treated as a secret; it currently is not (client reads are user-scoped) — informational | keep 6 random letters but drop the timestamp, or use a random 16-byte id | n/a |
| SEC-09 | Legacy P1s (unauthenticated crediting in `api/callback_card.php` etc.) are unchanged by this diff and still live whenever `PAYOS_ENABLED=false` | `api/callback_card.php:33,42-49` (pre-existing) | not re-tested here; previously documented in `09-qa-test-matrix.md` and `04-lead-report.md` | pre-existing money risk, currently the *only* live card path | owner disposition (already requested) | **NOT RUN** (out of scope for this diff) |
| SEC-10 | The licence gate in this checkout is a stub: `checkLicenseKey()` returns `Active` unconditionally; the real WHMCS client `CMSNT_check_license()` has **0** callers | `libs/helper.php:1443-1448`; `libs/helper.php:1295`; `views/admin/product-api-add.php:45` (`api.cmsnt.co/checkdomain.php` is live) | `awk`/`grep` call-site counts | deployment integrity observation for the owner; **not a finding of this diff** | owner decision. **Not modified, not bypassed, not weakened by this review** | n/a |

---

## 5. Domain-by-domain assessment

| Domain | Verdict | Evidence |
| --- | --- | --- |
| **Authentication (client)** | OK | `ajaxs/client/gtf.php:16-25` resolves the session/cookie token to a `users` row with `banned = 0`; 401/403 otherwise. Uses a prepared statement. |
| **Authorization (client object access / IDOR)** | OK | `find($requestId, $userId)` adds `AND user_id = ?` (`libs/gtf_card.php:51-63`); §R5 (dynamic): another user id cannot read the order, `history()` for another user is empty, and the response whitelist (`ajaxs/client/gtf.php:46-48`) excludes `serial_sealed`, `code_sealed`, `provider_ref`, `reason`. |
| **Authorization (admin)** | OK with caveats | `ajaxs/admin/gtf.php:28-40`: `checkPermission($getUser['admin'], 'edit_recharge')`, CSRF via `hash_equals` on a 32-byte session token, demo-site guard. Caveat = SEC-01 (money integrity) and SEC-06 (audit). |
| **CSRF** | OK | token issued by `gtf_csrf_token()` (`libs/gtf.php:101-107`), emitted with `JSON_HEX_*`/escaped (`views/admin/recharge-gtf.php:148`, `views/client/recharge-gtf-card.php:52`), compared with `hash_equals` in both AJAX endpoints. Read-only actions (status/history) use `GET` and are safe. |
| **SQL injection** | OK | Every value is bound (`PDO::ATTR_EMULATE_PREPARES => false`, `libs/gtf.php:94`); the only interpolated identifiers are hard-coded table/column names and `LIMIT` values clamped with `max(1, min(...))` (`libs/gtf_card.php:67-70, 397, 407`). `find()` rejects malformed ids before SQL (§R5). Migration DDL names come from a fixed array. |
| **SSRF / outbound destination** | OK | Single outbound call site (`libs/gtf_client.php:136-160`), HTTPS-only scheme enforced, port forced to 443, no credentials/path/query/fragment, host charset validated, path allow-listed (`:93-102`), `CURLOPT_PROTOCOLS = CURLPROTO_HTTPS`. §R6: six hostile origins all refused. Note: an IP-literal origin (`https://127.0.0.1`) is accepted — it comes from deployment configuration, never from request input, so it is a hardening note, not a vulnerability. |
| **TLS** | OK, verified dynamically | `CURLOPT_SSL_VERIFYPEER => true`, `SSL_VERIFYHOST => 2` (`:145-146`); no `verify => false` anywhere in the diff. Loopback probe with an **untrusted** certificate: 3/3 checks — the failure is a transport error, the body is not parsed, no body leaks into the message. |
| **Redirect handling** | OK, verified dynamically | `CURLOPT_FOLLOWLOCATION => false`, `CURLOPT_MAXREDIRS => 0` (`:141-142`). The probe endpoint answered `302` with a valid JSON body at the target; the client refused with `HTTP status 302`, and the endpoint log shows the redirect target was **never requested**. |
| **Secrets handling** | OK | No credential literal in any reviewed file (checked against the fixture values); `.env.example` ships `GTF_PARTNER_ID`/`GTF_PARTNER_KEY`/`GTF_API_BASE`/`GTF_CARD_REQUEST_FORMAT`/`GTF_CRON_TOKEN` blank; admin UI shows only "đã cấu hình" (`views/admin/recharge-gtf.php:53`). Transport errors expose the cURL errno only (`libs/gtf_client.php:169-174`); §R7 confirms no card data, sign, host or body in the message. |
| **Card-data retention** | Mostly OK, one open question | Sealed with AES-256-GCM under a key derived from `SECRET_KEY` (`libs/gtf.php:146-187`), random 96-bit IV, tamper-detected (§R8); stored masked (last 3 chars, §R8); wiped on terminal/credited states (`libs/gtf_card.php:352, 391, 418-420`); the worker only reports orders still holding sealed data after 30 days (`cron/gtf-card.php:54-60`) — it **does not shred them**, so retention is unbounded for orders stuck in `REVIEW`. |
| **Logging** | OK | Only order ids, reasons, cURL errno and exception class names are logged (no PIN/serial/key/body). Log injection was considered and does not hold: any log line that includes a caller-supplied `request_id` is reached only after `find()` has validated `^[A-Za-z0-9]{4,64}$`, and `recordEvent()` hashes the id rather than storing it. |
| **Callback / reconciliation trust** | Weak by design, mitigable | Callbacks never credit (`libs/gtf_card.php:12-21`); only a `check` bound to our `request_id` may credit. But the callback is unauthenticated for writes (SEC-02), unbound for replays (SEC-03) and the `check` contract is unverified (SEC-04). |
| **Replay / race behaviour** | OK for money | Fresh dynamic evidence this session: `gtf_service_test.php` **93/0** (C12 out-of-order callback before the submit result → exactly one credit, PAID immutable; C13 two connections → stale row refused, lock time-out fails cleanly with no money moved), `gtf_credit_path_test.php` **75/0** (A1 replay refused, A2 raw duplicate rejected by the primary key, A7 race → one credit), `gtf_credit_path_mutation.php` **2/2** (with the unique key the duplicate is rejected; without it, accepted — proving the guard is load-bearing). |
| **Atomic wallet / ledger updates** | OK | One transaction, ledger insert first as the idempotency guard (`gtf_ledger.transid` is the PRIMARY KEY, `scripts/migrate-gtf.php:70-77`), row lock on the order (`FOR UPDATE`), user row locked, `$finalize` must flip the order state or the whole transaction rolls back (`libs/gtf.php:300-343`, `libs/gtf_card.php:331-364`). A3/A4/A8 exercise the rollback path and the debt/affiliate side-effects; both are inside the same transaction. |
| **Configuration defaults** | OK, verified dynamically | §R10: with every `GTF_*` variable absent, `card_enabled=false`, `credit_enabled=false`, `api_base=''`, `card_request_format=''`, `partner_id=''`, `partner_key=''`, `cron_token=''`; `.env.example` matches. No default origin, no default format, no working default credentials anywhere in the diff. |
| **payOS regression risk** | None observed | The diff modifies **no** payOS file and **no** `libs/legacy_payment_guard.php`; the 11 guard call sites are intact; `git diff` over `ajaxs/client/create.php`, `libs/helper.php`, `api/callback_card.php`, `libs/payos.php`, `api/webhook-payos.php`, `ajaxs/client/payos.php` is **empty**. The GachtheFast client endpoint deliberately does not inherit the payOS 410 rule (`ajaxs/client/gtf.php:5-7`) — that is the approved Gate-1 routing, not a regression. |
| **XSS (new views)** | OK | Every interpolated value in the three new views passes through `htmlspecialchars(..., ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8')`; script blocks use `json_encode(..., JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)`; the nav/sidebar diff interpolates only `base_url()` results and static strings. |
| **Web reachability of new files** | OK | `.htaccess` denies `vendor|docs|tests|scripts|migrations` and dotfiles; every new `libs/*` and `cron/*` file refuses to run without `IN_SITE`; `scripts/migrate-gtf.php:21-24` is CLI-only; `cron/gtf-*.php` require the token over HTTP (default blank ⇒ 403). Only `api/callback-gtf-card.php` is intentionally public. |

---

## 6. Backdoor / malware sweep (with an explicit negative-result caveat)

Searched across the 14 reviewed files (token-level where it mattered, to ignore comments):

| Pattern | Result |
| --- | --- |
| `eval(`, `assert(`, `create_function`, `preg_replace /e` | 0 |
| Variable `include`/`require`, variable function calls from input | 0 (`current` only: `call_user_func($finalize, $db)` — a private callback object, not input) |
| Backtick shell operator | 0 (29 raw backticks exist, **all inside comments**; verified with `token_get_all`) |
| `shell_exec/system/exec/passthru/popen/proc_open/pcntl_exec` | 0 (the only `exec` matches are `PDO::exec()` on hard-coded DDL) |
| Obfuscation: `base64_decode`, `gzinflate`, `str_rot13`, `hex2bin`, `chr()` chains | 1 `base64_decode` — `GtfSecret::open()` (`libs/gtf.php:174`, strict mode, decoded payload length-checked). No long encoded blobs, no `\x` escape runs (token-level heuristic over the reviewed files). |
| Filesystem persistence (`fwrite`, `file_put_contents`, `fopen`, `chmod`, `mkdir`, `rename`, `copy`) | 0 writes; the only matches are `fwrite(STDERR, …)` diagnostics in `scripts/migrate-gtf.php`. Nothing writes a PHP file, an `.htaccess`, a cron entry or a shell script. |
| New outbound destinations | exactly one: the configured provider origin, pinned (`libs/gtf_client.php`). No hard-coded provider host, no SMTP, no webhook to a third party. |
| Hidden access: new admin/user creation, session fixation, privilege writes | 0 — the diff never writes `users`, `settings`, `admins` or session state; the only privileged action is the CSRF-protected admin AJAX (SEC-01/SEC-06). |
| `$_REQUEST`, `extract()`, `$$var` | 0 |
| Credential exposure (private keys, tokens, real hostnames in test fixtures) | 0 — fixtures use `api.example.test` and a loopback endpoint; all secrets blank in `.env.example`. |
| `mail(` | 0 |

**Caveat, stated plainly:** these are negative results for the *searched patterns at this revision*. They
do not prove the absence of every conceivable backdoor (e.g. logic bombs, time-delayed branches,
code paths only reachable in production configuration, or code hidden in files outside the reviewed
surface). The repository-wide sweep for outbound destinations found only pre-existing CMSNT/provider
code (see §7). **No "backdoor-free" certification is given or implied.**

---

## 7. Known legacy licensing / provider behaviour vs unexplained behaviour

Nothing in the diff is unexplained. Everything found outside the diff falls into categories the owner
has already encountered:

1. **CMSNT licensing/support (pre-existing).** `libs/helper.php:1295` (`CMSNT_check_license`, a WHMCS
   client aimed at `https://client.cmsnt.co/`) has **zero callers**; the function actually used,
   `checkLicenseKey()` (`libs/helper.php:1443-1448`), returns `Active` unconditionally. `help.cmsnt.co`
   appears only inside admin help-text HTML (UI links), while `api.cmsnt.co/checkdomain.php` **is**
   called from `views/admin/product-api-add.php:45` / `product-api-edit.php` (the CMSNT per-domain API
   check). `models/is_license.php` sits in front of three admin screens. **This review did not modify,
   bypass or weaken any part of that** — it is reported as an observation for the owner, and it is not
   exercised by the GachtheFast code.
2. **Legacy payment providers (pre-existing).** `libs/helper.php:871-880` (`card24h()` →
   `api.sieuthicode.net`), `api.web2m.com` usages, `fpayment.co`, `toyyibpay.com`, plus the payOS
   client — all untouched by this diff and gated by `libs/legacy_payment_guard.php` when
   `PAYOS_ENABLED=true`.
3. **Product/feature helpers of the CMSNT script (pre-existing).** The `curl_*`/`file_get_contents`
   helpers and calls to Facebook, Telegram, `walletvalidator.com`, Google, CDNs, payment and
   FX-picture services are part of the purchased script's feature set (customer-support tools, social
   helpers, product APIs). They are outside the reviewed diff; the QA pass previously recorded the
   legacy P1s in these paths (`09-qa-test-matrix.md` §4).
4. **The GachtheFast diff itself:** one outbound destination (configured), no unexplained persistence,
   no dynamic code — see §6.

---

## 8. Retest log (commands and actual output, this session)

Runtime: PHP 7.4.33 CLI (WASM), MySQL 5.6.24 disposable, loopback TLS endpoint. `SHIM_VENDOR=1` injects
the test-only Dotenv stub.

```
# money-path suites (fresh runs in this session)
node php74.mjs envrun '{"GTF_TEST_DSN":"mysql:host=127.0.0.1;port=3316;dbname=gtf_test",...}' tests/gtf_service_test.php
    → gtf_service_test: 93 passed, 0 failed
node php74.mjs envrun '{"GTF_TEST_DSN":"...dbname=gtf_test",...}' tests/gtf_credit_path_test.php
    → gtf_credit_path_test: 75 passed, 0 failed
node php74.mjs envrun '{"GTF_TEST_DSN":"...dbname=gtf_mut_test",...}' tests/gtf_credit_path_mutation.php
    → mutation self-check: 2 detected, 0 NOT detected
        probe 1: with the unique key, a duplicate credit is rejected
        probe 2: without the unique key, the same duplicate is accepted

# security-review checks (fixture gtf_review_test, fake provider gateway)
node php74.mjs runhost /tmp/review/gtf_review_fixture.php '{}'
    → review fixture ready on mysql:host=127.0.0.1;port=3316;dbname=gtf_review_test
node php74.mjs runhost /tmp/review/gtf_review_checks.php '{}'
    → sec_review_checks: 37 expectations confirmed, 0 failed
        R1 25 forged callbacks rejected … 25 rows still written
        R2 3 identical forged callbacks → 3 rows (NULL defeats the composite unique key)
        R3 5 replayed valid signatures for unknown orders → 5 rows, 0 provider calls
        R4 8 replayed valid signatures on a known order → 8 outbound checks, 0 ledger rows
        R5 IDOR blocked; whitelist excludes sealed columns; malformed ids rejected
        R6 6/6 hostile origins refused (note: an IP-literal origin is accepted)
        R7 transport error = curl errno only, no card data/sign/host in the message
        R8 AES-GCM sealing, random IV, tamper refused, masked storage
        R9 operator credit of a never-verified order, 1000 typed → 1000 moved
        R10 bank stub refuses; no default origin/format; all flags off by default

# end-to-end probe of the real callback entry point (forged POST, 3 runs)
node php74.mjs runhost /tmp/review/callback_entry_probe.php '{...}'
    → HTTP_STATUS=400 / GTF_EVENTS_BEFORE=41 / GTF_EVENTS_AFTER=42   (repeated: 43, 44)

# TLS + redirect handling against the loopback endpoint (repo test, unchanged)
node php74.mjs probe tests/gtf_transport_probe.php '{...}' /tmp/gtfprobe/cert.pem:/etc/ssl/certs/ca-certificates.crt
    → gtf_transport_probe: 18 passed, 0 failed  (incl. "a redirect is NOT followed"; endpoint log shows the
      redirect target was never requested)
node php74.mjs probe tests/gtf_transport_probe.php '{...,"GTF_PROBE_EXPECT_TLS_FAILURE":"1"}' ...
    → gtf_transport_probe (tls-negative): 3 passed, 0 failed

# static sweeps
grep -nE '\beval\s*\(|\bassert\s*\(|create_function|base64_decode|gz(inflate|uncompress)|shell_exec|system\(|exec\(|passthru|proc_open|curl_|fopen|file_put_contents|chmod|mail\(' <reviewed files>
    → only PDO::exec on DDL, base64_decode in GtfSecret::open, curl_* in libs/gtf_client.php, fwrite(STDERR) in the migration
php token analysis over the reviewed files
    → no backtick operator, no encoded blobs, no hex-obfuscation
```

*Harness note:* the review scripts (`/tmp/review/*`, `/tmp/phpwasm/php74.mjs`, `/tmp/gtfprobe/*`) live
outside the repository and are ephemeral by design — they were deliberately not added to the checkout so
that this review leaves the reviewed diff exactly as it found it. Every check is described in §8 with
its expected observation, so it can be reconstructed in any environment with PHP 7.4 and a disposable
MySQL; `tests/gtf_service_test.php`, `tests/gtf_credit_path_test.php`,
`tests/gtf_credit_path_mutation.php` and `tests/gtf_transport_probe.php` are already in the repository
and cover the same mechanics.

**Not retested / not executable in this environment:** the admin AJAX endpoints with a live admin
session (SEC-01 is reproduced at the service layer, SEC-06 by reading), the legacy P1 paths, a
provider sandbox (SEC-04), and any production database or provider call — all forbidden by the brief.

---

## 9. Residual risk and limitations

* **No certification.** Static absence of the searched patterns is not proof of absence of backdoors;
  the review additionally had to substitute a test-only Composer stub for missing `vendor/` and a
  WASM PHP for a native build (no `proc_open`), so two shipped test files could not run unmodified.
* **Contract-dependent controls** (SEC-04) cannot be closed by code review or this environment: they
  need the provider's real answers plus a sandbox run.
* **Pre-existing risks remain live** while `PAYOS_ENABLED=false` (SEC-09) and the licence gate is a stub
  in this checkout (SEC-10) — both are owner decisions, outside this diff, and were deliberately not
  touched.
* **Deployment configuration is the last line of defence** for several findings (`GTF_CRON_TOKEN`,
  `GTF_API_BASE`, `GTF_ALLOW_CREDITING`): the code fails closed when they are empty, which is the
  shipped state, but a misconfigured deployment would expose SEC-02/SEC-03 immediately and SEC-01 as
  soon as crediting is enabled.

## 10. Release recommendation

1. **Keep the feature disabled.** Do not set `GTF_CARD_ENABLED=true` or `GTF_ALLOW_CREDITING=true`.
2. **Fix SEC-01 before any crediting is enabled** (it is the only P1) and retest with §R9 +
   `tests/gtf_credit_path_test.php`.
3. **Fix SEC-02/SEC-03 before enabling card submissions** (both are reachable while disabled).
4. Treat SEC-04 as an enablement gate: no automatic credit until the contract questions in
   `08-verification-and-owner-questionnaire.md` §8.4 are answered and verified against a provider
   sandbox.
5. Schedule the independent security review and authorised pentest (project phases 4–5) — this
   document is an adversarial read-only pass, not a substitute for them.
6. Lead report remains **NO-GO**.
