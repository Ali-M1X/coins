<?php
/**
 * Pure-layer tests: Derive, Scoring, Format, Context and config integrity.
 *
 *   php tools/php-test.php
 *
 * Covers the parts that need no WordPress at all — the maths, the thresholds
 * and the configuration that governs everything above them. wp-runtime.test.php
 * covers the layer that does need WordPress.
 *
 * ON THE SCORE
 *
 * The original suite asserted "Ethereum is 71/100" against a fixture that was
 * lost with the rest of tools/. That fixture is not reconstructed here by
 * guesswork, because a number reverse-engineered to hit 71 would assert nothing
 * except that I had chosen it. Instead this file does the two things that are
 * actually worth protecting:
 *
 *   1. The scoring MATHS is pinned at known points — interpolation inside each
 *      scale segment, the band cutoffs, weight renormalisation when a provider
 *      is missing, and the coverage floor below which no score is shown.
 *   2. A deterministic derived-metric set is scored and its value pinned, so any
 *      change to weights, scales or component list fails here immediately.
 *
 * Together those catch exactly what the brief cares about: the score must not
 * move because of the v2 work.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
define('MONTH_IN_SECONDS', 2592000);
define('YEAR_IN_SECONDS', 31536000);

$base = __DIR__ . '/../thehybit-coins/';
foreach (['Format', 'Derive', 'Scoring', 'Context', 'Coin', 'Overrides', 'Datasets'] as $class) {
    require_once $base . 'includes/' . $class . '.php';
}

use TheHybit\Coins\{Format, Derive, Scoring, Context, Datasets};

$failed = 0;
function ok(bool $cond, string $msg): void
{
    global $failed;
    if (!$cond) { $failed++; }
    echo ($cond ? 'PASS' : 'FAIL') . "  {$msg}\n";
}
function section(string $s): void { echo "\n--- {$s} " . str_repeat('-', max(0, 58 - strlen($s))) . "\n"; }

$near = static fn(?float $a, float $b, float $tol = 0.01): bool =>
    $a !== null && abs($a - $b) <= $tol;

$config = require $base . 'config/providers.php';

/* =====================================================================
 * 1. Configuration integrity
 *
 * Everything downstream reads this file, so a typo here is a silent outage
 * rather than an error. These assertions are the contract.
 * ================================================================== */
section('configuration');

ok(isset($config['datasets'], $config['ttl'], $config['providers'], $config['scheduler']),
   'the four top-level config blocks are present');

/* Every dataset must name a provider that exists, or the scheduler queues work
   nothing can perform. */
foreach ($config['datasets'] as $name => $spec) {
    ok(isset($spec['provider'], $spec['scope'], $spec['priority']),
       "dataset {$name} declares provider, scope and priority");
    ok(isset($config['providers'][$spec['provider']]),
       "dataset {$name} points at a configured provider ({$spec['provider']})");
    ok(in_array($spec['scope'], ['site', 'chain', 'coin'], true),
       "dataset {$name} has a known scope ({$spec['scope']})");
}

/* Scope is what decides whether a dataset costs one request or a hundred. */
$scopes = array_count_values(array_column($config['datasets'], 'scope'));
ok(($scopes['site'] ?? 0) >= 5, 'at least five datasets are site-scoped — one request each, ever');
ok(($scopes['chain'] ?? 0) >= 1, 'at least one is chain-scoped');
ok(($scopes['coin'] ?? 0) >= 1, 'and the rest are per coin');

/* NAMED, not merely counted. The assertions above check that each declared
   scope is well-formed, which a dataset silently demoted from site to coin
   still satisfies — and that demotion is the single most expensive mistake
   available here: at a hundred coins it turns one request into a hundred. So
   the datasets whose scope the economics depend on are pinned by name. */
foreach ([
    'global'      => 'the whole market, identical for every coin',
    'categories'  => 'the complete category list, sliced locally',
    'sentiment'   => 'market-wide Fear & Greed, not a per-coin figure',
    'chains'      => 'every chain in one response',
    'stablecoins' => 'every stablecoin on every chain in one response',
    'fx'          => 'one USD rate for the whole site',
] as $dataset => $why) {
    ok(($config['datasets'][$dataset]['scope'] ?? null) === 'site',
       "{$dataset} MUST be site-scoped — {$why}");
}

