/**
 * TheHybit Coins — the mini converter block (2.16.0).
 *
 * Converts in the browser from the rates printed on the block (the cached
 * price and the fiat rates the server held). Nothing is fetched. A live tick
 * from live.js (`thb:live`) updates the coin's dollar price.
 */
(() => {
  'use strict';
  const FA = '۰۱۲۳۴۵۶۷۸۹';
  const NAMES = { usd: 'دلار', toman: 'تومان', eur: 'یورو', gbp: 'پوند' };
  const parse = (s) => {
    const t = String(s ?? '').trim()
      .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06F0))
      .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660))
      .replace(/٫/g, '.').replace(/[٬,،\s‌]/g, '');
    return /^\d+(\.\d+)?$|^\.\d+$/.test(t) ? parseFloat(t) : null;
  };
  const fa = (v, unit) => {
    const a = Math.abs(v);
    const d = unit === 'toman' ? (a >= 10 ? 0 : 2) : unit === 'coin' ? (a >= 1000 ? 2 : a >= 1 ? 4 : 8) : (a >= 1 ? 2 : a >= 0.01 ? 4 : 8);
    let s = v.toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
    if (d > 2 || unit === 'coin') s = s.replace(/\.?0+$/, '');
    return s.replace(/\d/g, (x) => FA[x]).replace(/,/g, '٬').replace(/\./g, '٫');
  };
  for (const root of document.querySelectorAll('[data-thb-conv]')) {
    const input = root.querySelector('[data-thb-conv-in]');
    const unit = root.querySelector('[data-thb-conv-unit]');
    const out = root.querySelector('[data-thb-conv-out]');
    let usd = parseFloat(root.dataset.usd);
    let rates = {};
    try { rates = JSON.parse(root.dataset.rates || '{}'); } catch (e) { rates = {}; }
    if (!input || !unit || !out || !(usd > 0)) continue;
    const run = () => {
      const n = parse(input.value);
      input.setAttribute('aria-invalid', n === null && input.value.trim() !== '' ? 'true' : 'false');
      const r = rates[unit.value];
      out.textContent = n === null || !(r > 0) ? '—' : `${fa(n, 'coin')} ${root.dataset.name} = ${fa(n * usd * r, unit.value)} ${NAMES[unit.value] || ''}`;
    };
    input.addEventListener('input', run);
    unit.addEventListener('change', run);
    document.addEventListener('thb:live', (ev) => {
      if (ev.detail && ev.detail.usd > 0 && root.dataset.coinId && ev.detail.id === root.dataset.coinId) { usd = ev.detail.usd; run(); }
    });
    run();
  }
})();
