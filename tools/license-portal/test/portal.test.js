'use strict';
/*
 * Orbit Office licence portal — test suite.
 * Run: node --test tools/license-portal/test/portal.test.js
 *
 * Everything runs against a temporary data directory and a loopback server:
 * no network, no real keys, no production state.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');

const storeLib = require('../store');
const { createServer, config, createAccount, bootstrapAdmin } = require('../server');

const SECRET = 'test-secret-'.padEnd(48, 'x');

function tmpDir() {
  return fs.mkdtempSync(path.join(os.tmpdir(), 'orbit-portal-test-'));
}

async function startPortal(overrides) {
  const env = Object.assign({
    PORTAL_SIGNING_SECRET: SECRET,
    PORTAL_DATA_DIR: tmpDir(),
    RESET_COOLDOWN_DAYS: '30',
    CONTACT_EMAIL: 'owner@orbit.example',
  }, overrides && overrides.env);
  const cfg = Object.assign(config(env), overrides && overrides.cfg);
  const store = new storeLib.FileStore(cfg.dataDir);
  await store.init();
  const { server, limiter } = createServer(Object.assign({}, cfg, { store }));
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const base = 'http://127.0.0.1:' + server.address().port;
  return {
    base, store, cfg, limiter,
    async close() { await new Promise((r) => server.close(r)); },
    async json(method, urlPath, body, headers) {
      const res = await fetch(base + urlPath, {
        method,
        headers: Object.assign({ 'content-type': 'application/json' }, headers || {}),
        body: body === undefined ? undefined : JSON.stringify(body),
        redirect: 'manual',
      });
      return { status: res.status, body: await res.json().catch(() => null), res };
    },
    async form(urlPath, fields, headers) {
      const body = new URLSearchParams(fields || {}).toString();
      const res = await fetch(base + urlPath, {
        method: 'POST',
        headers: Object.assign({ 'content-type': 'application/x-www-form-urlencoded' }, headers || {}),
        body,
        redirect: 'manual',
      });
      const text = await res.text();
      return { status: res.status, text, res, cookie: res.headers.get('set-cookie') };
    },
    async page(urlPath, headers) {
      const res = await fetch(base + urlPath, { headers: headers || {}, redirect: 'manual' });
      return { status: res.status, text: await res.text(), res };
    },
  };
}

async function makeAccountAndKey(portal, opts) {
  const o = opts || {};
  const { salt, hash } = storeLib.hashPassword(o.password || 'Customer-Pass-1');
  const account = {
    id: crypto.randomUUID(),
    email: o.email || 'customer@example.com',
    pass_salt: salt,
    pass_hash: hash,
    role: o.role || 'user',
    created_at: Date.now(),
    disabled: false,
  };
  await portal.store.insertAccount(account);
  let key = null;
  if (o.withKey !== false) {
    key = storeLib.generateKey();
    await portal.store.insertKey({
      id: crypto.randomUUID(),
      key_hash: storeLib.sha256(storeLib.normalizeKey(key)),
      key_masked: storeLib.maskKey(key),
      account_id: account.id,
      status: 'active',
      created_at: Date.now(),
      expires_at: null,
      hwid: null,
      hwid_bound_at: null,
      hwid_resets: [],
      last_seen_at: null,
      features: [],
    });
  }
  return { account, key };
}

async function adminSession(portal) {
  await makeAccountAndKey(portal, { email: 'admin@example.com', password: 'Admin-Pass-1', role: 'admin', withKey: false });
  const login = await portal.form('/login', { email: 'admin@example.com', password: 'Admin-Pass-1' });
  const cookie = (login.cookie || '').split(';')[0];
  const page = await portal.page('/admin', { cookie });
  const csrf = (/name="csrf" value="([0-9a-f]{16,})"/.exec(page.text) || [])[1];
  return { cookie, csrf };
}

async function customerSession(portal, opts) {
  const o = opts || {};
  const made = await makeAccountAndKey(portal, Object.assign({ withKey: true }, o));
  const login = await portal.form('/login', { email: o.email || 'customer@example.com', password: o.password || 'Customer-Pass-1' });
  const cookie = (login.cookie || '').split(';')[0];
  const page = await portal.page('/dashboard', { cookie });
  const csrf = (/name="csrf" value="([0-9a-f]{16,})"/.exec(page.text) || [])[1];
  return { cookie, csrf, ...made };
}

/* ------------------------------------------------------------ pure functions */

test('licence keys: format, checksum, masking, normalisation', () => {
  const key = storeLib.generateKey();
  assert.match(key, /^ORBIT(-[0-9A-HJKMNP-TV-Z]{5}){4}-[0-9A-F]{2}$/);
  assert.equal(storeLib.isValidKeyFormat(key), true);
  assert.equal(storeLib.isValidKeyFormat(key.toLowerCase()), true, 'lower case is normalised');
  assert.equal(storeLib.isValidKeyFormat(key.replace(/-/g, ' ')), true, 'spaces are tolerated');

  const tampered = key.slice(0, -2) + (key.endsWith('AA') ? 'BB' : 'AA');
  assert.equal(storeLib.isValidKeyFormat(tampered), false, 'bad checksum is rejected');
  assert.equal(storeLib.isValidKeyFormat('ORBIT-AAAAA-AAAAA-AAAAA-AAAAA-AA'), false, 'forbidden letters fail');
  assert.equal(storeLib.isValidKeyFormat(''), false);

  const masked = storeLib.maskKey(key);
  assert.ok(!masked.replace(/[^0-9A-Z]/g, '').includes(storeLib.normalizeKey(key).slice(5, 25)), 'middle is hidden');
  assert.ok(masked.startsWith('ORBIT-'), 'mask keeps a recognisable prefix');
  assert.notEqual(masked, key);
});

test('licence tokens: signed, verifiable, tamper-evident', () => {
  const payload = { v: 1, status: 'active', key: 'ORBIT-ABCDE-****-****-XY', exp: Math.floor(Date.now() / 1000) + 3600 };
  const token = storeLib.signToken(payload, SECRET);
  assert.deepEqual(storeLib.verifyToken(token, SECRET), payload);

  const [body] = token.split('.');
  assert.equal(storeLib.verifyToken(body + '.' + 'A'.repeat(43), SECRET), null, 'bad signature refused');
  assert.equal(storeLib.verifyToken(token, 'another-secret-that-is-long-enough-000000'), null, 'wrong secret refused');
  assert.equal(storeLib.verifyToken('not-a-token', SECRET), null);
  const edited = Buffer.from(JSON.stringify(Object.assign({}, payload, { status: 'lifetime' }))).toString('base64url');
  assert.equal(storeLib.verifyToken(edited + '.' + token.split('.')[1], SECRET), null, 'payload edit breaks the signature');
});

test('passwords: scrypt hashing verifies and rejects', () => {
  const { salt, hash } = storeLib.hashPassword('correct horse battery staple');
  assert.equal(storeLib.verifyPassword('correct horse battery staple', salt, hash), true);
  assert.equal(storeLib.verifyPassword('wrong password', salt, hash), false);
  assert.notEqual(storeLib.hashPassword('x').hash, storeLib.hashPassword('x').hash, 'salt is random');
});

test('HWID reset: cooldown arithmetic', () => {
  const now = Date.now();
  const key = { hwid_resets: [] };
  assert.equal(storeLib.resetState(key, now, 30).allowed, true);

  key.hwid_resets.push({ at: now - 10 * 86400000 });
  const blocked = storeLib.resetState(key, now, 30);
  assert.equal(blocked.allowed, false);
  assert.equal(blocked.days_remaining, 20);

  assert.equal(storeLib.resetState(key, now + 21 * 86400000, 30).allowed, true, 'allowed after the cooldown');
  assert.equal(storeLib.resetState(key, now, 15).days_remaining, 5, 'cooldown is configurable');
});

/* ------------------------------------------------------------------- HTTP API */

test('activate: binds a key to the first HWID, then enforces it', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  const { key } = await makeAccountAndKey(portal);

  const first = await portal.json('POST', '/v1/activate', { key, hwid: 'machine-aaa-0001', domain: 'shop.example' });
  assert.equal(first.status, 200);
  assert.equal(first.body.status, 'active');
  assert.equal(first.body.bound, true);
  assert.deepEqual(storeLib.verifyToken(first.body.token, SECRET).status, 'active', 'token is signed by the portal');

  const same = await portal.json('POST', '/v1/activate', { key, hwid: 'machine-aaa-0001' });
  assert.equal(same.status, 200);
  assert.equal(same.body.bound, false, 'already bound to this machine');

  const other = await portal.json('POST', '/v1/activate', { key, hwid: 'machine-bbb-0002' });
  assert.equal(other.status, 403);
  assert.equal(other.body.error, 'hwid_mismatch');

  const unknown = await portal.json('POST', '/v1/activate', { key: storeLib.generateKey(), hwid: 'machine-ccc-0003' });
  assert.equal(unknown.status, 404);
  assert.equal(unknown.body.error, 'unknown_key');

  assert.equal((await portal.json('POST', '/v1/activate', { key: 'nonsense', hwid: 'machine-ccc-0003' })).status, 400);
  assert.equal((await portal.json('POST', '/v1/activate', { key, hwid: 'no' })).status, 400);
});

