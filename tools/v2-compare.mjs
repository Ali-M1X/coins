/**
 * Per screen: reference image | built screen at 1440px | built screen at 390px.
 *
 *   php tools/v2-showcase.php && node tools/v2-screens.mjs screens && node tools/v2-compare.mjs screens
 *
 * The built screens are the SHOWCASE render (test data), rendered inside a
 * narrow boxed theme column like the live site's, so the captures also show
 * the page breaking out of that box.
 */
import { chromium } from 'playwright';
import { resolve } from 'node:path';
import { readFileSync } from 'node:fs';

// setContent pages cannot read file:// URLs, so images go in as data URIs.
const uri = (path) => 'data:image/png;base64,' + readFileSync(path).toString('base64');

const dir = resolve(process.argv[2] || 'screens');
const root = resolve('.');
const screens = [
  [['1', '2'], 'overview', 'نگاه کلی'], [['3'], 'ecosystem', 'اکوسیستم'], [['4'], 'flow', 'جریان زنجیره'],
  [['5'], 'story', 'داستان و یادگیری'], [['6'], 'dna', 'DNA'], [['7'], 'price-story', 'قیمت به‌روایت'],
  [['8'], 'pulse', 'پالس روزانه'], [['9'], 'peers', 'مقایسه با همتایان'], [['10'], 'supply', 'تامین و ریسک'],
];
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const page = await browser.newPage({ viewport: { width: 2600, height: 800 } });
let n = 0;
for (const [refs, id, title] of screens) {
  n++;
  const refImgs = refs.map((r) => `<img src="${uri(`${root}/${r}.png`)}" style="width:100%;display:block;border:1px solid #333;margin-bottom:10px">`).join('');
  await page.setContent(`<!doctype html><html dir="rtl"><body style="margin:0;background:#151515;color:#eee;font:600 20px sans-serif">
    <div style="padding:14px 18px;font-size:24px">${n}. ${title} — نسخه ۲.۰.۰ (داده آزمایشی)</div>
    <div style="display:flex;gap:18px;padding:0 18px 18px;align-items:flex-start">
      <figure style="margin:0;width:1392px;flex:none"><figcaption style="padding:6px 0">دسکتاپ ۱۴۴۰px</figcaption>
        <img src="${uri(`${dir}/ethereum-desktop-${id}.png`)}" style="width:100%;display:block;border:1px solid #333"></figure>
      <figure style="margin:0;width:360px;flex:none"><figcaption style="padding:6px 0">موبایل ۳۹۰px</figcaption>
        <img src="${uri(`${dir}/ethereum-mobile-${id}.png`)}" style="width:100%;display:block;border:1px solid #333"></figure>
      <figure style="margin:0;flex:1"><figcaption style="padding:6px 0">تصویر مرجع ${refs.map((r) => r + '.png').join(' و ')}</figcaption>${refImgs}</figure>
    </div></body></html>`, { waitUntil: 'load' });
  await page.screenshot({ path: `${dir}/screen-${String(n).padStart(2, '0')}-${id}.png`, fullPage: true });
}
/* The theme header on the classic page and on v2, under the same theme. */
await page.setContent(`<!doctype html><html dir="rtl"><body style="margin:0;background:#151515;color:#eee;font:600 20px sans-serif">
  <div style="padding:14px 18px;font-size:24px">سربرگ قالب — صفحه کلاسیک و نسخه ۲، در یک قالب با جعبه محتوامحور (مثل سایت)</div>
  <div style="display:flex;gap:18px;padding:0 18px 18px;align-items:flex-start">
    <div style="width:1440px;flex:none">
      <div style="padding:6px 0">دسکتاپ ۱۴۴۰px — کلاسیک</div><img src="${uri(`${dir}/header-classic-desktop.png`)}" style="width:100%;display:block;border:1px solid #333">
      <div style="padding:14px 0 6px">دسکتاپ ۱۴۴۰px — نسخه ۲</div><img src="${uri(`${dir}/header-v2-desktop.png`)}" style="width:100%;display:block;border:1px solid #333">
    </div>
    <div style="width:390px;flex:none">
      <div style="padding:6px 0">موبایل ۳۹۰px — کلاسیک</div><img src="${uri(`${dir}/header-classic-mobile.png`)}" style="width:100%;display:block;border:1px solid #333">
      <div style="padding:14px 0 6px">موبایل ۳۹۰px — نسخه ۲</div><img src="${uri(`${dir}/header-v2-mobile.png`)}" style="width:100%;display:block;border:1px solid #333">
    </div>
  </div></body></html>`, { waitUntil: 'load' });
await page.screenshot({ path: `${dir}/screen-00-header.png`, fullPage: true });

await browser.close();
console.log('wrote', screens.length + 1, 'composites to', dir);
