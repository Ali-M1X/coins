/**
 * Instant search on /coins/ (2.9.0), in a real browser.
 *
 * Runs against v2-list.html, coins-index.json and search-cases.json, written
 * by `THB_TOP20_DIR=$PWD php tools/top20.test.php`. The index request is
 * answered from coins-index.json and COUNTED: one per page, however much is
 * typed. The PHP and JS normalisers must agree on every case.
 */

import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { extname, join, normalize } from 'node:path';

const ROOT = new URL('..', import.meta.url).pathname;
let failed = 0;
const ok = (c, m) => { if (!c) failed++; console.log(`${c ? 'PASS' : 'FAIL'}  ${m}`); };

for (const f of ['v2-list.html', 'coins-index.json', 'search-cases.json']) {
  if (!existsSync(join(ROOT, f))) { console.log(`FAIL  ${f} missing — run THB_TOP20_DIR=$PWD php tools/top20.test.php first`); process.exit(1); }
}
const cases = JSON.parse(await readFile(join(ROOT, 'search-cases.json'), 'utf8'));
const TYPES = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8' };
const server = createServer(async (req, res) => {
  try {
    const path = join(ROOT, normalize(decodeURIComponent(req.url.split('?')[0])));
    res.writeHead(200, { 'Content-Type': TYPES[extname(path)] ?? 'application/octet-stream' });
    res.end(await readFile(path));
  } catch { res.writeHead(404).end(); }
});
await new Promise((r) => server.listen(8133, r));
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

const open = async (width) => {
  const page = await browser.newPage({ viewport: { width, height: 900 } });
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  let indexCalls = 0;
  await page.addInitScript(() => { window.WebSocket = class { constructor() { this.readyState = 0; } send() {} close() {} }; });
  await page.route('**/wp-json/thehybit/v1/coins-index', async (route) => {
    indexCalls++;
    await route.fulfill({ status: 200, contentType: 'application/json', body: await readFile(join(ROOT, 'coins-index.json'), 'utf8') });
  });
  await page.route('https://thehybit.com/wp-content/plugins/thehybit-coins/**', async (route) => {
    const rel = route.request().url().replace('https://thehybit.com/wp-content/plugins/', '').split('?')[0];
    try { await route.fulfill({ status: 200, body: await readFile(join(ROOT, rel)), contentType: TYPES[extname(rel)] }); } catch { await route.fulfill({ status: 404 }); }
  });
  await page.goto('http://localhost:8133/v2-list.html', { waitUntil: 'load' });
  return { page, errors, calls: () => indexCalls };
};

const results = (page) => page.$$eval('[data-list-rows] tr', (trs) => trs.map((tr) => tr.querySelector('.list-coin__sym')?.textContent));

{
  const { page, errors, calls } = await open(1440);
  const hasScript = await page.evaluate(() => !!window.__thbSearch);
  ok(hasScript, 'list.js is loaded on the list');
  const js = await page.evaluate((c) => c.map(([raw]) => window.__thbSearch.normalize(raw)), cases.normalize);
  ok(JSON.stringify(js) === JSON.stringify(cases.normalize.map((c) => c[1])), 'PHP and JS normalise every case identically');

  ok(calls() === 0, 'nothing is fetched before the visitor uses the search box');
  const q = page.locator('#list-q');
  await q.focus();
  await page.waitForTimeout(150);
  for (const [term, id] of cases.top) {
    await q.fill(term);
    await page.waitForTimeout(250);
    const syms = await results(page);
    const want = JSON.parse(await readFile(join(ROOT, 'coins-index.json'), 'utf8')).rows.find((r) => r.i === id)?.s;
    ok(syms[0] === want, `«${term}» → ${want} first (${syms.slice(0, 3).join(', ')})`);
  }
  ok(calls() === 1, `the index was fetched once for ${cases.top.length} searches (${calls()})`);
  ok(await page.locator('[data-list-main]').isHidden() && await page.locator('[data-list-pages]').isHidden(), 'while searching, the page table and pagination step aside');
  ok(new URL(page.url()).searchParams.get('q') === 'xaut', 'the address keeps ?q=, so a search can be shared or reloaded');
  ok((await page.textContent('[data-list-status]')).includes('ارز پیدا شد'), 'the result count is announced (role=status)');

  await q.fill('zzzzqq');
  await page.waitForTimeout(250);
  ok((await page.textContent('[data-list-status]')).includes('هیچ ارزی با «zzzzqq»') && await page.locator('[data-list-table]').isHidden(), 'no results: a sentence, not an empty table');

  await q.fill('btc');
  await page.waitForTimeout(250);
  await q.press('Escape');
  await page.waitForTimeout(100);
  ok((await q.inputValue()) === '' && await page.locator('[data-list-main]').isVisible() && await page.locator('[data-list-results]').isHidden(),
    'Escape clears the search and brings the list back');

  await page.keyboard.press('Tab');
  ok(await page.evaluate(() => document.activeElement?.classList.contains('list-search__go')), 'keyboard: Tab reaches the search button');
  ok(errors.length === 0, 'no script errors' + (errors.length ? ': ' + errors.join('; ') : ''));
  await page.close();
}

for (const width of [390, 360]) {
  const { page, errors } = await open(width);
  await page.locator('#list-q').fill('usd');
  await page.waitForTimeout(400);
  const sideways = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  const n = (await results(page)).length;
  ok(!sideways && n > 0 && errors.length === 0, `${width}px: results (${n}), no sideways scroll, no errors`);
  await page.close();
}

await browser.close();
server.close();
console.log(failed ? `\n${failed} FAILED` : '\nAll search tests passed.');
process.exit(failed ? 1 : 0);