test('validate: works for the bound machine, refuses others and revoked keys', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  const { key } = await makeAccountAndKey(portal);

  await portal.json('POST', '/v1/activate', { key, hwid: 'machine-ddd-0004' });
  const ok = await portal.json('POST', '/v1/validate', { key, hwid: 'machine-ddd-0004' });
  assert.equal(ok.status, 200);
  assert.equal(ok.body.status, 'active');

  assert.equal((await portal.json('POST', '/v1/validate', { key, hwid: 'machine-eee-0005' })).status, 403);

  const record = portal.store.getKeyByHash(storeLib.sha256(storeLib.normalizeKey(key)));
  await portal.store.updateKey(record.id, { status: 'revoked' });
  const revoked = await portal.json('POST', '/v1/validate', { key, hwid: 'machine-ddd-0004' });
  assert.equal(revoked.status, 403);
  assert.equal(revoked.body.error, 'revoked');
});

test('self-service reset: requires login, respects the cooldown, rebinds later', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  const { key } = await makeAccountAndKey(portal);
  await portal.json('POST', '/v1/activate', { key, hwid: 'machine-old-0006' });

  const anonymous = await portal.form('/dashboard/reset', { key });
  assert.equal(anonymous.status, 401, 'no session, no reset');

  const login = await portal.form('/login', { email: 'customer@example.com', password: 'Customer-Pass-1' });
  assert.equal(login.status, 302);
  const cookie = login.cookie.split(';')[0];

  const dashboard = await portal.page('/dashboard', { cookie });
  assert.equal(dashboard.status, 200);
  assert.ok(dashboard.text.includes(storeLib.maskKey(key)), 'dashboard shows the masked key');
  assert.ok(!dashboard.text.includes(key), 'dashboard never shows the full key');
  const csrf = /name="csrf" value="([0-9a-f]+)"/.exec(dashboard.text)[1];

  const noCsrf = await portal.form('/dashboard/reset', { key, hwid: 'machine-new-0007' }, { cookie });
  assert.equal(noCsrf.status, 403, 'CSRF token is required');
  assert.equal(noCsrf.json, undefined);

  const reset = await portal.form('/dashboard/reset', { key, csrf }, { cookie });
  assert.equal(reset.status, 200);
  assert.ok(reset.text.includes('"status":"reset"'));

  const again = await portal.form('/dashboard/reset', { key, csrf }, { cookie });
  assert.equal(again.status, 429, 'cooldown blocks the second reset');
  assert.ok(again.text.includes('"days_remaining":30'));

  const rebind = await portal.json('POST', '/v1/activate', { key, hwid: 'machine-new-0007' });
  assert.equal(rebind.status, 200, 'the new machine binds after the reset');
  assert.equal(rebind.body.bound, true);
});

test('login: rejects bad credentials and rate-limits attempts', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  await makeAccountAndKey(portal, { withKey: false });

  const bad = await portal.form('/login', { email: 'customer@example.com', password: 'nope' });
  assert.equal(bad.status, 401);
  assert.ok(bad.text.includes('Invalid email or password'));

  const unknown = await portal.form('/login', { email: 'ghost@example.com', password: 'nope' });
  assert.equal(unknown.status, 401, 'unknown account is not distinguishable in the response');

  let last = null;
  for (let i = 0; i < 12; i += 1) {
    last = await portal.form('/login', { email: 'customer@example.com', password: 'nope' });
  }
  assert.equal(last.status, 429, 'brute force is throttled');
});

test('admin: issuing keys requires the admin role and shows the key exactly once', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  const { account: customer } = await makeAccountAndKey(portal, { withKey: false, email: 'customer@example.com' });
  await makeAccountAndKey(portal, { withKey: false, email: 'admin@example.com', role: 'admin' });

  const customerLogin = await portal.form('/login', { email: 'customer@example.com', password: 'Customer-Pass-1' });
  assert.equal(customerLogin.status, 302, 'customer sign-in succeeds');
  assert.ok(customerLogin.cookie, 'customer session cookie is set');
  const customerCookie = customerLogin.cookie.split(';')[0];
  const customerPage = await portal.page('/dashboard', { cookie: customerCookie });
  const customerCsrf = /name="csrf" value="([0-9a-f]+)"/.exec(customerPage.text)[1];
  const denied = await portal.form('/admin/create-key', { email: 'customer@example.com', csrf: customerCsrf }, { cookie: customerCookie });
  assert.equal(denied.status, 403, 'non-admin cannot issue keys');

  const adminLogin = await portal.form('/login', { email: 'admin@example.com', password: 'Customer-Pass-1' });
  assert.equal(adminLogin.status, 302, 'admin sign-in succeeds');
  const adminCookie = adminLogin.cookie.split(';')[0];
  const adminPage = await portal.page('/admin', { cookie: adminCookie });
  assert.equal(adminPage.status, 200);
  const adminCsrf = /name="csrf" value="([0-9a-f]+)"/.exec(adminPage.text)[1];

  const created = await portal.form('/admin/create-key', { email: 'customer@example.com', csrf: adminCsrf }, { cookie: adminCookie });
  assert.equal(created.status, 200);
  const issued = /class="keybox">([^<]+)</.exec(created.text)[1];
  assert.equal(storeLib.isValidKeyFormat(issued), true, 'the issued key is well formed');
  assert.equal(portal.store.getKeyByHash(storeLib.sha256(storeLib.normalizeKey(issued))).account_id, customer.id);

  assert.equal(storeLib.isValidKeyFormat(issued), true);
  const unknownAccount = await portal.form('/admin/create-key', { email: 'nobody@example.com', csrf: adminCsrf }, { cookie: adminCookie });
  assert.equal(unknownAccount.status, 400, 'keys are only issued to existing accounts');
});

/* ------------------------------------------------------------------- storage */

test('store: two writers never lose each other\'s updates (CLI while server runs)', async () => {
  const dir = tmpDir();
  const server = new storeLib.FileStore(dir);
  await server.init();
  const cli = new storeLib.FileStore(dir);
  await cli.init();

  // the CLI creates an account while the "server" instance holds a stale snapshot
  await cli.insertAccount({ id: 'acc-1', email: 'buyer@example.com', pass_salt: 'x', pass_hash: 'y', role: 'user', created_at: 1, disabled: false });

  // the server writes something else; the CLI's account must survive
  await server.audit({ at: 2, actor: 'api', action: 'activate', target: '-', detail: 'unknown_key' });
  const file = JSON.parse(fs.readFileSync(path.join(dir, 'store.json'), 'utf8'));
  assert.equal(file.accounts.length, 1, 'the CLI account was not overwritten by the server snapshot');
  assert.equal(file.audit.length, 1, 'both writes are present');

  // and a running server picks up an external write without a restart
  await cli.insertAccount({ id: 'acc-2', email: 'second@example.com', pass_salt: 'x', pass_hash: 'y', role: 'user', created_at: 3, disabled: false });
  server.data.accounts = [];              // simulate a stale in-memory snapshot
  await server.maybeReload();
  assert.ok(server.getAccountByEmail('second@example.com'), 'maybeReload picks up the external write');
  assert.equal(server.data.accounts.length, 2, 'no write is lost in either direction');
});

test('accounts: CLI creation refuses duplicates and hashes the password', async () => {
  const dir = tmpDir();
  const store = new storeLib.FileStore(dir);
  await store.init();

  const account = await createAccount(store, 'Buyer@Example.com ', 'One-Time-Pass-1', 'user');
  assert.equal(account.email, 'buyer@example.com', 'email is normalised');
  assert.equal(account.role, 'user');
  assert.equal(storeLib.verifyPassword('One-Time-Pass-1', account.pass_salt, account.pass_hash), true);

  await assert.rejects(() => createAccount(store, 'buyer@example.com', 'another', 'user'), /already exists/);
  await assert.rejects(() => createAccount(store, 'x@example.com', '', 'user'), /required/);

  const raw = fs.readFileSync(path.join(dir, 'store.json'), 'utf8');
  assert.ok(!raw.includes('One-Time-Pass-1'), 'the account password is stored hashed');
});

