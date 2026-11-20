# 03 — Licence portal: design, decisions and go-live blockers

> **Security status:** the black-box penetration test, its findings and their fixes are in `05-pentest-report.md`. Nine issues (P2 ×4, P3 ×5) were found and fixed; the suite now reports 46 attacks neutralised / 0 findings. The second bug-hunt round — seven more bugs, store recovery/backup, and the admin account/key management features — is in `06-bug-hunt-and-hardening.md`.

Companion to `02-license-server-and-productization-requirements.md` (the requirements from round 3) and
`00-mapping-and-owner-questions.md` (frontend mapping). This document records **what was decided, what was
built, what was tested, and what still blocks real customers**.

---

## 1. Owner decisions (rounds 3–4, recorded verbatim where quoted)

| # | Decision | What was chosen |
| --- | --- | --- |
| 1 | Attribution | **Author = `Orbit Office`**; `By NggKhaiz` may appear in certain sections. **Non-negotiable** — the identity must not be removed. (Supersedes the earlier "remove copyright elements" request.) |
| 2 | Integration shape | Frontend prompts 01–04 are ported to **plain PHP views and adapted** to the shop; **PROMPT 05 (Orbit) keeps its values exactly**. No Node/Vite build for the shop. |
| 3 | Assets | Owner purchased the videos/clips: **rights confirmed**, self-host with the supplied files. |
| 4 | Code protection | **Encode later** (decided: not now). `02-…` §6 keeps the recommended release-artifact pipeline ready. |
| 5 | Licence stack | Delegated to engineering ("free, high-quality, fast"). **Chosen: Node 22 standard library only**, no npm dependencies, on Render. Keep-warm with the owner's **Uptime Kuma**. |
| 6 | First-run setup | "As secure, convenient and safe as possible" → the **one-time setup token + lock file** design in `02-…` §4 is adopted (not yet implemented). |
| 7 | Licence model | **HWID-bound activation** with a **key-management portal account**: the customer signs in with the account tied to their purchased key, resets the hardware ID themselves, **cooldown 15–30 days** between free resets, admin override for work-related/urgent cases. Contact = **Orbit Office**. |
| 8 | Backup | Must cover the **web source files** as well as data, restored by "running the source and resetting the HWID via the portal". |

---

## 2. What was built (this turn)

`tools/license-portal/` — a complete, tested portal service. **No shop code was touched.**

| File | Purpose |
| --- | --- |
| `server.js` | HTTP service: `/v1/activate`, `/v1/validate`, `/healthz`, `/login`, `/dashboard`, `/dashboard/reset`, `/admin`, `/admin/create-key`, `/admin/override-reset` |
| `store.js` | File-backed store (atomic writes, `0600`), scrypt passwords, key generation/validation/masking, HMAC-signed licence tokens, HWID reset arithmetic |
| `html.js` | Dependency-free pages (no external assets, strict CSP, no inline handlers) |
| `test/portal.test.js` | **17 tests** — see §3 |
| `render.yaml` | Render blueprint (free plan, health check, env vars, paid-disk note) |
| `README.md` | Run/deploy instructions, API contract, security notes, explicit "not implemented yet" list |
| `.gitignore` | Keeps `data/` and any local `.env` out of the repository |

> **Update (2026-10-05, later rounds):** `server.js` no longer holds the whole service. It was split into
> `config.js`, `http.js`, `routes.js`, `store.js`, `html.js`, `cli.js`, `accounts.js` and `settings.js`
> (`server.js` is now 135 lines of wiring only), and the guards/CSRF/rate-limit blocks were consolidated
> into shared helpers. The current module map, with real line counts and the 63-test suite, is in
> `tools/license-portal/README.md`; the round report is `07-bug-sweep-and-ux-safety.md`. The table below
> describes the shape of the first implementation, kept for history.

`.htaccess` was extended by one word: `tools` joined the deny list
(`RewriteRule ^(?:vendor|docs|tests|scripts|migrations|tools)(?:/|$) - [F,L,NC]`), so the portal source is
not served over HTTP by the shop. This is the only change to an existing file in this workstream.

### Security properties implemented (and how they are verified)

* Keys are **never stored or logged in clear** — only `sha256(key)` + a masked form. *Test 10 reads the raw
  store file and asserts the key and password do not appear in it.*
* Passwords: scrypt (N=16384, r=8, p=1), per-account random salt, timing-safe compare. *Test 3.*
* Licence tokens: HMAC-SHA256 over a base64url payload; a tampered payload or a wrong secret fails
  verification. *Test 2.* — the shop can therefore validate a licence **offline** within its grace window.
* Sessions: random 32-byte token, stored hashed, `HttpOnly` + `SameSite=Lax` (+`Secure` behind HTTPS),
  7-day expiry; **every** state-changing form requires the session CSRF token. *Tests 5, 8 (incl. the
  logout regression).*
* Role checks: issuing keys and force-resets require the `admin` role. *Test 9.*
* Rate limits: login 10/15 min, activate 30/min, validate 120/min, reset 5/hour. *Test 7.*
* The service **refuses to start** without a signing secret of at least 32 characters, so a misconfigured
  deploy cannot hand out unsigned licences. *Test 13.*
