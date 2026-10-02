/**
 * TheHybit — Derived metrics.
 *
 * The boundary this module enforces:
 *
 *   data/*.json   RAW. What one provider reported. Nothing computed.
 *   derive.js     DERIVED. Ratios and growth, often COMBINING providers.
 *
 * That split is the whole point of the data layer. TVL comes from DefiLlama and
 * market cap from CoinGecko, so "TVL / market cap" belongs to neither of them —
 * it is our own metric, and it must be computed somewhere that records which
 * providers it depends on. Baking these into the fixture would hide the
 * dependency and make the numbers impossible to re-check.
 *
 * Every metric is declared in one registry with:
 *   - `requires`  the raw paths it reads, so a missing provider yields
 *                 `available: false` instead of NaN
 *   - `providers` which upstreams it touches, for provenance in the UI
 *   - `compute`   a pure function
 *
 * PURE BY DESIGN. No DOM, no fetch, no formatting. This is the module most
 * likely to move server-side (PHP or a cron job) once real providers land; the
 * JS here stays the reference implementation.
 */

/** Read a dotted path, returning undefined rather than throwing. */
function at(obj, path) {
  return path.split('.').reduce((o, k) => (o == null ? undefined : o[k]), obj);
}

const isNum = (v) => typeof v === 'number' && Number.isFinite(v);

/** Percentage change from `then` to `now`. */
const growth = (now, then) => ((now - then) / then) * 100;

/**
 * The derived-metric registry.
 *
 * Adding a provider later means adding entries here — not touching any markup.
 */
export const METRICS = {
  /* --- Cross-provider ratios ----------------------------------------- */

  tvlToMarketCap: {
    label: 'نسبت TVL به ارزش بازار',
    unit: 'ratio',
    providers: ['defillama', 'coingecko'],
    requires: ['defi.tvl', 'market.marketCap'],
    compute: (d) => d.defi.tvl / d.market.marketCap,
  },

  volumeToMarketCap: {
    label: 'نسبت حجم به ارزش بازار',
    unit: 'ratio',
    providers: ['coingecko'],
    requires: ['market.volume24h', 'market.marketCap'],
    compute: (d) => d.market.volume24h / d.market.marketCap,
  },

  dexShareOfVolume: {
    label: 'سهم معاملات غیرمتمرکز',
    unit: 'percent',
    providers: ['dexscreener', 'coingecko'],
    requires: ['dex.volume24h', 'market.volume24h'],
    compute: (d) => (d.dex.volume24h / d.market.volume24h) * 100,
  },

  feesToMarketCap: {
    label: 'نسبت کارمزد سالانه به ارزش بازار',
    unit: 'percent',
    providers: ['defillama', 'coingecko'],
    // Annualised from a 24h figure — a rough proxy, flagged as such in the UI.
    requires: ['defi.fees24h', 'market.marketCap'],
    compute: (d) => ((d.defi.fees24h * 365) / d.market.marketCap) * 100,
  },

  /* --- Growth, derived from raw history ------------------------------- */

  tvlGrowth30d: {
    signed: true,
    label: 'رشد TVL (30 روز)',
    unit: 'percent',
    providers: ['defillama'],
    requires: ['defi.tvl', 'defi.tvl30dAgo'],
    compute: (d) => growth(d.defi.tvl, d.defi.tvl30dAgo),
  },

  volumeGrowth30d: {
    signed: true,
    label: 'رشد حجم معاملات (30 روز)',
    unit: 'percent',
    providers: ['coingecko'],
    requires: ['market.volume24h', 'market.volume30dAvg'],
    compute: (d) => growth(d.market.volume24h, d.market.volume30dAvg),
  },

  activityGrowth30d: {
    signed: true,
    label: 'رشد فعالیت شبکه (30 روز)',
    unit: 'percent',
    providers: ['dune'],
    requires: ['onchain.activeAddresses24h', 'onchain.activeAddresses30dAgo'],
    compute: (d) =>
      growth(d.onchain.activeAddresses24h, d.onchain.activeAddresses30dAgo),
  },

  /* --------------------------------------------------------------------
   * Added in v2.0.0. Every one of these is computed from data the plugin
   * ALREADY fetches and caches — no new provider, no new request.
   *
   * None is referenced by SCORING_MODEL, so the headline score and its five
   * components are bit-for-bit unchanged. They exist to be displayed.
   * Mirrors the same block in Derive.php.
   * ----------------------------------------------------------------- */

  marketCapToFdv: {
    signed: false,
    label: 'نسبت ارزش بازار به ارزش رقیق‌شده',
    unit: 'ratio',
    providers: ['coingecko'],
    requires: ['market.marketCap', 'market.fdv'],
    compute: (d) => (d.market.fdv ? d.market.marketCap / d.market.fdv : null),
  },

  circulatingPct: {
    signed: false,
    label: 'درصد عرضه در گردش',
    unit: 'percent',
    providers: ['coingecko'],
    requires: ['market.circulatingSupply', 'market.totalSupply'],
    compute: (d) =>
      d.market.totalSupply
        ? (d.market.circulatingSupply / d.market.totalSupply) * 100
        : null,
  },

  drawdownFromAth: {
    signed: true,
    label: 'فاصله از بالاترین قیمت تاریخی',
    unit: 'percent',
    providers: ['coingecko'],
    requires: ['market.ath.changePct'],
    compute: (d) => d.market.ath.changePct,
  },

  relativeToBenchmark30d: {
    signed: true,
    label: 'عملکرد نسبی در برابر بیت‌کوین (30 روز)',
    unit: 'percent',
    providers: ['coingecko'],
    requires: ['performance.30d', 'benchmark.performance.30d'],
    compute: (d) => d.performance['30d'] - d.benchmark.performance['30d'],
  },

  revenueToMarketCap: {
    signed: false,
    label: 'نسبت درآمد سالانه به ارزش بازار',
    unit: 'percent',
    providers: ['defillama', 'coingecko'],
    requires: ['defi.revenue24h', 'market.marketCap'],
    compute: (d) =>
      d.market.marketCap
        ? ((d.defi.revenue24h * 365) / d.market.marketCap) * 100
        : null,
  },

  feesToTvl: {
    signed: false,
    label: 'نسبت کارمزد سالانه به ارزش قفل‌شده',
    unit: 'percent',
    providers: ['defillama'],
    requires: ['defi.fees24h', 'defi.tvl'],
    compute: (d) => (d.defi.tvl ? ((d.defi.fees24h * 365) / d.defi.tvl) * 100 : null),
  },

  /* These two read a PRICE SERIES rather than scalars, so `requires` is empty
     and compute() returns null when the window is not cached. */
  volatility7d: {
    signed: false,
    label: 'نوسان قیمت (7 روز)',
    unit: 'percent',
    providers: ['coingecko'],
    requires: [],
    compute: (d) => volatility(d, '7d'),
  },

  volatility30d: {
    signed: false,
    label: 'نوسان قیمت (30 روز)',
    unit: 'percent',
    providers: ['coingecko'],
    requires: [],
    compute: (d) => volatility(d, '30d'),
  },
};

