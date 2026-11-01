<?php
/**
 * A pillar page (2.16.0): /coins/<coin>/<pillar>/ — e.g. «آموزش اتریوم».
 *
 * Intro (the editor's, per coin + pillar), the pillar navigation, featured
 * then newest articles, and the FAQ. Served only when the pillar has content
 * (Hub::route() answers 404 otherwise). Reads WordPress data and the cache
 * only.
 *
 * Child themes can override this file at thehybit/v2/pillar.php.
 *
 * @package TheHybit\Coins
 */

use TheHybit\Coins\Hub;
use TheHybit\Coins\Plugin;

defined('ABSPATH') || exit;

$plugin = Plugin::instance();
$hub = $plugin->hub;
$blocks = $plugin->blocks;
[$coinSlug, $pillar] = $hub->currentPillar() ?? Hub::requested();
$coin = $hub->coin($coinSlug);
$intro = $hub->intro($coinSlug, $pillar);
$featured = $hub->featured($coinSlug, $pillar);
$featuredIds = array_map(static fn($p) => (int) $p->ID, $featured);
$latest = $hub->articles($coinSlug, $pillar, 24, $featuredIds);
$faq = $hub->faq($coinSlug, $pillar);
$trail = $hub->trail($coinSlug, $pillar);
$siblings = array_values(array_diff($hub->pillarsWithContent($coinSlug), [$pillar]));

get_header();
?>
<div class="thb-v2-host">
<div class="thb-v2-sizer" aria-hidden="true"><span></span> <span></span> <span></span> <span></span> <span></span> <span></span> <span></span></div>
<div class="thb-v2-frame">
<div class="thb-v2 thb-hub" data-thb-design="v2">
  <script>(function(){var e=document.currentScript.parentNode,d=document.documentElement;function f(){var w=d.clientWidth,b=e.parentNode,p=b.getBoundingClientRect(),c=getComputedStyle(b),l=p.left+parseFloat(c.paddingLeft)+parseFloat(c.borderLeftWidth),r=p.right-parseFloat(c.paddingRight)-parseFloat(c.borderRightWidth);e.style.setProperty('--v2-vw',w+'px');e.style.marginLeft=(-l)+'px';e.style.marginRight=(r-w)+'px';}f();window.ResizeObserver?new ResizeObserver(f).observe(d):addEventListener('resize',f);})();</script>
  <div class="v2-wrap hub-wrap">

    <nav class="v2-crumbs" aria-label="مسیر صفحه">
      <?php foreach ($trail as $i => $t) : ?>
        <?php if ($i > 0) : ?><span aria-hidden="true">‹</span><?php endif; ?>
        <?php if ($i === count($trail) - 1) : ?>
          <span aria-current="page"><?= esc_html($t['name']) ?></span>
        <?php else : ?>
          <a href="<?= esc_url($t['url']) ?>"><?= esc_html($t['name']) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>

    <header class="hub-head">
      <h1 class="hub-head__title"><?= esc_html(Hub::pillarName($pillar) . ' ' . $coin->name) ?></h1>
      <?php if ($intro !== '') : ?>
        <div class="hub-intro"><?= wp_kses_post(wpautop($intro)) ?></div>
      <?php endif; ?>
    </header>

    <?= $blocks->pillars(['coin' => $coinSlug, 'current' => $pillar]) ?>

    <div class="hub-grid">
      <div class="hub-main">
        <?php if ($featured !== []) : ?>
          <section class="hub-sec" aria-labelledby="hub-featured">
            <h2 class="hub-sec__title" id="hub-featured">مقاله‌های برگزیده</h2>
            <?= $blocks::cards($featured, 'thb-b-cards thb-b-cards--featured') ?>
          </section>
        <?php endif; ?>
        <?php if ($latest !== []) : ?>
          <section class="hub-sec" aria-labelledby="hub-latest">
            <h2 class="hub-sec__title" id="hub-latest">تازه‌ترین مقاله‌ها</h2>
            <?= $blocks::cards($latest) ?>
          </section>
        <?php endif; ?>
        <?php if ($faq !== []) : ?>
          <div class="hub-sec"><?= $blocks::faqList($faq, 'پرسش‌های متداول درباره ' . Hub::pillarName($pillar) . ' ' . $coin->name) ?></div>
        <?php endif; ?>
      </div>
      <aside class="hub-side" aria-label="<?= esc_attr($coin->name . ' در یک نگاه') ?>">
        <?= $blocks->price(['coin' => $coinSlug, 'live' => true]) ?>
        <?= $blocks->stats(['coin' => $coinSlug]) ?>
        <nav class="hub-links" aria-label="پیوندها">
          <a class="hub-links__a" href="<?= esc_url(Hub::hubUrl($coinSlug)) ?>">صفحه کامل <?= esc_html($coin->name) ?> ←</a>
          <?php foreach ($siblings as $s) : ?>
            <a class="hub-links__a" href="<?= esc_url(Hub::pillarUrl($coinSlug, $s)) ?>"><?= esc_html(Hub::pillarName($s) . ' ' . $coin->name) ?> ←</a>
          <?php endforeach; ?>
        </nav>
      </aside>
    </div>

  </div>
</div>
</div>
</div>
<?php
get_footer();