/* And the ones that genuinely vary per coin must not be promoted either, which
   would make every coin read another coin's numbers. */
foreach (['market', 'metadata', 'historical', 'structure', 'dex'] as $dataset) {
    ok(($config['datasets'][$dataset]['scope'] ?? null) === 'coin',
       "{$dataset} MUST be coin-scoped — it genuinely differs per coin");
}
ok(!empty($config['datasets']['market']['batch']),
   'market is batchable — one request serves every coin');

/* Every dataset needs a TTL it can actually look up. Chart windows carry their
   own, so they are exempt from the flat ttl map. */
foreach (array_keys($config['datasets']) as $name) {
    if (str_starts_with($name, 'chart.')) {
        $period = substr($name, 6);
        ok(isset($config['chart']['windows'][$period]['ttl']),
           "chart window {$period} carries its own TTL");
        continue;
    }
    ok(isset($config['ttl'][$name]), "dataset {$name} has a TTL");
}

/* The measured CoinGecko ceiling. Five requests succeeded on the production
   server and the sixth returned 429, so the budget sits below five. */
$cg = $config['providers']['coingecko'];
ok($cg['budget']['per_minute'] < 5,
   "the CoinGecko per-minute budget ({$cg['budget']['per_minute']}) stays under the measured ceiling of 5");
ok($cg['budget']['per_hour'] > $cg['budget']['per_minute'],
   'with a larger hourly allowance');
ok(($cg['cooldown'] ?? 0) > 0, 'and a cooldown for when a 429 arrives anyway');

$sch = $config['scheduler'];
ok($sch['max_items'] > 0 && $sch['max_seconds'] > 0,
   "a cron tick is bounded: {$sch['max_items']} items, {$sch['max_seconds']}s");
ok($sch['max_seconds'] < 30, 'inside even the default PHP max_execution_time of 30s');
ok($sch['batch_size'] <= 250, "the batch size is within CoinGecko's per-page cap");
ok($sch['interval'] < 60,
   "the tick interval ({$sch['interval']}s) is under a minute, so a minute crontab never finds it not-yet-due");
ok($sch['lock_ttl'] >= 3 * $sch['max_seconds'],
   "the warm lock outlives a full-length tick ({$sch['lock_ttl']}s vs {$sch['max_seconds']}s)");

/* Sub-hosts: DefiLlama is several APIs on several hostnames under one budget. */
ok(isset($config['providers']['defillama']['bases']['stablecoins']),
   'DefiLlama declares the stablecoin sub-host — it is not on api.llama.fi');

/* =====================================================================
 * 2. Format — the display layer, and the RTL details that matter
 * ================================================================== */
section('Format');

ok(Format::usd(3245.67) === '$3,245.67', 'USD with thousands separators: ' . Format::usd(3245.67));
ok(Format::usdCompact(390226904100.0) === '$390.23B', 'compact USD: ' . Format::usdCompact(390226904100.0));
ok(Format::usdCompact(2.41e12) === '$2.41T', 'trillions: ' . Format::usdCompact(2.41e12));
ok(Format::pct(2.45) === '+2.45%', 'a signed percentage carries its sign: ' . Format::pct(2.45));
ok(Format::pct(2.45, false) === '2.45%', 'and drops it when asked');
/* A real MINUS SIGN (U+2212), not an ASCII hyphen. It is the correct glyph in
   Persian typography and it does not get mistaken for a bidi control or a
   hyphenation point, so it is pinned deliberately rather than normalised away. */
ok(Format::pct(-3.2) === "\u{2212}3.20%",
   'negatives use a typographic minus, not a hyphen: ' . Format::pct(-3.2));
ok(Format::direction(2.45) === 'up' && Format::direction(-1.0) === 'down',
   'direction classifies a change for the UI');

/* A missing value must never render as zero — the distinction the whole data
   layer is built to preserve has to survive the final formatting step. */
