/**
 * TheHybit Coins — live price over the visitor's own WebSocket (2.4.0).
 *
 * The browser connects straight to an exchange's PUBLIC feed: our server never
 * holds, relays or proxies a socket, and never makes a request for this. Price
 * and 24h change only; everything else on the page keeps the server's refresh.
 *
 *   USD   Kraken (ws.kraken.com/v2, ticker)       primary
 *         Nobitex USDT markets                    fallback, labelled «USDT»
 *   Toman Nobitex rial markets (÷ 10)
 *
 * What it promises:
 *   - The server-rendered figure stays until a REAL tick arrives. A coin no
 *     feed lists is never touched; no number is computed or guessed here.
 *   - One connection per exchange per page, whatever the number of coins.
 *   - Repaints at most once a second per coin; flashes green/red on a change,
 *     unless the visitor asked for reduced motion; the price box only grows,
 *     so the line never jumps.
 *   - A feed that falls silent marks its figure STALE (dimmed, with the time
 *     of the last tick) instead of freezing it silently, and hands the figure
 *     back to the page's one-a-minute polling (v2.js) as soon as that has a
 *     newer value.
 *   - Reconnects with jittered exponential backoff; a feed that never opened
 *     gives up after a few tries (blocked by region, say) — silently: the
 *     page simply works as it did before.
 *   - Sockets close when the tab has been hidden for a few seconds and reopen
 *     when it comes back.
 *
 * Config: window.__THB_LIVE__, written by includes/LiveFeed.php.
 * Kill switch: Settings → «قیمت زنده (WebSocket)» → خاموش (no script at all).
 */

/* ------------------------------------------------------------ formatting */

export const usd = (n) => {
  const abs = Math.abs(n);
  if (abs >= 1) return '$' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return '$' + n.toLocaleString('en-US', { maximumSignificantDigits: 4 });
};
export const pct = (n) => (n > 0 ? '+' : n < 0 ? '−' : '') + Math.abs(n).toFixed(2) + '%';
export const toman = (n) => Math.round(n).toLocaleString('en-US');
const num = (v) => (typeof v === 'number' ? v : typeof v === 'string' && v.trim() !== '' ? Number(v) : NaN);

/* --------------------------------------------------------------- parsing */

/** Kraken v2. One frame, one JSON message. */
export function parseKraken(raw) {
  let m;
  try { m = JSON.parse(raw); } catch { return [{ kind: 'junk' }]; }
  if (!m || typeof m !== 'object') return [{ kind: 'junk' }];
  if (m.channel === 'ticker' && Array.isArray(m.data)) {
    const out = [];
    for (const row of m.data) {
      const price = num(row?.last);
      if (typeof row?.symbol !== 'string' || !isFinite(price) || price <= 0) continue;
      const change = num(row.change_pct);
      out.push({ kind: 'tick', sym: row.symbol, price, change: isFinite(change) ? change : null });
    }
    return out.length ? out : [{ kind: 'alive' }];
  }
  if (m.method === 'subscribe' && m.success === false) {
    return [{ kind: 'reject', sym: m.symbol ?? m.result?.symbol ?? null }];
  }
  return [{ kind: 'alive' }];   // heartbeat, status, acks: the line is up
}

/** Nobitex (Centrifugo, JSON protocol). A frame may carry several
    newline-separated replies; `{}` is the server's ping; publication data
    may arrive as an object or as a JSON string. */
