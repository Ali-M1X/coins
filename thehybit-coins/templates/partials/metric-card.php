<?php
/**
 * Shared renderer for the §6/§7/§8/§9 metric cards.
 *
 * All four use the same approved markup: a title, a source label, three metric
 * tiles and an optional trend line. Rendering them from one file is what keeps
 * them identical as the design evolves.
 *
 * Expects: $cardTitle, $cardSource, $cardTiles, optionally $cardSpark,
 * $cardSparkCaption, $cardWide, $cardLabelledBy, $cardExtra.
 */
use TheHybit\Coins\Format;
defined('ABSPATH') || exit;

$classes = 'thb-card thb-metric-card' . (!empty($cardWide) ? ' thb-metric-card--wide' : '');
?>
<section class="<?= esc_attr($classes) ?>" aria-labelledby="<?= esc_attr($cardLabelledBy) ?>">
  <div class="thb-card__head">
    <h2 class="thb-card__title" id="<?= esc_attr($cardLabelledBy) ?>"><?= esc_html($cardTitle) ?></h2>
    <?php if (!empty($cardSource)) : ?>
      <span class="thb-source">منبع: <?= esc_html($cardSource) ?></span>
    <?php endif; ?>
  </div>

  <div class="thb-card__body">
    <div class="thb-metrics">
      <?php foreach ($cardTiles as $tile) : ?>
        <div class="thb-metric">
          <span class="thb-metric__label"><?= esc_html($tile['label']) ?></span>
          <span class="thb-metric__value">
            <?php if (!empty($tile['plain'])) : ?>
              <bdi><?= esc_html($tile['value']) ?></bdi>
            <?php else : ?>
              <span class="thb-num"><?= esc_html($tile['value']) ?></span>
            <?php endif; ?>
          </span>
          <span class="thb-metric__change<?= isset($tile['change']) ? '' : ' thb-muted' ?>">
            <?php if (isset($tile['change'])) : ?>
              <span class="thb-change" data-dir="<?= esc_attr(Format::direction($tile['change'])) ?>">
                <span class="thb-change__arrow" aria-hidden="true"><?= Format::arrow($tile['change']) ?></span><span><?= esc_html(Format::pct($tile['change'])) ?></span>
              </span>
            <?php else : ?>
              <?= esc_html($tile['note'] ?? '') ?>
            <?php endif; ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if (!empty($cardSpark)) : ?>
      <div class="thb-trend" data-thb-sparkline="<?= esc_attr($cardSpark) ?>"></div>
      <small class="thb-trend__caption"><?= esc_html($cardSparkCaption ?? '') ?></small>
    <?php endif; ?>

    <?php if (!empty($cardExtra)) { echo $cardExtra; } ?>
  </div>
</section>
