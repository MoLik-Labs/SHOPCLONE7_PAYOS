# Round 13 — the admin no longer loads code from strangers

Scope: every `<script>` and stylesheet the shop requests is now served from this origin, except the
payment SDKs and the two Google services the owner explicitly enables. No deployment, no schema
change, no behaviour change to payments.

Branch: `arena/01a1066c-shopclone7-payos`.

---

## 1. Why this mattered

The admin pages are the most privileged surface in the product. They list orders with customer names,
show bank and gateway credentials, edit the site's header/footer HTML, and render the login form. They
loaded **seven** different third-party resources:

| Resource | Origin | Version | Loaded on |
| --- | --- | --- | --- |
| clipboard.js | cdnjs.cloudflare.com | 2.0.6 | 20 pages, including the admin login and one legacy wallet page |
| Chart.js | cdn.jsdelivr.net | latest (`npm/chart.js`) | every admin page |
| simple-notify (css+js) | cdn.jsdelivr.net | 1.0.4 | admin + legacy storefront header |
| flatpickr (css+js) | cdn.jsdelivr.net | latest | legacy storefront header/footer |
| DataTables (css+js) + Responsive + Buttons + JSZip + pdfmake | cdn.datatables.net / cdnjs | 1.12.1 / 2.3.0 / 2.2.3 | 7 list pages |
| CodeMirror (css+js + 4 modes + theme) | cdnjs.cloudflare.com | 5.65.5 | settings |
| Roboto + Lora + Google Translate widget | fonts.googleapis.com, cdn.gtranslate.net | — | every admin page |

Three concrete problems:

1. **Scripts on the login page.** `views/admin/login.php` loaded clipboard.js it never used. A
   compromised CDN could read the password field and post it anywhere; the page had no reason to
   contain any third-party script at all.
2. **Scripts beside secrets.** The settings page loaded CodeMirror from a CDN while displaying the
   site's custom header/footer scripts and gateway keys. Same for every DataTables page.
3. **Availability.** A shop in Bắc Giang depends on cdnjs, jsdelivr and cdn.datatables.net being
   reachable and fast; each page made 3–10 cross-origin requests, and `chart.js` was requested with no
   version pin (`npm/chart.js` — whatever is latest).

## 2. What changed

All vendored files live under `public/common/vendor/<library>/`, next to their licence, and are loaded
through `BASE_URL()` so a shop installed in a subdirectory still resolves them.

| Library | Vendored file(s) | Size | Version | Licence |
| --- | --- | --- | --- | --- |
| clipboard (local implementation) | `public/common/clipboard.js` | 3 KB | — | project code |
| Chart.js | `vendor/chartjs/chart.umd.min.js` | 205 KB | 4.6.1 | MIT |
| simple-notify | `vendor/simple-notify/simple-notify.{css,min.js}` | 16 KB | 1.0.4 | MIT |
| flatpickr | `vendor/flatpickr/flatpickr.{min.css,min.js}` | 68 KB | 4.6.13 | MIT |
| DataTables core + Bootstrap 5 + Responsive | `vendor/datatables/{css,js}` | 140 KB | 1.12.1 / 2.3.0 | MIT |
| CodeMirror + xml/js/css/htmlmixed + monokai | `vendor/codemirror/**` | 244 KB | 5.65.5 (minified with esbuild) | MIT |
| Roboto variable, Latin + Vietnamese | `vendor/fonts/roboto-*.woff2` + `roboto.css` | 64 KB | 5.3.0 | Apache-2.0 |

`clipboard.js` is replaced by a 120-line local implementation (`public/common/clipboard.js`) that
supports exactly what these pages use: `new ClipboardJS(".copy")` with `data-clipboard-target`, plus
`data-clipboard-text`. It uses `navigator.clipboard` and falls back to `execCommand("copy")`.

The admin font is now a single variable Roboto file per subset. The old request asked Google for nine
Roboto weights *and* Lora, which no stylesheet in the checkout references; Lora is gone. Two woff2
files cover 100–900.

### Behaviour fixes found along the way

These were broken before this round; the asset sweep made them visible:

- **14 edit pages loaded a file that does not exist.** `public/AdminLTE3/plugins/bs-custom-file-input/…`
  was never in the checkout, so the script 404'd and `bsCustomFileInput.init()` threw a
  `ReferenceError` before the rest of the inline script ran. The markup uses plain
  `<input type="file">`; the include and the call are gone (automation, blog add/edit, blog category,
  category add/edit, coupon, currency, campaign, language, menu, product add, product API add, role).
