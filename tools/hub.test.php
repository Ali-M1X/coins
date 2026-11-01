<?php
/**
 * The coin hub (2.16.0): routes, 404 for empty pillars, 301 for moved
 * articles, schema, sitemap, the blocks and their shortcodes — and that none
 * of it makes a provider request.
 *
 *   php tools/hub.test.php
 *   THB_HUB_DIR=$PWD php tools/hub.test.php   # also writes hub-*.html for screenshots
 *
 * The articles below are SAMPLE CONTENT for the tests and screenshots only.
 */

declare(strict_types=1);

require_once __DIR__ . '/v2-bootstrap.php';

use TheHybit\Coins\{Blocks, Hub, Plugin};

$failed = 0;
function ok(bool $c, string $m): void { global $failed; if (!$c) { $failed++; } echo ($c ? 'PASS' : 'FAIL') . "  {$m}\n"; }
function section(string $s): void { echo "\n--- {$s} " . str_repeat('-', max(0, 58 - mb_strlen($s))) . "\n"; }

$plugin = Plugin::instance();
$hub = $plugin->hub;
$blocks = $plugin->blocks;
$hub->register();
$blocks->register();
$blocks->registerBlocks();
add_filter('the_content', 'do_blocks', 9);
add_filter('the_content', 'do_shortcode', 11);

/* ---- warm the cache the way the scheduler would (this is the ONLY place requests happen) ---- */
v2_warm(10);
v2_warm_list();
$GLOBALS['thb_probe_background'] = true;
$plugin->pipeline->warmTicker($plugin->coins->all());
foreach (['fx', 'irt', 'fiat'] as $ds) { $plugin->pipeline->warm($plugin->coins->find(10), $ds); }
$GLOBALS['thb_probe_background'] = false;

/* ---- terms and sample posts ---- */
$GLOBALS['thb_options']['thb_coins_settings']['x'] = 1;
$GLOBALS['thb_user_can'] = true;
$hub->syncTerms();
$ethTerm = $GLOBALS['thb_terms'][Hub::TAX_COIN]['ethereum']->term_id;
update_term_meta($ethTerm, 'thb_intro_learn', "اتریوم یک شبکه باز است که روی آن برنامه‌ها و قراردادهای هوشمند اجرا می‌شوند. در این بخش از مفاهیم پایه شروع می‌کنیم و قدم‌به‌قدم به کیف پول، کارمزد و امنیت می‌رسیم.\n\nهر مقاله کوتاه است و به مقاله بعدی پیوند دارد.");
update_term_meta($ethTerm, 'thb_intro_learn_at', strtotime('2026-10-20 08:00:00 UTC'));
update_term_meta($ethTerm, 'thb_faq_learn', "اتریوم با بیت‌کوین چه فرقی دارد؟ | بیت‌کوین بیشتر برای انتقال ارزش است؛ اتریوم برنامه‌پذیر است و قراردادهای هوشمند را اجرا می‌کند.\nگس چیست؟ | کارمزدی که برای اجرای تراکنش در شبکه اتریوم پرداخت می‌شود.");
update_term_meta($ethTerm, 'thb_intro_analysis', 'تحلیل‌های ما درباره بازار و شبکه اتریوم به‌زودی در این بخش منتشر می‌شود.');
update_term_meta($ethTerm, 'thb_intro_analysis_at', strtotime('2026-10-20 08:00:00 UTC'));   // set by saveTermFields() when an intro is saved
$GLOBALS['thb_authors'][3] = ['display_name' => 'سارا محمدی', 'description' => 'نویسنده آموزش‌های بلاکچین در های‌بیت.'];