/**
 * Annualised volatility, from a price series we already have cached.
 *
 * Standard deviation of log returns, scaled to a year by the square root of the
 * number of samples per year. The chart windows are sampled at CoinGecko's own
 * granularity, so the scaling factor is derived from the window length and the
 * sample count rather than assumed — which keeps the figure comparable across
 * windows.
 *
 * Returns null rather than 0 when the window is not cached: an uncomputed
 * volatility and a genuinely flat price are different statements.
 * Mirrors volatility() in Derive.php.
 */
function volatility(data, window) {
  const prices = data?.series?.[window]?.price;
  if (!Array.isArray(prices) || prices.length < 3) return null;

  const returns = [];
  for (let i = 1; i < prices.length; i++) {
    const prev = prices[i - 1];
    const curr = prices[i];
    if (prev > 0 && curr > 0) returns.push(Math.log(curr / prev));
  }
  if (returns.length < 2) return null;

  const mean = returns.reduce((a, b) => a + b, 0) / returns.length;
  const variance =
    returns.reduce((acc, r) => acc + (r - mean) ** 2, 0) / (returns.length - 1);
  const stdev = Math.sqrt(variance);

  const days = window === '7d' ? 7 : 30;
  const perYear = (returns.length / days) * 365;

  return stdev * Math.sqrt(perYear) * 100;
}

/**
 * Compute every derived metric that its inputs allow.
 *
 * @param {object} data  the normalized view model
 * @returns {Record<string, {value:number|null, available:boolean, label:string,
 *                           unit:string, providers:string[], missing:string[]}>}
 *
 * A metric whose inputs are absent comes back `available: false` with the
 * missing paths listed, rather than silently producing NaN. Callers render
 * only what is available — which is how a coin with no DeFi presence, or no
 * DEX listing, degrades cleanly instead of showing dashes everywhere.
 */
export function derive(data) {
  const out = {};

  for (const [key, def] of Object.entries(METRICS)) {
    const missing = def.requires.filter((p) => !isNum(at(data, p)));

    if (missing.length) {
      out[key] = { ...meta(def), value: null, available: false, missing };
      continue;
    }

    let value = null;
    try {
      value = def.compute(data);
    } catch {
      value = null;
    }

    out[key] = {
      ...meta(def),
      value: isNum(value) ? value : null,
      available: isNum(value),
      missing: [],
    };
  }

  return out;
}

function meta(def) {
  return {
    label: def.label,
    unit: def.unit,
    signed: def.signed === true,
    providers: def.providers,
  };
}

