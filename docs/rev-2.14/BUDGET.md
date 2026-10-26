# 2.14.0 — CoinGecko daily budget: root cause, fix, and what to check

## Root cause, in numbers

Simulated with the shipping scheduler at 84 coin pages and the 500-coin list, against the counting transport. The tool is `tools/capacity20.probe.php` (`THB_SIZES=84 THB_HOURS=36 THB_WINDOW=24`), and the full outputs are in `sim-before-steady.txt` and `sim-after-steady.txt`.

**Where 2.13.0's CoinGecko requests came from:**

| Job | Before (per day) | After (per day) |
|---|---|---|
| Live ticker `/simple/price` (every tick, 50 s TTL) | **1,560** | 780 (every second tick) |
| Market batch `/coins/markets?ids=` (2 requests of 50 coins every 5 min) | ~566 | 144 (1 request of 84 coins every 10 min) |
| `/coins/` list (5 requests of 100; page 1 every 10 min, the rest every 2 h) | ~178 | ~103 (2 requests of 250; ranks 1–250 every 15 min, 251–500 every 2 h) |
| `/global` (10 min; list header boxes, market context) | 130 | 48 (30 min) |
| Coin detail, history and charts at steady state (`/coins/{id}`, `market_chart`) | ~5 | ~5 |
| Fear & Greed, stat boxes | 0 (other providers, or cache reads) | 0 |
| **Scheduler, steady state** | **2,438 (61% of 4,000)** | **1,088 (27%)** |
| **Scheduler, first day after an empty cache** | **2,983 (75%)** | **1,596 (40%)** |

**Four things then spent the rest of the 4,000:**

1. **The hourly cap didn't protect the day.** `per_hour` was 200, and 200 × 24 = 4,800, which is more than the 4,000 daily allowance. Nothing stopped the day being spent early: an hour showing 67/200 is consistent with a day already at 4,000/4,000.
2. **Page views raced the scheduler.** Any page viewed just after a coin's price or chart TTL fetched that coin itself (1–3 requests). With crawlers walking 84 coin pages, these per-view requests came on top of the scheduler's 2,438. The simulation does not include page views; production does.
3. **Failed requests were retried immediately.**
   - Each failure is counted against the budget, correctly, because the provider counts it too.
   - A failed item with an older cached value was asked again on the very next tick.
   - A failed item with nothing cached was asked again every 15 minutes, indefinitely.
4. **Slow data had short TTLs:** `/global` every 10 min, peers every 30 min, the ticker every 50 s.

**Why pages went empty.** Every cached value was kept for its TTL + **6 hours** at most. The daily counter is a fixed 24-hour window, so once it was spent nothing could be refreshed for the rest of that window. Six hours later the list pages and the coin-page tables simply expired.

**A second cause on coin pages.** Values past their TTL were labelled `stale` internally, and several v2 sections treated "stale" as "missing".

**Blockchair.** It ran at about 30 requests an hour (simulation: 29.5/h) and got HTTP 430 ("IP blocked"). Each block paused it for a flat hour, after which the same rate resumed.

## What changed

**Fewer requests**
- **Batching:**
  - one market request for all 84 coins (`batch_size` 50 → 250);
  - the list fetched 250 coins per request (2 requests for 500, instead of 5).
- **Longer TTLs for slow data:**
  - ticker 50 s → 110 s (every second tick);
  - market 5 → 10 min;
  - `/global` 10 → 30 min;
  - peers 30 min → 2 h;
  - list ranks 1–250: 10 → 15 min.
- **Pacing:** `per_hour` 200 → 160, so 160 × 24 = 3,840 can never exceed the daily allowance. Each tick still does at most 4 a minute.
- **Page views don't race the scheduler.** A view serves a value up to one TTL past due and leaves the refresh to the scheduler. Only a value a full TTL overdue, or one never fetched, is fetched by a view. Cold-page cost is unchanged: Ethereum 7, Bitcoin 4, warm 0.
- **No immediate retries:**
  - a request that failed waits 5 min, then 10, 20 … up to 6 h before it is tried again;
  - our own budget refusals made no request and set no wait;
  - the list chunks back off the same way.