test('storage: keys and passwords are never written in clear', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  const { key } = await makeAccountAndKey(portal, { password: 'SuperSecret-Password-9' });
  await portal.json('POST', '/v1/activate', { key, hwid: 'machine-fff-0008' });

  const raw = fs.readFileSync(path.join(portal.cfg.dataDir, 'store.json'), 'utf8');
  assert.ok(!raw.includes(key), 'the licence key is stored hashed, not in clear');
  assert.ok(!raw.includes('SuperSecret-Password-9'), 'the password is stored hashed, not in clear');
  assert.ok(raw.includes(storeLib.sha256(storeLib.normalizeKey(key))), 'the key hash is present for lookup');
  const parsed = JSON.parse(raw);
  assert.equal(parsed.keys.length, 1);
  assert.equal(parsed.keys[0].key_masked.includes('****'), true);

  const stats = fs.statSync(path.join(portal.cfg.dataDir, 'store.json'));
  assert.equal((stats.mode & 0o077), 0, 'the store file is not world readable');
});

test('health: /healthz answers without authentication and leaks nothing', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  const res = await portal.json('GET', '/healthz');
  assert.equal(res.status, 200);
  assert.equal(res.body.ok, true);
  assert.deepEqual(Object.keys(res.body).sort(), ['ok', 'time', 'version']);
});

test('public landing page: contact details come from configuration', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  const res = await portal.page('/');
  assert.equal(res.status, 200);
  assert.ok(res.text.includes('owner@orbit.example'));
  assert.ok(res.text.includes('Orbit Office'));
  assert.ok(res.text.includes('30 days'), 'the cooldown is stated on the page');
});

test('logout: the header form carries a valid CSRF token (regression)', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  await makeAccountAndKey(portal, { withKey: false });

  const login = await portal.form('/login', { email: 'customer@example.com', password: 'Customer-Pass-1' });
  const cookie = login.cookie.split(';')[0];

  // an account with no licences must still be able to sign out
  const dashboard = await portal.page('/dashboard', { cookie });
  const csrf = /name="csrf" value="([0-9a-f]{16,})"/.exec(dashboard.text);
  assert.ok(csrf, 'a valid CSRF token is rendered in the header for a keyless account');

  const bad = await portal.form('/logout', { csrf: '' }, { cookie });
  assert.equal(bad.status, 403, 'an empty token cannot sign the session out');

  const good = await portal.form('/logout', { csrf: csrf[1] }, { cookie });
  assert.equal(good.status, 302, 'the session signs out with the real token');
});

test('support links: admin-only, validated, escaped, and live on the public page', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  await makeAccountAndKey(portal, { withKey: false, email: 'admin@example.com', role: 'admin' });
  await makeAccountAndKey(portal, { withKey: false, email: 'customer@example.com' });

  // a customer cannot change the public links
  const customerLogin = await portal.form('/login', { email: 'customer@example.com', password: 'Customer-Pass-1' });
  const customerCookie = customerLogin.cookie.split(';')[0];
  const customerPage = await portal.page('/dashboard', { cookie: customerCookie });
  const customerCsrf = /name="csrf" value="([0-9a-f]{16,})"/.exec(customerPage.text)[1];
  const denied = await portal.form('/admin/settings', { csrf: customerCsrf, link_telegram: 'https://t.me/evil' }, { cookie: customerCookie });
  assert.equal(denied.status, 403);

  const adminLogin = await portal.form('/login', { email: 'admin@example.com', password: 'Customer-Pass-1' });
  const adminCookie = adminLogin.cookie.split(';')[0];
  const settingsPage = await portal.page('/admin/settings', { cookie: adminCookie });
  assert.equal(settingsPage.status, 200);
  assert.ok(settingsPage.text.includes('Telegram'));
  assert.ok(settingsPage.text.includes('WhatsApp'), 'all channels are offered');
  const csrf = /name="csrf" value="([0-9a-f]{16,})"/.exec(settingsPage.text)[1];

  // rejected values: wrong scheme and an unsupported platform field
  const badScheme = await portal.form('/admin/settings', { csrf, link_telegram: 'javascript:alert(1)' }, { cookie: adminCookie });
  assert.equal(badScheme.status, 400);
  assert.ok(badScheme.text.includes('invalid_scheme'), 'a non-http(s) link is refused, not stored');
  const stored = portal.store.getSettings().support_links || {};
  assert.ok(!stored.telegram, 'nothing was saved on rejection');

  // a good set saves and appears publicly, escaped
  const saved = await portal.form('/admin/settings', {
    csrf,
    link_telegram: 'https://t.me/orbitoffice',
    link_facebook: 'https://facebook.com/orbitoffice',
    link_discord: 'https://discord.gg/orbit',
    link_whatsapp: 'https://wa.me/84900000000',
    link_email: 'sales@orbit.example',
    link_website: 'https://"><script>alert(1)</script>',
    sales_note: 'Replies within 24h',
  }, { cookie: adminCookie });
  assert.equal(saved.status, 400, 'the HTML-injection attempt is rejected');
  assert.ok(saved.text.includes('Some links were rejected'), 'the rejection is reported to the admin');
  assert.ok(!(portal.store.getSettings().support_links || {}).website, 'the crafted URL was not stored');

  const saved2 = await portal.form('/admin/settings', {
    csrf,
    link_telegram: 'https://t.me/orbitoffice',
    link_facebook: 'https://facebook.com/orbitoffice',
    link_discord: 'https://discord.gg/orbit',
    link_whatsapp: 'https://wa.me/84900000000',
    link_email: 'sales@orbit.example',
    sales_note: 'Replies within 24h',
  }, { cookie: adminCookie });
  assert.equal(saved2.status, 200);
  assert.ok(saved2.text.includes('Support links updated'));

  const landing = await portal.page('/');
  for (const url of ['https://t.me/orbitoffice', 'https://facebook.com/orbitoffice', 'https://discord.gg/orbit', 'https://wa.me/84900000000', 'mailto:sales@orbit.example']) {
    assert.ok(landing.text.includes(url), 'public page shows ' + url);
  }
  assert.ok(landing.text.includes('Replies within 24h'));
  assert.ok(!landing.text.includes('<script>'), 'no raw markup is rendered from settings');

  // an audit row records who changed the links
  const entry = portal.store.listAudit(5).find((a) => a.action === 'update_settings' && a.detail.includes('telegram'));
  assert.ok(entry, 'changing public links is audited');
  assert.equal(entry.actor, 'admin@example.com');

  // clearing a link hides it again
  const cleared = await portal.form('/admin/settings', { csrf, link_telegram: '', link_email: 'sales@orbit.example' }, { cookie: adminCookie });
  assert.equal(cleared.status, 200);
  const landing2 = await portal.page('/');
  assert.ok(!landing2.text.includes('t.me/orbitoffice'), 'a cleared link disappears');
});

/* ------------------------------------------------ penetration-test regressions */

test('race: two machines cannot bind the same key (atomic check-and-set)', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  const { key } = await makeAccountAndKey(portal);

  const hwidA = 'race-a-' + Date.now();
  const hwidB = 'race-b-' + Date.now();
  const [a, b] = await Promise.all([
    portal.json('POST', '/v1/activate', { key, hwid: hwidA }),
    portal.json('POST', '/v1/activate', { key, hwid: hwidB }),
  ]);
  const bothBound = a.status === 200 && b.status === 200 && a.body.bound === true && b.body.bound === true;
  assert.equal(bothBound, false, 'exactly one machine may bind: ' + a.status + '/' + b.status);

  const bound = [a, b].filter((r) => r.status === 200);
  const refused = [a, b].filter((r) => r.status === 403 && r.body.error === 'hwid_mismatch');
  assert.equal(bound.length, 1, 'one activation succeeds');
  assert.equal(refused.length, 1, 'the other is refused with hwid_mismatch');

  // and only the winning machine validates afterwards
  const winnerHwid = a.status === 200 ? hwidA : hwidB;
  const loserHwid = a.status === 200 ? hwidB : hwidA;
  assert.equal((await portal.json('POST', '/v1/validate', { key, hwid: winnerHwid })).status, 200);
  assert.equal((await portal.json('POST', '/v1/validate', { key, hwid: loserHwid })).status, 403);
});

test('race: two parallel resets cannot both consume the cooldown', async () => {
  const dir = tmpDir();
  const store = new storeLib.FileStore(dir);
  await store.init();
  const now = Date.now();
  await store.insertKey({
    id: 'k1', key_hash: 'h1', key_masked: 'ORBIT-AAAAA-****-****-AA', account_id: 'a1',
    status: 'active', created_at: now, expires_at: null, hwid: 'machine-1', hwid_bound_at: now,
    hwid_resets: [], last_seen_at: null, features: [],
  });

  const [r1, r2] = await Promise.all([
    store.consumeReset('k1', 30, now, { via: 'self', by: 'a@b.c' }),
    store.consumeReset('k1', 30, now, { via: 'self', by: 'a@b.c' }),
  ]);
  const accepted = [r1, r2].filter((r) => r.ok).length;
  assert.equal(accepted, 1, 'exactly one reset is consumed in a race');
  const blocked = [r1, r2].find((r) => !r.ok);
  assert.equal(blocked.reason, 'cooldown');

  const record = store.getKeyById('k1');
  assert.equal(record.hwid, null, 'the hardware id was cleared once');
  assert.equal(record.hwid_resets.length, 1, 'only one reset is recorded');
});