$post = static function (int $id, string $slug, string $title, array $terms, string $date, string $content = '', string $excerpt = '', string $status = 'publish'): object {
    return (object) ['ID' => $id, 'post_type' => 'post', 'post_status' => $status, 'post_name' => $slug, 'post_title' => $title,
        'post_content' => $content, 'post_excerpt' => $excerpt, 'post_author' => 3,
        'post_date_gmt' => $date, 'post_modified_gmt' => date('Y-m-d H:i:s', strtotime($date . ' UTC') + 86400 * 3), 'terms' => $terms];
};
$articleBody = <<<'HTML'
<p>اتریوم شبکه‌ای است که هر کس می‌تواند روی آن برنامه بنویسد. واحد پول آن اتر (ETH) است و برای پرداخت کارمزد تراکنش‌ها به کار می‌رود.</p>
<h2>اتریوم چگونه کار می‌کند؟</h2>
<p>هزاران رایانه در سراسر جهان نسخه‌ای از دفتر حساب اتریوم را نگه می‌دارند. هر تراکنش پس از تأیید اعتبارسنج‌ها در یک بلاک ثبت می‌شود و دیگر قابل تغییر نیست.</p>
<!-- wp:thb/price {"coin":"ethereum"} /-->
<p>قیمت اتر مثل هر دارایی دیگری با عرضه و تقاضا تغییر می‌کند؛ کارت بالا آخرین قیمت ذخیره‌شده را نشان می‌دهد و هر جا ممکن باشد زنده به‌روز می‌شود.</p>
<h2>قرارداد هوشمند چیست؟</h2>
<p>قرارداد هوشمند برنامه‌ای است که روی شبکه اجرا می‌شود و وقتی شرطی برقرار شد، کاری را خودکار انجام می‌دهد.</p>
<h3>یک مثال ساده</h3>
<p>فرض کنید پرداختی فقط وقتی انجام شود که کالا تحویل شده باشد؛ قرارداد هوشمند این شرط را بدون واسطه اجرا می‌کند.</p>
<!-- wp:thb/chart {"coin":"ethereum","period":"7d"} /-->
<h2>اتریوم در یک نگاه</h2>
<!-- wp:thb/stats {"coin":"ethereum"} /-->
<!-- wp:thb/converter {"coin":"ethereum"} /-->
<!-- wp:thb/faq {"text":"آیا اتریوم سقف عرضه دارد؟ | نه؛ اتریوم سقف ثابت ندارد، اما بخشی از کارمزدها سوزانده می‌شود.\nحداقل خرید اتر چقدر است؟ | اتر تا ۱۸ رقم اعشار بخش‌پذیر است؛ می‌توانید کسری از یک اتر بخرید."} /-->
HTML;
$GLOBALS['thb_posts'] = [
    101 => $post(101, 'what-is-ethereum', 'اتریوم چیست و چگونه کار می‌کند؟', [Hub::TAX_COIN => ['ethereum'], Hub::TAX_PILLAR => ['learn']], '2026-10-25 09:00:00', $articleBody, 'از مفاهیم پایه تا قرارداد هوشمند، به زبان ساده.'),
    102 => $post(102, 'ethereum-wallet', 'کیف پول اتریوم: کدام را انتخاب کنیم؟', [Hub::TAX_COIN => ['ethereum'], Hub::TAX_PILLAR => ['learn']], '2026-10-22 09:00:00', '<p>متن نمونه.</p>', 'مقایسه کیف پول‌های نرم‌افزاری و سخت‌افزاری.'),
    103 => $post(103, 'smart-contracts', 'قرارداد هوشمند به زبان ساده', [Hub::TAX_COIN => ['ethereum'], Hub::TAX_PILLAR => ['learn']], '2026-10-18 09:00:00', '<p>متن نمونه.</p>', 'قراردادی که خودش اجرا می‌شود.'),
    104 => $post(104, 'ethereum-staking-guide', 'راهنمای استیکینگ اتریوم', [Hub::TAX_COIN => ['ethereum'], Hub::TAX_PILLAR => ['staking']], '2026-10-24 09:00:00', '<p>متن نمونه.</p>'),
    105 => $post(105, 'what-is-bitcoin', 'بیت‌کوین چیست؟', [Hub::TAX_COIN => ['bitcoin'], Hub::TAX_PILLAR => ['learn']], '2026-10-23 09:00:00', '<p>متن نمونه.</p>'),
    106 => $post(106, 'site-news', 'خبر سایت', [], '2026-10-21 09:00:00', '<p>متن نمونه.</p>'),
    107 => $post(107, 'draft-news', 'پیش‌نویس خبر', [Hub::TAX_COIN => ['ethereum'], Hub::TAX_PILLAR => ['news']], '2026-10-26 09:00:00', '', '', 'draft'),
];
$GLOBALS['thb_options']['sticky_posts'] = [103];

