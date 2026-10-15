# Licence server, first-run setup, backup/restore and code protection — requirements and proposed design

**Status: DESIGN ONLY — nothing implemented.** These are new sub-systems requested in round 3. They are
**separate from** the GachtheFast payment work (which stays disabled, verdict NO-GO) and from the frontend
page work (still blocked on the prompt texts and content pack). No payment code, no CMSNT check and no
third-party notice is touched by any of this.

Owner's requirement, verbatim: *"The author must be Orbit Office (though 'By NggKhaiz' may appear in
certain sections); this attribution is non-negotiable. Crucially, the uploaded code must be encrypted to
prevent unauthorized reuse. Upon initial access, the user should be redirected to an admin sign-up page
for setup, after which standard configuration applies. A user-friendly backup feature (using JSON or a
similar format) must be implemented, featuring robust, password-protected encryption — the execution must
be highly professional. License key validation should be hosted on Render (free tier), with measures in
place to ensure uptime; access must be granted only upon validation of a valid key."*

---

## 1. What already exists in the checkout (facts)

| # | Fact | Where |
| --- | --- | --- |
| 1 | `install.php` is **CLI-only**: it refuses to run over HTTP (`Run database installation from the command line.`) and creates the schema table by table. There is **no web installer today**. | `install.php:3-5,14-94` |
| 2 | `update.php` is **deliberately locked** (`exit("Chức năng khóa")`) and also refuses localhost. | `update.php:9-13` |
| 3 | There is **no** "installed" lock file, no first-run flag and no admin-creation flow in the web app. | repo-wide |
| 4 | A licence-key **entry screen already exists** for the admin, shown when `license_key` is empty or invalid (`models/is_license.php:8-21`). | `models/is_license.php`, included by `views/admin/settings.php:13`, `product-api-add.php:14`, `product-api-edit.php:22`, `recharge-bank-config.php:20`, `recharge-momo.php:23` |
| 5 | `checkLicenseKey()` is a **stub that returns `Active` for any input**; a full legacy remote-licence routine exists but is never called (0 call sites). | `libs/helper.php:1443-1448`, `:1295-1441` |
| 6 | AES-256-GCM is already used in this codebase for sealing card data, with random IV and tamper refusal — a good basis for the backup format. | `libs/gtf.php:146-187` (`GtfSecret`) |
| 7 | Admin CSRF (`hash_equals` + permission checks) and role permissions already exist and are the pattern new admin features must follow. | `config.php:5`, `ajaxs/admin/gtf.php:58-73`, `10-security-review.md` "verified good" |
| 8 | The checkout has **no** `vendor/` (test stub only) and no Node toolchain. | repo state |

---

## 2. Proposed architecture (five separate pieces)

```
  customer's shop (PHP 7.4)                    Render (free tier)
  ┌──────────────────────────────┐             ┌──────────────────────────┐
  │ libs/licence_client.php      │  HTTPS/443  │ licence service          │
  │  - reads license_key setting │────────────▶│  POST /v1/activate       │
  │  - POST activate/validate    │◀────────────│  POST /v1/heartbeat      │
  │  - caches result in settings │             │  (signed responses)      │
  │  - offline grace window      │             └──────────────────────────┘
  └──────────────┬───────────────┘
                 │ gates only the admin features that already use is_license.php
                 ▼
        shop storefront + payments ALWAYS run (licence never blocks orders)

  B. first-run setup (web)      C. backup / restore (admin only)      D. code protection (release step)
```

| Piece | Purpose | Owner of the decision |
| --- | --- | --- |
| **A. Licence service + client** | Validate a key before the gated admin features unlock | Owner (host/contract); server code can be written now |
| **B. First-run setup** | On a fresh install, redirect to an admin sign-up page, then normal configuration | Owner (flow) + security review (unauthenticated admin creation) |
| **C. Backup / restore** | Admin exports an encrypted JSON backup; import restores it | Owner (scope) |
| **D. Code protection** | Ship the distributed copy in encoded form | Owner (encoder purchase) |

