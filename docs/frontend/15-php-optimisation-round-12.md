# Round 12 — maximum PHP optimisation, server tuning and database hardening

Date: 2026-11-16 · branch `arena/01a1066c-shopclone7-payos` · base `b4e94513`

This round answers one instruction: **optimise PHP as far as it can honestly go**, and decide on merit
whether a Rust/Go rewrite would actually be more effective (it would not — see
`docs/rewrite-evaluation-php-vs-go-rust.md`). Nothing here changes the payOS flow, the legacy 410
guard, the CMSNT licence checks or any page's visible design.

Everything below was measured or verified in this checkout. Items that need MySQL or a live HTTP
server are listed as **NOT RUN** with the exact command the owner should run on staging. No number in
this document is invented.

---

## 1. Where a request's time actually went

A shop page is I/O-bound, not CPU-bound: PHP parses ~100 small files, then waits on MySQL. The
wins are therefore in **how many round trips a render makes** and **whether the server recompiles
the code on every request** — not in micro-optimising loops.

Three costs were identified in the code:

| Cost | Before | Why it mattered |
| --- | --- | --- |
| `settings` reads | up to one `SELECT * FROM settings WHERE name = …` **per key**, 20–60 per page | Every header, footer, currency and status check was a round trip |
| `__()` translation | **two** SELECTs per string: language row, then phrase row | An admin screen calls `__()` 100–300 times → hundreds of round trips before any content |
| `getStock()`, `currencyDefault()`, `getDiscount()`, `format_currency()`, `getUser()`, … | each called `new DB()` | New TCP + auth (+TLS) handshake per call, then closed again |

## 2. What changed

### 2.1 Settings: one query per request (values ≤ 1 KiB)

`libs/settings_cache.php` gained a bulk layer — `remember_all($loader)`, `has_all()`,
`map_loads()` — and `libs/db.php::site()` now loads every small setting in a single statement:

```sql
SELECT `name`, `value` FROM `settings` WHERE LENGTH(`value`) <= 1024
```

Large values (the FAQ blob, e-mail templates) stay out of the map and are fetched on demand by
`remember()`, so a page that never prints them never pays for them. Writes to `settings`
(`insert`/`update`/`remove`) flush both layers; `settings_loads()` exposes the counter for
`tools/perf-probe.php`.

**Effect:** a render that read 40 settings now costs 1 query + 0–2 for blobs, instead of 40–60.

### 2.2 Translations: one language lookup and one bulk map per request

`libs/lang.php` is now a small `OrbitLang` request cache:

- the `language` cookie is validated against `^[A-Za-z0-9._-]{1,16}$` before it reaches SQL;
- the language row is resolved once (`chosen()`), with the default language as fallback;
- all phrases for that language load in one `SELECT name, value FROM translate WHERE lang_id = N`;
- a missing key falls back to the **default language's** map (loaded lazily), then to the key itself —
  exactly the old behaviour;
- `__()` answers from memory and memoises the result per key;
- `setLanguage()` now also updates `$_COOKIE["language"]` and resets the cache, so a switch takes
  effect inside the same request (the old code only took effect on the next page load).

**Effect:** a page with 200 `__()` calls costs 1–2 queries instead of ~400.

### 2.3 One connection per request

`libs/helper.php` gained:

```php
orbit_db()    // returns the request's $CMSNT, creating it once
orbit_users() // one `users` instance per request instead of one per call
```

`getStock()`, `currencyDefault()`, `getDiscount()`, `debit_processing()`, `addRef()`,
`sendMessTelegram()`, `claimSpin()`, `TypePassword()`, `getUser()`, `format_currency()`,
`checkAddon()` and `debit_processing()`'s `users` object all reuse it. `getStock()` also no longer
fatal-errors when the product has no stock row (it returns `0`), and its code is escaped.

**Effect:** removes 1–10 extra `mysqli_connect()` handshakes per page.

### 2.4 Database layer hardening (`libs/db.php`)

| Change | Reason |
| --- | --- |
| `mysqli_report(MYSQLI_REPORT_OFF)` before connecting | PHP 8.1+ throws `mysqli_sql_exception` by default; the whole class is written for "false on error". Behaviour no longer depends on the host's `php.ini` |
| `mysqli_init()` + `MYSQLI_OPT_CONNECT_TIMEOUT = 5` | A dead database returns 503 in ~5 s instead of hanging the worker for 60 s |
| `mysqli_set_charset('utf8mb4')` replaces `set names 'utf8'` | 3-byte `utf8` silently mangles emoji; utf8mb4 is a superset, no data change |
| SQL errors no longer `exit("Câu truy vấn bị sai")` | A failed statement now returns `false` / `[]` / `0`, logs `[orbit-db] <driver message> at file:line`, and increments `DB::$errors`. SQL text is logged only under `DB_DEBUG=1` because statements can contain tokens |
| `site()` fallback key is escaped | Defence in depth, even though keys are literals today |
| Connect failure logs and answers HTTP 503 | No stack trace, no blank page |

