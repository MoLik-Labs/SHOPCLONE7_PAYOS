'use strict';
/*
 * Orbit Office — HTTP plumbing: responses, request-body reading, cookie parsing,
 * client address resolution and the in-memory rate limiter.
 *
 * Security headers are applied here so no route can forget them. A caller that
 * sends a malformed cookie or aborts mid-body gets a normal answer instead of a
 * 500 or a leaked promise (see docs/frontend/06, bugs B6 and the body reader).
 */

const crypto = require('crypto');

/* ------------------------------------------------------------------ helpers */

// A same-origin image is allowed for the favicon only; scripts, frames, fonts and
// external content stay blocked.
const CSP = "default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'";

/** 429 with the standard Retry-After header, so clients back off politely. */
function tooMany(res, retryAfterSeconds, body) {
  res.setHeader('retry-after', String(Math.max(1, Math.ceil(retryAfterSeconds || 1))));
  return json(res, 429, body || { error: 'rate_limited' });
}

/** 405 with the methods the path does accept. */
function methodNotAllowed(res, allowed) {
  res.setHeader('allow', allowed.join(', '));
  return json(res, 405, { error: 'method_not_allowed', allow: allowed });
}

const MAX_JSON_BODY = 16 * 1024;
const MAX_FORM_BODY = 32 * 1024;

/**
 * Headers carried by every response. Declared once so a new route cannot forget
 * them: no sniffing, no caching of licence data, no search indexing, no framing.
 */
const SECURITY_HEADERS = {
  'cache-control': 'no-store',
  'x-content-type-options': 'nosniff',
  'x-robots-tag': 'noindex, nofollow, noarchive',
};

function json(res, status, body) {
  const payload = JSON.stringify(body);
  res.writeHead(status, Object.assign({}, SECURITY_HEADERS, {
    'content-type': 'application/json; charset=utf-8',
    'content-length': Buffer.byteLength(payload),
  }));
  return res.end(payload);
}

/** Plain-text response (robots.txt and other non-HTML, non-JSON bodies). */
function text(res, status, body) {
  res.writeHead(status, Object.assign({}, SECURITY_HEADERS, {
    'content-type': 'text/plain; charset=utf-8',
    'content-length': Buffer.byteLength(body),
  }));
  return res.end(body);
}

function html(res, status, body, extraHeaders) {
  res.writeHead(status, Object.assign({}, SECURITY_HEADERS, {
    'content-type': 'text/html; charset=utf-8',
    'x-frame-options': 'DENY',
    'referrer-policy': 'no-referrer',
    'content-security-policy': CSP,
  }, extraHeaders || {}));
  return res.end(body);
}

function redirect(res, location) {
  res.statusCode = 302;
  res.setHeader('location', location);
  return res.end();
}

function constantTimeEqual(a, b) {
  const x = Buffer.from(String(a || ''));
  const y = Buffer.from(String(b || ''));
  return x.length === y.length && crypto.timingSafeEqual(x, y);
}

function parseCookies(header) {
  const out = {};
  String(header || '').split(';').forEach((part) => {
    const i = part.indexOf('=');
    if (i <= 0) return;
    const name = part.slice(0, i).trim();
    const raw = part.slice(i + 1).trim();
    let value = raw;
    try {
      value = decodeURIComponent(raw);
    } catch {
      // A malformed escape (for example a truncated cookie) must not turn into a 500:
      // treat the raw value as the cookie and let session lookup fail normally.
      value = raw;
    }
    out[name] = value;
  });
  return out;
}

/**
 * Read a request body with a hard cap.
 *
 * An oversized body is DRAINED and answered with 413 rather than destroying the
 * socket: a reset gives the caller no status, breaks proxies and hides the real
 * reason (the penetration test caught this as a P2).
 */
