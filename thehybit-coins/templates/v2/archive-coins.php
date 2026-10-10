<?php
/**
 * The coin list — /coins/ and /coins/page/N/. The post type's archive.
 *
 * SERVER-RENDERED, like the coin pages: the whole table is in the first
 * response, and a page render only reads the cache (includes/Listing.php),
 * never a provider. 100 coins a page; the next hundred are a real link, so
 * every page is crawlable and nothing is loaded that is not shown.
 *
 * The same visual language as the v2 coin page: v2.css provides the tokens,
 * fonts and the theme-box breakout; list.css adds the table. The theme's
 * header and footer are left exactly as they are.
 *
 * Child themes can override this file at thehybit/v2/archive-coins.php.
 *
 * @package TheHybit\Coins
 */

use TheHybit\Coins\Format;
use TheHybit\Coins\Listing;
use TheHybit\Coins\Plugin;
use TheHybit\Coins\V2\Svg;
use TheHybit\Coins\V2\View;

defined('ABSPATH') || exit;

$plugin = Plugin::instance();
$L = $plugin->listing->view(Listing::currentPage());
$base = trailingslashit((string) (get_post_type_archive_link(\TheHybit\Coins\CoinRepository::POST_TYPE) ?: home_url('/coins/')));
$pageUrl = static fn(int $n): string => $n <= 1 ? $base : $base . 'page/' . $n . '/';
$from = ($L['page'] - 1) * $L['perPage'] + 1;
$to = $from + $L['perPage'] - 1;
$market = $L['market7d'];

