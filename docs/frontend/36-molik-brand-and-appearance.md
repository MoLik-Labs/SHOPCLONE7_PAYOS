# MoLik Studio identity and appearance

The previous Orbit artwork is superseded for the application chrome. The new mark is a geometric M:
`assets/brand/molik-concept.png` is the AI-generated concept and the SVGs are manually adapted
production artwork; `molik-wordmark.png` is an export of the dark-on-light wordmark. Trademark or
similarity clearance has not been established. Brand name, favicon, and studio credit shown in the
rebuilt storefront are code-owned. The admin sidebar, footer and remaining legacy client chrome use
MoLik Studio as well. The settings page no longer presents a mutable author and refuses these known
branding setting keys; the theme upload handler no longer accepts logo/favicon uploads. Existing
settings and uploaded files are not deleted. Operational shop name, contact information and business
links still serve the shop and are not replaced with invented studio links. No official MoLik Studio
URL was provided, so no new external attribution link was invented.

This is a UI and ordinary-settings restriction, **not tamper-proof enforcement**: a hosting operator
or administrator with arbitrary HTML/JS/custom-code or database/file access may change rendered output.
Some legacy admin endpoints can still write arbitrary settings; these should be separately audited,
particularly before granting administration access. Existing content, provider integrations, docs,
CMSNT licence verification and provenance are not automatically renamed or removed. Never alter licence
checks or payment protections as part of branding. Storefront brand settings are deliberately ignored,
so operators must rehearse this change and approve the visual change on a staging copy before release.

Appearance selector at the storefront footer provides Original Light, Catppuccin-inspired Latte,
Catppuccin-inspired Mocha and Forest Dark. Latte/Mocha use adapted colours to maintain control contrast,
not a claim of exact upstream theme implementation. The choice is stored only in the current browser's
`localStorage`, not in an account or global site setting. Without JS the server default remains usable;
if storage is blocked the choice is temporary. Values are restricted to a fixed allowlist. This does
not change the old admin theme switcher or provide a shop-wide default picker.

## Test evidence and limits

- Full gate: 68 suites, 8 NOT RUN, 0 failures (synthetic PHP-WASM; no live MySQL or provider).
- 38 preview pages × 2 viewports: 0 browser-audit failures; 10 interaction and 13 checkout fixture checks.
- `tests/manual/theme_browser.cjs`: four themes at 390/1440 pixels; selected five foreground/background
  token pairs each meet 4.5:1; persistence, invalid values and denied storage checked. This is not a
  complete WCAG audit of every control, page, state or image.
- Admin fixture browser probe loaded the correct logo at 390/1440 pixels in light/dark modes. It did not
  test a real admin session, database or all admin themes. Licence fixture 85/0; only human-readable
  support copy changed, not enforcement or transport.
- The live preview is static with synthetic content and inert links; it is not a deployed store.
