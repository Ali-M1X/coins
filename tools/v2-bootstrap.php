<?php
/**
 * Shared set-up for rendering the v2 page outside WordPress.
 *
 * Used by v2-render.test.php (assertions against fixtures) and v2-showcase.php
 * (pages for design screenshots). Same WordPress double as the classic render
 * test; the difference is the stand-in header and footer, which load the v2
 * stylesheet, data island and module instead of the classic ones — exactly
 * what Plugin::assetsV2() enqueues in production.
 *
 * Two coins are configured, Ethereum (post 10) and Bitcoin (post 11), from the
 * same seed file production uses, so the editorial fields under test are the
 * ones that ship.
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

function plugin_dir_path(string $file): string { return dirname($file) . '/'; }
function plugin_dir_url(string $file): string { return 'https://thehybit.com/wp-content/plugins/thehybit-coins/'; }
function register_activation_hook(string $f, $cb): void {}
function register_deactivation_hook(string $f, $cb): void {}
function locate_template($t, bool $load = false, bool $once = true): string { return ''; }
function is_singular($t = ''): bool { return true; }
function get_the_ID(): int { return (int) ($GLOBALS['thb_current_post'] ?? 10); }
function register_post_type(string $t, array $a) { return null; }
function wp_enqueue_style(...$a): void { $GLOBALS['thb_enqueued'][] = $a[0]; }
function wp_enqueue_script(...$a): void { $GLOBALS['thb_enqueued'][] = $a[0]; }
function wp_add_inline_script(...$a): bool { $GLOBALS['thb_inline'][$a[0]] = $a[1]; return true; }
function add_submenu_page(...$a) { return ''; }
function wp_get_script_tag(array $attr): string { return '<script></script>'; }
function submit_button(...$a): void {}
function flush_rewrite_rules(bool $hard = true): void {}

function get_header(...$a): void
{
    $plugin = \TheHybit\Coins\Plugin::instance();
    $coin = $plugin->coins->find(get_the_ID());
    $title = $coin ? 'قیمت ' . $coin->name . ' (' . $coin->symbol . ') امروز — های‌بیت' : 'های‌بیت';
    echo "<!DOCTYPE html>\n<html lang=\"fa\" dir=\"rtl\">\n<head>\n";
    echo "<meta charset=\"utf-8\">\n";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    echo "<meta name=\"robots\" content=\"noindex, follow\">\n";
    echo "<title>" . htmlspecialchars($title) . "</title>\n";
    echo "<link rel=\"stylesheet\" href=\"" . ($GLOBALS['thb_asset_base'] ?? 'thehybit-coins/') . "assets/v2/v2.css\">\n";
    echo "</head>\n<body>\n";
    /* Like the live theme: every page sits in a narrow boxed column, header
       included. v2 must break out of it; the box is drawn so a screenshot
       shows that it did. */
    if (!empty($GLOBALS['thb_theme_boxed'])) {
        echo "<div id=\"page\" style=\"max-width:560px;margin:0 auto;background:#fff;border:1px solid #ddd\">\n";
    }
    // A stand-in for the theme's header, so the page is judged inside a site.
    echo "<header style=\"padding:14px 16px;background:#070b14;border-bottom:1px solid #18202f;color:#e8edf5;font:600 18px Vazirmatn,sans-serif\">های‌بیت <small style=\"font-weight:400;color:#8b97a8\">(سربرگ قالب — داخل ستون باریک قالب)</small></header>\n";
}

function get_footer(...$a): void
{
    $plugin = \TheHybit\Coins\Plugin::instance();
    $coin = $plugin->coins->find(get_the_ID());
    echo "\n<script>window.__THB_V2__ = "
       . wp_json_encode(\TheHybit\Coins\V2\Model::client($plugin->v2($coin)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
       . ";</script>\n";
    echo "<script type=\"module\" src=\"" . ($GLOBALS['thb_asset_base'] ?? 'thehybit-coins/') . "assets/v2/v2.js\"></script>\n";
    if (!empty($GLOBALS['thb_theme_boxed'])) {
        echo "<footer style=\"padding:14px 16px;color:#555;font:13px sans-serif\">پانویس قالب</footer></div>\n";
    }
    echo "</body>\n</html>";
}

/* Keys are present: the v2 screens are judged with every wired provider on,
   which is how the site runs once the keys are pasted in. */
$GLOBALS['thb_options']['thb_coins_settings'] = [
    'etherscan_key'   => 'ETHERSCANKEY1234567890ABCDEF',
    'beaconchain_key' => 'BEACONKEY123456',
    'github_token'    => 'ghp_testtoken1234567890',
    'trade_url'       => 'https://thehybit.com/trade/{symbol}-usdt',
];

$base = __DIR__ . '/../thehybit-coins/';
require_once $base . 'thehybit-coins.php';

$seeds = require $base . 'config/coins.php';
$GLOBALS['thb_coin_fields'] = [
    10 => $seeds['ethereum']['fields'] + ['thb_dex_symbols' => 'WETH'],
    11 => $seeds['bitcoin']['fields'],
];
$GLOBALS['thb_post_slugs'] = [10 => 'ethereum', 11 => 'bitcoin'];
$GLOBALS['thb_coin_ids'] = [10, 11];
$GLOBALS['thb_probe_dex_symbol'] = 'WETH';

/** Every dataset the scheduler would warm, for one coin. */
function v2_warm(int $postId): void
{
    $plugin = \TheHybit\Coins\Plugin::instance();
    $config = $plugin->config;
    $coin = $plugin->coins->find($postId);
    $GLOBALS['thb_probe_background'] = true;
    $GLOBALS['thb_probe_slug'] = $coin->slug;
    $datasets = array_merge(
        array_map(static fn($p) => 'chart.' . $p, array_keys($config['chart']['windows'])),
        ['market', 'metadata', 'historical', 'defi', 'dex', 'fx', 'global', 'categories', 'sentiment',
         'chains', 'stablecoins', 'structure', 'gas', 'supply', 'network', 'staking', 'development',
         'peers', 'protocols', 'longchart']
    );
    foreach ($datasets as $dataset) {
        foreach (array_keys($config['providers']) as $provider) {
            (new \TheHybit\Coins\Budget($config))->reset($provider);
        }
        $plugin->pipeline->warm($coin, $dataset);
    }
    $GLOBALS['thb_probe_background'] = false;
}

/** Render the v2 template for one post, as a visitor with ?thb_design=v2. */
function v2_render(int $postId): string
{
    $GLOBALS['thb_current_post'] = $postId;
    $_GET['thb_design'] = 'v2';
    $plugin = \TheHybit\Coins\Plugin::instance();
    $template = $plugin->template('');
    // A fresh model per render: the memo would otherwise pin the first one.
    (function () { $this->v2Memo = []; })->call($plugin);
    ob_start();
    require $template;
    $html = (string) ob_get_clean();
    unset($_GET['thb_design']);
    return $html;
}
