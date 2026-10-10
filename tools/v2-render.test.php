<?php
/**
 * Design v2 — the switch, the page, and what it must never do.
 *
 *   php tools/v2-render.test.php
 *   THB_DUMP_HTML=/path/v2.html php tools/v2-render.test.php
 *
 * The classic page's own guarantees (byte-identical output, frozen files) live
 * in template-render.test.php. This file asserts the other half of the brief:
 *
 *   - the switch: classic by default, ?thb_design=v2 for one visit, the
 *     setting for everyone, and the parameter winning in both directions;
 *   - a parameterised URL is noindex everywhere a robots tag can come from;
 *   - all nine screens render server-side with one h1;
 *   - no figure is made up — the reference images' sample numbers never
 *     appear, and every source-less section says so;
 *   - a v2 render costs exactly what a classic render costs: Ethereum cold 7,
 *     warm 0;
 *   - the brand is the site's, and "Thinkbit" appears nowhere.
 */

declare(strict_types=1);

require_once __DIR__ . '/v2-bootstrap.php';

use TheHybit\Coins\{Plugin, Design, Settings, Format};
use TheHybit\Coins\V2\{Model, Svg};

$failed = 0;
function ok(bool $cond, string $msg): void
{
    global $failed;
    if (!$cond) { $failed++; }
    echo ($cond ? 'PASS' : 'FAIL') . "  {$msg}\n";
}
function section(string $s): void { echo "\n--- {$s} " . str_repeat('-', max(0, 58 - strlen($s))) . "\n"; }

$plugin = Plugin::instance();
$classicTpl = THB_COINS_DIR . 'templates/single-coin.php';
$v2Tpl = THB_COINS_DIR . 'templates/v2/single-coin.php';
$setDesign = static function (?string $design): void {
    $o = (array) get_option(Settings::OPTION, []);
    if ($design === null) { unset($o['design']); } else { $o['design'] = $design; }
    update_option(Settings::OPTION, $o);
};

/* =====================================================================
 * 1. The switch
 * ================================================================== */
section('design switch');

$_GET = [];
$setDesign(null);
ok(Design::current() === 'classic', 'with no setting and no parameter, the design is CLASSIC');
ok($plugin->template('x') === $classicTpl, 'and the classic template is served');

$_GET = ['thb_design' => 'v2'];
ok(Design::isV2(), '?thb_design=v2 switches this visit to v2');
ok($plugin->template('x') === $v2Tpl, 'and serves templates/v2/single-coin.php');

$_GET = ['thb_design' => 'V2<script>'];
ok(Design::current() === 'classic', 'an unrecognised parameter value falls back to the setting');

$_GET = [];
$setDesign('v2');
ok(Design::isV2(), 'the site-wide setting "v2" serves v2 to everyone');
$_GET = ['thb_design' => 'classic'];
ok(!Design::isV2() && $plugin->template('x') === $classicTpl,
   '?thb_design=classic still shows the classic page once v2 is the default');

$_GET = [];
$setDesign('nonsense');
ok(Design::current() === 'classic', 'a corrupted setting is treated as classic');
$setDesign(null);

/* =====================================================================
 * 2. Robots — a parameterised URL is a duplicate and must say so
 * ================================================================== */
section('robots and canonical');

$d = new Design();
$_GET = [];
ok($d->coreRobots(['index' => true, 'follow' => true]) === ['index' => true, 'follow' => true],
   'the plain coin URL keeps its robots directives untouched');
ok($d->yoastRobots('index, follow') === 'index, follow', 'Yoast output is untouched without the parameter');

foreach (['v2', 'classic'] as $value) {
    $_GET = ['thb_design' => $value];
    $core = $d->coreRobots(['index' => true, 'follow' => true]);
    ok(!empty($core['noindex']) && !isset($core['index']) && !empty($core['follow']),
       "?thb_design={$value}: core robots become noindex,follow");
    ok($d->yoastRobots('index, follow') === 'noindex, follow', "?thb_design={$value}: Yoast told noindex too");
    ok(($d->rankMathRobots(['index' => 'index'])['index'] ?? '') === 'noindex', "?thb_design={$value}: Rank Math too");
}
$_GET = [];

/* The canonical is built by Seo.php from the permalink, never from the
   request, so the parameter cannot leak into it. */
$seoSrc = (string) file_get_contents(THB_COINS_DIR . 'includes/Seo.php');
ok(!str_contains($seoSrc, '$_GET') && !str_contains($seoSrc, 'REQUEST_URI'),
   'the canonical URL is not built from the request, so it never carries ?thb_design');

/* =====================================================================
 * 3. Assets — v2 loads its own files and none of the classic ones
 * ================================================================== */
section('assets');

$GLOBALS['thb_enqueued'] = [];
$GLOBALS['thb_inline'] = [];
$_GET = ['thb_design' => 'v2'];
$plugin->assets();
ok(in_array('thb-v2', $GLOBALS['thb_enqueued'], true) && in_array('thb-coin-v2', $GLOBALS['thb_enqueued'], true),
   'v2 enqueues its stylesheet and module');
ok(!array_intersect(['thb-tokens', 'thb-base', 'thb-components', 'thb-coin-page', 'thb-coin'], $GLOBALS['thb_enqueued']),
   'and none of the classic files');
ok(str_starts_with((string) ($GLOBALS['thb_inline']['thb-coin-v2'] ?? ''), 'window.__THB_V2__ = '),
   'its data island is attached to the module');
ok(str_contains(Plugin::moduleTag('<script>a</script><script src="x.js"></script>', 'thb-coin-v2', 'x.js'), 'type="module"')
   && str_contains(Plugin::moduleTag('<script>a</script><script src="x.js"></script>', 'thb-coin-v2', 'x.js'), '<script>a</script>'),
   'the v2 module tag is rewritten in place, keeping the island');

$GLOBALS['thb_enqueued'] = [];
$_GET = [];
$plugin->assets();
ok(in_array('thb-coin', $GLOBALS['thb_enqueued'], true) && !in_array('thb-v2', $GLOBALS['thb_enqueued'], true),
   'the classic page still loads exactly the classic files');

