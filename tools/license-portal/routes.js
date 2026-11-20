'use strict';
/*
 * Orbit Office — everything that answers a request: page routes, the shop-facing
 * API (/v1/activate, /v1/validate) and the administrator operations.
 *
 * The module exposes a single `dispatch()` so the server file only deals with the
 * listening socket, the socket timeouts and the HSTS decision. Guards live here as
 * small helpers (requireAdmin / requireUser / formWithCsrf / allow) so a route
 * cannot forget the permission check, the CSRF check or the throttle.
 *
 * Invariants kept by this layer:
 *  - the clear licence key is never rendered, logged or stored (record ids instead);
 *  - a customer can only ever act on a key their own account owns;
 *  - every state change is CSRF-protected, permission-checked and audited;
 *  - the shop-facing API answers JSON only and never leaks a stack trace.
 */

const crypto = require('crypto');

const {
  sha256, hashPassword, verifyPassword,
  generateKey, normalizeKey, isValidKeyFormat, maskKey,
  signToken, isValidHwid, resetState, parseExpiryDays, MAX_KEY_DAYS,
} = require('./store');
const {
  json, html, text, redirect, tooMany, methodNotAllowed, constantTimeEqual, parseCookies,
  readJsonBody, readFormBody,
} = require('./http');
const { renderPage } = require('./html');
const { SUPPORT_PLATFORMS, publicLinks, sanitiseLinks, sanitiseLabel } = require('./settings');
const { createAccount, setAccountPassword, setAccountDisabled } = require('./accounts');

const SESSION_COOKIE = 'orbit_sid';
// A throwaway hash used to keep failed logins constant-time (see the login route).
const DUMMY = (() => {
  const salt = crypto.randomBytes(16);
  const derived = crypto.scryptSync(crypto.randomBytes(16).toString('hex'), salt, 64, { N: 16384, r: 8, p: 1, maxmem: 64 * 1024 * 1024 });
  return { salt: salt.toString('hex'), hash: derived.toString('hex') };
})();
const SESSION_TTL_MS = 7 * 86400000;
const NOTICES = {
  key_revoked: 'Licence key revoked. Activations and checks with it are refused from now on.',
  key_restored: 'Licence key restored to active.',
  account_disabled: 'Account disabled and its sessions were signed out.',
  account_enabled: 'Account enabled again.',
};
const KEY_TTL_MS = 365 * 86400000;   // licence token lifetime handed to the shop (offline grace)

