/**
 * TheHybit Coins — the comparison picker (2.11.0).
 *
 * Without this script the form submits ?c1=&c2=&c3= and the server answers.
 * With it, the same choice goes to the short, shareable /coins/compare/?c=a,b
 * address, and a coin cannot be picked twice. Nothing is fetched here.
 */
(() => {
  'use strict';
  const form = document.querySelector('[data-cmp-form]');
  if (!form) return;
  const selects = [...form.querySelectorAll('select')];
  const note = form.querySelector('[data-cmp-note]');

  const sync = () => {
    const taken = selects.map((s) => s.value);
    selects.forEach((s, i) => {
      for (const o of s.options) {
        o.disabled = o.value !== '' && taken.some((v, j) => j !== i && v === o.value);
      }
    });
  };
  selects.forEach((s) => s.addEventListener('change', sync));
  sync();

  form.addEventListener('submit', (ev) => {
    ev.preventDefault();
    const picked = [...new Set(selects.map((s) => s.value).filter(Boolean))];
    if (picked.length < 2) {
      if (note) note.textContent = 'دست‌کم دو ارز متفاوت انتخاب کنید.';
      selects[picked.length ? 1 : 0].focus();
      return;
    }
    location.href = form.getAttribute('action') + '?c=' + picked.map(encodeURIComponent).join(',');
  });
})();
