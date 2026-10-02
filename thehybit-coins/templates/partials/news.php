<?php
/**
 * §11 Related articles — TheHybit's own WordPress, filtered to this coin's
 * category via coin.newsCategorySlug. Never an external news feed, and never an
 * unfiltered query.
 */
use TheHybit\Coins\Format;
defined('ABSPATH') || exit;

$news = $model['news'];
$archive = $model['coin']['newsCategoryUrl'] ?? null;
?>
<section class="thb-card thb-news" aria-labelledby="thb-news-title">
  <div class="thb-card__head">
    <h2 class="thb-card__title" id="thb-news-title">مطالب مرتبط</h2>
    <span class="thb-source">از وبلاگ TheHybit</span>
  </div>

  <div class="thb-card__body">
    <div class="thb-news__list">
      <?php foreach ($news as $article) : ?>
        <a class="thb-news__item" href="<?= esc_url($article['url']) ?>">
          <?php if (!empty($article['image']['src'])) : ?>
            <img class="thb-news__thumb" src="<?= esc_url($article['image']['src']) ?>"
                 alt="<?= esc_attr($article['image']['alt'] ?? '') ?>" width="56" height="56" loading="lazy">
          <?php endif; ?>
          <span class="thb-news__body">
            <span class="thb-news__title"><?= esc_html($article['title']) ?></span>
            <span class="thb-news__meta">
              <?php if (!empty($article['category'])) : ?>
                <span class="thb-news__cat"><?= esc_html($article['category']) ?></span>
              <?php endif; ?>
              <time datetime="<?= esc_attr(substr($article['publishedAt'], 0, 10)) ?>"><?= esc_html(Format::jalaliShort($article['publishedAt'])) ?></time>
            </span>
          </span>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if ($archive) : ?>
      <a class="thb-news__all" href="<?= esc_url($archive) ?>">
        همه مطالب <?= esc_html($coin->name) ?>
        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
      </a>
    <?php endif; ?>
  </div>
</section>
