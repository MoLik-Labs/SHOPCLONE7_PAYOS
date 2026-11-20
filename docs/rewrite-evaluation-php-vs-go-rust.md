# Rewrite evaluation — PHP vs Go vs Rust

Date: 2026-11-16 · branch `arena/01a1066c-shopclone7-payos`
Status: **decision document**. No rewrite was started, and none of the numbers below are invented.

## The question

Round 12 allowed a Go or Rust rewrite if it would genuinely be faster and better long-term:
*"Có thể code lại cả project theo ngôn ngữ code khác như rust hay golang để hiệu suất tốt và lâu dài
cũng được, hiệu quả hơn là làm thôi"*. This document decides that on merit, not preference.

## What the workload actually is

| Property | Measured / observed in this repository |
| --- | --- |
| Entry points | 1 front controller (`index.php`), 14 AJAX handlers, ~30 `api/` callbacks, 3 cron scripts |
| PHP files | 284 linted tracked files (the shop itself is ~200) |
| Per-render work | parse ~60–120 small files, run 20–80 SQL statements, print 20–120 KB of HTML |
| After round 12 | settings = **1** statement, translations = **1–2** statements, 1 MySQL connection |
| Dominant cost | **network + MySQL round trips**, not PHP CPU |
| Concurrency style | one PHP-FPM worker per request; no shared in-process state |
| Deployment target | Render free tier (ephemeral FS, no native PHP runtime — PHP runs via a buildpack/container) or a VPS with Apache/PHP-FPM |

The hot path is `SELECT`-heavy and read-only. A compiled language cannot make MySQL answer faster;
it can only reduce the CPU time around the query, which is already small.

## What Go or Rust would buy

| | Go | Rust |
| --- | --- | --- |
| Per-request CPU overhead | lower than PHP-FPM (no per-request bootstrap) | lowest |
| Memory per worker | ~10–30 MB | ~5–15 MB |
| Concurrency | goroutines, excellent | async/threads, excellent |
| Start-up on free/cheap hosting | instant | instant |
| Ecosystem for this domain | mature SQL drivers, decent templating | mature SQL drivers, steeper templating/mail/PDF work |
| Hiring/maintenance by the owner | readable, one binary | harder to hand over |

Those gains are real **for CPU-bound services** (image processing, streaming, high-QPS APIs). This
shop is not one: with 20–80 queries per page, removing ~1–3 ms of PHP execution from a page that
spends 30–300 ms waiting on MySQL changes the total by a few percent.

## What the rewrite would cost

1. **Integrations.** 60+ files under `api/` implement payment callbacks (payOS, GachtheFast card
   partner, Perfect Money, Flutterwave, SquadCo, MoMo, PayPal, ToyyibPay, bank auto-transfer, crypto).
   Each has provider-specific signing, idempotency and status mapping (`1,2,3,4,99,100`). Porting
   them is where the bugs would be — and payment bugs cost money.
2. **CMSNT licence layer.** `libs/helper.php::CMSNT_check_license()` and the local-key verification
   are PHP code tied to this distribution. The rule from rounds 5–11 stands: **never bypass CMSNT
   licensing**. A rewrite would have to reproduce the check in another language, which is legally and
   practically risky; keeping PHP for that part means a hybrid anyway.
3. **Admin surface.** ~90 admin views with roles, filters, exports, editor uploads. Rebuilding them
   in Go templates is weeks of work with no performance return.
4. **Obfuscation/source-protection (level 4, deferred).** The plan targets a PHP encoder. Choosing
   Rust/Go would replace that plan with native binaries — a different, larger project.
5. **Two stacks, two deploys, two sets of secrets.** A hybrid (Go for the storefront, PHP for admin and
   payments) doubles the ops surface on a free-tier host.

## Benchmarks: what was and was not measured

- **NOT RUN — cross-language benchmark.** This sandbox has no native PHP, Go or Rust toolchain
  (`which php go rustc` → nothing) and no MySQL. Any table of "PHP 120 ms vs Go 8 ms" produced here
  would be fabricated. It is not in this document.
- **Measured here:** the structural facts above (query counts, one connection, files) and the test
  suite (`28 suites, 1 not run, 0 failures`).
- **Measurable on staging, before any rewrite decision:**
  1. `bash tools/dev/perf.sh 9 home product:<slug> blogs` — median `ms`, `queries`, `bytes`.
  2. MySQL slow-query log for one hour of traffic.
  3. `opcache_get_status()` — confirms the server actually has OPcache enabled.
  4. A load test (`oha`/`wrk`, 20 concurrent, 60 s) against the same page, with the DB on a separate
     host so PHP and MySQL time are separable.

A rewrite is justified only if, **after** indexes and OPcache are in place, a page's PHP execution
time (total minus DB time) is a significant share of p95 latency — for example p95 > 400 ms with
DB time < 100 ms. The probe already reports total time and query count; adding `mysqli` timing is a
five-line change if the owner wants that split.

## Decision

**Do not rewrite now.** The bottleneck is database round trips, and this round removed the largest
avoidable ones (settings, translations, duplicate connections). PHP + OPcache + indexes is the
cheapest path to a fast shop, and it keeps every payment integration and the CMSNT licence check
exactly as reviewed.

**Revisit when one of these is true:**

- p95 page latency stays above ~400 ms after indexes and OPcache, and the slow-query log is clean
  (i.e. PHP/CPU is the limiter);
- a genuinely CPU-heavy feature arrives (bulk stock import of 10⁵+ rows, image pipeline, live
  streaming);
- traffic outgrows one PHP-FPM pool on the chosen host.

**If it is revisited, the cheapest path is a strangler, not a rewrite:**

1. Keep PHP for admin, payments and licensing — the parts where correctness matters most.
2. Add a Go service that serves only read-only, high-traffic pages (home, product, blog) from the
   same MySQL, behind a path prefix (`/go/...`), with the same HTML rendered from Go templates.
3. Feature-flag it per route, compare with `tools/perf-probe.php`, and roll back by flipping the flag.
4. Only move an endpoint to Go after it shows a ≥30 % p95 improvement at equal correctness, with both
   versions running side by side for a week.

Rust would be chosen over Go only for a CPU-saturated, memory-tight component where its extra safety
and speed pay for the slower delivery — not for HTML pages backed by MySQL.

## Summary

| Option | Performance gain now | Risk | Recommendation |
| --- | --- | --- | --- |
| Optimise PHP (this round) | removes the dominant repeated queries and handshakes | low, file-local | **do this** |
| Add indexes + OPcache (owner) | large, measurable | low, reversible | **do this next** |
| Go rewrite of the storefront | small (DB-bound) | medium; HTML/SEO/template parity | only after measurements demand it |
| Full Go/Rust rewrite | small now | high (payments, licence, admin) | not justified |