* HWID semantics: first activation binds; a different machine gets `hwid_mismatch`; a reset clears the
  binding and the next activation rebinds; a second self-service reset inside the cooldown is refused with
  `days_remaining`. *Tests 1, 5, 6, 8.*

---

## 3. What was tested, with real output

```
$ node --test tools/license-portal/test/portal.test.js
1..17
# tests 17
# pass 17
# fail 0
# duration_ms ~1.7 s
```

Full run (2026-10-05, Node v22.22.3, loopback only, temporary data directory, no network):

| # | Test | Result |
| --- | --- | --- |
| 1 | licence keys: format, checksum, masking, normalisation | PASS |
| 2 | licence tokens: signed, verifiable, tamper-evident | PASS |
| 3 | passwords: scrypt hashing verifies and rejects | PASS |
| 4 | HWID reset: cooldown arithmetic (30 days, and configurable 15) | PASS |
| 5 | activate: binds a key to the first HWID, then enforces it | PASS |
| 6 | validate: bound machine ok, others refused, revoked refused | PASS |
| 7 | self-service reset: requires login, respects the cooldown, rebinds later | PASS |
| 8 | logout: header form carries a valid CSRF token (regression) | PASS |
| 9 | login: rejects bad credentials and rate-limits attempts | PASS |
| 10 | admin: issuing keys requires the admin role; key shown exactly once | PASS |
| 11 | storage: keys and passwords are never written in clear | PASS |
| 12 | health: `/healthz` answers without auth and leaks nothing | PASS |
| 13 | public landing page: contact details come from configuration | PASS |
| 14 | accounts: CLI creation refuses duplicates and hashes the password | PASS |
| 15 | store: two writers never lose each other's updates (CLI while server runs) | PASS |
| 16 | doc/support links: admin-only, validated, escaped, live on the public page | PASS |
| 17 | config: refuses to start without a strong signing secret | PASS |

**Two defects found and fixed during this work** (both would have caused real incidents):

1. **Broken sign-out:** the form in the page header rendered an **empty CSRF token**, so signing out would
   always have failed with `403 csrf`. Fixed in `html.js` (`layout()` now takes the session token) and
   locked in by test 8.
2. **Lost writes between the CLI and a running portal:** the file store assumed a single writer. Creating
   a customer account while the service ran was silently discarded the next time the service persisted its
   own snapshot — observed live (`unknown_key`, then a missing account). Fixed: every mutation now takes an
   exclusive lock, **re-reads the file**, applies the change and writes atomically, and the server calls
   `maybeReload()` (nanosecond-resolution freshness check) before serving a request. Locked in by test 15.

### NOT tested here (and not to be reported as passing)

* **PostgreSQL store driver** — not written yet; the file driver is what the tests exercise.
* **Deployment on Render**, TLS, custom domains, Uptime Kuma keep-warm, and real cold-start behaviour.
* **The shop-side PHP client** (`libs/licence_client.php`) — not written yet.
* **First-run setup** — not written yet.
* **Backup/restore** — not written yet.

---

## 4. Go-live blockers for the portal

| # | Blocker | Evidence / consequence | Remedy |
| --- | --- | --- | --- |
| 1 | **Render's free web service has an ephemeral filesystem** and cannot attach persistent disks | Render's own docs and reviews; the JSON store would be **wiped on every deploy, restart and spin-down** — for a licence system that means every customer's binding disappears | Use a free PostgreSQL that does not expire (**Neon**/Supabase) or a paid disk. Until then the portal is a demo, not a production licence authority |
| 2 | **Render's free Postgres is deleted after 30 days + 14 day grace** | Unsuitable as the permanent key store | Prefer Neon/Supabase free Postgres; migrate the store driver (next build step) |
| 3 | **Cold starts (30–60 s)** | Activation from a customer's shop could time out | Uptime Kuma keep-warm (owner's plan) **plus** the shop-side client's generous timeouts, cached validation and grace window |
| 4 | **Signing secret rotation** | Rotating `PORTAL_SIGNING_SECRET` invalidates every outstanding licence token until the shops re-validate | Documented procedure; keeps working because validation is cached and re-validates on the next heartbeat |
| 5 | Contact details are placeholders | `CONTACT_*` env values are unset; the public page shows `support@example.com` | Owner supplies email/Telegram/Zalo/docs URL |
| 6 | Account creation flow | Admin page issues keys to **existing** accounts; there is no sign-up | Decide: owner-created accounts (recommended for a paid product) vs self-registration |

---

## 5. What the shop-side client must do (next build step, not yet written)

1. `libs/licence_client.php` — HTTPS only, pinned to the portal origin from configuration, TLS verification
   on, bounded timeouts (5 s connect / 10 s read, one retry for cold starts), no key in a URL, no key in
   logs, POST JSON.
2. Compute a stable **HWID** from the host (hostname, PHP version, install path, machine-id where readable,
   plus a per-install salt) and send it on activate/validate.
