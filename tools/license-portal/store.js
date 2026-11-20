'use strict';
/*
 * Orbit Office — licence portal storage (file driver).
 *
 * Durability warning: this driver keeps state in one JSON file. It is intended for
 * LOCAL DEVELOPMENT and for tests. Render's free web services have an EPHEMERAL
 * filesystem (no persistent disks on the free plan), so a deployed portal MUST use a
 * durable database instead. See docs/frontend/03-license-portal-design.md §4.
 *
 * Security notes:
 *  - Licence keys are stored ONLY as sha256 hashes plus a masked display form.
 *  - Passwords are stored as scrypt(salt) — see hashPassword()/verifyPassword().
 *  - Session tokens are stored ONLY as sha256 hashes.
 */

const crypto = require('crypto');
const fs = require('fs');
const fsp = require('fs/promises');
const path = require('path');

const EMPTY = { version: 1, accounts: [], keys: [], sessions: [], audit: [], settings: {} };

function sha256(input) {
  return crypto.createHash('sha256').update(String(input)).digest('hex');
}

/* ---------------------------------------------------------------- passwords */

function hashPassword(password, saltHex) {
  const salt = saltHex ? Buffer.from(saltHex, 'hex') : crypto.randomBytes(16);
  const derived = crypto.scryptSync(password, salt, 64, { N: 16384, r: 8, p: 1, maxmem: 64 * 1024 * 1024 });
  return { salt: salt.toString('hex'), hash: derived.toString('hex') };
}

function verifyPassword(password, saltHex, expectedHex) {
  const { hash } = hashPassword(password, saltHex);
  const a = Buffer.from(hash, 'hex');
  const b = Buffer.from(expectedHex, 'hex');
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

/* -------------------------------------------------------------- licence keys */

const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // Crockford base32 (no I, L, O, U)

function generateKey() {
  const body = Array.from(crypto.randomBytes(20)).map((b) => ALPHABET[b % 32]).join('');
  const groups = body.match(/.{5}/g).join('-');
  const check = sha256('orbit-key:' + body).slice(0, 2).toUpperCase();
  return 'ORBIT-' + groups + '-' + check;
}

function normalizeKey(input) {
  return String(input || '').toUpperCase().replace(/[\s-]+/g, '');
}

function isValidKeyFormat(key) {
  const compact = normalizeKey(key);
  const m = /^ORBIT([0-9A-HJKMNP-TV-Z]{20})([0-9A-F]{2})$/.exec(compact);
  if (!m) return false;
  const expected = sha256('orbit-key:' + m[1]).slice(0, 2).toUpperCase();
  return expected === m[2];
}

function maskKey(key) {
  const compact = normalizeKey(key);
  return 'ORBIT-' + compact.slice(5, 10) + '-****-****-' + compact.slice(-2);
}

/* ------------------------------------------------------------------- tokens */

function signToken(payload, secret) {
  const b64 = Buffer.from(JSON.stringify(payload)).toString('base64url');
  const sig = crypto.createHmac('sha256', secret).update(b64).digest('base64url');
  return b64 + '.' + sig;
}

function verifyToken(token, secret) {
  if (typeof token !== 'string' || token.indexOf('.') < 0) return null;
  const [b64, sig] = token.split('.');
  const expected = crypto.createHmac('sha256', secret).update(b64).digest('base64url');
  const a = Buffer.from(sig || '');
  const b = Buffer.from(expected);
  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) return null;
  try {
    return JSON.parse(Buffer.from(b64, 'base64url').toString('utf8'));
  } catch {
    return null;
  }
}

/* ------------------------------------------------------------ HWID + resets */

function isValidHwid(hwid) {
  return typeof hwid === 'string' && /^[A-Za-z0-9._:-]{8,128}$/.test(hwid);
}

function daysBetween(laterMs, earlierMs) {
  return (laterMs - earlierMs) / 86400000;
}

/**
 * Can this key's HWID be reset right now, and when is the next reset allowed?
 * @returns {{allowed: boolean, next_reset_at: number|null, days_remaining: number}}
 */
