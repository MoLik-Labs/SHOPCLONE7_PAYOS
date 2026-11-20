/* A deterministic DOM/fetch simulation: a retry after an uncertain failure must keep its key. */
import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const handlers = {};
const events = () => ({ addEventListener(type, fn) { handlers[this.name + ':' + type] = fn; } });
const amount = { ...events(), name: 'amount', value: '1' };
const submit = { ...events(), name: 'submit', disabled: false, textContent: '' };
const status = { textContent: '', classList: { add() {}, remove() {} }, appendChild() {} };
const attrs = { 'data-endpoint': '/checkout', 'data-product': '101', 'data-token': 'test', 'data-api-key': 'test', 'data-min': '1', 'data-max': '10' };
const panel = {
  getAttribute(name) { return attrs[name] || null; },
  querySelector(name) { return ({ '#amount': amount, '[data-buy-status]': status, '[data-buy-submit]': submit })[name] || null; },
  querySelectorAll() { return []; },
};
const document = { readyState: 'complete', querySelector(s) { return s === '[data-buy]' ? panel : null; }, querySelectorAll() { return []; }, addEventListener() {}, createElement() { return {}; } };
const orders = [];
let attempts = 0;
const fetch = async (_url, request) => {
  const data = new URLSearchParams(request.body);
  if (data.get('action') === 'total_payment') return { json: async () => ({ status: 'success' }) };
  orders.push(data.get('client_order_id'));
  attempts++;
  if (attempts === 1) throw new Error('lost response');
  return { json: async () => ({ status: 'error', msg: 'Still processing' }) };
};
const window = { crypto: { randomUUID: () => '01234567-89ab-cdef-0123-456789abcdef' }, setTimeout, clearTimeout, addEventListener() {} };
const context = { window, document, fetch, URLSearchParams, Uint8Array, Promise, console, setTimeout, clearTimeout };
vm.runInNewContext(fs.readFileSync('public/client/orbit/store.js', 'utf8'), context);
const settle = () => new Promise(resolve => setImmediate(resolve));
assert.equal(typeof handlers['submit:click'], 'function');
handlers['submit:click']();
await settle();
assert.equal(submit.disabled, false, 'network failure enables retry');
handlers['submit:click']();
await settle();
assert.equal(submit.disabled, false, 'server error enables retry');
handlers['submit:click']();
await settle();
assert.equal(orders.length, 3);
assert.ok(orders[0]);
assert.deepEqual(orders, [orders[0], orders[0], orders[0]], 'both errors retain the original key');
console.log('checkout_retry_test: 6 checks, 0 failed (mock DOM/fetch)');
