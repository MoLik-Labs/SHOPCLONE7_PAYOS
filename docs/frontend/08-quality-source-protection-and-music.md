# 08 — Making the whole thing safe, stable and good-looking (and the music player)

**Date:** 2026-10-05 · **Status:** plan + the decisions I cannot make for you · **Branch:**
`arena/01a1066c-shopclone7-payos` (no commit, no deploy)

Owner brief, in their words: *"make everything safe, beautiful, convenient, secure, protect the source code
well, ensure the rendering is stable, make the frontend really good, add a music playback function, make it
really stable, simple yet beautiful, and not flashy."*

This document answers it in six parts, one per pillar, and ends with **four decisions**. Three of these
questions already exist elsewhere in the repo (prompt mapping in `00`, code protection in `02` §6, first-run
setup in `02` §4); this file consolidates them so nothing is answered twice or contradicted.

---

## 0. The short version

* **Already true on the new code** (portal + licence client): secure defaults, hashed secrets, CSRF, rate
  limits, atomic stores, TLS-verified client, no dependencies, 64 tests, 46/0 pentest — see §1.
* **The biggest risk is not code quality, it is distribution**: the shop is a *sold* PHP script, so
  "protect the source" is a product decision (encode? host it yourself?) — §2.
* **Rendering stability is an engineering contract, not a hope**: no external requests, fixed media
  dimensions, one stylesheet, works without JS — §3, checkable by script.
* **"Simple, beautiful, not flashy" is a taste brief** — it needs the real design target (the prompt texts)
  and the content pack before a single page is rebuilt; until then I can build the *system* (tokens,
  layout, components) that any of the five designs can plug into — §4.
* **Music is easy to do well and easy to do badly** — the difference is: never autoplay, never a third-party
  embed, always one discreet control that remembers what the visitor chose — §5 (with a working sketch).

---

## 1. Safe & secure — what is already done, and the honest remainder

| Property | Where it stands | Evidence |
| --- | --- | --- |
| Passwords | scrypt, per-account salt, timing-safe compare, constant-time unknown-account path | `05`/`07`, `test/portal.test.js` |
| Licence keys | stored only as `sha256`, masked for display, shown once at issue | same |
| Sessions | random 32-byte token stored hashed, `HttpOnly` + `SameSite=Lax` (+`Secure` on HTTPS), expiry, "sign out everywhere" | `07` |
| State changes | admin check + CSRF token + audit entry + rate limit on every mutation | `05`, 46/0 pentest |
| Data at rest | store file `0600`, atomic write + fsync + rotating backup + corruption recovery | `05` |
| Transport | TLS verification on for the shop→portal call, no redirects, certificate refusal verified | `04`, previous round |
| Dependencies | **zero** runtime dependencies in the portal; the shop uses only in-repo libraries | `package.json` |
| Payments | **frozen and unchanged** — still NO-GO with open P1/P2 items, not part of this plan | `docs/gachthefast/*` |

Remaining work, in priority order:

1. **Durable storage (Neon/Postgres)** — the free Render disk is ephemeral; until this is written, a deploy
   can wipe licence data. This is *the* go-live blocker (§7 P0).
2. **Encrypted backup that includes the web source files** — owner decision from round 4; design exists
   (`02` §5), implementation not started.
3. **Secret rotation plan** — rotating `PORTAL_SIGNING_SECRET` invalidates issued tokens; needs a documented
   overlap window (two accepted secrets) so shops are not locked out mid-rotation.
4. **Monitoring** — the owner's Uptime Kuma on `/healthz` (keeps a free instance warm and tells you it died).
5. **First-run setup token + lock file** for the shop (`02` §4) — designed, not built; must land before any
   distributed copy is installed by a stranger.

---

## 2. Protecting the source code — the four levels, honestly labelled

"Encrypted PHP" raises the bar; it does not make copying impossible (commercial decoders exist for ionCube
and SourceGuardian alike). The strongest protection is **not shipping the source at all**.

| Level | What it means | Cost | Effect | Cost to you in support |
| --- | --- | --- | --- | --- |
| **1. Host it yourself** | Customers use your shop on your server; you never distribute code. Licence portal enforces per-account access. | a small VPS or paid Render (~$7/mo) | copying becomes irrelevant | you run everything; no per-customer install |
| **2. Encode the release** | `tools/build-release.php` builds a distributable, ionCube/SourceGuardian encodes the private files, `config.php`/views stay editable. Loader required on the customer's PHP 7.4. | ~$249 one-off (encoder) | casual copying stopped, determined copying delayed | encoded files cannot be grepped/diffed/unit-tested — release must be smoke-tested on a loader host |
| **3. Obfuscate + fingerprint (free)** | Minify/rename internals, add per-customer watermarks in comments, keep the licence client enforcing the contract. | free | deters the lazy, not the determined | low |
| **4. Hygiene (always)** | `.htaccess` denies internals (already extended with `tools`), secrets out of source, no `.map`/backup/vendor junk in the web root, release manifest with SHA-256, keys never logged. | free | prevents the "found it in `public/`" class of leaks | low |

