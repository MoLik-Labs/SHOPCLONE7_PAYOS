# Checkout recovery — phased implementation plan

**Goal:** Reduce accidental duplicate orders after an unknown response, then design durable server-side
recovery without modifying a real database or making live provider calls.

**Constraints:** PHP 7.4 compatibility; preserve payOS/GTF/CMSNT and legacy API contracts. Do not
execute migrations against production. Never store tokens, API keys, account contents or customer data
in browser recovery state. No automatic supplier retry when a prior external result is uncertain.

## Phase 1 — conservative same-tab warning (implemented; not durable recovery)

The initial plan proposed retaining and resending the original key, amount and coupon. During
implementation the server audit showed a key lookup is not an authoritative claim: notably coupon
reservation occurs before the separate wallet debit, and an interrupted supplier call cannot be
safely inferred from a missing order. Resending even identical data is therefore not presented as
safe. This implementation instead:

1. Writes and reads back a versioned marker with buyer ID, product ID and random request key in
   `sessionStorage` **before** sending; it stores no coupon, quantity, token, API key or payment data.
2. On any unstructured error, malformed success, lost response or reload with a marker, disables the
   checkout in that tab and asks the buyer to check order history and contact support. Server error
   text is still shown when available. If the saved marker is valid, the panel shows its request key
   as a reference (not an order ID) and provides history and contact links. See the read-only support
   guidance in `28-checkout-support-runbook.md`. No purchase request is automatically or manually
   resent by this panel. A confirmed success response with a valid order ID removes the marker.
3. If storage is unavailable, corrupted or not writable/readable, checkout fails closed in that tab.
   Since round 31, a small set of pre-processing rejections explicitly reports `not_started` with the
   matching request key, allowing a **new manual attempt** after clearing and verifying the marker.
   Insufficient balance is included only when no coupon was reserved. Unstructured errors, coupon
   paths and debit/provider outcomes still block: they do not prove all side effects were reversed.
   This response describes the current request, not a durable claim across tabs, devices or direct
   clients. See `31-checkout-preflight-unlock.md`.

**Limitations:** sessionStorage is per tab, can be copied when a tab is duplicated, and can be cleared
or lost when the tab closes; other tabs, devices and direct API requests are not protected. Do not
assume absence of the warning means an earlier order did not happen. Buyers should not circumvent it
by opening another tab; history may itself be incomplete after a partial failure, so support must
inspect wallet, delivery and provider records. There is no server status endpoint, price lock,
operator resolution workflow or durable crash recovery in this phase.

## Phase 2 — durable backend claim and reconciliation (NOT IMPLEMENTED)

- Audit both web/API paths, MySQL engine and actual production schema/indexes on a disposable replica.
  The current wallet operation, coupon reservation, stock delivery, supplier calls and order insertion
  use different transactions/connections; adding a claim row alone cannot make the whole flow atomic.
- Design a new InnoDB claim table keyed by buyer + client key, with immutable request fingerprint,
  transaction ID, explicit states and a unique constraint. Migration must be additive, preflighted,
  idempotent, backed up, and separately approved for any production use. No data deletion.
- Define failure policy at each boundary: local stock can only be finalised with a tested atomic
  design; external supplier timeout is `unknown` and requires provider-specific reconciliation,
  never automatic resubmission or an unverified refund. A worker dying after debit needs a durable,
  auditable recovery path and operator controls.
- Add integration tests using a disposable MySQL/InnoDB instance and native PHP/MySQL, including
  duplicate concurrent submissions, multiple users and tabs, zero-price orders, partial delivery,
  supplier timeout, process termination at each boundary, and rollback/retry. Use mocks for all
  providers. Do not enable the new flow until these tests and a staging review pass.

A unique key alone is not evidence of exactly-once delivery: external services and crash windows are
part of the contract. A disposable native PHP/MySQL test stack was subsequently assembled for fixture
tests (see `30-native-database-review.md`), but it does not include the actual shop schema or establish
that the checkout recovery design is safe. Phase 2 is still not implemented or enabled.
