---
name: security-review
description: Audit a page or endpoint in this shop for authentication, CSRF, SQL injection, XSS, uploads, secrets, redirects and money bugs. Use before shipping anything that touches accounts, balances, orders or admin.
---

# Security review for this shop

The codebase has already been through a penetration test (`docs/frontend/05-pentest-report.md`), a
bug hunt (`06-bug-hunt-and-hardening.md`) and payment hardening (`docs/gachthefast/10-security-review.md`).
This skill is the working checklist for the next change, not a replacement for those reports.

## Rules of engagement

- **Never bypass or "fix" the CMSNT licence checks.** Never touch the payOS contract, the 410 legacy
  guards or the fail-closed GTF switches without an explicit owner decision.
- **Never test against production.** No real card, credential, customer row, balance or provider call.
  Use stubs and a disposable `*_test` database only.
- P0/P1 findings block release. Report with a command and its actual output — a heuristic scan is not a
  pass.
- Do not paste secrets into chat, tests, logs or documentation.

## 1. Reachability and auth

For every branch in an AJAX file (`ajaxs/client|admin/*.php`), answer:

1. Which action strings reach it?
2. Does it require `token` (browser session) or `api_key` (public API)?
3. Is `banned = 0` checked in the **same** query that resolves the user?
4. Is the row constrained by the authenticated id (`buyer = <id>`, `user_id = <id>`)? An id from the
   request is not ownership.

```bash
grep -n 'POST\["action"\]\|REQUEST\["action"\]' ajaxs/client/*.php
grep -rn 'SELECT \* FROM `product_order`' ajaxs | grep -v 'buyer'
```

## 2. SQL

`check_string()` is `trim(htmlspecialchars(addslashes()))`: it escapes quotes, so string literals
survive, but it is **not** a type check. The dangerous contexts are numbers, `LIMIT`, `ORDER BY`,
`IN (...)` and identifiers.

- Numeric context → `order_quantity()` (digits only, max 9 digits) or `(int)`.
- `LIMIT`/`OFFSET` → integers, never raw strings.
- `LIKE` → escape `%` and `_` as well as quotes.
- New code → prepared statements in `libs/payos.php` style, not string interpolation.

```bash
grep -rn 'LIMIT " \.\|LIMIT \$' --include=*.php . | grep -v '(int)\|order_quantity'
```

The known bad pattern that was fixed: `LIMIT " . $amount` with `$amount = "1 PROCEDURE ANALYSE(...)"`
passing loose min/max comparisons. `tests/order_quantity_test.php` pins the fix.

## 3. CSRF and sessions

- New POST endpoints that act on a cookie session need a CSRF token compared with `hash_equals`
  (`ajaxs/client/payos.php`, `gtf.php` are the reference).
- Legacy endpoints authenticate with the random `token` value in the POST body. That is the existing
  contract; do not silently change it, but never add a new endpoint that relies on it.
- Login must call `set_auth_cookie()` — it already regenerates the session id and sets
  `HttpOnly; Secure(https); SameSite=Lax`.
- Logout must clear both the cookie and `$_SESSION`.

## 4. XSS

- New views escape every value with `store_e()` / `htmlspecialchars(..., ENT_QUOTES, "UTF-8")`.
- Admin-authored HTML (product description, `page_faq`, manual instructions, blog content) is rendered
  raw by design. It must only ever come from an authenticated admin.
- Never echo a request value into HTML, a JS string or an attribute.
- No inline `on*=` handlers in rebuilt pages; `store.js` binds events.

```bash
grep -rn 'echo \$_GET\|echo \$_POST\|echo \$_REQUEST' --include=*.php views ajaxs
```

## 5. Money paths

- Credits only on a verified provider result; pending is never credited.
- Exactly-once: unique `order_code` / `trans_id`, compare-and-set status, idempotent webhook.
- Amounts are integers in the smallest currency unit; round with `roundMoney()`, never float equality.
- A refund path must not be reachable twice.
- The payOS webhook signature must be verified before any write.

Suites: `tests/payos_client_test.php` (45 checks, offline), `tests/payos_service_test.php` (needs a
disposable DB), `tests/gtf_service_test.php`, `tests/gtf_credit_path_test.php` (both NOT RUN without a
`*_test` DSN — say so). `bash .harness/legacy-gate.sh` proves 14 legacy entry points return 410 while
payOS is enabled.

## 6. Uploads, redirects, secrets, headers

- Uploads: `check_img()` + `valid_image_upload()`; never trust `$_FILES['name']`; store outside PHP.
- Redirects: only `BASE_URL()`-built targets. A `redirect($_GET['next'])` is an open redirect.
- Secrets: environment only (`$_ENV`/`getenv`); never in views, JS, HTML comments or error output.
- Auth cookies: `HttpOnly`, `Secure` on HTTPS, `SameSite=Lax`; no token in localStorage.
- New pages that show money: `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`.

## 7. Report format

Write findings as a table with severity, the exact request, the observed output, the fix and the test
that now covers it. Separate **tested** from **NOT RUN** explicitly. A clean scan is "no finding in the
patterns I checked", never "the code is secure".
