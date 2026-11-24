/* Static fixture interactions only; clipboard denial is simulated and no payment is sent. */
const { createServer } = require("http"),
  fs = require("fs"),
  path = require("path");
const puppeteer = require("puppeteer-core");
const root = path.resolve(
  process.env.PREVIEW_ROOT || "/tmp/shopclone7-preview",
);
const types = {
  ".html": "text/html; charset=utf-8",
  ".css": "text/css",
  ".js": "application/javascript",
  ".svg": "image/svg+xml",
  ".woff2": "font/woff2",
  ".png": "image/png",
  ".jpg": "image/jpeg",
  ".webp": "image/webp",
};
(async () => {
  const server = createServer((req, res) => {
    let f = path.resolve(root, "." + decodeURIComponent(req.url.split("?")[0]));
    if (!f.startsWith(root + "/")) return res.writeHead(403).end();
    fs.readFile(f, (err, data) => {
      if (err) return res.writeHead(404).end();
      res.setHeader(
        "Content-Type",
        types[path.extname(f)] || "application/octet-stream",
      );
      res.end(data);
    });
  });
  await new Promise((r) => server.listen(0, "127.0.0.1", r));
  let browser = await puppeteer.launch({
    executablePath: process.env.CHROMIUM_PATH || "/tmp/chromium",
    headless: true,
    args: ["--no-sandbox", "--disable-dev-shm-usage"],
  });
  let page = await browser.newPage();
  let errors = [];
  page.on("pageerror", (e) => errors.push(String(e)));
  await page.setViewport({ width: 390, height: 844 });
  await page.evaluateOnNewDocument(() => {
    Object.defineProperty(navigator, "clipboard", {
      value: { writeText: () => Promise.reject(new Error("simulated denied")) },
      configurable: true,
    });
    document.execCommand = () => false;
  });
  let base = "http://127.0.0.1:" + server.address().port + "/";
  function ok(name, pass) {
    if (!pass) throw new Error("FAIL " + name);
    console.log("PASS " + name);
  }
  await page.goto(base + "home.html", { waitUntil: "networkidle0" });
  await page.click("[data-menu-toggle]");
  ok(
    "mobile menu opens",
    await page.$eval(
      "[data-menu-toggle]",
      (e) => e.getAttribute("aria-expanded") === "true",
    ),
  );
  await page.keyboard.press("Escape");
  ok(
    "Escape closes menu and restores focus",
    await page.evaluate(
      () =>
        document
          .querySelector("[data-menu-toggle]")
          .getAttribute("aria-expanded") === "false" &&
        document.activeElement === document.querySelector("[data-menu-toggle]"),
    ),
  );
  let f = await page.$eval(".catalog-bar__sort", (e) =>
    Object.fromEntries(new FormData(e)),
  );
  ok(
    "sort form includes home route",
    f.module === "client" && f.action === "home" && Object.hasOwn(f, "sort"),
  );
  await page.goto(base + "payment.html", { waitUntil: "networkidle0" });
  ok(
    "invoice input linked to visible label",
    await page.$eval(
      "#invoice-address",
      (e) =>
        e.labels.length === 1 &&
        e.labels[0].textContent.trim() === "Địa chỉ nhận",
    ),
  );
  await page.click('[data-copy="#invoice-address"]');
  await page.waitForFunction(
    () =>
      document.querySelector('[data-copy="#invoice-address"]').textContent ===
      "Không chép được",
  );
  ok("denied invoice copy reports failure", true);
  await page.goto(base + "tool-emoji.html", { waitUntil: "networkidle0" });
  await page.click("[data-emoji]");
  await page.waitForFunction(() =>
    document
      .querySelector("[data-emoji-status]")
      .textContent.includes("Không chép được"),
  );
  ok("emoji copy denial is not reported as success", true);
  ok(
    "emoji fallback restores focus",
    await page.evaluate(() =>
      document.activeElement.hasAttribute("data-emoji"),
    ),
  );
  await page.goto(base + "order.html", { waitUntil: "networkidle0" });
  const accountCopy = await page.$("[data-copy-value]");
  ok("preview contains an account copy control", accountCopy !== null);
  await accountCopy.click();
  await page.waitForFunction(
    () =>
      document.querySelector("[data-copy-value]").textContent ===
      "Không chép được",
  );
  ok("single account copy reports denial", true);
  ok("no browser page errors", errors.length === 0);
  await browser.close();
  server.close();
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
