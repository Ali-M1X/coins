<?php
/**
 * Screen 3 — chain flow. Reference: 4.png.
 *
 * WHAT THE REFERENCE SHOWS AND WHAT THIS SHOWS
 *
 * The reference draws ETH moving between exchanges, whales and protocols.
 * No free source publishes those flows — they need labelled-address data
 * (Nansen, Arkham, Glassnode), all paid — so they are not drawn. The Sankey
 * here is a flow that IS published: the fees users paid on this chain in the
 * last 24 hours, split by the kind of protocol that earned them (left), and by
 * who keeps them (right) — the protocols' own revenue versus the liquidity
 * providers, lenders and stakers who supplied the capital. Both sides come
 * from DefiLlama and both add up to the same total.
 *
 * Each part is drawn only for coins a source exists for (Model::applies()):
 * a chain DefiLlama has no fee data for gets the network pulse and whale
 * radar alone, under the heading «شبکه»; a token with none of the three has
 * no section at all.
 */
use TheHybit\Coins\Format;
use TheHybit\Coins\V2\View;
defined('ABSPATH') || exit;

$f = $v['flow'];
$c = $v['coin'];
$tones = ['violet', 'blue', 'teal', 'pink', 'slate'];
?>
<section class="v2-section v2-flow" id="flow" aria-labelledby="flow-title">
  <?php if ($f['showSankey']) : ?>
    <?= View::heading('flow', 'جریان زنجیره ' . $c['name'], 'توزیع کارمزدهای پرداخت‌شده در ۲۴ ساعت گذشته بر حسب نوع پروتکل، و سهم هر یک از دریافت‌کنندگان نهایی آن.') ?>
  <?php else : ?>
    <?= View::heading('flow', 'شبکه ' . $c['name'], 'شاخص‌های فعالیت شبکه و انتقال‌های بزرگ ثبت‌شده روی زنجیره.') ?>
  <?php endif; ?>

  <?php $hasSide = $f['pulse'] !== [] || $f['showWhales']; ?>
  <div class="v2-flow__grid<?= $f['showSankey'] && $hasSide ? '' : ' v2-flow__grid--nosankey' ?>">
    <?php if ($f['showSankey']) : ?>
    <div class="v2-card v2-flow__sankey">
      <?php if ($f['state'] === 'ok') : ?>
        <div class="v2-xscroll" style="--min: 0px">
        <?php /* Right to left, as a Persian reader reads: fees ENTER on the
           right (by protocol type), pass through the chain, and LEAVE on the
           left (who keeps them). Column titles and an arrowhead at every
           ribbon's arriving end make the direction explicit. */ ?>
        <svg viewBox="-20 -50 1100 450" role="img" aria-label="جریان کارمزد <?= esc_attr($c['name']) ?> از راست به چپ: <?= esc_attr(implode('، ', array_map(static fn($n) => $n['label'] . ' ' . Format::pct($n['share'] * 100, false, 1), $f['left']))) ?> وارد شبکه شد (مجموع <?= esc_attr(Format::usdCompact($f['fees'])) ?>) و به <?= esc_attr(implode('، ', array_map(static fn($n) => $n['label'] . ' ' . Format::pct($n['share'] * 100, false, 1), $f['right']))) ?> رسید">
          <text class="v2-flow__head v2-fa" x="856" y="-20" text-anchor="middle">ورودی</text>
          <text class="v2-flow__head v2-fa" x="144" y="-20" text-anchor="middle">خروجی</text>
          <text class="v2-flow__dir" x="500" y="-20" text-anchor="middle">◀ ─────── جهت جریان ─────── ◀</text>
          <?php foreach ($f['ribbonsSources'] as $i => $r) : $mid = $r['cy'] + $r['ch'] / 2; ?>
            <path class="v2-ribbon v2-t--<?= $tones[$i % 5] ?>" d="<?= esc_attr($r['d']) ?>"/>
            <polygon class="v2-flow__arrow v2-t--<?= $tones[$i % 5] ?>" points="<?= 598 ?>,<?= $mid - 7 ?> <?= 598 ?>,<?= $mid + 7 ?> 584,<?= $mid ?>"/>
            <rect class="v2-flow__node v2-t--<?= $tones[$i % 5] ?>" x="850" y="<?= $r['y'] ?>" width="12" height="<?= $r['h'] ?>" rx="3"/>
            <?php /* 2.5.0: name, share AND amount inside the band, at its outer end where it is full height. */ ?>
            <text class="v2-flow__label v2-fa" x="836" y="<?= $r['y'] + $r['h'] / 2 - 4 ?>" text-anchor="start"><?= esc_html($f['left'][$i]['label']) ?></text>
            <text class="v2-flow__pct" x="836" y="<?= $r['y'] + $r['h'] / 2 + 15 ?>" text-anchor="end"><?= esc_html(Format::pct($f['left'][$i]['share'] * 100, false, 1) . ' · ' . Format::usdCompact((float) $f['left'][$i]['value'], 1)) ?></text>
          <?php endforeach; ?>
          <?php foreach ($f['ribbonsDest'] as $i => $r) : $mid = $r['y'] + $r['h'] / 2; ?>
            <path class="v2-ribbon v2-t--<?= $i === 0 ? 'green' : 'teal' ?>" d="<?= esc_attr($r['d']) ?>"/>
            <polygon class="v2-flow__arrow v2-t--<?= $i === 0 ? 'green' : 'teal' ?>" points="166,<?= $mid - 8 ?> 166,<?= $mid + 8 ?> 152,<?= $mid ?>"/>
            <rect class="v2-flow__node v2-t--<?= $i === 0 ? 'green' : 'teal' ?>" x="138" y="<?= $r['y'] ?>" width="12" height="<?= $r['h'] ?>" rx="3"/>
            <text class="v2-flow__label v2-fa" x="180" y="<?= $r['y'] + $r['h'] / 2 - 4 ?>" text-anchor="end"><?= esc_html($f['right'][$i]['label']) ?></text>
            <text class="v2-flow__pct" x="180" y="<?= $r['y'] + $r['h'] / 2 + 16 ?>" text-anchor="start"><?= esc_html(Format::pct($f['right'][$i]['share'] * 100, false, 1) . ' · ' . Format::usdCompact((float) $f['right'][$i]['value'], 1)) ?></text>
          <?php endforeach; ?>
          <rect class="v2-flow__core" x="420" y="130" width="160" height="140" rx="24"/>
          <?php if (!empty($c['logo'])) : ?>
            <image href="<?= esc_url($c['logo']) ?>" x="470" y="146" width="60" height="60"/>
          <?php endif; ?>
          <text class="v2-flow__total" x="500" y="236" text-anchor="middle"><?= esc_html(Format::usdCompact($f['fees'], 1)) ?></text>
          <text class="v2-flow__sub v2-fa" x="500" y="256" text-anchor="middle">کارمزد ۲۴ ساعته</text>
        </svg>
        </div>
        <?php /* Phone layout: the diagram fits the card and its labels move into
           this list, where they can be read at full size. */ ?>
        <div class="v2-flow__mobile">
          <?php foreach ([['ورودی', $f['left'], $tones], ['خروجی', $f['right'], ['green', 'teal']]] as [$title, $nodes, $tn]) : ?>
            <?php if ($nodes === []) { continue; } ?>
            <h3 class="v2-flow__mtitle"><?= esc_html($title) ?></h3>
            <ul class="v2-flow__mlist">
              <?php foreach ($nodes as $i => $node) : ?>
                <li><span class="v2-swatch v2-t--<?= esc_attr($tn[$i % count($tn)]) ?>" aria-hidden="true"></span><?= esc_html($node['label']) ?>
                  <?= View::n(Format::pct($node['share'] * 100, false, 1) . ' · ' . Format::usdCompact((float) $node['value'], 1)) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endforeach; ?>
        </div>
        <?php if ($f['right'] === []) : ?>
          <p class="v2-muted">تفکیک خروجی (سهم درآمد پروتکل‌ها) هنوز در دسترس نیست؛ بخش ورودی کامل است.</p>
        <?php endif; ?>
        <div class="v2-flow__legend">
          <span>ورودی: کارمزد پرداخت‌شده کاربران به تفکیک نوع پروتکل.</span>
          <span>خروجی: سهم درآمد خود پروتکل‌ها و سهم تأمین‌کنندگان سرمایه (نقدینگی‌دهندگان، وام‌دهندگان و سپرده‌گذاران).</span>
        </div>
      <?php else : ?>
        <?= View::na($f['state'] === 'disabled' ? 'disabled' : ($f['state'] === 'pending' ? 'pending' : 'unavailable'),
            $f['state'] === 'disabled' ? 'داده دیفای برای این ارز خاموش است' : 'تفکیک کارمزد این زنجیره هنوز در دسترس نیست') ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($hasSide) : ?>
    <aside class="v2-flow__side">
      <?php if ($f['pulse'] !== []) : ?>
      <div class="v2-card">
        <h3 class="v2-card__title">نبض شبکه</h3>
        <?php if (!empty($f['chainPulse'])) : ?>
          <p class="v2-muted v2-kv__intro">فعالیت اقتصادی روی شبکه <?= esc_html($c['name']) ?>؛ آمار فنی (تعداد تراکنش و زمان بلوک) برای این شبکه اندازه‌گیری نمی‌شود.</p>
        <?php endif; ?>
        <ul class="v2-kv">
          <?php foreach ($f['pulse'] as $p) : ?>
            <li>
              <span class="v2-kv__label"><?= esc_html($p['label']) ?></span>
              <?php if ($p['value'] !== null) : ?>
                <span class="v2-kv__value"><?= View::n($p['value']) ?></span>
              <?php else : ?>
                <?= View::na($p['state'], $p['note'] ?? null) ?>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($f['healthy'] === true) : ?>
          <p class="v2-badge v2-badge--ok">شبکه سالم · مشارکت اعتبارسنج‌ها <?= View::n(Format::pct((float) $f['participation'], false, 1)) ?></p>
        <?php elseif ($f['healthy'] === false) : ?>
          <p class="v2-badge v2-badge--warn">مشارکت اعتبارسنج‌ها <?= View::n(Format::pct((float) $f['participation'], false, 1)) ?></p>
        <?php endif; ?>
      </div>

      <?php endif; ?>

      <?php $w = $f['whales']; ?>
      <?php if ($f['showWhales']) : ?>
      <div class="v2-card v2-whales">
        <h3 class="v2-card__title">رادار نهنگ‌ها</h3>
        <?php if ($w['rows'] !== []) : ?>
          <p class="v2-muted">آخرین انتقال‌های دست‌کم <?= View::n(Format::num((float) $w['min'], 0) . ' ' . $c['symbol']) ?> روی زنجیره</p>
          <ul class="v2-whales__list">
            <?php foreach ($w['rows'] as $row) : ?>
              <li>
                <a href="<?= esc_url($row['url']) ?>" rel="nofollow noopener" target="_blank">
                  <strong><?= View::n($row['amount']) ?></strong>
                  <?php if ($row['usd']) : ?><span class="v2-muted"><?= View::n($row['usd']) ?></span><?php endif; ?>
                  <?php if ($row['from'] && $row['to']) : ?>
                    <span class="v2-whales__route"><?= View::n($row['from'] . ' → ' . $row['to']) ?></span>
                  <?php endif; ?>
                  <?php if ($row['time']) : ?><span class="v2-whales__time"><?= View::n($row['time']) ?></span><?php endif; ?>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
          <p class="v2-source">فقط انتقال‌های خودِ <?= esc_html($c['symbol']) ?> (نه توکن‌های روی این شبکه). صاحبان کیف پول‌ها شناسایی نمی‌شوند.</p>
        <?php elseif ($w['state'] === 'ok' || $w['state'] === 'stale') : ?>
          <p class="v2-muted">در بازه اخیر انتقالی بالاتر از <?= View::n(Format::num((float) $w['min'], 0) . ' ' . $c['symbol']) ?> ثبت نشده است.</p>
        <?php else : ?>
          <?= View::na($w['state']) ?>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </aside>
    <?php endif; ?>
  </div>

  <?php if ($f['cards'] !== []) : ?>
    <ul class="v2-facts">
      <?php foreach ($f['cards'] as $card) : ?>
        <li class="v2-card"><span><?= esc_html($card['text']) ?></span> <strong><?= View::n($card['value']) ?></strong></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
