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
$cat = Listing::currentCategory();
$q = \TheHybit\Coins\Search::currentQuery();
$L = $plugin->listing->view(Listing::currentPage(), $cat, $q);
$S = $L['search'];
$ourSlug = [];   // 2.11.0: coingecko id → our slug, for the compare links
foreach ($plugin->coins->all() as $oc) { $ourSlug[$oc->coingeckoId] = $oc->slug; }
$base = trailingslashit((string) (get_post_type_archive_link(\TheHybit\Coins\CoinRepository::POST_TYPE) ?: home_url('/coins/')));
$pageUrl = static fn(int $n): string => $n <= 1 ? $base : $base . 'page/' . $n . '/';
$catUrl = static function (?string $key) use ($base, $q): string {
    $args = array_filter(['cat' => $key, 'q' => $q !== '' ? $q : null]);
    return $args === [] ? $base : add_query_arg(array_map('rawurlencode', $args), $base);
};
/* 2.13.0: the subtitle counts what the cache actually holds — the real total
   and the positions of the first and last rows shown — never page × 100. */
$T = $plugin->listing->stats();
$shown = array_values(array_filter(array_column($L['rows'], 'position')));
$from = $shown !== [] ? min($shown) : null;
$to = $shown !== [] ? max($shown) : null;
$market = $L['market7d'];
$fa = static fn(float $n, int $d = 0): string => View::n(Format::num($n, $d));
$pct = static fn(?float $v, int $d = 1): string => View::n(Format::pct($v, true, $d));
$liveConfig = $plugin->config;

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
      <?php if ($S) : ?>
        <h1 class="list-head__title">جستجوی «<?= esc_html($S['query']) ?>» در ارزها</h1>
        <p class="list-head__sub">در نام فارسی، نام انگلیسی و نماد همه ارزهای فهرست<?= $L['category'] ? ' — فقط دسته «' . esc_html($L['category']['fa']) . '»' : '' ?>.</p>
      <?php elseif ($L['category']) : ?>
        <h1 class="list-head__title">قیمت ارزهای دسته «<?= esc_html($L['category']['fa']) ?>»</h1>
        <p class="list-head__sub">ارزهایی که در <?= esc_html(\TheHybit\Coins\V2\Model::brand()) ?> صفحه دارند و در این دسته‌اند، به ترتیب ارزش بازار.</p>
      <?php else : ?>
        <h1 class="list-head__title">قیمت ارزهای دیجیتال<?= $L['page'] > 1 ? ' — صفحه ' . View::n((string) $L['page']) : ' امروز' ?></h1>
        <?php if ($T['total'] > 0 && $from !== null) : ?>
          <p class="list-head__sub" data-list-sub><?= $fa((float) $T['total']) ?> ارز به ترتیب ارزش بازار، به دلار؛ نمایش رتبه <?= $fa((float) $from) ?> تا <?= $fa((float) $to) ?></p>
        <?php endif; ?>
      <?php endif; ?>
      <?php
      /* 2.13.0: compact figures, all from entries the scheduler already
         keeps. A box whose data is missing is not drawn at all. */
      $boxes = [];
      if ($T['cap']) {
          $boxes[] = ['label' => 'ارزش کل بازار', 'value' => View::n(Format::usdCompact($T['cap']['usd'])),
                      'delta' => $T['cap']['change'], 'note' => $T['cap']['change'] !== null ? '۲۴ ساعت' : null];
      }
      if ($T['volume'] !== null) {
          $boxes[] = ['label' => 'حجم معاملات ۲۴ ساعته', 'value' => View::n(Format::usdCompact($T['volume']))];
      }
      if ($T['shares'] !== []) {
          $names = ['BTC' => 'بیت‌کوین', 'ETH' => 'اتریوم', 'stable' => 'استیبل‌کوین‌ها'];
          $lines = [];
          foreach ($T['shares'] as $k => $v) {
              $lines[] = '<span class="list-stat__row"><span>' . esc_html($names[$k]) . '</span>' . View::n(Format::pct($v, false, 1)) . '</span>';
          }
          $boxes[] = ['label' => 'سهم از کل بازار', 'rows' => $lines];
      }
      if ($T['fng']) {
          $boxes[] = ['label' => 'شاخص ترس و طمع', 'value' => $fa((float) $T['fng']['value']) . ' <span class="list-stat__tag">' . esc_html($T['fng']['label']) . '</span>',
                      'meter' => $T['fng']['value']];
      }
      foreach (['gainer' => 'بیشترین رشد ۲۴ ساعته', 'loser' => 'بیشترین افت ۲۴ ساعته'] as $k => $label) {
          if ($T[$k]) {
              $m = $T[$k];
              $boxes[] = ['label' => $label, 'href' => $m['url'], 'delta' => $m['change'], 'deltaBig' => true,
                          'coin' => ($m['logo'] ? '<img class="list-stat__logo" src="' . esc_url($m['logo']) . '" alt="" width="22" height="22" loading="lazy" decoding="async">' : '')
                                    . '<span class="list-stat__coin">' . esc_html($m['name']) . '</span> <bdi class="list-stat__sym" dir="ltr">' . esc_html($m['symbol']) . '</bdi>'];
          }
      }
      if ($market !== null) {
          $boxes[] = ['label' => 'بازار در ۷ روز اخیر', 'delta' => $market, 'deltaBig' => true, 'note' => 'میانگین وزنی ۱۰۰ ارز بزرگ'];
      }
      if ($T['catBest'] && $T['catWorst']) {
          $lines = [];
          foreach (['catBest' => 'قوی‌ترین', 'catWorst' => 'ضعیف‌ترین'] as $k => $tag) {
              $c = $T[$k];
              $lines[] = '<span class="list-stat__row"><a href="' . esc_url($catUrl($c['key'])) . '">' . esc_html($tag . ': ' . $c['fa']) . '</a><span class="v2-delta--' . View::dir($c['change']) . '">' . $pct($c['change']) . '</span></span>';
          }
          $boxes[] = ['label' => 'دسته‌ها در ۲۴ ساعت', 'rows' => $lines];
      }
      ?>
      <?php if ($boxes !== []) : ?>
        <ul class="list-stats" aria-label="نمای کلی بازار">
          <?php foreach ($boxes as $bx) : ?>
            <li class="list-stat<?= isset($bx['href']) ? ' list-stat--link' : '' ?>">
              <?php if (isset($bx['href'])) : ?><a class="list-stat__hit" href="<?= esc_url($bx['href']) ?>"><?php endif; ?>
              <span class="list-stat__label"><?= esc_html($bx['label']) ?></span>
              <?php if (isset($bx['coin'])) : ?><span class="list-stat__who"><?= $bx['coin'] ?></span><?php endif; ?>
              <?php if (isset($bx['value'])) : ?><span class="list-stat__value"><?= $bx['value'] ?></span><?php endif; ?>
              <?php if (isset($bx['rows'])) : ?><span class="list-stat__rows"><?= implode('', $bx['rows']) ?></span><?php endif; ?>
              <?php if (isset($bx['delta']) && $bx['delta'] !== null) : ?>
                <span class="<?= !empty($bx['deltaBig']) ? 'list-stat__value ' : 'list-stat__delta ' ?>v2-delta--<?= View::dir($bx['delta']) ?>"><?= $pct($bx['delta'], !empty($bx['deltaBig']) ? 1 : 2) ?><?= !empty($bx['note']) && empty($bx['deltaBig']) ? ' <span class="list-stat__note">' . esc_html($bx['note']) . '</span>' : '' ?></span>
              <?php endif; ?>
              <?php if (!empty($bx['deltaBig']) && !empty($bx['note'])) : ?><span class="list-stat__note"><?= esc_html($bx['note']) ?></span><?php endif; ?>
              <?php if (isset($bx['meter'])) : ?><span class="list-stat__meter" aria-hidden="true"><i style="inset-inline-start: <?= (int) $bx['meter'] ?>%"></i></span><?php endif; ?>
              <?php if (isset($bx['href'])) : ?></a><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </header>

    <?php /* 2.8.0: filter by asset category — plain links, so every view
       works without script; category views are noindex (Design::applies). */ ?>
    <nav class="list-tools" aria-label="ابزارها">
      <a class="list-tools__link" href="<?= esc_url(\TheHybit\Coins\Tools::url('convert')) ?>">مبدل قیمت ارز به دلار و تومان</a>
      <a class="list-tools__link" href="<?= esc_url(\TheHybit\Coins\V2\Compare::url([])) ?>">مقایسه ارزها</a>
    </nav>

    <?php /* 2.9.0: search every coin of the list. A plain GET form — it works
       without script (/coins/?q=…, server-rendered, noindex); with script,
       list.js answers as you type from an index fetched once. */ ?>
    <form class="list-search" role="search" action="<?= esc_url($base) ?>" method="get" data-list-search
          data-index="<?= esc_url(\TheHybit\Coins\Search::url()) ?>"
          data-cats="<?= esc_attr(wp_json_encode(array_map(static fn($c) => $c['fa'], \TheHybit\Coins\Categories::all()), JSON_UNESCAPED_UNICODE)) ?>"<?= $cat !== null ? ' data-cat="' . esc_attr($cat) . '"' : '' ?>>
      <label class="list-search__label" for="list-q">جستجوی ارز</label>
      <div class="list-search__row">
        <input class="list-search__input" id="list-q" name="q" type="search" value="<?= esc_attr($q) ?>" maxlength="40"
               placeholder="نام یا نماد، مثلاً بیت‌کوین یا BTC" autocomplete="off" spellcheck="false" enterkeyhint="search"
               aria-describedby="list-q-hint" aria-controls="list-results">
        <?php if ($cat !== null) : ?><input type="hidden" name="cat" value="<?= esc_attr($cat) ?>"><?php endif; ?>
        <button class="list-search__go" type="submit">جستجو</button>
      </div>
      <p class="list-search__hint" id="list-q-hint">در همه <?= View::n(Format::num((float) ($T['total'] ?: $L['perPage'] * $plugin->listing->pages()), 0)) ?> ارز فهرست، با نام فارسی، انگلیسی یا نماد<?= $cat !== null ? ' — در همین دسته' : '' ?>.</p>
    </form>
    <div class="v2-card list-results" id="list-results" data-list-results hidden>
      <p class="list-results__status" role="status" aria-live="polite" data-list-status></p>
      <table class="list-table list-table--results" data-list-table hidden>
        <caption class="screen-reader-text">نتایج جستجو</caption>
        <thead><tr>
          <th scope="col" class="c-rank">#</th><th scope="col" class="c-coin">ارز</th>
          <th scope="col" class="c-num c-price">قیمت</th><th scope="col" class="c-num c-24h">۲۴ ساعت</th>
        </tr></thead>
        <tbody data-list-rows></tbody>
      </table>
    </div>

    <?php if ($L['counts'] !== []) : ?>
      <nav class="list-cats" aria-label="فیلتر بر اساس دسته">
        <span class="list-cats__label">دسته:</span>
        <a class="list-cats__item<?= $cat === null ? ' is-current' : '' ?>" href="<?= esc_url($catUrl(null)) ?>"<?= $cat === null ? ' aria-current="page"' : '' ?>>همه</a>
        <?php foreach (\TheHybit\Coins\Categories::all() as $key => $lab) : if (empty($L['counts'][$key])) { continue; } ?>
          <a class="list-cats__item<?= $cat === $key ? ' is-current' : '' ?>" href="<?= esc_url($catUrl($key)) ?>"<?= $cat === $key ? ' aria-current="page"' : '' ?>><?= esc_html($lab['fa']) ?> <span class="list-cats__n"><?= View::n((string) $L['counts'][$key]) ?></span></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>

    <section class="v2-card list-card" aria-labelledby="list-table-title" data-list-main>
      <?php if ($S) : ?>
        <h2 class="list-card__title" id="list-table-title"><?= $S['total'] > 0 ? 'نتایج — ' . View::n((string) $S['total']) . ' ارز' : 'نتیجه‌ای پیدا نشد' ?></h2>
        <?php if ($S['relayout']) : ?>
          <p class="list-results__note">نتایج برای «<bdi dir="ltr"><?= esc_html($S['relayout']) ?></bdi>» — همان کلیدها روی صفحه‌کلید انگلیسی.</p>
        <?php endif; ?>
      <?php elseif ($L['category']) : ?>
        <h2 class="list-card__title" id="list-table-title">جدول قیمت — <?= esc_html($L['category']['fa']) ?> (<?= View::n((string) count($L['rows'])) ?> ارز)</h2>
      <?php else : ?>
        <h2 class="list-card__title" id="list-table-title">جدول قیمت — رتبه <?= View::n(Format::num((float) $from, 0)) ?> تا <?= View::n(Format::num((float) $to, 0)) ?></h2>
      <?php endif; ?>

      <?php if ($S && $L['rows'] === []) : ?>
        <p class="list-results__empty">هیچ ارزی با «<?= esc_html($S['query']) ?>» در نام یا نماد پیدا نشد<?= $L['category'] ? ' (در این دسته)' : '' ?>. املای دیگری را امتحان کنید، یا <a href="<?= esc_url($base) ?>">همه ارزها</a> را ببینید.</p>
      <?php elseif ($L['rows'] === []) : ?>
        <?= View::na('pending', 'فهرست ارزها چند دقیقه پس از نصب در دسترس می‌شود.') ?>
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
              <?php /* data-live-*: live.js (2.4.0) replaces the price and 24h change
                 with real WebSocket ticks for coins a feed carries. Volume
                 stays the server's global figure. */ ?>
              <tr<?= !empty($r['rank']) ? ' id="rank-' . (int) $r['rank'] . '"' : '' ?><?= $r['url'] ? ' class="is-ours"' : '' ?><?= \TheHybit\Coins\LiveFeed::covers($liveConfig, (string) $r['id']) ? ' data-live-id="' . esc_attr((string) $r['id']) . '"' : '' ?>>
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
                      <?php if ($r['url'] && !empty($ourSlug[$r['id']])) : ?>
                        <a class="list-coin__cmp" href="<?= esc_url(\TheHybit\Coins\V2\Compare::url([$ourSlug[$r['id']]])) ?>" aria-label="مقایسه <?= esc_attr($name) ?> با ارز دیگر" title="مقایسه با ارز دیگر">⇄</a>
                      <?php endif; ?>
                      <?php if (!empty($r['category'])) : ?>
                        <a class="list-coin__cat" href="<?= esc_url($base . '?cat=' . rawurlencode($r['category']['key'])) ?>"><?= esc_html($r['category']['fa']) ?></a>
                      <?php endif; ?>
                    </span>
                  </span>
                </th>
                <td class="c-num c-price"><span class="list-price" data-live-usd><?= View::n(Format::usd($r['price'])) ?></span><span class="list-sub v2-delta--<?= View::dir($r['change24h']) ?>" data-live-chg><?= View::n(Format::pct($r['change24h'], true, 2)) ?></span></td>
                <td class="c-num c-24h v2-delta--<?= View::dir($r['change24h']) ?>" data-live-chg><?= View::n(Format::pct($r['change24h'], true, 2)) ?></td>
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
      <nav class="list-pages" aria-label="صفحه‌های فهرست" data-list-pages>
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
      <p class="v2-muted">ارزهایی که نامشان پیوند دارد، صفحه کامل تحلیل در <?= esc_html(\TheHybit\Coins\V2\Model::brand()) ?> دارند.</p>
    </section>

  </div>
</div>
</div>
</div>
<?php
get_footer();
