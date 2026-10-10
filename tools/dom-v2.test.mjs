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

/* THEME LAYOUTS. The live theme wraps a single post in a narrow box, and a
   v2 that sized itself from the window rather than from its real width
   rendered a squeezed desktop grid inside it. These variants wrap the same
   page the way themes do, so the breakout is tested, not assumed. */
const THEMES = {
  'v2-boxed.html':   ['<div id="page" style="max-width:480px;margin:0 auto;padding:0 14px;border:1px solid #ccc;background:#fff">', '</div>'],
  'v2-sidebar.html': ['<div style="display:flex;gap:30px;max-width:1100px;margin:0 auto"><main style="flex:1;min-width:0">', '</main><aside style="width:300px;flex:none">sidebar</aside></div>'],
  'v2-shrink.html':  ['<div style="display:flex;flex-direction:column;align-items:center"><div class="site">', '</div></div>'],
  /* THE LIVE THEME'S BEHAVIOUR: a page box sized by its content, capped.
     Measured: classic content asks for 1320px, the pre-fix v2 for 354px —
     which is why the theme header shrank on v2 pages. */
  'v2-fit.html':     ['<div id="page" style="width:fit-content;max-width:1400px;margin:0 auto;background:#fff">', '</div>'],
};
/* The same wrappers around the CLASSIC page, so the theme box can be compared
   with the one the classic design gets — they must be identical. */
for (const name of ['v2-boxed.html', 'v2-shrink.html', 'v2-fit.html']) {
  THEMES[name.replace('v2-', 'classic-')] = THEMES[name];
}

