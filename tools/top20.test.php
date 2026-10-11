<?php
/**
 * The top-20 coin pages (2.3.0): every coin in config/coins.php rendered with
 * the v2 template from its own configuration, against the fixture providers.
 *
 *   php tools/top20.test.php            assertions
 *   THB_STATUS_MD=out.md php tools/top20.test.php   also writes the per-coin
 *                                       section status table
 *
 * What it checks for every coin: the page renders without a PHP notice; its
 * title, meta description and FAQ are its own (not shared with any other
 * coin); a section is drawn only where a source exists for that coin; and a
 * coin without a source for a figure never shows a number for it.
 */

declare(strict_types=1);

require_once __DIR__ . '/v2-bootstrap.php';

use TheHybit\Coins\Seo;
use TheHybit\Coins\V2\Model;

$failed = 0;
function ok(bool $cond, string $msg): void
{
    global $failed;
    if (!$cond) { $failed++; }
    echo ($cond ? 'PASS' : 'FAIL') . "  {$msg}\n";
}
function section(string $s): void { echo "\n--- {$s} " . str_repeat('-', max(0, 58 - strlen($s))) . "\n"; }

$plugin = \TheHybit\Coins\Plugin::instance();
$seeds = require THB_COINS_DIR . 'config/coins.php';

/* Every coin as a post: Ethereum and Bitcoin keep 10 and 11. */
$ids = [10 => 'ethereum', 11 => 'bitcoin'];
$next = 100;
foreach ($seeds as $slug => $spec) {
    if (isset(array_flip($ids)[$slug])) {
        continue;
    }
    $GLOBALS['thb_coin_fields'][$next] = $spec['fields'];
    $GLOBALS['thb_post_slugs'][$next] = $slug;
    $ids[$next] = $slug;
    $next++;
}
$GLOBALS['thb_coin_ids'] = array_keys($ids);