ok(Format::usd(null) !== '$0.00', 'a null price does not format as $0.00: ' . Format::usd(null));
ok(Format::pct(null) !== '+0.00%', 'a null change does not format as +0.00%: ' . Format::pct(null));
ok(Format::compact(null) !== '0', 'and a null figure does not become 0: ' . Format::compact(null));

/* =====================================================================
 * 3. Derive — the metric definitions and their missing-input handling
 * ================================================================== */
section('Derive');

/* A deterministic model, chosen so each metric's arithmetic is checkable by
   hand rather than by running the code being tested. */
$model = [
    'market' => [
        'marketCap' => 400_000_000_000.0,
        'volume24h' => 20_000_000_000.0,
        'volume30dAvg' => 16_000_000_000.0,
        'fdv' => 500_000_000_000.0,
        'circulatingSupply' => 120_000_000.0,
        'totalSupply' => 150_000_000.0,
        'ath' => ['changePct' => -33.65],
    ],
    'defi' => [
        'tvl' => 64_000_000_000.0,
        'tvl30dAgo' => 50_000_000_000.0,
        'fees24h' => 12_000_000.0,
        'revenue24h' => 4_000_000.0,
    ],
    'dex'  => ['volume24h' => 2_000_000_000.0],
    'performance' => ['30d' => 12.0],
    'benchmark' => ['performance' => ['30d' => 7.0]],
];

$d = Derive::metrics($model);

ok($near($d['tvlToMarketCap']['value'], 0.16, 0.0001),
   'TVL/MCap = 64B / 400B = 0.16 (got ' . round((float) $d['tvlToMarketCap']['value'], 4) . ')');
ok($near($d['volumeToMarketCap']['value'], 0.05, 0.0001),
   'Volume/MCap = 20B / 400B = 0.05');
ok($near($d['dexShareOfVolume']['value'], 10.0, 0.01),
   'DEX share = 2B / 20B = 10% (a percentage, not a ratio)');
ok($near($d['tvlGrowth30d']['value'], 28.0, 0.01),
   'TVL growth = (64-50)/50 = +28%');
ok($near($d['marketCapToFdv']['value'], 0.8, 0.0001),
   'MCap/FDV = 400B / 500B = 0.8');
ok($near($d['circulatingPct']['value'], 80.0, 0.01),
   'circulating = 120M / 150M = 80%');
ok($near($d['relativeToBenchmark30d']['value'], 5.0, 0.01),
   'vs benchmark = 12 − 7 = +5 percentage points');

/* The property that matters more than any single formula: a metric whose
   inputs are absent must report unavailable, not zero. A zero is a claim. */
$sparse = Derive::metrics(['market' => ['marketCap' => 400_000_000_000.0]]);
ok($sparse['tvlToMarketCap']['available'] === false,
   'a metric with a missing input is unavailable');
ok($sparse['tvlToMarketCap']['value'] === null,
   'and its value is null rather than 0');
ok(in_array('defi.tvl', $sparse['tvlToMarketCap']['missing'], true),
   'and it names the input it lacked, so the UI can explain itself');

/* Division guards. A zero denominator is what an unwarmed dataset looks like,
   and INF rendered as a percentage is how a page starts lying. */
$zero = Derive::metrics(['market' => ['marketCap' => 0.0, 'volume24h' => 10.0], 'defi' => ['tvl' => 5.0]]);
ok(($zero['tvlToMarketCap']['value'] ?? null) === null,
   'a zero denominator yields null, never INF');

/* =====================================================================
 * 4. Scoring — the maths, pinned
 * ================================================================== */
section('Scoring');

$sm = Scoring::SCORING_MODEL;

ok(array_keys($sm['components']) === ['tvlToMarketCap', 'volumeToMarketCap', 'tvlGrowth30d',
                                      'activityGrowth30d', 'dexShareOfVolume'],
   'the five approved components, in order');
ok(array_sum(array_column($sm['components'], 'weight')) === 100, 'their weights total 100');
ok($sm['provisional'] === true, 'the model still declares itself provisional');
ok($sm['version'] === 'provisional-0', 'at version provisional-0');

