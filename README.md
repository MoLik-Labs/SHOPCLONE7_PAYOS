# Orbit Office — shop source

A Vietnamese digital-goods shop: accounts, licences and top-up cards, sold from a PHP monolith with
MySQL. The storefront, account area and money paths were rebuilt on one design system ("Orbit") on top
of the CMSNT `SHOPCLONE7` codebase; the CMSNT licence layer is kept intact and is **not** to be
removed.

**By NggKhaiz.**

> Status: work in progress on `arena/01a1066c-shopclone7-payos` (PR #1). Nothing in this repository is
> deployed by the agent, no live payment was created, and no licence call was made. See
> [Known open items](#known-open-items) before a production install.

---

## Contents

- [Requirements](#requirements)
- [Install](#install)
- [Configuration](#configuration)
- [How a request is served](#how-a-request-is-served)
- [Directory map](#directory-map)
- [Money paths](#money-paths)
- [Security controls](#security-controls)
- [Testing](#testing)
- [Documentation](#documentation)
- [Known open items](#known-open-items)
- [Licence and attribution](#licence-and-attribution)

---

## Requirements

| Component | Version | Notes |
| --- | --- | --- |
| PHP | 7.4 or newer | `composer.json` pins the platform to 7.4; the code also runs on PHP 8.x. Extensions: `mysqli`, `curl`, `mbstring`, `openssl`, `fileinfo`. |
| MySQL / MariaDB | MySQL 5.7+ / MariaDB 10.3+ | `utf8mb4`; the app uses `GET_LOCK`, so the statements must run on the same server. |
| Composer | 2.x | `composer install --no-dev --optimize-autoloader`. |
| Web server | Apache with `mod_rewrite`, or Nginx with an equivalent config | `.htaccess` is honoured by Apache only. |
| Node.js | 18+ | **Optional**, only for the local test harness and preview renderer. |

The app targets shared hosting and small VPS deployments. OPcache settings live in `.user.ini`;
compression, caching and the deny list live in `.htaccess`.

## Install

This build does not ship the CMSNT base schema. Start from an existing shop database (the one your
CMSNT licence was issued for), or import the vendor's `shopclone7.sql` from the original package.

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env          # then fill in DB_* and APP_URL
php install.php               # CLI only: adds this build's tables, columns and default settings
```

`install.php` is additive and idempotent for the rows it inserts (`insert_options()` checks first),
but its `CREATE TABLE` / `ALTER TABLE` statements are not `IF NOT EXISTS`; run it **once** against a
backed-up database. It refuses to run outside the CLI.

Then:

1. Point the document root at the repository root (the app uses `index.php?module=…&action=…` paths
   through `BASE_URL()`; no front controller rewrite is required).
2. Make `assets/storage/` writable by the PHP user. Uploaded images go to `assets/storage/images/`;
   `.htaccess` refuses to execute PHP under `assets/storage/`.
3. Sign in to `/admin` with an administrator account and change its password.
4. Fill the owner settings in **Settings** (title, support links, currencies, maintenance mode).
5. Only after that, configure a payment method (see [Money paths](#money-paths)).

`install.php` refuses to run outside the CLI. Do **not** run `update.php` on a live shop; it is the
vendor's in-place updater and it overwrites customised files.

## Configuration

Secrets live in `.env` only — never in the repository, never in the database. `.env.example` documents
every key; the groups are:

| Group | Keys | Notes |
| --- | --- | --- |
| Database | `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | |
| App | `APP_URL`, `SECRET_KEY`, `TRUSTED_PROXY_IPS` | `TRUSTED_PROXY_IPS` is required behind a proxy, otherwise `REMOTE_ADDR` is the only accepted client IP. |
| payOS | `PAYOS_ENABLED`, `PAYOS_CLIENT_ID`, `PAYOS_API_KEY`, `PAYOS_CHECKSUM_KEY`, `PAYOS_MIN_AMOUNT`, `PAYOS_MAX_AMOUNT`, `PAYOS_EXPIRE_MINUTES` | The only enabled online gateway by default. |
| Cron | `CRON_TOKEN` (≥ 16 chars) | Every `cron/*.php` worker refuses HTTP without `X-Cron-Token` (or `?token=`); CLI runs are always allowed. |
| GachtheFast | `GTF_*` | Ships **disabled and credit-locked**. Read `docs/gachthefast/` before changing anything. |

Optional `DB_DEBUG=1` logs the SQL text of failed statements (never values). Leave it off in
production.

## How a request is served

```
index.php?module=client&action=home        → views/client/home.php
index.php?module=admin&action=products     → views/admin/products.php
```

`index.php` normalises `module`/`action` through `check_path()`, refuses the partial views
(`header`, `footer`, `nav`, …), boots the session, and enforces the CSRF check for every admin
`POST` before the view runs. AJAX endpoints under `ajaxs/` are separate entry points and each one
carries its own guard.

## Directory map

| Path | What lives there |
| --- | --- |
| `index.php` | Front controller: session, maintenance, tracking cookies, admin CSRF gate, view dispatch. |
| `config.php` | Roles and permission names, small constants. No secrets. |
| `libs/` | The framework: `db.php` (mysqli wrapper, transactions, row locks), `helper.php` (all shared helpers), `lang.php`, `database/users.php` (money movement), `payos.php`, `gtf.php`, `settings_cache.php`, `sql_count.php`. |
| `views/client/` | Customer-facing pages (storefront, account, orders, recharge, tools). |
| `views/admin/` | Admin pages. |
| `views/common/` | Shells, 404/banned/maintenance, pagination, charts, music player. |
| `ajaxs/` | JSON endpoints for the client and admin UI. |
| `api/` | Public reseller API (`api/api.php`) and provider callbacks/webhooks. |
| `cron/` | Scheduled workers: bank polling, supplier sync, mail queue, automations. |
| `models/` | Auth guards (`is_admin.php`, …). |
| `assets/storage/` | Uploaded images (icons, avatars, banners). PHP execution refused by `.htaccess`. |
| `public/client/orbit/` | The Orbit design system: tokens, components, shell, store CSS and the small JS modules. |
| `public/common/vendor/` | Self-hosted third-party assets with their licences (Chart.js, DataTables, CodeMirror, flatpickr, …). No CDN at runtime. |
| `tests/` | Every regression suite (see [Testing](#testing)). |
| `tools/` | Dev and read-only operator tools: security scan, preview build, perf probe, index audit. |
| `docs/` | Design/audit/round write-ups, the GachtheFast dossier, vendored skills. |

## Money paths

Two ways money enters and one way it leaves as goods:

1. **Top-up (payOS).** `ajaxs/client/payos.php` creates a payment request; `api/webhook-payos.php`
   verifies the HMAC signature **before** any write, then claims the order row and credits exactly
   once. The ledger key is `PAYOS_<order_code>`. Polling (`ajaxs/client/payos.php?action=status`)
   only reports what the database already recorded.
2. **Top-up (legacy gateways).** Card, crypto, Flutterwave, Perfect Money, Squad, ToyyibPay, MoMo,
   bank auto and the external bank webhooks remain in the tree. While payOS is enabled, the legacy
   *entry points* answer **HTTP 410**; the callbacks stay live so in-flight invoices can finish.
   Every callback claims its invoice with a conditional `UPDATE … WHERE status='pending'` and only
   then credits.
3. **Purchase.** `ajaxs/client/product.php` and `api/api.php` share the same sequence: validate
   quantity → reserve coupon → **debit under the user row lock** → deliver from stock or supplier →
   write the order → commit the coupon → credit the seller, the affiliate, and the sold counter.

The ledger (`dongtien`) is the source of truth for every balance move. Each move carries a
deterministic key (`ORDER_<trans_id>`, `REFUND_<trans_id>`, `PARTIAL_<trans_id>`,
`DOANH_THU_<trans_id>`, `PAYOS_<code>`, …), and every writer inserts the row with
`WHERE NOT EXISTS (SELECT 1 … WHERE transid = ?)` inside a transaction that holds the user row lock.
Retries are therefore reported as `duplicate`, not applied twice.

## Security controls

- **CSRF.** Every admin form `POST` is checked by `index.php`; every client/admin AJAX endpoint calls
  `orbit_require_csrf()` or checks its own per-purpose token. Refusal is `403` with a JSON body.
- **Passwords.** `password_hash()` over the raw bytes (not the HTML-escaped input), with transparent
  upgrade of legacy hashes on the next successful login. 2FA (TOTP) and e-mail OTP are available.
- **Sessions.** Strict mode, `HttpOnly`, `Secure` on HTTPS, `SameSite=Lax`, id rotated at login.
- **SQL.** All statements go through `DB`, which pins `MYSQLI_REPORT_OFF`, logs the driver message
  (and the SQL only under `DB_DEBUG`), and returns `false`/`[]` instead of printing a stack trace.
  Request values reach SQL through `check_string()`/`addslashes()` or are cast; the security scan
  below fails the build on a token that reaches SQL unescaped.
- **Uploads.** `check_img()` accepts only real images (extension + `IMAGETYPE`, ≤ 5 MB, ≤ 10 000 px).
  The upload directory cannot execute PHP.
- **Cron.** HTTP needs `CRON_TOKEN` compared with `hash_equals`; with no token configured, HTTP is
  refused.
- **Headers / transport.** `nosniff`, `Referrer-Policy`, no `expose_php`, compression and a deny list
  in `.htaccess`; `.user.ini` hardens sessions and OPcache.
- **Static gate.** `tools/security-scan.php` token-scans 236 files for request data reaching SQL,
  HTML, includes, `exec`, or password hashes, and for files missing the `IN_SITE` guard. It runs as
  part of `tools/dev/run.sh --fail-on=high` and currently reports **0 findings**.

## Testing

The suites are plain PHP scripts. They need no database except the four `gtf_*`/`payos_service`
suites, which require a disposable MySQL whose DSN contains `test` and refuse anything else.

```bash
npm install --prefix tools/dev          # once: PHP-WASM runner for machines without a PHP binary
bash tools/dev/run.sh                   # lint all PHP + security scan + every suite
bash tools/dev/run.sh --tests-only tests/csrf_guard_test.php
node tools/dev/phpcl.mjs tests/count_query_test.php
```

A suite that cannot run (no database, no sub-process support) is reported as **NOT RUN** and never
counted as passing. `tests/README.md` lists every suite and what it pins.

Latest full run on this branch:

```
LINT 308 files, 0 parse errors
SCAN 237 files: 0 critical, 0 high, 0 medium, 0 low
42 suites, 1 not run (payos_service, no disposable database), 0 failures
```

Operator-side tools (read-only unless stated):

| Tool | Purpose |
| --- | --- |
| `php tools/db-index-audit.php` | Prints every missing hot-query index as an `ALTER TABLE` statement, and reports duplicate `dongtien.transid` / `coupon_used` pairs before suggesting `UNIQUE`. Never writes. |
| `php tools/perf-probe.php` | Queries, settings loads, errors, bytes and ms per page. |
| `php tools/preview-build.php` | Rebuilds the static preview under `docs/frontend/preview/`. |

## Documentation

| Document | Contents |
| --- | --- |
| `docs/frontend/00-…` – `08-…` | Discovery, licence requirements, pentest, bug sweeps, source protection. |
| `docs/frontend/09-…` | Design system and shell. |
| `docs/frontend/13-…` | Storefront redesign plan and page inventory. |
| `docs/frontend/15-…` | PHP/DB optimisation round 12 (settings and language caches, transactions, server config). |
| `docs/frontend/17-…` – `19-…` | CSRF/session hardening, exactly-once payment callbacks, checkout/auth/cron. |
| `docs/frontend/20-…` | Self-hosted assets (no CDN). |
| `docs/frontend/21-…` – `23-…` | Campaign queue, batched lookups and affiliate rate, CSRF + money paths (latest). |
| `docs/rewrite-evaluation-php-vs-go-rust.md` | Why the shop stays in PHP. |
| `docs/gachthefast/` | The GTF card integration dossier, security review, runbook, staging gates. |
| `docs/skills/` | Vendored Anthropic skills + three project skills. |
| `tests/README.md` | The suite matrix and how to run it. |

`docs/frontend/preview/index.html` is a static render of the real views with sample rows.

## Known open items

These are tracked, documented and **not** silently assumed to be fine:

- **No staging smoke test yet.** The sandbox has no MySQL and no browser session, so AJAX POSTs,
  login and the partial-delivery branch are proven by unit/source assertions, not by a live run.
- **Missing database constraints.** `UNIQUE (transid)` on `dongtien` and `UNIQUE (coupon_id, user_id)`
  on `coupon_used` are not applied. The code serialises both paths (row lock, `GET_LOCK`), but the
  keys are the complete guarantee. Run `tools/db-index-audit.php` and resolve duplicates first.
- **Source protection level 4** is designed but the encoder is deferred by the owner.
- **`payos_service_test.php`** requires a disposable MySQL and is reported as NOT RUN in this
  environment.
- Legacy payment entry points stay in the tree for historical invoices; they are gated with 410 when
  payOS is on, not deleted.

## Licence and attribution

This is a licensed CMSNT product. Keep the licence check and the attribution as the owner decided:

- product identity: **Orbit Office**;
- footer credit **“By NggKhaiz”** is allowed;
- the CMSNT licence verification must not be removed, bypassed or stubbed, and `install.php` /
  `update.php` must not be used to strip it.

Third-party assets under `public/` keep their own licence files next to them (Chart.js, DataTables,
CodeMirror, flatpickr, simple-notify, Be Vietnam Pro, Roboto, and others). The vendored skills under
`docs/skills/anthropic/` are Apache-2.0 with their `LICENSE` files retained.
