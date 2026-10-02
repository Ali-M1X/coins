<?php
/** §4 Performance, plus all-time high and low. */
use TheHybit\Coins\Format;
defined('ABSPATH') || exit;

$perf = $model['performance'] ?? [];
$labels = ['1h' => '1 ساعت', '24h' => '24 ساعت', '7d' => '7 روز', '30d' => '30 روز', '90d' => '90 روز', '1y' => '1 سال'];
$ath = $model['market']['ath'] ?? null;
$atl = $model['market']['atl'] ?? null;
?>
<section class="thb-card thb-perf" aria-labelledby="thb-perf-title">
  <div class="thb-card__head"><h2 class="thb-card__title" id="thb-perf-title">عملکرد</h2></div>

  <div class="thb-card__body">
    <div class="thb-perf__rows">
      <?php foreach ($labels as $key => $label) :
        $v = $perf[$key] ?? null; ?>
        <div class="thb-perf__row">
          <span class="thb-perf__label"><?= esc_html($label) ?></span>
          <span class="thb-change" data-dir="<?= esc_attr(Format::direction($v)) ?>">
            <span class="thb-change__arrow" aria-hidden="true"><?= Format::arrow($v) ?></span><span><?= esc_html(Format::pct($v)) ?></span>
          </span>
        </div>
      <?php endforeach; ?>
    </div>

    <?php
    /* Market behaviour — volatility, drawdown and relative strength.
       All four are computed from data already cached: the 7d/30d chart series,
       the ATH record, and the benchmark coin's own cached market dataset. */
    $d = $model['derived'] ?? [];
    $behaviour = [];
    foreach ([
        'volatility30d'          => 'نوسان 30 روزه',
        'volatility7d'           => 'نوسان 7 روزه',
        'drawdownFromAth'        => 'فاصله از سقف',
        'relativeToBenchmark30d' => 'نسبت به ' . ($model['benchmark']['symbol'] ?? 'BTC') . ' (30 روز)',
    ] as $key => $label) {
        if (!empty($d[$key]['available'])) {
            $behaviour[] = [$label, (float) $d[$key]['value'], !empty($d[$key]['signed'])];
        }
    }
    ?>

    <?php if ($behaviour) : ?>
      <hr class="thb-divider">
      <div class="thb-perf__rows">
        <?php foreach ($behaviour as [$label, $value, $signed]) : ?>
          <div class="thb-perf__row">
            <span class="thb-perf__label"><?= esc_html($label) ?></span>
            <?php if ($signed) : ?>
              <span class="thb-change" data-dir="<?= esc_attr(Format::direction($value)) ?>">
                <span class="thb-change__arrow" aria-hidden="true"><?= Format::arrow($value) ?></span><span><?= esc_html(Format::pct($value)) ?></span>
              </span>
            <?php else : ?>
              <span class="thb-perf__value"><span class="thb-num"><?= esc_html(Format::pct($value, false)) ?></span></span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($ath || $atl) : ?>
      <hr class="thb-divider">

      <?php foreach ([['بالاترین قیمت تاریخی', $ath], ['پایین‌ترین قیمت تاریخی', $atl]] as [$title, $rec]) :
        if (!$rec) { continue; } ?>
        <div class="thb-extreme">
          <div class="thb-extreme__top">
            <span class="thb-extreme__label"><?= esc_html($title) ?></span>
            <span class="thb-extreme__value"><span class="thb-num"><?= esc_html(Format::usd($rec['price'] ?? null)) ?></span></span>
          </div>
          <div class="thb-extreme__sub">
            <span><?= esc_html(Format::gregorianFa($rec['date'] ?? null)) ?></span>
            <span class="thb-change" data-dir="<?= esc_attr(Format::direction($rec['changePct'] ?? null)) ?>">
              <span class="thb-change__arrow" aria-hidden="true"><?= Format::arrow($rec['changePct'] ?? null) ?></span><span><?= esc_html(Format::pctSmart($rec['changePct'] ?? null)) ?></span>
            </span>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>
