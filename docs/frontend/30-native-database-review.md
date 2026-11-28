# Native PHP and disposable-database review

Status: fixture evidence, **not** a production release sign-off. No target hosting, original CMSNT schema,
live payment, live licence, production database or deployment was touched. MySQL contained synthetic data
and listened on loopback only. The test runtime was assembled locally from third-party npm archives; its
provenance was not independently audited and it is not a deployment recommendation. The packages and the
local database live in ignored `.cache/runtime/`, which may disappear between sessions.

## Problems reproduced and changed

- A missing `.env` previously aborted before a hosting process environment could be used. Dotenv now
  uses `safeLoad()`. Both an environment-only database query and a copied fixture with a real `.env` were
  exercised; missing mandatory config still requires an operator check.
- `mysqli_real_connect()` returns a boolean, not a connection: treating it as the connection broke real
  queries. The driver retains the `mysqli` object and clears it after close. Transaction begin and commit
  failures now return failure; a lost commit acknowledgement remains ambiguous and needs reconciliation.
- Native PHP 7.4 exposed amount parsing and HTML escaping differences. Positive amounts are trimmed
  before validation; `check_string()` uses explicit quote flags. Old PHP password-escaping forms are
  accepted for existing hashes and bcrypt hashes are upgraded after successful login. Legacy data that
  was stored using the older `ENT_COMPAT` escaping may need a separate, staged compatibility audit;
  these changes do not convert existing data.
- Forwarded scheme is accepted only from an explicitly trusted immediate proxy; session, CSRF and auth
  cookie decisions share the same helper. A configured HTTPS `APP_URL` also forces Secure cookies.
  The edge must enforce HTTPS and overwrite client-supplied forwarding headers. Proxy addresses are
  exact IPs, not CIDR ranges.
- The legacy 410 test used to leave a fake Composer autoloader in `vendor/`. It now removes a stub it
  created and leaves pre-existing dependencies untouched, with isolated success/failure cleanup tests.

## Recorded results

PHP 7.4.33 and PHP 8.2.20 on local MySQL 5.7.29 (synthetic schemas, no live provider):

| Command / group | Result |
| --- | --- |
| `php tests/db_connection_integration_test.php` (explicit loopback fixture opt-in) | PASS: 10/10 on each PHP; expected diagnostic messages on deliberately killed connections |
| `php tests/db_money_integration_test.php` (new, empty `orbit_test_money_*` DB) | PASS: 11/11 on each PHP; concurrent debit and triggered rollback included |
| `php tests/payos_service_test.php` (disposable DB) | PASS: 44 assertions on each PHP |
| `php tests/gtf_service_test.php` | PASS: 93/0 on each PHP |
| `php tests/gtf_credit_path_test.php` | PASS: 75/0 on each PHP; test-only credit unlock, shipped lock unchanged |
| `php tests/gtf_credit_path_mutation.php` | PASS: 2/0 on PHP 8.2 |
| GTF disposable migration lifecycle | PASS on PHP 8.2: migrate/repeat 15/0; rollback 9/0; MyISAM refusal 4/0; explicit conversion 9/0; missing column refusal 4/0 |
| 49 non-DB `tests/*_test.php` scripts | PASS: 49/49 each on native PHP 7.4 and 8.2, in separate subprocesses |
| `.env` copy + local MySQL query | PASS on both PHP versions; no repository `.env` created |
| `bash tools/dev/run.sh` | PASS: LINT 324 files/0 errors; SCAN 240 files/0 findings; 38 pages rendered; 60 suites, 5 NOT RUN, 0 partial, 0 failures. The five DB suites are exercised separately above; WASM cannot replace native DB testing. |
| `node tests/js/legacy_gate_cleanup_test.mjs` | PASS: 8 checks, isolated temporary workspace |
| `node tools/dev/browser-checkout.cjs` | PASS: 11 synthetic browser checks |
| `node tools/dev/browser-audit.cjs /tmp/shopclone7-preview` | PASS: 38 fixture pages × 2 viewports, 0 failing combinations |
| `node tools/dev/browser-interactions.cjs /tmp/shopclone7-preview` | PASS: 10 fixture interaction checks |
| `git diff --check` | PASS |

The native Composer install ignored `ext-gd` solely to run these tests. CAPTCHA/GD was **NOT RUN**;
production must satisfy all Composer platform requirements without bypassing them. PHP 7.4 is end-of-life;
these compatibility checks are not a recommendation to deploy it. Browser fixtures are not tests of
real authentication, the shop database, accessibility certification, or network/provider integrations.

## Safe execution and release gates

Read test headers before running them: payOS/GTF fixture suites and migration fixtures can drop/create
or modify tables. A DSN containing `test` is **not** proof of safety; never target a shared or real shop
database. The two new database suites are opt-in, require loopback and explicitly named disposable
schemas, and the wallet test refuses a nonempty database. Their options are documented in `tests/README.md`.

The actual licensed base schema is not supplied in this repository. Restore a backup to isolated staging,
inspect legacy schema and data, rehearse only reviewed schema changes, test a supported PHP runtime with
all extensions, verify proxy/host configuration and HTTPS, and exercise authentication, local stock,
provider callbacks and failure recovery in a suitable non-production environment. Checkout retry remains
non-durable across tabs/clients and provider timeout outcomes can be ambiguous. Do not deploy, apply a
migration, unlock GTF, or create real payments without owner authorization and explicit release review.
