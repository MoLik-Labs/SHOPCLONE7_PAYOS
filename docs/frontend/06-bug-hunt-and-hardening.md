# 06 — Bug hunt round 2: fixes, convenience and safety hardening

**Date:** 2026-10-05 · **Branch:** `arena/01a1066c-shopclone7-payos` (no commit, no deploy)
**Targets:** `tools/license-portal/**` (portal) and `libs/licence_client.php` (shop-side client)
**Earlier work:** penetration test and its nine fixes are in `05-pentest-report.md`. The payment code and the
CMSNT licensing checks were **not** touched.

> **Follow-up:** `07-bug-sweep-and-ux-safety.md` continues this work (module split, six further bugs fixed,
> UX sweep). Read it for the current state of the portal.

This round asked for two things: *find any remaining bugs* and *keep improving convenience and safety*.
Seven bugs were found — including one that made a headline feature unusable — all are fixed and covered by
tests. Four convenience/safety features were added, and honest notes are included about two harness results
that initially looked like findings but were not.

---

## 1. Bugs found and fixed

| # | Severity | Bug | Evidence (before) | Fix | Evidence (after) |
| --- | --- | --- | --- | --- | --- |
| B1 | **High (functional)** | **The "Reset hardware ID" button never worked.** The dashboard form submitted the *masked* key (`ORBIT-4V92X-****-****-73`), which fails format validation. The admin "Force reset" button had the same defect | `POST /dashboard/reset` → `400 {"error":"invalid_key_format"}`; `POST /admin/override-reset` → same. Both reproduced against the running portal | Forms now submit the licence **record id**; handlers accept `key_id` (ownership-checked for customers) and still accept a full key for scripted use. The clear key is never rendered | dashboard reset → `200`; admin force reset → `302`; regression tests through the **rendered form**, not the API |
| B2 | Medium (security) | **`PORTAL_LOGIN_LIMIT=ten` silently disabled the login throttle** (`NaN` comparison) | 20 failed logins → **0 throttled** | `envInt()` validates and clamps every numeric setting, warns loudly and falls back | bad value → 10/15 min enforced again; the 12th attempt is throttled |
| B3 | Medium (availability) | **`RESET_COOLDOWN_DAYS=thirty` locked customers out permanently**: the first reset succeeded, then every later reset returned `429` with `days_remaining: null` forever | reproduced: `200` then `429 {"days_remaining":null}` | same `envInt()` guard, plus `resetState()` is defensive (a non-finite cooldown falls back to 30 and can never emit `NaN`) | fallback to 30 days; `days_remaining` is a finite number in range; second reset is refused for the normal reason |
| B4 | Medium (data integrity) | **`expires_days` accepted anything**: `abc` → key stored with `expires_at: null` (**never expires** — the opposite of the intent), `-5` → key already expired, `1e9` → year ~2.7 million | reproduced, values as stored | `parseExpiryDays()`: blank/`never`/`0` = no expiry, otherwise a whole number of days 1…3650; anything else is refused with an explanation and audited | bad values → `400` + "not created" page and **no** key written; `90` → expiry 90.0 days out; blank → `null` |
| B5 | Medium (safety) | **Disabling an account only locked the web login** — the account's licence keys still activated and validated (`200`) | `POST /v1/activate` → `200` for a disabled account's key | the atomic `bindHwid`/`touchValidation` operations now treat a missing or disabled owner as inactive | activation/validation → `403 revoked`; the customer's session is ended on disable |
| B6 | Low (availability) | **A malformed cookie produced `500 internal_error`** (`decodeURIComponent` throws on a truncated escape) | `GET /dashboard` with `Cookie: orbit_sid=%` → `500` | `parseCookies()` never throws on a bad escape | treated as "no session" → `302` to login; no 500 |
| B7 | Low (client) | **A backwards clock (or an edited store row) could extend the offline grace window** — a future `licence_last_check` made `now - last` negative | code inspection; `grace` maths with a future timestamp grants more than the configured days | cached state refuses a timestamp more than 5 minutes in the future and clamps the reported remaining days at 0 | new client tests: future-dated cache → `stale`, gate closed; 60 s of skew tolerated |

Two further weaknesses were fixed while testing:

* **Duplicate accounts could be created in a race** (check-then-insert). `insertAccount()` now refuses a
  duplicate **inside the mutation**; the new admin UI surfaces it as "already exists".
* **A failed disk write left memory and file disagreeing** (reads would report a change that was never
  persisted). A failed persist now reloads from disk and rethrows.

## 2. Convenience and safety added

1. **The owner can run the portal without a shell.** Render's free plan has no shell, so the CLI-only
   account creation effectively made onboarding impossible in production. `/admin` now has:
   * **create a customer account** (email + optional password; blank generates one shown **once**),
     with an admin role option and email/length validation;
   * an **accounts table** — role, status, key count, created date;
   * **disable / enable** an account (disable signs the account out immediately and now also stops its shops);
   * **new password** (one-time display; the old password and all existing sessions stop working).
   There is still **no public sign-up** — accounts are owner-created, as decided in round 5.
