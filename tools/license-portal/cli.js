'use strict';
/*
 * Orbit Office — operator command line.
 *
 * A shell is not always available (Render's free plan has none), which is why the
 * same operations also exist in the admin UI. These commands are for operators on
 * a machine that does have one: they read and write the same store, take the same
 * locks, and write the same audit entries, so the two paths cannot drift.
 *
 * Passwords are never taken from argv: they come from the environment or are
 * generated and printed once.
 */

const crypto = require('crypto');

const { sha256, normalizeKey, isValidKeyFormat } = require('./store');
const { createAccount, bootstrapAdmin, setAccountPassword, setAccountDisabled } = require('./accounts');

const USAGE = `Orbit Office licence portal

  node server.js                             start serving
  node server.js --create-admin <email>      (PORTAL_ADMIN_PASSWORD=...)
  node server.js --create-account <email>    (PORTAL_ACCOUNT_PASSWORD=..., or generated)
  node server.js --list-accounts
  node server.js --disable-account <email>
  node server.js --enable-account <email>
  node server.js --reset-password <email>    (prints a one-time password)
  node server.js --revoke-key <ORBIT-...>    (also: --restore-key)
  node server.js --verify                    check the data file for problems
  node server.js --help

Environment: PORT, PORTAL_DATA_DIR, PORTAL_SIGNING_SECRET (>=32 chars), RESET_COOLDOWN_DAYS,
PORTAL_LOGIN_LIMIT, PUBLIC_BASE_URL, TRUST_PROXY_HEADERS, TRUSTED_PROXY_IPS, CONTACT_*.
See README.md for the full table.
`;

/** Argument value helper: `--flag value`. */
function argValue(flag) {
  const i = process.argv.indexOf(flag);
  return i >= 0 ? process.argv[i + 1] : null;
}

function usageError(...lines) {
  lines.forEach((l) => console.error(l));
  process.exit(2);
}

/** `--verify`: report the data file's health without modifying it. */
async function verify(store) {
  const report = await store.inspect();
  console.log('data file :', report.file);
  console.log('readable  :', report.readable);
  console.log('accounts  :', report.accounts, '| keys:', report.keys, '| sessions:', report.sessions, '| audit entries:', report.audit);
  console.log('backup    :', report.backup ? `${report.backup.accounts} account(s), ${report.backup.keys} key(s)` : 'none');
  if (report.problems.length) {
    console.log('problems  :');
    report.problems.forEach((p) => console.log('  - ' + p));
    process.exit(1);
  }
  console.log('problems  : none');
  process.exit(0);
}

function listAccounts(store) {
  const accounts = store.listAccounts();
  if (!accounts.length) console.log('no accounts yet');
  for (const a of accounts) {
    const keys = store.listKeysByAccount(a.id).length;
    console.log(`${a.email}\t${a.role}\t${a.disabled ? 'disabled' : 'enabled'}\t${keys} key(s)\tcreated ${new Date(a.created_at).toISOString().slice(0, 10)}`);
  }
  process.exit(0);
}

/**
 * Run one operator command.
 *
 * @returns {Promise<boolean>} true when a command ran (the caller should stop),
 *                            false when the caller should start the server.
 */
async function runCli(store, cfg, argv) {
  const args = argv || process.argv;

  if (args.includes('--verify')) return verify(store);
  if (args.includes('--list-accounts')) return listAccounts(store);

  const disableEmail = argValue('--disable-account');
  const enableEmail = argValue('--enable-account');
  if (disableEmail || enableEmail) {
    const target = store.getAccountByEmail(disableEmail || enableEmail);
    if (!target) usageError('no such account: ' + (disableEmail || enableEmail));
    if (disableEmail) {
      const admins = store.listAccounts().filter((a) => a.role === 'admin' && !a.disabled);
      if (target.role === 'admin' && admins.length <= 1) usageError('refusing to disable the last administrator');
      const ended = await setAccountDisabled(store, target, true, 'cli');
      console.log('disabled', target.email, '| sessions ended:', ended);
    } else {
      await setAccountDisabled(store, target, false, 'cli');
      console.log('enabled', target.email);
    }
    process.exit(0);
  }

  const passwordEmail = argValue('--reset-password');
  if (passwordEmail) {
    const target = store.getAccountByEmail(passwordEmail);
    if (!target) usageError('no such account: ' + passwordEmail);
    const password = crypto.randomBytes(12).toString('base64url');
    const ended = await setAccountPassword(store, target, password, 'cli');
    console.log('password reset for', target.email, '| sessions ended:', ended);
    console.log('one-time password:', password);
    process.exit(0);
  }

  const keyArg = argValue('--revoke-key') || argValue('--restore-key');
  if (keyArg) {
    if (!isValidKeyFormat(keyArg)) usageError('that does not look like a licence key');
    const record = store.getKeyByHash(sha256(normalizeKey(keyArg)));
    if (!record) usageError('no such key');
    const restore = Boolean(argValue('--restore-key'));
    await store.updateKey(record.id, { status: restore ? 'active' : 'revoked' });
    await store.audit({
      at: Date.now(), actor: 'cli', action: restore ? 'restore_key' : 'revoke_key',
      target: record.key_masked, detail: (store.getAccountById(record.account_id) || {}).email || '?',
    });
    console.log(restore ? 'restored' : 'revoked', record.key_masked);
    process.exit(0);
  }

  if (args.includes('--create-admin')) {
    const email = argValue('--create-admin') || cfg.adminEmail;
    if (!email || !cfg.adminPassword) {
      usageError('Usage: PORTAL_ADMIN_PASSWORD=... node server.js --create-admin <email>');
    }
    await bootstrapAdmin(store, email, cfg.adminPassword);
    console.log('admin account created:', String(email).toLowerCase());
    process.exit(0);
  }

  if (args.includes('--create-account')) {
    const email = argValue('--create-account');
    const generated = !cfg.accountPassword;
    const password = cfg.accountPassword || crypto.randomBytes(12).toString('base64url');
    if (!email) {
      usageError(
        'Usage: PORTAL_ACCOUNT_PASSWORD=... node server.js --create-account <email>',
        '       (omit PORTAL_ACCOUNT_PASSWORD to have one generated and printed once)',
      );
    }
    await createAccount(store, email, password, 'user');
    console.log('account created:', String(email).toLowerCase());
    if (generated) console.log('one-time password:', password);
    process.exit(0);
  }

  return false;
}

function helpRequested(argv) {
  const args = argv || process.argv;
  return args.includes('--help') || args.includes('-h');
}

module.exports = { runCli, helpRequested, USAGE, argValue };
