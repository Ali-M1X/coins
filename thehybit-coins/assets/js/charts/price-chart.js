/**
 * TheHybit — Price chart.
 *
 * Hand-written SVG. No charting library.
 *
 * Design notes
 * ------------
 * DIRECTION. The chart is an LTR island inside an RTL page: time runs
 * left→right and the value axis sits on the left, exactly as on every
 * financial site including Persian ones. Mirroring it would read as broken to
 * anyone who trades. Only the tooltip flips back to RTL, because it contains
 * Persian labels.
 *
 * COORDINATES. Unlike the sparkline, this chart draws text, so it cannot use a
 * stretched viewBox — `preserveAspectRatio="none"` would distort every glyph.
 * Instead the viewBox is recomputed in real pixels on resize (ResizeObserver
 * + rAF), so one SVG unit is one pixel and text stays crisp at any width.
 *
 * COLOUR. Series colour comes from a modifier class on the root
 * (`.thb-series--up` / `--down`), never an inline `var()` in a presentation
 * attribute. A theme switch then recolours the chart with no redraw.
 */

import { svg, el, clear, on } from '../dom.js';
import * as fmt from '../format.js';

/**
 * Plot padding. The right gutter holds the last-value pill, the left holds the
 * value axis. Both shrink on narrow screens, where a fixed 134px of gutter
 * would eat most of a 375px viewport.
 */
const padFor = (w) =>
  w < 480
    ? { top: 12, right: 62, bottom: 22, left: 44 }
    : { top: 14, right: 76, bottom: 24, left: 58 };

/** Share of the plot given to the volume band, and the gap above it. */
const VOL_SHARE = 0.20;
const GAP_SHARE = 0.06;

/** Fewer gridlines and labels on narrow screens. */
const yTickCount = (h) => (h < 200 ? 3 : 4);
const xLabelCount = (w) => (w < 420 ? 3 : w < 700 ? 4 : 6);

/* -------------------------------------------------------------------------
 * Scale helpers
 * ---------------------------------------------------------------------- */

/**
 * Round a domain outward to human-friendly gridline values.
 * Raw min/max produce ticks like $3,187.43; this yields $3,150 / $3,200.
 *
 * The thresholds round the step DOWN to the next nice number rather than up.
 * Rounding up overshoots: a domain of ~163 asking for 3 ticks gives a raw step
 * of 54, which rounds up to 100 and leaves a single gridline in range. Rounding
 * down picks 50 and yields the three that were asked for.
 */
function niceTicks(min, max, count) {
  const span = max - min;
  if (!(span > 0)) return [min];

  const raw = span / count;
  const mag = 10 ** Math.floor(Math.log10(raw));
  const norm = raw / mag;
  const step = (norm < 1.5 ? 1 : norm < 3 ? 2 : norm < 7 ? 5 : 10) * mag;

  const ticks = [];
  for (let v = Math.ceil(min / step) * step; v <= max + step * 0.001; v += step) {
    ticks.push(Number(v.toFixed(10)));
  }
  return ticks;
}

/** Axis labels are compact: "$3,400" and "$390B", never "$3,400.00". */
function axisLabel(v, metric) {
  if (metric === 'mcap') return fmt.usdCompact(v, 0);
  return v >= 1000 ? '$' + fmt.num(v, 0) : fmt.usd(v);
}

/** X tick text depends on the window: clock for a day, Jalali date beyond. */
function xLabel(ts, period) {
  return period === '24h' ? fmt.clock(ts) : fmt.jalaliShort(new Date(ts).toISOString());
}

/* -------------------------------------------------------------------------
 * Chart
 * ---------------------------------------------------------------------- */

/**
 * @param {Element} host        chart container (sized by CSS)
 * @param {object}  opts
 * @param {object}  opts.series data.series — every period
 * @param {string}  [opts.period='24h']
 * @param {'price'|'mcap'} [opts.metric='price']
 * @param {Element} [opts.tableHost] container for the screen-reader table
 * @param {string}  [opts.coinName]
 */
