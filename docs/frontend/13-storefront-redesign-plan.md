# 13 — Storefront redesign plan (taste-skill v2.5, round 9)

Status: **approved direction, phase 1 (home) in progress**. No deployment, no payment code touched,
no content invented. This document is the plan the owner asked for after the round-9 questions.

## 1. The five answers this plan is built on

| # | Question | Owner's answer | What it means here |
| --- | --- | --- | --- |
| 1 | Scope | “Tất cả mọi thứ … check all bugs thật kĩ – deeper debugs all” | Every customer surface and the admin chrome are in scope; the whole PR is re-tested at the end, not just the new page. |
| 2 | Content | **Keep database content** | No invented brand, no stock photos of people, no fake reviews or “4.5★ (3 reviews)”. Copy that is not in the DB is limited to factual interface labels. Missing content is a visible, labelled gap — never filler. |
| 3 | Direction | **Light, trustworthy storefront** | Warm paper ground, near-black ink, one deep-emerald accent, crisp 1px lines, generous type. Dark becomes an optional grade, not the default. |
| 4 | Media | **Real UI + real brand icons** | Visuals are the shop’s own screens: product cards from real rows, the order flow, the buyer’s history. Real provider marks (Google/GitHub/payment methods) keep their own colours; no drawn imitations, no stock photography, no hotlinks. |
| 5 | Rollout | **Home first to lock the direction** | Phase 1 ships the design system + home + a static preview. The owner approves that page; then the rest is converted in batches. |

## 2. What “taste-skill” changes in practice

The skill is written for marketing sites; this is a shop with accounts, balances and order tables. The
rules are adapted, not copied blindly:

| Rule | How it is applied here |
| --- | --- |
| One system, one section per screen | One token set, one grid, one button shape, one card recipe. The home is planned as whole screens; app pages (tables, forms) use the same rhythm and components without pretending to be landing pages. |
| Equal left/right edges | One `.container` (1200px + 24px gutter); every section, header and footer aligns to it. |
| Type scale + spacing scale | 4→128px spacing, 13/14/16/18/22/28/36/48/56 type. No page invents a value. |
| Real content only | Prices, names, stock, categories, contact details and payment switches come from the database. A missing value is omitted, not invented. |
| One accent | Deep emerald for the single primary action, links and focus; everything else is neutral. Never violet. |
| Motion | 140–200ms, transform/opacity only, hover lift 2px, photo zoom 1.02, one hero entrance, `prefers-reduced-motion` honoured. No carousels, no scroll-jacking, no autoplay. |
| Performance budget | Transfer < 1500 KiB, JS < 450 KiB, images < 1200 KiB, ≤ 2 font families, no render-blocking third party. Measured on the built page. |
| Accessibility | Visible focus ring, 44px controls, labelled fields, heading order, skip link, contrast ≥ 4.5:1 for text. |
| No AI tells | No emoji as icons, no gradient text, no glassmorphism, no fake stats/testimonials/logos, no “Lorem”, no invented claims. |

## 3. Design tokens (v3)

| Token group | Values |
| --- | --- |
| Ground | `--bg #FBF9F4` (warm paper), `--surface #FFFFFF`, `--surface-2 #F3EFE6`, `--surface-3 #EAE4D7` |
| Ink | `--text #171512`, `--text-muted #5D584E`, `--text-faint #857F72` |
| Line | `--border #E3DDD0`, `--border-strong #C9C1B1` |
| Accent | `--accent #0E6B4F`, `--accent-strong #0A5540`, `--accent-soft #E5F1EB`, on-accent white |
| State | ok `#0F7A4E`, warn `#A15C07`, danger `#B42318`, info `#1D4ED8` |
| Type | Be Vietnam Pro (self-hosted, OFL), 400/500/600/700; system fallback |
| Shape | 16px cards, 12px panels, 8px controls, pill for chips |
| Rhythm | header 64px, section padding 96/64 (48/40 mobile), container 1200px |

Be Vietnam Pro is added as four weights × two subsets (latin + vietnamese), 8 woff2 files, ~130 KiB
total. No font CDN, no Google Fonts request.

## 4. Page inventory and batches

