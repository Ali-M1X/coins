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
    ],

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
            /* After a 429, stop calling this provider entirely for a while.
               Hammering a rate-limited endpoint cannot succeed and only extends
               the limit; the cache serves its last good data meanwhile. A
               Retry-After header, when sent, wins over this default. */
            'cooldown'  => 5 * MINUTE_IN_SECONDS,
            'datasets'  => ['market', 'chart', 'metadata', 'historical', 'global', 'categories', 'structure'],
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
            'datasets' => ['defi', 'chains', 'stablecoins', 'bridges'],

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
        ],
    ],

    /* How many articles the news section shows. The approved UI expects 4. */
    'news_limit' => 4,
];
