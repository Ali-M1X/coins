<?php
/**
 * Template render tests — the actual page, not a model of it.
 *
 *   php tools/template-render.test.php
 *   THB_DUMP_HTML=/path/page.html php tools/template-render.test.php
 *
 * Renders templates/single-coin.php through the real pipeline and asserts
 * against the HTML that comes out. This is the layer the two earlier files
 * cannot reach: a view model can be perfect while a partial silently drops a
 * section, and the only way to know is to look at the output.
 *
 * It matters most right now because the v2 design work must not touch the
 * classic design. Everything asserted here is a property of the CLASSIC page,
 * so if adding the design switch changes any of it, this fails.
 *
 * SEO properties are asserted here too, for a reason the brief makes explicit:
 * the figures have to be in the initial HTML, not fetched by JavaScript. That
 * is testable only against rendered markup.
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

function plugin_dir_path(string $file): string { return dirname($file) . '/'; }
function plugin_dir_url(string $file): string { return 'https://thehybit.com/wp-content/plugins/thehybit-coins/'; }
function register_activation_hook(string $f, $cb): void {}
function register_deactivation_hook(string $f, $cb): void {}
function locate_template($t, bool $load = false, bool $once = true): string { return ''; }
function is_singular($t = ''): bool { return true; }
function get_the_ID(): int { return 10; }
function register_post_type(string $t, array $a) { return null; }
function wp_enqueue_style(...$a): void {}
function wp_enqueue_script(...$a): void {}
function wp_add_inline_script(...$a): bool { return true; }
function add_submenu_page(...$a) { return ''; }
function wp_get_script_tag(array $attr): string { return '<script></script>'; }
function submit_button(...$a): void {}
function flush_rewrite_rules(bool $hard = true): void {}

/* Harika supplies these in production; the plugin template is content only.
 *
 * The stand-in emits a complete document — charset, viewport, the plugin's own
 * stylesheets and its ES-module entry point — so a dumped page can be opened in
 * a real browser and the SHIPPING CSS and JS tested rather than a description of
 * them. Asset paths are repo-relative, which is why the dump is written to the
 * repository root. */
function get_header(...$a): void
{
    echo "<!DOCTYPE html>\n<html lang=\"fa\" dir=\"rtl\">\n<head>\n";
    echo "<meta charset=\"utf-8\">\n";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    echo "<title>قیمت اتریوم (ETH) امروز — های‌بیت</title>\n";
    foreach (['tokens', 'base', 'components', 'coin-page'] as $sheet) {
        echo "<link rel=\"stylesheet\" href=\"thehybit-coins/assets/css/{$sheet}.css\">\n";
    }
    echo "</head>\n<body>\n";
}

