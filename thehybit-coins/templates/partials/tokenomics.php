<?php
/**
 * §5b Tokenomics — supply and valuation.
 *
 * Everything here comes from the /coins/{id} response the plugin already makes:
 * supply figures were collected and never displayed, FDV likewise. No new
 * provider, no new request.
 *
 * The section uses the approved metric-card markup unchanged, so it introduces
 * no new visual language — it is the §6/§7/§8/§9 card with different tiles,
 * plus a compact supply row underneath.
 *
 * AN UNCAPPED ASSET IS NOT A ZERO. Ethereum has no maximum supply; that is a
 * fact about Ethereum, not missing data. It renders as ∞ rather than being
 * hidden (which would imply we failed to fetch it) or shown as 0 (which would
 * be false).
 */

use TheHybit\Coins\Format;

defined('ABSPATH') || exit;

$m = $model['market'] ?? [];
$d = $model['derived'] ?? [];

$val = static fn(string $key) =>
    !empty($d[$key]['available']) ? (float) $d[$key]['value'] : null;

$circulating = $m['circulatingSupply'] ?? null;
$total       = $m['totalSupply'] ?? null;
$max         = $m['maxSupply'] ?? null;
$symbol      = $model['coin']['symbol'] ?? '';

/* The market header already shows market cap, FDV, circulating supply and max
   supply. This section must ADD to that, not restate it — so the tiles carry
   the relationships between those figures, which is what tokenomics actually
   means, and the rows carry the full supply breakdown in one place. */
$cardTiles = array_values(array_filter([
    $val('marketCapToFdv') !== null
        ? ['label' => 'ارزش بازار به رقیق‌شده', 'value' => Format::ratio($val('marketCapToFdv')), 'plain' => true,
           'note' => 'هرچه نزدیک‌تر به 1، رقیق‌شدگی کمتر']
        : null,
    $val('circulatingPct') !== null
        ? ['label' => 'عرضه در گردش', 'value' => Format::pct($val('circulatingPct'), false), 'plain' => true,
           'note' => 'از کل عرضه']
        : null,
    ($total !== null && $circulating !== null)
        ? ['label' => 'عرضه آزادنشده', 'value' => Format::compact(max(0.0, (float) $total - (float) $circulating)) . ' ' . $symbol,
           'plain' => true, 'note' => 'اختلاف کل و در گردش']
        : null,
]));

if ($cardTiles === []) {
    return; // nothing to say; the section does not render
}

/* Supply rows, in the existing performance-row language. */
$supplyRows = array_values(array_filter([
    $circulating !== null
        ? ['عرضه در گردش', Format::compact((float) $circulating) . ' ' . $symbol] : null,
    $total !== null
        ? ['کل عرضه', Format::compact((float) $total) . ' ' . $symbol] : null,
    ['حداکثر عرضه', $max !== null ? Format::compact((float) $max) . ' ' . $symbol : '∞'],
]));

ob_start(); ?>
<hr class="thb-divider">
<div class="thb-perf__rows">
  <?php foreach ($supplyRows as [$label, $value]) : ?>
    <div class="thb-perf__row">
      <span class="thb-perf__label"><?= esc_html($label) ?></span>
      <span class="thb-perf__value"><span class="thb-num"><?= esc_html($value) ?></span></span>
    </div>
  <?php endforeach; ?>
</div>
<?php
$cardExtra = ob_get_clean();

$cardTitle       = 'توکنومیک';
$cardSource      = 'CoinGecko';
$cardLabelledBy  = 'thb-tokenomics-title';
$cardWide        = true;   // full row: it summarises the whole asset
$cardSpark       = null;

require __DIR__ . '/metric-card.php';
