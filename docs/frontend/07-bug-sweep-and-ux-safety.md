# 07 — Bug sweep, convenience and safety pass (licence portal)

**Date:** 2026-10-05
**Scope:** our own new code — `tools/license-portal/**` (Node, zero dependencies) and the new PHP licence
client `libs/licence_client.php` + `tests/licence_client_test.php`. The legacy shop, the payment paths, the
payOS integration and the CMSNT licensing checks were **not touched**. Nothing was committed, pushed or
deployed.
**Owner brief:** "Check for any remaining bugs and continue enhancing convenience and safety."

Everything below was run in this sandbox against loopback instances with temporary or demo data. Every
number is a command result from this session, not an estimate.

---

## 1. What this pass changed, in one table

| Area | Change | Why |
| --- | --- | --- |
| Bugs | 7 defects found and fixed, each with a regression test (§2) | correctness, durability, honesty of the UI |
| Customer convenience | licence **expiry** column, **last sign-in**, **sign out of all devices**, support links on the sign-in page | a customer can see and control their own licence |
| Owner convenience | install metadata per key (domain, PHP, shop version, first domain), mismatch counter + last refused domain | support answers "which site is this key on?" and "is my key being shared?" |
| HTTP hygiene | HTML 404 for browsers / JSON for API clients, `405` + `Allow`, `HEAD` support, `Retry-After` on `429`, inline `/favicon.svg` | a typo no longer looks like a crash; monitors and integrators get correct signals |
| Support links | `@handle`, phone numbers, `discord.gg/x`, bare `t.me/x` are normalised; hostile values still refused; rejections in plain language | the owner's contact channels must survive an account ban without a code change |
| Data hygiene | expired sessions pruned at startup; check-ins no longer rewrite the file every time | the store stays small and a valid key cannot force disk churn |
| Docs | README (module map, real line counts, 63 tests, install metadata), this report, cross-references in `03` and `05` | the next reader should not have to re-derive any of this |

---

## 2. Bugs found and fixed (each has a test in `test/portal.test.js`)

