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

- **A failed order insert refunded nothing.** If `product_order` could not be written after the debit,
  both checkouts exited with "System error" and kept the money. The failure path now refunds under
  `REFUND_<trans_id>` (tri-state, so a retry is a no-op) and the response says whether the refund
  actually went through.
- **The admin refund is idempotent.** `ajaxs/admin/update.php` treated a second refund of the same
  order as an error and stopped, so an order whose refund had already moved (double click, or a retry
  after a lost response) could never be closed. `duplicate` now closes the order too, only `failed`
  stops, and a failed seller clawback writes an `[Error]` row instead of disappearing. The refund no
  longer zeroes `pay`/`cost`/`money`: every report filters `refund = 0`, so zeroing destroyed the
  record of what the customer paid.

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

## 6. Affiliate withdrawal: validated, atomic, reversible

`ajaxs/client/create.php` (`WithdrawCommission`) compared the raw form value with `affiliate_min` and
with `ref_price`, then debited in a second statement:

- `"abc" < 50000` is **false** in PHP 8 (non-numeric string vs number compares as strings), so a
  non-number passed both checks and reached the numeric update;
- two requests started together both passed the balance check and both debited, leaving a negative
  `ref_price` — which the next line treated as fraud and **banned the member**;
- if the `aff_withdraw` insert failed after the debit, the commission was simply gone.

Now:

The admin coupon forms had the same string comparisons plus an uncapped discount: `discount = 150`
produced a free order (clamped to the total) and `amount = "abc"` was stored. Both the create and the
edit form now require a positive integer quantity, a discount in `0.01–100`, and `max ≥ min`, all
parsed before the comparison.

- `orbit_positive_amount()` parses the value (finite, > 0) before any comparison; the min and balance
  checks are float comparisons; `orbit_amount_string()` renders it without an exponent for SQL and
  provider requests. The same parser now guards the legacy card (`nap_the`, digits only, ≤ 100 M) and
  crypto recharge amounts, which had the identical string-comparison hole.
- `RemoveCommissionChecked()` runs the balance read (`SELECT ref_price … FOR UPDATE`), the `aff_log`
  row and the debit in one transaction, and returns `removed` / `insufficient` / `failed`.
  `RemoveCommission()` keeps its boolean contract for other callers.
- `insufficient` is reported as "your commission balance is not enough"; the fraud ban only runs when
  the checked debit actually succeeded and the balance is still negative.
- If the withdrawal row cannot be written, `RestoreCommission()` puts the commission back (log row +
  credit in one transaction). If that restore also fails, an `[Error]` log row names the amount and
  trans id, and the member is told to contact support with the reference.

The **admin** side was not covered by that round. `ajaxs/admin/modal/withdraw-edit.php` refunded the
member and only then wrote `status = cancel`, and it decided from an unlocked read. Two admins (or a
double-click) both saw `pending`, both called `RefundCommission()` and paid the member twice; a failed
row update after a successful refund left a pending withdrawal that could be cancelled again. The
cancel path now claims the row with `UPDATE … WHERE id = ? AND status <> 'cancel'`, refunds only when
that claim won, and puts the previous status back if the refund itself fails.

`ajaxs/admin/modal/coupon-view.php` still carried a copy of that withdrawal handler. It ran under
`view_coupon`, built `RefundCommission()` arguments from a **coupon** row (no `user_id`; `amount` is the
usage limit), and then updated `aff_withdraw` using the coupon id — so a crafted POST could complete or
cancel an unrelated withdrawal, without the `edit_withdraw_affiliate` permission and without paying the
member. The handler is gone: the modal is read-only, like the button it renders.

## 7. Commission and debit bookkeeping

Three more unlocked read-then-write sequences were left in the money layer:

- **Commission credit** (`AddCommission`) wrote its `aff_log` row from two separate unlocked
  `getUser()` reads, then credited three counters without checking the result. Concurrent payments
  could record a wrong before/after pair, and a failed credit left a log row for money that never
  arrived. The read (`SELECT ref_price … FOR UPDATE`), the log row and all three counters now share
  one transaction, and the caller still gets a boolean.
