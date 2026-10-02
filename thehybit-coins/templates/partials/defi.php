<?php
/** §6 DeFi — RAW DefiLlama figures only. Ratios belong to §10. */
use TheHybit\Coins\Format;
defined('ABSPATH') || exit;

$d = $model['defi'];
$cardTitle = 'نمای کلی دیفای';
$cardSource = $d['source'] ?? 'DefiLlama';
$cardLabelledBy = 'thb-defi-title';
$cardWide = false;
$cardTiles = array_values(array_filter([
    isset($d['tvl']) ? ['label' => 'ارزش کل قفل‌شده', 'value' => Format::usdCompact($d['tvl']), 'change' => $d['tvlChange24h'] ?? null] : null,
    isset($d['fees24h']) ? ['label' => 'کارمزد 24 ساعته', 'value' => Format::usdCompact($d['fees24h']), 'change' => $d['feesChange24h'] ?? null] : null,
    isset($d['revenue24h']) ? ['label' => 'درآمد 24 ساعته', 'value' => Format::usdCompact($d['revenue24h']), 'change' => $d['revenueChange24h'] ?? null] : null,
]));
$cardSpark = !empty($d['sparkline']) ? 'defi' : null;
$cardSparkCaption = 'روند ارزش کل قفل‌شده در 30 روز گذشته';

/* Growth over longer windows, plus the valuation ratio.
   The TVL changes come out of the historicalChainTvl response we already make —
   it returns the full daily series and the collector used to discard it. The
   fee and revenue windows come out of the fees response the same way. */
$derived = $model['derived'] ?? [];
$trend = array_values(array_filter([
    isset($d['tvlChange7d'])  ? ['TVL، 7 روز', (float) $d['tvlChange7d']] : null,
    isset($d['tvlChange30d']) ? ['TVL، 30 روز', (float) $d['tvlChange30d']] : null,
    isset($d['tvlChange90d']) ? ['TVL، 90 روز', (float) $d['tvlChange90d']] : null,
    isset($d['feesChange30d']) ? ['کارمزد، 30 روز', (float) $d['feesChange30d']] : null,
]));

$ratios = array_values(array_filter([
    !empty($derived['tvlToMarketCap']['available'])
        ? ['ارزش قفل‌شده به ارزش بازار', Format::ratio((float) $derived['tvlToMarketCap']['value'])] : null,
    !empty($derived['feesToTvl']['available'])
        ? ['کارمزد سالانه به TVL', Format::pct((float) $derived['feesToTvl']['value'], false)] : null,
]));

$cardExtra = null;
if ($trend || $ratios) {
    ob_start(); ?>
    <hr class="thb-divider">
    <div class="thb-perf__rows">
      <?php foreach ($trend as [$label, $value]) : ?>
        <div class="thb-perf__row">
          <span class="thb-perf__label"><?= esc_html($label) ?></span>
          <span class="thb-change" data-dir="<?= esc_attr(Format::direction($value)) ?>">
            <span class="thb-change__arrow" aria-hidden="true"><?= Format::arrow($value) ?></span><span><?= esc_html(Format::pct($value)) ?></span>
          </span>
        </div>
      <?php endforeach; ?>
      <?php foreach ($ratios as [$label, $value]) : ?>
        <div class="thb-perf__row">
          <span class="thb-perf__label"><?= esc_html($label) ?></span>
          <span class="thb-perf__value"><span class="thb-num"><?= esc_html($value) ?></span></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
    $cardExtra = ob_get_clean();
}

require __DIR__ . '/metric-card.php';
