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

<div class="thb-v2" data-thb-design="v2">
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

<?php get_footer(); ?>
