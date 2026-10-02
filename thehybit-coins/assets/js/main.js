/**
 * TheHybit — Coin page entry point.
 *
 * The page is server-rendered HTML. This module does not build the page; it
 * only enhances it — draws charts, wires interaction, and (later) computes the
 * analytics score. If JS fails to load, the page still renders every number.
 *
 * Sections are initialised independently and defensively: a missing element or
 * a null data branch skips that section instead of throwing and taking the rest
 * of the page down with it.
 */

import { qs, qsa, on, el, clear, readDataIsland } from './dom.js';
import { sparkline } from './charts/sparkline.js';
import { createPriceChart } from './charts/price-chart.js';
import { gauge } from './charts/gauge.js';
import { derive, sections } from './derive.js';
import { score, insight } from './scoring.js';
import * as fmt from './format.js';

const STORE_WATCHLIST = 'thb-watchlist';

/** Prototype-only fallback source. Unused once the island is populated. */
const FIXTURE_URL = 'data/ethereum.json';

/**
 * Resolve the coin view model.
 *
 * Three sources, in order, all yielding the identical shape
 * (docs/data-contract.md) so nothing downstream branches on which one ran:
 *
 *   1. window.__THB_COIN__  WordPress. Printed server-side by
 *                           wp_add_inline_script from the same pipeline that
 *                           rendered the HTML. THE BROWSER NEVER CALLS A
 *                           PROVIDER — it only draws what PHP already fetched,
 *                           cached and normalized.
 *   2. a JSON data island   alternative server-side injection point.
 *   3. the fixture          prototype only; absent in production.
 */
async function loadCoinData() {
  if (typeof window !== 'undefined' && window.__THB_COIN__) {
    return window.__THB_COIN__;
  }

  const island = readDataIsland('thb-coin-data');
  if (island) return island;

  try {
    const res = await fetch(FIXTURE_URL, { cache: 'no-cache' });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return await res.json();
  } catch (err) {
    console.error('[thb] could not load coin data', err);
    return null;
  }
}

async function init() {
  const data = await loadCoinData();
  if (!data) {
    // The page is server-rendered, so every number is already on screen.
    // Only the enhancements are lost.
    console.warn('[thb] no coin data — interactive parts disabled');
    return;
  }

  initBrokenImages();
  initHeaderSparkline(data);
  initPriceChart(data);
  initTrends(data);
  initAnalytics(data);
  initServerScore();
  initWatchlist(data);
  initCopyButtons();
}

/* -------------------------------------------------------------------------
 * Score ring, server-rendered variant
 *
 * In WordPress the analytics section is computed in PHP and echoed, so it
 * carries no [data-thb-analytics] root and initAnalytics() correctly does
 * nothing. The ring is the one part of that section JS still has to draw, and
 * without this it was simply missing from the page. The value and tone come
 * from the markup rather than being recomputed, so the two can never disagree.
 * ---------------------------------------------------------------------- */
function initServerScore() {
  const host = qs('[data-thb-gauge-value]');
  if (!host) return; // prototype path — initAnalytics() already drew it

  const value = Number(host.getAttribute('data-thb-gauge-value'));
  if (!Number.isFinite(value)) return;

  const mount = qs('[data-thb-gauge]', host);
  if (!mount || mount.firstChild) return;

  const band = qs('[data-thb-score-band]', host.closest('.thb-analytics') ?? host);

  gauge(mount, value, {
    tone: host.getAttribute('data-thb-gauge-tone') || 'neutral',
    label: `امتیاز کلی ${value} از 100${band ? ` — ${band.textContent.trim()}` : ''}`,
  });
}

/* -------------------------------------------------------------------------
 * §10 TheHybit analytics
 *
 * The one section computed rather than served. Raw data → derive.js →
 * scoring.js → DOM, with nothing pre-baked in the fixture; edit a raw number
 * and the ring, the bands and the sentence all move.
 *
 * Rendered client-side only in the prototype. In WordPress the same two pure
 * modules run server-side and the result is echoed like every other section,
 * which is why they carry no DOM dependency.
 * ---------------------------------------------------------------------- */

/** Provider ids → display names. Joined with × to signal a combination. */
const PROVIDER_NAMES = {
  coingecko: 'CoinGecko',
  defillama: 'DefiLlama',
  dexscreener: 'DexScreener',
  dune: 'Dune',
  l2beat: 'L2BEAT',
};