| # | Bug | Evidence it existed | Fix | Test |
| --- | --- | --- | --- | --- |
| 1 | The test suite was **cwd-dependent**: `node --test test/portal.test.js` from `tools/license-portal` failed (the `--help` test spawns the server by a repo-root-relative path) | suite red in 3 consecutive runs from the portal directory, green from the repo root | resolve the entry point from `__dirname`; a failing `--help` now prints the child's stderr | `convenience: --help and the operator commands exist` |
| 2 | **Sign-out cookie bug:** `setSessionCookie(res, '', 0)` treated `0` as falsy and re-issued a **7-day** `Max-Age` instead of clearing the cookie | new test printed `orbit_sid=; …; Max-Age=604800` | explicit `maxAgeSec === undefined` check + `Expires=Thu, 01 Jan 1970 …` | `safety: "sign out of all devices" ends every session of that account` |
| 3 | **Owner contact shorthands were silently dropped**: `CONTACT_TELEGRAM=@orbitoffice` (and `84 912 345 678`, `discord.gg/abc`) never reached the contact card | live page rendered the e-mail but no Telegram row | per-platform normalisation to canonical `https://…` URLs, applied to admin input **and** environment values | `settings: contact shorthands are normalised, hostile values are refused`, `settings: an environment handle appears as a usable link on the public page` |
| 4 | **Sessions were never pruned**: `pruneSessions()` existed but was called from nowhere, so one row per sign-in stayed in the file for ever | code inspection; demo store had 76+ session rows | prune on `init()`, and only write when something was actually dropped | `store: expired sessions are refused and cleaned up at startup (no unbounded growth)` |
| 5 | **Write amplification:** every check-in rewrote + `fsync`ed the whole store; a caller with a valid key could force ~120 full rewrites per minute (validate limit 120/min) | probe: `3 identical check-ins -> file rewritten each time` | check-in stamps throttled to one write per key per 10 minutes; a **changed** domain is still written immediately | `store: routine check-ins do not rewrite the data file (write throttle)` |
| 6 | The new metadata/mismatch throttle was a **no-op** — it decided inside `#mutate`, which always persists | code inspection (`#mutate` → `#persist` runs regardless of the callback's result) | decide from in-memory state *before* entering the mutation; the no-op path returns without touching the queue | same test + `safety: repeated mismatches are counted once a minute, not once per request` |

| 7 | **Broken error contract:** `accounts.js` threw a code-less `Error('account already exists')` while `routes.js` branches on `err.code === 'DUPLICATE_ACCOUNT'`, so a duplicate detected by that layer fell through to the generic "could not be created" message | code inspection during the final consistency pass | `createAccount` now throws the same `code` the store does | `accounts: a duplicate reports the same error code from either layer` |

Two smaller cleanups in the same spirit: a **dead copy of the CLI usage text and `argValue` helper** in
`accounts.js` (left behind by the module split; `cli.js` owns them) was removed, and the CLI help listed
`TRUSTED_PROXY_IPS` twice.

One deliberate behaviour change: a **refused** activation used to overwrite "Installed on" with the domain
of the machine that was turned away. The working install stays authoritative (`last_domain`), and the
refused machine is recorded separately (`last_mismatch_domain`) and shown as *last refused: …* next to the
mismatch pill. The existing test was updated to the new, intended semantics.

---

## 3. Convenience and safety enhancements

**Customer-facing (dashboard, `/login`)**

* **Expiry** column — a licence with an end date is no longer invisible to its owner (the admin table always
  showed it).
* **Last sign-in** line — "if that was not you, use the sign-out card below and ask Orbit Office for a new
  password". Only a timestamp is stored; the portal deliberately keeps **no visitor IP addresses**.
* **Sign out of all devices** (`POST /dashboard/signout-all`) — ends every session of the account, clears
  the cookie, and writes an audit entry. CSRF-protected; refuses anonymous callers.
* The **sign-in page now shows the support links**, so a locked-out customer still has a way to reach the
  owner — the case that matters most when a channel has been banned.

**Owner-facing (`/admin`, `/v1/*`)**

* Each key now carries **where it is installed**: last domain, first domain seen, PHP version and shop
  version (as reported by the shop on activate/validate, escaped on render, single-line, length-capped).
* **Mismatch counter** — a machine other than the bound one is refused *and counted*; the admin table shows
  `N mismatch(es)` and the last refused domain. Writes are throttled to one per key per minute so a flood
  cannot turn into disk abuse.
* Support-channel shorthand normalisation (see bug 3) and plain-language rejection messages with the
  machine code kept in parentheses for support.

**HTTP surface**

* `405` + `Allow` for a known path with the wrong method (was a misleading `404`).
* `HEAD` is served from the matching `GET` route — an uptime monitor can probe `/healthz` cheaply.
* Unknown paths answer **HTML for browsers, JSON for API clients**; `/favicon.svg` is inline (no binary in
  the repo, no CDN request); rate-limited responses carry `Retry-After` with a human sentence on the login
  page.

---

## 4. Evidence chain (all re-run on the final code)

| # | Command | Result |
| --- | --- | --- |
| 1 | `node --test tools/license-portal/test/portal.test.js` | **64/64 pass** — also green when run from `tools/license-portal`, 14 consecutive runs |
| 2 | `PENTEST_KEY=… PENTEST_RACE_KEY=… node /tmp/pentest/portal_pentest.js` (fresh process, secure default) | **46 attacks neutralised, 0 findings** — re-run on the frozen code after the last change |
| 3 | `node /tmp/pentest/timing_and_reset.js` | timing: **no usable side channel** (median difference 0.1–0.3 ms, ratio 1.00); parallel reset **200 + 429**, `recorded resets on disk: 1` |
| 4 | `node /tmp/pentest/race_check.js` (fresh process) | parallel activation serialised (`403 / 403`), both machines refused afterwards — re-run on the frozen code. The demo store's key has already spent its reset cooldown, so this run reports `429 / 429` for the parallel reset (also correct); the `200 / 429` measurement above came from the private harness with a freshly seeded key |
| 5 | `node /tmp/pentest/race_freq.js` | `trials=10 rate-limited=0 race reproduced=0` |
| 6 | `node /tmp/bughunt/live_smoke2.js` (live journey: account → key → activate → reset → re-bind → revoke → password reset → disable) | **26/26 pass** |
| 7 | `node /tmp/bughunt/live_smoke.js` | 20 pass, 0 fail (each smoke suite was re-run against a **freshly started** server; see the note below) |
| 8 | `node server.js --verify` (demo store) | 8 accounts, 133 keys, 88 sessions, **problems: none** |
| 9 | `node /tmp/phpsetup/batch.mjs <repo> <spec>` — token-based syntax check of every PHP file | **235 files, 0 failures** (211 tracked + 24 untracked new files) |
| 10 | same batch, `tests/licence_client_test.php` | **81 passed, 0 failed** |
| 11 | write probe (`/tmp/write_probe.js`) | 3 identical check-ins → **file not rewritten** (bug 5 fixed) |

Probe 3's harness had two defects of its own that were fixed in the same session (it probed a server it had
just killed, and its proxy-trust section could not discriminate its claim at the request count it used).
The proxy question is now answered by an explicit A/B on two fresh processes:

