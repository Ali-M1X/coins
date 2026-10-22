<?php
/**
 * /coins/compare/ — two or three coins side by side (2.11.0).
 *
 * Without coins: a picker and a few starting points. With ?c=a,b[,c]: the
 * comparison table, from the same cache-only models as the coin pages
 * (V2\Compare). Every comparison is noindex with its canonical here
 * (includes/Tools.php) — no thin page per pair.
 *
 * The picker is a plain GET form (c1, c2, c3); compare.js turns it into the
 * short shareable ?c=a,b address. Child themes can override this file at
 * thehybit/v2/compare.php.
 *
 * @package TheHybit\Coins
 */

use TheHybit\Coins\Plugin;
use TheHybit\Coins\V2\Compare;
use TheHybit\Coins\V2\View;

defined('ABSPATH') || exit;

$plugin = Plugin::instance();
$slugs = Compare::requested($plugin->coins);
$choices = Compare::choices($plugin->coins);
$bySlug = [];
foreach ($plugin->coins->all() as $c) { $bySlug[$c->slug] = $c; }
$table = count($slugs) >= 2 ? (new Compare($plugin))->build(array_map(static fn($s) => $bySlug[$s], $slugs)) : null;
$base = trailingslashit((string) (get_post_type_archive_link(\TheHybit\Coins\CoinRepository::POST_TYPE) ?: home_url('/coins/')));
$starters = array_values(array_filter([['bitcoin', 'ethereum'], ['ethereum', 'solana'], ['tether', 'usdc'], ['bitcoin', 'ethereum', 'solana'], ['dogecoin', 'shiba-inu'], ['tether-gold', 'pax-gold']],
    static fn($p) => array_diff($p, array_keys($bySlug)) === []));