test('rate limiting: a spoofed X-Forwarded-For does not create a new bucket', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());

  let throttled = false;
  for (let i = 0; i < 14; i += 1) {
    const r = await fetch(portal.base + '/login', {
      method: 'POST',
      headers: { 'content-type': 'application/x-www-form-urlencoded', 'x-forwarded-for': '198.51.100.' + (10 + i) },
      body: 'email=ghost@example.com&password=wrong',
      redirect: 'manual',
    });
    if (r.status === 429) { throttled = true; break; }
  }
  assert.equal(throttled, true, 'rotating the header must not bypass the limiter');
});

test('rate limiting: X-Forwarded-For is honoured only when the proxy is trusted', async (t) => {
  const portal = await startPortal({ env: { TRUST_PROXY_HEADERS: 'true' } });
  t.after(() => portal.close());

  // with trust enabled, distinct forwarded addresses get distinct buckets
  let throttled = false;
  for (let i = 0; i < 14; i += 1) {
    const r = await fetch(portal.base + '/login', {
      method: 'POST',
      headers: { 'content-type': 'application/x-www-form-urlencoded', 'x-forwarded-for': '203.0.113.7, 198.51.100.' + (10 + i) },
      body: 'email=ghost@example.com&password=wrong',
      redirect: 'manual',
    });
    if (r.status === 429) { throttled = true; break; }
  }
  assert.equal(throttled, false, 'each forwarded client gets its own bucket when the proxy is trusted');
});

test('rate limiting: a trusted deployment ignores client-written XFF hops', async (t) => {
  // Behind a real proxy the client's own header is appended to, not replaced, so a
  // spoofed left-hand value must not move the client to another bucket.
  const portal = await startPortal({ env: { TRUST_PROXY_HEADERS: 'true', PORTAL_LOGIN_LIMIT: '5' } });
  t.after(() => portal.close());

  let throttled = false;
  for (let i = 0; i < 8; i += 1) {
    const r = await fetch(portal.base + '/login', {
      method: 'POST',
      headers: {
        'content-type': 'application/x-www-form-urlencoded',
        'x-forwarded-for': '10.0.0.' + i + ', 203.0.113.99',   // left hop invented, right hop appended by the proxy
      },
      body: 'email=ghost@example.com&password=wrong',
      redirect: 'manual',
    });
    if (r.status === 429) { throttled = true; break; }
  }
  assert.equal(throttled, true, 'rotating the spoofable part of the header must not reset the bucket');
});

test('body limits: an oversized body gets a 413 instead of a connection reset', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());

  const huge = JSON.stringify({ key: 'A'.repeat(200000), hwid: 'machine-xxxxxxxx' });
  const res = await fetch(portal.base + '/v1/validate', {
    method: 'POST', headers: { 'content-type': 'application/json' }, body: huge, redirect: 'manual',
  });
  assert.equal(res.status, 413, 'the caller receives a status, not a socket reset');
  const body = await res.json();
  assert.equal(body.error, 'payload_too_large');

  const form = await fetch(portal.base + '/login', {
    method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' },
    body: 'email=' + 'a'.repeat(60000) + '@example.com&password=x', redirect: 'manual',
  });
  assert.equal(form.status, 413, 'oversized form posts are refused the same way');
});

test('security headers: framing is blocked and transport security is declared', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  const res = await fetch(portal.base + '/');
  const csp = res.headers.get('content-security-policy') || '';
  assert.ok(/frame-ancestors 'none'/.test(csp), 'CSP blocks framing');
  assert.equal(res.headers.get('x-frame-options'), 'DENY');
  assert.equal(res.headers.get('x-content-type-options'), 'nosniff');

  const https = await startPortal({ env: { PUBLIC_BASE_URL: 'https://portal.example' } });
  t.after(() => https.close());
  const secured = await fetch(https.base + '/');
  assert.ok(secured.headers.get('strict-transport-security'), 'HSTS on an HTTPS deployment');
});

test('login: unknown accounts cost the same as known ones (no user enumeration)', async (t) => {
  const portal = await startPortal({ env: { PORTAL_LOGIN_LIMIT: '100' } });
  t.after(() => portal.close());
  await makeAccountAndKey(portal, { withKey: false });

  async function timeLogin(email) {
    const t0 = process.hrtime.bigint();
    await portal.form('/login', { email, password: 'wrong-password' });
    return Number(process.hrtime.bigint() - t0) / 1e6;
  }
  await timeLogin('warmup@example.com');
  await timeLogin('customer@example.com');
  const unknown = [];
  const known = [];
  for (let i = 0; i < 3; i += 1) {
    unknown.push(await timeLogin('ghost' + i + '@example.com'));
    known.push(await timeLogin('customer@example.com'));
  }
  const avg = (a) => a.reduce((x, y) => x + y, 0) / a.length;
  const ratio = avg(unknown) / avg(known);
  assert.ok(avg(known) > 20, 'the known-account path really runs scrypt: ' + avg(known).toFixed(1) + 'ms');
  assert.ok(ratio > 0.5, 'unknown-account path is not detectably cheaper: ratio ' + ratio.toFixed(2));
});

test('rate limiter releases expired buckets (no unbounded growth)', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  assert.equal(typeof portal.limiter.size, 'function');
  const before = portal.limiter.size();
  for (let i = 0; i < 1200; i += 1) portal.limiter('probe:' + i, 5, 1, Date.now());
  await new Promise((r) => setTimeout(r, 5));
  portal.limiter('trigger', 5, 1, Date.now() + 10);
  assert.ok(portal.limiter.size() < 1100, 'expired buckets are pruned: ' + portal.limiter.size());
  assert.ok(before <= 5);
});

/* ---------------------------------------- bug hunt round 2: regressions (2026-10-05) */

test('bug: the dashboard reset button works through the rendered form', async (t) => {
  // The template used to submit the masked key ("ORBIT-XXXXX-****-****-XX"), which
  // fails format validation, so the advertised self-service reset always returned 400.
  const portal = await startPortal();
  t.after(() => portal.close());
  const { cookie, key } = await customerSession(portal);
  await portal.json('POST', '/v1/activate', { key, hwid: 'machine-abcdefgh' });

  const dash = await portal.page('/dashboard', { cookie });
  const formKey = (/name="key_id" value="([^"]+)"/.exec(dash.text) || [])[1];
  const csrf = (/name="csrf" value="([0-9a-f]{16,})"/.exec(dash.text) || [])[1];
  assert.ok(formKey, 'the form carries the record id');
  assert.equal(/name="key" value=/.test(dash.text), false, 'the clear key is never rendered');

  const reset = await portal.form('/dashboard/reset', { key_id: formKey, csrf }, { cookie });
  assert.equal(reset.status, 200, 'self-service reset succeeds: ' + reset.text.slice(0, 80));

  const record = portal.store.getKeyById(formKey);
  assert.equal(record.hwid, null, 'the hardware id was cleared');
});

test('bug: the admin force-reset button works through the rendered form', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  const { key } = await customerSession(portal);
  await portal.json('POST', '/v1/activate', { key, hwid: 'machine-abcdefgh' });
  const admin = await adminSession(portal);

  const page = await portal.page('/admin', { cookie: admin.cookie });
  const keyId = (/name="key_id" value="([^"]+)"/.exec(page.text) || [])[1];
  assert.ok(keyId, 'admin table exposes the record id');
  const forced = await portal.form('/admin/override-reset', { key_id: keyId, csrf: admin.csrf }, { cookie: admin.cookie });
  assert.equal(forced.status, 302, 'admin force reset redirects back to the admin page');
  assert.equal(portal.store.getKeyById(keyId).hwid, null);
});

test('bug: a malformed cookie is not a 500', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  for (const bad of ['orbit_sid=%', 'orbit_sid=%zz', 'orbit_sid=%E0%A4%A', 'orbit_sid=ok; other=%']) {
    const page = await portal.page('/dashboard', { cookie: bad });
    assert.notEqual(page.status, 500, 'cookie ' + bad + ' must not break the page');
    assert.equal(page.status, 302, 'it is treated as "no session"');
  }
  const post = await portal.form('/login', { email: 'nobody@example.com', password: 'x' }, { cookie: 'orbit_sid=%' });
  assert.notEqual(post.status, 500);
});

