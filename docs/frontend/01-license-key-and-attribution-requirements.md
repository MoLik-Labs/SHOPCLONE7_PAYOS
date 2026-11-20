# License-key logic and attribution — requirements (NOT IMPLEMENTED)

**Status: requirements only. No code written, no licence logic changed.** This is a **separate
workstream** from the GachtheFast payment work and from the frontend pages. It touches licensing code, so
it needs an explicit spec before a single line is written: a wrong implementation can lock the owner out
of the admin panel or create a bypass that looks like a working licence check.

Owner's requirement, verbatim: *"This is the source code that allows users to enter the license key I
provide. You need to implement the license key logic; this grants the right to modify certain elements,
but the original Orbit Office identity must not be removed — you may only replace specific parts, not
eliminate the original foundation entirely."*

---

## 1. What the code does today (facts, with line numbers)

| # | Fact | Where |
| --- | --- | --- |
| 1 | There **is already** a licence-key entry screen: if the `license_key` setting is empty or the check fails, an admin form ("THÔNG TIN BẢN QUYỀN CODE") with a `license_key` input and a `btnSaveLicense` POST handler is shown. | `models/is_license.php:8-21` (input at `:22-23`) |
| 2 | That gate is included by several admin pages, so the licence decides whether those pages render or fall back to the form: settings, product-API add/edit, bank config, momo. | `views/admin/settings.php:13`, `views/admin/product-api-add.php:14`, `views/admin/product-api-edit.php:22`, `views/admin/recharge-bank-config.php:20`, `views/admin/recharge-momo.php:23` |
| 3 | **`checkLicenseKey()` is a stub: it returns `status = "Active"` for any input.** Every key "activates"; there is no real validation. | `libs/helper.php:1443-1448` |
| 4 | A full legacy remote-licensing routine exists but is **never called** (0 call sites): token/nonce, `localkey`, `md5hash`, `checkdate`, expiry fields, remote request and error paths. | `libs/helper.php:1295-1441` |
| 5 | A separate live domain check calls `https://api.cmsnt.co/checkdomain.php?domain=…` on two product-API actions. | `views/admin/product-api-add.php:45,61` |
| 6 | Admin roles already gate a "view_license" permission ("Xem giấy phép kích hoạt website & số phiên bản website tại Dashboard"). | `config.php:5`, `views/admin/home.php:13` |
| 7 | The key lives in the `settings` table as `license_key` (read through `$CMSNT->site(...)`, written by the form above). | `models/is_license.php:8,16,23` |
| 8 | No key value exists anywhere in this checkout (`license_key` is a DB setting), and none was requested or supplied. | repo-wide |

**Consequences of the current state** (recorded in `docs/gachthefast/10-security-review.md` SEC-10 as
informational): the licence gate is cosmetic — it cannot fail — while the remote CMSNT domain check on
the product-API pages is live and can block those two pages when the third-party service is unreachable
or says no.

---

## 2. Why this is a separate, gated workstream

1. **It is enforcement code.** Getting it wrong either locks the shop owner out of
   `views/admin/settings.php` (the page that stores the key!) or produces a check that always passes —
   the current situation. The recovery path must be designed *before* the check is written.
2. **It is third-party licensing.** The owner's standing rule is: do **not** remove or bypass CMSNT
   licensing. Any new logic must sit **alongside** the existing CMSNT checks, not replace them.
