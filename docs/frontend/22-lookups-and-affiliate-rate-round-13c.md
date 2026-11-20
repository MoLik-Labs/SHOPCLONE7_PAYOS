# Round 13c — the last per-row lookups, and one commission rate instead of two

Scope: remaining per-row `SELECT`s on admin surfaces and in cron, plus the duplicated affiliate-rate
read in every crediting path. No schema change, no behaviour change except fewer queries.

Branch: `arena/01a1066c-shopclone7-payos`.

---

## 1. Admin names: one query per table

Round 13 removed per-row username lookups from 18 admin tables. Five more tables still asked the
database once per row for a *different* column:

| Page | Was | Now |
| --- | --- | --- |
| `blogs.php` | one `post_category` SELECT per post | `orbit_lookup_names("post_category", …)` |
| `coupons.php` | one `products` SELECT per product id in each coupon's JSON list | ids collected across all rows, one query |
| `product-orders.php` | one `suppliers` SELECT per order | one `suppliers` query |
| `products.php`, `product-stock.php`, `product-api-manager.php` | one `categories` SELECT per product | one `categories` query |

`orbit_lookup_names($table, $rows, $key, $column)` resolves any of
`users|categories|suppliers|post_category|products` in `IN (…)` chunks of 500. The table and column are
taken from a **fixed whitelist**, never from a request, so the identifiers interpolated into the SQL are
static strings. `orbit_lookup_name()` is the null-safe read; `orbit_user_name()` now delegates to it.

The admin dashboard timeline (`ajaxs/admin/view.php`, `view_dao_dong_gan_day` and
`view_don_hang_gan_day`) also looked up a username inside each of its two 100-row loops — up to 200
SELECTs per dashboard refresh. Each is now one query.

`ajaxs/admin/modal/coupon-view.php` (coupon usage log) and `cron/sending_email.php` (the 20-message
batch) are fixed the same way; the cron now joins `users` into the queue query instead of a SELECT per
message.

No admin view or AJAX endpoint calls `getRowRealtime()` inside a loop any more. The remaining calls are
single-record pages (`user-edit`, `affiliates`, `payment`) and two purchase-path reads of the buyer's own
balance, where the value must be fresh.

## 2. Affiliate rate: one query per referrer, not two per transaction

Every purchase and every recharge did this:

```php
$ck = $CMSNT->site("affiliate_ck");
if (getRowRealtime("users", $getUser["ref_id"], "ref_ck") != 0) {
    $ck = getRowRealtime("users", $getUser["ref_id"], "ref_ck");   // the same row again
}
```

`cron/bank.php` repeated that pair in **eight** branches (one per bank), so a cron run processing 200
transfers issued up to 400 identical SELECTs. `orbit_affiliate_rate($refId)` replaces all ten copies:

- reads `ref_ck` once with a `SELECT \`ref_ck\` … WHERE id = ?` shaped query;
- keeps the shop-wide `affiliate_ck` unless the member's own rate is non-zero (unchanged semantics);
- memoises per referrer for the request, so ten sales by the same buyer's referrer cost one query;
- returns the shop rate for `ref_id = 0` without touching the database.

## 3. Seller credit and sold counts

- The seller credit now goes through `AddCreditsChecked()`. `duplicate` (the retry already paid this
  transaction) is not an error; `failed` (buyer charged, seller not) writes an `[Error]` log row so the
  owner can see it.
- `api/api.php` credited `products.sold` and `order_log.amount` with the **requested** quantity even
  when the supplier delivered fewer units; it now uses `$isValue`, the number actually delivered.

## 4. Tests

| Suite | Checks | Covers |
| --- | --- | --- |
| `admin_user_lookup_test.php` | 21 (was 14) | generic helper, whitelist refusal, chunking, and a scan that fails on **any** `getRowRealtime()` inside a loop |
| `affiliate_rate_test.php` | 21 (new) | fallback vs override, memoisation, no query for `ref_id = 0`, fractional rates, and the wiring: `ref_ck` appears in no purchase/cron file |
| `purchase_flow_guards_test.php` | 25 (was 20) | `AddCreditsChecked()` wiring, the failure log, delivered-vs-requested counts |

```
bash tools/dev/run.sh
  LINT 304 files, 0 parse errors
  SCAN 236 files: 0 critical, 0 high, 0 medium, 0 low
  39 suites, 1 not run (payos_service, no disposable database), 0 failures
```

## 5. Not verified

No MySQL and no SMTP in this sandbox: the cron join and the batched INSERTs were checked against stubs
that record SQL, not against live tables. The rate helper is pure arithmetic plus one row read, and is
covered directly.
