<?php
/**
 * Coin page — design v2.
 *
 * Served when the site-wide setting says v2, or for one visit with
 * ?thb_design=v2 (see includes/Design.php). The classic template is untouched
 * and still serves every other request.
 *
 * SERVER-RENDERED like the classic page: every figure and every chart is in
 * the first response. v2.js only redraws on interaction and never fetches.
 *
 * Nine screens, in the order of the reference design, each a <section> with a
 * stable id so the section nav — and anyone sharing a link — can jump to it.
 *
 * Child themes can override this file at thehybit/v2/single-coin.php.
 *
 * @package TheHybit\Coins
 */

use TheHybit\Coins\Plugin;

defined('ABSPATH') || exit;

$plugin = Plugin::instance();
$coin = $plugin->coins->find(get_the_ID());

if (!$coin) {
    get_header();
    echo '<div class="thb-v2"><div class="v2-wrap"><p>پیکربندی این ارز کامل نیست.</p></div></div>';
    get_footer();
    return;
}

$v = $plugin->v2($coin);
$parts = THB_COINS_DIR . 'templates/v2/partials/';

$nav = [
    'overview'   => 'نگاه کلی',
    'ecosystem'  => 'اکوسیستم',
    'flow'       => 'جریان زنجیره',
    'story'      => 'داستان',
    'dna'        => 'DNA',
    'price-story' => 'قیمت به‌روایت',
    'pulse'      => 'پالس روزانه',
    'peers'      => 'مقایسه',
    'supply'     => 'تامین و ریسک',
];

get_header();
?>

<?php /* THE THEME'S BOX MUST NOT CHANGE.
   The live theme sizes its page box (header and footer included) from its
   content, like width: fit-content. The classic page's content asks for 1320px
   — its container's max-width — so the box, and the theme header in it, is
   1320px wide. v2 must ask for exactly the same and nothing more:

   - .thb-v2-sizer is an invisible, zero-height block whose natural width is
     what the classic container's is: 1320px at most, and narrow enough to
     wrap on a phone. It is the only thing v2 tells the theme about its size.
   - .thb-v2-frame has `contain: inline-size`, so the full-width layer inside
     it contributes nothing to the box's width — no matter how wide it is.

   The header and footer are never touched or styled. */ ?>
<div class="thb-v2-host">
<div class="thb-v2-sizer" aria-hidden="true"><span></span> <span></span> <span></span> <span></span> <span></span> <span></span> <span></span></div>
<div class="thb-v2-frame">
<div class="thb-v2" data-thb-design="v2">
  <?php /* Runs as soon as it is parsed, before this section paints, so there
     is no narrow first frame. Sets the exact viewport width (100vw would
     include the scrollbar and cause a sideways scroll) and pins the page's
     edges to the viewport's even when the theme's box is off-centre. The
     ResizeObserver catches the scrollbar appearing after fonts and images
     load, which fires no resize event. CSS alone handles the no-JS case. */ ?>
  <script>(function(){var e=document.currentScript.parentNode,d=document.documentElement;function f(){var w=d.clientWidth,b=e.parentNode,p=b.getBoundingClientRect(),c=getComputedStyle(b),l=p.left+parseFloat(c.paddingLeft)+parseFloat(c.borderLeftWidth),r=p.right-parseFloat(c.paddingRight)-parseFloat(c.borderRightWidth);e.style.setProperty('--v2-vw',w+'px');e.style.marginLeft=(-l)+'px';e.style.marginRight=(r-w)+'px';}f();window.ResizeObserver?new ResizeObserver(f).observe(d):addEventListener('resize',f);})();</script>
  <div class="v2-wrap">

    <?php require $parts . 'breadcrumb.php'; ?>

    <?php require $parts . 'overview.php'; ?>

    <nav class="v2-tabs" aria-label="بخش‌های صفحه">
      <ul class="v2-tabs__list">
        <?php foreach ($nav as $id => $label) : ?>
          <li><a class="v2-tabs__link" href="#<?= esc_attr($id) ?>" data-v2-tab="<?= esc_attr($id) ?>"><?= esc_html($label) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </nav>

    <?php
    require $parts . 'ecosystem.php';
    require $parts . 'flow.php';
    require $parts . 'story.php';
    require $parts . 'dna.php';
    require $parts . 'price-story.php';
    require $parts . 'pulse.php';
    require $parts . 'peers.php';
    require $parts . 'supply.php';
    require $parts . 'about.php';
    ?>

  </div>
</div>
</div>
</div>

<?php get_footer(); ?>
