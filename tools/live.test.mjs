/**
 * Browser tests for the live price over WebSocket (2.4.0, assets/v2/live.js).
 *
 *   node tools/live.test.mjs
 *
 * Runs against v2.html (Ethereum) and coins-list.html, written by
 * v2-render.test.php. The exchanges are NEVER contacted: window.WebSocket is
 * replaced by a scripted mock before the page loads, so every case — ticks,
 * silence, refusal, hidden tab — is reproducible offline. The page's own
 * polling endpoint is answered by page.route().
 *
 * Covered: message parsing (Kraken v2, Nobitex/Centrifugo), symbol mapping,
 * one socket per exchange, throttled repaint, flash + reduced motion, reserved
 * width, stale marking, arbitration with polling, fallback to Nobitex USDT,
 * reconnect backoff and give-up, hidden-tab pause.
 */

import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { extname, join, normalize } from 'node:path';

const ROOT = new URL('..', import.meta.url).pathname;
const PORT = 8126;
const TYPES = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8' };

for (const f of ['v2.html', 'coins-list.html']) {
  if (!existsSync(join(ROOT, f))) {
    console.log(`FAIL  ${f} missing — run THB_DUMP_HTML=$PWD/v2.html php tools/v2-render.test.php first`);
    process.exit(1);
  }
}

const server = createServer(async (req, res) => {
  try {
    const path = join(ROOT, normalize(decodeURIComponent(req.url.split('?')[0])));
    const body = await readFile(path);
    res.writeHead(200, { 'Content-Type': TYPES[extname(path)] ?? 'application/octet-stream' });
    res.end(body);
  } catch {
    res.writeHead(404).end('not found');
  }
});
await new Promise((r) => server.listen(PORT, r));

let failed = 0;
const ok = (cond, msg) => { if (!cond) failed++; console.log(`${cond ? 'PASS' : 'FAIL'}  ${msg}`); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const BASE = `http://localhost:${PORT}`;

/* The mock socket, installed before any page script runs. __wsMode maps a URL
   fragment to 'refuse' (closes before opening, like a blocked host). Short
   stale / hidden timings so the cases run in seconds, not minutes. */
const MOCK = ({ stale, hidden, pastPoll }) => {
  window.__sockets = [];
  window.__wsMode = {};
  class MockWS {
    constructor(url) {
      this.url = url; this.readyState = 0; this.sent = [];
      window.__sockets.push(this);
      const mode = Object.entries(window.__wsMode).find(([k]) => url.includes(k))?.[1];
      setTimeout(() => {
        if (mode === 'refuse') { this.readyState = 3; this.onerror?.({}); this.onclose?.({ code: 1006 }); return; }
        if (this.readyState !== 0) return;
        this.readyState = 1; this.onopen?.({});
      }, 5);
    }
    send(t) { this.sent.push(t); }
    close(code) { if (this.readyState === 3) return; this.readyState = 3; this.closedWith = code; setTimeout(() => this.onclose?.({ code: code || 1005 }), 0); }
    _recv(d) { if (this.readyState === 1) this.onmessage?.({ data: typeof d === 'string' ? d : JSON.stringify(d) }); }
    _drop() { this.readyState = 3; this.onclose?.({ code: 1006 }); }
  }
  window.WebSocket = MockWS;
  let live;
  Object.defineProperty(window, '__THB_LIVE__', {
    configurable: true,
    get: () => live,
    set: (v) => { for (const f of Object.values(v.feeds)) f.stale = stale; v.hidden = hidden; live = v; },
  });
  if (pastPoll) {
    let v2;
    Object.defineProperty(window, '__THB_V2__', {
      configurable: true,
      get: () => v2,
      set: (v) => { if (v.live) v.live.nextRefreshAt = new Date(Date.now() - 1000).toISOString(); v2 = v; },
    });
  }
};

async function open(file, opts = {}) {
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, reducedMotion: opts.reduced ? 'reduce' : 'no-preference' });
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.addInitScript(MOCK, { stale: opts.stale ?? 1500, hidden: opts.hidden ?? 300, pastPoll: !!opts.poll });
  if (opts.refuse) await page.addInitScript((r) => { for (const k of r) window.__wsMode[k] = 'refuse'; }, opts.refuse);
  if (opts.poll) {
    await page.route('**/wp-json/thehybit/v1/price/**', (route) => route.fulfill({
      contentType: 'application/json', body: JSON.stringify(page.__pollBody ?? {}),
    }));
  }
  await page.goto(`${BASE}/${file}`, { waitUntil: 'load' });
  await page.waitForFunction(() => window.__sockets.some((s) => s.readyState === 1) || window.__sockets.length > 0);
  await sleep(30);
  return { page, errors };
}

