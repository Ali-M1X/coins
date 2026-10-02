/**
 * Browser tests for the v2 page.
 *
 *   node tools/dom-v2.test.mjs
 *
 * Runs against v2.html, written by v2-render.test.php from the assertion
 * fixtures, served over HTTP so the ES module loads as in production.
 *
 * At each width the brief names (1440, 1120, 768, 390, 360): no horizontal
 * page scroll, one h1, every screen laid out with a real size. Then the
 * interactions — each must redraw from the data island without a single
 * network request leaving the page.
 */

import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { extname, join, normalize } from 'node:path';

const ROOT = new URL('..', import.meta.url).pathname;
const PORT = 8124;
const TYPES = {
  '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8', '.svg': 'image/svg+xml', '.woff2': 'font/woff2',
};

if (!existsSync(join(ROOT, 'v2.html'))) {
  console.log('FAIL  v2.html missing — run php tools/v2-render.test.php with THB_DUMP_HTML first');
  process.exit(1);
}

const server = createServer(async (req, res) => {
  try {
    const path = join(ROOT, normalize(decodeURIComponent(req.url.split('?')[0])));
    const body = await readFile(path);
    res.writeHead(200, { 'Content-Type': TYPES[extname(path)] ?? 'application/octet-stream' });
    res.end(body);
  } catch {
    res.writeHead(404).end('not found');
  }
});
await new Promise((r) => server.listen(PORT, r));

let failed = 0;
const ok = (cond, msg) => { if (!cond) failed++; console.log(`${cond ? 'PASS' : 'FAIL'}  ${msg}`); };

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const URL_ = `http://localhost:${PORT}/v2.html`;
const SECTIONS = ['overview', 'ecosystem', 'flow', 'story', 'dna', 'price-story', 'pulse', 'peers', 'supply'];

for (const width of [1440, 1120, 768, 390, 360]) {
  const page = await browser.newPage({ viewport: { width, height: 900 } });
  const offsite = [];
  const errors = [];
  page.on('request', (r) => { if (!r.url().startsWith(`http://localhost:${PORT}/`) && !r.url().startsWith('data:')) offsite.push(r.url()); });
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto(URL_, { waitUntil: 'networkidle' });

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  ok(overflow <= 0, `${width}px: no horizontal page scroll (${overflow}px)`);
  ok(await page.locator('h1').count() === 1, `${width}px: exactly one h1`);
  const sizes = await page.evaluate((ids) => ids.map((id) => {
    const r = document.getElementById(id)?.getBoundingClientRect();
    return r ? Math.round(r.width) : 0;
  }), SECTIONS);
  ok(sizes.every((w) => w > 250 && w <= width), `${width}px: all nine screens laid out within the viewport (${sizes.join(',')})`);
  ok(offsite.length === 0, `${width}px: no request leaves the site` + (offsite.length ? ': ' + offsite[0] : ''));
  ok(errors.length === 0, `${width}px: no script errors` + (errors.length ? ': ' + errors[0] : ''));
  await page.close();
}

/* ---- interactions, desktop ---- */
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
const requests = [];
page.on('request', (r) => requests.push(r.url()));
await page.goto(URL_, { waitUntil: 'networkidle' });
const before = requests.length;

ok(await page.evaluate(() => typeof window.__THB_V2__ === 'object' && !!window.__THB_V2__.chart), 'the v2 data island reached the page');

const d0 = await page.getAttribute('[data-v2-line]', 'd');
await page.click('[data-v2-range="24h"]');
const d1 = await page.getAttribute('[data-v2-line]', 'd');
ok(d0 !== d1 && d1.startsWith('M'), 'choosing the 24h range redraws the chart from the island');
ok(await page.getAttribute('[data-v2-range="24h"]', 'aria-pressed') === 'true', 'and marks the button pressed');
await page.click('[data-v2-metric="v"]');
ok(await page.getAttribute('[data-v2-line]', 'd') !== d1, 'switching to volume redraws again');

await page.click('[data-v2-watch]');
ok(await page.getAttribute('[data-v2-watch]', 'aria-pressed') === 'true', 'the watchlist button toggles on');
ok(await page.evaluate(() => JSON.parse(localStorage.getItem('thb-watchlist') || '[]').includes('ethereum')), 'and remembers the coin');

const groups = await page.$$eval('[data-v2-eco-filter]', (b) => b.map((x) => x.dataset.v2EcoFilter));
const target = groups.find((g) => g !== 'all');
if (target) {
  await page.click(`[data-v2-eco-filter="${target}"]`);
  const dims = await page.$$eval('[data-v2-node]', (n, g) => n.every((x) => x.classList.contains('is-dim') === (x.dataset.group !== g)), target);
  ok(dims, `the "${target}" filter dims every other protocol`);
}
await page.click('[data-v2-node="1"]');
const name = await page.textContent('[data-v2-d="name"]');
const expected = await page.evaluate(() => window.__THB_V2__.ecosystem[1].name);
ok(name.trim() === expected, `selecting a protocol fills the detail card (${expected})`);
await page.click('[data-v2-eco-view]');
ok(await page.isVisible('[data-v2-eco-list]') && !(await page.isVisible('[data-v2-eco-map]')), 'map/list toggles to the list');

const tvlToggle = await page.$('[data-v2-layer="tvl"]');
if (tvlToggle) {
  await tvlToggle.click();
  ok(await page.evaluate(() => !document.querySelector('[data-v2-layer-el="tvl"]').hasAttribute('hidden')), 'the TVL layer can be switched on');
}
const pins = await page.$$('[data-v2-event]');
if (pins.length > 1) {
  await pins[0].click();
  const title = await page.textContent('[data-v2-e="title"]');
  const want = await page.evaluate(() => window.__THB_V2__.story.events[0].title);
  ok(title.trim() === want, 'clicking an event pin fills the event card');
}

const axis = await page.$('[data-v2-axis]');
if (axis && (await page.$$('[data-v2-axis] option')).length > 1) {
  const t0 = await page.getAttribute('[data-v2-peer="0"]', 'transform');
  await page.selectOption('[data-v2-axis]', 'vol');
  ok(await page.getAttribute('[data-v2-peer="0"]', 'transform') !== t0, 'axis swap moves the bubbles');
}

ok(requests.length === before, 'none of the interactions made a network request');

/* ---- JavaScript off: the page is still complete ---- */
const ctx = await browser.newContext({ javaScriptEnabled: false });
const nojs = await ctx.newPage();
await nojs.goto(URL_);
ok((await nojs.textContent('[data-v2-price]')).includes('$3,245.67'), 'with JavaScript off the price is there');
ok(await nojs.locator('svg.v2-chart path[data-v2-line]').count() === 1, 'and the chart is drawn');

await browser.close();
server.close();
console.log(failed ? `\n${failed} FAILED` : '\nAll v2 DOM tests passed.');
process.exit(failed ? 1 : 0);
