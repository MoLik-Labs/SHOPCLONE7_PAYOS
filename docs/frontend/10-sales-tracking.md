# 10 — Sales tracking for admin and buyer (round 7, as built)

Round-7 brief, in the owner's words: *"do your absolute best to polish and complete anything that feels
unfinished, lacking, or not quite right. Put yourself in the shoes of both the admin and the user …
include sales tracking features and create visually appealing tables and charts."*

This document is the record of what was built, what was **not** touched, how it was verified, and what is
still waiting on you. The preview folder `docs/frontend/preview/` is the visual half of this document.

## 1. What was built (and where)

| Layer | File(s) | What it does |
| --- | --- | --- |
| Chart engine (math) | `libs/chart.php` | turns arrays into a chart payload: nice axis steps, series/stack stats, donut shares, safe JSON, escaping. Pure PHP — testable without a browser |
| Chart component (markup) | `views/common/chart.php` | `orbit_chart(...)` + `orbit_chart_assets()`: figure, caption, legend, payload, and a **fallback table** — without JavaScript the table *is* the chart |
| Chart renderer (browser) | `public/client/orbit/chart.js`, `chart.css` | draws SVG (area/line/bar/donut/spark), tooltips, keyboard-reachable points; no library, no CDN, no network |
| Table behaviour | `public/client/orbit/table.js` | optional sorting, filtering and a "showing N of M" counter on any `data-table`; the page works fully without it |
| Data components | `public/client/orbit/components.css` | KPI tiles (`.stat`), deltas (`.delta--up/down/flat`), progress meters, toolbars, timelines, switches, accordions, tooltips, sticky/zebra/responsive tables |
| Sales statistics | `libs/sales_stats.php` | period maths, growth, summary/orders/by-day/top-customers/deposits queries, CSV-safe cell escaping |
| Pagination | `views/common/pagination.php` | `orbit_pagination()` + window maths with ellipses; server-rendered links, works without JavaScript |
| Scoping contract | `tests/sales_scoping_test.php` | pins that the buyer page can only ever read the session user's rows (tokenizer-based, no database) |
| Admin page | `views/admin/sales.php` | `/?module=admin&action=sales&days=7\|30\|90` — KPI row, revenue chart, orders chart, product ranking, top customers, deposits by method, filterable order table, CSV export |
| Buyer page | `views/client/purchase-history.php` | `/?module=client&action=purchase-history&days=&q=&page=` — own spend, spend chart, search over own orders, pagination |
| Navigation | `views/admin/sidebar.php`, `views/client/sidebar.php` | "Thống kê bán hàng" (admin) and "Lịch sử mua hàng" (khách) — one line each |
| Living reference | `views/common/orbit-styleguide.php` | now also demonstrates KPI tiles, charts, tables+toolbar, pagination, timeline, switches, accordion, tooltip |
| Static preview | `tools/preview-build.php` + `tools/preview-data.php` | renders the two new pages and the styleguide into a self-contained folder with sample data, offline and inert |

Both new pages are built on the existing Orbit shell (`orbit_shell_open/close`), so they inherit the top
bar, footer, theme switching, focus rings and the skip link instead of re-implementing a layout.

### Admin and buyer, side by side

| Question the person has | Admin answer | Buyer answer |
| --- | --- | --- |
| "How much did we take?" | KPI: revenue, cost, margin, orders, new buyers, deposits — each with a delta against the previous period of the same length | KPI: orders, total spent, average per order |
| "Is it going up or down?" | area chart of daily revenue + bar chart of daily orders | area chart of own daily spend |
| "What sells?" | product ranking with orders, revenue, share bar | — (buyer does not need it) |
| "Who buys?" | top customers: orders, revenue, last order | — |
| "How does money come in?" | deposits by method with received-vs-recorded amounts | own top-up history stays where it was (`recharge-bank`) |
| "Show me the rows" | filterable, sortable order table + CSV export of the same numbers | search over **own** orders only + pagination |
| "Is it empty?" | empty states say which period has no data, in words | same |

Every number on the buyer page is scoped by `buyer = $user` in SQL, not by a hidden form field:
`tests/sales_scoping_test.php` (**27/0**, in the repo) walks the page statement by statement and fails if any
statement over a sales table is not bound to the session id — directly or through a variable that was bound
to it earlier in the file — and if the request can name a buyer at all.

### Honest-by-construction details

- **No invented content.** Titles, captions and empty-state sentences are passed by the page; the component
  never writes prose of its own. Where there is nothing to draw, the page says so in words
  ("chưa có kỳ trước", "chưa có dữ liệu trong khoảng này") instead of painting an empty box or a fake
  `+100%`.
- **CSV export is injection-safe.** `sales_csv_cell()` neutralises only formula *shapes* (`=`, `+1+1`,
  `-2+3`, `@`, `|`, leading whitespace); plain numbers stay numeric so the owner's spreadsheet still sums
  them.