**My recommendation:** level 4 now, level 2 when you are ready to sell the script (it only touches the
*built artifact* — the repository stays source, so tests and reviews keep working), level 1 as the plan for
customers you do not want to support on their own hosting.

---

## 3. Rendering stability — a contract I can check by script

Stability is mostly about removing things that can fail at page view time:

1. **No third-party requests on new pages.** No CDN, no Google Fonts, no analytics, no hotlinking. One font
   stack (`system-ui` for UI text) or a self-hosted font with `font-display: swap`.
2. **Fixed geometry for media.** Every `<img>`/`<video>` carries `width`/`height` (or `aspect-ratio`);
   the music player reserves its own box so nothing shifts when it appears.
3. **No JS in the critical path.** Content, navigation and forms work with JavaScript disabled; JS adds
   conveniences only (player, copy-to-clipboard).
4. **One stylesheet per area**, cache-busted with a version (`?v=<filemtime>`), `Cache-Control: immutable`
   for assets, `no-store` for HTML. No inline `<style>` blocks that duplicate it.
5. **Graceful degradation everywhere**: styled 404 / maintenance / banned pages (already exist), empty
   states with a sentence instead of a blank area, and no unhandled error text on a page.
6. **Motion is opt-in**: every transition behind `@media (prefers-reduced-motion: no-preference)`.
7. **A browser floor**, stated and tested: last two versions of Chrome/Edge/Safari/Firefox + iOS Safari.
8. **A script that proves it** (`tools/frontend-audit.php`, unblocked, no dependencies): walks the views and
   reports external hosts, images without dimensions, inline handlers, missing asset versions and font
   stacks. Run before any release; the report goes in the repo, so "stable" is measurable.

I can build this contract and the audit **without touching a single shop page**; applying the fixes inside
the shop views is frontend work and needs your go-ahead (§8).

---

## 4. "Really good, simple, not flashy" — what that means in practice

Design rules I would hold to (they are what "professional" looks like up close):

* **Tokens, not opinions**: one spacing scale (4/8/12/16/24/32/48/64), one type scale (14/16/20/24/32),
  two font weights, one accent colour + neutrals, radius and shadow tokens. Light and dark from the same
  tokens.
* **One idea per screen**; the primary action is visible without scrolling on a 360 px phone.
* **Contrast and focus**: WCAG AA text contrast, visible `:focus-visible` rings, real `<button>`/`<label>`
  semantics, keyboard-complete flows.
* **Restraint**: at most one "moment" per page (a hero, a reveal); no parallax stacks, no auto-rotating
  carousels, no cursor effects, no sound-on-hover.
* **Real content shapes**: prices, stock, order status and CTAs get honest empty/loading/error states.
* **Performance budget**: < 150 KB CSS+JS on a page, < 1 s first paint on 4G, no layout shift.

**What is still blocking the actual rebuild** (unchanged from `00` §9): the **five prompt texts** (only a
summary exists in the checkout — PROMPT 05 must be value-exact, so I cannot reconstruct it), the **asset
files** (videos/images/fonts you own — rights confirmed, files not supplied), and the **content pack**
(site name, logo, copy, nav, contact details, products, CTAs).

What I *can* build before those arrive, without guessing any brand or copy: the **design system + page
shell** (tokens, typography, buttons, cards, forms, tables, alerts, empty states, header/nav/footer
skeleton) in plain PHP/CSS, plus the music player from §5 as its first component. When the prompts and
content land, each page becomes an exercise in filling the shell, not a rewrite.

---

## 5. Music — what was built (revised 2026-10-05, after the round-6 answer)

**Owner's answer:** the player consumes **direct remote audio URLs from existing links** ("like YouTube or
similar sources — where the original audio URL has already been extracted"). This replaces the earlier
self-hosted-only design: a track may now be either a **local file** or a **direct https URL**.

| | local file (preferred) | direct https URL |
| --- | --- | --- |
| playlist entry | `{"file": "name.mp3"}` | `{"url": "https://…/audio.mp3"}` |
| how it gets there | `php tools/music-import.php <url> --title=… --license=…` | edit `public/music/playlist.json` |
| stability | yours; can never expire or block hotlinks | depends on the remote host |
| privacy | no third-party request from the visitor | the visitor's browser contacts that host |

So the imported (self-hosted) copy is the recommended path and the importer makes it one command; the URL
form stays available because that is what the owner asked for. Rights remain the owner's responsibility,
and `--license="…"` is recorded (not verified).

