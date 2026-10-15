# 09 — Design system, shell and music player (round 6, as built)

This is the report for the work authorised by the round-6 answers in `08`: *build the content-free design
system and shell now, source protection level 4 only, hybrid design direction, music from direct audio
URLs.* Nothing in the existing shop pages was restyled — the shop front itself still waits for your prompt
texts, assets and content pack (`02` → `01` → `04` → `05` → `03`).

---

## 1. What now exists

| area | files | what it does |
| --- | --- | --- |
| Design tokens | `public/client/orbit/tokens.css` | one place for colour, type, spacing, radius, motion; dark by default, light supported (`data-theme`, plus `prefers-color-scheme`) |
| Base layer | `public/client/orbit/base.css` | small reset, typography scale, layout primitives (`.container`, `.stack`, `.grid`, `.row`), focus rings, skip link, reduced-motion handling |
| Components | `public/client/orbit/components.css` | buttons, fields, cards, tables (zebra/sticky/responsive), alerts, pills, empty states, kv lists, breadcrumbs, skeletons, `dialog` |
| Data components | `public/client/orbit/components.css`, `chart.css`, `table.js` | KPI tiles + deltas, progress meters, toolbars, timelines, switches, accordions, tooltips, sortable/filterable tables — added in round 7 |
| Chart layer | `libs/chart.php`, `views/common/chart.php`, `public/client/orbit/chart.{js,css}` | payload maths in PHP, markup + no-JS fallback table, SVG rendering in the browser; no library, no network |
| Static preview | `tools/preview-build.php`, `tools/preview-data.php` | renders the new pages + this styleguide into a self-contained offline folder (`docs/frontend/preview/`) with sample data |
| Shell | `views/common/orbit-shell.php` + `public/client/orbit/shell.css` | `orbit_shell_open()` / `orbit_shell_close()`: document head, top bar, footer, landmarks. Copy comes from the CMS or the caller — the shell invents none |
| Living reference | `views/common/orbit-styleguide.php` | `/?module=common&action=orbit-styleguide` — admin-only, `noindex`, unlinked. Every component plus theme switching, built on the shell itself |
| Music player | `views/common/music_player.php`, `public/client/orbit/music-player.{css,js}`, `public/music/playlist.json` | one discreet control, never autoplays, renders **nothing at all** while the playlist is empty |
| Music importer | `tools/music-import.php` | CLI-only helper that downloads a direct audio URL once into `public/music/` and records it in the playlist |
| Frontend audit | `tools/frontend-audit.php` | checks the rendering-stability contract of `08 §3` and can write the report into the repo |
| Tests | `tests/music_player_test.php` (16), `tests/orbit_shell_test.php` (31), `tests/styleguide_render_test.php` (35), `tests/sidebar_links_test.php` (21), audit fixtures (19, re-runnable), importer suite (25, external rig) | the behaviours above, pinned |

Wired into the shop: **one line** in `views/client/footer.php` (`require_once … music_player.php`) which
currently renders nothing. Nothing else in `views/` was touched, so the live look is unchanged.

## 2. How the design stays consistent

Pages load three stylesheets in order — `tokens.css → base.css → components.css` (plus `shell.css` when the
shell is used) — and compose the documented classes. There is no build step, no framework, no CDN, and no
JavaScript in the critical path: every control in the system works with JavaScript disabled; JS only adds
conveniences (the player, copy-to-clipboard).

The styleguide is the contract in practice: if a component is not there, it does not exist yet.

## 3. Music — how you switch it on

```bash
# preferred: one command per track, self-hosted, licence recorded
php tools/music-import.php "https://…/audio.mp3" --title="Calm 01" --artist="Orbit Studio" --license="CC0"
php tools/music-import.php --list
```

Or edit `public/music/playlist.json` and add `{"title": "…", "url": "https://…/audio.mp3"}` for a direct
remote URL (your round-6 answer; the owner's rights responsibility, and the visitor's browser will contact
that host). Empty list = no player anywhere. Never autoplay; one control; nothing loads before a click.

