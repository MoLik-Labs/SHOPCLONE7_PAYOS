import puppeteer from 'puppeteer-core';
import { createServer } from 'http';
import { readFile } from 'fs/promises';
import { existsSync, mkdirSync, writeFileSync } from 'fs';
import { extname, join, resolve } from 'path';

/* Screenshot the static preview at several widths and report overflow / console errors.
   The pages are served over HTTP because file:// blocks the self-hosted font as a cross-origin
   request — the page would silently render in a fallback face.

     npm i --prefix tools/dev
     node -e '...decompress chromium...'  (see docs/skills/project/php-monolith-debug)
     LD_LIBRARY_PATH=/tmp/al2023/lib node tools/dev/browser.mjs /tmp/shots docs/frontend/preview/home.html --widths=1440,390 */
const root = resolve('docs/frontend/preview');
const types = { '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'text/javascript',
  '.woff2': 'font/woff2', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg', '.webp': 'image/webp', '.json': 'application/json', '.ico': 'image/x-icon' };
const server = createServer(async (req, res) => {
  const file = join(root, decodeURIComponent(req.url.split('?')[0]));
  if (!file.startsWith(root) || !existsSync(file)) { res.statusCode = 404; return res.end('not found'); }
  try {
    res.setHeader('Content-Type', types[extname(file)] || 'application/octet-stream');
    res.end(await readFile(file));
  } catch { res.statusCode = 500; res.end('error'); }
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const base = `http://127.0.0.1:${server.address().port}/`;

const outDir = process.argv[2] || '/tmp/shots';
const pages = process.argv.slice(3).filter((a) => !a.startsWith('--'));
const widthArg = process.argv.find((a) => a.startsWith('--widths='));
const widths = (widthArg ? widthArg.split('=')[1] : '1440').split(',').map(Number);
mkdirSync(outDir, { recursive: true });

const browser = await puppeteer.launch({
  executablePath: process.env.CHROMIUM_PATH || '/tmp/chromium',
  headless: true,
  args: ['--no-sandbox', '--disable-dev-shm-usage', '--font-render-hinting=none'],
});
const report = [];
for (const file of pages) {
  const name = file.split('/').pop().replace(/\.html$/, '');
  for (const width of widths) {
    const page = await browser.newPage();
    const errors = [];
    const failed = [];
    page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('requestfailed', (r) => failed.push(r.url()));
    await page.setViewport({ width, height: 900, deviceScaleFactor: 1 });
    await page.goto(base + name + '.html', { waitUntil: 'networkidle0' });
    await page.evaluate(() => document.fonts && document.fonts.ready);
    const m = await page.evaluate(() => ({
      scrollW: document.documentElement.scrollWidth,
      clientW: document.documentElement.clientWidth,
      h: document.documentElement.scrollHeight,
      h1: document.querySelectorAll('h1').length,
      inlineStyles: document.querySelectorAll('[style]').length,
    }));
    await page.screenshot({ path: `${outDir}/${name}-${width}.png`, fullPage: true });
    const overflow = Math.max(0, m.scrollW - m.clientW);
    report.push({ page: name, width, height: m.h, overflow, h1: m.h1, inlineStyles: m.inlineStyles, errors: errors.length, failed: failed.length });
    console.log(`${name} @${width} h${m.h} overflow ${overflow} bytes h1=${m.h1} inline=${m.inlineStyles} errors=${errors.length} failed=${failed.length}`);
    await page.close();
  }
}
writeFileSync(`${outDir}/report.json`, JSON.stringify(report, null, 2));
await browser.close();
server.close();
