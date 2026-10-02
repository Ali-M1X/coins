<?php
/**
 * CoinGecko — price, market cap, volume, supply, chart series, metadata, ATH/ATL.
 *
 * Keyed by the coin's STORED CoinGecko id, never by its WordPress slug. They
 * match for Ethereum and Bitcoin and diverge constantly elsewhere
 * ("binancecoin", "polygon-ecosystem-token"), so the id is configuration.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\Collectors;

use TheHybit\Coins\Coin;

defined('ABSPATH') || exit;

final class CoinGecko extends Collector
{
    public function id(): string { return 'coingecko'; }

    public function datasets(): array
    {
        return ['market', 'chart', 'metadata', 'historical', 'global', 'categories', 'structure'];
    }

    public function fetch(Coin $coin, string $dataset): ?array
    {
        // "chart.90d" — one window, one call, one cache entry. The whole-chart
        // form is kept for callers that still ask for every period at once.
        if (str_starts_with($dataset, 'chart.')) {
            return $this->chartWindow($coin, substr($dataset, 6));
        }

        return match ($dataset) {
            'market'     => $this->market($coin),
            'chart'      => $this->chart($coin),
            'metadata'   => $this->metadata($coin),
            'historical' => $this->historical($coin),
            'global'     => $this->globalMarket(),
            'categories' => $this->categories(),
            'structure'  => $this->structure($coin),
            default      => null,
        };
    }

    /* ------------------------------------------------------------------
     * Site-wide market context
     * ---------------------------------------------------------------- */

    /**
     * Total market cap, volume and dominance — ONE request for the whole site.
     *
     * Nothing here varies by coin, so nothing here is keyed by one. The
     * dataset registry scopes it to `site`, which is what keeps it a single
     * request whether the installation has two coins or two hundred.
     *
     * Dominance arrives as a map of every asset CoinGecko tracks; only the two
     * the page shows are kept, and each independently, so a response that omits
     * ETH still yields BTC rather than nothing.
     */
    private function globalMarket(): ?array
    {
        $d = $this->get('/global')['data'] ?? null;
        if (!is_array($d)) {
            return null;
        }

        $dominance = (array) ($d['market_cap_percentage'] ?? []);

        $out = [
            'totalMarketCap'    => self::num($d, 'total_market_cap.usd'),
            'totalVolume24h'    => self::num($d, 'total_volume.usd'),
            'marketCapChange24h' => isset($d['market_cap_change_percentage_24h_usd'])
                && is_numeric($d['market_cap_change_percentage_24h_usd'])
                ? (float) $d['market_cap_change_percentage_24h_usd']
                : null,
            'btcDominance'      => isset($dominance['btc']) && is_numeric($dominance['btc'])
                ? (float) $dominance['btc'] : null,
            'ethDominance'      => isset($dominance['eth']) && is_numeric($dominance['eth'])
                ? (float) $dominance['eth'] : null,
            'activeCoins'       => isset($d['active_cryptocurrencies']) && is_numeric($d['active_cryptocurrencies'])
                ? (int) $d['active_cryptocurrencies'] : null,
            'markets'           => isset($d['markets']) && is_numeric($d['markets'])
                ? (int) $d['markets'] : null,
        ];

        // A response that carried no usable figure at all is a failure, not an
        // empty success — otherwise it would be cached for the full TTL.
        return self::anyValue($out) ? $out : null;
    }

    /**
     * Every category, once, site-wide.
     *
     * The endpoint returns the COMPLETE list — around 300 entries — so asking
     * it per coin would be three hundred copies of the same document. It is
     * fetched once and each coin's categories are matched against it locally,
     * in Derive.
     *
     * Only the fields the response actually carries are kept. There is no rank
     * field, so none is invented: position within this list is a function of
     * whatever order CoinGecko returned, not a published ranking.
     */
    private function categories(): ?array
    {
        $rows = $this->get('/coins/categories');
        if (!is_array($rows) || $rows === []) {
            return null;
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['name'])) {
                continue;
            }
            $marketCap = self::num($row, 'market_cap');
            if ($marketCap === null) {
                continue;   // a category with no market cap tells the page nothing
            }

            $out[] = [
                'id'          => (string) ($row['id'] ?? ''),
                'name'        => (string) $row['name'],
                'marketCap'   => $marketCap,
                'change24h'   => self::num($row, 'market_cap_change_24h'),
                'volume24h'   => self::num($row, 'volume_24h'),
            ];
        }

        if ($out === []) {
            return null;
        }

        // Sorted by size so "largest category" is answerable without the page
        // re-sorting three hundred rows on every render.
        usort($out, static fn(array $a, array $b): int => $b['marketCap'] <=> $a['marketCap']);

        /* Capped. The full list is ~300 entries and only the ones a coin
           belongs to, plus a little context, are ever displayed — while the
           whole thing would be serialized into every cache read. A persistent
           object cache with a 1MB item ceiling fails SILENTLY above it, which
           would look like a provider problem rather than a payload problem. */
        return ['categories' => array_slice($out, 0, 120), 'total' => count($out)];
    }

    /* ------------------------------------------------------------------
     * CEX market structure — NO ADDITIONAL REQUEST
     * ---------------------------------------------------------------- */

    /**
     * Where this coin actually trades, derived from the coin-detail response.
     *
     * THIS ADDS NO REQUEST. /coins/{id} already runs for metadata and
     * historical; it simply now asks for tickers as well, and coinEndpoint()
     * memoizes the response for the request, so warming all three in one tick
     * costs one call between them. Their TTLs match for that reason.
     *
     * WHAT IS NOT CACHED MATTERS AS MUCH. The ticker array is large — hundreds
     * of rows, easily over a megabyte of JSON for a major asset — and it is
     * reduced to a handful of figures HERE, before anything is stored. Caching
     * the raw array would push entries past the 1MB item ceiling a Memcached
     * object cache imposes, where set_transient() fails silently and every page
     * render re-fetches everything.
     *
     * HONESTY ABOUT THE SAMPLE. The endpoint returns a page of tickers, not
     * every market in existence, so `markets` is reported as the number of
     * markets SEEN and concentration is explicitly the share within that
     * sample. Calling it "total markets" would be a fabrication.
     */
    private function structure(Coin $coin): ?array
    {
        $d = $this->coinEndpoint($coin, tickers: true);
        $tickers = $d['tickers'] ?? null;

        if (!is_array($tickers) || $tickers === []) {
            return null;
        }

        $byVenue = [];
        $total = 0.0;
        $best = null;
        $spreads = [];
        $trusted = 0;
        $counted = 0;

        foreach ($tickers as $t) {
            if (!is_array($t)) {
                continue;
            }
            $venue = (string) ($t['market']['name'] ?? '');
            $volume = self::num($t, 'converted_volume.usd');

            // A ticker with no venue or no volume cannot inform any of the
            // figures below; counting it would only dilute them.
            if ($venue === '' || $volume === null || $volume <= 0.0) {
                continue;
            }

            $counted++;
            $total += $volume;
            $byVenue[$venue] = ($byVenue[$venue] ?? 0.0) + $volume;

            if ($best === null || $volume > $best['volume']) {
                $best = [
                    'venue'  => $venue,
                    'pair'   => trim((string) ($t['base'] ?? '') . '/' . (string) ($t['target'] ?? ''), '/'),
                    'volume' => $volume,
                ];
            }

            $spread = self::num($t, 'bid_ask_spread_percentage');
            if ($spread !== null && $spread >= 0.0 && $spread < 100.0) {
                $spreads[] = $spread;
            }
            if (($t['trust_score'] ?? null) === 'green') {
                $trusted++;
            }
        }

        if ($counted === 0 || $total <= 0.0) {
            return null;
        }

        arsort($byVenue);
        $topVenues = [];
        foreach (array_slice($byVenue, 0, 5, true) as $venue => $volume) {
            $topVenues[] = [
                'venue'  => $venue,
                'volume' => $volume,
                'share'  => ($volume / $total) * 100,
            ];
        }

        return [
            'marketsSeen'    => $counted,
            'venues'         => count($byVenue),
            'volumeSeen'     => $total,
            'topVenues'      => $topVenues,
            'topVenue'       => $topVenues[0]['venue'] ?? null,
            'topVenueShare'  => $topVenues[0]['share'] ?? null,
            'topPair'        => $best['pair'] ?? null,
            'topPairVenue'   => $best['venue'] ?? null,
            /* Herfindahl over venue shares, 0–100. One venue holding
               everything scores 100; perfectly even venues approach zero. It
               needs several venues to mean anything, so it is withheld below
               three rather than reported as a certainty drawn from one. */
            'concentration'  => count($byVenue) >= 3 ? self::herfindahl($byVenue, $total) : null,
            'medianSpread'   => self::median($spreads),
            'trustedShare'   => ($trusted / $counted) * 100,
        ];
    }

    /** Sum of squared shares, expressed 0–100. */
    private static function herfindahl(array $byVenue, float $total): float
    {
        $sum = 0.0;
        foreach ($byVenue as $volume) {
            $share = $volume / $total;
            $sum += $share * $share;
        }
        return $sum * 100;
    }

    /** @param float[] $values */
    private static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $mid = intdiv(count($values), 2);
        return count($values) % 2 === 0
            ? ($values[$mid - 1] + $values[$mid]) / 2
            : $values[$mid];
    }

    private static function anyValue(array $row): bool
    {
        foreach ($row as $v) {
            if ($v !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Market data for MANY coins in one request.
     *
     * The single largest lever available at scale. /coins/markets accepts a
     * comma-separated id list and returns price, market cap, FDV, volume,
     * supply, ATH/ATL and the 7-day sparkline for every coin in it — so a
     * hundred coins cost two requests instead of a hundred. Against a measured
     * ceiling of five requests a minute, that is the difference between viable
     * and impossible.
     *
     * The shape it returns is deliberately identical to market(), because the
     * cache entries it fills are the same ones a page render reads. Nothing
     * downstream can tell whether a coin's market data arrived alone or in a
     * batch — which is what makes this safe to add without touching the page.
     *
     * @param Coin[] $coins
     * @return array<string, array> keyed by CoinGecko id; absent coins simply
     *                              did not come back and are left to the caller
     */
    public function markets(array $coins): array
    {
        $ids = [];
        foreach ($coins as $coin) {
            if ($coin->coingeckoId !== '') {
                $ids[$coin->coingeckoId] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        $rows = $this->get('/coins/markets', [
            'vs_currency'             => 'usd',
            'ids'                     => implode(',', array_keys($ids)),
            'sparkline'               => 'true',
            'price_change_percentage' => '1h,24h,7d,30d,1y',
            'per_page'                => (string) min(250, count($ids)),
            'page'                    => '1',
        ]);

        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['id'])) {
                continue;
            }
            $out[(string) $row['id']] = self::fromMarketRow($row);
        }
        return $out;
    }

    /**
     * One /coins/markets row -> the same shape market() produces.
     *
     * The field names differ from /coins/{id} — the batch endpoint flattens
     * everything to the vs_currency rather than nesting by currency — so this is
     * the only place that knows about the difference.
     */
    private static function fromMarketRow(array $r): array
    {
        $num = static fn(string $key): ?float =>
            isset($r[$key]) && is_numeric($r[$key]) ? (float) $r[$key] : null;

        return [
            'price'              => $num('current_price'),
            'change24h'          => $num('price_change_percentage_24h'),
            'marketCap'          => $num('market_cap'),
            'marketCapChange24h' => $num('market_cap_change_percentage_24h'),
            'fdv'                => $num('fully_diluted_valuation'),
            'volume24h'          => $num('total_volume'),
            'circulatingSupply'  => $num('circulating_supply'),
            'totalSupply'        => $num('total_supply'),
            'maxSupply'          => $num('max_supply'),   // null = uncapped
            'rank'               => isset($r['market_cap_rank']) ? (int) $r['market_cap_rank'] : null,
            'sparkline'          => $r['sparkline_in_7d']['price'] ?? null,
            'performance'        => [
                '1h'  => $num('price_change_percentage_1h_in_currency'),
                '24h' => $num('price_change_percentage_24h_in_currency') ?? $num('price_change_percentage_24h'),
                '7d'  => $num('price_change_percentage_7d_in_currency'),
                '30d' => $num('price_change_percentage_30d_in_currency'),
                '1y'  => $num('price_change_percentage_1y_in_currency'),
                // 90d is absent here too; Pipeline fills it from chart.90d.
            ],
        ];
    }

    /** Per-request memo of /coins/{id}, keyed by CoinGecko id. */
    private array $coinMemo = [];

    /**
     * One /coins/{id} call covers price, caps, supply, ATH/ATL and every
     * performance window — cheaper against the rate limit than /simple/price
     * plus separate calls, and it is the same response the other datasets read.
     *
     * MEMOIZED FOR THE LIFETIME OF THE REQUEST. market(), metadata() and
     * historical() are three separate CACHE entries with three very different
     * TTLs (2 minutes, a day, a week), which is correct — but they are fed by
     * one identical HTTP call. Without this memo a cold page fetched the same
     * URL three times and spent three throttle waits doing it, which is a third
     * of the burst that gets the free tier to answer 429.
     */
    /**
     * The coin-detail response, fetched at most once per coin per request.
     *
     * $tickers asks the SAME endpoint to include its market list. It is not a
     * second request and must never become one — which is the whole reason the
     * memo is keyed by coin rather than by coin-and-options.
     *
     * Whoever asks first decides. If market structure is warmed in this tick,
     * the response carries tickers and metadata reads its fields from the same
     * document; if only metadata is due, no tickers are requested and structure
     * is not being warmed anyway. The one case the memo must not allow is a
     * ticker-less response being handed to structure(), so a memoized response
     * that lacks them is refetched with them — once, and only if something
     * genuinely needs them.
     */
    private function coinEndpoint(Coin $coin, bool $tickers = false): ?array
    {
        $id = $coin->coingeckoId;

        if (array_key_exists($id, $this->coinMemo)) {
            // A FAILURE is memoized too, and rethrown. Each of the datasets
            // must still see the error so Cache::remember() can serve its own
            // stale copy — but one 429 should cost one call, not three.
            if ($this->coinMemo[$id] instanceof \Throwable) {
                throw $this->coinMemo[$id];
            }

            $memo = $this->coinMemo[$id];
            if (!$tickers || is_array($memo['tickers'] ?? null)) {
                return $memo;
            }
            // Falls through to fetch once more, with tickers this time. Only
            // reachable if `structure` is disabled and something asked anyway.
        }

        /* Ask for tickers up front whenever market structure is a live dataset.
         *
         * Otherwise the order the scheduler happens to pick decides the cost:
         * metadata first would memoize a ticker-less response, and structure
         * would then have to fetch the same document again — two requests for
         * the thing that was specified as one. Deciding it here, from
         * configuration rather than from tick ordering, makes it one every time.
         *
         * The trade is a larger response body on the coin-detail call, once per
         * coin per day at its 24h TTL. The tickers are reduced to a dozen
         * figures in structure() and never cached, so the cost is bandwidth on
         * a daily request, not cache weight or an extra call. Turning the
         * `structure` dataset off in config restores the smaller response.
         */
        $wantTickers = $tickers || (new \TheHybit\Coins\Datasets($this->config))->isEnabled('structure');

        try {
            return $this->coinMemo[$id] = $this->get('/coins/' . rawurlencode($id), [
                'localization'   => 'false',
                'tickers'        => $wantTickers ? 'true' : 'false',
                'market_data'    => 'true',
                'community_data' => 'false',
                'developer_data' => 'false',
                'sparkline'      => 'true',
            ]);
        } catch (\Throwable $e) {
            $this->coinMemo[$id] = $e;
            throw $e;
        }
    }

    private function market(Coin $coin): ?array
    {
        $d = $this->coinEndpoint($coin);
        if (!$d || empty($d['market_data'])) {
            return null;
        }
        $m = $d['market_data'];

        return [
            'price'              => self::num($m, 'current_price.usd'),
            'change24h'          => self::num($m, 'price_change_percentage_24h'),
            'marketCap'          => self::num($m, 'market_cap.usd'),
            'marketCapChange24h' => self::num($m, 'market_cap_change_percentage_24h'),
            'fdv'                => self::num($m, 'fully_diluted_valuation.usd'),
            'volume24h'          => self::num($m, 'total_volume.usd'),
            'circulatingSupply'  => self::num($m, 'circulating_supply'),
            'totalSupply'        => self::num($m, 'total_supply'),
            'maxSupply'          => self::num($m, 'max_supply'), // null = uncapped, renders as infinity
            'rank'               => isset($d['market_cap_rank']) ? (int) $d['market_cap_rank'] : null,
            'sparkline'          => $m['sparkline_7d']['price'] ?? null,
            /* CoinGecko exposes 1h, 24h, 7d, 30d, 60d, 200d and 1y — but NOT
               90d. The 60d figure used to be returned under the '90d' key,
               so the page has been labelling a two-month change as three
               months. It is omitted here instead; Pipeline fills 90d from the
               cached chart.90d series, which is the real thing and costs
               nothing because we already hold it. */
            'performance'        => [
                '1h'  => self::num($m, 'price_change_percentage_1h_in_currency.usd'),
                '24h' => self::num($m, 'price_change_percentage_24h'),
                '7d'  => self::num($m, 'price_change_percentage_7d'),
                '30d' => self::num($m, 'price_change_percentage_30d'),
                '1y'  => self::num($m, 'price_change_percentage_1y'),
            ],
        ];
    }

    /** The five approved periods, and the /market_chart window each needs. */
    private const CHART_WINDOWS = ['24h' => 1, '7d' => 7, '30d' => 30, '90d' => 90, '1y' => 365];

    /**
     * Chart series for exactly the five approved periods.
     *
     * CoinGecko returns [timestamp, value] pairs; the UI wants parallel arrays,
     * which is also ~40% smaller as JSON. Volume is bucketed, price and market
     * cap are point samples.
     *
     * ONE FAILED WINDOW MUST NOT COST THE OTHER FOUR. Collector::get() THROWS on
     * 429, and five calls in a burst is exactly what provokes a 429 on the free
     * tier. Without the per-window catch below, that single throw escaped the
     * loop, Cache::remember() stored nothing, `series` came back null, and the
     * chart rendered completely blank while every other figure on the page —
     * fed by /coins/{id} — looked perfectly healthy.
     *
     * Windows are fetched shortest-first so that if the budget does run out, the
     * period the page opens on (24h) is the one that survives.
     */
    private function chart(Coin $coin): ?array
    {
        $series = [];
        $failed = [];

        foreach (self::CHART_WINDOWS as $period => $days) {
            try {
                $raw = $this->get('/coins/' . rawurlencode($coin->coingeckoId) . '/market_chart', [
                    'vs_currency' => 'usd',
                    'days'        => $days,
                    // 'interval' is omitted so CoinGecko picks its own granularity;
                    // forcing it is a paid feature on the free tier.
                ]);
            } catch (\Throwable $e) {
                $failed[] = $period . ' (' . $e->getMessage() . ')';
                continue;
            }

            if (!$raw || empty($raw['prices'])) {
                $failed[] = $period . ' (empty)';
                continue;
            }

            $series[$period] = self::toColumns($raw);
        }

        if ($failed !== []) {
            error_log('[thb] chart windows unavailable for ' . $coin->slug . ': ' . implode(', ', $failed));
        }

        return $series ?: null;
    }

    /**
     * One chart window.
     *
     * The unit the pipeline actually schedules: each period is cached and aged
     * independently, so a refused 90d costs 90d alone and cannot be crowded out
     * forever by the periods that happen to be requested before it.
     */
    private function chartWindow(Coin $coin, string $period): ?array
    {
        $days = $this->config['chart']['windows'][$period]['days']
            ?? self::CHART_WINDOWS[$period]
            ?? null;

        if ($days === null) {
            return null;
        }

        $raw = $this->get('/coins/' . rawurlencode($coin->coingeckoId) . '/market_chart', [
            'vs_currency' => 'usd',
            'days'        => $days,
            // 'interval' is omitted so CoinGecko picks its own granularity;
            // forcing it is a paid feature on the free tier.
        ]);

        if (!$raw || empty($raw['prices'])) {
            return null;
        }

        return self::toColumns($raw);
    }

    /** [[t, v], …] triples -> parallel arrays, the shape the chart engine reads. */
    private static function toColumns(array $raw): array
    {
        $t = $price = $mcap = $volume = [];

        foreach ($raw['prices'] as $i => $point) {
            $t[]      = (int) $point[0];
            $price[]  = round((float) $point[1], 6);
            $mcap[]   = isset($raw['market_caps'][$i][1]) ? (float) $raw['market_caps'][$i][1] : null;
            $volume[] = isset($raw['total_volumes'][$i][1]) ? (float) $raw['total_volumes'][$i][1] : null;
        }

        return compact('t', 'price', 'mcap', 'volume');
    }

    private function metadata(Coin $coin): ?array
    {
        $d = $this->coinEndpoint($coin);
        if (!$d) {
            return null;
        }

        // Contract addresses across chains. The model allows zero or many;
        // native assets legitimately have none on their own chain.
        $contracts = [];
        foreach ((array) ($d['platforms'] ?? []) as $platform => $address) {
            if ($platform !== '' && is_string($address) && $address !== '') {
                $contracts[] = ['network' => $platform, 'address' => $address];
            }
        }

        return [
            'nameEn'      => $d['name'] ?? null,
            'symbol'      => isset($d['symbol']) ? strtoupper($d['symbol']) : null,
            'description' => $d['description']['en'] ?? null,  // ACF override wins; see Normalizer
            'homepage'    => $d['links']['homepage'][0] ?? null,
            'explorer'    => $d['links']['blockchain_site'][0] ?? null,
            'github'      => $d['links']['repos_url']['github'][0] ?? null,
            'twitter'     => isset($d['links']['twitter_screen_name'])
                ? 'https://twitter.com/' . $d['links']['twitter_screen_name'] : null,
            'genesisDate' => $d['genesis_date'] ?? null,
            'image'       => $d['image']['large'] ?? null,
            'contracts'   => $contracts,
            /* Which sectors this coin belongs to. Free — it is already in the
               coin-detail response — and it is the join key that makes the
               site-wide categories dataset mean anything for a given coin. */
            'categories'  => array_values(array_filter(
                (array) ($d['categories'] ?? []),
                static fn($c): bool => is_string($c) && $c !== ''
            )),
        ];
    }

    private function historical(Coin $coin): ?array
    {
        $d = $this->coinEndpoint($coin);
        if (!$d || empty($d['market_data'])) {
            return null;
        }
        $m = $d['market_data'];

        return [
            'ath' => [
                'price'     => self::num($m, 'ath.usd'),
                'date'      => $m['ath_date']['usd'] ?? null,
                'changePct' => self::num($m, 'ath_change_percentage.usd'),
            ],
            'atl' => [
                'price'     => self::num($m, 'atl.usd'),
                'date'      => $m['atl_date']['usd'] ?? null,
                'changePct' => self::num($m, 'atl_change_percentage.usd'),
            ],
        ];
    }

    private static function num(array $data, string $path): ?float
    {
        foreach (explode('.', $path) as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }
        return is_numeric($data) ? (float) $data : null;
    }
}