/* =====================================================================
 * 4. Request cost — a v2 render costs what a classic render costs
 * ================================================================== */
section('request cost');

$coldClassic = null;
$fresh = static function () use ($plugin): void {
    // A new request: no memoized view model, no memoized provider response.
    (function () { $this->memo = []; foreach ($this->collectors as $c) {
        (function () { if (property_exists($this, 'coinMemo')) { $this->coinMemo = []; } })->call($c);
    } })->call($plugin->pipeline);
};

Probe::reset();
Clock::reset();
$fresh();
$plugin->pipeline->viewModel($plugin->coins->find(10));
$coldClassic = Probe::count();

Probe::reset();
Clock::reset();
$fresh();
$cold = v2_render(10);
$coldV2 = Probe::count();
ok($coldV2 === $coldClassic && $coldV2 === 7,
   "Ethereum cold: v2 makes {$coldV2} requests, classic {$coldClassic} — the frozen baseline is 7");

ok(str_contains($cold, 'در حال دریافت داده'), 'a cold v2 page says "در حال دریافت داده" for what cron has not fetched');
ok(!preg_match('#<bdi class="v2-num" dir="ltr">\$?0(\.00)?%?</bdi>#', $cold), 'and never prints a bare zero in its place');

v2_warm(11);
v2_warm(10);
Probe::reset(keepCache: true);
$fresh();
$html = v2_render(10);
ok(Probe::count() === 0, 'Ethereum warm: v2 makes 0 requests (' . Probe::count() . ')');

/* =====================================================================
 * 5. The nine screens, server-rendered
 * ================================================================== */
section('screens');

ok(!str_contains($html, 'Warning:') && !str_contains($html, 'Fatal error') && !str_contains($html, 'Deprecated:'),
   'no PHP notice leaks into the markup');
foreach ([
    'overview' => 'overview', 'ecosystem' => 'ecosystem graph', 'flow' => 'chain flow (Sankey)',
    'story' => 'story & learning', 'dna' => 'DNA radar', 'price-story' => 'price as story',
    'pulse' => 'daily pulse', 'peers' => 'peer comparison', 'supply' => 'supply & risk',
] as $id => $label) {
    ok(str_contains($html, 'id="' . $id . '"'), "{$label} is on the page (#{$id})");
}
ok(substr_count($html, '<h1') === 1, 'exactly one h1');
ok(substr_count($html, '<h2') >= 9, 'every screen has an h2 (' . substr_count($html, '<h2') . ')');
ok(str_contains($html, 'قیمت اتریوم (<bdi>ETH</bdi>) امروز'), 'the page leads with the search phrase «قیمت اتریوم (ETH) امروز»');
ok(str_contains($html, 'dir="rtl"') && str_contains($html, 'lang="fa"'), 'RTL and Persian');

foreach ([
    '$3,245.67' => 'price', '$390.23B' => 'market cap', '$18.74B' => '24h volume',
    'Uniswap V3' => 'top protocol by fees', '$12.6M' => 'chain fees in the Sankey centre',
    '28.4%' => 'staked share (beaconcha.in stake ÷ Etherscan supply)', '3.12%' => 'staking APR (beaconcha.in)',
    '1,062,000' => 'validators', '14.00 Gwei' => 'gas', 'ادغام (The Merge)' => 'editorial timeline',
] as $needle => $label) {
    ok(str_contains($html, $needle), "{$label} is in the initial HTML ({$needle})");
}
foreach (['<path class="v2-chart__line" d="M', 'class="v2-radar__shape" points=', 'class="v2-ribbon', 'class="v2-bubble__pt'] as $chart) {
    ok(str_contains($html, $chart), 'chart drawn on the server: ' . explode('"', $chart)[1]);
}
ok(!preg_match('#api\.(coingecko|llama|etherscan|blockchair|github)|beaconcha\.in/api|coins\.llama#', $html),
   'no provider URL in the markup — the browser never calls a provider');
ok(str_contains($html, 'https://thehybit.com/trade/eth-usdt'), 'the trade button uses the configured URL with {symbol} filled in');

/* =====================================================================
 * 6. Nothing made up
 * ================================================================== */
section('no invented figures');

/* The reference images' sample numbers. If any appears, something was copied
   from a picture instead of read from a provider. */
foreach (['939 TH/s', '$72.4B', '989,423', '14.2 Gwei', '683.2K', '$3,261.48', '78/100', '84/100',
          '34.6%', '32.4%', '−45%', '+120%', 'Search interest'] as $sample) {
    ok(!str_contains($html, $sample), "the reference's sample figure «{$sample}» does not appear");
}
/* Every section that showed "data not available" in the first build now
   carries its figure — from a named source, never estimated. */
foreach ([
    '498,000'                      => 'daily active addresses (growthepie)',
    'آخرین انتقال‌های دست‌کم'      => 'whale radar lists large transfers (Blockchair)',
    '2,000 ETH'                    => 'and shows the largest of them',
    '1,500 ETH'                    => 'in whole coins, not rounded to "2K"',
    "0xA1b2\u{2026}5678"           => 'with shortened addresses, never names',
    '3.12%'                        => 'staking APR (beaconcha.in, network-wide)',
    'اتر در استیکینگ (امنیت شبکه)' => 'the hash-rate row becomes staked ETH on proof of stake',
    'بازدید ویکی‌پدیای فارسی'      => 'public interest from Wikipedia page views',
    'رپ‌شده به WETH'               => 'WETH share of supply (Etherscan)',
    'در پل‌های رسمی لایه ۲'        => 'L2 canonical bridge share (Etherscan)',
    'کیف پول بزرگ صرافی‌ها (حداقل)' => 'share of supply on exchanges (Etherscan labelled wallets, a floor)',
    'growthepie'                   => 'and named as its source',
    'پیدایش'                       => 'the editorial timeline',
    'قرارداد هوشمند'               => 'the learn-in-60-seconds cards',
] as $needle => $label) {
    ok(str_contains($html, $needle), "{$label} is on the page ({$needle})");
}
ok(!str_contains($html, 'Google Trends'), 'search interest no longer claims there is no source');
ok(str_contains($html, 'v2-cockpit__value">ندارد<'), 'the unlock calendar states "none" for Ethereum, from the editorial field');
ok(!preg_match('#<img[^>]+src="Array"#', $html), 'article thumbnails are real URLs, not "Array"');