const server = createServer(async (req, res) => {
  try {
    const name = decodeURIComponent(req.url.split('?')[0]).replace(/^\//, '');
    if (THEMES[name]) {
      const [open, close] = THEMES[name];
      const html = (await readFile(join(ROOT, name.startsWith('classic-') ? 'classic.html' : 'v2.html'), 'utf8'))
        .replace('<body>', '<body>' + open).replace('</body>', close + '</body>');
      res.writeHead(200, { 'Content-Type': TYPES['.html'] });
      res.end(html);
      return;
    }
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

/* ---- inside a theme's box: the page still takes the whole viewport ---- */
const columns = (page, sel) => page.$eval(sel, (el) => getComputedStyle(el).gridTemplateColumns.split(' ').filter(Boolean).length);
/* The theme's own box — header, footer and all — is left exactly as the
   classic page leaves it. v2 only breaks out INSIDE it. */
const themeBox = async (file, width) => {
  const p = await browser.newPage({ viewport: { width, height: 900 } });
  await p.goto(`http://localhost:${PORT}/${file}`, { waitUntil: 'networkidle' });
  const w = await p.$eval('body > div', (el) => Math.round(el.getBoundingClientRect().width));
  await p.close();
  return w;
};
for (const name of ['boxed', 'shrink', 'fit']) {
  for (const width of [1440, 390]) {
    const classic = await themeBox(`classic-${name}.html`, width);
    const v2 = await themeBox(`v2-${name}.html`, width);
    ok(classic === v2, `${name} theme at ${width}px: the theme box (and its header) is ${v2}px on v2, ${classic}px on classic — identical`);
  }
}

for (const file of Object.keys(THEMES).filter((f) => f.startsWith('v2-'))) {
  for (const width of [1440, 390]) {
    const p = await browser.newPage({ viewport: { width, height: 900 } });
    await p.goto(`http://localhost:${PORT}/${file}`, { waitUntil: 'networkidle' });
    const box = await p.$eval('.thb-v2', (el) => {
      const r = el.getBoundingClientRect();
      return { left: Math.round(r.left), width: Math.round(r.width), vw: document.documentElement.clientWidth,
               overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth };
    });
    ok(box.left === 0 && box.width === box.vw && box.overflow <= 0,
       `${file} at ${width}px: v2 spans the full viewport (left ${box.left}, ${box.width}/${box.vw}px, overflow ${box.overflow})`);
    if (width === 1440) {
      ok(await columns(p, '.v2-overview') === 3, `${file} at 1440px: the hero is the 3-column desktop layout`);
      ok(await columns(p, '.v2-dna__grid') === 3 && await columns(p, '.v2-story__grid') === 3 && await columns(p, '.v2-supply__grid') === 3,
         `${file} at 1440px: story, DNA and supply are 3-column`);
      ok(await columns(p, '.v2-eco__grid') === 2 && await columns(p, '.v2-flow__grid') === 2 && await columns(p, '.v2-peers__grid') === 2,
         `${file} at 1440px: ecosystem, chain flow and peers have their side panel beside them`);
    } else {
      ok(await columns(p, '.v2-overview') === 1 && await columns(p, '.v2-dna__grid') === 1 && await columns(p, '.v2-eco__grid') === 1,
         `${file} at 390px: single-column mobile layout`);
    }
    await p.close();
  }
}

/* ---- timeline: wrapped lines are joined into one continuous rail ---- */
const joins = async (width) => {
  const p = await browser.newPage({ viewport: { width, height: 900 } });
  await p.goto(URL_, { waitUntil: 'networkidle' });
  await p.evaluate(() => document.fonts.ready);
  const r = await p.evaluate(() => {
    const card = document.querySelector('.v2-timeline');
    const c = card.getBoundingClientRect();
    const items = [...card.querySelectorAll('.v2-timeline__item')].map((li) => li.getBoundingClientRect());
    const tops = [...new Set(items.map((i) => Math.round(i.top)))].sort((a, b) => a - b);
    const ends = [...card.querySelectorAll('.v2-timeline__join')].map((pa) => {
      const len = pa.getTotalLength();
      const a = pa.getPointAtLength(0);
      const b = pa.getPointAtLength(len);
      return { a: [a.x, a.y], b: [b.x, b.y] };
    });
    const rows = tops.map((t) => items.filter((i) => Math.round(i.top) === t));
    const expect = rows.slice(0, -1).map((row, k) => ({
      a: [Math.max(...row.map((i) => i.right)) - c.left, t0(row) + 10],
      b: [Math.min(...rows[k + 1].map((i) => i.left)) - c.left, t0(rows[k + 1]) + 10],
    }));
    function t0(row) { return Math.round(row[0].top - c.top); }
    return { rows: rows.length, perRow: rows[0].length, ends, expect,
             overflow: card.scrollWidth - card.clientWidth };
  });
  await p.close();
  return r;
};
for (const width of [1440, 900]) {
  const j = await joins(width);
  const near = (p, q) => Math.abs(p[0] - q[0]) < 1.5 && Math.abs(p[1] - q[1]) < 1.5;
  ok(j.rows >= 2 && j.ends.length === j.rows - 1, `timeline at ${width}px: ${j.rows} lines, joined by ${j.ends.length} connector(s)`);
  ok(j.ends.every((e, k) => near(e.a, j.expect[k].a) && near(e.b, j.expect[k].b)),
     `timeline at ${width}px: each connector starts where a line's rail ends and ends where the next line's rail starts`);
  ok(j.overflow <= 0, `timeline at ${width}px: no horizontal scroll (${j.overflow}px)`);
}
{
  const j = await joins(390);
  ok(j.perRow === 1 && j.ends.length === 0, 'timeline at 390px: one event per line, no connectors drawn');
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

/* ---- live price: updates in place, from our endpoint only ---- */
{
  const lp = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  await lp.clock.install();
  const polls = [];
  let price = 3300.5;
  await lp.route('**/wp-json/thehybit/v1/price/**', async (route) => {
    polls.push(route.request().url());
    price += 10;
    // The page's (fake) clock, as the server's would agree with the visitor's.
    const now = await lp.evaluate(() => Date.now());
    route.fulfill({
      status: 200, contentType: 'application/json', headers: { 'Access-Control-Allow-Origin': '*' },
      body: JSON.stringify({ coin: 'ethereum', price, change24h: -1.25, toman: price * 83000,
        fetchedAt: new Date(now).toISOString(), nextRefreshAt: new Date(now + 60000).toISOString(), source: 'ticker' }),
    });
  });
  const offsite = [];
  lp.on('request', (r) => { if (/coingecko/.test(r.url())) offsite.push(r.url()); });
  await lp.goto(URL_, { waitUntil: 'networkidle' });
  const before = await lp.textContent('[data-v2-price]');
  ok(polls.length === 0, 'no poll on load — the first paint is already the cached price');

  await lp.clock.runFor(20000);
  await lp.waitForFunction(() => document.querySelector('[data-v2-price]').textContent.includes('3,310.50'), null, { timeout: 5000 }).catch(() => {});
  const after = await lp.textContent('[data-v2-price]');
  ok(polls.length === 1 && after.includes('$3,310.50') && after !== before, `the price updates in place after the server refresh is due (${before.trim()} → ${after.trim()})`);
  ok((await lp.getAttribute('[data-v2-live-change]', 'class')).includes('v2-delta--down')
     && (await lp.textContent('[data-v2-live-change]')).includes('−1.25%'), 'and the 24h change with its direction');
  ok((await lp.textContent('[data-v2-live-toman]')).includes('274,771,500'), 'and the toman figure');

  await lp.clock.runFor(30000);
  ok(polls.length === 1, 'no second poll before the next refresh is due — never faster than the data changes');
  await lp.clock.runFor(40000);
  await lp.waitForFunction(() => document.querySelector('[data-v2-price]').textContent.includes('3,320.50'), null, { timeout: 5000 }).catch(() => {});
  ok(polls.length === 2, 'the next poll comes right after the next one-minute refresh');
  ok(offsite.length === 0, 'the browser never contacts CoinGecko');
  ok(await lp.locator('[data-v2-price]').count() === 1, 'no reload: the same element was updated');
  await lp.close();
}

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
