---
name: orbit-storefront
description: Build or review a customer-facing page for this shop on the Orbit design system. Use when adding or redesigning a view under views/client/, editing public/client/orbit/store.css or store.js, or regenerating the static preview.
---

# Orbit storefront

The shop sells accounts and licences. The design job is not "look impressive": it is to make a
sceptical buyer trust the price, the stock and the delivery. Everything below exists to protect that.

Read first: `docs/frontend/13-storefront-redesign-plan.md`, `views/common/store-shell.php`,
`public/client/orbit/tokens.css`, `public/client/orbit/store.css`.

## 1. The shell is the page

Every rebuilt page opens and closes the one shell. Do not include `views/client/header.php`,
`nav.php` or `footer.php` on a rebuilt page.

```php
require_once __DIR__ . "/../common/store-shell.php";

$storeShell = array(
    "title" => __("Nạp tiền") . " | " . $CMSNT->site("title"),
    "desc" => $CMSNT->site("description"),
    "nav" => store_nav_default($CMSNT, $methods),   // pass methods once; the helper caches
    "body_class" => "page-recharge",
    "noindex" => true,                               // private pages only
);
store_shell_open($storeShell);
?>
<section class="section section--tight">
  <div class="container"> … </div>
</section>
<?php
store_shell_close($storeShell);
```

Rules that the shell already enforces: fixed top bar, skip link, one `h1`, footer directory, mobile
sheet without JavaScript, self-hosted fonts, no third-party request. Do not duplicate them.

## 2. Page anatomy

| Part | Markup |
| --- | --- |
| Page heading | `<div class="page-head"><div><p class="eyebrow">…</p><h1>…</h1><p class="muted m0">…</p></div>…</div>` |
| Section | `<section class="section">` (96→64 px) or `.section--tight` (60%) |
| Card block | `.panel` + `.panel__head`/`.panel__title` + `.panel__body`; `.panel--muted` for a quiet aside |
| Table | wrap in `.table-wrap`; `.table` never escapes the panel |
| Empty state | `.empty` with `.empty__title` and one action |
| Breadcrumbs | `<nav aria-label="Đường dẫn"><ol class="breadcrumbs"><li>…</li></ol></nav>` — the component styles `li`, not the `nav` |
| Blog list | `.blog-list` → `.blog-item` (+`__media`, `__body`, `__meta`, `__title`, `__desc`, `__more`), `.blog-side` for "Đọc nhiều" |
| Money | `format_currency()` for money, `format_cash()` for counts |
| Pager | `orbit_pagination()` from `views/common/pagination.php` (never `store_pager()`: its hrefs point at the home page) |

Use the components that exist: `.btn` (`--primary`, `--ghost`, `--danger`, `--sm`, `--lg`, `--block`),
`.field`/`.input`/`.select`, `.alert--ok|warn|danger`, `.pill`, `.kv`, `.breadcrumbs`, `.segmented`,
`.stats`/`.stat`, `.progress`, `.toolbar`, `.timeline`, `.switch`, `.steps` (only for a real sequence).

## 3. Design rules (non-negotiable)

- **Light, quiet, one accent.** Accent `#0E6B4F` under ~3% of the surface. No second accent, no
  gradient washes, no violet.
- **Type**: Be Vietnam Pro, body 16 px, small 14 px, labels 13 px, never below 13. Line length < 80
  characters. Paragraphs are not 14 px.
- **One filled primary button per screen.** Secondary actions are ghost buttons or links.
- **Controls 44 px high**, forms have a visible `<label for>`, errors next to the field.
- **Spacing** uses the scale `--space-1…10` (4…128). Never invent a one-off margin.
- **Motion answers an action** (hover lift 2 px, dialog open). No autoplay, no carousel, no timed tabs.
- **Real content only.** Product names, prices, stock, balances come from the database. Never invent a
  statistic, a review, a delivery count or a "10,000 customers" line. A missing value is an honest
  empty state, not a placeholder number.
- **No photography we do not own.** Product UI, real brand icons already in `assets/img`, and flat
  colour fields. No hotlinked images, no stock photos.
- **Vietnamese copy**, sentence case, verbs on buttons ("Nạp tiền", not "Gửi"). Errors say what failed
  and what to do next; they do not apologise.

## 4. Hard technical rules

- No `style="…"` attribute anywhere. All styling lives in `public/client/orbit/*.css`.
- No CDN, no Google Fonts, no external script. The preview build fails the moment one appears.
- No `alert()`, `confirm()` or `prompt()`; use `.alert`, `.dialog` or inline status.
- Every class in the view must have a rule in `tokens.css`, `base.css`, `components.css`,
  `shell.css` or `store.css`. The preview build is the check.
- Escape output: `store_e()` for text, `BASE_URL("…")` for links. Admin-authored HTML (product
  description, manual instructions, FAQ page) is intentionally raw — say so in a comment.
- Money pages send `Cache-Control: no-store` and `X-Content-Type-Options: nosniff` when headers are
  still open.

## 5. Verify before claiming done

```bash
# 1. Render every page with sample data and run the class/asset checks
node .harness/phpcl.mjs tools/preview-build.php
# 2. Look at 1440, 768 and 390 px (the harness serves the preview over HTTP itself)
LD_LIBRARY_PATH=/tmp/al2023/lib node .harness/browser.mjs /tmp/shots \
    docs/frontend/preview/<page>.html --widths=1440,768,390
# 3. Read the report: page overflow must be 0 bytes, console errors 0, failed requests 0
#    (file:// shots always show a blocked font request — that is the harness, not the page)
# 4. Run the page test and the whole sweep
node .harness/phpcl.mjs tests/<page>_test.php
bash .harness/run.sh "$PWD" .harness/specs/all.json
```

A page is not done until the rendered HTML has: one `h1`, no inline style, no third-party host, no
PHP warning, a working empty state and a visible focus ring.

## 6. Pitfalls already paid for

- Every class needs a CSS rule; `.recharge-steps` and `.orders` both failed the class check first.
- `[hidden]` must stay `display:none !important`; the mobile sheet and payOS panel rely on it.
- A full-bleed chip row with negative margins overflows a 390 px viewport. Do not do it.
- Later media queries win silently: put the phone override after the desktop rule.
- `table` cells scroll inside `.table-wrap`; never let the page itself overflow.
- The preview is a build artifact. Regenerate it after any view/CSS change before reviewing.
- Owner HTML (FAQ, policy, manual) is not rewritten; style it with scoped `.page-content .prose …`
  rules. A plain `<h2>` inside running text inherits the page-title size unless `.prose h2` overrides it.
- Screenshot `file://` pages and the font is CORS-blocked; `.harness/browser.mjs` serves the preview
  over HTTP for exactly this reason.
- The footer legal line must name the shop, not the page title: `store_shell_close()` resolves it from
  `site("title")` (bug found while reviewing `blogs.html`).