/* Geo-blocked sources are off; what they fed is replaced or not drawn. */
ok(!str_contains($html, 'نگهدارندگان بلندمدت') && !str_contains($html, 'کیف پول‌های بلندمدت'),
   'long-term holders: no row at all — its only free source is location-blocked');
ok(!str_contains($html, 'CoinMetrics') && !str_contains($html, 'eth-api.lido'), 'CoinMetrics is not named anywhere on the page');
ok(!str_contains($html, 'خروج خالص از صرافی‌ها') && !str_contains($html, 'ورود خالص به صرافی‌ها'), 'exchange flows (CoinMetrics only) are not drawn');

/* Real yield: inflation from our own Etherscan supply readings a week apart. */
$vv = $plugin->v2($plugin->coins->find(10));
ok($vv['supply']['inflation'] === null && $vv['supply']['realYield'] === null && str_contains($html, 'دست‌کم ۶ روز فاصله'),
   'with no week of supply history yet, the card shows the APR alone and says why — no inflation figure invented');
// The readings warm-up recorded are replaced by two a week apart.
$GLOBALS['wpdb']->historyRows = array_values(array_filter($GLOBALS['wpdb']->historyRows, static fn($r) => $r['dataset'] !== 'supply'));
$GLOBALS['wpdb']->historyRows[] = ['coin_slug' => 'ethereum', 'dataset' => 'supply', 'provider' => 'etherscan',
    'captured_at' => gmdate('Y-m-d H:i:s', time() - 7 * 86400), 'payload' => json_encode(['totalSupply' => 120530000])];
$GLOBALS['wpdb']->historyRows[] = ['coin_slug' => 'ethereum', 'dataset' => 'supply', 'provider' => 'etherscan',
    'captured_at' => gmdate('Y-m-d H:i:s', time() - 60), 'payload' => json_encode(['totalSupply' => 120530350])];
$GLOBALS['thb_history_reads'] = true;
$vv = (new Model($plugin->config, $plugin->cache, new \TheHybit\Coins\History($plugin->config)))->build($plugin->coins->find(10), $plugin->pipeline->viewModel($plugin->coins->find(10)));
$infl = $vv['supply']['inflation'];
$expected = 350 / 120530000 * 365 / ((7 * 86400 - 60) / 86400) * 100;
ok($infl !== null && abs($infl - $expected) < 0.0001 && $vv['supply']['inflationSource'] === 'Etherscan',
   'inflation is the annualised change between our Etherscan supply readings a week apart (' . round((float) $infl, 3) . '%)');
ok($vv['supply']['realYield'] !== null && abs($vv['supply']['realYield'] - ($vv['supply']['apr'] - $infl)) < 1e-9,
   'real yield = staking APR − inflation (' . round((float) $vv['supply']['realYield'], 2) . '%)');
unset($GLOBALS['thb_history_reads']);
/* The APR source chain: beaconcha.in, then stETH via DefiLlama, then the protocol formula. */
$noBeacon = $vm = $plugin->pipeline->viewModel($plugin->coins->find(10));
$noBeacon['staking'] = ['available' => false, 'state' => 'unavailable', 'data' => null, 'source' => 'beaconcha.in', 'fetchedAt' => null];
$lidoBuild = (new Model($plugin->config, $plugin->cache))->build($plugin->coins->find(10), $noBeacon);
ok(abs($lidoBuild['supply']['apr'] - 2.87) < 1e-9 && str_contains((string) $lidoBuild['supply']['aprSource'], 'DefiLlama')
   && str_contains((string) $lidoBuild['supply']['aprSource'], 'لیدو'),
   'without beaconcha.in, the APR is stETH\'s 7-day yield from DefiLlama Yields, labelled as Lido\'s pool via DefiLlama');
$cfgNoLido = $plugin->config;
$cfgNoLido['datasets']['lidoapr']['enabled'] = false;
$cfgNoLido['datasets']['stakingyield']['enabled'] = false;
/* The formula needs the staked total, which only beaconcha.in reports
   (Etherscan's Eth2Staking is rewards): a stake figure without an APR. */
$stakeOnly = $noBeacon;
$stakeOnly['staking'] = ['available' => true, 'state' => 'ok', 'data' => ['stakedEth' => 34e6, 'apr' => null], 'source' => 'beaconcha.in', 'fetchedAt' => null];
$formula = (new Model($cfgNoLido, $plugin->cache))->build($plugin->coins->find(10), $stakeOnly);
ok($formula['supply']['aprKind'] === 'formula' && abs($formula['supply']['apr'] - Model::consensusApr(34e6)) < 1e-9,
   'with a stake but no published APR, it is the consensus formula, marked as a calculation');
$none = (new Model($cfgNoLido, $plugin->cache))->build($plugin->coins->find(10), $noBeacon);
ok($none['supply']['apr'] === null, 'and with no stake figure at all, no APR is shown — never one computed from the wrong number');
ok(Model::consensusApr(34e6) > 2.8 && Model::consensusApr(34e6) < 2.9, 'the consensus-formula fallback gives ~2.85% at 34M ETH staked');

/* =====================================================================
 * 7. The score is the classic score
 * ================================================================== */
section('score');

$vm = $plugin->pipeline->viewModel($plugin->coins->find(10));
$v = $plugin->v2($plugin->coins->find(10));
ok($v['dna']['score'] === (int) round($vm['analytics']['value']),
   'the DNA centre shows the classic analytics value (' . $v['dna']['score'] . ')');
ok($v['story']['score'] === $v['dna']['score'], 'and the story score ring shows the same number');
ok(count($v['dna']['axes']) === count($vm['analytics']['components']),
   'the radar has exactly the scoring model\'s ' . count($v['dna']['axes']) . ' components — no invented axis');
ok(array_column($v['dna']['axes'], 'weight') === array_column($vm['analytics']['components'], 'weight'),
   'with the same weights');

/* =====================================================================
 * 8. Brand
 * ================================================================== */
