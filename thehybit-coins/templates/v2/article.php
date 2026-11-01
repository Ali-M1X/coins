<?php
/**
 * A hub article (2.16.0): /coins/<coin>/<pillar>/<slug>/ — a normal post.
 *
 * Breadcrumb (کوین‌ها › اتریوم › آموزش › title), dates, author, table of
 * contents, the post content (with any blocks the editor placed in it), a
 * "next step" card, related articles and links to the pillar and the hub.
 * Schema (BreadcrumbList, Article, FAQPage when the content has a FAQ) is
 * printed in <head> by Hub::head().
 *
 * Child themes can override this file at thehybit/v2/article.php.
 *
 * @package TheHybit\Coins
 */

use TheHybit\Coins\Blocks;
use TheHybit\Coins\Format;
use TheHybit\Coins\Hub;
use TheHybit\Coins\Plugin;

defined('ABSPATH') || exit;

$plugin = Plugin::instance();
$hub = $plugin->hub;
$blocks = $plugin->blocks;
[$post, $coinSlug, $pillar] = $hub->currentArticle();
$coin = $hub->coin($coinSlug);
$trail = $hub->trail($coinSlug, $pillar, $post);
$raw = (string) $post->post_content;
$content = (string) apply_filters('the_content', $raw);
$hasToc = str_contains($raw, 'wp:thb/toc') || str_contains($raw, '[thb_toc');
$toc = $hasToc ? '' : Blocks::tocFor($content);
$published = Hub::iso((string) $post->post_date_gmt);
$modified = Hub::iso((string) $post->post_modified_gmt);
$authorId = (int) $post->post_author;
$authorName = $authorId ? (string) get_the_author_meta('display_name', $authorId) : '';
$authorBio = $authorId ? (string) get_the_author_meta('description', $authorId) : '';
$avatar = $authorId && function_exists('get_avatar_url') ? (string) get_avatar_url($authorId, ['size' => 96]) : '';

/* Next step: the next pillar of this coin that has content, else the hub. */
$have = $hub->pillarsWithContent($coinSlug);
$after = array_values(array_filter(array_keys(Hub::PILLARS), static fn($p) => $p !== $pillar && in_array($p, $have, true)));
$nextPillar = null;
foreach (array_slice(array_keys(Hub::PILLARS), (int) array_search($pillar, array_keys(Hub::PILLARS), true) + 1) as $p) {
    if (in_array($p, $have, true)) { $nextPillar = $p; break; }
}
$nextPillar ??= $after[0] ?? null;
$related = $hub->articles($coinSlug, $pillar, 4, [(int) $post->ID]);
if (count($related) < 4) {
    $more = $hub->articles($coinSlug, null, 4 - count($related), array_merge([(int) $post->ID], array_map(static fn($p) => (int) $p->ID, $related)));
    $related = array_merge($related, $more);
}

get_header();
?>
<div class="thb-v2-host">
<div class="thb-v2-sizer" aria-hidden="true"><span></span> <span></span> <span></span> <span></span> <span></span> <span></span> <span></span></div>
<div class="thb-v2-frame">
<div class="thb-v2 thb-hub thb-article" data-thb-design="v2">
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

    <article class="hub-article">
      <header class="hub-article__head">
        <a class="hub-article__kicker" href="<?= esc_url(Hub::pillarUrl($coinSlug, $pillar)) ?>"><?= esc_html(Hub::pillarName($pillar) . ' ' . $coin->name) ?></a>
        <h1 class="hub-article__title"><?= esc_html((string) $post->post_title) ?></h1>
        <p class="hub-article__meta">
          <?php if ($authorName !== '') : ?><span><?= esc_html($authorName) ?></span><?php endif; ?>
          <?php if ($published) : ?><span>انتشار: <time datetime="<?= esc_attr($published) ?>"><?= esc_html(Format::gregorianFa($published)) ?></time></span><?php endif; ?>
          <?php if ($modified && $modified !== $published) : ?><span>به‌روزرسانی: <time datetime="<?= esc_attr($modified) ?>"><?= esc_html(Format::gregorianFa($modified)) ?></time></span><?php endif; ?>
        </p>
      </header>

      <div class="hub-article__grid">
        <?php if ($toc !== '') : ?>
          <div class="hub-article__toc"><?= $toc ?></div>
        <?php endif; ?>
        <div class="hub-article__body hub-prose"><?= $content ?></div>
      </div>

      <?php if ($nextPillar !== null) : ?>
        <?= $blocks->next(['coin' => $coinSlug, 'pillar' => $nextPillar, 'title' => Hub::pillarName($nextPillar) . ' ' . $coin->name]) ?>
      <?php else : ?>
        <a class="thb-b thb-b--next" href="<?= esc_url(Hub::hubUrl($coinSlug)) ?>"><span class="thb-b-next__kicker">قدم بعدی</span><span class="thb-b-next__title">صفحه کامل <?= esc_html($coin->name) ?></span><span class="thb-b-next__arrow" aria-hidden="true">←</span></a>
      <?php endif; ?>

      <?php if ($authorName !== '') : ?>
        <aside class="hub-author" aria-label="نویسنده">
          <?php if ($avatar !== '') : ?><img class="hub-author__img" src="<?= esc_url($avatar) ?>" alt="" width="56" height="56" loading="lazy" decoding="async"><?php endif; ?>
          <div><p class="hub-author__name"><?= esc_html($authorName) ?></p><?php if ($authorBio !== '') : ?><p class="hub-author__bio"><?= esc_html($authorBio) ?></p><?php endif; ?></div>
        </aside>
      <?php endif; ?>
    </article>

    <?php if ($related !== []) : ?>
      <section class="hub-sec" aria-labelledby="hub-related">
        <h2 class="hub-sec__title" id="hub-related">مقاله‌های مرتبط</h2>
        <?= Blocks::cards($related) ?>
      </section>
    <?php endif; ?>

    <nav class="hub-links hub-links--row" aria-label="پیوندها">
      <a class="hub-links__a" href="<?= esc_url(Hub::pillarUrl($coinSlug, $pillar)) ?>">همه مقاله‌های <?= esc_html(Hub::pillarName($pillar) . ' ' . $coin->name) ?> ←</a>
      <a class="hub-links__a" href="<?= esc_url(Hub::hubUrl($coinSlug)) ?>">صفحه کامل <?= esc_html($coin->name) ?> ←</a>
    </nav>

  </div>
</div>
</div>
</div>
<?php
get_footer();
