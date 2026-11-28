/* Real Chromium against a synthetic checkout page and local mock endpoint only. No production data. */
const { createServer } = require("node:http");
const { readFileSync } = require("node:fs");
const { resolve } = require("node:path");
const puppeteer = require("puppeteer-core");

const script = readFileSync(
  resolve(__dirname, "../../public/client/orbit/store.js"),
);
const css = readFileSync(
  resolve(__dirname, "../../public/client/orbit/store.css"),
);
const html = `<!doctype html><html lang="vi"><head><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/store.css"></head><body>
<div data-buy data-endpoint="/checkout" data-product="101" data-buyer="7" data-token="fixture-token"
 data-api-key="fixture-key" data-min="1" data-max="10" data-order-url="/order/">
<label for="amount">Số lượng</label><input id="amount" value="1">
<label for="coupon">Mã giảm giá</label><input id="coupon">
<span data-buy-money></span><span data-buy-discount></span><span data-buy-pay></span>
<button data-buy-submit disabled>Thanh toán</button>
<button data-buy-retry-quote hidden>Thử tính lại</button>
<p data-buy-status role="status"></p><p class="buy-panel__reference" data-buy-reference hidden>Mã tham chiếu yêu cầu (không phải mã đơn hàng): <code data-buy-reference-value></code></p><p data-buy-help hidden><a href="/orders">Lịch sử</a> <a href="/contact">Liên hệ</a></p></div><script src="/store.js"></script></body></html>`;

