# Agent B — GachtheFast API Contract Matrix (documentation analysis)

Sources available for this analysis:

* The owner-supplied Postman page titled **"CARD PARTNER API"**, as summarised in the mission brief
  (endpoint `/chargingws/v2`, `command=charging` / `command=check`, signature
  `md5(partner_key + code + serial)`, status codes `1,2,3,4,99,100`, POST-JSON and GET-callback
  examples, `http://{{domain_post}}` placeholders).
* No bank-transfer document of any kind was supplied.
* Public provider pages on `gachthefast.com` (documentation only; **no provider API was called**).
  The merchant API page `/merchant/list` requires a merchant login, so no contract text was read from it.

Rules applied: undocumented items are marked **UNKNOWN**. Nothing below is inferred into an
implementation detail. No live API call, no credential request, no secret handling.

Legend: **[DOC]** = stated in the supplied documentation · **UNKNOWN** = not documented anywhere
available to this team · **[DERIVED]** = arithmetic consequence of two documented facts.

---

## 1. Applicability of the supplied document to "GachtheFast"

The supplied document is titled *CARD PARTNER API*. Neither its title nor its examples name
GachtheFast. The mission brief also lists seven mandatory unknowns, four of which are about the card
contract itself (callback field authentication, IP ranges, `check` authoritativeness, and value
semantics). Therefore this document is treated as **the card contract in principle**, but the binding
between that document and the GachtheFast account is **UNKNOWN** until the owner confirms.

Observed context that supports (but does not prove) a link: the existing in-repo card helper
`libs/helper.php:871-880` calls a same-shaped `/chargingws/v2` endpoint on a **different** host
(`thesieure.com`), which suggests a shared upstream API family among Vietnamese scratch-card
exchanges. That is an observation, not a contract.

---

## 2. Card-processing contract matrix

| Field | Status | Value / finding |
|---|---|---|
| Product | [DOC] | Card submission + status lookup ("CARD PARTNER API") |
| Endpoint path | [DOC] | `/chargingws/v2` |
| **Scheme + hostname** | **UNKNOWN** | Document uses `http://{{domain_post}}`. The production hostname, whether HTTPS is available, whether HTTPS is *enforced*, and the certificate identity are all unstated. Sending a card PIN over plaintext HTTP is prohibited by our rules, so **no submission can be implemented until an HTTPS hostname is confirmed**. |
| Method (submit) | [DOC] | POST JSON |
| Method (callback) | [DOC] | GET with query parameters |
| Auth headers | **UNKNOWN** | Not documented. No bearer/basic/API-key header is described. |
| Credentials | [DOC] | `partner_key` (and, from the examples, `partner_id`); **minting/rotation process UNKNOWN** |
| Signing (submit) | [DOC] | `md5(partner_key + code + serial)` |
| Signing (callback) | [DOC] | `md5(partner_key + code + serial)` — identical string, **no request id, order id, amount, or status is covered by the signature** |
| Canonicalisation | **UNKNOWN** | Case/whitespace/encoding rules for `code`/`serial` are not stated; `md5()` concatenation is byte-sensitive. Whether telco is upper-cased, whether PIN padding is stripped, etc. is unstated. |
| Required submit fields | [DOC] (by example) | `telco` (upper case), `code` (PIN), `serial`, `amount` (declared value), `partner_id`, `sign`, `command`, `request_id` |
| Field types / ranges | **UNKNOWN** | e.g. is `amount` an integer VND, a string, an enum? Are `command=check` fields the same? |
| Response schema | [DOC] (partial) | Result code field described only via its integer values; full envelope (wrapper object, message field, echo of `request_id`) **UNKNOWN** |
| Status meanings | [DOC] | `1` success at declared value · `2` success at a different value · `3` invalid card · `4` provider maintenance · `99` pending/accepted · `100` submission failure |
| Any other status | **UNKNOWN** | Behaviour on unknown/new codes is undefined by the document. |
| `amount` vs `value` vs `declared_value` | **UNKNOWN which is credited** | Mission brief explicitly lists this as an open decision; also **UNKNOWN** what partial/over/underpayment policy the shop wants. |
| Provider transaction id | **UNKNOWN** | No field is documented that uniquely identifies the card submission on the provider side and is returned on both the submit response and the callback. `request_id` is our id by example; whether it is echoed back is unstated. |
| Idempotency of submit | **UNKNOWN** | Whether re-sending the same `request_id` is deduplicated, rejected, or double-processed. |
| Callback authentication beyond the signature | **UNKNOWN** | Mission brief asks this directly. There is no documented field list for the callback other than `code`/`serial` in the signature; **there is no documented IP allow-list and no documented shared-secret header**. |
| **Callback integrity gap [DERIVED]** | — | Because the signature covers only `code` + `serial`, any party who ever observes one `(code, serial, callback_sign)` triple for a card can replay it with a **forged `status`, `value`, `declared_value`, `amount`, `request_id`, `trans_id` and `telco`**. Integrity of money-affecting fields is therefore **not** provided by the documented scheme. |
| Reconciliation (`command=check`) | [DOC] exists / **UNKNOWN** in effect | The brief asks: is `check` authoritative, and how is *its* response authenticated? Both are **UNKNOWN**. If the response is unsigned, an attacker with a MITM/redirect foothold could answer `status=1`. |
| Replay / retry behaviour of callbacks | **UNKNOWN** | Whether the provider retries on non-2xx, retry cadence, max attempts, and whether repeats are byte-identical. |
| Timeouts | **UNKNOWN** | No documented request timeout or slow-path SLA. An ambiguous timeout must be treated as *outcome unknown*, never as failure. |
| Limits / rate limits | **UNKNOWN** | No documented per-IP, per-partner, or per-telco limits. |
| Sandbox / test endpoint | **UNKNOWN** | None documented. Without one, Phase 3 must use a local mock (planned). |
| Card-type value set | **UNKNOWN** | The repo's own UI offers `VIETTEL, VINAPHONE, MOBIFONE, VNMOBI, ZING, VCOIN, GARENA` (`views/client/recharge-card.php:84`); whether GachtheFast accepts the same tokens and the same denomination list is unstated. |
| Currency | **UNKNOWN** | Presumed VND by context; not documented. |