function initAnalytics(data) {
  const root = qs('[data-thb-analytics]');
  if (!root) return;

  const derived = derive(data);
  const scored = score(derived);

  // Withheld rather than guessed when too few providers answered.
  if (!scored.available) {
    console.info('[thb] analytics withheld — provider coverage too low');
    return;
  }

  renderScore(root, scored);
  renderInsight(root, scored);
  renderDerivedMetrics(root, scored);

  // Only reveal once every part computed successfully.
  root.hidden = false;

  // Section visibility decisions are logged so an absent section is
  // explainable rather than mysterious during review.
  const vis = sections(data, derived);
  for (const [name, v] of Object.entries(vis)) {
    if (!v.visible) console.info(`[thb] «${name}» section hidden: ${v.reason}`);
  }
}

function renderScore(root, scored) {
  gauge(qs('[data-thb-gauge]', root), scored.value, {
    tone: scored.band.tone,
    label: `امتیاز کلی ${scored.value} از 100 — ${scored.band.label}`,
  });

  const value = qs('[data-thb-score]', root);
  if (value) value.textContent = fmt.num(scored.value, 0);

  const band = qs('[data-thb-score-band]', root);
  if (band) {
    band.textContent = scored.band.label;
    band.setAttribute('data-tone', scored.band.tone);
  }

  // Coverage is only worth surfacing when it is partial — a missing provider
  // is exactly when a reader deserves to know the score is incomplete.
  const coverage = qs('[data-thb-coverage]', root);
  if (coverage && scored.coverage < 1) {
    coverage.textContent = `بر پایه ${fmt.num(scored.coverage * 100, 0)}% از شاخص‌ها`;
  }
}

function renderInsight(root, scored) {
  const host = qs('[data-thb-insight]', root);
  if (!host) return;
  const text = insight(scored);
  if (text) host.textContent = text;
  else host.remove();
}

function renderDerivedMetrics(root, scored) {
  const host = qs('[data-thb-dmetrics]', root);
  if (!host) return;

  clear(host);

  for (const c of scored.components) {
    if (!c.available) continue; // no tile rather than a dash

    host.append(
      el('div', { class: 'thb-dmetric' }, [
        el('span', { class: 'thb-dmetric__label', text: c.label }),
        el('span', { class: 'thb-dmetric__value' }, [
          el('span', { class: 'thb-num', text: formatDerived(c) }),
        ]),
        el('span', {
          class: 'thb-dmetric__band',
          dataset: { tone: c.band.tone },
          text: c.band.label,
        }),
        el('span', {
          class: 'thb-dmetric__src',
          text: c.providers.map((p) => PROVIDER_NAMES[p] ?? p).join(' × '),
        }),
      ])
    );
  }
}

function formatDerived(c) {
  if (c.unit === 'ratio') return fmt.ratio(c.raw);
  return fmt.pct(c.raw, { sign: c.signed });
}

/* -------------------------------------------------------------------------
 * Missing images
 *
 * A featured image can 404 after a media item is deleted in WordPress. The
 * browser's default is a broken-image glyph and alt text sprawling across the
 * row; the row already has a correct no-thumbnail layout, so falling back to it
 * is better than showing the breakage.
 * ---------------------------------------------------------------------- */
function initBrokenImages() {
  for (const img of qsa('.thb-news__thumb, .thb-identity__logo')) {
    // `complete` with zero natural width means it already failed.
    if (img.complete && img.naturalWidth === 0) dropImage(img);
    else on(img, 'error', () => dropImage(img), { once: true });
  }
}

function dropImage(img) {
  if (img.classList.contains('thb-news__thumb')) img.remove();
  else img.style.visibility = 'hidden';
}

/* -------------------------------------------------------------------------
 * Price chart (§3)
 * ---------------------------------------------------------------------- */
