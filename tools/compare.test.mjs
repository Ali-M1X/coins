/**
 * Coin comparison (2.11.0), in a real browser.
 *
 * Runs against v2-compare.html (Bitcoin, Ethereum, Solana) and
 * v2-compare-pick.html (the bare picker), written by
 * `THB_TOP20_DIR=$PWD php tools/top20.test.php`.
 */
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { extname, join, normalize } from 'node:path';

const ROOT = new URL('..', import.meta.url).pathname;
let failed = 0;
const ok = (c, m) => { if (!c) failed++; console.log(`${c ? 'PASS' : 'FAIL'}  ${m}`); };
for (const f of ['v2-compare.html', 'v2-compare-pick.html']) {
  if (!existsSync(join(ROOT, f))) { console.log(`FAIL  ${f} missing — run THB_TOP20_DIR=$PWD php tools/top20.test.php first`); process.exit(1); }
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
await new Promise((r) => server.listen(8136, r));
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const open = async (file, width = 1440) => {
  const page = await browser.newPage({ viewport: { width, height: 900 } });
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.addInitScript(() => { window.WebSocket = class { constructor() { this.readyState = 0; } send() {} close() {} }; });
  await page.route('https://thehybit.com/coins/compare/**', (r) => r.fulfill({ status: 200, contentType: 'text/html', body: '<p>landed</p>' }));
  await page.goto(`http://localhost:8136/${file}`, { waitUntil: 'load' });
  return { page, errors };
};

{
  const { page, errors } = await open('v2-compare-pick.html');
  await page.selectOption('#cmp-c1', 'bitcoin');
  ok(await page.locator('#cmp-c2 option[value="bitcoin"]').isDisabled(), 'a coin picked in one box cannot be picked again in another');
  await page.click('.cmp-pick__go');
  await page.waitForTimeout(150);
  ok((await page.textContent('[data-cmp-note]')).includes('دست‌کم دو ارز'), 'one coin: asks for a second, without leaving the page');
  await page.selectOption('#cmp-c2', 'ethereum');
  await Promise.all([page.waitForURL('**/coins/compare/?c=*'), page.click('.cmp-pick__go')]);
  ok(page.url() === 'https://thehybit.com/coins/compare/?c=bitcoin,ethereum', 'two coins: the short shareable address ' + page.url());
  ok(errors.length === 0, 'no script errors');
  await page.close();
}
{
  const { page, errors } = await open('v2-compare.html');
  const cols = await page.$$eval('.cmp__coin a', (a) => a.map((x) => x.textContent.trim()));
  ok(cols.join(',') === 'بیت‌کوین,اتریوم,سولانا', 'three columns, each linking to its coin page');
  const empty = await page.$$eval('.cmp__cell.is-empty', (c) => c.map((x) => x.textContent.trim()));
  ok(empty.length > 0 && empty.every((t) => t === '—'), `missing figures are dashes (${empty.length})`);
  ok(errors.length === 0, 'no script errors');
  await page.close();
}
for (const width of [390, 360]) {
  const { page, errors } = await open('v2-compare.html', width);
  const sideways = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  const label = await page.locator('.cmp__row .cmp__label').first().boundingBox();
  const cell = await page.locator('.cmp__row .cmp__cell').first().boundingBox();
  ok(!sideways && cell.y > label.y && errors.length === 0, `${width}px: no sideways scroll; each figure's label sits above the three values`);
  await page.close();
}
await browser.close();
server.close();
console.log(failed ? `\n${failed} FAILED` : '\nAll comparison tests passed.');
process.exit(failed ? 1 : 0);