3. **It must not become a phone-home to an unknown party.** A licence server is an outbound dependency;
   the spec must name the authority (the owner's own server, or CMSNT). No host will be invented and no
   URL will be added by guesswork.
4. **Secrets and keys never appear in source, tests, logs or chat.** Key values are supplied out of band
   and stored only in the DB setting (or deployment `.env` if the design says so).

---

## 3. Design constraints the implementation must satisfy

* **Fail-closed for enforcement, fail-open for the shop's core.** If the licence cannot be verified
  (server down, timeout), the *gated admin features* degrade gracefully and **never** take the storefront
  down or destroy data. Ordering/payment flows must not depend on a licence call.
* **The key is never logged** (no `error_log`, no audit row containing the raw key — masked form only) and
  never echoed back in full into HTML.
* **No bypass by omission:** an empty key must not evaluate to "valid"; comparisons must be
  timing-safe where a secret is compared; the stored key must not be settable by non-admins.
* **Offline grace, if the design has one, must be bounded and explicit** (e.g. a signed local key with a
  fixed expiry, as the legacy routine already sketches at `libs/helper.php:1295-1441`) — not "cache
  forever".
* **Idempotent, reversible:** the change must be revertible with a documented rollback; migration of any
  stored field must be additive, and existing `license_key` values must keep working.
* **Recovery path:** a documented, owner-only way to re-enter or replace the key if the check ever locks
  the settings page (e.g. keep the key form reachable when the check fails — as today — never the
  reverse).
* **Tests:** pure logic (format validation, expiry, tamper) tested with fake transports; the network path
  tested only against a local stand-in, never a live licence server; no real key in any test or log.
* **Third-party notices and CMSNT checks untouched.**

---

## 4. The spec needed before implementation (owner to confirm)

| # | Question | Options / notes | Answer |
| --- | --- | --- | --- |
| L1 | **Who is the licence authority?** | (a) the owner's own server (provide host + endpoint contract out of band, never in chat); (b) CMSNT (reuse the existing legacy routine at `libs/helper.php:1295-1441`); (c) purely offline keys (signed key string, no server) | |
| L2 | **There is already an entry screen** (`models/is_license.php:8-21`). Is the task to (i) make `checkLicenseKey()` actually validate keys entered there, or (ii) build a new screen for a different product? | (i)/(ii); if (ii), where should it live | |
| L3 | **Key format** | e.g. `XXXX-XXXX-XXXX-XXXX` plus a signature/checksum, or an opaque string looked up remotely; what length/alphabet; is it per-domain, per-installation, per-customer? | |
| L4 | **What does a valid key unlock?** | (a) the admin pages currently gated by `models/is_license.php`; (b) nothing functional — it is a display/attribution licence only; (c) a specific feature list (name them) | |
| L5 | **Failure behaviour** | when the key is missing/invalid/unverifiable: (a) block the gated admin pages (as today) but keep the storefront fully working; (b) show a notice only, no blocking; (c) other | |
| L6 | **Offline/air-gapped behaviour** | (a) refuse to run without a server call; (b) bounded grace period (state how long); (c) offline-signed keys only | |
| L7 | **Keys for end users:** does the shop *issue* keys to its own customers, or only *consume* one key for itself? These are different systems — please pick. | consume / issue / both (then the issuing rules need their own spec: generation, delivery, revocation, refunds) | |
| L8 | **CMSNT interaction** | confirm the standing rule holds: existing CMSNT checks stay exactly as they are, and the new logic is additive | |
| L9 | **Rollback** | confirm the intended rollback if the licence logic misbehaves in production (e.g. restore the current permissive stub behaviour until fixed) | |

---

## 5. Attribution: "Orbit Office by NggKhaiz" — current state and what would change

| # | Fact | Where |
| --- | --- | --- |
| 1 | The string "Orbit Office" appears **nowhere in the code or DB statements** of this repo (the only near-hits are the FontAwesome icon named `orbit` and a `moment` locale author). `NggKhaiz` appears only as the repository owner account (`NggKhaiz/SHOPCLONE7_PAYOS`), not in the code. | repo-wide search |
| 2 | The visible credit line is **settings-driven**: `All Copyrights Reserved by {title} | {copyright_footer}`. | `views/client/footer.php:74-79` |
| 3 | `<meta name="copyright">` and `<meta name="author">` both come from the `author` setting. | `views/client/header.php:14-17` |
| 4 | Two hardcoded credit lines exist outside those settings: the admin footer and the reset-password page. | `views/admin/footer.php:8`, `views/client/reset-password.php:41` |
| 5 | Third-party licence/attribution notices in `public/**` are a **separate** category and must not be touched. | `public/ckeditor`, `public/fontawesome`, `public/theme/**` |
| 6 | The GachtheFast payment work touched none of the above (its only view edits added two nav/sidebar entries). | verified in `docs/gachthefast/10-security-review.md` |

**So the requested change is mostly configuration:** set `title`, `author`, `copyright_footer` (and the
page copy) to the Orbit Office wording, and edit the two hardcoded lines. **No attribution string will be
invented or removed** — the exact wording is question Q1 in
`00-mapping-and-owner-questions.md` §9.

---

## 6. What happens next

1. Owner answers L1-L9 (§4) and Q1 (§9 of the mapping doc).
2. A short design note is written here (authority, endpoints, key format, failure matrix, rollback,
   test plan) and approved.
3. Implementation, then tests with fake transports + a local stand-in server; the design note is updated
   with the real test output.
4. Nothing in this document changes the payment work: GachtheFast stays disabled, the release verdict
   stays **NO-GO**, and no frontend/attribution code is written before the mapping is confirmed.
