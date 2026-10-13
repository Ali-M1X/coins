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
        <svg viewBox="0 0 700 490" role="img" aria-label="نمودار حبابی: ارزش بازار در برابر <?= $p['hasTvl'] ? 'ارزش قفل‌شده' : 'حجم معاملات' ?>؛ اندازه حباب حجم ۲۴ ساعته">
        <rect class="v2-bubble__plot" x="80" y="20" width="610" height="420" rx="10"/>
        <?php foreach ($p['ticks']['y'] ?? [] as $tk) : ?>
          <line class="v2-bubble__grid" x1="80" y1="<?= $tk['at'] ?>" x2="690" y2="<?= $tk['at'] ?>"/>
          <text class="v2-bubble__tick" x="72" y="<?= $tk['at'] + 4 ?>" text-anchor="end"><?= esc_html($tk['label']) ?></text>
        <?php endforeach; ?>
        <?php foreach (['T' => 'tvl', 'V' => 'vol'] as $ax => $key) : ?>
          <g data-v2-xticks="<?= $key ?>"<?= ($p['hasTvl'] ? 'tvl' : 'vol') === $key ? '' : ' hidden' ?>>
            <?php foreach ($p['ticks'][$ax] ?? [] as $tk) : ?>
              <line class="v2-bubble__grid" x1="<?= $tk['at'] ?>" y1="20" x2="<?= $tk['at'] ?>" y2="440"/>
              <text class="v2-bubble__tick" x="<?= $tk['at'] ?>" y="458" text-anchor="middle"><?= esc_html($tk['label']) ?></text>
            <?php endforeach; ?>
          </g>
        <?php endforeach; ?>
        <text class="v2-axis v2-fa" x="385" y="484" text-anchor="middle" data-v2-xlabel><?= $p['hasTvl'] ? 'ارزش قفل‌شده در دیفای شبکه (مقیاس لگاریتمی)' : 'حجم معاملات ۲۴ ساعته (مقیاس لگاریتمی)' ?></text>
        <text class="v2-axis v2-fa" x="16" y="230" text-anchor="middle" transform="rotate(-90 16 230)">ارزش بازار (لگاریتمی)</text>
        <?php foreach ($plot as $i => $r) :
            $ax = $p['hasTvl'] ? 'T' : 'V';
            $x = $r['x' . $ax];
            // A peer with no TVL still has a volume: kept, hidden on the TVL axis.
            $hide = $x === null;
            $x ??= $r['xV'] ?? 80;
            $y = $r['y' . $ax] ?? $r['y']; ?>
          <g class="v2-bubble__pt<?= $r['self'] ? ' is-self' : '' ?>" data-v2-peer="<?= $i ?>" transform="translate(<?= $x ?> <?= $y ?>)"<?= $hide ? ' hidden' : '' ?>>
            <circle r="<?= $r['r'] ?>"/>
            <text y="4" text-anchor="middle"><?= esc_html($r['symbol']) ?></text>
            <title><?= esc_html($r['name'] . ' — ارزش بازار ' . Format::usdCompact($r['marketCap']) . ($r['tvl'] !== null ? '، ارزش قفل‌شده ' . Format::usdCompact($r['tvl']) : '') . ($r['volume'] !== null ? '، حجم ۲۴ ساعته ' . Format::usdCompact($r['volume']) : '')) ?></title>
          </g>
        <?php endforeach; ?>
      </svg>
        </div>
      <p class="v2-bubble__legend"><span class="v2-bubble__key v2-bubble__key--self" aria-hidden="true"></span><?= esc_html($c['name']) ?>
        <span class="v2-bubble__key" aria-hidden="true"></span>همتایان · اندازه حباب = حجم معاملات ۲۴ ساعته</p>
      <?php if ($p['insight']) : ?>
        <div class="v2-callout v2-peers__insight">
          <h3 class="v2-peers__insight-title">نسبت ارزش قفل‌شده به ارزش بازار یعنی چه؟</h3>
          <p><?= esc_html($p['insight']) ?></p>
        </div>
      <?php endif; ?>
    </div>

    <div class="v2-card v2-peers__table">
      <h3 class="v2-card__title">مقایسه با همتایان</h3>
      <div class="v2-scroll">
        <table class="v2-table v2-table--compare">
          <thead>
            <tr><th scope="col"><span class="v2-sr">شاخص</span></th>
              <?php foreach ($p['table'] as $r) : ?>
                <th scope="col" class="<?= $r['self'] ? 'is-self' : '' ?>"><?php if (!empty($r['url'])) : ?><a class="v2-peers__link" href="<?= esc_url($r['url']) ?>"><bdi><?= esc_html($r['symbol']) ?></bdi></a><?php else : ?><bdi><?= esc_html($r['symbol']) ?></bdi><?php endif; ?></th>
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
      <p class="v2-muted">علاقه جست‌وجو و فعالیت توسعه برای همه همتایان به‌صورت یکسان اندازه‌گیری‌پذیر نیست و نمایش داده نمی‌شود.</p>
      <?php $more = array_values(array_filter($p['rows'], static fn($r) => !empty($r['url']))); ?>
      <?php if ($more !== []) : ?>
        <p class="v2-peers__more">صفحه همتایان: <?php foreach ($more as $k => $r) : ?><?= $k ? '، ' : '' ?><a href="<?= esc_url($r['url']) ?>"><?= esc_html($r['name']) ?></a><?php endforeach; ?></p>
      <?php endif; ?>

    </div>
  </div>
  <?php endif; ?>
</section>
