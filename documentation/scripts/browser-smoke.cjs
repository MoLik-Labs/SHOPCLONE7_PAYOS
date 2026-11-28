/* Optional local smoke: run the built site preview first. Not a production or WCAG audit. */
const puppeteer = require('../../tools/dev/node_modules/puppeteer-core');
const root = process.env.DOCS_URL || 'http://127.0.0.1:4321/SHOPCLONE7_PAYOS/';
const executablePath = process.env.CHROMIUM_PATH || '/tmp/chromium';
(async () => {
  const browser = await puppeteer.launch({ executablePath, headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  const errors = [];
  try {
    for (const width of [390, 1440]) {
      const page = await browser.newPage();
      await page.setCacheEnabled(false);
      await page.setViewport({ width, height: 900 });
      page.on('pageerror', (error) => errors.push(`${width}: ${error.message}`));
      page.on('response', (response) => {
        if (response.url().startsWith(root) && !response.url().endsWith('/kiem-tra-404-khong-ton-tai/') && response.status() >= 400) errors.push(`${width}: ${response.status()} ${response.url()}`);
      });
      const home = await page.goto(root, { waitUntil: 'networkidle0' });
      if (home.status() !== 200) throw new Error(`home returned ${home.status()}`);
      const link = await page.$eval('a[href="./reference/"]', (anchor) => anchor.href);
      const reference = await page.goto(link, { waitUntil: 'networkidle0' });
      if (reference.status() !== 200) throw new Error(`reference returned ${reference.status()}`);
      const layout = await page.evaluate(() => ({ overflow: document.documentElement.scrollWidth > innerWidth,
        back: [...document.querySelectorAll('a')].some((a) => a.textContent.trim() === 'Tài liệu chính' && a.getAttribute('href') === '/SHOPCLONE7_PAYOS/') }));
      if (layout.overflow || !layout.back) throw new Error(`layout/back link failed at ${width}`);
      await page.click('.VPNavBarSearch button');
      await page.waitForSelector('.VPLocalSearchBox input');
      await page.type('.VPLocalSearchBox input', 'API_ALLOW_LEGACY_IMPORT_GET');
      await page.waitForFunction(() => [...document.querySelectorAll('.VPLocalSearchBox a')].some((a) => a.href.includes('/reference/api.html')), { timeout: 10000 });
      await page.keyboard.press('Escape');
      const debug = await page.goto(root + 'reference/debug.html', { waitUntil: 'networkidle0' });
      if (debug.status() !== 200) throw new Error(`debug returned ${debug.status()}`);
      await page.reload({ waitUntil: 'networkidle0' });
      if (!(await page.evaluate(() => document.body.innerText.includes('Debug theo triệu chứng')))) throw new Error('debug reload failed');
      await page.goto(root, { waitUntil: 'networkidle0' });
      await page.click('site-search button');
      await page.waitForSelector('dialog[open] input');
      await page.type('dialog[open] input', 'nhập kho');
      await page.waitForFunction(() => document.querySelector('dialog[open]')?.innerText.includes('kết quả'), { timeout: 10000 });
      await page.$eval('dialog[open] input', (input) => { input.value = ''; input.dispatchEvent(new Event('input', { bubbles: true })); });
      await page.type('dialog[open] input', 'lặp base');
      await page.waitForFunction(() => [...document.querySelectorAll('dialog[open] a')].some((a) => a.href.includes('/reference/pages.html')), { timeout: 10000 });
      const missing = await page.goto(root + 'kiem-tra-404-khong-ton-tai/', { waitUntil: 'networkidle0' });
      if (missing.status() !== 404 || !(await page.evaluate(() => document.body.innerText.includes('Đường dẫn có thể đã thay đổi')))) throw new Error('custom 404 failed');
      console.log(`${width}px: navigation, reload, shared and reference search, custom 404, layout OK`);
      await page.close();
    }
    if (errors.length) throw new Error(errors.join('\n'));
  } finally { await browser.close(); }
})().catch((error) => { console.error(error); process.exitCode = 1; });
