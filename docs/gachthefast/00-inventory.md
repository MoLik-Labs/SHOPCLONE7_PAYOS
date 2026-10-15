# Agent A — Provider / Path Inventory (read-only)

Repository: `NggKhaiz/SHOPCLONE7_PAYOS`
Branch: `arena/01a1066c-shopclone7-payos`
Base commit: `e80be783e8bbf5ba3db9e0b4c99b7617f8ed3283` ("FIRST", single commit in history)
Date of review: 2026-10-04
Scope: static review of tracked source only. No edits were made to application code.

Environment of this review: no PHP runtime, no Composer vendor tree, no database, no outbound
network to Packagist/apt in the sandbox. Everything below is static evidence (`file:line`), not a
runtime observation. This is a limitation, not a pass.

---

## 1. Executive summary

| Question | Finding |
|---|---|
| Is there any literal `cardvn` reference? | **No.** Absent in the working tree and in all of git history (text search over every blob). |
| Is there a Card24h reference? | **Yes, as display labels only.** The admin card screen labels the *Partner ID* / *Partner Key* fields with a link to `https://card24h.com/` (`views/admin/recharge-card.php:124-127`); the separate help link points at `help.cmsnt.co`. Neither is the runtime endpoint. |
| What does the card path actually call? | `libs/helper.php:871 card24h()` calls a **base64-obfuscated** URL that decodes to `https://thesieure.com/chargingws/v2?sign=...` (`libs/helper.php:876`). |
| Is there a distinct TheSieuRe **bank polling** implementation? | **No, not by that name.** Legacy bank polling uses `banks` table rows + `https://api.sieuthicode.net/…` (`cron/bank.php`) and `https://api.web2m.com/…` (`cron/bankWeb2m.php`, `api/webhook_web2m.php`). `THESIEURE` exists only as a **label option** in `config.php:4` and as a free-text value stored in `banks.short_name`. |
| Is GachtheFast present anywhere? | **No.** Zero references in source or history. No adapter, endpoint, credential slot, or documentation in the checkout. |
| Does the checkout contain the schema? | **No.** `*.sql` is gitignored (`.gitignore:9`) and no SQL file is present. `CHANGE_MANIFEST.txt` refers to `shopclone7.sql`, which is **not** in this checkout. |

**Conclusion:** the owner-described "CardVN card integration" has no identifiable implementation in
this checkout, and the GachtheFast provider has no implementation, configuration slot, or
documentation here. Both are Gate 0 blockers (see `02-blockers.md`).

---

## 2. Provider / path matrix (as it exists today)

### 2.1 Card (scratch-card / "thẻ cào") — legacy path

| Item | Evidence |
|---|---|
| Client view | `views/client/recharge-card.php:11` (form, telco list `:82-84`, denominations `:86-88`) |
| Client AJAX action | `ajaxs/client/create.php:91` `action=nap_the` |
| Server-side validation | `ajaxs/client/create.php:92-131` (`card_status`, token → `users.token`, `banned=0`, `max_time_load` throttle, telco/amount/serial/pin presence, `checkFormatCard()`, pending-card spam cap, daily error cap) |
| Provider call helper | `libs/helper.php:871-880` `card24h()` |
| **Actual provider endpoint** | base64 string at `libs/helper.php:876` decodes to `https://thesieure.com/chargingws/v2?sign=` … `&command=charging` |
| Signing | `md5($partner_key . $pin . $serial)`, `partner_id` from `settings.card_partner_id` |
| Created local record | `cards` row, `status='pending'`, only when provider returns `status == 99` (`ajaxs/client/create.php:134-135`) |
| **Order of operations (risk)** | the outbound provider call at `:133` happens **before** any local row exists; a fast callback therefore cannot match a row until `:135` commits |
| Callback | `api/callback_card.php:1-68` |
| Callback signature | `md5(card_partner_key . code . serial)` compared with `hash_equals()` (`api/callback_card.php:33`) — note: `code`+`serial` only, **not** `request_id`/`trans_id`/amount |
| Callback replay guard | `SELECT … FROM cards WHERE trans_id = <request_id> AND status='pending'` (`api/callback_card.php:36`) then `UPDATE … status='completed'` (`:48`) — read-then-write, **no row lock, no unique key** |
| Credit | `users::AddCredits()` (`api/callback_card.php:49`) |
| History | `deposit_log` insert (`api/callback_card.php:59`) |
| Admin config | `views/admin/recharge-card.php` — `card_status`, `card_partner_id`, `card_partner_key`, `card_ck`, `card_notice` |
| Transport hardening | cURL with **no TLS verification options set**, **no timeout**, no response-size bound (`libs/helper.php:874-878`) |

