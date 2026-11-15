# 11 — Social sign-in (Google / GitHub via Supabase) + performance & security notes

Status: **built and offline-verified; not yet exercised against a live Supabase project** (see §8).
Scope: customer login/registration only. Payments, payOS, GachtheFast, CMSNT licensing and the
Orbit pages are untouched. No new dependency was added.

## 1. What a visitor sees

- `client/login` and `client/register` gain a divider and one button per enabled provider.
- Clicking a button goes to `client/oauth-start?provider=google|github` (same origin, no popup,
  no third-party script, no iframe).
- Supabase/Google/GitHub do their thing and return to `client/oauth-callback?code=…`.
- The shop either logs the visitor in, links the identity to an existing account, creates an
  ordinary customer account, or redirects back to the login page with **one fixed sentence**
  explaining the refusal.

With the feature switched off (the default) the two pages render exactly as before. No settings
rows are required for that: a missing row means the documented default.

## 2. Files

| Layer | File | Responsibility |
| --- | --- | --- |
| Settings + rules (pure) | `libs/oauth.php` | names of the 7 settings, URL/key validation, PKCE, authorize URL, token-response parsing, linking rules, username generation, settings-form validation, error text |
| Transport | `libs/oauth_http.php` | the only network code: HTTPS, certificate + hostname verification, no redirects, 5/10 s timeouts, 256 KiB body cap, `apikey` header, provider-status probe |
| Start | `views/client/oauth-start.php` | one flow per session, PKCE pair, allow-listed return page, redirect to Supabase |
| Callback | `views/client/oauth-callback.php` | single-use flow, exchange, identity lookup, link/create/login, guards, cookie, log |
| Buttons/errors | `views/client/partials/oauth-buttons.php` | shared by login + register |
| Admin UI | `views/admin/partials/settings-social.php` | the Settings tab: switches, URL/key, callback URL + copy, setup steps, connection test |
| Save/test handler | `views/admin/settings.php` | CSRF-checked, whitelist-only write of the 7 rows; test-connection POST |
| Schema | `scripts/migrate-social-login.php` | creates `oauth_identities`, seeds missing settings, `--check`, `--rollback` |
| Cache | `libs/settings_cache.php` (+ `libs/db.php`) | one settings read per key per request; writes flush it |
| CSS | `public/client/css/user-auth.css` | button/divider styles (neutral, theme-agnostic) |

The Google/GitHub **client secrets never exist in this codebase**. They are entered in Supabase;
the shop stores only the project URL and the publishable/anon key (public by design).

## 3. Flow, step by step

```
browser ──GET /client/oauth-start?provider=google──► shop
                                                   │ verifier = random 64 chars
                                                   │ session: {provider, verifier, return, time}
                                                   ▼
browser ◄──302 https://<project>/auth/v1/authorize?provider=google
             &redirect_to=https://shop/client/oauth-callback
             &code_challenge=…&code_challenge_method=S256
                                                   │ Supabase → Google → Supabase
browser ──GET /client/oauth-callback?code=…────────► shop
                                                   │ flow record removed (single use)
                                                   │ POST /auth/v1/token?grant_type=pkce
                                                   │   {auth_code, code_verifier}
                                                   │   apikey + Bearer anon key
                                                   ▼
                                             GoTrue validates PKCE,
                                             returns user {id, email, app_metadata.provider}
                                                   │
                                                   ▼
                                    link / create / login decision → cookie
```

## 4. Security decisions (and why)