(async () => {
  let rejectQuote = false;
  let preflightReject = true;
  const purchases = [];
  const server = createServer((req, res) => {
    if (req.method === "GET" && req.url === "/") {
      res.writeHead(200, { "Content-Type": "text/html; charset=utf-8" });
      return res.end(html);
    }
    if (req.method === "GET" && req.url === "/store.css") {
      res.writeHead(200, { "Content-Type": "text/css" });
      return res.end(css);
    }
    if (req.method === "GET" && req.url === "/store.js") {
      res.writeHead(200, { "Content-Type": "application/javascript" });
      return res.end(script);
    }
    if (req.method === "POST" && req.url === "/checkout") {
      let body = "";
      req.on("data", (chunk) => {
        body += chunk;
      });
      return req.on("end", () => {
        const params = new URLSearchParams(body);
        if (params.get("action") === "buyProduct") {
          purchases.push(Object.fromEntries(params));
          if (preflightReject) {
            preflightReject = false;
            res.writeHead(200, { "Content-Type": "application/json; charset=utf-8" });
            return res.end(JSON.stringify({ status: "error", outcome: "not_started", request_id: params.get("client_order_id"), msg: "Số dư không đủ" }));
          }
          // An invalid gateway response leaves the result unknown without a real purchase.
          res.writeHead(502, { "Content-Type": "text/plain" });
          return res.end("fixture gateway error");
        }
        res.writeHead(200, {
          "Content-Type": "application/json; charset=utf-8",
        });
        res.end(
          JSON.stringify(
            rejectQuote
              ? { status: "error", msg: "Không tính được tổng tiền." }
              : {
                  status: "success",
                  money: params.get("amount") + "00",
                  discount: "0",
                  pay: params.get("amount") + "00",
                },
          ),
        );
      });
    }
    res.writeHead(404).end();
  });
  await new Promise((resolveReady) =>
    server.listen(0, "127.0.0.1", resolveReady),
  );
  let browser;
  try {
    browser = await puppeteer.launch({
      executablePath: process.env.CHROMIUM_PATH || "/tmp/chromium",
      headless: true,
      args: ["--no-sandbox", "--disable-dev-shm-usage"],
    });
    const page = await browser.newPage();
    const errors = [];
    page.on("pageerror", (error) => errors.push(String(error)));
    const assert = (name, value) => {
      if (!value) throw new Error("FAIL " + name);
      console.log("PASS " + name);
    };
    await page.goto("http://127.0.0.1:" + server.address().port + "/", {
      waitUntil: "networkidle0",
    });
    await page.waitForFunction(
      () => !document.querySelector("[data-buy-submit]").disabled,
    );
    assert(
      "initial quote enables checkout",
      await page.$eval("[data-buy-pay]", (e) => e.textContent === "100"),
    );
    await page.$eval("#amount", (input) => {
      input.value = "2";
      input.dispatchEvent(new Event("input", { bubbles: true }));
    });
    assert(
      "changed amount invalidates quote immediately",
      await page.$eval("[data-buy-submit]", (e) => e.disabled),
    );
    await page.waitForFunction(
      () =>
        document.querySelector("[data-buy-pay]").textContent === "200" &&
        !document.querySelector("[data-buy-submit]").disabled,
    );
    rejectQuote = true;
    await page.$eval("#amount", (input) => {
      input.value = "3";
      input.dispatchEvent(new Event("input", { bubbles: true }));
    });
    await page.waitForFunction(
      () => !document.querySelector("[data-buy-retry-quote]").hidden,
    );
    assert(
      "quote failure blocks checkout",
      await page.$eval("[data-buy-submit]", (e) => e.disabled),
    );
    rejectQuote = false;
    await page.click("[data-buy-retry-quote]");
    await page.waitForFunction(
      () =>
        document.querySelector("[data-buy-retry-quote]").hidden &&
        !document.querySelector("[data-buy-submit]").disabled,
    );
    assert(
      "quote can be retried",
      await page.$eval("[data-buy-pay]", (e) => e.textContent === "300"),
    );
    await page.click("[data-buy-submit]");
    await page.waitForFunction(() => document.querySelector("[data-buy-status]").textContent.includes("Số dư không đủ"));
    assert("confirmed preflight rejection allows a manual new attempt",
      purchases.length === 1 && await page.$eval("[data-buy-submit]", e => !e.disabled) &&
      await page.evaluate(() => sessionStorage.length === 0));
    await page.click("[data-buy-submit]");
    await page.waitForFunction(() =>
      document
        .querySelector("[data-buy-status]")
        .textContent.includes("Chưa xác định"),
    );
    assert("new attempt uses a different reference", purchases.length === 2 && purchases[0].client_order_id !== purchases[1].client_order_id);
    assert(
      "lost response blocks retry",
      purchases.length === 2 &&
        (await page.$eval("[data-buy-submit]", (e) => e.disabled)),
    );
    await page.reload({ waitUntil: "networkidle0" });
    assert(
      "reload retains warning and prevents retry",
      purchases.length === 2 &&
        (await page.$eval("[data-buy-submit]", (e) => e.disabled)) &&
        (await page.$eval("[data-buy-status]", (e) =>
          e.textContent.includes("lịch sử"),
        )),
    );
    const reference = await page.$eval(
      "[data-buy-reference-value]",
      (el) => el.textContent,
    );
    assert(
      "reference matches the uncertain attempt after reload",
      reference === purchases[1].client_order_id &&
        !(await page.$eval("[data-buy-reference]", (e) => e.hidden)),
    );
    assert(
      "support and history links become available",
      await page.$eval(
        "[data-buy-help]",
        (e) => !e.hidden && e.querySelectorAll("a").length === 2,
      ),
    );
    const stored = await page.evaluate(() => JSON.stringify(sessionStorage));
    assert(
      "storage contains no fixture credentials",
      !stored.includes("fixture-token") && !stored.includes("fixture-key"),
    );
    await page.setViewport({ width: 320, height: 700 });
    await page.evaluate(() => {
      const key = "orbit:checkout:v1:7:101";
      const saved = JSON.parse(sessionStorage.getItem(key));
      saved.key = "a".repeat(64);
      sessionStorage.setItem(key, JSON.stringify(saved));
    });
    await page.reload({ waitUntil: "networkidle0" });
    assert(
      "long reference wraps on narrow viewport",
      await page.evaluate(
        () => document.documentElement.scrollWidth <= window.innerWidth,
      ),
    );
    assert("no browser page errors", errors.length === 0);
  } finally {
    if (browser) await browser.close();
    await new Promise((resolveClosed) => server.close(resolveClosed));
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
