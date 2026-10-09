<?php
/**
 * About, FAQ and sources — the reading matter search engines index, and the
 * provenance a reader can check. Admin-editable: description and FAQ come from
 * the coin's ACF fields.
 */
use TheHybit\Coins\Format;
use TheHybit\Coins\V2\View;
defined('ABSPATH') || exit;

$a = $v['about'];
$c = $v['coin'];
?>
<section class="v2-section v2-about" id="about" aria-labelledby="about-title">
  <?= View::heading('about', 'درباره ' . $c['name']) ?>
  <div class="v2-about__grid">
    <div class="v2-card">
      <?php if (!empty($a['description'])) : ?>
        <p class="v2-about__text"><?= esc_html($a['description']) ?></p>
      <?php endif; ?>
      <dl class="v2-about__facts">
        <?php if (!empty($a['founder'])) : ?><div><dt>بنیان‌گذار</dt><dd><?= esc_html($a['founder']) ?></dd></div><?php endif; ?>
        <?php if (!empty($a['launchDate'])) : ?><div><dt>راه‌اندازی</dt><dd><?= esc_html(Format::gregorianFa($a['launchDate'])) ?></dd></div><?php endif; ?>
        <?php if (!empty($a['consensus'])) : ?><div><dt>اجماع</dt><dd><?= esc_html($a['consensus']) ?></dd></div><?php endif; ?>
        <?php if (!empty($a['website'])) : ?><div><dt>وب‌سایت</dt><dd><a href="<?= esc_url($a['website']) ?>" rel="nofollow noopener" target="_blank"><bdi><?= esc_html(preg_replace('#^https?://#', '', rtrim($a['website'], '/'))) ?></bdi></a></dd></div><?php endif; ?>
      </dl>
    </div>

    <?php if ($v['faq'] !== []) : ?>
      <div class="v2-card v2-faq">
        <h3 class="v2-card__title">پرسش‌های متداول</h3>
        <?php foreach ($v['faq'] as [$q, $ans]) : ?>
          <details>
            <summary><?= esc_html($q) ?></summary>
            <p><?= esc_html($ans) ?></p>
          </details>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php $fetched = array_values(array_filter($v['sources'], static fn($r) => $r['time'] !== null)); ?>
  <?php if ($fetched !== []) : ?>
    <details class="v2-card v2-provenance">
      <summary>منابع داده و زمان آخرین به‌روزرسانی (<?= View::n((string) count($fetched)) ?> منبع)</summary>
      <p class="v2-muted">هر عدد این صفحه از کدام منبع آمده و آخرین بار چه ساعتی (به وقت تهران) دریافت شده است — برای وقتی که می‌خواهید تازگی یک عدد را بسنجید.</p>
      <ol class="v2-sources">
        <?php foreach ($fetched as $src) : ?>
          <li><span class="v2-sources__time"><?= View::n($src['time']) ?></span><span><?= esc_html($src['what']) ?> · <bdi><?= esc_html($src['who']) ?></bdi></span></li>
        <?php endforeach; ?>
      </ol>
    </details>
  <?php endif; ?>

  <p class="v2-note">داده‌ها از CoinGecko، DefiLlama، Kraken، Etherscan، beaconcha.in، Blockchair، CoinMetrics، Lido، GitHub، ویکی‌پدیا و Alternative.me دریافت و در سرور <?= esc_html($v['brand']) ?> ذخیره می‌شوند؛ مرورگر شما مستقیماً با هیچ‌کدام تماس نمی‌گیرد. جایی که داده‌ای منبع معتبر ندارد، همین را می‌نویسیم و عددی نمی‌سازیم.</p>
</section>