| Decision | Reason |
| --- | --- |
| **PKCE S256, verifier in the PHP session** | The code alone is useless without the browser-bound secret. Supabase does not reliably echo a custom `state` back, so PKCE + a single-use session record is the CSRF control; a replayed callback finds no flow. `oauth-start` accepts only `google`/`github`. |
| **Server-to-server exchange over TLS** | No token, identity or email is accepted from the browser. TLS to the configured project authenticates the response, so no JWT signature check is needed. Redirects are refused and certs are verified. |
| **Identity = (provider, UUID subject)** | Stable across email changes at the provider. Stored in `oauth_identities` with `UNIQUE(provider, subject)` and `UNIQUE(user_id, provider)`. |
| **Email is not an identity** | `oauth_link_by_email` defaults **off**. Even when on, linking requires `email_confirmed_at` from Supabase. Without it, the login is refused with "email này đã có tài khoản". |
| **Admin / banned / 2FA accounts are refused** | Checked twice: when planning the action and again on the row about to be logged in. A Google password can never become a bypass of the operator account or of 2FA/OTP mail. |
| **New accounts are ordinary customers** | `oauth_user_payload()` writes no `admin`, `money`, `ref_*` or status column. The password column holds `password_hash(random, BCRYPT)` — unusable for password login. |
| **Fixed redirect list** | `oauth_normalize_return()` accepts only six `client/...` pages; everything else (absolute URLs, `../`, query strings) becomes `client/home`. |
| **No secrets in HTML** | The anon-key field is always empty; a masked value (first 4 + last 4) is shown as a placeholder. Saving with an empty field keeps the stored key. The admin HTML is tested not to contain the key. |
| **CSRF on the settings form** | New `verify_csrf_token()` (constant-time) plus a hidden token; the handler refuses before writing anything. |
| **Whitelist write** | Only the seven `OAUTH_*` names are read from `$_POST` and written; `oauth_settings_validate()` rejects an unsafe URL/key and an inconsistent switch combination. |
| **Rate limit** | Five failed callbacks per ten minutes per session → `rate_limited`; success clears the counter. |
| **Dead link is repaired** | If an identity row exists but its `users` row was deleted, the unique insert fails and the row is **re-pointed** to the account being logged in, instead of being left on the dead id (which would create a duplicate account on every later login). |
| **Headers** | `Referrer-Policy: no-referrer` and `Cache-Control: no-store` on both flow pages; the `code` never travels as a Referer. Session id is regenerated before the auth cookie is set (`set_auth_cookie`). |
| **No third-party assets** | The buttons are inline SVG + a local stylesheet. No Google/GitHub SDK, no `cdn`, no cookie set by the shop before consent. |
| **SSRF limits** | The project URL must be `https`, a real hostname, no credentials/path/query; localhost, `.internal/.local/.home.arpa` and private/loopback IPs are refused. The URL is admin-only. |

### Refusal matrix (tested)

| Situation | Result | Visitor message |
| --- | --- | --- |
| Feature off / provider off / no URL+key | refused | "hiện chưa được bật" |
| Link exists, account normal | login | — |
| Link exists, account admin | refused | "không thể dùng … cho tài khoản quản trị" |
| Link exists, banned | refused | "Tài khoản đang bị khoá" |
| Link exists, 2FA/mail-OTP on | refused | "Hãy đăng nhập bằng mật khẩu để xác minh" |
| Same email, opt-in off | refused | "Email này đã có tài khoản …" |
| Same email, opt-in on, email unverified | refused | "Email … chưa được xác minh" |
| Same email, opt-in on, verified | link + login | — |
| No account, auto-create on | create + login | — |
| No account, auto-create off | refused | "Chưa có tài khoản …" |
| Provider mismatch / no email / bad UUID | refused | invalid provider data |
| Code expired/reused (400/401/403) | refused | "Mã đăng nhập không còn hiệu lực" |
| Network/TLS/timeout | refused (fail closed) | "Không kết nối được …" |

## 5. Admin setup (the Settings tab)

1. Run the migration once on the server (adds one table, seeds seven rows, changes nothing else):

   ```
   php scripts/migrate-social-login.php --check   # report only
   php scripts/migrate-social-login.php           # apply
   ```

2. Supabase Dashboard → Authentication → Providers: enable **Google** and/or **GitHub**; paste the
   client id/secret **there**.
3. Supabase → Authentication → URL Configuration → Redirect URLs: add exactly the URL shown in the
   tab (copy button), and set Site URL to the shop domain.
4. Supabase → Project Settings → API: copy **Project URL** and **anon/publishable key** into the tab.
   Never paste a `service_role` key.
5. Switch the master toggle + the providers you configured, press **Kiểm tra kết nối** (no save),
   then **Lưu**.
6. Test in a private window. The login page shows a fixed sentence for any failure.

The seven rows:

| name | default | meaning |
| --- | --- | --- |
| `oauth_enabled` | `0` | master switch |
| `oauth_google` | `0` | show the Google button |
| `oauth_github` | `0` | show the GitHub button |
| `oauth_supabase_url` | `""` | `https://<ref>.supabase.co` |
| `oauth_supabase_anon_key` | `""` | publishable/anon key |
| `oauth_auto_create` | `1` | create an ordinary customer for a new identity |
| `oauth_link_by_email` | `0` | link to an existing same-email account (verified only) |

## 6. Performance

