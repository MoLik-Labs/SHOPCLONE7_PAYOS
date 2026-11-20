'use strict';
/*
 * Minimal, dependency-free HTML for the licence portal.
 * No external assets, no inline event handlers (CSP: default-src 'none').
 */

function esc(value) {
  return String(value === null || value === undefined ? '' : value)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function fmtDate(ms) {
  if (!ms) return '—';
  return new Date(Number(ms)).toISOString().slice(0, 16).replace('T', ' ') + ' UTC';
}

const LINK_ERROR_TEXT = {
  invalid_scheme: 'only http(s) links are accepted (or a Telegram handle / phone number for those channels)',
  invalid_email: 'that does not look like an email address',
  invalid_url: 'that does not look like a web address',
  invalid_host: 'that address has no host name',
  url_too_long: 'the link is longer than 300 characters',
  empty_url: 'the field was empty',
  invalid_links: 'the submitted link data was not readable',
  unsupported_platform: 'that channel is not supported',
};

const PLATFORM_META = {
  telegram: { label: 'Telegram', hint: '@yourhandle or https://t.me/yourhandle', icon: 'TG' },
  facebook: { label: 'Facebook', hint: 'https://facebook.com/yourpage', icon: 'FB' },
  discord:  { label: 'Discord',  hint: 'invite code or https://discord.gg/invite', icon: 'DC' },
  whatsapp: { label: 'WhatsApp', hint: '84 912 345 678 or https://wa.me/84912345678', icon: 'WA' },
  zalo:     { label: 'Zalo',     hint: '84 912 345 678 or https://zalo.me/84912345678', icon: 'ZL' },
  email:    { label: 'Email',    hint: 'you@example.com',                icon: '@'  },
  website:  { label: 'Website',  hint: 'https://your-site.example',      icon: 'WW' },
  docs:     { label: 'Docs',     hint: 'https://docs.your-site.example', icon: 'DOC' },
};

function linkRows(links, platforms) {
  const keys = platforms || Object.keys(PLATFORM_META);
  return keys.map((platform) => {
    const meta = PLATFORM_META[platform] || { label: platform, icon: '#' };
    return `<tr><th>${esc(meta.label)}</th><td><input name="link_${esc(platform)}" value="${esc(links[platform] || '')}" placeholder="${esc(meta.hint || '')}"></td></tr>`;
  }).join('');
}

/** Public "how to reach us" card: renders only links that are configured. */
function contactCard(links, salesNote) {
  const keys = Object.keys(PLATFORM_META).filter((k) => links[k]);
  const rows = keys.map((k) => {
    const meta = PLATFORM_META[k];
    const value = links[k];
    const href = k === 'email' ? 'mailto:' + value : value;
    const safeHref = /^(https?:\/\/|mailto:)/i.test(href) ? href : '#';
    return `<tr><th>${esc(meta.label)}</th><td><a href="${esc(safeHref)}" rel="noopener noreferrer">${esc(value)}</a></td></tr>`;
  }).join('');
  return `<div class="card">
    <h2 style="margin-top:0">Buy a licence / get support</h2>
    ${rows ? `<table>${rows}</table>` : '<p style="color:var(--muted);margin:0">Contact details are being configured.</p>'}
    ${salesNote ? `<p style="color:#cdd9ee;margin:14px 0 0">${esc(salesNote)}</p>` : ''}
    <p style="color:var(--muted);font-size:13px;margin-bottom:0">Sales and support are handled personally by Orbit Office.
    Links are updated here whenever an account changes, so these details always stay current.</p>
  </div>`;
}

const STYLE = `
:root{--bg:#0b0f17;--panel:#131a26;--line:#22304a;--text:#e7eefc;--muted:#8ea3c6;--accent:#4f8cff;--ok:#31c48d;--warn:#f5a623;--bad:#f26d6d}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:15px/1.55 ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
header{border-bottom:1px solid var(--line);padding:18px 22px;display:flex;justify-content:space-between;align-items:center}
.brand{font-weight:700;letter-spacing:.4px}
.brand span{color:var(--muted);font-weight:400;margin-left:8px;font-size:13px}
main{max-width:980px;margin:0 auto;padding:28px 22px 64px}
h1{font-size:24px;margin:0 0 6px}
h2{font-size:17px;margin:26px 0 10px}
p.lead{color:var(--muted);margin:0 0 22px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px 20px;margin:0 0 16px}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{text-align:left;padding:9px 10px;border-bottom:1px solid var(--line);vertical-align:top}
th{color:var(--muted);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.5px}
code,.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px}
input,button,select{font:inherit}
input,select{width:100%;padding:10px 12px;border-radius:8px;border:1px solid var(--line);background:#0d1420;color:var(--text)}
form.inline{display:inline;margin:0}
td form button{margin:0;padding:5px 10px;font-size:13px}
label{display:block;margin:12px 0 6px;color:var(--muted);font-size:13px}
button{margin-top:16px;padding:10px 16px;border-radius:8px;border:0;background:var(--accent);color:#fff;font-weight:600;cursor:pointer}
button.ghost{background:transparent;border:1px solid var(--line);color:var(--text)}
button.danger{background:transparent;border:1px solid var(--bad);color:var(--bad)}
.pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;border:1px solid var(--line);color:var(--muted)}
.pill.ok{color:var(--ok);border-color:rgba(49,196,141,.4)}
.pill.bad{color:var(--bad);border-color:rgba(242,109,109,.4)}
.pill.warn{color:var(--warn);border-color:rgba(245,166,35,.4)}
.notice{border-left:3px solid var(--warn);padding:10px 14px;background:#171f2e;border-radius:8px;margin:0 0 18px;color:#dfe8f7}
.error{border-left:3px solid var(--bad)}
.keybox{font-family:ui-monospace,Menlo,monospace;font-size:16px;padding:14px;border:1px dashed var(--accent);border-radius:10px;background:#0d1420;word-break:break-all}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:760px){.grid{grid-template-columns:1fr}}
ul.audit{list-style:none;padding:0;margin:0;font-size:13px;color:var(--muted)}
ul.audit li{padding:6px 0;border-bottom:1px solid var(--line)}
a{color:var(--accent)}
footer{color:var(--muted);font-size:13px;border-top:1px solid var(--line);margin-top:30px;padding-top:16px}
`;

function layout(title, account, body, csrf) {
  const nav = account
    ? `<a href="/dashboard">Dashboard</a>${account.role === 'admin' ? ' · <a href="/admin">Admin</a>' : ''} · <form method="post" action="/logout" style="display:inline"><input type="hidden" name="csrf" value="${esc(csrf || '')}"><button class="ghost" style="margin:0;padding:4px 10px">Sign out</button></form>`
    : '<a href="/login">Sign in</a>';
  return `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(title)} · Orbit Office Licence Portal</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml"><style>${STYLE}</style></head>
<body>
<header><div class="brand">Orbit Office <span>licence portal</span></div><nav>${nav}</nav></header>
<main>${body}</main>
</body></html>`;
}

function renderPage(name, data) {
  const d = data || {};
  switch (name) {
    case 'landing': {
      const c = d.cfg.contact;
      return layout('Home', null, `
<h1>Orbit Office licence portal</h1>
<p class="lead">Activate your licence, manage your hardware ID, and read the setup instructions.</p>
<div class="grid">
  <div class="card">
    <h2 style="margin-top:0">Setup in three steps</h2>
    <ol style="color:#cdd9ee;padding-left:18px">
      <li>Upload the shop source and point it at your domain.</li>
      <li>Open the site and complete the first-run setup (the installer asks for a one-time setup token).</li>
      <li>Enter your licence key in <span class="mono">Admin → Settings</span>. The shop activates against this portal.</li>
    </ol>
    <p style="color:var(--muted);font-size:13px">Moving to another server? Sign in below and use <em>Reset hardware ID</em>.
    Self-service resets are limited to one every <strong>${esc(d.cfg.cooldownDays)} days</strong>.</p>
  </div>
  ${contactCard(d.cfg.links || {}, d.cfg.salesNote || '')}
  <div class="card" style="grid-column:1/-1">
    <p style="color:var(--muted);font-size:13px;margin:0 0 12px">Keys and invoices are handled by Orbit Office directly.
    Never share your licence key or portal password with anyone.</p>
    <a href="/login"><button>Sign in to manage licences</button></a>
  </div>
</div>
<footer>Orbit Office by NggKhaiz · portal v${esc(d.cfg.version)} · <a href="/healthz">status</a></footer>`, null);
    }

    case 'login':
      return layout('Sign in', null, `
<h1>Sign in</h1>
<p class="lead">Use the account that was issued with your licence key.</p>
${d.error ? `<div class="card notice error">${esc(d.error)}</div>` : ''}
<form class="card" method="post" action="/login">
  <label for="email">Email</label>
  <input id="email" name="email" type="email" autocomplete="username" required>
  <label for="password">Password</label>
  <input id="password" name="password" type="password" autocomplete="current-password" required>
  <button type="submit">Sign in</button>
</form>
${d.links && Object.keys(d.links).length ? contactCard(d.links, '') : ''}
<footer>Forgot your password or need the account details for a purchased key? Contact Orbit Office using the details above.</footer>`);

    case 'dashboard': {
      const rows = d.keys.length ? d.keys.map((k) => `
<tr>
  <td class="mono">${esc(k.masked)}</td>
  <td>${k.status === 'active' ? '<span class="pill ok">active</span>' : '<span class="pill bad">revoked</span>'}</td>
  <td>${k.bound ? `<span class="pill ok">bound</span><br><span style="color:var(--muted);font-size:12px">${esc(fmtDate(k.bound_at))}</span>` : '<span class="pill warn">not bound</span>'}</td>
  <td>${esc(fmtDate(k.last_seen_at))}</td>
  <td>${esc(k.expires_at ? fmtDate(k.expires_at) : 'never')}</td>
  <td>${k.reset.allowed ? '<span class="pill ok">available now</span>'
    : `<span class="pill warn">in ${esc(k.reset.days_remaining)} day(s)</span><br><span style="color:var(--muted);font-size:12px">from ${esc(fmtDate(k.reset.next_reset_at))}</span>`}</td>
  <td>
    <form method="post" action="/dashboard/reset">
      <input type="hidden" name="csrf" value="${esc(d.csrf)}">
      <input type="hidden" name="key_id" value="${esc(k.id)}">
      <button class="ghost" ${k.reset.allowed ? '' : 'disabled title="Cooldown active"'} style="margin:0">Reset hardware ID</button>
    </form>
  </td>
</tr>`).join('') : '<tr><td colspan="7" style="color:var(--muted)">No licences are attached to this account yet. Contact Orbit Office.</td></tr>';
      return layout('Dashboard', d.account, `
<h1>Your licences</h1>
<p class="lead">Signed in as <span class="mono">${esc(d.account.email)}</span> · self-service resets are limited to one every ${esc(d.cooldownDays)} days.</p>
<p style="color:var(--muted);font-size:13px;margin-top:-6px">Last sign-in: ${d.account.last_login_at ? esc(fmtDate(d.account.last_login_at)) : 'this is the first sign-in on this account'}.
If that was not you, use the sign-out card below and ask Orbit Office for a new password.</p>
<div class="card" style="overflow-x:auto"><table>
<thead><tr><th>Licence</th><th>Status</th><th>Hardware</th><th>Last check-in</th><th>Expires</th><th>Next reset</th><th></th></tr></thead>
<tbody>${rows}</tbody></table></div>
<div class="card">
  <h2 style="margin-top:0">Need a reset before the cooldown ends?</h2>
  <p style="color:var(--muted);margin:0">Contact Orbit Office for a work-related or urgent reset. Admin resets are logged.</p>
</div>
<div class="card">
  <h2 style="margin-top:0">Signed in on a shared or lost device?</h2>
  <p style="color:var(--muted);margin:0 0 12px">Signing out everywhere ends every browser session for this account, including this one. Your licences are not affected.</p>
  <form method="post" action="/dashboard/signout-all">
    <input type="hidden" name="csrf" value="${esc(d.csrf)}">
    <button class="ghost" type="submit" style="margin:0">Sign out of all devices</button>
  </form>
</div>
${contactCard(d.links || {}, '')}`, d.csrf);
    }

    case 'admin': {
      const rows = d.keys.length ? d.keys.map((k) => `
<tr>
  <td class="mono">${esc(k.masked)}</td>
  <td>${esc(k.account || '—')}</td>
  <td>${k.status === 'active' ? '<span class="pill ok">active</span>' : '<span class="pill bad">revoked</span>'}</td>
  <td>${k.bound ? '<span class="pill ok">bound</span>' : '<span class="pill warn">free</span>'}
    ${k.mismatches ? `<br><span class="pill bad" title="A different machine asked for this licence">${esc(k.mismatches)} mismatch(es)</span>${k.mismatch_domain ? `<br><span style="color:var(--muted);font-size:12px">last refused: ${esc(k.mismatch_domain)}</span>` : ''}` : ''}</td>
  <td>${k.domain ? `<span class="mono">${esc(k.domain)}</span>${k.first_domain && k.first_domain !== k.domain ? `<br><span style="color:var(--muted);font-size:12px">first seen on ${esc(k.first_domain)}</span>` : ''}`
    : '<span style="color:var(--muted)">not reported yet</span>'}
    ${k.php || k.version ? `<br><span style="color:var(--muted);font-size:12px">PHP ${esc(k.php || '?')} · shop ${esc(k.version || '?')}</span>` : ''}</td>
  <td>${esc(k.resets)}</td>
  <td>${esc(fmtDate(k.last_seen_at))}</td>
  <td>${esc(k.expires_at ? fmtDate(k.expires_at) : 'never')}</td>
  <td>
    <form method="post" action="/admin/override-reset" class="inline">
      <input type="hidden" name="csrf" value="${esc(d.csrf)}">
      <input type="hidden" name="key_id" value="${esc(k.id)}">
      <button class="danger" style="margin:0">Force reset</button>
    </form>
    <form method="post" action="/admin/revoke-key" class="inline">
      <input type="hidden" name="csrf" value="${esc(d.csrf)}">
      <input type="hidden" name="key_id" value="${esc(k.id)}">
      <button class="${k.status === 'active' ? 'danger' : 'ghost'}" name="action" value="${k.status === 'active' ? 'revoke' : 'restore'}" style="margin:0">${k.status === 'active' ? 'Revoke' : 'Restore'}</button>
    </form>
  </td>
</tr>`).join('') : '<tr><td colspan="9" style="color:var(--muted)">No keys yet.</td></tr>';
      const accountRows = (d.accounts || []).map((a) => `
<tr>
  <td class="mono">${esc(a.email)}${a.self ? ' <span class="pill">you</span>' : ''}</td>
  <td>${a.role === 'admin' ? 'administrator' : 'customer'}</td>
  <td>${a.disabled ? '<span class="pill bad">disabled</span>' : '<span class="pill ok">enabled</span>'}</td>
  <td>${esc(a.keys)}</td>
  <td>${esc(fmtDate(a.created_at))}</td>
  <td>
    <form method="post" action="/admin/account-action" class="inline">
      <input type="hidden" name="csrf" value="${esc(d.csrf)}">
      <input type="hidden" name="email" value="${esc(a.email)}">
      <button class="${a.disabled ? 'ghost' : 'danger'}" name="action" value="${a.disabled ? 'enable' : 'disable'}" ${a.self ? 'disabled title="This is your own account"' : ''}>${a.disabled ? 'Enable' : 'Disable'}</button>
    </form>
    <form method="post" action="/admin/account-action" class="inline">
      <input type="hidden" name="csrf" value="${esc(d.csrf)}">
      <input type="hidden" name="email" value="${esc(a.email)}">
      <button class="ghost" name="action" value="reset-password">New password</button>
    </form>
  </td>
</tr>`).join('') || '<tr><td colspan="6" style="color:var(--muted)">No accounts yet.</td></tr>';
      const audit = (d.audit || []).slice(0, 20).map((a) =>
        `<li>${esc(fmtDate(a.at))} · <span class="mono">${esc(a.actor)}</span> · ${esc(a.action)} · ${esc(a.target)} · ${esc(a.detail)}</li>`).join('');
      return layout('Admin', d.account, `
<h1>Licence administration</h1>
<p class="lead">Create customer accounts and keys, revoke a leaked key, override resets, and keep the public contact links current.</p>
${d.notice ? `<div class="card notice">${esc(d.notice)}</div>` : ''}
<p style="margin:-6px 0 20px"><a href="/admin/settings">Support &amp; sales links →</a></p>
<div class="grid">
  <div class="card">
    <h2 style="margin-top:0">Issue a licence key</h2>
    <form method="post" action="/admin/create-key">
      <input type="hidden" name="csrf" value="${esc(d.csrf)}">
      <label for="email">Customer email (account must exist)</label>
      <input id="email" name="email" type="email" required>
      <label for="expires_days">Expires in (days, blank = no expiry)</label>
      <input id="expires_days" name="expires_days" type="number" min="1" max="3650">
      <button type="submit">Create key</button>
    </form>
    <p style="color:var(--muted);font-size:13px;margin-bottom:0">The key is shown once and stored only as a hash.</p>
  </div>
  <div class="card">
    <h2 style="margin-top:0">Create a customer account</h2>
    <form method="post" action="/admin/create-account">
      <input type="hidden" name="csrf" value="${esc(d.csrf)}">
      <label for="account_email">Customer email</label>
      <input id="account_email" name="email" type="email" required>
      <label for="account_password">Password (leave blank to generate one and show it once)</label>
      <input id="account_password" name="password" type="text" minlength="10" autocomplete="new-password" placeholder="blank = generate">
      <label for="account_role">Role</label>
      <select id="account_role" name="role"><option value="user">customer</option><option value="admin">administrator</option></select>
      <button type="submit">Create account</button>
    </form>
    <p style="color:var(--muted);font-size:13px;margin-bottom:0">There is no public sign-up: only you can create accounts. Disabling an account signs it out immediately.</p>
  </div>
  <div class="card">
    <h2 style="margin-top:0">Recent activity</h2>
    <ul class="audit">${audit || '<li>No activity yet.</li>'}</ul>
  </div>
</div>
<div class="card" style="overflow-x:auto">
<h2 style="margin-top:0">Accounts</h2>
<table><thead><tr><th>Email</th><th>Role</th><th>Status</th><th>Keys</th><th>Created</th><th></th></tr></thead>
<tbody>${accountRows}</tbody></table></div>
<div class="card" style="overflow-x:auto">
<h2 style="margin-top:0">All licences</h2>
<table><thead><tr><th>Licence</th><th>Account</th><th>Status</th><th>Hardware</th><th>Installed on</th><th>Resets</th><th>Last check-in</th><th>Expires</th><th></th></tr></thead>
<tbody>${rows}</tbody></table></div>`, d.csrf);
    }

    case 'admin-settings': {
      const errors = d.errors && d.errors.length
        ? `<div class="card notice error"><strong>Some links were rejected — nothing was saved.</strong>
<ul style="margin:8px 0 0;padding-left:18px">${d.errors.map((e) => {
          const [platform, code] = String(e).split(':');
          const meta = PLATFORM_META[platform] || { label: platform };
          const reason = LINK_ERROR_TEXT[code] || LINK_ERROR_TEXT[String(e).split(':')[0]] || 'not accepted';
          return `<li>${esc(meta.label)} — ${esc(reason)} <span class="mono">(${esc(e)})</span></li>`;
        }).join('')}</ul></div>` : '';
      const saved = d.saved ? '<div class="card notice">Support links updated. They are live on the public page now.</div>' : '';
      return layout('Support links', d.account, `
<h1>Support &amp; sales links</h1>
<p class="lead">These links are shown on the public page. Update them here whenever an account is restricted —
customers always land on a working contact channel.</p>
${saved}${errors}
<form class="card" method="post" action="/admin/settings">
  <input type="hidden" name="csrf" value="${esc(d.csrf)}">
  <table>${linkRows(d.links || {}, d.platforms)}</table>
  <label for="sales_note">Short sales note (optional, shown under the links)</label>
  <input id="sales_note" name="sales_note" value="${esc(d.salesNote || '')}" maxlength="40" placeholder="Replies within 24h">
  <button type="submit">Save links</button>
</form>
<div class="card">
  <h2 style="margin-top:0">Adding a new channel</h2>
  <p style="color:var(--muted);margin:0">Leave a field blank to hide that link. Only http(s) links and valid email
  addresses are accepted; anything else is rejected rather than stored.</p>
  <a href="/admin"><button class="ghost">Back to admin</button></a>
</div>`, d.csrf);
    }

    case 'key-created': {
      if (!d.key) {
        return layout('Key not created', d.account || null, `
<h1>Licence key not created</h1>
<div class="card notice error">${esc(d.message || d.error || 'The key could not be created.')}</div>
<a href="/admin"><button class="ghost">Back to admin</button></a>`, d.csrf || null);
      }
      return layout('Key created', d.account || null, `
<h1>Licence key created</h1>
<div class="card notice">Copy this key now — it is displayed only this once and is stored hashed.</div>
<div class="card"><div class="keybox">${esc(d.key)}</div>
<p style="color:var(--muted)">Assigned to <span class="mono">${esc(d.email)}</span>. The customer enters it in
<span class="mono">Admin → Settings → licence key</span> on their shop.</p>
<a href="/admin"><button>Back to admin</button></a></div>`, d.csrf || null);
    }

    case 'account-created': {
      if (d.error) {
        return layout('Account not created', d.account || null, `
<h1>Account not created</h1>
<div class="card notice error">${esc(d.message || d.error)}</div>
<a href="/admin"><button class="ghost">Back to admin</button></a>`, d.csrf || null);
      }
      return layout(d.heading || 'Account created', d.account || null, `
<h1>${esc(d.heading || 'Customer account ready')}</h1>
${d.password ? `<div class="card notice">Copy this password now — it is shown only this once. The account holder can ask you for a new one at any time.</div>
<div class="card"><div class="keybox">${esc(d.password)}</div>` : `<div class="card notice">${esc(d.message || 'The account is ready.')}</div>`}
<div class="card">
<p style="color:var(--muted);margin-top:0">Account <span class="mono">${esc(d.email)}</span> · role <strong>${esc(d.role)}</strong></p>
<p style="color:var(--muted)">Next step: issue a licence key for this account from the admin page.</p>
<a href="/admin"><button class="ghost">Back to admin</button></a></div>`, d.csrf || null);
    }

    case 'not_found':
      return layout('Page not found', d.account || null, `
<h1>Page not found</h1>
<p class="lead">That address does not exist. It may be an old link, or a typo.</p>
<div class="card">
  <p style="color:var(--muted);margin-top:0">Useful places:</p>
  <p style="margin:0"><a href="/">Home</a> · <a href="/login">Sign in</a> · <a href="/healthz">Status</a></p>
</div>
<footer>Orbit Office licence portal</footer>`, d.csrf || null);

    case 'denied':
    default:
      return layout('Not available', null, `
<h1>Not available</h1>
<p class="lead">This page requires an administrator session.</p>
<a href="/login"><button>Sign in</button></a>`);
  }
}

module.exports = { renderPage, esc, layout };
