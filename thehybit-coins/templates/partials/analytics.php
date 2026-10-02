<?php
/**
 * §10 TheHybit analytics — OURS, not a provider's.
 *
 * Server-rendered, unlike the prototype where JS computed it. Same numbers,
 * same provisional model; the score is simply calculated in PHP before the page
 * is sent, so it is in the HTML for search engines and for readers without JS.
 *
 * The provisional badge and footnote are non-negotiable furniture: the weights
 * are placeholder calibration, and the UI has to say so.
 */
use TheHybit\Coins\Format;
defined('ABSPATH') || exit;

$scored = $model['analytics'];
if (empty($scored['available'])) {
    return; // withheld rather than shown misleadingly
}

$providerNames = [
    'coingecko' => 'CoinGecko', 'defillama' => 'DefiLlama',
    'dexscreener' => 'DexScreener', 'dune' => 'Dune', 'l2beat' => 'L2BEAT',
];
$band = $scored['band'];
?>
<section class="thb-card thb-analytics" aria-labelledby="thb-analytics-title">
  <div class="thb-card__head">
    <h2 class="thb-card__title" id="thb-analytics-title">تحلیل اختصاصی TheHybit</h2>
    <span class="thb-analytics__badges">
      <?php if (!empty($scored['provisional'])) : ?>
        <span class="thb-badge thb-badge--warn">مدل آزمایشی</span>
      <?php endif; ?>
      <?php if ($scored['coverage'] < 1) : ?>
        <span class="thb-source">بر پایه <?= esc_html(Format::num($scored['coverage'] * 100, 0)) ?>% از شاخص‌ها</span>
      <?php endif; ?>
    </span>
  </div>

  <div class="thb-card__body">
    <div class="thb-analytics__top">
      <div>
        <div class="thb-score" data-thb-gauge-value="<?= esc_attr((string) $scored['value']) ?>"
             data-thb-gauge-tone="<?= esc_attr($band['tone']) ?>">
          <div data-thb-gauge></div>
          <div class="thb-score__readout">
            <span class="thb-score__value"><span class="thb-num" data-thb-score><?= esc_html(Format::num((float) $scored['value'], 0)) ?></span></span>
            <span class="thb-score__max thb-num">/100</span>
          </div>
        </div>
        <span class="thb-score__band" data-thb-score-band data-tone="<?= esc_attr($band['tone']) ?>"><?= esc_html($band['label']) ?></span>
      </div>

      <?php if (!empty($model['insight'])) : ?>
        <p class="thb-analytics__insight" data-thb-insight><?= esc_html($model['insight']) ?></p>
      <?php endif; ?>
    </div>

    <div class="thb-dmetrics" data-thb-dmetrics>
      <?php foreach ($scored['components'] as $c) :
        if (empty($c['available'])) { continue; }   // no tile rather than a dash
        $value = $c['unit'] === 'ratio'
            ? Format::ratio($c['raw'])
            : Format::pct($c['raw'], !empty($c['signed']));
        $sources = implode(' × ', array_map(
            static fn($p) => $providerNames[$p] ?? $p,
            $c['providers']
        )); ?>
        <div class="thb-dmetric">
          <span class="thb-dmetric__label"><?= esc_html($c['label']) ?></span>
          <span class="thb-dmetric__value"><span class="thb-num"><?= esc_html($value) ?></span></span>
          <span class="thb-dmetric__band" data-tone="<?= esc_attr($c['band']['tone']) ?>"><?= esc_html($c['band']['label']) ?></span>
          <!-- Provenance: the visible marker that this figure is a combination
               we made, not a number a provider handed us. -->
          <span class="thb-dmetric__src"><?= esc_html($sources) ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <p class="thb-analytics__note">
      <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
        <path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      <span>
        وزن‌ها و آستانه‌های این امتیاز <strong>موقت</strong> هستند و صرفاً برای نمایش کارکرد
        محاسبات انتخاب شده‌اند. روش‌شناسی نهایی پس از تکمیل نگاشت منابع داده تعیین می‌شود.
      </span>
    </p>
  </div>
</section>