export function createPriceChart(host, opts) {
  const {
    series,
    period: initialPeriod = '24h',
    metric: initialMetric = 'price',
    tableHost = null,
    coinName = '',
  } = opts;

  let period = initialPeriod;
  let metric = initialMetric;
  let frame = 0;

  const tooltip = el('div', { class: 'thb-chart__tooltip', hidden: 'hidden' });
  host.append(tooltip);

  /* --- Geometry ------------------------------------------------------- */
  function geometry(width, height, values, volumes) {
    const PAD = padFor(width);
    const plotW = Math.max(width - PAD.left - PAD.right, 10);
    const plotH = Math.max(height - PAD.top - PAD.bottom, 10);

    const volH = plotH * VOL_SHARE;
    const gap = plotH * GAP_SHARE;
    const priceH = plotH - volH - gap;

    let lo = Math.min(...values);
    let hi = Math.max(...values);
    // Breathing room so the line never touches the frame.
    const padY = (hi - lo || hi * 0.01) * 0.12;
    lo -= padY;
    hi += padY;

    const maxVol = Math.max(...volumes) || 1;
    const n = values.length;

    return {
      plotW, plotH, volH, gap, priceH, lo, hi, maxVol, n, PAD,
      x: (i) => PAD.left + (i / (n - 1)) * plotW,
      y: (v) => PAD.top + priceH - ((v - lo) / (hi - lo)) * priceH,
      yVol: (v) => PAD.top + priceH + gap + volH - (v / maxVol) * volH,
      volBase: PAD.top + priceH + gap + volH,
    };
  }

  /* --- Render --------------------------------------------------------- */
  function render() {
    const s = series?.[period];
    if (!s) return;

    const values = metric === 'price' ? s.price : s.mcap;
    const { t, volume } = s;
    if (!values?.length) return;

    const width = Math.round(host.clientWidth);
    const height = Math.round(host.clientHeight);
    if (width < 40 || height < 40) return;

    const g = geometry(width, height, values, volume);
    const rising = values.at(-1) >= values[0];
    const dir = rising ? 'up' : 'down';

    const gid = 'thb-chart-fill';
    const kids = [];

    /* Gradient — stop colours come from CSS, driven by the root class. */
    kids.push(
      svg('defs', {}, [
        svg('linearGradient', { id: gid, x1: '0', y1: '0', x2: '0', y2: '1' }, [
          svg('stop', { class: 'thb-chart__stop-from', offset: '0%' }),
          svg('stop', { class: 'thb-chart__stop-to', offset: '100%' }),
        ]),
      ])
    );

    /* Gridlines + value axis */
    for (const v of niceTicks(g.lo, g.hi, yTickCount(g.priceH))) {
      const y = g.y(v);
      if (y < g.PAD.top - 1 || y > g.PAD.top + g.priceH + 1) continue;
      kids.push(
        svg('line', {
          class: 'thb-chart__grid',
          x1: g.PAD.left, x2: g.PAD.left + g.plotW, y1: y, y2: y,
        })
      );
      kids.push(
        svg('text', {
          class: 'thb-chart__axis-y',
          x: g.PAD.left - 8, y: y + 4,
          'text-anchor': 'end',
          text: axisLabel(v, metric),
        })
      );
    }

    /* Volume band */
    const barW = Math.max((g.plotW / g.n) * 0.62, 1);
    for (let i = 0; i < g.n; i++) {
      const y = g.yVol(volume[i]);
      kids.push(
        svg('rect', {
          class: 'thb-chart__vol',
          x: g.x(i) - barW / 2,
          y,
          width: barW,
          height: Math.max(g.volBase - y, 0.5),
          rx: Math.min(barW / 2, 1.5),
        })
      );
    }

    /* Area + line */
    const line = values
      .map((v, i) => `${i ? 'L' : 'M'}${g.x(i).toFixed(2)},${g.y(v).toFixed(2)}`)
      .join('');

    kids.push(
      svg('path', {
        class: 'thb-chart__area',
        d: `${line}L${g.x(g.n - 1).toFixed(2)},${g.PAD.top + g.priceH}L${g.PAD.left},${g.PAD.top + g.priceH}Z`,
        fill: `url(#${gid})`,
      })
    );
    kids.push(svg('path', { class: 'thb-chart__line', d: line }));

    /* Time axis */
    const labels = xLabelCount(g.plotW);
    for (let k = 0; k < labels; k++) {
      const i = Math.round((k / (labels - 1)) * (g.n - 1));
      kids.push(
        svg('text', {
          class: 'thb-chart__axis-x',
          x: g.x(i),
          y: height - 6,
          'text-anchor': k === 0 ? 'start' : k === labels - 1 ? 'end' : 'middle',
          text: xLabel(t[i], period),
        })
      );
    }

    /* Last-value marker and pill, in the right-hand gutter. */
    const lastX = g.x(g.n - 1);
    const lastY = g.y(values.at(-1));
    const pillText = metric === 'price' ? fmt.usd(values.at(-1)) : fmt.usdCompact(values.at(-1));
    const pillW = Math.min(pillText.length * 7.4 + 14, g.PAD.right - 8);

    kids.push(svg('circle', { class: 'thb-chart__last-dot', cx: lastX, cy: lastY, r: 3.5 }));
    kids.push(
      svg('g', { class: 'thb-chart__pill' }, [
        svg('rect', {
          x: lastX + 8,
          y: lastY - 11,
          width: pillW,
          height: 22,
          rx: 6,
        }),
        svg('text', {
          x: lastX + 8 + pillW / 2,
          y: lastY + 4,
          'text-anchor': 'middle',
          text: pillText,
        }),
      ])
    );

    /* Crosshair, hidden until the pointer enters. */
    const cross = svg('g', { class: 'thb-chart__cross', hidden: 'hidden' }, [
      /* Spans the volume band too, so it reads as one time marker across both
         plots rather than stopping short at the price area. */
      svg('line', { class: 'thb-chart__cross-line', y1: g.PAD.top, y2: g.volBase }),
      svg('circle', { class: 'thb-chart__cross-dot', r: 4.5 }),
    ]);
    kids.push(cross);

    /* Pointer capture surface — one rect rather than per-point hit areas. */
    const surface = svg('rect', {
      class: 'thb-chart__surface',
      x: g.PAD.left, y: g.PAD.top,
      width: g.plotW, height: g.plotH,
      fill: 'transparent',
    });
    kids.push(surface);

    const node = svg(
      'svg',
      {
        class: `thb-chart__svg thb-series--${dir}`,
        viewBox: `0 0 ${width} ${height}`,
        width: '100%',
        height: '100%',
        role: 'img',
        'aria-label': ariaSummary(values, t, coinName, metric, period),
        focusable: 'false',
      },
      kids
    );

    // Replace the SVG only; the tooltip element is reused across renders.
    host.querySelector('.thb-chart__svg')?.remove();
    host.prepend(node);

    wireHover({ node, surface, cross, g, values, volume, t });
    renderTable(values, volume, t);
  }

  /* --- Hover ---------------------------------------------------------- */
  function wireHover({ node, surface, cross, g, values, volume, t }) {
    const line = cross.querySelector('.thb-chart__cross-line');
    const dot = cross.querySelector('.thb-chart__cross-dot');

    const move = (ev) => {
      const rect = node.getBoundingClientRect();
      // Map client px → viewBox units (they differ if CSS scales the SVG).
      const scale = rect.width / node.viewBox.baseVal.width || 1;
      const px = (ev.clientX - rect.left) / scale;

      const i = Math.max(0, Math.min(g.n - 1, Math.round(((px - g.PAD.left) / g.plotW) * (g.n - 1))));
      const cx = g.x(i);
      const cy = g.y(values[i]);

      line.setAttribute('x1', cx);
      line.setAttribute('x2', cx);
      dot.setAttribute('cx', cx);
      dot.setAttribute('cy', cy);
      cross.removeAttribute('hidden');

      showTooltip(i, cx, cy, { values, volume, t, width: node.viewBox.baseVal.width, scale });
    };

    on(surface, 'pointermove', move);
    on(surface, 'pointerdown', move);
    on(surface, 'pointerleave', () => {
      cross.setAttribute('hidden', 'hidden');
      tooltip.hidden = true;
    });
  }

  function showTooltip(i, cx, cy, { values, volume, t, width, scale }) {
    const when =
      period === '24h'
        ? fmt.clock(t[i])
        : `${fmt.jalaliShort(new Date(t[i]).toISOString())} · ${fmt.clock(t[i])}`;

    const valueLabel = metric === 'price' ? 'قیمت' : 'ارزش بازار';
    const valueText = metric === 'price' ? fmt.usd(values[i]) : fmt.usdCompact(values[i]);

    tooltip.innerHTML =
      `<div class="thb-chart__tt-time">${when}</div>` +
      `<div class="thb-chart__tt-row"><span>${valueLabel}</span>` +
      `<b class="thb-num">${valueText}</b></div>` +
      `<div class="thb-chart__tt-row"><span>حجم</span>` +
      `<b class="thb-num">${fmt.usdCompact(volume[i])}</b></div>`;

    tooltip.hidden = false;

    // Keep the tooltip inside the chart; flip sides near the right edge.
    const ttW = tooltip.offsetWidth / scale;
    const left = cx + 14 + ttW > width ? cx - 14 - ttW : cx + 14;

    tooltip.style.insetInlineStart = 'auto';
    tooltip.style.left = `${left * scale}px`;
    tooltip.style.top = `${Math.max(cy * scale - 10, 4)}px`;
  }

  /* --- Screen-reader table -------------------------------------------- */
  function renderTable(values, volume, t) {
    if (!tableHost) return;

    const step = Math.max(1, Math.ceil(values.length / 12));
    const rows = [];
    for (let i = 0; i < values.length; i += step) {
      rows.push(
        el('tr', {}, [
          el('th', { scope: 'row', text: xLabel(t[i], period) }),
          el('td', { text: metric === 'price' ? fmt.usd(values[i]) : fmt.usdCompact(values[i]) }),
          el('td', { text: fmt.usdCompact(volume[i]) }),
        ])
      );
    }

    clear(tableHost).append(
      el('table', {}, [
        el('caption', { text: `داده‌های نمودار ${coinName} — ${periodLabel(period)}` }),
        el('thead', {}, [
          el('tr', {}, [
            el('th', { scope: 'col', text: 'زمان' }),
            el('th', { scope: 'col', text: metric === 'price' ? 'قیمت' : 'ارزش بازار' }),
            el('th', { scope: 'col', text: 'حجم' }),
          ]),
        ]),
        el('tbody', {}, rows),
      ])
    );
  }

  /* --- Resize --------------------------------------------------------- */
  const ro = new ResizeObserver(() => {
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(render);
  });
  ro.observe(host);

  render();

  return {
    setPeriod(next) {
      if (next === period || !series[next]) return;
      period = next;
      render();
    },
    setMetric(next) {
      if (next === metric) return;
      metric = next;
      render();
    },
    get period() { return period; },
    get metric() { return metric; },
    destroy() {
      ro.disconnect();
      cancelAnimationFrame(frame);
      tooltip.remove();
      host.querySelector('.thb-chart__svg')?.remove();
    },
  };
}

