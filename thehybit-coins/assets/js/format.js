/**
 * TheHybit — Number, currency and date formatting.
 *
 * Every user-visible number on the coin page goes through this module. That is
 * deliberate: it makes the Latin-vs-Persian digit decision a ONE-LINE change
 * (see DIGIT_SYSTEM below) instead of a find-and-replace across the markup.
 *
 * Note on Intl: `Intl.NumberFormat('fa-IR')` emits Persian digits (۱۲۳) by
 * default. Because v1 uses Latin digits we must force the numbering system
 * explicitly with the `-u-nu-latn` extension — forgetting this is the usual
 * way Persian pages end up with inconsistent digits.
 *
 * No dependencies. Jalali dates come from built-in Intl, not a library.
 */

/**
 * The digit system for the whole page.
 * 'latn' → 3,245.67   |   'arabext' → ۳٬۲۴۵٫۶۷
 * @type {'latn' | 'arabext'}
 */
export const DIGIT_SYSTEM = 'latn';

/** Locale used for grouping/decimal separators. */
const LOCALE = `fa-IR-u-nu-${DIGIT_SYSTEM}`;

/** Locale for Jalali (Shamsi) dates. */
const DATE_LOCALE = `fa-IR-u-ca-persian-nu-${DIGIT_SYSTEM}`;

/* -------------------------------------------------------------------------
 * Internals
 * ---------------------------------------------------------------------- */

const nf = (min, max) =>
  new Intl.NumberFormat(LOCALE, {
    minimumFractionDigits: min,
    maximumFractionDigits: max,
  });

const isNum = (v) => typeof v === 'number' && Number.isFinite(v);

/** Placeholder for missing data. Never render "NaN" or "undefined". */
const EMPTY = '—';

/* -------------------------------------------------------------------------
 * Numbers
 * ---------------------------------------------------------------------- */

/**
 * Plain number with fixed decimals.
 * @param {number} n
 * @param {number} [decimals=2]
 */
export function num(n, decimals = 2) {
  if (!isNum(n)) return EMPTY;
  return nf(decimals, decimals).format(n);
}

/**
 * Price in USD with precision that adapts to magnitude.
 *
 * Crypto spans ~12 orders of magnitude, so a fixed 2 decimals is wrong at both
 * ends: it renders a meme coin as "$0.00" and clutters a large cap with noise.
 *
 * @param {number} n
 * @returns {string} e.g. "$3,245.67", "$0.00004182"
 */
export function usd(n) {
  if (!isNum(n)) return EMPTY;

  const abs = Math.abs(n);

  // At/above $1 we pin exactly 2 decimals so columns stay aligned.
  if (abs >= 1) return '$' + nf(2, 2).format(n);

  // Below $1 we allow more precision but let trailing zeros drop, so 0.42
  // renders as "$0.42" rather than "$0.4200".
  let max;
  if (abs >= 0.01) max = 4;
  else if (abs >= 0.0001) max = 6;
  else if (abs > 0) max = 8;
  else max = 2;

  return '$' + nf(2, max).format(n);
}

const COMPACT_STEPS = [
  { v: 1e12, s: 'T' },
  { v: 1e9,  s: 'B' },
  { v: 1e6,  s: 'M' },
  { v: 1e3,  s: 'K' },
];

/**
 * Compact number: 389_210_000_000 → "389.21B".
 * Latin suffixes match the reference design and are standard on Persian
 * crypto sites.
 *
 * @param {number} n
 * @param {number} [decimals=2]
 */
export function compact(n, decimals = 2) {
  if (!isNum(n)) return EMPTY;

  const abs = Math.abs(n);
  const step = COMPACT_STEPS.find((s) => abs >= s.v);
  if (!step) return nf(0, decimals).format(n);

  return nf(decimals, decimals).format(n / step.v) + step.s;
}

/** Compact USD: "$389.21B". */
export function usdCompact(n, decimals = 2) {
  if (!isNum(n)) return EMPTY;
  return '$' + compact(n, decimals);
}

/**
 * Compact token amount with its symbol: "120.23M ETH".
 * @param {number} n
 * @param {string} symbol
 */
export function tokenAmount(n, symbol) {
  if (!isNum(n)) return EMPTY;
  return compact(n) + (symbol ? ' ' + symbol : '');
}

/* -------------------------------------------------------------------------
 * Percentages and direction
 * ---------------------------------------------------------------------- */

/**
 * Percentage with an explicit sign: "+2.45%", "-33.67%".
 * @param {number} n           already a percentage (2.45 means 2.45%)
 * @param {object} [opts]
 * @param {boolean} [opts.sign=true]
 * @param {number}  [opts.decimals=2]
 */
export function pct(n, { sign = true, decimals = 2 } = {}) {
  if (!isNum(n)) return EMPTY;
  const body = nf(decimals, decimals).format(Math.abs(n)) + '%';
  if (!sign) return body;
  const mark = n > 0 ? '+' : n < 0 ? '−' : '';
  return mark + body;
}

