<?php
/**
 * Screen 6 — price as story. Reference: 7.png.
 *
 * Weekly price since launch (DefiLlama prices, log scale), with the editorial
 * timeline's dated events pinned to the price on that day. The event card's
 * figure is computed from the same series: the price change over the twelve
 * months after the event (or up to today, and labelled so, when a year has not
 * passed yet). Layers: TVL over the same years, and this site's articles.
 */
use TheHybit\Coins\Format;
use TheHybit\Coins\V2\View;
defined('ABSPATH') || exit;

$p = $v['priceStory'];
$c = $v['coin'];
$fe = $p['featured'] ?? null;
?>
<section class="v2-section v2-pstory" id="price-story" aria-labelledby="price-story-title">
  <?= View::heading('price-story', 'قیمت به‌روایت — ' . $c['name'], 'رویدادهای مهم روی نمودار قیمت از ابتدا') ?>

  <?php if (empty($p['line'])) : ?>
    <div class="v2-card"><?= View::na($p['state'], 'تاریخچه چندساله قیمت هنوز دریافت نشده') ?></div>
  <?php else : ?>
  <div class="v2-pstory__bar" role="group" aria-label="لایه‌ها">
    <button type="button" class="v2-toggle is-on" data-v2-layer="events" aria-pressed="true"><span class="v2-toggle__knob" aria-hidden="true"></span> رویدادها</button>
    <?php if ($p['tvl'] !== '') : ?>
      <button type="button" class="v2-toggle" data-v2-layer="tvl" aria-pressed="false"><span class="v2-toggle__knob" aria-hidden="true"></span> TVL</button>
    <?php endif; ?>
    <?php if ($p['articles'] !== []) : ?>
      <button type="button" class="v2-toggle" data-v2-layer="articles" aria-pressed="false"><span class="v2-toggle__knob" aria-hidden="true"></span> مقالات ما</button>
    <?php endif; ?>
  </div>

  <div class="v2-pstory__grid">
    <div class="v2-card v2-pstory__chart" data-v2-pstory>
      <div class="v2-xscroll" style="--min: 720px">
        <svg viewBox="0 -40 960 420" role="img" aria-label="قیمت هفتگی <?= esc_attr($c['name']) ?> از <?= esc_attr($p['from']) ?> تا امروز، مقیاس لگاریتمی، با <?= count($p['events']) ?> رویداد">
        <defs>
          <linearGradient id="v2-ps-area" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="var(--v2-accent)" stop-opacity=".4"/>
            <stop offset="1" stop-color="var(--v2-accent)" stop-opacity="0"/>
          </linearGradient>
        </defs>
        <?php foreach ($p['years'] as $yr) : ?>
          <line class="v2-grid" x1="<?= $yr['x'] ?>" y1="0" x2="<?= $yr['x'] ?>" y2="360"/>
          <text class="v2-axis" x="<?= $yr['x'] ?>" y="378" text-anchor="middle"><?= esc_html($yr['label']) ?></text>
        <?php endforeach; ?>
        <?php foreach ($p['ticks'] as $tk) : ?>
          <text class="v2-axis" x="4" y="<?= $tk['y'] - 4 ?>" text-anchor="start"><?= esc_html($tk['label']) ?></text>
        <?php endforeach; ?>
        <path d="<?= esc_attr($p['area']) ?>" fill="url(#v2-ps-area)"/>
        <path class="v2-chart__line" d="<?= esc_attr($p['line']) ?>"/>
        <?php if ($p['tvl'] !== '') : ?>
          <path class="v2-pstory__tvl" d="<?= esc_attr($p['tvl']) ?>" data-v2-layer-el="tvl" hidden/>
        <?php endif; ?>
        <g data-v2-layer-el="articles" hidden>
          <?php foreach ($p['articles'] as $a) : ?>
            <line class="v2-pstory__article" x1="<?= $a['x'] ?>" y1="0" x2="<?= $a['x'] ?>" y2="360"><title><?= esc_html($a['title']) ?></title></line>
          <?php endforeach; ?>
        </g>
        <g data-v2-layer-el="events">
          <?php foreach ($p['events'] as $e) : ?>
            <g class="v2-pin<?= $fe && $fe['key'] === $e['key'] ? ' is-on' : '' ?>" data-v2-event="<?= $e['key'] ?>" tabindex="0" role="button" aria-label="<?= esc_attr($e['title'] . '، ' . $e['date']) ?>">
              <line x1="<?= $e['x'] ?>" y1="<?= $e['y'] ?>" x2="<?= $e['x'] ?>" y2="<?= max(-10, $e['y'] - 46) ?>"/>
              <circle cx="<?= $e['x'] ?>" cy="<?= $e['y'] ?>" r="6"/>
              <text x="<?= $e['x'] ?>" y="<?= max(-14, $e['y'] - 52) ?>" text-anchor="middle"><?= esc_html(substr($e['date'], 0, 4)) ?></text>
              <title><?= esc_html($e['title'] . ' — ' . $e['date']) ?></title>
            </g>
          <?php endforeach; ?>
        </g>
      </svg>
        </div>
      <?= View::source($p['source'], $p['fetchedAt']) ?>
    </div>

    <aside class="v2-card v2-event" data-v2-eventcard aria-live="polite">
      <?php if ($fe) : ?>
        <h3 class="v2-event__title" data-v2-e="title"><?= esc_html($fe['title']) ?></h3>
        <p class="v2-event__date" data-v2-e="date"><?= View::n($fe['date']) ?></p>
        <p class="v2-event__text" data-v2-e="text"><?= esc_html($fe['text']) ?></p>
        <div class="v2-event__box">
          <p>تغییر قیمت <span data-v2-e="window"><?= esc_html($fe['window']) ?></span></p>
          <p class="v2-event__chg v2-delta--<?= View::dir($fe['change']) ?>" data-v2-e="change"><?= View::n($fe['change'] !== null ? Format::pct($fe['change'], true, 0) : null) ?></p>
          <p class="v2-muted">قیمت در روز رویداد: <span data-v2-e="price"><?= View::n(Format::usd((float) $fe['price'])) ?></span></p>
        </div>
      <?php else : ?>
        <?= View::na('unavailable', 'برای نمایش رویداد روی نمودار، تاریخ کامل (YYYY-MM-DD) در فیلد «داستان» لازم است') ?>
      <?php endif; ?>
      <?php if (!empty($c['newsUrl'])) : ?>
        <a class="v2-btn v2-btn--primary v2-btn--block" href="<?= esc_url($c['newsUrl']) ?>">مطالعه مرتبط</a>
      <?php endif; ?>
    </aside>
  </div>
  <?php endif; ?>
</section>
