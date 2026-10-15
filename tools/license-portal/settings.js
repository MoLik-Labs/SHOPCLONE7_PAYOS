'use strict';
/*
 * Orbit Office — the admin-editable support/sales links.
 *
 * The site sells licence keys, so the public contact channels are operational data:
 * the owner must be able to move to a new Telegram/Facebook/Discord account from the
 * portal without a code change. Values are validated here and escaped at render.
 */

/* --------------------------------------------------- admin-editable settings */

const SUPPORT_PLATFORMS = ['telegram', 'facebook', 'discord', 'whatsapp', 'zalo', 'email', 'website', 'docs'];
const MAX_LINKS = 8;
const MAX_URL_LEN = 300;
const MAX_LABEL_LEN = 40;

const EMAIL_RE = /^[^@\s]+@[^@\s.]+\.[^@\s]+$/;
const HOST_RE = /^[A-Za-z0-9.-]+\.[A-Za-z]{2,}(?:[/?#].*)?$/;

/**
 * Owners paste what the app hands them, not URLs: "@orbitoffice", "84 912 345 678",
 * "discord.gg/abc123". Normalising the shorthands keeps the contact card complete —
 * a silently dropped Telegram handle is exactly the failure this feature must avoid.
 * Everything that is not a web link (javascript:, data:, anything odd) is refused.
 */
function normaliseSupportLink(platform, value) {
  const p = String(platform || '').toLowerCase();
  const raw = String(value === null || value === undefined ? '' : value).trim();
  if (!raw) return null;
  if (/[\u0000-\u001f\u007f]/.test(raw)) return null;          // control characters
  if (p === 'email') {
    const bare = raw.replace(/^mailto:/i, '').trim();
    return EMAIL_RE.test(bare) ? bare : null;
  }
  const digits = raw.replace(/[\s().-]/g, '');
  if (/^https?:\/\//i.test(raw)) return /\s/.test(raw) ? null : raw;   // no spaces in a URL
  if (/^[a-z][a-z0-9+.-]*:/i.test(raw)) return null;            // mailto:, javascript:, data:, …
  if (p === 'telegram' && /^@?[A-Za-z0-9_]{4,32}$/.test(raw)) return 'https://t.me/' + raw.replace(/^@/, '');
  if (p === 'whatsapp' && /^\+?\d{8,15}$/.test(digits)) return 'https://wa.me/' + digits.replace(/^\+/, '');
  if (p === 'zalo' && /^\+?\d{8,15}$/.test(digits)) return 'https://zalo.me/' + digits.replace(/^\+/, '');
  if (p === 'discord' && /^[A-Za-z0-9-]{2,32}$/.test(raw)) return 'https://discord.gg/' + raw;
  if (HOST_RE.test(raw)) return 'https://' + raw;               // t.me/x, facebook.com/page
  return null;
}

/**
 * Validate one support link. Only http(s) URLs and email addresses are accepted, and the
 * value is stored as data (never as markup) so a banned account can be replaced quickly
 * without touching code.
 */
function validateSupportLink(platform, url) {
  const p = String(platform || '').toLowerCase();
  if (!SUPPORT_PLATFORMS.includes(p)) return { ok: false, error: 'unsupported_platform:' + p };
  const raw = String(url === null || url === undefined ? '' : url).trim();
  if (!raw) return { ok: false, error: 'empty_url' };
  if (raw.length > MAX_URL_LEN) return { ok: false, error: 'url_too_long' };
  const normalised = normaliseSupportLink(p, raw);
  if (!normalised) {
    return { ok: false, error: p === 'email' ? 'invalid_email' : 'invalid_scheme' };
  }
  if (/^https?:/i.test(normalised)) {
    try {
      const parsed = new URL(normalised);
      if (!parsed.hostname) return { ok: false, error: 'invalid_host' };
      if (!/^https?:$/.test(parsed.protocol)) return { ok: false, error: 'invalid_scheme' };
    } catch {
      return { ok: false, error: 'invalid_url' };
    }
  }
  return { ok: true, url: normalised };
}

function sanitiseLinks(input) {
  const out = {};
  const errors = [];
  if (input === null || input === undefined) return { links: out, errors };
  if (typeof input !== 'object') return { links: out, errors: ['invalid_links'] };
  const entries = Object.entries(input).slice(0, MAX_LINKS);
  for (const [platform, url] of entries) {
    if (!url || !String(url).trim()) continue;            // blank = remove the link
    const check = validateSupportLink(platform, url);
    if (check.ok) out[platform] = check.url;
    else errors.push(platform + ':' + check.error);
  }
  return { links: out, errors };
}

function sanitiseLabel(value) {
  return String(value === null || value === undefined ? '' : value).trim().slice(0, MAX_LABEL_LEN);
}

/** Links shown publicly: admin settings first, environment values as the fallback. */
function publicLinks(cfg) {
  const stored = sanitiseLinks(cfg.store.getSettings().support_links).links;
  const merged = Object.assign({}, stored);
  const env = cfg.contact || {};
  // Environment values go through the same normalisation, so CONTACT_TELEGRAM=@handle works.
  const fallbacks = { email: env.email, telegram: env.telegram, zalo: env.zalo, docs: env.docsUrl };
  for (const [platform, value] of Object.entries(fallbacks)) {
    if (merged[platform] || !value) continue;
    const check = validateSupportLink(platform, value);
    if (check.ok) merged[platform] = check.url;
  }
  return merged;
}

module.exports = {
  SUPPORT_PLATFORMS, MAX_LINKS,
  normaliseSupportLink, validateSupportLink, sanitiseLinks, sanitiseLabel, publicLinks,
};