- **Commission clawback** (`ClawbackCommission`) checked for its `aff_log` row before taking any
  lock, so two refund workers could both reverse the same commission. The referrer row is locked
  first and the check runs inside that transaction.
- **Commission refund** (`RefundCommission`) duplicated the restore path with the same unlocked
  pattern; it now delegates to the locked `RestoreCommission()`.
- **Debit settlement** (`debit_processing`) read `money`/`debit`, then ran two unconditional
  updates. Two deposits could both pay the same overdraft, and a failure between the updates left
  the debt reduced but the wallet untouched. `users::SettleDebit()` reads both under one row lock,
  pays `min(money, debit)`, and commits the ledger row with both updates or none of them.

## 8. Local stock is claimed, not copied

The local-stock branch of both checkouts (`ajaxs/client/product.php`, `api/api.php`) selected up to
`amount` stock rows, inserted a `product_sold` row and then deleted the stock row. Nothing tied the
two statements to the row that was actually claimed:

- two buyers checking out together both selected the same row (the `SELECT … LIMIT` ran before
  either delete) and both received the same account;
- the losing `DELETE` removed nothing, so the stock count was also wrong;
- a failed `product_sold` insert left the stock row in place but could be retried into a duplicate.

Supplier loops also stop once the ordered quantity has been delivered. A provider that returns more
accounts than requested used to have every one inserted into `product_sold`, inflating the delivery
above what the buyer paid for (and above the `sold` counter the shop reports). Extra rows are now
ignored; the paid-for quantity is the ceiling.

A failed `product_order` insert after a delivery used to refund the buyer but leave the delivered
accounts reserved in `product_sold`, invisible to everyone. The refund path now calls
`orbit_release_sold()`: local accounts go back to stock, supplier accounts cannot be un-bought so an
`[Error]` log line names the order. The last-refund (local-stock shortfall) path also reports through
`RefundCreditsChecked()` instead of announcing a refund that may have failed.

`orbit_deliver_stock()` now deletes the stock row inside a transaction and only inserts the sold
record when that `DELETE` affected exactly one row; a failed insert rolls the delete back. The mirror
operation, `orbit_return_stock()`, claims the sold row the same way before re-inserting it into
stock, so a repeated refund cleanup cannot duplicate an account. `stock_claim_test.php` exercises
both helpers against an in-memory database and checks that both checkout paths use them.

## 9. Administrative adjustments and other read-then-write fixes

- **Admin credit/debit (`views/admin/user-edit.php`)** announced "Cộng số dư thành công" even when the
  balance update had failed, and the debit path read the balance, compared it, and debited in a
  separate statement. Credit now reports the real `AddCreditsChecked()` outcome; debit uses
  `DebitCreditsChecked()` (balance re-checked under the row lock) and reports
  `insufficient`/`failed` distinctly. The debt wallet cannot be reduced below zero.
- **Role checks (`checkPermission`)** passed a decoded role list straight to `in_array()`; an empty or
  corrupt `admin_role.role` decodes to `null`, which PHP 8 rejects. The list is validated as an array
  first.
- **Sitemap (`cron/cron.php`)** wrote to `../sitemap.xml` relative to the *caller's* working directory;
  run from the webroot it wrote outside the site. The path is anchored to `__DIR__` and a failed write
  is logged.
- **Supplier payloads** are iterated as arrays in both checkouts, so a provider that answers with a
  string or `null` cannot raise a warning per row (the partial-delivery refund still runs).
- **Admin lists** (`roles`, `coupons`) no longer iterate `null` when a JSON column is empty.

## 10. Baseline response headers

Every rendered page now sends `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`,
`Referrer-Policy: strict-origin-when-cross-origin` and a restrictive `Permissions-Policy`, from
`orbit_security_headers()` at the top of both layouts, before any output. No CSP is sent: the
templates still carry inline handlers, and a policy that is not actually enforced would be worse
than none. Uploads under `assets/storage/` remain non-executable at the web-server level.

