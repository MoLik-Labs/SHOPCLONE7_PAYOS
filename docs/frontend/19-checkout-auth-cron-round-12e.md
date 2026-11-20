# Round 12e — coupon reservation, real passwords, and cron that is not public

Scope: four findings that were each exploitable without touching the payment gateways covered by
round 12d. No deployment, no schema change.

Branch: `arena/01a1066c-shopclone7-payos`.

---

## 1. A coupon could be used twice at the same time

The checkout did this:

```php
$discount = checkCoupon(...);      // read: code exists, used < amount, user has no row
$pay = $money - $discount;
RemoveCredits($pay);               // charge the discounted price
... insert order ...
cong("coupons", "used", 1);        // record the use afterwards
insert("coupon_used", ...);
```

`checkCoupon()` only reads. Two checkouts that overlap both pass, both get the discount, and the
`used` counter can end above `amount`. There is no unique key on `(coupon_id, user_id)` to stop it.

`orbit_coupon_claim()` now reserves the use **before** the charge:

1. validate code, window (`min`/`max`) and product scope;
2. take a MySQL advisory lock, `GET_LOCK('orbit_coupon_<id>', 3)` — fail-closed if it cannot be
   taken, because without the lock the read-then-write is not serialised;
3. re-read the coupon (`used < amount`) and the per-user row under the lock;
4. insert `coupon_used` with the order `trans_id`, then `used + 1` (still under the lock).

The reservation returns the discount in money, or `false`; the checkout stops with
"Mã giảm giá không hợp lệ hoặc đã được sử dụng" instead of silently charging full price. Every
refund/error path already `exit`s, so `register_shutdown_function` releases an uncommitted claim:
it deletes the `coupon_used` row and decrements `used`. `orbit_coupon_commit()` is called the moment
the order row is written; after that the slot stays used. Both purchase paths (`ajaxs/client/product.php`
and `api/api.php`) use it, and the old post-order counter update is gone.

**Money bug found while doing this:** user discount + coupon discount could exceed 100%, making
`$pay` negative. `RemoveCredits(-x)` calls `tru(-x)`, which *adds* money. Both paths now clamp the
total discount to the order total.

## 2. Passwords were being HTML-escaped before hashing

Every password entry point ran the value through `check_string()`:

```php
$password = check_string($_POST["password"]);   // addslashes + htmlspecialchars + trim
password_verify($password, $hash);
```

A password containing `'`, `"`, `&`, `<` or a leading/trailing space was therefore hashed in a
different form than the user typed. Registration did the same thing, so the account could only be
entered by retyping exactly the escaped string — which the user cannot see or guess.

* New registrations, password changes, resets and the admin reset form hash the **raw** bytes.
* `orbit_password_matches()` accepts the raw value, and if that fails the legacy sanitised form, for
  bcrypt/md5/sha1/plaintext modes.
* `orbit_password_upgrade()` re-hashes a bcrypt password with its raw bytes on the next successful
  login, so the legacy path disappears naturally.

## 3. Admin-created users had their username as the password

`views/admin/users.php` posted a password field and ignored it:

```php
"password" => TypePassword(check_string($_POST["username"]))
```

Every account created in the admin panel could be opened by typing its own name. It now validates
and hashes the posted password. The same insert also minted the session token with
`md5(random(6) . time())` and the API key with `md5(time() . random(6))`; both now come from
`random_bytes()`.

Two smaller fixes in the same area: an empty `limit_block_ip_login` setting no longer means "ban the
IP after the first failure" (`0 < (int) $max_attempts`), and the admin password reset stores the raw
value instead of a sanitised copy.

## 4. Cron endpoints were public

`cron/*.php` polls bank APIs, syncs supplier balances and stock, runs warehouse automations and drains
the mail queue. All of them were reachable by anyone; the admin pages even print the URLs. A stranger
could spend the shop's supplier quota, trip provider rate limits, or run automations at will.

`orbit_cron_allowed()`: CLI is always allowed; over HTTP the request needs `CRON_TOKEN` (at least 16
characters) as an `X-Cron-Token` header or `?token=`, compared with `hash_equals()`. With no token
configured, HTTP is refused. All 25 legacy endpoints call it before doing any work; the two
GachtheFast workers keep their own `gtf_worker_allowed()` guard. `.env.example` documents the
variable and the curl form.

## 5. N+1 queries removed

| Page | Before | After |
| --- | --- | --- |
| `views/admin/product-api.php` | 3 aggregates per supplier row | one `GROUP BY supplier_id` |
| `views/admin/email-campaigns.php` | 2 COUNTs per campaign row | one `GROUP BY camp_id` |
| `views/admin/email-sending-view.php` | 2 user lookups per row | one `IN (…)` query |
| `views/admin/product-stock.php` | SELECT + INSERT/UPDATE per imported line | uids pre-loaded in `IN (…)` chunks, only writes remain |

Supplier JSON that fails to decode (`null`) is now reported to the buyer instead of `$data["msg"]`
being indexed on `null`; the five `!isset($data)` guards became `!is_array($data)`.

## 6. Verification

| Gate | Command | Result |
| --- | --- | --- |
| Coupon suite | `node tools/dev/phpcl.mjs tests/coupon_claim_test.php` | **38 checks, 0 failed** |
| Password suite | `node tools/dev/phpcl.mjs tests/auth_password_test.php` | **25 checks, 0 failed** |
| Cron suite | `node tools/dev/phpcl.mjs tests/cron_guard_test.php` | **14 checks, 0 failed** |
| Callback suite (12d) | `node tools/dev/phpcl.mjs tests/payment_callback_test.php` | **58 checks, 0 failed** |
| Full sweep | `bash tools/dev/run.sh` | **35 suites, 1 not run (`payos_service`, no disposable DB), 0 failures** |
| Parse | lint step of the sweep | **301 files, 0 parse errors** |
| Security scan | `--fail-on=high` | **237 files, 0 findings** |
| Preview | `node tools/dev/phpcl.mjs tools/preview-build.php` | 36 pages, asset check ok, rebuild is byte-identical |

The coupon suite runs the real `orbit_coupon_claim()` against a stub DB (expiry window, product
scope, per-user reuse, lock denied, commit vs release). The password suite hashes real values with
`password_hash()` — no fixture hash is hard-coded — and checks both the raw and legacy forms.

## 7. Still open

* `coupon_used` has no `UNIQUE (coupon_id, user_id)` and `dongtien` has no `UNIQUE (transid)`. Both
  are now safe against normal retries through statements and the advisory lock, but the unique keys
  are the only complete guarantee under MySQL without the lock. They need a staging-data audit before
  being applied (`tools/db-index-audit.php`).
* No live AJAX POST against MySQL in this environment; the suites are in-process + static.
* Cron endpoints now require `CRON_TOKEN`; an installation that was calling them anonymously must add
  the header. This is intentional and documented, not a regression.