$pillarRequest = static function (string $coin, string $pillar, ?string $name = null): void {
    $GLOBALS['thb_query_vars'] = [Hub::VAR_COIN => $coin, Hub::VAR_PILLAR => $pillar] + ($name !== null ? ['name' => $name] : []);
    unset($GLOBALS['thb_post'], $GLOBALS['thb_status']);
};
$renderTemplate = static function (string $file, string $head): string {
    $GLOBALS['thb_hub_page'] = true;
    $GLOBALS['thb_hub_head'] = $head;
    ob_start();
    require THB_COINS_DIR . 'templates/v2/' . $file;
    $html = (string) ob_get_clean();
    unset($GLOBALS['thb_hub_page'], $GLOBALS['thb_hub_head']);
    return $html;
};
$head = static function () use ($hub): string { ob_start(); $hub->head(); return (string) ob_get_clean(); };
$ld = static function (string $html): array {
    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
    $g = json_decode($m[1] ?? '{}', true)['@graph'] ?? [];
    $by = [];
    foreach ($g as $n) { $by[$n['@type']] = $n; }
    return $by;
};

section('routes');
$hub->rules();
$rules = array_filter($GLOBALS['thb_rewrite'] ?? [], static fn($q) => str_contains($q, Hub::VAR_COIN));
ok(count($rules) === 2, 'two rewrite rules: pillar and article');
$match = static function (string $path) use ($rules): ?string {
    foreach ($rules as $re => $q) {
        if (preg_match('#' . $re . '#', $path, $m)) {
            return preg_replace_callback('#\$matches\[(\d)\]#', static fn($x) => $m[(int) $x[1]], $q);
        }
    }
    return null;
};
ok($match('coins/ethereum/learn/') === 'index.php?thb_hub_coin=ethereum&thb_hub_pillar=learn', '/coins/ethereum/learn/ → the pillar');
ok($match('coins/ethereum/learn/what-is-ethereum/') === 'index.php?name=what-is-ethereum&thb_hub_coin=ethereum&thb_hub_pillar=learn', '/coins/ethereum/learn/<slug>/ → the article (a normal post by its slug)');
ok($match('coins/ethereum/') === null && $match('coins/convert/') === null && $match('coins/compare/') === null && $match('coins/page/2/') === null
   && $match('coins/ethereum/whatever/') === null, 'no conflict: /coins/ethereum/, /coins/convert/, /coins/compare/, /coins/page/2/ and non-pillar paths are untouched');
$tools = new \TheHybit\Coins\Tools();
$tools->rules();
ok(isset($GLOBALS['thb_rewrite']['^coins/convert/?$']) && isset($GLOBALS['thb_rewrite']['^coins/compare/?$']), 'the tool routes are still registered');
Hub::registerTaxonomies();
ok(isset($GLOBALS['thb_taxonomies'][Hub::TAX_COIN], $GLOBALS['thb_taxonomies'][Hub::TAX_PILLAR]) && $GLOBALS['thb_taxonomies'][Hub::TAX_COIN]['show_in_rest'],
   'taxonomies thb_coin_topic and thb_pillar, on posts, in the block editor');
