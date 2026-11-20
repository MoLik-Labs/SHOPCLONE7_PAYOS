# Round 12c — CSRF for every state-changing endpoint, and a session cookie worth its name

Scope: close the last class of cross-site request forgery in the shop (the admin panel had none), make
the session cookie explicit instead of relying on PHP/browser defaults, and put both under a test that
runs on every change. No deployment; no endpoint outside `ajaxs/` was touched.

Branch: `arena/01a1066c-shopclone7-payos`.

---

## 1. What was actually missing

The shop had two flavours of authentication:

| Entry point | Authenticates by | CSRF before this round |
| --- | --- | --- |
| `views/admin/*` pages | `$_SESSION["admin_login"]` | none |
| `ajaxs/admin/{create,update,remove,view}.php` | same session | **none** |
| `ajaxs/client/auth.php` (profile, password, 2FA, API key) | `token` in the POST body | none |
| `ajaxs/client/create.php` | `token` in the POST body | `WithdrawCommission` only |
| `ajaxs/client/view.php` | `token` in the POST body | none |
| `ajaxs/client/payos.php`, `gtf.php` | session | already had their own token |

So `ajaxs/admin/remove.php` — delete a user, a product, a coupon, an IP block — accepted any POST that
carried the admin's session cookie. Browsers now default to `SameSite=Lax`, which blocks a cross-site
form POST, but that is a browser default, not a server guarantee, and the cookie never said it.

## 2. The design: one guard, two proofs

`libs/helper.php`

```php
orbit_csrf_ok()          // true when the request proves it is same-site
orbit_require_csrf([...])// 403 JSON + exit for anything that is not
```

`orbit_csrf_ok()` accepts, in order:

1. **The token** — a `csrf_token` form field or an `X-CSRF-Token` header, compared with
   `hash_equals()` against the token stored in the session.
2. **A same-origin `Origin`** (or `Referer` when Origin is absent), compared by host with `HTTP_HOST`.
   This is the compatibility path: every page we have not rebuilt still works, and it is why the guard
   could be switched on in one commit without a page-by-page audit. It is logged
   (`[orbit-csrf] same-origin request without a token: …`) so the day the log is quiet, it can be removed.

Refused outright: a foreign Origin, the literal `null` origin that sandboxed frames send, a malformed
Host, and any scheme that is not http/https. The scheme is deliberately *not* compared — Render and
Cloudflare terminate TLS, and PHP would otherwise see `http` while the browser correctly sends `https`.

### Why no meta tag

A token is published as a **readable cookie**, `orbit_csrf`, set next to the session id in `libs/db.php`.
It is created with `random_bytes(32)`, is not the session id, and reading it grants nothing a same-origin
script could not already do. `public/common/csrf.js` (1.4 KB) reads it and wraps
`XMLHttpRequest.prototype.open/send` and `window.fetch`, so jQuery, axios and hand-written XHR all get the
header without touching a single call site. It is loaded by the admin footer, the legacy client footer and
`store_shell_close()` — the three places every page in the shop ends with.

A request that already carries the header is left alone; a same-origin GET is left alone; a cross-origin
request is left alone (and would be refused by the server anyway).

## 3. Session cookie

Before: `session_start()` with PHP defaults — no `SameSite`, no `use_strict_mode`.

Now, in `libs/db.php` before `session_start()`:

| Setting | Value | Why |
| --- | --- | --- |
| `session.use_strict_mode` | `1` | a session id the server never issued is discarded (fixation) |
| `httponly` | `true` | not readable from script |
| `samesite` | `Lax` | not sent on cross-site form POST |
| `secure` | HTTPS, incl. `X-Forwarded-Proto` | not sent over plaintext when the site is TLS |

## 4. Evidence

```
node tools/dev/phpcl.mjs tests/csrf_guard_test.php
  34 checks, 0 failed
  PASS  a forged request is refused with 403 JSON      (shutdown assertion: code 403 + JSON body)
  PASS  a cross-site origin is refused
  PASS  a host that merely starts with ours is refused
  PASS  a sandboxed "null" origin is refused
  PASS  X-Forwarded-Host cannot move the expected origin
  PASS  a proxy port on HTTP_HOST does not defeat the check

bash tools/dev/run.sh
  LINT 296 files, 0 parse errors
  SCAN 237 files: 0 critical, 0 high, 0 medium, 0 low
  js_syntax: 5 files ok
  === SUMMARY: 30 suites, 1 not run, 0 failures
```

Real Chromium, page loaded from the preview, `orbit_csrf=probe-token-42` set, then four requests:

| Request | `X-CSRF-Token` received |
| --- | --- |
| `fetch("/echo", {method:"POST"})` | `probe-token-42` |
| `XMLHttpRequest` POST `/echo` | `probe-token-42` |
| `fetch("http://127.0.0.1:<other-port>/echo", POST)` | `null` |
| `fetch("/echo")` (GET) | `null` |

`PASS csrf.js attaches the header to same-origin writes only`, 0 page errors.

Preview after the change: 36 pages rebuild, `class check: 360 class(es) used, all defined`,
`payment @1440/@390 → overflow 0 bytes h1=1 inline=0 errors=0 failed=0`.

## 5. Decisions worth knowing

- **The origin fallback is intentional, not an oversight.** Removing it today would break any page that
  has not shipped `csrf.js`. It is one line in `orbit_csrf_ok()` and the log line tells you when it is safe.
- **Login, register, e-mail OTP and both reset links are exempt.** They run before a session exists, and
  the reset links carry a single-use token from the e-mail. The exemption list is the argument to
  `orbit_require_csrf()` in `ajaxs/client/auth.php`; a new action added there is protected by default.
- **No nonce rotation per form.** A per-session token is the standard trade-off; rotating per form would
  break the "open two tabs" case for no measurable gain here.
- **`gtf.php` and `payos.php` were left alone.** They already verify their own token, and their clients
  (the GTF card form, the payOS checkout poll) send it explicitly.

## 6. Two loose ends closed with evidence

**LCP `fetchpriority` was a non-issue.** The standing queue asked for `fetchpriority="high"` on the home
hero. The home page has no hero image — the `<img>` tags are category icons (36 px), product covers and
payment marks, all `loading="lazy"`. Measured with a `PerformanceObserver` in Chromium on the preview
(36 pages, home at 1440 and 390):

| Viewport | LCP time | LCP element | Size |
| --- | --- | --- | --- |
| 1440 | 112 ms | text block | 2 014 px² |
| 390 | 92 ms | the shop name (`span.topbar__name`) | 1 824 px² |

(painted after the preview's own gallery banner is removed). LCP is paint-bound text, so a priority hint
would have nothing to raise. The item is closed rather than "still open".

**`/client/widget_tools` retired.** It printed four legacy cards with inline styles, `mod/img/`
backgrounds and links to `/tool/<name>`; nothing included it. It is now a redirect to `/client/tools`,
covered by two new checks in `tests/client_pages_test.php` (48 passed).

## 7. The API guide, rebuilt

`views/client/document-api.php` was the last legacy page a signed-in customer could reach that loaded a
third-party script: ClipboardJS from `cdnjs.cloudflare.com`. It also printed the **session token** into a
button's `onclick` (`changeAPIKey(\`<token>\`)`) purely so the old handler could re-authenticate.

Rewritten on the shell:

- `[data-copy]` from `store.js` replaces ClipboardJS; the page makes no third-party request.
- Rotation posts `changeAPIKey` with the CSRF token; `ajaxs/client/auth.php` resolves the account from
  the session and only falls back to a posted token for an older cached page.
- New keys and registration keys are `bin2hex(random_bytes(16))` — same 32-hex shape the API endpoints
  already index, without `md5(username . time)`.
- The hidden page only renders while `api_status = 1`; turning it off redirects home (tested).

Evidence: `document-api.html` in the preview (36th page), `document-api @1440/@390 → overflow 0 bytes
h1=1 inline=0 errors=0 failed=0`; `tests/account_pages_test.php` **215 passed, 0 failed** (was 201),
including "no clipboard CDN", "no session token in the page", "goes home when the API is off".

One preview-only detail: `BASE_URL` turns routes into inert `#preview-…` anchors, which is right for a
link and wrong for a URL printed as documentation. The builder swaps a readable example host back into
that page's inputs, so the preview shows `https://shop.orbit.example/api/profile.php?api_key=…`.

## 8. Not done / next

- The admin panel has not been exercised end-to-end against MySQL (no database here): the guard is unit
  tested and every admin page that posts was statically confirmed to load `csrf.js`.
- `dongtien.transid` still has a plain index, not a unique one; the payOS path is idempotent in SQL, the
  legacy manual-credit path is not. `tools/db-index-audit.php` now ends with an advisory block that lists
  duplicated `transid` values and prints the `ALTER … ADD UNIQUE INDEX` only when the history is clean.
  It is read-only and does not change the tool's exit code. To run on staging:
  `php tools/db-index-audit.php`.
- Still open from round 12b: legacy AJAX token-in-POST cleanup, `md5` password fallback (owner decision),
  LCP `fetchpriority` on the home hero.