get_header();
?>
<div class="thb-v2-host">
<div class="thb-v2-sizer" aria-hidden="true"><span></span> <span></span> <span></span> <span></span> <span></span> <span></span> <span></span></div>
<div class="thb-v2-frame">
<div class="thb-v2 thb-list thb-tool" data-thb-design="v2">
  <script>(function(){var e=document.currentScript.parentNode,d=document.documentElement;function f(){var w=d.clientWidth,b=e.parentNode,p=b.getBoundingClientRect(),c=getComputedStyle(b),l=p.left+parseFloat(c.paddingLeft)+parseFloat(c.borderLeftWidth),r=p.right-parseFloat(c.paddingRight)-parseFloat(c.borderRightWidth);e.style.setProperty('--v2-vw',w+'px');e.style.marginLeft=(-l)+'px';e.style.marginRight=(r-w)+'px';}f();window.ResizeObserver?new ResizeObserver(f).observe(d):addEventListener('resize',f);})();</script>
  <div class="v2-wrap">

    <nav class="v2-crumbs" aria-label="مسیر صفحه">
      <a href="<?= esc_url(home_url('/')) ?>">خانه</a>
      <span aria-hidden="true">‹</span>
      <a href="<?= esc_url($base) ?>">ارزها</a>
      <span aria-hidden="true">‹</span>
      <span aria-current="page">مقایسه</span>
    </nav>

    <header class="list-head">
      <?php if ($table) : ?>
        <h1 class="list-head__title">مقایسه <?= esc_html(implode(' و ', array_column($table['cols'], 'name'))) ?></h1>
      <?php else : ?>
        <h1 class="list-head__title">مقایسه ارزهای دیجیتال</h1>
      <?php endif; ?>
      <p class="list-head__sub">دو یا سه ارز را انتخاب کنید؛ هر عدد همان است که در صفحه خود آن ارز آمده، و جایی که داده واقعی نداریم خط تیره می‌بینید.</p>
    </header>

    <form class="v2-card cmp-pick" action="<?= esc_url(Compare::url([])) ?>" method="get" data-cmp-form>
      <?php for ($i = 0; $i < Compare::MAX; $i++) : $sel = $slugs[$i] ?? ''; ?>
        <div class="cmp-pick__field">
          <label for="cmp-c<?= $i + 1 ?>">ارز <?= ['اول', 'دوم', 'سوم'][$i] ?><?= $i === 2 ? ' (اختیاری)' : '' ?></label>
          <select class="v2-conv__select" id="cmp-c<?= $i + 1 ?>" name="c<?= $i + 1 ?>">
            <option value=""><?= $i === 2 ? '— بدون ارز سوم —' : '— انتخاب کنید —' ?></option>
            <?php foreach ($choices as $ch) : ?>
              <option value="<?= esc_attr($ch['slug']) ?>"<?= $ch['slug'] === $sel ? ' selected' : '' ?>><?= esc_html($ch['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endfor; ?>
      <button class="list-search__go cmp-pick__go" type="submit">مقایسه کن</button>
      <p class="cmp-pick__note" role="status" data-cmp-note></p>
    </form>

    <?php if ($table) : ?>
      <section class="v2-card cmp" aria-labelledby="cmp-title" style="--n: <?= count($table['cols']) ?>">
        <h2 class="list-card__title" id="cmp-title">کنار هم</h2>
        <table class="cmp__table">
          <caption class="screen-reader-text">مقایسه <?= esc_html(implode('، ', array_column($table['cols'], 'name'))) ?></caption>
          <thead>
            <tr>
              <td class="cmp__corner"></td>
              <?php foreach ($table['cols'] as $col) : ?>
                <th scope="col" class="cmp__coin">
                  <a href="<?= esc_url($col['url']) ?>"><?= esc_html($col['name']) ?></a>
                  <bdi class="list-coin__sym" dir="ltr"><?= esc_html($col['symbol']) ?></bdi>
                </th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <?php foreach ($table['groups'] as $g) : ?>
            <tbody>
              <tr class="cmp__group"><th scope="colgroup" colspan="<?= count($table['cols']) + 1 ?>"><?= esc_html($g['title']) ?></th></tr>
              <?php foreach ($g['rows'] as $r) : ?>
                <tr class="cmp__row">
                  <th scope="row" class="cmp__label"><?= esc_html($r['label']) ?><?php if ($r['note']) : ?><span class="cmp__note"><?= esc_html($r['note']) ?></span><?php endif; ?></th>
                  <?php foreach ($r['cells'] as $i => $cell) : ?>
                    <td class="cmp__cell<?= $cell === '—' ? ' is-empty' : '' ?>" data-coin="<?= esc_attr($table['cols'][$i]['symbol']) ?>"><?= View::n($cell) ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          <?php endforeach; ?>
        </table>
        <p class="v2-muted cmp__foot">داده‌ها همان داده‌های صفحه هر ارز است. این مقایسه توصیه خرید یا فروش نیست.</p>
        <p class="cmp__share"><span>نشانی این مقایسه:</span> <a href="<?= esc_url(Compare::url($slugs)) ?>"><bdi dir="ltr"><?= esc_html(rawurldecode(Compare::url($slugs))) ?></bdi></a></p>
      </section>
    <?php elseif (count($slugs) === 1) : ?>
      <div class="v2-card"><p>یک ارز دیگر انتخاب کنید تا مقایسه نمایش داده شود.</p></div>
    <?php endif; ?>

    <?php if ($starters !== []) : ?>
      <section class="v2-card list-about" aria-labelledby="cmp-start-title">
        <h2 class="list-card__title" id="cmp-start-title">مقایسه‌های پرکاربرد</h2>
        <ul class="cmp-start">
          <?php foreach ($starters as $p) : ?>
            <li><a class="list-tools__link" href="<?= esc_url(Compare::url($p)) ?>"><?= esc_html(implode(' در برابر ', array_map(static fn($s) => $bySlug[$s]->name, $p))) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>

  </div>
</div>
</div>
</div>
<?php
get_footer();
