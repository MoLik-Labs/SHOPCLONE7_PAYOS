# Orbit Office identity pass

The generated geometric mark represents continuity around a digital item. `assets/brand/orbit-mark.png`
is the AI-generated source; `orbit-icon*.png` and the two transparent wordmarks are resized/adapted
outputs for small screens and light/dark admin surfaces. Trademark uniqueness and legal clearance have
not been established. The logo has no external network dependency. The public shell keeps the configured
shop name beside the fallback icon; a custom logo or favicon set by the shop still takes precedence.

The fallback replaces only known legacy asset paths bundled in this checkout (and missing values), not
all user uploads or every possible string. It does not modify the shop database, delete historical
assets or force a custom shop to use Orbit. A deployment may have other CMSNT logos, site titles,
footer text, banners, emails, uploaded content or external URLs in its database; audit these in a
staging copy and change them via owner settings. The admin sidebar uses the Orbit identity; the admin
footer, dashboard community promotions, supplier recommendation carousel and one security-email link
have been cleaned up. The supplier-management controls remain. Other vendor support links and the
licence screen may remain where they have operational or legal relevance. Internal `$CMSNT` variable
names and the licence implementation are intentionally untouched; provenance and attribution are kept
in project documentation. This is not a licence bypass or a claim that all vendor references are gone.

## Verification

- `tests/brand_identity_test.php` checks known legacy fallbacks, escaping, and preservation of custom
  logos/favicons. `tests/sidebar_links_test.php` renders the real sidebar with fixture data.
- The full gate reports 68 suites, 8 NOT RUN and 0 failures; no database-dependent or live provider
  checks are implied.
- Static fixture browser audit: 38 pages at desktop/mobile widths, zero failures; interaction 10/10
  and checkout 13/13. An additional local browser probe rendered the admin sidebar with fixture data:
  the icon/wordmark loaded at 390 and 1440 px, in light and dark modes. This is not a full logged-in
  admin or live database test.
- No production data, schema change, payment, licence call or deployment was performed. Full release
  readiness remains subject to the open database, payment, integration and concurrency gates already
  documented in the repository.
