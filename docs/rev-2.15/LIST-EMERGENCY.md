# 2.15.0 — the empty /coins/ list, the emergency slot, and CoinGecko's real limit

## Root cause

- **What blocked the list.** The daily counter is a fixed 24-hour window that starts at its first request. In 2.14.0, the list's reserve (the last 5%) only protected it from other work while budget remained.
  - Once the counter already stood at 4,000/4,000, every request was refused, the list's own included, until that window rolled over.
  - Meanwhile the old per-page list entries expired, and the new 250-coin entries could not be fetched.
- **A second possible cause, which I cannot check from here.** The new 250-coin request returns about 1 MB with sparklines, and CoinGecko had an 8-second timeout.
  - The list request now has its own 20-second timeout.
  - If the list is still empty after the counter rolls over, the new button shows the exact error.

## What changed

- **Emergency slot.** A list chunk with nothing to show (never fetched, or expired) may make its request past our own hourly and daily caps.
  - **Hard limit:** 6 a day. 2 fill the 500 coins, so that is three attempts.
  - **Never bypassed:** a provider cooldown, the per-minute limit, or a monthly plan quota.
  - **Logged:** every use. The budget table shows «اضطراری لیست: n / 6 امروز» and the last use (time and reason).
- **«تازه‌سازی فوری لیست» button** (Tools → TheHybit Coins, admin only). It fetches both chunks now.
  - It uses the normal budget when there is room, otherwise the emergency slot under the same 6-a-day limit.
  - It shows success (rows saved, HTTP 200), or the exact error, HTTP status and the provider's message.
- **CoinGecko caps in Settings** (Settings → TheHybit Coins):
  - an optional Demo key, masked like the other keys;
  - a daily cap and a monthly cap;
  - a warning on the diagnostics screen when the projected month (last-24-hour pace × 30) exceeds the monthly cap.

## The real CoinGecko limit

| | Keyless public API (what this site uses) | Free Demo plan (with key) |
|---|---|---|
| Key | none | `x-cg-demo-api-key` |
| Per minute | ~10–30, shared by IP and varying with load (CoinGecko docs); 5 measured from this server | 100 (raised from 30 in May 2026) |
| Per month | **none published** | **10,000** |
| Per day | none published | none (≈ 333 if spread evenly) |

- **4,000 a day is our own cap,** in `config/providers.php`. No CoinGecko plan imposes it. The code sends no CoinGecko key (the old config left its header empty, and Settings had no CoinGecko field), so the site runs on the keyless public API.
- **What the cap should be:**
  - **Keyless (current plan):** no monthly cap. The daily cap is only a governor; 4,000 is fine. The real limit is per minute, and the plugin already holds itself to 4 a minute.
  - **A Demo key would make things worse:** 10,000 a month is about 333 a day. The plugin's normal schedule is about 1,088 a day (about 32,600 a month), so the month would run out in about 9 days. Don't add a Demo key unless the schedule is cut to about a third. The monthly warning will flag this immediately if you do.

## Requests after the fix (keyless)

- **Normal schedule** (simulated, steady state): 1,088 a day, about 32,600 a month. That is 27% of our own 4,000/day cap; there is no monthly quota.
- **Emergency slot:** at most 6 a day, only while a list chunk is empty. Normally 0.

## Check on the live server

1. **Before anything else:** Tools → TheHybit Coins, then press «تازه‌سازی فوری لیست».
   - **Success:** both chunks are saved with HTTP 200, and `/coins/` shows the list at once.
   - **Failure:** read the HTTP status and message shown, and send them to me:
     - 429: the keyless IP pool is busy; try again in a minute;
     - a timeout;
     - 401/403: blocked.
2. **Budget table:** «اضطراری لیست» shows how many emergency requests were used today (at most 6) and when.
3. **After 24 hours:** CoinGecko «در روز» about 1,100–1,600. No monthly warning appears while there is no key.
4. **Settings:** leave the CoinGecko key empty, and the daily and monthly caps empty (defaults), unless you decide to move to a paid plan.

## Sources

- [Keyless Public API — CoinGecko docs](https://docs.coingecko.com/docs/keyless-public-api)
- [CoinGecko API pricing](https://www.coingecko.com/en/api/pricing)
- [Errors and rate limits — CoinGecko docs](https://docs.coingecko.com/docs/errors-and-rate-limits.md)
- [CoinGecko changelog](https://docs.coingecko.com/changelog.md)
- [Best free crypto APIs (CoinGecko)](https://www.coingecko.com/learn/best-free-crypto-api)