/* Scale interpolation at exact knots and between them. These are the numbers
   that produced the live score, so they are pinned directly. */
$scored = Scoring::score($d);
$byKey = [];
foreach ($scored['components'] as $c) { $byKey[$c['key']] = $c; }

ok($near($byKey['tvlToMarketCap']['normalized'], 75.0, 0.01),
   'TVL/MCap of exactly 0.16 sits on a scale knot and normalises to 75');
ok($near($byKey['volumeToMarketCap']['normalized'], 71.666, 0.01),
   'Volume/MCap of 0.05 interpolates inside [0.03→55, 0.06→80] to 71.67 (got '
   . round((float) $byKey['volumeToMarketCap']['normalized'], 2) . ')');
ok($near($byKey['dexShareOfVolume']['normalized'], 59.1667, 0.01),
   'DEX share of 10% interpolates inside [8→55, 20→80] to 59.17 (got '
   . round((float) $byKey['dexShareOfVolume']['normalized'], 2) . ')');
ok($near($byKey['tvlGrowth30d']['normalized'], 85.3333, 0.01),
   'TVL growth of +28% interpolates inside [20→80, 50→100] to 85.33');

/* activityGrowth30d needs on-chain data, and that provider ships disabled — so
   the component drops out and the remaining weights renormalise. That is the
   behaviour being asserted, not an accident of the fixture. */
ok($byKey['activityGrowth30d']['available'] === false,
   'activityGrowth30d is unavailable without an on-chain provider');
ok($near($scored['coverage'], 0.8, 0.001),
   'so coverage is 80 of 100 weight (got ' . round($scored['coverage'] * 100) . '%)');
ok($scored['available'] === true, 'which is above the 50% floor, so a score is shown');

/* THE PIN. Hand-computed from the four available normalizations:
     (25×75 + 20×71.6667 + 20×85.3333 + 15×59.1667) / 80
   = (1875 + 1433.33 + 1706.67 + 887.50) / 80
   = 5902.50 / 80 = 73.78 → 74
   Any change to a weight, a scale knot or the component list moves this. */
ok($scored['value'] === 74,
   'the weighted score for this fixture is pinned at 74 (got ' . var_export($scored['value'], true) . ')');

/* Below the coverage floor, no score at all — a number computed from one
   component would look as authoritative as one computed from five. */
$thin = Scoring::score(['tvlToMarketCap' => $d['tvlToMarketCap']]);
ok($thin['available'] === false && $thin['value'] === null,
   'one component out of five is below minCoverage, so the score is withheld');
ok($thin['coverage'] < $sm['minCoverage'], 'and coverage says why');

/* Bands. */
ok(Scoring::bandFor(85.0)['label'] === 'عالی', 'band at 85 is عالی');
ok(Scoring::bandFor(70.0)['label'] === 'خوب', 'band at 70 is خوب');
ok(Scoring::bandFor(50.0)['label'] === 'متوسط', 'band at 50 is متوسط');
ok(Scoring::bandFor(20.0)['label'] === 'ضعیف', 'band at 20 is ضعیف');
ok(Scoring::bandFor(80.0)['label'] === 'عالی', 'a boundary value takes the higher band');

/* =====================================================================
 * 5. Context — Group A relationships stay OUT of scoring
 * ================================================================== */
section('Context');

/* array_replace, NOT `+`. The union operator keeps the LEFT operand's keys on
   collision, so `$model + ['market' => …]` silently discards the override and
   the comparison metric quietly reports unavailable. */
$ctxModel = array_replace($model, [
    'globalMarket' => ['data' => ['totalMarketCap' => 2.41e12, 'totalVolume24h' => 9.8e10,
                                  'marketCapChange24h' => 1.42]],
    'market' => $model['market'] + ['change24h' => 2.45],
    'coin' => ['chain' => 'Ethereum', 'name' => 'اتریوم', 'categories' => ['Layer 1 (L1)']],
    'chains' => ['data' => ['totalTvl' => 7.74e10, 'chains' => [
        ['name' => 'Ethereum', 'tvl' => 6.1e10, 'rank' => 1, 'share' => 78.8],
    ]]],
    'stablecoins' => ['data' => ['byChain' => ['Ethereum' => 8.1e10], 'totalUsd' => 1.474e11]],
    'categories' => ['data' => ['categories' => [
        ['id' => 'layer-1', 'name' => 'Layer 1 (L1)', 'marketCap' => 1.6e12, 'change24h' => 1.1],
    ]]],
]);

