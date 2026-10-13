/**
 * Full-page screenshots of v2 coin pages, desktop and phone.
 *
 *   node tools/rev-shots.mjs <out-dir> <prefix> [slug …]
 *
 * Serves the repository root, opens v2-{slug}.html (written by
 * `THB_TOP20_DIR=$PWD php tools/top20.test.php`) at 1440 and 390 px, and
 * saves {prefix}-{slug}-desktop.png and {prefix}-{slug}-phone.png. WebSockets
 * are stubbed so no exchange is contacted and the server price shows.
 */

import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFile, mkdir } from 'node:fs/promises';
import { extname, join, normalize } from 'node:path';

const ROOT = new URL('..', import.meta.url).pathname;
const [out = 'shots', prefix = 'shot', ...slugs] = process.argv.slice(2);
const list = slugs.length ? slugs : ['ethereum', 'tron'];
const TYPES = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.svg': 'image/svg+xml' };
const PORT = 8131;

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
for (const slug of list) {
  for (const [label, width] of [['desktop', 1440], ['phone', 390]]) {
    const page = await browser.newPage({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
    await page.addInitScript(() => { window.WebSocket = class { constructor() { this.readyState = 0; } send() {} close() {} }; });
    await page.goto(`http://localhost:${PORT}/v2-${slug}.html`, { waitUntil: 'load' });
    await page.waitForTimeout(400);
    await page.screenshot({ path: join(out, `${prefix}-${slug}-${label}.png`), fullPage: true });
    await page.close();
  }
}
await browser.close();
server.close();
console.log(`saved ${list.length * 2} screenshots to ${out}`);
