/**
 * The price converter (2.10.0), in a real browser.
 *
 * Runs against v2-ethereum.html, v2-convert.html and conv-cases.json,
 * written by `THB_TOP20_DIR=$PWD php tools/top20.test.php`. Counts every
 * request the page makes after load: converting must make none.
 */
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { extname, join, normalize } from 'node:path';

const ROOT = new URL('..', import.meta.url).pathname;
let failed = 0;
const ok = (c, m) => { if (!c) failed++; console.log(`${c ? 'PASS' : 'FAIL'}  ${m}`); };
for (const f of ['v2-ethereum.html', 'v2-convert.html', 'conv-cases.json']) {
  if (!existsSync(join(ROOT, f))) { console.log(`FAIL  ${f} missing — run THB_TOP20_DIR=$PWD php tools/top20.test.php first`); process.exit(1); }
}
const cases = JSON.parse(await readFile(join(ROOT, 'conv-cases.json'), 'utf8'));
const TYPES = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8' };
const server = createServer(async (req, res) => {
  try {
    const path = join(ROOT, normalize(decodeURIComponent(req.url.split('?')[0])));
    const body = await readFile(path);
    res.writeHead(200, { 'Content-Type': TYPES[extname(path)] ?? 'application/octet-stream' });
    res.end(body);
  } catch { res.writeHead(404).end(); }
});
await new Promise((r) => server.listen(8135, r));
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

const open = async (file, width = 1440) => {
  const page = await browser.newPage({ viewport: { width, height: 900 } });
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.addInitScript(() => { window.WebSocket = class { constructor() { this.readyState = 0; } send() {} close() {} }; });
  await page.goto(`http://localhost:8135/${file}`, { waitUntil: 'load' });
  await page.waitForTimeout(200);
  let requests = 0;
  page.on('request', () => { requests++; });
  return { page, errors, requests: () => requests };
};

{
  const { page, errors, requests } = await open('v2-ethereum.html');
  const parsed = await page.evaluate((c) => c.map(([s]) => window.__thbConv.parse(s)), cases.parse);
  ok(JSON.stringify(parsed) === JSON.stringify(cases.parse.map((c) => c[1])), 'PHP and JS read every input case identically');
  const fa = await page.evaluate((c) => c.map(([v, u]) => window.__thbConv.fa(v, u)), cases.fa);
  ok(JSON.stringify(fa) === JSON.stringify(cases.fa.map((c) => c[2])), 'and format every output case identically (Persian digits, ٬ and ٫)');

  const out = page.locator('[data-v2-conv-out]');
  await page.locator('#conv-amount').fill('۲٫۵');
  const r1 = await out.textContent();
  const [expect, own] = await page.evaluate(() => [window.__thbConv.convert(2.5, 'ethereum', 'usd'), window.__THB_CONV__.coins.find((c) => c.i === 'ethereum').p]);
  const shown = await page.evaluate((v) => window.__thbConv.fa(v, 'usd'), 2.5 * own);
  ok(r1 === `۲٫۵ اتریوم = ${shown} دلار` && Math.abs(expect - 2.5 * own) < 1e-6, `typing «۲٫۵» answers at once, from the page's own cached rate: «${r1}»`);
  await page.locator('#conv-to').selectOption('bitcoin');
  ok((await out.textContent()).endsWith(' بیت‌کوین'), 'coin → coin: ' + (await out.textContent()));
  await page.locator('#conv-to').selectOption('toman');
  ok((await out.textContent()).endsWith(' تومان'), 'coin → toman: ' + (await out.textContent()));
  await page.click('[data-v2-conv-swap]');
  ok((await page.locator('#conv-from').inputValue()) === 'toman' && (await page.locator('#conv-to').inputValue()) === 'ethereum', 'the swap button exchanges «از» and «به»');
  await page.locator('#conv-from').selectOption('ethereum');
  await page.locator('#conv-to').selectOption('usd');
  await page.locator('#conv-amount').fill('1');
  await page.evaluate(() => document.dispatchEvent(new CustomEvent('thb:live', { detail: { id: 'ethereum', usd: 4000, toman: null } })));
  ok((await out.textContent()) === '۱ اتریوم = ۴٬۰۰۰٫۰۰ دلار' && (await page.textContent('[data-v2-conv-meta]')).includes('زنده'),
    'a live tick from live.js replaces the price, and the meta line says it is live');
  await page.evaluate(() => document.dispatchEvent(new CustomEvent('thb:live', { detail: { id: 'bitcoin', usd: 1 } })));
  ok((await out.textContent()) === '۱ اتریوم = ۴٬۰۰۰٫۰۰ دلار', "another coin's tick is ignored");
  await page.locator('#conv-amount').fill('abc');
  ok((await out.textContent()).includes('معتبر نیست') && (await page.getAttribute('#conv-amount', 'aria-invalid')) === 'true', 'invalid input: said so, and marked aria-invalid');
  await page.locator('#conv-amount').focus();
  await page.keyboard.press('Tab');
  await page.keyboard.press('Tab');
  ok(await page.evaluate(() => document.activeElement?.hasAttribute('data-v2-conv-swap')), 'keyboard: amount → from → swap → to');
  ok(requests() === 0, `no request while converting (${requests()})`);
  ok(errors.length === 0, 'no script errors' + (errors.length ? ': ' + errors.join('; ') : ''));
  await page.close();
}
{
  const { page, errors } = await open('v2-convert.html');
  await page.locator('#conv-amount').fill('3');
  await page.locator('#conv-to').selectOption('ethereum');
  const u = new URL(page.url());
  ok(u.searchParams.get('amount') === '3' && u.searchParams.get('to') === 'ethereum' && u.searchParams.get('from') === 'bitcoin',
    'standalone: the address keeps from/to/amount, so a conversion can be shared');
  ok(errors.length === 0, 'standalone: no script errors');
  await page.close();
}
for (const [file, width] of [['v2-ethereum.html', 360], ['v2-convert.html', 360], ['v2-convert.html', 390]]) {
  const { page, errors } = await open(file, width);
  const sideways = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  const box = await page.locator('[data-v2-conv]').boundingBox();
  ok(!sideways && box && box.width <= width && errors.length === 0, `${file} at ${width}px: no sideways scroll, the converter fits (${Math.round(box?.width ?? 0)}px)`);
  await page.close();
}
await browser.close();
server.close();
console.log(failed ? `\n${failed} FAILED` : '\nAll converter tests passed.');
process.exit(failed ? 1 : 0);