**Rules that do not change** (enforced in the code, several checked by tests):

1. **Never autoplay.** Nothing loads or plays before a click — `preload="none"`, the `<audio>` element is
   only created when the visitor presses play, and blocked autoplay is treated as normal, not an error. A
   "Continue listening" pill appears after a navigation; the visitor still clicks it.
2. **No third-party embeds or players.** No `<iframe>`, no YouTube/Spotify widget, no external player JS —
   only the URL of the audio itself.
3. **One discreet control**, bottom-right: three buttons and a volume slider. No marquee, no popup.
4. **Never in the critical path.** Nothing renders while the playlist is empty (no markup, no CSS, no JS,
   no request). A failing track shows one line of text and moves on. Without JavaScript the shop is
   unaffected.
5. **It says what it is.** Artist and licence show in the panel; a missing licence is an audit warning, not
   a silent omission.

Where it lives: `public/client/orbit/music-player.{css,js}`, `views/common/music_player.php` (renders
nothing when there are no tracks), `public/music/playlist.json` (empty by default) and the importer
`tools/music-import.php` (CLI only, https only, refuses private/loopback hosts, follows no redirects,
verifies TLS, 20 MB cap, `audio/*` only, refuses to leave an orphan file). It is wired into
`views/client/footer.php` by one line and is inert today.

**Tests:** `tests/music_player_test.php` (16 checks: an empty playlist renders nothing; hostile titles
cannot escape the JSON block; `http://`, `javascript:`, path traversal and junk entries are dropped; the
playlist is capped) and the importer suite (25 checks through PHP 7.4: the CLI guard, internal/private
target refusals, redirect/404/wrong-content-type/oversize refusals, a real download → `audio/mpeg` → file
written → playlist entry with title/artist/licence/source, duplicate and `--force` paths, the playlist cap,
and that a refused import leaves no orphan file).

## 6. Convenience & stability of operations

| Thing | Today | Next |
| --- | --- | --- |
| Issuing a sale | admin page: create account → issue key → copy it once | same, plus a copy button and a printable one-time card |
| Contact channels | admin-editable links with `@handle`/phone shorthand | unchanged (done) |
| Customer self-service | HWID reset with 30-day cooldown, sign-out-everywhere, expiry visible | unchanged (done) |
| Backups | store backup beside the file | encrypted export incl. **web source files** (`02` §5), restore dry-run |
| Health | `/healthz`, `--verify`, startup warnings | Uptime Kuma monitor + "last check-in" column already visible |
| Releases | no packaging | manifest with hashes + rollback note (`02` §6 step 1) |

---

## 7. Build order (what I would do, and roughly how long)

| Priority | Item | Blocked on | Effort |
| --- | --- | --- | --- |
| **P0** | Neon/Postgres store driver + encrypted backup with source files | your one connection URL | 2–3 sessions |
| **P0** | Frontend stability contract + `tools/frontend-audit.php`; portal already complies | nothing | 1 session |
| **P1** | Music player component, switched off, no files | rights/files + go-ahead | 1 session |
| **P1** | Design system + page shell (tokens, components) in plain PHP/CSS | go-ahead to start without content | 2–3 sessions |
| **P1** | First-run setup token + lock file for the shop | D4 decision | 1–2 sessions |
| **P2** | Per-page rebuilds (01–05) once prompts + assets + content arrive | the three inputs in `00` §9 | per page |
| **P2** | Release packaging + encoder path | encoder purchase (D3) + loader test host | 1–2 sessions |

---

## 8. What I need from you (four answers, then I start)

1. **Frontend start** — may I start the content-free design system + shell now (no brand, no copy, nothing
   invented), or do you want zero frontend code until the prompt texts and content pack arrive?
2. **Music** — do you have tracks you own (or CC0) to self-host, and should the player be site-wide or on
   the landing page only? If no licensed files exist yet, I will build it switched off.
3. **Source protection** — level 1 (host it yourself), level 2 (buy an encoder, encode only the release),
   level 3 (free obfuscation) or level 4 only (hygiene) for now?
4. **Design direction** — keep the existing dark "Orbit" look and extend it to the shop, or rebuild the
   pages to the prompt designs once the content pack arrives? (The five prompt texts are still the missing
   input; nothing gets invented in the meantime.)

## 9. Honest limits of this plan

* No page of the shop gets rebuilt before the prompt texts, assets and content pack exist; this file says
  what will be built, not what has been built.
* Encoding cannot be tested in this sandbox (no ionCube/SourceGuardian loader here) — that step must be
  smoke-tested on a host with the loader, and will be reported as NOT RUN until it is.
* Nothing in this plan changes the payment code or the CMSNT licensing checks; the payment workstream
  remains frozen and NO-GO.
* No commit, push or deploy is included.
