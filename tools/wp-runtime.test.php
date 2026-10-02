<?php
/**
 * Runtime tests: the layer that needs WordPress.
 *
 *   php tools/wp-runtime.test.php
 *
 * php-test.php covers the maths. This file covers everything above it — the
 * cache's behaviour on failure, the request budget, the warming lock, the cron
 * scheduler, the collectors' parsing and the pipeline's request cost.
 *
 * It runs the SHIPPING classes against a WordPress double, so what is asserted
 * here is what runs in production. Where the double has to be faithful rather
 * than convenient — the options table's atomicity, wp_schedule_event()'s
 * recurrence check — it is, because the bugs those cover all shipped once.
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

/* Extra stubs the plugin bootstrap itself needs at load time. */
function plugin_dir_path(string $file): string { return dirname($file) . '/'; }
function plugin_dir_url(string $file): string { return 'https://thehybit.com/wp-content/plugins/thehybit-coins/'; }
function register_activation_hook(string $f, $cb): void {}
function register_deactivation_hook(string $f, $cb): void {}
function locate_template($t, bool $load = false, bool $once = true): string { return ''; }
function is_singular($t = ''): bool { return true; }
function get_the_ID(): int { return 10; }
function register_post_type(string $t, array $a) { return null; }
function wp_enqueue_style(...$a): void {}
function wp_enqueue_script(...$a): void {}
function wp_add_inline_script(...$a): bool { return true; }
function add_submenu_page(...$a) { return ''; }
function wp_get_script_tag(array $attr): string { return '<script></script>'; }
function submit_button(...$a): void {}
function flush_rewrite_rules(bool $hard = true): void {}

$base = __DIR__ . '/../thehybit-coins/';
require_once $base . 'thehybit-coins.php';

use TheHybit\Coins\{Plugin, Coin, Cache, History, News, Pipeline, CoinRepository,
                    Datasets, Budget, Lock, Scheduler, Context, Derive, Scoring, Settings, ProviderProbe};
use TheHybit\Coins\Collectors as C;

$config = require $base . 'config/providers.php';
$GLOBALS['thb_cfg'] = $config;

$failed = 0;
function ok(bool $cond, string $msg): void
{
    global $failed;
    if (!$cond) { $failed++; }
    echo ($cond ? 'PASS' : 'FAIL') . "  {$msg}\n";
}
function section(string $s): void { echo "\n--- {$s} " . str_repeat('-', max(0, 58 - strlen($s))) . "\n"; }

function pipeline(array $config): Pipeline
{
    $p = new Pipeline($config, new Cache($config), new History($config), new News($config));
    foreach ([C\CoinGecko::class, C\DefiLlama::class, C\DexScreener::class,
              C\L2Beat::class, C\OnChain::class, C\Fx::class, C\Alternative::class,
              C\Etherscan::class, C\Blockchair::class, C\BeaconChain::class, C\GitHub::class] as $cls) {
        $p->register(new $cls($config));
    }
    return $p;
}

/**
 * Clear every provider budget.
 *
 * Scenarios that predate budgets mean "the provider is willing"; without this
 * they would measure the budget rather than the thing under test. The budget
 * gets its own section.
 */
function freeBudget(array $config): void
{
    $budget = new Budget($config);
    foreach (array_keys($config['providers']) as $provider) {
        $budget->reset($provider);
    }
}

function ethereum(array $meta = [], array $flags = []): Coin
{
    $GLOBALS['thb_probe_slug'] = 'ethereum';
    return new Coin(
        postId: 10, slug: 'ethereum', symbol: 'ETH', name: 'اتریوم', nameEn: 'Ethereum',
        coingeckoId: 'ethereum', newsCategorySlug: 'ethereum-news',
        meta: $meta + ['defillamaChain' => 'Ethereum', 'dexTokenAddress' => '0xC02aaA39'],
        flags: $flags + ['defillama' => true, 'dexscreener' => true, 'onchain' => false, 'l2beat' => false]
    );
}

function bitcoin(): Coin
{
    return new Coin(
        postId: 11, slug: 'bitcoin', symbol: 'BTC', name: 'بیت‌کوین', nameEn: 'Bitcoin',
        coingeckoId: 'bitcoin', newsCategorySlug: 'bitcoin-news',
        meta: ['defillamaChain' => 'Bitcoin'],
        flags: ['defillama' => false, 'dexscreener' => false, 'onchain' => false, 'l2beat' => false]
    );
}

function reset_world(array $config): void
{
    Probe::reset();
    Clock::reset();
    $GLOBALS['thb_options'] = [];
    $GLOBALS['thb_cron'] = [];
    freeBudget($config);
}

/* =====================================================================
 * 1. Cache — TTL, stale-while-error, negative caching
 *
 * Stale-while-error is the behaviour that keeps a rate limit from blanking a
 * section: the last good payload is served with a flag rather than nothing.
 * Negative caching is what stops one dead provider costing five identical
 * calls per page render, forever.
 * ================================================================== */
section('cache');

reset_world($config);
$cache = new Cache($config);

$calls = 0;
$fetch = static function () use (&$calls): array { $calls++; return ['v' => $calls]; };

$first = $cache->remember('market', 'k', $fetch);
ok($first['data']['v'] === 1 && $calls === 1, 'a cold read fetches once');

$second = $cache->remember('market', 'k', $fetch);
ok($second['data']['v'] === 1 && $calls === 1, 'a warm read inside the TTL does not fetch again');
ok($second['stale'] === false, 'and is not marked stale');

Clock::advance($cache->ttl('market') + 1);
$third = $cache->remember('market', 'k', $fetch);
ok($third['data']['v'] === 2 && $calls === 2, 'past the TTL it refetches');

/* The failure path: the previous value survives. */
Clock::advance($cache->ttl('market') + 1);
$failing = $cache->remember('market', 'k', static fn() => throw new RuntimeException('429'));
ok($failing['data']['v'] === 2, 'a failed refresh keeps the last good payload');
ok($failing['stale'] === true, 'and flags it stale so the UI can say so');
ok($failing['source'] === 'stale', 'with a source that names the situation');

/* With nothing cached, a failure is remembered briefly so it is not retried
   by every pipeline run in the same render. */
$missCalls = 0;
$miss = static function () use (&$missCalls) { $missCalls++; throw new RuntimeException('down'); };
$cache->remember('defi', 'none', $miss);
$cache->remember('defi', 'none', $miss);
$cache->remember('defi', 'none', $miss);
ok($missCalls === 1, 'a provider with nothing to give is called ONCE, not once per pipeline run');

Clock::advance($cache->missTtl() + 1);
$cache->remember('defi', 'none', $miss);
ok($missCalls === 2, 'and is retried once the short miss TTL lapses — recovery is picked up quickly');