| Change | Effect | Evidence |
| --- | --- | --- |
| `DB::site()` memoised per request (`libs/settings_cache.php`) | One `SELECT … FROM settings WHERE name = …` per distinct key instead of one per read. | Static count of literal `->site("…")` reads in the files a page renders: **login chain** (`login.php`, `header.php`, `footer.php`, `nav.php`) 89 reads → 45 distinct keys, i.e. **44 queries avoided per render**; **admin settings chain** (`settings.php`, admin `header/sidebar/footer`) 133 → 93, **40 avoided**. Runtime query-count: **NOT RUN** (no MySQL in this sandbox); the cache semantics are covered by `tests/settings_cache_test.php` (15 checks). |
| OAuth settings loaded in **one** query | The login/register partial and the Settings tab used to be able to issue seven queries; they now issue one. A second caller can reuse the array (`oauth_config($cmsnt, $raw)`). | `tests/oauth_test.php`: "the whole setting group is read with one query", "only the seven documented names are requested", "a caller that already loaded the rows causes no second query". |
| Callback query budget | 1 flow removal (session), 1 table probe, 1 identity, 1 user-by-id, 1 user-by-email, 1 insert/update, 1 user update, 1 log insert — bounded and constant, independent of shop size. | Code review; no unbounded query in the file. |
| Network | 5 s connect / 10 s total, 256 KiB cap, no retries — a slow provider cannot hold a PHP worker for long. | `libs/oauth_http.php` |
| Assets | No new request on login (inline SVG + existing `user-auth.css`); no third-party script. | `tests/oauth_test.php`, `oauth_pages_test.php` |

The cache is request-scoped and shared across `DB` instances (the shop creates `new DB()` in several
helpers). Every write to `settings` flushes it, so "save then read" in the same request is correct.
Long-running workers should call `flush_site_cache()` if they cache settings across cycles.

## 7. Verification (commands + actual output)

Harness: PHP 7.4 WASM, checkout mounted at `/app`, tests wrapped only to provide `STDOUT`/`STDERR`.

```
node /home/user/harness/batch.mjs /home/user/SHOPCLONE7_PAYOS /tmp/sweep_new.json
=== LINT 277 files ===
LINT 277 files, 0 parse errors
settings_cache_test: 15 passed, 0 failed
oauth_pages_test: 33 passed, 0 failed
oauth_test: 120 passed, 0 failed
=== SUMMARY: 15 runs, 0 harness-level failures ===   (863 assertions)
```

`oauth_test.php` (120) covers the RFC 7636 vector, PKCE randomness, URL/key refusal list, one-query
settings load, authorize URL shape, exchange request body/headers, provider mismatch, missing email,
bad UUID, expired code, transport failure, all ten linking outcomes, username generation, the created
account payload, flow expiry/replay, return-path allow-list, settings validation/masking, error text,
table detection, button markup and the provider-status probe.

`oauth_pages_test.php` (33) renders the real partials and asserts the stored anon key **never**
appears in the admin HTML, the CSRF field is present, a missing table produces the migration command,
and the test-connection result is readable without leaking provider/curl text.

Not covered (honest list):

- **Live Supabase exchange: NOT RUN.** No project/credentials were supplied, and the work rules
  forbid asking for them in chat. The exchange is tested against a fake transport that asserts the
  exact URL, body and headers.
- **MySQL migration: NOT RUN.** No MySQL server in this sandbox; the SQL is DDL-only, idempotent, and
  `--check` exists so it can be reviewed on staging first.
- **Browser end-to-end: NOT RUN** (no browser/headless session; no jsdom install this round).
- **Concurrent first login** with the same new email can, in principle, create two accounts because
  the legacy `users` table has no transaction helper here. The window requires two simultaneous
  callbacks for the same unused code — PKCE codes are single-use — and is documented as residual.

## 8. Rollback

1. Settings → turn the master switch off (buttons disappear immediately, no deploy needed).
2. `php scripts/migrate-social-login.php --rollback` — drops only `oauth_identities`; settings rows,
   users, orders, balances and passwords are untouched.
3. Revert the code files listed in §2 if a full removal is wanted.

Deleting the table does **not** delete accounts created through it: they are ordinary `users` rows
with a bcrypt password nobody knows, which is the fail-closed state. Analyse them before removing.

## 9. Residual risks / follow-ups

- `users.email` uniqueness is assumed, not enforced by this change. A duplicate email would make
  `oauth_link_plan` see one row; the rest of the shop already treats email as the login identifier,
  so this is a pre-existing schema property. Recommend a unique index (after de-duplication) as a
  separate, approved migration.
- The admin-entered Supabase URL can resolve (via DNS) to a private address after the name check.
  The URL is admin-only and https-cert-validated; this is accepted, not ignored.
- `oauth_identities.email` is stored for support/audit. It is not used for authorisation.
- The anon key is public by design; the security boundary is Supabase's provider configuration and
  the linking rules above. A leaked anon key does **not** allow account takeover here.
