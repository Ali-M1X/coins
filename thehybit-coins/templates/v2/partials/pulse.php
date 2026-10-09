<?php
/**
 * Screen 7 — the daily pulse. Reference: 8.png.
 *
 * Four sentences generated from today's figures, each with the figure beside
 * it, a dateline in the Jalali calendar, and — the part that makes it honest —
 * when each underlying dataset was fetched and from where.
 */
use TheHybit\Coins\V2\View;
defined('ABSPATH') || exit;

$p = $v['pulse'];
$c = $v['coin'];
?>
<section class="v2-section v2-pulse" id="pulse" aria-labelledby="pulse-title">
  <header class="v2-head v2-pulse__head">
    <h2 class="v2-head__title" id="pulse-title">پالس روزانه <?= esc_html($c['name']) ?></h2>
    <p class="v2-pulse__date"><span class="v2-live">خودکار</span> <time datetime="<?= esc_attr($v['generatedAt']) ?>"><?= View::n($p['date']) ?></time></p>
    <p class="v2-head__sub">به‌صورت خودکار از ترکیب داده‌ها نوشته شده · ساعت <?= View::n($p['time']) ?> به وقت تهران</p>
  </header>

  <div class="v2-pulse__grid">
    <div class="v2-pulse__main">
      <?php if ($p['lines'] !== []) : ?>
        <ul class="v2-pulse__lines">
          <?php foreach ($p['lines'] as $l) : ?>
            <li><?= esc_html($l['text']) ?>
              <?php if ($l['figure']) : ?><strong class="v2-delta--<?= esc_attr($l['dir']) ?>"><?= View::n($l['figure']) ?></strong><?php endif; ?></li>
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

      <div class="v2-card v2-heat">
        <h3 class="v2-card__title">حرارت اکوسیستم امروز</h3>
        <?php if ($p['heat'] !== []) : ?>
          <ul class="v2-heat__bars">
            <?php foreach ($p['heat'] as $i => $h) : ?>
              <li>
                <span class="v2-heat__bar v2-t--<?= ['teal', 'violet', 'green', 'amber', 'pink'][$i % 5] ?>" style="--h: <?= round(max(6, $h['share']), 1) ?>%"></span>
                <span class="v2-heat__label"><?= esc_html($h['label']) ?></span>
                <span class="v2-heat__pct"><?= View::n(\TheHybit\Coins\Format::pct($h['share'], false, 0)) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
          <p class="v2-muted">سهم هر گروه از کارمزد ۲۴ ساعته پروتکل‌ها</p>
        <?php else : ?>
          <?= View::na($p['heatState']) ?>
        <?php endif; ?>
      </div>
    </div>

    <aside class="v2-pulse__side">
      <?php /* The per-dataset provenance list that used to stand here is a
         transparency record, not a reading aid: it answers "where did this
         number come from, and how old is it?", which a reader asks of a
         specific figure, not while reading the day's summary. It is now a
         collapsed footnote under the About section. */ ?>
      <div class="v2-card v2-card--accent v2-tldr">
        <h3 class="v2-card__title">اگر فقط ۳۰ ثانیه وقت داری</h3>
        <?php $top = array_slice(array_values(array_filter($v['story']['signals'], static fn($x) => $x['tone'] !== 'neutral')), 0, 3); ?>
        <?php if ($top !== []) : ?>
          <ul class="v2-tldr__list">
            <?php foreach ($top as $sig) : ?>
              <li><span class="v2-pill v2-pill--<?= esc_attr($sig['tone']) ?>"><?= esc_html($sig['toneFa']) ?></span> <?= esc_html($sig['label']) ?> <?= View::n($sig['value']) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <p class="v2-tldr__text">ترکیب داده‌ها، سیگنال خرید نیست.</p>
      </div>
    </aside>
  </div>
</section>
