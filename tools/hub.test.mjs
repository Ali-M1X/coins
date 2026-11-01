/**
 * The coin hub pages and blocks (2.16.0), in a real browser.
 *
 * Runs against hub-ethereum.html, hub-learn.html and hub-article.html (written
 * by THB_HUB_DIR=$PWD php tools/hub.test.php). WebSockets are stubbed; every
 * request the page makes is counted, and any to an external host other than
 * an image is a failure.
 */
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { extname, join, normalize } from 'node:path';

const ROOT = new URL('..', import.meta.url).pathname;
let failed = 0;
const ok = (c, m) => { if (!c) failed++; console.log(`${c ? 'PASS' : 'FAIL'}  ${m}`); };
for (const f of ['hub-ethereum.html', 'hub-learn.html', 'hub-article.html']) {
  if (!existsSync(join(ROOT, f))) { console.log(`FAIL  ${f} missing — run THB_HUB_DIR=$PWD php tools/hub.test.php first`); process.exit(1); }
}
const TYPES = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8' };
const server = createServer(async (req, res) => {
  try {
    const path = join(ROOT, normalize(decodeURIComponent(req.url.split('?')[0])));
    const body = await readFile(path);
    res.writeHead(200, { 'Content-Type': TYPES[extname(path)] ?? 'application/octet-stream' });
    res.end(body);
  } catch { res.writeHead(404).end(); }
});
await new Promise((r) => server.listen(8139, r));
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

const open = async (file, width) => {
  const page = await browser.newPage({ viewport: { width, height: 900 } });
  const errors = [];
  const external = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.addInitScript(() => { window.WebSocket = class { constructor() { this.readyState = 0; } send() {} close() {} }; });
  await page.route(/^https?:\/\/(?!localhost)/, (r) => { if (r.request().resourceType() !== 'image') external.push(r.request().url()); r.abort(); });
  await page.goto(`http://localhost:8139/${file}`, { waitUntil: 'load' });
  await page.waitForTimeout(150);
  return { page, errors, external };
};

for (const width of [1440, 360]) {
  for (const file of ['hub-ethereum.html', 'hub-learn.html', 'hub-article.html']) {
    const { page, errors, external } = await open(file, width);
    const sideways = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    const outside = await page.$$eval('.thb-b', (els) => els.filter((e) => { const r = e.getBoundingClientRect(); return r.width > 0 && (r.left < -1 || r.right > window.innerWidth + 1); }).length);
    ok(!sideways && outside === 0 && errors.length === 0 && external.length === 0,
      `${file} at ${width}px: no sideways scroll, every block inside the screen, no script error, no external request` +
      (errors.length ? ' — ' + errors.join('; ') : '') + (external.length ? ' — ' + external.join(', ') : ''));
    await page.close();
  }
}

{
  const { page } = await open('hub-article.html', 1440);
  const lazy = await page.$$eval('.thb-b img, .thb-b-card img', (imgs) => imgs.every((i) => i.loading === 'lazy' && i.width > 0 && i.height > 0));
  ok(lazy, 'block and card images are lazy-loaded and carry width/height (no layout shift)');
  const conv = page.locator('[data-thb-conv]');
  await conv.locator('[data-thb-conv-in]').fill('۲');
  const usd = await conv.locator('[data-thb-conv-out]').textContent();
  await conv.locator('[data-thb-conv-unit]').selectOption('toman');
  const toman = await conv.locator('[data-thb-conv-out]').textContent();
  ok(usd.startsWith('۲ اتریوم = ') && usd.endsWith(' دلار') && toman.endsWith(' تومان'), `mini converter answers in the browser: «${usd}» / «${toman}»`);
  const tocLinks = await page.$$eval('.thb-b--toc a', (as) => as.every((a) => document.getElementById(decodeURIComponent(a.hash.slice(1)))));
  ok(tocLinks, 'every table-of-contents link points at a heading on the page');
  const card = await page.$eval('.hub-article__body .thb-b--price', (a) => ({ tag: a.tagName, href: a.getAttribute('href'), live: a.dataset.liveId }));
  ok(card.tag === 'A' && card.href === 'https://thehybit.com/coins/ethereum/' && card.live === 'ethereum', 'the price card in the text is one link to the coin hub, wired to the live client');
  await page.close();
}
{
  const { page } = await open('hub-ethereum.html', 360);
  const nav = await page.$eval('.thb-b--pillars-hub', (n) => ({ w: n.getBoundingClientRect().width, sw: n.querySelector('.thb-b-chips').scrollWidth, cw: n.querySelector('.thb-b-chips').clientWidth }));
  ok(nav.w <= 360, `hub pillar tabs fit the 360px screen (they scroll inside themselves: ${nav.sw}/${nav.cw})`);
  await page.close();
}

await browser.close();
server.close();
console.log(failed ? `\n${failed} FAILED` : '\nAll hub browser tests passed.');
process.exit(failed ? 1 : 0);
