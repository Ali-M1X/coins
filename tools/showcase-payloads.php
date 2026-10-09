<?php
/**
 * SHOWCASE payloads — for design screenshots only.
 *
 * The assertion fixtures in wp-payloads.php are built to catch parser bugs:
 * straight lines, identical rows, deliberately broken entries. A design cannot
 * be judged on a straight line, so screenshots use these instead: series with
 * a plausible shape, peers with different sizes, a protocol list with several
 * categories.
 *
 * NONE OF THIS IS REAL MARKET DATA and none of it ships in the plugin zip.
 * v2-showcase.php stamps every page rendered with it with a visible
 * "test data" banner, so a screenshot can never be mistaken for the live site.
 */

declare(strict_types=1);

/** Deterministic noise, so screenshots are reproducible run to run. */
function showcase_noise(int $i, int $seed = 7): float
{
    $x = sin(($i + 1) * 12.9898 + $seed * 78.233) * 43758.5453;
    return ($x - floor($x)) * 2 - 1;
}

/** Log-interpolate through dated anchors, then add noise. @return array<int,float> */
function showcase_path(array $anchors, array $times, float $noise, int $seed): array
{
    $keys = array_keys($anchors);
    $out = [];
    $walk = 0.0;
    foreach ($times as $i => $ts) {
        $k = 0;
        while ($k < count($keys) - 2 && $keys[$k + 1] < $ts) {
            $k++;
        }
        [$t0, $t1] = [$keys[$k], $keys[$k + 1]];
        $f = max(0.0, min(1.0, ($ts - $t0) / max(1, $t1 - $t0)));
        $base = exp(log($anchors[$t0]) + (log($anchors[$t1]) - log($anchors[$t0])) * $f);
        $walk = $walk * 0.85 + showcase_noise($i, $seed) * $noise;
        $out[] = $base * exp($walk);
    }
    return $out;
}

