/**
 * Browser price alerts (2.12.0), in a real browser.
 *
 * Runs against v2-ethereum.html (written by THB_TOP20_DIR=$PWD php
 * tools/top20.test.php). Notification is replaced by a recorder so the test
 * can see what would be shown; every network request after load is counted.
 */
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { extname, join, normalize } from 'node:path';

const ROOT = new URL('..', import.meta.url).pathname;
let failed = 0;
const ok = (c, m) => { if (!c) failed++; console.log(`${c ? 'PASS' : 'FAIL'}  ${m}`); };
if (!existsSync(join(ROOT, 'v2-ethereum.html'))) { console.log('FAIL  v2-ethereum.html missing'); process.exit(1); }
const TYPES = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8' };
const server = createServer(async (req, res) => {
  try {
    const path = join(ROOT, normalize(decodeURIComponent(req.url.split('?')[0])));
    const body = await readFile(path);
    res.writeHead(200, { 'Content-Type': TYPES[extname(path)] ?? 'application/octet-stream' });
    res.end(body);
  } catch { res.writeHead(404).end(); }
});
await new Promise((r) => server.listen(8137, r));
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
await ctx.addInitScript(() => {
  window.WebSocket = class { constructor() { this.readyState = 0; } send() {} close() {} };
  window.__notes = [];
  window.__perm = sessionStorage.getItem('perm') || 'default';
  window.Notification = class { constructor(t, o) { window.__notes.push([t, o.body]); } static get permission() { return window.__perm; }
    static requestPermission() { window.__perm = 'granted'; sessionStorage.setItem('perm', 'granted'); return Promise.resolve('granted'); } };
});
const URL0 = 'http://localhost:8137/v2-ethereum.html';
const page = await ctx.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(String(e)));
await page.goto(URL0, { waitUntil: 'load' });
let requests = 0;
const dataReqs = [];
page.on('request', (r) => { if (['fetch', 'xhr', 'beacon', 'ping', 'eventsource'].includes(r.resourceType())) { requests++; dataReqs.push(r.url()); } });

ok(await page.locator('[data-v2-alerts]').isVisible(), 'the alerts card appears (script and storage available)');
const price = await page.evaluate(() => window.__THB_ALERT__.price);
const msg = page.locator('[data-v2-alerts-msg]');

await page.fill('#alert-price', String(Math.floor(price / 2)));
await page.click('.v2-alerts__add');
ok((await msg.textContent()).includes('همین حالا بالاتر') && (await page.$$('.v2-alerts__item')).length === 0, '«above» a price already passed is refused, with the reason');
await page.fill('#alert-price', 'abc');
await page.click('.v2-alerts__add');
ok((await page.getAttribute('#alert-price', 'aria-invalid')) === 'true', 'nonsense is refused and marked aria-invalid');

const target = Math.ceil(price * 1.1);
const faTarget = String(target).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]);
await page.fill('#alert-price', faTarget);
await page.click('.v2-alerts__add');
ok((await msg.textContent()).includes('هشدار ثبت شد') && (await page.$$('.v2-alerts__item')).length === 1, `an alert «above ${faTarget}» (Persian digits) is saved and listed`);
ok(await page.locator('[data-v2-alerts-notify]').isVisible(), 'the notification button is offered — permission is asked only on click');
await page.click('[data-v2-alerts-notify]');
ok((await msg.textContent()).includes('روشن شد') && await page.locator('[data-v2-alerts-notify]').isHidden(), 'after allowing, the button goes away');

await page.reload({ waitUntil: 'load' });
ok((await page.$$('.v2-alerts__item')).length === 1, 'the alert survives a reload (kept in this browser)');

await page.evaluate((p) => document.dispatchEvent(new CustomEvent('thb:live', { detail: { id: 'ethereum', usd: p } })), target - 1);
ok(await page.locator('[data-v2-alerts-toast]').isHidden(), 'a live tick below the target does not fire');
await page.evaluate((p) => document.dispatchEvent(new CustomEvent('thb:live', { detail: { id: 'ethereum', usd: p } })), target + 5);
ok(await page.locator('[data-v2-alerts-toast]').isVisible() && (await page.$$('.v2-alerts__item.is-fired')).length === 1, 'a live tick past the target fires it: an in-page message, marked in the list');
const notes = await page.evaluate(() => window.__notes);
ok(notes.length === 1 && notes[0][0].startsWith('هشدار قیمت'), 'and one browser notification');
await page.evaluate((p) => document.dispatchEvent(new CustomEvent('thb:live', { detail: { id: 'ethereum', usd: p } })), target + 50);
ok((await page.evaluate(() => window.__notes.length)) === 1, 'it fires once, not on every tick');
await page.keyboard.press('Escape');
ok(await page.locator('[data-v2-alerts-toast]').isHidden(), 'Escape dismisses the message');

await page.evaluate(() => {
  const a = JSON.parse(localStorage.getItem('thb-alerts-v1'));
  a.push({ id: 'b1', coin: 'bitcoin', name: 'بیت‌کوین', dir: 'below', price: 1e9, createdAt: Date.now(), firedAt: null });
  localStorage.setItem('thb-alerts-v1', JSON.stringify(a));
});
await page.reload({ waitUntil: 'load' });
ok((await page.$$('.v2-alerts__item.is-fired')).length === 2, "another coin's alert is checked on load against its cached price (Bitcoin «below» fired here)");
await page.locator('.v2-alerts__del').first().click();
ok((await page.$$('.v2-alerts__item')).length === 1 && (await msg.textContent()).includes('حذف شد'), 'an alert can be removed');
ok(requests === 0, `nothing was sent or fetched for any of this (${requests} data requests${dataReqs.length ? ': ' + dataReqs.join(', ') : ''})`);
ok(errors.length === 0, 'no script errors' + (errors.length ? ': ' + errors.join('; ') : ''));
await page.close();

{
  const p = await ctx.newPage();
  await p.addInitScript(() => { Object.defineProperty(window, 'localStorage', { get() { throw new Error('blocked'); } }); });
  await p.goto(URL0, { waitUntil: 'load' });
  ok(await p.locator('[data-v2-alerts]').isHidden(), 'storage blocked (private mode): alerts are not offered, rather than offered and lost');
  await p.close();
}
for (const width of [390, 360]) {
  const p = await browser.newPage({ viewport: { width, height: 900 } });
  await p.goto(URL0, { waitUntil: 'load' });
  const sideways = await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  const box = await p.locator('[data-v2-alerts]').boundingBox();
  ok(!sideways && box && box.width <= width, `${width}px: the alerts card fits, no sideways scroll`);
  await p.close();
}
await browser.close();
server.close();
console.log(failed ? `\n${failed} FAILED` : '\nAll alert tests passed.');
process.exit(failed ? 1 : 0);