### 2.2 Bank / incoming transfer — legacy paths

| Item | Evidence |
|---|---|
| Client route | `views/client/recharge-bank.php:1-5` → **unconditionally** `require`s `recharge-payos.php` |
| Polling cron #1 (SieuthiCode) | `cron/bank.php` — ACB `:16`, VietinBank `:59`, TCB `:102`, VCB `:145`, VPB `:188`, MBBank `:231`, TPB `:274`, BIDV `:317`; all `https://api.sieuthicode.net/...` + `banks.token` |
| Polling cron #2 (Web2M) | `cron/bankWeb2m.php:19` `https://api.web2m.com/historyapiacb/<password>/<accountNumber>/<token>` |
| Push webhook (Web2M) | `api/webhook_web2m.php:20-24` — `Bearer` token compared with `hash_equals()` against `settings.token_webhook_web2m` |
| Order-id matching | `libs/helper.php:432-443 parse_order_id($description, $prefix_autobank)` — regex `/<prefix>\d+/` |
| Amount window | `settings.bank_min` / `settings.bank_max` |
| Promotion | `libs/helper.php:57-65 checkPromotion()` — **first matching row wins**, query is `ORDER BY min DESC` but iterates and returns on the first iteration |
| Dedupe | pre-check `SELECT … FROM payment_bank WHERE tid=? AND description=?` then `INSERT` (`cron/bank.php:25`, `api/webhook_web2m.php:35`) — no unique constraint, race-prone |
| Credit | `users::AddCredits()` (`cron/bank.php:30`, `api/webhook_web2m.php:40`) |
| Commission | `AddCommission()` to `users.ref_id` (`cron/bank.php:34-40`) |
| Debt offset | `debit_processing()` |
| History / audit | `deposit_log` insert; `log_bank_auto` insert for non-credited observations |
| Admin UI | `views/admin/recharge-bank.php`, `recharge-bank-config.php`, `recharge-bank-edit.php`, `views/admin/log-auto-bank.php` |
| `THESIEURE` label | `config.php:4` → consumed only as `<option>` values at `views/admin/recharge-bank-config.php:141` and `views/admin/recharge-bank-edit.php:80` |

### 2.3 payOS (current primary route)

| Item | Evidence |
|---|---|
| Client | `views/client/recharge-payos.php`, route alias `views/client/recharge-bank.php:5` |
| Create/status AJAX | `ajaxs/client/payos.php` (session or cookie token, `banned=0` check `:12-17`, CSRF `hash_equals` `:21-24`) |
| Service | `libs/payos.php` — `create()` `:102`, `webhook()` `:177`, `refresh()` `:190`, `synchronize()` `:205`, `credit()` `:248` (single `beginTransaction` `:250`, row locks, ledger `:245`, `payment_bank` + `deposit_log` insert, commission, debt offset, `credited_at` set at `:290`) |
| Client (transport) | `libs/payos_client.php` — fixed host `https://api-merchant.payos.vn` `:9`, response cap `:10`, HMAC-SHA256 signing `:79-96` |
| Webhook | `api/webhook-payos.php` (POST-only, 1 MiB cap, 400/503 semantics) |
| Migration | `scripts/migrate-payos.php` (tables → InnoDB, money columns → `DECIMAL(20,2)`, creates `payos_orders`) |
| Admin | `views/admin/recharge-payos.php` |
| Tests | `tests/payos_client_test.php`, `tests/payos_service_test.php`, `tests/payos_race_worker.php`, `tests/legacy_payment_gate_test.php`, `tests/legacy_security_test.php` |

---

## 3. Feature-flag / routing behaviour (verified by static reading)

`libs/legacy_payment_guard.php` defines two guards:

* `reject_legacy_payment_when_payos_enabled()` (`:8`) → HTTP **410** when `PAYOS_ENABLED` is truthy.
* `require_active_legacy_payment($db, $statusName, $secretName)` (`:16`) → HTTP **403** when the channel
  is off or its secret is blank.

