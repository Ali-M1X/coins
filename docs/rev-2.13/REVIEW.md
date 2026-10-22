# 2.13.0 — what to check on the real server

All three new sources are **not verified live**: the build sandbox cannot reach any external host (DNS fails for api.nobitex.ir, api.frankfurter.dev, open.er-api.com and the other candidates). The parsers follow the documented response shapes and refuse anything else, so a wrong guess means the option is missing, never a wrong number.

## 1. Run the Provider Probe (wp-admin → TheHybit Coins → Provider Probe)

Three new rows, plus one for the existing toman fallback:

| Row | What a pass looks like |
|---|---|
| `nobitex` — USDT/RLS market | `status: "ok"`, `stats["usdt-rls"].latest` a rial string such as `"1050000"` |
| `persiantoolbox` — USD rate (fallback) | `data[].symbol = "USD"` with a `price` |
| `frankfurter` — USD → EUR, GBP | `base: "USD"`, `date`, `rates.EUR`, `rates.GBP` (each about 0.7–1.0) |
| `erapi` — fallback | `result: "success"`, `base_code: "USD"`, `rates.EUR`, `rates.GBP` |

If a row fails, send me its sample body and I will adjust the parser.

## 2. Expected shapes (documented, not fetched)

```
GET https://api.nobitex.ir/market/stats?srcCurrency=usdt&dstCurrency=rls
{"status":"ok","stats":{"usdt-rls":{"isClosed":false,"bestSell":"…","bestBuy":"…","latest":"…","dayLow":"…","dayHigh":"…", …}}}

GET https://api.frankfurter.dev/v1/latest?base=USD&symbols=EUR,GBP
{"amount":1.0,"base":"USD","date":"YYYY-MM-DD","rates":{"EUR":…,"GBP":…}}

GET https://open.er-api.com/v6/latest/USD
{"result":"success","base_code":"USD","time_last_update_unix":…,"rates":{"EUR":…,"GBP":…, …}}
```

Sources:
- Nobitex: I could not open its API docs (apidocs.nobitex.ir) from here. The field names are as I know them from those docs and from community clients (e.g. https://glama.ai/mcp/servers/@xmannii/nobitex-mcp-server), so treat them as unconfirmed until the probe runs.
- Frankfurter: https://frankfurter.dev
- ExchangeRate-API's open endpoint: https://www.exchangerate-api.com/docs/free

## 3. How each rate is used

- **Toman per dollar** = Nobitex USDT/RLS ÷ 10 ÷ Tether's cached USD price. If Nobitex has no entry, or Tether's price is outside 0.5–1.5, the old PersianToolbox USD rate is used. If neither exists, there is no toman option.
- **EUR and GBP** come from Frankfurter (the ECB reference rate, once a working day). Without a Frankfurter entry they come from open.er-api. Without either, those options are not shown.
- **The hero's «≈ … تومان» line** still uses PersianToolbox only, as before. I can switch it to the same toman rate in the next version if you want.

## 4. Requests

| Source | TTL | Per hour | Per day | Budget per hour |
|---|---|---|---|---|
| Nobitex | 10 min | 6 | 144 | 20 |
| Frankfurter | 6 h | 0.17 | 4 | 4 |
| open.er-api | 12 h | 0.08 | 2 | 2 |

- **Added in total:** about 6.25 requests an hour.
- **Existing load, unchanged:** CoinGecko about 96/h at 20 coins (simulation), with no 429s.
- **Page views:** add nothing. Ethereum stays at 7 requests cold and 0 warm; the list and the tools pages make 0.
- **Logos in the From/To lists:** the browser loads these image files only when a visitor opens a list.

## 5. Things I chose; tell me if you want them otherwise

- Stablecoins and gold tokens are excluded from the top-gainer and top-loser boxes and from the category ranking.
- A category needs at least 3 coins with a 24h change to be ranked.
- The 7-day box keeps a small note «میانگین وزنی ۱۰۰ ارز بزرگ», because the number means nothing without it.
- Provider names still appear where the browser needs them: WebSocket URLs, logo image URLs, and links to a block explorer for large transfers. Exchange and protocol names that are content (Binance, Kraken, Lido) also stay.
