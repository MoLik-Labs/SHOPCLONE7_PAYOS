'use strict';
/*
 * Orbit Office — configuration.
 *
 * Every setting is read from the environment with a safe fallback. A typo must
 * never silently weaken a guard: the login throttle used to disappear entirely
 * when PORTAL_LOGIN_LIMIT was not a number (see docs/frontend/06).
 */

const path = require('path');

const DEFAULTS = {
  port: 8080,
  // Relative to this module, so running a command from another directory cannot
  // scatter data folders around the shop source.
  dataDir: path.join(__dirname, 'data'),
  cooldownDays: 30,
  loginLimit: 10,
};

/**
 * Parse a numeric environment value.
 *
 * A typo must never silently change behaviour: `PORTAL_LOGIN_LIMIT=ten` used to
 * disable the login throttle entirely, and a non-numeric cooldown locked every
 * customer out of HWID resets. Bad values fall back with a loud warning.
 */
function envInt(value, fallback, min, max, name) {
  const raw = String(value === undefined || value === null ? '' : value).trim();
  if (raw === '') return fallback;
  const n = Number(raw);
  if (!Number.isFinite(n)) {
    console.warn('[portal] ' + name + '="' + raw + '" is not a number; using ' + fallback);
    return fallback;
  }
  const whole = Math.trunc(n);
  const clamped = Math.min(max, Math.max(min, whole));
  if (clamped !== whole) console.warn('[portal] ' + name + '=' + raw + ' is outside ' + min + '..' + max + '; using ' + clamped);
  return clamped;
}

function config(env) {
  const e = env || process.env;
  return {
    port: envInt(e.PORT, 8080, 1, 65535, 'PORT'),
    dataDir: e.PORTAL_DATA_DIR || DEFAULTS.dataDir,
    signingSecret: e.PORTAL_SIGNING_SECRET || '',
    cooldownDays: envInt(e.RESET_COOLDOWN_DAYS, 30, 15, 30, 'RESET_COOLDOWN_DAYS'),
    // Rate limiting: trust X-Forwarded-For ONLY when explicitly enabled. Secure by
    // default, because a spoofed header otherwise resets every per-IP bucket.
    trustProxyHeaders: String(e.TRUST_PROXY_HEADERS || '').toLowerCase() === 'true',
    trustedProxyIps: String(e.TRUSTED_PROXY_IPS || '').split(',').map((v) => v.trim()).filter(Boolean),
    loginLimit: envInt(e.PORTAL_LOGIN_LIMIT, 10, 3, 1000, 'PORTAL_LOGIN_LIMIT'),
    secureCookies: String(e.PUBLIC_BASE_URL || '').startsWith('https://'),
    contact: {
      email: e.CONTACT_EMAIL || 'support@example.com',
      telegram: e.CONTACT_TELEGRAM || '',
      zalo: e.CONTACT_ZALO || '',
      docsUrl: e.CONTACT_DOCS_URL || '',
    },
    adminEmail: (e.PORTAL_ADMIN_EMAIL || '').trim().toLowerCase(),
    adminPassword: e.PORTAL_ADMIN_PASSWORD || '',
    accountPassword: e.PORTAL_ACCOUNT_PASSWORD || '',
    now: () => Date.now(),
  };
}

module.exports = { config, envInt, DEFAULTS };