**Priority when the daily budget is low** (`budget_tiers`)
- Tiers, in order:
  - 0: the `/coins/` list;
  - 1: the live ticker, and the batch for the top 20 coins;
  - 2: the other 64 coins, and anything a page view asks for;
  - 3: history and slow data (charts, metadata, `/global`, categories …).
- **Where each tier stops:** tier 3 below 30% of the day left, tier 2 below 15%, tier 1 below 5%.
- **Safety margin:** the last 5% (200 requests) is reserved for the list. When tier 2 is held back, the batch asks for the top 20 coins only.

**Never an empty page** (`max_stale`)
- A failed refresh serves the last good value. The value is kept for its TTL + a maximum stale age chosen per dataset; past that it is gone, and its section with it.

| Data | Maximum stale age |
|---|---|
| Prices (ticker, market), 24h chart, `/global`, peers, DEX, toman | 36 h |
| `/coins/` list | 48 h |
| 7-day chart | 3 days |
| 30-day chart, structure, categories, EUR/GBP | 7 days |
| 90-day chart | 14 days |
| 1-year chart, since-launch history, metadata, ATH/ATL | 30 days |
| Gas | 6 h |
| Whale transfers | 2 days |
| Network stats, everything else | 3 days |

- 36 hours is longer than one fully spent daily window (24 h), so a spent day never empties a page.
- **No wording:** public pages say nothing about age, provider or staleness. A value past its TTL is shown as normal. The real fetch time stays in the cache entry for the admin diagnostics screen.

**Blockchair**
- **Backoff:** each 430/434/402 in a row doubles the pause: 1 h, 2 h, 4 h … up to 24 h. A success resets it.
- **Lower rate:** network stats and whale transfers are hourly (were 20 and 30 min). The budget is 15/h and 300/day (was 40/h and 1,200/day). Simulated: 8.8 an hour, was 29.5.

**Admin**
- The diagnostics budget table has a new column, «مصرف روزانه پیش‌بینی‌شده». It is the rate over the last 24 hours (at least one hour) × 24, against the daily limit.
- A warning appears on that screen only when the projection is above 80%.
- The Blockchair row says how many blocks in a row it has had.

## Can CoinGecko sustain 500 listed coins?

Yes. The 500 coins cost 2 requests per refresh, about 103 a day: 2.6% of the daily budget. No fallback is needed. If the budget ever runs low, the tiers keep the list going first, and the 48-hour stale limit keeps it visible through a fully spent day.

## Check on the live server

**Right after the update**
- The «پیش‌بینی» column will be mostly empty: it builds up from the first request.
- The list pages and coin tables should show their last values again right away if the cache still holds them.
- **One request to watch:** the list is now cached under new keys. Until the first two list requests succeed, it reads the old per-page entries, which may already have expired on your server. If CoinGecko's day is still spent, the list fills at the next daily reset: tier 0 is the first thing spent then.
- The Blockchair cooldown already running (about 1,875 s) is honoured as it is.

**After 1 hour** (Diagnostics → budget table)
- **coingecko:**
  - «در ساعت» should be about 45–70 at most;
  - the projection should be about 1,100–1,700 / 4,000 (27–42%), with no warning;
  - no 429 in the cooldown column.
- **blockchair:** about 9–12 an hour, with no new 430.
- **Scheduler progress:**
  - the ticker should appear on about every second tick;
  - «listing chunk 1» about every 15 minutes;
  - one «market (batched)» request every 10 minutes.

**After 24 hours**
- **coingecko:**
  - «در روز» under 2,400 (the 60% target); expected about 1,100–1,600 plus page-view requests;
  - the projection under 80%, so no warning;
  - nothing in the skipped list mentioning «daily budget low».
- **blockchair:** under 300 a day, and its strike count 0.
- **If the projection is above 60%,** the excess is page views or crawlers. Send me a screenshot of the budget table and the last tick's progress, and I will tighten the page-view rule further.
