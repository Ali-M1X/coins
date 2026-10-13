<?php
/**
 * Screen 7 — the daily pulse. Reference: 8.png.
 *
 * Sentences generated from today's figures, each with the figure beside it,
 * under a Gregorian dateline, inside one card. Beside it, «۳۰ ثانیه»: four
 * facts the lines do not already say — distance from the all-time high, the
 * 30-day change, the share of the whole market, the market's mood — each with
 * a one-line plain explanation.
 *
 * 2.5.0: the "written automatically at HH:MM" line is gone; the «ecosystem
 * heat» bars are gone too — they repeated the inflow side of the chain-flow
 * diagram (fees by protocol group) under a name nobody could read.
 */
use TheHybit\Coins\V2\View;
defined('ABSPATH') || exit;

$p = $v['pulse'];
$c = $v['coin'];
?>
<section class="v2-section v2-pulse" id="pulse" aria-labelledby="pulse-title">
  <header class="v2-head v2-pulse__head">
    <h2 class="v2-head__title" id="pulse-title">پالس روزانه <?= esc_html($c['name']) ?></h2>
    <p class="v2-pulse__date"><time datetime="<?= esc_attr(substr((string) $v['generatedAt'], 0, 10)) ?>"><?= View::n($p['date']) ?></time></p>
  </header>

  <div class="v2-pulse__grid">
    <div class="v2-pulse__main">
      <div class="v2-card v2-pulse__card">
        <?php if ($p['lines'] !== []) : ?>
          <ul class="v2-pulse__lines">
            <?php foreach ($p['lines'] as $l) : ?>
              <li><span class="v2-pulse__text"><?= esc_html($l['text']) ?></span>
                <?php if ($l['figure']) : ?><strong class="v2-pulse__fig v2-delta--<?= esc_attr($l['dir']) ?>"><?= View::n($l['figure']) ?></strong><?php endif; ?></li>
            <?php endforeach; ?>
          </ul>
        <?php else : ?>
          <?= View::na('pending') ?>
        <?php endif; ?>

        <?php if ($p['chips'] !== []) : ?>
          <ul class="v2-chips">
            <?php foreach ($p['chips'] as $chip) : ?>
              <li class="v2-chips__item v2-chips__item--<?= esc_attr($chip['tone']) ?>"><?= esc_html($chip['text']) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($p['summary'] !== []) : ?>
    <aside class="v2-pulse__side">
      <div class="v2-card v2-card--accent v2-tldr">
        <h3 class="v2-card__title">اگر فقط ۳۰ ثانیه وقت دارید</h3>
        <ul class="v2-tldr__list">
          <?php foreach ($p['summary'] as $sm) : ?>
            <li>
              <span class="v2-tldr__label"><?= esc_html($sm['label']) ?></span>
              <strong class="v2-tldr__value v2-delta--<?= esc_attr($sm['dir']) ?>"><?= preg_match('/^[\p{Arabic}]/u', $sm['value']) ? esc_html($sm['value']) : View::n($sm['value']) ?></strong>
              <span class="v2-tldr__note"><?= esc_html($sm['note']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="v2-tldr__text">این خلاصه توصیف وضعیت فعلی است، نه توصیه خرید یا فروش.</p>
      </div>
    </aside>
    <?php endif; ?>
  </div>
</section>
