# Frontend reconciliation — mapping table and owner questions

**Status: the shop-page rebuild is still gated; the surrounding work is built.** Round 2 resolved the
*approach* (see §8). Rounds 4–7 then answered the remaining questions and produced, with the owner's
go-ahead, the pieces that need no brand or copy: the design system, shell, music player, the licence client
and the round-7 sales-tracking layer (recorded in `08`, `09` and `10`). What this document blocks is the
**per-page rebuild of the shop itself** — the five supplied prompts are **not** combined, guessed at, or
partially built, and no brand, logo, copy or contact detail has been invented. Those still wait on the
inputs listed in §9.

**Standing rules for this workstream** (owner instructions, restated):

* Do not remove or alter required third-party licence/attribution notices (`public/ckeditor`,
  `public/fontawesome`, `public/theme/**`, vendor assets) or the CMSNT licensing checks.
* Do not combine prompts, and do not assume a brand, page or stack.
* Nothing is deleted or replaced until the mapping says so and the owner approves it.

---

## 1. Mapping table — what each prompt is, per the supplied summary

The full prompt texts are **not stored in this checkout**; the only record is the summary in
`docs/gachthefast/04-lead-report.md` §9. Please re-attach the originals if exact-value fidelity matters
(PROMPT 05 explicitly asks to "reproduce every value exactly" — that is impossible without the text).

| # | Prompt | What it delivers (per summary) | Claimed stack | Mismatch with this checkout | Most plausible home here | Open decision |
| --- | --- | --- | --- | --- | --- | --- |
| 01 | **Mainframe** | Hero section (marketing) | React + TS + Vite + Tailwind | Repo has no React/Vite/TS/Tailwind and no `index.html` build; hero has no obvious page yet | `views/client/home.php` (the homepage, selected by the `home_page` setting) **or** a new page | design target? which route? |
| 02 | **LGPSM 404** | 404 / "page not found" screen | React + TS + Vite + Tailwind **v4** | Same stack mismatch; the repo already has a 404 at `views/common/404.php` (plain HTML/CSS, Vietnamese copy) | `views/common/404.php` (reached from `index.php:37,45`) | replace the existing 404, or add a new one? |
| 03 | **Viktor** | Portfolio hero | React + TS + Vite + Tailwind | Portfolio branding/copy is unrelated to a card & top-up shop | `views/client/home.php` **or** a new page | is this wanted at all for the shop? |
| 04 | **Nexeus** | Landing page, single file | Single-file HTML (+ inline CSS/JS) | Droppable in principle — needs a route and the PHP wrapper (header/footer/nav) decision | new route, e.g. `?module=client&action=<slug>` | route + brand |
| 05 | **Orbit** | Landing page, single file, "reproduce every value exactly" | Single-file HTML (+ inline CSS/JS) | Same as 04, plus exact-value fidelity conflicts with adapting copy/brand for this shop | new route, e.g. `?module=client&action=<slug>` | route + how much may be adapted |

**Conflicts that must be resolved by a human, not by assumption:** five different brands (Mainframe,
LGPSM, Viktor, Nexeus, Orbit) versus this shop's own name; three React/Vite/Tailwind projects versus a
PHP-monolith client area; single-file HTML pages whose styling does not inherit the shop theme; and
one explicit "reproduce exactly" instruction versus the earlier "adapt the content to this site".

---

## 2. What exists in the repository today (facts, so answers are concrete)