/* The merge callback: a partial refresh must not delete what it did not return. */
$merged = $cache->remember(
    'chart', 'm',
    static fn() => ['24h' => [1]],
    static fn(array $fresh, array $prev) => $fresh + $prev
);
Clock::advance($cache->ttl('chart') + 1);
$merged2 = $cache->remember(
    'chart', 'm',
    static fn() => ['7d' => [2]],
    static fn(array $fresh, array $prev) => $fresh + $prev
);
ok(isset($merged2['data']['24h'], $merged2['data']['7d']),
   'a partial chart refresh keeps the windows it did not fetch');

/* =====================================================================
 * 2. Budget — refusal before the request, cooldown after a 429
 * ================================================================== */
section('provider budget');

reset_world($config);
$budget = new Budget($config);
$perMinute = (int) $config['providers']['coingecko']['budget']['per_minute'];

ok($budget->refuse('coingecko') === null, 'a fresh budget allows a request');

for ($i = 0; $i < $perMinute; $i++) {
    $budget->record('coingecko');
}
ok($budget->refuse('coingecko') !== null,
   "after {$perMinute} requests the per-minute budget refuses: " . (string) $budget->refuse('coingecko'));
ok($budget->remaining('coingecko') === 0, 'and reports nothing remaining');

Clock::advance(61);
ok($budget->refuse('coingecko') === null, 'the window rolls and the allowance refills');

/* A 429 stops the provider entirely, and the provider's own Retry-After wins
   over our configured default. */
$seconds = $budget->startCooldown('coingecko', 60);
ok($seconds === 60, "Retry-After beats the configured cooldown ({$seconds}s vs {$config['providers']['coingecko']['cooldown']}s)");
ok($budget->cooldownRemaining('coingecko') > 0, 'the provider is now cooling down');
ok(str_contains((string) $budget->refuse('coingecko'), 'cool'), 'and every request is refused while it does');
ok($budget->remaining('coingecko') === 0, 'with nothing remaining regardless of the counters');

Clock::advance(30);
ok($budget->refuse('coingecko') !== null, 'still cooling halfway through');
Clock::advance(40);
ok($budget->refuse('coingecko') === null, 'and clear once Retry-After has elapsed');

/* An absurd Retry-After must not freeze the site for a day. */
$capped = $budget->startCooldown('coingecko', 86400);
ok($capped <= HOUR_IN_SECONDS, "a day-long Retry-After is capped at an hour (got {$capped}s)");
$budget->reset('coingecko');

$snap = $budget->snapshot('coingecko');
ok(isset($snap['limits'], $snap['used'], $snap['remaining'], $snap['cooldown']),
   'the snapshot carries everything the diagnostics screen needs');

/* A provider with no configured budget is unlimited, not blocked. */
ok($budget->refuse('l2beat') === null, 'a provider with no budget configured is not refused');

/* =====================================================================
 * 3. The budget gate is wired into the collector
 * ================================================================== */
section('budget gate');

reset_world($config);
$GLOBALS['thb_probe_background'] = true;
$gecko = new C\CoinGecko($config);
$eth = ethereum();

for ($i = 0; $i < $perMinute; $i++) {
    $budget->record('coingecko');
}
$threw = false;
try { $gecko->fetch($eth, 'market'); } catch (\Throwable $e) { $threw = true; }
ok($threw, 'a spent budget throws BEFORE the request leaves');
ok(Probe::count('api.coingecko.com') === 0,
   'so no request is made — a request we do not send cannot earn a 429');

/* A real 429 starts a cooldown carrying the provider's own Retry-After.
   A FRESH collector: the one above memoized its refusal, and a memoized failure
   is rethrown without a request — correct behaviour, but it would mean this
   assertion never reached the transport. */
reset_world($config);
Probe::$rateLimit['api.coingecko.com'] = ['max' => 0, 'per' => 60];
try { (new C\CoinGecko($config))->fetch($eth, 'market'); } catch (\Throwable $e) {}
ok((new Budget($config))->cooldownRemaining('coingecko') > 0,
   'a 429 from the provider starts a cooldown');
Probe::$rateLimit = [];

/* =====================================================================
 * 4. Collectors — what each one actually parses
 * ================================================================== */
section('collectors');

reset_world($config);
$GLOBALS['thb_probe_background'] = true;

/* Fresh instances throughout this section. A collector memoizes the coin-detail
   response — and any exception from it — for its own lifetime, so reusing one
   across sections would replay an earlier section's refusal. */
$gecko = new C\CoinGecko($config);
$market = $gecko->fetch($eth, 'market');
ok($market['price'] === 3245.67, 'CoinGecko market: price');
ok($market['marketCap'] === 390226904100.0, 'market cap');
ok($market['rank'] === 2, 'rank');
ok($market['maxSupply'] === null, 'an uncapped max supply stays NULL — never 0, which would be a claim');
ok(is_array($market['sparkline']) && count($market['sparkline']) === 168, 'the 7-day sparkline');
ok(!isset($market['performance']['90d']),
   'and NO 90d figure: CoinGecko has none, so the 60d one is not relabelled as it');

freeBudget($config);
$meta = $gecko->fetch($eth, 'metadata');
ok($meta['nameEn'] === 'Ethereum' && $meta['genesisDate'] === '2015-07-30', 'CoinGecko metadata');
ok(in_array('Layer 1 (L1)', $meta['categories'], true), 'categories ride the same response');

freeBudget($config);
$hist = $gecko->fetch($eth, 'historical');
ok(($hist['ath']['price'] ?? null) == 4891.7, 'historical ATH price');
ok(($hist['ath']['changePct'] ?? null) == -33.65, 'and the drawdown from it');

freeBudget($config);
$global = $gecko->fetch($eth, 'global');
ok($global['totalMarketCap'] === 2.41e12 && $global['btcDominance'] === 54.2,
   'CoinGecko global: total market cap and BTC dominance');

freeBudget($config);
$cats = $gecko->fetch($eth, 'categories');
ok(count($cats['categories']) === 3,
   'categories: the row with no market cap is dropped rather than cached as null');
ok($cats['categories'][0]['marketCap'] >= $cats['categories'][1]['marketCap'],
   'and the list is sorted by size');

freeBudget($config);
$structure = $gecko->fetch($eth, 'structure');
ok($structure['marketsSeen'] === 6,
   'market structure discards the zero-volume and unnamed tickers (6 of 8 rows)');
ok($structure['topVenue'] === 'Binance', 'identifies the top venue by volume');
ok($structure['concentration'] !== null, 'reports concentration with six venues present');
ok(!isset($structure['tickers']),
   'and never returns the raw ticker array — it would blow the 1MB cache item ceiling');

