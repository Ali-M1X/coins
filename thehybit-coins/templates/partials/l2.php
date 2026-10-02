<?php
/**
 * §8 Layer 2 — CONDITIONAL, and only for assets that ARE an L2 (scope=chain).
 * Ethereum's ecosystem-level aggregation is a different meaning for the same
 * labels, so it stays hidden until that distinction is defined.
 */
use TheHybit\Coins\Format;
defined('ABSPATH') || exit;

$l = $model['l2'];
$levels = ['low' => ['کم‌خطر', 'var(--thb-up)'], 'medium' => ['متوسط', 'var(--thb-warn)'], 'high' => ['پرخطر', 'var(--thb-down)']];

ob_start();
if (!empty($l['risks'])) : ?>
  <hr class="thb-divider" style="margin-block:var(--thb-s5)">
  <div class="thb-rows">
    <?php foreach ($l['risks'] as $risk) :
      $level = $levels[$risk['level'] ?? 'medium'] ?? $levels['medium']; ?>
      <div class="thb-row">
        <span class="thb-row__label"><?= esc_html($risk['label'] ?? '') ?></span>
        <span class="thb-row__value" style="color:<?= esc_attr($level[1]) ?>"><?= esc_html($level[0]) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif;
$cardExtra = ob_get_clean();

$cardTitle = 'شبکه لایه 2';
$cardSource = $l['source'] ?? 'L2BEAT';
$cardLabelledBy = 'thb-l2-title';
$cardWide = !empty($oddCard) && !empty($isLastMetricCard);
$cardTiles = array_values(array_filter([
    isset($l['tvs']) ? ['label' => 'ارزش کل تضمین‌شده', 'value' => Format::usdCompact($l['tvs']), 'change' => $l['tvsChange24h'] ?? null] : null,
    isset($l['tps']) ? ['label' => 'تراکنش بر ثانیه', 'value' => Format::num($l['tps'], 1), 'note' => 'میانگین 24 ساعته'] : null,
    !empty($l['stage']) ? ['label' => 'مرحله بلوغ', 'value' => $l['stage'], 'plain' => true, 'note' => 'بر پایه چارچوب L2BEAT'] : null,
]));
$cardSpark = null;

require __DIR__ . '/metric-card.php';
