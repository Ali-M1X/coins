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
import { resolve } from 'node:path';

const out = resolve(process.argv[2] || 'screens');
mkdirSync(out, { recursive: true });
const root = resolve('.');
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

const sections = ['overview', 'ecosystem', 'flow', 'story', 'dna', 'price-story', 'pulse', 'peers', 'supply'];

for (const slug of ['ethereum', 'bitcoin']) {
  const url = `file://${root}/v2-${slug}.html`;
  for (const [name, width] of [['desktop', 1440], ['mobile', 390]]) {
    const page = await browser.newPage({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
    await page.goto(url, { waitUntil: 'load' });
    await page.evaluate(() => document.fonts.ready);
    await page.screenshot({ path: `${out}/${slug}-${name}-full.png`, fullPage: true });
    if (slug === 'ethereum') {
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
