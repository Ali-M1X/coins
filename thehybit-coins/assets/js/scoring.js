/**
 * TheHybit — Analytical score.
 *
 * ⚠️  THE MODEL BELOW IS PROVISIONAL.
 *
 * Every weight, threshold and band cutoff in `SCORING_MODEL` is placeholder
 * calibration. They are NOT a methodology and must not be presented as one.
 * The real model gets defined after the data-source mapping (CoinGecko,
 * DefiLlama, on-chain, DEX) is complete.
 *
 * What IS settled, and what this file exists to prove:
 *
 *   - the pipeline is real — raw → derive.js → scoring.js → UI, with nothing
 *     pre-computed in the fixture;
 *   - all guesswork is confined to ONE exported object, so replacing the
 *     methodology means editing config, not logic;
 *   - the score degrades honestly when providers are missing, reporting how
 *     much of its own weight it could actually evaluate.
 *
 * PURE. No DOM, no fetch, no formatting — this is the other module destined to
 * move server-side, and the JS stays the reference implementation.
 */

/**
 * @provisional  Placeholder calibration pending data-source mapping.
 *
 * `scale` maps a raw metric onto 0–100 by linear interpolation between
 * breakpoints. Breakpoints are `[inputValue, score]` pairs, ascending.
 * They are round numbers chosen to look plausible for large-cap assets —
 * that is exactly the part that needs replacing with evidence.
 */
export const SCORING_MODEL = {
  version: 'provisional-0',
  provisional: true,

  components: {
    tvlToMarketCap: {
      weight: 25,
      scale: [[0, 0], [0.02, 25], [0.08, 55], [0.16, 75], [0.35, 100]],
      hint: 'سرمایه قفل‌شده نسبت به اندازه بازار',
    },
    volumeToMarketCap: {
      weight: 20,
      scale: [[0, 0], [0.01, 25], [0.03, 55], [0.06, 80], [0.15, 100]],
      hint: 'نقدشوندگی روزانه نسبت به اندازه بازار',
    },
    tvlGrowth30d: {
      weight: 20,
      scale: [[-30, 0], [-5, 30], [5, 50], [20, 80], [50, 100]],
      hint: 'تغییر سرمایه قفل‌شده در 30 روز',
    },
    activityGrowth30d: {
      weight: 20,
      scale: [[-30, 0], [-5, 30], [5, 50], [20, 80], [50, 100]],
      hint: 'تغییر آدرس‌های فعال در 30 روز',
    },
    dexShareOfVolume: {
      weight: 15,
      scale: [[0, 0], [2, 30], [8, 55], [20, 80], [45, 100]],
      hint: 'سهم معاملات غیرمتمرکز از کل حجم',
    },
  },

  bands: [
    { min: 80, label: 'عالی', tone: 'up' },
    { min: 65, label: 'خوب', tone: 'up' },
    { min: 45, label: 'متوسط', tone: 'warn' },
    { min: 0, label: 'ضعیف', tone: 'down' },
  ],

  /**
   * Below this share of total weight the score is withheld rather than shown
   * with a misleading number — too few providers answered to say anything.
   */
  minCoverage: 0.5,
};

/** Linear interpolation across the breakpoint table, clamped at both ends. */
function applyScale(value, scale) {
  if (value <= scale[0][0]) return scale[0][1];
  if (value >= scale.at(-1)[0]) return scale.at(-1)[1];

  for (let i = 1; i < scale.length; i++) {
    const [x0, y0] = scale[i - 1];
    const [x1, y1] = scale[i];
    if (value <= x1) return y0 + ((value - x0) / (x1 - x0)) * (y1 - y0);
  }
  return scale.at(-1)[1];
}

export function bandFor(score, model = SCORING_MODEL) {
  return model.bands.find((b) => score >= b.min) ?? model.bands.at(-1);
}

/**
 * Score a coin from its DERIVED metrics.
 *
 * @param {object} derived  output of derive()
 * @param {object} [model]
 * @returns {{
 *   available: boolean, value: number|null, band: object|null,
 *   coverage: number, components: Array, provisional: boolean, version: string
 * }}
 *
 * Missing providers do not zero a component — that would punish a coin for our
 * lack of data. The component is dropped and the remaining weights are
 * renormalised, with `coverage` reporting how much of the model actually ran.
 */