freeBudget($config);
$llama = new C\DefiLlama($config);
$defi = $llama->fetch($eth, 'defi');
ok($defi['tvl'] > 0 && $defi['tvlChange7d'] !== null, 'DefiLlama TVL and its 7-day change');
ok($defi['fees24h'] === 1.26e7, 'with fees from the second endpoint');

freeBudget($config);
$chains = $llama->fetch($eth, 'chains');
ok($chains['chains'][0]['name'] === 'Ethereum' && $chains['chains'][0]['rank'] === 1,
   'chains are ranked by TVL — a real ordering, unlike categories');
ok(count($chains['chains']) === 4, 'and the null-TVL chain is skipped, not summed as zero');

freeBudget($config);
$stables = $llama->fetch($eth, 'stablecoins');
ok(abs(($stables['byChain']['Ethereum'] ?? 0) - 8.1e10) < 1e9,
   'stablecoin supply sums EVERY peg type, not just peggedUSD');

freeBudget($config);
$dex = (new C\DexScreener($config))->fetch($eth, 'dex');
ok($dex['pairCount'] === 30 && $dex['volume24h'] > 0, 'DexScreener pairs and volume');
/* The pair is labelled with the COIN's canonical symbol, not the wrapped
   token the pool actually quotes — a reader looking for ETH should not have to
   know that WETH is the same thing. */
ok(($dex['topPair']['pair'] ?? '') === 'ETH/USDC',
   'the top pair uses the coin symbol, not the wrapped one: ' . ($dex['topPair']['pair'] ?? '—'));

freeBudget($config);
$fng = (new C\Alternative($config))->fetch($eth, 'sentiment');
ok($fng['value'] === 55, 'Fear & Greed current value');
ok($fng['labelFa'] !== '', 'with a Persian label derived from the value: ' . $fng['labelFa']);
ok($fng['previous'] !== null && $fng['change'] !== null, 'and a day-on-day change');

freeBudget($config);
$fx = (new C\Fx($config))->fetch($eth, 'fx');
ok($fx['usdToRial'] === 830000.0 && $fx['usdToToman'] === 83000.0,
   'FX picks USD out of a multi-currency response and derives Toman');

/* Malformed responses must become null — never a fatal, never a zero. */
foreach ([
    'an empty array'         => [],
    'an unexpected object'   => ['error' => 'nope'],
    'rows missing fields'    => [['name' => 'X'], ['tvl' => 5]],
    'nulls where numbers go' => [['name' => 'Ethereum', 'tvl' => null]],
] as $label => $payload) {
    $GLOBALS['thb_probe_override'] = $payload;
    freeBudget($config);
    $threw = false;
    $result = 'unset';
    try { $result = $llama->fetch($eth, 'chains'); } catch (\Throwable $e) { $threw = true; }
    unset($GLOBALS['thb_probe_override']);
    ok(!$threw && $result === null, "{$label} yields null, not a fatal or a fabricated zero");
}

/* =====================================================================
 * 5. The warming lock
 *
 * Two ticks would spend the same minute's budget twice and overwrite each
 * other's cursor. The acquire is one atomic statement against the options
 * table, which the double emulates faithfully — see FakeWpdb::query().
 * ================================================================== */
section('warm lock');

reset_world($config);
$lock = new Lock($config);

$token = $lock->acquire();
ok(is_string($token) && $token !== '', 'a free lock can be taken');
ok($lock->acquire() === null, 'and a second process cannot take it while held');
ok($lock->isHeld(), 'it reports itself held');

$status = $lock->status();
ok($status['pid'] > 0, "it names its holder (pid {$status['pid']}) so a stuck worker is identifiable");
ok($lock->ttl() >= 3 * (int) $config['scheduler']['max_seconds'],
   "and outlives a full-length tick by design ({$lock->ttl()}s)");

ok($lock->release($token) === true, 'the owner can release it');
ok($lock->release($token) === false, 'releasing twice is a harmless no-op');
ok(!$lock->isHeld(), 'and it is free again');

/* A worker that dies never releases. The lock must expire, or warming stops
   forever — but expiry is a compare-and-swap takeover, not a delete, so only
   one of several waiters can win it. */
$dead = $lock->acquire();
Clock::advance($lock->ttl() - 5);
ok($lock->acquire() === null, 'a merely slow worker keeps its lock right up to expiry');

Clock::advance(10);
$expired = $lock->status();
ok(!$expired['held'] && $expired['expired'],
   'past the TTL it reads as expired-but-present, not as absent');

$recovered = $lock->acquire();
ok(is_string($recovered), 'the next tick takes the dead worker\'s lock over — warming cannot be stuck forever');
$takeovers = (array) get_option(Lock::OPTION_TAKEOVERS, []);
ok((int) ($takeovers['count'] ?? 0) === 1,
   'the takeover is counted, so repeatedly dying ticks are visible rather than inferred');

ok($lock->release((string) $dead) === false,
   'and the displaced worker waking up CANNOT release the lock it lost');
ok($lock->isHeld(), 'so the new owner still holds it');
$lock->release((string) $recovered);

/* =====================================================================
 * 6. Datasets — scope, render mode, enablement
 * ================================================================== */
section('datasets');

$sets = new Datasets($config);
$btc = bitcoin();

foreach (['global', 'categories', 'sentiment', 'chains', 'stablecoins'] as $dataset) {
    $k = $cache->keyFor($dataset, $eth);
    ok($k === $cache->keyFor($dataset, $btc), "{$dataset}: both coins share one entry ({$k})");
    ok($sets->rendersFromCacheOnly($dataset),
       "and it is cache-only on render, so a page never fetches it");
}

ok($cache->keyFor('structure', $eth) !== $cache->keyFor('structure', $btc),
   'structure is genuinely per coin');
ok($cache->keyFor('defi', $eth) !== $cache->keyFor('defi', $btc),
   'defi is per chain, and these two are different chains');
ok(!$sets->isEnabled('bridges'),
   'bridges ships disabled — the production probe got HTTP 407 from that endpoint');
ok(!in_array('bridges', $sets->forCoin($eth), true),
   'so it never enters a coin\'s dataset list');

/* =====================================================================
 * 7. Pipeline — request cost, memoization and cache-only rendering
 *
 * The number the brief freezes: Ethereum cold 7, warm 0.
 * ================================================================== */
section('pipeline');

reset_world($config);
Probe::$transients = [];
$GLOBALS['thb_probe_background'] = false;   // a visitor, not cron

$GLOBALS['thb_probe_dex_symbol'] = 'WETH';
$p = pipeline($config);
$model = $p->viewModel(ethereum(['dexSymbols' => 'WETH']));
$cold = Probe::count();

ok($cold === 7, "a COLD Ethereum render makes exactly 7 provider requests (got {$cold})");

/* "Warm" means every chart window cached, which is the steady state cron
   maintains. A render refreshes at most `fetches_per_request` stale windows, so
   the two renders after a cold one legitimately fetch the remaining three —
   that staggering is the fix for the burst that used to starve the 90d and 1y
   windows, not a leak. */