### Phase 1 — lock the direction (this round)
| Page | Work |
| --- | --- |
| `views/client/home.php` | Full rewrite: real categories, real products, real stock (batched query), search, sort, pagination, purchase modal preserved, honest empty states, no fake ratings. |
| `views/common/orbit-shell.php` | New light shell: skip link, brand, nav, search, account/balance, footer directory, legal row. |
| `public/client/orbit/*` | Tokens v3, base rhythm, components (product card, chips, hero, steps, trust, footer), storefront composition layer. |
| `tools/preview-build.php` | Renders the real home with fixtures into `docs/frontend/preview/home.html`; screenshots at 1440 and 390. |

### Phase 2 — customer account and purchase path
login, register, forgot/reset password, verify 2FA/OTP, product, purchase modal, payment, product
orders/history, profile, security, favorites, logs, transactions, affiliates.

### Phase 3 — money in
recharge-* (bank, card, momo, crypto, paypal, perfectmoney, toyyibpay, squadco, flutterwave, payOS,
GachtheFast card/bank). Every page keeps its existing disabled-by-default guards; only the shell,
forms and tables change.

### Phase 4 — content and support
blogs/blog, faq, policy, contact, document-api, tool pages, 404.

### Phase 5 — admin
Shared chrome (header/sidebar/footer), dashboard, products/orders/users, sales (already Orbit), settings
(including the social-login tab), payment configuration. Same tokens and components; admin keeps its
denser table rhythm.

### Phase 6 — deep debug
Lint, all suites, the frontend audit tool, `node --check`, rendered-page checks (balanced tags, no
leaked PHP, no third-party request, no empty href), 1440/390 screenshots, keyboard tab order, console
errors, and a security pass over every file touched.

## 5. Home page composition (the page to approve)

| Screen | Content | Real data source |
| --- | --- | --- |
| 1. Hero | Shop name (h1), the database description as the lead, two actions, and a stack of the first three real products with prices and stock. No invented claim. | `settings.title`, `settings.description`, `products` |
| 2. Categories | Category cards with the uploaded icon (or a monogram when none), product count. Hidden when no categories. | `categories`, `products` |
| 3. Catalogue | Filter chips, search, sort, product grid, pagination. Each card: category, name, short description, price/discount, stock pill, sold count when the shop shows it. | `products`, batched `product_stock` |
| 4. How it works | Three factual steps of this shop’s existing flow; no delivery-time promise. | interface copy |
| 5. Payment methods | Only the methods this shop has switched on, each linking to its recharge page. Hidden when none. | `*_status` settings + payOS/GTF gates |
| 6. Support + close | Contact details from settings, support links, legal row, existing copyright setting, Orbit Office credit. | `settings`, `footer_card` |

Admin notices (`notice_home`, popup) keep working but are visually integrated, not floating popups.
The legacy hotline/Zalo floating widgets are **not** carried into the new shell (they are fixed overlays
and duplicate the support block); the settings remain untouched and Phase 3 decides per-widget.

## 6. Content rules (hard)

1. Never render a rating, review count, sold count or “trusted by N” that is not in the database.
   The current home hardcodes “4.5★ / (3 Reviews)” — that is removed.
2. Never invent a headline claim (“24/7”, “instant”, “cheapest”, “100% safe”). The hero uses the
   shop’s own title/description; steps describe what the code actually does.
3. Payment logos are only shown when the corresponding method is enabled.
4. Every placeholder visible to a buyer is a labelled gap, and the build fails if one is left.
5. Keep the owner’s identity: “Orbit Office” credit, existing copyright setting, CMSNT notices.

## 7. Acceptance criteria

- Home renders with zero PHP notices/warnings on PHP 7.4; no leaked PHP; valid, balanced HTML.
- No third-party request from the page (fonts, CSS, JS, images all self-hosted).
- Every product card shows the real price; stock is computed in **one** query for the whole grid.
- Search, category filter, sort and pagination all work with GET and keep focus/URLs stable.
- The purchase modal still opens and completes through the existing endpoint unchanged.
- 1440×900 and 390×844: no horizontal overflow, no clipped text, hero alone on the first screen,
  every tap target ≥ 44px, contrast checked.