export function parseNobitex(raw) {
  const out = [];
  for (const line of String(raw).split('\n')) {
    if (line.trim() === '') continue;
    let m;
    try { m = JSON.parse(line); } catch { out.push({ kind: 'junk' }); continue; }
    if (!m || typeof m !== 'object') { out.push({ kind: 'junk' }); continue; }
    if (Object.keys(m).length === 0) { out.push({ kind: 'ping' }); continue; }
    if (m.id === 1 && m.connect) { out.push({ kind: 'connected' }); continue; }
    if (m.id && m.error) { out.push({ kind: 'reject', id: m.id }); continue; }
    const push = m.push;
    if (push && typeof push.channel === 'string' && push.pub) {
      const sym = push.channel.startsWith('public:market-stats-') ? push.channel.slice(20) : null;
      let d = push.pub.data;
      if (typeof d === 'string') { try { d = JSON.parse(d); } catch { d = null; } }
      const price = num(d?.latest);
      if (sym && d && d.isClosed !== true && isFinite(price) && price > 0) {
        const change = num(d.dayChange);
        out.push({ kind: 'tick', sym, price, change: isFinite(change) ? change : null });
        continue;
      }
    }
    if (push && push.disconnect) { out.push({ kind: 'drop' }); continue; }
    out.push({ kind: 'alive' });
  }
  return out;
}

/** Jittered exponential backoff: 1 s, 2 s, 4 s … capped at a minute. */
export const backoff = (attempt, rand = Math.random) =>
  Math.round(Math.min(60000, 1000 * 2 ** Math.max(0, attempt - 1)) * (0.5 + rand() * 0.5));

/* Wire protocol per exchange: what to send, how to read. */
const PROTOCOLS = {
  kraken: {
    parse: parseKraken,
    open(feed) { feed.send(JSON.stringify({ method: 'subscribe', params: { channel: 'ticker', symbol: feed.syms } })); feed.ready = true; },
    on(feed, ev) { if (ev.kind === 'reject' && ev.sym) feed.rejected.add(ev.sym); },
  },
  nobitex: {
    parse: parseNobitex,
    open(feed) { feed.send(JSON.stringify({ connect: {}, id: 1 })); },
    on(feed, ev) {
      if (ev.kind === 'ping') feed.send('{}');
      else if (ev.kind === 'connected') {
        feed.ready = true;
        feed.ids = {};
        feed.syms.forEach((s, i) => {
          feed.ids[i + 2] = s;
          feed.send(JSON.stringify({ subscribe: { channel: 'public:market-stats-' + s }, id: i + 2 }));
        });
      } else if (ev.kind === 'reject' && feed.ids?.[ev.id]) feed.rejected.add(feed.ids[ev.id]);
      else if (ev.kind === 'drop') feed.ws?.close();
    },
  },
};

/* ------------------------------------------------------------------ feed */

/** One socket to one exchange, carrying every symbol on the page. */
export class Feed {
  constructor(name, cfg, syms, env, onEvent) {
    Object.assign(this, { name, cfg, syms, env, onEvent });
    this.proto = PROTOCOLS[name];
    this.ws = null;
    this.ready = false;
    this.attempts = 0;
    this.opened = false;      // ever delivered a message on this page view
    this.gaveUp = false;
    this.paused = false;
    this.lastMsg = 0;
    this.rejected = new Set();
    this.retry = null;
    this.connectTimer = null;
  }

  /** Fresh = connected, subscribed, and heard from within `stale` ms. */
  fresh(now) {
    return !!this.ws && this.ready && this.ws.readyState === 1 && now - this.lastMsg < this.cfg.stale;
  }

  send(text) {
    try { if (this.ws && this.ws.readyState === 1) this.ws.send(text); } catch { /* the close handler follows */ }
  }

  start() {
    if (this.ws || this.gaveUp || this.paused || !this.proto) return;
    clearTimeout(this.retry);
    let ws;
    try { ws = new this.env.WebSocket(this.cfg.url); } catch { this.failed(); return; }
    this.ws = ws;
    this.ready = false;
    /* A socket stuck in CONNECTING (a black-holed host) counts as a failure. */
    this.connectTimer = this.env.setTimeout(() => { if (this.ws === ws && ws.readyState !== 1) { try { ws.close(); } catch {} this.closed(ws); } }, 10000);
    ws.onopen = () => { if (this.ws === ws) this.proto.open(this); };
    ws.onmessage = (e) => {
      if (this.ws !== ws) return;
      this.lastMsg = this.env.now();
      if (!this.opened || this.attempts) { this.opened = true; this.attempts = 0; }
      for (const ev of this.proto.parse(e.data)) {
        this.proto.on(this, ev);
        this.onEvent(this, ev);
      }
    };
    ws.onerror = () => {};
    ws.onclose = () => this.closed(ws);
  }

