<?php
/**
 * Screen 2 — the ecosystem graph. Reference: 3.png.
 *
 * Nodes are the protocols on this chain ranked by the fees users paid them in
 * the last 24 hours (DefiLlama). Fees, not TVL, because they measure use; and
 * DefiLlama publishes them per protocol per chain, which TVL by chain-protocol
 * pair it does not do in one request.
 */
use TheHybit\Coins\Format;
use TheHybit\Coins\V2\View;
defined('ABSPATH') || exit;

$e = $v['ecosystem'];
$c = $v['coin'];
$nodes = $e['nodes'];
$present = array_values(array_unique(array_column($nodes, 'group')));
$first = $nodes[0] ?? null;
?>
<section class="v2-section v2-eco" id="ecosystem" aria-labelledby="ecosystem-title">
  <?= View::heading('ecosystem', 'اکوسیستم ' . $c['name'], 'پروتکل‌های این زنجیره به ترتیب کارمزدی که کاربران در ۲۴ ساعت گذشته پرداخته‌اند') ?>

  <?php if ($nodes === []) : ?>
    <div class="v2-card"><?= View::na($e['state'], $e['state'] === 'disabled' ? 'داده دیفای برای این ارز خاموش است' : null) ?></div>
  <?php else : ?>
  <div class="v2-eco__bar">
    <div class="v2-filters" role="group" aria-label="دسته‌بندی">
      <button type="button" class="is-on" data-v2-eco-filter="all" aria-pressed="true">همه</button>
      <?php foreach (\TheHybit\Coins\V2\Model::GROUP_LABELS as $key => $label) : ?>
        <?php if (in_array($key, $present, true)) : ?>
          <button type="button" data-v2-eco-filter="<?= esc_attr($key) ?>" aria-pressed="false"><?= esc_html($label) ?></button>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <button type="button" class="v2-toggle" data-v2-eco-view aria-pressed="false">
      <span class="v2-toggle__knob" aria-hidden="true"></span> نقشه / فهرست
    </button>
  </div>

  <div class="v2-eco__grid">
    <div class="v2-card v2-eco__map" data-v2-eco-map>
      <div class="v2-xscroll" style="--min: 680px" data-v2-center>
        <svg viewBox="0 0 1000 600" role="img" aria-label="نقشه پروتکل‌های <?= esc_attr($c['name']) ?> بر اساس کارمزد ۲۴ ساعته">
        <defs>
          <radialGradient id="v2-core" cx="50%" cy="50%" r="50%">
            <stop offset="0" stop-color="var(--v2-violet)" stop-opacity=".55"/>
            <stop offset="1" stop-color="var(--v2-violet)" stop-opacity="0"/>
          </radialGradient>
        </defs>
        <circle cx="500" cy="290" r="160" fill="url(#v2-core)"/>
        <?php foreach ($nodes as $i => $nd) :
            $next = $nodes[($i + 1) % count($nodes)]; ?>
          <line class="v2-eco__edge v2-eco__edge--faint" x1="<?= $nd['x'] ?>" y1="<?= $nd['y'] ?>" x2="<?= $next['x'] ?>" y2="<?= $next['y'] ?>"/>
          <line class="v2-eco__edge" data-group="<?= esc_attr($nd['group']) ?>" x1="500" y1="290" x2="<?= $nd['x'] ?>" y2="<?= $nd['y'] ?>"/>
        <?php endforeach; ?>
        <circle cx="500" cy="290" r="62" class="v2-eco__core"/>
        <?php if (!empty($c['logo'])) : ?>
          <image href="<?= esc_url($c['logo']) ?>" x="460" y="250" width="80" height="80"/>
        <?php else : ?>
          <text x="500" y="300" text-anchor="middle" class="v2-eco__coretext"><?= esc_html($c['symbol']) ?></text>
        <?php endif; ?>
        <?php foreach ($nodes as $i => $nd) : ?>
          <g class="v2-eco__node v2-g--<?= esc_attr($nd['group']) ?>" data-group="<?= esc_attr($nd['group']) ?>" data-v2-node="<?= $i ?>" tabindex="0" role="button"
             aria-label="<?= esc_attr($nd['name'] . '، ' . $nd['categoryFa'] . '، کارمزد ۲۴ ساعته ' . Format::usdCompact($nd['fees'])) ?>">
            <circle cx="<?= $nd['x'] ?>" cy="<?= $nd['y'] ?>" r="<?= $nd['r'] ?>"/>
            <text x="<?= $nd['x'] ?>" y="<?= $nd['y'] + 5 ?>" text-anchor="middle" class="v2-eco__initial"><?= esc_html(mb_strtoupper(mb_substr($nd['name'], 0, 2))) ?></text>
            <text x="<?= $nd['x'] ?>" y="<?= $nd['y'] + $nd['r'] + 22 ?>" text-anchor="middle" class="v2-eco__label"><?= esc_html($nd['name']) ?></text>
            <text x="<?= $nd['x'] ?>" y="<?= $nd['y'] + $nd['r'] + 42 ?>" text-anchor="middle" class="v2-eco__fig"><?= esc_html(Format::usdCompact($nd['fees'], 1)) ?></text>
          </g>
        <?php endforeach; ?>
      </svg>
        </div>
      <p class="v2-hint">روی هر گره بزنید تا جزئیاتش را ببینید. اندازه گره = کارمزد ۲۴ ساعته.</p>
    </div>

    <div class="v2-card v2-eco__list" data-v2-eco-list hidden>
      <div class="v2-scroll"><table class="v2-table">
        <caption class="v2-sr">پروتکل‌ها بر اساس کارمزد ۲۴ ساعته</caption>
        <thead><tr><th scope="col">پروتکل</th><th scope="col" class="v2-hide-sm">دسته</th><th scope="col">کارمزد ۲۴ساعته</th><th scope="col" class="v2-hide-sm">سهم</th><th scope="col">تغییر ۱ روزه</th></tr></thead>
        <tbody>
          <?php foreach ($nodes as $nd) : ?>
            <tr data-group="<?= esc_attr($nd['group']) ?>">
              <th scope="row"><bdi><?= esc_html($nd['name']) ?></bdi></th>
              <td class="v2-hide-sm"><?= esc_html($nd['categoryFa']) ?></td>
              <td><?= View::n(Format::usdCompact($nd['fees'])) ?></td>
              <td class="v2-hide-sm"><?= View::n($nd['share'] !== null ? Format::pct($nd['share'], false, 1) : null) ?></td>
              <td class="v2-delta--<?= View::dir($nd['change']) ?>"><?= View::n(Format::pct($nd['change'], true, 1)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>

    <aside class="v2-eco__side">
      <div class="v2-card v2-detail" data-v2-detail aria-live="polite">
        <p class="v2-detail__kicker" data-v2-d="categoryFa"><?= esc_html($first['categoryFa']) ?></p>
        <h3 class="v2-detail__name" data-v2-d="name"><bdi><?= esc_html($first['name']) ?></bdi></h3>
        <dl class="v2-detail__dl">
          <div><dt>کارمزد ۲۴ ساعته</dt><dd data-v2-d="fees"><?= View::n(Format::usdCompact($first['fees'])) ?></dd></div>
          <div><dt>سهم از کارمزد فهرست‌شده</dt><dd data-v2-d="share"><?= View::n($first['share'] !== null ? Format::pct($first['share'], false, 1) : null) ?></dd></div>
          <div><dt>تغییر ۱ روزه</dt><dd data-v2-d="change"><?= View::n(Format::pct($first['change'], true, 1)) ?></dd></div>
        </dl>
      </div>

      <div class="v2-card">
        <h3 class="v2-card__title">ارزش کل قفل‌شده</h3>
        <?php if ($e['tvl'] !== null) : ?>
          <p class="v2-big"><?= View::n(Format::usdCompact($e['tvl'])) ?>
            <span class="v2-delta v2-delta--<?= View::dir($e['tvlChange7d']) ?>"><?= View::n(Format::pct($e['tvlChange7d'], true, 1)) ?></span> <small>۷ روز</small></p>
          <?php if ($e['tvlLine'] !== '') : ?>
            <svg class="v2-spark" viewBox="0 0 300 90" preserveAspectRatio="none" aria-hidden="true">
              <path class="v2-spark__area" d="<?= esc_attr($e['tvlArea']) ?>"/>
              <path class="v2-spark__line" d="<?= esc_attr($e['tvlLine']) ?>"/>
            </svg>
          <?php endif; ?>
        <?php else : ?>
          <?= View::na('unavailable') ?>
        <?php endif; ?>
      </div>

      <div class="v2-card">
        <h3 class="v2-card__title">کارمزد ۱۰ پروتکل برتر</h3>
        <svg class="v2-bars" viewBox="0 0 300 110" preserveAspectRatio="none" aria-hidden="true">
          <?php foreach ($e['bars'] as $b) : ?>
            <rect x="<?= $b['x'] ?>" y="<?= $b['y'] ?>" width="<?= $b['w'] ?>" height="<?= $b['h'] ?>" rx="2"/>
          <?php endforeach; ?>
        </svg>
        <p class="v2-muted">مجموع: <?= View::n(Format::usdCompact($e['totalFees'])) ?> در ۲۴ ساعت</p>
      </div>
      <?= View::source($e['source'], $e['fetchedAt']) ?>
    </aside>
  </div>
  <?php endif; ?>
</section>