test('bug: a typo in PORTAL_LOGIN_LIMIT must not disable the throttle', async (t) => {
  const portal = await startPortal({ env: { PORTAL_LOGIN_LIMIT: 'ten' } });
  t.after(() => portal.close());
  assert.equal(portal.cfg.loginLimit, 10, 'falls back to the default');
  let throttled = false;
  for (let i = 0; i < 12; i += 1) {
    const r = await portal.form('/login', { email: 'ghost@example.com', password: 'wrong' });
    if (r.status === 429) { throttled = true; break; }
  }
  assert.equal(throttled, true, 'brute force is still throttled');
});

test('bug: a typo in RESET_COOLDOWN_DAYS must not lock customers out', async (t) => {
  const portal = await startPortal({ env: { RESET_COOLDOWN_DAYS: 'thirty' } });
  t.after(() => portal.close());
  assert.equal(portal.cfg.cooldownDays, 30, 'falls back to 30 days');

  const { cookie, csrf, key } = await customerSession(portal);
  const keyId = portal.store.getKeyByHash(storeLib.sha256(storeLib.normalizeKey(key))).id;
  const first = await portal.form('/dashboard/reset', { key_id: keyId, csrf }, { cookie });
  assert.equal(first.status, 200, 'first reset is allowed');
  const second = await portal.form('/dashboard/reset', { key_id: keyId, csrf }, { cookie });
  assert.equal(second.status, 429, 'the cooldown applies straight after');
  const body = JSON.parse(second.text);
  assert.equal(Number.isFinite(body.days_remaining), true, 'days_remaining is a real number: ' + second.text);
  assert.equal(Number.isFinite(body.next_reset_at), true, 'next_reset_at is a real timestamp');
  assert.ok(body.days_remaining >= 1 && body.days_remaining <= 30, 'and stays inside the cooldown');
});

test('bug: expires_days is validated instead of silently ignored', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  const admin = await adminSession(portal);
  await makeAccountAndKey(portal, { withKey: false });

  for (const bad of ['abc', '-5', '1e9', '0.5', '3651']) {
    const r = await portal.form('/admin/create-key', { email: 'customer@example.com', expires_days: bad, csrf: admin.csrf }, { cookie: admin.cookie });
    assert.equal(r.status, 400, 'expires_days=' + JSON.stringify(bad) + ' is rejected');
    assert.match(r.text, /not created|Expiry must be/i);
  }
  assert.equal(portal.store.listKeys().length, 0, 'no key was created for a bad expiry');

  const ok = await portal.form('/admin/create-key', { email: 'customer@example.com', expires_days: '90', csrf: admin.csrf }, { cookie: admin.cookie });
  assert.equal(ok.status, 200);
  const record = portal.store.listKeys()[0];
  const days = (record.expires_at - Date.now()) / 86400000;
  assert.ok(days > 89.9 && days < 90.1, 'expiry is 90 days out, got ' + days.toFixed(2));

  const never = await portal.form('/admin/create-key', { email: 'customer@example.com', expires_days: '', csrf: admin.csrf }, { cookie: admin.cookie });
  assert.equal(never.status, 200);
  assert.equal(portal.store.listKeys()[1].expires_at, null, 'blank means no expiry');
});

test('bug: a key is not issued for a disabled account', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  const admin = await adminSession(portal);
  const { account } = await makeAccountAndKey(portal, { withKey: false });
  await portal.store.updateAccount(account.id, { disabled: true });

  const r = await portal.form('/admin/create-key', { email: 'customer@example.com', csrf: admin.csrf }, { cookie: admin.cookie });
  assert.equal(r.status, 400);
  assert.match(r.text, /disabled/i);
  assert.equal(portal.store.listKeys().length, 0);
});

test('store: recovers from a corrupt file using the backup', async (t) => {
  const dir = tmpDir();
  const store = new storeLib.FileStore(dir);
  await store.init();
  await store.insertAccount({ id: 'a1', email: 'x@example.com', pass_salt: 's', pass_hash: 'h', role: 'user', created_at: Date.now(), disabled: false });

  // force a fresh backup, then corrupt the live file the way a bad manual edit would
  const second = new storeLib.FileStore(dir);
  await second.init();
  await second.updateAccount('a1', { role: 'admin' });     // writes once (backup is fresh)
  await new Promise((r) => setTimeout(r, 5));
  await second.updateAccount('a1', { disabled: true });     // still inside the backup window
  fs.writeFileSync(path.join(dir, 'store.json'), '{"accounts":[', 'utf8');

  const recovered = new storeLib.FileStore(dir);
  await recovered.init();
  assert.ok(recovered.getAccountByEmail('x@example.com'), 'the account survived through the backup');
});

test('store: refuses to start on corruption with no usable backup', async () => {
  const dir = tmpDir();
  fs.mkdirSync(dir, { recursive: true });
  fs.writeFileSync(path.join(dir, 'store.json'), 'not json at all', 'utf8');
  const store = new storeLib.FileStore(dir);
  await assert.rejects(() => store.init(), /not valid JSON|no usable backup/);
});

test('store: duplicate accounts are refused atomically', async () => {
  const dir = tmpDir();
  const store = new storeLib.FileStore(dir);
  await store.init();
  const base = { email: 'dup@example.com', pass_salt: 's', pass_hash: 'h', role: 'user', created_at: Date.now(), disabled: false };
  const [a, b] = await Promise.allSettled([
    store.insertAccount(Object.assign({ id: '1' }, base)),
    store.insertAccount(Object.assign({ id: '2' }, base)),
  ]);
  const fulfilled = [a, b].filter((r) => r.status === 'fulfilled').length;
  assert.equal(fulfilled, 1, 'exactly one insert wins the race');
  assert.equal(store.listAccounts().length, 1);
});

test('store: --verify reports a clean file and catches injected damage', async () => {
  const dir = tmpDir();
  const store = new storeLib.FileStore(dir);
  await store.init();
  await store.insertAccount({ id: 'a1', email: 'ok@example.com', pass_salt: 's', pass_hash: 'h', role: 'user', created_at: Date.now(), disabled: false });
  await store.insertKey({ id: 'k1', key_hash: storeLib.sha256('x'), key_masked: 'ORBIT-AAAAA-****-****-AA', account_id: 'a1', status: 'active', created_at: Date.now(), hwid: null, hwid_resets: [], features: [] });
  const clean = await store.inspect();
  assert.equal(clean.problems.length, 0, 'clean file: ' + JSON.stringify(clean.problems));

  const raw = JSON.parse(fs.readFileSync(path.join(dir, 'store.json'), 'utf8'));
  raw.keys.push({ id: 'k2', key_hash: 'not-a-hash', key_masked: 'ORBIT-BBBBB-****-****-BB', account_id: 'ghost', status: 'active' });
  fs.writeFileSync(path.join(dir, 'store.json'), JSON.stringify(raw), 'utf8');
  const dirty = await store.inspect();
  assert.ok(dirty.problems.length >= 2, 'reports the orphan key and the bad hash: ' + JSON.stringify(dirty.problems));
});

test('admin: customer accounts can be created without a shell', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  const admin = await adminSession(portal);

  const created = await portal.form('/admin/create-account', { email: 'New.Customer@Example.com', role: 'user', csrf: admin.csrf }, { cookie: admin.cookie });
  assert.equal(created.status, 200);
  assert.match(created.text, /Customer account ready/);
  const password = (/class="keybox">([^<]+)</.exec(created.text) || [])[1];
  assert.ok(password && password.length >= 12, 'a one-time password is shown');

  const account = portal.store.getAccountByEmail('new.customer@example.com');
  assert.ok(account, 'the email is normalised to lower case');
  assert.equal(account.role, 'user');

  const login = await portal.form('/login', { email: 'new.customer@example.com', password });
  assert.equal(login.status, 302, 'the generated password works');

  const dupe = await portal.form('/admin/create-account', { email: 'new.customer@example.com', csrf: admin.csrf }, { cookie: admin.cookie });
  assert.equal(dupe.status, 400);
  assert.match(dupe.text, /already exists/i);

  const weak = await portal.form('/admin/create-account', { email: 'weak@example.com', password: 'short', csrf: admin.csrf }, { cookie: admin.cookie });
  assert.equal(weak.status, 400, 'a short owner-chosen password is refused');

  const badEmail = await portal.form('/admin/create-account', { email: 'not-an-email', csrf: admin.csrf }, { cookie: admin.cookie });
  assert.equal(badEmail.status, 400);
});