function resetState(keyRecord, nowMs, cooldownDays) {
  const resets = Array.isArray(keyRecord.hwid_resets) ? keyRecord.hwid_resets : [];
  // A misconfigured cooldown must never block customers forever (NaN comparisons are
  // always false) — fall back to the documented default and stay finite.
  const days = Number.isFinite(Number(cooldownDays)) ? Math.abs(Number(cooldownDays)) : 30;
  if (resets.length === 0) return { allowed: true, next_reset_at: null, days_remaining: 0 };
  const last = resets.reduce((m, r) => Math.max(m, Number(r.at) || 0), 0);
  const next = last + days * 86400000;
  if (nowMs >= next) return { allowed: true, next_reset_at: null, days_remaining: 0 };
  return {
    allowed: false,
    next_reset_at: next,
    days_remaining: Math.ceil(daysBetween(next, nowMs)),
  };
}

/**
 * Whole days until a key expires. Blank/0/never = no expiry; anything else must be a
 * plain integer in range, so a typo cannot silently create a never-expiring key.
 */
const MAX_KEY_DAYS = 3650;
function parseExpiryDays(value) {
  const raw = String(value === undefined || value === null ? '' : value).trim().toLowerCase();
  if (raw === '' || raw === '0' || raw === 'never' || raw === 'none') return { ok: true, days: null };
  if (!/^\d{1,6}$/.test(raw)) return { ok: false, error: 'invalid_expires_days' };
  const days = Number(raw);
  if (days < 1 || days > MAX_KEY_DAYS) return { ok: false, error: 'expires_days_out_of_range' };
  return { ok: true, days };
}

/* -------------------------------------------------------------------- store */

class FileStore {
  constructor(dataDir) {
    this.file = path.join(dataDir || process.env.PORTAL_DATA_DIR || './data', 'store.json');
    this.lockFile = this.file + '.lock';
    this.data = JSON.parse(JSON.stringify(EMPTY));
    this.queue = Promise.resolve();
    this.loadedStat = null;
  }

  async init() {
    await fsp.mkdir(path.dirname(this.file), { recursive: true });
    if (fs.existsSync(this.file)) {
      try {
        await this.#reload();
      } catch (err) {
        // The live file is unreadable/corrupt: recover rather than silently starting
        // empty (which would overwrite real licence data on the first write).
        const restored = await this.#recoverFromBackup('store.json is unreadable: ' + err.message);
        if (!restored) {
          throw new Error('store file is not valid JSON and no usable backup exists: ' + err.message);
        }
      }
    } else if (fs.existsSync(this.file + '.bak')) {
      await this.#recoverFromBackup('store.json is missing');
    } else {
      await this.#persist();
    }
    await this.#tightenPermissions();
    // Expired sessions are already refused on read, but without this they would stay in the
    // file for ever (one row per sign-in); a restart is a natural, write-free place to drop
    // them. pruneSessions() only writes when it actually removed something.
    await this.pruneSessions();
    return this;
  }