/* -------------------------------------------------------------------------
 * Labels
 * ---------------------------------------------------------------------- */

/* Latin digits, matching DIGIT_SYSTEM in format.js. */
const PERIOD_LABELS = {
  '24h': '24 ساعت گذشته',
  '7d': '7 روز گذشته',
  '30d': '30 روز گذشته',
  '90d': '90 روز گذشته',
  '1y': 'یک سال گذشته',
};

export function periodLabel(p) {
  return PERIOD_LABELS[p] ?? p;
}

/** One-sentence description of the series, for screen readers. */
function ariaSummary(values, t, coinName, metric, period) {
  const first = values[0];
  const last = values.at(-1);
  const change = ((last - first) / first) * 100;
  const what = metric === 'price' ? 'قیمت' : 'ارزش بازار';
  const fv = metric === 'price' ? fmt.usd : (v) => fmt.usdCompact(v);

  return (
    `نمودار ${what} ${coinName} در ${periodLabel(period)}. ` +
    `از ${fv(first)} به ${fv(last)}، ` +
    `${change >= 0 ? 'افزایش' : 'کاهش'} ${fmt.pct(Math.abs(change), { sign: false })}. ` +
    `کمترین ${fv(Math.min(...values))}، بیشترین ${fv(Math.max(...values))}.`
  );
}