test('admin: disabling an account signs it out and blocks its keys', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  const { cookie, key } = await customerSession(portal);
  const other = await makeAccountAndKey(portal, { withKey: true, email: 'other@example.com' });
  assert.ok(other.account.email === 'other@example.com');
  const admin = await adminSession(portal);

  const disable = await portal.form('/admin/account-action', { email: 'customer@example.com', action: 'disable', csrf: admin.csrf }, { cookie: admin.cookie });
  assert.equal(disable.status, 302);
  assert.equal(portal.store.getAccountByEmail('customer@example.com').disabled, true);

  const page = await portal.page('/dashboard', { cookie });
  assert.equal(page.status, 302, 'the old session is gone');
  assert.equal(portal.store.listKeysByAccount(portal.store.getAccountByEmail('customer@example.com').id).length > 0, true);

  const activate = await portal.json('POST', '/v1/activate', { key, hwid: 'machine-abcdefgh' });
  assert.equal(activate.status, 403, 'a disabled account cannot activate');
  assert.equal(activate.body.error, 'revoked');

  const enable = await portal.form('/admin/account-action', { email: 'customer@example.com', action: 'enable', csrf: admin.csrf }, { cookie: admin.cookie });
  assert.equal(enable.status, 302);
  assert.equal((await portal.json('POST', '/v1/activate', { key, hwid: 'machine-abcdefgh' })).status, 200);

  const self = await portal.form('/admin/account-action', { email: 'admin@example.com', action: 'disable', csrf: admin.csrf }, { cookie: admin.cookie });
  assert.equal(self.status, 400, 'an admin cannot disable their own account');
  assert.equal(portal.store.getAccountByEmail('admin@example.com').disabled, false);
});

test('admin: a password reset invalidates the old password and sessions', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  const { cookie } = await customerSession(portal);
  const admin = await adminSession(portal);

  const reset = await portal.form('/admin/account-action', { email: 'customer@example.com', action: 'reset-password', csrf: admin.csrf }, { cookie: admin.cookie });
  assert.equal(reset.status, 200);
  assert.match(reset.text, /Password reset/);
  const password = (/class="keybox">([^<]+)</.exec(reset.text) || [])[1];
  assert.ok(password && password.length >= 12);

  const oldLogin = await portal.form('/login', { email: 'customer@example.com', password: 'Customer-Pass-1' });
  assert.equal(oldLogin.status, 401, 'the old password no longer works');
  const newLogin = await portal.form('/login', { email: 'customer@example.com', password });
  assert.equal(newLogin.status, 302, 'the new password works');
  const page = await portal.page('/dashboard', { cookie });
  assert.equal(page.status, 302, 'the old session was ended');
});

test('admin: a leaked key can be revoked and restored', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  const { key } = await customerSession(portal);
  const admin = await adminSession(portal);
  const id = portal.store.getKeyByHash(storeLib.sha256(storeLib.normalizeKey(key))).id;

  assert.equal((await portal.json('POST', '/v1/activate', { key, hwid: 'machine-abcdefgh' })).status, 200);

  const revoke = await portal.form('/admin/revoke-key', { key_id: id, action: 'revoke', csrf: admin.csrf }, { cookie: admin.cookie });
  assert.equal(revoke.status, 302);
  assert.equal(portal.store.getKeyById(id).status, 'revoked');
  const denied = await portal.json('POST', '/v1/validate', { key, hwid: 'machine-abcdefgh' });
  assert.equal(denied.status, 403, 'a revoked key stops validating');
  assert.equal(denied.body.error, 'revoked');
  assert.equal((await portal.json('POST', '/v1/activate', { key, hwid: 'other-machine-1' })).status, 403);

  const restore = await portal.form('/admin/revoke-key', { key_id: id, action: 'restore', csrf: admin.csrf }, { cookie: admin.cookie });
  assert.equal(restore.status, 302);
  assert.equal((await portal.json('POST', '/v1/validate', { key, hwid: 'machine-abcdefgh' })).status, 200);
});

test('admin actions are permission-checked and CSRF-protected', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  const customer = await customerSession(portal);
  const admin = await adminSession(portal);
  const anon = await portal.form('/admin/create-account', { email: 'evil@example.com' });
  assert.equal(anon.status, 403);
  const asCustomer = await portal.form('/admin/create-account', { email: 'evil@example.com', csrf: customer.csrf }, { cookie: customer.cookie });
  assert.equal(asCustomer.status, 403, 'a customer cannot create accounts');
  for (const path of ['/admin/create-account', '/admin/account-action', '/admin/revoke-key']) {
    const noCsrf = await portal.form(path, { email: 'customer@example.com', action: 'disable' }, { cookie: admin.cookie });
    assert.equal(noCsrf.status, 403, path + ' requires a CSRF token');
  }
  assert.equal(portal.store.getAccountByEmail('evil@example.com'), null);
});

test('safety: robots.txt and noindex headers keep the portal out of search results', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  const robots = await portal.page('/robots.txt');
  assert.equal(robots.status, 200);
  assert.match(robots.text, /Disallow: \//);
  const page = await portal.page('/');
  assert.match(page.res.headers.get('x-robots-tag') || '', /noindex/);
  const api = await portal.json('POST', '/v1/validate', { key: 'ORBIT-AAAAA-AAAAA-AAAAA-AAAAA-AA', hwid: 'machine-abcdefgh' });
  assert.match(api.res.headers.get('x-robots-tag') || '', /noindex/);
});

test('convenience: --help and the operator commands exist', async () => {
  // Resolve against this file, not the process CWD: the suite must pass whether it is
  // started from the repository root or from tools/license-portal.
  const path = require('node:path');
  const { spawnSync } = require('node:child_process');
  const serverEntry = path.resolve(__dirname, '..', 'server.js');
  const run = spawnSync(process.execPath, [serverEntry, '--help'], { encoding: 'utf8' });
  assert.strictEqual(run.status, 0, 'server.js --help exits 0 (stderr: ' + (run.stderr || '').trim() + ')');
  const out = run.stdout;
  for (const flag of ['--create-account', '--list-accounts', '--disable-account', '--reset-password', '--revoke-key', '--verify']) {
    assert.match(out, new RegExp(flag), 'help mentions ' + flag);
  }
});

test('config: refuses to start without a strong signing secret', async () => {
  assert.throws(() => createServer({ signingSecret: 'short', store: new storeLib.FileStore(tmpDir()) }),
    /PORTAL_SIGNING_SECRET must be set/);
});

test('http: a known path with the wrong method answers 405 and lists Allow', async () => {
  const portal = await startPortal();
  try {
    const wrong = await portal.json('DELETE', '/healthz');
    assert.strictEqual(wrong.status, 405);
    assert.match(String(wrong.res.headers.get('allow')), /GET/);
    // The POST-only JSON API answers the same way instead of pretending to be missing.
    const apiGet = await portal.page('/v1/activate');
    assert.strictEqual(apiGet.status, 405);
    assert.match(String(apiGet.res.headers.get('allow')), /POST/);
  } finally { await portal.close(); }
});

test('http: HEAD is served from GET (monitors probe health endpoints this way)', async () => {
  const portal = await startPortal();
  try {
    const res = await fetch(portal.base + '/healthz', { method: 'HEAD' });
    assert.strictEqual(res.status, 200);
    assert.strictEqual((await res.text()).length, 0, 'HEAD carries no body');
  } finally { await portal.close(); }
});

test('http: unknown path is HTML for a browser and JSON for an API client', async () => {
  const portal = await startPortal();
  try {
    const browser = await portal.page('/definitely-not-a-page', { accept: 'text/html,application/xhtml+xml' });
    assert.strictEqual(browser.status, 404);
    assert.match(browser.text, /Page not found/);
    assert.match(browser.text, /<html/i);

    const api = await portal.json('GET', '/definitely-not-a-page', undefined, { accept: 'application/json' });
    assert.strictEqual(api.status, 404);
    assert.deepStrictEqual(api.body, { error: 'not_found' });
  } finally { await portal.close(); }
});

test('http: favicon is served inline with a long cache', async () => {
  const portal = await startPortal();
  try {
    const res = await fetch(portal.base + '/favicon.svg');
    assert.strictEqual(res.status, 200);
    assert.match(String(res.headers.get('content-type')), /image\/svg\+xml/);
    assert.match(await res.text(), /^<svg/);
  } finally { await portal.close(); }
});

test('ux: the sign-in page shows the support links so a locked-out customer can reach the owner', async () => {
  const portal = await startPortal();
  try {
    await portal.store.updateSettings({ support_links: { telegram: 'https://t.me/orbitoffice', zalo: 'https://zalo.me/0123456789' } });
    const page = await portal.page('/login');
    assert.strictEqual(page.status, 200);
    assert.match(page.text, /https:\/\/t\.me\/orbitoffice/);
    assert.match(page.text, /https:\/\/zalo\.me\/0123456789/);
    // The owner's e-mail from the environment is still shown.
    assert.match(page.text, /owner@orbit\.example/);
  } finally { await portal.close(); }
});