$p2 = pipeline($config);
foreach (array_keys($config['chart']['windows']) as $period) {
    freeBudget($config);
    $p2->warm(ethereum(['dexSymbols' => 'WETH']), 'chart.' . $period);
}
$windows = 0;
foreach (array_keys($config['chart']['windows']) as $period) {
    if ($cache->peek('chart.' . $period, $cache->keyFor('chart.' . $period, $eth)) !== null) { $windows++; }
}
ok($windows === 5, "all five chart windows are cached after cron fills them (got {$windows})");

$before = Probe::count();
$p2->viewModel(ethereum(['dexSymbols' => 'WETH']));
ok(Probe::count() - $before === 0, 'a fully WARM render makes none');

/* Five unrelated call sites ask for the model in one render; the memo is what
   stops that costing five passes. */
$before = Probe::count();
for ($i = 0; $i < 5; $i++) { $p2->viewModel(ethereum(['dexSymbols' => 'WETH'])); }
ok(Probe::count() - $before === 0, 'and asking five more times costs nothing');

ok($model['market']['price'] === 3245.67, 'the model carries the price');
ok($model['coin']['symbol'] === 'ETH', 'and the coin identity');
ok(is_array($model['series'] ?? null), 'with chart series');
ok(isset($model['analytics']['value']), 'and a score');

/* Cache-only datasets are NOT fetched by a render, however cold it is. */
reset_world($config);
Probe::$transients = [];
pipeline($config)->viewModel(ethereum());
$siteFetches = 0;
foreach (Probe::$calls as $call) {
    foreach (['/global', '/coins/categories', '/fng', '/v2/chains', '/stablecoinchains'] as $f) {
        if (str_contains($call['url'], $f)) { $siteFetches++; }
    }
}
ok($siteFetches === 0, 'a cold render fetches no Group A dataset at all — the scheduler owns them');

/* Those sections report WHY they are empty, rather than rendering zeros. */
$m = pipeline($config)->viewModel(ethereum());
ok(($m['globalMarket']['state'] ?? '') === 'pending',
   'an unwarmed site dataset reports PENDING, not unavailable and not zero');
ok(array_key_exists('data', $m['globalMarket']) && $m['globalMarket']['data'] === null,
   'with null data rather than an empty array of zeros');
ok(($m['bridges']['state'] ?? '') === 'disabled',
   'a dataset switched off in config reports DISABLED — not an error');

/* Bitcoin has DeFi and DEX switched off per coin, so it costs less. */
reset_world($config);
Probe::$transients = [];
pipeline($config)->viewModel(bitcoin());
$btcCold = Probe::count();
ok($btcCold === 4, "a cold Bitcoin render makes 4 requests (got {$btcCold}) — its providers are off per coin");

/* The batch: one request, every coin written. */
reset_world($config);
Probe::$transients = [];
$GLOBALS['thb_probe_background'] = true;
$coins = [ethereum(), bitcoin()];
$written = pipeline($config)->warmBatch($coins, 'market');
ok($written === 2, "the batch wrote both coins (got {$written})");
ok(Probe::count('/coins/markets') === 1, 'from ONE request');
ok($cache->peek('market', $cache->keyFor('market', $eth)) !== null,
   'into the same cache entry a single fetch would have filled');

/* =====================================================================
 * 8. Scheduler — schedule registration, bounds, cursor, shared pass
 * ================================================================== */
section('scheduler');

reset_world($config);
$GLOBALS['thb_acf'] = [
    'thb_symbol' => 'ETH', 'thb_name_fa' => 'اتریوم', 'thb_name_en' => 'Ethereum',
    'thb_coingecko_id' => 'ethereum', 'thb_news_category_slug' => 'ethereum-news',
    'thb_defillama_chain' => 'Ethereum', 'thb_dex_token_address' => '0xC02aaA39',
    'thb_enable_defi' => 1, 'thb_enable_dex' => 1,
];
$GLOBALS['thb_post_slugs'] = [10 => 'ethereum'];
$GLOBALS['thb_coin_ids'] = [10];

/* The recurrence must exist BEFORE wp_schedule_event() looks it up. It used to
   be registered from boot(), which never runs during activation — so the call
   returned false and the site had no warming event at all, silently. */
$GLOBALS['thb_hooks']['cron_schedules'] = [];
ok(!isset(wp_get_schedules()[Scheduler::SCHEDULE]),
   'with no filter registered WordPress does not know the thb_tick recurrence');
ok(wp_schedule_event(Clock::$now + 30, Scheduler::SCHEDULE, Scheduler::HOOK_TICK) === false,
   'and wp_schedule_event() returns false — silently, which is why this went unnoticed');

Scheduler::registerSchedule();
ok(isset(wp_get_schedules()[Scheduler::SCHEDULE]), 'registerSchedule() teaches it the recurrence');
ok((int) wp_get_schedules()[Scheduler::SCHEDULE]['interval'] === 55,
   'at 55 seconds — under the crontab minute, so a tick is always already due');

Scheduler::registerSchedule();
Scheduler::registerSchedule();
ok(count($GLOBALS['thb_hooks']['cron_schedules']) === 1,
   'and calling it repeatedly leaves ONE filter, because callbacks are keyed by identity');

$GLOBALS['thb_cron'] = [];
ok(Scheduler::activate($config) === true, 'activation creates the event and says so');
$next = wp_next_scheduled(Scheduler::HOOK_TICK);
ok(is_int($next) && $next > Clock::$now, "wp_next_scheduled() returns a future timestamp ({$next})");
ok(wp_get_scheduled_event(Scheduler::HOOK_TICK)->schedule === Scheduler::SCHEDULE,
   'carrying the thb_tick recurrence, so it repeats rather than firing once');

Scheduler::activate($config);
Scheduler::activate($config);
ok(wp_next_scheduled(Scheduler::HOOK_TICK) === $next,
   'activating repeatedly cannot duplicate or move it');

Scheduler::deactivate($config);
ok(wp_next_scheduled(Scheduler::HOOK_TICK) === false, 'deactivation removes it');
ok(Scheduler::activate($config) === true, 'and re-activation puts it back');

/* A tick. */
reset_world($config);
$GLOBALS['thb_probe_background'] = true;
Probe::$transients = [];
$sched = new Scheduler($config, new CoinRepository(), pipeline($config), new History($config));

$progress = $sched->tick();
ok(($progress['status'] ?? '') === 'ran', 'a tick with a free lock runs');
ok(count((array) $progress['refreshed']) <= (int) $config['scheduler']['max_items'],
   'and never exceeds the item ceiling');