function get_footer(...$a): void
{
    /* The data island, exactly as Plugin::assets() injects it, followed by the
       module that reads it. Without the island every JS-driven part of the page
       is inert — which is the bug the script_loader_tag filter exists to
       prevent, so the dump has to carry it or the browser test proves nothing. */
    $plugin = \TheHybit\Coins\Plugin::instance();
    $coin = $plugin->coins->find(10);
    echo "\n<script>window.__THB_COIN__ = "
       . wp_json_encode($plugin->pipeline->viewModel($coin), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
       . ";</script>\n";
    echo "<script type=\"module\" src=\"thehybit-coins/assets/js/main.js\"></script>\n";
    echo "</body>\n</html>";
}

$base = __DIR__ . '/../thehybit-coins/';
require_once $base . 'thehybit-coins.php';

use TheHybit\Coins\{Coin, Cache, Budget, Plugin};

$config = require $base . 'config/providers.php';
$GLOBALS['thb_cfg'] = $config;

$failed = 0;
function ok(bool $cond, string $msg): void
{
    global $failed;
    if (!$cond) { $failed++; }
    echo ($cond ? 'PASS' : 'FAIL') . "  {$msg}\n";
}
function section(string $s): void { echo "\n--- {$s} " . str_repeat('-', max(0, 58 - strlen($s))) . "\n"; }

$GLOBALS['thb_acf'] = [
    'thb_symbol' => 'ETH', 'thb_name_fa' => 'اتریوم', 'thb_name_en' => 'Ethereum',
    'thb_coingecko_id' => 'ethereum', 'thb_news_category_slug' => 'ethereum-news',
    'thb_defillama_chain' => 'Ethereum', 'thb_dex_token_address' => '0xC02aaA39',
    'thb_dex_symbols' => 'WETH',
    'thb_description' => 'اتریوم یک بستر غیرمتمرکز برای قراردادهای هوشمند است که '
        . 'توسعه‌دهندگان را قادر می‌سازد برنامه‌های غیرمتمرکز بسازند.',
    'thb_founder' => 'ویتالیک بوترین',
    'thb_launch_date' => '2015-07-30',
    'thb_consensus' => 'اثبات سهام',
    'thb_website' => 'https://ethereum.org',
    'thb_enable_defi' => 1, 'thb_enable_dex' => 1,
];
$GLOBALS['thb_post_slugs'] = [10 => 'ethereum'];
$GLOBALS['thb_coin_ids'] = [10];
$GLOBALS['thb_probe_slug'] = 'ethereum';
$GLOBALS['thb_probe_dex_symbol'] = 'WETH';

/**
 * Render the page the way production does: cron warms, then a visitor arrives.
 *
 * Warming first is not test scaffolding around an inconvenience — it IS the
 * production sequence. The Group A datasets are cache-only on render, so a page
 * that has never been warmed shows its "not yet received" state, which is
 * asserted separately below.
 */
function render_page(bool $warm = true): string
{
    $plugin = Plugin::instance();
    $config = $GLOBALS['thb_cfg'];
    $coin = $plugin->coins->find(10);

    if ($warm) {
        $GLOBALS['thb_probe_background'] = true;
        $datasets = array_merge(
            array_map(static fn($p) => 'chart.' . $p, array_keys($config['chart']['windows'])),
            ['market', 'metadata', 'historical', 'defi', 'dex', 'fx',
             'global', 'categories', 'sentiment', 'chains', 'stablecoins', 'structure']
        );
        foreach ($datasets as $dataset) {
            foreach (array_keys($config['providers']) as $provider) {
                (new Budget($config))->reset($provider);
            }
            $plugin->pipeline->warm($coin, $dataset);
        }
        $GLOBALS['thb_probe_background'] = false;
    }

    $plugin->pipeline->viewModel($coin, refresh: false);

    ob_start();
    require __DIR__ . '/../thehybit-coins/templates/single-coin.php';
    return (string) ob_get_clean();
}

Probe::reset();
Clock::reset();
$html = render_page();

/* =====================================================================
 * 1. The page renders, and every section is in it
 * ================================================================== */
section('sections');

ok(strlen($html) > 10000, 'the template rendered (' . number_format(strlen($html)) . ' bytes)');
ok(!str_contains($html, 'Fatal error') && !str_contains($html, 'Warning:'),
   'with no PHP notices leaking into the markup');

$expected = [
    'thb-coin'            => 'the root wrapper',
    'thb-breadcrumb'      => 'breadcrumb',
    'thb-header__main'    => 'coin header',
    'thb-chart-card'      => 'price chart',
    'thb-perf'            => 'performance',
    'thb-market-context-title' => 'market context',
    'thb-about'           => 'about',
    'thb-tokenomics'      => 'tokenomics',
    'thb-ecosystem-title' => 'network & ecosystem',
    'thb-structure-title' => 'market structure',
    'thb-analytics'       => 'TheHybit analytics',
    'thb-news'            => 'news',
];
foreach ($expected as $needle => $label) {
    ok(str_contains($html, $needle), "{$label} is present");
}

/* The DEX card was merged into Market Structure; its data must still render. */
ok(!str_contains($html, 'thb-dex-title'),
   'the standalone DEX card is gone — its figures live in Market Structure now');
ok(str_contains($html, 'ETH/USDC'), 'and the top decentralised pair still renders');

/* =====================================================================
 * 2. Server-side rendering — the SEO requirement
 *
 * Every headline figure must be in the initial HTML. A number that only
 * appears after JavaScript runs is a number Google does not see.
 * ================================================================== */
section('server-side rendering');

foreach ([
    '$3,245.67'  => 'the price',
    '390.23B'    => 'market cap',
    '18.74B'     => '24h volume',
    'اتریوم'      => 'the Persian name',
    'ETH'        => 'the symbol',
] as $needle => $label) {
    ok(str_contains($html, $needle), "{$label} is in the initial HTML, not fetched by JS");
}

ok(str_contains($html, 'ویتالیک بوترین'), 'the editorial About text is server-rendered');
ok(substr_count($html, 'اتریوم') > 3, 'the coin name appears throughout the prose, not just once');

/* The data island carries series for the chart, so the browser draws rather
   than fetches. */
ok(!str_contains($html, 'api.coingecko.com') && !str_contains($html, 'api.llama.fi'),
   'NO provider URL appears in the markup — the browser never calls a provider');

/* =====================================================================
 * 3. Persian RTL correctness
 * ================================================================== */
section('RTL');

ok(str_contains($html, 'dir="rtl"'), 'the document is RTL');
ok(str_contains($html, 'lang="fa"'), 'and declared Persian');

/* Mixed-direction content must be isolated or the digits visually scramble
   against the Persian text around them. */
ok(substr_count($html, 'thb-num') > 5,
   'numbers are wrapped in the bidi-isolating class (' . substr_count($html, 'thb-num') . ' of them)');
ok(str_contains($html, '<bdi>'), 'and non-numeric Latin runs are wrapped in <bdi>');

/* =====================================================================
 * 4. Availability states — never a fake zero
 * ================================================================== */
section('availability');

ok(!str_contains($html, 'thb-datastate--pending'),
   'a warmed page shows no "not yet received" state');

/* An unwarmed page must say so rather than render zeros. */
Probe::reset();
Probe::$transients = [];
$GLOBALS['thb_options'] = [];
Plugin::instance()->pipeline->viewModel(Plugin::instance()->coins->find(10), refresh: true);
$cold = render_page(warm: false);

ok(str_contains($cold, 'thb-datastate--pending'),
   'an unwarmed page reports "در حال دریافت داده" for the Group A sections');
ok(str_contains($cold, 'در حال دریافت داده'), 'in Persian, as the visitor reads it');
ok(!preg_match('/thb-stat__value">\s*<span class="thb-num">0<\/span>/', $cold),
   'and never substitutes a bare 0 for a figure nobody has measured');

/* =====================================================================
 * 5. Accessibility
 * ================================================================== */
section('accessibility');

ok(substr_count($html, 'aria-labelledby') >= 5,
   'sections are labelled for screen readers (' . substr_count($html, 'aria-labelledby') . ')');
ok(substr_count($html, '<h2') >= 5, 'with a real heading hierarchy (' . substr_count($html, '<h2') . ' h2s)');

preg_match_all('/<img[^>]*>/', $html, $imgs);
$missingAlt = 0;
foreach ($imgs[0] as $img) {
    if (!str_contains($img, 'alt=')) { $missingAlt++; }
}
ok($missingAlt === 0, 'every <img> carries alt text (' . count($imgs[0]) . ' images checked)');

/* =====================================================================
 * 6. The classic design is FROZEN
 *
 * The v2 design is being built beside the classic one, and the brief is
 * explicit that the classic templates, CSS and JS must not change. Asserting
 * that a few sections are present would let a changed class name, a moved
 * figure or a restyled card through. So the whole rendered page is compared
 * byte for byte with a snapshot taken before the v2 work began, and every
 * classic template, stylesheet and script is pinned by hash.
 *
 * To deliberately change the classic design later, regenerate with
 * THB_UPDATE_GOLDEN=1 and review the diff in the commit.
 * ================================================================== */
section('classic design frozen');

require_once __DIR__ . '/golden-normalize.php';
$goldenPath = __DIR__ . '/golden/classic.html';
$current = thb_golden_normalize($html);

if (getenv('THB_UPDATE_GOLDEN')) {
    file_put_contents($goldenPath, $current);
    echo "  (golden updated)\n";
}
$golden = (string) file_get_contents($goldenPath);
ok($current === $golden,
   'the classic page renders BYTE-IDENTICAL to the pre-v2 snapshot (' . number_format(strlen($golden)) . ' bytes)'
   . ($current === $golden ? '' : ' — first difference near byte ' . strspn($current ^ $golden, "\0")));

$manifest = file(__DIR__ . '/golden/classic-files.sha256', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$changed = [];
foreach ($manifest as $row) {
    [$hash, $file] = preg_split('/\s+/', trim($row), 2);
    $path = __DIR__ . '/../thehybit-coins/' . $file;
    if (!is_file($path) || hash_file('sha256', $path) !== $hash) {
        $changed[] = $file;
    }
}
ok(count($manifest) > 20, 'the classic file manifest pins ' . count($manifest) . ' templates, stylesheets and scripts');
ok($changed === [], 'none of them has changed' . ($changed ? ': ' . implode(', ', $changed) : ''));


if (getenv('THB_DUMP_HTML')) {
    file_put_contents((string) getenv('THB_DUMP_HTML'), $html);
}

echo $failed ? "\n{$failed} FAILED\n" : "\nAll template render tests passed.\n";
exit($failed ? 1 : 0);