get_header();
?>
<div class="thb-v2-host">
<div class="thb-v2-sizer" aria-hidden="true"><span></span> <span></span> <span></span> <span></span> <span></span> <span></span> <span></span></div>
<div class="thb-v2-frame">
<div class="thb-v2 thb-list" data-thb-design="v2">
  <script>(function(){var e=document.currentScript.parentNode,d=document.documentElement;function f(){var w=d.clientWidth,b=e.parentNode,p=b.getBoundingClientRect(),c=getComputedStyle(b),l=p.left+parseFloat(c.paddingLeft)+parseFloat(c.borderLeftWidth),r=p.right-parseFloat(c.paddingRight)-parseFloat(c.borderRightWidth);e.style.setProperty('--v2-vw',w+'px');e.style.marginLeft=(-l)+'px';e.style.marginRight=(r-w)+'px';}f();window.ResizeObserver?new ResizeObserver(f).observe(d):addEventListener('resize',f);})();</script>
  <div class="v2-wrap">

    <nav class="v2-crumbs" aria-label="مسیر صفحه">
      <a href="<?= esc_url(home_url('/')) ?>">خانه</a>
      <span aria-hidden="true">‹</span>
      <?php if ($L['page'] > 1) : ?>
        <a href="<?= esc_url($base) ?>">ارزها</a>
        <span aria-hidden="true">‹</span>
        <span aria-current="page">صفحه <?= View::n((string) $L['page']) ?></span>
      <?php else : ?>
        <span aria-current="page">ارزها</span>
      <?php endif; ?>
    </nav>

    <header class="list-head">
      <h1 class="list-head__title">قیمت ارزهای دیجیتال<?= $L['page'] > 1 ? ' — صفحه ' . View::n((string) $L['page']) : ' امروز' ?></h1>
      <p class="list-head__sub">ارزهای رتبه <?= View::n(Format::num((float) $from, 0)) ?> تا <?= View::n(Format::num((float) $to, 0)) ?> بر اساس ارزش بازار، به دلار.</p>
      <ul class="list-stats">
        <li class="list-stat">
          <span class="list-stat__label">بازار در ۷ روز اخیر</span>
          <?php if ($market !== null) : ?>
            <span class="list-stat__value v2-delta--<?= View::dir($market) ?>"><?= View::n(Format::pct($market, true, 1)) ?></span>
            <span class="list-stat__note">میانگین وزنی ۱۰۰ ارز بزرگ (وزن: ارزش بازار)</span>
          <?php else : ?>
            <?= View::na('pending') ?>
          <?php endif; ?>
        </li>
        <li class="list-stat">
          <span class="list-stat__label">آخرین به‌روزرسانی</span>
          <?php if ($L['fetchedAt']) : ?>
            <span class="list-stat__value"><?= View::n(Format::tehranTime($L['fetchedAt'])) ?></span>
            <span class="list-stat__note">به وقت تهران · CoinGecko<?= $L['page'] === 1 ? ' · هر ۵ دقیقه' : ' · هر ۳۰ دقیقه' ?></span>
          <?php else : ?>
            <?= View::na('pending') ?>
          <?php endif; ?>
        </li>
      </ul>
    </header>

    <section class="v2-card list-card" aria-labelledby="list-table-title">
      <h2 class="list-card__title" id="list-table-title">جدول قیمت — رتبه <?= View::n(Format::num((float) $from, 0)) ?> تا <?= View::n(Format::num((float) $to, 0)) ?></h2>

      <?php if ($L['rows'] === []) : ?>
        <?= View::na('pending', 'فهرست ارزها چند دقیقه پس از نصب از CoinGecko دریافت می‌شود.') ?>
      <?php else : ?>
        <table class="list-table">
          <caption class="screen-reader-text">قیمت، تغییرات، ارزش بازار و حجم معاملات ارزهای دیجیتال رتبه <?= esc_html((string) $from) ?> تا <?= esc_html((string) $to) ?></caption>
          <thead>
            <tr>
              <th scope="col" class="c-rank">#</th>
              <th scope="col" class="c-coin">ارز</th>
              <th scope="col" class="c-num c-price">قیمت</th>
              <th scope="col" class="c-num c-24h">۲۴ ساعت</th>
              <th scope="col" class="c-num c-7d">۷ روز</th>
              <th scope="col" class="c-vs"><abbr title="تغییر ۷ روزه این ارز منهای تغییر ۷ روزه کل بازار (۱۰۰ ارز بزرگ، وزنی). مثبت یعنی این هفته از بازار جلو زده است.">در برابر بازار</abbr></th>
              <th scope="col" class="c-num c-cap">ارزش بازار</th>
              <th scope="col" class="c-num c-vol">حجم ۲۴ ساعته</th>
              <th scope="col" class="c-spark">۷ روز اخیر</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($L['rows'] as $r) :
                $name = $r['nameFa'] ?: $r['name'];
                $vs = $r['vsMarket'];
                $spark = Svg::line(Svg::clean((array) $r['sparkline']), 100, 32, 2);
                $sparkDir = View::dir($r['change7d']); ?>
              <tr<?= $r['url'] ? ' class="is-ours"' : '' ?>>
                <td class="c-rank"><?= View::n((string) ($r['rank'] ?? $r['position'])) ?></td>
                <th scope="row" class="c-coin">
                  <span class="list-coin">
                    <?php if ($r['logo']) : ?>
                      <img class="list-coin__logo" src="<?= esc_url($r['logo']) ?>" alt="" width="28" height="28" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="this.style.visibility='hidden'">
                    <?php else : ?>
                      <span class="list-coin__logo list-coin__logo--none" aria-hidden="true"><?= esc_html(mb_substr($r['symbol'], 0, 1)) ?></span>
                    <?php endif; ?>
                    <span class="list-coin__text">
                      <?php if ($r['url']) : ?>
                        <a class="list-coin__name" href="<?= esc_url($r['url']) ?>"><?= esc_html($name) ?></a>
                      <?php else : ?>
                        <span class="list-coin__name"><?= esc_html($name) ?></span>
                      <?php endif; ?>
                      <bdi class="list-coin__sym" dir="ltr"><?= esc_html($r['symbol']) ?></bdi>
                    </span>
                  </span>
                </th>
                <td class="c-num c-price"><?= View::n(Format::usd($r['price'])) ?><span class="list-sub v2-delta--<?= View::dir($r['change24h']) ?>"><?= View::n(Format::pct($r['change24h'], true, 2)) ?></span></td>
                <td class="c-num c-24h v2-delta--<?= View::dir($r['change24h']) ?>"><?= View::n(Format::pct($r['change24h'], true, 2)) ?></td>
                <td class="c-num c-7d v2-delta--<?= View::dir($r['change7d']) ?>"><?= View::n(Format::pct($r['change7d'], true, 2)) ?></td>
                <td class="c-vs">
                  <?php if ($vs !== null) : ?>
                    <span class="list-vs list-vs--<?= View::dir($vs) ?>" title="<?= esc_attr(sprintf('۷ روز: این ارز %s، بازار %s', Format::pct($r['change7d'], true, 1), Format::pct($market, true, 1))) ?>">
                      <?= View::n(Format::pct($vs, true, 1)) ?>
                      <span class="list-vs__word"><?= $vs > 0 ? 'جلوتر' : ($vs < 0 ? 'عقب‌تر' : 'هم‌پا') ?></span>
                    </span>
                  <?php else : ?>
                    <span class="v2-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="c-num c-cap"><?= View::n(Format::usdCompact($r['marketCap'])) ?></td>
                <td class="c-num c-vol"><?= View::n(Format::usdCompact($r['volume24h'])) ?></td>
                <td class="c-spark">
                  <?php if ($spark !== '') : ?>
                    <svg class="list-spark list-spark--<?= $sparkDir ?>" viewBox="0 0 100 32" width="100" height="32" aria-hidden="true" focusable="false"><path d="<?= esc_attr($spark) ?>"/></svg>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <?php if ($L['pages'] > 1) : ?>
      <nav class="list-pages" aria-label="صفحه‌های فهرست">
        <?php if ($L['page'] > 1) : ?>
          <a class="list-pages__step" href="<?= esc_url($pageUrl($L['page'] - 1)) ?>" rel="prev">→ صد ارز قبلی</a>
        <?php endif; ?>
        <ol class="list-pages__list">
          <?php for ($n = 1; $n <= $L['pages']; $n++) : ?>
            <li>
              <?php if ($n === $L['page']) : ?>
                <span class="list-pages__link is-current" aria-current="page"><?= View::n((string) $n) ?></span>
              <?php else : ?>
                <a class="list-pages__link" href="<?= esc_url($pageUrl($n)) ?>"><?= View::n((string) $n) ?></a>
              <?php endif; ?>
            </li>
          <?php endfor; ?>
        </ol>
        <?php if ($L['page'] < $L['pages']) : ?>
          <a class="list-pages__step" href="<?= esc_url($pageUrl($L['page'] + 1)) ?>" rel="next">صد ارز بعدی ←</a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>

    <section class="v2-card list-about" aria-labelledby="list-about-title">
      <h2 class="list-card__title" id="list-about-title">ستون «در برابر بازار» چیست؟</h2>
      <p>تغییر ۷ روزه هر ارز منهای تغییر ۷ روزه کل بازار. «بازار» یعنی ۱۰۰ ارز بزرگ، هر کدام به اندازه ارزش بازارش. عدد مثبت یعنی این ارز در هفته گذشته از بازار جلو زده و عدد منفی یعنی عقب مانده؛ مستقل از اینکه کل بازار بالا رفته یا پایین آمده. این عدد از همان داده‌های جدول محاسبه می‌شود و پیش‌بینی یا توصیه خرید نیست.</p>
      <p class="v2-muted">داده‌ها از CoinGecko دریافت و در سرور <?= esc_html(\TheHybit\Coins\V2\Model::brand()) ?> ذخیره می‌شوند. ارزهایی که نامشان پیوند دارد، صفحه کامل تحلیل در <?= esc_html(\TheHybit\Coins\V2\Model::brand()) ?> دارند.</p>
    </section>

  </div>
</div>
</div>
</div>
<?php
get_footer();