Call sites of the 410 guard: `ajaxs/client/create.php:8` (actions `nap_the`,
`CreateInvoiceCrypto`, `CreateToyyibpay`), `ajaxs/client/update.php:8`,
`api/callback_card.php:6`, `api/callback_crypto.php:6`, `api/callback_flutterwave.php:6`,
`api/callback_perfectmoney.php:6`, `api/callback_squadco.php:6`, `api/webhook_web2m.php:6`,
`cron/bank.php:5`, `cron/bankWeb2m.php:6`, `cron/momo.php:6`.

Call sites of the 403 guard: `api/callback_card.php:13`, `api/callback_crypto.php:11`,
`api/callback_flutterwave.php:12`, `api/callback_perfectmoney.php:11`,
`api/callback_squadco.php:12`, `api/webhook_web2m.php:12`.

Client views that redirect to payOS when enabled: `recharge-card.php:7`,
`recharge-crypto.php:7`, `recharge-flutterwave.php:7`, `recharge-manual.php:7`,
`recharge-momo.php:7`, `recharge-paypal.php:7`, `recharge-perfectmoney.php:7`,
`recharge-squadco.php:7`, `recharge-toyyibpay.php:7`.
Navigation label switch: `views/client/nav.php:7` (`$payosNavEnabled`) used at `:120`, `:304`, `:444`.

Resulting routing matrix **today**:

| `PAYOS_ENABLED` | `/client/recharge-bank` | `/client/recharge-card` | `nap_the` AJAX | legacy bank cron/webhook | payOS webhook |
|---|---|---|---|---|---|
| `false` | payOS page, "chưa sẵn sàng" notice, form disabled (`recharge-payos.php:71-79`) — **the legacy bank UI is never restored** | legacy card UI (if `card_status=1`) | allowed | allowed | rejected by missing keys |
| `true` | payOS page | payOS page | **410** | **410** | active |

Notes that matter for the requested work:

1. `views/client/recharge-bank.php:5` requires the payOS view **unconditionally**, so there is
   currently no code path back to a legacy bank client page. Any GachtheFast bank UI must therefore
   be added as a new, explicitly-routed view rather than by editing that shim.
2. The 410 gate is a hard stop placed **before** DB/API work in every legacy entry point (asserted by
   `tests/legacy_payment_gate_test.php`, 14 entry points). It must not be weakened or bypassed; a new
   provider needs its **own** flag, not a hole in this one.
3. `cron/bank.php` and `cron/bankWeb2m.php` are **web-reachable** (`views/admin/recharge-bank-edit.php`
   instructs the operator to request `cron/bank.php` every minute; `.htaccess` blocks
   `vendor|docs|tests|scripts|migrations` but **not** `cron`). `cron/bank.php` has **no** time/rate
   guard and no shared secret. This is pre-existing behaviour, and any new bank cron must not copy it.

---

## 4. Data surface (names observed in source; schema NOT supplied)

Tables read/written by payment paths (from `FROM`/`insert()`/`update()` call sites):

```
users, settings, dongtien, deposit_log, payment_bank, aff_log, aff_withdraw,
cards, banks, log_bank_auto, payos_orders, promotions, logs, payments*,
payment_crypto, payment_momo, payment_flutterwave, payment_paypal, payment_pm,
payment_squadco, payment_toyyibpay, payment_manual, product_order, product_sold,
invoices, automations, coupons, coupon_used, currencies, block_ip, failed_attempts …
```

Money-critical columns referenced by name only (no DDL available): `users.money`,
`users.total_money`, `users.debit`, `users.ref_price`, `users.ref_amount`,
`dongtien.{sotientruoc,sotienthaydoi,sotiensau,transid}`, `payment_bank.{tid,method,amount,received}`,
`deposit_log.{amount,received,is_virtual}`, `aff_log.{sotientruoc,sotienthaydoi,sotienhientai}`,
`cards.{trans_id,status,price,pin,serial}`.

`scripts/migrate-payos.php:11-17` asserts these tables and columns exist, and `:26-31` converts them to
`ENGINE=InnoDB` and `DECIMAL(20,2)`. **The storage engine and index state of the live database is
unknown from this checkout** — `02-blockers.md` asks for a schema-only fixture.

`users::AddCredits()` (`libs/database/users.php:50-64`) is **not** transactional: it inserts into
`dongtien`, then `UPDATE users.money`, then `UPDATE users.total_money`, each via separate `mysqli`
calls with string-interpolated values. New money-moving code must not reuse it as-is.

