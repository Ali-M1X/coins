<?php
/** §9 DEX — CONDITIONAL. Rendered only when volume and share clear the thresholds. */
use TheHybit\Coins\Format;
defined('ABSPATH') || exit;

$x = $model['dex'];
$top = $x['topPair'] ?? [];
$cardTitle = 'بازارهای غیرمتمرکز';
$cardSource = $x['source'] ?? 'DexScreener';
$cardLabelledBy = 'thb-dex-title';
// The odd card out takes the full row rather than trailing an empty half.
$cardWide = !empty($oddCard) && !empty($isLastMetricCard);
$cardTiles = array_values(array_filter([
    isset($x['volume24h']) ? ['label' => 'حجم 24 ساعته', 'value' => Format::usdCompact($x['volume24h']), 'change' => $x['volumeChange24h'] ?? null] : null,
    isset($x['liquidity']) ? ['label' => 'نقدینگی', 'value' => Format::usdCompact($x['liquidity']), 'change' => $x['liquidityChange24h'] ?? null] : null,
    !empty($top['pair']) ? ['label' => 'برترین جفت‌ارز', 'value' => $top['pair'], 'plain' => true, 'note' => $top['exchange'] ?? ''] : null,
]));
$cardSpark = null;

/* Pair count and the venue behind the top pair were already collected by the
   collector and simply never shown. `sampled` is surfaced too, because a total
   drawn from a truncated page of pairs should say so rather than read as
   complete — the same honesty that keeps it from vetoing its own section. */
$dexRows = array_values(array_filter([
    !empty($x['pairCount']) ? ['تعداد جفت‌ارز', (string) (int) $x['pairCount']] : null,
    !empty($top['exchange']) ? ['صرافی برتر', (string) $top['exchange']] : null,
]));

$cardExtra = null;
if ($dexRows) {
    ob_start(); ?>
    <hr class="thb-divider">
    <div class="thb-perf__rows">
      <?php foreach ($dexRows as [$label, $value]) : ?>
        <div class="thb-perf__row">
          <span class="thb-perf__label"><?= esc_html($label) ?></span>
          <span class="thb-perf__value"><bdi><?= esc_html($value) ?></bdi></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (!empty($x['sampled'])) : ?>
      <small class="thb-trend__caption">بر پایه پرحجم‌ترین جفت‌ارزهای گزارش‌شده</small>
    <?php endif; ?>
    <?php
    $cardExtra = ob_get_clean();
}

require __DIR__ . '/metric-card.php';