ok(count($GLOBALS['thb_terms'][Hub::TAX_PILLAR]) === 9 && $GLOBALS['thb_terms'][Hub::TAX_PILLAR]['prediction']->name === 'پیش‌بینی و آینده'
   && isset($GLOBALS['thb_terms'][Hub::TAX_COIN]['ethereum'], $GLOBALS['thb_terms'][Hub::TAX_COIN]['bitcoin'])
   && (int) get_term_meta($ethTerm, 'thb_coin_id', true) === 10, 'nine fixed pillar terms; one coin term per coin page, linked to its thb_coin entry');
$src = (string) file_get_contents(THB_COINS_DIR . 'thehybit-coins.php');
ok(str_contains($src, 'Hub::registerTaxonomies();') && str_contains($src, '->rules();') && preg_match('#register_activation_hook.*?Hub::registerTaxonomies.*?flush_rewrite_rules#s', $src) === 1,
   'activation registers the taxonomies and routes before flushing; a version change flushes once from wp-admin');

section('pillars: content or 404');
ok($hub->pillarsWithContent('ethereum') === ['learn', 'analysis', 'staking'], 'Ethereum\'s pillars with content: learn (articles + intro), analysis (intro only), staking (an article)');
ok(!$hub->hasContent('ethereum', 'news') && !$hub->hasContent('ethereum', 'buy'), 'news (only a draft) and buy (nothing) have no content');
foreach ([['ethereum', 'learn', 200], ['ethereum', 'analysis', 200], ['ethereum', 'news', 404], ['ethereum', 'buy', 404], ['no-such-coin', 'learn', 404]] as [$c, $p, $want]) {
    $pillarRequest($c, $p);
    $hub->route();
    $tpl = $hub->template('theme.php');
    ok(($GLOBALS['thb_status'] ?? 0) === $want && ($want === 200 ? str_ends_with($tpl, 'templates/v2/pillar.php') : $tpl === 'theme.php'),
       "/coins/{$c}/{$p}/ answers {$want}" . ($want === 200 ? ' with the pillar template' : ' (the theme\'s 404)'));
}

section('articles: URL, 301 when moved');
ok(get_permalink(101) === 'https://thehybit.com/coins/ethereum/learn/what-is-ethereum/' && get_permalink(106) === 'https://thehybit.com/site-news/'
   && get_permalink(107) === 'https://thehybit.com/draft-news/', 'a hub article\'s permalink is /coins/<coin>/<pillar>/<slug>/; other posts and drafts keep theirs');
$redirect = null;
add_filter('thb_hub_redirect', static function ($go, $url) use (&$redirect) { $redirect = $url; return false; }, 10, 2);
$pillarRequest('ethereum', 'analysis', 'what-is-ethereum');
$GLOBALS['thb_post'] = $GLOBALS['thb_posts'][101];
$hub->route();
ok($redirect === 'https://thehybit.com/coins/ethereum/learn/what-is-ethereum/', 'reached under another pillar (it moved): 301 to its current URL');
$redirect = null;
$GLOBALS['thb_query_vars'] = [];
$GLOBALS['thb_post'] = $GLOBALS['thb_posts'][101];
$hub->route();
ok($redirect === 'https://thehybit.com/coins/ethereum/learn/what-is-ethereum/', 'reached at its old /slug/ URL: 301 to the hub URL');
$redirect = null;
$pillarRequest('ethereum', 'learn', 'what-is-ethereum');
$GLOBALS['thb_post'] = $GLOBALS['thb_posts'][101];
$hub->route();
ok($redirect === null && str_ends_with($hub->template('theme.php'), 'templates/v2/article.php'), 'at its own URL: no redirect, the article template');

section('templates and schema');
Probe::reset(keepCache: true);
$pillarRequest('ethereum', 'learn');
$learnHead = $head();
$learn = $renderTemplate('pillar.php', $learnHead);
$pillarRequest('ethereum', 'learn', 'what-is-ethereum');
$GLOBALS['thb_post'] = $GLOBALS['thb_posts'][101];
$articleHead = $head();
$article = $renderTemplate('article.php', $articleHead);
unset($GLOBALS['thb_post']);
$GLOBALS['thb_query_vars'] = [];
$GLOBALS['thb_current_post'] = 10;
$hubPage = v2_render(10);
unset($GLOBALS['thb_current_post']);
ok(Probe::count() === 0, 'the pillar page, the article (with five blocks in it) and the coin hub make ZERO provider requests (' . Probe::count() . ')');

