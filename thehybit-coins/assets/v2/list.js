/**
 * TheHybit Coins — instant search on /coins/ (2.9.0).
 *
 * Progressive enhancement over a plain GET form: without this script the form
 * submits ?q= and the server renders the results (Search.php). With it, the
 * list answers as you type, from a compact index of every coin in the list
 * (all pages, not just the hundred on screen), fetched ONCE — on first focus —
 * from /wp-json/thehybit/v1/coins-index, which the server builds from its
 * cache. No request per keystroke; no provider is ever asked.
 *
 * normalize() and relayout() are the twins of Search::normalize() and
 * Search::relayout(): both sides are tested against the same cases.
 */
(() => {
  'use strict';

  const form = document.querySelector('[data-list-search]');
  if (!form || !window.fetch) return;
  const input = form.querySelector('input[name="q"]');
  const box = document.querySelector('[data-list-results]');
  const status = box && box.querySelector('[data-list-status]');
  const table = box && box.querySelector('[data-list-table]');
  const tbody = box && box.querySelector('[data-list-rows]');
  const main = document.querySelector('[data-list-main]');
  const pages = document.querySelector('[data-list-pages]');
  if (!input || !box || !tbody) return;

  const cat = form.dataset.cat || null;
  let cats = {};
  try { cats = JSON.parse(form.dataset.cats || '{}'); } catch (e) { cats = {}; }
  const base = form.getAttribute('action') || '/coins/';
  const LIMIT = 50;

  const FOLD = {
    'ي': 'ی', 'ى': 'ی', 'ئ': 'ی', 'ك': 'ک', 'ة': 'ه', 'ۀ': 'ه', 'أ': 'ا', 'إ': 'ا', 'آ': 'ا', 'ٱ': 'ا', 'ؤ': 'و',
  };
  const LAYOUT = {
    'ض': 'q', 'ص': 'w', 'ث': 'e', 'ق': 'r', 'ف': 't', 'غ': 'y', 'ع': 'u', 'ه': 'i', 'خ': 'o', 'ح': 'p',
    'ش': 'a', 'س': 's', 'ی': 'd', 'ب': 'f', 'ل': 'g', 'ا': 'h', 'ت': 'j', 'ن': 'k', 'م': 'l',
    'ظ': 'z', 'ط': 'x', 'ز': 'c', 'ر': 'v', 'ذ': 'b', 'د': 'n', 'پ': 'm',
  };

  const normalize = (s) => String(s || '')
    .replace(/[يىئكةۀأإآٱؤ]/g, (c) => FOLD[c])
    .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06F0))
    .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660))
    .toLowerCase()
    .replace(/[ً-ٰٟـ‌‍]/g, '')
    .replace(/[^\p{L}\p{N}]+/gu, '');

  const relayout = (q) => {
    if (!q || /[^؀-ۿ]/.test(q)) return null;
    let out = '';
    for (const ch of q) {
      if (!LAYOUT[ch]) return null;
      out += LAYOUT[ch];
    }
    return out;
  };

  let index = null;      // [{row, sym, names[]}]
  let loading = null;

  const load = () => {
    if (index || loading) return loading;
    loading = fetch(form.dataset.index, { credentials: 'omit', headers: { Accept: 'application/json' } })
      .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
      .then((j) => {
        index = (j.rows || []).map((r) => ({ r, sym: normalize(r.s), names: [normalize(r.n), normalize(r.f)].filter(Boolean) }));
        return index;
      })
      .catch(() => { index = null; loading = null; return null; });
    return loading;
  };

  const score = (q) => {
    const hits = [];
    for (const e of index) {
      if (cat && e.r.k !== cat) continue;
      let tier = null;
      if (e.sym === q) tier = 0;
      else if (e.names.includes(q)) tier = 1;
      else if (e.sym.startsWith(q)) tier = 2;
      else if (e.names.some((n) => n.startsWith(q))) tier = 3;
      else if (q.length >= 2 && e.names.some((n) => n.includes(q))) tier = 4;
      if (tier !== null) hits.push([tier, e.r.r ?? Infinity, e.r]);
    }
    return hits.sort((a, b) => a[0] - b[0] || a[1] - b[1]);
  };

  const fmtPct = (v) => (v === null || v === undefined ? '—' : (v > 0 ? '+' : v < 0 ? '−' : '') + Math.abs(v).toFixed(2) + '%');
  const dir = (v) => (v === null || v === undefined || v === 0 ? 'flat' : v > 0 ? 'up' : 'down');
  const el = (tag, attrs = {}, text = null) => {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) n.setAttribute(k, v);
    if (text !== null) n.textContent = text;
    return n;
  };
  const num = (text) => el('bdi', { class: 'v2-num', dir: 'ltr' }, text);
  const pageUrl = (g) => (g && g > 1 ? base.replace(/\/?$/, '/') + 'page/' + g + '/' : base);

  const render = (hits, alt, raw) => {
    tbody.textContent = '';
    for (const [, , r] of hits.slice(0, LIMIT)) {
      const tr = el('tr', r.u ? { class: 'is-ours' } : {});
      const rank = el('td', { class: 'c-rank' }); rank.append(num(r.r ? String(r.r) : '—'));
      const th = el('th', { scope: 'row', class: 'c-coin' });
      const wrap = el('span', { class: 'list-coin__text' });
      const name = r.f || r.n;
      if (r.u) wrap.append(el('a', { class: 'list-coin__name', href: r.u }, name));
      else wrap.append(el('a', { class: 'list-coin__name list-coin__name--plain', href: pageUrl(r.g) + '#rank-' + (r.r || ''), title: r.g ? 'در صفحه ' + r.g + ' فهرست' : '' }, name));
      wrap.append(el('bdi', { class: 'list-coin__sym', dir: 'ltr' }, r.s));
      if (r.k && cats[r.k]) wrap.append(el('span', { class: 'list-coin__cat' }, cats[r.k]));
      th.append(wrap);
      const price = el('td', { class: 'c-num c-price' }); price.append(num(r.p));
      const ch = el('td', { class: 'c-num c-24h v2-delta--' + dir(r.c) }); ch.append(num(fmtPct(r.c)));
      tr.append(rank, th, price, ch);
      tbody.append(tr);
    }
    const n = Math.min(hits.length, LIMIT);
    table.hidden = n === 0;
    status.textContent = n === 0
      ? `هیچ ارزی با «${raw}» در نام یا نماد پیدا نشد${cat ? ' (در این دسته)' : ''}. املای دیگری را امتحان کنید.`
      : `${n} ارز پیدا شد${hits.length > LIMIT ? ` (${LIMIT} نتیجه اول)` : ''}${alt ? ` — نتایج برای «${alt}»، همان کلیدها روی صفحه‌کلید انگلیسی` : ''}${cat ? ' — در این دسته' : ''}.`;
  };

  const show = (on) => {
    box.hidden = !on;
    if (main) main.hidden = on;
    if (pages) pages.hidden = on;
  };

  const syncUrl = (raw) => {
    if (!window.history || !history.replaceState) return;
    const u = new URL(location.href);
    if (raw) u.searchParams.set('q', raw); else u.searchParams.delete('q');
    history.replaceState(null, '', u);
  };

  let timer = null;
  const run = async () => {
    const raw = input.value.trim().slice(0, 40);
    const q = normalize(raw);
    syncUrl(raw);
    if (!q) { show(false); return; }
    show(true);
    if (!index) {
      status.textContent = 'در حال آماده‌سازی جستجو…';
      await load();
      if (!index) { status.textContent = 'جستجوی فوری در دسترس نیست؛ دکمه «جستجو» را بزنید.'; return; }
      if (input.value.trim().slice(0, 40) !== raw) return;   // typed on while loading
    }
    let hits = score(q);
    let alt = null;
    if (!hits.length) {
      const r = relayout(q);
      if (r) { const h = score(r); if (h.length) { hits = h; alt = r; } }
    }
    render(hits, alt, raw);
  };

  // If the server already rendered ?q= results, keep them until the visitor types.
  const serverResults = input.value.trim() !== '';
  input.addEventListener('focus', load, { once: true });
  input.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(run, 120);
  });
  input.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape' && input.value) { ev.preventDefault(); input.value = ''; run(); }
  });
  form.addEventListener('submit', (ev) => {
    if (index) { ev.preventDefault(); clearTimeout(timer); run(); }   // without the index, the server answers
  });
  if (serverResults) load();

  window.__thbSearch = { normalize, relayout };   // for the test suite
})();