section('configuration');
ok(count($seeds) === 20, 'config/coins.php lists 20 coins (' . count($seeds) . ')');
$gecko = array_column(array_column($seeds, 'fields'), 'thb_coingecko_id');
ok(count(array_unique($gecko)) === 20, 'each with its own CoinGecko id');
foreach (['tether', 'xrp', 'bnb', 'solana', 'usdc', 'dogecoin', 'cardano', 'litecoin'] as $slug) {
    ok(isset($seeds[$slug]), "/coins/{$slug}/ is one of them");
}
foreach ($seeds as $slug => $spec) {
    $f = $spec['fields'];
    $missing = array_diff(['thb_symbol', 'thb_name_fa', 'thb_coingecko_id', 'thb_description', 'thb_tagline', 'thb_timeline',
                           'thb_learn', 'thb_audience', 'thb_faq', 'thb_seo_title', 'thb_meta_description', 'thb_price_events'], array_keys($f));
    if ($missing !== []) {
        ok(false, "{$slug}: editorial fields missing: " . implode(', ', $missing));
    }
    foreach (preg_split('/\n/', (string) ($f['thb_price_events'] ?? '')) as $line) {
        if (trim($line) !== '' && !preg_match('/^\d{4}-\d{2}-\d{2} \|/', $line)) {
            ok(false, "{$slug}: a price event without a full date: {$line}");
        }
    }
    if (!empty($f['thb_launch_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['thb_launch_date'])) {
        ok(false, "{$slug}: launch date is not YYYY-MM-DD");
    }
}
ok(true, 'every coin has the full editorial set, full-date market events and well-formed dates');

section('unique SEO and FAQ');
$seo = new Seo($plugin->coins, $plugin->pipeline);
$titles = $descs = $faqs = [];
foreach ($ids as $id => $slug) {
    $GLOBALS['thb_current_post'] = $id;
    $titles[$slug] = $seo->title('fallback');
    $descs[$slug] = $seo->description('fallback');
    $faqs[$slug] = (string) ($seeds[$slug]['fields']['thb_faq'] ?? '');
}
unset($GLOBALS['thb_current_post']);
ok(count(array_unique($titles)) === 20 && !in_array('fallback', $titles, true), 'all 20 titles are different and hand-written');
ok(count(array_unique($descs)) === 20 && min(array_map('mb_strlen', $descs)) >= 100, 'all 20 meta descriptions are different and substantial');
$questions = [];
foreach ($faqs as $slug => $faq) {
    foreach (preg_split('/\n/', $faq) as $line) {
        $questions[] = trim(explode('|', $line)[0]);
    }
}
ok(count($questions) === count(array_unique($questions)) && count($questions) >= 60, 'no FAQ question is repeated across coins (' . count($questions) . ' questions)');
foreach ($titles as $slug => $t) {
    $name = $seeds[$slug]['fields']['thb_name_fa'];
    $sym = $seeds[$slug]['fields']['thb_symbol'];
    if (!str_contains($t, $name) && !str_contains($t, $sym)) {
        ok(false, "{$slug}: the title names neither the coin nor its symbol");
    }
}

section('every page renders');
Probe::reset();
Clock::reset();
foreach (array_keys($ids) as $id) {
    v2_warm($id);
}
$status = [];
$pages = [];
foreach ($ids as $id => $slug) {
    $html = v2_render($id);
    $pages[$slug] = $html;
    $v = $plugin->v2($plugin->coins->find($id));
    $status[$slug] = ['name' => $seeds[$slug]['fields']['thb_name_fa'], 'symbol' => $seeds[$slug]['fields']['thb_symbol'], 'sections' => $v['sections']];
    $clean = !str_contains($html, 'Warning:') && !str_contains($html, 'Deprecated:') && !str_contains($html, 'Fatal');
    ok($clean && substr_count($html, '<h1') === 1 && str_contains($html, 'id="overview"'),
       "{$slug}: renders, one h1" . ($clean ? '' : ' — PHP notice in the output'));
    foreach ($v['sections'] as $sec => $st) {
        $inPage = str_contains($html, 'id="' . $sec . '"');
        if ($inPage !== $st['show']) {
            ok(false, "{$slug}: section {$sec} is " . ($inPage ? 'drawn' : 'missing') . " but the map says " . ($st['show'] ? 'show' : 'hide'));
        }
        if (!$st['show'] && str_contains($html, 'data-v2-tab="' . $sec . '"')) {
            ok(false, "{$slug}: a hidden section ({$sec}) still has a tab");
        }
    }
}

section('nothing shown without a source');
$s = static fn(string $slug, string $sec) => $status[$slug]['sections'][$sec];
foreach (['tether', 'usdc', 'chainlink', 'shiba-inu'] as $token) {
    ok(!$s($token, 'ecosystem')['show'] && !$s($token, 'flow')['show'],
       "{$token}: a token, not a chain — no ecosystem, no chain flow");
    ok(!str_contains($pages[$token], 'ارزش کل قفل‌شده (TVL)'), "{$token}: no chain TVL row");
}
foreach (['dogecoin', 'litecoin', 'bitcoin-cash', 'bitcoin'] as $pow) {
    ok($s($pow, 'flow')['show'] && !$s($pow, 'ecosystem')['show'] && str_contains($pages[$pow], 'هش‌ریت'),
       "{$pow}: proof of work — network pulse with hash rate (Blockchair), no DeFi sections");
    ok(!str_contains($pages[$pow], 'بازده استیکینگ (APR)'), "{$pow}: no staking-yield row");
}
foreach (['solana', 'cardano', 'avalanche', 'tron', 'sui'] as $pos) {
    ok($s($pos, 'ecosystem')['show'] && !str_contains($pages[$pos], 'این شبکه استیکینگ ندارد'),
       "{$pos}: ecosystem from DefiLlama, and never told it «has no staking» (it does; there is just no free source)");
    ok(!str_contains($pages[$pos], 'گس پیشنهادی') && !str_contains($pages[$pos], 'اعتبارسنج‌های فعال'),
       "{$pos}: no Ethereum gas or beaconcha.in validator rows");
}
foreach ($pages as $slug => $html) {
    if ($slug !== 'ethereum' && (str_contains($html, 'از Etherscan نرسیده') || str_contains($html, 'رپ‌شده به WETH'))) {
        ok(false, "{$slug}: shows an Ethereum-only (Etherscan) supply row");
    }
}
ok(true, 'no Ethereum-only supply rows (WETH, bridges, exchange wallets) on any other coin');
ok(in_array('بازده استیکینگ', $status['tron']['sections']['overview']['gaps'], true)
   && in_array('بازده استیکینگ', $status['tether']['sections']['overview']['na'], true),
   'tron\'s staking yield is "no free source" (it has staking); tether\'s is "not applicable"');
ok(str_contains($pages['ethereum'], 'بازده استیکینگ (APR)') && $s('ethereum', 'ecosystem')['show'] && $s('ethereum', 'flow')['show'],
   'ethereum keeps every section');
ok(str_contains($pages['tether'], 'USDC') && !str_contains(strip_tags(explode('id="peers"', $pages['tether'])[1] ?? ''), 'Bitcoin'),
   'tether is compared with stablecoins (USDC …), not with Bitcoin');
ok(str_contains($pages['dogecoin'], 'blockchair.com/dogecoin/transaction/') || !str_contains($pages['dogecoin'], 'blockchair.com/bitcoin/transaction/'),
   'dogecoin whale links point at the Dogecoin explorer, never Bitcoin\'s');

/* No coin shows a sample/reference figure, and no staking APR appears
   anywhere except where a source exists (Ethereum: beaconcha.in / Lido pool). */
foreach ($pages as $slug => $html) {
    if ($slug !== 'ethereum' && preg_match('#بازده استیکینگ \(APR\)</span>\s*<span class="v2-kv__value">#u', $html)) {
        ok(false, "{$slug}: shows a staking APR figure without a source");
    }
}
ok(true, 'no staking APR figure outside Ethereum');

section('charts kept current by the market batch');
$GLOBALS['thb_probe_chart_realistic'] = true;
$GLOBALS['thb_probe_background'] = true;
Probe::reset();
Clock::reset();
$sol = $plugin->coins->find(array_search('solana', $ids, true));
$k24 = $plugin->cache->keyFor('chart.24h', $sol);
$plugin->pipeline->warm($sol, 'chart.24h');
$c0 = $plugin->cache->peek('chart.24h', $k24)['data'];
Clock::advance(300);
(new TheHybit\Coins\Budget($plugin->config))->reset('coingecko');
$before = Probe::count();
$plugin->pipeline->warmBatch([$sol], 'market');
$e1 = $plugin->cache->peek('chart.24h', $k24);
ok(Probe::count() - $before === 1 && count($e1['data']['t']) === count($c0['t']) + 1,
   'one batch request appends a new 5-minute point to the 24h chart (no chart request)');
ok((int) (end($e1['data']['t']) / 1000) === Clock::$now && $e1['fetchedAt'] === Clock::$now
   && $e1['data']['t'][0] >= (Clock::$now - 86400) * 1000, 'ending now, the window trimmed to 24 hours, and the entry fresh');
Clock::advance(120);
(new TheHybit\Coins\Budget($plugin->config))->reset('coingecko');
$plugin->pipeline->warmBatch([$sol], 'market');
$e2 = $plugin->cache->peek('chart.24h', $k24);
ok(count($e2['data']['t']) === count($e1['data']['t']) && (int) (end($e2['data']['t']) / 1000) === Clock::$now,
   'inside the same 5-minute step the last point moves instead of adding one');
Clock::advance(2 * 3600);
(new TheHybit\Coins\Budget($plugin->config))->reset('coingecko');
$plugin->pipeline->warmBatch([$sol], 'market');
$e3 = $plugin->cache->peek('chart.24h', $k24);
ok((int) (end($e3['data']['t']) / 1000) === (int) (end($e2['data']['t']) / 1000),
   'a two-hour hole is not patched over: the window is left for a full download');
unset($GLOBALS['thb_probe_chart_realistic']);

section('the live ticker keeps its slot');
$b = new TheHybit\Coins\Budget($plugin->config);
$b->reset('coingecko');
Clock::reset();
$b->record('coingecko', true);              // tick T: the ticker
$okA = $b->refuse('coingecko') === null; $b->record('coingecko');
$okB = $b->refuse('coingecko') === null; $b->record('coingecko');
$third = $b->refuse('coingecko');
ok($okA && $okB && $third !== null, 'after the ticker, two ordinary calls this tick, not three (' . $third . ')');
Clock::advance(55);                           // tick T+55: the previous tick's calls are still in the minute
ok($b->refuse('coingecko', reserved: true) === null, 'the next tick\'s ticker call is still allowed (3 in the minute, under 4)');
$b->record('coingecko', true);
ok($b->refuse('coingecko') !== null, 'and the minute\'s total cap (4) holds ordinary calls back after it');
Clock::advance(10);
ok($b->refuse('coingecko') === null, 'once the earlier tick leaves the 60-second window, ordinary calls resume');

/* ---- the status table ---- */
$fa = ['overview' => 'نگاه کلی', 'ecosystem' => 'اکوسیستم', 'flow' => 'جریان/شبکه', 'story' => 'داستان', 'dna' => 'DNA',
       'price-story' => 'قیمت به‌روایت', 'pulse' => 'پالس', 'peers' => 'مقایسه', 'supply' => 'عرضه و ریسک'];
$en = ['overview' => 'Overview', 'ecosystem' => 'Ecosystem', 'flow' => 'Chain flow / network', 'story' => 'Story', 'dna' => 'DNA',
       'price-story' => 'Price story', 'pulse' => 'Daily pulse', 'peers' => 'Peers', 'supply' => 'Supply & risk'];
$md = "| Coin | Full | Partial — missing for want of a free source | Not applicable to this coin | Fills in after launch |\n|---|---|---|---|---|\n";
foreach ($status as $slug => $row) {
    $full = $partial = $na = $later = [];
    foreach ($row['sections'] as $sec => $st) {
        if ($st['status'] === 'full') {
            $full[] = $en[$sec];
        } elseif ($st['status'] === 'partial') {
            $partial[] = $en[$sec] . ': ' . implode('، ', $st['gaps']);
        } else {
            $na[] = '**' . $en[$sec] . '** (section)';
        }
        foreach ($st['na'] as $piece) {
            $na[] = $en[$sec] . ': ' . $piece;
        }
        foreach ($st['later'] as $piece) {
            $later[] = $en[$sec] . ': ' . $piece;
        }
    }
    $md .= sprintf("| %s (%s)<br>`/coins/%s/` | %s | %s | %s | %s |\n", $row['name'], $row['symbol'], $slug,
        implode(', ', $full) ?: '—', implode('<br>', $partial) ?: '—', implode('<br>', $na) ?: '—', implode('<br>', $later) ?: '—');
}
if (getenv('THB_STATUS_MD')) {
    file_put_contents((string) getenv('THB_STATUS_MD'), $md);
}
if (getenv('THB_TOP20_DIR')) {
    foreach (['solana', 'tether', 'dogecoin', 'xrp'] as $slug) {
        file_put_contents(rtrim((string) getenv('THB_TOP20_DIR'), '/') . "/v2-{$slug}.html", $pages[$slug]);
    }
}

echo $failed ? "\n{$failed} FAILED\n" : "\nAll top-20 tests passed.\n";
exit($failed ? 1 : 0);
