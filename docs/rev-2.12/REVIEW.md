# 2.9.0 – 2.12.0: search, converter, comparison, alerts — what to review, and how to measure use

Each feature shipped as its own version, with its own zip:
- `thehybit-coins-2.9.0.zip` … `thehybit-coins-2.12.0.zip`
- Roll back by installing the previous zip.

Screenshots (desktop 1440px, phone 360px) are in:
- `docs/rev-2.9/` — search
- `docs/rev-2.10/` — converter
- `docs/rev-2.11/` — comparison
- `docs/rev-2.12/` — alerts

## Request cost

**Zero external API requests** for all four features.
- **Search:** one request to our own server per visit, on first use of the box. It goes to `/wp-json/thehybit/v1/coins-index`, which is built from the cache, rate-limited and browser-cacheable.
- **Converter:** reads the existing cache only.
- **Comparison:** reads the existing cache only, in a strict mode that never refreshes anything.
- **Alerts:** reads the existing cache only and never contacts any server.

Ethereum stays at 7 requests cold and 0 warm, and the classic page is byte-identical.

## What to review

**Search (2.9.0)**
- Try Persian names typed with Arabic letters, a space instead of the half-space, and symbols typed on the Persian keyboard («ذفز»).
- The hint says "500 coins": it is the list's 5 pages × 100. If you change `listing.pages`, the hint follows.

**Converter (2.10.0)**
- Toman uses PersianToolbox's cached USD rate; that is the existing source, refreshed every 15 minutes. If the hero shows no «≈ … تومان» line on your server, the converter will not offer toman either. That is deliberate: no rate is ever assumed.
- The per-coin text is generated from each coin's facts (name, category, price, which rates are live), not hand-written. All 84 are distinct. If you want hand-written text for the biggest coins, I can add an editable field.
- `/coins/convert/` is a new indexable page. Consider adding it to your menu or footer.

**Comparison (2.11.0)**
- Every pair or trio is noindex with its canonical on `/coins/compare/`.
- **Proposal, not built:** indexable pages for a curated set of pairs, each with ~150–250 words of hand-written text on what actually distinguishes them. Suggested set (both sides already have pages):
  - Bitcoin vs Ethereum
  - Ethereum vs Solana
  - Tether vs USDC
  - USDC vs Dai
  - Bitcoin vs Litecoin
  - Dogecoin vs Shiba Inu
  - BNB vs Ethereum
  - XRP vs Stellar
  - Tether Gold vs PAX Gold
  - Arbitrum vs Polygon
  - Chainlink vs Pyth
  - Monero vs Zcash

  These would live at `/coins/compare/bitcoin-vs-ethereum/`, be in the sitemap and carry FAQ structured data. Tell me which pairs, and whether you or I write the text (I would mark every factual claim for your fact-check).
- The hero's «⇄ مقایسه» pairs a coin with the first coin in its peer group that has a page here; check a few pairings read naturally.

**Alerts (2.12.0)**
- Browser-only: nothing stored on the server, no REST endpoint, no email.
- Alerts are checked only while one of our coin pages is open, which the card says plainly.
- Email alerts: see `EMAIL-ALERTS.md` for my recommendation. Nothing is built until you approve.

## How to see whether these are used — without third-party trackers

**Recommendation:** a tiny first-party counter. The page sends `navigator.sendBeacon('/wp-json/thehybit/v1/event', {e:'…'})`, and the endpoint increments a daily counter per event name in one option or a small table.
- **What it does not keep:** no cookies, no IP stored (rate-limited by the same hashed-IP limiter), no user id and no query text.
- **What you see:** a wp-admin screen with counts per day.
- **Not yet built:** it adds one endpoint, so I'd like your go-ahead.

Events I would count:

| Feature | Event | Why |
|---|---|---|
| Search | `search_open` (first focus) | How many visitors try it |
| Search | `search_results` / `search_noresults` (debounced, once per query) | Whether people find what they look for; spikes in no-results show missing coins or spellings |
| Search | `search_click` (a result opened) | Whether results are useful |
| Search | `search_relayout` | How often the wrong-keyboard fallback saves a search |
| Converter | `convert_use` (first change per page view) | Real use, not just page views |
| Converter | `convert_unit` (from/to pair, e.g. `coin>toman`) | Which conversions matter, toman especially |
| Converter | `convert_live` | How often a live price was in play |
| Converter | `convert_page_view` | Traffic to `/coins/convert/` |
| Comparison | `compare_view` (number of coins, not which) | Use of the page |
| Comparison | `compare_entry` (hero, list row, list header, starter link) | Which entry points work |
| Comparison | `compare_share_open` (a page opened from a shared ?c= link, by referrer) | Whether shareable links are used |
| Alerts | `alert_add` (direction only) | Adoption |
| Alerts | `alert_fire` | Whether alerts actually trigger while pages are open (if rarely, that argues for email alerts) |
| Alerts | `alert_notify_on` / `alert_notify_denied` | Notification uptake |
| Alerts | `alert_delete` | Churn |
| Return visits | `return_7d` (a first-party flag in localStorage, counted once per week per browser) | Whether the features bring people back |

A server-side alternative needs no script: compare access-log counts for `/coins/convert/`, `/coins/compare/`, `?q=` and `coins-index` before and after each release.
