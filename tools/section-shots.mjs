/**
 * Screenshots of one section of v2 coin pages, desktop and phone.
 *
 *   node tools/section-shots.mjs <out-dir> <prefix> <section-id> [slug …]
 *
 * Same server and stubs as rev-shots.mjs; captures #<section-id> only, so a
 * before/after of one part of the page can be compared at full size.
 */

import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFile, mkdir } from 'node:fs/promises';
import { extname, join, normalize } from 'node:path';

const ROOT = new URL('..', import.meta.url).pathname;
const [out = 'shots', prefix = 'shot', id = 'flow', ...slugs] = process.argv.slice(2);
const list = slugs.length ? slugs : ['ethereum'];
const TYPES = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.svg': 'image/svg+xml' };
const PORT = 8132;

const server = createServer(async (req, res) => {
  try {
    const path = join(ROOT, normalize(decodeURIComponent(req.url.split('?')[0])));
    const body = await readFile(path);
    res.writeHead(200, { 'Content-Type': TYPES[extname(path)] ?? 'application/octet-stream' });
    res.end(body);
  } catch { res.writeHead(404).end(); }
});
await new Promise((r) => server.listen(PORT, r));
await mkdir(out, { recursive: true });
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const report = [];
for (const slug of list) {
  for (const [label, width] of [['desktop', 1440], ['tablet', 768], ['phone', 390], ['small', 360]]) {
    const page = await browser.newPage({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
    await page.addInitScript(() => { window.WebSocket = class { constructor() { this.readyState = 0; } send() {} close() {} }; });
    await page.goto(`http://localhost:${PORT}/v2-${slug}.html`, { waitUntil: 'load' });
    await page.waitForTimeout(400);
    // The sticky tab bar would sit over the top of the captured section.
    await page.addStyleTag({ content: '.v2-tabs { position: static !important; }' });
    const el = page.locator('#' + id);
    if (await el.count() === 0) { report.push(`${slug} ${label}: no #${id}`); await page.close(); continue; }
    const box = await el.boundingBox();
    const sideways = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    report.push(`${slug} ${label}: #${id} ${Math.round(box.width)}×${Math.round(box.height)}${sideways ? ' SIDEWAYS SCROLL' : ''}`);
    await el.screenshot({ path: join(out, `${prefix}-${slug}-${id}-${label}.png`) });
    await page.close();
  }
}
await browser.close();
server.close();
console.log(report.join('\n'));
