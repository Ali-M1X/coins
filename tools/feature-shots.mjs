/**
 * Screenshots of a feature in a given state, desktop and phone.
 *
 *   node tools/feature-shots.mjs <out.png-prefix> <page.html> [selector] [fill-selector=text] [click-selector]
 *
 * Serves the repository root (index requests answered from coins-index.json),
 * optionally types into a field and clicks, then captures the selector (or
 * the full page) at 1440 and 360 px.
 */
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { extname, join, normalize } from 'node:path';

const ROOT = new URL('..', import.meta.url).pathname;
const [prefix, file, selector = '', fill = '', click = '', script = ''] = process.argv.slice(2);
const TYPES = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8' };
const server = createServer(async (req, res) => {
  try {
    const path = join(ROOT, normalize(decodeURIComponent(req.url.split('?')[0])));
    const body = await readFile(path);
    res.writeHead(200, { 'Content-Type': TYPES[extname(path)] ?? 'application/octet-stream' });
    res.end(body);
  } catch { res.writeHead(404).end(); }
});
await new Promise((r) => server.listen(8134, r));
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
for (const [label, width] of [['desktop', 1440], ['phone', 360]]) {
  const page = await browser.newPage({ viewport: { width, height: 900 } });
  await page.addInitScript(() => { window.WebSocket = class { constructor() { this.readyState = 0; } send() {} close() {} }; });
  await page.route('**/wp-json/thehybit/v1/coins-index', async (r) => r.fulfill({ status: 200, contentType: 'application/json', body: await readFile(join(ROOT, 'coins-index.json'), 'utf8') }));
  await page.goto(`http://localhost:8134/${file}`, { waitUntil: 'load' });
  await page.addStyleTag({ content: '.v2-tabs { position: static !important; }' });
  if (fill) {
    for (const part of fill.split('&')) {
      const [sel, text] = part.split('=');
      const isSelect = await page.locator(sel).evaluate((e) => e.tagName === 'SELECT');
      if (isSelect) await page.locator(sel).selectOption(text); else await page.locator(sel).fill(text);
    }
    await page.waitForTimeout(500);
  }
  if (click) { await page.locator(click).first().click(); await page.waitForTimeout(400); }
  if (script) { await page.evaluate(script); await page.waitForTimeout(400); }
  if (selector === 'viewport') { await page.screenshot({ path: `${prefix}-${label}.png` }); await page.close(); continue; }
  const target = selector ? page.locator(selector).first() : page;
  await target.screenshot({ path: `${prefix}-${label}.png`, ...(selector ? {} : { fullPage: true }) });
  await page.close();
}
await browser.close();
server.close();
console.log('saved', prefix);
