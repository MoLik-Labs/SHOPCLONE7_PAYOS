# Checkout support — round 17

## Change and decision

For a valid pending sessionStorage marker, the product panel now shows the original request key as a
**request reference, not an order ID**, with links to order history and the shop contact page. The
reference is validated and inserted with `textContent`; a corrupt marker or storage failure before
sending does not invent or render a reference. The reference survives same-tab reload, carries no
credentials and is not put in either link. A narrow-screen rule wraps long values. A read-only support
runbook is in `docs/frontend/28-checkout-support-runbook.md`.

We reviewed adding a "safe to retry" response for pre-debit errors. A response proves at most what
that request did, not that another duplicate with the same key isn't still executing; there is no
durable request claim. Coupon reservation and wallet processing also have separate boundaries.
Consequently this round does not unlock retries on these responses. The misleading comment in the API
and the corresponding order-key test description were corrected without changing API behavior. The
tradeoff in round 17 was that even ordinary insufficient-balance errors blocked the buy panel in that
tab. Round 31 adds a narrowly scoped, explicitly tagged pre-processing rejection for this request
(including insufficient balance without a coupon); unstructured responses remain blocked. This is
not an authoritative cross-client claim or full recovery workflow. See `31-checkout-preflight-unlock.md`.

## Verification

- **PASS** `bash tools/dev/run.sh`: `LINT 321 files, 0 parse errors`; `SCAN 239 files: 0 critical, 0 high, 0 medium, 0 low`; 38 pages rendered; `57 suites, 3 not run, 0 partial, 0 failures`. Product fixture: `54 passed, 0 failed`; mock checkout suites passed.
- **NOT RUN** `gtf_credit_path`, `gtf_service`, `payos_service`: no disposable database configured. No native MySQL/InnoDB concurrency, crash, real purchase or provider tests.
- **PASS** `LD_LIBRARY_PATH=/tmp/al2023/lib node tools/dev/browser-checkout.cjs`: 11 checks against a synthetic local checkout and mocked endpoint, including reference after reload, links, no stored credentials and narrow viewport wrapping.
- **PASS** `node tools/dev/phpcl.mjs tools/preview-build.php --out=/tmp/orbit-r17-preview`, followed by `LD_LIBRARY_PATH=/tmp/al2023/lib node tools/dev/browser-audit.cjs /tmp/orbit-r17-preview`: 38 fixture pages × 2 widths, 0 failing combinations. This is a smoke test, not a full accessibility audit.
- **PASS** `LD_LIBRARY_PATH=/tmp/al2023/lib node tools/dev/browser-interactions.cjs /tmp/orbit-r17-preview`: all 10 listed fixture checks passed.
- **PASS** `node tools/dev/phpcl.mjs tests/order_key_test.php`: 15 checks, 0 failures after the test-description clarification.
- **PASS** `git diff --check` and `node --check public/client/orbit/store.js`.

No deployment, production database operation, live payment or supplier call was performed. Tab-scoped
state remains bypassable, history may be incomplete, and the current backend may refund after an
ambiguous supplier response. No exactly-once or production-readiness claim is made.
