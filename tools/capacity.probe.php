<?php
/**
 * Capacity model — requests per hour, per provider, at 2 and 100 coins.
 *
 *   php tools/capacity.probe.php
 *
 * Runs the SHIPPING Scheduler::tick() once every 55 simulated seconds for an
 * hour, against the counting transport, with CoinGecko held to what the
 * production server actually measured (five requests a minute, then 429). The
 * figures are therefore what the scheduler really does under the budget and
 * the item cap — including the work it DECLINES to do — not what the TTLs
 * would imply if nothing else competed for a tick.
 *
 * Coins are spread across chains the way a real top-100 list is: most on
 * Ethereum, a handful each on other chains. Chain scope is the reason the
 * Group B providers stay cheap, so a model with one coin per chain would
 * overstate them badly.
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

use TheHybit\Coins\{Cache, History, News, Pipeline, Scheduler, CoinRepository, Budget};
use TheHybit\Coins\Collectors as C;

$config = require $base . 'config/providers.php';

/** Measured on the production server: five succeed, the sixth is refused. */
const OBSERVED_COINGECKO_PER_MINUTE = 5;

/**
 * N coins, distributed over chains like a real top-100.
 *
 * Roughly: two-thirds ERC-20s on Ethereum, a tenth native or wrapped Bitcoin
 * exposure, the rest spread across other chains. The exact mix moves the
 * per-chain figures a little; it cannot move them by an order of magnitude,
 * which is the point of chain scope.
 */
function registerCoins(int $n): void
{
    $chains = $n <= 2
        ? ['Ethereum', 'Bitcoin']
        : array_merge(
            array_fill(0, (int) round($n * 0.62), 'Ethereum'),
            array_fill(0, (int) round($n * 0.10), 'Bitcoin'),
            ['Solana', 'BSC', 'Arbitrum', 'Base', 'Avalanche', 'Polygon', 'Tron', 'Optimism']
        );

    $GLOBALS['thb_post_slugs'] = [];
    $GLOBALS['thb_coin_fields'] = [];
    $ids = [];

    for ($i = 0; $i < $n; $i++) {
        $id = 1000 + $i;
        $ids[] = $id;
        $chain = $chains[$i % count($chains)];
        $slug = $n <= 2 ? ['ethereum', 'bitcoin'][$i] : 'coin' . $i;

        $GLOBALS['thb_post_slugs'][$id] = $slug;
        $GLOBALS['thb_coin_fields'][$id] = [
            'thb_symbol'             => strtoupper(substr($slug, 0, 3)),
            'thb_name_fa'            => $slug,
            'thb_name_en'            => $slug,
            'thb_coingecko_id'       => $slug,
            'thb_news_category_slug' => $slug . '-news',
            'thb_defillama_chain'    => $chain,
            'thb_dex_token_address'  => '0xabc' . $i,
            'thb_dex_symbols'        => 'WETH',
            'thb_github_repo'        => 'org' . $i . '/repo' . $i,
            // Bitcoin-style coins have no DeFi or DEX surface; everything else does.
            'thb_enable_defi'        => $chain === 'Bitcoin' ? 0 : 1,
            'thb_enable_dex'         => $chain === 'Bitcoin' ? 0 : 1,
            'thb_enable_onchain'     => 0,
            'thb_enable_l2'          => 0,
        ];
    }
    $GLOBALS['thb_coin_ids'] = $ids;
}

/**
 * One simulated hour of cron.
 *
 * @return array{byHost:array<string,int>, ticks:int, worstTick:int}
 */