## 11. Admin uploads

Every upload form had the same three defects:

1. the stored filename was always `.png`, whatever the bytes were — a JPEG served as `image/png` is
   exactly what `nosniff` refuses to render;
2. the previous file was `unlink()`ed *before* the new upload was moved, so a failed upload left the
   record pointing at a deleted path (and `unlink("")` warned for unset settings);
3. `move_uploaded_file()` used a path relative to the caller's working directory.

`orbit_store_image()` now validates the bytes (`getimagesize()` type must match the extension),
derives the extension from the type, refuses a prefix or subdirectory with anything but
`[A-Za-z0-9_-]`, writes to an absolute path under `assets/storage/`, chmods the result `0644`, and
returns the site-relative path. `orbit_delete_upload()` only removes paths that resolve inside
`assets/storage/`, so a crafted database value cannot unlink anything else. Every form stores first,
updates the row, then deletes the old file. Product galleries store the real basename and refuse to
record an image whose move failed. The stock importer now checks `is_uploaded_file()` and caps the
upload at 10 MB before reading it into memory.

## 12. Sign-in, recovery and recharge filters

- **Account lockout.** `limit_block_client_login` was compared with `<=`, so its default of `0`
  banned an account on the first wrong password. Nothing reset `login_attempts` on success, so a
  member who mistyped twice stayed one mistake from a ban forever. `0` now disables the counter, and
  all three sign-in paths (password, 2FA, e-mail OTP) reset it.
- **Forgot password.** "This email does not exist" made the form an account-enumeration oracle, and
  the 60-second rate limit answered differently from a normal request. Both now return the same
  neutral "if the address exists, a message will arrive" response; a registered address gets the
  mail, an unregistered one gets nothing. The duplicated demo-site guard was removed.
- **Logout.** The views destroyed the session but deleted the auth cookie with the legacy
  three-argument call (no SameSite/Secure) and left `$_SESSION["login"]` populated. `orbit_logout()`
  clears the array, expires both cookies with the attributes they were created with, and destroys the
  session.
- **Cookie security flag.** `set_auth_cookie()` only inspected `$_SERVER["HTTPS"]`; behind the TLS
  proxy the site runs on, that is empty, so the cookie was issued without `Secure`. `orbit_is_https()`
  also honours `X-Forwarded-Proto`, port 443 and `APP_URL`.
- **SQL injection in the recharge lists (16 sites).** Filters did
  `$where .= " AND `amount` = " . check_string($_GET["amount"])` — `addslashes()` escapes quotes, so
  `1 OR 1=1` reached the unquoted numeric comparison untouched. All twelve list views (admin and
  client, amount and price) parse the value with `orbit_positive_amount()` and render it with
  `orbit_amount_string()`.

## 13. PayPal confirmation

`confirmPaypal` trusted the order id posted by the browser. Any completed charge under the same
merchant account — created by another integration, or by an older page — could be claimed by whoever
submitted its id, and the only guard was the local `payment_paypal` table. The page now issues a
per-render nonce (last five kept for open tabs) and puts `custom_id = "<user id>:<nonce>"` into the
order it creates; the handler re-fetches the order from PayPal and accepts it only when the
`custom_id` matches one of this session's nonces. Amount and rate must be finite and positive, the
amount capped at 100 000, and a missing `purchase_units[0]` is refused instead of raising.

If the invoice row is written but the credit fails, the row is removed again so a retry can succeed;
the old code left it in place and answered "already credited" — stranding the money.

## 14. Legacy bank webhook

`api/webhook_web2m.php` decoded the body and walked `$result["data"]` without checking its shape, read
`amount` as a quoted string (so `abc` compared as 0 and `1.500.000` was credited as 1.5), and had no
bound on the batch. It now refuses a body over 1 MB, requires `{data: [...]}`, caps the batch at 200,
guards every key, parses the amount with `orbit_gateway_amount()` and compares it numerically. The
credit-first/tri-state path is unchanged.

