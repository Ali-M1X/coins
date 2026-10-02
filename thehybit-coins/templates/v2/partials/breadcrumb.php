<?php
/** Breadcrumb. Mirrors the BreadcrumbList emitted by Schema.php. */
use TheHybit\Coins\CoinRepository;
defined('ABSPATH') || exit;
$archive = get_post_type_archive_link(CoinRepository::POST_TYPE) ?: home_url('/coins/');
?>
<nav class="v2-crumbs" aria-label="مسیر صفحه">
  <a href="<?= esc_url(home_url('/')) ?>">خانه</a>
  <span aria-hidden="true">‹</span>
  <a href="<?= esc_url($archive) ?>">ارزها</a>
  <span aria-hidden="true">‹</span>
  <span aria-current="page"><?= esc_html($v['coin']['name']) ?></span>
</nav>
