/**
 * Screenshots of the v2 showcase pages, for design review.
 *
 *   php tools/v2-showcase.php && node tools/v2-screens.mjs [outdir]
 *
 * Full-page captures at desktop and phone widths, plus one capture per
 * section at desktop width so each screen can be set beside its reference.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { readFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { resolve, join, extname } from 'node:path';

const out = resolve(process.argv[2] || 'screens');
mkdirSync(out, { recursive: true });
const root = resolve('.');
/* Served over HTTP: Chromium refuses ES modules from file://, and a
   screenshot without v2.js would not show the page a visitor gets. */
const TYPES = { '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml', '.woff2': 'font/woff2', '.png': 'image/png' };
const server = createServer(async (req, res) => {
  try {
    const path = join(root, decodeURIComponent(req.url.split('?')[0]));
    const body = await readFile(path);
    res.writeHead(200, { 'Content-Type': TYPES[extname(path)] ?? 'application/octet-stream' });
    res.end(body);
  } catch { if (!res.headersSent) res.writeHead(404).end(); }
});
await new Promise((r) => server.listen(8125, r));

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

const sections = ['overview', 'ecosystem', 'flow', 'story', 'dna', 'price-story', 'pulse', 'peers', 'supply'];

for (const slug of ['ethereum', 'bitcoin']) {
  const url = `http://localhost:8125/v2-${slug}.html`;
  for (const [name, width] of [['desktop', 1440], ['mobile', 390]]) {
    const page = await browser.newPage({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
    await page.goto(url, { waitUntil: 'networkidle' });
    await page.evaluate(() => document.fonts.ready);
    await page.screenshot({ path: `${out}/${slug}-${name}-full.png`, fullPage: true });
    if (slug === 'ethereum') {
      // The sticky section nav would sit on top of every per-section capture.
      await page.addStyleTag({ content: '.v2-tabs{position:static!important}' });
      for (const id of sections) {
        const el = await page.$(`#${id}`);
        if (el) await el.screenshot({ path: `${out}/${slug}-${name}-${id}.png` });
      }
    }
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    console.log(`${slug} ${name}: horizontal overflow ${overflow}px`);
    await page.close();
  }
}
await browser.close();
server.close();
