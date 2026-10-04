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
 * Whale Radar stays on the screen as an explicit "no source" card, so the gap
 * is visible rather than silently designed out.
 */
use TheHybit\Coins\Format;
use TheHybit\Coins\V2\View;
defined('ABSPATH') || exit;

$f = $v['flow'];
$c = $v['coin'];
$tones = ['violet', 'blue', 'teal', 'pink', 'slate'];
?>
<section class="v2-section v2-flow" id="flow" aria-labelledby="flow-title">
  <?= View::heading('flow', 'جریان زنجیره ' . $c['name'], 'کارمزدهای ۲۴ ساعت گذشته از کجا آمدند و به کجا رفتند؟') ?>

  <div class="v2-flow__grid">
    <div class="v2-card v2-flow__sankey">
      <?php if ($f['state'] === 'ok') : ?>
        <div class="v2-xscroll" style="--min: 0px">
        <svg viewBox="-20 0 1100 400" role="img" aria-label="نمودار جریان کارمزد <?= esc_attr($c['name']) ?>: مجموع <?= esc_attr(Format::usdCompact($f['fees'])) ?> در ۲۴ ساعت">
          <?php foreach ($f['ribbonsLeft'] as $i => $r) : ?>
            <path class="v2-ribbon v2-t--<?= $tones[$i % 5] ?>" d="<?= esc_attr($r['d']) ?>"/>
            <rect class="v2-flow__node v2-t--<?= $tones[$i % 5] ?>" x="138" y="<?= $r['y'] ?>" width="12" height="<?= $r['h'] ?>" rx="3"/>
            <text class="v2-flow__label v2-fa" x="128" y="<?= $r['y'] + $r['h'] / 2 - 2 ?>" text-anchor="start"><?= esc_html($f['left'][$i]['label']) ?></text>
            <text class="v2-flow__pct" x="128" y="<?= $r['y'] + $r['h'] / 2 + 18 ?>" text-anchor="end"><?= esc_html(Format::pct($f['left'][$i]['share'] * 100, false, 1)) ?></text>
          <?php endforeach; ?>
          <?php foreach ($f['ribbonsRight'] as $i => $r) : ?>
            <path class="v2-ribbon v2-t--<?= $i === 0 ? 'green' : 'teal' ?>" d="<?= esc_attr($r['d']) ?>"/>
            <rect class="v2-flow__node v2-t--<?= $i === 0 ? 'green' : 'teal' ?>" x="850" y="<?= $r['y'] ?>" width="12" height="<?= $r['h'] ?>" rx="3"/>
            <text class="v2-flow__label v2-fa" x="872" y="<?= $r['y'] + $r['h'] / 2 - 2 ?>" text-anchor="end"><?= esc_html($f['right'][$i]['label']) ?></text>
            <text class="v2-flow__pct" x="872" y="<?= $r['y'] + $r['h'] / 2 + 18 ?>" text-anchor="start"><?= esc_html(Format::pct($f['right'][$i]['share'] * 100, false, 1)) ?></text>
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
          <?php foreach ([['از کجا آمد', $f['left'], $tones], ['به کجا رفت', $f['right'], ['green', 'teal']]] as [$title, $nodes, $tn]) : ?>
            <?php if ($nodes === []) { continue; } ?>
            <h3 class="v2-flow__mtitle"><?= esc_html($title) ?></h3>
            <ul class="v2-flow__mlist">
              <?php foreach ($nodes as $i => $node) : ?>
                <li><span class="v2-swatch v2-t--<?= esc_attr($tn[$i % count($tn)]) ?>" aria-hidden="true"></span><?= esc_html($node['label']) ?>
                  <?= View::n(Format::pct($node['share'] * 100, false, 1)) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endforeach; ?>
        </div>
        <?php if ($f['right'] === []) : ?>
          <p class="v2-muted">سمت راست نمودار (سهم درآمد پروتکل‌ها) هنوز دریافت نشده است؛ سمت چپ کامل است.</p>
        <?php endif; ?>
        <div class="v2-flow__legend">
          <span>چپ: کارمزد بر اساس نوع پروتکل</span>
          <span>راست: سهم درآمد پروتکل‌ها در برابر تأمین‌کنندگان سرمایه</span>
        </div>
        <?= View::source('DefiLlama') ?>
      <?php else : ?>
        <?= View::na($f['state'] === 'disabled' ? 'disabled' : ($f['state'] === 'pending' ? 'pending' : 'unavailable'),
            $f['state'] === 'disabled' ? 'داده دیفای برای این ارز خاموش است' : 'تفکیک کارمزد این زنجیره هنوز در دسترس نیست') ?>
      <?php endif; ?>
    </div>

    <aside class="v2-flow__side">
      <div class="v2-card">
        <h3 class="v2-card__title">نبض شبکه</h3>
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

      <?php $w = $f['whales']; ?>
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
          <p class="v2-source">انتقال‌های بومی <?= esc_html($c['symbol']) ?> (نه توکن‌ها). نام صاحب کیف پول‌ها در منبع رایگانی منتشر نمی‌شود و حدس زده نمی‌شود. منبع: <bdi>Blockchair</bdi></p>
        <?php elseif ($w['state'] === 'ok' || $w['state'] === 'stale') : ?>
          <p class="v2-muted">در بازه اخیر انتقالی بالاتر از <?= View::n(Format::num((float) $w['min'], 0) . ' ' . $c['symbol']) ?> ثبت نشده است.</p>
        <?php else : ?>
          <?= View::na($w['state'], 'فهرست انتقال‌های بزرگ از Blockchair') ?>
        <?php endif; ?>
      </div>
    </aside>
  </div>

  <?php if ($f['cards'] !== []) : ?>
    <ul class="v2-facts">
      <?php foreach ($f['cards'] as $card) : ?>
        <li class="v2-card"><span><?= esc_html($card['text']) ?></span> <strong><?= View::n($card['value']) ?></strong></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
