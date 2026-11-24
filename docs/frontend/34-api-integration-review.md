# API integration: staged rollout and limitations

This is an incremental change, not an API v2, a deployment approval, or an MCP server.
The endpoints accept `X-API-Key`; the stock-import modal and customer documentation show
key-free URLs. API responses, the customer key page and the admin stock page request no-store.
Ensure HTTPS all the way to the trusted edge and secure access to each hop; never send real keys,
inventory or purchased goods to test tools, URLs, analytics, browser logs or support screenshots.
Configure the edge to forward `X-API-Key` to PHP, reject untrusted forwarding headers, and redact
that header, query strings and sensitive request/response bodies from proxy, application, APM and
error logs. `no-store` does not prevent logging. Verify these protections in the actual stack.

## Migration

1. Inventory existing integrations, credentials and their permissions. Back up configuration; test
   on an isolated staging copy with synthetic data. Provision a dedicated admin with only the stock
   permission required by this application, subject to the existing permission model.
2. Move read integrations to `X-API-Key`. For stock import send `POST` form fields `code`, `account`
   and optionally `filter=1` to `/api/importAccount.php`, and the admin key in `X-API-Key`. A header
   request refuses URL `api_key` or `account`, and only accepts body data and filter 1 (or omitted).
   An existing UID receives 409 rather than being updated or moved. DB errors return 503; after an
   ambiguous failure inspect inventory before any manual retry. Do not automate blind retries.
3. In staging verify that the real TLS proxy forwards the header and that access logs, WAF, CDN and
   observability systems redact secrets; check each client's behavior and HTTP/JSON errors. Once all
   consumers are migrated, set `API_ALLOW_QUERY_KEYS=false` and
   `API_ALLOW_LEGACY_IMPORT_GET=false` in the environment actually loaded by PHP, then restart/reload
   as required. Both flags are independent; absent flags retain legacy behavior for upgrades and
   `.env.example` opts new installs out. An unrecognized value fails closed. Roll back flags only if
   necessary, knowing the original URL-leak risks return. Rotate exposed keys and coordinate consumers;
   verify old keys stop working. Remove/redact retained logs under the operator's retention policy.

Disabling URL-key authentication does **not** disable the import's legacy POST body-key path. That
path retains its previous `$_REQUEST` behavior, including `filter=1` updating/moving an existing UID
and `filter=0` allowing duplicates. Disabling GET import does not remove this POST path. Restrict
legacy clients at the edge and migrate them to header authentication; do not assume these flags by
themselves enforce the new import semantics. Existing URL secrets may have been recorded already.
The header format accepts 16–128 ASCII letters, digits, underscores or hyphens; current generated keys
are 32 hex characters. Unusual historical keys should be rotated after checking consumers rather than
assuming header compatibility. Authentication/authorization failures retain historical response shape.

The UID lookup then insert is **not atomic**. Concurrent requests can duplicate a UID without an
appropriate database constraint; no uniqueness migration, schema compatibility audit, idempotency key,
or production concurrency proof is included here. The create-order action still mutates via GET;
there is no durable end-to-end idempotency, and ambiguous checkout/supplier results require manual
reconciliation. Do not enable retries or expose privileged writes to an MCP client on this basis.

## Evidence and gaps

`tests/api_credentials_test.php` checks precedence and flags. `tests/js/api_integrations_test.mjs`
executes actual endpoint files against a fake DB in isolated subprocesses; it covers authentication,
permissions, legacy/modern paths, duplicate/error cases and buyer-scoped order results. PHP-WASM
and native PHP 7.4/8.2 runs pass. `tests/manual/api_http_probe.mjs` additionally exercised five local
HTTP cases and response headers on the PHP 7.4/8.2 built-in servers with a fake DB; it is not an
Apache, production proxy, real SQL or concurrency test. The get-order-detail query-count fixture demonstrates one owner-scoped inventory read rather
than two; this is not a production latency benchmark. Confirm indexes, schema, output encoding and
real SQL behavior with representative synthetic data before release. The stock view checks include
source assertions; they are not a full admin browser integration or production-cache test.