section('brand');

ok(str_contains($html, 'های‌بیت'), 'the brand on the page is «های‌بیت»');
ok(stripos($html, 'thinkbit') === false, 'no "Thinkbit" anywhere in the rendered page');
$hits = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(THB_COINS_DIR, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->isFile() && stripos((string) file_get_contents($file->getPathname()), 'thinkbit') !== false) {
        $hits[] = $file->getFilename();
    }
}
ok($hits === [], 'no "Thinkbit" in any plugin file' . ($hits ? ': ' . implode(', ', $hits) : ''));
$GLOBALS['thb_blogname'] = 'نام سایت';
ok(Model::brand() === 'نام سایت', 'the brand follows the site name in WordPress settings');
$GLOBALS['thb_blogname'] = '';
ok(Model::brand() === 'های‌بیت', 'and falls back to «های‌بیت» when it is empty');
unset($GLOBALS['thb_blogname']);

/* =====================================================================
 * 9. Bitcoin — different states, no Ethereum leakage
 * ================================================================== */
section('bitcoin');

$btc = v2_render(11);
ok(!str_contains($btc, 'Warning:') && str_contains($btc, 'id="supply"'), 'the Bitcoin v2 page renders');
ok(str_contains($btc, 'داده دیفای برای این ارز خاموش است'), 'its ecosystem says DeFi is switched off for it, not "loading"');
ok(!str_contains($btc, '1,062,000'), 'and it shows no Ethereum validator count');
ok(str_contains($btc, 'کاربرد ندارد'), 'staking and gas are "not applicable" on Bitcoin');

/* =====================================================================
 * 10. Model arithmetic
 * ================================================================== */
section('model arithmetic');

$t = []; $pa = []; $pb = [];
for ($i = 0; $i < 120; $i++) {
    $t[] = (1_700_000_000 + $i * 86400) * 1000;
    $pa[] = 100 * exp(0.02 * sin($i * 1.7));
    $pb[] = 100 * exp(0.02 * sin($i * 1.7) + 0.005 * cos($i * 3.1));
}
$ra = Model::dailyReturns(['t' => $t, 'price' => $pa]);
$rb = Model::dailyReturns(['t' => $t, 'price' => $pb]);
ok(count($ra) === 119, 'daily returns: one per day after the first');
$corr = Model::correlation($ra, $rb);
ok($corr !== null && $corr > 0.9 && $corr <= 1.0, 'two nearly identical series correlate near 1 (' . round((float) $corr, 3) . ')');
ok(Model::correlation($ra, array_map(static fn($x) => -$x, $ra)) < -0.99, 'and a mirrored series near −1');
ok(Model::correlation(array_slice($ra, 0, 10, true), $rb) === null, 'fewer than 30 shared days gives no correlation, not a guess');
$flat = Model::dailyReturns(['t' => $t, 'price' => array_fill(0, 120, 5.0)]);
ok(Model::correlation($flat, $rb) === null, 'a series that never moved has no correlation');
ok(Model::annualisedVolatility(array_slice($ra, 0, 10)) === null, 'volatility needs 20 days');
$vol = Model::annualisedVolatility($ra);
ok($vol !== null && $vol > 0, 'volatility is annualised from daily returns (' . round((float) $vol, 1) . '%)');

ok(Model::parseLines("2015 | a | b\n\n  | x | y\nbad line\n2016|c|d", 3) === [['2015', 'a', 'b'], ['2016', 'c', 'd']],
   'editorial lines: blank and incomplete lines are skipped, parts trimmed');
ok(Model::iso('$1') === "\u{2066}\$1\u{2069}", 'numbers inside sentences are wrapped in Unicode isolates');
ok(Model::hashrate(6.5e20) === '650.0 EH/s', 'hashrate is shown in the unit miners use');
ok(Svg::line([1], 100, 10) === '' && Svg::line([1, 'x', null], 100, 10) === '', 'a series with under two points draws nothing');
ok(str_starts_with(Svg::line([1, 2, 3], 100, 10), 'M'), 'and two or more draw a path');
$rib = Svg::ribbons([0.9, 0.05, 0.05], 0, 100, 0, 300, 10, 100, 100);
ok(abs(array_sum(array_column($rib, 'h')) - 280) < 1, 'Sankey bands fill the height exactly');
ok(min(array_column($rib, 'h')) >= 34, 'and the smallest band still has room for its label');

/* No figure for a dataset whose provider is switched off. */
$cfg = $plugin->config;
$cfg['datasets']['peers']['enabled'] = false;
$off = (new Model($cfg, $plugin->cache))->build($plugin->coins->find(10), $vm);
ok($off['peers']['state'] === 'disabled' && $off['peers']['rows'] === [], 'a disabled dataset reports "disabled" and no rows');

/* =====================================================================
 * 11. The three v2 datasets
 * ================================================================== */
section('v2 datasets');

use TheHybit\Coins\{Datasets, Pipeline};
use TheHybit\Coins\Collectors as C;

$sets = new Datasets($cfg);
ok($sets->scope('peers') === 'site' && $sets->scope('protocols') === 'chain' && $sets->scope('longchart') === 'coin',
   'scopes: peers site-wide (one request), protocols per chain, long history per coin');
foreach (['peers', 'protocols', 'longchart'] as $ds) {
    ok($sets->rendersFromCacheOnly($ds), "{$ds} is cache-only on render — the scheduler fills it");
    ok(isset($cfg['ttl'][$ds]), "{$ds} has its TTL in config");
    ok(!array_key_exists($ds, (array) (new ReflectionClassConstant(Pipeline::class, 'DATASETS'))->getValue()),
       "{$ds} is NOT in the classic view model's dataset list");
}
$eth = $plugin->coins->find(10);
$btcCoin = $plugin->coins->find(11);
ok(in_array('longchart', $sets->forCoin($btcCoin), true), 'Bitcoin still gets its price history with DeFi switched off');
ok(!in_array('protocols', $sets->forCoin($btcCoin), true), 'but no protocol list');