test('ux: a 429 tells the caller when to retry', async () => {
  const portal = await startPortal({ cfg: { loginLimit: 1 } });
  try {
    const first = await portal.form('/login', { email: 'nobody@example.com', password: 'wrong-password' });
    assert.notStrictEqual(first.status, 429, 'first attempt is not throttled');
    const second = await portal.form('/login', { email: 'nobody@example.com', password: 'wrong-password' });
    assert.strictEqual(second.status, 429);
    assert.ok(Number(second.res.headers.get('retry-after')) >= 1, 'Retry-After header present');
    assert.match(second.text, /Please wait/i);
  } finally { await portal.close(); }
});

test('safety: the dashboard shows the last sign-in time', async () => {
  const portal = await startPortal();
  try {
    const { cookie } = await customerSession(portal);
    const page = await portal.page('/dashboard', { cookie });
    assert.strictEqual(page.status, 200);
    assert.match(page.text, /Last sign-in:/);
    // A second sign-in updates the stamp the page reports back.
    await portal.form('/login', { email: 'customer@example.com', password: 'Customer-Pass-1' });
    const account = portal.store.getAccountByEmail('customer@example.com');
    assert.ok(account.last_login_at > 0, 'last_login_at recorded');
  } finally { await portal.close(); }
});

test('safety: "sign out of all devices" ends every session of that account', async () => {
  const portal = await startPortal();
  try {
    const first = await customerSession(portal);
    const second = await portal.form('/login', { email: 'customer@example.com', password: 'Customer-Pass-1' });
    const secondCookie = second.cookie.split(';')[0];
    assert.strictEqual((await portal.page('/dashboard', { cookie: secondCookie })).status, 200);

    // The token comes from the rendered page: the UI is what a customer actually uses.
    const page = await portal.page('/dashboard', { cookie: first.cookie });
    const csrf = /name="csrf" value="([^"]+)"/.exec(page.text)[1];
    const out = await portal.form('/dashboard/signout-all', { csrf }, { cookie: first.cookie });
    assert.strictEqual(out.status, 302);
    assert.strictEqual(out.res.headers.get('location'), '/login');
    assert.match(String(out.res.headers.get('set-cookie')), /Max-Age=0/);

    for (const cookie of [first.cookie, secondCookie]) {
      const after = await portal.page('/dashboard', { cookie });
      assert.strictEqual(after.status, 302, 'session is gone: ' + cookie);
    }
    const audit = portal.store.listAudit(10).find((e) => e.action === 'signout_all');
    assert.ok(audit, 'the mass sign-out is audited');
  } finally { await portal.close(); }
});

test('safety: "sign out of all devices" needs a valid CSRF token', async () => {
  const portal = await startPortal();
  try {
    const { cookie } = await customerSession(portal);
    const bad = await portal.form('/dashboard/signout-all', { csrf: 'nope' }, { cookie });
    assert.strictEqual(bad.status, 403);
    assert.strictEqual((await portal.page('/dashboard', { cookie })).status, 200, 'session survives');
    const anon = await portal.form('/dashboard/signout-all', { csrf: 'nope' });
    assert.strictEqual(anon.status, 401);
  } finally { await portal.close(); }
});

test('settings: contact shorthands are normalised, hostile values are refused', async () => {
  const { normaliseSupportLink, validateSupportLink, sanitiseLinks } = require('../settings');
  const cases = [
    ['telegram', '@orbitoffice', 'https://t.me/orbitoffice'],
    ['telegram', 'orbitoffice', 'https://t.me/orbitoffice'],
    ['telegram', 'https://t.me/orbitoffice', 'https://t.me/orbitoffice'],
    ['whatsapp', '84 912 345 678', 'https://wa.me/84912345678'],
    ['whatsapp', '+84 912 345 678', 'https://wa.me/84912345678'],
    ['zalo', '84912345678', 'https://zalo.me/84912345678'],
    ['discord', 'abc123', 'https://discord.gg/abc123'],
    ['discord', 'discord.gg/abc123', 'https://discord.gg/abc123'],
    ['facebook', 'facebook.com/orbitoffice', 'https://facebook.com/orbitoffice'],
    ['website', 'shop.example.com', 'https://shop.example.com'],
    ['email', 'sales@orbit.example', 'sales@orbit.example'],
    ['email', 'mailto:sales@orbit.example', 'sales@orbit.example'],
  ];
  for (const [platform, input, expected] of cases) {
    assert.strictEqual(normaliseSupportLink(platform, input), expected, platform + ' ' + input);
    const check = validateSupportLink(platform, input);
    assert.strictEqual(check.ok, true, platform + ' ' + input + ' should validate');
    assert.strictEqual(check.url, expected);
  }

  for (const bad of ['javascript:alert(1)', 'data:text/html,<script>', 'file:///etc/passwd',
    'not a url at all', 'https://exa mple.com', 'jаvascript:alert(1)']) {
    assert.strictEqual(normaliseSupportLink('telegram', bad), null, 'refused: ' + bad);
    assert.strictEqual(validateSupportLink('telegram', bad).ok, false, 'refused: ' + bad);
  }
  assert.strictEqual(validateSupportLink('telegram', 'https://t.me/x\u0000').ok, false, 'control characters refused');

  // An invalid value in the batch changes nothing at all.
  const batch = sanitiseLinks({ telegram: '@orbitoffice', discord: 'javascript:alert(1)@' });
  assert.deepStrictEqual(batch.links, { telegram: 'https://t.me/orbitoffice' });
  assert.deepStrictEqual(batch.errors, ['discord:invalid_scheme']);
});

test('settings: an environment handle appears as a usable link on the public page', async () => {
  const portal = await startPortal({ env: { CONTACT_TELEGRAM: '@orbitoffice', CONTACT_EMAIL: '' } });
  try {
    const page = await portal.page('/');
    assert.match(page.text, /href="https:\/\/t\.me\/orbitoffice"/, 'the handle became a link');
    assert.match(page.text, /https:\/\/t\.me\/orbitoffice/);
  } finally { await portal.close(); }
});

test('store: expired sessions are refused and cleaned up at startup (no unbounded growth)', async () => {
  const dir = tmpDir();
  const store = new storeLib.FileStore(dir);
  await store.init();
  const { salt, hash } = storeLib.hashPassword('Customer-Pass-1');
  await store.insertAccount({
    id: 'acct-1', email: 'grow@example.com', pass_salt: salt, pass_hash: hash,
    role: 'user', created_at: Date.now(), disabled: false,
  });
  // One live session and two that are long past their expiry.
  await store.createSession({ token_hash: 'live', account_id: 'acct-1', csrf: 'c', created_at: Date.now(), expires_at: Date.now() + 60000 });
  await store.createSession({ token_hash: 'dead-1', account_id: 'acct-1', csrf: 'c', created_at: 1, expires_at: Date.now() - 1000 });
  await store.createSession({ token_hash: 'dead-2', account_id: 'acct-1', csrf: 'c', created_at: 1, expires_at: Date.now() - 86400000 });
  assert.strictEqual(store.listSessions ? store.listSessions().length : 3, 3, 'three sessions on disk');
  assert.strictEqual(store.getSession('dead-1'), null, 'an expired session is refused on read');
  assert.ok(store.getSession('live'), 'the live session still works');

  // A restart prunes the dead rows and leaves the live one alone.
  const reopened = new storeLib.FileStore(dir);
  await reopened.init();
  const raw = JSON.parse(fs.readFileSync(path.join(dir, 'store.json'), 'utf8'));
  assert.strictEqual(raw.sessions.length, 1, 'only the live session survived the restart');
  assert.strictEqual(raw.sessions[0].token_hash, 'live');

  // A clean store is not rewritten just because it was opened.
  const before = fs.statSync(path.join(dir, 'store.json')).mtimeMs;
  await new Promise((r) => setTimeout(r, 20));
  const again = new storeLib.FileStore(dir);
  await again.init();
  assert.strictEqual(fs.statSync(path.join(dir, 'store.json')).mtimeMs, before, 'no needless write');
});

test('licence expiry: an expired key is refused by activate and validate', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  const { key } = await makeAccountAndKey(portal, { email: 'expired@example.com' });
  const record = portal.store.listKeys().find((k) => k.key_masked === storeLib.maskKey(key));
  await portal.store.updateKey(record.id, { expires_at: Date.now() - 1000 });

  const activate = await portal.json('POST', '/v1/activate', { key, hwid: 'machine-abcdefgh', domain: 'shop.example' });
  assert.strictEqual(activate.status, 403);
  assert.deepStrictEqual(activate.body, { error: 'expired' });

  const validate = await portal.json('POST', '/v1/validate', { key, hwid: 'machine-abcdefgh' });
  assert.strictEqual(validate.status, 403);
  assert.deepStrictEqual(validate.body, { error: 'expired' });

  // A key without an expiry keeps working (the default for the shop's own keys).
  const live = await makeAccountAndKey(portal, { email: 'live@example.com' });
  const ok = await portal.json('POST', '/v1/activate', { key: live.key, hwid: 'machine-abcdefgh' });
  assert.strictEqual(ok.status, 200, 'a key with no expiry activates: ' + JSON.stringify(ok.body));
});