- **Two blog pages loaded a whole DataTables bundle from a path that does not exist.**
  `public/theme/assets/js/plugins/…` (13 files) 404'd on both pages and no table was ever enhanced.
  The dead block was removed; the tables use the theme's Bootstrap styles.
- **The Buttons/export stack was dead weight on 7 list pages.** No page configures `buttons:`, and the
  theme demo that did (`public/theme/assets/js/datatables.js`) is not loaded anywhere. Core,
  Bootstrap 5 and Responsive stay; Buttons, print, html5, JSZip and pdfmake are gone (≈250 KB and four
  requests per page).
- **Google Translate ran on every admin page**, including pages where the owner never chose
  `gtranslate` mode. It is now gated on `language_type === "gtranslate"` and uses the owner's
  `gtranslate_script`, the same contract the storefront nav uses.
- **12 admin pages loaded clipboard.js without ever calling it**, and one legacy page loaded notyf
  without ever constructing it. Both are removed. With the login page, 13 pages now make zero
  clipboard/notyf requests.
- **`views/common/maintenance.php`** asked Google for Noto Sans to render a maintenance notice; it now
  uses the system font stack.

## 3. What still talks to a third party, and why

| Origin | When | Why it stays |
| --- | --- | --- |
| `www.paypal.com/sdk/js` | only on the PayPal recharge page | the SDK must talk to PayPal |
| `checkout.squadco.com/widget/squad.min.js` | only on the Squad recharge page | provider requirement |
| `www.google.com/recaptcha/api.js` | only when `reCAPTCHA_status = 1` | owner opt-in, anti-abuse |
| `www.googletagmanager.com/gtag/js` | only when `google_analytics_status = 1` | owner opt-in, analytics |
| `$CMSNT->site("gtranslate_script")` | only when `language_type = gtranslate` | owner-pasted snippet |
| `graph.facebook.com/<uid>/picture` | tool page preview | the tool's purpose is to show that avatar |
| `api.vietqr.io/v2/banks` | server-side, on bank config pages | bank list, not a browser request |

`api.cmsnt.co` (licence/domain check) and `help.cmsnt.co` (documentation links) are left untouched;
they are part of the CMSNT contract, not page assets.

## 4. The regression test

`tests/self_hosted_assets_test.php` — 41 checks, 0 failures:

- scans every `views/**/*.php` for `src="https://…"` and `<link href="https://…">` and fails on any
  origin outside the five-row allowlist above (outbound `<a href>` links, documentation and Zalo chat
  links are deliberately not counted);
- asserts the retired hosts (`cdnjs`, `jsdelivr`, `cdn.datatables.net`, `fonts.googleapis.com`,
  `fonts.gstatic.com`) appear in no `src`/`href`;
- asserts each vendored file exists, has a plausible size and contains the global it must define;
- asserts each licence file is shipped;
- asserts the admin login carries no clipboard helper;
- asserts the six pages that call `new ClipboardJS` load the local helper and that no admin page loads
  it without using it;
- asserts every path a view passes to `BASE_URL()`/`base_url()` exists on disk (this is the check that
  would have caught the AdminLTE and theme-DataTables 404s);
- asserts the admin header links the local font and preconnects to no font host, and that the footer
  gates the translate widget and hardcodes no translate CDN.

## 5. Verification

```
bash tools/dev/run.sh
  LINT 303 files, 0 parse errors
  SCAN 237 files: 0 critical, 0 high, 0 medium, 0 low
  37 suites, 1 not run (payos_service, no disposable database), 0 failures
node tools/dev/phpcl.mjs tests/self_hosted_assets_test.php
  41 checks, 0 failure(s)
```

Not run: a live browser pass over the admin (no MySQL in this sandbox). The rewritten includes were
verified by parse-checking every view, resolving every referenced path on disk, and by the static
assertions above; the pages still need one manual smoke test on staging (login → dashboard → one
DataTables page → settings → a copy button).

## 6. Limits

- The vendored versions are pinned; they do not receive upstream security patches automatically. The
  licences and versions are in the table above so an upgrade is a deliberate change.
- Roboto is shipped for Latin and Vietnamese only. Other locales fall back to the system sans stack.
- CodeMirror is minified at build time with esbuild 0.24; the unminified source is not kept in the
  repository.