| Thing | Where it lives now |
| --- | --- |
| Front controller / routing | `index.php:33-46` — `?module=<dir>&action=<file>`, else `views/common/404.php` |
| Homepage | `views/client/home.php`; which action is the homepage is a DB setting (`home_page`) |
| 404 page | `views/common/404.php` (+ its own `public/css/Error404.css`) |
| Site header / nav / footer | `views/client/header.php`, `nav.php`, `footer.php` (`sidebar.php` for the account area) |
| Brand & content source | the `settings` table, read through `$CMSNT->site(...)`: `title`, `description`, `keywords`, `author`, `logo_light`, `favicon`, `theme_color`, `theme_color1`, `email`, `hotline`, `address`, `copyright_footer`, `footer_card`, `widget_phone1_*`, `widget_zalo1_*`, `widget_fbzalo2_*`, `javascript_footer` |
| Site name in the footer credit | `views/client/footer.php:74-79` → `© All Copyrights Reserved by {title} | {copyright_footer}` |
| Other credit/meta spots | `views/client/header.php:14-17` (`<meta name="copyright">` = `author`), `views/admin/footer.php:8`, `views/client/reset-password.php:41` |
| Styles/scripts | `public/client/**` (theme), `public/**` third-party libs, CDN links (jsdelivr, Google Fonts) already used in places |
| No JS build system | no `package.json` build for the app, no Vite/React/TS/Tailwind anywhere in the repo |

---

## 3. Owner questions (answer each; blanks are for you)

### A. Design target and route

| # | Question | Answer |
| --- | --- | --- |
| A1 | **Which prompt is the actual design target?** (one of 01–05, or a different design) | |
| A2 | **Which page/route does it apply to?** (`views/client/home.php` homepage / the 404 at `views/common/404.php` / a brand-new page / the whole public client area) | |
| A3 | Should the current page it replaces stay reachable (e.g. keep the old 404 and add the new design as an alternative), or be replaced outright? | |
| A4 | Are PROMPT 01–05 all wanted eventually (as separate pages)? If yes, in what order? | |

### B. Site-specific content (needed before any code)

| # | Question | Answer |
| --- | --- | --- |
| B1 | Final **shop/site name** (used in the title, footer credit, logo alt text) | |
| B2 | **Logo** file(s) to use (light/dark), and favicon — or keep the current `logo_light`/`favicon` settings | |
| B3 | **Copy**: headline, sub-headline, section text, CTA labels (any language(s): VI / EN) | |
| B4 | **Navigation** items for the new page(s) — and whether the current nav (`views/client/nav.php`) stays | |
| B5 | **Contact details** shown on the page (email, hotline, Zalo/Facebook, address) | |
| B6 | **Products/services** to feature (which card telcos/top-ups, denominations, links) | |
| B7 | **Primary call(s) to action** (register / login / recharge / contact) and where each links | |
| B8 | **SEO/content** to keep: title, description, keywords (`description`/`keywords` settings) | |

### C. Where it belongs (integration shape)

| # | Question | Answer |
| --- | --- | --- |
| C1 | Inside the PHP app as plain HTML/CSS/JS views (**no new build step**; recommended for consistency and least risk) — or | |
| C2 | A Vite build whose output is produced by a build step and served by the PHP app (**new toolchain + deploy step**) — or | |
| C3 | A separate project/site (own repo or subdirectory), linked from the shop — or | |
| C4 | Unclear: produce a side-by-side comparison of C1/C2/C3 (effort, deploy, theming, SEO) before deciding | |
| C5 | If a JS toolchain is chosen: which Node version, and is adding `package.json`/CI acceptable in this repo? | |

### D. Copyright / footer text (exact list)

| # | Question | Answer |
| --- | --- | --- |
| D1 | Which strings should change: the client footer credit (`views/client/footer.php:74-79`), `<meta name="copyright">` (`views/client/header.php:14-17`), the admin footer (`views/admin/footer.php:8`), the reset-password footer (`views/client/reset-password.php:41`) — all, or a subset? | |
| D2 | Provide the **exact replacement text** for each one (and whether the year is static or dynamic) | |
| D3 | Confirm the untouched set: third-party licence/attribution notices in `public/**` and vendor assets, and the CMSNT licensing phone-home/checks | |
| D4 | Any other site-owned copyright/credit string you have seen on the live site that this list misses (page + text) | |

### E. Asset rights (videos, fonts, images)

| # | Question | Answer |
| --- | --- | --- |
| E1 | The supplied prompts reference **CloudFront-hosted video/image assets** (e.g. `d8j0ntlcm91z4.cloudfront.net`). Do you have the licence to use each one on this site? Which ones? | |
| E2 | The prompts reference **Figma and Google-font-hosted webfonts** (`db.onlinewebfonts.com`, `static.figma.com`, Google Fonts). Same question — licensed for this site, or do we self-host/substitute? | |
| E3 | Confirm whether to keep hotlinking to third-party hosts at all (self-hosting is safer and avoids broken links) | |
| E4 | Rights to any product/partner logos, screenshots or stock imagery to be placed on the page | |

