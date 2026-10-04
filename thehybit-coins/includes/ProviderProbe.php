<?php
/**
 * Provider probe — what the production server actually experiences.
 *
 * WHY THIS EXISTS
 *
 * Every rate limit in config/providers.php is currently an assumption taken
 * from a provider's published documentation. Documentation describes policy;
 * it does not describe what a particular server on a particular IP gets back.
 * CoinGecko rate-limits PER IP, and on shared cPanel hosting that IP belongs to
 * every other account on the box as well — so the only number that means
 * anything is the one measured from the live server.
 *
 * This class calls each candidate endpoint once and records exactly what came
 * back: status, timing, size, every rate-limit header, and whether the payload
 * actually contains the fields we intend to read. A 200 that is missing the
 * field we need is a failure, and saying so is half the point.
 *
 * TWO DELIBERATE DEPARTURES FROM Collector::get()
 *
 * 1. IT DOES NOT GO THROUGH THE COLLECTOR. A probe that tripped the 429
 *    cooldown would degrade the live site to answer a question about it. The
 *    probe must be able to receive a 429 and write it down without the rest of
 *    the plugin noticing.
 *
 * 2. IT KEEPS THE RESPONSE HEADERS. Collector::get() returns decoded JSON and
 *    discards everything else; the headers are the measurement here.
 *
 * The request itself is otherwise identical — same User-Agent, same Accept,
 * same timeout source, same configured headers — so what it observes is what
 * the collectors would observe.
 *
 * MANUAL ONLY. Nothing here is hooked to a front-end action, a cron event or a
 * page render. It runs when an administrator presses a button, and at no other
 * time.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class ProviderProbe
{
    /** Where the last run is kept so the page can show it after a redirect. */
    public const OPTION_RESULT = 'thb_probe_result';

    /** Response headers worth capturing, lowercased. */
    private const RATE_HEADERS = [
        'retry-after',
        'x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-reset',
        'ratelimit-limit', 'ratelimit-remaining', 'ratelimit-reset',
        'x-rate-limit-limit', 'x-rate-limit-remaining',
        // Binance reports consumed request weight rather than a call count.
        'x-mbx-used-weight', 'x-mbx-used-weight-1m',
        // CoinGecko has been seen to send these on its paid tiers.
        'x-cg-limit', 'x-cg-remaining',
    ];

    /**
     * The endpoints worth testing, and nothing else.
     *
     * Only what the plugin uses today or what the audit identified as a real
     * candidate — deliberately not "every endpoint a provider offers", because
     * a probe run costs real budget on the provider we are least sure about.
     *
     * `expects` lists the payload paths we would actually read. A numeric
     * segment indexes a list. If a path is missing, the endpoint is reported as
     * reachable but unusable, which is a different problem from unreachable.
     *
     * @return array<int, array{provider:string, label:string, url:string, query:array, needsKey:bool, expects:array<int,string>, note?:string}>
     */
    public static function endpoints(): array
    {
        return [
            /* ---------------- CoinGecko ---------------- */
            [
                'provider' => 'coingecko',
                'label'    => 'liveness / header capture',
                'url'      => 'https://api.coingecko.com/api/v3/ping',
                'query'    => [],
                'needsKey' => false,
                'expects'  => ['gecko_says'],
                'note'     => 'cheapest possible call — used to read rate-limit headers',
            ],
            [
                'provider' => 'coingecko',
                'label'    => 'coin detail (in use)',
                'url'      => 'https://api.coingecko.com/api/v3/coins/ethereum',
                'query'    => [
                    'localization' => 'false', 'tickers' => 'false', 'market_data' => 'true',
                    'community_data' => 'false', 'developer_data' => 'false', 'sparkline' => 'true',
                ],
                'needsKey' => false,
                'expects'  => ['market_data.current_price.usd', 'market_data.ath.usd', 'categories'],
            ],
            [
                'provider' => 'coingecko',
                'label'    => 'coin detail with tickers (in use)',
                'url'      => 'https://api.coingecko.com/api/v3/coins/ethereum',
                'query'    => [
                    'localization' => 'false', 'tickers' => 'true', 'market_data' => 'false',
                    'community_data' => 'false', 'developer_data' => 'false', 'sparkline' => 'false',
                ],
                'needsKey' => false,
                'expects'  => ['tickers.0.market.name', 'tickers.0.converted_volume.usd', 'tickers.0.bid_ask_spread_percentage'],
                'note'     => 'CEX market structure without a second request',
            ],
            [
                'provider' => 'coingecko',
                'label'    => 'chart window (in use)',
                'url'      => 'https://api.coingecko.com/api/v3/coins/ethereum/market_chart',
                'query'    => ['vs_currency' => 'usd', 'days' => '1'],
                'needsKey' => false,
                'expects'  => ['prices.0.0', 'total_volumes.0.0'],
            ],
            [
                'provider' => 'coingecko',
                'label'    => 'batched markets (in use)',
                'url'      => 'https://api.coingecko.com/api/v3/coins/markets',
                'query'    => [
                    'vs_currency' => 'usd', 'ids' => 'ethereum,bitcoin',
                    'sparkline' => 'true', 'price_change_percentage' => '1h,24h,7d,30d,1y',
                ],
                'needsKey' => false,
                'expects'  => ['0.current_price', '0.fully_diluted_valuation', '0.ath', '0.sparkline_in_7d.price'],
                'note'     => 'one call for many coins — the 100-coin lever',
            ],
            [
                'provider' => 'coingecko',
                'label'    => 'global market (in use)',
                'url'      => 'https://api.coingecko.com/api/v3/global',
                'query'    => [],
                'needsKey' => false,
                'expects'  => ['data.total_market_cap.usd', 'data.market_cap_percentage.btc'],
            ],
            [
                'provider' => 'coingecko',
                'label'    => 'categories (in use)',
                'url'      => 'https://api.coingecko.com/api/v3/coins/categories',
                'query'    => [],
                'needsKey' => false,
                'expects'  => ['0.id', '0.market_cap'],
            ],

            /* ---------------- DefiLlama ---------------- */
            [
                'provider' => 'defillama',
                'label'    => 'chain TVL history (in use)',
                'url'      => 'https://api.llama.fi/v2/historicalChainTvl/Ethereum',
                'query'    => [],
                'needsKey' => false,
                'expects'  => ['0.date', '0.tvl'],
            ],
            [
                'provider' => 'defillama',
                'label'    => 'chain fees (in use)',
                'url'      => 'https://api.llama.fi/overview/fees/Ethereum',
                'query'    => ['excludeTotalDataChart' => 'true', 'excludeTotalDataChartBreakdown' => 'true'],
                'needsKey' => false,
                'expects'  => ['total24h', 'total7d', 'total30d'],
            ],
            [
                'provider' => 'defillama',
                'label'    => 'all chains (in use)',
                'url'      => 'https://api.llama.fi/v2/chains',
                'query'    => [],
                'needsKey' => false,
                'expects'  => ['0.name', '0.tvl'],
            ],
            [
                'provider' => 'defillama',
                'label'    => 'stablecoins by chain (in use)',
                'url'      => 'https://stablecoins.llama.fi/stablecoinchains',
                'query'    => [],
                'needsKey' => false,
                'expects'  => ['0.name'],
            ],
            [
                'provider' => 'defillama',
                /* STILL A CANDIDATE, and the only Group A endpoint left off.
                   The last probe returned HTTP 407 — a proxy/authorisation
                   rejection no parser change reaches. The collector and its
                   parser are written and tested; the dataset is disabled in
                   config until this row comes back green. The timestamp key has
                   appeared as both `date` and `ts`, so neither is asserted
                   here — the probe reports the shape rather than assuming it. */
                'label'    => 'bridge volume (candidate — dataset disabled)',
                'url'      => 'https://bridges.llama.fi/bridgevolume/Ethereum',
                'query'    => [],
                'needsKey' => false,
                'expects'  => ['0.depositUSD'],
            ],

            /* ---------------- DexScreener ---------------- */
            [
                'provider' => 'dexscreener',
                'label'    => 'token pairs (in use)',
                'url'      => 'https://api.dexscreener.com/latest/dex/tokens/0xC02aaA39b223FE8D0A0e5C4F27eAD9083C756Cc2',
                'query'    => [],
                'needsKey' => false,
                'expects'  => ['pairs.0.baseToken.symbol', 'pairs.0.volume.h24', 'pairs.0.liquidity.usd'],
                'note'     => 'WETH — also reveals how many pairs one page returns',
            ],

            /* ---------------- Binance ---------------- */
            [
                'provider' => 'binance',
                'label'    => 'open interest (candidate)',
                'url'      => 'https://fapi.binance.com/fapi/v1/openInterest',
                'query'    => ['symbol' => 'ETHUSDT'],
                'needsKey' => false,
                'expects'  => ['openInterest'],
            ],
            [
                'provider' => 'binance',
                'label'    => 'long/short ratio (candidate)',
                'url'      => 'https://fapi.binance.com/futures/data/globalLongShortAccountRatio',
                'query'    => ['symbol' => 'ETHUSDT', 'period' => '1h', 'limit' => '1'],
                'needsKey' => false,
                'expects'  => ['0.longShortRatio'],
            ],

            /* ---------------- Etherscan ----------------
             *
             * Sent WITH the configured key (from Settings, merged into the query
             * at send time) and displayed WITHOUT it. Etherscan reports an
             * invalid or missing key as HTTP 200 with status "0", so a green
             * status code here is not enough — the `expects` paths are what
             * confirm a real answer. */
            [
                'provider' => 'etherscan',
                'label'    => 'gas oracle (in use — gas)',
                'url'      => 'https://api.etherscan.io/v2/api',
                'query'    => ['chainid' => '1', 'module' => 'gastracker', 'action' => 'gasoracle'],
                'needsKey' => true,
                'expects'  => ['status', 'result.SafeGasPrice', 'result.ProposeGasPrice', 'result.FastGasPrice', 'result.suggestBaseFee'],
            ],
            [
                'provider' => 'etherscan',
                'label'    => 'ETH supply incl. burn and staking (in use — supply)',
                'url'      => 'https://api.etherscan.io/v2/api',
                'query'    => ['chainid' => '1', 'module' => 'stats', 'action' => 'ethsupply2'],
                'needsKey' => true,
                'expects'  => ['status', 'result.EthSupply', 'result.Eth2Staking', 'result.BurntFees'],
            ],

            /* ---------------- beaconcha.in ---------------- */
            [
                'provider' => 'beaconchain',
                'label'    => 'latest epoch (in use — staking)',
                'url'      => 'https://beaconcha.in/api/v1/epoch/latest',
                'query'    => [],
                'needsKey' => false,
                'expects'  => ['status', 'data.validatorscount', 'data.totalvalidatorbalance', 'data.globalparticipationrate'],
            ],
            [
                'provider' => 'beaconchain',
                'label'    => 'realised staking APR (in use — staking)',
                'url'      => 'https://beaconcha.in/api/v1/ethstore/latest',
                'query'    => [],
                'needsKey' => false,
                'expects'  => ['status', 'data.apr'],
                'note'     => 'optional — if this fails the validator figures still render and only the APR is unavailable',
            ],

            /* ---------------- Blockchair ---------------- */
            [
                'provider' => 'blockchair',
                'label'    => 'Ethereum network stats (in use — network)',
                'url'      => 'https://api.blockchair.com/ethereum/stats',
                'query'    => [],
                'needsKey' => false,
                'expects'  => ['data.blocks', 'data.blocks_24h', 'data.transactions_24h', 'data.mempool_transactions', 'data.average_transaction_fee_usd_24h'],
            ],
            [
                'provider' => 'blockchair',
                'label'    => 'Bitcoin network stats (in use — network)',
                'url'      => 'https://api.blockchair.com/bitcoin/stats',
                'query'    => [],
                'needsKey' => false,
                'expects'  => ['data.blocks', 'data.blocks_24h', 'data.transactions_24h', 'data.hashrate_24h', 'data.difficulty'],
            ],

            /* ---------------- GitHub ---------------- */
            [
                'provider' => 'github',
                'label'    => 'repository (in use — development)',
                'url'      => 'https://api.github.com/repos/ethereum/go-ethereum',
                'query'    => [],
                'needsKey' => false,
                'expects'  => ['full_name', 'stargazers_count', 'forks_count', 'pushed_at'],
                'note'     => 'x-ratelimit-limit shows 60 without a token and 5000 with one — the clearest proof the token is being sent',
            ],
            [
                'provider' => 'github',
                'label'    => 'recent commits (in use — development)',
                'url'      => 'https://api.github.com/repos/ethereum/go-ethereum/commits',
                'query'    => ['per_page' => '5'],
                'needsKey' => false,
                'expects'  => ['0.sha', '0.commit.author.date'],
            ],

            /* ---------------- v2 design ---------------- */
            [
                'provider' => 'coingecko',
                'label'    => 'peer comparison (in use — v2 peers)',
                'url'      => 'https://api.coingecko.com/api/v3/coins/markets',
                'query'    => [
                    'vs_currency' => 'usd', 'ids' => 'bitcoin,ethereum,solana',
                    'sparkline' => 'true', 'price_change_percentage' => '24h,7d,30d,1y',
                ],
                'needsKey' => false,
                'expects'  => ['0.market_cap', '0.total_volume', '0.price_change_percentage_30d_in_currency', '0.sparkline_in_7d.price'],
            ],
            [
                'provider' => 'defillama',
                'label'    => 'fees by protocol (in use — v2 protocols)',
                'url'      => 'https://api.llama.fi/overview/fees/Ethereum',
                'query'    => ['excludeTotalDataChart' => 'true', 'excludeTotalDataChartBreakdown' => 'true'],
                'needsKey' => false,
                'expects'  => ['protocols.0.name', 'protocols.0.category', 'protocols.0.total24h'],
            ],
            [
                'provider' => 'llamaprices',
                'label'    => 'weekly price since launch (in use — v2 longchart)',
                'url'      => 'https://coins.llama.fi/chart/coingecko:ethereum',
                'query'    => ['start' => '1438387200', 'span' => '520', 'period' => '1w'],
                'needsKey' => false,
                'expects'  => ['coins.coingecko:ethereum.prices.0.timestamp', 'coins.coingecko:ethereum.prices.0.price'],
                'note'     => 'confirms how many weekly points one request may return (span is capped at 600 in the collector)',
            ],

            [
                'provider' => 'defillama',
                'label'    => 'chain revenue (in use — v2 revenue, fee-flow right side)',
                'url'      => 'https://api.llama.fi/overview/fees/Ethereum',
                'query'    => ['dataType' => 'dailyRevenue', 'excludeTotalDataChart' => 'true', 'excludeTotalDataChartBreakdown' => 'true'],
                'needsKey' => false,
                'expects'  => ['total24h'],
            ],
            [
                'provider' => 'coinmetrics',
                'label'    => 'on-chain daily metrics (in use — v2 chainstats)',
                'url'      => 'https://community-api.coinmetrics.io/v4/timeseries/asset-metrics',
                'query'    => [
                    'assets' => 'eth', 'frequency' => '1d', 'page_size' => '3',
                    'metrics' => 'AdrActCnt,TxCnt,SplyCur,IssTotNtv,SplyExNtv,FlowInExNtv,FlowOutExNtv,SplyAct1yr,HashRate',
                    'ignore_forbidden_errors' => 'true', 'ignore_unsupported_errors' => 'true',
                ],
                'needsKey' => false,
                'expects'  => ['data.0.time', 'data.0.AdrActCnt', 'data.0.SplyCur', 'data.0.SplyExNtv', 'data.0.FlowInExNtv', 'data.0.SplyAct1yr'],
                'note'     => 'shows exactly which metrics the free tier returns for ETH; any "expects" that fails is a figure the page will report as unavailable',
            ],
            [
                'provider' => 'lido',
                'label'    => 'stETH APR, 7-day average (in use — v2 lidoapr)',
                'url'      => 'https://eth-api.lido.fi/v1/protocol/steth/apr/sma',
                'query'    => [],
                'needsKey' => false,
                'expects'  => ['data.smaApr'],
            ],
            [
                'provider' => 'blockchair',
                'label'    => 'large ETH transfers (in use — v2 whales)',
                'url'      => 'https://api.blockchair.com/ethereum/transactions',
                'query'    => ['q' => 'value(500000000000000000000..)', 's' => 'id(desc)', 'limit' => '3'],
                'needsKey' => false,
                'expects'  => ['data.0.hash', 'data.0.value', 'data.0.sender', 'data.0.recipient', 'data.0.time'],
            ],
            [
                'provider' => 'etherscan',
                'label'    => 'WETH total supply (in use — v2 ethlocations)',
                'url'      => 'https://api.etherscan.io/v2/api',
                'query'    => ['chainid' => '1', 'module' => 'stats', 'action' => 'tokensupply', 'contractaddress' => '0xC02aaA39b223FE8D0A0e5C4F27eAD9083C756Cc2'],
                'needsKey' => true,
                'expects'  => ['result'],
            ],
            [
                'provider' => 'etherscan',
                'label'    => 'canonical bridge balances (in use — v2 ethlocations)',
                'url'      => 'https://api.etherscan.io/v2/api',
                'query'    => ['chainid' => '1', 'module' => 'account', 'action' => 'balancemulti', 'tag' => 'latest',
                               'address' => '0x8315177aB297bA92A06054cE80a67Ed4DBd7ed3a,0xbEb5Fc579115071764c7423A4f12eDde41f106Ed,0x49048044D57e1C92A77f79988d21Fa8fAF74E97e,0xae0Ee0A63A2cE6BaeEFFE56e7714FB4EFE48D419,0xd19d4B5d358258f05D7B411E21A1460D11B0876F'],
                'needsKey' => true,
                'expects'  => ['result.0.account', 'result.0.balance'],
                'note'     => 'open each address on etherscan.io to confirm its name tag (Arbitrum One Bridge, OptimismPortal, Base Portal, StarkGate ETH, Linea)',
            ],
            [
                'provider' => 'wikimedia',
                'label'    => 'Wikipedia page views (in use — v2 interest)',
                'url'      => 'https://wikimedia.org/api/rest_v1/metrics/pageviews/per-article/fa.wikipedia/all-access/user/' . rawurlencode('اتریوم') . '/daily/' . gmdate('Ymd', time() - 8 * 86400) . '00/' . gmdate('Ymd', time() - 86400) . '00',
                'query'    => [],
                'needsKey' => false,
                'expects'  => ['items.0.views'],
            ],

            /* ---------------- alternative.me ---------------- */
            [
                'provider' => 'alternative',
                'label'    => 'fear & greed (in use)',
                'url'      => 'https://api.alternative.me/fng/',
                'query'    => ['limit' => '1'],
                'needsKey' => false,
                'expects'  => ['data.0.value', 'data.0.value_classification'],
            ],
        ];
    }

    public function __construct(private array $config) {}

    /**
     * Probe every endpoint once, sequentially.
     *
     * Sequential on purpose: a burst would measure our own concurrency rather
     * than the provider's limit, and could trip a rate limit that the plugin
     * then has to live with.
     *
     * @return array{startedAt:string, durationMs:int, results:array<int,array>, summary:array<string,array>}
     */
    public function run(): array
    {
        $started = microtime(true);
        $results = [];

        foreach (self::endpoints() as $endpoint) {
            $results[] = $this->probe($endpoint);
        }

        return [
            'startedAt'  => gmdate('c'),
            'durationMs' => (int) round((microtime(true) - $started) * 1000),
            'results'    => $results,
            'summary'    => self::summarise($results),
        ];
    }

    /**
     * Find where CoinGecko actually stops answering, from this IP.
     *
     * The one measurement no amount of documentation can supply. Fires up to
     * $max cheap /ping calls back to back and records the call number at which
     * the first 429 appears. Deliberately a separate action: it spends real
     * budget, and it is the only thing here that does so on purpose.
     *
     * Stops at the first refusal — enough to answer the question without
     * digging the hole deeper.
     *
     * @return array{startedAt:string, attempted:int, firstRefusalAt:?int, calls:array<int,array>}
     */
    public function burst(int $max = 15): array
    {
        $max = max(1, min($max, 30));
        $calls = [];
        $firstRefusal = null;

        for ($i = 1; $i <= $max; $i++) {
            $r = $this->probe([
                'provider' => 'coingecko',
                'label'    => 'burst #' . $i,
                'url'      => 'https://api.coingecko.com/api/v3/ping',
                'query'    => [],
                'needsKey' => false,
                'expects'  => ['gecko_says'],
            ]);

            $calls[] = [
                'n'          => $i,
                'status'     => $r['status'],
                'durationMs' => $r['durationMs'],
                'headers'    => $r['rateHeaders'],
            ];

            if ($r['status'] === 429) {
                $firstRefusal = $i;
                break;
            }
            if ($r['status'] === 0) {
                break;   // transport failure: stop rather than hammer
            }
        }

        return [
            'startedAt'      => gmdate('c'),
            'attempted'      => count($calls),
            'firstRefusalAt' => $firstRefusal,
            'calls'          => $calls,
        ];
    }

    /**
     * One endpoint, one request.
     *
     * @param array{provider:string,label:string,url:string,query:array,needsKey:bool,expects:array,note?:string} $endpoint
     */
    private function probe(array $endpoint): array
    {
        $settings = $this->config['providers'][$endpoint['provider']] ?? [];
        $configuredHeaders = (array) ($settings['headers'] ?? []);

        /* TWO URLS, ON PURPOSE.
         *
         * Etherscan and Blockchair take their key in the QUERY STRING, and this
         * probe used to echo the URL it requested straight onto the diagnostics
         * screen — the screen somebody screenshots when asking for help. So the
         * request goes out with the provider's query defaults (where
         * Settings::apply() put the key) merged in, and the URL that is stored
         * and displayed is built from the endpoint's own parameters only. The
         * key is sent; it is never recorded. */
        $displayUrl = $endpoint['query'] === []
            ? $endpoint['url']
            : $endpoint['url'] . '?' . http_build_query($endpoint['query']);

        $sendQuery = (array) $endpoint['query'] + (array) ($settings['query'] ?? []);
        $url = $sendQuery === []
            ? $endpoint['url']
            : $endpoint['url'] . '?' . http_build_query($sendQuery);

        /* Whether a KEY is configured — not merely whether any header is. GitHub
           always carries an Accept header, which used to read as "key present". */
        $auth = (array) ($settings['auth'] ?? []);
        $keyConfigured = match ($auth['in'] ?? null) {
            'header' => isset($configuredHeaders[$auth['name'] ?? '']),
            'query'  => isset($settings['query'][$auth['name'] ?? '']),
            default  => false,
        };

        $started = microtime(true);
        $response = wp_remote_get($url, [
            'timeout'     => (int) ($settings['timeout'] ?? 10),
            'redirection' => 3,
            'headers'     => array_merge(
                ['Accept' => 'application/json', 'User-Agent' => 'TheHybit/1.0 (+https://thehybit.com)'],
                $configuredHeaders
            ),
        ]);
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $base = [
            'provider'    => $endpoint['provider'],
            'label'       => $endpoint['label'],
            // The DISPLAY url: endpoint parameters only, never the provider's
            // query defaults, so a query-string key cannot reach the screen.
            'url'         => $displayUrl,
            'note'        => $endpoint['note'] ?? '',
            'needsKey'    => (bool) $endpoint['needsKey'],
            'keyConfigured' => $keyConfigured,
            'durationMs'  => $durationMs,
            'testedAt'    => gmdate('c'),
        ];

        if (is_wp_error($response)) {
            return $base + [
                'ok'          => false,
                'status'      => 0,
                'bytes'       => 0,
                'error'       => Settings::scrub($response->get_error_message(), $settings),
                'rateHeaders' => [],
                'missing'     => $endpoint['expects'],
                'quotaNote'   => '',
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $rateHeaders = self::rateHeaders($response);
        $decoded = json_decode($body, true);

        $missing = [];
        if (is_array($decoded)) {
            foreach ($endpoint['expects'] as $path) {
                if (self::dig($decoded, $path) === null) {
                    $missing[] = $path;
                }
            }
        } else {
            $missing = $endpoint['expects'];
        }

        return $base + [
            // Reachable is not the same as usable: a 200 whose payload lacks the
            // fields we read is a failure for our purposes, and is reported as one.
            'ok'          => $status >= 200 && $status < 300 && $missing === [] && is_array($decoded),
            'status'      => $status,
            'bytes'       => strlen($body),
            'error'       => '',
            'rateHeaders' => $rateHeaders,
            'missing'     => $missing,
            'quotaNote'   => self::quotaNote($decoded, $status),
        ];
    }

    /**
     * Rate-limit headers, if the provider sends any.
     *
     * An empty result is itself a finding, and the UI says so in words rather
     * than leaving a blank cell that could be read as "not checked".
     *
     * @return array<string,string>
     */
    private static function rateHeaders(mixed $response): array
    {
        $found = [];
        foreach (self::RATE_HEADERS as $name) {
            $value = function_exists('wp_remote_retrieve_header')
                ? wp_remote_retrieve_header($response, $name)
                : '';
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            if (is_string($value) && $value !== '') {
                $found[$name] = $value;
            }
        }
        return $found;
    }

    /**
     * Quota or refusal detail carried in the BODY rather than a header.
     *
     * Several providers answer 200 with an error object, or explain a refusal
     * in prose. Etherscan without a key is the clearest example, and the reason
     * that endpoint is probed keyless on purpose.
     */
    private static function quotaNote(mixed $decoded, int $status): string
    {
        if (!is_array($decoded)) {
            return '';
        }

        foreach ([
            ['status', 'error_message'],
            ['status', 'error_code'],
            ['result'],
            ['message'],
            ['msg'],
            ['error'],
        ] as $path) {
            $value = $decoded;
            foreach ($path as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$key];
            }
            if (is_string($value) && $value !== '' && !is_numeric($value)) {
                $lower = strtolower($value);
                foreach (['key', 'rate', 'limit', 'quota', 'invalid', 'exceed', 'throttl'] as $needle) {
                    if (str_contains($lower, $needle)) {
                        return mb_substr($value, 0, 200);
                    }
                }
            }
        }

        if ($status === 429) {
            return 'rate limited';
        }
        return '';
    }

    /** One row per provider, for the summary table. */
    private static function summarise(array $results): array
    {
        $byProvider = [];

        foreach ($results as $r) {
            $p = $r['provider'];
            $byProvider[$p] ??= [
                'tested' => 0, 'ok' => 0, 'rateLimited' => 0, 'failed' => 0,
                'totalMs' => 0, 'headers' => [], 'keyConfigured' => false, 'needsKey' => false,
            ];

            $byProvider[$p]['tested']++;
            $byProvider[$p]['totalMs'] += $r['durationMs'];
            $byProvider[$p]['keyConfigured'] = $byProvider[$p]['keyConfigured'] || $r['keyConfigured'];
            $byProvider[$p]['needsKey'] = $byProvider[$p]['needsKey'] || $r['needsKey'];

            if ($r['ok']) {
                $byProvider[$p]['ok']++;
            } elseif ($r['status'] === 429) {
                $byProvider[$p]['rateLimited']++;
            } else {
                $byProvider[$p]['failed']++;
            }

            foreach ($r['rateHeaders'] as $name => $value) {
                $byProvider[$p]['headers'][$name] = $value;
            }
        }

        foreach ($byProvider as $p => $row) {
            $byProvider[$p]['avgMs'] = $row['tested'] > 0 ? (int) round($row['totalMs'] / $row['tested']) : 0;
            $byProvider[$p]['status'] = match (true) {
                $row['rateLimited'] > 0 => 'rate limited',
                $row['ok'] === $row['tested'] => 'ok',
                $row['ok'] > 0 => 'partial',
                default => 'failed',
            };
        }

        return $byProvider;
    }

    /** Dotted path lookup; a numeric segment indexes a list. */
    private static function dig(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value)) {
                return null;
            }
            if (!array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }
}