$GLOBALS['thb_probe_background'] = true;
foreach (array_keys($cfg['providers']) as $prov) { (new TheHybit\Coins\Budget($cfg))->reset($prov); }
$peers = (new C\CoinGecko($cfg))->fetch($eth, 'peers');
ok(count($peers['peers'] ?? []) === count($cfg['peers']), 'peers: one row per configured id, from ONE request');
ok(Probe::count('/coins/markets') >= 1 && str_contains((string) end(Probe::$calls)['url'] ?? '', 'ids='), 'asked by id list');

$GLOBALS['thb_probe_override'] = [['id' => 'x', 'symbol' => 'x'], ['id' => 'y', 'symbol' => 'y', 'market_cap' => 5, 'sparkline_in_7d' => ['price' => [1, 'bad', 2]]]];
$bad = (new C\CoinGecko($cfg))->fetch($eth, 'peers');
unset($GLOBALS['thb_probe_override']);
ok(count($bad['peers']) === 1 && $bad['peers'][0]['sparkline'] === [1.0, 2.0], 'a peer with no market cap is dropped, junk sparkline points too');

$pro = (new C\DefiLlama($cfg))->fetch($eth, 'protocols');
ok(count($pro['protocols']) === 5, 'protocols: rows with no fee figure or no name are discarded (5 of 7 kept)');
ok($pro['protocols'][0]['name'] === 'Uniswap V3' && $pro['protocols'][0]['fees24h'] >= $pro['protocols'][1]['fees24h'], 'sorted by 24h fees');
ok(count($pro['tvlWeekly']) === 18 && $pro['tvlWeekly'][1][0] - $pro['tvlWeekly'][0][0] === 7 * 86400, 'TVL history thinned to one point a week');

$long = (new C\LlamaPrices($cfg))->fetch($eth, 'longchart');
ok(count($long['t'] ?? []) > 250 && $long['t'][0] >= 1438387200, 'long history: a point every two weeks from launch (' . count($long['t'] ?? []) . ')');
ok(str_contains((string) end(Probe::$calls)['url'] ?? '', 'coins.llama.fi/chart/coingecko:ethereum?'),
   'from DefiLlama prices, with the colon NOT percent-encoded (2.0.1 sent %3A, which matched no coin)');
$GLOBALS['thb_probe_override'] = ['coins' => []];
try {
    (new C\LlamaPrices($cfg))->fetch($eth, 'longchart');
    ok(false, 'an empty answer from both sources is an error, not an empty chart');
} catch (\RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'DefiLlama') && str_contains($e->getMessage(), 'Kraken'),
       'an empty answer from both sources is an error naming both, for the diagnostics page');
}
unset($GLOBALS['thb_probe_override']);
$GLOBALS['thb_probe_background'] = false;

$probeSrc = (string) file_get_contents(THB_COINS_DIR . 'includes/ProviderProbe.php');
foreach (['v2 peers', 'v2 protocols', 'v2 longchart'] as $entry) {
    ok(str_contains($probeSrc, $entry), "the Provider Probe has an entry for {$entry}");
}

/* =====================================================================
 * 13. Second-round collectors parse what they are given, and no more
 * ================================================================== */
section('second-round collectors');

$GLOBALS['thb_probe_background'] = true;
$cfg2 = $plugin->config;
$eth2 = $plugin->coins->find(10);
$btc2 = $plugin->coins->find(11);
$free = static function () use ($cfg2): void { foreach (array_keys($cfg2['providers']) as $p) { (new TheHybit\Coins\Budget($cfg2))->reset($p); } };

$free();
$w = (new C\Blockchair($cfg2))->fetch($eth2, 'whales');
ok(count($w['transfers']) === 3 && min(array_column($w['transfers'], 'amount')) >= 500,
   'whales: the 120 ETH row under the threshold is dropped even though the provider returned it');
ok(str_contains((string) end(Probe::$calls)['url'], 'value%28500000000000000000000..%29'), 'whales: asked for transfers of at least 500 ETH, in wei');
$free();
$wb = (new C\Blockchair($cfg2))->fetch($btc2, 'whales');
ok($wb['transfers'][0]['amount'] === 1500.0 && $wb['transfers'][0]['from'] === null, 'whales on Bitcoin: amounts in BTC, no sender/recipient invented');

$free();
$loc = (new C\Etherscan($cfg2))->fetch($eth2, 'ethlocations');
ok(abs($loc['weth'] - 2.9e6) < 1, 'WETH supply read from the token contract (2.9M)');
ok(count($loc['bridges']) === count($cfg2['providers']['etherscan']['bridges']), 'every configured bridge balance matched, whatever the address case');
ok(abs($loc['bridgesTotal'] - (400000 + 500000 + 600000 + 700000 + 800000)) < 1, 'and summed');
ok((new C\Etherscan($cfg2))->fetch($btc2, 'ethlocations') === null, 'and never asked about Bitcoin');

$free();
$cm = (new C\CoinMetrics($cfg2))->fetch($eth2, 'chainstats');
ok($cm['asset'] === 'eth' && isset($cm['metrics']['AdrActCnt'], $cm['metrics']['SplyExNtv']), 'CoinMetrics: the community metrics that came back are kept');
ok(!isset($cm['metrics']['IssTotNtv']) && !isset($cm['metrics']['HashRate']), 'and a metric withheld by the free tier is absent, not zero');
ok($cm['metrics']['SplyCur']['weekAgo'] !== null, 'a week-ago reading is kept for the inflation calculation');
ok(str_contains((string) end(Probe::$calls)['url'], 'ignore_forbidden_errors=true'), 'it asks the API to drop forbidden metrics instead of failing the request');

$free();
ok((new C\Lido($cfg2))->fetch($eth2, 'lidoapr') === ['smaApr' => 2.87], 'Lido: the 7-day stETH APR');
$GLOBALS['thb_probe_override'] = ['data' => ['smaApr' => 287]];
try {
    (new C\Lido($cfg2))->fetch($eth2, 'lidoapr');
    ok(false, 'an APR of 287% is rejected as a unit error, not shown');
} catch (\RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'implausible'), 'an APR of 287% is rejected as a unit error, not shown');
}
unset($GLOBALS['thb_probe_override']);