- **Refunds and trashed orders are excluded** (`refund = 1`, `trash = 1`), matching the shop's existing
  report convention, and revenue is always paired with `SUM(cost)` so "profit" never means "revenue".
- **No JavaScript, no problem.** Charts fall back to their data table; sorting/filtering/search/pagination
  are all server-side; the CSV export is a plain link.
- **The money screens stay out of shared caches.** Both pages ask for `Cache-Control: no-store` and
  `X-Content-Type-Options: nosniff`, guarded by `headers_sent()` so a router that already produced output
  cannot turn them into a PHP warning.
- **No new dependencies, no CDN, no network.** SVG is drawn by our own ~13 KB script; fonts are the system
  stack.

## 2. What was deliberately **not** touched

- **Payment code** — nothing under `api/`, `cron/`, `ajaxs/`, `libs/payos*`, `libs/gachthefast*` was edited.
  The new pages only read `product_order`, `product_sold`, `deposit_log`, `users`.
- **CMSNT licensing and third-party notices** — untouched.
- **The legacy dashboard** — left as it is on purpose: it is one of the pages scheduled for the per-page
  rebuild once the owner sends the content for PROMPT 01–05. Rebuilding it now would mean inventing the
  design twice.
- **Brand, logo, copy, contact details** — none invented; the pages use the shop's existing labels.
- **No shop-page rollout** — the two new pages are additive routes; no existing page's markup was rewritten.
  The only edits to existing files are the two sidebar lines and the styleguide additions.

## 3. Evidence (PHP 7.4 in the sandbox harness)

Each row is a command that was actually run in this round; "not run" is never recorded as a pass.

| What | Command | Result |
| --- | --- | --- |
| Chart engine | `php tests/chart_test.php` | **93 / 0** |
| Sales statistics | `php tests/sales_stats_test.php` | **91 / 0** |
| Both new pages render | `php tests/sales_pages_test.php` | **55 / 0** |
| Pagination maths + markup | `php tests/pagination_test.php` | **36 / 0** |
| Styleguide (shell + all components) | `php tests/styleguide_render_test.php` | **35 / 0** |
| Orbit shell contract | `php tests/orbit_shell_test.php` | **31 / 0** |
| Music player partial | `php tests/music_player_test.php` | **16 / 0** |
| Licence client (regression) | `php tests/licence_client_test.php` | **81 / 0** |
| GachtheFast rules (regression) | `php tests/gtf_rules_test.php` | **124 / 0** |
| Sidebars (new links, balanced markup) | `php tests/sidebar_links_test.php` | **21 / 0** (admin 54/54 anchors, client 7/7) |
| Buyer-page scoping (IDOR, search box, cache headers) | `php tests/sales_scoping_test.php` | **27 / 0** |
| Lint of every touched PHP file (and every new test) | `token_get_all(TOKEN_PARSE)` | **20 / 0** |
| JS syntax | `node --check` on `chart.js`, `table.js` | pass |
| Frontend audit — new sales scope | `php tools/frontend-audit.php --scope=sales` | **9 files / 0 findings** |
| Frontend audit — design system | `php tools/frontend-audit.php --scope=orbit` | **10 files / 0 findings** |
| Frontend audit — legacy views baseline | `php tools/frontend-audit.php --scope=views --summary` | 134 files / **398** findings (see §4) |
| Audit rule self-test | `python3 tests/audit-fixture/run_selftest.py` | **19 / 0** (18 fixtures + the shim) |
| Static preview builds itself and checks its own links | `php tools/preview-build.php` | 4 pages + 9 assets, "every referenced stylesheet/script/image resolves" |
| Every class on the new pages has a rule | the same command's class check | **150 classes used, all defined** (both checks exit non-zero when deliberately broken — verified) |

All of the above ran in one pass — 20 lint files, 15 script runs, **610 assertions across eleven suites, 0
failures** — and the audit self-test runs on its own from the repository:

```
python3 tests/audit-fixture/run_selftest.py
```

The PHP suites run on a PHP 7.4 WASM harness that lives outside this repository (it is sandbox tooling, not
part of the shop); every suite above is a plain PHP script, so any PHP 7.4 binary runs them, and the sidebar
and scoping tests need no database at all.

Four real defects were found and fixed by these checks: a scalar `max()` warning that corrupted axis
steps, a pagination window clamp that could render page numbers outside the range, a spreadsheet-CSV guard
that neutralised plain numbers, and the buyer page sending no cache headers at all (found by the scoping
suite, which is also what discovered that a badly escaped regex could make it pass silently — the suite is
tokenizer-based now for that reason). Two more were found by the preview tool itself (see §5).