$ctx = Context::build($ctxModel);

ok($near($ctx['marketCapShare']['value'], 16.598, 0.01),
   'market-cap share = 400B / 2.41T = 16.60%');
ok($near($ctx['vsMarket24h']['value'], 1.03, 0.001),
   'performance vs the market = 2.45 − 1.42 = +1.03 percentage POINTS');
ok($ctx['vsMarket24h']['unit'] === 'pp', 'and is labelled as points, not percent');

foreach ($ctx as $key => $metric) {
    ok(isset($metric['formula'], $metric['sources'], $metric['available']),
       "{$key} documents its formula and sources");
}

/* The guarantee the brief asks for: no context metric can reach a weight. */
$scoredWithContext = Scoring::score(Derive::metrics($ctxModel));
$strippedModel = $ctxModel;
unset($strippedModel['globalMarket'], $strippedModel['chains'],
      $strippedModel['stablecoins'], $strippedModel['categories']);
ok(Scoring::score(Derive::metrics($strippedModel)) == $scoredWithContext,
   'removing every Group A input leaves the score identical — Scoring reads only `derived`');

$overlap = array_intersect(array_keys($ctx), array_keys(Derive::metrics($ctxModel)));
ok($overlap === [], 'and no context key collides with a derived key');

/* Chain standing is a REAL rank: /v2/chains publishes TVL for every chain, so
   ordering them produces a genuine position. Categories publish no ranking, so
   none is produced there. */
$standing = Context::chainStanding($ctxModel, 'Ethereum');
ok(is_array($standing) && $standing['rank'] === 1, 'the chain standing carries a real rank');

$cats = Context::categories($ctxModel);
ok(count($cats) === 1, 'the coin matches one of the published categories');
ok(!isset($cats[0]['rank']), 'and no category carries a rank — the endpoint publishes none');

/* =====================================================================
 * 6. Datasets — scope keys
 * ================================================================== */
section('Datasets');

$sets = new Datasets($config);
$eth = new \TheHybit\Coins\Coin(
    postId: 10, slug: 'ethereum', symbol: 'ETH', name: 'اتریوم', nameEn: 'Ethereum',
    coingeckoId: 'ethereum', newsCategorySlug: 'ethereum-news',
    meta: ['defillamaChain' => 'Ethereum']
);
$btc = new \TheHybit\Coins\Coin(
    postId: 11, slug: 'bitcoin', symbol: 'BTC', name: 'بیت‌کوین', nameEn: 'Bitcoin',
    coingeckoId: 'bitcoin', newsCategorySlug: 'bitcoin-news',
    meta: ['defillamaChain' => 'Bitcoin']
);

foreach (array_keys($config['datasets']) as $dataset) {
    $key = $sets->scopeKey($dataset, $eth);
    if ($sets->scope($dataset) === Datasets::SCOPE_SITE) {
        ok($key === $sets->scopeKey($dataset, $btc),
           "{$dataset} is site-scoped: both coins share one key ({$key})");
        ok(!str_contains($key, 'ethereum') && !str_contains($key, 'bitcoin'),
           "and the key names no coin at all");
    } elseif ($sets->scope($dataset) === Datasets::SCOPE_CHAIN) {
        ok($key !== $sets->scopeKey($dataset, $btc),
           "{$dataset} is chain-scoped: different chains, different keys");
        ok(str_contains($key, 'Ethereum'), "keyed by chain name ({$key})");
    } else {
        ok(str_contains($key, 'ethereum'), "{$dataset} is coin-scoped ({$key})");
    }
}

echo $failed ? "\n{$failed} FAILED\n" : "\nAll pure tests passed.\n";
exit($failed ? 1 : 0);
