---
name: php-monolith-debug
description: Reproduce, test and debug PHP code in this repository when there is no native PHP CLI or database. Use when a view, AJAX endpoint, service or test needs to be executed, or when a bug must be proven fixed with evidence.
---

# Debugging this PHP monolith

Plain PHP 7.4-era code, no framework, no Composer vendor tree in the checkout, no MySQL in the
sandbox. "It looks right" is not evidence. The loop is always: **reproduce → isolate → failing check →
fix → re-run → full sweep**.

## 1. Run PHP anyway

`.harness/phpcl.mjs` boots PHP 8.2.29 (php-wasm) as the CLI SAPI with the host repository mounted.
`STDOUT`/`STDERR`, `getenv()`, `__DIR__`, `$_SERVER['argv']`, `http_response_code()` and sessions all
work; `proc_open()` and a real `mysqli` do not.

```bash
node .harness/phpcl.mjs tests/recharge_pages_test.php
node .harness/phpcl.mjs tools/preview-build.php
```

If `.harness/` vanished (it is intentionally git-excluded), rebuild it:

```bash
mkdir -p .harness && cd .harness && npm init -y
npm i @php-wasm/universal@2.0.22 @php-wasm/node@2.0.22

# screenshots (optional): Chromium 153 + its AL2023 libraries
npm i @sparticuz/chromium puppeteer-core
node -e 'const fs=require("fs"),z=require("zlib"),p=require("path");const d="node_modules/@sparticuz/chromium/bin";
fs.writeFileSync("/tmp/al2023.tar",z.brotliDecompressSync(fs.readFileSync(p.join(d,"al2023.tar.br"))));
fs.writeFileSync("/tmp/chromium",z.brotliDecompressSync(fs.readFileSync(p.join(d,"chromium.br"))));'
tar -xf /tmp/al2023.tar -C /tmp/al2023 && chmod 755 /tmp/chromium
LD_LIBRARY_PATH=/tmp/al2023/lib /tmp/chromium --version   # Chromium 153.0.8010.0
```

`.harness/browser.mjs` serves `docs/frontend/preview/` over a throwaway localhost port before taking
shots. **Do not screenshot `file://` pages**: the browser blocks the font fetch as a cross-origin
request, the page silently renders in a fallback typeface, and the report shows two bogus console
errors. Over HTTP the same pages report `errors=0 failed=0`.

```js
// .harness/phpcl.mjs
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
const php = new PHP(await loadNodeRuntime('8.2'));
useHostFilesystem(php);
const args = process.argv.slice(2);
const stream = await php.cli(['php', '-d', 'session.save_path=/tmp', args[0], ...args.slice(1)]);
process.stdout.write(await stream.stdoutText);
process.stderr.write(await stream.stderrText);
process.exit(await stream.exitCode);
```

Why the CLI SAPI: under the request SAPI (`php.run`), `STDOUT`/`STDERR` are undefined and `exit()`
becomes an exception. Tests legitimately call `fwrite(STDERR, "SKIP…")`.

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
bash .harness/run.sh "$PWD" .harness/specs/all.json
# LINT 282 files, 0 parse errors
# SUMMARY: 24 runs, 0 harness-level failures
```

A spec is `{"lint": "all" | [files], "run": [{"script": "tests/x_test.php", "label": "x"}]}`;
a shell-driven suite uses `{"cmd": "bash .harness/legacy-gate.sh"}`. The legacy payment gate needs
`vendor/autoload.php`; the checkout has no Composer tree, so a **test-only** stub defines
`Dotenv\Dotenv` (vendor/ is gitignored — never commit it, never use it in production).

Lint is `token_get_all($code, TOKEN_PARSE)` in `.harness/lintlist.php`; it catches syntax errors the
eye misses (an `elseif` after a closed brace already cost a round).

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
