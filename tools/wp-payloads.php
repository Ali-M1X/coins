<?php
/**
 * Provider payloads for the test transport.
 *
 * Kept in their own file because they are the harness's most load-bearing part
 * and the easiest to get quietly wrong. Each shape here was read off the
 * collector that parses it, not off documentation, so a parser change that
 * breaks against production breaks here first.
 *
 * Three rules these fixtures follow:
 *
 * 1. ROUTE ORDER MATTERS. `/coins/categories` and `/coins/markets` both match a
 *    naive `/coins/{id}` pattern, and both have been silently swallowed by it
 *    before — every batched request receiving one coin's detail payload while
 *    appearing to work. The specific routes come first and the catch-all is
 *    guarded by a negative lookahead.
 *
 * 2. PAYLOADS CONTAIN ROWS THAT MUST BE DISCARDED. Zero-volume tickers, a
 *    venue with no name, a chain with a null TVL. A fixture made only of good
 *    rows cannot tell a careful parser from a careless one.
 *
 * 3. THE BATCH ECHOES BACK WHAT IT WAS ASKED FOR. A stub that always returned
 *    the same coin would hide exactly the behaviour under test — whether every
 *    requested coin gets written.
 *
 * @return array decoded JSON body
 */

declare(strict_types=1);

function probe_payload(string $url): array
{
    /* A caller can substitute the body for one call, so malformed and hostile
       responses can be replayed against the real parsers. Collectors must turn
       these into null — never a fatal, never a fabricated zero. */
    if (isset($GLOBALS['thb_probe_override'])) {
        return (array) $GLOBALS['thb_probe_override'];
    }

    $path = parse_url($url, PHP_URL_PATH) ?: '';
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

    /* ---- CoinGecko: chart windows ---- */
    if (str_contains($path, '/market_chart')) {
        $days = (int) ($q['days'] ?? 1);
        /* CoinGecko's own auto-granularity, which decides the payload size:
           1 day -> 5-minutely, 2-90 days -> hourly, over 90 -> daily.
           Reproduced faithfully because the 90d window is by far the largest
           and its size is part of what the probes measure. */
        $n = match (true) {
            $days <= 1  => 288,
            $days <= 90 => $days * 24,
            default     => $days,
        };
        $prices = $caps = $vols = [];
        $t = Clock::$now * 1000;
        for ($i = 0; $i < $n; $i++) {
            $ts = $t - ($n - $i) * 3600_000;
            $prices[] = [$ts, 3200 + $i * 0.5];
            $caps[]   = [$ts, 3.9e11];
            $vols[]   = [$ts, 1.8e10];
        }
        return ['prices' => $prices, 'market_caps' => $caps, 'total_volumes' => $vols];
    }

    /* ---- CoinGecko: site-wide ---- */
    if (str_contains($path, '/global')) {
        return ['data' => [
            'total_market_cap'                     => ['usd' => 2.41e12, 'btc' => 3.6e7],
            'total_volume'                         => ['usd' => 9.8e10],
            'market_cap_percentage'                => ['btc' => 54.2, 'eth' => 16.1, 'usdt' => 4.9],
            'market_cap_change_percentage_24h_usd' => 1.42,
            'active_cryptocurrencies'              => 17342,
            'markets'                              => 1180,
        ]];
    }

    if (str_contains($path, '/coins/categories')) {
        return [
            ['id' => 'smart-contract-platform', 'name' => 'Smart Contract Platform',
             'market_cap' => 8.4e11, 'market_cap_change_24h' => 1.9, 'volume_24h' => 3.1e10],
            ['id' => 'layer-1', 'name' => 'Layer 1 (L1)',
             'market_cap' => 1.6e12, 'market_cap_change_24h' => 1.1, 'volume_24h' => 5.5e10],
            ['id' => 'ethereum-ecosystem', 'name' => 'Ethereum Ecosystem',
             'market_cap' => 4.2e11, 'market_cap_change_24h' => -0.6, 'volume_24h' => 1.9e10],
            // No market cap: the parser must drop it rather than cache a null.
            ['id' => 'empty-sector', 'name' => 'Empty Sector'],
        ];
    }

    /* ---- CoinGecko: batched markets. Echoes back every id asked for. ---- */
    if (str_contains($path, '/coins/markets')) {
        $ids = array_filter(explode(',', (string) ($q['ids'] ?? '')));
        $rows = [];
        foreach ($ids as $i => $id) {
            $rows[] = [
                'id' => $id, 'symbol' => 'c' . $i, 'name' => ucfirst($id),
                'current_price' => 3245.67, 'market_cap' => 390226904100,
                'market_cap_rank' => $i + 1, 'fully_diluted_valuation' => 391200605100,
                'total_volume' => 18740000000,
                'price_change_percentage_24h' => 2.45,
                'market_cap_change_percentage_24h' => 2.44,
                'circulating_supply' => 120230000, 'total_supply' => 120530000,
                'max_supply' => null, 'ath' => 4891.7, 'atl' => 0.4209,
                'price_change_percentage_1h_in_currency'  => 0.12,
                'price_change_percentage_24h_in_currency' => 2.45,
                'price_change_percentage_7d_in_currency'  => 5.1,
                'price_change_percentage_30d_in_currency' => -3.2,
                'price_change_percentage_1y_in_currency'  => 40.0,
                'sparkline_in_7d' => ['price' => array_fill(0, 168, 3200)],
            ];
        }
        return $rows;
    }

    /* ---- Etherscan ----
     *
     * Every Etherscan reply is an envelope, and a FAILURE arrives as HTTP 200
     * with status "0" — an invalid key, an exhausted quota, a bad request. The
     * `thb_probe_etherscan_error` switch reproduces that, because a parser that
     * trusted the status code would cache "Invalid API Key" as a gas price. */
    if (str_contains((string) parse_url($url, PHP_URL_HOST), 'etherscan.io')) {
        if (!empty($GLOBALS['thb_probe_etherscan_error'])) {
            return ['status' => '0', 'message' => 'NOTOK', 'result' => (string) $GLOBALS['thb_probe_etherscan_error']];
        }
        if (($q['module'] ?? '') === 'gastracker') {
            return ['status' => '1', 'message' => 'OK', 'result' => [
                'LastBlock' => '21000000', 'SafeGasPrice' => '12', 'ProposeGasPrice' => '14',
                'FastGasPrice' => '16', 'suggestBaseFee' => '11.913', 'gasUsedRatio' => '0.4,0.6',
            ]];
        }
        if (($q['action'] ?? '') === 'ethsupply2') {
            /* Wei, as decimal STRINGS far beyond PHP_INT_MAX — the shape that
               breaks a parser which casts to int. */
            return ['status' => '1', 'message' => 'OK', 'result' => [
                'EthSupply'      => '120530000000000000000000000',
                'Eth2Staking'    => '34000000000000000000000000',
                'BurntFees'      => '4400000000000000000000000',
                'WithdrawnTotal' => '4100000000000000000000000',
            ]];
        }
    }

    /* ---- Blockchair ----
     *
     * Ethereum reports NO hashrate (proof of stake since the Merge); Bitcoin
     * does. A parser that rendered Ethereum's zero as "0 H/s" would be making a
     * false statement, so the fixture carries exactly that zero. */
    if (preg_match('#^/(ethereum|bitcoin)/stats$#', $path, $m)) {
        $isBtc = $m[1] === 'bitcoin';
        return ['data' => [
            'blocks'                          => $isBtc ? 865000 : 21000000,
            'blocks_24h'                      => $isBtc ? 144 : 7180,
            'transactions_24h'                => $isBtc ? 560000 : 1210000,
            'mempool_transactions'            => $isBtc ? 42000 : 2843,
            'average_transaction_fee_usd_24h' => $isBtc ? 1.92 : 0.87,
            'median_transaction_fee_usd_24h'  => $isBtc ? 0.95 : 0.31,
            'difficulty'                      => $isBtc ? 9.2e13 : 0,
            'hashrate_24h'                    => $isBtc ? '6.5e20' : '0',
            'nodes'                           => $isBtc ? 18000 : null,
        ], 'context' => ['code' => 200]];
    }

    /* ---- beaconcha.in ----
     *
     * Participation arrives as a FRACTION and APR may too. Rendering 0.0312 as
     * "0.03%" would understate a staking yield a hundredfold. */
    if (str_contains($path, '/epoch/latest')) {
        return ['status' => 'OK', 'data' => [
            'epoch'                   => 330000,
            'validatorscount'         => 1062000,
            'totalvalidatorbalance'   => 34200000000000000,   // gwei
            'finalized'               => true,
            'globalparticipationrate' => 0.995,
        ]];
    }
    if (str_contains($path, '/ethstore/')) {
        if (!empty($GLOBALS['thb_probe_ethstore_down'])) {
            return ['status' => 'ERROR', 'data' => 'endpoint not available'];
        }
        return ['status' => 'OK', 'data' => ['day' => 1100, 'apr' => 0.0312]];
    }

    /* ---- GitHub ----
     *
     * One commit has a NULL `author` — what GitHub returns when the commit
     * email matches no account. Counting contributors by `author.login` alone
     * would fold every such person into one null bucket. */
    if (preg_match('#^/repos/([^/]+/[^/]+)/commits$#', $path)) {
        $rows = [];
        foreach (['alice', 'bob', 'alice', 'carol', null] as $i => $login) {
            $rows[] = [
                'sha'    => str_repeat((string) $i, 40),
                'author' => $login === null ? null : ['login' => $login],
                'commit' => ['author' => ['name' => $login ?? 'Dana Unlinked', 'date' => gmdate('c', Clock::$now - $i * 3600)]],
            ];
        }
        return $rows;
    }
    if (preg_match('#^/repos/([^/]+/[^/]+)$#', $path, $m)) {
        return [
            'full_name' => $m[1], 'html_url' => 'https://github.com/' . $m[1],
            'stargazers_count' => 48100, 'forks_count' => 20300, 'open_issues_count' => 310,
            'language' => 'Go', 'pushed_at' => gmdate('c', Clock::$now - 7200),
        ];
    }

    /* ---- CoinGecko: coin detail. Guarded so the two routes above win. ---- */
    if (preg_match('#/coins/(?!markets$|categories$)[^/]+$#', $path)) {
        return coin_detail_payload(($q['tickers'] ?? 'false') === 'true');
    }

    /* ---- Alternative.me ---- */
    if (str_contains($path, '/fng')) {
        $rows = [];
        for ($i = 0; $i < 8; $i++) {
            $rows[] = [
                'value'                => (string) (55 - $i),
                'value_classification' => 'Greed',
                'timestamp'            => (string) (Clock::$now - $i * 86400),
            ];
        }
        return ['name' => 'Fear and Greed Index', 'data' => $rows, 'metadata' => ['error' => null]];
    }

    /* ---- DefiLlama ---- */
    if (str_contains($path, '/v2/historicalChainTvl/')) {
        $series = [];
        for ($i = 120; $i >= 0; $i--) {
            $series[] = ['date' => Clock::$now - $i * 86400, 'tvl' => 6.0e10 + (120 - $i) * 1e8];
        }
        return $series;
    }

    if (str_contains($path, '/overview/fees/')) {
        return [
            'total24h' => 1.26e7, 'total7d' => 8.9e7, 'total30d' => 3.6e8,
            'change_1d' => 15.3, 'change_7d' => -4.2, 'change_1m' => 9.1,
            'totalRevenue24h' => 4.1e6, 'totalRevenue7d' => 2.8e7, 'totalRevenue30d' => 1.1e8,
        ];
    }

    if (str_contains($path, '/v2/chains')) {
        return [
            ['gecko_id' => 'ethereum',    'tvl' => 6.1e10, 'tokenSymbol' => 'ETH', 'name' => 'Ethereum'],
            ['gecko_id' => 'solana',      'tvl' => 9.4e9,  'tokenSymbol' => 'SOL', 'name' => 'Solana'],
            ['gecko_id' => 'binancecoin', 'tvl' => 5.8e9,  'tokenSymbol' => 'BNB', 'name' => 'BSC'],
            ['gecko_id' => 'bitcoin',     'tvl' => 1.2e9,  'tokenSymbol' => 'BTC', 'name' => 'Bitcoin'],
            // Null TVL: must be skipped, not summed as zero.
            ['gecko_id' => 'ghost',       'tvl' => null,   'tokenSymbol' => 'GHO', 'name' => 'Ghost'],
        ];
    }

    if (str_contains($path, '/stablecoinchains')) {
        return [
            // Several peg types on purpose: summing only peggedUSD would
            // silently drop every non-dollar stablecoin.
            ['name' => 'Ethereum', 'totalCirculatingUSD' => ['peggedUSD' => 8.05e10, 'peggedEUR' => 5.0e8]],
            ['name' => 'Tron',     'totalCirculatingUSD' => ['peggedUSD' => 6.1e10]],
            ['name' => 'BSC',      'totalCirculatingUSD' => ['peggedUSD' => 5.4e9]],
        ];
    }

    if (str_contains($path, '/bridgevolume/')) {
        $rows = [];
        for ($i = 30; $i >= 0; $i--) {
            $rows[] = [
                'date'        => (string) (Clock::$now - $i * 86400),
                'depositUSD'  => 1.2e8 + $i * 1e6,
                'withdrawUSD' => 1.1e8 + $i * 1e6,
            ];
        }
        return $rows;
    }

    /* ---- DexScreener ---- */
    if (str_contains($path, '/dex/tokens/') || str_contains($path, '/dex/search')) {
        $symbol = strtoupper((string) ($GLOBALS['thb_probe_dex_symbol'] ?? 'WETH'));
        $pairs = [];
        for ($i = 0; $i < 30; $i++) {
            $pairs[] = [
                'chainId'    => 'ethereum',
                'dexId'      => ['uniswap', 'curve', 'balancer'][$i % 3],
                'url'        => 'https://dexscreener.com/ethereum/p' . $i,
                'baseToken'  => ['symbol' => $symbol, 'name' => 'Wrapped Ether'],
                'quoteToken' => ['symbol' => ['USDC', 'USDT', 'DAI'][$i % 3]],
                'volume'     => ['h24' => 11_150_000 - $i * 100_000],
                'liquidity'  => ['usd' => 12_130_000 - $i * 90_000],
                'priceUsd'   => '3245.67',
            ];
        }
        return ['pairs' => $pairs];
    }

    /* ---- FX ---- */
    if (str_contains($path, '/market/currency')) {
        return ['data' => [
            ['symbol' => 'EUR', 'price' => 905000],
            ['symbol' => 'USD', 'price' => 830000],
        ]];
    }

    return [];
}

