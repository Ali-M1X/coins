/**
 * TheHybit Coins — price alerts in the visitor's browser (2.12.0).
 *
 * Kept in localStorage under one key; nothing is sent anywhere and nothing is
 * fetched. Checked while a coin page is open, against prices the page
 * already holds:
 *   - the cached price of every coin we price (window.__THB_CONV__, the
 *     converter's data), on load;
 *   - this page's coin, on every live tick live.js announces (`thb:live`).
 * An alert fires once: an in-page message (role=alert) and, if the visitor
 * turned it on, a browser notification. It then stays in the list, marked,
 * until removed or set again.
 */
(() => {
  'use strict';

  const root = document.querySelector('[data-v2-alerts]');
  const page = window.__THB_ALERT__;
  if (!root || !page) return;
  const KEY = 'thb-alerts-v1';
  const MAX = 20;
  const $ = (s) => root.querySelector(s);
  const form = $('[data-v2-alerts-form]');
  const priceEl = $('#alert-price');
  const msg = $('[data-v2-alerts-msg]');
  const list = $('[data-v2-alerts-list]');
  const empty = $('[data-v2-alerts-empty]');
  const notifyBtn = $('[data-v2-alerts-notify]');
  const toast = $('[data-v2-alerts-toast]');
  const nowEl = $('[data-v2-alerts-now]');

  let store;
  try { localStorage.setItem(KEY + '-t', '1'); localStorage.removeItem(KEY + '-t'); store = localStorage; } catch (e) { store = null; }
  if (!store) return;   // private mode or storage blocked: offer nothing rather than alerts that vanish
  root.hidden = false;

  const FA = '۰۱۲۳۴۵۶۷۸۹';
  const parse = (s) => {
    const t = String(s ?? '').trim()
      .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06F0))
      .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660))
      .replace(/٫/g, '.').replace(/[٬,،\s‌$]/g, '');
    return /^\d+(\.\d+)?$|^\.\d+$/.test(t) ? parseFloat(t) : null;
  };
  const fa = (v) => {
    const d = v >= 1 ? 2 : v >= 0.01 ? 4 : 8;
    let s = v.toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
    if (d > 2) s = s.replace(/\.?0+$/, '');
    return s.replace(/\d/g, (x) => FA[x]).replace(/,/g, '٬').replace(/\./g, '٫');
  };

  const load = () => { try { const a = JSON.parse(store.getItem(KEY) || '[]'); return Array.isArray(a) ? a : []; } catch (e) { return []; } };
  const save = (a) => { try { store.setItem(KEY, JSON.stringify(a)); } catch (e) { /* full or blocked: the list on screen is still right */ } };

  /* Prices the page holds: every coin's cached price, then this coin's live ticks. */
  const prices = {};
  for (const c of (window.__THB_CONV__ && window.__THB_CONV__.coins) || []) prices[c.i] = c.p;
  if (page.price > 0) prices[page.coin] = page.price;

  const say = (text) => { msg.textContent = text; };
  const render = () => {
    const alerts = load();
    list.textContent = '';
    for (const a of alerts) {
      const li = document.createElement('li');
      li.className = 'v2-alerts__item' + (a.firedAt ? ' is-fired' : '');
      const t = document.createElement('span');
      t.textContent = `${a.name}: ${a.dir === 'above' ? 'بالاتر از' : 'پایین‌تر از'} ${fa(a.price)} دلار${a.firedAt ? ' — فعال شد' : ''}`;
      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'v2-alerts__del';
      del.textContent = 'حذف';
      del.setAttribute('aria-label', `حذف هشدار ${a.name} ${a.dir === 'above' ? 'بالاتر از' : 'پایین‌تر از'} ${fa(a.price)} دلار`);
      del.addEventListener('click', () => { save(load().filter((x) => x.id !== a.id)); render(); say('هشدار حذف شد.'); });
      li.append(t, del);
      list.append(li);
    }
    empty.hidden = alerts.length > 0;
    if (notifyBtn) notifyBtn.hidden = !(alerts.some((a) => !a.firedAt) && 'Notification' in window && Notification.permission === 'default');
  };

  const fire = (a, price) => {
    const text = `${a.name} ${a.dir === 'above' ? 'از' : 'به زیر'} ${fa(a.price)} دلار ${a.dir === 'above' ? 'بالاتر رفت' : 'رسید'}؛ قیمت: ${fa(price)} دلار.`;
    toast.textContent = '';
    const span = document.createElement('span');
    span.textContent = text;
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'v2-alerts__close';
    close.textContent = 'بستن';
    close.addEventListener('click', () => { toast.hidden = true; });
    toast.append(span, close);
    toast.hidden = false;
    if ('Notification' in window && Notification.permission === 'granted') {
      try { new Notification('هشدار قیمت ' + a.name, { body: text, tag: 'thb-' + a.id, lang: 'fa', dir: 'rtl' }); } catch (e) { /* the in-page message stands */ }
    }
  };

  const check = () => {
    const alerts = load();
    let changed = false;
    for (const a of alerts) {
      if (a.firedAt) continue;
      const p = prices[a.coin];
      if (!(p > 0)) continue;
      if ((a.dir === 'above' && p >= a.price) || (a.dir === 'below' && p <= a.price)) {
        a.firedAt = Date.now();
        a.firedPrice = p;
        changed = true;
        fire(a, p);
      }
    }
    if (changed) { save(alerts); render(); }
  };

  form.addEventListener('submit', (ev) => {
    ev.preventDefault();
    const target = parse(priceEl.value);
    const dir = form.querySelector('input[name="dir"]:checked')?.value || 'above';
    const now = prices[page.coin];
    priceEl.setAttribute('aria-invalid', target === null ? 'true' : 'false');
    if (target === null || target <= 0) { say('یک قیمت معتبر به دلار وارد کنید؛ مثلاً ۳۵۰۰ یا 0.25'); priceEl.focus(); return; }
    if (now > 0 && dir === 'above' && target <= now) { say(`قیمت فعلی (${fa(now)} دلار) همین حالا بالاتر از این عدد است؛ عدد بزرگ‌تری وارد کنید.`); return; }
    if (now > 0 && dir === 'below' && target >= now) { say(`قیمت فعلی (${fa(now)} دلار) همین حالا پایین‌تر از این عدد است؛ عدد کوچک‌تری وارد کنید.`); return; }
    const alerts = load();
    if (alerts.length >= MAX) { say('حداکثر ۲۰ هشدار؛ یکی را حذف کنید.'); return; }
    alerts.push({ id: Date.now().toString(36) + Math.random().toString(36).slice(2, 6), coin: page.coin, name: page.name, dir, price: target, createdAt: Date.now(), firedAt: null });
    save(alerts);
    priceEl.value = '';
    render();
    say(`هشدار ثبت شد: وقتی ${page.name} ${dir === 'above' ? 'بالاتر از' : 'پایین‌تر از'} ${fa(target)} دلار شد، خبرتان می‌کنیم (تا وقتی یکی از صفحه‌های ارز ما باز است).`);
  });

  if (notifyBtn) notifyBtn.addEventListener('click', async () => {
    try {
      const r = await Notification.requestPermission();
      say(r === 'granted' ? 'اعلان مرورگر روشن شد.' : 'اعلان مرورگر روشن نشد؛ هشدار همچنان در همین صفحه نمایش داده می‌شود.');
    } catch (e) { say('این مرورگر اعلان را پشتیبانی نمی‌کند.'); }
    render();
  });

  document.addEventListener('thb:live', (ev) => {
    const d = ev.detail || {};
    if (d.id === page.coin && d.usd > 0) {
      prices[page.coin] = d.usd;
      if (nowEl) nowEl.textContent = fa(d.usd);
      check();
    }
  });
  document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape' && !toast.hidden) toast.hidden = true; });
  window.addEventListener('storage', (ev) => { if (ev.key === KEY) render(); });   // another tab added or removed one

  render();
  check();
  window.__thbAlerts = { parse, check, KEY };   // for the test suite
})();
