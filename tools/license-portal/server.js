'use strict';
/*
 * Orbit Office — licence portal HTTP server.
 *
 * Zero runtime dependencies (Node stdlib only): small cold-start cost on a free
 * instance, nothing to audit in the dependency tree.
 *
 * This file is deliberately small: it owns the listening socket, the connect/read
 * timeouts, the HSTS decision and the process lifecycle. Routing, guards, storage,
 * configuration and the CLI live in sibling modules:
 *
 *   config.js    every environment setting, with safe fallbacks
 *   http.js      responses, body reading, cookies, client IP, rate limiter
 *   routes.js    pages, the /v1 API and the administrator operations
 *   store.js     file storage: atomic writes, hashing, key and HWID domain rules
 *   accounts.js  account operations shared by the HTTP routes and the CLI
 *   settings.js  admin-editable support/sales links
 *   cli.js       operator commands (--verify, --list-accounts, …)
 *   html.js      the server-rendered pages
 *
 * Security model: docs/frontend/03-license-portal-design.md, tests in test/portal.test.js.
 */

const http = require('http');
const { URL } = require('url');

const { config } = require('./config');
const { FileStore } = require('./store');
const { json, html, makeRateLimiter, clientIp } = require('./http');
const { renderPage } = require('./html');
const { createRouter } = require('./routes');
const { runCli, helpRequested, USAGE } = require('./cli');
const { createAccount, bootstrapAdmin, setAccountPassword, setAccountDisabled } = require('./accounts');

function createServer(options) {
  const cfg = Object.assign(config(), options || {});
  const store = cfg.store || new FileStore(cfg.dataDir);
  const limiter = makeRateLimiter();
  const ipOf = (req) => clientIp(req, cfg);

  if (!cfg.signingSecret || cfg.signingSecret.length < 32) {
    // Fail closed in production; an ephemeral secret in dev would silently invalidate
    // every licence token on restart, so it is refused rather than guessed.
    throw new Error('PORTAL_SIGNING_SECRET must be set to at least 32 characters');
  }

  const router = createRouter({ store, cfg, limiter, ipOf });


  const server = http.createServer(async (req, res) => {
    try {
      // Pick up changes made by another process (CLI account creation, admin script).
      if (typeof store.maybeReload === 'function') await store.maybeReload();
      const parsed = new URL(req.url, 'http://localhost');
      const pathName = parsed.pathname.replace(/\/+$/, '') || '/';

      if (await router.dispatch(req, res, pathName, parsed.searchParams)) return undefined;

      // Unknown path: a browser gets a proper page (a typo should not look like a crash),
      // an API client keeps getting JSON.
      const acceptsHtml = String(req.headers.accept || '').includes('text/html');
      if (acceptsHtml && (req.method === 'GET' || req.method === 'HEAD')) {
        return html(res, 404, renderPage('not_found'));
      }
      return json(res, 404, { error: 'not_found' });
    } catch (err) {
      const status = err && err.status ? err.status : 500;
      if (status >= 500) console.error('[portal] error:', err && err.message);
      return json(res, status, { error: status === 500 ? 'internal_error' : err.message });
    }
  });

  // Slow clients must not be able to hold a connection open indefinitely.
  server.headersTimeout = 20000;
  server.requestTimeout = 30000;
  server.keepAliveTimeout = 5000;
  server.timeout = 60000;

  if (cfg.secureCookies) {
    // Only meaningful over HTTPS; browsers ignore it on plain HTTP.
    server.on('request', (req, res) => {
      if (!res.headersSent) res.setHeader('strict-transport-security', 'max-age=15552000; includeSubDomains');
    });
  }

  return { server, store, cfg, limiter };
}


/*
 * `main()` is deliberately thin: parse the environment, open the store, hand over
 * to the CLI when a command was given, then serve. Everything else lives in the
 * modules above (config, http, settings, accounts, store, html, routes).
 */
async function main() {
  // Help and usage errors must work without touching (or creating) any data.
  if (helpRequested()) {
    console.log(USAGE);
    return;
  }

  const cfg = config();
  const store = new FileStore(cfg.dataDir);
  await store.init();

  if (await runCli(store, cfg)) return;

  // Loud, one-line warnings for the misconfigurations that quietly weaken the portal
  // in production (a shell-less host has no other place to notice them).
  if (cfg.secureCookies && !cfg.trustProxyHeaders && cfg.trustedProxyIps.length === 0) {
    console.warn('[portal] PUBLIC_BASE_URL is https but proxy headers are not trusted;' +
      ' on Render (or any reverse proxy) every visitor shares one rate-limit bucket.' +
      ' Set TRUST_PROXY_HEADERS=true unless the service is reachable directly.');
  }
  if (cfg.adminPassword && store.getAccountByEmail(cfg.adminEmail)) {
    console.warn('[portal] PORTAL_ADMIN_PASSWORD is still set while the admin account exists;' +
      ' remove it from the environment so the bootstrap password is not kept around.');
  }

  const { server } = createServer({ store, ...cfg });
  server.listen(cfg.port, '0.0.0.0', () => {
    console.log('orbit licence portal listening on 0.0.0.0:' + cfg.port);
    console.log('cooldown between self-service HWID resets:', cfg.cooldownDays, 'days');
    console.log('instance id:', process.pid, '| data dir:', cfg.dataDir);
  });
}

if (require.main === module) {
  main().catch((err) => {
    console.error('fatal:', err.message);
    process.exit(1);
  });
}

module.exports = { createServer, config, FileStore, createAccount, bootstrapAdmin, setAccountPassword, setAccountDisabled };
