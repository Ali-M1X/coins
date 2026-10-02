<?php
/** §3 Price chart. Exactly the five approved periods. */
defined('ABSPATH') || exit;
?>
<section class="thb-card thb-chart-card" aria-labelledby="thb-chart-title">
  <h2 class="thb-sr-only" id="thb-chart-title">نمودار قیمت <?= esc_html($coin->name) ?></h2>

  <noscript>
    <p class="thb-noscript">نمایش نمودار به جاوااسکریپت نیاز دارد. سایر اطلاعات این صفحه بدون آن نیز در دسترس است.</p>
  </noscript>

  <div class="thb-chart-card__interactive">
    <div class="thb-card__head">
      <div class="thb-segmented" role="group" aria-label="انتخاب معیار نمودار">
        <button type="button" data-thb-metric="price" aria-pressed="true">قیمت</button>
        <button type="button" data-thb-metric="mcap" aria-pressed="false">ارزش بازار</button>
      </div>
      <div class="thb-segmented thb-segmented--ltr" role="group" aria-label="انتخاب بازه زمانی">
        <?php foreach (['24h' => '24H', '7d' => '7D', '30d' => '30D', '90d' => '90D', '1y' => '1Y'] as $key => $label) : ?>
          <button type="button" data-thb-period="<?= esc_attr($key) ?>" aria-pressed="<?= $key === '24h' ? 'true' : 'false' ?>"><?= esc_html($label) ?></button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="thb-chart" data-thb-chart></div>
    <div class="thb-sr-only" data-thb-chart-table></div>
  </div>
</section>
