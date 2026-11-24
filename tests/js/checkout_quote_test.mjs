/* No network or real money: control quote completion order and checkout errors deterministically. */
import fs from "node:fs";
import vm from "node:vm";
import assert from "node:assert/strict";

const handlers = {};
const control = (name, value = "") => ({
  name,
  value,
  textContent: "",
  disabled: false,
  hidden: false,
  addEventListener(type, fn) {
    handlers[`${name}:${type}`] = fn;
  },
});
const amount = control("amount", "1");
const coupon = control("coupon");
const submit = control("submit");
const retryQuote = control("retryQuote");
const pay = control("pay");
const money = control("money");
const discount = control("discount");
const status = {
  textContent: "",
  classList: { add() {}, remove() {} },
  appendChild() {},
};
const attrs = {
  "data-endpoint": "/checkout",
  "data-product": "101",
  "data-buyer": "7",
  "data-token": "token",
  "data-api-key": "test-key",
  "data-min": "1",
  "data-max": "10",
};
const panel = {
  getAttribute(key) {
    return attrs[key] || null;
  },
  querySelector(key) {
    return (
      {
        "#amount": amount,
        "#coupon": coupon,
        "[data-buy-status]": status,
        "[data-buy-submit]": submit,
        "[data-buy-retry-quote]": retryQuote,
        "[data-buy-pay]": pay,
        "[data-buy-money]": money,
        "[data-buy-discount]": discount,
      }[key] || null
    );
  },
  querySelectorAll() {
    return [];
  },
};
const document = {
  readyState: "complete",
  querySelector: (key) => (key === "[data-buy]" ? panel : null),
  querySelectorAll: () => [],
  addEventListener() {},
  createElement: () => ({}),
};
const quotes = [],
  purchases = [],
  timers = new Map();
let timerId = 0;
const storage = new Map();
const window = {
  sessionStorage: {
    getItem: (k) => storage.get(k) ?? null,
    setItem: (k, v) => storage.set(k, v),
    removeItem: (k) => storage.delete(k),
  },
  crypto: { randomUUID: () => "01234567-89ab-cdef-0123-456789abcdef" },
  setTimeout(fn) {
    const id = ++timerId;
    timers.set(id, fn);
    return id;
  },
  clearTimeout(id) {
    timers.delete(id);
  },
  addEventListener() {},
};
const fetch = (_url, request) => {
  const data = new URLSearchParams(request.body);
  if (data.get("action") === "total_payment") {
    return new Promise((resolve, reject) =>
      quotes.push({ resolve, reject, amount: data.get("amount") }),
    );
  }
  purchases.push(data);
  return Promise.reject(new Error("response lost"));
};
vm.runInNewContext(fs.readFileSync("public/client/orbit/store.js", "utf8"), {
  window,
  document,
  fetch,
  URLSearchParams,
  Uint8Array,
  Promise,
  console,
});
const settle = () => new Promise((resolve) => setImmediate(resolve));
const runTimer = () => {
  const [id, fn] = [...timers.entries()].at(-1);
  timers.delete(id);
  fn();
};
const answer = (quote, price) =>
  quote.resolve({
    json: async () => ({
      status: "success",
      pay: price,
      money: price,
      discount: "0",
    }),
  });

assert.equal(submit.disabled, true, "cannot buy before first quote resolves");
answer(quotes[0], "100");
await settle();
assert.equal(pay.textContent, "100");
assert.equal(submit.disabled, false);
amount.value = "2";
handlers["amount:input"]();
assert.equal(
  submit.disabled,
  true,
  "input immediately invalidates displayed quote",
);
assert.equal(money.textContent, "—", "stale subtotal is cleared");
assert.equal(discount.textContent, "—", "stale discount is cleared");
runTimer();
amount.value = "3";
handlers["amount:input"]();
answer(quotes[1], "200");
await settle();
assert.notEqual(
  pay.textContent,
  "200",
  "late quote cannot overwrite newer selection",
);
assert.equal(submit.disabled, true);
runTimer();
answer(quotes[2], "300");
await settle();
assert.equal(pay.textContent, "300");
assert.equal(submit.disabled, false);
amount.value = "4";
handlers["amount:input"]();
runTimer();
answer(quotes[3], "400");
await settle();
handlers["amount:change"]();
assert.equal(
  submit.disabled,
  false,
  "blur of the already quoted value must not start another quote",
);
amount.value = "2";
handlers["amount:input"]();
runTimer();
quotes[4].reject(new Error("offline"));
await settle();
assert.equal(
  submit.disabled,
  true,
  "failed quotes cannot be purchased as though current",
);
assert.equal(retryQuote.hidden, false, "failed quote offers a retry");
handlers["retryQuote:click"]();
answer(quotes[5], "200");
await settle();
assert.equal(retryQuote.hidden, true);
assert.equal(submit.disabled, false);
amount.value = "5"; // Simulate autofill or script changing a field without an event.
handlers["submit:click"]();
assert.equal(
  purchases.length,
  0,
  "unobserved input change must not submit an old quote",
);
assert.equal(
  submit.disabled,
  true,
  "unobserved change schedules a fresh quote",
);
runTimer();
answer(quotes[6], "500");
await settle();
handlers["submit:click"]();
await settle();
assert.equal(purchases.length, 1);
assert.equal(
  submit.disabled,
  true,
  "an uncertain result blocks another purchase in this tab",
);
assert.match(status.textContent, /lịch sử|hỗ trợ/i);
console.log("checkout_quote_test: all assertions passed (mock DOM/fetch)");
