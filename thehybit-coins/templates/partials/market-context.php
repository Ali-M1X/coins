<?php
/**
 * Market Context — the whole crypto market, not this coin.
 *
 * THE QUESTION THIS SECTION ANSWERS
 *
 * "Is Ethereum up because Ethereum is strong, or because everything is up?"
 * Every figure above it on the page is about the coin in isolation, which
 * cannot distinguish a coin outperforming a flat market from one merely riding
 * a rising one. The comparison row is therefore the point of the section, not a
 * garnish: the coin's 24-hour change minus the market's, in percentage points.
 *
 * Everything here is SITE-WIDE. One /global response and one Fear & Greed
 * reading serve every coin page on the installation, so this section costs the
 * same whether the site has two coins or two hundred.
 *
 * Fear & Greed is labelled as market sentiment explicitly. It says nothing
 * about this coin, and presenting it inside a coin page without that label
 * would invite exactly the wrong reading.
 *
 * @package TheHybit\Coins
 */

use TheHybit\Coins\Format;

defined('ABSPATH') || exit;

$global = $model['globalMarket'] ?? ['available' => false, 'state' => 'pending', 'data' => null];
$fng    = $model['sentiment'] ?? ['available' => false, 'state' => 'pending', 'data' => null];
$ctx    = $model['context'] ?? [];

$g = (array) ($global['data'] ?? []);
$f = (array) ($fng['data'] ?? []);

/* The section is present when EITHER provider has answered. They are separate
   providers with separate budgets, so one being unavailable is not a reason to
   hide what the other returned. */
$statState = $global['available'] || $fng['available']
    ? (($global['state'] ?? '') === 'stale' ? 'stale' : 'ok')
    : (($global['state'] ?? 'pending') === 'pending' ? 'pending' : 'unavailable');

$items = [];

if (($g['totalMarketCap'] ?? null) !== null) {
    $items[] = [
        'label'  => 'ارزش کل بازار رمزارز',
        'value'  => Format::usdCompact((float) $g['totalMarketCap']),
        'change' => isset($g['marketCapChange24h']) ? (float) $g['marketCapChange24h'] : null,
    ];
}

if (($g['totalVolume24h'] ?? null) !== null) {
    $items[] = [
        'label' => 'حجم کل ۲۴ ساعته',
        'value' => Format::usdCompact((float) $g['totalVolume24h']),
    ];
}

if (($g['btcDominance'] ?? null) !== null) {
    $items[] = [
        'label' => 'سلطه بیت‌کوین',
        'value' => Format::pct((float) $g['btcDominance'], false),
    ];
}

if (($g['ethDominance'] ?? null) !== null) {
    $items[] = [
        'label' => 'سلطه اتریوم',
        'value' => Format::pct((float) $g['ethDominance'], false),
    ];
}

/* This coin's own weight in the market, computed only when both inputs are
   real numbers — see Context::divide(). A missing /global would otherwise
   render an infinite share. */
if (!empty($ctx['marketCapShare']['available'])) {
    $items[] = [
        'label' => 'سهم ' . $model['coin']['name'] . ' از کل بازار',
        'value' => Format::pct((float) $ctx['marketCapShare']['value'], false),
    ];
}

if (!empty($ctx['volumeShare']['available'])) {
    $items[] = [
        'label' => 'سهم از حجم کل بازار',
        'value' => Format::pct((float) $ctx['volumeShare']['value'], false),
    ];
}

/* The comparison the section exists for. Percentage POINTS, not percent — a
   coin up 2% while the market is up 1.4% outperformed by 0.6 points. */
if (!empty($ctx['vsMarket24h']['available'])) {
    $delta = (float) $ctx['vsMarket24h']['value'];
    $items[] = [
        'label'  => 'عملکرد نسبت به کل بازار',
        'value'  => Format::pct($delta) . ' واحد',
        'plain'  => true,
        'meta'   => $delta >= 0 ? 'بهتر از میانگین بازار' : 'ضعیف‌تر از میانگین بازار',
    ];
}

if (($f['value'] ?? null) !== null) {
    $items[] = [
        'label' => 'شاخص ترس و طمع بازار',
        'value' => (string) $f['value'] . ' / 100',
        'meta'  => (string) ($f['labelFa'] ?? '') . ' — احساسات کل بازار، نه این ارز',
    ];
}

if (($f['change'] ?? null) !== null) {
    $items[] = [
        'label'  => 'تغییر شاخص نسبت به دیروز',
        'value'  => sprintf('%+d', (int) $f['change']),
        'plain'  => true,
        'meta'   => ($f['weekAgo'] ?? null) !== null ? 'هفته پیش: ' . (int) $f['weekAgo'] : '',
    ];
}

$statTitle  = 'وضعیت کلی بازار رمزارزها';
$statSource = 'CoinGecko · Alternative.me';
$statItems  = $items;
$statId     = 'thb-market-context-title';
$statSpan   = 12;
$statNote   = 'این بخش وضعیت کل بازار رمزارزها را نشان می‌دهد و مخصوص این ارز نیست.';

require __DIR__ . '/stat-grid.php';
