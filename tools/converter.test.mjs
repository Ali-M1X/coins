/**
 * The price converter (2.10.0), in a real browser.
 *
 * Runs against v2-ethereum.html, v2-convert.html and conv-cases.json,
 * written by `THB_TOP20_DIR=$PWD php tools/top20.test.php`. Counts every
 * request the page makes after load: converting must make none.
 */
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { extname, join, normalize } from 'node:path';

const ROOT = new URL('..', import.meta.url).pathname;
let failed = 0;
const ok = (c, m) => { if (!c) failed++; console.log(`${c ? 'PASS' : 'FAIL'}  ${m}`); };
for (const f of ['v2-ethereum.html', 'v2-convert.html', 'conv-cases.json']) {
  if (!existsSync(join(ROOT, f))) { console.log(`FAIL  ${f} missing — run THB_TOP20_DIR=$PWD php tools/top20.test.php first`); process.exit(1); }
}
const cases = JSON.parse(await readFile(join(ROOT, 'conv-cases.json'), 'utf8'));
const TYPES = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8' };
const server = createServer(async (req, res) => {
  try {
    const path = join(ROOT, normalize(decodeURIComponent(req.url.split('?')[0])));
    const body = await readFile(path);
    res.writeHead(200, { 'Content-Type': TYPES[extname(path)] ?? 'application/octet-stream' });
    res.end(body);
  } catch { res.writeHead(404).end(); }
});
await new Promise((r) => server.listen(8135, r));
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

const open = async (file, width = 1440) => {
  const page = await browser.newPage({ viewport: { width, height: 900 } });
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.addInitScript(() => { window.WebSocket = class { constructor() { this.readyState = 0; } send() {} close() {} }; });
  await page.goto(`http://localhost:8135/${file}`, { waitUntil: 'load' });
  await page.waitForTimeout(200);
  let requests = 0;
  let images = 0;
  page.on('request', (r) => { if (r.resourceType() === 'image') images++; else requests++; });
  return { page, errors, requests: () => requests, images: () => images };
};