/**
 * Direction of a change: 'up' | 'down' | 'flat'.
 * Drives both the colour token and the arrow glyph.
 */
export function direction(n) {
  if (!isNum(n) || n === 0) return 'flat';
  return n > 0 ? 'up' : 'down';
}

/**
 * Arrow glyph for a change.
 *
 * Accessibility: colour alone must never carry the up/down meaning, so every
 * change value pairs its colour with one of these glyphs.
 */
export function arrow(n) {
  const d = direction(n);
  return d === 'up' ? '▲' : d === 'down' ? '▼' : '•';
}

/* -------------------------------------------------------------------------
 * Local currency
 * ---------------------------------------------------------------------- */

/**
 * Toman equivalent, as shown under the USD price.
 *
 * Returns the NUMERAL ONLY — the "تومان" unit belongs in the markup as a
 * separate element. Keeping them apart matters for bidi: the numeral is an
 * LTR run that must be isolated, while the unit is Persian text that must
 * stay in the RTL flow. Baking them into one string forces one direction on
 * both and reorders the result.
 *
 * Toman amounts are large and the sub-unit is meaningless, so this always
 * rounds to whole toman.
 *
 * @param {number} usdValue
 * @param {number} usdToToman  toman per 1 USD
 */
export function toman(usdValue, usdToToman) {
  if (!isNum(usdValue) || !isNum(usdToToman)) return EMPTY;
  return nf(0, 0).format(Math.round(usdValue * usdToToman));
}

/* -------------------------------------------------------------------------
 * Dates (Jalali / Shamsi)
 * ---------------------------------------------------------------------- */

/**
 * Short Jalali date: "۱۰ خرداد" → with latn digits, "10 خرداد".
 * Used for news items.
 *
 * Zero dependencies — `Intl` ships the Persian calendar in every modern
 * browser and in Node. (PHP has no equivalent built-in; the WordPress port
 * will need a Jalali library. Noted in docs/wordpress-integration.md.)
 *
 * @param {string} iso  ISO-8601 date string
 */
export function jalaliShort(iso) {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return EMPTY;
  return new Intl.DateTimeFormat(DATE_LOCALE, {
    day: 'numeric',
    month: 'long',
  }).format(d);
}

/**
 * Full Jalali date: "10 خرداد 1404".
 * Used for launch date and ATH/ATL timestamps.
 */
export function jalaliFull(iso) {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return EMPTY;
  return new Intl.DateTimeFormat(DATE_LOCALE, {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(d);
}

/**
 * Gregorian date in Persian: "30 ژوئیهٔ 2015".
 *
 * Used for HISTORICAL dates — launch, all-time high, all-time low — while
 * recent content (news, chart axes) uses Jalali above. That split follows the
 * reference design and normal Persian practice: international historical
 * events are cited in the Gregorian calendar, local and recent ones in Jalali.
 */
export function gregorianFa(iso) {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return EMPTY;
  return new Intl.DateTimeFormat(`fa-IR-u-ca-gregory-nu-${DIGIT_SYSTEM}`, {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(d);
}

/**
 * Percentage that stays readable at any magnitude.
 *
 * Change since an all-time low can run to six figures (ETH is about
 * +771,000%), where two decimals are noise. Above 1000% the decimals are
 * dropped.
 */
export function pctSmart(n) {
  if (!isNum(n)) return EMPTY;
  return pct(n, { decimals: Math.abs(n) >= 1000 ? 0 : 2 });
}

/**
 * Clock label for chart x-axis ticks: "14:30".
 * Always Latin digits and always LTR — the chart is an LTR island.
 */
export function clock(ts) {
  const d = new Date(ts);
  if (Number.isNaN(d.getTime())) return EMPTY;
  return new Intl.DateTimeFormat('en-GB', {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(d);
}

/**
 * Day label for longer chart ranges: "12 خرداد".
 */
export function dayLabel(ts) {
  return jalaliShort(new Date(ts).toISOString());
}

/* -------------------------------------------------------------------------
 * Misc
 * ---------------------------------------------------------------------- */

/**
 * Truncate a hex address for display: "0x2170…f933f8".
 * The caller MUST render the result inside <bdi> or .thb-ltr.
 */
export function shortAddress(addr, lead = 6, tail = 6) {
  if (typeof addr !== 'string' || addr.length <= lead + tail + 1) return addr || EMPTY;
  return addr.slice(0, lead) + '…' + addr.slice(-tail);
}

/** Ratio shown with 3 decimals, e.g. TVL/MCap = 0.165 */
export function ratio(n, decimals = 3) {
  if (!isNum(n)) return EMPTY;
  return nf(decimals, decimals).format(n);
}

export { EMPTY };