ok(str_contains($learn, '<h1 class="hub-head__title">آموزش اتریوم</h1>') && str_contains($learn, 'اتریوم یک شبکه باز است')
   && strpos($learn, 'مقاله‌های برگزیده') < strpos($learn, 'تازه‌ترین مقاله‌ها') && strpos($learn, 'قرارداد هوشمند به زبان ساده') < strpos($learn, 'تازه‌ترین مقاله‌ها')
   && str_contains($learn, 'پرسش‌های متداول درباره آموزش اتریوم'), 'pillar page: intro, featured (sticky) then newest articles, then the FAQ');
ok(str_contains($learn, 'href="https://thehybit.com/coins/ethereum/"') && str_contains($learn, 'href="https://thehybit.com/coins/ethereum/analysis/"')
   && str_contains($learn, 'href="https://thehybit.com/coins/ethereum/learn/what-is-ethereum/"') && !str_contains($learn, '/coins/ethereum/news/'),
   'it links to the hub, its articles and sibling pillars — never to an empty pillar');
$s = $ld($learnHead);
ok(count($s['BreadcrumbList']['itemListElement'] ?? []) === 3 && ($s['BreadcrumbList']['itemListElement'][2]['name'] ?? '') === 'آموزش'
   && count($s['CollectionPage']['mainEntity']['itemListElement'] ?? []) === 3 && count($s['FAQPage']['mainEntity'] ?? []) === 2,
   'pillar schema: BreadcrumbList (کوین‌ها › اتریوم › آموزش), CollectionPage with its 3 articles, FAQPage');
ok($hub->title('ethereum', 'learn') === 'آموزش اتریوم | های بیت' && str_starts_with($hub->description('ethereum', 'learn'), 'اتریوم یک شبکه باز است'),
   'pillar title and meta description (from the intro)');
$pillarRequest('ethereum', 'learn');
ok($hub->canonical('x') === 'https://thehybit.com/coins/ethereum/learn/', 'canonical: the pillar URL');

ok(preg_match('#<nav class="v2-crumbs"[^>]*>.*کوین‌ها.*اتریوم.*آموزش.*aria-current="page">اتریوم چیست و چگونه کار می‌کند؟</span>#su', $article) === 1,
   'article breadcrumb: کوین‌ها › اتریوم › آموزش › title');
ok(str_contains($article, 'class="thb-b thb-b--toc"') && str_contains($article, 'href="#h-اتریوم-چگونه-کار-می-کند"') && str_contains($article, 'id="h-اتریوم-چگونه-کار-می-کند"'),
   'table of contents, linked to ids on the article\'s headings');
ok(str_contains($article, 'سارا محمدی') && str_contains($article, 'datetime="2026-10-25T09:00:00+00:00"') && str_contains($article, 'datetime="2026-10-28T09:00:00+00:00"'),
   'author box, published and updated dates');
$body = substr($article, (int) strpos($article, 'class="hub-article__body'));
$priceAt = strpos($body, 'class="thb-b thb-b--price"');
ok($priceAt !== false && $priceAt > strpos($body, 'اتریوم چگونه کار می‌کند؟') && $priceAt < strpos($body, 'قرارداد هوشمند چیست؟'),
   'the price card sits in the middle of the text, where the editor placed it');
ok(str_contains($article, 'class="thb-b thb-b--next" href="https://thehybit.com/coins/ethereum/analysis/"') && str_contains($article, 'مقاله‌های مرتبط')
   && str_contains($article, 'href="https://thehybit.com/coins/ethereum/learn/"') && str_contains($article, 'href="https://thehybit.com/coins/ethereum/"'),
   'next-step card (the next pillar with content), related articles, links to its pillar and the hub');
