# 2.8.0 — «رشد فعالیت شبکه (۳۰ روز)»: sources, cost, score changes

## Why it was «بدون داده» everywhere

The score's fifth component was wired only to an on-chain provider (`dune`) that this site has never had. On every coin it was missing, and the score ran on four of five parts. That is the «۴/۵ مؤلفه داده دارند» under your Ethereum radar.

## What fills it now — no new provider, measured against real data only

| Measure | Source | Coins | When it appears |
|---|---|---|---|
| Daily **active addresses** | growthepie `fundamentals.json` — the same file and parser that already fills «آدرس‌های فعال» on your Ethereum page | Ethereum; Arbitrum and Mantle (origin keys to verify) | At once: the file holds about 90 days |
| Daily **transactions** (Blockchair's rolling 24-hour count) | Our own readings of the Blockchair `network` figure the pulse already fetches, now stored per chain, 4 times a day, for 120 days | Bitcoin, Litecoin, Dogecoin, Bitcoin Cash, Zcash (Ethereum uses growthepie first) | After 37 days of readings, and only while Blockchair answers your server |

**What is measured.** The average of the last 7 days against the average of the same 7 days a month earlier. Seven-day averages keep a quiet weekend from swinging the figure.
- It needs at least 5 of the 7 daily points (growthepie) or 4 readings (Blockchair) at each end.
- A shorter window is never passed off as 30 days.
- The DNA card says it under the radar, for example: «میانگین ۷ روز اخیر آدرس‌های فعال روزانه شبکه (۴۷۴K) در برابر همان میانگین ۳۰ روز قبل (۴۴۰K): +7.7%».

**Scoring.** The existing scale and weight (20 of 100) are unchanged.
- −30% scores 0, ±5% scores 30–50, +20% scores 80, +50% scores 100.
- The classic page and its score are untouched. The component is filled on the v2 page's copy of the model only, so the classic output stays byte-identical.

## Request cost at the current 84 coin pages

| | Requests per hour |
|---|---|
| growthepie: Arbitrum and Mantle added (same file, every 6 hours each) | 0.17 → **0.5** (cap 6/h, 24/day) |
| Blockchair history | **0**: it stores what the pulse already fetches |
| Page renders | **0** (cache only; Ethereum stays 7 requests cold, 0 warm) |

Full simulation, steady state at 84 coins (`budget.txt`):

| Provider | Requests | Limit |
|---|---|---|
| CoinGecko | 129.5/h, ≈3,100/day | 200/h, 4,000/day |
| Blockchair | 29.7/h | 40/h |
| GitHub | 13.3/h | 40/h |
| DefiLlama | 245/h | 1,200/h |

Every dataset is on time at the end (age ÷ TTL ≤ 1).

## Sources found but NOT enabled

I could not fetch a real response from the build environment: its network policy refuses these hosts. Following your rule, no parser is written for any of them yet. Each would cost about 4 requests a day per chain (≈0.17/h).

To proceed, first check that the host answers your server: Diagnostics → Provider Probe, once an entry is added. With your go-ahead, I'll then add them the same way as the others (collector, dataset, budget, cooldown, TTL cache, cron and probe entry).

| Chain | Candidate (free) | What it gives | Note |
|---|---|---|---|
| Solana | Public RPC `getTransactionCount` | Cumulative transaction count | A daily delta from our own readings; activity after 37 days |
| Cardano | Koios `epoch_info` | Transactions per epoch (5 days) | Last 6 epochs against the previous 6 |
| Tron | Tronscan / TronGrid statistics | Daily transactions | Tronscan now asks for a free key (TRON-PRO-API-KEY), entered in Settings |

**Rejected:**
- Etherscan's daily transaction statistics are on its paid plan.
- Sampling a few blocks over RPC would be an estimate, not a measurement.
- CoinMetrics refuses your server by location.

## Coins that still cannot have this component

- **All tokens on another chain:** stablecoins, gold and fund tokens, exchange tokens, DeFi and meme tokens. Their "network" is someone else's chain, so the component does not describe them; they keep 4 of 5 parts or fewer.
- **Layer-1s without a verified free source today:** XRP, BNB, Solana, Tron, Cardano, Sui, Avalanche, Hedera, Toncoin, Stellar, Polkadot, NEAR, Aptos, ICP, Ethereum Classic, Kaspa, Algorand, Cosmos, VeChain, Canton, Pi, Injective, XDC, Bittensor, Hyperliquid.
- **Layer 2s:** Polygon and Stacks (growthepie does not list them as Ethereum L2s in the same file).

## Score changes

### Test fixture (the only data reachable here)

| Coin | Before (parts) | After (parts) | Activity, 30 days | Component |
|---|---|---|---|---|
| Ethereum | 62 (3/5) | **60** (4/5) | +7.7% active addresses | 55 |
| Arbitrum | 62 (3/5) | **54** (4/5) | −3.8% | 32 |
| Mantle | 62 (3/5) | **64** (4/5) | +15.0% | 70 |
| Every other coin | unchanged | unchanged | no source yet | — |

**What moved and why.** The new component enters at weight 20 and the others are renormalised over 100 instead of 80. So the score moves toward the activity component's own value: up if activity grew more than about +10% in the month, down if it grew less.

### Your Ethereum page

Today it reads **56 with 4/5 parts**: TVL/market cap 76, volume/market cap 67, TVL growth 47, DEX share 23. After the upgrade:

score = (25·76 + 20·67 + 20·47 + 15·23 + 20·A) / 100 = 45.25 + 0.2·A, where A is the activity component.

| Ethereum's active addresses over the month | A | Score |
|---|---|---|
| Flat (0%) | ≈40 | ≈53 |
| +7.7% | ≈55 | ≈56 |
| +20% | 80 | ≈61 |

The real figure appears under the radar once 2.8.0 runs. I could not fetch growthepie from here to compute it in advance.

Bitcoin, Litecoin, Dogecoin, Bitcoin Cash and Zcash have no score today (fewer than half the parts). Their activity component arrives after 37 days of readings, which also lifts their coverage.
