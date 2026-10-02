<?php
/**
 * Screen 1 — overview: identity, price, the orbit, the chart, key metrics.
 * Reference: 1.png and the top band of 2.png.
 */
use TheHybit\Coins\Format;
use TheHybit\Coins\V2\View;
defined('ABSPATH') || exit;

$c = $v['coin'];
$h = $v['hero'];
$ch = $v['chart'];
?>
<section class="v2-overview" id="overview" aria-labelledby="overview-title">

  <div class="v2-overview__id">
    <p class="v2-kicker">قیمت <?= esc_html($c['name']) ?> (<bdi><?= esc_html($c['symbol']) ?></bdi>) امروز</p>
    <div class="v2-identity">
      <?php if (!empty($c['logo'])) : ?>
        <img class="v2-identity__logo" src="<?= esc_url($c['logo']) ?>" alt="نشان <?= esc_attr($c['name']) ?>" width="48" height="48" decoding="async">
      <?php endif; ?>
      <h1 class="v2-identity__name" id="overview-title"><?= esc_html($c['name']) ?></h1>
      <span class="v2-chip"><bdi><?= esc_html($c['symbol']) ?></bdi></span>
      <?php if (!empty($c['rank'])) : ?>
        <span class="v2-chip v2-chip--muted">رتبه <?= View::n('#' . $c['rank']) ?></span>
      <?php endif; ?>
    </div>
    <?php if ($c['tagline'] !== '') : ?>
      <p class="v2-tagline"><?= esc_html($c['tagline']) ?></p>
    <?php endif; ?>

    <div class="v2-price">
      <?php if ($h['price'] !== null) : ?>
        <span class="v2-price__value" data-v2-price><?= View::n(Format::usd((float) $h['price'])) ?></span>
        <span class="v2-delta v2-delta--<?= View::dir($h['change24h']) ?>">
          <?= esc_html(Format::arrow($h['change24h'])) ?> <?= View::n(Format::pct($h['change24h'])) ?> <small>۲۴ ساعت</small>
        </span>
      <?php else : ?>
        <?= View::na('pending') ?>
      <?php endif; ?>
    </div>
    <?php if (!empty($h['toman']) && $h['toman'] !== '—') : ?>
      <p class="v2-toman">≈ <?= View::n($h['toman']) ?> تومان</p>
    <?php endif; ?>

    <div class="v2-actions">
      <?php if ($c['tradeUrl']) : ?>
        <a class="v2-btn v2-btn--primary" href="<?= esc_url($c['tradeUrl']) ?>" rel="nofollow noopener" target="_blank">معامله <?= esc_html($c['name']) ?></a>
      <?php endif; ?>
      <button type="button" class="v2-btn v2-btn--ghost" data-v2-watch="<?= esc_attr($c['slug']) ?>" aria-pressed="false" aria-label="افزودن <?= esc_attr($c['name']) ?> به واچ‌لیست">
        <svg viewBox="0 0 24 24" aria-hidden="true" width="16" height="16"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/></svg>
        <span data-v2-watch-label>واچ‌لیست</span>
      </button>
    </div>

    <dl class="v2-stats">
      <div><dt>ارزش بازار</dt><dd><?= View::n(Format::usdCompact($h['marketCap'])) ?></dd></div>
      <div><dt>حجم ۲۴ ساعته</dt><dd><?= View::n(Format::usdCompact($h['volume24h'])) ?></dd></div>
      <div><dt>عرضه در گردش</dt><dd><?= View::n(Format::compact($h['circulating'])) ?> <bdi><?= esc_html($c['symbol']) ?></bdi></dd></div>
      <div><dt>بالاترین قیمت تاریخ</dt><dd><?= View::n(Format::usd($h['ath'])) ?></dd></div>
      <div><dt>سهم از بازار</dt><dd><?= View::n($h['dominance'] !== null ? Format::pct((float) $h['dominance'], false, 1) : null) ?></dd></div>
      <div><dt>ارزش رقیق‌شده</dt><dd><?= View::n(Format::usdCompact($h['fdv'])) ?></dd></div>
    </dl>
  </div>

  <div class="v2-orbit" aria-hidden="true">
    <svg class="v2-orbit__rings" viewBox="0 0 400 400">
      <defs>
        <radialGradient id="v2-glow" cx="50%" cy="50%" r="50%">
          <stop offset="0" stop-color="var(--v2-accent)" stop-opacity=".35"/>
          <stop offset="1" stop-color="var(--v2-accent)" stop-opacity="0"/>
        </radialGradient>
      </defs>
      <circle cx="200" cy="200" r="190" fill="url(#v2-glow)"/>
      <ellipse cx="200" cy="200" rx="185" ry="70" class="v2-orbit__path" transform="rotate(-18 200 200)"/>
      <ellipse cx="200" cy="200" rx="150" ry="150" class="v2-orbit__path v2-orbit__path--faint"/>
      <ellipse cx="200" cy="330" rx="120" ry="20" class="v2-orbit__base"/>
      <ellipse cx="200" cy="330" rx="80" ry="12" class="v2-orbit__base"/>
    </svg>
    <?php if (!empty($c['logo'])) : ?>
      <img class="v2-orbit__logo" src="<?= esc_url($c['logo']) ?>" alt="" width="160" height="160" decoding="async">
    <?php endif; ?>
    <a class="v2-orbit__chip v2-orbit__chip--1" href="#ecosystem" tabindex="-1">اکوسیستم</a>
    <a class="v2-orbit__chip v2-orbit__chip--2" href="#flow" tabindex="-1">جریان زنجیره</a>
    <a class="v2-orbit__chip v2-orbit__chip--3" href="#story" tabindex="-1">داستان</a>
    <a class="v2-orbit__chip v2-orbit__chip--4" href="#dna" tabindex="-1">DNA</a>
  </div>

  <div class="v2-overview__side">
    <div class="v2-card v2-chartcard" data-v2-chart>
      <div class="v2-chartcard__bar">
        <div class="v2-seg" role="group" aria-label="شاخص نمودار">
          <button type="button" class="is-on" data-v2-metric="p" aria-pressed="true">قیمت</button>
          <button type="button" data-v2-metric="v" aria-pressed="false">حجم</button>
          <button type="button" data-v2-metric="m" aria-pressed="false">ارزش بازار</button>
        </div>
        <div class="v2-seg v2-seg--small" role="group" aria-label="بازه زمانی">
          <?php foreach ($ch['windows'] as $period => $label) : ?>
            <button type="button" data-v2-range="<?= esc_attr($period) ?>" class="<?= $period === $ch['default'] ? 'is-on' : '' ?>" aria-pressed="<?= $period === $ch['default'] ? 'true' : 'false' ?>"><?= esc_html($label) ?></button>
          <?php endforeach; ?>
        </div>
      </div>
      <?php if ($ch['line'] !== '') : ?>
        <svg class="v2-chart" viewBox="0 0 600 220" preserveAspectRatio="none" role="img"
             aria-label="نمودار قیمت <?= esc_attr($c['name']) ?> در <?= esc_attr($ch['windows'][$ch['default']] ?? '') ?> گذشته">
          <defs>
            <linearGradient id="v2-area" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0" stop-color="var(--v2-accent)" stop-opacity=".35"/>
              <stop offset="1" stop-color="var(--v2-accent)" stop-opacity="0"/>
            </linearGradient>
          </defs>
          <path class="v2-chart__area" d="<?= esc_attr($ch['area']) ?>" fill="url(#v2-area)" data-v2-area/>
          <path class="v2-chart__line" d="<?= esc_attr($ch['line']) ?>" data-v2-line/>
        </svg>
        <div class="v2-chart__axis">
          <span>کمینه <?= View::n(Format::usd($ch['min']), '') ?></span>
          <span>بیشینه <?= View::n(Format::usd($ch['max'])) ?></span>
        </div>
      <?php else : ?>
        <div class="v2-chart v2-chart--empty"><?= View::na('pending', 'سری قیمت هنوز دریافت نشده') ?></div>
      <?php endif; ?>
    </div>

    <div class="v2-card v2-keymetrics">
      <h2 class="v2-card__title">شاخص‌های کلیدی</h2>
      <ul class="v2-kv">
        <?php foreach ($v['keyMetrics'] as $km) : ?>
          <li>
            <span class="v2-kv__label"><?= esc_html($km['label']) ?></span>
            <?php if ($km['value'] !== null) : ?>
              <span class="v2-kv__value"><?= View::n($km['value']) ?></span>
              <?php if ($km['change'] !== null) : ?>
                <span class="v2-delta v2-delta--<?= View::dir($km['change']) ?>"><?= View::n(Format::pct($km['change'], true, 1)) ?></span>
              <?php endif; ?>
            <?php else : ?>
              <?= View::na('unavailable', $km['note']) ?>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>

</section>