SquadCo read `$body["Body"]` and its `amount`/`email` keys without checking the decoded shape; Perfect
Money concatenated six `$_POST` fields into the signature string without requiring them to exist. Both
now validate the shape first, and SquadCo parses its minor-unit amount with `orbit_gateway_amount()`
instead of string arithmetic.

## 15. Tests

| Suite | Checks | Covers |
| --- | --- | --- |
| `csrf_guard_test.php` | 48 | every client/admin endpoint guarded or documented exception |
| `random_user_test.php` | 20 (new) | bounded user-id probing, sparse ids, and cron wiring |
| `partial_delivery_test.php` | 30 (new) | proration table, boundaries, ledger key, delivered-vs-requested records |
| `admin_user_lookup_test.php` | 39 (was 21) | count helpers, whitelist, 500-code chunking, grouped optgroups, batched product/supplier/task pages |
| `purchase_flow_guards_test.php` | 43 (was 25) | quote clamp, guest quote, delivered quantities, conditional debit, API_20 refunds, failed-insert refund, idempotent admin refund |
| `db_hardening_test.php` | 48 (was 33) | tri-state credit/refund/debit, row locks, transaction pairing, duplicate audit |
| `coupon_claim_test.php` | 44 | updated for the third clamp in the web quote; coupon admin validation |
| `storefront_test.php` | 40 (was 36) | one nav tree, no per-parent menu queries |
| `auth_guard_test.php` | 18 (new) | lockout semantics, reset on success, enumeration, logout |
| `upload_guard_test.php` | 27 (new) | upload store/delete guards, type-following extension, form wiring |
| `security_headers_test.php` | 11 (new) | header names, ordering before output, layout wiring |
| `stock_claim_test.php` | 20 (new) | exactly-once stock claim and return, rollback on failed insert, release after a failed order insert |
| `amount_guard_test.php` | 61 (new) | amount parsing (strings, exponents, NAN/INF, injections), withdrawal wiring, commission lock, restore path, legacy card/crypto amounts |
| `count_query_test.php` | 26 (new) | COUNT rewrite: plain scans, aliases, literals containing "ORDER BY"; and every shape that must not be rewritten |

```
bash tools/dev/run.sh
  LINT 312 files, 0 parse errors
  SCAN 237 files: 0 critical, 0 high, 0 medium, 0 low
  47 suites, 1 not run (payos_service, no disposable database), 0 failures
```

Two consecutive full runs produced identical per-suite counts. An earlier exploratory run reported
one failure in `random_user_test.php`: the first version of the helper could return fewer ids than
requested when every random probe missed a sparse id space. That was a real helper bug, not test
noise; the walk-forward fill above fixes it, and the suite passed 20 consecutive runs afterwards.

## 16. Not verified / owner actions

- **No live AJAX or login test.** The sandbox has no MySQL, so the CSRF guard is verified by source
  scan and unit-level token/origin checks, not by a browser session.
- **No staging smoke test.** Nothing was deployed and no payment or licence call was made.
- **Schema indexes still missing**: `UNIQUE (transid)` on `dongtien` and `UNIQUE (coupon_id, user_id)`
  on `coupon_used`. The coupon claim path already serialises with `GET_LOCK` and the ledger path now
  locks the user row, but the database-level constraints remain the correct long-term fix.
  `tools/db-index-audit.php` now reports duplicate ledger keys, duplicate coupon/user pairs and
  duplicate `payment_paypal.trans_id` / `payment_bank.tid` rows, and prints the exact
  `ALTER TABLE … ADD UNIQUE` for each, without ever writing to the database. It also lists the
  missing indexes on those two payment tables.
- **Partial-delivery path is exercised by arithmetic and source assertions**, not by a live supplier
  response. The next staging run should place one order where the supplier confirms fewer accounts.
- `install.php` still contains no schema DDL; no migration was run against any database.
