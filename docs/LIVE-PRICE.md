# Live price (2.0.2)

Price and 24h change refresh on the v2 coin page about once a minute, with no reload. The classic page is frozen by the brief and is unchanged.

## How it works

- **Server.** Each scheduler tick (about every 55–60 s, driven by the minute crontab) makes ONE CoinGecko `/simple/price` request. That request returns price, 24h change and CoinGecko's own update time for every coin, and the result is cached as one site-wide entry.
- **Endpoint.** `GET /wp-json/thehybit/v1/price/{slug}` returns that cached figure as JSON. It reads the cache only and never calls CoinGecko. It also says when the next refresh is due.
- **Page.** `v2.js` polls the endpoint once per server refresh, timed a few seconds after the refresh is due, and updates the price, the 24h change and the toman figure in place.
  - It pauses while the tab is hidden.
  - It backs off on errors: 1, 2, 4 minutes and so on, up to 5 minutes.
- **First paint.** The server-rendered page already shows the ticker price, so the first paint is minute-fresh too.

## Why 60 seconds, not 30

1. CoinGecko's public API updates `/simple/price` once every 60 seconds. Asking every 30 s returns the same number twice.
2. The scheduler runs from the minute crontab. Going sub-minute would need a second cron mechanism, which would only fetch that same unchanged number.

The ticker TTL is 50 s, deliberately shorter than the 55–60 s tick, so it is due on every tick. A 60 s TTL would find it still fresh on some ticks and halve the cadence.

## Budget arithmetic (CoinGecko: 4/min · 200/h · 4,000/day, ours)

Measured with `tools/capacity.probe.php`: the shipping scheduler over 8 simulated hours, with CoinGecko held to the 5-per-minute limit measured on the server.

| | 2 coins | 100 coins |
|---|---|---|
| Live price ticker | 65.0 /h | 65.0 /h (still one request) |
| All other CoinGecko work | 31.7 /h | 40.3 /h |
| Total | 96.7 /h | 105.3 /h |
| Per day | ~2,320 | ~2,530 |
| Busiest 60 s | 3 | 4 |
| Simulated 429s | 0 | 0 |

The ticker's cost does not grow with coin count. `/simple/price` takes any number of ids in one URL, up to a few hundred coins.

## Two budget changes that came with it

1. **The per-minute budget is now a true sliding 60-second window.** The old fixed bucket let four calls at a bucket's end and four at the next one's start land inside one real minute. In the model, the previous code reached 6 calls in a minute at 100 coins and drew **7 HTTP 429s** from CoinGecko. The new window draws none.
2. **One of the four per-minute CoinGecko calls is reserved for the ticker** (`reserve_per_minute`). Everything else gets three a minute, so the live price can never be crowded out.

## Trade-off at 100 coins

At 100 coins, CoinGecko's other work gets about 40 calls an hour instead of about 69. Part of the old 69 was the bursts CoinGecko refused, but the charts and metadata of 100 coins would still refresh more slowly than they do today. At today's 2 coins the change is negligible (34 → 32 an hour).

If that matters when you onboard 100 coins, the options are:
- a paid CoinGecko plan (the free Demo key's 10,000 calls a month cannot cover even today's ~2,300 a day);
- or a slower ticker above 50 coins.
