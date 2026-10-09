/**
 * TheHybit Coins — design v2 interactions.
 *
 * Progressive enhancement only. Every figure and every chart is already in
 * the server-rendered HTML; this module redraws on interaction from
 * window.__THB_V2__ (written by PHP) and NEVER calls a provider. With
 * JavaScript off, the page is complete — the controls simply do nothing.
 */

const data = window.__THB_V2__ || {};
const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

const usd = (n) => {
  if (n == null || !isFinite(n)) return '—';
  const abs = Math.abs(n);
  if (abs >= 1) return '$' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return '$' + n.toLocaleString('en-US', { maximumSignificantDigits: 4 });
};
const usdCompact = (n) => {
  if (n == null || !isFinite(n)) return '—';
  const steps = [[1e12, 'T'], [1e9, 'B'], [1e6, 'M'], [1e3, 'K']];
  for (const [size, suffix] of steps) {
    if (Math.abs(n) >= size) return '$' + (n / size).toFixed(2) + suffix;
  }
  return '$' + n.toFixed(0);
};
const pct = (n, digits = 1) => {
  if (n == null || !isFinite(n)) return '—';
  const sign = n > 0 ? '+' : n < 0 ? '−' : '';
  return sign + Math.abs(n).toFixed(digits) + '%';
};

function setPressed(group, active) {
  group.forEach((b) => {
    const on = b === active;
    b.classList.toggle('is-on', on);
    b.setAttribute('aria-pressed', on ? 'true' : 'false');
  });
}

/* ------------------------------------------------------------------ chart */

function linePath(values, w, h, pad) {
  const v = values.filter((x) => typeof x === 'number' && isFinite(x));
  if (v.length < 2) return { line: '', area: '', min: null, max: null };
  const lo = Math.min(...v);
  const hi = Math.max(...v);
  const range = hi - lo;
  const pts = v.map((y, i) => {
    const x = pad + (w - 2 * pad) * (i / (v.length - 1));
    const py = range > 0 ? pad + (h - 2 * pad) * (1 - (y - lo) / range) : h / 2;
    return [x.toFixed(1), py.toFixed(1)];
  });
  const line = pts.map(([x, y], i) => (i ? 'L' : 'M') + x + ' ' + y).join(' ');
  const area = `${line} L${pts[pts.length - 1][0]} ${h} L${pts[0][0]} ${h} Z`;
  return { line, area, min: lo, max: hi };
}

function initChart() {
  const card = $('[data-v2-chart]');
  if (!card || !data.chart) return;
  const metricBtns = $$('[data-v2-metric]', card);
  const rangeBtns = $$('[data-v2-range]', card);
  const line = $('[data-v2-line]', card);
  const area = $('[data-v2-area]', card);
  const axis = $$('.v2-chart__axis .v2-num', card);
  const svg = $('svg.v2-chart', card);
  let metric = 'p';
  let range = (rangeBtns.find((b) => b.classList.contains('is-on')) || rangeBtns[0] || {}).dataset?.v2Range;

  const draw = () => {
    const series = data.chart[range];
    if (!series || !line) return;
    const d = linePath(series[metric] || [], 600, 220, 6);
    if (!d.line) return;
    line.setAttribute('d', d.line);
    area.setAttribute('d', d.area);
    const fmt = metric === 'p' ? usd : usdCompact;
    if (axis[0]) axis[0].textContent = fmt(d.min);
    if (axis[1]) axis[1].textContent = fmt(d.max);
    const names = { p: 'قیمت', v: 'حجم معاملات', m: 'ارزش بازار' };
    const label = rangeBtns.find((b) => b.dataset.v2Range === range)?.textContent || '';
    svg?.setAttribute('aria-label', `نمودار ${names[metric]} در ${label} گذشته`);
  };

  metricBtns.forEach((b) => b.addEventListener('click', () => { metric = b.dataset.v2Metric; setPressed(metricBtns, b); draw(); }));
  rangeBtns.forEach((b) => b.addEventListener('click', () => { range = b.dataset.v2Range; setPressed(rangeBtns, b); draw(); }));
}

/* -------------------------------------------------------------- watchlist */

function initWatchlist() {
  const btn = $('[data-v2-watch]');
  if (!btn) return;
  const key = 'thb-watchlist';
  const slug = btn.dataset.v2Watch;
  const read = () => { try { return JSON.parse(localStorage.getItem(key) || '[]'); } catch { return []; } };
  const write = (list) => { try { localStorage.setItem(key, JSON.stringify(list)); } catch { /* private mode */ } };
  const label = $('[data-v2-watch-label]', btn);
  const paint = () => {
    const on = read().includes(slug);
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    if (label) label.textContent = on ? 'در واچ‌لیست' : 'واچ‌لیست';
  };
  btn.addEventListener('click', () => {
    const list = read();
    write(list.includes(slug) ? list.filter((s) => s !== slug) : [...list, slug]);
    paint();
  });
  paint();
}

/* -------------------------------------------------------------- ecosystem */