---

## 3. A — Licence service and client

### 3.1 Render facts that change the design (verified)

* Free web services **spin down after 15 minutes of inactivity** and take **30–60 s** to cold-start on the
  next request; paid plans start around $7/month and remove this. The free tier is described by Render's
  own reviewers as unsuitable for production APIs.
  <https://render.com/docs/your-first-deploy> · <https://render.com/blog/free-tier>
* Render's **native runtimes do not include PHP**; deploying PHP means using the **Docker** runtime.
  <https://render.com/docs/your-first-deploy>

**Consequence — the two requirements fight each other.** "Access must be granted only upon validation of a
valid key" plus a licence server that is asleep most of the day equals: the first admin page load after an
idle period waits 30–60 s, and if the client timeout is short, it sees "no answer" and — under a strict
reading — refuses access. The design that satisfies both:

1. **Storefront and payments never depend on the licence.** Orders, deposits and callbacks must keep
   working whether the licence server is reachable or not (this also protects the owner's revenue).
2. **Only the already-gated admin features** (`models/is_license.php` call sites) depend on validation.
3. **Cache the validation result locally** (in `settings`, signed) with a bounded grace window, e.g. 7–30
   days — so a sleeping licence server normally does not matter at all.
4. **Client timeouts are generous but bounded** (e.g. 5 s connect / 10 s read), with one retry, because a
   cold start can be 60 s; the client never hangs the admin page beyond that.
5. **Keep-warm is allowed but not relied upon:** a scheduled ping (cron every 10 min) keeps the free
   instance awake during business hours; it must never be the only reason validation works.
6. **The licence service uses PHP? No — use Node.** Since Render has no PHP runtime and PHP would need a
   Dockerfile, the service is proposed as a **single-file Node/Express app + SQLite-or-file key store**
   (nothing else needed), or a **Docker/PHP** service if the owner prefers one language. Either way it
   ships with its own tests and a `render.yaml`.

### 3.2 Client behaviour (fail-open for the shop, fail-closed for gated features)

| Situation | Gated admin features | Storefront / payments |
| --- | --- | --- |
| Key present, server says valid | unlocked | normal |
| Key present, server says **invalid/revoked** | locked, clear message + key form | normal |
| Key present, server **unreachable** | unlocked only inside the cached grace window; otherwise locked with a clear message | normal |
| No key at all | locked (as today) | normal |
| First install (no key yet) | locked; key form reachable | normal |

Hard rules: the key is never logged in clear, never echoed in full, never sent in a URL query string, and
never bundled into the backup file in clear text without the owner's explicit decision (§5).

### 3.3 Proposed endpoints (v1 — to be confirmed by the owner in §7)

```
POST /v1/activate   { key, domain, install_id, php, version }  → { status, expires_at, features[], signature }
POST /v1/heartbeat  { key, install_id }                        → { status, expires_at, signature }
POST /v1/deactivate { key, install_id }                        → { status }        (release a seat)
```

Responses are signed with an Ed25519 or HMAC key held only by the service; the client verifies the
signature before trusting `status`. The service must support: per-key domain binding, revoke, expiry,
seat count, an audit log (no full keys), and rate limiting.

---

## 4. B — First-run setup (web installer)

**Why this is the highest-risk item:** an unauthenticated page that creates an administrator is a classic
"first visitor becomes admin" vulnerability. If the shop is uploaded before the owner finishes setup, an
attacker who finds `?module=…&action=setup` owns the site.

Safe design (all must hold):

1. Setup is reachable **only when no admin account exists** (`users.admin != 0` count = 0) **and** the
   install lock file is absent.
2. It runs **once**: on success it writes a lock file (e.g. `install.lock` outside the web root or in a
   non-servable directory) and re-checks it on every request; a second visit is a 404.
3. Optional but recommended: a **setup token** printed by the CLI installer (or generated into the lock
   file) that the first visit must carry — stops opportunistic discovery.
4. The `.htaccess` rule that denies `vendor|docs|tests|scripts|migrations` must be extended if any new
   directory is added.
5. The password field enforces the same strength policy as the rest of the app; the form is CSRF-protected;
   the action is rate-limited and logged.
6. The existing CLI installer (`install.php`) keeps working and remains the documented path for non-web
   setup.

Open question: does the owner want this to **replace** `install.php` for customers, or be an addition?
(`update.php` stays locked — it is not run and not changed.)

---

## 5. C — Backup and restore (admin only)

Proposed format: a single file, `backup-YYYY-MM-DD-HHMM.orb` (extension cosmetic), containing:

```json
{ "format": "orbit-backup/1", "created_at": "…", "shop": {"url": "…", "version": "…"},
  "tables": { "settings": [ … ], "users": [ … ], "…": [ … ] },
  "counts": { "users": 12, "products": 40, "gtf_card_orders": 0 },
  "sha256": "…" }
```
encrypted as a whole with **AES-256-GCM**, key derived from the operator's password with
**PBKDF2-HMAC-SHA256 (≥ 200 000 iterations, random 16-byte salt)**, plus a random 12-byte IV per file; the
GCM tag makes the file tamper-evident. PHP 7.4 supports all of this (`hash_pbkdf2`, `openssl_*`), and the
same pattern is already proven in `libs/gtf.php:146-187`.

Safety rules (non-negotiable, consistent with the owner's own standing instruction):

* Export is **admin-only**, CSRF-protected, permission-gated (`edit_setting`), and rate-limited.
* **Import never deletes payment history.** It offers a **dry-run diff** (rows added/changed/removed per
  table) and refuses to touch `dongtien`, `deposit_log`, `payment_bank`, `gtf_ledger`, `gtf_card_orders`
  unless the operator explicitly chooses "restore payments too" **and** the shop is in maintenance mode.
* Secrets (`SECRET_KEY`, `PAYOS_*`, `GTF_PARTNER_*`, licence key) are **excluded by default**; a separate
  opt-in exists because restoring them incorrectly can break encrypted card data
  (see the `SECRET_KEY` caveat in `docs/gachthefast/11-operator-runbook.md` §6).
* A copy of the current state is written **before** any import (auto-snapshot), and the operation is
  logged with who/when/what.
* Restore is **atomic per table** (transaction + rollback), and a failed import leaves the shop unchanged.
* The file is streamed to the operator's browser (never left in a web-servable directory); no temporary
  unencrypted file touches disk.

---

## 6. D — Code protection ("the uploaded code must be encrypted")

**Facts to decide on, honestly stated:**

* PHP "encryption" means a commercial encoder: **ionCube** or **SourceGuardian**, both around **$249**
  entry / ~$369–429 for the licensing editions; the **loader is free** for the customer's server but must
  be **installed as a PHP extension and match the PHP version** (7.4 here).
  <https://www.sourceguardian.com/blog-sourceguardian-vs-ioncube-encoding-comparison-post-288-1.html>
* Commercial decoders exist for both products, so encoding **raises the bar, it does not make copying
  impossible**. <https://decodephp.io/blog/ioncube-vs-sourceguardian-vs-zend-guard>
* Encoded files **cannot be edited, diffed, grepped or unit-tested**. That collides with the owner's
  standing rule that everything be verified and tested, and with the security-review discipline used for
  the payment code.

**Recommended approach — encode the released artifact, keep the repository as source:**

1. `tools/build-release.php` (new) copies the tree to `build/dist/`, applies the encoder to the files that
   should be protected, keeps `config.php`/`.env.example`/`views/**` configurable, and writes a manifest
   with SHA-256 hashes + the required loader version.
2. Before encoding: run the full test suite on the **source** (lint, GTF suites, legacy gate, payOS
   client — the same commands as `docs/gachthefast/09-qa-test-matrix.md`).
3. After encoding: a **smoke test on a host with the loader installed** (not possible in this sandbox —
   documented as NOT RUN, with the exact steps for the owner's host).
4. The licence check and the encoded distribution are **independent**: encoding protects the source,
   the licence key enforces the contract.
5. Payment code stays **behaviour-identical** when encoded; the release manifest records the exact
   pre-encoding commit hashes so any encoded file can be traced back to reviewed source.

---

## 7. Decisions needed (blocking)

| # | Decision | Options | Why it blocks |
| --- | --- | --- | --- |
| D1 | **Licence service stack** | (a) Node (single file, native Render runtime — recommended); (b) PHP via Dockerfile on Render; (c) something else | Determines everything about the service code |
| D2 | **Licence key format + issuing** | Are keys per-domain, per-installation, with an expiry? Issued by hand from an admin page on the service, or auto-generated at purchase? | The client and the service must agree |
| D3 | **Code protection** | (a) ionCube (buy encoder, encode the release artifact only — recommended); (b) SourceGuardian; (c) skip encoding, rely on the licence key only; (d) decide later | I will not add an encoder or encode anything without the owner buying the tool and confirming the customer hosts can install the loader |
| D4 | **First-run setup** | (a) new web setup page with a one-time token + lock file (recommended); (b) keep CLI-only install and just add a redirect to the existing admin login; (c) web setup but without a token | Admin-creation surface — must be chosen deliberately, not by default |
| D5 | **Backup scope** | (a) full database, secrets excluded by default (recommended); (b) everything including secrets; (c) settings/products only, no user data | Determines the format and the safety rules |
| D6 | **Render account/host** | the service URL + where its signing secret and key store live (give out of band, never in chat) | Deployment cannot happen without it, but development can proceed with a local stand-in |

---

## 8. Risks (recorded now, reviewed before any release)

| # | Risk | Severity | Mitigation |
| --- | --- | --- | --- |
| L1 | Licence server asleep → admin locked out of settings, or (worse) the shop blocked | **High** | Storefront never depends on it; cached validation + grace window; generous timeouts; keep-warm cron; documented manual unlock path |
| L2 | Web setup page creates an admin for an attacker | **High** | Only when no admin exists + one-time token + lock file + rate limit + log |
| L3 | Backup file leaks (secrets, customer data, payment records) | **High** | Admin-only, encrypted (AES-256-GCM + PBKDF2), secrets excluded by default, never left on disk |
| L4 | Restore destroys payment history | **High** | Dry-run diff; payment tables refused unless explicitly chosen; auto-snapshot before import; atomic per table |
| L5 | Encoded code cannot be reviewed/tested; a regression ships silently | **Medium-High** | Encode only the built artifact; test source before encoding; smoke test on a loader-equipped host; manifest maps encoded files to source hashes |
| L6 | "Encrypted" code gives false confidence and breaks on hosts without the loader | **Medium** | Documented loader requirement + fallback to unencoded distribution; expectation set about decoders |
| L7 | New licence client becomes a phone-home with weak trust | **Medium** | Signed responses; TLS verification on; no key in URL; no logging of keys; explicit failure matrix (§3.2) |

---

## 9. Suggested build order (once D1–D6 are answered)

1. **Attribution** (no code risk, owner already decided): settings values + the two hardcoded credit lines.
2. **Licence service** (Node, single file) + local stand-in tests — no deployment.
3. **Licence client** in PHP with the §3.2 failure matrix + fake-transport tests + grace cache.
4. **Backup/restore** (format + export first; import with dry-run last).
5. **First-run setup** (after its security design is approved).
6. **Release pipeline** (`tools/build-release.php`) — after the encoder is purchased.

Every step: tests first-class, no live calls, no secrets in the repo, and a rollback note. The payment
workstream stays where it is: **disabled**, verdict **NO-GO**, untouched by any of this.