export function score(derived, model = SCORING_MODEL) {
  const components = [];
  let weightAvailable = 0;
  let weightTotal = 0;
  let acc = 0;

  for (const [key, cfg] of Object.entries(model.components)) {
    weightTotal += cfg.weight;

    const metric = derived?.[key];
    if (!metric?.available) {
      components.push({
        key, weight: cfg.weight, hint: cfg.hint,
        available: false, raw: null, normalized: null,
        label: metric?.label ?? key,
        providers: metric?.providers ?? [],
      });
      continue;
    }

    const normalized = applyScale(metric.value, cfg.scale);
    weightAvailable += cfg.weight;
    acc += normalized * cfg.weight;

    components.push({
      key, weight: cfg.weight, hint: cfg.hint,
      available: true,
      raw: metric.value,
      normalized,
      unit: metric.unit,
      signed: metric.signed,
      label: metric.label,
      providers: metric.providers,
      band: bandFor(normalized, model),
    });
  }

  const coverage = weightTotal ? weightAvailable / weightTotal : 0;

  if (coverage < model.minCoverage || weightAvailable === 0) {
    return {
      available: false, value: null, band: null, coverage,
      components, provisional: model.provisional, version: model.version,
    };
  }

  const value = Math.round(acc / weightAvailable);

  return {
    available: true,
    value,
    band: bandFor(value, model),
    coverage,
    components,
    provisional: model.provisional,
    version: model.version,
  };
}

/* -------------------------------------------------------------------------
 * Insight
 * ---------------------------------------------------------------------- */

/**
 * One deterministic sentence describing the strongest and weakest signals.
 *
 * Templates, not generation: no model call, no dependency, and the same input
 * always yields the same sentence. Also provisional — it describes whatever
 * the placeholder model happened to rank highest.
 */
export function insight(scored) {
  const usable = scored.components.filter((c) => c.available);
  if (usable.length < 2) return null;

  const sorted = [...usable].sort((a, b) => b.normalized - a.normalized);
  const best = sorted[0];
  const worst = sorted.at(-1);

  const strong = STRENGTH[best.key];
  const weak = WEAKNESS[worst.key];
  if (!strong || !weak) return null;

  // When everything scores similarly there is no contrast worth drawing.
  if (best.normalized - worst.normalized < 12) {
    return `شاخص‌های ${scored.band.label === 'ضعیف' ? 'این دارایی' : 'اتریوم'} در این دوره تصویری متعادل نشان می‌دهند و هیچ سیگنال غالبی دیده نمی‌شود.`;
  }

  return `${strong} در مقابل، ${weak}`;
}

const STRENGTH = {
  tvlToMarketCap: 'نسبت سرمایه قفل‌شده به ارزش بازار در سطح قابل‌توجهی است، که نشان می‌دهد بخش معناداری از ارزش شبکه واقعاً در پروتکل‌ها به کار گرفته شده است.',
  volumeToMarketCap: 'نقدشوندگی روزانه نسبت به اندازه بازار بالاست و ورود و خروج سرمایه به‌سادگی انجام می‌شود.',
  tvlGrowth30d: 'رشد سرمایه قفل‌شده در یک ماه گذشته قوی بوده و سرمایه تازه وارد اکوسیستم شده است.',
  activityGrowth30d: 'فعالیت کاربران شبکه در یک ماه گذشته رشد محسوسی داشته است.',
  dexShareOfVolume: 'سهم بالای معاملات غیرمتمرکز نشان‌دهنده عمق نقدشوندگی خارج از صرافی‌های متمرکز است.',
};

const WEAKNESS = {
  tvlToMarketCap: 'سرمایه قفل‌شده نسبت به ارزش بازار کم است و بخش بزرگی از ارزش‌گذاری به کاربرد مستقیم شبکه متکی نیست.',
  volumeToMarketCap: 'حجم معاملات نسبت به اندازه بازار پایین است و نقدشوندگی محدودتر به نظر می‌رسد.',
  tvlGrowth30d: 'رشد سرمایه قفل‌شده در یک ماه گذشته کند بوده است.',
  activityGrowth30d: 'رشد فعالیت کاربران در یک ماه گذشته ضعیف بوده است.',
  dexShareOfVolume: 'سهم معاملات غیرمتمرکز پایین است و نقدشوندگی عمدتاً به صرافی‌های متمرکز وابسته است.',
};