$a = $ld($articleHead);
ok(count($a['BreadcrumbList']['itemListElement'] ?? []) === 4 && ($a['Article']['headline'] ?? '') === 'اتریوم چیست و چگونه کار می‌کند؟'
   && ($a['Article']['datePublished'] ?? '') === '2026-10-25T09:00:00+00:00' && ($a['Article']['dateModified'] ?? '') === '2026-10-28T09:00:00+00:00'
   && ($a['Article']['author']['name'] ?? '') === 'سارا محمدی' && count($a['FAQPage']['mainEntity'] ?? []) === 2,
   'article schema: BreadcrumbList (4 levels), Article (headline, dates, author), FAQPage from the FAQ block');
ok(!preg_match('#coingecko|defillama|kraken|nobitex|نوبیتکس|etherscan|blockchair|alternative\.me|هر [۰-۹]+ دقیقه#iu',
   strip_tags((string) preg_replace(['#<script\b.*?</script>#s', '#\s(src|href)="[^"]*"#'], ['', ''], $learn . $article))),
   'no provider name on the pillar or article page');

ok(str_contains($hubPage, 'class="thb-b thb-b--pillars thb-b--pillars-hub"') && str_contains($hubPage, 'href="https://thehybit.com/coins/ethereum/learn/">آموزش</a>')
   && str_contains($hubPage, 'href="https://thehybit.com/coins/ethereum/staking/">استیکینگ و درآمد</a>') && !str_contains($hubPage, '/coins/ethereum/news/'),
   'the coin hub (v2) carries a pillar tab bar: every pillar with content, none without');
$GLOBALS['thb_current_post'] = 11;
$btcPage = v2_render(11);
unset($GLOBALS['thb_current_post']);
ok(str_contains($btcPage, 'href="https://thehybit.com/coins/bitcoin/learn/"') && !str_contains($btcPage, '/coins/bitcoin/staking/'), 'Bitcoin\'s hub links only its own pillar (learn)');

section('blocks and shortcodes');
$GLOBALS['thb_post'] = $GLOBALS['thb_posts'][101];
$GLOBALS['thb_query_vars'] = [Hub::VAR_COIN => 'ethereum', Hub::VAR_PILLAR => 'learn', 'name' => 'what-is-ethereum'];
Probe::reset(keepCache: true);
$names = array_keys(Blocks::BLOCKS);
$rendered = [];
foreach ($names as $n) {
    $attrs = match ($n) {
        'next' => ['pillar' => 'staking'], 'faq' => ['text' => 'پرسش؟ | پاسخ.'], default => [],
    };
    $rendered[$n] = $blocks->render($n, $attrs);
}
ok(array_filter($rendered, static fn($h) => $h === '') === [] && Probe::count() === 0,
   'all nine blocks render for Ethereum from the cache, with zero provider requests: ' . implode(', ', $names));
ok(count($GLOBALS['thb_blocks'] ?? []) === 9 && isset($GLOBALS['thb_blocks']['thb/price']['render_callback']) && count($GLOBALS['thb_shortcodes'] ?? []) === 9,
   'registered as nine dynamic Gutenberg blocks (thb/*) and nine shortcodes (thb_*)');
ok(do_shortcode('[thb_price coin="ethereum"]') === $blocks->render('price', ['coin' => 'ethereum', 'live' => true])
   && do_shortcode('[thb_stats]') === $blocks->render('stats', []) && do_blocks('<!-- wp:thb/chart {"period":"7d"} /-->') === $blocks->render('chart', ['period' => '7d']),
   'a shortcode and its block render the same markup; coin defaults to the page\'s coin');
ok(str_contains($rendered['price'], 'href="https://thehybit.com/coins/ethereum/"') && str_contains($rendered['price'], 'data-live-usd')
   && str_contains($rendered['price'], 'تومان') && str_contains($rendered['price'], 'thb-b-spark') && str_contains($rendered['price'], 'loading="lazy"'),
   'price card: links to the hub; USD, toman, 24h change, 7-day sparkline; lazy logo');
