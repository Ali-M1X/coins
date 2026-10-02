/**
 * TheHybit — Score ring.
 *
 * A 0–100 arc drawn with stroke-dasharray on a circle. No dependencies, no
 * path maths: the track is a full circle and the value arc is the same circle
 * with a dash pattern that reveals the right fraction of its circumference.
 *
 * Rotated −90° so the arc starts at twelve o'clock. Colour comes from a
 * modifier class, like every other chart here, so a theme switch recolours it
 * with no redraw.
 */

import { svg, clear } from '../dom.js';

const SIZE = 96;
const STROKE = 9;
const R = (SIZE - STROKE) / 2;
const C = 2 * Math.PI * R;

/**
 * @param {Element} host
 * @param {number|null} value    0–100, or null when the score is unavailable
 * @param {object} [opts]
 * @param {'up'|'down'|'warn'|'brand'} [opts.tone='brand']
 * @param {string} [opts.label]  accessible description
 */
export function gauge(host, value, opts = {}) {
  const { tone = 'brand', label } = opts;
  if (!host) return;

  const has = typeof value === 'number' && Number.isFinite(value);
  const pct = has ? Math.max(0, Math.min(100, value)) : 0;

  const node = svg(
    'svg',
    {
      class: `thb-gauge thb-series--${tone}`,
      viewBox: `0 0 ${SIZE} ${SIZE}`,
      width: SIZE,
      height: SIZE,
      role: 'img',
      'aria-label': label || `امتیاز ${has ? Math.round(pct) : 'نامشخص'} از 100`,
      focusable: 'false',
    },
    [
      svg('circle', {
        class: 'thb-gauge__track',
        cx: SIZE / 2, cy: SIZE / 2, r: R,
        'stroke-width': STROKE,
        fill: 'none',
      }),
      svg('circle', {
        class: 'thb-gauge__value',
        cx: SIZE / 2, cy: SIZE / 2, r: R,
        'stroke-width': STROKE,
        fill: 'none',
        'stroke-linecap': 'round',
        'stroke-dasharray': `${((pct / 100) * C).toFixed(2)} ${C.toFixed(2)}`,
        transform: `rotate(-90 ${SIZE / 2} ${SIZE / 2})`,
      }),
    ]
  );

  clear(host).append(node);
}
