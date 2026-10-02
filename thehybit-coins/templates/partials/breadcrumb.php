<?php
/** Breadcrumb. Mirrors the BreadcrumbList emitted by Schema.php. */
use TheHybit\Coins\CoinRepository;
defined('ABSPATH') || exit;
$archive = get_post_type_archive_link(CoinRepository::POST_TYPE) ?: home_url('/coins/');
?>
<nav class="thb-breadcrumb" aria-label="مسیر صفحه">
  <a href="<?= esc_url(home_url('/')) ?>">خانه</a>
  <svg class="thb-breadcrumb__sep" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
  <a href="<?= esc_url($archive) ?>">ارزها</a>
  <svg class="thb-breadcrumb__sep" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
  <span aria-current="page"><?= esc_html($coin->name) ?></span>
</nav>
