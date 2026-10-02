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
    '28.2%' => 'staked share (Etherscan)', '3.12%' => 'staking APR (beaconcha.in)',
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
foreach ([
    'رادار نهنگ‌ها'          => 'whale radar',
    'آدرس‌های فعال روزانه'    => 'daily active addresses',
    'Google Trends'          => 'search interest',
    'روی صرافی‌های متمرکز'    => 'supply on exchanges',
    'کیف پول‌های بلندمدت'     => 'long-term wallets',
    'تقویم آزادسازی توکن'     => 'unlock calendar',
] as $needle => $label) {
    ok(str_contains($html, $needle), "{$label} is present, as an explicit no-source state");
}
ok(substr_count($html, 'v2-na__label') >= 8, 'unavailable states are visible, not hidden (' . substr_count($html, 'v2-na__label') . ')');
ok(str_contains($html, 'در اثبات سهام وجود ندارد'), 'Ethereum hashrate is "not applicable", not a number');

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
ok(count($long['t'] ?? []) > 400 && $long['t'][0] >= 1438387200, 'long history: weekly points from launch (' . count($long['t'] ?? []) . ')');
ok(str_contains((string) end(Probe::$calls)['url'] ?? '', 'coins.llama.fi/chart/coingecko%3Aethereum'), 'from DefiLlama prices, by CoinGecko id');
$GLOBALS['thb_probe_override'] = ['coins' => []];
ok((new C\LlamaPrices($cfg))->fetch($eth, 'longchart') === null, 'an empty answer is null, not an empty chart');
unset($GLOBALS['thb_probe_override']);
$GLOBALS['thb_probe_background'] = false;

$probeSrc = (string) file_get_contents(THB_COINS_DIR . 'includes/ProviderProbe.php');
foreach (['v2 peers', 'v2 protocols', 'v2 longchart'] as $entry) {
    ok(str_contains($probeSrc, $entry), "the Provider Probe has an entry for {$entry}");
}

if (getenv('THB_DUMP_HTML')) {
    file_put_contents((string) getenv('THB_DUMP_HTML'), $html);
}

echo $failed ? "\n{$failed} FAILED\n" : "\nAll v2 render tests passed.\n";
exit($failed ? 1 : 0);
