/**
 * TheHybit — Sparkline.
 *
 * A tiny trend line with no axes, no labels and no interaction. Used in the
 * coin header and later in the DeFi / on-chain cards.
 *
 * Zero dependencies, ~1KB. Two techniques carry it:
 *
 *   - `preserveAspectRatio="none"` lets one path stretch to any container size
 *     without recomputing anything on resize.
 *   - `vector-effect="non-scaling-stroke"` keeps the stroke an even width in
 *     spite of that non-uniform stretch. Without it the line renders visibly
 *     thicker vertically than horizontally.
 *
 * Colour is carried by a modifier class, not by an inline `var()` in a
 * presentation attribute. Chromium resolves var() in presentation attributes,
 * but support is not universal, and a silent failure there means an invisible
 * line. A class driving a real CSS rule works everywhere and is still fully
 * theme-reactive — a theme switch recolours the chart with no JS and no
 * re-render.
 */

import { svg, clear } from '../dom.js';

/** Internal coordinate space. Arbitrary — the viewBox stretches to fit. */
const W = 100;
const H = 32;

/**
 * Draw a sparkline into a container.
 *
 * @param {Element} host      element to render into (sized by CSS)
 * @param {number[]} values
 * @param {object} [opts]
 * @param {'auto'|'up'|'down'|'brand'} [opts.color='auto']
 *        'auto' derives direction from first vs last value.
 * @param {boolean} [opts.area=true]  draw the soft gradient fill
 * @param {string}  [opts.label]      accessible description
 */
export function sparkline(host, values, opts = {}) {
  const { color = 'auto', area = true, label } = opts;

  if (!host) return;
  if (!Array.isArray(values) || values.length < 2) {
    clear(host);
    return;
  }

  const dir =
    color !== 'auto'
      ? color
      : values.at(-1) >= values[0]
        ? 'up'
        : 'down';

  const min = Math.min(...values);
  const max = Math.max(...values);
  const span = max - min || 1;

  // Inset vertically so the stroke is never clipped at the extremes.
  const pad = 2;
  const x = (i) => (i / (values.length - 1)) * W;
  const y = (v) => H - pad - ((v - min) / span) * (H - pad * 2);

  const line = values.map((v, i) => `${i ? 'L' : 'M'}${x(i).toFixed(2)},${y(v).toFixed(2)}`).join('');

  // Unique id so multiple sparklines on one page don't share a gradient.
  const gid = `thb-spark-${Math.random().toString(36).slice(2, 9)}`;

  const children = [];

  if (area) {
    children.push(
      svg('defs', {}, [
        svg('linearGradient', { id: gid, x1: '0', y1: '0', x2: '0', y2: '1' }, [
          svg('stop', { class: 'thb-sparkline__stop-from', offset: '0%' }),
          svg('stop', { class: 'thb-sparkline__stop-to', offset: '100%' }),
        ]),
      ])
    );
    children.push(
      svg('path', {
        class: 'thb-sparkline__area',
        d: `${line}L${W},${H}L0,${H}Z`,
        fill: `url(#${gid})`,
      })
    );
  }

  children.push(
    svg('path', {
      class: 'thb-sparkline__line',
      d: line,
      'vector-effect': 'non-scaling-stroke',
    })
  );

  const node = svg(
    'svg',
    {
      class: `thb-sparkline thb-series--${dir}`,
      viewBox: `0 0 ${W} ${H}`,
      preserveAspectRatio: 'none',
      role: label ? 'img' : 'presentation',
      'aria-label': label || null,
      'aria-hidden': label ? null : 'true',
      focusable: 'false',
    },
    children
  );

  clear(host).append(node);
}
