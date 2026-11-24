# Round 15 — product availability and checkout quote safety

Scope: product UI and browser-side quote/retry handling; no payment/provider call, deployment, database
migration or production data. This is not a redesign of the payment service.

## Changes

- Do not offer a purchase when positive stock or the configured upper bound is below the product's
  minimum order quantity. Explain the limitation and disable the inactive stepper controls. A guest
  seeing an out-of-stock product is no longer invited to sign in as though it were purchasable.
- A signed-in buyer without JavaScript now sees a disabled purchase button and a clear explanation.
  The product breadcrumb encodes a category slug and escapes its HTML attribute.
- A purchase can only be submitted after a current price quote succeeds. Editing the quantity or
  coupon immediately invalidates the displayed subtotal, discount and total; a monotonically
  increasing version ignores late responses. A failed quote offers an explicit retry. The checkout
  also rechecks the field values at submission in case autofill or a script skipped input events.
- While a purchase is in flight, quantity and coupon controls are locked. A retry after an uncertain
  result uses the same key and the same amount/coupon; changing either cannot silently replay the old
  key. The UI tells the buyer to inspect order history and restore the original selection. A server
  error does not prove the original request is safe to repeat with new parameters. Because the
  existing endpoint does not distinguish all pre-debit errors from uncertain outcomes, this rule is
  conservative and can require support or investigation even for a harmless validation error.

These browser-side changes **do not** freeze the price at the server, prove exactly-once delivery,
persist a key across reloads, prevent concurrent requests from different tabs, or recover a crash
between debit and delivery. Server-side durable claims, a schema/unique-index migration plan,
reconciliation and real concurrent integration tests are still needed. Do not infer production
readiness from the UI tests.

## Evidence

- `node tools/dev/phpcl.mjs tests/client_pages_test.php` — PASS: 53 passed, 0 failed. New minimum,
  no-JavaScript and hostile-slug checks failed before the fix.
- `node tests/js/checkout_quote_test.mjs` — PASS: 24 mocked DOM/fetch checks covering pending and
  stale quotes, failed quotes, unobserved edits, changed retry inputs and the original retry key.
- `node tests/js/checkout_retry_test.mjs` — PASS: 6 mocked DOM/fetch checks.
- `LD_LIBRARY_PATH=/tmp/al2023/lib node tools/dev/browser-checkout.cjs` — PASS: 8 checks in Chromium
  against a synthetic page and local mock endpoint, including a simulated gateway error, changed and
  unchanged retry, and quote failure/retry. It never calls a real payment endpoint.
- `bash tools/dev/run.sh` — PASS with limits: 321 PHP files linted, 0 parse errors; 239 files scanned,
  0 findings; 38 fixture renders; 56 suites, 3 NOT RUN, 0 partial, 0 failures.
- `PREVIEW_ROOT=/tmp/orbit-r15-final LD_LIBRARY_PATH=/tmp/al2023/lib node tools/dev/browser-audit.cjs`
  — PASS: 38 fixture pages × 2 widths, 0 failing combinations for the documented smoke checks.
  `tools/dev/browser-interactions.cjs` also passed the fixture interactions listed in round 14.

The three database-dependent GTF/payOS suites remain NOT RUN unless disposable databases are
configured; php-wasm does not provide native MySQL. No real payment, provider, deployment, Apache
routing or full accessibility integration test was performed.

For the synthetic browser checkout test, run `npm install --prefix tools/dev`,
`bash tools/dev/setup-chromium.sh`, then the browser command above. For whole-page static browser
coverage, see `24-storefront-review-round-14.md` and `tools/dev/browser-audit.cjs`.
