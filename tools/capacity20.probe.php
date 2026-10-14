<?php
/**
 * Request budget at 2 coins vs the top 20 (2.3.0) — the SHIPPING scheduler,
 * every collector, the /coins/ list, the real coin configurations.
 *
 *   php tools/capacity20.probe.php
 *
 * Runs Scheduler::tick() every interval for eight simulated hours against the
 * counting transport, CoinGecko held to what the production server measured
 * (five a minute, then 429), and averages the last six hours: steady state,
 * after every TTL has cycled. Besides requests per hour it reports FRESHNESS:
 * a budget-capped scheduler never exceeds its budget — it falls behind — so
 * the honest question at 20 coins is how far behind each dataset falls.
 */

declare(strict_types=1);

require_once __DIR__ . '/v2-bootstrap.php';

use TheHybit\Coins\{Budget, Cache, Scheduler, Datasets};

$plugin = \TheHybit\Coins\Plugin::instance();
$seeds = require THB_COINS_DIR . 'config/coins.php';
const OBSERVED_COINGECKO_PER_MINUTE = 5;

function register_coins(array $seeds, int $n): array
{
    $ids = [10 => 'ethereum', 11 => 'bitcoin'];
    $next = 100;
    foreach ($seeds as $slug => $spec) {
        if (count($ids) >= $n) {
            break;
        }
        if (in_array($slug, $ids, true)) {
            continue;
        }
        $GLOBALS['thb_coin_fields'][$next] = $spec['fields'];
        $GLOBALS['thb_post_slugs'][$next] = $slug;
        $ids[$next] = $slug;
        $next++;
    }
    $GLOBALS['thb_coin_ids'] = array_keys($ids);
    return $ids;
}

function simulate(array $config, int $n, array $seeds): array
{
    $plugin = \TheHybit\Coins\Plugin::instance();
    Probe::reset();
    Clock::reset();
    $GLOBALS['thb_probe_background'] = true;
    $GLOBALS['thb_probe_chart_realistic'] = true;   // CoinGecko's real chart spacing, ending now
    foreach (array_keys($config['providers']) as $p) { (new Budget($config))->reset($p); }
    Probe::$rateLimit['api.coingecko.com'] = ['max' => OBSERVED_COINGECKO_PER_MINUTE, 'per' => 60];
    $ids = register_coins($seeds, $n);

    $cache = new Cache($config);
    $pipeline = new \TheHybit\Coins\Pipeline($config, $cache, $plugin->history, $plugin->news);
    foreach ((function () { return $this->collectors; })->call($plugin->pipeline) as $c) {
        $cls = get_class($c);
        $pipeline->register(new $cls($config));
    }
    $listing = new \TheHybit\Coins\Listing($config, $cache, $pipeline, $plugin->coins);
    $scheduler = new Scheduler($config, $plugin->coins, $pipeline, $plugin->history, $listing);

    $interval = Scheduler::interval($config);
    $ticks = (int) floor(3600 / $interval);
    $hours = (int) (getenv('THB_HOURS') ?: 8);
    $window = 6;
    $start = 0;
    for ($h = 0; $h < $hours; $h++) {
        if ($h === $hours - $window) {
            $start = count(Probe::$calls);
        }
        for ($t = 0; $t < $ticks; $t++) {
            $at = Clock::$now;
            $scheduler->tick();
            Clock::$now = max($at + $interval, Clock::$now);
        }
        if (getenv('THB_FILL')) {
            /* How many coins have every chart window and the since-launch
               history, after each simulated hour from an empty cache. */
            $full = 0;
            $priced = 0;
            foreach ($ids as $id => $slug) {
                $coin = $plugin->coins->find($id);
                $ok = true;
                foreach (['chart.24h', 'chart.7d', 'chart.30d', 'chart.90d', 'chart.1y', 'longchart'] as $ds) {
                    $e = $cache->peek($ds, $cache->keyFor($ds, $coin));
                    if (!is_array($e) || empty($e['data'])) { $ok = false; }
                }
                $m = $cache->peek('market', $cache->keyFor('market', $coin));
                $priced += is_array($m) && !empty($m['data']) ? 1 : 0;
                $full += $ok ? 1 : 0;
            }
            fwrite(STDERR, sprintf("  after %dh: %d/%d coins priced, %d/%d with every chart filled\n", $h + 1, $priced, $n, $full, $n));
        }
    }
    $calls = array_slice(Probe::$calls, $start);
    if (getenv('THB_DEBUG_HOST')) {
        $by = [];
        foreach ($calls as $c) {
            if ($c['host'] === getenv('THB_DEBUG_HOST')) {
                $u = preg_replace(['#\?.*#', '#/coins/(?!markets)[a-z0-9-]+#'], ['', '/coins/{id}'], $c['url']);
                if (str_contains($c['url'], 'market_chart')) { preg_match('#days=([0-9a-z]+)#', $c['url'], $dm); $u .= '?days=' . ($dm[1] ?? '?'); }
                $by[$u] = ($by[$u] ?? 0) + 1 / $window;
            }
        }
        arsort($by);
        foreach (array_slice($by, 0, 25, true) as $u => $n) { fwrite(STDERR, sprintf("  %6.1f/h  %s\n", $n, $u)); }
    }
    $byHost = [];
    foreach ($calls as $c) {
        $byHost[$c['host']] = ($byHost[$c['host']] ?? 0) + 1 / $window;
    }
    ksort($byHost);
    $cg = array_values(array_filter($calls, static fn($c) => $c['host'] === 'api.coingecko.com'));
    $times = array_map(static fn($c) => (int) $c['at'], $cg);
    sort($times);
    $peak = 0;
    for ($i = 0, $j = 0, $k = count($times); $i < $k; $i++) {
        while ($times[$i] - $times[$j] >= 60) { $j++; }
        $peak = max($peak, $i - $j + 1);
    }

    /* Freshness of every coin-scoped CoinGecko dataset at the end of the
       run: age over TTL. 1 = exactly due; 3 = three TTLs old. */
    $sets = new Datasets($config);
    $worst = [];
    foreach ($ids as $id => $slug) {
        $coin = $plugin->coins->find($id);
        foreach (['chart.24h', 'chart.7d', 'chart.30d', 'chart.90d', 'chart.1y', 'metadata', 'historical', 'structure'] as $ds) {
            $e = $cache->peek($ds, $cache->keyFor($ds, $coin));
            $age = is_array($e) ? (Clock::$now - (int) ($e['fetchedAt'] ?? 0)) / max(1, $cache->ttl($ds)) : INF;
            $worst[$ds] = max($worst[$ds] ?? 0, $age);
            if (getenv('THB_DEBUG_FRESH') && $age > 1.5) { fwrite(STDERR, "  stale: {$slug} {$ds} " . (is_finite($age) ? round($age, 1) : 'never') . "\n"); }
        }
    }
    $GLOBALS['thb_probe_background'] = false;
    return ['byHost' => $byHost, 'peak' => $peak, 'refused' => Probe::$refused['api.coingecko.com'] ?? 0,
            'ticker' => count(array_filter($cg, static fn($c) => str_contains($c['url'], '/simple/price'))) / $window,
            'listing' => count(array_filter($cg, static fn($c) => str_contains($c['url'], 'order=market_cap_desc'))) / $window,
            'worst' => $worst, 'ticks' => $ticks];
}

