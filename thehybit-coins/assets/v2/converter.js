/**
 * TheHybit Coins — the price converter (2.10.0; 2.13.0 currencies and list).
 *
 * Converts in the browser, from the rates in window.__THB_CONV__ (the same
 * cached prices the page prints). No request per conversion, nothing fetched
 * at all. This page's coin follows live USD ticks where live.js (2.4.0)
 * already has them — it announces each repaint as a `thb:live` event.
 *
 * 2.13.0: fiat units are whatever `fiat` holds (units per dollar) — a currency
 * with no rate is not an option. Each <select> is replaced by a searchable
 * list (Picker below); the select stays in the form, hidden, as the value
 * holder and the no-script fallback.
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
  const swap = root.querySelector('[data-v2-conv-swap]');
  if (!form || !amountEl || !fromEl || !toEl || !out) return;

  const fiat = data.fiat || { usd: 1 };
  const FIAT = ['usd', 'toman', 'eur', 'gbp'];
  const CENTS = ['usd', 'eur', 'gbp'];
  const prices = {};
  const names = { usd: 'دلار', toman: 'تومان', eur: 'یورو', gbp: 'پوند' };
  for (const c of data.coins) { prices[c.i] = c.p; names[c.i] = c.n; }
  const live = { usd: null, toman: null };   // this page's coin, from real ticks only

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
    if (CENTS.includes(unit)) return a >= 1 ? 2 : a >= 0.01 ? 4 : 8;
    if (unit === 'toman') return a >= 10 ? 0 : 2;
    return a >= 1000 ? 2 : a >= 1 ? 4 : a >= 0.0001 ? 6 : 8;
  };
  const fa = (v, unit) => {
    const d = decimals(unit, v);
    let s = v.toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d, useGrouping: true });
    if (d > 0 && (!CENTS.includes(unit) || d > 2)) s = s.replace(/\.?0+$/, '');
    return s.replace(/\d/g, (x) => FA[x]).replace(/,/g, '٬').replace(/\./g, '٫');
  };

  const usdPer = (unit) => {
    if (FIAT.includes(unit)) return fiat[unit] > 0 ? 1 / fiat[unit] : null;
    if (unit === data.base && live.usd) return live.usd;
    return prices[unit] ?? null;
  };
  const convert = (amount, from, to) => {
    /* This coin ↔ toman with a live toman-market tick: the market's own
       number, not the dollar price times a rate. Only where toman is offered. */
    if (live.toman && fiat.toman && from === data.base && to === 'toman') return amount * live.toman;
    if (live.toman && fiat.toman && from === 'toman' && to === data.base) return amount / live.toman;
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
    syncUrl();
  };

  /* ------------------------------------------------------------------
   * Picker — a searchable list in place of a native <select>.
   *
   * The trigger is a button (aria-haspopup=listbox); the popup holds a search
   * field that is the combobox (aria-controls the listbox, active option by
   * aria-activedescendant) and a listbox of grouped options. Keyboard:
   * arrows, Home/End, Enter, Escape, Tab; typing on the closed trigger opens
   * it and starts a search. Closes on an outside press. Logos load the first
   * time a list opens, never on page view. On a narrow screen the popup is a
   * bottom sheet (CSS).
   * ---------------------------------------------------------------- */
  const norm = (s) => String(s || '').toLowerCase()
    .replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه')
    .replace(/[\s‌_-]/g, '');
  let uid = 0;
  let logosOn = false;   // set the first time any list opens
  const open = new Set();

  class Picker {
    constructor(wrap) {
      this.wrap = wrap;
      this.select = wrap.querySelector('select');
      this.btn = wrap.querySelector('[data-v2-pick-btn]');
      this.id = `v2pick${++uid}`;
      this.items = [];
      this.active = -1;
      this.loaded = false;
      this.build();
      this.sync();
      this.select.hidden = true;
      this.select.tabIndex = -1;
      this.btn.hidden = false;
      wrap.classList.add('is-on');
    }

    build() {
      const pop = document.createElement('div');
      pop.className = 'v2-pick__pop';
      pop.hidden = true;
      const label = this.btn.getAttribute('aria-label') || '';
      pop.innerHTML = `<div class="v2-pick__head"><span class="v2-pick__title"></span><button type="button" class="v2-pick__close" aria-label="بستن">×</button></div>
        <input class="v2-pick__search" type="search" autocomplete="off" spellcheck="false" placeholder="جستجو" role="combobox"
               aria-autocomplete="list" aria-expanded="true" aria-controls="${this.id}-list">
        <div class="v2-pick__list" id="${this.id}-list" role="listbox"></div>
        <p class="v2-pick__none" hidden>موردی پیدا نشد</p>`;
      pop.querySelector('.v2-pick__title').textContent = label;
      pop.querySelector('.v2-pick__search').setAttribute('aria-label', `جستجو در فهرست «${label}»`);
      this.list = pop.querySelector('.v2-pick__list');
      this.list.setAttribute('aria-label', label);
      this.search = pop.querySelector('.v2-pick__search');
      this.none = pop.querySelector('.v2-pick__none');
      this.pop = pop;
      this.btn.setAttribute('aria-controls', `${this.id}-list`);

      for (const g of this.select.querySelectorAll('optgroup')) {
        const group = document.createElement('div');
        group.setAttribute('role', 'group');
        const head = document.createElement('div');
        head.className = 'v2-pick__group';
        head.id = `${this.id}-g${this.list.children.length}`;
        head.setAttribute('role', 'presentation');
        head.textContent = g.label;
        group.setAttribute('aria-labelledby', head.id);
        group.append(head);
        for (const o of g.querySelectorAll('option')) {
          const el = document.createElement('div');
          el.className = 'v2-pick__opt';
          el.id = `${this.id}-${o.value}`;
          el.setAttribute('role', 'option');
          el.setAttribute('aria-selected', 'false');
          el.dataset.value = o.value;
          el.append(this.icon(o), this.text(o));
          group.append(el);
          this.items.push({ el, group, value: o.value, key: norm(`${o.dataset.name || ''} ${o.dataset.sym || ''} ${o.value} ${o.textContent}`),
            exact: [norm(o.dataset.name), norm(o.dataset.sym), norm(o.value)] });
        }
        this.list.append(group);
      }
      this.wrap.append(pop);

      this.btn.addEventListener('click', () => (this.isOpen() ? this.close(true) : this.openList()));
      this.btn.addEventListener('keydown', (ev) => {
        if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(ev.key)) { ev.preventDefault(); this.openList(); }
        else if (ev.key.length === 1 && !ev.ctrlKey && !ev.metaKey && !ev.altKey) { ev.preventDefault(); this.openList(ev.key); }
      });
      this.search.addEventListener('input', () => this.filter());
      this.search.addEventListener('keydown', (ev) => this.key(ev));
      this.list.addEventListener('click', (ev) => {
        const el = ev.target.closest('[role="option"]');
        if (el) this.choose(el.dataset.value);
      });
      this.list.addEventListener('mousemove', (ev) => {
        const el = ev.target.closest('[role="option"]');
        if (el) this.setActive(this.visible().findIndex((it) => it.el === el), false);
      });
      pop.querySelector('.v2-pick__close').addEventListener('click', () => this.close(true));
      this.select.addEventListener('change', () => this.sync());
    }

    icon(o) {
      const i = document.createElement('span');
      i.className = 'v2-pick__icon';
      i.setAttribute('aria-hidden', 'true');
      if (o.dataset.logo) {
        const img = document.createElement('img');
        img.alt = '';
        img.width = 22;
        img.height = 22;
        img.decoding = 'async';
        img.dataset.src = o.dataset.logo;
        img.addEventListener('error', () => { img.remove(); i.textContent = (o.dataset.sym || '?').slice(0, 1); });
        i.append(img);
      } else {
        i.textContent = o.dataset.glyph || (o.dataset.sym || '?').slice(0, 1);
        i.classList.add('is-glyph');
      }
      return i;
    }

    text(o) {
      const t = document.createElement('span');
      t.className = 'v2-pick__text';
      const n = document.createElement('span');
      n.className = 'v2-pick__name';
      n.textContent = o.dataset.name || o.textContent;
      const s = document.createElement('bdi');
      s.className = 'v2-pick__sym';
      s.dir = 'ltr';
      s.textContent = o.dataset.sym || '';
      t.append(n, s);
      return t;
    }

    sync() {
      const o = this.select.selectedOptions[0];
      this.btn.textContent = '';
      if (o) {
        const icon = this.icon(o);
        const img = icon.querySelector('img');
        /* Logos load only once a list has been opened — never on page view. */
        if (img && logosOn) img.src = img.dataset.src;
        else if (img) { img.remove(); icon.textContent = (o.dataset.sym || '?').slice(0, 1); icon.classList.add('is-glyph'); }
        this.btn.append(icon, this.text(o));
      }
      const chev = document.createElement('span');
      chev.className = 'v2-pick__chev';
      chev.setAttribute('aria-hidden', 'true');
      this.btn.append(chev);
      for (const it of this.items) it.el.setAttribute('aria-selected', String(it.value === this.select.value));
    }

    isOpen() { return !this.pop.hidden; }
    visible() { return this.items.filter((it) => !it.el.hidden); }

    openList(seed = '') {
      for (const p of open) if (p !== this) p.close(false);
      if (!this.loaded) {
        for (const img of this.list.querySelectorAll('img[data-src]')) { img.loading = 'lazy'; img.src = img.dataset.src; }
        this.loaded = true;
      }
      if (!logosOn) { logosOn = true; for (const p of pickers) p.sync(); }
      this.pop.hidden = false;
      this.wrap.classList.add('is-open');
      this.btn.setAttribute('aria-expanded', 'true');
      open.add(this);
      this.search.value = seed;
      this.filter();
      const sel = this.visible().findIndex((it) => it.value === this.select.value);
      this.setActive(seed ? 0 : Math.max(0, sel), true);
      this.search.focus({ preventScroll: true });
    }

    close(focus) {
      if (!this.isOpen()) return;
      this.pop.hidden = true;
      this.wrap.classList.remove('is-open');
      this.btn.setAttribute('aria-expanded', 'false');
      this.search.removeAttribute('aria-activedescendant');
      open.delete(this);
      if (focus) this.btn.focus({ preventScroll: true });
    }

    filter() {
      const q = norm(this.search.value);
      for (const it of this.items) it.el.hidden = q !== '' && !it.key.includes(q);
      for (const g of this.list.children) g.hidden = !g.querySelector('[role="option"]:not([hidden])');
      const vis = this.visible();
      this.none.hidden = vis.length > 0;
      /* An exact name or symbol («usd», «تومان») goes to the top of the choice. */
      const exact = q === '' ? -1 : vis.findIndex((it) => it.exact.includes(q));
      this.setActive(vis.length ? Math.max(0, exact) : -1, true);
    }

    setActive(i, scroll) {
      const vis = this.visible();
      for (const it of this.items) it.el.classList.remove('is-active');
      this.active = i;
      if (i < 0 || !vis[i]) { this.search.removeAttribute('aria-activedescendant'); return; }
      vis[i].el.classList.add('is-active');
      this.search.setAttribute('aria-activedescendant', vis[i].el.id);
      if (scroll) vis[i].el.scrollIntoView({ block: 'nearest' });
    }

    key(ev) {
      const n = this.visible().length;
      const move = { ArrowDown: 1, ArrowUp: -1, PageDown: 8, PageUp: -8 }[ev.key];
      if (move) { ev.preventDefault(); if (n) this.setActive(Math.min(n - 1, Math.max(0, this.active + move)), true); }
      else if (ev.key === 'Home' && !this.search.value) { ev.preventDefault(); this.setActive(0, true); }
      else if (ev.key === 'End' && !this.search.value) { ev.preventDefault(); this.setActive(n - 1, true); }
      else if (ev.key === 'Enter') { ev.preventDefault(); const it = this.visible()[this.active]; if (it) this.choose(it.value); }
      else if (ev.key === 'Escape') { ev.preventDefault(); this.close(true); }
      else if (ev.key === 'Tab') this.close(false);
    }

    choose(value) {
      if (this.select.value !== value) {
        this.select.value = value;
        this.select.dispatchEvent(new Event('change', { bubbles: true }));
      } else this.sync();
      this.close(true);
    }
  }

  const pickers = [...root.querySelectorAll('[data-v2-pick]')].filter((w) => w.querySelector('select') && w.querySelector('[data-v2-pick-btn]')).map((w) => new Picker(w));
  document.addEventListener('pointerdown', (ev) => {
    for (const p of [...open]) if (!p.wrap.contains(ev.target)) p.close(false);
  });

  amountEl.addEventListener('input', run);
  fromEl.addEventListener('change', run);
  toEl.addEventListener('change', run);
  form.addEventListener('submit', (ev) => { ev.preventDefault(); run(); });
  if (swap) swap.addEventListener('click', () => {
    const f = fromEl.value;
    fromEl.value = toEl.value;
    toEl.value = f;
    for (const p of pickers) p.sync();
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

  window.__thbConv = { parse, fa, convert, pickers };   // for the test suite
})();