/* -------------------------------------------------------------------------
 * Optional-section visibility
 *
 * Whether to show the DEX and L2 sections is a DERIVED decision, so it lives
 * here with the metrics it depends on rather than being scattered through
 * markup. Each answer carries a `reason`, which makes the behaviour
 * inspectable instead of mysterious when a section does not appear.
 * ---------------------------------------------------------------------- */

/**
 * Thresholds for "meaningful", as opposed to merely present.
 *
 * A token with $12k of DEX volume technically has DEX data; showing a card for
 * it would be noise. These are editable in one place and deliberately
 * conservative.
 */
export const VISIBILITY_RULES = {
  dex: {
    minVolume24h: 1_000_000, // USD
    minSharePct: 0.5,        // share of total reported volume
  },
};

/**
 * Decide which optional sections should render.
 *
 * @returns {{dex: {visible:boolean, reason:string}, l2: {visible:boolean, reason:string}}}
 */
export function sections(data, derived = derive(data)) {
  return {
    dex: dexVisibility(data, derived),
    l2: l2Visibility(data),
    news: newsVisibility(data),
  };
}

/**
 * News comes from TheHybit's own WordPress site, filtered to this coin.
 *
 * A coin with no related articles gets NO card — not an empty one and not a
 * "no news yet" placeholder, which would be a worse thing to publish than
 * silence. New or obscure assets will legitimately have nothing written about
 * them, and that is the common case rather than an error.
 */
function newsVisibility(data) {
  const news = data?.news;
  if (!Array.isArray(news) || news.length === 0) {
    return no('هنوز مطلبی درباره این ارز در سایت منتشر نشده است');
  }
  return yes(`${news.length} مطلب مرتبط`);
}

function dexVisibility(data, derived) {
  const dex = data?.dex;
  if (!dex) return no('پیش از این داده‌ای از صرافی‌های غیرمتمرکز ثبت نشده است');

  const { minVolume24h, minSharePct } = VISIBILITY_RULES.dex;

  if (!isNum(dex.volume24h) || dex.volume24h < minVolume24h) {
    return no(`حجم معاملات غیرمتمرکز کمتر از آستانه است (${minVolume24h})`);
  }

  /* THE SHARE VETO NEEDS A COMPLETE MEASUREMENT, NOT A SAMPLE.
   *
   * `dex.sampled` means the provider truncated its pair list at a page limit,
   * so dex.volume24h is what the returned pairs happened to carry — not the
   * asset's DEX volume. Dividing that by CoinGecko's TOTAL market volume gives
   * a ratio whose numerator and denominator measure different things, and it
   * systematically understates.
   *
   * On the live Ethereum page that is precisely what hid a section carrying
   * $111.5M of real volume: 30 sampled pairs against a $25–40bn market came to
   * 0.28–0.45%, under the 0.5% floor. The absolute minVolume24h rule — which
   * that same figure clears a hundred times over — is what actually governs
   * whether the presence is meaningful.
   *
   * The threshold is unchanged and still applies whenever the measurement is
   * complete. It simply does not get to veto on an input it cannot support. */
  const share = derived.dexShareOfVolume;
  if (share.available && !dex.sampled && share.value < minSharePct) {
    return no(`سهم صرافی‌های غیرمتمرکز ناچیز است (${share.value.toFixed(2)}%)`);
  }

  return yes('حجم و سهم معنادار');
}

/**
 * L2 renders only when the data model explicitly supplies L2 data that is
 * SEMANTICALLY APPLICABLE to this asset.
 *
 * `scope` distinguishes the two things that would otherwise compete for the
 * same card with the same labels:
 *
 *   'chain'      this asset IS an L2 — its own TVS, stage and risk rows.
 *   'ecosystem'  this asset HOSTS L2s — aggregate TVS across them.
 *
 * Only 'chain' renders in v1. The ecosystem reading is still undefined, so
 * Ethereum shows nothing rather than something ambiguous. Widening this is a
 * one-line change once that distinction is agreed.
 */
function l2Visibility(data) {
  const l2 = data?.l2;
  if (!l2) return no('این دارایی داده اختصاصی لایه 2 ندارد');
  if (l2.scope !== 'chain') {
    return no(`دامنه داده لایه 2 («${l2.scope ?? 'نامشخص'}») هنوز تعریف نشده است`);
  }
  return yes('داده لایه 2 مختص همین شبکه است');
}

const yes = (reason) => ({ visible: true, reason });
const no = (reason) => ({ visible: false, reason });

/** Which upstreams a set of derived metrics actually touched. */
export function providersUsed(derived) {
  const set = new Set();
  for (const m of Object.values(derived)) {
    if (m.available) for (const p of m.providers) set.add(p);
  }
  return [...set];
}