## 4. Rendering-stability contract — measured, not claimed

`php tools/frontend-audit.php --scope=orbit` → **0 findings** across the 10 Orbit files, and the generated
report is in `docs/frontend/frontend-audit-report.md`. The rule set is itself tested against fixtures with
deliberate violations (16/16), so a passing run means the rules ran.

For the record, the same tool on the legacy shop views is the honest *baseline*, not a regression:

```
php tools/frontend-audit.php --scope=views --summary
scope=views files=130 findings=456
  external-request             232   (CDNs, Google Fonts, analytics-shaped hosts, hotlinks)
  insecure-http                 58
  unversioned-asset             17
  inline-style-block            14
  blocking-script              130
  remote-font                    4
  img-dimensions                 1
```

That debt belongs to the legacy theme and is what the per-page rebuild (prompt by prompt) is for. It is
listed here so nobody mistakes the new scope's clean number for the whole shop being clean.

## 5. Evidence from this round

| what | command (PHP 7.4 via the sandbox harness) | result |
| --- | --- | --- |
| Design system + shell lint | `token_get_all(TOKEN_PARSE)` over the new files | pass |
| Shell contract | `tests/orbit_shell_test.php` | **31/31** |
| Styleguide renders | `tests/styleguide_render_test.php` | **35/35** (one doctype, one `<main>`, 109/109 balanced divs, no leaked PHP, every data component present, empty chart explains itself) |
| Player partial | `tests/music_player_test.php` | **16/16** |
| Importer end-to-end | 25 cases incl. a real HTTP download | **25/25** |
| Audit rules | `python3 tests/audit-fixture/run_selftest.py` (bad/good fixture per rule, in the repo) | **19/19** |
| Audit on the new scope | `php tools/frontend-audit.php --scope=orbit` | **0 findings** (10 files) |
| Audit on the sales layer | `php tools/frontend-audit.php --scope=sales` | **0 findings** (9 files) — round 7 |
| Client regression | `tests/licence_client_test.php` | **81/0** |
| JS syntax | `node --check` on both Orbit scripts | pass |

Two real bugs were found by these tests and fixed: the importer moved the audio file into place *before*
checking the playlist cap (leaving an orphan file on refusal), and the audit matched HTML tags with a naive
`[^>]*`, so a `?>` inside a PHP-generated attribute hid `width`/`height` (false reports on real views).

## 6. What is still gated (unchanged)

1. **Restyling the shop pages** — needs the approved mapping and your content (name, logo, copy, nav,
   products, CTAs, contact links). The system is ready; the content is yours.
2. **Music content** — rights and files are yours; the player is off until a track is added.
3. **Source-protection levels 1–2** (self-hosted encoder / licensing) — you chose level 4 only, so nothing
   was encoded or obfuscated; the release manifest with SHA-256 is still on the list (`08 §2`).
4. **Payment code, CMSNT checks, third-party notices** — untouched.

## 6b. Round-7 additions (sales tracking)

The chart layer, the data components, the admin sales page, the buyer purchase-history page and the
static preview are documented in `10-sales-tracking.md`, together with the legacy-baseline
reconciliation and the round-6 leftovers that were closed in the same round.

## 7. Where to look first

- `docs/frontend/preview/index.html` (built by `php tools/preview-build.php`) — the design system, the sales
  pages and the styleguide as a self-contained, offline folder you can open or zip.
- `/?module=common&action=orbit-styleguide` (as an admin) — the whole system on one page, in the real shop.
- `/?module=admin&action=sales` and `/?module=client&action=purchase-history` — the round-7 pages.
- `docs/frontend/10-sales-tracking.md` — what round 7 built, and how it was verified.
- `docs/frontend/08-quality-source-protection-and-music.md` — the plan these answers came from.
- `docs/frontend/frontend-audit-report.md` — the generated audit result.
- `tools/music-import.php --help` — how to add a track.