$free();
$gp = (new C\GrowThePie($cfg2))->fetch($eth2, 'activity');
ok($gp['latest'] === 498000.0 && $gp['weekAgo'] === 480000.0 && count($gp['series']) === 30,
   'growthepie: Ethereum\'s daa rows only, latest and a week earlier (other chains and metrics filtered out)');
ok((new C\GrowThePie($cfg2))->fetch($btc2, 'activity') === null, 'and Bitcoin is not asked about');
try {
    C\GrowThePie::extract([['metric_key' => 'daa', 'origin_key' => 'base', 'date' => '2026-10-01', 'value' => 1]], 'ethereum', 'daa');
    ok(false, 'a file with no Ethereum rows is an error, not a zero');
} catch (\RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'no "daa" rows for "ethereum"'), 'a file with no Ethereum rows is an error, not a zero');
}
$free();
$yl = (new C\LlamaYields($cfg2))->fetch($eth2, 'stakingyield');
ok(abs($yl['apy7d'] - 2.87) < 1e-9 && $yl['apy'] === 2.8 && str_contains((string) end(Probe::$calls)['url'], 'yields.llama.fi/chart/747c1d2a-c668-4682-b9f9-296708a3dd90'),
   'DefiLlama Yields: the stETH pool\'s 7-day mean (2.87%) from its daily chart');
try {
    C\LlamaYields::extract(['data' => [['timestamp' => '2026-10-01T00:00:00Z', 'apy' => 287]]], 'p');
    ok(false, 'a stETH APY of 287% is rejected');
} catch (\RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'implausible'), 'a stETH APY of 287% is rejected as a unit error');
}
ok(empty($cfg2['providers']['coinmetrics']['enabled']) && empty($cfg2['providers']['lido']['enabled']),
   'CoinMetrics and Lido are switched off (location-blocked from the production server)');

/* A replacement source that fails takes its row with it. */
$failCfg = $plugin->config;
update_option(\TheHybit\Coins\Cache::OPTION_ERRORS, ['activity' => ['at' => 'x', 'message' => 'growthepie: HTTP 403']]);
$plugin->cache->put('activity', $plugin->cache->keyFor('activity', $eth2), []);
$GLOBALS['thb_transients'] = $GLOBALS['thb_transients'] ?? null;
$vmF = $plugin->pipeline->viewModel($eth2);
$cacheKeyA = $plugin->cache->keyFor('activity', $eth2);
delete_transient('thb_' . md5('activity|' . $cacheKeyA));
$failBuild = (new Model($failCfg, new \TheHybit\Coins\Cache($failCfg)))->build($eth2, $vmF);
$labels = array_merge(array_column($failBuild['keyMetrics'], 'label'), array_column($failBuild['flow']['pulse'], 'label'));
ok(!in_array('آدرس‌های فعال روزانه', $labels, true) && !in_array('آدرس‌های فعال (روز گذشته)', $labels, true),
   'when growthepie fails, the active-address rows are left out, not shown as "unavailable"');
update_option(\TheHybit\Coins\Cache::OPTION_ERRORS, []);

$free();
$int = (new C\Wikimedia($cfg2))->fetch($eth2, 'interest');
ok(isset($int['fa'], $int['en']) && $int['fa']['title'] === 'اتریوم' && $int['en']['title'] === 'Ethereum', 'Wikipedia: Persian and English articles counted separately');
ok($int['fa']['last7'] === array_sum(array_map(static fn($i) => 900 + $i * 10, range(8, 14))), 'the last seven full days, summed');
$GLOBALS['thb_probe_background'] = false;

$probeSrc2 = (string) file_get_contents(THB_COINS_DIR . 'includes/ProviderProbe.php');
foreach (['v2 activity', 'v2 stakingyield', 'v2 whales', 'v2 ethlocations', 'v2 interest', 'v2 revenue'] as $entry) {
    ok(str_contains($probeSrc2, $entry), "the Provider Probe has an entry for {$entry}");
}

/* =====================================================================
 * 12. Editorial content reaches coins that already exist
 * ================================================================== */
section('editorial fill on upgrade');

use TheHybit\Coins\Seeder;

$GLOBALS['thb_pages'] = ['ethereum' => (object) ['ID' => 77]];
$GLOBALS['thb_coin_fields'][77] = ['thb_timeline' => 'written by an editor', 'thb_symbol' => 'ETH'];
delete_option(Seeder::OPTION_EDITORIAL);
$filled = Seeder::fillEditorial();
ok(($GLOBALS['thb_coin_fields'][77]['thb_timeline'] ?? '') === 'written by an editor', 'an editor\'s text is never overwritten');
ok(str_contains((string) ($GLOBALS['thb_coin_fields'][77]['thb_learn'] ?? ''), 'قرارداد هوشمند'), 'empty fields are filled from config/coins.php');
ok(!isset($GLOBALS['thb_coin_fields'][77]['thb_coingecko_id']) && !isset($GLOBALS['thb_coin_fields'][77]['thb_enable_defi']),
   'identifiers and provider switches are never touched');
ok(!isset($filled['bitcoin']), 'a coin that does not exist is skipped — no post is created');
ok(Seeder::fillEditorial() === [], 'and it runs once per plugin version');
unset($GLOBALS['thb_pages'], $GLOBALS['thb_coin_fields'][77]);

/* =====================================================================
 * 14. Live price — once a minute, one request for every coin
 * ================================================================== */
section('live price');

use TheHybit\Coins\{LivePrice, Scheduler};

$cfgL = $plugin->config;
$sched = new Scheduler($cfgL, $plugin->coins, $plugin->pipeline, $plugin->history);
$live = new LivePrice($cfgL, $plugin->coins, $plugin->cache);
$ethL = $plugin->coins->find(10);

ok($plugin->cache->ttl('ticker') < Scheduler::interval($cfgL),
   'the ticker TTL (' . $plugin->cache->ttl('ticker') . 's) is shorter than the tick interval (' . Scheduler::interval($cfgL) . 's), so every tick refreshes it');
ok(!array_key_exists('ticker', (array) (new ReflectionClassConstant(TheHybit\Coins\Pipeline::class, 'DATASETS'))->getValue()),
   'the ticker is not part of a page render — renders still never wait on it');

