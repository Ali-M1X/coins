/**
 * TheHybit Coins — the price converter (2.10.0).
 *
 * Converts in the browser, from the rates in window.__THB_CONV__ (the same
 * cached prices the page prints, with their times). No request per
 * conversion, nothing fetched at all. This page's coin follows live ticks
 * where live.js (2.4.0) already has them — it announces each repaint as a
 * `thb:live` event — and the meta line says which figures are live.
 *
 * parse(), decimals() and fa() are the twins of V2\Converter's; both are
 * tested against the same cases.
 */
(() => {
  'use strict';

  const root = document.querySelector('[data-v2-conv]');
  const data = window.__THB_CONV__;
  if (!root || !data) return;
  const form = root.querySelector('[data-v2-conv-form]');
  const amountEl = root.querySelector('#conv-amount');
  const fromEl = root.querySelector('#conv-from');
  const toEl = root.querySelector('#conv-to');
  const out = root.querySelector('[data-v2-conv-out]');
  const meta = root.querySelector('[data-v2-conv-meta]');
  const swap = root.querySelector('[data-v2-conv-swap]');
  if (!form || !amountEl || !fromEl || !toEl || !out) return;

  const prices = {};
  const names = { usd: 'دلار', toman: 'تومان' };
  for (const c of data.coins) { prices[c.i] = c.p; names[c.i] = c.n; }
  const live = { usd: null, toman: null };   // this page's coin, from real ticks only
  const metaServer = meta ? meta.innerHTML : '';

  const FA = '۰۱۲۳۴۵۶۷۸۹';
  const parse = (s) => {
    const t = String(s ?? '').trim()
      .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06F0))
      .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660))
      .replace(/٫/g, '.')
      .replace(/[٬,،\s‌]/g, '');
    return /^\d+(\.\d+)?$|^\.\d+$/.test(t) ? parseFloat(t) : null;
  };
  const decimals = (unit, v) => {
    const a = Math.abs(v);
    if (unit === 'usd') return a >= 1 ? 2 : a >= 0.01 ? 4 : 8;
    if (unit === 'toman') return a >= 10 ? 0 : 2;
    return a >= 1000 ? 2 : a >= 1 ? 4 : a >= 0.0001 ? 6 : 8;
  };
  const fa = (v, unit) => {
    const d = decimals(unit, v);
    let s = v.toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d, useGrouping: true });
    if (d > 0 && (unit !== 'usd' || d > 2)) s = s.replace(/\.?0+$/, '');
    return s.replace(/\d/g, (x) => FA[x]).replace(/,/g, '٬').replace(/\./g, '٫');
  };

  const usdPer = (unit) => {
    if (unit === 'usd') return 1;
    if (unit === 'toman') return data.toman ? 1 / data.toman : null;
    if (unit === data.base && live.usd) return live.usd;
    return prices[unit] ?? null;
  };
  const convert = (amount, from, to) => {
    /* This coin ↔ toman with a live Nobitex toman price: the market's own
       number, not the dollar price times a rate. */
    if (live.toman && from === data.base && to === 'toman') return amount * live.toman;
    if (live.toman && from === 'toman' && to === data.base) return amount / live.toman;
    const a = usdPer(from);
    const b = usdPer(to);
    return a == null || b == null || b <= 0 ? null : (amount * a) / b;
  };

  const syncUrl = () => {
    if (!root.closest('.thb-tool') || !history.replaceState) return;   // only the standalone page keeps state
    const u = new URL(location.href);
    u.searchParams.set('from', fromEl.value);
    u.searchParams.set('to', toEl.value);
    u.searchParams.set('amount', amountEl.value.trim());
    history.replaceState(null, '', u);
  };

  const run = () => {
    const amount = parse(amountEl.value);
    amountEl.setAttribute('aria-invalid', amount === null && amountEl.value.trim() !== '' ? 'true' : 'false');
    if (amount === null) {
      out.textContent = amountEl.value.trim() === '' ? 'یک عدد وارد کنید.' : 'این عدد معتبر نیست؛ مثلاً ۰٫۵ یا 2,500 بنویسید.';
      return;
    }
    const r = convert(amount, fromEl.value, toEl.value);
    out.textContent = r == null ? '—' : `${fa(amount, 'coin')} ${names[fromEl.value]} = ${fa(r, toEl.value)} ${names[toEl.value]}`;
    if (meta && (live.usd || live.toman) && [fromEl.value, toEl.value].includes(data.base)) {
      meta.innerHTML = metaServer;
      const s = document.createElement('span');
      s.className = 'v2-conv__live';
      s.textContent = ` قیمت ${names[data.base]} زنده است${live.toman && [fromEl.value, toEl.value].includes('toman') ? ' (تومان: بازار تومانی نوبیتکس)' : ''}.`;
      meta.append(s);
    } else if (meta) {
      meta.innerHTML = metaServer;
    }
    syncUrl();
  };

  amountEl.addEventListener('input', run);
  fromEl.addEventListener('change', run);
  toEl.addEventListener('change', run);
  form.addEventListener('submit', (ev) => { ev.preventDefault(); run(); });
  if (swap) swap.addEventListener('click', () => {
    const f = fromEl.value;
    fromEl.value = toEl.value;
    toEl.value = f;
    run();
  });
  document.addEventListener('thb:live', (ev) => {
    const d = ev.detail || {};
    if (d.id !== data.base) return;
    if (d.usd > 0) live.usd = d.usd;
    if (d.toman > 0) live.toman = d.toman;
    run();
  });
  run();

  window.__thbConv = { parse, fa, convert };   // for the test suite
})();