2. **Key revocation.** A leaked or refunded key can be revoked (and restored) from the licence table; a
   revoked key immediately stops activating and validating.
3. **Durability and recovery of the store.** Writes are now `fsync`ed before an atomic rename, the previous
   snapshot is rotated to `store.json.bak` by rename on every write (no copy, always one write behind), and
   `init()` recovers from the backup if the live file is ever unreadable — keeping the damaged file as
   `store.json.corrupt` for inspection instead of silently starting empty. The store file is tightened to
   `0600` (with a warning if the data directory is group/world accessible).
4. **Operator CLI + diagnostics:** `--help`, `--list-accounts`, `--disable-account`, `--enable-account`,
   `--reset-password`, `--revoke-key` / `--restore-key`, and `--verify` (which reports accounts, keys,
   sessions, audit size and data problems: orphaned keys, malformed hashes, duplicate emails, sessions for
   missing accounts).
5. **Startup safety warnings** for the two silent misconfigurations: HTTPS without trusted proxy headers
   (every visitor would share one rate-limit bucket) and a `PORTAL_ADMIN_PASSWORD` left in the environment
   after bootstrap.
6. **Search-engine hygiene:** `/robots.txt` disallows everything and every response (pages and API) carries
   `X-Robots-Tag: noindex, nofollow, noarchive`, so a customer portal never lands in a search index.

## 3. Verification (all commands run this session)

| Check | Result |
| --- | --- |
| `node --test tools/license-portal/test/portal.test.js` | **44 passed / 0 failed** (26 before this round; +18 regressions) |
| Black-box suite `/tmp/pentest/portal_pentest.js`, fresh process, secure default config | **46 attacks neutralised / 0 findings** |
| `/tmp/pentest/race_freq.js` (10 fresh unbound keys, no throttling) | **0/10** races, `rate-limited=0` |
| Live smoke test `/tmp/bughunt/live_smoke.js` against the running portal | **20 passed / 0 failed** (create account → sign in → issue key → activate → reset → revoke → restore → disable) |
| PHP lint over the repository | **235 files, 0 syntax errors** |
| `tests/licence_client_test.php` | **81 passed / 0 failed** (77 before; +4 for the clock-skew defence) |
| `node server.js --verify` | `readable: true`, 3 accounts, 88 keys, 63 sessions, **problems: none** |

## 4. Two harness results that were **not** findings (recorded for honesty)

Re-running the black-box suite against a **long-lived demo portal** started with `TRUST_PROXY_HEADERS=true`
(a Render-style setting I had used for the live preview) reported two problems that are artifacts of that
setup, not defects:

1. *"P1 IDOR on reset — status 401"* — the suite's own login returned `429` because the demo portal's
   per-address login limit (10 per 15 minutes) had already been consumed by my repeated manual logins to the
   same long-running process. With no session, the reset request is `401`. A fresh process reproduces `404`.
2. *"rate-limit bypass via X-Forwarded-For — 14/14 unthrottled"* — with proxy headers **trusted** and the
   suite talking to the app **directly**, a spoofed header legitimately wins, which is exactly why the
   setting must only be enabled behind a proxy. The secure default (`false`) reports 0/14, and the unit tests
   cover both directions: rotating the spoofable left-hand hops cannot move the bucket when trust is on.

Both are documented in the portal README and warned about at startup, so the failure mode is visible to an
operator rather than silently weakening the deployment.

## 5. Still not tested (do not read as passing)

* No deployed instance: Render's edge, real cold starts, real clients over the public internet.
* The atomic operations are serialized **inside one process**; the file store is single-writer. A
  multi-instance deployment (or the planned Neon/Postgres layer) must re-implement the same guarantees with
  database transactions — not tested here because that layer does not exist yet.
* No volumetric/DoS testing, no browser automation (the UI flows were exercised over HTTP with the real
  rendered forms), and no third-party audit.
* The payment code remains out of scope and untouched (`docs/gachthefast/10-security-review.md`).

## 6. Verdict

* **B1 is the one to remember:** an advertised, customer-facing feature (and its admin counterpart) was
  broken in the browser while every API-level test passed, because the tests supplied a full key that the UI
  never sends. The regression tests now drive the **rendered forms**, which is the boundary that matters.
* All seven bugs are fixed and covered; the black-box suite and the race measurements are unchanged
  (46/0, 0/10), so the earlier fixes still hold.
* The portal is now operable by the owner without a shell, can cut off a leaked key or a bad account in two
  clicks, survives a corrupted data file, and reports its own data problems on demand.
* **No go-live claim:** the storage layer (Neon) and the deployment steps in
  `docs/frontend/03-license-portal-design.md` §4 and `docs/frontend/04-licence-client-and-hwid.md` are still
  required.