function showcase_payload(string $url): ?array
{
    $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $now = Clock::$now;

    /* Intraday-to-yearly series with a shape. Bitcoin's moves are made
       partly from the same noise as Ethereum's, so the two correlate the
       way large caps do. */
    if (str_contains($path, '/market_chart')) {
        $isBtc = str_contains($path, '/coins/bitcoin/');
        $days = (int) ($q['days'] ?? 1);
        $n = $days <= 1 ? 288 : ($days <= 90 ? $days * 24 : $days);
        $step = $days <= 1 ? 300 : ($days <= 90 ? 3600 : 86400);
        $end = $isBtc ? 62000.0 : 3245.67;
        $vol = $days <= 1 ? 0.0012 : ($days <= 90 ? 0.0035 : 0.032);
        $prices = $caps = $vols = [];
        $level = 0.0;
        $series = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $shock = showcase_noise($i + $days * 31, 3) * ($isBtc ? 0.75 : 1.0) + ($isBtc ? showcase_noise($i, 11) * 0.45 : 0.0);
            $level += $shock * $vol + ($days > 90 ? 0.0009 : 0.0);
            $series[$i] = $level;
        }
        for ($i = 0; $i < $n; $i++) {
            $ts = ($now - ($n - 1 - $i) * $step) * 1000;
            $p = $end * exp($series[$i] - $series[$n - 1] - 0.0);
            $prices[] = [$ts, round($p, 2)];
            $caps[] = [$ts, $p * ($isBtc ? 19.7e6 : 120.23e6)];
            $vols[] = [$ts, ($isBtc ? 3.1e10 : 1.874e10) * (1 + 0.35 * showcase_noise($i, 5))];
        }
        return ['prices' => $prices, 'market_caps' => $caps, 'total_volumes' => $vols];
    }

    /* Bitcoin's own detail page: the assertion fixture answers every coin
       with Ethereum's figures, which would put a $3,245 price on Bitcoin. */
    if (preg_match('#/coins/bitcoin$#', $path)) {
        $d = coin_detail_payload(($q['tickers'] ?? 'false') === 'true');
        $d['id'] = 'bitcoin';
        $d['name'] = 'Bitcoin';
        $d['symbol'] = 'btc';
        $d['market_cap_rank'] = 1;
        $d['image'] = ['large' => 'tools/showcase-btc.svg'];
        $d['categories'] = ['Layer 1 (L1)'];
        $d['market_data'] = array_replace($d['market_data'], [
            'current_price' => ['usd' => 62000.0], 'price_change_percentage_24h' => -0.85,
            'market_cap' => ['usd' => 1.22e12], 'fully_diluted_valuation' => ['usd' => 1.302e12],
            'total_volume' => ['usd' => 3.1e10], 'circulating_supply' => 19.7e6, 'total_supply' => 19.7e6,
            'max_supply' => 21e6, 'ath' => ['usd' => 108000.0], 'ath_change_percentage' => ['usd' => -42.6],
            'price_change_percentage_7d' => 1.9, 'price_change_percentage_30d' => -1.1,
        ]);
        return $d;
    }

    if (str_contains($path, '/coins/markets') && ($q['price_change_percentage'] ?? '') === '24h,7d,30d,1y') {
        $peers = [
            'bitcoin'     => ['BTC', 'Bitcoin', 1.22e12, 3.1e10, -1.1, 4.0],
            'ethereum'    => ['ETH', 'Ethereum', 3.90e11, 1.87e10, -3.2, 5.1],
            'solana'      => ['SOL', 'Solana', 7.2e10, 3.4e9, 6.4, 9.0],
            'binancecoin' => ['BNB', 'BNB', 8.6e10, 1.6e9, 1.8, 4.1],
            'ripple'      => ['XRP', 'XRP', 3.1e10, 1.2e9, -4.0, 6.2],
            'cardano'     => ['ADA', 'Cardano', 1.3e10, 4.1e8, -7.5, 7.7],
            'avalanche-2' => ['AVAX', 'Avalanche', 1.0e10, 3.3e8, 2.2, 10.4],
            'tron'        => ['TRX', 'TRON', 1.1e10, 3.9e8, 0.6, 2.9],
            'sui'         => ['SUI', 'Sui', 5.1e9, 6.2e8, 12.5, 14.0],
        ];
        $rows = [];
        $i = 0;
        foreach ($peers as $id => [$sym, $name, $cap, $vol, $c30, $range]) {
            $spark = [];
            for ($k = 0; $k < 168; $k++) {
                $spark[] = 100 * (1 + $range / 100 * (0.5 + 0.5 * sin($k / 17 + $i)) + 0.004 * showcase_noise($k, $i));
            }
            $rows[] = [
                'id' => $id, 'symbol' => strtolower($sym), 'name' => $name, 'market_cap' => $cap,
                'market_cap_rank' => ++$i, 'total_volume' => $vol, 'current_price' => 1.0,
                'price_change_percentage_30d_in_currency' => $c30,
                'price_change_percentage_7d_in_currency' => $c30 / 3,
                'price_change_percentage_24h_in_currency' => $c30 / 9,
                'sparkline_in_7d' => ['price' => $spark],
            ];
        }
        return $rows;
    }

    if (str_contains($path, '/v2/chains')) {
        return [
            ['gecko_id' => 'ethereum',    'tvl' => 7.2e10,  'tokenSymbol' => 'ETH',  'name' => 'Ethereum'],
            ['gecko_id' => 'solana',      'tvl' => 9.4e9,   'tokenSymbol' => 'SOL',  'name' => 'Solana'],
            ['gecko_id' => 'binancecoin', 'tvl' => 5.8e9,   'tokenSymbol' => 'BNB',  'name' => 'BSC'],
            ['gecko_id' => 'tron',        'tvl' => 4.6e9,   'tokenSymbol' => 'TRX',  'name' => 'Tron'],
            ['gecko_id' => 'bitcoin',     'tvl' => 1.2e9,   'tokenSymbol' => 'BTC',  'name' => 'Bitcoin'],
            ['gecko_id' => 'avalanche-2', 'tvl' => 1.5e9,   'tokenSymbol' => 'AVAX', 'name' => 'Avalanche'],
            ['gecko_id' => 'sui',         'tvl' => 1.1e9,   'tokenSymbol' => 'SUI',  'name' => 'Sui'],
            ['gecko_id' => 'cardano',     'tvl' => 3.4e8,   'tokenSymbol' => 'ADA',  'name' => 'Cardano'],
        ];
    }

    if (str_contains($path, '/overview/fees/')) {
        $p = static fn($n, $c, $f, $ch) => ['name' => strtolower($n), 'displayName' => $n, 'category' => $c, 'total24h' => $f, 'total7d' => $f * 7.1, 'change_1d' => $ch];
        return [
            'total24h' => 1.26e7, 'total7d' => 8.9e7, 'total30d' => 3.6e8,
            'change_1d' => 15.3, 'change_7d' => -4.2, 'change_1m' => 9.1,
            'totalRevenue24h' => 4.1e6, 'totalRevenue7d' => 2.8e7, 'totalRevenue30d' => 1.1e8,
            'protocols' => [
                $p('Uniswap', 'Dexs', 2.4e6, 4.2), $p('Lido', 'Liquid Staking', 2.1e6, 0.4),
                $p('Aave', 'Lending', 1.5e6, -2.1), $p('Maker', 'CDP', 7.2e5, 1.3),
                $p('Base', 'Chain', 6.8e5, 3.0), $p('Arbitrum', 'Rollup', 4.1e5, 1.0),
                $p('OpenSea', 'NFT Marketplace', 3.1e5, 7.5), $p('EigenLayer', 'Restaking', 2.9e5, -0.8),
                $p('Curve', 'Dexs', 2.2e5, 2.6), $p('Chainlink', 'Oracle', 1.9e5, 0.2),
                $p('Blur', 'NFT Marketplace', 1.4e5, -5.0), $p('Pendle', 'Yield', 1.2e5, 6.1),
            ],
        ];
    }

    if (str_contains($path, '/v2/historicalChainTvl/')) {
        $start = strtotime('2019-01-01 UTC');
        $anchors = [
            $start => 3.0e8, strtotime('2020-06-01 UTC') => 1.0e9, strtotime('2021-01-01 UTC') => 2.0e10,
            strtotime('2021-12-01 UTC') => 1.6e11, strtotime('2022-06-15 UTC') => 5.0e10,
            strtotime('2023-06-01 UTC') => 2.6e10, strtotime('2024-03-15 UTC') => 6.0e10,
            strtotime('2024-08-05 UTC') => 4.5e10, strtotime('2024-12-10 UTC') => 7.8e10,
            strtotime('2025-04-08 UTC') => 4.6e10, $now => 7.2e10,
        ];
        $times = range($start, $now, 86400);
        $tvl = showcase_path($anchors, $times, 0.012, 9);
        $tvl[count($tvl) - 1] = 7.2e10;
        return array_map(static fn($t, $v) => ['date' => $t, 'tvl' => round($v)], $times, $tvl);
    }

    if (str_contains($path, '/chart/coingecko')) {
        $key = rawurldecode(substr($path, strpos($path, '/chart/') + 7));
        $start = (int) ($q['start'] ?? 0);
        $span = (int) ($q['span'] ?? 0);
        $step = (int) ($q['period'] ?? '1w') * 604800; // '2w' → two weeks
        $times = [];
        for ($i = 0; $i < $span; $i++) {
            $times[] = $start + $i * $step;
        }
        $isBtc = str_contains($key, 'bitcoin');
        $anchors = $isBtc ? [
            $start => 280, strtotime('2017-12-15 UTC') => 17000, strtotime('2018-12-15 UTC') => 3500,
            strtotime('2021-04-14 UTC') => 60000, strtotime('2021-11-10 UTC') => 66000, strtotime('2022-11-20 UTC') => 16000,
            strtotime('2024-03-14 UTC') => 71000, strtotime('2024-12-17 UTC') => 105000, $now => 62000,
        ] : [
            $start => 1.2, strtotime('2016-06-15 UTC') => 14, strtotime('2016-12-15 UTC') => 8,
            strtotime('2018-01-13 UTC') => 1350, strtotime('2018-12-15 UTC') => 90, strtotime('2020-03-13 UTC') => 110,
            strtotime('2021-05-12 UTC') => 4100, strtotime('2021-07-20 UTC') => 1800, strtotime('2021-11-10 UTC') => 4800,
            strtotime('2022-06-18 UTC') => 1000, strtotime('2022-09-15 UTC') => 1600, strtotime('2022-12-20 UTC') => 1200,
            strtotime('2023-04-12 UTC') => 1900, strtotime('2024-03-12 UTC') => 4000, strtotime('2024-08-05 UTC') => 2300,
            strtotime('2024-12-16 UTC') => 3900, strtotime('2025-04-08 UTC') => 1500, strtotime('2025-08-20 UTC') => 4600,
            $now => 3245.67,
        ];
        $prices = showcase_path($anchors, $times, 0.05, $isBtc ? 4 : 2);
        return ['coins' => [$key => ['symbol' => $isBtc ? 'BTC' : 'ETH', 'confidence' => 0.99,
            'prices' => array_map(static fn($t, $p) => ['timestamp' => $t, 'price' => round($p, 4)], $times, $prices)]]];
    }

    return null;
}
