/**
 * Reference image beside the built screen, one PNG per screen.
 *
 *   php tools/v2-showcase.php && node tools/v2-screens.mjs screens && node tools/v2-compare.mjs screens
 *
 * The built side is the SHOWCASE render (test data, labelled as such).
 */
import { chromium } from 'playwright';
import { resolve } from 'node:path';
import { readFileSync } from 'node:fs';

// setContent pages cannot read file:// URLs, so images go in as data URIs.
const uri = (path) => 'data:image/png;base64,' + readFileSync(path).toString('base64');

const dir = resolve(process.argv[2] || 'screens');
const root = resolve('.');
const pairs = [
  ['1', 'overview', 'نگاه کلی'], ['2', 'overview', 'نگاه کلی (داشبورد)'], ['3', 'ecosystem', 'اکوسیستم'],
  ['4', 'flow', 'جریان زنجیره'], ['5', 'story', 'داستان و یادگیری'], ['6', 'dna', 'DNA'],
  ['7', 'price-story', 'قیمت به‌روایت'], ['8', 'pulse', 'پالس روزانه'], ['9', 'peers', 'مقایسه با همتایان'],
  ['10', 'supply', 'تامین و ریسک'],
];
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const page = await browser.newPage({ viewport: { width: 2000, height: 800 } });
for (const [ref, id, title] of pairs) {
  await page.setContent(`<!doctype html><html dir="rtl"><body style="margin:0;background:#111;color:#eee;font:600 18px sans-serif">
    <div style="display:flex;gap:16px;padding:16px;align-items:flex-start">
      <figure style="margin:0;flex:1"><figcaption style="padding:6px 0">نسخه ۲.۰.۰ — ${title} (داده آزمایشی)</figcaption>
        <img src="${uri(`${dir}/ethereum-desktop-${id}.png`)}" style="width:100%;display:block;border:1px solid #333"></figure>
      <figure style="margin:0;flex:1"><figcaption style="padding:6px 0">تصویر مرجع ${ref}.png</figcaption>
        <img src="${uri(`${root}/${ref}.png`)}" style="width:100%;display:block;border:1px solid #333"></figure>
    </div></body></html>`, { waitUntil: 'load' });
  await page.screenshot({ path: `${dir}/compare-${ref.padStart(2, '0')}-${id}.png`, fullPage: true });
}
await browser.close();
console.log('wrote', pairs.length, 'comparisons to', dir);
