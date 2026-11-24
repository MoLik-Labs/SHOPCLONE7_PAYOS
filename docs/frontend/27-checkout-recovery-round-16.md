# Checkout uncertainty — round 16

## Change

The product buy panel now writes a buyer/product-scoped marker to sessionStorage and verifies it
before sending a purchase. It contains a version, buyer and product IDs and a random client order
key, not credentials, coupon, quantity, payment details or delivery data. A valid success with a
well-formed server order ID clears the marker. A rejected, malformed or lost response leaves it in
place: the panel disables resubmission and directs the buyer to order history and support. Server
error text is displayed where present. Reloading the same tab retains the warning. If storage is
unavailable, corrupt or not writable/readable, checkout is blocked before the purchase request.

This deliberately supersedes round 15's same-key manual retry. A completed-order lookup and a ledger
claim are not a durable, atomic purchase claim across coupon reservation, wallet debit, stock and
external suppliers. Even identical resubmission is not assumed safe. The backend comment was
corrected accordingly; backend checkout behavior and schema were not changed in this round.

## Known tradeoffs and limits

- Current errors do not distinguish safe pre-debit validation errors from uncertain results. Thus even
  an insufficient-balance response blocks this panel for the rest of the tab session. This is a
  conservative availability tradeoff, not an ideal recovery experience. There is no customer-facing
  or operator reconciliation/reset workflow yet.
- The marker is tab-scoped, can be copied when duplicating a tab, and can be cleared or lost on tab
  close. Other tabs/devices, direct API clients and storage deletion bypass it. Order history alone
  may be incomplete after partial processing. Support must check wallet, delivery and supplier
  records; the marker is not proof of a completed or failed order.
- A durable claim, authoritative status/reconciliation, external-provider ambiguity handling, safe
  transaction boundaries and concurrent/crash tests are still required. See
  `docs/frontend/26-checkout-recovery-plan.md`. No production migration, payment or provider call
  was made, and this change does not establish exactly-once fulfillment or production readiness.

## Verification

- **PASS** `bash tools/dev/run.sh`: `LINT 321 files, 0 parse errors`; `SCAN 239 files: 0 critical, 0 high, 0 medium, 0 low`; 38 pages rendered; `57 suites, 3 not run, 0 partial, 0 failures`. The mock checkout quote, retry and recovery suites passed.
- **NOT RUN** `gtf_credit_path`, `gtf_service`, `payos_service`: disposable database unavailable. No native MySQL/InnoDB concurrency, crash, real checkout or provider tests were performed.
- **PASS** `LD_LIBRARY_PATH=/tmp/al2023/lib node tools/dev/browser-checkout.cjs`: 8 checks against a synthetic local checkout with mocked gateway; quote failure/retry, lost response, reload warning and credential exclusion included.
- **PASS** `node tools/dev/phpcl.mjs tools/preview-build.php --out=/tmp/orbit-r16-preview` then `LD_LIBRARY_PATH=/tmp/al2023/lib node tools/dev/browser-audit.cjs /tmp/orbit-r16-preview`: 38 fixture pages, 76 viewport combinations, 0 failing combinations. This is a smoke test, not a complete accessibility audit.
- **PASS** `LD_LIBRARY_PATH=/tmp/al2023/lib node tools/dev/browser-interactions.cjs /tmp/orbit-r16-preview`: all 10 listed fixture interaction checks passed.
- **PASS** `git diff --check` and `node --check public/client/orbit/store.js`.