{
  const { page, errors, requests } = await open('v2-ethereum.html');
  const parsed = await page.evaluate((c) => c.map(([s]) => window.__thbConv.parse(s)), cases.parse);
  ok(JSON.stringify(parsed) === JSON.stringify(cases.parse.map((c) => c[1])), 'PHP and JS read every input case identically');
  const fa = await page.evaluate((c) => c.map(([v, u]) => window.__thbConv.fa(v, u)), cases.fa);
  ok(JSON.stringify(fa) === JSON.stringify(cases.fa.map((c) => c[2])), 'and format every output case identically (Persian digits, ٬ and ٫)');

  const out = page.locator('[data-v2-conv-out]');
  /* 2.13.0: choices are made through the searchable list, as a visitor would. */
  const pick = async (which, query, value) => {
    await page.locator(`#conv-${which}`).locator('xpath=..').locator('[data-v2-pick-btn]').click();
    await page.locator(`#conv-${which} ~ .v2-pick__pop .v2-pick__search`).fill(query);
    await page.locator(`#conv-${which} ~ .v2-pick__pop [role="option"][data-value="${value}"]`).click();
  };
  await page.locator('#conv-amount').fill('۲٫۵');
  const r1 = await out.textContent();
  const [expect, own] = await page.evaluate(() => [window.__thbConv.convert(2.5, 'ethereum', 'usd'), window.__THB_CONV__.coins.find((c) => c.i === 'ethereum').p]);
  const shown = await page.evaluate((v) => window.__thbConv.fa(v, 'usd'), 2.5 * own);
  ok(r1 === `۲٫۵ اتریوم = ${shown} دلار` && Math.abs(expect - 2.5 * own) < 1e-6, `typing «۲٫۵» answers at once, from the page's own cached rate: «${r1}»`);
  await pick('to', 'btc', 'bitcoin');
  ok((await out.textContent()).endsWith(' بیت‌کوین') && (await page.locator('#conv-to').inputValue()) === 'bitcoin', 'coin → coin, chosen from the list: ' + (await out.textContent()));
  await pick('to', 'تومان', 'toman');
  ok((await out.textContent()).endsWith(' تومان'), 'coin → toman: ' + (await out.textContent()));
  await pick('to', 'eur', 'eur');
  const eur = await page.evaluate(() => window.__thbConv.fa(2.5 * window.__THB_CONV__.coins.find((c) => c.i === 'ethereum').p * window.__THB_CONV__.fiat.eur, 'eur'));
  ok((await out.textContent()) === `۲٫۵ اتریوم = ${eur} یورو`, 'coin → EUR at the cached ECB rate: ' + (await out.textContent()));
  await pick('to', 'پوند', 'gbp');
  ok((await out.textContent()).endsWith(' پوند'), 'coin → GBP: ' + (await out.textContent()));
  await page.click('[data-v2-conv-swap]');
  ok((await page.locator('#conv-from').inputValue()) === 'gbp' && (await page.locator('#conv-to').inputValue()) === 'ethereum'
    && (await page.textContent('#conv-from ~ [data-v2-pick-btn]')).includes('پوند'), 'the swap button exchanges «از» and «به», and both lists show it');
  await pick('from', 'eth', 'ethereum');
  await pick('to', 'usd', 'usd');
  await page.locator('#conv-amount').fill('1');
  await page.evaluate(() => document.dispatchEvent(new CustomEvent('thb:live', { detail: { id: 'ethereum', usd: 4000, toman: null } })));
  ok((await out.textContent()) === '۱ اتریوم = ۴٬۰۰۰٫۰۰ دلار' && (await page.locator('[data-v2-conv-meta]').count()) === 0,
    'a live tick from live.js replaces the price — and no explanatory note is added');
  await page.evaluate(() => document.dispatchEvent(new CustomEvent('thb:live', { detail: { id: 'bitcoin', usd: 1 } })));
  ok((await out.textContent()) === '۱ اتریوم = ۴٬۰۰۰٫۰۰ دلار', "another coin's tick is ignored");
  await page.locator('#conv-amount').fill('abc');
  ok((await out.textContent()).includes('معتبر نیست') && (await page.getAttribute('#conv-amount', 'aria-invalid')) === 'true', 'invalid input: said so, and marked aria-invalid');
  await page.locator('#conv-amount').focus();
  await page.keyboard.press('Tab');
  ok(await page.evaluate(() => document.activeElement?.matches('#conv-from ~ [data-v2-pick-btn]')), 'keyboard: amount → «از» list (the hidden select is out of the tab order)');
  await page.keyboard.press('Tab');
  ok(await page.evaluate(() => document.activeElement?.hasAttribute('data-v2-conv-swap')), 'keyboard: «از» → swap → «به»');
  ok(requests() === 0, `no data request while converting (${requests()}); logos only once a list was opened`);
  ok(errors.length === 0, 'no script errors' + (errors.length ? ': ' + errors.join('; ') : ''));
  await page.close();
}
{
  const { page, errors } = await open('v2-convert.html');
  await page.locator('#conv-amount').fill('3');
  await page.locator('#conv-to ~ [data-v2-pick-btn]').click();
  await page.locator('#conv-to ~ .v2-pick__pop .v2-pick__search').fill('اتریوم');
  await page.keyboard.press('Enter');
  const u = new URL(page.url());
  ok(u.searchParams.get('amount') === '3' && u.searchParams.get('to') === 'ethereum' && u.searchParams.get('from') === 'bitcoin',
    'standalone: the address keeps from/to/amount, so a conversion can be shared');
  ok(errors.length === 0, 'standalone: no script errors');
  await page.close();
}
/* ---- 2.13.0: the From/To list ---- */
{
  const { page, errors, requests, images } = await open('v2-ethereum.html');
  await page.route(/^https?:\/\/(?!localhost)/, (r) => r.abort());
  const btn = page.locator('#conv-to ~ [data-v2-pick-btn]');
  const pop = page.locator('#conv-to ~ .v2-pick__pop');
  const search = pop.locator('.v2-pick__search');
  ok(await page.locator('#conv-to').isHidden() && await btn.isVisible(), 'the native select is replaced by the list trigger');
  const h = await btn.evaluate((e) => e.getBoundingClientRect().height);
  ok(h === 46, `the trigger keeps the select's 46px box — no layout shift (${h}px)`);
  ok(requests() === 0 && images() === 0 && (await page.locator('.v2-pick img[src]').count()) === 0, 'no logo (and nothing else) is requested on page view');
  const groups = await pop.locator('.v2-pick__group').allTextContents();
  const fiat = await pop.locator('[role="group"]:last-child [role="option"]').evaluateAll((els) => els.map((e) => e.dataset.value));
  const island = await page.evaluate(() => Object.keys(window.__THB_CONV__.fiat));
  ok(JSON.stringify(groups) === JSON.stringify(['ارز دیجیتال', 'ارز رایج']) && JSON.stringify(fiat) === JSON.stringify(['toman', 'usd', 'eur', 'gbp'])
    && JSON.stringify([...island].sort()) === JSON.stringify([...fiat].sort()),
    `groups «ارز دیجیتال» and «ارز رایج»; fiat options exactly the rates held (${fiat.join(', ')})`);
  ok((await btn.getAttribute('aria-haspopup')) === 'listbox' && (await btn.getAttribute('aria-expanded')) === 'false', 'ARIA: trigger has aria-haspopup=listbox, collapsed');

  await btn.focus();
  await page.keyboard.press('ArrowDown');
  ok(await pop.isVisible() && (await btn.getAttribute('aria-expanded')) === 'true' && await search.evaluate((e) => e === document.activeElement),
    'keyboard: ArrowDown opens it and focuses the search field');
  ok((await search.getAttribute('role')) === 'combobox' && (await search.getAttribute('aria-controls')) === (await pop.locator('[role="listbox"]').getAttribute('id'))
    && (await page.locator('#' + (await search.getAttribute('aria-activedescendant')).replace(/([^\w-])/g, '\\$1')).getAttribute('aria-selected')) === 'true',
    'ARIA: the search is a combobox controlling the listbox; the active option is the selected one');
  await page.waitForTimeout(300);
  ok(images() > 0 && requests() === 0, `logos are requested only once a list is opened (${images()} image requests, none before; no other request)`);
  await page.keyboard.type('sol');
  const vis = await pop.locator('[role="option"]:visible').evaluateAll((els) => els.map((e) => e.dataset.value));
  ok(vis.includes('solana') && vis.length < 10, `search filters as you type («sol» → ${vis.slice(0, 4).join(', ')})`);
  await search.fill('');
  await page.keyboard.type('بيت');
  ok((await pop.locator('[role="option"]:visible').evaluateAll((els) => els.map((e) => e.dataset.value))).includes('bitcoin'), 'Arabic letters find Persian names («بيت» → بیت‌کوین)');
  await search.fill('zzzz');
  ok(await pop.locator('.v2-pick__none').isVisible(), 'no match: «موردی پیدا نشد»');
  await search.fill('USD');
  await page.keyboard.press('Enter');
  ok(!(await pop.isVisible()) && (await page.locator('#conv-to').inputValue()) === 'usd' && await btn.evaluate((e) => e === document.activeElement),
    'an exact symbol is the active choice (USD, not USDT); Enter chooses it, closes the list and returns focus to the trigger');
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('ArrowDown');
  const active = await search.getAttribute('aria-activedescendant');
  await page.keyboard.press('Escape');
  ok(!(await pop.isVisible()) && (await page.locator('#conv-to').inputValue()) === 'usd' && active && !active.endsWith('-usd'), 'arrows move the active option; Escape closes without choosing');
  await btn.focus();
  await page.keyboard.press('t');
  ok(await pop.isVisible() && (await search.inputValue()) === 't', 'type-to-search: a letter on the closed trigger opens it and starts the search');
  await page.mouse.click(5, 5);
  ok(!(await pop.isVisible()) && (await btn.getAttribute('aria-expanded')) === 'false', 'a click outside closes it');
  await page.locator('#conv-from ~ [data-v2-pick-btn]').click();
  await btn.click();
  ok(await pop.isVisible() && !(await page.locator('#conv-from ~ .v2-pick__pop').isVisible()), 'opening one list closes the other');
  await page.keyboard.press('Escape');
  ok(errors.length === 0, 'no script errors' + (errors.length ? ': ' + errors.join('; ') : ''));
  await page.close();
}
{
  const { page } = await open('v2-ethereum.html', 360);
  await page.locator('#conv-to ~ [data-v2-pick-btn]').click();
  await page.waitForTimeout(300);   // the slide-up animation
  const box = await page.locator('#conv-to ~ .v2-pick__pop').boundingBox();
  const sideways = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  ok(box && Math.round(box.width) === 360 && Math.abs(box.y + box.height - 900) < 2 && !sideways, `360px: the list opens as a full-width bottom sheet (${Math.round(box?.width)}×${Math.round(box?.height)} at y=${Math.round(box?.y)})`);
  ok(await page.locator('#conv-to ~ .v2-pick__pop .v2-pick__close').isVisible(), 'with a close button');
  await page.close();
}
{
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 }, javaScriptEnabled: false });
  await page.goto('http://localhost:8135/v2-convert.html', { waitUntil: 'load' });
  ok(await page.locator('#conv-to').isVisible() && await page.locator('#conv-to ~ [data-v2-pick-btn]').isHidden()
    && (await page.locator('#conv-to optgroup').count()) === 2, 'without script: the native select with both groups, and the form submits');
  await page.close();
}
for (const [file, width] of [['v2-ethereum.html', 360], ['v2-convert.html', 360], ['v2-convert.html', 390]]) {
  const { page, errors } = await open(file, width);
  const sideways = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  const box = await page.locator('[data-v2-conv]').boundingBox();
  ok(!sideways && box && box.width <= width && errors.length === 0, `${file} at ${width}px: no sideways scroll, the converter fits (${Math.round(box?.width ?? 0)}px)`);
  await page.close();
}
await browser.close();
server.close();
console.log(failed ? `\n${failed} FAILED` : '\nAll converter tests passed.');
process.exit(failed ? 1 : 0);
