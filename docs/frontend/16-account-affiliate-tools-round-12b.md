# Round 12b — account area, affiliates, tools, and a standing security gate

Scope: finish the customer-facing surface on the Orbit shell, remove the last third-party frames from
pages the shop controls, and turn the manual security checklist into something the test runner enforces
on every change. No deployment; nothing here touches production data or a live payment endpoint.

Branch: `arena/01a1066c-shopclone7-payos`. Commits in this round (oldest first):

| Commit | What it does |
| --- | --- |
| `c65b41f5` | eight signed-in pages rebuilt on the shell + shared account nav |
| `83778029` | four real injection vectors closed; `tools/security-scan.php` added |
| `0c4d93ff` | affiliate pages, 2FA/OTP challenge, canonical 404 |
| `5d75f59b` | four tool pages, no third-party iframes |
| `8ad1ee1b` | legacy USDT invoice page |

---

## 1. Account pages

`favorites`, `transactions`, `logs`, `change-password`, `security`, `contact`, `forgot-password`,
`reset-password` (and `profile`, which swapped its inline nav for the shared one).

**Two real bugs fixed, not just restyled**

- `transactions.php` read `$content` and used `$reason`, neither of which existed: the search box never
  filtered anything and every request emitted `Undefined variable` warnings. The filter now reaches SQL
  (verified by a test that greps the query log, not by looking at the page).
- `favorites.php` ran three queries per row. It is now one JOIN plus one grouped stock query for the
  page's product codes.

**Shared behaviour** — `views/common/account-nav.php`

| Helper | Rule |
| --- | --- |
| `store_account_limit($v, $default)` | only 5/10/20/50/100 |
| `store_account_page($v)` | clamped to 1..10000 |
| `store_account_search($v, $max)` | `strip_tags` + `mb_substr` + `addslashes` |
| `store_account_date_range($v)` | `"Y-m-d to Y-m-d"` → `[00:00:00, 23:59:59]`, or `null` |
| `store_account_badge($amount)` | `+`/`-` stated in words, not only colour |

`store_pager()` gained a `$path` argument: it used to link every account page back to `client/home`.

**Security page.** The QR is a local `data:` URI (`getQRCodeInline`), the manual key is shown when the
library is missing, and the SMTP-dependent switches are *disabled with a reason* instead of silently
doing nothing.

---

## 2. Security work (four real vectors)

| Where | Problem | Fix |
| --- | --- | --- |
| `views/admin/coupons.php`, `coupon-edit.php` | `json_encode($_POST["product_id"])` stored straight into SQL; a quote inside one value broke out | `orbit_post_id_list()` keeps only positive integers as strings |
| `views/admin/automations.php`, `automation-edit.php` | same pattern | same helper |
| `views/admin/roles.php`, `role-edit.php` | same pattern on `$_POST["role"]` | intersect with the `$admin_roles` whitelist from `config.php` |
| `api/checklive_hotmail.php` | unauthenticated URL made the server open an IMAP session with arbitrary credentials, and logged them | retired with `410`, like the other dead endpoints |

Also: `index.php` maintenance lookup now `addslashes()` after its regex (defence in depth, the comment
no longer claims the regex is the only barrier); `common/404|banned|block-ip|maintenance` refuse direct
requests; `libs/oauth.php` builds `api_key` with `bin2hex(random_bytes(16))` instead of `md5(...)`.

### The gate: `tools/security-scan.php`

A token-based scan (not regex over raw text, so comments and strings cannot fool it). It tracks variables
assigned from `$_GET/$_POST/$_COOKIE/$_REQUEST` and reports when one reaches SQL, `echo`/`print`,
`include`, a shell primitive, or a password hash — plus files under `views/`, `ajaxs/`, `api/`, `models/`
without an `IN_SITE` guard.

```bash
node tools/dev/phpcl.mjs tools/security-scan.php            # report
node tools/dev/phpcl.mjs tools/security-scan.php --json     # machine-readable
node tools/dev/phpcl.mjs tools/security-scan.php --fail-on=high   # CI exit code
```