test('login: email case and surrounding spaces do not lock a customer out', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  await makeAccountAndKey(portal, { email: 'Mixed.Case@Example.com' });
  for (const typed of ['mixed.case@example.com', 'MIXED.CASE@EXAMPLE.COM', '  Mixed.Case@Example.com  ']) {
    const login = await portal.form('/login', { email: typed, password: 'Customer-Pass-1' });
    assert.strictEqual(login.status, 302, 'sign-in accepted for "' + typed + '"');
    assert.strictEqual(login.res.headers.get('location'), '/dashboard');
  }
  assert.ok(portal.store.getAccountByEmail('MIXED.CASE@EXAMPLE.COM'), 'lookup is case-insensitive');
});

test('support: activation records where the shop is installed, on the admin page', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  const { key } = await makeAccountAndKey(portal, { email: 'owner-support@example.com' });
  await portal.json('POST', '/v1/activate', {
    key, hwid: 'machine-abcdefgh', domain: 'shop.example.com', php: '7.4.33', version: '6.0.1',
  });

  let record = portal.store.listKeys().find((k) => k.key_masked === storeLib.maskKey(key));
  assert.strictEqual(record.first_domain, 'shop.example.com');
  assert.strictEqual(record.last_domain, 'shop.example.com');
  assert.strictEqual(record.last_php, '7.4.33');
  assert.strictEqual(record.last_version, '6.0.1');

  // The shop moved to another domain: the first one is kept for the record.
  await portal.json('POST', '/v1/validate', {
    key, hwid: 'machine-abcdefgh', domain: 'new.example.net', php: '7.4.33', version: '6.0.2',
  });
  record = portal.store.listKeys().find((k) => k.key_masked === storeLib.maskKey(key));
  assert.strictEqual(record.last_domain, 'new.example.net');
  assert.strictEqual(record.first_domain, 'shop.example.com', 'the original domain is remembered');

  const admin = await adminSession(portal);
  const page = await portal.page('/admin', { cookie: admin.cookie });
  assert.match(page.text, /new\.example\.net/, 'the admin table shows the install domain');
  assert.match(page.text, /first seen on shop\.example\.com/);
  assert.match(page.text, /PHP 7\.4\.33/);

  // Client-supplied metadata is escaped, never markup.
  await portal.json('POST', '/v1/validate', {
    key, hwid: 'machine-abcdefgh', domain: '</td><script>alert(1)</script>', php: '<img src=x onerror=1>',
  });
  const after = await portal.page('/admin', { cookie: admin.cookie });
  assert.ok(!after.text.includes('<script>alert(1)</script>'), 'metadata cannot inject markup');
  assert.ok(!after.text.includes('<img src=x'), 'php version cannot inject markup');
});

test('safety: a licence used from a second machine is counted and visible to the admin', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  const { key } = await makeAccountAndKey(portal, { email: 'shared@example.com' });
  await portal.json('POST', '/v1/activate', { key, hwid: 'machine-first000', domain: 'first.example' });

  const other = await portal.json('POST', '/v1/activate', { key, hwid: 'machine-second00', domain: 'second.example' });
  assert.strictEqual(other.status, 403);
  assert.strictEqual(other.body.error, 'hwid_mismatch');

  const record = portal.store.listKeys().find((k) => k.key_masked === storeLib.maskKey(key));
  assert.strictEqual(record.mismatch_count, 1);
  assert.strictEqual(record.last_mismatch_hwid, 'machine-second00');
  assert.strictEqual(record.last_domain, 'first.example', 'the working install is still the recorded one');
  assert.strictEqual(record.last_mismatch_domain, 'second.example', 'the refused install is recorded separately');
  assert.strictEqual(record.hwid, 'machine-first000', 'the binding itself is untouched');

  const admin = await adminSession(portal);
  const page = await portal.page('/admin', { cookie: admin.cookie });
  assert.match(page.text, /1 mismatch\(es\)/);
  const audited = portal.store.listAudit(20).some((e) => /hwid_mismatch/.test(e.detail));
  assert.ok(audited, 'the mismatch is in the audit log');
});

test('safety: licensees can see when their licence expires', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  const { cookie, key } = await customerSession(portal);
  const record = portal.store.listKeys().find((k) => k.key_masked === storeLib.maskKey(key));
  const until = Date.now() + 30 * 86400000;
  await portal.store.updateKey(record.id, { expires_at: until });

  const page = await portal.page('/dashboard', { cookie });
  assert.strictEqual(page.status, 200);
  assert.match(page.text, /Expires/);
  assert.ok(page.text.includes(new Date(until).toISOString().slice(0, 10)), 'the expiry date is shown');
  assert.ok(!/never<\/td>/.test(page.text.split('Expires')[1] || ''), 'the key is not shown as never-expiring');
});

test('store: routine check-ins do not rewrite the data file (write throttle)', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  const { key } = await makeAccountAndKey(portal, { email: 'throttle@example.com' });
  await portal.json('POST', '/v1/activate', { key, hwid: 'machine-abcdefgh', domain: 'shop.example' });
  const file = path.join(portal.cfg.dataDir, 'store.json');
  const before = fs.statSync(file).mtimeMs;
  await new Promise((r) => setTimeout(r, 30));

  for (let i = 0; i < 3; i += 1) {
    const res = await portal.json('POST', '/v1/validate', { key, hwid: 'machine-abcdefgh', domain: 'shop.example' });
    assert.strictEqual(res.status, 200, 'check-in still succeeds');
  }
  assert.strictEqual(fs.statSync(file).mtimeMs, before, 'unchanged check-ins do not touch the disk');

  // A real change is still persisted immediately.
  await new Promise((r) => setTimeout(r, 30));
  const moved = await portal.json('POST', '/v1/validate', { key, hwid: 'machine-abcdefgh', domain: 'moved.example' });
  assert.strictEqual(moved.status, 200);
  assert.notStrictEqual(fs.statSync(file).mtimeMs, before, 'a new install domain is written');
  const record = portal.store.listKeys().find((k) => k.key_masked === storeLib.maskKey(key));
  assert.strictEqual(record.last_domain, 'moved.example');

  // The stamp is still refreshed once the throttle window has passed.
  await new Promise((r) => setTimeout(r, 30));
  const old = portal.store.listKeys().find((k) => k.key_masked === storeLib.maskKey(key)).last_seen_at;
  await portal.store.touchValidation(record.id, 'machine-abcdefgh', Date.now(), 0);
  const fresh = portal.store.listKeys().find((k) => k.key_masked === storeLib.maskKey(key)).last_seen_at;
  assert.ok(fresh >= old, 'an expired throttle refreshes the stamp');
});

test('safety: repeated mismatches are counted once a minute, not once per request', async (t) => {
  const portal = await startPortal();
  t.after(() => portal.close());
  const { key } = await makeAccountAndKey(portal, { email: 'flood@example.com' });
  await portal.json('POST', '/v1/activate', { key, hwid: 'machine-abcdefgh' });
  for (let i = 0; i < 5; i += 1) {
    const res = await portal.json('POST', '/v1/validate', { key, hwid: 'machine-other0000' });
    assert.strictEqual(res.status, 403);
  }
  const record = portal.store.listKeys().find((k) => k.key_masked === storeLib.maskKey(key));
  assert.strictEqual(record.mismatch_count, 1, 'the flood wrote once, so the disk is not the victim');
  assert.strictEqual(record.last_mismatch_hwid, 'machine-other0000');
});

test('accounts: a duplicate reports the same error code from either layer', async () => {
  const dir = tmpDir();
  const store = new storeLib.FileStore(dir);
  await store.init();
  const { createAccount } = require('../accounts');
  await createAccount(store, 'dupe@example.com', 'Customer-Pass-1', 'user');
  await assert.rejects(() => createAccount(store, 'dupe@example.com', 'Customer-Pass-1', 'user'),
    (err) => err.code === 'DUPLICATE_ACCOUNT', 'the pre-check throws the documented code');
  // And the store itself keeps the same contract when the pre-check loses a race.
  await assert.rejects(() => store.insertAccount({
    id: crypto.randomUUID(), email: 'dupe@example.com', pass_salt: 'x', pass_hash: 'y',
    role: 'user', created_at: Date.now(), disabled: false,
  }), (err) => err.code === 'DUPLICATE_ACCOUNT');
});