  /** Copy the previous good snapshot back into place after corruption or loss. */
  async #recoverFromBackup(reason) {
    const bak = this.file + '.bak';
    try {
      const raw = await fsp.readFile(bak, 'utf8');
      const parsed = JSON.parse(raw);
      this.data = Object.assign(JSON.parse(JSON.stringify(EMPTY)), parsed);
      // Keep the unreadable file for inspection instead of overwriting it, then write
      // the restored snapshot back.
      try { await fsp.rename(this.file, this.file + '.corrupt'); } catch { /* ignore */ }
      await this.#persist();
      console.warn('[portal] ' + reason + '; restored ' + this.data.keys.length + ' key(s) from store.json.bak');
      return true;
    } catch (err) {
      console.error('[portal] ' + reason + ' and store.json.bak is unusable (' + err.message + ')');
      return false;
    }
  }

  /** The store holds password hashes and licence hashes: keep it owner-readable only. */
  async #tightenPermissions() {
    try {
      const st = await fsp.stat(this.file);
      if ((st.mode & 0o077) !== 0) {
        await fsp.chmod(this.file, 0o600);
        console.warn('[portal] store.json had group/world permissions; tightened to 0600');
      }
    } catch { /* ignore */ }
    try {
      const st = await fsp.stat(path.dirname(this.file));
      if ((st.mode & 0o077) !== 0) {
        console.warn('[portal] PORTAL_DATA_DIR is group/world accessible (' +
          (st.mode & 0o777).toString(8) + '); consider chmod 700 — it stores password and licence hashes');
      }
    } catch { /* ignore */ }
  }

  /* ---- cross-process safety -------------------------------------------------
   * The portal may be running while an operator runs the CLI (create-account,
   * create-admin) and the shop-side tests may open a second store on the same
   * directory. Every mutation therefore: takes an exclusive lock, re-reads the
   * file so no other writer's change is lost, applies the change, writes
   * atomically, releases the lock.
   */
  async #lock() {
    const started = Date.now();
    for (;;) {
      try {
        const handle = await fsp.open(this.lockFile, 'wx');
        await handle.writeFile(String(process.pid));
        await handle.close();
        return;
      } catch (err) {
        if (err && err.code !== 'EEXIST') throw err;
        try {
          const st = await fsp.stat(this.lockFile);
          if (Date.now() - st.mtimeMs > 10000) {
            await fsp.unlink(this.lockFile).catch(() => {});
            continue;
          }
        } catch { /* the lock disappeared: retry immediately */ }
        if (Date.now() - started > 15000) throw new Error('store lock timeout');
        await new Promise((r) => setTimeout(r, 20));
      }
    }
  }

  async #unlock() {
    await fsp.unlink(this.lockFile).catch(() => {});
  }

  async #stat() {
    const st = await fsp.stat(this.file, { bigint: true });
    // nanosecond resolution: two writes in the same millisecond are still distinguishable
    return { mtimeNs: st.mtimeNs.toString(), size: st.size.toString() };
  }

  async #reload() {
    try {
      const raw = await fsp.readFile(this.file, 'utf8');
      const parsed = JSON.parse(raw);
      this.data = Object.assign(JSON.parse(JSON.stringify(EMPTY)), parsed);
      this.loadedStat = await this.#stat();
    } catch (err) {
      if (err && err.code !== 'ENOENT') throw err;
    }
  }

  /** Cheap freshness check for a long-running server: reload if the file changed. */
  async maybeReload() {
    try {
      const now = await this.#stat();
      if (!this.loadedStat || now.mtimeNs !== this.loadedStat.mtimeNs || now.size !== this.loadedStat.size) {
        await this.#reload();
      }
    } catch { /* missing file: keep the current snapshot */ }
  }

  /* Serialize every mutation in-process, and coordinate across processes. */
  #mutate(fn) {
    const run = this.queue.then(async () => {
      await this.#lock();
      try {
        await this.#reload();
        const result = fn(this.data);
        try {
          await this.#persist();
        } catch (err) {
          // The write failed: drop the in-memory change so reads cannot report a
          // success that is not on disk.
          await this.#reload().catch(() => {});
          throw err;
        }
        return result;
      } finally {
        await this.#unlock();
      }
    });
    this.queue = run.catch(() => {});
    return run;
  }

  async #persist() {
    const tmp = this.file + '.tmp.' + process.pid;
    const payload = JSON.stringify(this.data, null, 2);
    // fsync before the rename: a crash must not leave a renamed-but-empty file, which
    // would look like a valid store with no accounts or keys.
    const handle = await fsp.open(tmp, 'w', 0o600);
    try {
      await handle.writeFile(payload);
      await handle.sync();
    } finally {
      await handle.close();
    }
    // Rotate the previous snapshot into place with a rename (no data copy), so the
    // backup is always exactly one write behind — cheap enough to do on every write.
    try {
      await fsp.rename(this.file, this.file + '.bak');
    } catch { /* first write: there is nothing to rotate */ }
    await fsp.rename(tmp, this.file);
    await this.#fsyncDir();
    await this.#refreshStat();
  }

  /** Best effort: make the rename itself durable on filesystems that need it. */
  async #fsyncDir() {
    let handle = null;
    try {
      handle = await fsp.open(path.dirname(this.file), 'r');
      await handle.sync();
    } catch { /* not supported everywhere; the rename is still atomic */ } finally {
      if (handle) await handle.close().catch(() => {});
    }
  }

  /** Verify the on-disk snapshot without mutating it (used by --verify). */
  async inspect() {
    const out = { file: this.file, readable: false, keys: 0, accounts: 0, sessions: 0, audit: 0, problems: [], backup: null };
    let data = null;
    try {
      data = JSON.parse(await fsp.readFile(this.file, 'utf8'));
      out.readable = true;
    } catch (err) {
      out.problems.push('store.json: ' + err.message);
    }
    try {
      const parsed = JSON.parse(await fsp.readFile(this.file + '.bak', 'utf8'));
      out.backup = { keys: (parsed.keys || []).length, accounts: (parsed.accounts || []).length };
    } catch { /* no backup is not a problem */ }
    if (!data) return out;

    const accounts = data.accounts || [];
    const keys = data.keys || [];
    const sessions = data.sessions || [];
    out.accounts = accounts.length;
    out.keys = keys.length;
    out.sessions = sessions.length;
    out.audit = (data.audit || []).length;

    const emails = new Set();
    for (const a of accounts) {
      if (!a || !a.email) out.problems.push('account without an email');
      else if (emails.has(a.email)) out.problems.push('duplicate account email: ' + a.email);
      else emails.add(a.email);
      if (!a || !a.pass_hash || !a.pass_salt) out.problems.push('account without password material: ' + ((a && a.email) || '?'));
    }
    const ids = new Set(accounts.map((a) => a && a.id));
    for (const k of keys) {
      if (!k || !ids.has(k.account_id)) out.problems.push('key not linked to an existing account: ' + ((k && k.key_masked) || '?'));
      if (k && (!/^[0-9a-f]{64}$/.test(String(k.key_hash || '')))) out.problems.push('key with a malformed hash: ' + ((k && k.key_masked) || '?'));
      if (k && k.hwid && !isValidHwid(k.hwid)) out.problems.push('key with a malformed hwid: ' + ((k && k.key_masked) || '?'));
    }
    for (const s of sessions) {
      if (!s || !ids.has(s.account_id)) out.problems.push('session for a missing account');
    }
    return out;
  }

  async #refreshStat() {
    try {
      this.loadedStat = await this.#stat();
    } catch { /* ignore */ }
  }

  /* ---- accounts ---- */
  getAccountByEmail(email) {
    const e = String(email || '').trim().toLowerCase();
    return this.data.accounts.find((a) => a.email === e) || null;
  }
  listAccounts() {
    return this.data.accounts.slice();
  }
  getAccountById(id) {
    return this.data.accounts.find((a) => a.id === id) || null;
  }
  insertAccount(account) {
    return this.#mutate((d) => {
      const email = String((account && account.email) || '').trim().toLowerCase();
      if (!email) throw Object.assign(new Error('account email is required'), { code: 'INVALID_ACCOUNT' });
      if (d.accounts.some((a) => a.email === email)) {
        // Checked inside the mutation so two parallel creators cannot both succeed.
        throw Object.assign(new Error('account already exists'), { code: 'DUPLICATE_ACCOUNT' });
      }
      account.email = email;
      d.accounts.push(account);
      return account;
    });
  }
  updateAccount(id, patch) {
    return this.#mutate((d) => {
      const acc = d.accounts.find((a) => a.id === id);
      if (!acc) return null;
      Object.assign(acc, patch);
      return acc;
    });
  }

  /* ---- keys ---- */
  getKeyByHash(hash) {
    return this.data.keys.find((k) => k.key_hash === hash) || null;
  }
  getKeyById(id) {
    return this.data.keys.find((k) => k.id === id) || null;
  }
  listKeysByAccount(accountId) {
    return this.data.keys.filter((k) => k.account_id === accountId);
  }
  listKeys() {
    return this.data.keys.slice();
  }
  insertKey(key) {
    return this.#mutate((d) => {
      d.keys.push(key);
      return key;
    });
  }
  updateKey(id, patch) {
    return this.#mutate((d) => {
      const key = d.keys.find((k) => k.id === id);
      if (!key) return null;
      Object.assign(key, patch);
      return key;
    });
  }

  /* ---- atomic hardware-id operations -------------------------------------
   * These are check-and-set inside a single serialized mutation. Doing the
   * check in the HTTP handler and the write in the store leaves a window in
   * which two concurrent requests both pass the check (verified by the
   * penetration test: 2/10 parallel activations bound two different machines).
   */

  /** Bind a hardware id if the key is free (or already bound to the same one). */
  bindHwid(keyId, hwid, now) {
    return this.#mutate((d) => {
      const key = d.keys.find((k) => k.id === keyId);
      if (!key) return { ok: false, reason: 'not_found' };
      if (key.status !== 'active') return { ok: false, reason: 'inactive', record: key };
      // Disabling an account must stop the shop it licensed, not just the web login.
      const owner = d.accounts.find((a) => a.id === key.account_id);
      if (!owner || owner.disabled) return { ok: false, reason: 'inactive', record: key };
      if (key.expires_at && key.expires_at < now) return { ok: false, reason: 'expired', record: key };
      if (key.hwid && key.hwid !== hwid) return { ok: false, reason: 'mismatch', record: key };
      const wasBound = Boolean(key.hwid);
      if (!wasBound) {
        key.hwid = hwid;
        key.hwid_bound_at = now;
      }
      key.last_seen_at = now;
      return { ok: true, record: key, bound: !wasBound };
    });
  }

  /** Note a successful validation for an already-bound hardware id. */
  /**
   * Record a check-in. The shop calls this on a timer, and each write costs a full
   * rewrite + fsync of the file, so the stamp is refreshed at most once per
   * `throttleMs` per key (ten minutes by default) — accurate enough for "last seen",
   * and it stops a caller from turning check-ins into disk churn.
   */
  touchValidation(keyId, hwid, now, throttleMs) {
    const throttle = throttleMs === undefined ? 10 * 60000 : throttleMs;
    const key = this.data.keys.find((k) => k.id === keyId);
    if (!key) return Promise.resolve({ ok: false, reason: 'not_found' });
    const owner = this.data.accounts.find((a) => a.id === key.account_id);
    if (!owner || owner.disabled) return Promise.resolve({ ok: false, reason: 'inactive', record: key });
    if (key.hwid && key.hwid !== hwid) return Promise.resolve({ ok: false, reason: 'mismatch', record: key });
    if (key.last_seen_at && now - key.last_seen_at < throttle) {
      return Promise.resolve({ ok: true, skipped: true, record: key });
    }
    return this.#mutate((d) => {
      const rec = d.keys.find((k) => k.id === keyId);
      if (!rec) return { ok: false, reason: 'not_found' };
      const own = d.accounts.find((a) => a.id === rec.account_id);
      if (!own || own.disabled) return { ok: false, reason: 'inactive', record: rec };
      if (rec.hwid && rec.hwid !== hwid) return { ok: false, reason: 'mismatch', record: rec };
      rec.last_seen_at = now;
      return { ok: true, record: rec };
    });
  }

  /**
   * Remember where a licence is installed (domain, PHP version, shop version).
   *
   * Support needs this ("which site is this key on?") and it is the cheapest signal
   * for a key that has been copied to another domain. The shop calls validate often,
   * so the write is throttled: only a changed domain or a stale stamp is persisted.
   */
  recordKeyMeta(keyId, meta, now, ttlMs) {
    const m = meta || {};
    const throttle = ttlMs || 6 * 3600000;
    // Decide before #mutate: every mutate persists the file, so the no-op path must
    // never reach it (that is what makes the throttle real).
    const key = this.data.keys.find((k) => k.id === keyId);
    if (!key) return Promise.resolve({ ok: false, reason: 'not_found' });
    const domainChanged = Boolean(m.domain) && m.domain !== key.last_domain;
    const stampStale = !key.meta_at || now - key.meta_at > throttle;
    const detailChanged = (Boolean(m.php) && m.php !== key.last_php)
      || (Boolean(m.version) && m.version !== key.last_version);
    if (!domainChanged && !stampStale && !detailChanged) {
      return Promise.resolve({ ok: true, skipped: true, record: key });
    }
    return this.#mutate((d) => {
      const rec = d.keys.find((k) => k.id === keyId);
      if (!rec) return { ok: false, reason: 'not_found' };
      if (m.domain) {
        rec.last_domain = m.domain;
        if (!rec.first_domain) rec.first_domain = m.domain;
      }
      if (m.php) rec.last_php = m.php;
      if (m.version) rec.last_version = m.version;
      rec.meta_at = now;
      return { ok: true, record: rec };
    });
  }

  /**
   * Count a hardware-ID mismatch (a machine other than the bound one asked for this
   * licence). Writes are throttled to one per key per minute so a caller cannot force
   * an fsync per request; the counter therefore means "at least N, last seen at T".
   */
  recordHwidMismatch(keyId, hwid, now, meta, throttleMs) {
    const throttle = throttleMs === undefined ? 60000 : throttleMs;
    const m = meta || {};
    const key = this.data.keys.find((k) => k.id === keyId);
    if (!key) return Promise.resolve({ ok: false, reason: 'not_found' });
    if (key.last_mismatch_at && now - key.last_mismatch_at < throttle) {
      return Promise.resolve({ ok: true, skipped: true, record: key });
    }
    return this.#mutate((d) => {
      const rec = d.keys.find((k) => k.id === keyId);
      if (!rec) return { ok: false, reason: 'not_found' };
      rec.mismatch_count = (rec.mismatch_count || 0) + 1;
      rec.last_mismatch_at = now;
      rec.last_mismatch_hwid = String(hwid || '').slice(0, 60);
      // Deliberately NOT last_domain: "installed on" must keep pointing at the site the
      // licence actually works on, so a refused machine is recorded as a mismatch only.
      if (m.domain) rec.last_mismatch_domain = m.domain;
      return { ok: true, record: rec };
    });
  }

  /**
   * Consume a self-service reset, honouring the cooldown, in one mutation.
   * Two parallel calls can never both succeed.
   */
  consumeReset(keyId, cooldownDays, now, meta) {
    return this.#mutate((d) => {
      const key = d.keys.find((k) => k.id === keyId);
      if (!key) return { ok: false, reason: 'not_found' };
      if (key.status !== 'active') return { ok: false, reason: 'inactive' };
      const state = resetState(key, now, cooldownDays);
      if (!state.allowed) return { ok: false, reason: 'cooldown', state };
      const resets = (key.hwid_resets || []).concat([Object.assign({ at: now, from: key.hwid || null }, meta || {})]);
      key.hwid = null;
      key.hwid_bound_at = null;
      key.hwid_resets = resets;
      return { ok: true, next_reset_at: now + cooldownDays * 86400000 };
    });
  }

  /* ---- sessions ---- */
  createSession(session) {
    return this.#mutate((d) => {
      d.sessions = d.sessions.filter((s) => s.expires_at > Date.now());
      d.sessions.push(session);
      return session;
    });
  }
  getSession(tokenHash) {
    const s = this.data.sessions.find((x) => x.token_hash === tokenHash);
    if (!s || s.expires_at <= Date.now()) return null;
    return s;
  }
  deleteSession(tokenHash) {
    return this.#mutate((d) => {
      d.sessions = d.sessions.filter((s) => s.token_hash !== tokenHash);
      return true;
    });
  }
  /** Drop every session of an account (used when it is disabled or its password changes). */
  deleteSessionsByAccount(accountId) {
    return this.#mutate((d) => {
      const before = d.sessions.length;
      d.sessions = d.sessions.filter((s) => s.account_id !== accountId);
      return before - d.sessions.length;
    });
  }
  /**
   * Drop sessions that have already expired. The store is only rewritten when
   * something was actually removed, so calling this on every start is free.
   */
  pruneSessions() {
    const now = Date.now();
    const live = (s) => s.expires_at > now;
    if (Array.isArray(this.data.sessions) && this.data.sessions.every(live)) {
      return Promise.resolve(this.data.sessions.length);
    }
    return this.#mutate((d) => {
      d.sessions = d.sessions.filter(live);
      return d.sessions.length;
    });
  }

  /* ---- settings (admin editable: support/sales links, shop copy) ---- */
  getSettings() {
    // Deep copy: callers must not be able to mutate stored settings by accident.
    return JSON.parse(JSON.stringify(this.data.settings || {}));
  }
  updateSettings(patch) {
    return this.#mutate((d) => {
      d.settings = Object.assign({}, d.settings || {}, patch);
      return d.settings;
    });
  }

  /* ---- audit ---- */
  audit(entry) {
    return this.#mutate((d) => {
      d.audit.push(entry);
      if (d.audit.length > 5000) d.audit = d.audit.slice(-5000);
      return entry;
    });
  }
  listAudit(limit) {
    return this.data.audit.slice(-(limit || 100)).reverse();
  }
}

module.exports = {
  EMPTY,
  parseExpiryDays,
  MAX_KEY_DAYS,
  resetState,
  sha256,
  hashPassword,
  verifyPassword,
  generateKey,
  normalizeKey,
  isValidKeyFormat,
  maskKey,
  signToken,
  verifyToken,
  isValidHwid,
  FileStore,
};