`tools/dev/run.sh` runs it with `--fail-on=high` before the suites. Current tree: **236 files, 0 findings**.
It caught four false positives during development; each was fixed in the scanner (close-tag statement
boundaries, whole-variable-name matching, `store_pager` as an escaping helper) rather than by suppressing
the rule.

`tools/dev/run.sh` also now lints **untracked** PHP files: a brand-new page used to skip the lint simply
because `git ls-files` did not know it yet.

---

## 3. Affiliate programme

`affiliates`, `affiliate-history`, `affiliate-withdraw` on the shell, with `store_affiliate_nav()` as a
short sub-navigation (the main account map stays eight items).

- Withdrawal no longer prints the session token into the page. It posts a CSRF token, and
  `ajaxs/client/create.php` verifies it and reads `$_SESSION["login"]`, falling back to the posted token
  only for older clients.
- Banks, the minimum and the rate come from the owner's settings. With no banks configured the form is
  disabled with an explanation rather than submitting a payout nobody can receive.
- `affiliate_history` reads `aff_log`; the legacy page's `$time` filter wrote to a `create_date` column
  that table does not have — the new page uses `create_gettime`.

---

## 4. Tools area

The owner's tools menu embedded **2fa.zone**, **smileysapp.com** and **thispersondoesnotexist.com** in
full-height iframes. Beyond the third-party connections, `2fa.zone` received the customer's TOTP secret.

| Page | Now |
| --- | --- |
| `tool-2fa` | RFC 6238 implemented with WebCrypto in the page. No request at all. |
| `tool-icon-facebook` | local emoji grid, click to copy |
| `tool-random-face` | explains the service, opens it in a new tab with `rel="noopener noreferrer nofollow"` |
| `tool-checklive-fb` | same picture-size heuristic, on the shell, states that it is an estimate and that UIDs are not stored |

**TOTP verified three ways:** the algorithm reproduces the RFC 6238 test vectors (T=59 s → `287082`,
T=1111111109 → `081804`); the real page in Chromium produced the same code as an independent Node
implementation (`538748`); and the countdown/next-code path runs without page errors.

The header link appears only while `status_menu_tools = 1`, and the pages redirect home when it is off.

---

## 5. Legacy invoice page

Old e-mails link to `client/payment?trans_id=…`. It was raw echoes, a CDN `clipboard.js` and jQuery
polling. Rebuilt read-only on the shell, with the `trans_id` validated before SQL, an unknown id
redirected home, and the return URL accepted only when it is `http(s)`.

When `PAYOS_ENABLED` is on, the page says the USDT channel is closed and links to the payOS route instead
of inviting a transfer to a wallet the shop no longer watches. Polling keeps the same action and field
(`loadStatusInvoice`, `trans_id`), so the backend contract is unchanged.

---

## 6. Preview

`docs/frontend/preview/` now holds **35 generated pages**. The index is generated from the pages that were
actually written, so a new page cannot be missing from the list. Every page was screenshotted at 1440 and
390 px with **0 console errors, 0 failed requests and 0 horizontal overflow**.

---

## 7. Evidence

| Check | Result |
| --- | --- |
| `bash tools/dev/run.sh` | LINT 294 files 0 errors · SCAN 236 files 0 findings · 29 suites, 1 not run, 0 failures |
| `tests/account_pages_test.php` | 201 passed, 0 failed |
| `legacy-gate.sh` | 14/14 entry points stop with 410 |
| `node --check public/client/orbit/store.js` | OK |
| Preview browser pass | 35 pages × 2 widths, clean |
| TOTP | RFC vectors + live browser = match |

**Not run:** `tests/payos_service_test.php` (needs a disposable database), `tools/perf-probe.php` and
`tools/db-index-audit.php` (need `DB_*`). These are reported as NOT RUN, never as passing.

## 8. Still open

- Legacy AJAX endpoints authenticate with a token in the POST body; new endpoints use CSRF. Converting the
  rest is a separate change with its own compatibility questions.
- `md5` fallback in the CMSNT licence path — kept deliberately, and out of scope without an owner decision.
- LCP `fetchpriority` on the hero image of the home page.
- Recharge-* legacy pages (shown only when payOS is off) are still on the old shell.
