<?php
/**
 * The v2 view model — what the nine new screens show, assembled from data the
 * plugin already holds.
 *
 * THREE RULES
 *
 * 1. NO REQUESTS. Everything here comes from the classic view model (which a
 *    render already built) or from Cache::peek(), which reads a transient and
 *    never fetches. The v2 page therefore costs exactly what the classic page
 *    costs: Ethereum cold 7, warm 0.
 *
 * 2. NO INVENTED FIGURES. Every number is either a provider's figure or a
 *    stated calculation on providers' figures (a ratio, a change, a standard
 *    deviation). Where the reference design shows something no free source
 *    can supply — exchange flows, whale transfers, search interest, holdings
 *    by wallet type — the section carries an explicit `unavailable` entry with
 *    the reason, and the template says so instead of drawing a plausible shape.
 *
 * 3. THE SCORE IS THE CLASSIC SCORE. The DNA radar plots Scoring's own five
 *    components and the centre shows Scoring's own value. Nothing here
 *    re-weights, re-normalises or adds an axis, so Ethereum's score is the
 *    same number on both designs.
 *
 * Every threshold that turns a figure into a word ("calm", "watch") is a named
 * constant below, so it can be read and argued with in one place.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\V2;

use TheHybit\Coins\Cache;
use TheHybit\Coins\Coin;
use TheHybit\Coins\Datasets;
use TheHybit\Coins\Format;
use TheHybit\Coins\Settings;

defined('ABSPATH') || exit;

final class Model
{
    /* ---- risk cockpit thresholds ----------------------------------------
       Distance below the all-time high, in percent. */
    public const ATH_CALM  = 25.0;
    public const ATH_WATCH = 50.0;
    /* Annualised volatility of daily returns, in percent. Bitcoin has spent
       most of its recent history between 40 and 70; large caps above 90 are
       in a genuinely turbulent stretch. */
    public const VOL_CALM  = 60.0;
    public const VOL_WATCH = 90.0;
    /* Venue concentration, Context's Herfindahl index ×100. */
    public const HHI_CALM  = 15.0;
    public const HHI_WATCH = 25.0;
    /* A change smaller than this, in percent, is reported as "flat". */
    public const SIGNAL_DEADBAND = 2.0;
    /* Staking participation at or above this, with finality, is "healthy". */
    public const HEALTHY_PARTICIPATION = 95.0;

    /** DefiLlama's protocol categories, grouped the way the design filters them. */
    private const GROUPS = [
        'defi'   => ['Dexs', 'Lending', 'Liquid Staking', 'CDP', 'Derivatives', 'Yield', 'Yield Aggregator',
                     'Liquid Restaking', 'Restaking', 'Options', 'Synthetics', 'Stablecoins', 'RWA', 'Basis Trading',
                     'Liquidity manager', 'Indexes', 'Leveraged Farming', 'Insurance', 'Payments', 'Privacy',
                     'Dexes', 'DEX Aggregator', 'Bridge Aggregator', 'Launchpad', 'Farm', 'Prediction Market'],
        'nft'    => ['NFT Marketplace', 'NFT Lending', 'NFT'],
        'layer2' => ['Rollup', 'Chain', 'Layer 2'],
        'infra'  => ['Bridge', 'Cross Chain', 'Services', 'Oracle', 'Infrastructure', 'Wallets', 'MEV', 'Developer Tools'],
        'gaming' => ['Gaming', 'Gaming / Metaverse'],
        'social' => ['SoFi', 'Social'],
    ];

    public const GROUP_LABELS = [
        'defi'   => 'دیفای',
        'nft'    => 'NFT',
        'layer2' => 'لایه ۲',
        'infra'  => 'زیرساخت',
        'gaming' => 'گیمینگ',
        'social' => 'سوشال',
        'other'  => 'سایر',
    ];

    private const CATEGORY_FA = [
        'Dexs' => 'صرافی غیرمتمرکز', 'Lending' => 'وام‌دهی', 'Liquid Staking' => 'استیکینگ نقدشونده',
        'CDP' => 'استیبل‌کوین وثیقه‌ای', 'Derivatives' => 'مشتقات', 'Yield' => 'بازده', 'Rollup' => 'رول‌آپ',
        'Chain' => 'زنجیره', 'NFT Marketplace' => 'بازار NFT', 'Bridge' => 'پل', 'Services' => 'خدمات',
        'Liquid Restaking' => 'ری‌استیکینگ نقدشونده', 'Restaking' => 'ری‌استیکینگ', 'RWA' => 'دارایی واقعی',
        'Options' => 'آپشن', 'Stablecoins' => 'استیبل‌کوین', 'Gaming' => 'گیمینگ', 'SoFi' => 'سوشال',
        'Oracle' => 'اوراکل', 'Wallets' => 'کیف پول', 'Yield Aggregator' => 'تجمیع بازده',
        'Prediction Market' => 'بازار پیش‌بینی', 'Launchpad' => 'لانچ‌پد', 'MEV' => 'MEV',
    ];

    private Datasets $sets;

    /** The second-round datasets, peeked once per build. @var array<string, array> */
    private array $x = [];

    public function __construct(private array $config, private Cache $cache)
    {
        $this->sets = new Datasets($config);
    }

    /**
     * @param array $m the classic view model for the same coin, already built
     */
    public function build(Coin $coin, array $m): array
    {
        $peers     = $this->peek('peers', $coin, 'CoinGecko');
        $protocols = $this->peek('protocols', $coin, 'DefiLlama');
        $long      = $this->peek('longchart', $coin, 'DefiLlama');
        $bench     = $this->benchmarkYear($coin);
        $this->x = [
            'chainstats'   => $this->peek('chainstats', $coin, 'CoinMetrics'),
            'lidoapr'      => $this->peek('lidoapr', $coin, 'Lido'),
            'whales'       => $this->peek('whales', $coin, 'Blockchair'),
            'ethlocations' => $this->peek('ethlocations', $coin, 'Etherscan'),
            'interest'     => $this->peek('interest', $coin, 'Wikimedia'),
        ];

        $returns = self::dailyReturns($m['series']['1y'] ?? null);
        $vol = self::annualisedVolatility(array_slice($returns, -30, null, true));
        $corr = $bench['state'] === 'self' ? null : self::correlation($returns, self::dailyReturns($bench['data']));

        $timeline = self::parseLines((string) $coin->meta('timeline', ''), 3);
        /* The price chart's markers: the editor's market events when written,
           otherwise the timeline's dated entries. */
        $marketEvents = self::parseLines((string) $coin->meta('priceEvents', ''), 3);
        $events = $this->events($marketEvents !== [] ? $marketEvents : $timeline, $long);
        if ($marketEvents !== []) {
            $events['byIndex'] = [];   // markers no longer correspond to timeline rows
        }

        $v = [
            'brand'      => self::brand(),
            'coin'       => $this->coin($coin, $m),
            'hero'       => $this->hero($m, $coin),
            'chart'      => $this->chart($m),
            'keyMetrics' => $this->keyMetrics($m),
            'ecosystem'  => $this->ecosystem($m, $protocols),
            'flow'       => $this->flow($coin, $m, $protocols),
            'story'      => $this->story($coin, $m, $timeline, $events),
            'dna'        => $this->dna($m, $vol, $corr, $bench),
            'priceStory' => $this->priceStory($m, $long, $protocols, $events),
            'pulse'      => $this->pulse($coin, $m, $protocols, $peers),
            'peers'      => $this->peers($coin, $m, $peers),
            'supply'     => $this->supply($coin, $m, $vol, $corr, $bench),
            'faq'        => self::parseLines((string) $coin->meta('faq', ''), 2),
            'about'      => $m['about'] ?? [],
            'news'       => array_slice((array) ($m['news'] ?? []), 0, 4),
            'generatedAt' => $m['meta']['generatedAt'] ?? gmdate('c', time()),
        ];
        $v['sources'] = $this->sources($m, ['peers' => $peers, 'protocols' => $protocols, 'longchart' => $long] + $this->x);

        return $v;
    }

    /**
     * What the script needs to redraw on interaction — and nothing it does
     * not. Every figure is already in the HTML; this is series and positions.
     */
    public static function client(array $v): array
    {
        return [
            'symbol'  => $v['coin']['symbol'] ?? '',
            'slug'    => $v['coin']['slug'] ?? '',
            'chart'   => $v['chart']['client'] ?? [],
            'live'    => $v['hero']['live'] ?? null,
            'story'   => $v['priceStory']['client'] ?? null,
            'ecosystem' => array_map(
                static fn($p) => array_intersect_key($p, array_flip(['name', 'categoryFa', 'group', 'fees', 'change', 'share'])),
                $v['ecosystem']['nodes'] ?? []
            ),
            'peers'   => $v['peers']['client'] ?? [],
        ];
    }

    /* ==================================================================
     * Sections
     * ================================================================ */

    private function coin(Coin $coin, array $m): array
    {
        $trade = (string) Settings::get('trade_url', '');
        if ($trade !== '') {
            $trade = strtr($trade, [
                '{symbol}' => rawurlencode(strtolower($coin->symbol)),
                '{slug}'   => rawurlencode($coin->slug),
            ]);
        }

        return [
            'slug'     => $coin->slug,
            'name'     => (string) ($m['coin']['name'] ?? $coin->name),
            'nameEn'   => (string) ($m['coin']['nameEn'] ?? ''),
            'symbol'   => (string) ($m['coin']['symbol'] ?? $coin->symbol),
            'logo'     => $m['coin']['logo'] ?? null,
            'rank'     => $m['coin']['rank'] ?? null,
            'tagline'  => trim((string) $coin->meta('tagline', '')),
            'tradeUrl' => $trade !== '' ? $trade : null,
            'newsUrl'  => $m['coin']['newsCategoryUrl'] ?? null,
            'isBenchmark' => $coin->slug === ($this->config['benchmark']['slug'] ?? ''),
        ];
    }

    private function hero(array $m, Coin $coin): array
    {
        $mk = (array) ($m['market'] ?? []);

        /* The live ticker, when it is newer than the five-minute market entry:
           the first paint already shows the minute-fresh price, so the script's
           first update is a real change rather than a correction. */
        $tick = $this->cache->peek('ticker', $this->cache->keyFor('ticker'))['data']['prices'][$coin->coingeckoId] ?? null;
        if (is_array($tick) && isset($tick['price'])) {
            $mk['price'] = (float) $tick['price'];
            $mk['change24h'] = $tick['change24h'] ?? ($mk['change24h'] ?? null);
        }
        $live = (new \TheHybit\Coins\LivePrice($this->config, new \TheHybit\Coins\CoinRepository(), $this->cache))->read($coin);

        return [
            'live'       => [
                'url'           => \TheHybit\Coins\LivePrice::url($coin->slug),
                'nextRefreshAt' => $live['nextRefreshAt'] ?? null,
                'fetchedAt'     => $live['fetchedAt'] ?? null,
                'interval'      => \TheHybit\Coins\Scheduler::interval($this->config),
            ],
            'price'      => $mk['price'] ?? null,
            'change24h'  => $mk['change24h'] ?? null,
            'marketCap'  => $mk['marketCap'] ?? null,
            'volume24h'  => $mk['volume24h'] ?? null,
            'circulating' => $mk['circulatingSupply'] ?? null,
            'fdv'        => $mk['fdv'] ?? null,
            'ath'        => $mk['ath']['price'] ?? null,
            'athDate'    => $mk['ath']['date'] ?? null,
            'dominance'  => ($m['context']['marketCapShare']['available'] ?? false)
                ? $m['context']['marketCapShare']['value'] : null,
            'toman'      => isset($mk['price'], $m['currency']['usdToToman'])
                ? Format::toman((float) $mk['price'], (float) $m['currency']['usdToToman']) : null,
        ];
    }

    /** Price/volume/market-cap series per window, thinned for drawing. */
    private function chart(array $m): array
    {
        $labels = ['24h' => '۲۴ ساعت', '7d' => '۷ روز', '30d' => '۳۰ روز', '90d' => '۹۰ روز', '1y' => '۱ سال'];
        $client = [];
        foreach ($labels as $period => $label) {
            $s = $m['series'][$period] ?? null;
            if (!is_array($s) || count(Svg::clean($s['price'] ?? [])) < 2) {
                continue;
            }
            $client[$period] = [
                't' => Svg::thin((array) ($s['t'] ?? []), 120),
                'p' => Svg::thin((array) ($s['price'] ?? []), 120),
                'v' => Svg::thin((array) ($s['volume'] ?? []), 120),
                'm' => Svg::thin((array) ($s['mcap'] ?? []), 120),
            ];
        }

        $default = isset($client['7d']) ? '7d' : (array_key_first($client) ?? null);
        $prices = $default ? $client[$default]['p'] : [];

        return [
            'windows' => array_intersect_key($labels, $client),
            'default' => $default,
            'line'    => Svg::line($prices, 600, 220, 6),
            'area'    => Svg::area($prices, 600, 220, 6),
            'min'     => $prices ? min(Svg::clean($prices)) : null,
            'max'     => $prices ? max(Svg::clean($prices)) : null,
            'change'  => $default ? ($m['performance'][$default] ?? null) : null,
            'client'  => $client,
        ];
    }

    private function keyMetrics(array $m): array
    {
        $defi = (array) ($m['defi'] ?? []);
        $staking = (array) ($m['staking'] ?? []);
        $stable = (array) ($m['context']['stablecoinSupply'] ?? []);
        $apr = $this->stakingApr($m);
        $active = $this->cm('AdrActCnt');

        return [
            self::metric('ارزش کل قفل‌شده (TVL)', isset($defi['tvl']) ? Format::usdCompact((float) $defi['tvl']) : null,
                $defi['tvlChange7d'] ?? null, '۷ روز', 'DefiLlama', $m['defi'] ? null : 'این زنجیره داده دیفای ندارد'),
            self::metric('کارمزد پرداختی (۲۴ ساعت)', isset($defi['fees24h']) ? Format::usdCompact((float) $defi['fees24h']) : null,
                $defi['feesChange24h'] ?? null, '۱ روز', 'DefiLlama', $m['defi'] ? null : 'این زنجیره داده کارمزد ندارد'),
            self::metric('بازده استیکینگ (APR)', $apr ? Format::pct($apr['value'], false) : null,
                null, null, $apr['source'] ?? null,
                ($staking['state'] ?? '') === 'not_applicable' ? 'این شبکه استیکینگ ندارد' : 'در حال دریافت داده', $apr['detail'] ?? null),
            self::metric('عرضه استیبل‌کوین روی زنجیره', ($stable['available'] ?? false) ? Format::usdCompact((float) $stable['value']) : null,
                null, null, 'DefiLlama', self::stateNote('unavailable')),
            self::metric('آدرس‌های فعال روزانه', $active ? Format::num($active['latest'], 0) : null,
                $active ? self::change($active['latest'], $active['weekAgo']) : null, '۷ روز', 'CoinMetrics',
                self::stateNote($this->x['chainstats']['state'] ?? 'pending'), $active ? 'روز ' . Format::jalaliShort($active['date']) . ' · CoinMetrics' : null),
        ];
    }

    /* ------------------------------------------------------------------
     * Ecosystem graph — protocols by fees users paid in the last 24 hours
     * ---------------------------------------------------------------- */

    private function ecosystem(array $m, array $protocols): array
    {
        $rows = (array) ($protocols['data']['protocols'] ?? []);
        $top = array_slice($rows, 0, 10);
        $total = array_sum(array_map(static fn($p) => (float) $p['fees24h'], $rows));

        $nodes = [];
        $n = count($top);
        foreach ($top as $i => $p) {
            /* Two rings: the five largest close in, the rest further out, each
               spread evenly so labels never stack. Positions are in a 1000×560
               box the template scales with viewBox. */
            $inner = $i < 5;
            $count = $inner ? min(5, $n) : max(1, $n - 5);
            $slot = $inner ? $i : $i - 5;
            $angle = -M_PI / 2 + 2 * M_PI * ($slot + ($inner ? 0 : 0.5)) / $count;
            $rx = $inner ? 240 : 420;
            $ry = $inner ? 150 : 210;
            $group = self::groupOf((string) $p['category']);
            $nodes[] = [
                'name'       => $p['name'],
                'category'   => $p['category'],
                'categoryFa' => self::CATEGORY_FA[$p['category']] ?? ($p['category'] ?: 'سایر'),
                'group'      => $group,
                'fees'       => $p['fees24h'],
                'fees7d'     => $p['fees7d'] ?? null,
                'change'     => $p['change1d'] ?? null,
                'share'      => $total > 0 ? $p['fees24h'] / $total * 100 : null,
                'logo'       => $p['logo'] ?? null,
                'x'          => round(500 + $rx * cos($angle), 1),
                'y'          => round(290 + $ry * sin($angle), 1),
                'r'          => round(24 + 26 * sqrt($top[0]['fees24h'] > 0 ? $p['fees24h'] / $top[0]['fees24h'] : 0), 1),
            ];
        }

        $groups = [];
        foreach ($rows as $p) {
            $g = self::groupOf((string) $p['category']);
            $groups[$g] = ($groups[$g] ?? 0) + (float) $p['fees24h'];
        }
        arsort($groups);

        $defi = (array) ($m['defi'] ?? []);

        return [
            'state'     => $protocols['state'],
            'source'    => $protocols['source'],
            'fetchedAt' => $protocols['fetchedAt'],
            'nodes'     => $nodes,
            'groups'    => array_map(fn($g, $v) => [
                'key' => $g, 'label' => self::GROUP_LABELS[$g], 'fees' => $v,
                'share' => $total > 0 ? $v / $total * 100 : null,
            ], array_keys($groups), $groups),
            'totalFees' => $total > 0 ? $total : null,
            'tvl'       => $defi['tvl'] ?? null,
            'tvlChange7d' => $defi['tvlChange7d'] ?? null,
            'tvlLine'   => Svg::line((array) ($defi['sparkline'] ?? []), 300, 90, 4),
            'tvlArea'   => Svg::area((array) ($defi['sparkline'] ?? []), 300, 90, 4),
            'bars'      => self::bars(array_map(static fn($p) => (float) $p['fees24h'], $top), 300, 110),
        ];
    }

    /* ------------------------------------------------------------------
     * Chain flow — where the fees come from and where they go
     * ---------------------------------------------------------------- */

    private function flow(Coin $coin, array $m, array $protocols): array
    {
        $defi = (array) ($m['defi'] ?? []);
        $pd = (array) ($protocols['data'] ?? []);
        $fees = isset($defi['fees24h']) ? (float) $defi['fees24h']
            : (isset($pd['fees24h']) && is_numeric($pd['fees24h']) ? (float) $pd['fees24h'] : null);
        /* Revenue from the classic DeFi dataset when it carries it, otherwise
           from the protocols dataset, which asks DefiLlama for it explicitly. */
        $revenue = isset($defi['revenue24h']) ? (float) $defi['revenue24h']
            : (isset($pd['revenue24h']) && is_numeric($pd['revenue24h']) ? (float) $pd['revenue24h'] : null);

        /* LEFT: fees by protocol group. Shares of the protocols DefiLlama lists
           for the chain; anything the list does not cover is "other" against
           the chain total, so the bands always add up to what was paid. */
        $left = [];
        $rows = (array) ($protocols['data']['protocols'] ?? []);
        if ($rows !== [] && $fees !== null && $fees > 0) {
            $byGroup = [];
            foreach ($rows as $p) {
                $g = self::groupOf((string) $p['category']);
                $byGroup[$g] = ($byGroup[$g] ?? 0) + (float) $p['fees24h'];
            }
            arsort($byGroup);
            $listed = array_sum($byGroup);
            $base = max($fees, $listed);
            /* DefiLlama's own "Others" category and the unlisted remainder are
               both "other": named groups first, then ONE "سایر" band for the
               rest — 2.0.1 showed "سایر" twice. */
            $named = array_diff_key($byGroup, ['other' => true]);
            foreach (array_slice($named, 0, 4, true) as $g => $v) {
                $left[] = ['label' => self::GROUP_LABELS[$g], 'value' => $v, 'share' => $v / $base];
            }
            $rest = $base - array_sum(array_column($left, 'value'));
            if ($rest > 0) {
                $left[] = ['label' => 'سایر', 'value' => $rest, 'share' => $rest / $base];
            }
        }

        /* RIGHT: who keeps it. DefiLlama's revenue is the part that goes to the
           protocols and their token holders; the remainder goes to liquidity
           providers, lenders and stakers who supplied the capital. */
        $right = [];
        if ($fees !== null && $fees > 0 && $revenue !== null && $revenue >= 0 && $revenue <= $fees) {
            $right[] = ['label' => 'درآمد پروتکل‌ها', 'value' => $revenue, 'share' => $revenue / $fees];
            $right[] = ['label' => 'تأمین‌کنندگان سرمایه', 'value' => $fees - $revenue, 'share' => ($fees - $revenue) / $fees];
        }

        /* The left half stands on its own: where the fees came from is
           complete without the revenue split, so it is drawn even when the
           right half has nothing to show. */
        $ok = $left !== [];

        $network = (array) ($m['network'] ?? []);
        $gas = (array) ($m['gas'] ?? []);
        $staking = (array) ($m['staking'] ?? []);
        $nd = (array) ($network['data'] ?? []);
        $sd = (array) ($staking['data'] ?? []);
        $isPos = ($staking['state'] ?? '') !== 'not_applicable';

        $pulse = [
            ['label' => 'زمان بلوک (میانگین)', 'value' => isset($nd['blockTime']) ? Format::num((float) $nd['blockTime'], 1) . ' ثانیه' : null, 'state' => $network['state'] ?? 'unavailable', 'source' => 'Blockchair'],
            ['label' => 'گس پیشنهادی', 'value' => isset($gas['data']['propose']) ? Format::num((float) $gas['data']['propose'], 2) . ' Gwei' : null, 'state' => $gas['state'] ?? 'unavailable', 'source' => 'Etherscan'],
            ['label' => 'تراکنش‌های در انتظار', 'value' => isset($nd['mempool']) ? Format::num((float) $nd['mempool'], 0) : null, 'state' => $network['state'] ?? 'unavailable', 'source' => 'Blockchair'],
            ['label' => 'تراکنش‌ها (۲۴ ساعت)', 'value' => isset($nd['transactions24h']) ? Format::compact((float) $nd['transactions24h']) : null, 'state' => $network['state'] ?? 'unavailable', 'source' => 'Blockchair'],
        ];
        $active = $this->cm('AdrActCnt');
        $pulse[] = ['label' => 'آدرس‌های فعال (روز گذشته)', 'value' => $active ? Format::num($active['latest'], 0) : null,
                    'state' => $this->x['chainstats']['state'] ?? 'pending', 'source' => 'CoinMetrics'];
        if ($isPos) {
            $pulse[] = ['label' => 'اعتبارسنج‌های فعال', 'value' => isset($sd['validators']) ? Format::num((float) $sd['validators'], 0) : null,
                        'state' => $staking['state'] ?? 'unavailable', 'source' => 'beaconcha.in'];
            /* Proof of stake has no hash rate. Its security budget is the ETH
               at stake, which is what takes the hash-rate row's place. */
            $staked = $sd['stakedEth'] ?? null;   // beaconcha.in: validators' total balance
            $pulse[] = ['label' => 'اتر در استیکینگ (امنیت شبکه)', 'value' => is_numeric($staked) ? Format::compact((float) $staked) . ' ETH' : null,
                        'state' => $staking['state'] ?? 'unavailable', 'source' => 'beaconcha.in'];
        } else {
            $cmHash = $this->cm('HashRate');
            $hash = isset($nd['hashrate']) ? (float) $nd['hashrate'] : ($cmHash ? $cmHash['latest'] * 1e12 : null);   // CoinMetrics reports TH/s
            $pulse[] = ['label' => 'هش‌ریت', 'value' => $hash !== null ? self::hashrate($hash) : null,
                        'state' => $network['state'] ?? 'unavailable', 'source' => isset($nd['hashrate']) ? 'Blockchair' : 'CoinMetrics'];
        }

        $healthy = null;
        if (($staking['available'] ?? false) && isset($sd['participation'])) {
            $healthy = !empty($sd['finalized']) && (float) $sd['participation'] >= self::HEALTHY_PARTICIPATION;
        }

        $cards = [];
        $stable = (array) ($m['context']['stablecoinSupply'] ?? []);
        if ($stable['available'] ?? false) {
            $cards[] = ['text' => 'عرضه استیبل‌کوین روی این زنجیره', 'value' => Format::usdCompact((float) $stable['value'])];
        }
        $stakedShare = $this->stakedShare($m);
        if ($stakedShare !== null) {
            $cards[] = ['text' => 'سهم اتر سپرده‌شده در استیکینگ', 'value' => Format::pct($stakedShare, false, 1)];
        }
        if ($m['derived']['dexShareOfVolume']['available'] ?? false) {
            $cards[] = ['text' => 'سهم صرافی‌های غیرمتمرکز از حجم', 'value' => Format::pct((float) $m['derived']['dexShareOfVolume']['value'], false, 1)];
        }

        /* Exchange flows, where CoinMetrics' free tier includes them: coins
           deposited to and withdrawn from known exchange wallets yesterday. */
        $symbol = (string) ($m['coin']['symbol'] ?? $coin->symbol);
        $in = $this->cm('FlowInExNtv');
        $outFlow = $this->cm('FlowOutExNtv');
        $exchange = null;
        if ($in && $outFlow && $in['date'] === $outFlow['date']) {
            $net = $in['latest'] - $outFlow['latest'];
            $exchange = [
                'in' => $in['latest'], 'out' => $outFlow['latest'], 'net' => $net, 'date' => $in['date'],
                'text' => $net < 0 ? 'خروج خالص از صرافی‌ها' : 'ورود خالص به صرافی‌ها',
            ];
            $cards[] = ['text' => $exchange['text'] . ' (روز گذشته)', 'value' => Format::compact(abs($net)) . ' ' . $symbol];
        }

        $wd = (array) ($this->x['whales']['data'] ?? []);
        $whales = [
            'state'     => $this->x['whales']['state'] ?? 'unavailable',
            'min'       => $wd['min'] ?? null,
            'fetchedAt' => $this->x['whales']['fetchedAt'] ?? null,
            'rows'      => array_map(static fn($t) => [
                // Whole coins below 100,000: "1,500 ETH", never a rounded "2K".
                'amount' => ($t['amount'] < 100000 ? Format::num((float) $t['amount'], 0) : Format::compact((float) $t['amount'], 1)) . ' ' . $symbol,
                'usd'    => isset($t['usd']) ? Format::usdCompact((float) $t['usd'], 1) : null,
                'from'   => isset($t['from']) ? Format::shortAddress($t['from'], 6, 4) : null,
                'to'     => isset($t['to']) ? Format::shortAddress($t['to'], 6, 4) : null,
                'time'   => isset($t['time']) ? Format::tehranTime(str_replace(' ', 'T', (string) $t['time']) . 'Z') : null,
                'url'    => 'https://blockchair.com/' . ($symbol === 'BTC' ? 'bitcoin' : 'ethereum') . '/transaction/' . rawurlencode((string) $t['hash']),
            ], array_slice((array) ($wd['transfers'] ?? []), 0, 6)),
        ];

        return [
            'state'   => $ok ? 'ok' : ($protocols['state'] === 'ok' || $protocols['state'] === 'stale' ? 'unavailable' : $protocols['state']),
            'fees'    => $fees,
            'left'    => $left,
            'right'   => $right,
            /* RTL: money ENTERS on the right (where a Persian reader starts)
               and LEAVES on the left. `left`/`right` in this array are the
               data's roles — sources and destinations — drawn on the right and
               left of the diagram respectively. */
            'ribbonsSources' => $ok ? Svg::ribbons(array_column($left, 'share'), 850, 580, 20, 360, 12, 140, 120) : [],
            'ribbonsDest'    => $ok && $right !== [] ? Svg::ribbons(array_column($right, 'share'), 150, 420, 60, 280, 24, 140, 120, 60) : [],
            'whales'  => $whales,
            'exchange' => $exchange,
            'pulse'   => $pulse,
            'healthy' => $healthy,
            'participation' => $sd['participation'] ?? null,
            'cards'   => $cards,
            'source'  => 'DefiLlama',
        ];
    }

    /* ------------------------------------------------------------------
     * Story & learning
     * ---------------------------------------------------------------- */

    private function story(Coin $coin, array $m, array $timeline, array $events): array
    {
        $entries = [];
        foreach ($timeline as $i => [$date, $title, $text]) {
            // ">2026", ">بلندمدت": a planned roadmap item, not a past event.
            $planned = str_starts_with($date, '>');
            $entries[] = [
                'planned' => $planned,
                'year'  => $planned ? trim(substr($date, 1)) : substr($date, 0, 4),
                'date'  => $date,
                'title' => $title,
                'text'  => $text,
                'event' => $events['byIndex'][$i] ?? null,
            ];
        }

        $learn = [];
        foreach (array_slice(self::parseLines((string) $coin->meta('learn', ''), 2), 0, 5) as [$title, $text]) {
            $learn[] = ['title' => $title, 'text' => $text];
        }

        $news = [];
        foreach (array_slice((array) ($m['news'] ?? []), 0, 3) as $n) {
            $news[] = [
                'title' => $n['title'] ?? '',
                'url'   => $n['url'] ?? '#',
                'date'  => Format::jalaliShort($n['publishedAt'] ?? null),
                'iso'   => $n['publishedAt'] ?? null,
                /* News::thumbnail() returns {src, alt}, not a URL. Printing the
                   array produced src="Array" — a broken image on every card. */
                'image' => is_array($n['image'] ?? null) ? ($n['image']['src'] ?? null) : null,
                'imageAlt' => is_array($n['image'] ?? null) ? (string) ($n['image']['alt'] ?? '') : '',
                'category' => $n['category'] ?? null,
            ];
        }

        $a = (array) ($m['analytics'] ?? []);

        return [
            'timeline' => $entries,
            'featured' => $events['featured'] ?? null,
            'learn'    => $learn,
            'news'     => $news,
            'signals'  => self::signals($m),
            'score'    => ($a['available'] ?? false) ? (int) round((float) $a['value']) : null,
            'scoreBand' => $a['band']['label'] ?? null,
            'scoreDash' => ($a['available'] ?? false) ? Svg::dash(52, (float) $a['value']) : null,
        ];
    }

    /**
     * Data signals for "what's next". Each is a measured change with its
     * figure beside it — a description of what the data did, never a forecast.
     */
    private static function signals(array $m): array
    {
        $defi = (array) ($m['defi'] ?? []);
        $sentiment = (array) ($m['sentiment']['data'] ?? []);
        $candidates = [
            ['ارزش قفل‌شده دیفای (۳۰ روز)', $defi['tvlChange30d'] ?? null],
            ['کارمزد پرداختی کاربران (۷ روز)', $defi['feesChange7d'] ?? null],
            ['قیمت (۳۰ روز)', $m['performance']['30d'] ?? null],
            ['عملکرد نسبت به کل بازار (۲۴ ساعت)', ($m['context']['vsMarket24h']['available'] ?? false) ? $m['context']['vsMarket24h']['value'] : null, 'pp'],
            ['شاخص ترس و طمع نسبت به هفته قبل', isset($sentiment['value'], $sentiment['weekAgo']) ? (float) $sentiment['value'] - (float) $sentiment['weekAgo'] : null, 'pt'],
        ];

        $out = [];
        foreach ($candidates as $c) {
            [$label, $value] = $c;
            if ($value === null) {
                continue;
            }
            $unit = $c[2] ?? 'pct';
            $tone = abs((float) $value) < self::SIGNAL_DEADBAND ? 'neutral' : ($value > 0 ? 'positive' : 'negative');
            $out[] = [
                'label' => $label,
                'value' => match ($unit) {
                    'pp'    => Format::num((float) $value, 2) . ' واحد درصد',
                    'pt'    => ($value > 0 ? '+' : ($value < 0 ? '−' : '')) . Format::num(abs((float) $value), 0) . ' واحد',
                    default => Format::pct((float) $value),
                },
                'tone'  => $tone,
                'toneFa' => ['positive' => 'مثبت', 'negative' => 'منفی', 'neutral' => 'خنثی'][$tone],
            ];
        }
        return $out;
    }

    /* ------------------------------------------------------------------
     * DNA — the classic score, drawn as a radar
     * ---------------------------------------------------------------- */

    private function dna(array $m, ?float $vol, ?float $corr, array $bench): array
    {
        $a = (array) ($m['analytics'] ?? []);
        $components = (array) ($a['components'] ?? []);
        $n = count($components);

        $axes = [];
        foreach ($components as $i => $c) {
            [$lx, $ly] = Svg::polar(200, 200, 168, $i, $n);
            $r = ($c['available'] ?? false) ? 140 * max(0, min(100, (float) $c['normalized'])) / 100 : 0;
            [$vx, $vy] = Svg::polar(200, 200, $r, $i, $n);
            // The figure sits just outside its point, and never inside the centre disc.
            [$tx, $ty] = Svg::polar(200, 200, max($r + 22, 70), $i, $n);
            $axes[] = [
                'label'  => $c['label'] ?? $c['key'],
                'value'  => ($c['available'] ?? false) ? (int) round((float) $c['normalized']) : null,
                'weight' => $c['weight'] ?? null,
                'lx' => $lx, 'ly' => $ly, 'vx' => $vx, 'vy' => $vy, 'tx' => $tx, 'ty' => $ty + 5,
            ];
        }

        $values = array_map(static fn($c) => ($c['available'] ?? false) ? (float) $c['normalized'] : null, $components);
        $available = count(array_filter($values, static fn($v) => $v !== null));

        $s = (array) ($m['sentiment'] ?? []);
        $sv = isset($s['data']['value']) ? (float) $s['data']['value'] : null;

        $s7 = (array) ($m['series']['7d'] ?? []);
        $mk = (array) ($m['market'] ?? []);
        $defi = (array) ($m['defi'] ?? []);

        return [
            'score'     => ($a['available'] ?? false) ? (int) round((float) $a['value']) : null,
            'band'      => $a['band']['label'] ?? null,
            'coverage'  => [$available, $n],
            'axes'      => $axes,
            'grid'      => array_map(static fn($r) => Svg::ring(200, 200, $r, max(3, $n)), [35, 70, 105, 140]),
            'polygon'   => $n >= 3 ? Svg::radar(200, 200, 140, $values) : '',
            'sentiment' => [
                'state'  => $s['state'] ?? 'unavailable',
                'value'  => $sv,
                'label'  => $s['data']['labelFa'] ?? null,
                'arc'    => Svg::arc(100, 100, 74, 150, 390),
                'fill'   => $sv !== null ? Svg::arc(100, 100, 74, 150, 150 + 240 * $sv / 100) : '',
                'needle' => $sv !== null ? Svg::needle(100, 100, 56, $sv) : null,
                'weekAgo' => $s['data']['weekAgo'] ?? null,
            ],
            'sparks' => [
                ['label' => 'قیمت', 'span' => '۷ روز', 'd' => Svg::line(Svg::thin((array) ($s7['price'] ?? []), 60), 180, 44, 3), 'tone' => 'green'],
                ['label' => 'حجم معاملات', 'span' => '۷ روز', 'd' => Svg::line(Svg::thin((array) ($s7['volume'] ?? []), 60), 180, 44, 3), 'tone' => 'violet'],
                ['label' => 'ارزش قفل‌شده', 'span' => count((array) ($defi['sparkline'] ?? [])) . ' روز', 'd' => Svg::line((array) ($defi['sparkline'] ?? []), 180, 44, 3), 'tone' => 'amber'],
            ],
            'cards' => [
                ['title' => 'نقدشوندگی', 'value' => isset($mk['volume24h']) ? Format::usdCompact((float) $mk['volume24h'], 1) : null,
                 'sub' => ($m['derived']['volumeToMarketCap']['available'] ?? false)
                    ? 'حجم ۲۴ ساعته · ' . Format::pct((float) $m['derived']['volumeToMarketCap']['value'] * 100, false, 1) . ' از ارزش بازار' : 'حجم ۲۴ ساعته'],
                ['title' => 'ارزش قفل‌شده', 'value' => isset($defi['tvl']) ? Format::usdCompact((float) $defi['tvl'], 1) : null,
                 'sub' => ($m['derived']['tvlToMarketCap']['available'] ?? false)
                    ? Format::pct((float) $m['derived']['tvlToMarketCap']['value'] * 100, false, 1) . ' از ارزش بازار' : null,
                 'note' => isset($defi['tvl']) ? null : 'این زنجیره داده دیفای ندارد'],
                $this->interestCard(),
                ['title' => 'ریسک نوسان', 'value' => $vol !== null ? Format::pct($vol, false, 0) : null,
                 'sub' => $corr !== null ? 'همبستگی با بیت‌کوین ' . Format::ratio($corr, 2)
                    : ($bench['state'] === 'self' ? 'این ارز خودش معیار بازار است' : 'همبستگی با بیت‌کوین: داده یک‌ساله بیت‌کوین هنوز نرسیده'),
                 'note' => $vol === null ? 'سری قیمت یک‌ساله هنوز دریافت نشده' : null],
            ],
        ];
    }

    /* ------------------------------------------------------------------
     * Price as story — the multi-year chart with events and layers
     * ---------------------------------------------------------------- */

    private function events(array $timeline, array $long): array
    {
        $t = (array) ($long['data']['t'] ?? []);
        $p = (array) ($long['data']['price'] ?? []);
        $n = count($t);
        $byIndex = [];
        $list = [];

        foreach ($timeline as $i => [$date]) {
            // Only a full date can be placed on a weekly chart honestly.
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $n < 2) {
                continue;
            }
            $ts = strtotime($date . ' 00:00:00 UTC');
            if ($ts === false || $ts < $t[0] || $ts > $t[$n - 1]) {
                continue;
            }
            $at = self::nearest($t, $ts);
            $after = self::nearest($t, $ts + 365 * 86400);
            $after90 = self::nearest($t, $ts + 90 * 86400);
            $hasYear = $t[$n - 1] >= $ts + 365 * 86400;
            $change = $p[$at] > 0 ? ($p[$after] - $p[$at]) / $p[$at] * 100 : null;
            $change90 = $p[$at] > 0 && $t[$n - 1] >= $ts + 90 * 86400 ? ($p[$after90] - $p[$at]) / $p[$at] * 100 : null;
            $event = [
                'index'  => $i,
                'date'   => $date,
                'title'  => $timeline[$i][1],
                'text'   => $timeline[$i][2],
                'ts'     => $ts,
                'price'  => $p[$at],
                'change' => $change,
                'change90' => $change90,
                'window' => $hasYear ? '۱۲ ماه بعد' : 'تا امروز',
            ];
            $byIndex[$i] = count($list);
            $list[] = $event;
        }

        /* The event the page opens on: the most recent one with a full year
           after it, so its "what happened next" figure is a complete one. */
        $featured = null;
        foreach ($list as $k => $e) {
            if ($e['window'] === '۱۲ ماه بعد') {
                $featured = $k;
            }
        }
        $featured ??= $list === [] ? null : count($list) - 1;

        return ['list' => $list, 'byIndex' => $byIndex, 'featured' => $featured];
    }

    private function priceStory(array $m, array $long, array $protocols, array $events): array
    {
        $W = 960.0;
        $H = 360.0;
        $t = (array) ($long['data']['t'] ?? []);
        $p = (array) ($long['data']['price'] ?? []);
        $n = count($t);

        if ($n < 2) {
            return ['state' => $long['state'], 'source' => $long['source'], 'events' => [], 'client' => null];
        }

        $t0 = $t[0];
        $t1 = $t[$n - 1];
        $x = static fn(int $ts): float => round(($ts - $t0) / max(1, $t1 - $t0) * $W, 1);

        /* Log scale for price: a coin that went from $1 to $4,000 is unreadable
           on a linear axis — everything before the last cycle is a flat line. */
        $clean = array_filter($p, static fn($v) => $v > 0);
        $lo = min($clean);
        $hi = max($clean);
        $y = static fn(float $v): float => round($H - 12 - Svg::logScale($v, $lo, $hi, 0, $H - 40), 1);

        $pts = [];
        foreach ($t as $i => $ts) {
            if ($p[$i] > 0) {
                $pts[] = [$x($ts), $y((float) $p[$i])];
            }
        }
        $line = Svg::pathOf($pts);
        $area = $line . sprintf(' L%s %s L%s %s Z', end($pts)[0], $H, $pts[0][0], $H);

        $markers = [];
        /* Labels sit in rows above the line, each with an arrow down to the
           price on the day. A label goes in the first row where it clears the
           previous label by its own width; four rows hold the densest decade. */
        $rowEnds = [];
        foreach ($events['list'] as $k => $e) {
            $mx = $x($e['ts']);
            $row = 0;
            while (isset($rowEnds[$row]) && $mx - $rowEnds[$row] < 150 && $row < 3) {
                $row++;
            }
            $rowEnds[$row] = $mx;
            $markers[] = $e + [
                'x' => $mx, 'y' => $y((float) $e['price']), 'key' => $k,
                'row' => $row, 'ly' => -196 + $row * 46,   // label rows above the plot area
                'c90' => $e['change90'] !== null ? Format::pct($e['change90'], true, 0) : null,
                'dir90' => Format::direction($e['change90']),
            ];
        }

        /* TVL layer, on its own linear scale: it is a different quantity and
           only its shape over time is being compared with price. */
        $tvlPath = '';
        $tvl = array_values(array_filter(
            (array) ($protocols['data']['tvlWeekly'] ?? []),
            static fn($r) => is_array($r) && $r[0] >= $t0 && $r[0] <= $t1 && $r[1] > 0
        ));
        if (count($tvl) >= 2) {
            $tmax = max(array_column($tvl, 1));
            $tvlPts = array_map(static fn($r) => [$x((int) $r[0]), round($H - 12 - ($H - 40) * 0.55 * $r[1] / $tmax, 1)], $tvl);
            $tvlPath = Svg::pathOf($tvlPts);
        }

        $articles = [];
        foreach ((array) ($m['news'] ?? []) as $a) {
            $ts = strtotime((string) ($a['publishedAt'] ?? '')) ?: null;
            if ($ts !== null && $ts >= $t0 && $ts <= $t1) {
                $articles[] = ['x' => $x($ts), 'title' => $a['title'] ?? '', 'url' => $a['url'] ?? '#'];
            }
        }

        $years = [];
        for ($yr = (int) gmdate('Y', $t0) + 1; $yr <= (int) gmdate('Y', $t1); $yr++) {
            $years[] = ['label' => (string) $yr, 'x' => $x((int) strtotime("$yr-01-01 UTC"))];
        }

        // Powers of ten: the only honest gridlines on a log axis.
        $ticks = [];
        for ($e = (int) ceil(log10($lo)); $e <= (int) floor(log10($hi)); $e++) {
            $v = 10 ** $e;
            $ticks[] = ['label' => $v < 1 ? Format::usd((float) $v) : Format::usdCompact((float) $v, 0), 'y' => $y((float) $v)];
        }

        $featured = $events['featured'];

        return [
            'state'    => $long['state'],
            'source'   => $long['source'],
            'fetchedAt' => $long['fetchedAt'],
            'line'     => $line,
            'area'     => $area,
            'tvl'      => $tvlPath,
            'tvlState' => $protocols['state'],
            'events'   => $markers,
            'featured' => $featured !== null ? $markers[$featured] : null,
            'articles' => $articles,
            'years'    => $years,
            'ticks'    => $ticks,
            'from'     => gmdate('Y', $t0),
            'client'   => [
                'events' => array_map(static fn($e) => [
                    'title' => $e['title'], 'text' => $e['text'], 'date' => $e['date'],
                    'change' => $e['change'] !== null ? Format::pct($e['change'], true, 0) : null,
                    'dir' => Format::direction($e['change']), 'window' => $e['window'],
                    'price' => Format::usd((float) $e['price']),
                ], $markers),
            ],
        ];
    }

    /* ------------------------------------------------------------------
     * Daily pulse — a dateline, four sentences, and where each came from
     * ---------------------------------------------------------------- */

    private function pulse(Coin $coin, array $m, array $protocols, array $peers): array
    {
        $name = (string) ($m['coin']['name'] ?? $coin->name);
        $mk = (array) ($m['market'] ?? []);
        $defi = (array) ($m['defi'] ?? []);
        $lines = [];

        if (isset($mk['change24h'], $mk['price'])) {
            $c = (float) $mk['change24h'];
            $lines[] = [
                'text' => sprintf('قیمت %s در ۲۴ ساعت گذشته %s و به %s رسید.', $name,
                    abs($c) < 0.5 ? 'تقریباً ثابت ماند' : ($c > 0 ? 'افزایش یافت' : 'کاهش یافت'),
                    self::iso(Format::usd((float) $mk['price']))),
                'figure' => Format::pct($c), 'dir' => Format::direction($c),
            ];
        }
        if (isset($defi['tvl'], $defi['tvlChange7d'])) {
            $lines[] = [
                'text' => sprintf('ارزش قفل‌شده در دیفای این زنجیره در هفت روز گذشته به %s رسید.', self::iso(Format::usdCompact((float) $defi['tvl']))),
                'figure' => Format::pct((float) $defi['tvlChange7d']), 'dir' => Format::direction((float) $defi['tvlChange7d']),
            ];
        }
        if (isset($defi['fees24h'])) {
            $lines[] = [
                'text' => sprintf('کاربران در ۲۴ ساعت گذشته %s کارمزد پرداختند.', self::iso(Format::usdCompact((float) $defi['fees24h']))),
                'figure' => isset($defi['feesChange24h']) ? Format::pct((float) $defi['feesChange24h']) : null,
                'dir' => Format::direction($defi['feesChange24h'] ?? null),
            ];
        }
        $active = $this->cm('AdrActCnt');
        if ($active && $active['weekAgo']) {
            $c7 = self::change($active['latest'], $active['weekAgo']);
            $lines[] = [
                'text' => sprintf('%s آدرس روز گذشته روی شبکه فعال بودند.', self::iso(Format::num($active['latest'], 0))),
                'figure' => Format::pct($c7, true, 1) . ' هفتگی', 'dir' => Format::direction($c7),
            ];
        }
        $in = $this->cm('FlowInExNtv');
        $outFlow = $this->cm('FlowOutExNtv');
        if ($in && $outFlow && $in['date'] === $outFlow['date']) {
            $net = $in['latest'] - $outFlow['latest'];
            $lines[] = [
                'text' => sprintf($net < 0 ? 'روز گذشته خالص %s از صرافی‌ها خارج شد.' : 'روز گذشته خالص %s به صرافی‌ها وارد شد.',
                    self::iso(Format::compact(abs($net), 1) . ' ' . (string) ($m['coin']['symbol'] ?? $coin->symbol))),
                'figure' => null, 'dir' => $net < 0 ? 'up' : 'down',
            ];
        }
        $s = (array) ($m['sentiment']['data'] ?? []);
        if (isset($s['value'])) {
            $lines[] = [
                'text' => sprintf('شاخص ترس و طمع کل بازار روی «%s» است.', (string) ($s['labelFa'] ?? '')),
                'figure' => Format::num((float) $s['value'], 0) . '/100', 'dir' => 'flat',
            ];
        }

        $chips = [];
        foreach (self::signals($m) as $sig) {
            if ($sig['tone'] !== 'neutral') {
                $chips[] = ['text' => $sig['label'] . ($sig['tone'] === 'positive' ? ' رو به افزایش' : ' رو به کاهش'), 'tone' => $sig['tone']];
            }
        }

        // Ecosystem heat: today's fees by protocol group, as shares.
        $heat = [];
        $rows = (array) ($protocols['data']['protocols'] ?? []);
        $byGroup = [];
        foreach ($rows as $p) {
            $g = self::groupOf((string) $p['category']);
            $byGroup[$g] = ($byGroup[$g] ?? 0) + (float) $p['fees24h'];
        }
        $sum = array_sum($byGroup);
        arsort($byGroup);
        foreach (array_slice($byGroup, 0, 5, true) as $g => $v) {
            $heat[] = ['label' => self::GROUP_LABELS[$g], 'share' => $sum > 0 ? $v / $sum * 100 : 0];
        }

        $generated = $m['meta']['generatedAt'] ?? gmdate('c', time());

        return [
            'date'   => Format::jalaliLong($generated),
            'time'   => Format::tehranTime($generated),
            'lines'  => $lines,
            'chips'  => array_slice($chips, 0, 4),
            'heat'   => $heat,
            'heatState' => $protocols['state'],
        ];
    }

    /* ------------------------------------------------------------------
     * Peers — the bubble chart and the comparison table
     * ---------------------------------------------------------------- */

    private function peers(Coin $coin, array $m, array $peers): array
    {
        $rows = (array) ($peers['data']['peers'] ?? []);
        if ($rows === []) {
            // One coin compared with nothing is not a comparison.
            return ['state' => $peers['state'], 'source' => $peers['source'], 'fetchedAt' => $peers['fetchedAt'],
                    'rows' => [], 'table' => [], 'insight' => null, 'hasTvl' => false, 'client' => []];
        }
        $tvlByGecko = [];
        foreach ((array) ($m['chains']['data']['chains'] ?? []) as $c) {
            if (!empty($c['geckoId']) && isset($c['tvl'])) {
                $tvlByGecko[$c['geckoId']] = (float) $c['tvl'];
            }
        }

        // This coin is always in the comparison, with its own (fresher) figures.
        $self = $coin->coingeckoId;
        $mk = (array) ($m['market'] ?? []);
        $found = false;
        $list = [];
        foreach ($rows as $r) {
            if ($r['id'] === $self) {
                $found = true;
                $r['marketCap'] = $mk['marketCap'] ?? $r['marketCap'];
                $r['volume24h'] = $mk['volume24h'] ?? $r['volume24h'];
            }
            $list[] = $r;
        }
        if (!$found && isset($mk['marketCap'])) {
            array_unshift($list, [
                'id' => $self, 'symbol' => $coin->symbol, 'name' => $coin->nameEn ?: $coin->symbol,
                'marketCap' => $mk['marketCap'], 'volume24h' => $mk['volume24h'] ?? null,
                'change30d' => $m['performance']['30d'] ?? null, 'sparkline' => [],
            ]);
        }

        $out = [];
        foreach ($list as $r) {
            $tvl = $tvlByGecko[$r['id']] ?? null;
            $spark = Svg::clean((array) ($r['sparkline'] ?? []));
            $range = count($spark) >= 2 && min($spark) > 0 ? (max($spark) - min($spark)) / min($spark) * 100 : null;
            $out[] = [
                'id'        => $r['id'],
                'symbol'    => $r['symbol'],
                'name'      => $r['name'] ?? $r['symbol'],
                'self'      => $r['id'] === $self,
                'marketCap' => $r['marketCap'],
                'volume'    => $r['volume24h'] ?? null,
                'tvl'       => $tvl,
                'tvlToMcap' => $tvl !== null && ($r['marketCap'] ?? 0) > 0 ? $tvl / $r['marketCap'] : null,
                'volToMcap' => isset($r['volume24h']) && ($r['marketCap'] ?? 0) > 0 ? $r['volume24h'] / $r['marketCap'] : null,
                'change30d' => $r['change30d'] ?? null,
                'range7d'   => $range,
            ];
        }

        // Bubble positions: y = market cap, x = TVL (or volume when swapped), both log.
        $caps = array_filter(array_column($out, 'marketCap'), static fn($v) => $v > 0);
        $tvls = array_filter(array_column($out, 'tvl'), static fn($v) => $v > 0);
        $vols = array_filter(array_column($out, 'volume'), static fn($v) => $v > 0);
        $bounds = static fn(array $v): array => $v === [] ? [1, 10] : [min($v) / 1.8, max($v) * 1.8];
        [$cLo, $cHi] = $bounds($caps);
        [$tLo, $tHi] = $bounds($tvls);
        [$vLo, $vHi] = $bounds($vols);
        $vmax = $vols === [] ? 1 : max($vols);

        foreach ($out as &$r) {
            $r['y']  = 440 - Svg::logScale($r['marketCap'], $cLo, $cHi, 0, 400);
            $r['xT'] = $r['tvl'] !== null ? 60 + Svg::logScale($r['tvl'], $tLo, $tHi, 0, 560) : null;
            $r['xV'] = $r['volume'] !== null ? 60 + Svg::logScale($r['volume'], $vLo, $vHi, 0, 560) : null;
            $r['r']  = round(12 + 26 * sqrt(($r['volume'] ?? 0) / $vmax), 1);
        }
        unset($r);

        /* Which other coins to set beside this one in the table: the largest
           by market cap, as the reader's most likely comparison. */
        $others = array_values(array_filter($out, static fn($r) => !$r['self']));
        usort($others, static fn($a, $b) => ($b['marketCap'] ?? 0) <=> ($a['marketCap'] ?? 0));
        $selfRow = array_values(array_filter($out, static fn($r) => $r['self']))[0] ?? null;
        $table = $selfRow ? array_merge([$selfRow], array_slice($others, 0, 3)) : array_slice($others, 0, 4);

        // One sentence, computed: where this coin ranks on TVL per dollar of market cap.
        $insight = null;
        $withTvl = array_values(array_filter($out, static fn($r) => $r['tvlToMcap'] !== null));
        if ($selfRow && $selfRow['tvlToMcap'] !== null && count($withTvl) >= 3) {
            usort($withTvl, static fn($a, $b) => $b['tvlToMcap'] <=> $a['tvlToMcap']);
            $rank = array_search($self, array_column($withTvl, 'id'), true) + 1;
            $insight = sprintf(
                'نسبت TVL به ارزش بازار %s رتبه %s در میان %s دارایی مقایسه‌شده است.',
                (string) ($m['coin']['name'] ?? $coin->name),
                self::iso(Format::num((float) $rank, 0)),
                self::iso(Format::num((float) count($withTvl), 0))
            );
        }

        return [
            'state'   => $peers['state'],
            'source'  => $peers['source'],
            'fetchedAt' => $peers['fetchedAt'],
            'rows'    => $out,
            'table'   => $table,
            'insight' => $insight,
            'hasTvl'  => $tvls !== [],
            'client'  => array_map(static fn($r) => [
                'symbol' => $r['symbol'], 'xT' => $r['xT'], 'xV' => $r['xV'], 'y' => $r['y'],
            ], array_values(array_filter($out, static fn($r) => $r['marketCap'] !== null))),
        ];
    }

    /* ------------------------------------------------------------------
     * Supply & real risk
     * ---------------------------------------------------------------- */

    private function supply(Coin $coin, array $m, ?float $vol, ?float $corr, array $bench): array
    {
        $sc = (array) ($m['supplyChain'] ?? []);
        $sd = (array) ($sc['data'] ?? []);
        $mk = (array) ($m['market'] ?? []);
        $staking = (array) ($m['staking'] ?? []);

        $slices = [];
        if ($sc['available'] ?? false) {
            $stakedShare = $this->stakedShare($m);
            if ($stakedShare !== null) {
                $slices[] = ['label' => 'در استیکینگ', 'pct' => $stakedShare, 'tone' => 'violet', 'source' => 'beaconcha.in / Etherscan'];
            }
            if (isset($sd['burnedPct'])) {
                $slices[] = ['label' => 'سوزانده‌شده (نسبت به عرضه فعلی)', 'pct' => (float) $sd['burnedPct'], 'tone' => 'amber', 'source' => 'Etherscan'];
            }
        } elseif (isset($mk['circulatingSupply'], $mk['maxSupply']) && $mk['maxSupply'] > 0) {
            // A capped supply has a real, simple split: issued vs still to come.
            $issued = (float) $mk['circulatingSupply'] / (float) $mk['maxSupply'] * 100;
            $slices[] = ['label' => 'منتشرشده از سقف عرضه', 'pct' => $issued, 'tone' => 'violet', 'source' => 'CoinGecko'];
            $slices[] = ['label' => 'باقی‌مانده تا سقف', 'pct' => 100 - $issued, 'tone' => 'amber', 'source' => 'CoinGecko'];
        }

        /* Where the rest sits — each slice measured, each named for exactly
           what it is. A slice whose source did not answer is listed below the
           bars by name as unavailable, never estimated. */
        $total = isset($sd['totalSupply']) ? (float) $sd['totalSupply']
            : ($this->cm('SplyCur')['latest'] ?? ($mk['circulatingSupply'] ?? null));
        $missing = [];
        $loc = (array) ($this->x['ethlocations']['data'] ?? []);
        $isEthereum = ($m['supplyChain']['state'] ?? '') !== 'not_applicable';
        if ($isEthereum) {
            if ($total && isset($loc['weth'])) {
                $slices[] = ['label' => 'رپ‌شده به WETH (برای دیفای)', 'pct' => $loc['weth'] / $total * 100, 'tone' => 'green', 'source' => 'Etherscan'];
            } else {
                $missing[] = ['رپ‌شده به WETH', 'از Etherscan هنوز نرسیده'];
            }
            if ($total && isset($loc['bridgesTotal'])) {
                $slices[] = ['label' => 'در پل‌های رسمی لایه ۲ (' . implode('، ', array_column($loc['bridges'], 'label')) . ')',
                             'pct' => $loc['bridgesTotal'] / $total * 100, 'tone' => 'blue', 'source' => 'Etherscan'];
            } else {
                $missing[] = ['در پل‌های لایه ۲', 'از Etherscan هنوز نرسیده'];
            }
        }
        $ex = $this->cm('SplyExNtv');
        $cur = $this->cm('SplyCur');
        $onExchanges = $this->exchangeShare($total);
        if ($onExchanges !== null) {
            $slices[] = ['label' => $onExchanges['label'], 'pct' => $onExchanges['pct'], 'tone' => 'pink', 'source' => $onExchanges['source']];
        } else {
            $missing[] = ['روی صرافی‌های متمرکز', 'نه CoinMetrics پاسخ داد و نه موجودی کیف پول‌های صرافی‌ها از Etherscan رسید'];
        }
        $act = $this->cm('SplyAct1yr');
        if ($act && $cur && $cur['latest'] > 0) {
            $slices[] = ['label' => 'بدون جابه‌جایی در یک سال اخیر (نگهدارندگان بلندمدت)',
                         'pct' => max(0.0, 100 - $act['latest'] / $cur['latest'] * 100), 'tone' => 'teal', 'source' => 'CoinMetrics'];
        } else {
            $missing[] = ['کیف پول‌های بلندمدت', 'سن کوین‌ها را رایگان فقط CoinMetrics منتشر می‌کند و هنوز از آن پاسخی نرسیده'];
        }

        $aprInfo = $this->stakingApr($m);
        $apr = $aprInfo['value'] ?? null;
        $inflation = $this->inflation();

        $dd = $m['derived']['drawdownFromAth']['value'] ?? null;
        $hhi = ($m['context']['cexConcentration']['available'] ?? false) ? (float) $m['context']['cexConcentration']['value'] : null;

        $risk = [
            self::risk('فاصله از سقف تاریخی', $dd !== null ? Format::pct(abs((float) $dd), false, 0) : null,
                $dd === null ? null : (abs($dd) < self::ATH_CALM ? 'ok' : (abs($dd) < self::ATH_WATCH ? 'warn' : 'alert')), 'CoinGecko'),
            self::risk('نوسان سالانه‌شده (۳۰ روز)', $vol !== null ? Format::pct($vol, false, 0) : null,
                $vol === null ? null : ($vol < self::VOL_CALM ? 'ok' : ($vol < self::VOL_WATCH ? 'warn' : 'alert')), 'CoinGecko',
                $vol === null ? 'سری قیمت یک‌ساله هنوز دریافت نشده' : null),
            self::risk('همبستگی با بیت‌کوین (۹۰ روز)', $corr !== null ? Format::ratio($corr, 2) : null, $corr === null ? null : 'info', 'CoinGecko',
                $bench['state'] === 'self' ? 'خود این ارز معیار است' : ($corr === null ? 'سری قیمت بیت‌کوین هنوز در حافظه نیست' : null)),
            self::risk('تمرکز صرافی‌ها (شاخص هرفیندال)', $hhi !== null ? Format::num($hhi, 0) : null,
                $hhi === null ? null : ($hhi < self::HHI_CALM ? 'ok' : ($hhi < self::HHI_WATCH ? 'warn' : 'alert')), 'CoinGecko',
                $hhi === null ? 'داده بازارهای صرافی هنوز دریافت نشده' : null),
            $this->exchangeRisk($onExchanges),
            $this->unlocks($coin),
        ];

        $audience = [];
        foreach (self::parseLines((string) $coin->meta('audience', ''), 3) as [$who, $fit, $text]) {
            $audience[] = ['who' => $who, 'fit' => $fit, 'text' => $text];
        }

        return [
            'state'   => $sc['state'] ?? 'unavailable',
            'slices'  => $slices,
            'missing' => $missing,
            'apr'     => $apr,
            'aprSource' => $aprInfo['detail'] ?? null,
            'aprKind'  => $aprInfo['kind'] ?? null,
            'aprState' => $staking['state'] ?? 'unavailable',
            'inflation' => $inflation,
            'inflationDate' => $cur['date'] ?? null,
            'realYield' => $apr !== null && $inflation !== null ? $apr - $inflation : null,
            'validators' => $staking['data']['validators'] ?? null,
            'risk'    => $risk,
            'audience' => $audience,
        ];
    }

    /* Share of supply on exchanges, as a concentration risk. */
    public const CEX_CALM  = 10.0;
    public const CEX_WATCH = 20.0;

    private static function exchangeTone(float $pct): string
    {
        return $pct < self::CEX_CALM ? 'ok' : ($pct < self::CEX_WATCH ? 'warn' : 'alert');
    }

    /**
     * Token unlocks. Editorial: no free source publishes vesting schedules
     * for every token (TokenUnlocks and DefiLlama's emissions data sit behind
     * paid APIs), and for coins with nothing locked — Ethereum, Bitcoin — the
     * answer is a fact an editor can state once. "ندارد" at the start reads
     * as calm; anything else is shown as written, for the reader to judge.
     */
    private function unlocks(Coin $coin): array
    {
        $text = trim((string) $coin->meta('unlocks', ''));
        if ($text === '') {
            return self::risk('تقویم آزادسازی توکن', null, null, null,
                'برای این ارز در پیکربندی ارز نوشته نشده است (فیلد «برنامه آزادسازی توکن»)');
        }
        [$head, $tail] = array_pad(array_map('trim', preg_split('/\s+—\s+|\s+-\s+/u', $text, 2) ?: [$text]), 2, '');
        $none = str_starts_with($head, 'ندارد');
        $row = self::risk('تقویم آزادسازی توکن', $head, $none ? 'ok' : 'info', 'تحریریه');
        $row['detail'] = $tail !== '' ? $tail : null;
        return $row;
    }

    /** Every dataset the page drew from, and when it was fetched. */
    private function sources(array $m, array $extra): array
    {
        $rows = [
            ['قیمت و بازار', 'CoinGecko', $m['meta']['staleness']['oldestFetchedAt'] ?? null],
            ['ارزش قفل‌شده و کارمزد', 'DefiLlama', null],
            ['کارمزد شبکه (گس)', 'Etherscan', $m['gas']['fetchedAt'] ?? null],
            ['عرضه و استیکینگ', 'Etherscan / beaconcha.in', $m['staking']['fetchedAt'] ?? ($m['supplyChain']['fetchedAt'] ?? null)],
            ['فعالیت شبکه', 'Blockchair', $m['network']['fetchedAt'] ?? null],
            ['احساسات بازار', 'Alternative.me', $m['sentiment']['fetchedAt'] ?? null],
            ['همتایان', 'CoinGecko', $extra['peers']['fetchedAt']],
            ['پروتکل‌ها', 'DefiLlama', $extra['protocols']['fetchedAt']],
            ['قیمت از ابتدا', 'DefiLlama', $extra['longchart']['fetchedAt']],
            ['فعالیت توسعه', 'GitHub', $m['development']['fetchedAt'] ?? null],
            ['آدرس‌های فعال و عرضه', 'CoinMetrics', $extra['chainstats']['fetchedAt'] ?? null],
            ['انتقال‌های بزرگ', 'Blockchair', $extra['whales']['fetchedAt'] ?? null],
            ['WETH و پل‌های لایه ۲', 'Etherscan', $extra['ethlocations']['fetchedAt'] ?? null],
            ['بازده stETH', 'Lido', $extra['lidoapr']['fetchedAt'] ?? null],
            ['علاقه عمومی', 'Wikipedia', $extra['interest']['fetchedAt'] ?? null],
        ];
        return array_map(static fn($r) => [
            'what' => $r[0], 'who' => $r[1], 'at' => $r[2],
            'time' => $r[2] ? Format::tehranTime($r[2]) : null,
        ], $rows);
    }

    /* ==================================================================
     * Cache access — peeks only
     * ================================================================ */

    /**
     * A v2 dataset's state, read from the cache WITHOUT fetching.
     *
     * @return array{state:string, data:mixed, source:string, fetchedAt:?string}
     */
    private function peek(string $dataset, Coin $coin, string $label): array
    {
        $provider = $this->sets->provider($dataset);
        $out = ['state' => 'unavailable', 'data' => null, 'source' => $label, 'fetchedAt' => null];

        if ($provider === null || !$coin->usesProvider($provider, $this->config) || !$this->sets->isEnabled($dataset)) {
            return ['state' => 'disabled'] + $out;
        }
        if (!$this->sets->servesChain($provider, $coin)) {
            return ['state' => 'not_applicable'] + $out;
        }

        $entry = $this->cache->peek($dataset, $this->cache->keyFor($dataset, $coin));
        if (!is_array($entry)) {
            return ['state' => 'pending'] + $out;
        }
        if (!is_array($entry['data'] ?? null)) {
            return ['state' => !empty($entry['miss']) ? 'unavailable' : 'pending'] + $out;
        }

        $age = time() - (int) ($entry['fetchedAt'] ?? 0);
        return [
            'state'     => $age > $this->cache->ttl($dataset) ? 'stale' : 'ok',
            'data'      => $entry['data'],
            'source'    => $label,
            'fetchedAt' => gmdate('c', (int) $entry['fetchedAt']),
        ];
    }

    /**
     * The benchmark's (Bitcoin's) one-year series, for correlation — read
     * from the cache the scheduler fills for the benchmark's own page.
     */
    private function benchmarkYear(Coin $coin): array
    {
        $b = (array) ($this->config['benchmark'] ?? []);
        if (($b['slug'] ?? '') === '' || $coin->slug === $b['slug']) {
            return ['state' => 'self', 'data' => null];
        }
        $key = sprintf('%s:%s:%s', 'chart.1y', $b['slug'], $b['coingeckoId'] ?? $b['slug']);
        $entry = $this->cache->peek('chart.1y', $key);
        return is_array($entry['data'] ?? null)
            ? ['state' => 'ok', 'data' => $entry['data']]
            : ['state' => 'pending', 'data' => null];
    }

    /* ==================================================================
     * Arithmetic
     * ================================================================ */

    /**
     * Daily log returns keyed by UTC day number, so two coins' series can be
     * aligned on the same days before they are compared.
     *
     * @return array<int, float>
     */
    public static function dailyReturns(?array $series): array
    {
        $t = (array) ($series['t'] ?? []);
        $p = (array) ($series['price'] ?? []);
        $byDay = [];
        foreach ($t as $i => $ms) {
            if (isset($p[$i]) && is_numeric($p[$i]) && $p[$i] > 0) {
                $byDay[intdiv((int) $ms, 86_400_000)] = (float) $p[$i];   // last price of each day
            }
        }
        ksort($byDay);
        $out = [];
        $prev = null;
        foreach ($byDay as $day => $price) {
            if ($prev !== null) {
                $out[$day] = log($price / $prev);
            }
            $prev = $price;
        }
        return $out;
    }

    /** Standard deviation of daily returns × √365, in percent. Null under 20 days. */
    public static function annualisedVolatility(array $returns): ?float
    {
        $r = array_values($returns);
        $n = count($r);
        if ($n < 20) {
            return null;
        }
        $mean = array_sum($r) / $n;
        $var = array_sum(array_map(static fn($x) => ($x - $mean) ** 2, $r)) / ($n - 1);
        return sqrt($var) * sqrt(365) * 100;
    }

    /** Pearson correlation of returns on the days both series share (last 90). */
    public static function correlation(array $a, array $b): ?float
    {
        $days = array_slice(array_values(array_intersect(array_keys($a), array_keys($b))), -90);
        $n = count($days);
        if ($n < 30) {
            return null;
        }
        $x = array_map(static fn($d) => $a[$d], $days);
        $y = array_map(static fn($d) => $b[$d], $days);
        $mx = array_sum($x) / $n;
        $my = array_sum($y) / $n;
        $sxy = $sxx = $syy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sxy += ($x[$i] - $mx) * ($y[$i] - $my);
            $sxx += ($x[$i] - $mx) ** 2;
            $syy += ($y[$i] - $my) ** 2;
        }
        // A series that never moved has no correlation with anything.
        if ($sxx <= 1e-18 || $syy <= 1e-18) {
            return null;
        }
        return $sxy / sqrt($sxx * $syy);
    }

    /* ==================================================================
     * Small helpers
     * ================================================================ */

    /**
     * A number for use INSIDE a Persian sentence. Wrapped in Unicode isolate
     * marks (LRI … PDI), so "$3,245.67" cannot be reordered into "3,245.67$"
     * by the surrounding right-to-left text — the plain-text equivalent of the
     * <bdi> the templates use around standalone figures.
     */
    public static function iso(string $formatted): string
    {
        return "\u{2066}" . $formatted . "\u{2069}";
    }

    /** Hashes per second in the unit miners use: 650 EH/s, not 650,000,000T. */
    public static function hashrate(float $hs): string
    {
        foreach (['EH/s' => 1e18, 'PH/s' => 1e15, 'TH/s' => 1e12, 'GH/s' => 1e9, 'MH/s' => 1e6] as $unit => $size) {
            if ($hs >= $size) {
                return Format::num($hs / $size, 1) . ' ' . $unit;
            }
        }
        return Format::num($hs, 0) . ' H/s';
    }

    /** The site's name, from WordPress, with the brand as a fallback. */
    public static function brand(): string
    {
        $name = function_exists('get_bloginfo') ? trim((string) get_bloginfo('name')) : '';
        return $name !== '' ? $name : 'های‌بیت';
    }

    /**
     * "a | b | c" lines into arrays of exactly $fields strings. Blank lines and
     * lines missing a required part are skipped rather than rendered half empty.
     *
     * @return array<int, string[]>
     */
    public static function parseLines(string $text, int $fields): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line, $fields));
            if (count($parts) < $fields || in_array('', array_slice($parts, 0, 2), true)) {
                continue;
            }
            $out[] = $parts;
        }
        return $out;
    }

    private static function groupOf(string $category): string
    {
        foreach (self::GROUPS as $group => $cats) {
            if (in_array($category, $cats, true)) {
                return $group;
            }
        }
        return 'other';
    }

    private static function nearest(array $t, int $ts): int
    {
        $best = 0;
        $bestD = PHP_INT_MAX;
        foreach ($t as $i => $v) {
            $d = abs($v - $ts);
            if ($d < $bestD) {
                $best = $i;
                $bestD = $d;
            }
        }
        return $best;
    }

    /** Bar heights for a small column chart. @return array<int, array{x:float,y:float,h:float,w:float}> */
    private static function bars(array $values, float $w, float $h): array
    {
        $n = count($values);
        $max = $n ? max($values) : 0;
        if ($n === 0 || $max <= 0) {
            return [];
        }
        $slot = $w / $n;
        $out = [];
        foreach (array_values($values) as $i => $v) {
            $bh = max(2.0, ($h - 4) * $v / $max);
            // RTL: the largest bar sits on the right, where reading starts.
            $out[] = ['x' => round($w - ($i + 1) * $slot + $slot * 0.18, 1), 'y' => round($h - $bh, 1), 'h' => round($bh, 1), 'w' => round($slot * 0.64, 1)];
        }
        return $out;
    }

    private static function metric(string $label, ?string $value, ?float $change, ?string $span, ?string $source, ?string $note, ?string $detail = null): array
    {
        return [
            'label'  => $label,
            'value'  => $value,
            'change' => $change,
            'span'   => $span,
            'source' => $source,
            'detail' => $value !== null ? $detail : null,
            'note'   => $value === null ? $note : null,
        ];
    }

    /* ==================================================================
     * Second-round sources
     * ================================================================ */

    /**
     * The "narrative" card: public interest as Wikipedia readership.
     * Persian first — for this site's readers it is the more telling figure —
     * with the English article as the fallback.
     */
    private function interestCard(): array
    {
        $d = (array) ($this->x['interest']['data'] ?? []);
        foreach (['fa' => 'ویکی‌پدیای فارسی', 'en' => 'ویکی‌پدیای انگلیسی'] as $lang => $where) {
            if (!isset($d[$lang]['last7'])) {
                continue;
            }
            $row = $d[$lang];
            $other = $lang === 'fa' ? ($d['en']['last7'] ?? null) : null;
            return [
                'title' => 'روایت (علاقه عمومی)',
                'value' => Format::compact((float) $row['last7'], 1),
                'sub'   => 'بازدید ' . $where . ' در ۷ روز'
                    . ($row['change'] !== null ? ' · ' . Format::pct((float) $row['change'], true, 0) . ' نسبت به هفته قبل' : '')
                    . ($other !== null ? ' · انگلیسی ' . Format::compact((float) $other, 1) : ''),
            ];
        }
        return ['title' => 'روایت (علاقه عمومی)', 'value' => null, 'sub' => null,
                'note' => self::stateNote($this->x['interest']['state'] ?? 'pending') . ' — بازدید ویکی‌پدیا (Wikimedia)'];
    }

    /** Staked share of supply: beaconcha.in's staked balance over Etherscan's supply. */
    private function stakedShare(array $m): ?float
    {
        $staked = $m['staking']['data']['stakedEth'] ?? null;
        $total = $m['supplyChain']['data']['totalSupply'] ?? ($m['market']['circulatingSupply'] ?? null);
        return is_numeric($staked) && is_numeric($total) && $total > 0 ? $staked / $total * 100 : null;
    }

    /**
     * Share of supply on exchanges: CoinMetrics' figure for all exchange
     * wallets when available; otherwise the measured balances of the largest
     * labelled exchange wallets (Etherscan) — a floor, and labelled as one.
     *
     * @return array{pct:float, label:string, source:string, floor:bool, top:?array}|null
     */
    private function exchangeShare(?float $total): ?array
    {
        $ex = $this->cm('SplyExNtv');
        $cur = $this->cm('SplyCur');
        if ($ex && $cur && $cur['latest'] > 0) {
            return ['pct' => $ex['latest'] / $cur['latest'] * 100, 'label' => 'روی صرافی‌های متمرکز', 'source' => 'CoinMetrics', 'floor' => false, 'top' => null];
        }
        $loc = (array) ($this->x['ethlocations']['data'] ?? []);
        if ($total && !empty($loc['exchangesTotal'])) {
            $byExchange = [];
            foreach ((array) $loc['exchanges'] as $w) {
                $byExchange[$w['exchange']] = ($byExchange[$w['exchange']] ?? 0) + $w['eth'];
            }
            arsort($byExchange);
            $topName = array_key_first($byExchange);
            return [
                'pct'    => $loc['exchangesTotal'] / $total * 100,
                'label'  => 'در ' . Format::num((float) count($loc['exchanges']), 0) . ' کیف پول بزرگ صرافی‌ها (حداقل)',
                'source' => 'Etherscan',
                'floor'  => true,
                'top'    => ['name' => $topName, 'pct' => $byExchange[$topName] / $total * 100, 'count' => count($byExchange), 'wallets' => count($loc['exchanges'])],
            ];
        }
        return null;
    }

    private function exchangeRisk(?array $share): array
    {
        if ($share === null) {
            return self::risk('تمرکز روی صرافی‌ها', null, null, null,
                'نه CoinMetrics این شاخص را برگرداند و نه موجودی کیف پول‌های صرافی‌ها از Etherscan رسید');
        }
        $row = self::risk($share['floor'] ? 'تمرکز روی صرافی‌ها (حداقل)' : 'تمرکز روی صرافی‌ها', Format::pct($share['pct'], false, 1),
            self::exchangeTone($share['pct']), $share['source']);
        $row['detail'] = $share['floor']
            ? sprintf('موجودی %s کیف پول شناخته‌شده از %s صرافی؛ بیشترین: %s با %s از کل عرضه. کیف پول‌های واریز بی‌شمارند، پس عدد واقعی بیشتر است.',
                Format::num((float) $share['top']['wallets'], 0), Format::num((float) $share['top']['count'], 0), $share['top']['name'], Format::pct($share['top']['pct'], false, 1))
            : 'همه کیف پول‌های صرافی‌ها که CoinMetrics شناسایی کرده است';
        return $row;
    }

    /** One CoinMetrics metric: {latest, date, weekAgo, series}, or null. */
    private function cm(string $metric): ?array
    {
        $m = $this->x['chainstats']['data']['metrics'][$metric] ?? null;
        return is_array($m) && isset($m['latest']) ? $m : null;
    }

    /** Percentage change, or null when there is nothing to compare with. */
    private static function change(?float $now, ?float $then): ?float
    {
        return $now !== null && $then !== null && $then > 0 ? ($now - $then) / $then * 100 : null;
    }

    /**
     * The staking yield, from the best source that answered — always named.
     *
     *   1. beaconcha.in: the network-wide realised APR (measured);
     *   2. Lido: stETH's 7-day average APR (measured, one provider's yield);
     *   3. the consensus-layer reward formula applied to the ETH actually
     *      staked (Etherscan) — the protocol's own arithmetic, labelled as a
     *      calculation and as the maximum, because it assumes every validator
     *      performs perfectly and excludes execution-layer tips and MEV.
     *
     * @return array{value:float, source:string, detail:string, kind:string}|null
     */
    private function stakingApr(array $m): ?array
    {
        $staking = (array) ($m['staking'] ?? []);
        if (($staking['state'] ?? '') === 'not_applicable') {
            return null;
        }
        if (($staking['available'] ?? false) && isset($staking['data']['apr'])) {
            return ['value' => (float) $staking['data']['apr'], 'source' => 'beaconcha.in',
                    'detail' => 'میانگین کل شبکه · beaconcha.in', 'kind' => 'measured'];
        }
        $lido = $this->x['lidoapr']['data']['smaApr'] ?? null;
        if (is_numeric($lido)) {
            return ['value' => (float) $lido, 'source' => 'Lido',
                    'detail' => 'بازده stETH لیدو، میانگین ۷ روزه', 'kind' => 'measured'];
        }
        $staked = $staking['data']['stakedEth'] ?? null;
        if (is_numeric($staked) && $staked > 0) {
            return ['value' => self::consensusApr((float) $staked), 'source' => 'محاسبه از فرمول پروتکل',
                    'detail' => 'حداکثر نظری لایه اجماع، از کل اتر سپرده‌شده (Etherscan)', 'kind' => 'formula'];
        }
        return null;
    }

    /**
     * Ethereum's consensus-layer reward rate for a given total stake, from
     * the protocol's own constants: each epoch an ETH of effective balance
     * earns BASE_REWARD_FACTOR (64) / √(total active balance in gwei) ETH,
     * and a year is 365.25 × 225 = 82,181.25 epochs. At 34M ETH staked this
     * is about 2.85%.
     */
    public static function consensusApr(float $stakedEth): float
    {
        return 64 * 82181.25 / sqrt($stakedEth * 1e9) * 100;
    }

    /**
     * Net supply inflation, annualised, from two CoinMetrics supply readings a
     * week apart — issuance minus burn, as it actually happened. Negative when
     * more was burned than issued.
     */
    private function inflation(): ?float
    {
        $s = $this->cm('SplyCur');
        if (!$s || !isset($s['weekAgo']) || $s['weekAgo'] <= 0) {
            return null;
        }
        return ($s['latest'] - $s['weekAgo']) / $s['weekAgo'] * (365 / 7) * 100;
    }

    private static function risk(string $label, ?string $value, ?string $tone, ?string $source, ?string $note = null): array
    {
        return [
            'label'  => $label,
            'value'  => $value,
            'tone'   => $tone,
            'toneFa' => match ($tone) {
                'ok' => 'آرام', 'warn' => 'مراقب', 'alert' => 'هشدار', 'info' => 'اطلاعاتی', default => null,
            },
            'source' => $source,
            'note'   => $value === null ? ($note ?? 'داده در دسترس نیست') : null,
        ];
    }

    /** The visitor-facing sentence for a dataset that has no figure. */
    public static function stateNote(string $state): string
    {
        return match ($state) {
            'pending'        => 'در حال دریافت داده',
            'disabled'       => 'منبع این داده هنوز فعال نشده (کلید API در تنظیمات)',
            'not_applicable' => 'برای این شبکه کاربرد ندارد',
            default          => 'داده در دسترس نیست',
        };
    }
}
