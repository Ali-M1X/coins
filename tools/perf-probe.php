<?php
/**
 * Request-count probe.
 *
 *   php tools/perf-probe.php
 *
 * Reports how many provider requests one page render costs, cold and warm.
 * These are the numbers the v2 brief freezes — "page renders must not make more
 * requests (currently Ethereum cold 7, warm 0)" — so they are reported here in
 * a form that can be read at a glance and diffed between versions.
 *
 * The counts are exact. The timings are the request count multiplied by an
 * assumed round trip, which is honest about being a model rather than a
 * measurement: the point is the shape of the cost, not a benchmark of somebody
 * else's network.
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

function plugin_dir_path(string $file): string { return dirname($file) . '/'; }
function plugin_dir_url(string $file): string { return 'https://thehybit.com/'; }
function register_activation_hook(string $f, $cb): void {}
function register_deactivation_hook(string $f, $cb): void {}
function locate_template($t, bool $l = false, bool $o = true): string { return ''; }
function is_singular($t = ''): bool { return true; }
function get_the_ID(): int { return 10; }
function register_post_type(string $t, array $a) { return null; }
function wp_enqueue_style(...$a): void {}
function wp_enqueue_script(...$a): void {}
function wp_add_inline_script(...$a): bool { return true; }
function add_submenu_page(...$a) { return ''; }
function wp_get_script_tag(array $a): string { return ''; }
function submit_button(...$a): void {}
function flush_rewrite_rules(bool $h = true): void {}

$base = __DIR__ . '/../thehybit-coins/';
require_once $base . 'thehybit-coins.php';

use TheHybit\Coins\{Coin, Cache, History, News, Pipeline, Budget};
use TheHybit\Coins\Collectors as C;

$config = require $base . 'config/providers.php';

function pipeline(array $config): Pipeline
{
    $p = new Pipeline($config, new Cache($config), new History($config), new News($config));
    foreach ([C\CoinGecko::class, C\DefiLlama::class, C\DexScreener::class,
              C\L2Beat::class, C\OnChain::class, C\Fx::class, C\Alternative::class] as $cls) {
        $p->register(new $cls($config));
    }
    return $p;
}

function coin(string $slug, string $symbol, string $chain, bool $providers = true): Coin
{
    $GLOBALS['thb_probe_slug'] = $slug;
    return new Coin(
        postId: $slug === 'ethereum' ? 10 : 11,
        slug: $slug, symbol: $symbol, name: $slug, nameEn: ucfirst($slug),
        coingeckoId: $slug, newsCategorySlug: $slug . '-news',
        meta: ['defillamaChain' => $chain, 'dexTokenAddress' => '0xC02aaA39', 'dexSymbols' => 'WETH'],
        flags: ['defillama' => $providers, 'dexscreener' => $providers,
                'onchain' => false, 'l2beat' => false]
    );
}

function freeBudget(array $config): void
{
    $b = new Budget($config);
    foreach (array_keys($config['providers']) as $p) { $b->reset($p); }
}

/**
 * @return array{requests:int, byEndpoint:array, network:float}
 */
function measure(array $config, Coin $c, bool $warmFirst): array
{
    Probe::reset();
    Clock::reset();
    $GLOBALS['thb_options'] = [];
    freeBudget($config);
    $GLOBALS['thb_probe_dex_symbol'] = 'WETH';

    $p = pipeline($config);

    if ($warmFirst) {
        /* What cron does before any visitor arrives: fill every window and
           every dataset. A render measured against a half-warm cache is
           measuring the warm-up, not the steady state. */
        $GLOBALS['thb_probe_background'] = true;
        $datasets = array_merge(
            array_map(static fn($w) => 'chart.' . $w, array_keys($config['chart']['windows'])),
            ['market', 'metadata', 'historical', 'defi', 'dex', 'fx',
             'global', 'categories', 'sentiment', 'chains', 'stablecoins', 'structure']
        );
        foreach ($datasets as $dataset) {
            freeBudget($config);
            $p->warm($c, $dataset);
        }
        $GLOBALS['thb_probe_background'] = false;
        Probe::reset(keepCache: true);
    }

    $before = Probe::count();
    pipeline($config)->viewModel($c);

    return [
        'requests'   => Probe::count() - $before,
        'byEndpoint' => Probe::byEndpoint(),
        'network'    => (Probe::count() - $before) * ASSUMED_LATENCY,
    ];
}

$line = static fn(string $s = '') => print($s . "\n");
$head = static function (string $s) use ($line) {
    $line();
    $line(str_repeat('=', 72));
    $line($s);
    $line(str_repeat('=', 72));
};

$cases = [
    'Ethereum cold' => [coin('ethereum', 'ETH', 'Ethereum'), false],
    'Ethereum warm' => [coin('ethereum', 'ETH', 'Ethereum'), true],
    'Bitcoin cold'  => [coin('bitcoin', 'BTC', 'Bitcoin', providers: false), false],
    'Bitcoin warm'  => [coin('bitcoin', 'BTC', 'Bitcoin', providers: false), true],
];

$results = [];
foreach ($cases as $label => [$c, $warm]) {
    $results[$label] = measure($config, $c, $warm);
}

$head('Provider requests per page render');
$line();
printf("  %-18s %10s %12s\n", '', 'requests', 'network');
foreach ($results as $label => $r) {
    printf("  %-18s %10d %11.2fs\n", $label, $r['requests'], $r['network']);
}
$line();
$line('  network = requests x ' . ASSUMED_LATENCY . 's assumed round trip. The');
$line('  counts are exact; the seconds are a model, not a benchmark.');

$head('Where a cold Ethereum render spends its requests');
$line();
foreach ($results['Ethereum cold']['byEndpoint'] as $endpoint => $n) {
    printf("  %-58s %3d\n", substr($endpoint, 0, 58), $n);
}

$head('Baseline for v2');
$line();
$line('  The brief freezes these. A v2 page render may do LESS work than this');
$line('  but not more, and the warm figure must stay at zero: every Group A');
$line('  dataset is cache-only on render, so the scheduler owns them and a');
$line('  visitor never waits on a provider.');
$line();
printf("    Ethereum cold %d   warm %d\n", $results['Ethereum cold']['requests'], $results['Ethereum warm']['requests']);
printf("    Bitcoin  cold %d   warm %d\n", $results['Bitcoin cold']['requests'], $results['Bitcoin warm']['requests']);
$line();

/* A non-zero exit when the frozen numbers move, so this is usable in the
   runner rather than only by eye. */
$expected = ['Ethereum cold' => 7, 'Ethereum warm' => 0, 'Bitcoin cold' => 4, 'Bitcoin warm' => 0];
$drift = [];
foreach ($expected as $label => $n) {
    if ($results[$label]['requests'] !== $n) {
        $drift[] = sprintf('%s: expected %d, got %d', $label, $n, $results[$label]['requests']);
    }
}

if ($drift !== []) {
    $line('  DRIFT FROM THE BASELINE:');
    foreach ($drift as $d) { $line('    ' . $d); }
    exit(1);
}

$line('  All four match the baseline.');
exit(0);