ok(($progress['lastDataset'] ?? '') !== '', 'recording the last dataset it completed');
ok(!(new Lock($config))->isHeld(), 'and releasing its lock when it finishes');

/* Overlap: a tick arriving while another holds the lock must do nothing. */
$foreign = (new Lock($config))->acquire();
Probe::reset(true);
$overlap = $sched->tick();
ok(($overlap['status'] ?? '') === 'locked', 'a tick arriving mid-run declines to start');
ok(Probe::count() === 0,
   'and makes ZERO provider calls — it cannot double-spend the minute budget');
(new Lock($config))->release((string) $foreign);

/* Shared datasets are served from the front of their own list, NOT cursored.
   Rotating a single /global entry through a long queue made a ten-minute TTL
   refresh hourly: six times an hour at two coins, zero at a hundred. */
reset_world($config);
Probe::$transients = [];
$queue = $sched->queue((new CoinRepository())->all());
$shared = array_values(array_filter(
    $queue,
    static fn(array $i): bool => (new Datasets($GLOBALS['thb_cfg']))->scope($i['dataset']) !== Datasets::SCOPE_COIN
));
ok($shared !== [], 'the queue contains shared (site/chain) entries');
ok($queue[0]['priority'] <= $queue[count($queue) - 1]['priority'],
   'and is ordered by priority, most critical first');

$seen = [];
foreach ($queue as $item) {
    $key = $item['dataset'] . '|' . $cache->keyFor($item['dataset'], $item['coin']);
    $seen[$key] = ($seen[$key] ?? 0) + 1;
}
ok(max($seen) === 1, 'no dataset is queued twice for the same cache key');

ok(!in_array('market', array_column($queue, 'dataset'), true),
   'and market is absent from the per-coin queue — the batch covers it');

/* =====================================================================
 * 9. Group B providers — keys, parsing, chain gating, and no leaks
 *
 * Etherscan, Blockchair, beaconcha.in and GitHub. Three things matter more
 * than any single figure: a key never reaches the screen or the repository; a
 * provider that cannot answer for a chain is never asked; and each parser
 * survives the specific shape that would fool it.
 * ================================================================== */
section('Group B — keys');

reset_world($config);

/* Keys are merged into the config from the WordPress option at boot. */
update_option(Settings::OPTION, [
    'etherscan_key'   => 'ETHERSCANKEY1234567890ABCDEF',
    'beaconchain_key' => 'BEACONKEY987654321',
    'github_token'    => 'github_pat_TOKEN1234567890',
]);
$keyed = Settings::apply($config);

ok(($keyed['providers']['etherscan']['query']['apikey'] ?? '') === 'ETHERSCANKEY1234567890ABCDEF',
   'the Etherscan key goes into the query string, where Etherscan expects it');
ok(($keyed['providers']['beaconchain']['headers']['apikey'] ?? '') === 'BEACONKEY987654321',
   'the beaconcha.in key goes into a header');
ok(($keyed['providers']['github']['headers']['Authorization'] ?? '') === 'Bearer github_pat_TOKEN1234567890',
   'and the GitHub token as a Bearer authorization header');
ok(!isset($keyed['providers']['blockchair']['query']['key']),
   'Blockchair, with no key stored, runs keyless rather than sending an empty key');

/* A REQUIRED key that is missing disables its provider rather than letting it
   fail on every request, spend budget and fill the log. */
update_option(Settings::OPTION, []);
$unkeyed = Settings::apply($config);
ok(empty($unkeyed['providers']['etherscan']['enabled']),
   'with no Etherscan key, Etherscan is disabled at boot');
ok(($unkeyed['providers']['etherscan']['disabled_reason'] ?? '') === 'no API key configured',
   'and says why, for the diagnostics screen');
ok(!empty($unkeyed['providers']['beaconchain']['enabled']),
   'while an OPTIONAL key\'s provider stays on, keyless');

/* The committed config must never hold a key — it ships in the zip. */
$configSource = (string) file_get_contents($base . 'config/providers.php');
ok(!preg_match('/[\'"](?:apikey|key|Authorization)[\'"]\s*=>\s*[\'"][A-Za-z0-9_\-]{12,}/', $configSource),
   'config/providers.php contains no key — only where a key goes, never what it is');

/* Masking: enough to recognise, never enough to use. */
ok(Settings::mask('ETHERSCANKEY1234567890ABCDEF') === 'ETHE' . str_repeat('•', 20) . 'CDEF',
   'a long key shows its first and last four characters only');
ok(Settings::mask('SHORTKEY') === '••••••••',
   'a short key is masked ENTIRELY — first-four/last-four would reveal all of it');

/* Scrub: secrets removed from anything bound for the screen or the log. */
$scrubbed = Settings::scrub(
    'https://api.etherscan.io/api?module=stats&apikey=ETHERSCANKEY1234567890ABCDEF',
    $keyed['providers']['etherscan']
);
ok(!str_contains($scrubbed, 'ETHERSCANKEY1234567890ABCDEF'), 'a query-string key is scrubbed from a URL');
ok(str_contains($scrubbed, 'module=stats'), 'while the rest of the URL survives, so it is still useful');
ok(!str_contains(
    Settings::scrub('401 for Bearer github_pat_TOKEN1234567890', $keyed['providers']['github']),
    'github_pat_TOKEN1234567890'
), 'and a header token is scrubbed from error text');

/* The live-check trace — rendered as a table on the diagnostics screen. */
$traced = [];
add_action('thb_coins_http', static function (array $call) use (&$traced): void { $traced[] = $call; });
$GLOBALS['thb_probe_background'] = true;
(new C\Etherscan($keyed))->fetch(ethereum(), 'gas');
remove_action('thb_coins_http', array_values($GLOBALS['thb_hooks']['thb_coins_http'] ?? [])[0] ?? '');
$GLOBALS['thb_hooks']['thb_coins_http'] = [];

ok(isset($traced[0]) && !str_contains($traced[0]['url'], 'ETHERSCANKEY1234567890ABCDEF'),
   'the live-check trace shows the URL WITHOUT the key');
ok(Probe::count('apikey=ETHERSCANKEY1234567890ABCDEF') === 1,
   'while the request itself DID carry it — scrubbing is display-only');

/* The provider probe: same rule. */
$GLOBALS['thb_cfg_probe'] = $keyed;
$probeResult = (new ProviderProbe($keyed))->run();
$leaked = 0;
foreach ($probeResult['results'] as $r) {
    if (str_contains((string) $r['url'], 'ETHERSCANKEY') || str_contains((string) $r['url'], 'BEACONKEY')
        || str_contains((string) $r['error'], 'ETHERSCANKEY')) {
        $leaked++;
    }
}
ok($leaked === 0, 'no probe result records a key (' . count($probeResult['results']) . ' endpoints probed)');

