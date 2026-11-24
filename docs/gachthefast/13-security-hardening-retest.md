# 13 — GachtheFast hardening and isolated retest

**Status: NO-GO for production.** The provider origin, request/response format, authenticity of
`check`, callback retry semantics and status-2 settlement rule remain unconfirmed. GTF card and
credit flags remain false in `.env.example`; the bank flow remains unimplemented. No production DB,
real provider, real card or real payment was touched, and no migration or deployment was performed.
The older documents 09/10/12 record their historical revision; the notes below describe this worktree.

## Changes

- **SEC-01 operator override:** removed the direct amount-entry button from the admin UI. For legacy
  `card_credit` requests, the server requires a checked `REVIEW`/`UNKNOWN` order and a posted amount
  matching its stored denomination, but the amount/note never authorise credit. A fresh provider
  recheck is required; status 2, conflicting values, missing fields or uncertainty remain review.
  The internal credit method is private and can only be reached through reconciliation. Under the
  row lock it rechecks status, order ID, user, declared amount, currency, carrier and sealed card
  data; a cancelled or altered order cannot be credited based on a stale provider response. This
  does **not** establish that the unconfirmed provider response is authentic.
- **SEC-02 callback write amplification:** invalid signatures and unknown order IDs no longer create
  event rows. For an open known order, the signed code/serial must match the sealed stored card; a
  fixed callback receipt key yields at most one callback event per order using the migration's
  composite unique index. Unsigned callback status/reference are not recorded as trusted evidence.
  This reduces DB-write amplification, not all possible request/CPU abuse; apply edge rate limits.
- **SEC-03 replay:** callback verification uses the existing per-order 15-second DB claim rather than
  forcing a provider call on every replay. A busy HTTP callback returns 503 with `Retry-After: 15`;
  eventual retry by the real provider is **not verified** and remains a release blocker. The worker
  can revisit open orders; REVIEW orders need operator attention. Direct admin force-reconciliation
  remains intentionally available to authorised operators.
- **Late submit race:** the submit handler now uses conditional updates and reads current state when
  a callback/worker changed the order first. A late pending, terminal, unknown or timed-out submit
  response cannot downgrade an already-paid order. A failed, stale provider check is likewise guarded
  from downgrading a paid order. Deterministic interleaving fixtures cover eight submit result types
  and a stale check timeout; this is not a full concurrency proof.
- **SEC-06:** reconciliation now attempts an admin `logs` insert. This insert is separate from the
  money transaction and is not guaranteed; it is not a complete or tamper-resistant audit trail.

No production schema was changed. The provider callback is no longer stored for unknown IDs or bad
signatures: this is intentional; use protected edge/server monitoring rather than public DB writes
for abuse monitoring. Admin direct amount overrides and status-2 settlement remain unavailable until
there is a verified rule and an independently reviewed design.

## Commands and observed results

Local PHP 7.4.33 and PHP 8.2.20 used synthetic MySQL 5.7.29 databases bound to `127.0.0.1:33317`,
fake gateways, and no external network. The test DBs were separately created for each suite and
version; fixture tests **drop/recreate tables** and must never run on a real shop database. The suite
models the migration's composite callback-event unique index; it does not validate a real CMSNT schema.

| Command | Result |
| --- | --- |
| `php tests/gtf_service_test.php` with disposable `GTF_TEST_DSN` | **PASS** PHP 7.4 and 8.2: 163 passed, 0 failed each; includes fake callback abuse, cooldown and late submit interleavings |
| `php tests/gtf_credit_path_test.php` with disposable `GTF_TEST_DSN` | **PASS** PHP 7.4 and 8.2: 104 passed, 0 failed each; includes typed amount, status 2, identity mutation, terminal-state and stale-timeout guards |
| `php tests/gtf_callback_endpoint_test.php` | **PASS** PHP 7.4 and 8.2: 7/0 each; isolated endpoint with fake service, including busy → 503 |
| `node tools/dev/phpcl.mjs tests/gtf_rules_test.php` | **PASS** 124 passed, 0 failed |
| `bash tools/dev/run.sh` | **PASS** LINT 326 PHP files/0 errors; SCAN 240 files/0 findings; 38 pages rendered; 62 suites, 7 NOT RUN, 0 partial, 0 failures. Native fixture suites above were run separately; other DB suites were not rerun in this round. |
| `git diff --check` | **PASS** |

No independent security/pentest sign-off has been performed on these changes. The known remaining gates
include the provider contract/response authentication (SEC-04), callback 503 retry compatibility, actual
schema and migrations, real staging end-to-end behaviour, operations/audit reliability and owner approval.
Keep payment locks and existing CMSNT licence checks intact until those gates are resolved.