function initEcosystem() {
  const filters = $$('[data-v2-eco-filter]');
  const nodes = $$('[data-v2-node]');
  const rows = $$('[data-v2-eco-list] tbody tr');
  const map = $('[data-v2-eco-map]');
  const list = $('[data-v2-eco-list]');
  const viewBtn = $('[data-v2-eco-view]');
  const detail = $('[data-v2-detail]');
  const eco = data.ecosystem || [];

  filters.forEach((b) => b.addEventListener('click', () => {
    setPressed(filters, b);
    const g = b.dataset.v2EcoFilter;
    nodes.forEach((n) => n.classList.toggle('is-dim', g !== 'all' && n.dataset.group !== g));
    $$('.v2-eco__edge[data-group]').forEach((e) => e.style.opacity = g === 'all' || e.dataset.group === g ? '' : '.08');
    rows.forEach((r) => { r.hidden = g !== 'all' && r.dataset.group !== g; });
  }));

  const setView = (toList) => {
    viewBtn?.setAttribute('aria-pressed', toList ? 'true' : 'false');
    if (map) map.hidden = toList;
    if (list) list.hidden = !toList;
  };
  viewBtn?.addEventListener('click', () => setView(viewBtn.getAttribute('aria-pressed') !== 'true'));
  // On a phone the list reads better than a ten-node map; the map is a tap away.
  if (window.matchMedia?.('(max-width: 599px)').matches) setView(true);

  const show = (i) => {
    const p = eco[i];
    if (!p || !detail) return;
    nodes.forEach((n) => n.classList.toggle('is-on', Number(n.dataset.v2Node) === i));
    const set = (k, v) => { const el = $(`[data-v2-d="${k}"]`, detail); if (el) el.textContent = v; };
    set('name', p.name);
    set('categoryFa', p.categoryFa);
    set('fees', usdCompact(p.fees));
    set('share', p.share == null ? '—' : p.share.toFixed(1) + '%');
    set('change', pct(p.change));
  };
  nodes.forEach((n) => {
    const i = Number(n.dataset.v2Node);
    n.addEventListener('click', () => show(i));
    n.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); show(i); } });
  });
}

/* ---------------------------------------------------------- price as story */

function initPriceStory() {
  const toggles = $$('[data-v2-layer]');
  toggles.forEach((t) => t.addEventListener('click', () => {
    const on = t.getAttribute('aria-pressed') !== 'true';
    t.setAttribute('aria-pressed', on ? 'true' : 'false');
    t.classList.toggle('is-on', on);
    // toggleAttribute, not .hidden: SVG elements have no `hidden` property, so
    // assigning it sets an inert expando and the layer never appears.
    $$(`[data-v2-layer-el="${t.dataset.v2Layer}"]`).forEach((el) => el.toggleAttribute('hidden', !on));
  }));

  const card = $('[data-v2-eventcard]');
  const events = data.story?.events || [];
  const pins = $$('[data-v2-event]');
  const show = (k) => {
    const e = events[k];
    if (!e || !card) return;
    pins.forEach((p) => p.classList.toggle('is-on', Number(p.dataset.v2Event) === k));
    const set = (key, v) => { const el = $(`[data-v2-e="${key}"]`, card); if (el) el.textContent = v; };
    set('title', e.title);
    set('date', e.date);
    set('text', e.text);
    set('window', e.window);
    set('price', e.price);
    const chg = $('[data-v2-e="change"]', card);
    if (chg) {
      chg.textContent = e.change || '—';
      chg.className = `v2-event__chg v2-delta--${e.dir}`;
    }
  };
  pins.forEach((p) => {
    const k = Number(p.dataset.v2Event);
    p.addEventListener('click', () => show(k));
    p.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); show(k); } });
  });
}

/* ------------------------------------------------------------------ peers */

function initPeers() {
  const select = $('[data-v2-axis]');
  const peers = data.peers || [];
  const label = $('[data-v2-xlabel]');
  if (!select) return;
  select.addEventListener('change', () => {
    const useTvl = select.value === 'tvl';
    $$('[data-v2-peer]').forEach((g) => {
      const p = peers[Number(g.dataset.v2Peer)];
      if (!p) return;
      const x = useTvl ? p.xT : p.xV;
      g.toggleAttribute('hidden', x == null);
      if (x != null) g.setAttribute('transform', `translate(${x} ${p.y})`);
    });
    if (label) label.textContent = useTvl ? 'ارزش قفل‌شده (مقیاس لگاریتمی)' : 'حجم ۲۴ ساعته (مقیاس لگاریتمی)';
  });
}

/* ------------------------------------------------------------ section nav */

function initTabs() {
  const links = $$('[data-v2-tab]');
  if (!links.length || !('IntersectionObserver' in window)) return;
  const byId = new Map(links.map((l) => [l.dataset.v2Tab, l]));
  const io = new IntersectionObserver((entries) => {
    entries.forEach((en) => {
      if (!en.isIntersecting) return;
      const link = byId.get(en.target.id);
      if (!link) return;
      links.forEach((l) => l.classList.toggle('is-on', l === link));
    });
  }, { rootMargin: '-45% 0px -50% 0px' });
  byId.forEach((_, id) => { const s = document.getElementById(id); if (s) io.observe(s); });
}

