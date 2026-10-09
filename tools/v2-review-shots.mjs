/**
 * One capture per item of the pre-launch review (data/1–8.PNG), so each fix
 * can be checked on its own.
 *
 *   php tools/v2-showcase.php && node tools/v2-review-shots.mjs [outdir]
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { readFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { resolve, join, extname } from 'node:path';

const out = resolve(process.argv[2] || 'docs/v2-review');
mkdirSync(out, { recursive: true });
const root = resolve('.');
const TYPES = { '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml', '.woff2': 'font/woff2', '.png': 'image/png' };
const server = createServer(async (req, res) => {
  try {
    const path = join(root, decodeURIComponent(req.url.split('?')[0]));
    const body = await readFile(path);
    res.writeHead(200, { 'Content-Type': TYPES[extname(path)] ?? 'application/octet-stream' });
    res.end(body);
  } catch { if (!res.headersSent) res.writeHead(404).end(); }
});
await new Promise((r) => server.listen(8126, r));
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

/* [file, selector, how to find the card] — the card is the closest
   .v2-card around the element when `card` is set. */
const shots = [
  ['1-active-addresses', '.v2-keymetrics', false],
  ['2-top-protocols', '.v2-hbars', true],
  ['3-chain-flow', '.v2-flow__sankey', false],
  ['4-network-pulse', '.v2-flow__side > .v2-card', false],
  ['5-timeline', '.v2-timeline', false],
  ['6-price-story', '#price-story', false],
  ['7-sources-footnote', '.v2-provenance', false],
  ['7-pulse-side', '.v2-pulse__side', false],
  ['8-supply-risk', '.v2-cockpit', false],
  ['8-supply-distribution', '.v2-dist', false],
];

for (const [name, width] of [['desktop', 1440], ['mobile', 390]]) {
  const page = await browser.newPage({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
  await page.goto('http://localhost:8126/v2-ethereum.html', { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
  await page.addStyleTag({ content: '.v2-tabs{position:static!important}' });
  await page.evaluate(() => document.querySelector('.v2-provenance')?.setAttribute('open', ''));
  for (const [file, sel, card] of shots) {
    let el = await page.$(sel);
    if (el && card) el = await el.evaluateHandle((n) => n.closest('.v2-card'));
    if (!el) { console.log(`missing: ${file} (${sel})`); continue; }
    await el.asElement().screenshot({ path: `${out}/${file}-${name}.png` });
  }
  const tl = await page.evaluate(() => { const l = document.querySelector('.v2-timeline__list'); return l ? l.scrollWidth - l.clientWidth : -1; });
  console.log(`${name}: timeline horizontal overflow ${tl}px`);
  await page.close();
}
await browser.close();
server.close();
console.log(`wrote review captures to ${out}`);
