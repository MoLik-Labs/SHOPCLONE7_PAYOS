# Round 12d — exactly-once crediting in every legacy payment callback

Scope: the six non-payOS handlers that can move money into a wallet (`api/callback_card.php`,
`callback_crypto.php`, `callback_flutterwave.php`, `callback-perfectmoney.php`, `callback_squadco.php`,
`api/webhook_web2m.php`), plus the card top-up form. No deployment, no schema change, no live gateway
call. payOS was audited and already had the strongest form of this protection, so it was not touched.

Branch: `arena/01a1066c-shopclone7-payos`.

---

## 1. The pattern that was wrong

All six handlers did the same three steps in the same order:

```php
$row = SELECT … WHERE … AND status = 'pending';   // 1. read
AddCredits($row["user_id"], $price, …);           // 2. credit
UPDATE … SET status = 'completed';                // 3. mark
```

Every gateway retries a callback until it gets a 2xx. Two deliveries that overlap both pass step 1,
both credit, and only then both mark the invoice. The second credit is real money. A sequential retry
(one that arrives after step 3) was rejected by the `status = 'pending'` filter, which is why the bug
is invisible in a single-threaded test.

The same code had smaller holes:

| Handler | Hole |
| --- | --- |
| `callback_card.php` | `value` (face value) is **not** covered by the card network's signature; the callback was credited with whatever `value` it sent, with no ceiling. A card declared at 20 000 could be reported at 500 000. |
| `callback_card.php` | status `99` ("processing" in the network's own status list) fell into the failure branch, permanently marking a card `error` while it was still being checked. |
| `callback_crypto.php` | `$amount` could be `0` and the handler would still close the invoice and log a 0 credit. |
| `callback_flutterwave.php` | `json_decode()` of a failed/timed-out verify is `null`, then `$response["data"]["id"]` — warnings, and `$getUser` was never defined while the notification used it. |
| `callback-perfectmoney.php` | credited `site("rate_pm")` — a setting that does not exist, so the rate was `0` and the customer received nothing. `$User` was never instantiated (`AddCredits` on `null`). The invoice was marked paid before the credit, so a retry would never fix it. |
| `callback_squadco.php` | wrote `payment_squadco` first, then credited and **ignored the return value**; a failed credit left a row that made every retry answer "already processed". |
| `webhook_web2m.php` | same order: `payment_bank` row first, credit second, return value ignored. |
| `ajaxs/client/create.php` | the daily error limit subtracted rows with `status = 'complted'` (typo), so successful cards never reduced the count. |

## 2. The fix: claim, then credit, and know which happened

### 2.1 Claiming an invoice

The status transition is the lock. The `WHERE` clause names the state the handler observed:

```php
UPDATE `cards` SET `status` = 'completed', `price` = '…'
 WHERE `id` = 42 AND `status` = 'pending';
if ($CMSNT->affectedRows() !== 1) {
    exit("thẻ này đã được cộng tiền rồi");   // someone else claimed it
}
```

`DB::affectedRows()` was added for exactly this: without it the handler cannot tell "updated" from
"matched nothing". payOS already did the stronger version of the same thing (`SELECT … FOR UPDATE`
plus `credited_at`), which is why it is unchanged.

The crypto handler keeps its `expired` transition conditional on `status <> 'completed'` as well.

### 2.2 The ledger key

`users::AddCredits()` now writes the ledger row only if that `transid` is not already there:

```sql
INSERT INTO `dongtien` (…)
SELECT 100, 50, 150, '…', '…', 7, 'TOPUP_CARD_123456'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `dongtien` WHERE `transid` = 'TOPUP_CARD_123456')
```

`transid` is deterministic for every credited event (`PAYOS_<code>`, `TOPUP_CARD_<pin>`,
`TOPUP_Flutterwave_<tx_ref>`, `TOPUP_PM_<invoice>`, `TOPUP_<account>_<bank_ref>`,
`TOPUP_Squadco_<ref>`), so "this key exists" means "this money is already on the balance". The
window is one statement, not one request. The complete guarantee is the unique index recommended by
`tools/db-index-audit.php`; without it two simultaneous inserts can still both pass `NOT EXISTS`.

### 2.3 A tri-state result

The claim makes the ledger's answer ambiguous in a new way: `false` can mean "the database failed"
or "this was already booked". A handler must do opposite things in those cases. So:

```php
AddCreditsChecked($user_id, $amount, $reason, $transid)   // "credited" | "duplicate" | "failed"
AddCredits($user_id, $amount, $reason, $transid)          // unchanged bool: true only for "credited"
```

* `credited` → notify the admin, write `deposit_log`.
* `duplicate` → the money was already booked. Close the invoice, send **no** second notification and
  no second `deposit_log` row.
* `failed` → move the invoice back to `pending` (or write nothing at all) and exit non-2xx so the
  gateway retries. A paid invoice is never left stuck.

Every handler in the table now uses `AddCreditsChecked()`; the test suite asserts that none of them
calls the ambiguous `AddCredits()` any more.

## 3. Handler-specific changes

* **Card** — signature is computed from the raw `code`/`serial` (sanitising could rewrite `&`, quotes
  or backslashes and break a valid signature); `value` is capped at the amount the customer declared
  when submitting the card, and a non-positive value is refused; status `99` now answers
  `payment.processing` without mutating the row; only final failures (`2,3,4,100`) mark the card
  `error`, and that update is conditional on `pending`.
* **Crypto** — refuses a zero-value invoice; conditional claim; requeue on failed credit.
* **Flutterwave** — verifies the decoded shape before reading it, defines `$getUser`, claims the
  invoice with `AND status = 'pending'`, requeues on failed credit.
* **Perfect Money** — reads `perfectmoney_rate` (the field the admin form writes), validates amount
  and rate, instantiates `users`, claims via `status = 0 → 1`, requeues on failure.
* **Squadco** — credits first through the keyed ledger and only then writes `payment_squadco`; a
  failed credit leaves no row, so the retry works.
* **web2m bank** — credit first, then `payment_bank`, `deposit_log`, `debit_processing` and the
  admin message; a duplicate delivery backfills the invoice row without crediting again.
* **Card form** — `complted` → `completed`.

## 4. Verification

| Gate | Command | Result |
| --- | --- | --- |
| Callback suite | `node tools/dev/phpcl.mjs tests/payment_callback_test.php` | **58 checks, 0 failed** |
| DB hardening | `node tools/dev/phpcl.mjs tests/db_hardening_test.php` | **31 passed, 0 failed** |
| Full sweep | `bash tools/dev/run.sh` | **32 suites, 1 not run (`payos_service`, no disposable DB), 0 failures** |
| Parse | `bash tools/dev/run.sh --lint-only` | **298 files, 0 parse errors** |
| Security scan | `node tools/dev/phpcl.mjs tools/security-scan.php --fail-on=high` | **237 files, 0 findings** |
| Preview build | `node tools/dev/phpcl.mjs tools/preview-build.php` | 36 pages, asset check ok, 360 classes |

The new suite runs `AddCreditsChecked()` against a stub `DB` and proves, in-process: a fresh keyed
credit reports `credited` and moves the balance once; the same key retried reports `duplicate`, moves
nothing and adds no ledger row; a broken ledger write reports `failed` and moves nothing; the old
`AddCredits()` boolean contract still holds. The rest are wiring checks on the handlers.

`tools/preview-build.php` now seeds fixed CSRF tokens, so rebuilding the preview no longer produces a
one-line diff in every generated form.

## 5. What is deliberately still open

* **No unique index yet.** The ledger claim is one statement, but only a `UNIQUE (transid)` index
  makes concurrent inserts impossible. `tools/db-index-audit.php` lists it; applying it needs a
  staging run against real data first.
* **MyISAM.** If `dongtien`/`cards` are MyISAM, the transaction wrapper is a no-op. The conditional
  UPDATE is still atomic; the ledger claim still works.
* **No live AJAX/webhook POST against MySQL** in this environment. The suite is in-process + static;
  the first staging run should replay a callback twice with `curl` and confirm one ledger row.
