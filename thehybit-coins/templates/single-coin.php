<?php
/**
 * Coin detail page template.
 *
 * SERVER-RENDERED. Every headline figure, the whole About section, the news
 * list and the analytics numbers are in the initial HTML. JavaScript draws the
 * charts and wires interaction; it never fetches from a provider.
 *
 * The markup is the approved prototype's, unchanged — same classes, same
 * structure, same order. Only literals became <?= ?>.
 *
 * Harika supplies the header and footer via get_header()/get_footer(); this
 * file is content only, exactly as coin-ethereum.html was.
 *
 * @package TheHybit\Coins
 */

use TheHybit\Coins\Plugin;
use TheHybit\Coins\CoinRepository;

defined('ABSPATH') || exit;

$plugin = Plugin::instance();
$coin = $plugin->coins->find(get_the_ID());

if (!$coin) {
    get_header();
    echo '<div class="thb-coin"><div class="thb-container"><p>پیکربندی این ارز کامل نیست.</p></div></div>';
    get_footer();
    return;
}

$model = $plugin->pipeline->viewModel($coin);
$sections = $model['sections'];
$partials = THB_COINS_DIR . 'templates/partials/';

get_header();
?>

<div class="thb-coin">
  <div class="thb-container">

    <?php require $partials . 'breadcrumb.php'; ?>

    <div class="thb-page">

      <?php
      /* THE APPROVED ORDER, with Group A folded in rather than appended.
       *
       * Market Context sits directly after performance because that is where
       * it is needed: having just read this coin's numbers, the reader's next
       * question is whether the market did the same thing. Putting it at the
       * bottom would answer it long after it stopped being asked.
       *
       * Network & Ecosystem follows DeFi, widening from "this chain's DeFi" to
       * "this chain among all chains". Market Structure absorbs the old DEX
       * card and the new centralised-venue figures into one dense section. */
      require $partials . 'header.php';         // §1 + §2
      require $partials . 'chart.php';          // §3
      require $partials . 'performance.php';    // §4
      require $partials . 'market-context.php'; // §4b — site-wide market context
      require $partials . 'about.php';          // §5
      require $partials . 'tokenomics.php';     // §5b — supply and valuation

      // §6 / §7 — absent entirely when the provider has nothing for this coin.
      if (!empty($model['defi']))    { require $partials . 'defi.php'; }
      if (!empty($model['onchain'])) { require $partials . 'onchain.php'; }

      /* §7b — the chain around the coin, distinct from the DeFi card above. */
      require $partials . 'ecosystem.php';

      /* §8 — L2 stays a metric card and stays conditional on meaningful data;
         the thresholds live in Derive::sectionVisibility(). DEX no longer
         renders as its own card: its figures moved into Market Structure,
         where they sit beside the centralised venues instead of half filling
         a card of their own. */
      $showL2 = $sections['l2']['visible'];
      if ($showL2) {
          $isLastMetricCard = true;
          $oddCard = true;
          require $partials . 'l2.php';
      }

      /* §9 — centralised and decentralised venues together. */
      require $partials . 'market-structure.php';

      require $partials . 'analytics.php';   // §10

      if ($sections['news']['visible']) {
          require $partials . 'news.php';    // §11
      }
      ?>

    </div><!-- /.thb-page -->
  </div><!-- /.thb-container -->
</div><!-- /.thb-coin -->

<?php get_footer(); ?>
