<?php
/**
 * Network & Ecosystem — the chain around the coin.
 *
 * DELIBERATELY NOT THE DeFi CARD. That card answers "how much value is locked
 * in this chain's DeFi, and how is it growing" — DeFi economics. This one
 * answers a wider question: where does the chain sit among all chains, how much
 * settled money (stablecoins) lives on it, and what moves across its bridges.
 * The two share a provider and nothing else, and no figure appears in both.
 *
 * The chain's RANK is real here in a way category rank is not: /v2/chains
 * publishes TVL for every chain, so ordering them produces a genuine position.
 * CoinGecko publishes no category ranking, so none is shown anywhere on this
 * page — see Context::categories().
 *
 * Bridges are disabled in configuration pending a production check (the probe
 * got HTTP 407), so that block simply does not render rather than showing an
 * apologetic empty card on every page view.
 *
 * @package TheHybit\Coins
 */

use TheHybit\Coins\Format;
use TheHybit\Coins\Context;

defined('ABSPATH') || exit;

$chains  = $model['chains'] ?? ['available' => false, 'state' => 'pending'];
$stables = $model['stablecoins'] ?? ['available' => false, 'state' => 'pending'];
$bridges = $model['bridges'] ?? ['available' => false, 'state' => 'disabled'];
$ctx     = $model['context'] ?? [];
$defi    = $model['defi'] ?? null;

$chainName = (string) ($model['coin']['chain'] ?? '');
$standing  = Context::chainStanding($model, $chainName);

$items = [];

/* ---- the chain's standing among all chains ---- */

if ($standing !== null) {
    $items[] = [
        'label' => 'رتبه زنجیره بر پایه TVL',
        'value' => '#' . (int) $standing['rank'],
        'plain' => true,
        'meta'  => 'از میان ' . Format::num((float) ($chains['data']['chainCount'] ?? 0), 0) . ' زنجیره',
    ];
}

if (!empty($ctx['tvlShareOfDefi']['available'])) {
    $items[] = [
        'label' => 'سهم از کل TVL دیفای',
        'value' => Format::pct((float) $ctx['tvlShareOfDefi']['value'], false),
    ];
}

/* TVL itself and its growth stay the DeFi card's business; what is added here
   is the chain's weight RELATIVE to every other chain, which the per-chain
   endpoint cannot express. */
if (is_array($defi) && isset($defi['tvl'])) {
    $items[] = [
        'label' => 'TVL زنجیره',
        'value' => Format::usdCompact((float) $defi['tvl']),
        'meta'  => 'مجموع کل زنجیره‌ها: ' . Format::usdCompact((float) ($chains['data']['totalTvl'] ?? 0)),
    ];
}

/* ---- stablecoins on this chain ---- */

if (!empty($ctx['stablecoinSupply']['available'])) {
    $items[] = [
        'label' => 'عرضه استیبل‌کوین روی زنجیره',
        'value' => Format::usdCompact((float) $ctx['stablecoinSupply']['value']),
    ];
}

if (!empty($ctx['stablecoinDominance']['available'])) {
    $items[] = [
        'label' => 'سهم از کل استیبل‌کوین‌ها',
        'value' => Format::pct((float) $ctx['stablecoinDominance']['value'], false),
    ];
}

/* Stablecoins measured against the chain's own DeFi rather than against other
   chains — a different question, and the one that says whether the locked value
   is speculative or settled. */
if (!empty($ctx['stablecoinToTvl']['available'])) {
    $items[] = [
        'label' => 'نسبت استیبل‌کوین به TVL',
        'value' => Format::pct((float) $ctx['stablecoinToTvl']['value'], false),
        'meta'  => 'نسبت نقدینگی پایدار به کل ارزش قفل‌شده',
    ];
}

/* ---- bridges, only when the dataset is live ---- */

$b = (array) ($bridges['data'] ?? []);
if (($b['volume24h'] ?? null) !== null) {
    $items[] = [
        'label' => 'حجم پل‌های زنجیره (۲۴ ساعت)',
        'value' => Format::usdCompact((float) $b['volume24h']),
    ];
}
if (($b['volume7d'] ?? null) !== null) {
    $items[] = [
        'label' => 'حجم پل‌ها (۷ روز)',
        'value' => Format::usdCompact((float) $b['volume7d']),
    ];
}

/* Present when either provider answered; absent only when neither has. */
$statState = ($chains['available'] || $stables['available'] || $bridges['available'])
    ? 'ok'
    : (($chains['state'] ?? 'pending') === 'pending' ? 'pending' : 'unavailable');

$statTitle  = 'شبکه و اکوسیستم';
$statSource = 'DefiLlama';
$statItems  = $items;
$statId     = 'thb-ecosystem-title';
$statSpan   = 12;

require __DIR__ . '/stat-grid.php';