/* Deliver a frame on the newest open socket whose URL contains `part`. */
const recv = (page, part, msg) => page.evaluate(([p, m]) => {
  const s = [...window.__sockets].reverse().find((x) => x.url.includes(p) && x.readyState === 1);
  s?._recv(m);
  return !!s;
}, [part, msg]);
const kTick = (sym, last, change_pct) => ({ channel: 'ticker', type: 'update', data: [{ symbol: sym, last, change_pct, volume: 1, bid: last, ask: last }] });
const nStats = (sym, latest, dayChange, extra = {}) => ({ push: { channel: `public:market-stats-${sym}`, pub: { data: JSON.stringify({ isClosed: false, latest: String(latest), dayChange: String(dayChange), ...extra }) } } });
const heartbeat = { channel: 'heartbeat' };
const heroPrice = (page) => page.$eval('[data-live-usd] .v2-num', (e) => e.textContent);

/* =============================================== 1. pure functions */
{
  const { page } = await open('v2.html');
  const r = await page.evaluate(async () => {
    const m = await import('/thehybit-coins/assets/v2/live.js');
    const k = m.parseKraken(JSON.stringify({ channel: 'ticker', type: 'snapshot', data: [{ symbol: 'BTC/USD', last: 62000.5, change_pct: -1.25 }, { symbol: 'X/USD', last: 0 }] }));
    const kHb = m.parseKraken('{"channel":"heartbeat"}');
    const kRej = m.parseKraken(JSON.stringify({ method: 'subscribe', success: false, error: 'Currency pair not supported', symbol: 'HYPE/USD' }));
    const kJunk = m.parseKraken('not json');
    const n = m.parseNobitex('{}\n' + JSON.stringify({ push: { channel: 'public:market-stats-BTCIRT', pub: { data: '{"isClosed":false,"latest":"62000000000","dayChange":"-5.12"}' } } })
      + '\n' + JSON.stringify({ push: { channel: 'public:market-stats-ETHUSDT', pub: { data: { latest: '3000.1', dayChange: '1.5' } } } }));
    const nClosed = m.parseNobitex(JSON.stringify({ push: { channel: 'public:market-stats-XIRT', pub: { data: { isClosed: true, latest: '5' } } } }));
    const nConn = m.parseNobitex('{"id":1,"connect":{"client":"x","version":"5"}}');
    const nErr = m.parseNobitex('{"id":4,"error":{"code":102,"message":"unknown channel"}}');
    const b = [1, 2, 3, 10, 30].map((a) => [m.backoff(a, () => 0), m.backoff(a, () => 1)]);
    return { k, kHb, kRej, kJunk, n, nClosed, nConn, nErr, b,
      fmt: [m.usd(62000.5), m.usd(0.0000123456), m.pct(-1.234), m.pct(2), m.toman(6200000000 / 10)] };
  });
  ok(r.k.length === 1 && r.k[0].sym === 'BTC/USD' && r.k[0].price === 62000.5 && r.k[0].change === -1.25,
    'Kraken ticker: symbol, last price and 24h change parsed; a zero price is dropped, not shown');
  ok(r.kHb[0].kind === 'alive' && r.kRej[0].kind === 'reject' && r.kRej[0].sym === 'HYPE/USD' && r.kJunk[0].kind === 'junk',
    'Kraken heartbeat = alive; an unsupported pair is a reject for that symbol; garbage is ignored');
  ok(r.n.length === 3 && r.n[0].kind === 'ping' && r.n[1].sym === 'BTCIRT' && r.n[1].price === 62000000000 && r.n[1].change === -5.12
    && r.n[2].sym === 'ETHUSDT' && r.n[2].price === 3000.1,
    'Nobitex: a frame of several lines is split; {} is a ping; data as JSON string or object both parse');
  ok(r.nClosed[0].kind !== 'tick' && r.nConn[0].kind === 'connected' && r.nErr[0].kind === 'reject' && r.nErr[0].id === 4,
    'Nobitex: a closed market gives no price; connect reply and per-channel errors are recognised');
  ok(r.b[0][0] === 500 && r.b[0][1] === 1000 && r.b[1][1] === 2000 && r.b[2][1] === 4000 && r.b[3][1] === 60000 && r.b[4][1] === 60000,
    'backoff: 1 s, 2 s, 4 s … capped at 60 s, with up to 50 % jitter below');
  ok(JSON.stringify(r.fmt) === JSON.stringify(['$62,000.50', '$0.00001235', '−1.23%', '+2.00%', '620,000,000']),
    'formatting matches the page: USD, signed %, rial ÷ 10 = toman');
  await page.close();
}