$etherscanRows = array_values(array_filter($probeResult['results'], static fn($r) => $r['provider'] === 'etherscan'));
ok($etherscanRows !== [] && $etherscanRows[0]['keyConfigured'] === true,
   'the probe reports the Etherscan key as configured');
$githubRows = array_values(array_filter($probeResult['results'], static fn($r) => $r['provider'] === 'github'));
ok($githubRows !== [] && $githubRows[0]['keyConfigured'] === true,
   'and the GitHub token — detected from the auth header, not from the always-present Accept header');

$unkeyedProbe = (new ProviderProbe($unkeyed))->run();
$ghUnkeyed = array_values(array_filter($unkeyedProbe['results'], static fn($r) => $r['provider'] === 'github'));
ok($ghUnkeyed !== [] && $ghUnkeyed[0]['keyConfigured'] === false,
   'and reports NO token when none is stored, despite GitHub\'s Accept header');

foreach (['gas oracle', 'ETH supply', 'latest epoch', 'staking APR', 'Ethereum network', 'Bitcoin network', 'repository', 'recent commits'] as $label) {
    $found = array_filter($probeResult['results'], static fn($r) => str_contains($r['label'], $label));
    ok($found !== [], "the probe covers: {$label}");
}

update_option(Settings::OPTION, []);

/* ------------------------------------------------------------------ */
section('Group B — parsing');

reset_world($config);
$GLOBALS['thb_probe_background'] = true;
$eth = ethereum();

$gas = (new C\Etherscan($config))->fetch($eth, 'gas');
ok($gas['safe'] === 12.0 && $gas['propose'] === 14.0 && $gas['fast'] === 16.0,
   'gas: the three tiers a wallet shows, passed through rather than averaged');
ok(abs((float) $gas['baseFee'] - 11.913) < 1e-9, 'and the base fee');

freeBudget($config);
$supply = (new C\Etherscan($config))->fetch($eth, 'supply');
ok(abs($supply['totalSupply'] - 120530000.0) < 1.0,
   'supply: wei strings beyond PHP_INT_MAX convert to ETH without overflow (' . round($supply['totalSupply']) . ')');
ok(abs($supply['stakedPct'] - 28.21) < 0.01,
   'the staked share is a ratio of two figures in the SAME response (' . round($supply['stakedPct'], 2) . '%)');
ok(!isset($supply['burnRate']) && !isset($supply['issuanceRate']),
   'and no RATE is claimed: burn and staking totals are cumulative since genesis');

/* Etherscan reports failure as HTTP 200 with status "0". */
freeBudget($config);
$GLOBALS['thb_probe_etherscan_error'] = 'Invalid API Key';
$bad = (new C\Etherscan($config))->fetch($eth, 'gas');
ok($bad === null, 'an "Invalid API Key" reply is null — not cached as a gas price');

freeBudget($config);
$GLOBALS['thb_probe_etherscan_error'] = 'Max calls per sec rate limit reached (5/sec)';
(new C\Etherscan($config))->fetch($eth, 'gas');
ok((new Budget($config))->cooldownRemaining('etherscan') > 0,
   'a rate-limit reply arriving as HTTP 200 still starts a cooldown — the budget could not see it otherwise');
unset($GLOBALS['thb_probe_etherscan_error']);

freeBudget($config);
$net = (new C\Blockchair($config))->fetch($eth, 'network');
ok($net['transactions24h'] === 1210000 && $net['mempool'] === 2843, 'network: daily transactions and the mempool');
ok(abs($net['blockTime'] - 12.03) < 0.01,
   'block time is DERIVED from the day\'s block count: 86400 / 7180 = ' . round($net['blockTime'], 2) . 's');
ok($net['hashrate'] === null,
   'Ethereum has NO hashrate since the Merge — a reported 0 becomes null, never "0 H/s"');

freeBudget($config);
$btcNet = (new C\Blockchair($config))->fetch(bitcoin(), 'network');
ok($btcNet['hashrate'] === 6.5e20, 'while Bitcoin, which is mined, reports its hashrate');
ok(abs($btcNet['blockTime'] - 600.0) < 0.1, 'and ten-minute blocks (' . round($btcNet['blockTime']) . 's)');

freeBudget($config);
$unmapped = new Coin(postId: 30, slug: 'solana', symbol: 'SOL', name: 'سولانا', nameEn: 'Solana',
    coingeckoId: 'solana', newsCategorySlug: 'solana-news', meta: ['defillamaChain' => 'Solana']);
$before = Probe::count();
ok((new C\Blockchair($config))->fetch($unmapped, 'network') === null, 'a chain Blockchair does not index returns null');
ok(Probe::count() === $before, 'without being asked — no request for an answer that cannot exist');

freeBudget($config);
$stake = (new C\BeaconChain($config))->fetch($eth, 'staking');
ok($stake['validators'] === 1062000, 'staking: active validators');
ok(abs($stake['stakedEth'] - 34200000.0) < 1.0, 'staked balance converted from gwei');
ok(abs($stake['participation'] - 99.5) < 1e-9,
   'participation arrives as a fraction (0.995) and renders as 99.5%, not 0.995%');
ok(abs($stake['apr'] - 3.12) < 1e-9, 'realised APR likewise: 0.0312 → 3.12%, not 0.03%');

freeBudget($config);
$GLOBALS['thb_probe_ethstore_down'] = true;
$partial = (new C\BeaconChain($config))->fetch($eth, 'staking');
unset($GLOBALS['thb_probe_ethstore_down']);
ok($partial['validators'] === 1062000 && $partial['apr'] === null,
   'if the APR endpoint fails, the validator figures survive and only the APR is unavailable');

freeBudget($config);
$dev = (new C\GitHub($config))->fetch(ethereum(['githubRepo' => 'ethereum/go-ethereum']), 'development');
ok($dev['repo'] === 'ethereum/go-ethereum' && $dev['stars'] === 48100, 'development: the repository');
ok($dev['commits4w'] === 5, 'commits in the last four weeks');
ok($dev['contributors4w'] === 4,
   'distinct contributors = 4: alice, bob, carol AND the unlinked author — not folded into one null bucket');

freeBudget($config);
$before = Probe::count();
ok((new C\GitHub($config))->fetch(new Coin(postId: 31, slug: 'x', symbol: 'X', name: 'x', nameEn: 'x',
    coingeckoId: 'x', newsCategorySlug: 'x'), 'development') === null,
   'with no repository configured, development is null');
ok(Probe::count() === $before, 'and GitHub is not asked to guess one from the slug');

/* The repository comes from CoinGecko's own metadata when no ACF value is set,
   read from cache so warming dev activity never costs a CoinGecko request. */
