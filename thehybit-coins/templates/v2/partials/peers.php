<?php
/**
 * Screen 8 — peer comparison. Reference: 9.png.
 *
 * One CoinGecko /coins/markets request returns every configured peer (see
 * `peers` in config/providers.php); each chain's TVL comes from the DefiLlama
 * chain list the classic page already caches. Both axes are logarithmic: a
 * linear market-cap axis puts every coin but Bitcoin on the floor.
 *
 * The reference's search-interest and developer-activity rows have no source
 * that covers every peer, so the table carries only columns every row can
 * fill honestly.
 */
use TheHybit\Coins\Format;
use TheHybit\Coins\V2\View;
defined('ABSPATH') || exit;

$p = $v['peers'];
$c = $v['coin'];
$plot = array_values(array_filter($p['rows'], static fn($r) => $r['marketCap'] !== null));
?>
<section class="v2-section v2-peers" id="peers" aria-labelledby="peers-title">
  <?= View::heading('peers', 'مقایسه با همتایان', 'جایگاه ' . $c['name'] . ' میان رمزارزهای بزرگ') ?>

  <?php if ($plot === []) : ?>
    <div class="v2-card"><?= View::na($p['state'], 'داده همتایان هنوز دریافت نشده') ?></div>
  <?php else : ?>
  <div class="v2-peers__grid">
    <div class="v2-card v2-bubble" data-v2-bubble data-axis="<?= $p['hasTvl'] ? 'tvl' : 'vol' ?>">
      <div class="v2-bubble__bar">
        <label class="v2-select">
          <span>محور افقی</span>
          <select data-v2-axis>
            <?php if ($p['hasTvl']) : ?><option value="tvl">ارزش قفل‌شده (TVL)</option><?php endif; ?>
            <option value="vol">حجم ۲۴ ساعته</option>
          </select>
        </label>
      </div>
      <div class="v2-xscroll" style="--min: 560px" data-v2-center>
        <svg viewBox="0 0 700 480" role="img" aria-label="نمودار حبابی: ارزش بازار در برابر <?= $p['hasTvl'] ? 'ارزش قفل‌شده' : 'حجم معاملات' ?>؛ اندازه حباب حجم ۲۴ ساعته">
        <line class="v2-axisline" x1="50" y1="440" x2="680" y2="440"/>
        <line class="v2-axisline" x1="50" y1="20" x2="50" y2="440"/>
        <text class="v2-axis v2-fa" x="365" y="472" text-anchor="middle" data-v2-xlabel><?= $p['hasTvl'] ? 'ارزش قفل‌شده (مقیاس لگاریتمی)' : 'حجم ۲۴ ساعته (مقیاس لگاریتمی)' ?></text>
        <text class="v2-axis v2-fa" x="20" y="230" text-anchor="middle" transform="rotate(-90 20 230)">ارزش بازار (لگاریتمی)</text>
        <?php foreach ($plot as $i => $r) :
            $x = $p['hasTvl'] ? $r['xT'] : $r['xV'];
            // A peer with no TVL still has a volume: kept, hidden on the TVL axis.
            $hide = $x === null;
            $x ??= $r['xV'] ?? 60; ?>
          <g class="v2-bubble__pt<?= $r['self'] ? ' is-self' : '' ?>" data-v2-peer="<?= $i ?>" transform="translate(<?= $x ?> <?= $r['y'] ?>)"<?= $hide ? ' hidden' : '' ?>>
            <circle r="<?= $r['r'] ?>"/>
            <text y="5" text-anchor="middle"><?= esc_html($r['symbol']) ?></text>
            <title><?= esc_html($r['name'] . ' — ارزش بازار ' . Format::usdCompact($r['marketCap']) . ($r['tvl'] !== null ? '، TVL ' . Format::usdCompact($r['tvl']) : '')) ?></title>
          </g>
        <?php endforeach; ?>
      </svg>
        </div>
      <?php if ($p['insight']) : ?><p class="v2-callout"><?= esc_html($p['insight']) ?></p><?php endif; ?>
      <?= View::source($p['source'] . ' · DefiLlama', $p['fetchedAt']) ?>
    </div>

    <div class="v2-card v2-peers__table">
      <h3 class="v2-card__title">مقایسه با همتایان</h3>
      <div class="v2-scroll">
        <table class="v2-table v2-table--compare">
          <thead>
            <tr><th scope="col"><span class="v2-sr">شاخص</span></th>
              <?php foreach ($p['table'] as $r) : ?>
                <th scope="col" class="<?= $r['self'] ? 'is-self' : '' ?>"><bdi><?= esc_html($r['symbol']) ?></bdi></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php
            $rows = [
                'ارزش بازار'          => static fn($r) => Format::usdCompact($r['marketCap'], 1),
                'حجم ۲۴ ساعته'        => static fn($r) => Format::usdCompact($r['volume'], 1),
                'TVL زنجیره'          => static fn($r) => Format::usdCompact($r['tvl'], 1),
                'TVL / ارزش بازار'    => static fn($r) => $r['tvlToMcap'] !== null ? Format::ratio($r['tvlToMcap'], 3) : '—',
                'حجم / ارزش بازار'    => static fn($r) => $r['volToMcap'] !== null ? Format::ratio($r['volToMcap'], 3) : '—',
                'تغییر ۳۰ روزه'       => static fn($r) => Format::pct($r['change30d'], true, 1),
                'دامنه نوسان ۷ روزه'  => static fn($r) => $r['range7d'] !== null ? Format::pct($r['range7d'], false, 1) : '—',
            ];
            foreach ($rows as $label => $fmt) : ?>
              <tr>
                <th scope="row"><?= esc_html($label) ?></th>
                <?php foreach ($p['table'] as $r) : ?>
                  <td class="<?= $r['self'] ? 'is-self' : '' ?>"><?= View::n($fmt($r)) ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="v2-muted">علاقه جست‌وجو و فعالیت توسعه برای همه همتایان منبع یکسان و رایگانی ندارد و نمایش داده نمی‌شود.</p>
    </div>
  </div>
  <?php endif; ?>
</section>