The counter `DB::$queries` (already added this round) now counts every statement including failures,
and `DB::$errors` makes a "blank but no error" render impossible to miss.

### 2.5 Money movement is now atomic

`users::AddCredits()`, `RefundCredits()` and `RemoveCredits()` wrote a `dongtien` ledger row and then
updated `users.money` in two separate statements. If the second failed, the ledger claimed money had
moved when it had not. All three now run inside `DB::transaction()`: the ledger row and the balance
commit together, or neither does. The balance snapshot (`sotientruoc`/`sotiensau`) is read on the
transaction's own connection, and the user id is cast to `int` in every balance statement.

`DB::cong()`/`DB::tru()` now refuse a non-numeric amount (`"1abc"`, `""`) instead of letting MySQL
cast it to `1`/`0` and writing a ledger row that disagrees with the balance; the refusal is logged.
The admin credit/debit form in `views/admin/user-edit.php` parses the amount with
`FILTER_VALIDATE_FLOAT` and rejects anything non-finite or ≤ 0 before it reaches the database.

On MyISAM tables the engine ignores `BEGIN`/`ROLLBACK` — behaviour is then exactly what it was
before, never worse. On InnoDB (the normal install) the two writes are atomic.

### 2.6 Entry point (`index.php`)

- The maintenance-mode admin token is validated against `^[A-Za-z0-9]{16,64}$` before the one query —
  an invalid cookie can no longer reach SQL.
- `aff` is cast to `int` and only stored/queried when positive; the query selects `id` only and uses
  the integer.
- `utm_source` is reduced to `[A-Za-z0-9._-]`, capped at 64 chars, and not set when empty.
- Both tracking cookies are now `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS, and `Path=/`.

This is also a small perf win: the `aff` click previously ran `check_string()` and a `SELECT *` on
every request carrying the cookie.

### 2.7 Server configuration

`.user.ini` (PHP-FPM/CGI) — OPcache 192 MB / 20 000 files / `revalidate_freq=2`, realpath cache
4 MB / 600 s, `display_errors=Off`, `log_errors=On`, `expose_php=Off`, zlib output compression at
level 6 for hosts without `mod_deflate` (Render's PHP runtime), strict sessions with `HttpOnly` and
`SameSite=Lax`.

`.htaccess` — deflate + brotli for text types, `Cache-Control: public, max-age=31536000, immutable`
for `/public/client/orbit/` (every file is linked with `?v=<filemtime>`), 30 days for
`/public/client/fonts/` (font URLs are not versioned), `nosniff`, `Referrer-Policy`,
`X-Frame-Options: SAMEORIGIN`, `AddDefaultCharset UTF-8`, and a deny list for dotfiles, `vendor/`,
`docs/`, `tests/`, `tools/`, `install.php` and backup/archive extensions.

The store shell preloads `latin-400.woff2` **and** `vietnamese-400.woff2` (above the CSS link), so the
two files needed for first paint start together instead of after CSS parsing.

## 3. New tooling

### 3.1 `tools/perf-probe.php` — per-page baseline

Renders one real page in CLI and prints a single JSON line:

```bash
php tools/perf-probe.php home
php tools/perf-probe.php product netflix-1-thang
php tools/perf-probe.php blogs
# {"page":"home","queries":9,"settings_loads":1,"errors":0,"bytes":48211,"ms":38.2,"peak_mb":6.0}
```

It is read-only (GET pages only), refuses to run outside the CLI, and needs the same `DB_*` variables
as the site. `tools/dev/perf.sh` runs it N times per page and prints the median:

```bash
bash tools/dev/perf.sh 5 home product:netflix-1-thang blogs faq
```

**Status in this sandbox: NOT RUN** — no MySQL is configured here, so the probe exits with
`Error => DATABASE` (the expected path). Production numbers must be taken on staging.

### 3.2 `tools/db-index-audit.php` — missing-index detector

Reads `information_schema.STATISTICS` and checks each hot query's leftmost prefix. It never writes;
every miss is printed as the exact `ALTER TABLE` for the owner to review:

```bash
php tools/db-index-audit.php
# settings: PRIMARY(id), idx_name(name)
# users: PRIMARY(id), ...
#   MISSING (token)
#     ALTER TABLE `users` ADD INDEX `idx_users_token` (`token`);
```

Covered: `settings(name)`, `users(token|api_key|username)`, `products(status|category_id,status|slug)`,
`product_stock(product_code)`, `product_order(buyer,id|trans_id|trash)`,
`product_sold(trans_id|trans_id,buyer|buyer)`, `posts(slug|status,category_id)`,
`post_category(status)`, `payos_orders(order_code|status)`, `block_ip(ip,banned)`,
`failed_attempts(ip_address,type)`, `coupons(code)`, `currencies(display,default_currency)`,
`dongtien(user_id|transid)`.

**Status: NOT RUN** (needs the shop database). Run it once on staging and once on production read
replica before adding the indexes.

## 4. Verification (this checkout)

```text
$ bash tools/dev/run.sh
LINT 284 files, 0 parse errors
...
=== SUMMARY: 28 suites, 1 not run, 0 failures