Probe::reset();
Clock::reset();
foreach (array_keys($cfgL['providers']) as $p) { (new TheHybit\Coins\Budget($cfgL))->reset($p); }
$GLOBALS['thb_probe_background'] = true;
$sched->tick();
ok(Probe::count('/simple/price') === 1, 'one tick: ONE /simple/price request for every coin (' . Probe::count('/simple/price') . ')');
$call = array_values(array_filter(Probe::$calls, static fn($c) => str_contains($c['url'], '/simple/price')))[0]['url'] ?? '';
ok(str_contains($call, 'ethereum') && str_contains($call, 'bitcoin') && str_contains($call, 'include_24hr_change=true'),
   'asking for both coins and their 24h change in the same request');
$tick = $plugin->cache->peek('ticker', $plugin->cache->keyFor('ticker'));
ok(isset($tick['data']['prices']['ethereum'], $tick['data']['prices']['bitcoin']) && !isset($tick['data']['prices']['zero-coin']),
   'both coins cached; a zero price is dropped, never shown as $0');

Clock::advance(30);
$sched->tick();
ok(Probe::count('/simple/price') === 1, 'a tick 30s later does not ask again — the minute has not passed');
Clock::advance(25);
$sched->tick();
ok(Probe::count('/simple/price') === 2, 'the next tick (55s after the first) refreshes it — once a minute');
$GLOBALS['thb_probe_background'] = false;

/* The per-minute budget slides, and holds one call for the ticker. */
$b = new TheHybit\Coins\Budget($cfgL);
$b->reset('coingecko');
Clock::reset();
foreach (range(1, 3) as $_) { $b->record('coingecko'); }
ok($b->refuse('coingecko') !== null, 'after 3 calls in a minute, ordinary CoinGecko calls stop: the 4th slot is reserved');
ok($b->refuse('coingecko', reserved: true) === null, 'but the live ticker may still use it');
$b->record('coingecko');
ok($b->refuse('coingecko', reserved: true) !== null, 'and nothing gets a 5th call inside 60 seconds');
Clock::advance(59);
ok($b->refuse('coingecko', reserved: true) !== null, '59s later the window still holds all four — it slides, it does not reset on a bucket edge');
Clock::advance(2);
ok($b->refuse('coingecko') === null, '61s later they have aged out');
$b->reset('coingecko');

/* The endpoint: reads the cache, never a provider. */
Probe::reset(keepCache: true);
$r = $live->read($ethL);
ok(abs($r['price'] - 3251.04) < 1e-9 && abs($r['change24h'] - 2.61) < 1e-9 && $r['source'] === 'ticker',
   'the endpoint returns the cached ticker price and 24h change');
ok(Probe::count() === 0, 'and makes 0 provider requests (' . Probe::count() . ')');
for ($i = 0; $i < 500; $i++) { $live->read($ethL); }
ok(Probe::count() === 0, '500 polls later: still 0 provider requests');
ok(strtotime($r['nextRefreshAt']) >= strtotime($r['fetchedAt']) + Scheduler::interval($cfgL),
   'it tells the page when the next refresh is due, so the page polls once per refresh');
ok(isset($r['toman']) && $r['toman'] > 0, 'with the toman figure, recomputed from the live price');

$GLOBALS['thb_pages'] = ['ethereum' => (object) ['ID' => 10], 'bitcoin' => (object) ['ID' => 11]];
$resp = $live->respond(['slug' => 'ethereum']);
ok($resp->get_status() === 200 && str_contains($resp->headers['Cache-Control'] ?? '', 's-maxage=20'),
   'HTTP 200, cacheable by a shared cache for 20s');
ok($live->respond(['slug' => 'no-such-coin'])->get_status() === 404, 'an unknown coin is a 404');
unset($GLOBALS['thb_pages']);
$live->register();
do_action('rest_api_init');
ok(isset($GLOBALS['thb_rest_routes']['thehybit/v1/price/(?P<slug>[a-z0-9-]+)']), 'the route is registered as GET /wp-json/thehybit/v1/price/{slug}');

/* Before the first ticker refresh, the 5-minute market price still answers. */
$plugin->cache->forget('ticker', $plugin->cache->keyFor('ticker'));
$fallback = $live->read($ethL);
ok($fallback !== null && $fallback['source'] === 'market', 'with no ticker yet, it falls back to the cached market price');
Probe::reset(keepCache: true);
$GLOBALS['thb_probe_background'] = true;
foreach (array_keys($cfgL['providers']) as $p) { (new TheHybit\Coins\Budget($cfgL))->reset($p); }
$sched->tick();
$GLOBALS['thb_probe_background'] = false;

/* The page. */
$fresh();
$livePage = v2_render(10);
ok(str_contains($livePage, '$3,251.04'), 'the v2 page renders the live price on first paint, not the 5-minute one');
ok(str_contains($livePage, 'data-v2-live-change') && str_contains($livePage, 'data-v2-live-stamp'), 'the live figures are marked for in-place updates');
$island = \TheHybit\Coins\V2\Model::client($plugin->v2($ethL));
ok(($island['live']['url'] ?? '') === 'https://thehybit.com/wp-json/thehybit/v1/price/ethereum', 'the data island carries the endpoint URL');
ok(!str_contains($livePage, 'api.coingecko.com'), 'the page never points the browser at CoinGecko');

/* =====================================================================
 * 15. Pre-launch review fixes (data/1–8.PNG)
 * ================================================================== */
section('pre-launch review');

$cfgR = $plugin->config;
$ethR = $plugin->coins->find(10);
foreach (array_keys($cfgR['providers']) as $p) { (new TheHybit\Coins\Budget($cfgR))->reset($p); }
$GLOBALS['thb_probe_background'] = true;

/* #6 — long chart: DefiLlama empty → Kraken weekly candles. */
$GLOBALS['thb_probe_override'] = null;
unset($GLOBALS['thb_probe_override']);
$llamaRoute = static fn() => null;
Probe::reset(keepCache: true);
$GLOBALS['thb_probe_llama_empty'] = true;
$kr = (new C\LlamaPrices($cfgR))->fetch($ethR, 'longchart');
unset($GLOBALS['thb_probe_llama_empty']);
ok(count($kr['t']) > 500 && Probe::count('api.kraken.com/0/public/OHLC?pair=ETHUSD&interval=10080') === 1,
   '#6 when DefiLlama has nothing, the long chart comes from Kraken weekly candles (' . count($kr['t']) . ' weeks)');