/**
 * The /coins/{id} response.
 *
 * Tickers are included only when asked for, because whether market structure
 * costs an extra request depends on that flag being honoured — and the ticker
 * rows deliberately include two the parser must throw away.
 */
function coin_detail_payload(bool $withTickers): array
{
    $d = [
        'id' => 'ethereum', 'name' => 'Ethereum', 'symbol' => 'eth', 'market_cap_rank' => 2,
        'description' => ['en' => 'Ethereum is a decentralized platform.'],
        'links' => [
            'homepage'        => ['https://ethereum.org'],
            'blockchain_site' => ['https://etherscan.io'],
            'repos_url'       => ['github' => ['https://github.com/ethereum/go-ethereum']],
            'twitter_screen_name' => 'ethereum',
        ],
        'genesis_date' => '2015-07-30',
        /* A LOCAL asset, not a placeholder domain. The rendered page is loaded
           in a real browser by tools/dom.test.mjs, which asserts that nothing
           leaves the site — an off-site logo would fail that for the wrong
           reason and train everyone to ignore it. */
        'image' => ['large' => 'thehybit-coins/assets/img/eth.svg'],
        'platforms' => [],
        'categories' => ['Smart Contract Platform', 'Layer 1 (L1)', 'Nonexistent Sector'],
        'market_data' => [
            'current_price' => ['usd' => 3245.67],
            'price_change_percentage_24h' => 2.45,
            'market_cap' => ['usd' => 390226904100],
            'market_cap_change_percentage_24h' => 2.44,
            'fully_diluted_valuation' => ['usd' => 391200605100],
            'total_volume' => ['usd' => (float) ($GLOBALS['thb_probe_market_volume'] ?? 18740000000)],
            'circulating_supply' => 120230000,
            'total_supply' => 120530000,
            'max_supply' => null,              // uncapped: must render as infinity, never 0
            'sparkline_7d' => ['price' => array_fill(0, 168, 3200)],
            'price_change_percentage_1h_in_currency' => ['usd' => 0.12],
            'price_change_percentage_7d'  => 5.1,
            'price_change_percentage_30d' => -3.2,
            'price_change_percentage_60d' => 12.0,
            'price_change_percentage_1y'  => 40.0,
            'ath' => ['usd' => 4891.7], 'ath_date' => ['usd' => '2021-11-16T00:00:00Z'],
            'ath_change_percentage' => ['usd' => -33.65],
            'atl' => ['usd' => 0.4209], 'atl_date' => ['usd' => '2015-10-20T00:00:00Z'],
            'atl_change_percentage' => ['usd' => 771026.16],
        ],
    ];

    if (!$withTickers) {
        return $d;
    }

    $rows = [
        ['Binance',           'ETH', 'USDT', 5.4e9, 0.01, 'green'],
        ['Coinbase Exchange', 'ETH', 'USD',  2.1e9, 0.02, 'green'],
        ['OKX',               'ETH', 'USDT', 1.3e9, 0.03, 'green'],
        ['Kraken',            'ETH', 'USD',  6.0e8, 0.04, 'yellow'],
        ['Bybit',             'ETH', 'USDT', 4.2e8, 0.05, 'green'],
        ['Upbit',             'ETH', 'KRW',  3.1e8, 0.06, null],
    ];
    $tickers = [];
    foreach ($rows as [$venue, $base, $target, $vol, $spread, $trust]) {
        $tickers[] = [
            'market' => ['name' => $venue],
            'base' => $base, 'target' => $target,
            'converted_volume' => ['usd' => $vol],
            'bid_ask_spread_percentage' => $spread,
            'trust_score' => $trust,
        ];
    }
    // Two rows the parser must discard: no volume, and no venue name.
    $tickers[] = ['market' => ['name' => 'DeadExchange'], 'base' => 'ETH', 'target' => 'USDT',
                  'converted_volume' => ['usd' => 0]];
    $tickers[] = ['market' => ['name' => ''], 'base' => 'ETH', 'target' => 'BTC',
                  'converted_volume' => ['usd' => 9.9e9]];

    $d['tickers'] = $tickers;
    return $d;
}