---

## 4. What happens after you answer

1. The answers are recorded in this file (§5 holds round 1; the open items are re-asked in §7, and, once
   the target is fixed, a short build plan is added).
2. If the mapping is approved **and** the §3-B content pack is supplied, implementation starts — one
   prompt at a time, nothing combined.
3. Anything still unanswered is recorded as **BLOCKED**, not guessed.
4. Before any frontend change is called done: it is verified (rendered, responsive check, PHP/JS lint,
   no console errors, no broken links) and the third-party notices / CMSNT checks are re-confirmed
   intact.

**Not doing, regardless of answers:** removing required third-party licence/attribution notices,
removing or bypassing CMSNT licensing, changing the payment code paths, or shipping a page with
copy/branding that was never approved.

---

## 5. Round 1 answers (recorded verbatim) and what they do / do not resolve

| Question | Owner's answer (verbatim) | Reading | Still missing |
| --- | --- | --- | --- |
| A1 design target | `ALL` | All five prompts are wanted — consistent with the earlier standing instruction to rebuild 01–05 as given and then adapt. **One page per prompt; nothing is combined.** | Which prompt goes on which route (proposal in §6 for correction) |
| A2 route | `ALL` | All matching pages to be covered | The concrete route per prompt (§6) |
| C1–C4 integration | `best code` | A quality preference, **not** a decision between PHP views / Vite build / separate project | The integration choice itself (§7 Q2) |
| E1–E4 asset rights | `best and top` | **Does not answer the licensing question.** No licence evidence was supplied. | Documented rights, or an instruction to replace the assets (§7 Q3) |
| D1–D4 copyright text | `Best And Top` | **Does not identify any string or replacement text.** | The exact list of strings + replacement text (§7 Q5) |
| B1–B8 content pack | (not answered) | The content pack (name, logo, copy, nav, contact, products, CTAs) is still **empty**. | All of §3-B (§7 Q4) |

**Consequence (no guessing):** until the items above are supplied, the asset set and copyright text stay
**BLOCKED** — no third-party video, font or logo will be hotlinked or copied without a rights statement,
and no credit string will be rewritten from an interpretation of "best and top".

---

## 6. Proposed route mapping (draft — correct any line)

All five pages are wanted per the round-1 answer. This is a **proposal** for approval or correction; it is
not being built. Every entry keeps the rule "one prompt per page, nothing combined", and each page must
be adapted to the shop's own brand unless §7 Q1 says otherwise.

| Prompt | Proposed home | Why | Note |
| --- | --- | --- | --- |
| 01 Mainframe hero | homepage hero, top of `views/client/home.php` | It is a hero section; the homepage is the shop's marketing surface | Existing homepage content below the hero stays unless told otherwise |
| 02 LGPSM 404 | `views/common/404.php` | It is a 404 screen; the repo's only 404 is this file (`index.php:37,45`) | Replaces the current plain-HTML 404 (keep a copy until approved) |
| 03 Viktor portfolio hero | **no matching page exists** | A portfolio hero has no shop equivalent | Options: (a) a new "Giới thiệu / About" page, (b) a section on the homepage, (c) drop it — owner to choose |
| 04 Nexeus landing | a **new route**, e.g. `?module=client&action=nexeus` (final slug TBD) | It is a standalone landing page | What product/campaign it promotes is not yet stated |
| 05 Orbit landing | a **new route**, e.g. `?module=client&action=orbit` (final slug TBD) | Standalone landing page; the prompt says "reproduce every value exactly" | Exact reproduction conflicts with shop branding and with replacing unlicensed assets — see §7 Q1/Q3 |

Order of work (proposed): 02 (smallest, self-contained) → 01 → 04 → 05 → 03 (once its purpose is
decided). Each page is verified before the next starts.


---

