# 04 — Shop-side licence client, hardware binding and operations

> **Security status:** the client's transport and trust decisions were probed against a forged licence server (untrusted certificate → refused, nothing stored; trusted certificate control → accepted) — see `05-pentest-report.md` §4.1.

Companion to `03-license-portal-design.md` (the portal service) and
`02-license-server-and-productization-requirements.md` (requirements). This document covers the **PHP
side**: what was built, how it behaves when the portal is asleep or hostile, the evidence, and what is
deliberately not wired yet.

---

## 1. Decisions locked (round 5)

| # | Decision | Choice |
| --- | --- | --- |
| 1 | Key store | **Neon free Postgres** (never expires, unlike Render's free Postgres which is deleted after 30 days + 14 day grace). Driver still to be written — see §7. |
| 2 | Accounts | **Owner creates them** (`--create-account` on the CLI, then issue the key in `/admin`). No self-registration. |
| 3 | Reset cooldown | **30 days** (already the default; admin override always available and logged). |
| 4 | Next build step | **The shop-side client** — this document. |
| 5 | Contact/support links | Admin-editable from the portal (`/admin/settings`), so a banned Telegram/Facebook/Discord account can be replaced without touching code. **Implemented this turn** (§6). |
| 6 | Portal as a sales platform | Noted as the owner's goal. The public page already routes buyers to the configured channels; **online checkout was not built** — that needs a payment decision first (§8). |

---

## 2. What was built (this turn)

| File | Purpose |
| --- | --- |
| `libs/licence_client.php` | The client: config, hardware fingerprint, HTTPS transport, state machine, cache/grace, gate decision. ~470 lines, no dependencies. |
| `scripts/licence-check.php` | CLI: `php scripts/licence-check.php [--activate] [--json]`. Prints state, masked key, hardware ID, gate. Exit 0 = active, 1 = not active, 2 = misconfigured. |
| `tests/licence_client_test.php` | **77 offline checks** — fake transport, array-backed store, no DB, no network. |
| `.env.example` | New `LICENCE_*` section with **blank** placeholders and the operational notes. |

**Nothing else in the shop changed.** `models/is_license.php`, `checkLicenseKey()` and every CMSNT check
are untouched; the payment code (payOS, GachtheFast, legacy) is untouched and still disabled/NO-GO.

### How the client behaves (the failure matrix, as implemented)

| Situation | Client state | Gated admin features | Storefront + payments |
| --- | --- | --- | --- |
| No key entered | `unconfigured` | locked | **unchanged, fully working** |
| Portal answers `active` | `valid` | unlocked | unchanged |
| Portal says `unknown_key` / `invalid_key_format` / `invalid_request` | `invalid` | locked | unchanged |
| Portal says `revoked` / `expired` | `revoked` | locked | unchanged |
| Portal says `hwid_mismatch` | `hwid_mismatch` | locked, with the "reset hardware ID" instruction | unchanged |
| Portal unreachable, last success **inside** the grace window | `valid_cached` | unlocked (and the message says how many days remain) | unchanged |
| Portal unreachable, last success **older** than grace, or `grace_days=0` | `stale` | locked | unchanged |
| Portal unreachable, nothing cached | `unreachable` | locked | unchanged |
| HTTP 200 without `status:"active"` | `error` | locked | unchanged |

Safety properties, all covered by tests: the portal URL must be a **bare HTTPS origin on 443** (plain
HTTP, a path, a query, credentials and non-443 ports are refused); the transport re-checks the origin
before opening a socket and never follows redirects; TLS certificate verification stays on (verify peer +
verify host 2); the response is capped at 64 KiB; the only endpoints the client can call are
`/v1/activate` and `/v1/validate`; the key is never logged and only ever displayed in masked form; the
portal token is stored opaquely and is never interpreted locally.

---

## 3. Test evidence (this session, real PHP 7.4.33)

Runtime: PHP 7.4.33 WASM CLI (`curl 7.69.1 / OpenSSL 1.1.1t`), no native PHP in the sandbox. Harness
lives outside the repository.

### 3.1 Syntax + unit suite

```
$ node /tmp/phpsetup/batch.mjs <repo> <spec>
LINT 235 passed, 0 failed                 # every PHP file in the checkout, token_get_all(TOKEN_PARSE)
licence_client_test: 77 passed, 0 failed  # fake transport, no DB, no network
```

Coverage: config validation (8 rejection cases), timeout/grace clamping, enforcement flag, key
normalisation/masking, hardware-ID determinism and sensitivity, endpoint allow-list and origin pinning,
malformed/oversized body handling, all 5 portal verdicts, a 200 that is not `active`, a throwing
transport, the whole grace-window matrix, and the store contract.

### 3.2 Live integration: the real client against the real portal over real TLS

A loopback TLS endpoint (own CA, `IP:127.0.0.1` SAN) in front of the portal service, the test CA installed
at curl's default trust path inside the WASM filesystem, and a key issued by the portal:

```
portal: https://127.0.0.1
  ok   configuration parses the portal URL
  ok   activation against the live portal succeeds        # activate -> valid
  ok   activation is reported as online
  ok   the gate unlocks after a live activation
  ok   the portal token was cached
  ok   a second check validates the bound machine
  this machine:   hwid-fdbc1a75b39273654da6ecd26a86e3da86629d2c
  other machine:  hwid-fd8d930510919b627fb6c1cd49e15da1aa97e01e
  ok   a different hardware id is refused by the portal   # -> hwid_mismatch, gate locked
  ok   an unknown key is rejected by the portal           # -> invalid
licence_portal_e2e: 11 passed, 0 failed
```

**Defects found by that integration run and fixed in the product:**

1. **Environment lookup was brittle.** The first version merged `$_ENV` with `getenv()` (no arguments).
   In this runtime `getenv()` returns only the *base* environment, so request-scoped variables were
   invisible and the client reported `unconfigured` while the value was clearly set. Fixed with a layered
   per-key lookup — `getenv($name)` first (matching the `.env` loader and the rest of the codebase),
   then `$_ENV`, then `$_SERVER` — locked in by the unit tests and by the live run.
2. **A JSON list was accepted as a response object.** `licence_parse_body()` now requires the body to
   start with `{`, so `[1,2,3]` and scalars are treated as malformed.
3. A portal `400 invalid_request` was surfaced as an internal error; it is now mapped to `invalid`
   (the portal refuses to validate that key), while genuinely unexpected responses still report `error`.

### 3.3 Not tested (do not report as passing)

* Wiring the client into `models/is_license.php` — **not done, by design** (§5).
* The Postgres store driver (not written yet).
* Any deployed portal (Render), real cold starts, real DNS/TLS chain, or a real customer machine.
* The first-run setup page and backup/restore (not written yet).

---

## 4. Operational use

```bash
# first run on a new server (binds this machine to the key)
php scripts/licence-check.php --activate

# routine check (or from cron, once a day)
php scripts/licence-check.php --json
```

The CLI never prints the key, writes only the licence cache keys in the `settings` table
(`licence_state`, `licence_last_check`, `licence_token`, `licence_expires`, `licence_install_salt`), and
is CLI-only. `licence_install_salt` must be preserved across migrations/backups — losing it changes the
hardware ID and would require a portal reset (the 30-day cooldown applies to self-service resets).

Recommended cron: `0 4 * * * php /path/to/shop/scripts/licence-check.php --json >/dev/null 2>&1`.

---

## 5. Deliberately not wired yet

The client library is additive and **changes no behaviour while it is not included**. Two things remain
before a key can actually gate anything:

1. **Including it in `models/is_license.php`** (the file that already shows the key form and is included by
   `views/admin/settings.php:13`, `product-api-add.php:14`, `product-api-edit.php:22`,
   `recharge-bank-config.php:20`, `recharge-momo.php:23`) and replacing the always-`Active` stub in
   `checkLicenseKey()` with the real state — behind `LICENCE_ENFORCE=true`, with the current permissive
   behaviour kept as the rollback.
2. **Owner approval + a rollback drill.** That edit can lock the settings page, which is the page that
   stores the key, so it ships with: a documented recovery path, a CLI check that never blocks, tests, and
   a rehearsal on staging. This is the next code change, not this one.

---

## 6. Portal: admin-editable support links (implemented this turn)

`GET/POST /admin/settings` on the portal, admin-only and CSRF-protected:

* Eight channels — Telegram, Facebook, Discord, WhatsApp, Zalo, Email, Website, Docs — plus a short sales
  note. Blank field = link hidden.
* Only `http(s)` URLs (and a valid email for the email channel) are accepted; anything else is rejected
  with the reason shown, and **nothing is stored** on rejection. HTML/script payloads are refused (the
  earlier `https://"><script>…` probe was rejected as `invalid_url`).
* Values are escaped on output; a stored value can never become markup.
* Every save writes an audit row (who, when, which channels).
* The public page renders only the configured links, so when an account is banned the owner replaces one
  URL and every customer immediately sees the new channel.

Portal tests now: **17 passed, 0 failed** (`node --test tools/license-portal/test/portal.test.js`).

---

## 7. Next steps, in order

1. **Neon Postgres store driver** for the portal (owner's decision this round). Needs the connection URL
   from the owner's Neon project, out of band. Until then the file driver is demo-only: Render's free
   filesystem is wiped on every restart/deploy.
2. **Wire the client into the licence gate**, behind `LICENCE_ENFORCE`, with the approval and drill in §5.
3. **Deploy the portal** (Render blueprint in `tools/license-portal/render.yaml`) + Uptime Kuma
   keep-warm on `/healthz` + create the first admin and the owner's account.
4. **Backup/restore** (encrypted JSON for data + source files) and the **first-run setup page**
   (one-time token + lock file), both specified in `02-…` §4–§5.
5. **Frontend prompts 01–05** once the prompt texts, assets and content pack arrive.

### Still open for the owner

| # | Question | Why it matters |
| --- | --- | --- |
| O1 | Neon connection URL (out of band) | Unblocks the durable store + deployment |
| O2 | Prices/plans and whether checkout is **online payment** or **contact-to-buy** | The portal is currently contact-to-buy; adding real payments needs a provider decision (and the payment workstream is NO-GO until the contract issues are resolved) |
| O3 | Real contact details (email / Telegram / Zalo / docs) | The public page still shows placeholders; they are editable in `/admin/settings` meanwhile |
| O4 | Approval to wire the gate (`LICENCE_ENFORCE`) | Last step before the licence actually locks admin features |
