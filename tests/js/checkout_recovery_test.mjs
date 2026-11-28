/* Same-tab recovery only. All requests and storage below are fake; no credentials or money. */
import assert from "node:assert/strict";
import fs from "node:fs";
import vm from "node:vm";

const source = fs.readFileSync("public/client/orbit/store.js", "utf8");
const memory = new Map();
const storage = {
  getItem(key) {
    return memory.has(key) ? memory.get(key) : null;
  },
  setItem(key, value) {
    memory.set(key, value);
  },
  removeItem(key) {
    memory.delete(key);
  },
};
const settle = () => new Promise((resolve) => setImmediate(resolve));
async function page(buyer, store = storage, outcome = "lost", product = "101") {
  const handlers = {};
  const element = (name, value = "") => ({
    value,
    textContent: "",
    disabled: false,
    hidden: false,
    addEventListener(type, fn) {
      handlers[name + ":" + type] = fn;
    },
  });
  const amount = element("amount", "1"),
    coupon = element("coupon"),
    submit = element("submit"),
    reference = element("reference"),
    referenceValue = element("referenceValue"),
    help = element("help");
  reference.hidden = true;
  help.hidden = true;
  const status = {
    textContent: "",
    classList: { add() {}, remove() {} },
    appendChild() {},
  };
  const attrs = {
    "data-endpoint": "/mock",
    "data-product": product,
    "data-buyer": buyer,
    "data-token": "SENSITIVE-TOKEN",
    "data-api-key": "SENSITIVE-API-KEY",
    "data-min": "1",
    "data-max": "10",
  };
  const panel = {
    getAttribute: (key) => attrs[key] || null,
    querySelector: (key) =>
      ({
        "#amount": amount,
        "#coupon": coupon,
        "[data-buy-status]": status,
        "[data-buy-submit]": submit,
        "[data-buy-reference]": reference,
        "[data-buy-reference-value]": referenceValue,
        "[data-buy-help]": help,
      })[key] || null,
    querySelectorAll: () => [],
  };
  const document = {
    readyState: "complete",
    querySelector: (key) => (key === "[data-buy]" ? panel : null),
    querySelectorAll: () => [],
    addEventListener() {},
    createElement: () => ({}),
  };
  let sequence = 0;
  const window = {
    sessionStorage: store,
    crypto: { randomUUID: () => `01234567-89ab-cdef-0123-${String(++sequence).padStart(12, "0")}` },
    setTimeout,
    clearTimeout,
    addEventListener() {},
  };
  const calls = [];
  const fetch = async (_url, request) => {
    const data = new URLSearchParams(request.body);
    if (data.get("action") === "total_payment")
      return {
        json: async () => ({
          status: "success",
          money: "100",
          discount: "0",
          pay: "100",
        }),
      };
    assert.ok(
      store.getItem(`orbit:checkout:v1:${buyer}:${product}`),
      "marker is present before purchase fetch",
    );
    calls.push(data);
    if (outcome === "rejected")
      return { json: async () => ({ status: "error", msg: "Số dư không đủ" }) };
    if (outcome === "preflight")
      return { json: async () => ({ status: "error", outcome: "not_started", request_id: data.get("client_order_id"), msg: "Số dư không đủ" }) };
    if (outcome === "mismatch")
      return { json: async () => ({ status: "error", outcome: "not_started", request_id: "wrong-key", msg: "Không đủ" }) };
    if (outcome === "changed-marker") {
      store.setItem(`orbit:checkout:v1:${buyer}:${product}`, JSON.stringify({ version: 1, buyer, product, key: "another-reference" }));
      return { json: async () => ({ status: "error", outcome: "not_started", request_id: data.get("client_order_id") }) };
    }
    if (outcome === "malformed")
      return { json: async () => ({ status: "success" }) };
    if (outcome === "lost") throw new Error("lost");
    return {
      json: async () => ({
        status: "success",
        trans_id: "W0123456789abcdef0123456789abcd",
        msg: "ok",
      }),
    };
  };
  vm.runInNewContext(source, {
    window,
    document,
    fetch,
    URLSearchParams,
    Uint8Array,
    Promise,
    console,
  });
  await settle();
  return {
    amount,
    coupon,
    submit,
    status,
    reference,
    referenceValue,
    help,
    calls,
    click() {
      handlers["submit:click"]();
    },
  };
}

