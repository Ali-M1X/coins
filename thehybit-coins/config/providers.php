<?php
/**
 * Provider configuration — endpoints, TTLs, limits.
 *
 * EVERY cache lifetime in the system is declared here. Nothing downstream may
 * hard-code a duration; collectors receive their TTL from this array. Tuning
 * freshness against a provider's rate limit is then a config edit, not a hunt
 * through the codebase.
 *
 * TTLs are chosen to stay comfortably inside free-tier limits. The keyless
 * CoinGecko tier is far tighter than its documentation suggests and is enforced
 * PER IP — which on shared hosting means sharing an allowance with every other
 * account on the box. The live server has been receiving 429s, so CoinGecko's
 * datasets are set conservatively: two coins simply do not need faster polling,
 * and no value here is faster than the rate at which the figure behind it moves.
 *
 * @package TheHybit\Coins
 */

defined('ABSPATH') || exit;

return [

    /* ------------------------------------------------------------------
     * Cache lifetimes, in seconds.
     *
     * Keyed by DATASET rather than by provider, because one provider can feed
     * datasets that age at very different rates: CoinGecko supplies both the
     * price (seconds) and the coin's description (days).
     * ---------------------------------------------------------------- */
    'ttl' => [
        /* CoinGecko is on the keyless public tier and the live server has been
           receiving 429s, so its datasets are deliberately conservative. Two
           coins do not need faster polling than this, and every value below is
           still at or above the rate at which the underlying figure moves. */
        'market'     => 5 * MINUTE_IN_SECONDS,   // price, market cap, 24h change/volume

        /* The LIVE PRICE: price and 24h change for every coin in ONE
           /simple/price request, refreshed on every scheduler tick.

           50 seconds is not "how fresh we would like it" — it is the value that
           makes the entry due on EVERY tick. Ticks come from the minute crontab
           about every 55–60s (see `scheduler.interval`); a TTL of 60 would find
           the entry still fresh on some ticks and silently halve the cadence to
           two minutes. Effective refresh: once a minute.

           Faster is pointless, not just expensive: CoinGecko's public API
           itself refreshes /simple/price once every 60 seconds, so a 30-second
           poll would return the same number twice.

           Cost: one request per tick whatever the coin count — about 65 an
           hour. See docs/LIVE-PRICE.md for the budget arithmetic. */
        'ticker'     => 50,
        'chart'      => 10 * MINUTE_IN_SECONDS,  // fallback only; see the `chart` block
        'metadata'   => DAY_IN_SECONDS,          // name, links, launch date, contracts
        'historical' => DAY_IN_SECONDS,          // ATH/ATL and other near-static records

        /* Other providers, unchanged. */
        'dex'        => 5 * MINUTE_IN_SECONDS,
        'defi'       => 20 * MINUTE_IN_SECONDS,
        'onchain'    => 45 * MINUTE_IN_SECONDS,
        'l2'         => 3 * HOUR_IN_SECONDS,
        'fx'         => 15 * MINUTE_IN_SECONDS,  // USD -> IRR
        'news'       => 6 * HOUR_IN_SECONDS,     // also invalidated on save_post

        /* Group A. Each is the rate at which the underlying figure actually
           moves, not the rate at which we could ask.

           `global` is the fastest of them at 10 minutes: total market cap and
           dominance do move intraday, and one request serves every coin on the
           site, so 6/hour is the whole cost however many coins exist.

           `structure` matches metadata and historical at 24h deliberately — it
           rides their /coins/{id} request. Top venue and market count are
           daily-resolution facts; asking hourly would buy nothing and would
           turn a free dataset into a paid one. */
        'global'      => 10 * MINUTE_IN_SECONDS,
        'sentiment'   => HOUR_IN_SECONDS,        // the index is computed once a day
        'chains'      => 30 * MINUTE_IN_SECONDS,
        'stablecoins' => HOUR_IN_SECONDS,
        'bridges'     => 6 * HOUR_IN_SECONDS,
        'categories'  => 6 * HOUR_IN_SECONDS,
        'structure'   => DAY_IN_SECONDS,

        /* Group B. Each is the rate at which the underlying figure actually
           moves, which for most of these is far slower than it feels.

           Gas is the fastest at five minutes — it genuinely swings intraday and
           a stale gas price is a misleading one. Validator counts and staking
           yield change over hours; total supply over days. Developer activity is
           counted over a 30-day window, so asking more than four times a day
           cannot change the answer. */
        'gas'         => 5 * MINUTE_IN_SECONDS,
        'network'     => 10 * MINUTE_IN_SECONDS,
        'staking'     => 30 * MINUTE_IN_SECONDS,
        'supply'      => HOUR_IN_SECONDS,
        'development' => 6 * HOUR_IN_SECONDS,

        /* v2 design. None of these reaches the classic page: they are read by
           includes/V2/Model.php straight from the cache, and the classic view
           model never sees them.

           Peers move with the market but feed a comparison, not a ticker;
           protocol fees are daily totals; the multi-year price history is
           weekly points, so asking more than daily cannot change it. */
        'peers'       => 30 * MINUTE_IN_SECONDS,
        'protocols'   => HOUR_IN_SECONDS,
        'longchart'   => DAY_IN_SECONDS,

        /* v2, second round — sources for the sections that were empty.
           CoinMetrics publishes daily figures with a one-day lag, so asking
           more than four times a day returns the same rows. Whale transfers
           are the one fast-moving list here. */
        'chainstats'   => 6 * HOUR_IN_SECONDS,
        'lidoapr'      => HOUR_IN_SECONDS,
        /* Replacements for the two geo-blocked sources (2.0.5). Both are
           daily figures; asking more often returns the same row. */
        /* The /coins/ list's cache entries live this long; how often each
           page is REFRESHED is set under 'listing' below (page 1 more often). */
        'listing'      => 30 * MINUTE_IN_SECONDS,
        'activity'     => 6 * HOUR_IN_SECONDS,
        'stakingyield' => 6 * HOUR_IN_SECONDS,
        'whales'       => 10 * MINUTE_IN_SECONDS,
        'ethlocations' => HOUR_IN_SECONDS,
        'interest'     => 12 * HOUR_IN_SECONDS,
    ],

    /* ------------------------------------------------------------------
     * Grace window.
     *
     * When a refresh fails, serving slightly stale data beats serving nothing.
     * A cached payload stays usable for this long past its TTL while the
     * collector retries in the background; only past this does the section
     * fall back to its empty state.
     * ---------------------------------------------------------------- */
    'stale_grace' => 6 * HOUR_IN_SECONDS,

    /* ------------------------------------------------------------------
     * How long a FAILURE is remembered.
     *
     * Not a way to serve staler data — it caches the ABSENCE of data, and only
     * when there is no cached payload to fall back on. Without it, a provider
     * returning nothing was retried by every one of the five pipeline runs in a
     * page render, and again on every subsequent view, so a single broken
     * provider cost five outbound requests per visitor indefinitely.
     *
     * Deliberately much shorter than any success TTL: a provider that recovers
     * should be picked up within the minute, not at the end of a long window.
     * ---------------------------------------------------------------- */
    'miss_ttl' => MINUTE_IN_SECONDS,

    /* ------------------------------------------------------------------
     * DATASET REGISTRY — scope, priority, provider.
     *
     * SCOPE is the thing that decides whether a dataset costs one request or a
     * hundred:
     *
     *   site   one value for the whole installation. Fetched once, shared by
     *          every coin. The USD->IRR rate is the obvious case, and it was
     *          being fetched once PER COIN before this registry existed.
     *   chain  one value per blockchain. Two coins on the same chain share it.
     *   coin   genuinely per coin. The only scope that scales with coin count.
     *
     * PRIORITY decides what survives when the request budget runs short. The
     * production probe measured five requests before a 429, so a budget running
     * short is the normal case at scale, not an edge case. Lower number wins.
     *
     *   1 critical  the price. Without it the page has nothing to say.
     *   2 high      the default chart view and the near-term series.
     *   3 medium    secondary sections, and the FX rate.
     *   4 low       long windows and near-static metadata. Days-old is fine.
     * ---------------------------------------------------------------- */
    'datasets' => [
        'market'      => ['provider' => 'coingecko',      'scope' => 'coin',  'priority' => 1, 'batch' => true],
        /* Site-wide: one entry holding every coin's live price. Warmed by the
           scheduler's own step, never queued per coin, never fetched on a page
           render or by the live-price endpoint. */
        'ticker'      => ['provider' => 'coingecko',      'scope' => 'site',  'priority' => 1, 'batch' => true, 'render' => 'cache'],
        'chart.24h'   => ['provider' => 'coingecko',      'scope' => 'coin',  'priority' => 2],
        'chart.7d'    => ['provider' => 'coingecko',      'scope' => 'coin',  'priority' => 2],
        'chart.30d'   => ['provider' => 'coingecko',      'scope' => 'coin',  'priority' => 3],
        'chart.90d'   => ['provider' => 'coingecko',      'scope' => 'coin',  'priority' => 4],
        'chart.1y'    => ['provider' => 'coingecko',      'scope' => 'coin',  'priority' => 4],
        'dex'         => ['provider' => 'dexscreener',    'scope' => 'coin',  'priority' => 3],
        'defi'        => ['provider' => 'defillama',      'scope' => 'chain', 'priority' => 3],
        'onchain'     => ['provider' => 'onchain',        'scope' => 'chain', 'priority' => 3],
        'l2'          => ['provider' => 'l2beat',         'scope' => 'chain', 'priority' => 4],
        'metadata'    => ['provider' => 'coingecko',      'scope' => 'coin',  'priority' => 4],
        'historical'  => ['provider' => 'coingecko',      'scope' => 'coin',  'priority' => 4],
        'fx'          => ['provider' => 'persiantoolbox', 'scope' => 'site',  'priority' => 3],

        /* ---- Group A ----------------------------------------------------
         *
         * RENDER MODE is the new field, and it is what keeps a page render
         * costing what it cost before. 'cache' means a page reads whatever the
         * cache holds and never fetches: the scheduler is the only thing that
         * fills these. Without it, the first visitor after a cache flush would
         * pay for six extra provider calls in series — the exact burst the
         * budget architecture exists to prevent — and would do so against
         * CoinGecko's four-a-minute ceiling.
         *
         * The cost of that choice is honest and visible: until cron has run,
         * these sections say "در حال دریافت داده" rather than showing nothing
         * or, worse, showing zero.
         *
         * SCOPE is what stops these multiplying by coin count. /global,
         * /coins/categories, /fng, /v2/chains and /stablecoinchains each return
         * the WHOLE picture in one response, so they are site-scoped and the
         * per-chain and per-coin slices are derived locally. At a hundred coins
         * they are still one request each. */
        'global'      => ['provider' => 'coingecko',   'scope' => 'site',  'priority' => 2, 'render' => 'cache'],
        'categories'  => ['provider' => 'coingecko',   'scope' => 'site',  'priority' => 4, 'render' => 'cache'],
        'sentiment'   => ['provider' => 'alternative', 'scope' => 'site',  'priority' => 3, 'render' => 'cache'],
        'chains'      => ['provider' => 'defillama',   'scope' => 'site',  'priority' => 3, 'render' => 'cache'],
        'stablecoins' => ['provider' => 'defillama',   'scope' => 'site',  'priority' => 3, 'render' => 'cache'],

        /* CEX market structure rides the EXISTING /coins/{id} request — the one
           metadata and historical already make — by asking it for tickers. The
           collector memoizes that response per request, so when the scheduler
           warms the three together they cost one call between them, not three.
           Its TTL matches theirs for exactly that reason. */
        'structure'   => ['provider' => 'coingecko',   'scope' => 'coin',  'priority' => 4, 'render' => 'cache'],

        /* NOT ENABLED. The production probe got HTTP 407 from this endpoint —
           a proxy/authorisation rejection, not something a parser fix reaches.
           The collector and its parser are implemented and tested, and the
           probe covers the endpoint, so confirming it is one button press and
           turning it on is one line. Enabling it on a single 407 would spend
           budget on a refusal every miss_ttl and put an "unavailable" card on
           the page indefinitely. */
        'bridges'     => ['provider' => 'defillama',   'scope' => 'chain', 'priority' => 4, 'render' => 'cache', 'enabled' => false],

        /* ---- Group B: on-chain, network and ecosystem --------------------
         *
         * All CHAIN-scoped except `development`, which is genuinely per coin —
         * each project has its own repository. Chain scope is the whole reason
         * these are affordable: a hundred coins across ten chains cost ten
         * requests per dataset, not a hundred, and two coins on one chain cost
         * one between them.
         *
         * Every one is `render => cache`, like Group A. A page reads what the
         * scheduler left and never calls a provider itself, so adding eight
         * datasets leaves the cold-render cost exactly where it was. */
        'gas'         => ['provider' => 'etherscan',   'scope' => 'chain', 'priority' => 3, 'render' => 'cache'],
        'supply'      => ['provider' => 'etherscan',   'scope' => 'chain', 'priority' => 4, 'render' => 'cache'],
        'network'     => ['provider' => 'blockchair',  'scope' => 'chain', 'priority' => 3, 'render' => 'cache'],
        'staking'     => ['provider' => 'beaconchain', 'scope' => 'chain', 'priority' => 3, 'render' => 'cache'],
        'development' => ['provider' => 'github',      'scope' => 'coin',  'priority' => 4, 'render' => 'cache'],

        /* ---- v2 design ---------------------------------------------------
         *
         * Cache-only like Group A and B, so the v2 page costs exactly what the
         * classic page costs to render. `peers` is ONE /coins/markets request
         * for the whole comparison list, site-wide; `protocols` is the fee
         * breakdown DefiLlama publishes per chain; `longchart` is the weekly
         * price since launch, which CoinGecko's free tier no longer serves
         * beyond 365 days. */
        'peers'       => ['provider' => 'coingecko',   'scope' => 'site',  'priority' => 4, 'render' => 'cache'],
        'protocols'   => ['provider' => 'defillama',   'scope' => 'chain', 'priority' => 4, 'render' => 'cache'],
        'longchart'   => ['provider' => 'llamaprices', 'scope' => 'coin',  'priority' => 4, 'render' => 'cache'],

        /* Second round: the sections that showed "data unavailable". All
           cache-only, so a render still costs exactly what it did. */
        'chainstats'   => ['provider' => 'coinmetrics', 'scope' => 'coin',  'priority' => 4, 'render' => 'cache'],
        'lidoapr'      => ['provider' => 'lido',        'scope' => 'chain', 'priority' => 4, 'render' => 'cache'],
        'activity'     => ['provider' => 'growthepie',  'scope' => 'chain', 'priority' => 4, 'render' => 'cache'],
        'stakingyield' => ['provider' => 'llamayields', 'scope' => 'chain', 'priority' => 4, 'render' => 'cache'],
        'whales'       => ['provider' => 'blockchair',  'scope' => 'chain', 'priority' => 3, 'render' => 'cache'],
        'ethlocations' => ['provider' => 'etherscan',   'scope' => 'chain', 'priority' => 4, 'render' => 'cache'],
        'interest'     => ['provider' => 'wikimedia',   'scope' => 'coin',  'priority' => 4, 'render' => 'cache'],
    ],

    /* ------------------------------------------------------------------
     * Peer comparison (v2). CoinGecko ids, in display order.
     *
     * Configuration, not code: which coins a reader compares against is an
     * editorial choice. One /coins/markets request returns all of them, so
     * the list can grow to 250 without costing a second request.
     * ---------------------------------------------------------------- */
    'peers' => ['bitcoin', 'ethereum', 'solana', 'binancecoin', 'ripple', 'cardano', 'avalanche-2', 'tron', 'sui'],

    /* ------------------------------------------------------------------
     * SCHEDULER — bounded work per tick.
     *
     * The old scheduler looped every coin inside one cron execution. Modelled
     * at 25 coins that took 304 seconds, past a typical max_execution_time, and
     * a tick killed mid-loop always died at the same coins — so the tail of the
     * list would never be warmed at all.
     *
     * A tick now stops at whichever of these it reaches first, and resumes from
     * where it stopped on the next tick.
     * ---------------------------------------------------------------- */
    'scheduler' => [
        /* Deliberately just UNDER the crontab's minute.

           WP-Cron reschedules an event from the moment it ran, not the moment it
           was due. At a flat 60s a tick starting at 12:00:03 is next due at
           12:01:03, so the 12:01:00 crontab run finds nothing due and the real
           cadence quietly halves. Five seconds of slack removes the drift; it
           does not make ticks more frequent, because the crontab is what fires
           them. See Scheduler::interval(). */
        'interval'         => 55,
        'max_items'        => 8,    // datasets refreshed per tick
        'max_seconds'      => 20,   // wall-clock ceiling, well inside any limit
        /* How many candidate entries queue() inspects before it stops looking.
         *
         * NOT a work limit — max_items and max_seconds are what bound the work.
         * This bounds the COST OF CHOOSING it, and it is raised here only
         * because Group A added seven datasets: at 13 datasets, 200 candidates
         * reached about fifteen coins; at 20 it reached ten. Raising it keeps
         * the same number of coins in view, and a tick still does exactly eight
         * items in twenty seconds.
         *
         * It remains a real ceiling. Beyond roughly twenty coins the queue is
         * built from the coins at the front of the list, so the tail relies on
         * the batched market call rather than on reaching the queue. That is
         * fine for price — the batch covers every coin — and it is the thing to
         * revisit when the hundred coins are actually onboarded. */
        'max_candidates'   => 400,  // cache entries inspected while choosing work
        'batch_size'       => 50,   // coins per batched market call (CoinGecko's cap)

        /* How long a tick's lock outlives the process holding it.

           Six times max_seconds. Long enough that a tick doing its full
           allowance — plus one HTTP request sitting in its 8-second timeout — is
           never displaced while still working, short enough that a worker killed
           by max_execution_time costs two ticks rather than an afternoon.
           Lock::ttl() floors this at 3 × max_seconds regardless, so a lowered
           value can never expire while ticks are still running. */
        'lock_ttl'         => 120,
    ],

    /* ------------------------------------------------------------------
     * The comparison asset for relative-strength figures.
     *
     * Configuration, not a hard-coded coin: "did this outperform Bitcoin" is
     * the question every crypto reader asks, but nothing in the pipeline knows
     * the answer is Bitcoin. It must be a coin that already exists here, because
     * its market dataset is READ FROM CACHE ONLY — rendering one coin never
     * triggers a provider call for another.
     * ---------------------------------------------------------------- */
    'benchmark' => [
        'slug'        => 'bitcoin',
        'coingeckoId' => 'bitcoin',
        'symbol'      => 'BTC',
        'name'        => 'بیت‌کوین',
    ],

    /* ------------------------------------------------------------------
     * Chart windows.
     *
     * The five approved periods are FIVE SEPARATE CoinGecko calls, so they are
     * five separate cache entries rather than one. Sharing one entry meant every
     * refresh re-requested all five at once — a six-call burst against a per-IP
     * allowance that is shared with every other site on the host. When that
     * allowance is smaller than the burst, the windows at the END of the list
     * are refused on every single attempt, so 90d and 1y do not go missing
     * intermittently: they never arrive at all.
     *
     * EACH TTL IS THE PROVIDER'S OWN SAMPLING INTERVAL, not a number chosen to
     * reduce traffic. CoinGecko samples 1 day at 5-minute resolution, 2–90 days
     * hourly, and beyond that daily. Re-requesting the 1-year series every five
     * minutes cannot return anything new — the underlying data only moves once a
     * day — so those calls were spending the allowance that 90d needed while
     * adding no freshness whatsoever.
     *
     * `fetches_per_request` caps how many windows one page render may refresh.
     * Combined with staleness-ordered selection in Pipeline::chartSeries(), a
     * window that has never been fetched sorts FIRST, which is what guarantees
     * every period eventually arrives instead of the same ones winning forever.
     * ---------------------------------------------------------------- */
    'chart' => [
        'fetches_per_request' => 2,
        'windows' => [
            /* At or above the upstream sampling rate in every case, and
               deliberately slower than that for the longer windows: a 90-day
               curve does not visibly change within four hours, and spending
               free-tier budget to re-download an identical 93KB payload is what
               starved the longer windows in the first place. */
            '24h' => ['days' => 1,   'ttl' => 10 * MINUTE_IN_SECONDS],  // 5-minutely upstream
            '7d'  => ['days' => 7,   'ttl' => HOUR_IN_SECONDS],         // hourly upstream
            '30d' => ['days' => 30,  'ttl' => 2 * HOUR_IN_SECONDS],     // hourly upstream
            '90d' => ['days' => 90,  'ttl' => 4 * HOUR_IN_SECONDS],     // hourly upstream
            '1y'  => ['days' => 365, 'ttl' => 12 * HOUR_IN_SECONDS],    // daily upstream
        ],
    ],

    /* ------------------------------------------------------------------
     * Per-provider transport settings.
     * `enabled` lets a provider be switched off without removing its config —
     * useful during the Ethereum + Bitcoin validation phase.
     * ---------------------------------------------------------------- */
    'providers' => [

        'coingecko' => [
            'label'     => 'CoinGecko',
            'enabled'   => true,
            'base'      => 'https://api.coingecko.com/api/v3',
            'timeout'   => 8,
            'headers'   => [],           // add x-cg-demo-api-key here if a key is issued
            'min_interval' => 2,         // seconds between calls, client-side throttle
            /* MEASURED ON THE PRODUCTION SERVER, not taken from documentation:
               five consecutive requests succeeded, the sixth returned 429, and
               Retry-After was 60. This is what OUR IP gets on OUR host, which
               on shared cPanel is shared with every other account on the box.
               It is not CoinGecko's published limit and must not be treated as
               one — it is an observation that could change, which is exactly
               why it lives in config. Four leaves headroom under the five we
               saw succeed. */
            'budget'    => ['per_minute' => 4, 'per_hour' => 200, 'per_day' => 4000],
            /* One of the four per-minute calls is held for the live price
               ticker, so the rest of the scheduler's CoinGecko work can never
               crowd it out: everything else gets three a minute. */
            'reserve_per_minute' => 1,
            /* After a 429, stop calling this provider entirely for a while.
               Hammering a rate-limited endpoint cannot succeed and only extends
               the limit; the cache serves its last good data meanwhile. A
               Retry-After header, when sent, wins over this default. */
            'cooldown'  => 5 * MINUTE_IN_SECONDS,
            'datasets'  => ['market', 'chart', 'metadata', 'historical', 'global', 'categories', 'structure', 'peers', 'ticker'],
        ],

        'defillama' => [
            'label'    => 'DefiLlama',
            'enabled'  => true,
            'base'     => 'https://api.llama.fi',
            'timeout'  => 8,
            'headers'  => [],
            'min_interval' => 1,
            /* NOT MEASURED. A conservative placeholder until the provider
               probe reports this host's real behaviour for it. */
            'budget'   => ['per_minute' => 30, 'per_hour' => 1200, 'per_day' => 20000],
            'datasets' => ['defi', 'chains', 'stablecoins', 'bridges', 'protocols'],

            /* DefiLlama is several APIs on several hostnames. The TVL and fee
               endpoints live on api.llama.fi; stablecoins and bridges do not,
               and asking api.llama.fi for /stablecoinchains reaches nothing.
               They stay ONE provider because they share an operator, a budget
               and a cooldown — being refused by one is being refused by all. */
            'bases'    => [
                'stablecoins' => 'https://stablecoins.llama.fi',
                'bridges'     => 'https://bridges.llama.fi',
            ],

            /* The stablecoin endpoint returns every stablecoin on every chain
               in one document and timed out at 8 seconds during the production
               probe. A bigger payload needs a longer wait, and this is still
               well inside a tick's own budget. */
            'timeouts' => [
                'stablecoins' => 15,
                'bridges'     => 12,
            ],
        ],

        /* Fear & Greed. A separate provider rather than a dataset hung off an
           existing one, so its budget, cooldown and failures are its own: a
           CoinGecko 429 must not silence market sentiment, and vice versa. */
        'alternative' => [
            'label'    => 'Alternative.me',
            'enabled'  => true,
            'base'     => 'https://api.alternative.me',
            'timeout'  => 8,
            'headers'  => [],
            'min_interval' => 2,
            /* NOT MEASURED, and deliberately tiny: the index is recomputed once
               a day, so anything above a handful of requests an hour would be
               asking for a number that cannot have changed. */
            'budget'   => ['per_minute' => 2, 'per_hour' => 20, 'per_day' => 200],
            'cooldown' => 10 * MINUTE_IN_SECONDS,
            'datasets' => ['sentiment'],
        ],

        'dexscreener' => [
            'label'    => 'DexScreener',
            'enabled'  => true,
            'base'     => 'https://api.dexscreener.com/latest',
            'timeout'  => 8,
            'headers'  => [],
            'min_interval' => 1,
            /* NOT MEASURED. A conservative placeholder until the provider
               probe reports this host's real behaviour for it. */
            'budget'   => ['per_minute' => 30, 'per_hour' => 1200, 'per_day' => 20000],
            'datasets' => ['dex'],
        ],

        'l2beat' => [
            'label'    => 'L2BEAT',
            'enabled'  => false,         // off until the ecosystem/chain scope is defined
            'base'     => 'https://l2beat.com/api',
            'timeout'  => 10,
            'headers'  => [],
            'min_interval' => 2,
            'datasets' => ['l2'],
        ],

        'onchain' => [
            'label'    => 'Dune / Alchemy',
            'enabled'  => false,         // needs an API key; section stays hidden until then
            'base'     => '',
            'timeout'  => 12,
            'headers'  => [],
            'min_interval' => 5,
            'datasets' => ['onchain'],
        ],

        /* USD -> IRR. Deliberately abstracted: Iranian FX sources change often
           and we expect to replace this one. */
        'persiantoolbox' => [
            'label'    => 'PersianToolbox',
            'enabled'  => true,
            'base'     => 'https://api.persiantoolbox.com/v1',
            'timeout'  => 8,
            'headers'  => [],
            'min_interval' => 5,
            /* NOT MEASURED. A conservative placeholder until the provider
               probe reports this host's real behaviour for it. */
            'budget'   => ['per_minute' => 4, 'per_hour' => 60, 'per_day' => 600],
            'datasets' => ['fx'],
        ],

        /* ==================================================================
         * GROUP B PROVIDERS — the on-chain and ecosystem sources.
         *
         * Every one of these is CHAIN-SCOPED or coin-scoped by its datasets,
         * never site-scoped, because what they report genuinely differs per
         * chain. At a hundred coins spread over ten chains that is ten
         * requests, not a hundred — the `chains` whitelist below is what keeps
         * it so, and what stops us asking a Bitcoin-only endpoint about
         * Ethereum.
         *
         * `auth` says where a key goes, never what it is. Keys live in the
         * WordPress option behind includes/Settings.php and are merged in at
         * boot, so nothing in this file or this repository ever holds one.
         * ================================================================== */

        'etherscan' => [
            'label'    => 'Etherscan',
            'enabled'  => true,
            /* The V2 MULTICHAIN API. V1 (`/api` with no chain id) was deprecated
               by Etherscan, so a key may be refused there. V2 takes the same
               module/action parameters plus a `chainid`, which comes from the
               map below — adding another EVM chain is a line here, not code. */
            'base'     => 'https://api.etherscan.io/v2',
            'chainids' => ['Ethereum' => 1],
            'timeout'  => 10,
            'headers'  => [],
            'min_interval' => 1,
            /* The free tier is 5 calls/second and 100,000 calls/day, which is
               far more than anything here needs. The budget below is set by what
               the DATA justifies rather than by what the provider permits: gas
               moves by the minute, supply by the hour, and asking faster buys
               nothing but a larger bill for somebody. */
            'budget'   => ['per_minute' => 10, 'per_hour' => 200, 'per_day' => 3000],
            'cooldown' => 2 * MINUTE_IN_SECONDS,
            'auth'     => ['in' => 'query', 'name' => 'apikey'],
            /* EVM chains only, and only the ones whose explorer this base
               actually serves. A chain absent from this list simply has no gas
               or supply figures, which the page states rather than guesses. */
            'chains'   => ['Ethereum'],
            'datasets' => ['gas', 'supply', 'ethlocations'],

            /* Where ETH sits, read from the chain itself (`ethlocations`).
               WETH is the ERC-20 wrapper DeFi contracts trade; the bridges are
               the CANONICAL L1 escrows of the largest rollups — native ETH
               locked on Ethereum while it circulates on the L2. One
               balancemulti call reads them all.

               VERIFY THESE ON THE SERVER: Provider Probe → "canonical bridge
               balances" prints each balance next to its address, and each
               address can be opened on etherscan.io to confirm its name tag. */
            'weth'     => '0xC02aaA39b223FE8D0A0e5C4F27eAD9083C756Cc2',
            'bridges'  => [
                'Arbitrum One' => '0x8315177aB297bA92A06054cE80a67Ed4DBd7ed3a',
                'Optimism'     => '0xbEb5Fc579115071764c7423A4f12eDde41f106Ed',
                'Base'         => '0x49048044D57e1C92A77f79988d21Fa8fAF74E97e',
                'Starknet'     => '0xae0Ee0A63A2cE6BaeEFFE56e7714FB4EFE48D419',
                'Linea'        => '0xd19d4B5d358258f05D7B411E21A1460D11B0876F',
            ],

            /* The largest exchange wallets by public Etherscan name tag, read
               in the SAME balancemulti call as the bridges (20 addresses max).
               Their total is a floor for "ETH held on exchanges", never the
               whole — exchanges run thousands of deposit addresses — and the
               page says so. Used when CoinMetrics' exchange-supply figure is
               not available.

               VERIFY ON THE SERVER: Provider Probe → "exchange wallet
               balances" lists each balance; open each address on etherscan.io
               and check its name tag before launch. Format: label => address,
               the label's first word is the exchange. */
            'exchange_wallets' => [
                'Binance 7'   => '0xBE0eB53F46cd790Cd13851d5EFf43D12404d33E8',
                'Binance 8'   => '0xF977814e90dA44bFA03b6295A0616a897441aceC',
                'Binance 14'  => '0x28C6c06298d514Db089934071355E5743bf21d60',
                'Coinbase 10' => '0xA9D1e08C7793af67e9d92fe308d5697FB81d3E43',
                'Kraken 13'   => '0xDA9dfA130Df4dE4673b89022EE50ff26f6EA73Cf',
                'Robinhood'   => '0x40B38765696e3d5d8d9d834D8AaD4bB6e418E489',
                'Crypto.com'  => '0x6262998Ced04146fA42253a5C0AF90CA02dfd2A3',
            ],
        ],

        'beaconchain' => [
            'label'    => 'beaconcha.in',
            'enabled'  => true,
            'base'     => 'https://beaconcha.in/api/v1',
            'timeout'  => 12,
            'headers'  => [],
            'min_interval' => 2,
            /* 10 calls/minute on the free tier — the tightest limit of any
               provider here, so the budget sits well under it and the TTL is
               measured in tens of minutes. Validator counts move slowly. */
            'budget'   => ['per_minute' => 2, 'per_hour' => 30, 'per_day' => 500],
            'cooldown' => 5 * MINUTE_IN_SECONDS,
            'auth'     => ['in' => 'header', 'name' => 'apikey'],
            /* The Ethereum beacon chain, and nothing else. There is no sense in
               which this endpoint has an answer for Bitcoin or Solana. */
            'chains'   => ['Ethereum'],
            'datasets' => ['staking'],
        ],

        'blockchair' => [
            'label'    => 'Blockchair',
            'enabled'  => true,
            'base'     => 'https://api.blockchair.com',
            'timeout'  => 10,
            'headers'  => [],
            'min_interval' => 2,
            /* 1,440 calls a day without a key — one a minute, averaged. Chosen
               KEYLESS deliberately: it covers both Ethereum and Bitcoin from one
               endpoint shape, which Etherscan cannot, and a key only raises a
               ceiling we are nowhere near. */
            'budget'   => ['per_minute' => 2, 'per_hour' => 40, 'per_day' => 1200],
            'cooldown' => 5 * MINUTE_IN_SECONDS,
            'auth'     => ['in' => 'query', 'name' => 'key'],
            /* Blockchair's own codes: 430/434 "your IP is (temporarily)
               blacklisted", 402 "daily limit spent". Not a 429: retrying in
               five minutes extends the ban, so these pause it for an hour. */
            'block_statuses' => [402, 430, 434],
            'block_cooldown' => HOUR_IN_SECONDS,
            'chains'   => ['Ethereum', 'Bitcoin'],
            /* Blockchair names chains its own way — an eighth identifier for one
               coin, stored rather than derived, for the same reason as the other
               seven. See Coin.php. */
            'slugs'    => ['Ethereum' => 'ethereum', 'Bitcoin' => 'bitcoin'],
            'datasets' => ['network', 'whales'],
            /* Whale radar: native transfers at or above this many coins. */
            'whale_min' => ['Ethereum' => 500, 'Bitcoin' => 500],
        ],

        /* v2's price-since-launch chart. DefiLlama's price API, as its own
           provider so a coin's DeFi switch (off for Bitcoin) cannot take the
           price history down with it. Keyless. One request per coin per day. */
        'llamaprices' => [
            'label'    => 'DefiLlama Prices',
            'enabled'  => true,
            'base'     => 'https://coins.llama.fi',
            'timeout'  => 12,
            'headers'  => [],
            'min_interval' => 1,
            /* NOT MEASURED. A conservative placeholder until the provider
               probe reports this host's real behaviour for it. */
            'budget'   => ['per_minute' => 6, 'per_hour' => 60, 'per_day' => 600],
            'cooldown' => 5 * MINUTE_IN_SECONDS,
            'datasets' => ['longchart'],
            /* Second source, same budget: Kraken's public OHLC (keyless). */
            'bases'    => ['kraken' => 'https://api.kraken.com/0/public'],
            'kraken_pairs' => ['ethereum' => 'ETHUSD', 'bitcoin' => 'XBTUSD'],
        ],

        /* CoinMetrics Community API — free, keyless, documented. Daily
           on-chain metrics (active addresses, transactions, supply, and —
           where the community tier includes them — exchange balances and
           flows, and supply untouched for a year). One request per coin per
           six hours. Its published limit is 10 requests per 6 seconds per IP. */
        /* CoinMetrics and Lido refuse the production server by LOCATION
           (Cloudflare 1009 "the site owner has banned the visitor's country";
           Lido: "Access to our services from your current location is
           unavailable"). No key or plan changes that, and routing around it
           is not something this plugin does. Both stay switched off; their
           figures come from growthepie and DefiLlama Yields, or are not shown.
           Set 'enabled' back to true only on a server they serve. */
        'coinmetrics' => [
            'label'    => 'CoinMetrics',
            'enabled'  => false,
            'base'     => 'https://community-api.coinmetrics.io/v4',
            'timeout'  => 12,
            'headers'  => [],
            'min_interval' => 1,
            'budget'   => ['per_minute' => 6, 'per_hour' => 60, 'per_day' => 600],
            'cooldown' => 5 * MINUTE_IN_SECONDS,
            'datasets' => ['chainstats'],
            /* CoinMetrics' asset ids, by CoinGecko id. Unlisted coins fall
               back to the lower-case ticker, which is CoinMetrics' own
               convention for most assets. */
            'assets'   => ['ethereum' => 'eth', 'bitcoin' => 'btc'],
            /* Requested together; the API is asked to drop any the community
               tier does not include instead of refusing the whole request. */
            'metrics'  => ['AdrActCnt', 'TxCnt', 'SplyCur', 'IssTotNtv', 'SplyExNtv',
                           'FlowInExNtv', 'FlowOutExNtv', 'SplyAct1yr', 'HashRate'],
        ],

        /* Lido's published stETH APR (7-day moving average). Keyless. A real,
           measured staking yield for the largest staking provider — used
           when beaconcha.in's network-wide figure is unavailable, and
           labelled as Lido's on the page. */
        'lido' => [
            'label'    => 'Lido',
            'enabled'  => false,   // location-blocked; see CoinMetrics above
            'base'     => 'https://eth-api.lido.fi/v1',
            'timeout'  => 10,
            'headers'  => [],
            'min_interval' => 1,
            'budget'   => ['per_minute' => 2, 'per_hour' => 20, 'per_day' => 200],
            'cooldown' => 10 * MINUTE_IN_SECONDS,
            'chains'   => ['Ethereum'],
            'datasets' => ['lidoapr'],
        ],

        /* growthepie — Ethereum ecosystem analytics, keyless. Daily active
           addresses on mainnet, from its flat fundamentals export (every chain
           and metric for ~90 days in one file). Public guidance: ≤ 10 calls a
           minute; this asks four times a day. */
        'growthepie' => [
            'label'    => 'growthepie',
            'enabled'  => true,
            'base'     => 'https://api.growthepie.com/v1',
            'timeout'  => 20,
            'headers'  => [],
            'min_interval' => 6,
            'budget'   => ['per_minute' => 1, 'per_hour' => 6, 'per_day' => 24],
            'cooldown' => 30 * MINUTE_IN_SECONDS,
            'chains'   => ['Ethereum'],
            'datasets' => ['activity'],
            'origins'  => ['Ethereum' => 'ethereum'],   // growthepie origin_key by DefiLlama chain name
            'metric'   => 'daa',                        // daily active addresses
        ],

        /* DefiLlama Yields — the stETH pool's daily yield, computed by
           DefiLlama from on-chain data. Same organisation and network path as
           the DefiLlama endpoints this server already reaches. */
        'llamayields' => [
            'label'    => 'DefiLlama Yields',
            'enabled'  => true,
            'base'     => 'https://yields.llama.fi',
            'timeout'  => 15,
            'headers'  => [],
            'min_interval' => 2,
            'budget'   => ['per_minute' => 2, 'per_hour' => 10, 'per_day' => 60],
            'cooldown' => 15 * MINUTE_IN_SECONDS,
            'chains'   => ['Ethereum'],
            'datasets' => ['stakingyield'],
            /* DefiLlama pool id by chain: "stETH — Lido"
               (defillama.com/yields/pool/747c1d2a-c668-4682-b9f9-296708a3dd90). */
            'pools'    => ['Ethereum' => '747c1d2a-c668-4682-b9f9-296708a3dd90'],
        ],

        /* Wikipedia page views (Wikimedia REST API) — free, keyless, official.
           The public-interest figure that stands in for search interest, for
           which Google publishes no API. Persian and English articles. */
        'wikimedia' => [
            'label'    => 'Wikimedia',
            'enabled'  => true,
            'base'     => 'https://wikimedia.org/api/rest_v1',
            'timeout'  => 10,
            'headers'  => [],
            'min_interval' => 1,
            'budget'   => ['per_minute' => 10, 'per_hour' => 100, 'per_day' => 1000],
            'cooldown' => 5 * MINUTE_IN_SECONDS,
            'datasets' => ['interest'],
        ],

        'github' => [
            'label'    => 'GitHub',
            'enabled'  => true,
            'base'     => 'https://api.github.com',
            'timeout'  => 10,
            'headers'  => ['Accept' => 'application/vnd.github+json'],
            'min_interval' => 1,
            /* 60 calls/hour unauthenticated, 5,000 with a token. The budget
               assumes the unauthenticated floor so the plugin behaves the same
               before and after a token is pasted in — it simply gets more
               headroom, not different behaviour. */
            'budget'   => ['per_minute' => 2, 'per_hour' => 40, 'per_day' => 400],
            'cooldown' => 10 * MINUTE_IN_SECONDS,
            'auth'     => ['in' => 'header', 'name' => 'Authorization', 'format' => 'Bearer %s'],
            'datasets' => ['development'],
        ],

    ],

    /* ------------------------------------------------------------------
     * Historical snapshots.
     *
     * Interval is how often a datapoint is APPENDED to long-term storage, which
     * is far less often than the cache refreshes. Retention is how long rows
     * are kept before pruning. Both per dataset — see includes/History.php.
     * ---------------------------------------------------------------- */
    'history' => [
        'enabled' => true,
        'datasets' => [
            'market'  => ['interval' => HOUR_IN_SECONDS,      'retain' => 2 * YEAR_IN_SECONDS],
            'defi'    => ['interval' => 6 * HOUR_IN_SECONDS,  'retain' => 2 * YEAR_IN_SECONDS],
            'onchain' => ['interval' => 12 * HOUR_IN_SECONDS, 'retain' => 2 * YEAR_IN_SECONDS],
            'dex'     => ['interval' => 6 * HOUR_IN_SECONDS,  'retain' => YEAR_IN_SECONDS],
            'l2'      => ['interval' => DAY_IN_SECONDS,       'retain' => 2 * YEAR_IN_SECONDS],
            'fx'      => ['interval' => 6 * HOUR_IN_SECONDS,  'retain' => 2 * YEAR_IN_SECONDS],
            /* Etherscan's total ETH supply, kept so net inflation can be
               measured from our own readings a week apart (2.0.5: CoinMetrics,
               which supplied it, is location-blocked). */
            'supply'  => ['interval' => 6 * HOUR_IN_SECONDS,  'retain' => YEAR_IN_SECONDS],
        ],
    ],

    /* ------------------------------------------------------------------
     * The coin list at /coins/ (includes/Listing.php). One CoinGecko
     * /coins/markets request per page of `per_page` coins:
     *   page 1 every 5 min (12/h) + pages 2–5 every 30 min (8/h) = 20/h.
     * The rest of the plugin uses ~100 of CoinGecko's 200/h (docs/LIVE-PRICE.md).
     * More pages cost 2 requests/hour each.
     * ---------------------------------------------------------------- */
    'listing' => [
        'enabled'   => true,
        'per_page'  => 100,
        'pages'     => 5,
        'ttl_first' => 5 * MINUTE_IN_SECONDS,
        'ttl_rest'  => 30 * MINUTE_IN_SECONDS,
    ],

    /* How many articles the news section shows. The approved UI expects 4. */
    'news_limit' => 4,
];