reset_world($config);
Probe::$transients = [];
$GLOBALS['thb_probe_background'] = true;
$p = pipeline($config);
$p->warm($eth, 'metadata');
$geckoBefore = Probe::count('api.coingecko.com');
freeBudget($config);
$p->warm($eth, 'development');
ok(Probe::count('api.coingecko.com') === $geckoBefore,
   'warming development reads the repository from CACHED metadata — zero CoinGecko requests');
ok(Probe::count('/repos/ethereum/go-ethereum') >= 1,
   'and asks GitHub about the repository CoinGecko named');

/* ------------------------------------------------------------------ */
section('Group B — scope and chain gating');

$sets = new Datasets($config);
$cache = new Cache($config);

foreach (['gas', 'supply', 'network', 'staking'] as $dataset) {
    ok($sets->scope($dataset) === Datasets::SCOPE_CHAIN, "{$dataset} is chain-scoped");
    ok($sets->rendersFromCacheOnly($dataset), "and cache-only on render");
}
ok($sets->scope('development') === Datasets::SCOPE_COIN, 'development is coin-scoped — each project has its own repo');

/* Two coins on one chain share one entry — the reason chain scope is cheap. */
$lido = new Coin(postId: 40, slug: 'lido-dao', symbol: 'LDO', name: 'لیدو', nameEn: 'Lido',
    coingeckoId: 'lido-dao', newsCategorySlug: 'lido-news', meta: ['defillamaChain' => 'Ethereum']);
ok($cache->keyFor('gas', $eth) === $cache->keyFor('gas', $lido),
   'ETH and an ERC-20 on the same chain share ONE gas entry');
ok($cache->keyFor('network', $eth) !== $cache->keyFor('network', bitcoin()),
   'while different chains do not');

$btcSets = $sets->forCoin(bitcoin());
foreach (['gas', 'supply', 'staking'] as $dataset) {
    ok(!in_array($dataset, $btcSets, true), "Bitcoin is never queued for {$dataset} — it has no such thing");
}
ok(in_array('network', $btcSets, true), 'but IS queued for network activity, which Blockchair serves');

reset_world($config);
Probe::$transients = [];
$btcModel = pipeline($config)->viewModel(bitcoin());
ok(($btcModel['staking']['state'] ?? '') === 'not_applicable',
   'a Bitcoin page reports staking as NOT APPLICABLE — not "pending", which would promise data that never comes');
ok(($btcModel['gas']['state'] ?? '') === 'not_applicable', 'and gas likewise');

$ethModel = pipeline($config)->viewModel(ethereum());
ok(($ethModel['staking']['state'] ?? '') === 'pending', 'while an unwarmed Ethereum page reports staking as PENDING');

/* ------------------------------------------------------------------ */
section('Group B — request cost');

reset_world($config);
Probe::$transients = [];
$GLOBALS['thb_probe_background'] = false;
$GLOBALS['thb_probe_dex_symbol'] = 'WETH';
pipeline($config)->viewModel(ethereum(['dexSymbols' => 'WETH']));
ok(Probe::count() === 7, 'a cold Ethereum render STILL makes exactly 7 requests (got ' . Probe::count() . ')');
foreach (['etherscan.io', 'blockchair.com', 'beaconcha.in', 'api.github.com'] as $host) {
    ok(Probe::count($host) === 0, "and none of them goes to {$host} — the scheduler owns Group B");
}

/* The queue: one entry per chain, not per coin. */
$GLOBALS['thb_acf'] = [];
$GLOBALS['thb_coin_fields'] = [];
$GLOBALS['thb_post_slugs'] = [];
$ids = [];
for ($i = 0; $i < 20; $i++) {
    $id = 600 + $i;
    $ids[] = $id;
    $GLOBALS['thb_post_slugs'][$id] = 'erc' . $i;
    $GLOBALS['thb_coin_fields'][$id] = [
        'thb_symbol' => 'E' . $i, 'thb_name_fa' => 'e' . $i, 'thb_coingecko_id' => 'erc' . $i,
        'thb_news_category_slug' => 'erc' . $i, 'thb_defillama_chain' => 'Ethereum',
    ];
}
$GLOBALS['thb_coin_ids'] = $ids;
$many = (new CoinRepository())->all();
$q = (new Scheduler($config, new CoinRepository(), pipeline($config), new History($config)))->queue($many);
$counts = array_count_values(array_column($q, 'dataset'));
foreach (['gas', 'supply', 'network', 'staking'] as $dataset) {
    ok(($counts[$dataset] ?? 0) === 1,
       "20 coins on Ethereum queue {$dataset} ONCE (got " . ($counts[$dataset] ?? 0) . ')');
}
$GLOBALS['thb_coin_ids'] = [10];
$GLOBALS['thb_coin_fields'] = [];


/* =====================================================================
 * 10. The queue sees every chain, and every coin gets a turn
 *
 * THE BUG: candidates were collected in one pass over the coin list, stopping
 * at max_candidates — about twenty coins. At a hundred, the rest were never
 * looked at, so a chain that only appeared among them never had its
 * chain-scoped data refreshed at all. Measured: Bitcoin's network stats and
 * eight chains' DeFi figures received zero requests an hour.
 * ================================================================== */
section('queue reach at scale');

reset_world($config);
Probe::$transients = [];
$GLOBALS['thb_acf'] = [];
$GLOBALS['thb_coin_fields'] = [];
$GLOBALS['thb_post_slugs'] = [];
$ids = [];
for ($i = 0; $i < 100; $i++) {
    $id = 2000 + $i;
    $ids[] = $id;
    // Ninety-nine coins on Ethereum, then ONE on Solana at the very end of the
    // list — exactly where the old single pass never reached.
    $chain = $i === 99 ? 'Solana' : 'Ethereum';
    $GLOBALS['thb_post_slugs'][$id] = 'tail' . $i;
    $GLOBALS['thb_coin_fields'][$id] = [
        'thb_symbol' => 'T' . $i, 'thb_name_fa' => 't' . $i, 'thb_coingecko_id' => 'tail' . $i,
        'thb_news_category_slug' => 't' . $i, 'thb_defillama_chain' => $chain,
        'thb_enable_defi' => 1, 'thb_enable_dex' => 1,
    ];
}
$GLOBALS['thb_coin_ids'] = $ids;
$hundred = (new CoinRepository())->all();
$sched = new Scheduler($config, new CoinRepository(), pipeline($config), new History($config));

$q = $sched->queue($hundred, 0);
$solanaDefi = array_filter($q, static fn($i) => $i['dataset'] === 'defi'
    && $i['coin']->meta('defillamaChain') === 'Solana');
ok(count($solanaDefi) === 1,
   'the LAST of 100 coins is the only one on Solana, and Solana\'s DeFi is still queued');

$chainsQueued = array_unique(array_map(
    static fn($i) => $i['coin']->meta('defillamaChain'),
    array_filter($q, static fn($i) => $i['dataset'] === 'defi')
));
sort($chainsQueued);
ok($chainsQueued === ['Ethereum', 'Solana'], 'every chain present is queued exactly once: ' . implode(', ', $chainsQueued));