3. Cache the signed licence token and its expiry in the settings table; treat the HMAC signature as the
   source of truth; never accept an unsigned or expired token.
4. Failure matrix from `02-…` §3.2: **storefront and payments never depend on the portal**; only the admin
   features already gated by `models/is_license.php` do. Offline grace window (proposed 30 days) so a
   sleeping free instance cannot lock anyone out.
5. Tests with a **fake transport** (no live portal calls) plus a local stand-in server, following the same
   discipline as the payment suites: every command and its real output recorded, unavailable tests marked
   NOT RUN.

---

## 6. Suggested next steps (in order)

1. **Portal durability** — pick Neon or Supabase free Postgres and add a tested `postgres` store driver
   (the current file driver stays for local dev/tests). *Blocks real customers.*
2. **Shop-side licence client** + HWID computation + cached/graceful validation, with fake-transport tests.
3. **First-run setup** with a one-time token + lock file (`02-…` §4), reviewed before it is enabled.
4. **Backup/restore** covering the source files + database, encrypted, with the dry-run import rules in
   `02-…` §5.
5. **Frontend prompts 01–05** once the texts, assets and content pack arrive (plus the free first page: the
   `/dashboard` styles can be reused for the shop's pages).

Nothing here changes the payment position: GachtheFast stays **disabled**, the release verdict stays
**NO-GO**, and no payment code was modified.

---

## 7. Live verification (this session, loopback demo on port 8080)

The portal was started locally and driven end to end with the real HTTP API (no mocks), then the demo
state was left running for inspection:

```
$ PORTAL_ACCOUNT_PASSWORD=... node server.js --create-account customer@example.com
account created: customer@example.com

$ POST /login (admin) → session cookie + CSRF
$ POST /admin/create-key  email=customer@example.com
issued key: ORBIT-4V92X-YXEJV-S0QRQ-YXF4S-73      (shown once; stored only as sha256)

1. POST /v1/activate  key + hwid=demo-machine-A-01
   → 200 {"token":"eyJ2IjoxLCJzdGF0dXMiOiJhY3RpdmUi…","status":"active","bound":true}
2. POST /v1/activate  key + hwid=demo-machine-B-02
   → 403 {"error":"hwid_mismatch","message":"This licence is bound to another machine. …"}
3. POST /dashboard/reset (customer session + CSRF)
   → 200 {"status":"reset","next_reset_at":1793780388677}
4. POST /dashboard/reset again
   → 429 {"error":"cooldown","days_remaining":30,"next_reset_at":1793780388674}
5. POST /v1/activate  key + hwid=demo-machine-B-02
   → 200 {"status":"active","bound":true}          (rebound after the reset)

GET /healthz → {"ok":true,"time":"2026-10-05T08:19:05.003Z","version":"1.0.0"}
GET /        → public page renders the configured contact (orbitoffice@example.com)
```

The demo password, signing secret and data live **outside the repository** (`/tmp/orbit-portal-demo`) and
are demo-only values; nothing secret is committed.

---

## 8. Round 5: decisions applied, support links shipped, shop client built

**Decisions (owner, round 5):** Neon free Postgres for the key store · owner-created accounts ·
30-day reset cooldown · build the shop-side client next · the portal is also the **sales/support front
door**, with admin-editable social links so a banned account can be swapped instantly.

**Shipped in the portal this round:**

* `GET/POST /admin/settings` — admin-only, CSRF-protected editing of **Telegram, Facebook, Discord,
  WhatsApp, Zalo, Email, Website, Docs** links plus a short sales note. Blank hides a link; only
  `http(s)` URLs (and a valid email) are accepted; rejections are reported and store nothing; every save
  is audited; output is escaped.
* The public landing page renders only configured links and explains that the details stay current —
  covering the "account gets banned" continuity requirement without code changes.
* Settings now live in the store (`settings` key of the store document), with the environment
  `CONTACT_*` values as a fallback for a fresh install.

**Shop-side client:** see `04-licence-client-and-hwid.md` — `libs/licence_client.php`,
`scripts/licence-check.php`, `tests/licence_client_test.php` (**77/77**), the whole failure matrix, and a
**live 11/11 integration run** against this portal over TLS. Three real defects were found and fixed by
that work (brittle env lookup, a JSON list accepted as a response object, and a mis-mapped portal
`invalid_request`).

**Still blocking go-live** (unchanged, now with owners):

| # | Blocker | Owner |
| --- | --- | --- |
| 1 | **Durable store** — Neon Postgres driver not written; the file driver is wiped by Render restarts | Engineering (needs the Neon URL) |
| 2 | Wiring the client into `models/is_license.php` (behind `LICENCE_ENFORCE`, with the recovery path) | Engineering + owner approval |
| 3 | Real contact details on the public page (placeholders until the owner sends them; editable meanwhile) | Owner |
| 4 | Checkout decision: contact-to-buy (today) vs online payment | Owner |
| 5 | First-run setup page + encrypted backup/restore (specified, not built) | Engineering |
| 6 | The GachtheFast payment workstream stays **disabled / NO-GO** — untouched by all of the above | Owner (contract answers, SEC-01) |
