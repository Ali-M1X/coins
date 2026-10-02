<?php
/**
 * Screen 4 — story & learning. Reference: 5.png (and the lower band of 2.png).
 *
 * The timeline, the "learn in 60 seconds" cards and the audience notes are
 * editorial content an editor writes in the coin's ACF fields. Related
 * insights are the site's own articles. "What's next" is data signals — each
 * a measured change shown with its figure — and the score ring is the classic
 * analytics score, unchanged.
 */
use TheHybit\Coins\Format;
use TheHybit\Coins\V2\View;
defined('ABSPATH') || exit;

$s = $v['story'];
$c = $v['coin'];
$featured = $s['featured'];
?>
<section class="v2-section v2-story" id="story" aria-labelledby="story-title">
  <?= View::heading('story', 'داستان ' . $c['name'], 'از یک ایده تا امروز') ?>

  <?php if ($s['timeline'] !== []) : ?>
    <div class="v2-card v2-timeline">
      <ol class="v2-timeline__list">
        <?php foreach ($s['timeline'] as $i => $t) :
            $isFeatured = $t['event'] !== null && $t['event'] === $featured; ?>
          <li class="v2-timeline__item<?= $isFeatured ? ' is-featured' : '' ?>">
            <span class="v2-timeline__dot" aria-hidden="true"></span>
            <time class="v2-timeline__year" datetime="<?= esc_attr($t['date']) ?>"><?= View::n($t['year']) ?></time>
            <h3 class="v2-timeline__title"><?= esc_html($t['title']) ?></h3>
            <p class="v2-timeline__text"><?= esc_html($t['text']) ?></p>
          </li>
        <?php endforeach; ?>
      </ol>
    </div>
  <?php else : ?>
    <div class="v2-card"><?= View::na('unavailable', 'رویدادهای این ارز هنوز در بخش «داستان» پیکربندی ارز نوشته نشده است') ?></div>
  <?php endif; ?>

  <div class="v2-story__grid">
    <div class="v2-card v2-learn">
      <h3 class="v2-card__title">یادگیری <?= esc_html($c['name']) ?> در ۶۰ ثانیه</h3>
      <?php if ($s['learn'] !== []) : ?>
        <ul class="v2-learn__list">
          <?php foreach ($s['learn'] as $i => $l) : ?>
            <li class="v2-learn__card">
              <span class="v2-learn__icon v2-t--<?= ['blue', 'violet', 'teal', 'pink', 'amber'][$i % 5] ?>" aria-hidden="true"><?= esc_html(mb_substr($l['title'], 0, 1)) ?></span>
              <h4><?= esc_html($l['title']) ?></h4>
              <p><?= esc_html($l['text']) ?></p>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else : ?>
        <?= View::na('unavailable', 'کارت‌های آموزشی هنوز نوشته نشده‌اند') ?>
      <?php endif; ?>
    </div>

    <div class="v2-card v2-insights">
      <h3 class="v2-card__title">تحلیل‌ها و اخبار مرتبط</h3>
      <?php if ($s['news'] !== []) : ?>
        <ul class="v2-insights__list">
          <?php foreach ($s['news'] as $n) : ?>
            <li>
              <a href="<?= esc_url($n['url']) ?>">
                <?php if (!empty($n['image'])) : ?>
                  <img src="<?= esc_url($n['image']) ?>" alt="" width="64" height="64" loading="lazy" decoding="async">
                <?php else : ?>
                  <span class="v2-insights__thumb" aria-hidden="true"></span>
                <?php endif; ?>
                <span class="v2-insights__title"><?= esc_html($n['title']) ?></span>
                <time datetime="<?= esc_attr((string) $n['iso']) ?>"><?= esc_html($n['date']) ?></time>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if (!empty($c['newsUrl'])) : ?>
          <a class="v2-link" href="<?= esc_url($c['newsUrl']) ?>">همه اخبار <?= esc_html($c['name']) ?></a>
        <?php endif; ?>
      <?php else : ?>
        <?= View::na('unavailable', 'هنوز مطلبی در دسته اخبار این ارز منتشر نشده') ?>
      <?php endif; ?>
    </div>

    <div class="v2-card v2-next">
      <h3 class="v2-card__title">بعد از این چه؟ سیگنال‌های داده</h3>
      <?php if ($s['signals'] !== []) : ?>
        <ul class="v2-signals">
          <?php foreach ($s['signals'] as $sig) : ?>
            <li>
              <span><?= esc_html($sig['label']) ?> <?= View::n($sig['value']) ?></span>
              <span class="v2-pill v2-pill--<?= esc_attr($sig['tone']) ?>"><?= esc_html($sig['toneFa']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="v2-muted">این‌ها توصیف تغییرات اندازه‌گیری‌شده‌اند، نه پیش‌بینی یا توصیه خرید.</p>
      <?php else : ?>
        <?= View::na('pending') ?>
      <?php endif; ?>

      <div class="v2-score">
        <svg viewBox="0 0 120 120" class="v2-score__ring" role="img" aria-label="امتیاز <?= esc_attr($v['brand']) ?>: <?= esc_attr((string) ($s['score'] ?? 'نامشخص')) ?> از ۱۰۰">
          <circle cx="60" cy="60" r="52" class="v2-score__track"/>
          <?php if ($s['scoreDash']) : ?>
            <circle cx="60" cy="60" r="52" class="v2-score__fill" stroke-dasharray="<?= esc_attr($s['scoreDash']) ?>" transform="rotate(-90 60 60)"/>
          <?php endif; ?>
          <text x="60" y="66" text-anchor="middle" class="v2-score__num"><?= esc_html($s['score'] !== null ? (string) $s['score'] : '—') ?></text>
          <text x="60" y="84" text-anchor="middle" class="v2-score__of">/100</text>
        </svg>
        <div>
          <p class="v2-score__label">تحلیل اختصاصی <?= esc_html($v['brand']) ?></p>
          <?php if ($s['scoreBand']) : ?><p class="v2-muted">سطح: <?= esc_html($s['scoreBand']) ?></p><?php endif; ?>
          <a class="v2-btn v2-btn--primary v2-btn--sm" href="#dna">مطالعه تحلیل کامل</a>
        </div>
      </div>
    </div>
  </div>
</section>