/* Coin-scoped entries are still bounded, and the window rotates. */
$coinScoped = static fn(array $q): array => array_values(array_unique(array_map(
    static fn($i) => $i['coin']->slug,
    array_filter($q, static fn($i) => (new Datasets($GLOBALS['thb_cfg']))->scope($i['dataset']) === Datasets::SCOPE_COIN)
)));
$firstWindow = $coinScoped($sched->queue($hundred, 0));
ok(count($firstWindow) < 100, 'coin-scoped candidates are still capped (' . count($firstWindow) . ' coins per window)');
ok($firstWindow[0] === 'tail0', 'the first window starts at the head of the list');

$GLOBALS['thb_probe_background'] = true;
freeBudget($config);
$sched->tick();
$offset = (int) get_option(Scheduler::OPTION_COIN_OFFSET, 0);
ok($offset === count($firstWindow), "a tick moves the window on by the coins it scanned (offset {$offset})");

$secondWindow = $coinScoped($sched->queue($hundred));
ok(($secondWindow[0] ?? '') === 'tail' . $offset, 'and the next window starts where that one stopped: ' . ($secondWindow[0] ?? '—'));
ok(array_intersect($firstWindow, $secondWindow) === [], 'with no overlap between consecutive windows');

/* Over enough ticks, every coin is reached — the actual guarantee. */
$reached = [];
for ($t = 0; $t < 20; $t++) {
    foreach ($coinScoped($sched->queue($hundred)) as $slug) { $reached[$slug] = true; }
    freeBudget($config);
    $sched->tick();
    Clock::advance(55);
}
ok(count($reached) === 100, 'within twenty ticks every one of the 100 coins has been in a window (' . count($reached) . ')');

/* Reading the queue must not move it — the diagnostics screen calls queue(). */
$before = get_option(Scheduler::OPTION_COIN_OFFSET, 0);
$sched->queue($hundred);
$sched->queue($hundred);
ok(get_option(Scheduler::OPTION_COIN_OFFSET, 0) === $before, 'reading the queue does not advance the window; only a tick does');

$GLOBALS['thb_probe_background'] = false;
$GLOBALS['thb_coin_ids'] = [10];
$GLOBALS['thb_coin_fields'] = [];


/* =====================================================================
 * 11. The settings screen never echoes a key back
 * ================================================================== */
section('settings screen');

update_option(Settings::OPTION, [
    'etherscan_key' => 'ETHERSCANKEY1234567890ABCDEF',
    'github_token'  => 'github_pat_TOKEN1234567890',
]);
$_GET = [];
ob_start();
(new Settings())->render();
$screen = (string) ob_get_clean();

ok(!str_contains($screen, 'ETHERSCANKEY1234567890ABCDEF'), 'the stored Etherscan key does not appear in the page');
ok(!str_contains($screen, 'github_pat_TOKEN1234567890'), 'nor the GitHub token');
ok(str_contains($screen, 'ETHE') && str_contains($screen, 'CDEF'), 'a masked hint does, so you can tell which key is stored');
ok(!preg_match('/name="etherscan_key" value="[^"]+"/', $screen), 'and the input is EMPTY — the key is not round-tripped through the form');
foreach (Settings::keys() as $setting => $spec) {
    ok(str_contains($screen, $spec['url']), "the screen links to where the {$spec['label']} key is created");
}

/* Saving an empty field leaves the stored key alone; clearing is explicit. */
$_POST = ['etherscan_key' => '', 'github_token' => '', 'beaconchain_key' => 'NEWBEACON_123456', 'blockchair_key' => ''];
try { (new Settings())->save(); } catch (\Throwable $e) {}
$after = Settings::all();
ok(($after['etherscan_key'] ?? '') === 'ETHERSCANKEY1234567890ABCDEF', 'saving with an empty field does NOT delete the stored key');
ok(($after['beaconchain_key'] ?? '') === 'NEWBEACON_123456', 'while a newly typed key is stored');

$_POST = ['clear_github_token' => '1'];
try { (new Settings())->save(); } catch (\Throwable $e) {}
ok(!isset(Settings::all()['github_token']), 'and a key is removed only when "clear" is ticked');

$_POST = ['etherscan_key' => "  KEY'WITH\"<script>junk  "];
try { (new Settings())->save(); } catch (\Throwable $e) {}
ok(Settings::all()['etherscan_key'] === 'KEYWITHscriptjunk', 'pasted junk is reduced to key characters before it can reach a request');

$_POST = [];
update_option(Settings::OPTION, []);


/* =====================================================================
 * 12. The diagnostics screen renders, and leaks nothing
 *
 * Never rendered by any test before this; it is also the page most likely to
 * be screenshotted and posted somewhere when asking for help.
 * ================================================================== */
section('diagnostics screen');

reset_world($config);
Probe::$transients = [];
$GLOBALS['thb_acf'] = [
    'thb_symbol' => 'ETH', 'thb_name_fa' => 'اتریوم', 'thb_name_en' => 'Ethereum',
    'thb_coingecko_id' => 'ethereum', 'thb_news_category_slug' => 'ethereum-news',
    'thb_defillama_chain' => 'Ethereum', 'thb_enable_defi' => 1, 'thb_enable_dex' => 1,
];
$GLOBALS['thb_coin_ids'] = [10];
$GLOBALS['thb_post_slugs'] = [10 => 'ethereum'];
update_option(Settings::OPTION, ['etherscan_key' => 'ETHERSCANKEY1234567890ABCDEF']);
$diagConfig = Settings::apply($config);

$_GET = ['coin' => 'ethereum'];
ob_start();
$threw = null;
try {
    (new \TheHybit\Coins\Diagnostics(new CoinRepository(), pipeline($diagConfig), $diagConfig))->render();
} catch (\Throwable $e) {
    $threw = $e;
}
$diag = (string) ob_get_clean();
$_GET = [];

ok($threw === null, 'the diagnostics screen renders without an error' . ($threw ? ': ' . $threw->getMessage() : ''));
ok(str_contains($diag, 'کلیدهای API'), 'it shows the API key status table');
ok(!str_contains($diag, 'ETHERSCANKEY1234567890ABCDEF'), 'without ever printing the stored key');
ok(str_contains($diag, 'ETHE') , 'only its masked hint');
foreach (['gas', 'supply', 'network', 'staking', 'development'] as $dataset) {
    ok(str_contains($diag, '<code>' . $dataset . '</code>'), "the dataset table lists {$dataset}");
}

update_option(Settings::OPTION, []);


echo $failed ? "\n{$failed} FAILED\n" : "\nAll runtime tests passed.\n";
exit($failed ? 1 : 0);
