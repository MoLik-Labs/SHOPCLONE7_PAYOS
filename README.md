# MoLik Studio — shop source

A Vietnamese digital-goods shop: accounts, licences and top-up cards, sold from a PHP monolith with
MySQL. The storefront, account area and money paths were rebuilt on one design system ("Orbit") on top
of the CMSNT `SHOPCLONE7` codebase; the CMSNT licence layer is kept intact and is **not** to be
removed.

**Nhận diện sản phẩm: MoLik Studio.**

**Tài liệu tiếng Việt:** [Trang Starlight (GitHub Pages, khi đã triển khai)](https://molik-labs.github.io/SHOPCLONE7_PAYOS/) · [Mã nguồn và hướng dẫn chạy tại chỗ](documentation/src/content/docs/phat-trien/docs.mdx).

> Status: work in progress. The application has not been validated against the target hosting or a
> real shop database. Nothing has been deployed by the agent; no live payment or licence call was made.
> Read [Known open items](#known-open-items) before considering a production install.

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
| PHP | 7.4 or newer | `composer.json` pins dependency resolution to 7.4; use a security-supported PHP version on hosting and test it in staging. Required extensions include `mysqli`, `pdo_mysql`, `curl`, `mbstring`, `openssl`, `fileinfo` and `gd` (required by CAPTCHA). Run `composer check-platform-reqs --no-dev` on the actual host. |
| MySQL / MariaDB | MySQL 5.7+ / MariaDB 10.3+ | `utf8mb4`; the app uses `GET_LOCK`, so the statements must run on the same server. |
| Composer | 2.x | `composer install --no-dev --optimize-autoloader`. |
| Web server | Apache with `mod_rewrite`, or Nginx with an equivalent config | `.htaccess` is honoured by Apache only. |
| Node.js | 18+ | **Optional**, only for the local test harness and preview renderer. |

The app targets shared hosting and small VPS deployments. OPcache settings live in `.user.ini`;
compression, caching and the deny list live in `.htaccess`.

## Install

This build does not ship the CMSNT base schema. Obtain the matching licensed vendor schema or a
backup of your existing shop, and restore it **only to an isolated staging database first**. Its
compatibility with this build is not yet established.

```bash
composer install --no-dev --optimize-autoloader
composer check-platform-reqs --no-dev
cp .env.example .env          # or configure the process environment; set DB_* and APP_URL
```

**Do not run `install.php` on production as an automatic installation step.** It is a legacy CLI
script with schema-dependent, non-idempotent DDL, including a dropped/recreated index; SQL failures can
leave a partially changed schema. It does not supply the original CMSNT base schema and has not been
verified against your shop database. Back up and restore a copy to an isolated staging database,
inspect its actual schema and indexes, then plan and test any required changes before seeking approval
to apply them. Never blindly run `update.php`, which can overwrite customised files.

The payOS tables need their own `scripts/migrate-payos.php`; that script modifies legacy table types
and engines and must likewise be reviewed and rehearsed on a backup before production approval. GTF
is disabled/credit-locked pending its separate contract and staging gates.

For staging, configure the web root, HTTPS, equivalent access restrictions and routing, then verify
all of them on the actual server. Pretty links depend on the rewrite rules, so an equivalent Nginx
configuration is required if Apache `.htaccess` is not honoured. Make `assets/storage/` writable
only as needed and ensure the server will never execute uploaded scripts. Review administrator access,
owner settings and payment configuration in staging; do not expose real users or payments until the
remaining release gates have been completed and deployment is authorised.

`install.php` refuses to run outside the CLI; that guard alone does not make its schema changes safe.

## Configuration

Deployment secrets belong in the hosting environment or an untracked `.env`, never in the repository.
Some legacy provider settings are stored in the shop database; secure and back up that database accordingly.
`.env.example` documents
every key; the groups are:

| Group | Keys | Notes |
| --- | --- | --- |
| Database | `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | |
| App | `APP_URL`, `SECRET_KEY`, `TRUSTED_PROXY_IPS` | Set `APP_URL` to the actual canonical HTTPS origin, with no path, query or credentials; it controls absolute links and is required for password reset. Without it, legacy rendering may use the request host; an invalid nonempty value falls back to localhost. Configure an edge Host allowlist in all cases. Behind a proxy, list only its exact immediate-peer IPs (comma-separated; no CIDR) and configure it to overwrite incoming forwarding headers. Otherwise forwarded client IP/scheme are ignored. An HTTPS `APP_URL` also forces Secure cookies; enforce HTTPS at the edge. |
| payOS | `PAYOS_ENABLED`, `PAYOS_CLIENT_ID`, `PAYOS_API_KEY`, `PAYOS_CHECKSUM_KEY`, `PAYOS_MIN_AMOUNT`, `PAYOS_MAX_AMOUNT`, `PAYOS_EXPIRE_MINUTES` | Disabled in `.env.example`; enable only after staging and provider verification. |
| Cron | `CRON_TOKEN` (≥ 16 chars) | Every `cron/*.php` worker refuses HTTP without `X-Cron-Token` (or `?token=`); CLI runs are always allowed. |
| GachtheFast | `GTF_*` | Ships **disabled and credit-locked**. Read `docs/gachthefast/` before changing anything. |

Optional `DB_DEBUG=1` logs SQL text of failed statements, which **can contain values and secrets**.
Leave it off in production and protect any debug logs.

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

Most suites are plain PHP scripts with fixture data. Database integration suites require a disposable
MySQL and may create, change or drop fixture tables; read each suite's safety checks and never point
one at a real shop database. The local mysqli and wallet suites additionally require explicitly named
`orbit_test_*` databases on loopback; the wallet suite insists on an initially empty database.

```bash
npm install --prefix tools/dev          # once: PHP-WASM runner for machines without a PHP binary
bash tools/dev/run.sh                   # lint all PHP + security scan + every suite
bash tools/dev/run.sh --tests-only tests/csrf_guard_test.php
node tools/dev/phpcl.mjs tests/count_query_test.php
```

A suite that cannot run (no database, no sub-process support) is reported as **NOT RUN** and never
counted as passing. `tests/README.md` lists every suite and what it pins.

See `docs/frontend/30-native-database-review.md` for the native PHP/MySQL fixture runs and their
limits. The PHP-WASM runner reports the database-dependent suites as NOT RUN; it does not replace
native MySQL testing.

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

- **Target staging not tested.** Native PHP/MySQL tests use synthetic tables, not the vendor schema
  or hosting configuration. Login, complete checkout, actual provider behaviour and production data
  remain unverified; the base CMSNT schema is not included.
- **Checkout recovery is incomplete.** An interrupted purchase or supplier timeout may leave an
  ambiguous outcome; same-tab warnings are not a durable claim or exactly-once guarantee. See
  `docs/frontend/26-checkout-recovery-plan.md` and `28-checkout-support-runbook.md`.
- **Missing database constraints.** Audit the actual schema and duplicates before proposing unique
  indexes; do not blindly add them or change/delete existing data. `tools/db-index-audit.php` is
  read-only and produces suggestions, not a migration.
- **Legacy installer is unsafe to run blindly**, and payOS migration changes engine/column types.
  Rehearse on an isolated restored backup and obtain approval before production changes.
- **Legacy data and password modes need review.** Explicit quote encoding differs from older PHP
  defaults; existing stored values may require a staged compatibility audit. Legacy MD5/SHA-1 modes
  remain supported, not modern password storage; plan a safe migration rather than toggling the
  setting blindly. Configure the canonical `APP_URL` and an edge host allowlist.
- **Legacy APIs expose credentials in URLs.** Reseller purchase and privileged stock import still
  accept API keys in query strings; GET purchase is a mutation, and checkout is not fully atomic.
  Restrict access and redact query strings in proxy, server, APM and analytics logs; coordinate a
  staged migration to authenticated POST before removing legacy compatibility. Never blindly retry
  uncertain purchases. See `docs/frontend/32-public-security-review.md`.
- **GachtheFast remains disabled and credit-locked** until its provider contract, retry semantics
  and release gates are resolved. The operator-credit and callback hardening is fixture-tested, not
  provider-verified; see `docs/gachthefast/13-security-hardening-retest.md`. Source-protection encoder
  is deferred by the owner.
- Legacy payment entry points remain for historical invoices; they are gated with 410 when payOS is
  enabled, not deleted.

## Licence and attribution

This is a licensed CMSNT product. Keep the licence check and the attribution as the owner decided:

- product identity: **MoLik Studio**;
- footer credit: **“MoLik Studio”**;
- the CMSNT licence verification must not be removed, bypassed or stubbed, and `install.php` /
  `update.php` must not be used to strip it.

Third-party assets under `public/` keep their own licence files next to them (Chart.js, DataTables,
CodeMirror, flatpickr, simple-notify, Be Vietnam Pro, Roboto, and others). The vendored skills under
`docs/skills/anthropic/` are Apache-2.0 with their `LICENSE` files retained.