/* ------------------------------------------------------------- timeline */

/* Bring the featured event into view inside the timeline's own scroller.
   scrollBy with a measured delta works under both RTL scrollLeft
   conventions, and never scrolls the page itself. */
function initTimeline() {
  const box = $('.v2-timeline');
  const item = $('.v2-timeline__item.is-featured');
  if (!box || !item || box.scrollWidth <= box.clientWidth) return;
  const b = box.getBoundingClientRect();
  const i = item.getBoundingClientRect();
  box.scrollBy({ left: (i.left + i.width / 2) - (b.left + b.width / 2) });
}

/* On a phone, wide charts scroll inside their card. Graphs organised around
   a centre (ecosystem, fee flow, peers) open on their centre; the price
   history keeps its RTL start, which is the newest end of the time axis. */
function initCentred() {
  $$('[data-v2-center]').forEach((box) => {
    const over = box.scrollWidth - box.clientWidth;
    if (over <= 0) return;
    const b = box.getBoundingClientRect();
    const inner = box.firstElementChild.getBoundingClientRect();
    box.scrollBy({ left: (inner.left + inner.width / 2) - (b.left + b.width / 2) });
  });
}

/* ------------------------------------------------------------ live price */

/* The price and 24h change, refreshed in place.
 *
 * Polls OUR server (/wp-json/thehybit/v1/price/{slug}), which only reads the
 * cache — never CoinGecko. The server refreshes that cache once a minute, so
 * polling faster would fetch the same number again. Instead each response
 * says when the next refresh is due, and the next poll is timed a few seconds
 * after it: one request per real change, about one a minute.
 *
 * Paused while the tab is hidden; on return, it polls at once if a refresh
 * was missed. Errors back off (1, 2, 4… up to 5 minutes) rather than retry. */
function initLivePrice() {
  const live = data.live;
  const priceEl = $('[data-v2-price] .v2-num');
  if (!live?.url || !priceEl) return;
  const changeEl = $('[data-v2-live-change]');
  const tomanEl = $('[data-v2-live-toman]');
  const stampEl = $('[data-v2-live-stamp]');
  const MIN = 15000;
  const FALLBACK = Math.max(60, Number(live.interval) || 60) * 1000;
  let timer = null;
  let failures = 0;
  let nextAt = Date.parse(live.nextRefreshAt || '') || Date.now() + FALLBACK;

  const tehran = (iso) => {
    try {
      return new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Tehran', hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date(iso));
    } catch { return ''; }
  };

  const render = (d) => {
    const old = parseFloat(priceEl.textContent.replace(/[^0-9.]/g, ''));
    priceEl.textContent = usd(d.price);
    const host = priceEl.parentElement;
    if (isFinite(old) && old !== d.price) {
      host.classList.remove('is-tick-up', 'is-tick-down');
      host.classList.add(d.price > old ? 'is-tick-up' : 'is-tick-down');
      setTimeout(() => host.classList.remove('is-tick-up', 'is-tick-down'), 1500);
    }
    if (changeEl && d.change24h != null) {
      const dir = d.change24h > 0 ? 'up' : d.change24h < 0 ? 'down' : 'flat';
      changeEl.className = `v2-delta v2-delta--${dir}`;
      const num = $('.v2-num', changeEl);
      if (num) num.textContent = (d.change24h > 0 ? '+' : d.change24h < 0 ? '−' : '') + Math.abs(d.change24h).toFixed(2) + '%';
      const arrow = $('[data-v2-live-arrow]', changeEl);
      if (arrow) arrow.textContent = dir === 'up' ? '▲' : dir === 'down' ? '▼' : '•';
    }
    if (tomanEl && d.toman != null) {
      const num = $('.v2-num', tomanEl);
      if (num) num.textContent = Math.round(d.toman).toLocaleString('en-US');
      tomanEl.hidden = false;
    }
    if (stampEl && d.fetchedAt) {
      const num = $('.v2-num', stampEl);
      if (num) num.textContent = tehran(d.fetchedAt);
    }
  };

  const schedule = () => {
    clearTimeout(timer);
    if (document.hidden) return;
    const wait = failures
      ? Math.min(300000, FALLBACK * 2 ** (failures - 1))
      : Math.max(MIN, nextAt - Date.now() + 5000);   // a few seconds after the server's refresh
    timer = setTimeout(poll, wait);
  };

  const poll = async () => {
    try {
      const res = await fetch(live.url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      if (!res.ok) throw new Error(String(res.status));
      const d = await res.json();
      if (typeof d.price !== 'number') throw new Error('no price');
      render(d);
      failures = 0;
      nextAt = Date.parse(d.nextRefreshAt || '') || Date.now() + FALLBACK;
    } catch {
      failures++;
    }
    schedule();
  };

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) { clearTimeout(timer); return; }
    if (Date.now() >= nextAt) poll(); else schedule();
  });
  schedule();
}

initChart();
initLivePrice();
initTimeline();
initCentred();
initWatchlist();
initEcosystem();
initPriceStory();
initPeers();
initTabs();