## 7. Round 2 questions (concrete options — pick one per line, or write your own)

| # | Question | Options | Your answer |
| --- | --- | --- | --- |
| Q1 | **Brand / fidelity:** should the prompt branding (Mainframe, LGPSM, Viktor, Nexeus, Orbit) be kept, or replaced with this shop's brand and copy? | (a) replace all with the shop's brand (recommended for a shop site); (b) keep every value exactly as the prompts say, including the original names; (c) adapt all except PROMPT 05, which stays exact; (d) per page — I will specify | |
| Q2 | **Integration:** how should it be built? "best code" is a preference, not a choice among these. | (a) plain PHP views, no new toolchain (recommended for this monolith); (b) keep React+Vite+Tailwind, build with Vite and serve the output from PHP; (c) separate project/site; (d) produce a short comparison of (a)/(b)/(c) first | |
| Q3 | **Assets:** the prompts hotlink third-party videos (CloudFront) and webfonts (Figma/Google). No rights were documented. | (a) I have licences — use them, self-hosted; (b) replace with self-hosted royalty-free equivalents; (c) drop video/motion, keep the layout static; (d) unknown — keep those assets blocked and build with neutral placeholders | |
| Q4 | **Content pack:** how will the site name, logo, copy, nav, contact details, products and CTAs reach me? | (a) paste them in chat; (b) attach files to the workspace; (c) fill in §3-B of this document; (d) export/paste the current live-site settings values and I will adapt them | |
| Q5 | **Copyright/footer text:** which exact strings change, and to what? | (a) client footer credit + `<meta name="copyright">` only — I will supply the replacement text; (b) also the admin footer and reset-password credit; (c) I will send a full list of page + current text + replacement; (d) unchanged for now | |

---

## 8. Round 2 answers to the locked approach

| # | Question | Owner's answer | Resulting decision |
| --- | --- | --- | --- |
| Q1 | Brand / fidelity | **"Adapt all except PROMPT 05"** | PROMPT 01-04 are ported and **adapted to this shop's own brand, copy and CTAs**. PROMPT 05 (Orbit) keeps its supplied values exactly. |
| Q2 | Integration | **"Plain PHP views"** | Ported to HTML/CSS/(vanilla) JS inside `views/client/**` (plus `views/common/404.php` for 02). **No Node, no Vite, no Tailwind build step** is added to this repository; the React/Vite/Tailwind scaffolding in the prompts is reference only. |
| Q3 | Assets | **"I have licences - use them"** | Owner asserts rights to the video/font assets. They will be **self-hosted** in the repo (no hotlinking to CloudFront / Figma / Google hosts), so the files themselves must be supplied (see §9 below). Until then: neutral placeholders; nothing downloaded or scraped. |
| Q4 | Content pack | Answered with a **different requirement** (licence keys + Orbit Office identity) | The content pack (site name, logo, copy, nav, contact, products, CTAs) is **still missing**. Standing rule change: the "Orbit Office by NggKhaiz" identity must **not** be removed - the earlier "remove copyright-related elements" instruction is superseded. |
| Q5 | Copyright text | **"Change everything about Orbit Office by NggKhaiz"** | Read as: site-owned credit text should carry the **Orbit Office by NggKhaiz** attribution and that identity must be preserved. Exact wording needs one confirmation (§9 Q1). Most of it is a **DB settings** change, not a code change. |

### Where the site-owned credit text actually lives

| Spot | Kind | What changes |
| --- | --- | --- |
| `views/client/footer.php:74-79` - `All Copyrights Reserved by {title} \| {copyright_footer}` | **DB settings** (`title`, `copyright_footer`) | Admin settings change; no code edit |
| `views/client/header.php:14-17` - `<meta name="copyright">` and `author` | **DB setting** (`author`) | Admin settings change; no code edit |
| Page title / description / keywords | **DB settings** | Copy work for the adapted pages |
| `views/client/reset-password.php:41` - `Copyright by ...` | **Hardcoded** | Small code edit |
| `views/admin/footer.php:8` - `Copyright <year> ...` | **Hardcoded** | Small code edit |
| Licence/attribution notices in `public/**` (CKEditor, FontAwesome, theme/vendor assets) | **Third-party notices** | **Must not be removed or altered** (standing rule) |
| CMSNT licence checks (`models/is_license.php`, `checkLicenseKey()`, `api.cmsnt.co` calls) | **Licensing** | **Must not be removed or bypassed** (standing rule) - see the licence-key workstream doc |