/* #8 — exchange wallets read in the same balancemulti call as the bridges. */
Probe::reset(keepCache: true);
$locR = (new C\Etherscan($cfgR))->fetch($ethR, 'ethlocations');
ok(count($locR['exchanges']) === count($cfgR['providers']['etherscan']['exchange_wallets']) && $locR['exchangesTotal'] > 0,
   '#8 the labelled exchange wallets are read: ' . count($locR['exchanges']) . ' balances');
ok(Probe::count('balancemulti') === 1, '#8 in ONE balancemulti call together with the bridges');
// What the scheduler would do with it (ethlocations is scheduler-only).
$plugin->cache->put('ethlocations', $plugin->cache->keyFor('ethlocations', $ethR), $locR);
$GLOBALS['thb_probe_background'] = false;

$html15 = v2_render(10);

/* #2 — every bar carries its protocol's name. */
preg_match('#<ol class="v2-hbars">(.*?)</ol>#s', $html15, $bars);
ok(isset($bars[1]) && substr_count($bars[1], 'v2-hbars__name') >= 5 && str_contains($bars[1], 'Uniswap V3') && str_contains($bars[1], 'Lido'),
   '#2 the top-protocols bars are labelled with protocol names and values');

/* #3 — direction: titles, arrowheads, RTL order, one "other". */
ok(str_contains($html15, 'ورودی: از کجا آمد') && str_contains($html15, 'خروجی: به کجا رفت') && str_contains($html15, 'جهت جریان'),
   '#3 the Sankey has "in" (right) and "out" (left) column titles and a direction caption');
ok(substr_count($html15, 'class="v2-flow__arrow') >= 4, '#3 every ribbon ends in an arrowhead (' . substr_count($html15, 'class="v2-flow__arrow') . ')');
preg_match_all('#<text class="v2-flow__label v2-fa"[^>]*>([^<]+)</text>#', $html15, $flowLabels);
ok(count(array_keys($flowLabels[1], 'سایر')) <= 1, '#3 "سایر" appears once, not twice');

/* #5 — wrapping timeline with the roadmap. */
ok(substr_count($html15, 'is-planned') >= 3 && substr_count($html15, 'برنامه‌ریزی‌شده') >= 3,
   '#5 three planned roadmap items are on the timeline, marked as planned');
ok(str_contains($html15, 'گلمستردام (Glamsterdam)') && str_contains($html15, 'فوزاکا (Fusaka)'), '#5 including Glamsterdam, after Fusaka');

/* #6 — market events on the chart, label above, arrow to the price. */
ok(substr_count($html15, 'class="v2-pin__box"') >= 6, '#6 market events are marked on the price chart (' . substr_count($html15, 'class="v2-pin__box"') . ')');
ok(str_contains($html15, 'marker-end="url(#v2-arrowhead)"') && str_contains($html15, 'سقوط ترا/لونا'),
   '#6 each with an arrow to the price on the day and its label above');

/* #7 — provenance is a collapsed footnote, not a pulse card. */
ok(!str_contains($html15, 'چرا این گزارش؟') && str_contains($html15, '<details class="v2-card v2-provenance">'),
   '#7 the provenance list is a collapsed footnote under About, not a card in the daily pulse');

/* #8 — exchange concentration falls back to the labelled wallets. */
$cfgNoCm = $plugin->config;
$cfgNoCm['datasets']['chainstats']['enabled'] = false;
$noCm = (new Model($cfgNoCm, $plugin->cache))->build($ethR, $plugin->pipeline->viewModel($ethR));
$riskRow = array_values(array_filter($noCm['supply']['risk'], static fn($r) => str_contains($r['label'], 'صرافی‌ها (حداقل)')))[0] ?? null;
ok($riskRow !== null && $riskRow['value'] !== null && str_contains((string) $riskRow['detail'], 'Binance'),
   '#8 without CoinMetrics, exchange concentration is the known wallets\' balance, labelled as a minimum, naming the largest');

/* Seeds: 2.0.1's untouched timeline is upgraded; an edited one is not. */
$seeds = require THB_COINS_DIR . 'config/coins.php';
$GLOBALS['thb_pages'] = ['ethereum' => (object) ['ID' => 78], 'bitcoin' => (object) ['ID' => 79]];
$GLOBALS['thb_coin_fields'][78] = ['thb_timeline' => $seeds['ethereum']['fields']['previous']['thb_timeline'][0]];
$GLOBALS['thb_coin_fields'][79] = ['thb_timeline' => $seeds['bitcoin']['fields']['previous']['thb_timeline'][0] . "\n2030 | edited | by an editor"];
Seeder::fillEditorial(true);
ok($GLOBALS['thb_coin_fields'][78]['thb_timeline'] === $seeds['ethereum']['fields']['thb_timeline'], 'an untouched 2.0.1 timeline is upgraded with the roadmap');
ok(str_contains($GLOBALS['thb_coin_fields'][79]['thb_timeline'], 'by an editor'), 'an edited one is left exactly as the editor left it');
ok(!isset($GLOBALS['thb_coin_fields'][78]['previous']), 'and "previous" is never written as a field');
unset($GLOBALS['thb_pages'], $GLOBALS['thb_coin_fields'][78], $GLOBALS['thb_coin_fields'][79]);

/* Item 4 data correctness: Etherscan's Eth2Staking is not the stake. */
ok(!str_contains($html15, '4.38M ETH') && str_contains($html15, '34.20M ETH'), 'staked ETH comes from beaconcha.in, not from Etherscan\'s rewards field');

if (getenv('THB_DUMP_HTML')) {
    file_put_contents((string) getenv('THB_DUMP_HTML'), $html);
}

echo $failed ? "\n{$failed} FAILED\n" : "\nAll v2 render tests passed.\n";
exit($failed ? 1 : 0);