function createRouter({ store, cfg, limiter, ipOf }) {
  function signingSecret() {
    return cfg.signingSecret;
  }

  async function currentSession(req) {
    const cookies = parseCookies(req.headers.cookie);
    const token = cookies[SESSION_COOKIE];
    if (!token) return null;
    const session = store.getSession(sha256(token));
    if (!session) return null;
    const account = store.getAccountById(session.account_id);
    if (!account || account.disabled) return null;
    return { session, account, token };
  }

  /**
   * Set the session cookie. `maxAgeSec === 0` clears it (a falsy check here would
   * silently re-issue a week-long cookie on sign-out, so the test pins it down).
   */
  function setSessionCookie(res, token, maxAgeSec) {
    const age = maxAgeSec === undefined ? SESSION_TTL_MS / 1000 : maxAgeSec;
    const parts = [
      SESSION_COOKIE + '=' + token,
      'HttpOnly', 'SameSite=Lax', 'Path=/', 'Max-Age=' + age,
    ];
    if (age === 0) parts.push('Expires=Thu, 01 Jan 1970 00:00:00 GMT');
    if (cfg.secureCookies) parts.push('Secure');
    res.setHeader('set-cookie', parts.join('; '));
  }

  async function createSessionFor(account) {
    const token = crypto.randomBytes(32).toString('base64url');
    const session = {
      token_hash: sha256(token),
      account_id: account.id,
      csrf: crypto.randomBytes(24).toString('hex'),
      created_at: cfg.now(),
      expires_at: cfg.now() + SESSION_TTL_MS,
    };
    await store.createSession(session);
    return { token, session };
  }

  function licencePayload(keyRecord, status) {
    const expiresAt = keyRecord.expires_at || (cfg.now() + KEY_TTL_MS);
    return {
      v: 1,
      status,
      key: keyRecord.key_masked,
      account: keyRecord.account_id || 0,
      features: keyRecord.features || [],
      iat: Math.floor(cfg.now() / 1000),
      exp: Math.floor(expiresAt / 1000),
    };
  }

  /* ------------------------------------------------------------ API handlers */

  /**
   * Install metadata a shop sends with activate/validate. It is stored for support and
   * rendered escaped, so keep it short, single-line and free of control characters.
   */
  function installMeta(body) {
    const clean = (value, max) => String(value === null || value === undefined ? '' : value)
      .replace(/[\u0000-\u001f\u007f]/g, '').trim().slice(0, max);
    return { domain: clean(body.domain, 120), php: clean(body.php, 20), version: clean(body.version, 20) };
  }

  async function handleActivate(req, res) {
    if (!allow('activate:' + ipOf(req), res, 30, 60000)) return undefined;

    const body = await readJsonBody(req);
    const keyRaw = String(body.key || '');
    const hwid = String(body.hwid || '');
    if (!isValidKeyFormat(keyRaw)) return json(res, 400, { error: 'invalid_key_format' });
    if (!isValidHwid(hwid)) return json(res, 400, { error: 'invalid_hwid' });

    const record = store.getKeyByHash(sha256(normalizeKey(keyRaw)));
    if (!record) {
      await store.audit({ at: cfg.now(), actor: 'api', action: 'activate', target: maskKey(keyRaw), detail: 'unknown_key' });
      return json(res, 404, { error: 'unknown_key' });
    }

    // Atomic check-and-set: two machines racing for one key can no longer both bind.
    const result = await store.bindHwid(record.id, hwid, cfg.now());
    if (!result.ok) {
      if (result.reason === 'inactive') return json(res, 403, { error: 'revoked' });
      if (result.reason === 'expired') return json(res, 403, { error: 'expired' });
      if (result.reason === 'mismatch') {
        const state = resetState(result.record, cfg.now(), cfg.cooldownDays);
        const meta = installMeta(body);
        await store.recordHwidMismatch(record.id, hwid, cfg.now(), meta);
        await store.audit({ at: cfg.now(), actor: 'api', action: 'activate', target: record.key_masked, detail: 'hwid_mismatch' + (meta.domain ? ' ' + meta.domain : '') });
        return json(res, 403, {
          error: 'hwid_mismatch',
          message: 'This licence is bound to another machine. Use the licence portal to reset the hardware ID.',
          next_reset_allowed: state.allowed,
        });
      }
      return json(res, 404, { error: 'unknown_key' });
    }

    await store.recordKeyMeta(record.id, installMeta(body), cfg.now());
    if (result.bound) {
      await store.audit({ at: cfg.now(), actor: 'api', action: 'activate', target: record.key_masked, detail: 'bound_hwid' });
    }
    return json(res, 200, Object.assign({ token: signToken(licencePayload(result.record, 'active'), signingSecret()) }, {
      status: 'active', bound: Boolean(result.bound),
    }));
  }

  async function handleValidate(req, res) {
    if (!allow('validate:' + ipOf(req), res, 120, 60000)) return undefined;

    const body = await readJsonBody(req);
    const keyRaw = String(body.key || '');
    const hwid = String(body.hwid || '');
    if (!isValidKeyFormat(keyRaw) || !isValidHwid(hwid)) return json(res, 400, { error: 'invalid_request' });

    const record = store.getKeyByHash(sha256(normalizeKey(keyRaw)));
    if (!record) return json(res, 404, { error: 'unknown_key' });
    if (record.status !== 'active') return json(res, 403, { error: 'revoked' });
    if (record.expires_at && record.expires_at < cfg.now()) return json(res, 403, { error: 'expired' });

    const meta = installMeta(body);
    const touched = await store.touchValidation(record.id, hwid, cfg.now());
    if (!touched.ok) {
      if (touched.reason === 'mismatch') await store.recordHwidMismatch(record.id, hwid, cfg.now(), meta);
      return json(res, 403, { error: 'hwid_mismatch' });
    }
    await store.recordKeyMeta(record.id, meta, cfg.now());
    return json(res, 200, {
      status: 'active',
      token: signToken(licencePayload(touched.record, 'active'), signingSecret()),
    });
  }

  /**
   * Find the licence a form refers to.
   *
   * Forms carry the record id (the portal never stores the clear key, so the UI
   * cannot send one back). A full key is still accepted for scripted use.
   */
  function keyFromForm(form) {
    const id = String((form && form.key_id) || '').trim();
    if (id) return store.getKeyById(id);
    const raw = String((form && form.key) || '').trim();
    if (!raw) return null;
    if (!isValidKeyFormat(raw)) return { invalid_format: true };
    return store.getKeyByHash(sha256(normalizeKey(raw)));
  }

  /** Self-service HWID reset — requires a signed-in account that owns the key. */
  async function handleReset(req, res, account, form) {
    const found = keyFromForm(form);
    if (found && found.invalid_format) return json(res, 400, { error: 'invalid_key_format' });
    const record = found;
    if (!record || record.account_id !== account.id) return json(res, 404, { error: 'not_found' });
    if (record.status !== 'active') return json(res, 403, { error: 'revoked' });

    // Atomic: the cooldown check and the write happen in one mutation, so two
    // parallel reset requests cannot both consume the customer's allowance.
    const consumed = await store.consumeReset(record.id, cfg.cooldownDays, cfg.now(), { via: 'self', by: account.email });
    if (!consumed.ok) {
      if (consumed.reason === 'cooldown') {
        await store.audit({
          at: cfg.now(), actor: account.email, action: 'reset_hwid', target: record.key_masked,
          detail: 'cooldown_blocked days_remaining=' + consumed.state.days_remaining,
        });
        return json(res, 429, {
          error: 'cooldown',
          days_remaining: consumed.state.days_remaining,
          next_reset_at: consumed.state.next_reset_at,
        });
      }
      return json(res, 403, { error: consumed.reason === 'inactive' ? 'revoked' : 'not_found' });
    }

    await store.audit({ at: cfg.now(), actor: account.email, action: 'reset_hwid', target: record.key_masked, detail: 'self_service' });
    return json(res, 200, {
      status: 'reset',
      message: 'Hardware ID cleared. The licence will bind to the next machine that validates it.',
      next_reset_at: consumed.next_reset_at,
    });
  }

  /* -------------------------------------------------------------- page routes */

  const routes = {
    async 'GET /'(req, res) {
      const cfgPublic = {
        cooldownDays: cfg.cooldownDays,
        contact: cfg.contact,
        links: publicLinks({ store, contact: cfg.contact }),
        salesNote: sanitiseLabel(store.getSettings().sales_note),
        version: '1.0.0',
      };
      return html(res, 200, renderPage('landing', { cfg: cfgPublic }));
    },

    async 'GET /favicon.svg'(req, res) {
      // Inline so the portal ships no binary assets and no extra request to a CDN.
      const svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">' +
        '<rect width="64" height="64" rx="14" fill="#0b1020"/>' +
        '<circle cx="32" cy="32" r="13" fill="none" stroke="#5b8def" stroke-width="5"/>' +
        '<circle cx="47" cy="18" r="6" fill="#7ce0b8"/></svg>';
      res.writeHead(200, {
        'content-type': 'image/svg+xml',
        'cache-control': 'public, max-age=86400',
        'content-length': Buffer.byteLength(svg),
      });
      return res.end(svg);
    },

    async 'GET /robots.txt'(req, res) {
      // The portal is for customers, not search engines: keep the login page and the
      // licence endpoints out of indexes.
      return text(res, 200, 'User-agent: *\nDisallow: /\n');
    },

    async 'GET /healthz'(req, res) {
      return json(res, 200, { ok: true, time: new Date(cfg.now()).toISOString(), version: '1.0.0' });
    },

    async 'GET /login'(req, res) {
      return html(res, 200, renderPage('login', {
        error: null,
        links: publicLinks({ store, contact: cfg.contact }),
      }));
    },

    async 'POST /login'(req, res) {
      if (!allow('login:' + ipOf(req), res, cfg.loginLimit, 15 * 60000, 'html')) return undefined;
      const form = await readFormBody(req);
      const email = String(form.email || '').trim().toLowerCase();
      const password = String(form.password || '');
      const account = store.getAccountByEmail(email);
      // Always run one scrypt verification, even when the account does not exist:
      // otherwise response time reveals which addresses have accounts.
      const ok = account && !account.disabled
        ? verifyPassword(password, account.pass_salt, account.pass_hash)
        : (verifyPassword(password, DUMMY.salt, DUMMY.hash), false);
      if (!ok) {
        await store.audit({ at: cfg.now(), actor: email || 'unknown', action: 'login', target: '-', detail: 'failed' });
        return html(res, 401, renderPage('login', { error: 'Invalid email or password.' }));
      }
      const { token } = await createSessionFor(account);
      setSessionCookie(res, token);
      await store.audit({ at: cfg.now(), actor: account.email, action: 'login', target: '-', detail: 'ok' });
      // Shown back to the account holder so they can spot a sign-in they did not make.
      // Only a timestamp is kept: the portal deliberately stores no visitor IPs.
      await store.updateAccount(account.id, { last_login_at: cfg.now() });
      res.statusCode = 302;
      res.setHeader('location', '/dashboard');
      return res.end();
    },

    async 'POST /logout'(req, res) {
      const current = await currentSession(req);
      if (current) {
        const form = await formWithCsrf(req, res, current);
        if (!form) return undefined;
        await store.deleteSession(sha256(current.token));
      }
      res.setHeader('set-cookie', SESSION_COOKIE + '=; HttpOnly; SameSite=Lax; Path=/; Max-Age=0');
      res.statusCode = 302;
      res.setHeader('location', '/');
      return res.end();
    },

    async 'GET /dashboard'(req, res) {
      const current = await requireUser(req, res, 'page');
      if (!current) return undefined;
      const keys = store.listKeysByAccount(current.account.id).map((k) => ({
        id: k.id,
        masked: k.key_masked,
        status: k.status,
        bound: Boolean(k.hwid),
        bound_at: k.hwid_bound_at,
        last_seen_at: k.last_seen_at,
        expires_at: k.expires_at,
        domain: k.last_domain || null,
        reset: resetState(k, cfg.now(), cfg.cooldownDays),
      }));
      return html(res, 200, renderPage('dashboard', {
        account: current.account,
        keys,
        csrf: current.session.csrf,
        cooldownDays: cfg.cooldownDays,
        links: publicLinks({ store, contact: cfg.contact }),
        now: cfg.now(),
      }));
    },

    async 'POST /dashboard/signout-all'(req, res) {
      const current = await requireUser(req, res, 'json');
      if (!current) return undefined;
      const form = await formWithCsrf(req, res, current);
      if (!form) return undefined;
      const removed = await store.deleteSessionsByAccount(current.account.id);
      await store.audit({
        at: cfg.now(), actor: current.account.email, action: 'signout_all', target: '-',
        detail: removed + ' session(s)',
      });
      // The browser still holds the cookie; clearing it avoids a session that looks alive.
      setSessionCookie(res, '', 0);
      res.statusCode = 302;
      res.setHeader('location', '/login');
      return res.end();
    },

    async 'POST /dashboard/reset'(req, res) {
      const current = await requireUser(req, res, 'json');
      if (!current) return undefined;
      const form = await formWithCsrf(req, res, current);
      if (!form) return undefined;
      if (!allow('reset:' + current.account.id, res, 5, 3600000)) return undefined;
      return handleReset(req, res, current.account, form);
    },

    async 'GET /admin'(req, res, parsed_query) {
      const current = await requireAdmin(req, res, 'html');
      if (!current) return undefined;
      const notice = NOTICES[parsed_query ? String(parsed_query.get('notice') || '') : ''] || '';
      const keys = store.listKeys().map((k) => ({
        id: k.id,
        masked: k.key_masked,
        status: k.status,
        bound: Boolean(k.hwid),
        account: (store.getAccountById(k.account_id) || {}).email || null,
        last_seen_at: k.last_seen_at,
        expires_at: k.expires_at,
        resets: (k.hwid_resets || []).length,
        domain: k.last_domain || null,
        first_domain: k.first_domain || null,
        php: k.last_php || null,
        version: k.last_version || null,
        mismatches: k.mismatch_count || 0,
        last_mismatch_at: k.last_mismatch_at || null,
        mismatch_domain: k.last_mismatch_domain || null,
        reset: resetState(k, cfg.now(), cfg.cooldownDays),
      }));
      const accounts = store.listAccounts().map((a) => ({
        email: a.email,
        role: a.role,
        disabled: Boolean(a.disabled),
        created_at: a.created_at,
        keys: store.listKeysByAccount(a.id).length,
        self: a.id === current.account.id,
      }));
      return html(res, 200, renderPage('admin', {
        account: current.account,
        keys,
        accounts,
        csrf: current.session.csrf,
        audit: store.listAudit(50),
        notice: notice,
      }));
    },

    async 'GET /admin/settings'(req, res) {
      const current = await requireAdmin(req, res, 'html');
      if (!current) return undefined;
      return html(res, 200, renderPage('admin-settings', {
        account: current.account,
        csrf: current.session.csrf,
        links: publicLinks({ store, contact: cfg.contact }),
        salesNote: store.getSettings().sales_note || '',
        platforms: SUPPORT_PLATFORMS,
        salesNoteSavedAt: store.getSettings().updated_at || null,
        saved: false,
      }));
    },

    async 'POST /admin/settings'(req, res) {
      const current = await requireAdmin(req, res, 'json');
      if (!current) return undefined;
      const form = await formWithCsrf(req, res, current);
      if (!form) return undefined;

      const links = {};
      for (const platform of SUPPORT_PLATFORMS) {
        const value = form['link_' + platform];
        if (value && String(value).trim()) links[platform] = String(value).trim();
      }
      const { links: sanitised, errors } = sanitiseLinks(links);
      if (errors.length) {
        await store.audit({ at: cfg.now(), actor: current.account.email, action: 'update_settings', target: 'support_links', detail: 'rejected:' + errors.join(',') });
        return html(res, 400, renderPage('admin-settings', {
          account: current.account,
          csrf: current.session.csrf,
          links: Object.assign({}, publicLinks({ store, contact: cfg.contact }), links),
          salesNote: sanitiseLabel(form.sales_note),
          platforms: SUPPORT_PLATFORMS,
          errors,
          saved: false,
        }));
      }

      await store.updateSettings({
        support_links: sanitised,
        sales_note: sanitiseLabel(form.sales_note),
        updated_at: cfg.now(),
        updated_by: current.account.email,
      });
      await store.audit({ at: cfg.now(), actor: current.account.email, action: 'update_settings', target: 'support_links', detail: Object.keys(sanitised).join(',') || 'cleared' });
      return html(res, 200, renderPage('admin-settings', {
        account: current.account,
        csrf: current.session.csrf,
        links: publicLinks({ store, contact: cfg.contact }),
        salesNote: store.getSettings().sales_note || '',
        platforms: SUPPORT_PLATFORMS,
        saved: true,
      }));
    },

    async 'POST /admin/create-key'(req, res) {
      const current = await requireAdmin(req, res, 'json');
      if (!current) return undefined;
      const form = await formWithCsrf(req, res, current);
      if (!form) return undefined;
      const email = String(form.email || '').trim().toLowerCase();
      const target = store.getAccountByEmail(email);
      const fail = (error, message) => html(res, 400, renderPage('key-created', {
        key: null, email, error, message, csrf: current.session.csrf, account: current.account,
      }));
      if (!target) return fail('unknown_account', 'No account exists for that email. Create the customer account first.');
      if (target.disabled) return fail('account_disabled', 'That account is disabled. Enable it before issuing a key.');
      const expiry = parseExpiryDays(form.expires_days);
      if (!expiry.ok) {
        await store.audit({ at: cfg.now(), actor: current.account.email, action: 'create_key', target: email, detail: 'rejected:' + expiry.error });
        return fail(expiry.error, 'Expiry must be blank (never) or a whole number of days between 1 and ' + MAX_KEY_DAYS + '.');
      }
      const keyRaw = generateKey();
      const record = {
        id: crypto.randomUUID(),
        key_hash: sha256(normalizeKey(keyRaw)),
        key_masked: maskKey(keyRaw),
        account_id: target.id,
        status: 'active',
        created_at: cfg.now(),
        expires_at: expiry.days === null ? null : cfg.now() + expiry.days * 86400000,
        hwid: null,
        hwid_bound_at: null,
        hwid_resets: [],
        last_seen_at: null,
        features: [],
      };
      await store.insertKey(record);
      await store.audit({
        at: cfg.now(), actor: current.account.email, action: 'create_key', target: record.key_masked,
        detail: email + (expiry.days === null ? ' never expires' : ' expires in ' + expiry.days + 'd'),
      });
      // The clear key is shown exactly once, in this response, and never stored.
      return html(res, 200, renderPage('key-created', {
        key: keyRaw, email, error: null, csrf: current.session.csrf, account: current.account,
      }));
    },

    /* ---------------------------------------------------- account management ----
     * Accounts are created by the administrator only (no public sign-up): the owner
     * sells a key, then creates the account that will manage it. This is the only
     * way to onboard on Render's free plan, which has no shell for the CLI.
     */
    async 'POST /admin/create-account'(req, res) {
      const current = await requireAdmin(req, res, 'json');
      if (!current) return undefined;
      const form = await formWithCsrf(req, res, current);
      if (!form) return undefined;

      const email = String(form.email || '').trim().toLowerCase();
      const role = form.role === 'admin' ? 'admin' : 'user';
      let password = String(form.password || '');
      const generated = password === '';
      if (generated) password = crypto.randomBytes(12).toString('base64url');
      const back = (error, message) => html(res, 400, renderPage('account-created', {
        account: current.account, email, role, password: null, error, message, csrf: current.session.csrf,
      }));

      if (!/^[^@\s]+@[^@\s.]+\.[^@\s]{2,}$/.test(email) || email.length > 160) {
        return back('invalid_email', 'Enter a valid email address.');
      }
      if (!generated && password.length < 10) {
        return back('weak_password', 'A password you choose must be at least 10 characters (leave it blank to generate one).');
      }
      if (store.getAccountByEmail(email)) return back('account_exists', 'An account with that email already exists.');
      try {
        await createAccount(store, email, password, role);
      } catch (err) {
        return back(err && err.code === 'DUPLICATE_ACCOUNT' ? 'account_exists' : 'create_failed',
          err && err.code === 'DUPLICATE_ACCOUNT' ? 'An account with that email already exists.' : 'The account could not be created.');
      }
      await store.audit({
        at: cfg.now(), actor: current.account.email, action: 'create_account', target: email,
        detail: role + (generated ? ' generated_password' : ' owner_password'),
      });
      return html(res, 200, renderPage('account-created', {
        account: current.account, email, role, password: generated ? password : null, error: null,
        message: generated ? null : 'The account is ready. The password you chose is not shown again.', csrf: current.session.csrf,
      }));
    },

    async 'POST /admin/account-action'(req, res) {
      const current = await requireAdmin(req, res, 'json');
      if (!current) return undefined;
      const form = await formWithCsrf(req, res, current);
      if (!form) return undefined;

      const action = String(form.action || '');
      const target = store.getAccountByEmail(String(form.email || '').trim().toLowerCase());
      if (!target) return json(res, 404, { error: 'not_found' });
      const admins = store.listAccounts().filter((a) => a.role === 'admin' && !a.disabled);

      if (action === 'disable') {
        if (target.id === current.account.id) return json(res, 400, { error: 'cannot_disable_self' });
        if (target.role === 'admin' && admins.length <= 1) return json(res, 400, { error: 'last_admin' });
        await store.updateAccount(target.id, { disabled: true });
        const dropped = await store.deleteSessionsByAccount(target.id);   // sign them out at once
        await store.audit({ at: cfg.now(), actor: current.account.email, action: 'disable_account', target: target.email, detail: 'sessions_ended=' + dropped });
        return redirect(res, '/admin?notice=account_disabled');
      }
      if (action === 'enable') {
        await store.updateAccount(target.id, { disabled: false });
        await store.audit({ at: cfg.now(), actor: current.account.email, action: 'enable_account', target: target.email, detail: 'admin' });
        return redirect(res, '/admin?notice=account_enabled');
      }
      if (action === 'reset-password') {
        const password = crypto.randomBytes(12).toString('base64url');
        const { salt, hash } = hashPassword(password);
        await store.updateAccount(target.id, { pass_salt: salt, pass_hash: hash });
        const dropped = await store.deleteSessionsByAccount(target.id);   // a new password invalidates old sessions
        await store.audit({ at: cfg.now(), actor: current.account.email, action: 'reset_password', target: target.email, detail: 'sessions_ended=' + dropped });
        return html(res, 200, renderPage('account-created', {
          account: current.account, email: target.email, role: target.role, password, error: null, message: null,
          csrf: current.session.csrf, heading: 'Password reset',
        }));
      }
      return json(res, 400, { error: 'unknown_action' });
    },

    async 'POST /admin/revoke-key'(req, res) {
      const current = await requireAdmin(req, res, 'json');
      if (!current) return undefined;
      const form = await formWithCsrf(req, res, current);
      if (!form) return undefined;
      const found = keyFromForm(form);
      if (found && found.invalid_format) return json(res, 400, { error: 'invalid_key_format' });
      if (!found) return json(res, 404, { error: 'not_found' });
      const action = form.action === 'restore' ? 'restore' : 'revoke';
      await store.updateKey(found.id, { status: action === 'restore' ? 'active' : 'revoked' });
      await store.audit({
        at: cfg.now(), actor: current.account.email,
        action: action === 'restore' ? 'restore_key' : 'revoke_key',
        target: found.key_masked, detail: (store.getAccountById(found.account_id) || {}).email || '?',
      });
      return redirect(res, '/admin?notice=' + (action === 'restore' ? 'key_restored' : 'key_revoked'));
    },

    async 'POST /admin/override-reset'(req, res) {
      const current = await requireAdmin(req, res, 'json');
      if (!current) return undefined;
      const form = await formWithCsrf(req, res, current);
      if (!form) return undefined;
      const found = keyFromForm(form);
      if (found && found.invalid_format) return json(res, 400, { error: 'invalid_key_format' });
      const record = found;
      if (!record) return json(res, 404, { error: 'not_found' });
      // Admin overrides ignore the cooldown but are still atomic and audited.
      const forced = await store.consumeReset(record.id, 0, cfg.now(), { via: 'admin', by: current.account.email });
      if (!forced.ok && forced.reason !== 'cooldown') return json(res, 403, { error: forced.reason });
      await store.audit({ at: cfg.now(), actor: current.account.email, action: 'override_reset', target: record.key_masked, detail: 'admin' });
      res.statusCode = 302;
      res.setHeader('location', '/admin');
      return res.end();
    },
  };

  /* ------------------------------------------------------------- guards ----
   * One place for the three checks every state-changing route needs, so a new
   * route cannot quietly forget one of them.
   */

  /**
   * Signed-in administrator or nothing.
   * @param {'html'|'json'} refusal how the caller expects the refusal to be written
   */
  async function requireAdmin(req, res, refusal) {
    const current = await currentSession(req);
    if (!current || current.account.role !== 'admin') {
      if (refusal === 'html') html(res, 403, renderPage('denied', {}));
      else json(res, 403, { error: 'forbidden' });
      return null;
    }
    return current;
  }

  /**
   * Signed-in customer or nothing.
   * @param {'page'|'json'} refusal a page redirects to /login, an API answers 401
   */
  async function requireUser(req, res, refusal) {
    const current = await currentSession(req);
    if (!current) {
      if (refusal === 'page') redirect(res, '/login');
      else json(res, 401, { error: 'auth_required' });
      return null;
    }
    return current;
  }

  /** Read the form and verify its CSRF token; null (and a refusal) when it fails. */
  async function formWithCsrf(req, res, current) {
    const form = await readFormBody(req);
    if (!constantTimeEqual(form.csrf, current.session.csrf)) {
      json(res, 403, { error: 'csrf' });
      return null;
    }
    return form;
  }

  /**
   * Per-scope rate limit.
   * @returns {boolean} true when the request may proceed
   */
  function allow(scope, res, limit, windowMs, refusal) {
    const rl = limiter(scope, limit, windowMs);
    if (rl.allowed) return true;
    if (refusal === 'html') {
      res.setHeader('retry-after', String(Math.max(1, rl.retryAfter)));
      html(res, 429, renderPage('login', {
        error: 'Too many attempts. Please wait ' + Math.max(1, Math.ceil(rl.retryAfter / 60)) + ' minute(s) and try again.',
        links: publicLinks({ store, contact: cfg.contact }),
      }));
    } else {
      tooMany(res, rl.retryAfter, { error: 'rate_limited', retry_after: rl.retryAfter });
    }
    return false;
  }

  /** API endpoints are matched on their own because they are POST-only JSON. */
  const api = {
    '/v1/activate': handleActivate,
    '/v1/validate': handleValidate,
  };

  /**
   * Route one request.
   *
   * @returns {Promise<boolean>} true when a route handled it (the server must not
   *          answer again), false when nothing matched so the server can send 404.
   *          Deliberately independent of what the handler returns: a handler that
   *          ends the response without returning a value must not cause a second
   *          write (that produced ERR_HTTP_HEADERS_SENT during the module split).
   */
  async function dispatch(req, res, pathname, searchParams) {
    if (req.method === 'POST' && api[pathname]) {
      await api[pathname](req, res);
      return true;
    }
    // Uptime monitors (and crawlers) often probe with HEAD; serve it from GET and let
    // Node drop the body. Cheaper than answering 405 to a health check.
    const handler = routes[req.method + ' ' + pathname]
      || (req.method === 'HEAD' ? routes['GET ' + pathname] : undefined);
    if (!handler) {
      // A known path with the wrong method is a 405 (with Allow), not a 404: that
      // tells an integrator what went wrong instead of pretending the route is absent.
      const allowed = methodsFor(pathname);
      if (allowed.length) {
        methodNotAllowed(res, allowed);
        return true;
      }
      return false;
    }
    await handler(req, res, searchParams);
    return true;
  }

  /** Every method the router accepts for a path (derived from the route table). */
  function methodsFor(pathname) {
    const methods = Object.keys(routes)
      .filter((key) => key.endsWith(' ' + pathname))
      .map((key) => key.split(' ')[0])
      .concat(api[pathname] ? ['POST'] : []);
    if (methods.includes('GET') && !methods.includes('HEAD')) methods.push('HEAD');
    return methods;
  }

  return { dispatch, routes, currentSession, SESSION_COOKIE };
}

module.exports = { createRouter, SESSION_COOKIE, SESSION_TTL_MS, DUMMY, KEY_TTL_MS, NOTICES };
