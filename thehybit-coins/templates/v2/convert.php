<?php
/**
 * /coins/convert/ — the standalone price converter (2.10.0).
 *
 * The same converter block as every coin page, starting from Bitcoin to USD
 * (or the visitor's ?from=&to=&amount=), followed by a table of what one unit
 * of every coin we price is worth — all from the cache, with the time of each
 * rate. One indexable page; any parameterised state is noindex with its
 * canonical here (includes/Tools.php).
 *
 * Child themes can override this file at thehybit/v2/convert.php.
 *
 * @package TheHybit\Coins
 */

use TheHybit\Coins\Plugin;
use TheHybit\Coins\V2\Converter;
use TheHybit\Coins\V2\View;

defined('ABSPATH') || exit;

$plugin = Plugin::instance();
$conv = $plugin->converter()->forCoin(null);
$standalone = true;
$valid = static function (?string $u) use ($conv): ?string {
    if ($u === null || $conv === null) { return null; }
    $u = sanitize_key($u);
    return Converter::usdPer($conv, $u) !== null ? $u : null;
};
$state = [
    'from' => $valid($_GET['from'] ?? null) ?? ($conv && Converter::usdPer($conv, 'bitcoin') !== null ? 'bitcoin' : ($conv['base']['id'] ?? 'usd')),
    'to' => $valid($_GET['to'] ?? null) ?? 'usd',
    'amount' => isset($_GET['amount']) ? mb_substr(sanitize_text_field(wp_unslash((string) $_GET['amount'])), 0, 24) : '1',
];
$base = trailingslashit((string) (get_post_type_archive_link(\TheHybit\Coins\CoinRepository::POST_TYPE) ?: home_url('/coins/')));

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
      <span aria-current="page">مبدل قیمت</span>
    </nav>

    <header class="list-head">
      <h1 class="list-head__title">مبدل قیمت ارز دیجیتال به دلار و تومان</h1>
      <p class="list-head__sub">بیت‌کوین، اتریوم، تتر و <?= View::n((string) max(0, count($conv['coins'] ?? []) - 3)) ?> ارز دیگر را به دلار، تومان یا به یکدیگر تبدیل کنید.</p>
    </header>

    <?php if ($conv === null) : ?>
      <div class="v2-card"><?= View::na('pending', 'قیمت‌ها چند دقیقه پس از نصب در دسترس می‌شوند.') ?></div>
    <?php else : ?>
      <?php require THB_COINS_DIR . 'templates/v2/partials/converter.php'; ?>

      <section class="v2-card list-card" aria-labelledby="conv-all-title">
        <h2 class="list-card__title" id="conv-all-title">ارزش هر واحد، به دلار<?= $conv['toman'] ? ' و تومان' : '' ?></h2>
        <table class="list-table v2-conv__all">
          <thead><tr>
            <th scope="col" class="c-coin">ارز</th><th scope="col" class="c-num">۱ واحد به دلار</th>
            <?php if ($conv['toman']) : ?><th scope="col" class="c-num">۱ واحد به تومان</th><?php endif; ?>
          </tr></thead>
          <tbody>
            <?php foreach ($conv['coins'] as $p) : ?>
              <tr>
                <th scope="row" class="c-coin"><span class="list-coin__text"><a class="list-coin__name" href="<?= esc_url($p['url']) ?>#convert"><?= esc_html($p['name']) ?></a> <bdi class="list-coin__sym" dir="ltr"><?= esc_html($p['symbol']) ?></bdi></span></th>
                <td class="c-num"><?= View::n(\TheHybit\Coins\Format::usd($p['price'])) ?></td>
                <?php if ($conv['toman']) : ?><td class="c-num"><?= View::n(\TheHybit\Coins\Format::toman($p['price'], $conv['toman']['rate'])) ?></td><?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </section>

      <section class="v2-card list-about" aria-labelledby="conv-how-title">
        <h2 class="list-card__title" id="conv-how-title">این مبدل چطور حساب می‌کند؟</h2>
        <p>قیمت هر ارز همان قیمتی است که <?= esc_html(\TheHybit\Coins\V2\Model::brand()) ?> هر دقیقه از CoinGecko دریافت و ذخیره می‌کند؛ برای ارزهایی که در صرافی‌ها قیمت زنده دارند، تا وقتی صفحه باز است همان قیمت زنده به کار می‌رود. تبدیل دو ارز به یکدیگر از قیمت دلاری هر دو حساب می‌شود.</p>
        <?php if ($conv['toman']) : ?>
          <p>مبلغ تومانی حاصل ضرب قیمت دلاری در نرخ دلار <?= esc_html($conv['toman']['source']) ?> است (آخرین به‌روزرسانی <?= esc_html((string) $conv['tomanUpdated']) ?> به وقت تهران)؛ قیمت خرید و فروش در صرافی‌های ایرانی ممکن است با آن فرق داشته باشد.</p>
        <?php endif; ?>
        <p class="v2-muted">کارمزد معامله، اختلاف قیمت خرید و فروش و کارمزد انتقال در این محاسبه نیست. این ابزار برای آگاهی است و توصیه خرید یا فروش نیست.</p>
      </section>
    <?php endif; ?>

  </div>
</div>
</div>
</div>
<?php
get_footer();
