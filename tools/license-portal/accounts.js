'use strict';
/*
 * Orbit Office — account operations shared by the HTTP routes, the CLI and the
 * first-boot bootstrap. Keeping them here means the admin UI and the CLI cannot
 * drift apart in validation or session handling.
 *
 * The operator CLI itself (flags, usage text, exit codes) lives in cli.js.
 */

const crypto = require('crypto');
const { hashPassword } = require('./store');

/** Create an account from the CLI (password read from the environment, never argv). */
async function createAccount(store, email, password, role) {
  if (!email || !password) throw new Error('email and password are required');
  // Same error contract as store.insertAccount, so callers never have to guess which
  // layer noticed the duplicate (the admin route branches on err.code).
  if (store.getAccountByEmail(email)) {
    throw Object.assign(new Error('account already exists'), { code: 'DUPLICATE_ACCOUNT' });
  }
  const { salt, hash } = hashPassword(password);
  const account = {
    id: crypto.randomUUID(),
    email: String(email).trim().toLowerCase(),
    pass_salt: salt,
    pass_hash: hash,
    role: role === 'admin' ? 'admin' : 'user',
    created_at: Date.now(),
    disabled: false,
  };
  await store.insertAccount(account);
  return account;
}

/** Back-compat alias used by the bootstrap path and the tests. */
function bootstrapAdmin(store, email, password) {
  return createAccount(store, email, password, 'admin');
}

/** Set a new password and end every session of that account. */
async function setAccountPassword(store, account, password, actor) {
  const { salt, hash } = hashPassword(password);
  await store.updateAccount(account.id, { pass_salt: salt, pass_hash: hash });
  const ended = await store.deleteSessionsByAccount(account.id);
  await store.audit({ at: Date.now(), actor: actor || 'cli', action: 'reset_password', target: account.email, detail: 'sessions_ended=' + ended });
  return ended;
}

/** Enable/disable an account; disabling ends its sessions immediately. */
async function setAccountDisabled(store, account, disabled, actor) {
  await store.updateAccount(account.id, { disabled: Boolean(disabled) });
  let ended = 0;
  if (disabled) ended = await store.deleteSessionsByAccount(account.id);
  await store.audit({
    at: Date.now(), actor: actor || 'cli',
    action: disabled ? 'disable_account' : 'enable_account',
    target: account.email, detail: disabled ? 'sessions_ended=' + ended : 'manual',
  });
  return ended;
}

module.exports = { createAccount, bootstrapAdmin, setAccountPassword, setAccountDisabled };