## 4. The legacy baseline moved — here is exactly why

The recorded round-6 baseline was `--scope=views` = **130 files / 456 findings**. Today it reads
**134 files / 398 findings**. That is accounted for line by line:

- **−58**: the audit counted `http://www.w3.org/2000/svg` (the SVG namespace string that
  `document.createElementNS` requires) as an insecure `http://` request. Namespaces are identifiers, not
  fetches. The rule now exempts `www.w3.org/<year>/…` only — a *real* stylesheet hotlinked from
  `www.w3.org/StyleSheets/…` is still reported, and a fixture proves it.
- **−2**: two genuine findings in the new pages (`render-blocking script`), fixed by moving `defer` onto the
  same source line as the `<script` tag. The audit reads concatenated PHP one line at a time, which is a
  deliberate strictness now documented in the rule list.
- **+4 files, ±0 findings**: the four new view files (`views/common/chart.php`,
  `views/common/pagination.php`, `views/admin/sales.php`, `views/client/purchase-history.php`) are clean
  under this scope.

So the legacy backlog is **398 real findings in 134 files**, none of them in the new layer. The sales scope
is part of the audit tool now, so it cannot silently regress.

## 5. Two bugs the preview tool caught in itself

1. `tools/preview-build.php` checked "does every referenced asset exist?" with the pattern
   `'#…[^"?#]+…#'` — the `#` inside the character class terminated the delimiter, so the check always
   passed. Now `~…~`; a deliberately broken page in the output folder makes the tool exit 4 with the exact
   missing path.
2. The landing page pointed at `assets/orbit/…` while the mirror copies to `assets/client/orbit/…`, so the
   preview index rendered unstyled. The index now goes through the same URL rewriting as the pages, and the
   builder refuses to finish if any reference would 404.

Both are the kind of thing that only shows up when a tool is actually run end to end — which is why the
preview is now built and inspected, not just described.

## 6. How to look at it

```
php tools/preview-build.php            # writes docs/frontend/preview/  (self-contained, offline)
php tools/preview-build.php --out=/tmp/orbit-preview   # write it somewhere else to zip and send
php tools/preview-build.php --clean    # remove the generated folder
```

The folder contains `index.html` (landing), `sales.html`, `purchase-history.html`, `styleguide.html` and the
mirrored `assets/`. Sample data only, no database, no network calls, no autoplaying music; every route link
is inert so a click can never land on a 404 inside the folder. In this session it is also served over the
sandbox preview URL, which is the fastest way to look at it in a browser.

In the real shop the two pages are at:

- `/?module=admin&action=sales` (with `&days=7|30|90`, `&export=csv`)
- `/?module=client&action=purchase-history` (with `&days=`, `&q=`, `&page=`)

## 7. Round-6 leftovers, closed

| Leftover | State now |
| --- | --- |
| "Styleguide demo / screenshot" outstanding | Closed: the styleguide is a real page, extended with the data components, covered by `tests/styleguide_render_test.php` (35/0), and rendered into the static preview |
| `--scope=sales` audit baseline owed | Closed: scope added to the tool, **0 findings**, plus a self-test that pins every rule |
| Audit rule self-test lived only in `/tmp` | Closed: `tests/audit-fixture/` now carries the fixtures, `expected.json`, the argument shim **and** the driver, **19/0**, re-runnable from the repository with one command |
| Legacy views baseline stale | Re-measured and reconciled (§4) |
| `tools/license-portal/README.md` module table count | Fixed (`accounts.js` 58 → 62 lines; every other count verified) |
| Music decision wording (`08 §5`) | Verified already correct (direct remote audio URLs, never autoplay, no third-party embeds) |
| Stale portal process possibly holding `0.0.0.0:8080` | Verified gone — nothing is listening on 8080 |

## 8. Still open (needs you, not code)

1. **Content for the shop pages.** The frontend rebuild stays gated on the PROMPT 01–05 mapping answers and
   the real name/logo/copy/contact details (`00-mapping-and-owner-questions.md`). Until then the new pages
   are the only place the design system is visible in the shop, and they reuse existing labels.
2. **Sample-data vs real numbers.** Everything above is verified with fixtures and stub databases. The first
   time the pages run against your real `product_order` table, please look at one day's revenue and one
   buyer's history and confirm the numbers match what you expect; the queries follow the shop's existing
   report convention, but only your data can confirm it.
3. **Importer rig.** The 25-check music-importer suite from round 6 ran from a rig that lives outside the
   repository (`/tmp`, wiped between sessions). The 16-check in-repo player test and the importer's own
   guards still stand, but if you want the full download suite re-runnable I need to rebuild the rig and
   store it beside the other suites under `tests/` — say the word.
4. **Nothing is committed, deployed or enabled.** As before: no commit, no push, no payment change, no
   CMSNT touch.
