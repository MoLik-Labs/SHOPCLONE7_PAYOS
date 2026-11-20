# Orbit Office — licence portal

Licence activation, HWID binding and self-service resets for shops running this source code, hosted on
Render (free tier). **Zero runtime dependencies** (Node standard library only), so it cold-starts fast on
a sleeping free instance.

Design and open decisions: `docs/frontend/03-license-portal-design.md`.

## Run locally

```bash
cd tools/license-portal
export PORTAL_SIGNING_SECRET="$(node -e "console.log(require('crypto').randomBytes(48).toString('base64url'))")"
export PORTAL_DATA_DIR="./data"
export CONTACT_EMAIL="you@example.com"
# first admin (password comes from the environment, never from argv)
PORTAL_ADMIN_PASSWORD='choose-a-strong-one' npm run create-admin -- admin@example.com
npm start              # http://localhost:8080
```

Tests (loopback only, temporary data dir, no network):

```bash
npm test          # 64 tests: routes, guards, storage, UX and the security regressions
```

## Code layout

`server.js` only owns the socket and the process lifecycle; everything else is a small module with one
job. Adding a route means touching `routes.js` and `html.js`, nothing else.

| File | Lines | Responsibility |
| --- | --- | --- |
| `server.js` | 135 | listen socket, timeouts, HSTS, the HTML/JSON 404 split, `main()` wiring — no business logic |
| `config.js` | 68 | every environment variable, with safe defaults and validation |
| `http.js` | 256 | responses (`json`/`html`/`text`/`redirect`, 404/405/429 helpers), body limits, cookies, client IP, rate limiter |
| `routes.js` | 740 | pages and the `/v1` API; shared `requireAdmin` / `requireUser` / `formWithCsrf` / `allow` guards |
| `store.js` | 658 | JSON store: atomic writes, hashing, key/HWID/reset/cool-down rules, write throttling |
| `html.js` | 383 | server-rendered pages (no template engine, no client-side JavaScript) |
| `cli.js` | 163 | operator commands (`--verify`, `--list-accounts`, …) |
| `accounts.js` | 62 | account operations shared by the HTTP routes and the CLI |
| `settings.js` | 110 | admin-editable support/sales links, incl. `@handle` / phone shorthand normalisation |
| `test/portal.test.js` | 1353 | the 64 tests |

The whole service is ~2.5k lines of source plus the test suite — deliberately small enough to audit in one
sitting, which is what lets this file list every module and every route.

## Deploy to Render

1. Push this repository, then Render → **New → Blueprint** → pick this repo, root directory
   `tools/license-portal` (or deploy the service manually with the same settings: runtime `node`, build
   `npm install --omit=dev`, start `node server.js`, health check `/healthz`).
2. Set `PORTAL_SIGNING_SECRET` (Render can generate it), `PUBLIC_BASE_URL=https://<service>.onrender.com`,
   the `CONTACT_*` values, and `RESET_COOLDOWN_DAYS` (15–30; default 30).
3. Create the first admin: set `PORTAL_ADMIN_EMAIL` + `PORTAL_ADMIN_PASSWORD`, boot once, then **remove
   `PORTAL_ADMIN_PASSWORD`** from the dashboard and redeploy. (Or run `npm run create-admin` in the
   Render shell — not available on the free plan, so the env bootstrap above is the free-tier path.)
4. Add a **Uptime Kuma** monitor on `https://<service>.onrender.com/healthz` every 5–10 minutes. This keeps
   the free instance awake so activation does not wait 30–60 s for a cold start.
5. **Storage:** the free plan has an ephemeral filesystem, so the JSON file store is wiped by deploys and
   restarts. Before real customers, either attach a paid disk mounted at `PORTAL_DATA_DIR` **or** switch
   to a free PostgreSQL that does not expire (Neon/Supabase). See the design doc §4 — this is a
   go-live blocker, not a nice-to-have.

## API (what the shop calls)

```
POST /v1/activate   { key, hwid, domain, install_id, php, version }   # domain/php/version are recorded
                    → 200 { status:"active", bound, token }            (token is HMAC-signed)
                    → 403 { error:"hwid_mismatch" } | { error:"revoked" } | { error:"expired" }
                    → 404 { error:"unknown_key" } | 400 invalid_request
POST /v1/validate   { key, hwid, domain, php, version } → 200 { status:"active", token } | 403/404
GET  /healthz       → { ok:true, time, version }
```
Unknown paths answer with an HTML page for a browser and `{"error":"not_found"}` for an API client; a
known path with the wrong method answers `405` with an `Allow` header instead of `404`; `HEAD` is served
from the matching `GET` route, so an uptime monitor can probe `/healthz`. Rate-limited responses carry
`Retry-After`.

Browser pages: `/` (setup + contact), `/login` (with the support links, so a locked-out customer can still
reach the owner), `/dashboard` (licences, hardware ID, reset),
`/admin` (accounts, keys, revoke/restore, force resets, audit log), `/admin/settings` (support links),
`/robots.txt` (search engines are refused, and every response carries `X-Robots-Tag: noindex`),
`/favicon.svg`. The dashboard shows the licence expiry, the time of the last sign-in and a
*Sign out of all devices* button (`POST /dashboard/signout-all`), which ends every session for the account
and is written to the audit log.