* `TRUST_PROXY_HEADERS` unset, 10 rotating `X-Forwarded-For` headers, login limit 5 → **5 of 10 throttled**
  (headers ignored, one shared bucket).
* `TRUST_PROXY_HEADERS=true` → **0 of 10 throttled** (one bucket per header).

That is the documented trade-off: the header may only be trusted when a real proxy sets it, which is why the
portal warns at startup and `render.yaml` sets it for Render.

**Note on running the smoke suites:** the login throttle is per client address (10 per 15 minutes by
default), so a smoke suite run twice against the same long-lived process starts reporting failures that have
nothing to do with the code — the first re-run of `live_smoke.js` in this session showed 4/20 for exactly
that reason, and 20/20 on a fresh process. Restart the server (or raise `PORTAL_LOGIN_LIMIT`) between runs.

**Honesty note on old artefacts:** `/tmp/bughunt/repro3.js` is the *pre-fix* reproduction from the earlier
round (it submits `name="key"`, the payload the template no longer produces). Running it today correctly
returns `400 invalid_key_format`; it is a "before" artefact and must not be cited as a pass. The current UI
path is covered by the two `bug: …works through the rendered form` tests and by probes 6–7.

---

## 5. What remains untested or open (no production-readiness claim)

* **No headless browser** exists in this sandbox: pages are verified by HTML-level assertions and a scripted
  journey, so layout/CSS regressions would not be caught. A real browser check is still owed before go-live.
* **Storage:** the durable PostgreSQL/Neon driver is still design-only (`03` §4). The free Render disk is
  ephemeral, so this pass does not make the portal production-ready by itself.
* **Single-instance assumption:** the lock + reload file store is safe for one writer; horizontal scaling
  needs the database driver, not more file locking.
* **PHP client:** unchanged in this pass. Its last live end-to-end exercise against the portal (WASM PHP 7.4
  with a trusted CA, plus the forged-CA refusal) predates this session; it was not re-run today, so the
  TLS/forged-CA result is still last turn's evidence, not this one's.
* **Timing measurement** is loopback-only on this host; it says nothing about latency differences on Render.
* **`bindHwid` still writes on every activation** (rare, bind-time only). Deliberately left alone: an
  activation that binds a machine is exactly the event worth persisting immediately.
* Not implemented (owner decisions stand): self-service sign-up, e-mail delivery, two-factor, admin UI for
  account creation is not needed — accounts are owner-created and the portal has a one-time-password flow
  plus the CLI.
* No commit, push, deploy or live provider call was made in this round.
