<?php
/**
 * Market Structure — where this coin actually trades, centralised and not.
 *
 * WHAT THIS REPLACED
 *
 * A DEX card carrying three figures in a container sized for six, which the
 * previous audit measured at roughly half empty. The fix is not tighter margins
 * — it is giving the card enough to say. Merging the centralised venues into it
 * doubles the content at no layout cost, and the two halves genuinely belong
 * together: they are the same question asked of two kinds of venue.
 *
 * NO TICKER TABLE. The provider returns hundreds of rows and rendering them
 * would be a data dump, not analysis. What appears here is derived in
 * CoinGecko::structure(): top venue, top pair, how concentrated the volume is,
 * and the median spread — a handful of figures that answer "how healthy is this
 * market" without asking the reader to scan a table.
 *
 * HONEST ABOUT THE SAMPLE. CoinGecko returns a page of tickers, not a census of
 * every market in existence. So the count is labelled as markets SEEN and
 * concentration as concentration among them. Calling either a total would be a
 * fabrication, and a confident one.
 *
 * @package TheHybit\Coins
 */

use TheHybit\Coins\Format;

defined('ABSPATH') || exit;

$structure = $model['structure'] ?? ['available' => false, 'state' => 'pending'];
$dex = $model['dex'] ?? null;
$ctx = $model['context'] ?? [];

$s = (array) ($structure['data'] ?? []);
$items = [];

/* ---- centralised venues ---- */

if (($s['topVenue'] ?? null) !== null) {
    $items[] = [
        'label' => 'بزرگ‌ترین صرافی متمرکز',
        'value' => (string) $s['topVenue'],
        'plain' => true,
        'meta'  => ($s['topVenueShare'] ?? null) !== null
            ? Format::pct((float) $s['topVenueShare'], false) . ' از حجم دیده‌شده'
            : '',
    ];
}

if (($s['topPair'] ?? null) !== null) {
    $items[] = [
        'label' => 'پرحجم‌ترین جفت‌ارز',
        'value' => (string) $s['topPair'],
        'plain' => true,
        'meta'  => (string) ($s['topPairVenue'] ?? ''),
    ];
}

if (($s['marketsSeen'] ?? null) !== null) {
    $items[] = [
        'label' => 'بازارهای بررسی‌شده',
        'value' => Format::num((float) $s['marketsSeen'], 0),
        'meta'  => 'در ' . Format::num((float) ($s['venues'] ?? 0), 0) . ' صرافی',
    ];
}

/* Withheld below three venues by the collector — a Herfindahl over one venue is
   arithmetically 100 and means nothing at all. */
if (($s['concentration'] ?? null) !== null) {
    $c = (float) $s['concentration'];
    $items[] = [
        'label' => 'تمرکز حجم در صرافی‌ها',
        'value' => Format::num($c, 1),
        'meta'  => $c >= 40 ? 'متمرکز در چند صرافی' : ($c >= 20 ? 'نسبتاً متمرکز' : 'پخش‌شده'),
    ];
}

if (($s['medianSpread'] ?? null) !== null) {
    $items[] = [
        'label' => 'میانه اسپرد خرید/فروش',
        'value' => Format::pct((float) $s['medianSpread'], false),
        'meta'  => 'هرچه کمتر، نقدشوندگی بهتر',
    ];
}

/* ---- decentralised venues ---- */

if (is_array($dex)) {
    if (($dex['pairCount'] ?? null) !== null) {
        $items[] = [
            'label' => 'تعداد جفت‌ارز غیرمتمرکز',
            'value' => Format::num((float) $dex['pairCount'], 0),
            'meta'  => ($dex['topPair']['dex'] ?? null) ? 'برتر: ' . (string) $dex['topPair']['dex'] : '',
        ];
    }
    if (($dex['volume24h'] ?? null) !== null) {
        $items[] = [
            'label' => 'حجم ۲۴ ساعته صرافی‌های غیرمتمرکز',
            'value' => Format::usdCompact((float) $dex['volume24h']),
        ];
    }
    if (($dex['liquidity'] ?? null) !== null) {
        $items[] = [
            'label' => 'نقدینگی غیرمتمرکز',
            'value' => Format::usdCompact((float) $dex['liquidity']),
        ];
    }
    if (($dex['topPair']['pair'] ?? null) !== null) {
        $items[] = [
            'label' => 'جفت‌ارز برتر غیرمتمرکز',
            'value' => (string) $dex['topPair']['pair'],
            'plain' => true,
            'meta'  => (string) ($dex['topPair']['dex'] ?? ''),
        ];
    }
}

/* The relationship between the two halves — the reason for merging them. The
   denominator is the ticker sample, and the label says so rather than implying
   a census. */
if (!empty($ctx['dexVsCex']['available'])) {
    $items[] = [
        'label' => 'نسبت حجم غیرمتمرکز به متمرکز',
        'value' => Format::pct((float) $ctx['dexVsCex']['value'], false),
        'meta'  => 'نسبت به صرافی‌های نمونه‌برداری‌شده',
    ];
}

$hasDex = is_array($dex) && $items !== [];

$statState = ($structure['available'] || $hasDex)
    ? 'ok'
    : (($structure['state'] ?? 'pending') === 'pending' ? 'pending' : 'unavailable');

$statTitle  = 'ساختار بازار';
$statSource = 'CoinGecko · DexScreener';
$statItems  = $items;
$statId     = 'thb-structure-title';
$statSpan   = 12;
$statNote   = 'ارقام صرافی‌های متمرکز از نمونه بازارهایی است که CoinGecko بازمی‌گرداند، نه همه بازارهای موجود.';

require __DIR__ . '/stat-grid.php';