function readLimitedBody(req, limit, hardLimit, onComplete) {
  return new Promise((resolve, reject) => {
    let size = 0;
    let tooLarge = false;
    let hardStop = false;
    let settled = false;
    const chunks = [];
    const fail = (err) => {
      if (settled) return;
      settled = true;
      reject(err);
    };
    const finish = (fn) => {
      if (settled) return;
      settled = true;
      try {
        resolve(fn());
      } catch (err) {
        reject(err);
      }
    };
    req.on('data', (c) => {
      size += c.length;
      if (size > hardLimit) {
        hardStop = true;
        chunks.length = 0;
        req.destroy();
        return;
      }
      if (size > limit) {
        tooLarge = true;
        chunks.length = 0;
        return;                       // keep consuming so the request can be answered
      }
      chunks.push(c);
    });
    req.on('end', () => {
      if (tooLarge) return fail(Object.assign(new Error('payload_too_large'), { status: 413 }));
      return finish(() => onComplete(Buffer.concat(chunks).toString('utf8')));
    });
    req.on('error', (err) => {
      if (hardStop) return fail(Object.assign(new Error('payload_too_large'), { status: 413 }));
      return fail(err);
    });
    req.on('close', () => {
      // The client hung up (or the server timed the request out) before the body
      // ended. Without this the promise never settles and the handler leaks.
      if (hardStop) return fail(Object.assign(new Error('payload_too_large'), { status: 413 }));
      if (!req.readableEnded) return fail(Object.assign(new Error('client_closed'), { status: 400 }));
      return undefined;
    });
  });
}

/**
 * Whole days until a key expires. Blank/0/never = no expiry; anything else must be a
 * plain integer in range, so a typo cannot silently create a never-expiring key
 * (`"abc"` used to become NaN, which JSON turns into null = never expires).
 */
async function readJsonBody(req, limitBytes) {
  const limit = limitBytes || MAX_JSON_BODY;
  // The hard cap only exists to bound absurd uploads; it must sit above anything a
  // normal client sends so the soft limit can answer with a proper 413 first.
  return readLimitedBody(req, limit, Math.max(limit * 8, 8 * 1024 * 1024), (raw) => {
    if (!raw) return {};
    try {
      return JSON.parse(raw);
    } catch {
      throw Object.assign(new Error('invalid_json'), { status: 400 });
    }
  });
}

async function readFormBody(req) {
  const limit = MAX_FORM_BODY;
  return readLimitedBody(req, limit, Math.max(limit * 8, 8 * 1024 * 1024), (raw) => {
    const params = new URLSearchParams(raw);
    const out = {};
    params.forEach((v, k) => { out[k] = v; });
    return out;
  });
}

/** Strip an IPv6-mapped IPv4 prefix so the same client always hashes to one bucket. */
function normaliseIp(ip) {
  const v = String(ip || '').trim();
  return v.startsWith('::ffff:') ? v.slice(7) : v;
}

/**
 * Client address used for rate limiting.
 *
 * X-Forwarded-For is attacker-controlled unless a trusted proxy overwrites it,
 * so it is only honoured when the deployment opts in (TRUST_PROXY_HEADERS=true)
 * or the immediate peer is listed in TRUSTED_PROXY_IPS. When trusted, the
 * RIGHTMOST hop is used: that is the value the nearest proxy appended, whereas
 * anything further left may have been supplied by the client.
 */
function clientIp(req, cfg) {
  const peer = normaliseIp(req.socket.remoteAddress);
  const trusted = Boolean(cfg && (cfg.trustProxyHeaders || (cfg.trustedProxyIps || []).indexOf(peer) >= 0));
  if (trusted) {
    const fwd = req.headers['x-forwarded-for'];
    if (typeof fwd === 'string' && fwd.trim()) {
      const hops = fwd.split(',').map((h) => normaliseIp(h)).filter(Boolean);
      if (hops.length) return hops[hops.length - 1];
    }
  }
  return peer || 'unknown';
}

function makeRateLimiter() {
  const buckets = new Map();
  let lastPrune = 0;
  function prune(now) {
    // Without this the bucket map grows with every distinct client address and is
    // never released (noted in the penetration test as a slow memory leak).
    if (now - lastPrune < 60000 && buckets.size < 1000) return;
    lastPrune = now;
    buckets.forEach((b, k) => {
      if (now >= b.resetAt) buckets.delete(k);
    });
  }
  function allow(key, limit, windowMs, nowMs) {
    const now = nowMs || Date.now();
    prune(now);
    const b = buckets.get(key);
    if (!b || now >= b.resetAt) {
      buckets.set(key, { count: 1, resetAt: now + windowMs });
      return { allowed: true, retryAfter: 0 };
    }
    if (b.count >= limit) {
      return { allowed: false, retryAfter: Math.ceil((b.resetAt - now) / 1000) };
    }
    b.count += 1;
    return { allowed: true, retryAfter: 0 };
  }
  allow.size = () => buckets.size;
  return allow;
}

module.exports = {
  json, html, text, redirect, tooMany, methodNotAllowed, constantTimeEqual, parseCookies, readLimitedBody,
  readJsonBody, readFormBody, normaliseIp, clientIp, makeRateLimiter,
  SECURITY_HEADERS, MAX_JSON_BODY, MAX_FORM_BODY,
};