$ node tools/dev/phpcl.mjs tests/lang_cache_test.php
24 passed, 0 failed.

$ node tools/dev/phpcl.mjs tests/db_hardening_test.php
30 passed, 0 failed.

$ node tools/dev/phpcl.mjs tests/settings_cache_test.php
23 passed, 0 failed.

$ bash tools/dev/legacy-gate.sh
14 legacy payment entry points stop with HTTP 410 when payOS is enabled.
```

`payos_service` remains **NOT RUN**: it needs a disposable database (`PAYOS_TEST_DB`), which this
sandbox does not have. It is not counted as passing.

The two new suites are honest about their limits: `lang_cache_test` uses an in-memory fake of the two
tables (it proves the query count, not MySQL performance), and `db_hardening_test` asserts the source
wiring (no MySQL here). `perf-probe` and the index audit are the tools that measure the real thing.

## 5. Deliberately not done

| Idea | Why not |
| --- | --- |
| Full-page cache for guests | The shell prints the visitor's login state and balance; a shared cache would leak one visitor's header to another. Correct keying needs a session-aware cache layer, not a directive |
| Prepared statements everywhere | The app opens one connection per request, so MySQL cannot reuse a prepared plan across requests (no `mysqli` persistent cache). Escaping + casting gives the same safety with a much smaller diff |
| Merging the 5 Orbit CSS files into one | Over HTTP/2 the 5 files cost roughly one request, and each is cached `immutable` with its own `?v=`. A bundle adds a build step the owner can forget to run |
| Rust/Go rewrite | See `docs/rewrite-evaluation-php-vs-go-rust.md` — DB round trips dominate, and the rewrite would have to reimplement 60+ payment integrations and the CMSNT licence layer |
| OPcache preload | Most files exit unless `IN_SITE` is defined; preloading them is unsafe, and OPcache + `revalidate_freq=2` already removes compilation from the request |

## 6. Owner actions

1. **Run the index audit on staging** and add any missing indexes in a maintenance window:
   `php tools/db-index-audit.php`. Adding an index on a large `product_sold` table can lock writes for
   seconds — MySQL 5.6+ supports `ALGORITHM=INPLACE, LOCK=NONE` for secondary indexes; use it.
2. **Take the baseline on staging** (`bash tools/dev/perf.sh 9 home product:<slug> blogs`) before and
   after enabling OPcache, and keep both JSON medians in this document.
3. **Confirm `.user.ini` is read**: `php -i | grep opcache.enable` under the same SAPI as the site, or
   check `opcache_get_status()` from a one-off script. On mod_php, move the values into the vhost.
4. **Confirm compression**: `curl -sI -H 'Accept-Encoding: br,gzip' https://<host>/ | grep -i content-encoding`.

## 7. Rollback

Every change is local to a file and independent:

- settings bulk layer → revert `libs/settings_cache.php` + the `site()` block in `libs/db.php`;
- translations → restore `libs/lang.php` from `b4e94513` (the old 57-line version);
- connection reuse → `orbit_db()`/`orbit_users()` are additive; the call sites are one-line reverts;
- DB hardening → revert `libs/db.php`; the audit/probe tools are read-only and can stay;
- server config → delete `.user.ini`, revert `.htaccess`;
- entry point → revert `index.php` (no schema or data change anywhere).

No SQL was executed against production in this round. No deployment was performed.
