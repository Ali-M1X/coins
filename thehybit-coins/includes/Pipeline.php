<?php
/**
 * The pipeline. One coin in, one view model out.
 *
 *   Coin config
 *     -> provider collectors        (Collectors/*)
 *     -> raw storage                (History)
 *     -> normalization              (this file)
 *     -> derived metrics            (Derive)
 *     -> analytics                  (Scoring)
 *     -> WordPress template         (templates/*)
 *
 * Ethereum and Bitcoin run this identically; nothing here is coin-specific.
 * Adding a coin is a CPT post plus a news category — no code.
 *
 * The output shape is the contract documented in docs/data-contract.md, so the
 * template partials and the JS both consume exactly what the prototype's
 * fixture provided.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

use TheHybit\Coins\Collectors\Collector;

defined('ABSPATH') || exit;

final class Pipeline
{
    /**
     * dataset => the provider that supplies it.
     *
     * The single list. viewModel() walks it to build a page and warm() walks one
     * entry of it for cron, so the two can no longer disagree about which
     * provider owns which dataset.
     */
    private const DATASETS = [
        'market'     => 'coingecko',
        'metadata'   => 'coingecko',
        'historical' => 'coingecko',
        'defi'       => 'defillama',
        'dex'        => 'dexscreener',
        'onchain'    => 'onchain',
        'l2'         => 'l2beat',
        'fx'         => 'persiantoolbox',

        /* Group A. Every one of these is 'render' => 'cache' in the registry,
           so a page render READS them and the scheduler is the only thing that
           fills them — see Datasets::rendersFromCacheOnly(). That is what keeps
           a cold page render costing the same seven requests it cost before. */
        'global'      => 'coingecko',
        'categories'  => 'coingecko',
        'structure'   => 'coingecko',
        'sentiment'   => 'alternative',
        'chains'      => 'defillama',
        'stablecoins' => 'defillama',
        'bridges'     => 'defillama',

        /* Group B — network and on-chain. Also cache-only on render, so the
           cold-render cost stays at seven. */
        'gas'         => 'etherscan',
        'supply'      => 'etherscan',
        'network'     => 'blockchair',
        'staking'     => 'beaconchain',
        'development' => 'github',
    ];

    /**
     * Datasets only the v2 design reads.
     *
     * Kept OUT of DATASETS on purpose: build() walks that list to assemble the
     * classic view model, and the classic page must not change by a byte. The
     * scheduler still warms these through warm(), and includes/V2/Model.php
     * reads them straight from the cache.
     */
    public const V2_DATASETS = [
        'peers'     => 'coingecko',
        'protocols' => 'defillama',
        'longchart' => 'llamaprices',
        'chainstats'   => 'coinmetrics',
        'lidoapr'      => 'lido',
        'activity'     => 'growthepie',
        'stakingyield' => 'llamayields',
        'whales'       => 'blockchair',
        'ethlocations' => 'etherscan',
        'interest'     => 'wikimedia',
    ];

    /** @var Collector[] keyed by provider id */
    private array $collectors = [];

    /** @var array<string, array> per-request view models, keyed by coin slug */
    private array $memo = [];

    public function __construct(
        private array $config,
        private Cache $cache,
        private History $history,
        private News $news
    ) {}

    /** A registered collector by provider id, or null. */
    public function collector(string $id): ?Collector
    {
        return $this->collectors[$id] ?? null;
    }

    public function register(Collector $collector): void
    {
        $this->collectors[$collector->id()] = $collector;
    }

    /**
     * Build the full view model for a coin.
     *
     * MEMOIZED PER REQUEST. One page render asks for this model five times, from
     * five unrelated places, none of which knows about the others:
     *
     *   Plugin::assets()        the JSON data island
     *   Seo::description()      wpseo_metadesc
     *   Seo::description()      wpseo_opengraph_desc
     *   Schema::output()        JSON-LD in wp_head
     *   single-coin.php         the page itself
     *
     * The dataset cache absorbed most of that, but not all: a dataset that
     * returns null is not a cache hit, so a provider with no answer was refetched
     * on every one of the five runs — measured at five identical DexScreener
     * calls per page, on a warm cache, on every single view. Deriving and scoring
     * five times over was pure waste on top.
     *
     * The memo is an object property, so it lives exactly as long as the request.
     *
     * @param bool $refresh  bypass the cache (admin "refresh now")
     */
    public function viewModel(Coin $coin, bool $refresh = false): array
    {
        if (!$refresh && isset($this->memo[$coin->slug])) {
            return $this->memo[$coin->slug];
        }

        return $this->memo[$coin->slug] = $this->build($coin, $refresh);
    }

    /**
     * Refresh ONE dataset, for cron.
     *
     * Deliberately not viewModel(): warming the market price must not drag the
     * chart, DeFi, DEX and FX collectors along with it every two minutes. The
     * cache entry it fills is the same one a page render reads, so a visitor
     * arriving afterwards pays nothing for it.
     */
    public function warm(Coin $coin, string $dataset): void
    {
        // The warmed entry replaces anything memoized earlier in this process.
        unset($this->memo[$coin->slug]);

        /* A SINGLE chart window — "chart.90d". This is what the scheduler now
           queues, because each window has its own TTL and its own priority, and
           against a budget of a few requests a minute the unit of work has to be
           one request rather than five. */
        if (str_starts_with($dataset, Cache::CHART_PREFIX)) {
            $this->chartWindow($coin, $dataset);
            return;
        }

        /* Every chart window at once. Kept for the "refresh now" path and for
           callers that genuinely want the whole chart; the scheduler no longer
           uses it. Each window still goes through the budget individually, so a
           provider that refuses partway simply leaves the rest for a later
           tick. */
        if ($dataset === 'chart') {
            foreach (array_keys($this->config['chart']['windows'] ?? []) as $period) {
                $this->chartWindow($coin, Cache::CHART_PREFIX . $period);
            }
            return;
        }

        $providerId = self::DATASETS[$dataset] ?? self::V2_DATASETS[$dataset] ?? null;
        if ($providerId === null) {
            return;
        }

        if ($dataset === 'development') {
            $coin = $this->withRepository($coin);
        }

        // Through the cache, NOT around it. A tick that arrives while the entry
        // is still fresh should cost nothing, and a fetch that fails must fall
        // back to the stale copy rather than having already deleted it.
        $this->dataset($coin, $dataset, $providerId);
    }

    /**
     * Refresh market data for many coins in ONE request.
     *
     * Writes each coin's own cache entry, in the same shape and under the same
     * key a single fetch would have used — so a page render cannot tell the
     * difference, and nothing downstream needed changing to support this.
     *
     * A coin the batch did not return is left alone rather than marked as a
     * miss: its existing entry, however stale, is better than recording an
     * absence that was really just an omission.
     *
     * @param Coin[] $coins
     * @return int coins whose cache entry was refreshed
     */
    public function warmBatch(array $coins, string $dataset = 'market'): int
    {
        $collector = $this->collectors['coingecko'] ?? null;
        if (!$collector instanceof Collectors\CoinGecko || !$collector->isEnabled() || $coins === []) {
            return 0;
        }

        $eligible = array_values(array_filter(
            $coins,
            fn(Coin $c): bool => $c->usesProvider('coingecko', $this->config)
        ));
        if ($eligible === []) {
            return 0;
        }

        try {
            $rows = $collector->markets($eligible);
        } catch (\Throwable $e) {
            /* The batch's failure mode is wider than a single fetch's: one
               refusal costs every coin in it. Stale-while-error still covers
               them — each keeps its own last good entry — but the blast radius
               is real and worth saying out loud in the log. */
            error_log(sprintf(
                '[thb] batched %s failed for %d coins: %s',
                $dataset,
                count($eligible),
                $e->getMessage()
            ));
            return 0;
        }

        $written = 0;
        foreach ($eligible as $coin) {
            $row = $rows[$coin->coingeckoId] ?? null;
            if (!is_array($row) || ($row['price'] ?? null) === null) {
                continue;   // omitted by the provider; keep whatever we hold
            }

            $this->cache->put($dataset, $this->cache->keyFor($dataset, $coin), $row);
            $this->history->record($coin, $dataset, 'coingecko', $row);
            unset($this->memo[$coin->slug]);
            $written++;
        }

        return $written;
    }

    /**
     * Refresh the live price for every coin — one request.
     *
     * Merged over the previous entry, so a coin CoinGecko omitted this time
     * keeps its last price (and its older timestamp says so) instead of
     * disappearing from the display.
     *
     * @param Coin[] $coins
     * @return int coins written
     */
    public function warmTicker(array $coins): int
    {
        $collector = $this->collectors['coingecko'] ?? null;
        $eligible = array_values(array_filter(
            $coins,
            fn(Coin $c): bool => $c->usesProvider('coingecko', $this->config)
        ));
        if (!$collector instanceof Collectors\CoinGecko || !$collector->isEnabled() || $eligible === []) {
            return 0;
        }

        try {
            $rows = $collector->ticker($eligible);
        } catch (\Throwable $e) {
            error_log('[thb] live price refresh failed: ' . $e->getMessage());
            return 0;
        }
        if ($rows === []) {
            return 0;
        }

        $key = $this->cache->keyFor('ticker');
        $previous = $this->cache->peek('ticker', $key);
        $prices = is_array($previous['data']['prices'] ?? null) ? $previous['data']['prices'] : [];
        $now = time();
        foreach ($rows as $id => $row) {
            $prices[$id] = $row + ['fetchedAt' => $now];
        }
        $this->cache->put('ticker', $key, ['prices' => $prices]);

        return count($rows);
    }

    /** Refresh one chart window through the cache. */
    private function chartWindow(Coin $coin, string $dataset): void
    {
        $collector = $this->collectors['coingecko'] ?? null;
        if (!$collector || !$collector->isEnabled() || !$coin->usesProvider('coingecko', $this->config)) {
            return;
        }
        $this->cache->remember(
            $dataset,
            $this->cache->keyFor($dataset, $coin),
            static fn() => $collector->fetch($coin, $dataset)
        );
    }

    private function build(Coin $coin, bool $refresh): array
    {
        if ($refresh) {
            // Scope-aware: a coin refresh must not evict site-wide entries that
            // every other coin is also reading.
            $this->cache->forgetCoinScoped($coin);
        }

        $raw = [];
        foreach (self::DATASETS as $dataset => $providerId) {
            $raw[$dataset] = $this->dataset($coin, $dataset, $providerId, renderPass: true);
        }

        // The chart is not one dataset but five, scheduled separately.
        $raw['chart'] = ['data' => $this->chartSeries($coin), 'fetchedAt' => 0, 'stale' => false, 'source' => 'windows'];

        $model = $this->normalize($coin, $raw);

        // Manual corrections are applied for PRESENTATION only. The raw payloads
        // above and the history rows below are untouched.
        $model = Overrides::apply($model, $coin);

        /* Context is built BEFORE derived/analytics and stored under its own
           key. Scoring reads $model['derived'] and nothing else, so no Group A
           metric can reach a weight — the score stays exactly where it was. */
        $model['context']    = Context::build($model);
        $model['categories'] = ($model['categories'] ?? []) + ['matched' => Context::categories($model)];

        $model['derived']  = Derive::metrics($model);
        $model['analytics'] = Scoring::score($model['derived']);
        $model['insight']   = Scoring::insight($model['analytics']);
        $model['sections']  = Derive::sectionVisibility($model, $model['derived']);

        return $model;
    }

    /**
     * Fetch one dataset through the cache, and archive it.
     *
     * @return array{data:mixed, fetchedAt:int, stale:bool, source:string}
     */
    private function dataset(Coin $coin, string $dataset, string $providerId, bool $renderPass = false): array
    {
        $collector = $this->collectors[$providerId] ?? null;
        $sets = new Datasets($this->config);

        if (!$collector || !$collector->isEnabled() || !$coin->usesProvider($providerId, $this->config)
            || !$sets->isEnabled($dataset)) {
            return ['data' => null, 'fetchedAt' => 0, 'stale' => false, 'source' => 'disabled'];
        }

        /* Bitcoin has no validators and no gas market. That is not a missing
           figure or a failed provider, and the page must not say "not yet
           received" for something that will never arrive — so a provider that
           does not serve this coin's chain reports NOT APPLICABLE, and is never
           asked. */
        if (!$sets->servesChain($providerId, $coin)) {
            return ['data' => null, 'fetchedAt' => 0, 'stale' => false, 'source' => 'not_applicable'];
        }

        /* CACHE-ONLY DATASETS.
         *
         * Six Group A datasets fetched in series during a page render would be
         * six provider calls a visitor waits for — and against CoinGecko's
         * measured four-a-minute ceiling, the first would succeed and the rest
         * would earn a 429, turning one cold page view into a site-wide
         * cooldown. So a render reads what the scheduler left and never fetches.
         *
         * A miss is reported as `pending` rather than as a failure, because
         * "cron has not reached this yet" and "the provider refused" are
         * different things and the page says different things about them. */
        if ($renderPass && $sets->rendersFromCacheOnly($dataset)) {
            $entry = $this->cache->peek($dataset, $this->cache->keyFor($dataset, $coin));

            if (!is_array($entry) || !is_array($entry['data'] ?? null)) {
                return [
                    'data'      => null,
                    'fetchedAt' => 0,
                    'stale'     => false,
                    'source'    => is_array($entry) && !empty($entry['miss']) ? 'unavailable' : 'pending',
                ];
            }

            $age = time() - (int) ($entry['fetchedAt'] ?? 0);
            return [
                'data'      => $entry['data'],
                'fetchedAt' => (int) ($entry['fetchedAt'] ?? 0),
                'stale'     => $age >= $this->cache->ttl($dataset),
                'source'    => 'cache',
            ];
        }

        $result = $this->cache->remember(
            $dataset,
            $this->cache->keyFor($dataset, $coin),
            static fn() => $collector->fetch($coin, $dataset),
            $dataset === 'chart' ? self::mergeChartWindows(...) : null
        );

        /* Archive only fresh, real payloads. History::record() enforces its own
           (much longer) interval, so this is cheap to call on every request.
           SITE-SCOPED datasets are excluded: history is keyed by coin, so
           recording one would write the same global figures once per coin and
           then read them back as if they were that coin's own. */
        if ($result['data'] !== null && !$result['stale']
            && $sets->scope($dataset) !== Datasets::SCOPE_SITE) {
            $this->history->record($coin, $dataset, $providerId, (array) $result['data']);
        }

        return $result;
    }

    /**
     * Assemble the five chart windows.
     *
     * THE PROBLEM THIS SOLVES. The five periods are five separate CoinGecko
     * calls. They used to share one cache entry with one five-minute TTL, so
     * every refresh fired all five at once — a burst against a per-IP allowance
     * that, on shared hosting, is shared with every other site on the box. When
     * the allowance is smaller than the burst, the calls at the END are refused.
     * And because the order was fixed, it was the SAME windows every time: 90d
     * and 1y were not intermittently missing, they could never arrive at all.
     *
     * Two changes make that impossible:
     *
     *   1. Each window is cached separately, with a TTL equal to the resolution
     *      CoinGecko samples it at. Re-requesting a daily-sampled 1-year series
     *      every five minutes cannot return anything new; those calls were pure
     *      loss, and they were spending the budget 90d needed.
     *
     *   2. Windows are refreshed MOST-STALE-FIRST, and only a couple per
     *      request. A window that has never been fetched has infinite staleness,
     *      so it sorts ahead of everything — which is precisely the property the
     *      old fixed order lacked. A missing period is now first in line rather
     *      than last, so it arrives within a page view or two and then settles
     *      into its own refresh rhythm.
     *
     * A window that is stale but could not be refreshed keeps its previous
     * value, so the chart never loses a period it already had.
     */
    private function chartSeries(Coin $coin): ?array
    {
        $collector = $this->collectors['coingecko'] ?? null;
        if (!$collector || !$collector->isEnabled() || !$coin->usesProvider('coingecko', $this->config)) {
            return null;
        }

        $windows = $this->config['chart']['windows'] ?? [];
        $budget = (int) ($this->config['chart']['fetches_per_request'] ?? 2);

        // What we hold, and how overdue each window is. Never-fetched sorts
        // first; after that, whichever is furthest past its own TTL.
        $state = [];
        foreach (array_keys($windows) as $period) {
            $dataset = Cache::CHART_PREFIX . $period;
            $entry = $this->cache->peek($dataset, $this->cache->keyFor($dataset, $coin));
            $ttl = $this->cache->ttl($dataset);
            $age = is_array($entry) ? time() - (int) ($entry['fetchedAt'] ?? 0) : PHP_INT_MAX;
            $has = is_array($entry) && is_array($entry['data'] ?? null);

            $state[$period] = [
                'data'     => $has ? $entry['data'] : null,
                'stale'    => $age >= $ttl,
                // Overdue by how many multiples of its own TTL — so a window is
                // compared against its own cadence, not against a faster one.
                'priority' => $has ? $age / max(1, $ttl) : PHP_INT_MAX,
            ];
        }

        $order = array_keys($state);
        usort($order, static fn(string $a, string $b) => $state[$b]['priority'] <=> $state[$a]['priority']);

        foreach ($order as $period) {
            if ($budget <= 0) {
                break;
            }
            if (!$state[$period]['stale']) {
                continue;
            }

            $budget--;
            $dataset = Cache::CHART_PREFIX . $period;
            $result = $this->cache->remember(
                $dataset,
                $this->cache->keyFor($dataset, $coin),
                static fn() => $collector->fetch($coin, $dataset)
            );

            if (is_array($result['data'] ?? null)) {
                $state[$period]['data'] = $result['data'];
            }
        }

        // Emitted in the configured order, not the refresh order, so the chart
        // engine always sees 24h first.
        $series = [];
        foreach (array_keys($windows) as $period) {
            if (is_array($state[$period]['data'])) {
                $series[$period] = $state[$period]['data'];
            }
        }

        return $series ?: null;
    }

    /**
     * Keep chart windows a partial refresh did not return.
     *
     * The five periods are five independent HTTP calls, so a rate limit can
     * hand back 24h and 7d while refusing 90d and 1y. Overwriting wholesale
     * would then delete two periods the page had a moment ago and leave their
     * buttons drawing nothing. Fresh windows win; missing ones fall back.
     *
     * @param array $fresh     windows just fetched
     * @param array $previous  windows from the last good fetch
     */
    private static function mergeChartWindows(array $fresh, array $previous): array
    {
        return $fresh + $previous;
    }

    /**
     * Raw provider payloads -> the normalized view model.
     *
     * This is the only place that knows both sides. Nothing above it sees a
     * provider's field names; nothing below it sees ours.
     */
    private function normalize(Coin $coin, array $raw): array
    {
        $market   = $raw['market']['data'] ?? [];
        $meta     = $raw['metadata']['data'] ?? [];
        $hist     = $raw['historical']['data'] ?? [];
        $fx       = $raw['fx']['data'] ?? null;

        // ACF beats the provider for editorial fields: a Persian description is
        // ours, and CoinGecko's English blurb is only a fallback.
        $description = $coin->meta('description') ?: ($meta['description'] ?? null);

        $model = [
            'schemaVersion' => 2,
            'meta' => [
                'generatedAt' => gmdate('c'),
                'isFixture'   => false,
                'staleness'   => $this->staleness($raw),
            ],
            'currency' => [
                'usdToToman' => $fx['usdToToman'] ?? null,
                'fx'         => $fx,   // source, timestamp, freshness — shown in the UI
            ],
            'coin' => [
                'id'               => $coin->slug,
                'symbol'           => $coin->symbol,
                'name'             => $coin->name,
                'nameEn'           => $coin->nameEn ?: ($meta['nameEn'] ?? ''),
                'logo'             => get_the_post_thumbnail_url($coin->postId, 'thumbnail') ?: ($meta['image'] ?? null),
                'rank'             => $market['rank'] ?? null,
                'tags'             => self::splitTags($coin->meta('tags')),
                /* The join keys for the two site-wide datasets. `categories`
                   comes free from the coin-detail response; `chain` is the
                   DefiLlama name already configured per coin. Both live on the
                   coin so Context can match without knowing about ACF. */
                'categories'       => (array) ($meta['categories'] ?? []),
                'chain'            => (string) $coin->meta('defillamaChain', ''),
                'coingeckoId'      => $coin->coingeckoId,
                'newsCategorySlug' => $coin->newsCategorySlug,
                'newsCategoryUrl'  => $coin->newsCategoryUrl(),
                'links' => [
                    'website'    => $coin->meta('website') ?: ($meta['homepage'] ?? null),
                    'whitepaper' => $coin->meta('whitepaper'),
                    'explorer'   => $coin->meta('explorer') ?: ($meta['explorer'] ?? null),
                    'social'     => array_filter([
                        'twitter'  => $coin->meta('social.twitter') ?: ($meta['twitter'] ?? null),
                        'github'   => $coin->meta('social.github') ?: ($meta['github'] ?? null),
                        'discord'  => $coin->meta('social.discord'),
                        'telegram' => $coin->meta('social.telegram'),
                    ]),
                ],
                'contracts' => self::contracts($coin, $meta),
            ],
            'market' => [
                'price'              => $market['price'] ?? null,
                'change24h'          => $market['change24h'] ?? null,
                'marketCap'          => $market['marketCap'] ?? null,
                'marketCapChange24h' => $market['marketCapChange24h'] ?? null,
                'fdv'                => $market['fdv'] ?? null,
                'volume24h'          => $market['volume24h'] ?? null,
                'volume30dAvg'       => $this->history->growth($coin->slug, 'market', 'volume24h') !== null
                    ? $this->averageFromHistory($coin, 'volume24h')
                    : null,
                'circulatingSupply'  => $market['circulatingSupply'] ?? null,
                'totalSupply'        => $market['totalSupply'] ?? null,
                'maxSupply'          => $market['maxSupply'] ?? null,
                'sparkline'          => $market['sparkline'] ?? null,
                'ath'                => $hist['ath'] ?? null,
                'atl'                => $hist['atl'] ?? null,
            ],
            'performance' => self::performance($market, $raw['chart']['data'] ?? null),
            'about' => [
                'description' => $description,
                'launchDate'  => $coin->meta('launchDate') ?: ($meta['genesisDate'] ?? null),
                'founder'     => $coin->meta('founder'),
                'consensus'   => $coin->meta('consensus'),
                'website'     => $coin->meta('website') ?: ($meta['homepage'] ?? null),
            ],
            'series'    => $raw['chart']['data'] ?? null,
            'benchmark' => $this->benchmark($coin),
            /* Group A. Each keeps its availability state rather than collapsing
               to null, so the page can tell "cron has not reached this yet"
               apart from "the provider has nothing" — see State::of(). */
            'globalMarket' => self::state($raw['global'], 'CoinGecko'),
            'sentiment'    => self::state($raw['sentiment'], 'Alternative.me'),
            'chains'       => self::state($raw['chains'], 'DefiLlama'),
            'stablecoins'  => self::state($raw['stablecoins'], 'DefiLlama'),
            'bridges'      => self::state($raw['bridges'], 'DefiLlama'),
            'structure'    => self::state($raw['structure'], 'CoinGecko'),
            'categories'   => self::state($raw['categories'], 'CoinGecko'),

            /* Group B — on-chain and network. Same five-state contract. */
            'network'      => self::state($raw['network'], 'Blockchair'),
            'gas'          => self::state($raw['gas'], 'Etherscan'),
            'supplyChain'  => self::state($raw['supply'], 'Etherscan'),
            'staking'      => self::state($raw['staking'], 'beaconcha.in'),
            'development'  => self::state($raw['development'], 'GitHub'),

            'defi'    => self::withSource($raw['defi'], 'DefiLlama', 'defillama', $coin, $this->history),
            'onchain' => self::withSource($raw['onchain'], 'Dune / Alchemy', 'dune', $coin, $this->history),
            'dex'     => self::withSource($raw['dex'], 'DexScreener', 'dexscreener', $coin, $this->history),
            'l2'      => self::withSource($raw['l2'], 'L2BEAT', 'l2beat', $coin, $this->history),
            'news'    => $this->news->forCoin($coin),
        ];

        return $model;
    }

    /**
     * The benchmark asset's performance, READ FROM CACHE ONLY.
     *
     * "How did this coin do against Bitcoin" needs Bitcoin's numbers, and
     * Bitcoin is already a configured coin whose market dataset the scheduler
     * warms on the same five-minute cadence as every other. So this is a cache
     * read, never a fetch: peek() is used precisely so that rendering one coin
     * can never trigger a provider call for another. If the benchmark is not
     * cached the metric reports unavailable, which is correct and costs nothing.
     *
     * The benchmark is configuration, not a hard-coded coin — the comparison
     * asset is named in config/providers.php and nothing here knows about
     * Bitcoin specifically.
     */
    private function benchmark(Coin $coin): ?array
    {
        $spec = $this->config['benchmark'] ?? null;
        if (!$spec || $coin->slug === ($spec['slug'] ?? null)) {
            return null;   // a coin is not its own benchmark
        }

        $key = sprintf('market:%s:%s', $spec['slug'], $spec['coingeckoId']);
        $entry = $this->cache->peek('market', $key);
        $data = $entry['data'] ?? null;

        if (!is_array($data) || empty($data['performance'])) {
            return null;
        }

        return [
            'symbol'      => (string) ($spec['symbol'] ?? ''),
            'name'        => (string) ($spec['name'] ?? ''),
            'performance' => $data['performance'],
        ];
    }

    /**
     * The performance list, with 90d filled from the chart.
     *
     * CoinGecko has no 90-day field. The collector used to return its 60-day
     * figure under the '90d' key, so the page displayed a two-month change
     * labelled as three months. The cached 90-day price series is the actual
     * answer and we already hold it, so the honest figure costs nothing.
     *
     * When that window has not been fetched yet the key is simply absent and
     * the row renders an em dash, which is the truthful state.
     */
    private static function performance(array $market, ?array $series): array
    {
        $perf = (array) ($market['performance'] ?? []);

        $prices = $series['90d']['price'] ?? null;
        if (is_array($prices) && count($prices) > 1) {
            $first = (float) $prices[0];
            $last  = (float) $prices[count($prices) - 1];
            if ($first > 0.0) {
                $perf['90d'] = (($last - $first) / $first) * 100;
            }
        }

        // Keep the approved row order regardless of which keys were filled.
        $ordered = [];
        foreach (['1h', '24h', '7d', '30d', '90d', '1y'] as $key) {
            $ordered[$key] = $perf[$key] ?? null;
        }
        return $ordered;
    }

    /**
     * The coin, with its GitHub repository filled in from cached metadata.
     *
     * The repository is not guessed from the slug — github.com/bitcoin/bitcoin
     * and github.com/ethereum/go-ethereum follow no rule. An editor's ACF value
     * wins; otherwise the link CoinGecko already returned in the coin-detail
     * response is used. Read with peek(), so warming developer activity never
     * triggers a CoinGecko request of its own.
     */
    private function withRepository(Coin $coin): Coin
    {
        if ((string) $coin->meta('githubRepo', '') !== '' || (string) $coin->meta('social.github', '') !== '') {
            return $coin;
        }

        $meta = $this->cache->peek('metadata', $this->cache->keyFor('metadata', $coin))['data'] ?? null;
        $github = is_array($meta) ? (string) ($meta['github'] ?? '') : '';
        if ($github === '') {
            return $coin;
        }

        return new Coin(
            postId: $coin->postId, slug: $coin->slug, symbol: $coin->symbol,
            name: $coin->name, nameEn: $coin->nameEn, coingeckoId: $coin->coingeckoId,
            newsCategorySlug: $coin->newsCategorySlug,
            meta: $coin->meta + ['githubRepo' => $github],
            overrides: $coin->overrides, flags: $coin->flags
        );
    }

    /**
     * A dataset plus WHY it has no data, when it has none.
     *
     * The requirement this exists for: never show a fake zero, and never let
     * four different absences look like one. A section that renders "0" when the
     * truth is "cron has not fetched this yet" is worse than an empty section,
     * because it is a claim.
     *
     *   ok           real data, fresh
     *   stale        real data, past its TTL, still worth showing
     *   pending      never fetched — the scheduler has not reached it
     *   unavailable  fetched and the provider had nothing, or refused
     *   disabled     switched off in configuration; not an error at all
     *
     * The distinction is carried all the way to the template, which prints
     * "در حال دریافت داده" for pending and "داده فعلاً در دسترس نیست" for
     * unavailable.
     */
    private static function state(array $entry, string $label): array
    {
        $data = $entry['data'] ?? null;
        $source = (string) ($entry['source'] ?? '');

        if (!is_array($data)) {
            return [
                'available' => false,
                'state'     => match ($source) {
                    'pending'        => 'pending',
                    'disabled'       => 'disabled',
                    'not_applicable' => 'not_applicable',
                    default          => 'unavailable',
                },
                'data'      => null,
                'source'    => $label,
                'fetchedAt' => null,
            ];
        }

        return [
            'available' => true,
            'state'     => !empty($entry['stale']) ? 'stale' : 'ok',
            'data'      => $data,
            'source'    => $label,
            'fetchedAt' => ($entry['fetchedAt'] ?? 0) ? gmdate('c', (int) $entry['fetchedAt']) : null,
        ];
    }

    /**
     * Attach provenance, and fill growth inputs from OUR history rather than a
     * provider — the reason History exists.
     */
    private static function withSource(array $entry, string $label, string $providerId, Coin $coin, History $history): ?array
    {
        $data = $entry['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        $data['source'] = $label;
        $data['provider'] = $providerId;
        $data['stale'] = (bool) ($entry['stale'] ?? false);

        if ($providerId === 'defillama' && isset($data['tvl'])) {
            $data['tvl30dAgo'] = self::valueFromHistory($history, $coin, 'defi', 'tvl');
        }
        if ($providerId === 'dune' && isset($data['activeAddresses24h'])) {
            $data['activeAddresses30dAgo'] = self::valueFromHistory($history, $coin, 'onchain', 'activeAddresses24h');
        }

        return $data;
    }

    /** Oldest snapshot in the 30-day window, i.e. the "then" of a growth figure. */
    private static function valueFromHistory(History $history, Coin $coin, string $dataset, string $path): ?float
    {
        $series = $history->series($coin->slug, $dataset, '-30 days');
        if ($series === []) {
            return null; // no history yet — the metric reports unavailable, not zero
        }
        $value = Derive::dig($series[0]['payload'], $path);
        return is_numeric($value) ? (float) $value : null;
    }

    private function averageFromHistory(Coin $coin, string $path): ?float
    {
        $series = $this->history->series($coin->slug, 'market', '-30 days');
        $values = [];
        foreach ($series as $row) {
            $v = Derive::dig($row['payload'], $path);
            if (is_numeric($v)) {
                $values[] = (float) $v;
            }
        }
        return $values === [] ? null : array_sum($values) / count($values);
    }

    /** Oldest fetch time across datasets, so the UI can say how fresh the page is. */
    private function staleness(array $raw): array
    {
        $oldest = null;
        $stale = [];
        foreach ($raw as $key => $entry) {
            if (!empty($entry['stale'])) {
                $stale[] = $key;
            }
            $t = (int) ($entry['fetchedAt'] ?? 0);
            if ($t > 0 && ($oldest === null || $t < $oldest)) {
                $oldest = $t;
            }
        }
        return [
            'oldestFetchedAt' => $oldest ? gmdate('c', $oldest) : null,
            'staleDatasets'   => $stale,
        ];
    }

    private static function contracts(Coin $coin, array $meta): array
    {
        // An explicit ACF address wins; otherwise take what CoinGecko listed.
        $address = $coin->meta('contractAddress');
        if ($address) {
            return [[
                'network'   => $coin->meta('contractNetwork', ''),
                'networkFa' => $coin->meta('contractNetworkFa', ''),
                'address'   => $address,
            ]];
        }
        return array_slice((array) ($meta['contracts'] ?? []), 0, 1);
    }

    private static function splitTags(mixed $tags): array
    {
        if (is_array($tags)) {
            return $tags;
        }
        if (!is_string($tags) || $tags === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $tags))));
    }
}