  closed(ws) {
    if (this.ws !== ws) return;
    this.env.clearTimeout(this.connectTimer);
    this.ws = null;
    this.ready = false;
    this.onEvent(this, { kind: 'down' });
    if (!this.paused) this.failed();
  }

  failed() {
    this.attempts++;
    /* Never got a single message through: blocked, refused or filtered.
       Five tries (about half a minute), then this page view stays on polling. */
    if (!this.opened && this.attempts >= 5) { this.gaveUp = true; return; }
    this.retry = this.env.setTimeout(() => this.start(), backoff(this.attempts));
  }

  pause() {
    this.paused = true;
    this.env.clearTimeout(this.retry);
    this.env.clearTimeout(this.connectTimer);
    const ws = this.ws;
    this.ws = null;
    this.ready = false;
    if (ws) { try { ws.close(1000); } catch {} }
    this.onEvent(this, { kind: 'down' });
  }

  resume() {
    if (!this.paused) return;
    this.paused = false;
    this.attempts = 0;
    this.start();
  }
}

/* ----------------------------------------------------------------- page */

/**
 * Wire every [data-live-id] element on the page to the feeds. Returns a
 * handle (used by the tests) or null when there is nothing to stream.
 */
export function boot(config, env = {}) {
  const w = typeof window !== 'undefined' ? window : {};
  env = {
    WebSocket: w.WebSocket,
    document: w.document,
    now: () => Date.now(),
    setTimeout: (f, ms) => setTimeout(f, ms),
    clearTimeout: (t) => clearTimeout(t),
    setInterval: (f, ms) => setInterval(f, ms),
    reducedMotion: !!w.matchMedia?.('(prefers-reduced-motion: reduce)').matches,
    ...env,
  };
  const doc = env.document;
  if (!config || !env.WebSocket || !doc || !config.feeds || !config.coins) return null;

  /* Coins on this page that a feed carries. */
  const coins = {};
  for (const root of doc.querySelectorAll('[data-live-id]')) {
    const id = root.getAttribute('data-live-id');
    const map = config.coins[id];
    if (!map) continue;
    const c = coins[id] ??= {
      id, map, roots: [], usd: {}, irt: null, lastPaint: 0, timer: null, shown: null,
    };
    c.roots.push(root);
  }

  /* Which symbol on which feed belongs to which coin and slot. */
  const index = {};
  const symsByFeed = {};
  const add = (feed, sym, entry) => {
    if (!config.feeds[feed]) return;
    (index[feed + '|' + sym] ??= []).push(entry);
    (symsByFeed[feed] ??= new Set()).add(sym);
  };
  for (const c of Object.values(coins)) {
    for (const cand of c.map.usd ?? []) add(cand.feed, cand.sym, { coin: c, slot: 'usd', quote: cand.quote });
    /* The toman line only exists on the coin page and only when the server had
       a rate for it; a live tick never makes a hidden line appear. */
    const irtEl = c.roots.map((r) => r.querySelector('[data-live-irt]')).find(Boolean);
    if (c.map.irt && irtEl && !irtEl.hidden) add(c.map.irt.feed, c.map.irt.sym, { coin: c, slot: 'irt' });
  }
  if (!Object.keys(symsByFeed).length) return null;

  /* Reserve the price's width so a longer number never shifts the line. */
  const reserve = (el) => {
    const wpx = el.getBoundingClientRect?.().width || 0;
    if (wpx > (parseFloat(el.style.minWidth) || 0)) el.style.minWidth = Math.ceil(wpx) + 'px';
  };
  for (const c of Object.values(coins)) c.roots.forEach((r) => r.querySelectorAll('[data-live-usd]').forEach(reserve));

  const feeds = {};
  const paintSoon = (c) => {
    if (c.timer) return;
    const wait = Math.max(0, c.lastPaint + (config.paint || 1000) - env.now());
    c.timer = env.setTimeout(() => { c.timer = null; paint(c); }, wait);
  };
  const onEvent = (feed, ev) => {
    if (ev.kind === 'tick') {
      for (const e of index[feed.name + '|' + ev.sym] ?? []) {
        const t = { price: ev.price, change: ev.change, at: env.now(), feed: feed.name, quote: e.quote };
        if (e.slot === 'usd') e.coin.usd[feed.name] = t; else e.coin.irt = t;
        paintSoon(e.coin);
      }
    } else if (ev.kind === 'down') {
      /* A value that was not pushed on THIS connection is not current. Kraken
         resends a snapshot on reconnect; Nobitex only pushes on change, so its
         figures wait for a real update. */
      for (const c of Object.values(coins)) {
        delete c.usd[feed.name];
        if (c.irt?.feed === feed.name) c.irt = null;
        paintSoon(c);
      }
    }
  };
  for (const [name, set] of Object.entries(symsByFeed)) {
    feeds[name] = new Feed(name, config.feeds[name], [...set], env, onEvent);
  }

  const hhmmss = (ms) => {
    try {
      return new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Tehran', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }).format(new Date(ms));
    } catch { return ''; }
  };

  const setText = (el, text) => {
    const n = el.querySelector('.v2-num') || el;
    if (n.textContent !== text) n.textContent = text;
  };

  const flash = (el, up) => {
    if (env.reducedMotion) return;
    el.classList.remove('is-tick-up', 'is-tick-down');
    void el.offsetWidth;
    el.classList.add(up ? 'is-tick-up' : 'is-tick-down');
    env.setTimeout(() => el.classList.remove('is-tick-up', 'is-tick-down'), 1200);
  };

  const markLive = (el, t) => {
    el.dataset.liveSrc = t.feed;
    el.dataset.liveAt = String(feeds[t.feed]?.lastMsg || t.at);
    el.classList.remove('is-stale');
    /* 2.5.0: no exchange is named on the public page; a USDT price still says so. */
    el.title = 'قیمت زنده' + (t.quote === 'USDT' ? ' — به تتر (USDT)' : '');
  };

  const markStale = (el) => {
    if (!el.dataset.liveAt || el.classList.contains('is-stale')) return false;
    delete el.dataset.liveSrc;
    el.classList.add('is-stale');
    el.title = 'اتصال زنده قطع شد · آخرین قیمت زنده ' + hhmmss(Number(el.dataset.liveAt));
    return true;
  };

  const stamp = (c, t) => {
    for (const root of c.roots) {
      const el = root.querySelector('[data-live-stamp]');
      if (!el) continue;
      const text = t
        ? 'قیمت زنده' + (t.quote === 'USDT' ? ' · به تتر (USDT)' : '')
        : 'اتصال زنده قطع شد · آخرین قیمت زنده ' + hhmmss(Number(root.querySelector('[data-live-usd]')?.dataset.liveAt || 0));
      if (el.dataset.liveText === text) continue;
      el.dataset.liveText = text;
      el.textContent = '';
      if (t) {
        const dot = doc.createElement('span');
        dot.className = 'v2-live-dot';
        dot.setAttribute('aria-hidden', 'true');
        el.append(dot);
      }
      el.append(text);
      el.classList.toggle('is-stale', !t);
    }
  };

  function paint(c) {
    c.lastPaint = env.now();
    const now = env.now();

    /* USD: the first candidate whose feed is fresh and has a tick. */
    let t = null;
    for (const cand of c.map.usd ?? []) {
      const f = feeds[cand.feed];
      const tick = c.usd[cand.feed];
      if (f && tick && f.fresh(now) && !f.rejected.has(cand.sym)) { t = tick; break; }
    }
    for (const root of c.roots) {
      for (const el of root.querySelectorAll('[data-live-usd]')) {
        if (t) {
          const prev = c.shown;
          setText(el, usd(t.price));
          markLive(el, t);
          reserve(el);
          if (prev != null && prev !== t.price) flash(el, t.price > prev);
        } else {
          markStale(el);
        }
      }
      for (const el of root.querySelectorAll('[data-live-chg]')) {
        if (t && t.change != null) {
          setText(el, pct(t.change));
          const dir = t.change > 0 ? 'up' : t.change < 0 ? 'down' : 'flat';
          el.classList.remove('v2-delta--up', 'v2-delta--down', 'v2-delta--flat');
          el.classList.add('v2-delta--' + dir);
          const arrow = el.querySelector('[data-v2-live-arrow]');
          if (arrow) arrow.textContent = dir === 'up' ? '▲' : dir === 'down' ? '▼' : '•';
        }
      }
    }
    if (t) c.shown = t.price;
    const hasStamp = c.roots.some((r) => r.querySelector('[data-live-usd]')?.dataset.liveAt);
    if (t) stamp(c, t); else if (hasStamp) stamp(c, null);

    /* Toman. */
    const irt = c.irt;
    const fi = irt && feeds[irt.feed];
    for (const root of c.roots) {
      const el = root.querySelector('[data-live-irt]');
      if (!el || el.hidden) continue;
      if (irt && fi.fresh(now) && !fi.rejected.has(c.map.irt.sym)) {
        setText(el, toman(irt.price / 10));   // rial → toman
        markLive(el, irt);
      } else {
        markStale(el);
      }
    }

    /* 2.10.0: the same real tick, for the price converter on this page —
       nothing new is fetched or computed here. */
    const irtLive = irt && fi && fi.fresh(now) && !fi.rejected.has(c.map.irt.sym) ? irt.price / 10 : null;
    if (t || irtLive != null) {
      try {
        document.dispatchEvent(new CustomEvent('thb:live', { detail: { id: c.id, usd: t ? t.price : null, toman: irtLive, at: now } }));
      } catch (e) { /* an old browser without CustomEvent simply keeps the cached price */ }
    }
  }

  /* Freshness watch: a feed that falls silent turns its figures stale. */
  const state = {};
  const watch = () => {
    const now = env.now();
    let changed = false;
    for (const f of Object.values(feeds)) {
      const fresh = f.fresh(now);
      if (state[f.name] !== fresh) { state[f.name] = fresh; changed = true; }
    }
    if (changed) Object.values(coins).forEach(paintSoon);
  };
  const watcher = env.setInterval(watch, 2000);

  /* Hidden tab: close the sockets after a grace period, reopen on return. */
  let hideTimer = null;
  const onVisibility = () => {
    if (doc.hidden) {
      env.clearTimeout(hideTimer);
      hideTimer = env.setTimeout(() => Object.values(feeds).forEach((f) => f.pause()), config.hidden ?? 10000);
    } else {
      env.clearTimeout(hideTimer);
      Object.values(feeds).forEach((f) => f.resume());
      watch();
    }
  };
  doc.addEventListener?.('visibilitychange', onVisibility);

  if (!doc.hidden) Object.values(feeds).forEach((f) => f.start());
  else onVisibility();

  return { feeds, coins, watch, paint, stop: () => { clearInterval(watcher); Object.values(feeds).forEach((f) => f.pause()); } };
}

if (typeof window !== 'undefined' && window.__THB_LIVE__ && !window.__THB_LIVE_MANUAL__) {
  try { window.__thbLive = boot(window.__THB_LIVE__); } catch { /* the page works without it */ }
}