### 2.1 Card state machine that the documentation *cannot yet determine*

The documented code set is enough to design a state machine, but **not** enough to decide the money
semantics:

```
submitted ──(99)──> pending ──(1)──> success@declared        → amount to credit = ??? 
                            ──(2)──> success@different value → amount to credit = ??? (OPEN DECISION)
                            ──(3)──> invalid                 → no credit, free retry per policy
                            ──(4)──> maintenance             → no credit, retryable, do not consume card
                            ──(100)──> submit failure        → no credit, retryable
submitted ──(transport timeout)──> OUTCOME UNKNOWN (must be reconciled via `check`, never assumed failed)
```

Every `???` above is a blocking owner/provider question (`02-blockers.md` Q5/Q5b).

---

## 3. Incoming bank-transfer contract matrix

**No bank-transfer document was supplied.** The rendered documentation described in the brief
contains a card contract only, and the brief itself states: *"The rendered documentation does not
provide the incoming-bank-transfer contract. Do not invent it."* Public pages on `gachthefast.com`
document only a **customer depositing into GachtheFast**, which is the opposite direction.

| Field | Status | Note |
|---|---|---|
| Direction (incoming deposit vs payout) | [OWNER SCOPE] incoming top-up is intended — **still to be confirmed in writing** given the provider publicly sells payout ("Rút quỹ") products as well | must be confirmed before design sign-off |
| Endpoint + hostname + scheme | **UNKNOWN** | none supplied |
| Method / content type | **UNKNOWN** | — |
| Credentials | **UNKNOWN** | — |
| Signing / canonicalisation | **UNKNOWN** | — |
| What creates/identifies a transfer | **UNKNOWN** | Is it a per-order virtual account (VA) we mint, a static shop account we poll, or a provider-hosted deposit intent with a return URL? These are three fundamentally different designs and the choice drives the whole schema. |
| Unique provider reference | **UNKNOWN** | no documented field that uniquely keys a transfer and prevents duplicate credit |
| Status model | **UNKNOWN** | no documented "pending/credited/failed" set |
| Notification mechanism | **UNKNOWN** | push callback, pull poll, or both |
| Callback authentication | **UNKNOWN** | no bearer/HMAC/IP list described |
| Reconciliation query | **UNKNOWN** | no documented authoritative lookup |
| Amounts / fees / partials / min / max | **UNKNOWN** | — |
| Retries / replay | **UNKNOWN** | — |
| Sandbox | **UNKNOWN** | — |
| IP ranges | **UNKNOWN** | — |

Consequently **no bank-transfer implementation can be authored from the material available here.**
Any code written now would encode guesses about money movement, which the mission prohibits.

---

## 4. Cross-cutting items also required from the provider/owner

1. **HTTPS and TLS on both hops.** Our rules require the exact documented hostname over HTTPS with
   certificate validation, strict timeouts, bounded responses, and no redirects. The supplied examples
   use `http://`; if the provider cannot serve HTTPS for submission *and* the callback, the feature is
   not implementable as specified.
2. **Callback source IPs or a signed callback envelope** for the card callback (to compensate for the
   `code`/`serial`-only signature), and the equivalent for the bank callback.
3. **`command=check` response authentication** — needed before `check` may be treated as authoritative
   for reconciliation.
4. **Value policy** for card status `2` (`amount` / `value` / `declared_value`), including
   under/over-payment handling, so the ledger rule is deterministic.
5. **Sandbox credentials and a test endpoint**, or written permission to test against production with
   a minimum-value card — Phase 3 and Phase 5 cannot be completed otherwise. (Recommendation: sandbox,
   or a local mock; never production with real cards.)
6. **Confirmation that submit retry with the same `request_id` is safe** (idempotent) — without it,
   an ambiguous timeout leaves us unable to retry and unable to safely abort.
