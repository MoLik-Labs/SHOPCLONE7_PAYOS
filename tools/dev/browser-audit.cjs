/* Static fixture smoke audit; not a replacement for interactive, DB or accessibility testing. */
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
    if (!f.startsWith(root + "/")) {
      res.writeHead(403).end();
      return;
    }
    fs.readFile(f, (e, data) => {
      if (e) {
        res.writeHead(404).end();
        return;
      }
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
  let files = fs
    .readdirSync(root)
    .filter((x) => x.endsWith(".html"))
    .sort();
  if (!files.length) throw new Error("No HTML files in " + root);
  let all = [];
  for (const file of files)
    for (const width of [1440, 390]) {
      const page = await browser.newPage(),
        errors = [],
        failed = [];
      page.on("pageerror", (e) => errors.push(String(e)));
      page.on("console", (e) => {
        if (e.type() === "error") errors.push(e.text());
      });
      page.on("requestfailed", (r) => failed.push(r.url()));
      page.on("response", (r) => {
        if (r.status() >= 400) failed.push(r.status() + " " + r.url());
      });
      await page.setViewport({ width, height: 900 });
      await page.goto(
        "http://127.0.0.1:" + server.address().port + "/" + file,
        { waitUntil: "networkidle0" },
      );
      let m = await page.evaluate(() => {
        let visible = (e) =>
          !!(e.offsetWidth || e.offsetHeight || e.getClientRects().length);
        return {
          overflow:
            document.documentElement.scrollWidth -
            document.documentElement.clientWidth,
          h1: document.querySelectorAll("h1").length,
          missingLabels: [
            ...document.querySelectorAll(
              "input:not([type=hidden]),select,textarea",
            ),
          ]
            .filter(
              (e) =>
                visible(e) &&
                !(e.labels && e.labels.length) &&
                !e.getAttribute("aria-label") &&
                !e.getAttribute("aria-labelledby") &&
                !e.getAttribute("title"),
            )
            .map((e) => e.outerHTML.slice(0, 200)),
          unnamedButtons: [...document.querySelectorAll("button")]
            .filter(
              (e) =>
                visible(e) &&
                !e.textContent.trim() &&
                !e.getAttribute("aria-label") &&
                !e.getAttribute("aria-labelledby") &&
                !e.getAttribute("title"),
            )
            .map((e) => e.outerHTML.slice(0, 200)),
        };
      });
      all.push({ file, width, ...m, errors, failed });
      await page.close();
    }
  if (process.env.BROWSER_REPORT)
    fs.writeFileSync(process.env.BROWSER_REPORT, JSON.stringify(all, null, 2));
  for (const x of all) {
    if (
      x.overflow ||
      x.h1 !== 1 ||
      x.missingLabels.length ||
      x.unnamedButtons.length ||
      x.errors.length ||
      x.failed.length
    )
      console.log(JSON.stringify(x));
  }
  const violations = all.filter(
    (x) =>
      x.overflow > 0 ||
      x.errors.length ||
      x.failed.length ||
      x.h1 !== 1 ||
      x.missingLabels.length ||
      x.unnamedButtons.length,
  );
  console.log(
    `Checked ${files.length} pages, ${all.length} page/viewport combinations; ${violations.length} failing combinations`,
  );
  await browser.close();
  server.close();
  if (violations.length) process.exitCode = 1;
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
