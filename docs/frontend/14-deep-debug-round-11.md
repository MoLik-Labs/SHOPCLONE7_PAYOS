# 14 — Deep debug round 11: executable tests, and a real injection in the money path

**Date:** 2026-11-16 · **Branch:** `arena/01a1066c-shopclone7-payos` (committed, not deployed)
**Scope:** the whole PHP tree, with priority on the paths that move money and the pages rebuilt in
rounds 9–10. No production database, provider or customer data was touched.

This round had two jobs: apply the Anthropic skills (`docs/skills/`) and go deeper on correctness and
security. The second job needed something the sandbox never had — a PHP interpreter. That is now
solved, which turned a set of "written but unexecuted" suites into real results.

---

## 1. What can now be executed

The sandbox has no native `php`, no MySQL and no Composer vendor tree. `.harness/phpcl.mjs` boots
**PHP 8.2.29-dev** (php-wasm) as the CLI SAPI with the repository mounted, so tests run against the
real views and helpers. `STDOUT`/`STDERR`, `getenv()`, `http_response_code()`, sessions and `__DIR__`
all behave as on a server; `proc_open()` and `mysqli` do not.

| Check | Command | Actual result |
| --- | --- | --- |
| Syntax of every PHP file | `bash .harness/run.sh "$PWD" .harness/specs/all.json` | `LINT 282 files, 0 parse errors` |
| Page/service suites | same | `SUMMARY: 25 runs, 0 harness-level failures` |
| Legacy payment gate | `bash .harness/legacy-gate.sh` | `14 legacy payment entry points stop with HTTP 410 when payOS is enabled.` |
| Static preview | `node .harness/phpcl.mjs tools/preview-build.php` | `319 class(es) used, all defined`, 11 assets, every asset resolves |

Suites that ran and their counts: chart 99, client pages 46, gtf rules 124, legacy security
(`Legacy security regression checks passed.`), licence client 81, music 16, oauth pages 33, oauth 120,
orbit shell 32, order quantity 31 (new), order pages 28, pagination 36, payOS client 45 (offline),
recharge 24, sales pages 61, sales scoping 27, sales stats 118, settings cache 15, sidebar 21,
storefront 36, styleguide 35.

**NOT RUN, and not counted as passing:** `gtf_service_test`, `gtf_credit_path_test` and
`payos_service_test` need a disposable MySQL database (`GTF_TEST_DSN` / `PAYOS_TEST_DB`). They print
`SKIP`/`Set PAYOS_TEST_DB…` in this environment. The browser E2E pass on a real server also remains
NOT RUN.

## 2. Finding F1 — SQL injection through the order quantity (High, fixed)

`ajaxs/client/product.php` (`buyProduct`) and `api/api.php` (`create-order`) accepted the requested
quantity as a string, checked it with `check_string()` and loose comparisons, then interpolated it into
an unquoted position:

```php
$amount = check_string($_REQUEST["amount"]);
if ($amount < $product["min"]) { … }
if ($product["max"] < $amount) { … }
…
" … ORDER BY … LIMIT " . $amount . " "
```

A payload such as `1 PROCEDURE ANALYSE(EXTRACTVALUE(1,CONCAT(0x3a,VERSION())),1)` passes both loose
comparisons and is not `is_numeric()`, so the fractional check does not fire. Measured with PHP 8.2:

```
amount<min: bool(false)
max<amount: bool(false)
is_numeric: bool(false)
limit sql: LIMIT 1 PROCEDURE ANALYSE(EXTRACTVALUE(1,CONCAT(0x3a,VERSION())),1)
```

That is a classic numeric-context injection reachable by any signed-in customer (buyProduct) or API
caller (create-order). MySQL 8 removed `PROCEDURE ANALYSE`, but the same slot accepts other
expressions and is enough for blind extraction on MySQL 5.7 installations.

**Fix:** `order_quantity()` in `libs/helper.php` accepts only `int` or a 1–9 digit string, after
trimming. Both endpoints now refuse anything else with the existing "invalid quantity" message before
building SQL; the public API returns its normal JSON error. Valid numeric input and all messages are
unchanged.

**Regression test:** `tests/order_quantity_test.php` — 31 checks, including the PROCEDURE payload,
`1e3`, `10.5`, `-1`, `0x1A`, a newline-smuggled value, 10-digit overflow, arrays, booleans, floats, and
source assertions that both endpoints keep calling the validator.

## 3. Tooling finding — the payment gate test could not run

`tests/legacy_payment_gate_test.php` launches each legacy callback with `proc_open()` and
`PHP_BINARY`; neither exists in the wasm runtime. The check was therefore never executed in this
sandbox. `.harness/legacy-gate.sh` runs the same 14 probes as separate processes and asserts
`HTTP_STATUS=410` with empty stderr. While wiring it, the driver briefly listed
`api/callback-toyyibpay.php` while the real file is `api/callback_toyyibpay.php`; the repository's own
test list was correct and is unchanged.

## 4. What this round did not prove

- MySQL-dependent suites (payment state machine, exactly-once under concurrency) are **NOT RUN**.
- No browser E2E against a running shop; previews are static renders of real views with sample rows.
- The legacy views outside the rebuilt pages (`blogs`, `blog`, `faq`, `purchase-history`) were not
  re-audited for XSS or SQL in this round.
- CSRF on legacy `token`-authenticated endpoints is unchanged by design; it is an owner decision, not
  a silent rewrite.

## 5. Where the evidence lives

- Harness (git-excluded): `.harness/phpcl.mjs`, `.harness/run.sh`, `.harness/lintlist.php`,
  `.harness/legacy-gate.sh`, `.harness/specs/all.json`.
- Rebuild instructions and the working method: `docs/skills/project/php-monolith-debug/SKILL.md`.
- Security checklist used for this pass: `docs/skills/project/security-review/SKILL.md`.
- Vendored upstream skills and licence notes: `docs/skills/README.md`.