- Keyboard: skip link, nav, filters, cards and modal reachable in reading order with a visible ring.
- All existing suites stay green; new tests cover the home data path and the shell.
- Preview + screenshots committed for review; no real customer data in them.

## 8. What is deliberately not in this plan

- No new business claim, brand name, logo or photography.
- No change to payment logic, payOS, GachtheFast guards or CMSNT licensing.
- No deploy, migration or enablement.
- No JavaScript framework or build step; plain PHP + CSS + small vanilla JS.
- No removal of third-party licences/attribution.

## 9. Phase 1 evidence — home (2026-11-16)

Everything below ran against this checkout, not against a description of it. The PHP harness is
`.harness/run.sh` (php-wasm 7.4, local only, git-excluded); the browser is headless Chromium driven by
`.harness/shot.mjs` over the **static preview**, so measurements describe the same CSS/HTML the shop
serves, with sample rows instead of live ones.

| Check | Result |
| --- | --- |
| PHP parse, every tracked + untracked file | `LINT 279 files, 0 parse errors` |
| Suites (16) | storefront 36, chart 99, sales_stats 118, sales_pages 61, pagination 36, styleguide 35, sales_scoping 27, sidebar 21, orbit_shell 32, music 16, licence 81, gtf 124, payOS 45 checks, oauth 120, settings_cache 15, oauth_pages 33 — **0 failures** |
| Preview build | `php tools/preview-build.php`: home + sales + purchase history + styleguide, asset check OK, `class check: 227 used, all defined` |
| Overflow 1440 / 768 / 390 | 0 px horizontal overflow at all three widths |
| Network from the home page | 17 requests, all local (document, 5 CSS, 1 deferred JS, 8 woff2, 1 svg); **no third-party host** |
| Page weight | CSS 77.6 KB, JS 3.6 KB (store.js only), fonts 135.6 KB total (only the used latin/vietnamese pairs download) |
| Console | no errors, no page errors |
| A11y snapshot | 1 `h1`, no heading jumps, no duplicate ids, every `img` has `alt`, every field has a label, no empty links/buttons |
| Mobile menu | opens (`aria-expanded` true, sheet 385 px), Escape closes, focus ring visible on the first Tab |
| Theme | light is the default; `[data-theme="dark"]` still renders (admin/styleguide use it) |

**Screenshots** (committed under `docs/frontend/preview/`): `home-1440.png`, `home-768.png`,
`home-390.png`, `menu-open-390.png`, plus the admin sales, purchase-history and styleguide pages.

### What changed on the home screen in this phase

- `components.css` moved from the v2 dark component set to v3 light: outline secondary buttons,
  44 px minimum targets, 48 px fields with visible labels, hairline cards, sticky table headers,
  readable alerts, pagination and segmented controls at 44 px, scrolling dialogs.
- The main landmark is full-bleed so alternating `.band` sections own their containers; the hero is
  top-aligned, carries one filled primary and one outline action, and lists five real products.
- Category cards became rows on a phone and fill the row on desktop; the catalogue is two cards per
  line at 390 px, three at 768 px, four at 1440 px; the sort form no longer forces the chips sideways.
- `--text-faint` was darkened to `#6e695c` (5.2:1 on `--bg`) — it was 3.78:1 and failed for small text.
- `store_payment_methods()` is memoised: the nav, the top-bar button and the payment grid used to ask
  the same question three times (`payment_manual` was read three times per request; now once).
- The top-bar “Nạp tiền” button now points at the first enabled method instead of a hardcoded
  `?action=recharge-bank`.
- The styleguide’s theme switch starts on Light to match the new default.

### Still not done in phase 1

- Home is reviewed from sample rows: the live database, a real payOS/GTF transaction and the purchase
  modal in a browser remain **NOT RUN** (no shop installation in this sandbox).
- LCP/CLS are not claimed: the preview is local files, so paint timings (FCP 147 ms) say nothing about
  a real network. CLS was not measured.
- `table.js`, `chart.css/js`, `music-player.css/js` are unchanged this phase and still carry the older
  grade; they are on the phase 5 list with the admin pages.
- Hover states were verified from computed styles and screenshots, not with a real pointer on a device.