const first = await page("7");
first.click();
await settle();
assert.equal(first.calls.length, 1);
assert.equal(memory.size, 1, "attempt is stored before the request");
const saved = [...memory.values()][0];
const referenceKey = JSON.parse(saved).key;
assert.equal(first.reference.hidden, false);
assert.equal(
  first.referenceValue.textContent,
  referenceKey,
  "reference identifies the sent attempt",
);
assert.equal(first.help.hidden, false);
assert.ok(
  !saved.includes("SENSITIVE-TOKEN") && !saved.includes("SENSITIVE-API-KEY"),
  "no credentials stored",
);
first.click();
await settle();
assert.equal(
  first.calls.length,
  1,
  "unknown response never automatically or manually resends",
);
const restored = await page("7");
assert.match(restored.status.textContent, /lịch sử|hỗ trợ/i);
assert.equal(
  restored.referenceValue.textContent,
  referenceKey,
  "reload shows original reference",
);
assert.equal(restored.reference.hidden, false);
restored.click();
await settle();
assert.equal(
  restored.calls.length,
  0,
  "reload does not resend an unknown attempt",
);
const other = await page("8");
assert.doesNotMatch(
  other.status.textContent,
  /lịch sử|hỗ trợ/i,
  "another buyer is isolated",
);
const otherProduct = await page("7", storage, "lost", "102");
assert.doesNotMatch(
  otherProduct.status.textContent,
  /lịch sử|hỗ trợ/i,
  "another product is isolated",
);
memory.clear();
const completed = await page("7", storage, "success");
completed.click();
await settle();
assert.equal(completed.calls.length, 1);
assert.equal(memory.size, 0, "confirmed success removes the pending state");

const rejected = await page("7", storage, "rejected");
rejected.click();
await settle();
assert.match(rejected.status.textContent, /Số dư không đủ/);
assert.equal(
  rejected.submit.disabled,
  true,
  "unstructured error is treated as uncertain",
);
memory.clear();
const safe = await page("7", storage, "preflight");
safe.click();
await settle();
assert.equal(safe.submit.disabled, false, "verified preflight rejection allows a fresh manual attempt");
assert.equal(safe.amount.disabled, false);
assert.equal(memory.size, 0);
safe.click();
await settle();
assert.equal(safe.calls.length, 2);
assert.notEqual(safe.calls[0].get("client_order_id"), safe.calls[1].get("client_order_id"), "fresh request receives a new key");
memory.clear();
const mismatch = await page("7", storage, "mismatch");
mismatch.click();
await settle();
assert.equal(mismatch.submit.disabled, true, "a response for another request never clears the marker");
assert.equal(memory.size, 1);
memory.clear();
const changed = await page("7", storage, "changed-marker");
changed.click();
await settle();
assert.equal(changed.submit.disabled, true, "a changed marker must not be cleared");
assert.equal(JSON.parse([...memory.values()][0]).key, "another-reference");
memory.clear();
const stickyStorage = {
  getItem: storage.getItem,
  setItem: storage.setItem,
  removeItem() {},
};
const sticky = await page("7", stickyStorage, "preflight");
sticky.click();
await settle();
assert.equal(sticky.submit.disabled, true, "failed marker removal must stay blocked");
assert.equal(memory.size, 1);
memory.clear();
const malformed = await page("7", storage, "malformed");
malformed.click();
await settle();
assert.equal(
  malformed.submit.disabled,
  true,
  "malformed success cannot clear the marker",
);
assert.equal(memory.size, 1);
memory.clear();
const silent = {
  getItem() {
    return null;
  },
  setItem() {},
};
const unwritable = await page("7", silent);
unwritable.click();
await settle();
assert.equal(unwritable.calls.length, 0, "silent storage failure is detected");

const broken = {
  getItem() {
    throw new Error("blocked");
  },
  setItem() {
    throw new Error("blocked");
  },
};
const blocked = await page("7", broken);
blocked.click();
await settle();
assert.equal(blocked.calls.length, 0, "unavailable storage fails closed");
assert.match(blocked.status.textContent, /lịch sử|hỗ trợ/i);
assert.equal(
  blocked.reference.hidden,
  true,
  "storage failure before sending must not invent an attempt reference",
);
assert.equal(blocked.help.hidden, false);
const badKey = "orbit:checkout:v1:7:101";
memory.set(badKey, "{not-json");
const corrupt = await page("7");
corrupt.click();
await settle();
assert.equal(
  corrupt.calls.length,
  0,
  "corrupt state cannot silently become a fresh purchase",
);
assert.match(corrupt.status.textContent, /lịch sử|hỗ trợ/i);
assert.equal(
  corrupt.reference.hidden,
  true,
  "corrupt data must not be shown as a reference",
);
memory.set(
  badKey,
  JSON.stringify({
    version: 1,
    buyer: "7",
    product: "101",
    key: "<img src=x onerror=alert(1)>",
  }),
);
const injected = await page("7");
injected.click();
await settle();
assert.equal(injected.calls.length, 0);
assert.equal(
  injected.reference.hidden,
  true,
  "invalid reference is never rendered",
);
assert.equal(injected.referenceValue.textContent, "");
console.log(
  "checkout_recovery_test: recovery checks, 0 failed (mock DOM/sessionStorage/fetch)",
);
