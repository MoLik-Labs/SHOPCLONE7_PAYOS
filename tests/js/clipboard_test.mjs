/* Clipboard success, denial and unavailable API: deterministic DOM simulation, no real clipboard. */
import fs from "node:fs";
import vm from "node:vm";
import assert from "node:assert/strict";

async function storeCase(mode, literal = false) {
  const handlers = {};
  let selected = false;
  const attrs = literal
    ? { "data-copy-value": "secret-test" }
    : { "data-copy": "#invoice-address" };
  const button = {
    textContent: "Sao chép",
    getAttribute: (key) => (Object.hasOwn(attrs, key) ? attrs[key] : null),
    addEventListener: (type, fn) => {
      handlers[type] = fn;
    },
  };
  const input = {
    value: "address-test",
    select() {
      selected = true;
    },
  };
  const document = {
    readyState: "complete",
    querySelector: (key) => (key === "#invoice-address" ? input : null),
    querySelectorAll: (key) =>
      key === "[data-copy], [data-copy-value]:not([data-totp-copy])"
        ? [button]
        : [],
    addEventListener() {},
  };
  const navigator =
    mode === "absent"
      ? {}
      : {
          clipboard: {
            writeText: () =>
              mode === "reject"
                ? Promise.reject(new Error("permission denied"))
                : Promise.resolve(),
          },
        };
  const window = {
    setTimeout() {
      return 1;
    },
    clearTimeout() {},
    addEventListener() {},
  };
  vm.runInNewContext(fs.readFileSync("public/client/orbit/store.js", "utf8"), {
    window,
    document,
    navigator,
    Promise,
    console,
  });
  handlers.click();
  await new Promise((resolve) => setImmediate(resolve));
  return { label: button.textContent, selected };
}

assert.equal((await storeCase("success")).label, "Đã chép");
assert.equal((await storeCase("reject")).label, "Không chép được");
assert.equal((await storeCase("absent")).label, "Không chép được");
assert.equal(
  (await storeCase("absent")).selected,
  true,
  "select input for manual copy",
);
assert.equal((await storeCase("reject", true)).label, "Không chép được");

async function emojiCase(mode, fallbackWorks) {
  const source = fs.readFileSync("views/client/tool-icon-facebook.php", "utf8");
  const script = source.match(/<script>\s*([\s\S]*?)<\/script>/)[1];
  const status = { textContent: "" },
    handlers = {};
  const field = { setAttribute() {}, select() {} };
  const button = {
    getAttribute: () => "😀",
    addEventListener: (type, fn) => {
      handlers[type] = fn;
    },
    focus() {},
  };
  const document = {
    querySelector: () => status,
    querySelectorAll: () => [button],
    createElement: () => field,
    body: { appendChild() {} },
    execCommand: () => fallbackWorks,
  };
  const navigator =
    mode === "absent"
      ? {}
      : {
          clipboard: {
            writeText: () =>
              mode === "reject"
                ? Promise.reject(new Error("denied"))
                : Promise.resolve(),
          },
        };
  vm.runInNewContext(script, { document, navigator, Promise });
  handlers.click();
  await new Promise((resolve) => setImmediate(resolve));
  return status.textContent;
}
assert.equal(await emojiCase("success", false), "Đã chép 😀");
assert.equal(await emojiCase("reject", true), "Đã chép 😀");
assert.match(await emojiCase("reject", false), /không chép/i);
assert.match(await emojiCase("absent", false), /không chép/i);
console.log("clipboard_test: 9 checks, 0 failed (mock DOM/clipboard)");

async function orderDeniedCase() {
  const handlers = {};
  const status = { textContent: "", classList: { add() {}, remove() {} } };
  const selected = {
    checked: true,
    getAttribute: (key) =>
      key === "data-account" ? "test-account" : "test-uid",
  };
  const copyButton = {
    disabled: false,
    addEventListener: (event, fn) => {
      handlers[event] = fn;
    },
  };
  const root = {
    getAttribute: () => "",
    querySelector: (key) =>
      ({
        "[data-accounts-status]": status,
        "[data-accounts-copy]": copyButton,
      })[key] || null,
    querySelectorAll: (key) =>
      key === "[data-account-check]" ? [selected] : [],
    addEventListener() {},
  };
  const document = {
    readyState: "complete",
    querySelector: (key) => (key === "[data-order-detail]" ? root : null),
    querySelectorAll: () => [],
    addEventListener() {},
  };
  const navigator = {
    clipboard: { writeText: () => Promise.reject(new Error("denied")) },
  };
  const window = { addEventListener() {}, setTimeout() {}, clearTimeout() {} };
  vm.runInNewContext(fs.readFileSync("public/client/orbit/store.js", "utf8"), {
    window,
    document,
    navigator,
    Promise,
    console,
  });
  handlers.click();
  await new Promise((resolve) => setImmediate(resolve));
  return status.textContent;
}
assert.match(
  await orderDeniedCase(),
  /không chép/i,
  "denied order copy explains recovery",
);
console.log("clipboard_order_test: 1 check, 0 failed (mock DOM/clipboard)");