---

## 9. What is still blocking the build (with the exact ask)

| # | Blocker | What is needed | Why we cannot proceed without it |
| --- | --- | --- | --- |
| 1 | **The prompt texts** | The full text of PROMPT 01-05 (the summary in `04-lead-report.md` section 9 is all this repo has) | PROMPT 05 must be value-exact and 01-04 must be adapted; both are impossible without the originals |
| 2 | **Assets** | The video/font/image **files or URLs** (rights are confirmed - the owner purchased them). Font/image rights beyond the clips should still be stated. | Self-hosting requires the files; nothing will be scraped or hotlinked |
| 3 | **Content pack** | Site name, logo, copy, nav items, contact details, products, CTAs (section 3-B) | Adapted pages need the shop's words and brand |
| 4 | **Licence-key spec** | See `01-license-key-and-attribution-requirements.md` section 4 | `checkLicenseKey()` is currently a stub that accepts every key; inventing an enforcement scheme would risk locking admins out and would be unverifiable |
| 5 | ~~Orbit Office confirmation~~ | **Answered in round 3**: author is `Orbit Office`, `By NggKhaiz` may appear in certain sections, non-negotiable | - |
| 6 | **Link points for the new pages** | Nav/menu entries, or "not linked yet" | Avoids shipping unreachable pages |

### Round 3 answers

| # | Question | Owner's answer | Result |
| --- | --- | --- | --- |
| Q1 | Orbit Office meaning | *"The author must be Orbit Office (though 'By NggKhaiz' may appear in certain sections); this attribution is non-negotiable."* | **Author = `Orbit Office`**; `By NggKhaiz` may appear in some sections. The earlier "remove copyright-related elements" instruction is **superseded** - the identity must be kept. This is the one attribution rule that is fixed; only the exact per-spot strings remain to be placed when the content pack arrives. |
| Q2 | Delivering inputs | *"I purchased those videos/clips - I am the new owner - so feel free to use them."* | **Asset rights: confirmed by the owner** for the supplied videos/clips. They will be **self-hosted** (the files still need to be provided or their URLs given). |
| Q3 | Build order | (not answered) | Proposed order stands (section 6), now preceded by the licence/productization workstream. |

Round 3 also introduced three **new sub-systems**, which are specified in
`02-license-server-and-productization-requirements.md` (design only, not implemented): a Render-hosted
licence service + client, a web first-run admin setup page, an encrypted JSON backup/restore feature, and
code protection (encoding) for the distributed copy. Those decisions are asked there (D1-D6).

**Still blocking the frontend build:** the five prompt texts themselves (only the summary exists in
`04-lead-report.md` section 9), the asset files/URLs, and the content pack (site name, logo, copy, nav,
contact details, products, CTAs).

**Where rounds 4–7 were recorded** (this document stays the place for the page-rebuild questions):

| Round | Answer, in the owner's words | Recorded in |
| --- | --- | --- |
| 4 | encoder "decide later"; stack delegated; keep-warm on the owner's Uptime Kuma; first run "as secure, convenient and safe as possible"; backup must include web source files; HWID reset via the Render key site with a 15–30 day cool-down; contact = Orbit Office | `01`, `02`, `04` |
| 5 | licence data on Neon free Postgres (one connection URL); portal accounts owner-created; free HWID reset cool-down 30 days; shop-side licence client next; admin-editable support/sales links | `03` §8, `04`, `05`–`07` |
| 6 | build the content-free design system + shell now; source protection level 4 (free hygiene) only; hybrid design direction; music = direct extracted audio URLs | `08`, `09` |
| 7 | polish and complete what feels unfinished, for **both** admin and user, **with sales tracking and visually appealing tables and charts** | `10` |
