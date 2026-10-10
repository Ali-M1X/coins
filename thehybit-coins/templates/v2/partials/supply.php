<?php
/**
 * Screen 9 — supply & real risk. Reference: 10.png.
 *
 * WHAT IS MEASURED: the staked share and the cumulative burn (Etherscan's
 * supply endpoint), the staking APR (beaconcha.in), distance from the
 * all-time high, 30-day volatility and the 90-day correlation with Bitcoin
 * (CoinGecko series), venue concentration (CoinGecko tickers).
 *
 * WHAT IS NOT: how much sits in long-term wallets. Coin age has no free
 * source this server can reach, so that row is not drawn at all. A slice
 * whose source is merely late is listed by name as unavailable. Real yield
 * needs an inflation rate, which needs two supply readings far enough apart;
 * until the history table has them, only the APR is shown.
 */
use TheHybit\Coins\Format;
use TheHybit\Coins\V2\View;
defined('ABSPATH') || exit;

$s = $v['supply'];
$c = $v['coin'];
?>
<section class="v2-section v2-supply" id="supply" aria-labelledby="supply-title">
  <?= View::heading('supply', 'تامین و ریسک واقعی', 'چه بخشی از عرضه کجاست، و این دارایی امروز چقدر پرریسک است') ?>

  <div class="v2-supply__grid">
    <div class="v2-card v2-dist">
      <h3 class="v2-card__title">توزیع عرضه</h3>
      <?php if ($s['slices'] !== []) : ?>
        <ul class="v2-dist__bars">
          <?php foreach ($s['slices'] as $sl) : ?>
            <li>
              <span class="v2-dist__label"><?= esc_html($sl['label']) ?></span>
              <span class="v2-dist__track"><span class="v2-dist__fill v2-t--<?= esc_attr($sl['tone']) ?>" style="--w: <?= round(min(100, max(0.5, $sl['pct'])), 2) ?>%"></span></span>
              <span class="v2-dist__pct"><?= View::n(Format::pct($sl['pct'], false, 1)) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else : ?>
        <?= View::na($s['state'], $s['state'] === 'disabled' ? 'کلید Etherscan در تنظیمات افزونه وارد نشده است' : null) ?>
      <?php endif; ?>
      <?php if ($s['missing'] !== []) : ?>
        <ul class="v2-dist__missing">
          <?php foreach ($s['missing'] as $m) : ?>
            <li><span><?= esc_html($m[0]) ?></span> <?= View::na('unavailable', $m[1]) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <p class="v2-muted">هر نوار سهم جداگانه‌ای از کل عرضه است و جمع آن‌ها ۱۰۰ نیست — یک سکه می‌تواند هم در استیکینگ باشد و هم در WETH. منابع: beaconcha.in (استیکینگ) و Etherscan (سوزاندن، WETH، پل‌ها، کیف پول‌های صرافی‌ها).</p>
    </div>

    <div class="v2-card v2-yield">
      <h3 class="v2-card__title">بازده واقعی</h3>
      <?php if ($s['apr'] !== null) : ?>
        <p class="v2-yield__eq">بازده استیکینگ <?= View::n(Format::pct($s['apr'], false, 2)) ?>
          − تورم خالص عرضه <?= $s['inflation'] !== null ? View::n(Format::pct($s['inflation'], true, 2)) : '<span class="v2-muted">؟</span>' ?></p>
        <?php if ($s['realYield'] !== null) : ?>
          <p class="v2-yield__big"><?= View::n(Format::pct($s['realYield'], false, 2)) ?></p>
          <p class="v2-muted">بازده واقعی سالانه = بازده استیکینگ منهای تغییر سالانه‌شده عرضه در ۷ روز گذشته (انتشار منهای سوزاندن، <bdi><?= esc_html((string) $s['inflationSource']) ?></bdi>)</p>
        <?php else : ?>
          <p class="v2-yield__big"><?= View::n(Format::pct($s['apr'], false, 2)) ?></p>
          <p class="v2-muted">بازده استیکینگ؛ بازده واقعی وقتی محاسبه می‌شود که دو اندازه‌گیری عرضه با دست‌کم ۶ روز فاصله ثبت شده باشد.</p>
        <?php endif; ?>
        <p class="v2-source">بازده: <?= esc_html((string) $s['aprSource']) ?><?= $s['validators'] ? ' · ' . View::n(Format::num((float) $s['validators'], 0)) . ' اعتبارسنج' : '' ?></p>
        <?php if ($s['aprKind'] === 'formula') : ?>
          <p class="v2-muted">این عدد اندازه‌گیری نیست: حداکثر بازده لایه اجماع است که از فرمول پروتکل و کل اتر سپرده‌شده محاسبه شده؛ کارمزد اولویت و MEV در آن نیست.</p>
        <?php endif; ?>
      <?php elseif ($s['inflation'] !== null) : ?>
        <p class="v2-yield__eq">این شبکه استیکینگ ندارد؛ تورم سالانه عرضه:</p>
        <p class="v2-yield__big"><?= View::n(Format::pct($s['inflation'], true, 2)) ?></p>
        <p class="v2-muted">تغییر سالانه‌شده عرضه در ۷ روز گذشته · <bdi><?= esc_html((string) $s['inflationSource']) ?></bdi></p>
      <?php else : ?>
        <?= View::na($s['aprState'] === 'not_applicable' ? 'not_applicable' : 'pending', $s['aprState'] === 'not_applicable' ? 'این شبکه استیکینگ ندارد' : 'بازده استیکینگ هنوز از هیچ منبعی نرسیده است') ?>
      <?php endif; ?>
    </div>

    <div class="v2-card v2-cockpit">
      <h3 class="v2-card__title">کابین ریسک</h3>
      <ul class="v2-cockpit__list">
        <?php foreach ($s['risk'] as $r) : ?>
          <li>
            <div>
              <span class="v2-cockpit__label"><?= esc_html($r['label']) ?></span>
              <?php if ($r['value'] !== null) : ?>
                <span class="v2-cockpit__value"><?= preg_match('/\p{Arabic}/u', $r['value']) ? esc_html($r['value']) : View::n($r['value']) ?></span>
                <?php if (!empty($r['detail'])) : ?><span class="v2-muted"><?= esc_html($r['detail']) ?></span><?php endif; ?>
              <?php else : ?>
                <?= View::na('unavailable', $r['note']) ?>
              <?php endif; ?>
            </div>
            <?php if ($r['toneFa']) : ?><span class="v2-pill v2-pill--<?= esc_attr($r['tone']) ?>"><?= esc_html($r['toneFa']) ?></span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>

  <div class="v2-card v2-audience">
    <h3 class="v2-card__title">این کوین برای کیست؟</h3>
    <?php if ($s['audience'] !== []) : ?>
      <ul class="v2-audience__list">
        <?php foreach ($s['audience'] as $i => $a) : ?>
          <li>
            <span class="v2-audience__icon v2-t--<?= ['amber', 'blue', 'teal'][$i % 3] ?>" aria-hidden="true"><?= esc_html(mb_substr($a['who'], 0, 1)) ?></span>
            <div>
              <h4><?= esc_html($a['who']) ?></h4>
              <p>تناسب: <strong><?= esc_html($a['fit']) ?></strong></p>
              <?php if ($a['text'] !== '') : ?><p class="v2-muted"><?= esc_html($a['text']) ?></p><?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="v2-muted">نظر تحریریه <?= esc_html($v['brand']) ?>؛ بر پایه داده نیست و توصیه سرمایه‌گذاری هم نیست.</p>
    <?php else : ?>
      <?= View::na('unavailable', 'این بخش هنوز توسط تحریریه نوشته نشده است') ?>
    <?php endif; ?>
  </div>
</section>
