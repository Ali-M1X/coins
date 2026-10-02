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
                    Datasets, Budget, Lock, Scheduler, Context, Derive, Scoring};
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
              C\L2Beat::class, C\OnChain::class, C\Fx::class, C\Alternative::class] as $cls) {
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

echo $failed ? "\n{$failed} FAILED\n" : "\nAll runtime tests passed.\n";
exit($failed ? 1 : 0);