/* =============================== 2. mapping, one socket per exchange */
{
  const { page, errors } = await open('coins-list.html');
  const s = await page.evaluate(() => window.__sockets.map((x) => ({ url: x.url, sent: x.sent })));
  const kr = s.filter((x) => x.url.includes('kraken'));
  const nb = s.filter((x) => x.url.includes('nobitex'));
  ok(kr.length === 1 && nb.length === 1, `list page: exactly one Kraken and one Nobitex socket for all its live rows (${s.length} sockets)`);
  const sub = JSON.parse(kr[0].sent[0] || '{}');
  ok(sub.method === 'subscribe' && sub.params?.channel === 'ticker'
    && JSON.stringify([...sub.params.symbol].sort()) === JSON.stringify(['BTC/USD', 'ETH/USD', 'SOL/USD', 'USDT/USD']),
    'Kraken: one subscribe message for every mapped row (' + (sub.params?.symbol || []).join(', ') + ')');
  ok(JSON.parse(nb[0].sent[0] || '{}').connect && nb[0].sent.length === 1, 'Nobitex: connect first, no subscription before the server accepts');
  await recv(page, 'nobitex', '{"id":1,"connect":{"client":"c"}}');
  const nsent = await page.evaluate(() => window.__sockets.find((x) => x.url.includes('nobitex')).sent.slice(1).map((t) => JSON.parse(t).subscribe.channel).sort());
  ok(JSON.stringify(nsent) === JSON.stringify(['public:market-stats-BTCUSDT', 'public:market-stats-ETHUSDT', 'public:market-stats-SOLUSDT']),
    'Nobitex on the list: USDT markets only (no toman column, so no rial channels; Tether has no USDT market)');
  await recv(page, 'nobitex', '{}');
  ok(await page.evaluate(() => window.__sockets.find((x) => x.url.includes('nobitex')).sent.at(-1)) === '{}', 'Nobitex ping {} is answered {}');

  const unmapped = await page.$$eval('tbody tr:not([data-live-id]) .c-price', (els) => els.slice(0, 5).map((e) => e.textContent));
  await recv(page, 'kraken', kTick('BTC/USD', 70123.45, 3.21));
  await recv(page, 'kraken', heartbeat);
  await sleep(1200);
  const btc = await page.$eval('tr[data-live-id="bitcoin"]', (tr) => ({
    price: tr.querySelector('[data-live-usd] .v2-num').textContent,
    chg: tr.querySelector('td.c-24h .v2-num').textContent, cls: tr.querySelector('td.c-24h').className,
    sub: tr.querySelector('.list-sub .v2-num').textContent, vol: tr.querySelector('.c-vol').textContent,
  }));
  ok(btc.price === '$70,123.45' && btc.chg === '+3.21%' && btc.cls.includes('v2-delta--up') && btc.sub === '+3.21%',
    'list: a Kraken tick updates the bitcoin row\'s price and both 24h figures');
  ok(btc.vol === '$31.00B', 'list: volume stays the server\'s global figure');
  const after = await page.$$eval('tbody tr:not([data-live-id]) .c-price', (els) => els.slice(0, 5).map((e) => e.textContent));
  ok(JSON.stringify(unmapped) === JSON.stringify(after), 'list: rows no feed carries are never touched');
  ok(errors.length === 0, 'no page errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await page.close();
}

/* =========== 3. coin page: tick, stamp, throttle, flash, reserved width */
{
  const { page, errors } = await open('v2.html');
  const server = await heroPrice(page);
  ok(/^\$[0-9,]+\.\d\d$/.test(server), `before any tick the server-rendered price stands (${server})`);
  const minW0 = await page.$eval('[data-live-usd]', (e) => e.style.minWidth);
  ok(/px$/.test(minW0), 'the price box has its width reserved before the first tick (' + minW0 + ')');

  await page.evaluate(() => {
    window.__paints = 0;
    new MutationObserver((m) => { window.__paints += m.length; }).observe(document.querySelector('[data-live-usd] .v2-num'), { childList: true, characterData: true, subtree: true });
  });
  await recv(page, 'kraken', kTick('ETH/USD', 3000, 1.5));
  await recv(page, 'kraken', heartbeat);
  await sleep(150);
  const first = await heroPrice(page);
  for (let i = 1; i <= 10; i++) { await recv(page, 'kraken', kTick('ETH/USD', 3000 + i, 1.5)); await sleep(30); }
  await sleep(1300);
  const paints = await page.evaluate(() => window.__paints);
  ok(first === '$3,000.00', 'the first real tick replaces the price within one repaint');
  ok(paints <= 3 && (await heroPrice(page)) === '$3,010.00', `ten ticks in 300 ms → ${paints} repaints, ending on the latest value (≈1 per second per coin)`);
  const st = await page.$eval('[data-live-stamp]', (e) => e.textContent);
  ok(st.includes('قیمت زنده') && st.includes('Kraken'), 'the stamp says the figure is live, and from where: «' + st + '»');
  const chg = await page.$eval('[data-live-chg]', (e) => ({ t: e.querySelector('.v2-num').textContent, c: e.className, a: e.querySelector('[data-v2-live-arrow]').textContent }));
  ok(chg.t === '+1.50%' && chg.c.includes('v2-delta--up') && chg.a === '▲', '24h change, its colour and arrow follow the tick');

  await page.waitForTimeout(1100);
  await recv(page, 'kraken', heartbeat);
  const box0 = await page.$eval('[data-live-chg]', (e) => e.getBoundingClientRect().right);
  await recv(page, 'kraken', kTick('ETH/USD', 2999.5, -0.2));
  await sleep(80);
  const flashDown = await page.$eval('[data-live-usd]', (e) => e.className);
  ok(flashDown.includes('is-tick-down'), 'a fall flashes red (is-tick-down)');
  await sleep(1100);
  await recv(page, 'kraken', heartbeat);
  await recv(page, 'kraken', kTick('ETH/USD', 9.5, -0.2));
  await sleep(1100);
  const box1 = await page.$eval('[data-live-chg]', (e) => e.getBoundingClientRect().right);
  ok(Math.abs(box1 - box0) < 0.5, `a much shorter price does not move the line (change badge right edge ${box0.toFixed(1)} → ${box1.toFixed(1)})`);

  /* Nobitex: toman from the rial market. */
  await recv(page, 'nobitex', '{"id":1,"connect":{}}');
  await recv(page, 'nobitex', nStats('ETHIRT', 3123456780, 2.1));
  await sleep(1100);
  const tm = await page.$eval('[data-live-irt] .v2-num', (e) => e.textContent);
  ok(tm === '312,345,678', 'toman line: Nobitex ETHIRT 3,123,456,780 rial → 312,345,678 toman');
  ok(errors.length === 0, 'no page errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await page.close();
}

/* ========================================= 4. reduced motion: no flash */
{
  const { page } = await open('v2.html', { reduced: true });
  await page.evaluate(() => { window.__flash = 0; new MutationObserver(() => { if (document.querySelector('[data-live-usd]').className.includes('is-tick')) window.__flash++; }).observe(document.querySelector('[data-live-usd]'), { attributes: true }); });
  await recv(page, 'kraken', kTick('ETH/USD', 3000, 1)); await recv(page, 'kraken', heartbeat);
  await sleep(1100);
  await recv(page, 'kraken', kTick('ETH/USD', 3100, 1)); await recv(page, 'kraken', heartbeat);
  await sleep(1100);
  ok((await heroPrice(page)) === '$3,100.00' && (await page.evaluate(() => window.__flash)) === 0,
    'prefers-reduced-motion: the price still updates, without any flash');
  await page.close();
}

/* =========================== 5. stale marking and arbitration with polling */
{
  const { page, errors } = await open('v2.html', { poll: true, refuse: [] });
  const poll = async (body) => {
    page.__pollBody = body;
    await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    await sleep(250);
  };
  await recv(page, 'kraken', kTick('ETH/USD', 3333, 2)); await recv(page, 'kraken', heartbeat);
  await sleep(1100);
  const now = () => new Date().toISOString();
  await poll({ price: 1111, change24h: -9, fetchedAt: now(), nextRefreshAt: new Date(Date.now() - 1000).toISOString(), toman: 1 });
  ok((await heroPrice(page)) === '$3,333.00', 'while the feed is live, a poll does not overwrite the live price');

  /* Silence: no heartbeat for longer than `stale`. */
  await sleep(2600);
  const stale = await page.$eval('[data-live-usd]', (e) => ({ c: e.className, src: e.dataset.liveSrc, t: e.title }));
  const stStale = await page.$eval('[data-live-stamp]', (e) => e.textContent);
  ok(stale.c.includes('is-stale') && !stale.src && stale.t.includes('آخرین قیمت زنده'), 'a silent feed marks the figure stale (dimmed, titled with the last tick time)');
  ok(stStale.includes('اتصال زنده قطع شد') && (await heroPrice(page)) === '$3,333.00', 'and says so in the stamp — never an empty price, never a silent freeze');

  await poll({ price: 2222, change24h: -1, fetchedAt: new Date(Date.now() - 600000).toISOString(), nextRefreshAt: new Date(Date.now() - 1000).toISOString(), toman: 1 });
  ok((await heroPrice(page)) === '$3,333.00', 'a poll OLDER than the last live tick does not replace it');
  await poll({ price: 2345.67, change24h: -1, fetchedAt: now(), nextRefreshAt: new Date(Date.now() + 60000).toISOString(), toman: 1 });
  const back = await page.$eval('[data-live-usd]', (e) => e.className);
  const stBack = await page.$eval('[data-live-stamp]', (e) => e.textContent);
  ok((await heroPrice(page)) === '$2,345.67' && !back.includes('is-stale') && stBack.includes('به‌روزرسانی'),
    'a newer poll takes the figure back, clears the stale mark and restores the stamp');
  ok(errors.length === 0, 'no page errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await page.close();
}

/* ============================ 6. Kraken blocked → Nobitex USDT fallback */
{
  const { page, errors } = await open('v2.html', { refuse: ['kraken'] });
  await recv(page, 'nobitex', '{"id":1,"connect":{}}');
  await recv(page, 'nobitex', nStats('ETHUSDT', 2987.65, -0.4));
  await sleep(1100);
  const st = await page.$eval('[data-live-stamp]', (e) => e.textContent);
  ok((await heroPrice(page)) === '$2,987.65' && st.includes('USDT') && st.includes('نوبیتکس'),
    'Kraken refused: the Nobitex ETHUSDT tick fills the price, and the stamp says it is a USDT price from Nobitex');
  await sleep(2600);   // nothing more from Nobitex either
  ok((await page.$eval('[data-live-usd]', (e) => e.className)).includes('is-stale'), 'when Nobitex falls silent too, the fallback figure is marked stale');
  ok(errors.length === 0, 'no page errors');
  await page.close();
}

/* ================================== 7. everything blocked: page as before */
{
  const { page, errors } = await open('v2.html', { refuse: ['kraken', 'nobitex'] });
  const before = await page.$eval('.v2-overview__id', (e) => e.innerText);
  await sleep(2500);
  const after = await page.$eval('.v2-overview__id', (e) => e.innerText);
  const marks = await page.$$eval('.is-stale, [data-live-src]', (e) => e.length);
  ok(before === after && marks === 0 && errors.length === 0,
    'both feeds refused (a blocked region): the hero is unchanged, no stale mark, no error — the polling page as before');
  await page.close();
}

/* ======================= 8. reconnect backoff and give-up (fake clock) */
{
  const { page } = await open('v2.html');
  const r = await page.evaluate(async () => {
    const { Feed } = await import('/thehybit-coins/assets/v2/live.js');
    let t = 0; const q = [];
    const made = [];
    class Refused { constructor(u) { made.push(t); this.readyState = 0; q.push({ at: t + 5, f: () => { this.readyState = 3; this.onclose?.({}); } }); } send() {} close() {} }
    const env = { WebSocket: Refused, now: () => t, setTimeout: (f, ms) => { const o = { at: t + ms, f }; q.push(o); return o; }, clearTimeout: (o) => { const i = q.indexOf(o); if (i >= 0) q.splice(i, 1); } };
    const run = (until) => { for (;;) { q.sort((a, b) => a.at - b.at); const n = q[0]; if (!n || n.at > until) break; q.shift(); t = n.at; n.f(); } t = until; };
    const f = new Feed('kraken', { url: 'wss://x', stale: 15000 }, ['BTC/USD'], env, () => {});
    f.start();
    run(10 * 60 * 1000);
    const gaps = made.slice(1).map((v, i) => v - made[i]);

    /* A feed that DID open once keeps retrying after a drop. */
    let opened = 0; const sockets = [];
    class Flaky { constructor() { sockets.push(this); this.readyState = 0; q.push({ at: t + 5, f: () => { if (opened++ === 0) { this.readyState = 1; this.onopen?.(); this.onmessage?.({ data: '{"channel":"heartbeat"}' }); } else { this.readyState = 3; this.onclose?.({}); } } }); } send() {} close() {} }
    env.WebSocket = Flaky;
    const g = new Feed('kraken', { url: 'wss://y', stale: 15000 }, ['BTC/USD'], env, () => {});
    g.start(); run(t + 100);
    sockets[0].readyState = 3; sockets[0].onclose?.({});
    run(t + 10 * 60 * 1000);
    return { made: made.length, gaveUp: f.gaveUp, gaps, flaky: sockets.length, gGaveUp: g.gaveUp };
  });
  ok(r.made === 5 && r.gaveUp, `a feed that never opens (blocked) is tried 5 times, then left alone: page stays on polling (${r.made} attempts)`);
  ok(r.gaps.every((g, i) => i === 0 || g >= r.gaps[i - 1] * 0.5) && r.gaps.every((g) => g >= 500 && g <= 60010),
    'the retries back off: ' + r.gaps.map((g) => (g / 1000).toFixed(1) + ' s').join(', '));
  ok(r.flaky > 6 && !r.gGaveUp, `a feed that worked and then dropped keeps reconnecting with backoff (${r.flaky} sockets in 10 min, not hundreds)`);
  ok(r.flaky < 30, 'and stays within Kraken\'s connection-attempt limit (~150 per 10 min per IP)');
  await page.close();
}

/* ======================================= 9. hidden tab pauses the sockets */
{
  const { page } = await open('v2.html');
  await page.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, get: () => true }); document.dispatchEvent(new Event('visibilitychange')); });
  await sleep(150);
  const during = await page.evaluate(() => window.__sockets.filter((s) => s.readyState === 1).length);
  await sleep(400);
  const paused = await page.evaluate(() => ({ open: window.__sockets.filter((s) => s.readyState === 1).length, codes: window.__sockets.map((s) => s.closedWith) }));
  ok(during === 2 && paused.open === 0 && paused.codes.every((c) => c === 1000), 'hidden tab: sockets stay through the grace period, then close cleanly (code 1000)');
  const n0 = await page.evaluate(() => window.__sockets.length);
  await sleep(1500);
  ok((await page.evaluate(() => window.__sockets.length)) === n0, 'and no reconnect is attempted while hidden');
  await page.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, get: () => false }); document.dispatchEvent(new Event('visibilitychange')); });
  await sleep(100);
  const back = await page.evaluate(() => window.__sockets.slice(-2).map((s) => ({ url: s.url, open: s.readyState === 1, sent: s.sent.length })));
  ok(back.length === 2 && back.every((s) => s.open && s.sent >= 1), 'visible again: both sockets reopen and resubscribe');
  await page.close();
}

/* ============================== 10. no WebSocket in the browser at all */
{
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.addInitScript(() => { delete window.WebSocket; window.WebSocket = undefined; });
  await page.goto(`${BASE}/v2.html`, { waitUntil: 'load' });
  await sleep(300);
  ok(errors.length === 0 && /^\$[0-9,]+\.\d\d$/.test(await heroPrice(page)), 'no WebSocket support: no error, the server price stands');
  await page.close();
}

await browser.close();
server.close();
console.log(failed ? `\n${failed} FAILED` : '\nAll live-price tests passed.');
process.exit(failed ? 1 : 0);
