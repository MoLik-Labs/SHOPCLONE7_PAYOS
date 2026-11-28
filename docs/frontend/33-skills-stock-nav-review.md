# Skills, stock rendering and catalogue UX — 2026-10-04

## Skill references

The owner-requested skill collections were reviewed and pinned as documented in `docs/skills/UPSTREAM.md`. The marketplace is an index, not automatic installation of its plugins. Vercel Agent Skills has no repository licence in the inspected revision, so a pinned link was recorded instead of copying its source. Existing Impeccable and Taste copies stay at their earlier reviewed revisions. `AGENTS.md` provides a repository-local starting point for future agents whose clients load it; it cannot provide global memory or force another chat to open this branch. No third-party installer, hook or deployment was run. UI/UX Pro Max's local UX search was used for navigation guidance; Superpowers debugging/TDD and the Go/low-level principle of measuring before optimizing informed the work. No Go/Rust service was introduced.

## Fixes

- `store_nav_default()` returned before reaching its tools switch. With the switch on, tools now appear in the shared navigation and the current state reflects a tool page; with it off they remain hidden. A failing regression was reproduced before the fix.
- When an existing page of results consists only of hidden sold-out rows, the empty state now says **this page** has no in-stock items and points to other pages when available. Negative supplier stock is treated as unavailable when the owner chooses to hide sold-out products. Stock filtering still happens *after* SQL pagination: counts and pages can include hidden products. Fixing that properly requires reviewing the real schema/indexes, query plans and provider stock semantics; the text does not pretend the pagination issue is resolved.
- Admin stock rows previously printed account values, UID, seller name and type directly into HTML and an attribute. They now escape the actual database bytes for their output context, with numeric row/seller IDs cast before interpolation. This preserves the decoded attribute value used for copying/export; existing HTML-encoded legacy data should be checked on a staging copy. TXT export now joins selected accounts with newlines instead of silently concatenating them. This is a local fix, not an audit of every admin page or JavaScript interpolation.

## Verification

- `tests/storefront_test.php`: 51 passed, 0 failed. `tests/admin_stock_render_test.php`: 8 passed, 0 failed, including hostile fixture values, attribute round-trip and TXT separator. The latter executes the actual row loop with fake data; it is not a full admin HTTP/browser/DB test.
- `bash tools/dev/run.sh`: lint 329 files/0 errors, static scan 240 files/0 findings, 38 fixture pages; 65 suites, 8 NOT RUN, 0 partial, 0 failures. NOT RUN includes database/process-isolation suites; do not count these as passed.
- Fixture Chromium smoke: 38 pages at 1440/390 pixels, 0 failing page/viewport combinations; 10 interaction checks and 13 checkout recovery checks passed. Not a full accessibility audit or real payment test.
- A limited pattern scan over changed text files before commit found one published AWS documentation example in a vendored reference. It was replaced with a neutral placeholder. The scan is not complete secret detection and excludes binaries, large files and Git history. No production performance benchmark was conducted, so no overall speedup is claimed.

No real customer records, SMTP, payment/provider calls, production database, migrations or deployment were used. The GTF/financial safety gates and remaining release blockers are unchanged.
