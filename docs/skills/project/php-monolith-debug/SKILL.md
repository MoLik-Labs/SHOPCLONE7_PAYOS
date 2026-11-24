---
name: php-monolith-debug
description: Reproduce, test and debug PHP code in this repository when there is no native PHP CLI or database. Use when a view, AJAX endpoint, service or test needs to be executed, or when a bug must be proven fixed with evidence.
---

# Debugging this PHP monolith

Plain PHP 7.4-era code, no framework, no Composer vendor tree in the checkout, no MySQL in the
sandbox. "It looks right" is not evidence. The loop is always: **reproduce → isolate → failing check →
fix → re-run → full sweep**.

## 1. Run PHP anyway

`tools/dev/phpcl.mjs` boots PHP 8.2 (php-wasm) as the CLI SAPI with the host repository mounted.
It is a useful local test runtime, not a native PHP installation or a substitute for production PHP,
MySQL, provider integrations, or browser interaction tests. `proc_open()` and real `mysqli` are not
available through the shim.

```bash
npm install --prefix tools/dev --no-audit --no-fund
node tools/dev/phpcl.mjs tests/storefront_test.php
node tools/dev/phpcl.mjs tools/preview-build.php --out=/tmp/shopclone7-preview
```

For static browser smoke tests, the optional Chromium package is already in `tools/dev/package.json`:

```bash
bash tools/dev/setup-chromium.sh
node tools/dev/phpcl.mjs tools/preview-build.php
LD_LIBRARY_PATH=/tmp/al2023/lib node tools/dev/browser.mjs /tmp/shots docs/frontend/preview/home.html --widths=1440,390
```

The preview builder's default output modifies tracked preview files; use a throwaway `--out` for
normal regression testing. The browser harness currently serves the default preview directory, so
only regenerate it deliberately and review the diff. It serves over HTTP rather than `file://` to
avoid blocked font requests. Screenshots and overflow/error counts alone do not verify keyboard,
contrast, interactions, real data, production services or accessibility.

## 2. The test pattern

Every page test follows the same shape: stub the database and the helper functions, then include the
real view in an output buffer and assert on the rendered HTML.

```php
<?php
define("IN_SITE", true);
function BASE_URL($p = "") { return "https://shop.example/" . ltrim($p, "/"); }
function check_string($v) { return trim(htmlspecialchars(addslashes((string) $v))); }
function __($t) { return $t; }
function format_currency($n) { return number_format((float) $n, 0, ",", ".") . "đ"; }

class DB
{
    public function site($key) { return $key === "title" ? "Orbit Shop" : ""; }
    public function get_row($sql) { return strpos($sql, "FROM `users`") !== false ? $user : false; }
    public function get_list($sql) { return array(); }
    public function num_rows($sql) { return 0; }
}

$_GET = array("module" => "client", "action" => "product", "slug" => "netflix");
ob_start();
include __DIR__ . "/../views/client/product.php";
$html = ob_get_clean();
```

Rules:
- **No database, no network.** A stub answers the exact queries the page sends. If a query is not
  stubbed, return `false`/`array()` and make the page handle it honestly.
- **Capture warnings**: `set_error_handler()` around the `include`, then assert `$warnings === array()`.
- **Grep exact strings** (`strpos`) — one check, one claim. Report counts, not adjectives.
- **Redirects** are asserted with a test-local `redirect()` that prints `REDIRECT:<url>`; never let a
  test call `header()`.
- **Never mark a suite as passing if it skipped.** `SKIP: GTF_TEST_DSN is not set` is NOT RUN.
  Register such suites in the harness spec with `"not_run": ["marker"]`.

## 3. The regression sweep

```bash
bash tools/dev/run.sh                 # lint, security scan, JS syntax, preview, suites
bash tools/dev/run.sh --lint-only
bash tools/dev/run.sh tests/storefront_test.php
```

The runner uses `tools/dev/lintlist.php`, reports skipped database suites as NOT RUN and uses
`tools/dev/legacy-gate.sh` for the test that requires process isolation. Treat fixture rendering as
static evidence, not proof of real database, browser interaction, or provider behavior.

## 4. Where things are

| Need | Path |
| --- | --- |
| Router | `index.php` → `views/<module>/<action>.php`; `.htaccess` rewrites pretty URLs |
| URL | `BASE_URL()` / `base_url()` (`libs/helper.php:536`) |
| Escaping | `check_string()` (`:679`) — SQL-ish escaping, **not** a number validator |
| Quantities | `order_quantity()` — digits only, safe for `LIMIT` |
| Shell | `views/common/store-shell.php`; pager in `views/common/pagination.php` |
| Auth | `models/is_user.php` sets `$getUser` and `$GLOBALS["getUser"]` |
| Preview | `tools/preview-build.php` + `tools/preview-data.php` (CLI only, sample rows) |
| Money | `libs/payos*.php`, `libs/gtf*.php`, `libs/database/users.php` |

## 5. Debugging traps

- `DB::get_row()` **exits** when a query fails; it is not a probe. Use `query()` while debugging.
- `check_string()` escapes quotes but leaves `1 PROCEDURE ANALYSE(...)` intact. Any value used in a
  numeric or `LIMIT` context must go through `order_quantity()` (or `(int)`).
- `$getUser` is a local in some call sites and a global published by `models/is_user.php` in others.
  Use the global in shell helpers.
- The preview stub is a fake `DB` class named `DB`; `models/is_user.php`'s `require_once` is a no-op on
  later pages in the same build, so the builder publishes `$GLOBALS["getUser"]` itself.
- `tools/preview-build.php` refuses to run outside the CLI and writes only into `docs/frontend/preview/`.
- Never run `update.php`, never point a test at a production DSN, never invent a passing result.