$variants = ['current TTLs' => $plugin->config];
if (getenv('THB_TTL_PROPOSAL')) {
    $variants = ['proposed TTLs' => $plugin->config];
}
foreach ($variants as $label => $config) {
    /* THB_SIZES=20,50 compares the 2.5 site with the 2.6 one; default 2,20. */
    [$na, $nb] = array_map('intval', explode(',', getenv('THB_SIZES') ?: '2,20'));
    $r2 = simulate($config, $na, $seeds);
    $r20 = simulate($config, $nb, $seeds);
    printf("\n=== %s — requests per hour (steady state, hours 3–8) ===\n", $label);
    printf("  %-28s %10s %10s\n", 'host', $na . ' coins', $nb . ' coins');
    foreach (array_unique(array_merge(array_keys($r2['byHost']), array_keys($r20['byHost']))) as $host) {
        printf("  %-28s %10.1f %10.1f\n", $host, $r2['byHost'][$host] ?? 0, $r20['byHost'][$host] ?? 0);
    }
    printf("  %-28s %10.1f %10.1f\n", '  of which live ticker', $r2['ticker'], $r20['ticker']);
    printf("  %-28s %10.1f %10.1f\n", '  of which /coins/ list', $r2['listing'], $r20['listing']);
    printf("  %-28s %10d %10d\n", 'CoinGecko busiest 60 s', $r2['peak'], $r20['peak']);
    printf("  %-28s %10d %10d\n", 'CoinGecko 429s (8 h)', $r2['refused'], $r20['refused']);
    printf("  %-28s %10.0f %10.0f\n", 'CoinGecko per day (x24)', 24 * ($r2['byHost']['api.coingecko.com'] ?? 0), 24 * ($r20['byHost']['api.coingecko.com'] ?? 0));
    echo "\n  Freshness at the end (worst coin, age ÷ TTL; ≤ 1 is on time):\n";
    foreach ($r20['worst'] as $ds => $w) {
        printf("  %-28s %10.1f %10.1f\n", $ds, $r2['worst'][$ds], $w);
    }
    if (getenv('THB_CAPACITY_JSON')) {
        file_put_contents((string) getenv('THB_CAPACITY_JSON'), json_encode(['2' => $r2, '20' => $r20], JSON_PRETTY_PRINT));
    }
}
