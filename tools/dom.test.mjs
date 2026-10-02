/**
 * Browser tests against the rendered page.
 *
 *   node tools/dom.test.mjs
 *
 * The PHP suites assert what the server produces. This one asserts what a
 * browser does with it — the properties that only exist once CSS has been
 * applied and the module has run, and that no amount of markup inspection can
 * reach.
 *
 * Three of them are requirements the v2 work must not regress:
 *
 *   - no horizontal scroll at 360px, which is the single most common way an
 *     RTL page with wide numeric tables breaks on a phone;
 *   - the data island surviving into the page, because without it every chart,
 *     sparkline and gauge is inert while the server-rendered figures around
 *     them still look perfectly healthy;
 *   - numbers rendering left-to-right inside right-to-left prose, which is a
 *     correctness issue rather than a cosmetic one: an unisolated contract
 *     address or price is displayed in the wrong character order.
 *
 * It runs against classic.html, written by template-render.test.php, served
 * over HTTP so the ES module and its imports load the way they do in
 * production.
 */

import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { extname, join, normalize } from 'node:path';

const ROOT = new URL('..', import.meta.url).pathname;
const PORT = 8123;

const TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.woff2': 'font/woff2',
  '.json': 'application/json',
};

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
const ok = (cond, msg) => {
  if (!cond) failed++;
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${msg}`);
};
const section = (s) => console.log(`\n--- ${s} ${'-'.repeat(Math.max(0, 58 - s.length))}`);

/* The container ships a Chromium whose build number may not match the
   playwright package's expectation, and downloading another is both slow and
   blocked by the egress policy. Pointing at the installed binary is the
   supported way round it; the env var lets CI override. */
const CHROME = process.env.THB_CHROME
  ?? '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const launchOptions = existsSync(CHROME) ? { executablePath: CHROME } : {};
const browser = await chromium.launch(launchOptions);

/* Console errors are collected rather than ignored: a module that throws on
   load leaves the page looking fine and every interactive part dead. */
/* JavaScript exceptions and failed network requests are tracked separately: a
   module that throws leaves every interactive part dead while the page looks
   fine, and a blocked third-party asset is a different problem with a different
   fix. Collapsing them into one list makes both harder to read. */
const pageErrors = [];
const failedRequests = [];
const context = await browser.newContext({ locale: 'fa-IR' });
const page = await context.newPage();
page.on('pageerror', (e) => pageErrors.push(e.message));
page.on('requestfailed', (r) => failedRequests.push(r.url()));

await page.setViewportSize({ width: 1440, height: 1000 });
await page.goto(`http://localhost:${PORT}/classic.html`, { waitUntil: 'networkidle' });

/* ------------------------------------------------------------------ */
section('page health');

ok(pageErrors.length === 0, `no JavaScript errors${pageErrors.length ? ': ' + pageErrors[0] : ''}`);

/* The page must not depend on any third-party host to render. Every asset it
   needs is served by the site itself; an external request here would be a
   privacy leak, a render-blocking dependency, and a Core Web Vitals cost. */
ok(failedRequests.length === 0,
   `no request left the site${failedRequests.length ? ': ' + failedRequests.join(', ') : ''}`);

const island = await page.evaluate(() => typeof window.__THB_COIN__);
ok(island === 'object', 'the data island reached the browser');

const islandPrice = await page.evaluate(() => window.__THB_COIN__?.market?.price);
ok(islandPrice === 3245.67, `carrying the price (${islandPrice})`);

ok(await page.locator('.thb-coin').count() === 1, 'the root wrapper is present exactly once');

/* ------------------------------------------------------------------ */
section('RTL correctness');

ok(await page.evaluate(() => document.documentElement.dir) === 'rtl', 'the document direction is RTL');
ok(await page.evaluate(() => document.documentElement.lang) === 'fa', 'and the language is Persian');

/* Numbers must read left-to-right inside right-to-left prose. Without
   isolation, "$3,245.67" next to Persian text is displayed in the wrong
   order — a correctness bug that looks like a typo. */
const numDirection = await page.evaluate(() => {
  const el = document.querySelector('.thb-num');
  return el ? getComputedStyle(el).direction : null;
});
ok(numDirection === 'ltr', `numeric runs are forced LTR (got ${numDirection})`);

const numIsolation = await page.evaluate(() => {
  const el = document.querySelector('.thb-num');
  return el ? getComputedStyle(el).unicodeBidi : null;
});
ok(['isolate', 'isolate-override'].includes(numIsolation),
   `and bidi-isolated from the Persian around them (${numIsolation})`);

/* Time must flow left-to-right on the price chart. Asserting the container's
   CSS direction would prove nothing — SVG path coordinates are absolute and
   unaffected by it — so the actual geometry is checked instead: the drawn line
   has to advance rightwards. A mirrored chart advances leftwards, which is what
   a reader would see as broken. */
const chartGeometry = await page.evaluate(() => {
  const host = document.querySelector('[data-thb-chart]');
  const path = host?.querySelector('svg path.thb-chart__line');   // the line, not the closed area fill
  if (!path) return null;
  const xs = [...path.getAttribute('d').matchAll(/[ML]\s*(-?[\d.]+)/g)].map((m) => parseFloat(m[1]));
  return xs.length < 2 ? null : { first: xs[0], last: xs[xs.length - 1], points: xs.length };
});
ok(chartGeometry !== null, 'the price chart drew a line');
ok(chartGeometry !== null && chartGeometry.last > chartGeometry.first,
   `and time flows left to right (x ${chartGeometry?.first} → ${chartGeometry?.last}, `
   + `${chartGeometry?.points} points)`);

/* ------------------------------------------------------------------ */
section('responsive');

for (const width of [1440, 1120, 768, 390, 360]) {
  await page.setViewportSize({ width, height: 900 });
  await page.waitForTimeout(120);

  const overflow = await page.evaluate(() => ({
    doc: document.documentElement.scrollWidth,
    win: window.innerWidth,
  }));
  ok(overflow.doc <= overflow.win + 1,
     `${width}px: no horizontal page scroll (content ${overflow.doc}px in ${overflow.win}px)`);
}

/* At phone width, find anything that actually sticks out — naming the culprit
   is the difference between a failing test and a debuggable one. */
await page.setViewportSize({ width: 360, height: 900 });
await page.waitForTimeout(150);
const wideElements = await page.evaluate(() => {
  const out = [];
  for (const el of document.querySelectorAll('.thb-coin *')) {
    const r = el.getBoundingClientRect();
    if (r.width > window.innerWidth + 1) {
      out.push(`${el.tagName.toLowerCase()}.${(el.className || '').toString().split(' ')[0]} ${Math.round(r.width)}px`);
    }
  }
  return out.slice(0, 5);
});
ok(wideElements.length === 0,
   `360px: nothing overflows the viewport${wideElements.length ? ' — ' + wideElements.join(', ') : ''}`);

/* A section that collapses to zero height at phone width has effectively
   disappeared, which markup assertions cannot detect. */
const collapsed = await page.evaluate(() => {
  const out = [];
  for (const el of document.querySelectorAll('.thb-page > section, .thb-page > div')) {
    if (el.getBoundingClientRect().height < 20) {
      out.push((el.className || '').toString().split(' ')[1] ?? el.tagName);
    }
  }
  return out;
});
ok(collapsed.length === 0,
   `360px: no section collapses to nothing${collapsed.length ? ' — ' + collapsed.join(', ') : ''}`);

/* ------------------------------------------------------------------ */
section('progressive enhancement');

/* Every figure must be readable with JavaScript off. The charts are
   enhancement; the numbers are the content. */
const noJs = await context.newPage();
await noJs.setViewportSize({ width: 1440, height: 1000 });
const noJsContext = await browser.newContext({ javaScriptEnabled: false, locale: 'fa-IR' });
const plain = await noJsContext.newPage();
await plain.goto(`http://localhost:${PORT}/classic.html`, { waitUntil: 'domcontentloaded' });

const plainText = await plain.textContent('body');
for (const [needle, label] of [
  ['$3,245.67', 'the price'],
  ['اتریوم', 'the coin name'],
  ['ویتالیک بوترین', 'the About text'],
]) {
  ok(plainText.includes(needle), `${label} is readable with JavaScript disabled`);
}

const headings = await plain.evaluate(() =>
  [...document.querySelectorAll('h1,h2,h3')].map((h) => h.tagName));
ok(headings.filter((h) => h === 'H2').length >= 5,
   `and the heading structure survives (${headings.length} headings)`);

await noJs.close();
await noJsContext.close();
await browser.close();
server.close();

console.log(failed ? `\n${failed} FAILED\n` : '\nAll DOM tests passed.\n');
process.exit(failed ? 1 : 0);
