# Round 14 — customer navigation, copy feedback and fixture browser audit

Scope: small, tested fixes to the current PHP storefront and shared shell. No deployment, schema
migration, real payment/provider call or production data was used.

## Findings and changes

- **Search, filters and pagination could lose their query string under the checked-in Apache rules.**
  The pretty-route rules substitute their own query string without QSA. Rather than globally alter
  routing (which could allow duplicate `module`/`action` parameters to override route identity), the
  affected home search/sort, shared client pager, order list/detail, purchase history and affiliate
  filter forms now submit or link to `index.php` with explicit route parameters. The shared pager
  refuses a query-supplied module/action override for a `client/<action>` path. This finding and fix
  are based on configuration and render tests; Apache itself was unavailable here, so an end-to-end
  rewrite test remains necessary on a representative staging server. Other routes have not all been
  exhaustively audited for the same issue.
- **Home pagination:** clamp a stale page number before its SQL query, retain pagination when the
  current page contains only hidden sold-out products, and ignore array-valued search/filter inputs
  without PHP notices. Product/category path components are URL encoded and output is HTML escaped.
  The current total still counts active products *before* out-of-stock filtering; an empty page can
  therefore be valid, but visitors can reach adjacent pages. A change to the meaning of counts or
  stock filtering needs representative database performance and business-rule review.
- **Clipboard:** account-row controls with `data-copy-value` had no listener; permission failures could
  also be silent or incorrectly reported as success. Shared controls now report refusal and select
  text inputs for manual copying; bulk order-copy denial points to download/manual copying without
  silently downloading secrets. The emoji fallback checks the actual result, reports failure, and
  returns keyboard focus. These paths are tested with simulated clipboard outcomes, not the user's
  system clipboard or real credentials.
- **Labels and semantics:** the invoice address has an associated visible label. The admin design
  reference keeps the heading-1 visual sample without adding another document `h1`, and its two
  example inputs have labels.
- **Home hero price:** the prior setup round fixed unclamped discounts using the shared pricing
  helpers, with a failing-then-passing regression test.

## Evidence

| Command | Result |
| --- | --- |
| `bash tools/dev/run.sh` | PASS with limits: 321 PHP files linted, 0 parse errors; 239 scanned, 0 findings; 38 fixture renders; 55 suites, 3 NOT RUN, 0 partial, 0 failures. |
| `node tools/dev/phpcl.mjs tests/storefront_test.php` | PASS: 48 passed, 0 failed (the new route/input cases were observed failing before the fixes). |
| `node tools/dev/phpcl.mjs tests/orders_pages_test.php` | PASS: 30 passed, 0 failed. |
| `node tools/dev/phpcl.mjs tests/account_pages_test.php` | PASS: 231 passed, 0 failed. |
| `node tools/dev/phpcl.mjs tests/sales_pages_test.php` | PASS: 61 passed, 0 failed. |
| `node tools/dev/phpcl.mjs tests/styleguide_render_test.php` | PASS: 37 passed, 0 failed. |
| `node tests/js/clipboard_test.mjs` | PASS: 10 mocked DOM/clipboard checks. |
| `node tools/dev/phpcl.mjs tools/preview-build.php --out=/tmp/orbit-review` | PASS: assets resolve; 361 classes used, all defined. |
| `PREVIEW_ROOT=/tmp/orbit-review CHROMIUM_PATH=/tmp/orbit-chromium LD_LIBRARY_PATH=/tmp/orbit-al2023/lib node tools/dev/browser-audit.cjs` | PASS: 38 static pages × 2 widths = 76 combinations; 0 failing combinations for the checks below. |
| Same environment, `node tools/dev/browser-interactions.cjs` | PASS: mobile menu open/Escape/focus, route fields, invoice label, simulated clipboard denial on invoice, emoji and account, and no page errors. |

The browser audit checks document-width overflow, heading count, a basic visible-control label/name
heuristic, JS console/page errors and failed or HTTP-error requests. It is not WCAG certification,
visual approval at every breakpoint, a screen-reader test, a real server or a complete interaction
suite. The static fixture builder intentionally makes application links inert. The tools can be run
again using `npm install --prefix tools/dev`, `bash tools/dev/setup-chromium.sh`, and the commands in
`docs/skills/project/orbit-storefront/SKILL.md`.

## NOT RUN / remaining work

- `gtf_credit_path`, `gtf_service`, and `payos_service` need disposable database fixtures and were
  NOT RUN. Provider calls, live payments and deployment were deliberately NOT RUN.
- No Apache executable was present; HTTP rewrite semantics and the modified route flows require a
  real Apache staging check. Nginx/other server configurations also need equivalent verification.
- The complete authenticated, responsive, screen-reader and no-JavaScript customer journeys remain
  to be tested with representative database data, including empty and sold-out catalogue pages.
- Checkout does not yet guarantee exactly-once delivery under every retry/crash/concurrency scenario;
  canonical production origin/Host allowlisting is also unresolved. Do not claim production readiness
  from the fixture gate. See `23-csrf-and-money-paths-round-13d.md` for earlier evidence and limits.