---

## 5. Mismatch list versus the owner's stated setup

| # | Owner statement | Checkout reality | Impact |
|---|---|---|---|
| M1 | "Remove the CardVN-specific card integration once its actual implementation is identified" | no `cardvn` token exists in tree or history; no endpoint other than `thesieure.com/chargingws/v2` | **Cannot identify a target for removal.** Removing anything would risk hitting the path the owner asked to keep. |
| M2 | "Keep the existing TheSieuRe bank option" | `THESIEURE` is only a **label** in the `banks.short_name` datalist; the polling logic keys off the literal value `ACB`/`VietinBank`/… and the host `api.sieuthicode.net`, not off `THESIEURE` | "The exact TheSieuRe bank route/configuration to keep" is undetermined. |
| M3 | "Add GachtheFast incoming bank-transfer service" | no GachtheFast code, config, credential slot, cron, callback, or doc in the checkout; the supplied doc set does not contain a bank contract | Contract **UNKNOWN** — implementation would require invention, which is prohibited. |
| M4 | GachtheFast automated card processing | supplied doc describes `/chargingws/v2` + `command=charging`/`check`, `md5(partner_key+code+serial)`; the existing `card24h()` speaks the **same shape** to a different host | GachtheFast card may be a drop-in re-point of `card24h()`, but the hostname, HTTPS support, and callback field/verification set are not established. |
| M5 | "Preserve payOS behaviour unless the owner approves a routing change" | payOS currently **replaces** the whole legacy top-up surface (client route + 410 gate) | Needs an explicit decision on whether GachtheFast card/bank coexist with payOS or inherit the same replacement rule. |
| M6 | "Do not use live payment credentials or real cards" | `.env.example` has blank payOS placeholders; no secrets in tree | OK — but the new provider needs a blank-placeholder configuration story before any code. |

### Public provider reconnaissance (documentation only, no provider API calls)

A public web check of `gachthefast.com` was performed to see whether the required contracts are
published (read-only public pages; no authenticated endpoint and no provider API was called):

* `https://gachthefast.com/` — operator is a Vietnamese scratch-card/top-up exchange; site is served
  over HTTPS. It advertises "KẾT NỐI API" and a merchant page at `/merchant/list`.
* `https://gachthefast.com/merchant/list` — **redirects to `/account/login`** ("Vui lòng đăng nhập
  tài khoản để sử dụng"). The partner API documentation is behind a merchant login.
* `https://gachthefast.com/news/huong-dan-nap-bang-hinh-thuc-chuyen-khoan-vo-web-.html` — a
  **customer-facing** guide for depositing money **into GachtheFast** (video + Zalo approval), i.e. the
  opposite direction from a partner credit feed. It is not an API contract.
* A near-identical clone domain `gachthefast1s.site` also exists; the operator's own homepage warns
  about copied/cloned sites.

Consequences: the production API hostname, HTTPS support for API and callbacks, signing, and the
incoming-bank-transfer contract cannot be established from public material. They must come from the
owner's linked Postman collection / merchant panel. See `01-contract-matrix.md`.

---

## 6. Questions Agent A puts to the owner

1. Which deployed file(s) implement the card integration the owner calls **CardVN**? Is the checkout
   the deployed revision, or is the deployed source ahead/behind it? (If it is ahead, we need that
   file, or the removal target is unfixable from here.)
2. Is `libs/helper.php:871 card24h()` (thesieure.com `/chargingws/v2`) the path the owner intends to
   **replace** with GachtheFast card processing? If yes, confirm that the TheSieuRe **bank** option
   (`config.php:4` label + `banks` rows) is the thing to keep, not the card path.
3. Which concrete `banks` rows / cron / webhook make up the "TheSieuRe bank option" that must remain —
   `cron/bank.php` (sieuthicode), `cron/bankWeb2m.php` + `api/webhook_web2m.php` (web2m), or both?
4. With `PAYOS_ENABLED=true`, must GachtheFast card and bank top-ups be reachable, or must payOS keep
   replacing every legacy top-up route?
5. Please supply a **schema-only** fixture (DDL for `users`, `dongtien`, `payment_bank`, `deposit_log`,
   `aff_log`, `cards`, `banks`, `log_bank_auto`, `settings`, `promotions`, plus `payos_orders`),
   including `ENGINE` and indexes. No rows, no production data.