function initPriceChart(data) {
  const host = qs('[data-thb-chart]');
  if (!host || !data.series) return;

  const chart = createPriceChart(host, {
    series: data.series,
    period: '24h',
    metric: 'price',
    tableHost: qs('[data-thb-chart-table]'),
    coinName: data.coin?.name ?? '',
  });

  /* Segmented controls. aria-pressed is the single source of truth for which
     option is active — CSS only reflects it, so the two cannot disagree. */
  const bindGroup = (attr, apply) => {
    const buttons = qsa(`[${attr}]`);
    for (const btn of buttons) {
      on(btn, 'click', () => {
        const value = btn.getAttribute(attr);
        apply(value);
        for (const b of buttons) {
          b.setAttribute('aria-pressed', String(b === btn));
        }
      });
    }
  };

  bindGroup('data-thb-period', (v) => chart.setPeriod(v));
  bindGroup('data-thb-metric', (v) => chart.setMetric(v));
}

/* -------------------------------------------------------------------------
 * Header sparkline
 * ---------------------------------------------------------------------- */
function initHeaderSparkline(data) {
  drawSpark('header', data.market?.sparkline, {
    label: `روند قیمت ${data.coin?.name ?? ''} در 24 ساعت گذشته`,
  });
}

/* -------------------------------------------------------------------------
 * Trend lines for the DeFi (§6) and on-chain (§7) cards
 *
 * Both sections are absent from the DOM when their provider has no data for
 * the coin, so each draw is guarded on the host as well as the values.
 * ---------------------------------------------------------------------- */
function initTrends(data) {
  drawSpark('defi', data.defi?.sparkline, {
    color: 'brand',
    label: 'روند ارزش کل قفل‌شده در 30 روز گذشته',
  });

  drawSpark('onchain', data.onchain?.sparkline, {
    color: 'brand',
    label: 'روند آدرس‌های فعال در 30 روز گذشته',
  });
}

function drawSpark(name, values, opts) {
  const host = qs(`[data-thb-sparkline="${name}"]`);
  if (!host || !Array.isArray(values)) return;
  sparkline(host, values, { area: true, ...opts });
}

/* -------------------------------------------------------------------------
 * Watchlist
 *
 * STUB: there is no account system in the prototype, so membership lives in
 * localStorage. The real implementation will POST to WordPress and reconcile
 * against the logged-in user. The markup and the aria-pressed contract stay
 * the same either way.
 * ---------------------------------------------------------------------- */
function initWatchlist(data) {
  const btn = qs('[data-thb-watchlist]');
  if (!btn) return;

  const id = data.coin?.id;
  if (!id) return;

  const read = () => {
    try {
      const raw = JSON.parse(localStorage.getItem(STORE_WATCHLIST) || '[]');
      return Array.isArray(raw) ? raw : [];
    } catch {
      return [];
    }
  };

  const paint = (isOn) => {
    btn.setAttribute('aria-pressed', String(isOn));
    const label = qs('[data-thb-watchlist-label]', btn);
    if (label) label.textContent = isOn ? 'در واچ‌لیست' : 'افزودن به واچ‌لیست';
  };

  paint(read().includes(id));

  on(btn, 'click', () => {
    const list = read();
    const idx = list.indexOf(id);
    if (idx === -1) list.push(id);
    else list.splice(idx, 1);

    try {
      localStorage.setItem(STORE_WATCHLIST, JSON.stringify(list));
    } catch {
      /* Private mode or storage full — the button still reflects the click. */
    }
    paint(idx === -1);
  });
}

/* -------------------------------------------------------------------------
 * Copy-to-clipboard (contract addresses)
 * ---------------------------------------------------------------------- */
function initCopyButtons() {
  for (const btn of qsa('[data-thb-copy]')) {
    on(btn, 'click', async () => {
      const value = btn.getAttribute('data-thb-copy');
      if (!value) return;

      try {
        await navigator.clipboard.writeText(value);
      } catch {
        return; // Clipboard blocked (insecure origin / permission) — stay silent.
      }

      // Transient confirmation via the accessible label, no layout shift.
      const prev = btn.getAttribute('aria-label');
      btn.setAttribute('aria-label', 'کپی شد');
      btn.dataset.copied = 'true';
      setTimeout(() => {
        btn.setAttribute('aria-label', prev || 'کپی نشانی قرارداد');
        delete btn.dataset.copied;
      }, 1600);
    });
  }
}

/* `defer` on the script tag means the DOM is already parsed, but guard anyway
   so the module is safe to import from anywhere. */
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
  init();
}