function simulateHour(array $config, int $coins, int $hours = 8): array
{
    Probe::reset();
    Clock::reset();
    $GLOBALS['thb_options'] = [];
    $GLOBALS['thb_probe_background'] = true;
    $GLOBALS['thb_probe_dex_symbol'] = 'WETH';

    $b = new Budget($config);
    foreach (array_keys($config['providers']) as $p) { $b->reset($p); }

    Probe::$rateLimit['api.coingecko.com'] = ['max' => OBSERVED_COINGECKO_PER_MINUTE, 'per' => 60];

    registerCoins($coins);

    $cache = new Cache($config);
    $history = new History($config);
    $pipeline = new Pipeline($config, $cache, $history, new News($config));
    foreach ([C\CoinGecko::class, C\DefiLlama::class, C\DexScreener::class, C\L2Beat::class,
              C\OnChain::class, C\Fx::class, C\Alternative::class, C\Etherscan::class,
              C\Blockchair::class, C\BeaconChain::class, C\GitHub::class] as $cls) {
        $pipeline->register(new $cls($config));
    }
    $scheduler = new Scheduler($config, new CoinRepository(), $pipeline, $history);

    $interval = Scheduler::interval($config);
    $ticks = (int) floor(3600 / $interval);
    $worst = 0;

    /* STEADY STATE, NOT WARM-UP. The first hour from a cold cache fetches
       everything once — including six-hourly and daily datasets — and so
       overstates them; it also understates nothing, because a cold queue is
       full. Running eight hours and keeping only the last measures what the
       scheduler does once every TTL has cycled at least once. */
    /* Averaged over the last SIX hours, not sampled from one. The slowest
       Group B dataset refreshes every six hours, so any single hour either
       contains its refresh or does not — a sample would report 0 or double the
       true rate depending on luck. Six hours is one full cycle of everything. */
    $window = 6;
    $lastHourStart = 0;
    for ($h = 0; $h < $hours; $h++) {
        if ($h === $hours - $window) {
            $lastHourStart = count(Probe::$calls);
        }
        for ($t = 0; $t < $ticks; $t++) {
            $start = Clock::$now;
            $before = Probe::count();
            $scheduler->tick();
            if ($h >= $hours - $window) {
                $worst = max($worst, Probe::count() - $before);
            }
            // Next tick fires on the interval, or later if this one ran long.
            Clock::$now = max($start + $interval, Clock::$now);
        }
    }

    $GLOBALS['thb_last_hour_calls'] = array_slice(Probe::$calls, $lastHourStart);

    $byHost = [];
    foreach ($GLOBALS['thb_last_hour_calls'] as $call) {
        $byHost[$call['host']] = ($byHost[$call['host']] ?? 0) + 1;
    }
    $byHost = array_map(static fn(int $n): float => $n / $window, $byHost);
    ksort($byHost);

    $GLOBALS['thb_probe_background'] = false;
    return ['byHost' => $byHost, 'ticks' => $ticks, 'worstTick' => $worst];
}

/** Calls per hour to URLs containing $needle — for per-dataset rows. */
function perEndpoint(string $needle): float
{
    return count(array_filter($GLOBALS['thb_last_hour_calls'] ?? [], static fn($c) => str_contains($c['url'], $needle))) / 6;
}

$endpoints = [
    'gas'         => 'gastracker',
    'supply'      => 'ethsupply2',
    'network'     => '/stats',
    'staking'     => 'beaconcha.in',
    'development' => 'api.github.com',
];

$results = [];
$perDataset = [];
foreach ([2, 100] as $n) {
    $results[$n] = simulateHour($config, $n);
    foreach ($endpoints as $dataset => $needle) {
        $perDataset[$n][$dataset] = perEndpoint($needle);
    }
}

$line = static fn(string $s = '') => print($s . "\n");

$line(str_repeat('=', 72));
$line('Requests per hour by provider — steady state, averaged over hours 3-8');
$line(str_repeat('=', 72));
$line();
$hosts = array_unique(array_merge(array_keys($results[2]['byHost']), array_keys($results[100]['byHost'])));
sort($hosts);
printf("  %-30s %10s %10s\n", 'host', '2 coins', '100 coins');
foreach ($hosts as $host) {
    printf("  %-30s %10.1f %10.1f\n", $host, $results[2]['byHost'][$host] ?? 0, $results[100]['byHost'][$host] ?? 0);
}
$line();
printf("  ticks per hour: %d   worst tick: %d requests (2 coins), %d (100 coins)\n",
    $results[2]['ticks'], $results[2]['worstTick'], $results[100]['worstTick']);

$line();
$line(str_repeat('=', 72));
$line('Group B datasets, per hour');
$line(str_repeat('=', 72));
$line();
printf("  %-14s %10s %10s\n", 'dataset', '2 coins', '100 coins');
foreach ($endpoints as $dataset => $_) {
    printf("  %-14s %10.1f %10.1f\n", $dataset, $perDataset[2][$dataset], $perDataset[100][$dataset]);
}

$line();
$line('  `network` counts every /stats call, so at 2 coins it is Ethereum AND');
$line('  Bitcoin — two chains. `development` counts repo + commits, two calls a');
$line('  refresh. 100 coins spans ten chains; chain-scoped datasets scale with');
$line('  THAT, not with the coin count.');

if (getenv('THB_CAPACITY_JSON')) {
    file_put_contents((string) getenv('THB_CAPACITY_JSON'), json_encode([
        'byHost' => [2 => $results[2]['byHost'], 100 => $results[100]['byHost']],
        'perDataset' => $perDataset,
    ], JSON_PRETTY_PRINT));
}