ok(in_array('ethereum', $blocks->liveIds(), true) && str_contains($rendered['price'], 'data-live-id="ethereum"'), 'and joins the existing live-price client');
ok(str_contains($rendered['pillars'], 'aria-current="page" href="https://thehybit.com/coins/ethereum/learn/"'), 'pillar navigation: the current pillar is marked');
ok(!str_contains($rendered['related'], 'what-is-ethereum') && str_contains($rendered['related'], 'ethereum-wallet'), 'related articles exclude the one being read');
ok(str_contains($rendered['stats'], 'رتبه بازار') && str_contains($rendered['stats'], 'ارزش بازار') && str_contains($rendered['stats'], 'Gwei'),
   'key stats: rank, market cap, gas — only rows with a real cached value');
$GLOBALS['thb_query_vars'] = [];
unset($GLOBALS['thb_post']);
$tick = $plugin->cache->peek('ticker', $plugin->cache->keyFor('ticker'));
$plugin->cache->forget('ticker', $plugin->cache->keyFor('ticker'));
$btc = $plugin->coins->find(11);
foreach (['market', 'chart.7d'] as $ds) { $plugin->cache->forget($ds, $plugin->cache->keyFor($ds, $btc)); }
$empty = array_map(static fn($n) => $blocks->render($n, ['coin' => 'bitcoin', 'period' => '7d']), ['price', 'converter', 'chart', 'stats']);
ok($empty === ['', '', '', ''], 'no cached data for the coin: price, converter, chart and stats render nothing at all');
ok($blocks->render('next', ['coin' => 'ethereum', 'pillar' => 'news']) === '' && $blocks->render('faq', ['text' => '']) === ''
   && $blocks->render('pillars', ['coin' => 'bitcoin']) !== '' && $blocks->render('toc', []) === '',
   'next step to an empty pillar, an empty FAQ, a TOC outside an article: nothing');
$plugin->cache->put('ticker', $plugin->cache->keyFor('ticker'), $tick['data']);

section('sitemap');
$entries = $hub->sitemapEntries();
$locs = array_column($entries, 'lastmod', 'loc');
ok(array_key_exists('https://thehybit.com/coins/ethereum/analysis/', $locs) && isset($locs['https://thehybit.com/coins/ethereum/learn/'], $locs['https://thehybit.com/coins/ethereum/analysis/'], $locs['https://thehybit.com/coins/ethereum/staking/'],
   $locs['https://thehybit.com/coins/ethereum/learn/what-is-ethereum/'], $locs['https://thehybit.com/coins/bitcoin/learn/'])
   && !isset($locs['https://thehybit.com/coins/ethereum/news/']), 'pillars with content and their articles are in the plugin sitemap; empty pillars are not');
ok($locs['https://thehybit.com/coins/ethereum/learn/'] === '2026-10-28T09:00:00+00:00' && $locs['https://thehybit.com/coins/ethereum/analysis/'] === '2026-10-20T08:00:00+00:00'
   && $locs['https://thehybit.com/coins/ethereum/learn/what-is-ethereum/'] === '2026-10-28T09:00:00+00:00',
   'honest lastmod: an article\'s own modified time; a pillar\'s newest article or the time its intro was saved');
ok($hub->excludeFromPostsSitemap([], 'post')['tax_query'][0]['operator'] === 'NOT EXISTS' && in_array(101, $hub->yoastExclude([]), true),
   'hub articles are left out of the posts sitemap, so no URL is listed twice');

if (getenv('THB_HUB_DIR')) {
    $dir = rtrim((string) getenv('THB_HUB_DIR'), '/');
    file_put_contents($dir . '/hub-ethereum.html', $hubPage);
    file_put_contents($dir . '/hub-learn.html', $learn);
    file_put_contents($dir . '/hub-article.html', $article);
}

echo $failed ? "\n{$failed} FAILED\n" : "\nAll hub tests passed.\n";
exit($failed ? 1 : 0);
