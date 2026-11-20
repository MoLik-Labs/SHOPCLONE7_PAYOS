# Round 13d — guarded client endpoints, bounded demo data, and honest partial deliveries

Scope: close the last unguarded AJAX write endpoints, remove the remaining table-scan queries, make
the checkout charge for what was actually delivered, and stop the price quote from going negative.
No schema change. No new integration. Nothing deployed.

Branch: `arena/01a1066c-shopclone7-payos`.

---

## 1. CSRF on the last client write endpoints

`ajaxs/client/update.php` and `ajaxs/client/remove.php` were the only write endpoints in
`ajaxs/{client,admin}/` that did not call `orbit_require_csrf()`. Both are session-backed (they read
`$_SESSION["token"]`/`$_POST["token"]`), so a forged cross-site POST could change profile data with
the victim's cookie. Both now call the guard first.

The guard accepts, in order:

1. an `X-CSRF-Token` header or a `csrf_token` form field equal to the session token;
2. otherwise, a same-host `Origin` or `Referer` (scheme + host must match the request host).

Anything else is refused with `403` and a JSON body. Three endpoint families carry their own
credential instead and are exempt by design:

| Endpoint | Own guard |
| --- | --- |
| `ajaxs/client/payos.php` | `payos_csrf` token minted for the payOS modal |
| `ajaxs/client/gtf.php` | session token |
| `ajaxs/admin/gtf.php` | `gtf_csrf` token |
| `ajaxs/client/product.php` | buyer `api_key` (the reseller endpoint `buy_API_SHOPCLONE7` calls it server-to-server) |

`tests/csrf_guard_test.php` scans every `ajaxs/{client,admin}/*.php` and fails if a file has neither
the guard, its own token check, nor the documented `api_key` exception. **48 checks, 0 failed.**

## 2. Demo data no longer sorts the user table

`cron/cron.php` generates fake deposits and orders when `status_tao_gd_ao` is on. It used

```sql
SELECT * FROM `users` ORDER BY RAND()
```

once per generated row — MySQL sorts the entire user table and PHP fetches one row. On a 200 000-member
shop a single tick could spend minutes on a cosmetic feature.

`orbit_random_user_ids($count)` now probes the primary key: `MAX(id)`, then a random id followed by
`WHERE id >= ? ORDER BY id ASC LIMIT 1`, at most three probes per wanted id, de-duplicated. A sparse
id space (three members among a million ids) could miss every probe, so the helper then walks forward
from the last hit with `WHERE id > ? LIMIT 1`, wrapping once and stopping when it revisits an id; the
result is always `min($count, members)` distinct ids, still with no table sort. It costs the same
whether the table has 10 rows or 10 million.

- The buyers are resolved **once per cron tick** and reused by the deposit and order generators.
- The fake order loop no longer runs `ORDER BY RAND()` on `products`; the order of the inserts was
  never visible.
- Removed the `tao_gd_ao_sp_het_hang` block that ran a stock `COUNT` per product and then did nothing
  with the result. The generator has always written one fake order per active product; that is now
  the documented behaviour, without the query.
- An empty user table still writes the row with `buyer = 0` instead of crashing on a missing row.

`tests/random_user_test.php` — **20 checks**: distinct existing ids, bounded probes, sparse and
empty id spaces, no `ORDER BY RAND`, probes past the last row, clamping, and the cron wiring. The
suite was run **20 times** after the fix to confirm it is deterministic.

## 3. Last table scans on admin and storefront pages

| Surface | Was | Now |
| --- | --- | --- |
| `views/admin/products.php` | `getStock()` + `COUNT(product_die)` per row (`2N` queries) | two grouped `IN (…)` queries via `orbit_counts_by_code()` |
| `views/admin/product-api.php` | `COUNT(categories)` + `COUNT(products)` per supplier | two grouped queries for the page |
| `views/client/nav.php` | the category tree loaded twice, one query per parent per menu | one `SELECT` grouped in PHP; both menus render the same tree |
| `cron/task.php` | one `products` SELECT per configured product id | one `orbit_lookup_names(..., "code")` |
| `ajaxs/admin/view.php` (`download_product_die`) | one `products` SELECT per distinct product code | one `products` query, hash lookup |
| pagination totals (`DB::num_rows()`, 30+ list pages) | ran the page query and read every matching row just to count it | `orbit_count_sql()` rewrites simple selects to one server-side `COUNT(*)`; anything with DISTINCT/GROUP BY/HAVING/LIMIT/UNION/JOIN/subquery/expression keeps the original statement |

`orbit_counts_by_code($table, $codes)` takes the table from a fixed whitelist
(`product_stock|product_die`) and chunks codes by 500. `orbit_lookup_names()` now also permits the
`code` column of `products`.

## 4. Partial deliveries are charged honestly

A supplier can accept an order and return fewer accounts than requested. Both checkouts used to keep
the full payment, record the requested quantity as sold, and pay the seller on the full amount.

New flow, in `ajaxs/client/product.php` and `api/api.php`:

1. `$isValue` counts the accounts actually inserted (unchanged).
2. If the product is supplier-backed and `0 < $isValue < $amount`:
   - `orbit_prorated_pay($pay, $amount, $isValue)` splits the order total over the delivered share
     (coupon and member discounts stay proportional);
   - the difference is refunded under `PARTIAL_<trans_id>` via the new
     `RefundCreditsChecked()` — `credited`/`duplicate` both mean the buyer has the money back,
     `failed` writes an `[Error]` log row for the owner;
   - the order, the seller credit, the affiliate commission and `order_log` use the prorated `$pay`;
   - the response names the delivered/ordered counts and whether the refund went through.
3. Self-stocked shortfalls keep the existing behaviour: the reserved rows are returned to stock and
   the full payment is refunded under `REFUND_<trans_id>`.

Both endpoints still refund in full when nothing was delivered. Two `API_20` error branches that
previously exited with a 500/201 message **after charging the buyer** now refund first and report
whether the refund succeeded. A null/unparseable supplier response no longer raises warnings.

Also fixed in the same file pair:

- `total_payment` (the price quote) applied the tier discount but returned the undiscounted `pay` for
  guests, and could return a negative `pay` when discounts exceeded the order. It is now clamped to
  `[0, $money]` in all branches.
- The web checkout records `products.sold`, `product_order.amount`, `product_order.cost` and
  `order_log.amount` with the delivered quantity `$isValue`; the API already did.

## 5. One ledger row per transaction, one debit that cannot overdraw

`ledger_claim()` inserts only `WHERE NOT EXISTS (SELECT 1 FROM dongtien WHERE transid = …)`. Without a
`UNIQUE (transid)` index, two concurrent callbacks can both pass that check. The three balance reads
inside the credit/refund/debit transactions now take the user row lock (`SELECT money … FOR UPDATE`),
so claims for the same user serialise: the second transaction sees the first ledger row and reports
`duplicate` instead of crediting twice.

A `UNIQUE` index is still the definitive fix; the repository holds no schema DDL, so it is documented
as an owner-side migration rather than executed here.

The same lock closes the checkout race: `DebitCreditsChecked()` re-reads the balance **inside** the
transaction and refuses with `insufficient` when it does not cover the purchase. Previously the
checkout compared the balance, then called `RemoveCredits()` in a separate statement — two purchases
in the same second both passed the comparison, both debited, and the negative balance triggered the
fraud ban for an innocent buyer. `RemoveCredits()` keeps its boolean contract for the admin and
helper callers (which may intentionally allow a negative balance).

## 6. Tests

| Suite | Checks | Covers |
| --- | --- | --- |
| `csrf_guard_test.php` | 48 | every client/admin endpoint guarded or documented exception |
| `random_user_test.php` | 20 (new) | bounded user-id probing, sparse ids, and cron wiring |
| `partial_delivery_test.php` | 30 (new) | proration table, boundaries, ledger key, delivered-vs-requested records |
| `admin_user_lookup_test.php` | 39 (was 21) | count helpers, whitelist, 500-code chunking, grouped optgroups, batched product/supplier/task pages |
| `purchase_flow_guards_test.php` | 33 (was 25) | quote clamp, guest quote, delivered quantities, conditional debit, API_20 refunds |
| `db_hardening_test.php` | 39 (was 33) | tri-state credit/refund/debit, row lock, transaction pairing, duplicate audit |
| `coupon_claim_test.php` | 38 | updated for the third clamp in the web quote |
| `storefront_test.php` | 40 (was 36) | one nav tree, no per-parent menu queries |
| `count_query_test.php` | 26 (new) | COUNT rewrite: plain scans, aliases, literals containing "ORDER BY"; and every shape that must not be rewritten |

```
bash tools/dev/run.sh
  LINT 308 files, 0 parse errors
  SCAN 237 files: 0 critical, 0 high, 0 medium, 0 low
  42 suites, 1 not run (payos_service, no disposable database), 0 failures
```

Two consecutive full runs produced identical per-suite counts. An earlier exploratory run reported
one failure in `random_user_test.php`: the first version of the helper could return fewer ids than
requested when every random probe missed a sparse id space. That was a real helper bug, not test
noise; the walk-forward fill above fixes it, and the suite passed 20 consecutive runs afterwards.

## 7. Not verified / owner actions

- **No live AJAX or login test.** The sandbox has no MySQL, so the CSRF guard is verified by source
  scan and unit-level token/origin checks, not by a browser session.
- **No staging smoke test.** Nothing was deployed and no payment or licence call was made.
- **Schema indexes still missing**: `UNIQUE (transid)` on `dongtien` and `UNIQUE (coupon_id, user_id)`
  on `coupon_used`. The coupon claim path already serialises with `GET_LOCK` and the ledger path now
  locks the user row, but the database-level constraints remain the correct long-term fix.
  `tools/db-index-audit.php` now reports duplicate ledger keys **and** duplicate coupon/user pairs and
  prints the exact `ALTER TABLE … ADD UNIQUE` for each, without ever writing to the database.
- **Partial-delivery path is exercised by arithmetic and source assertions**, not by a live supplier
  response. The next staging run should place one order where the supplier confirms fewer accounts.
- `install.php` still contains no schema DDL; no migration was run against any database.
