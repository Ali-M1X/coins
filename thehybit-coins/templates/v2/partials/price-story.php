<?php
/**
 * Screen 6 — price as story. Reference: 7.png.
 *
 * Weekly price since launch (DefiLlama prices, log scale), with the editorial
 * timeline's dated events pinned to the price on that day. The event card's
 * figure is computed from the same series: the price change over the twelve
 * months after the event (or up to today, and labelled so, when a year has not
 * passed yet). Layers: TVL over the same years, and this site's articles.
 *
 * 2.5.0: the side panel that repeated one event at a time is gone; the chart
 * takes the full width, and each event explains itself in a box that opens on
 * hover, keyboard focus or tap, right where the event is.
 */
use TheHybit\Coins\Format;
use TheHybit\Coins\V2\View;
defined('ABSPATH') || exit;

$p = $v['priceStory'];
$c = $v['coin'];
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

  <div class="v2-pstory__grid v2-pstory__grid--full">
    <div class="v2-card v2-pstory__chart" data-v2-pstory>
      <div class="v2-xscroll" style="--min: 720px">
        <svg viewBox="0 -205 960 585" role="img" aria-label="قیمت هفتگی <?= esc_attr($c['name']) ?> از <?= esc_attr($p['from']) ?> تا امروز، مقیاس لگاریتمی، با <?= count($p['events']) ?> رویداد">
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
          <defs>
            <marker id="v2-arrowhead" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="9" markerHeight="9" orient="auto">
              <path d="M0 0 L10 5 L0 10 Z" class="v2-pin__head"/>
            </marker>
          </defs>
          <?php foreach ($p['events'] as $e) :
              $lw = 132; $lx = max(2, min(960 - $lw - 2, $e['x'] - $lw / 2)); ?>
            <g class="v2-pin" data-v2-event="<?= $e['key'] ?>" tabindex="0" role="button"
               aria-label="<?= esc_attr($e['title'] . '، ' . $e['dateFa'] . '. ' . $e['text']) ?>">
              <line class="v2-pin__arrow" x1="<?= $e['x'] ?>" y1="<?= $e['ly'] + 38 ?>" x2="<?= $e['x'] ?>" y2="<?= $e['y'] - 9 ?>" marker-end="url(#v2-arrowhead)"/>
              <circle cx="<?= $e['x'] ?>" cy="<?= $e['y'] ?>" r="5"/>
              <rect class="v2-pin__box" x="<?= $lx ?>" y="<?= $e['ly'] ?>" width="<?= $lw ?>" height="38" rx="7"/>
              <text class="v2-pin__title v2-fa" x="<?= $lx + $lw / 2 ?>" y="<?= $e['ly'] + 16 ?>" text-anchor="middle"><?= esc_html($e['title']) ?></text>
              <text class="v2-pin__meta" x="<?= $lx + $lw / 2 ?>" y="<?= $e['ly'] + 32 ?>" text-anchor="middle"><?= esc_html($e['monthFa']) ?></text>
            </g>
          <?php endforeach; ?>
        </g>
      </svg>
        </div>
      <?php /* One explanation box, filled from the data island by v2.js and
         placed next to the event. Every explanation is also in the pin's
         aria-label, so a screen reader hears it without the box. */ ?>
      <div class="v2-pop" data-v2-pop role="tooltip" id="v2-pop" hidden>
        <p class="v2-pop__title" data-v2-p="title"></p>
        <p class="v2-pop__date" data-v2-p="date"></p>
        <p class="v2-pop__text" data-v2-p="text"></p>
        <p class="v2-pop__figs"><span>قیمت در آن روز: <bdi class="v2-num" dir="ltr" data-v2-p="price"></bdi></span>
          <span data-v2-p="changeRow">تغییر قیمت <span data-v2-p="window"></span>: <bdi class="v2-num" dir="ltr" data-v2-p="change"></bdi></span></p>
      </div>
      <p class="v2-hint">برای دیدن توضیح هر رویداد، نشانگر را روی آن ببرید یا آن را لمس کنید.</p>
    </div>

  </div>
  <?php endif; ?>
</section>