Every activation/check-in also records where the shop is installed (domain, PHP version, shop version,
first domain seen) so the admin table answers "which site is this key on?". A machine other than the bound
one is refused **and counted** — the admin sees `N mismatch(es)` plus the last refused domain, which is the
cheapest piracy signal a key can give. Usage records are written at most once per key per 10 minutes, so a
chatty shop cannot turn check-ins into disk churn.

Administrator operations (all CSRF-protected, permission-checked and audited):

| Route | What it does |
| --- | --- |
| `POST /admin/create-account` | Create a customer (or admin) account. Blank password = one generated and shown once. There is **no public sign-up**. |
| `POST /admin/account-action` | `disable` / `enable` / `reset-password` for an account. Disable signs the account out at once and stops its shops; a password reset invalidates the old password and all sessions. |
| `POST /admin/revoke-key` | `revoke` / `restore` a licence key (cut off a leaked key immediately). |
| `POST /admin/create-key` | Issue a key for an existing account; `expires_days` is blank (= never) or a whole number 1–3650. The key is shown once and stored hashed. |

Operator CLI (for machines that have a shell; the admin UI above covers the free hosting tier, which has none):

```bash
node server.js --help
node server.js --list-accounts
node server.js --disable-account <email>   # --enable-account to undo
node server.js --reset-password <email>    # prints a one-time password
node server.js --revoke-key <ORBIT-...>    # --restore-key to undo
node server.js --verify                    # data-file health report, non-zero exit on problems
```

### Data safety

* Writes are `fsync`ed and then atomically renamed; the previous snapshot is rotated to `store.json.bak` on
  every write.
* If `store.json` is ever unreadable, the portal recovers from the backup and keeps the damaged file as
  `store.json.corrupt`; if no usable backup exists it **refuses to start** rather than starting empty.
* The store file is kept at mode `0600`; the portal warns if `PORTAL_DATA_DIR` is group/world accessible.
* `--verify` reports orphaned keys, malformed hashes, duplicate emails and stale sessions.
* Expired sessions are pruned when the service starts (only written when something actually changed), and
  check-in stamps are throttled to one write per key per ten minutes, so a chatty shop cannot make the
  portal rewrite its data file on every request.
* Only the last sign-in **time** is kept for the account holder; the portal stores no visitor IP addresses
  (an IP is used for rate limiting and then discarded). Audit entries name the actor and the action.

## Security configuration (post-penetration-test)

| Variable | Default | Meaning |
| --- | --- | --- |
| `TRUST_PROXY_HEADERS` | `false` | Honour `X-Forwarded-For` for rate limiting. **Set to `true` on Render** (it is already in `render.yaml`); leave false when the service is reachable directly, otherwise a caller can spoof the header and dodge the throttle. When enabled, the rightmost hop is used — the one the trusted proxy appended — so client-written values cannot move the bucket. |
| `TRUSTED_PROXY_IPS` | empty | Comma-separated peer addresses that may supply `X-Forwarded-For` without the global switch. Useful when only one known reverse proxy fronts the app. |
| `PORTAL_LOGIN_LIMIT` | `10` | Failed logins per client address per 15 minutes (minimum 3). |

Also hardened by the penetration test: hardware-ID binding, self-service reset and admin
override are performed as atomic check-and-set operations, so parallel requests cannot bind two
machines to one key or burn the 30-day reset twice; oversized bodies are answered with `413`
instead of a connection reset; `X-Frame-Options: DENY` and `frame-ancestors 'none'` block
framing; HSTS is sent when `PUBLIC_BASE_URL` is HTTPS; failed logins always run one password
hash so response time does not reveal whether an account exists; rate-limit buckets are pruned
so the map cannot grow without bound; and slow clients are cut off (20 s headers, 30 s request).

Every one of those has a regression test in `test/portal.test.js`.

## Security notes

* Licence keys are **never stored in clear** — only `sha256(key)` plus a masked display form
  (`ORBIT-XXXXX-****-****-XX`). A newly issued key is shown exactly once.
* Passwords: scrypt, per-account salt, timing-safe verification. Sessions: random 32-byte token stored
  hashed, `HttpOnly` + `SameSite=Lax` (+ `Secure` behind HTTPS), 7-day expiry.
* Every state-changing form is CSRF-protected; the admin role is required for key issuance and force
  resets; both are written to an audit log.
* Per-IP rate limits: login 10/15 min, activate 30/min, validate 120/min, reset 5/hour.
* The signing secret is required (≥32 chars) — the service refuses to start without it, so a
  misconfigured deploy cannot hand out unsigned licences.
* HWID resets are limited to one per `RESET_COOLDOWN_DAYS` (15–30, default 30); admin force-resets bypass
  the cooldown and are logged.

## Not implemented yet (deliberately)

* Durable store driver (PostgreSQL/Neon) — required before real customers, because the free tier's disk is
  ephemeral. The owner creates the database and supplies one connection URL; the storage layer is still
  design-only (`docs/frontend/03-license-portal-design.md` §4).
* The shop-side first-run setup token and lock file (designed, not built). The licence *client* that talks
  to this portal does exist: `libs/licence_client.php`, exercised by `tests/licence_client_test.php`.
* Email delivery (password reset / key delivery). Free Render instances cannot send SMTP; use an API.
* Editing a key's expiry after it was issued (issue a new key instead; the old one can be revoked).
