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
ok(count($seeds) === 50, 'config/coins.php lists 50 coins: the 20 of 2.3 and the 30 of batch 3 (' . count($seeds) . ')');
$gecko = array_column(array_column($seeds, 'fields'), 'thb_coingecko_id');
ok(count(array_unique($gecko)) === 50, 'each with its own CoinGecko id');
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

/* 2.5.0: the story and the chart's events. */
$overlay = require THB_COINS_DIR . 'config/editorial-2.5.php';
foreach ($seeds as $slug => $spec) {
    $f = $spec['fields'];
    $tl = array_values(array_filter(preg_split('/\n/', (string) ($f['thb_timeline'] ?? ''))));
    $ev = array_values(array_filter(preg_split('/\n/', (string) ($f['thb_price_events'] ?? ''))));
    if (count($tl) < 7) {
        ok(false, "{$slug}: only " . count($tl) . ' timeline events (at least 7)');
    }
    foreach (array_merge($tl, $ev) as $line) {
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) !== 3) {
            ok(false, "{$slug}: malformed line: {$line}");
            continue;
        }
        [$date, $title, $text] = $parts;
        if (!preg_match('/^(>.+|\d{4}(-\d{2}(-\d{2})?)?)$/u', $date)) {
            ok(false, "{$slug}: bad date «{$date}»");
        }
        foreach (['title' => $title, 'explanation' => $text] as $what => $str) {
            if (!preg_match('/^[\x{0600}-\x{06FF}]/u', $str)) {
                ok(false, "{$slug}: {$what} does not start with a Persian letter: «{$str}»");
            }
        }
        if (mb_strlen($text) < 30) {
            ok(false, "{$slug}: explanation too short to be complete: «{$text}»");
        }
    }
    foreach ((array) ($overlay[$slug]['verify'] ?? []) as $v) {
        $hit = array_filter(array_merge($tl, $ev), static fn($l) => str_starts_with($l, $v . ' |'));
        if ($hit === []) {
            ok(false, "{$slug}: fact-check item «{$v}» matches no line");
        }
    }
}
ok(true, 'every coin: at least 7 timeline events; every title and explanation starts with a Persian letter; every fact-check item names a real line');


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
ok(count(array_unique($titles)) === 50 && !in_array('fallback', $titles, true), 'all 50 titles are different and hand-written');
ok(count(array_unique($descs)) === 50 && min(array_map('mb_strlen', $descs)) >= 100, 'all 50 meta descriptions are different and substantial');
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
section('batch 3 (2.6.0) — thirty more coins');
$original20 = ['ethereum', 'bitcoin', 'tether', 'xrp', 'bnb', 'solana', 'usdc', 'dogecoin', 'tron', 'cardano', 'hyperliquid',
               'chainlink', 'stellar', 'sui', 'bitcoin-cash', 'avalanche', 'hedera', 'litecoin', 'toncoin', 'shiba-inu'];
ok(array_slice(array_keys($seeds), 0, 20) === $original20, 'the 20 existing pages keep their slugs, so their URLs, in their order');
$batch = array_filter($seeds, static fn($sp) => ($sp['batch'] ?? null) === 3);
ok(count($batch) === 30, 'batch 3 adds exactly 30 coins (' . count($batch) . ')');
$batchIds = array_map(static fn($sp) => $sp['fields']['thb_coingecko_id'], $batch);
$oldIds = array_map(static fn($slug) => $seeds[$slug]['fields']['thb_coingecko_id'], $original20);
ok(array_intersect($batchIds, $oldIds) === [], 'no batch-3 coin is one of the existing 20 (by CoinGecko id)');
$excluded = ['ethena-usde', 'usds', 'dai', 'usd1-wlfi', 'paypal-usd', 'first-digital-usd', 'tether-gold', 'pax-gold', 'pi-network',
             'staked-ether', 'wrapped-steth', 'wrapped-bitcoin', 'weth', 'wrapped-eeth', 'coinbase-wrapped-btc', 'binance-bridged-usdt-bnb-smart-chain'];
ok(array_intersect($batchIds, $excluded) === [], 'no stablecoin, gold token, Pi, wrapped, bridged or staked token in batch 3');
$activeSrc = (string) file_get_contents(THB_COINS_DIR . 'config/coins-batch3.php');
preg_match('#\$active = \[(.*?)\];#s', $activeSrc, $am);
preg_match_all("#'([a-z0-9-]+)',#", $am[1] ?? '', $al);
ok($al[1] === array_keys($batch), 'the batch is exactly the one-line-per-coin $active list, in its order (swap = one line)');
foreach ($batch as $slug => $sp) {
    $v = $plugin->v2($plugin->coins->find(array_search($slug, $ids, true)));
    $f = $sp['fields'];
    if (!empty($f['thb_enable_dex']) && empty($f['thb_dex_token_address'])) {
        ok(false, "{$slug}: DEX on without a token address (a ticker search could match another coin)");
    }
    if (!empty($f['thb_enable_defi']) && empty($f['thb_defillama_chain'])) {
        ok(false, "{$slug}: DeFi on without a DefiLlama chain");
    }
    foreach (['overview', 'story', 'price-story', 'pulse', 'peers'] as $always) {
        if (!($v['sections'][$always]['show'] ?? false)) {
            ok(false, "{$slug}: the {$always} section is missing");
        }
    }
}
ok(true, 'every batch-3 coin: DEX only by contract address, DeFi only with a chain, core sections present');
$z = $plugin->v2($plugin->coins->find(array_search('zcash', $ids, true)));
ok(($z['sections']['flow']['show'] ?? false) && array_filter($z['flow']['pulse'], static fn($r) => $r['ds'] === 'network') !== [],
   'zcash gets the network pulse from Blockchair (same shapes as the other Bitcoin-style chains)');
$xmr = $plugin->v2($plugin->coins->find(array_search('monero', $ids, true)));
ok(!($xmr['sections']['flow']['show'] ?? true) && !($xmr['sections']['ecosystem']['show'] ?? true),
   'monero: no free source for its network or DeFi — those sections and their tabs are removed, not filled');
$liveMap = \TheHybit\Coins\LiveFeed::client($plugin->config, $batchIds);
$liveUsd = array_keys(array_filter($liveMap['coins'] ?? [], static fn($m) => !empty($m['usd'])));
ok(count($liveUsd) >= 20 && !isset($liveMap['coins']['leo-token']) && !isset($liveMap['coins']['pepe']['irt']),
   'live prices: ' . count($liveUsd) . ' of 30 new coins mapped; LEO stays on polling; PEPE gets no rescaled toman lot');
$sm = new \TheHybit\Coins\Sitemap($plugin->cache, $plugin->coins);
$aaveCoin = $plugin->coins->find(array_search('aave', $ids, true));
$plugin->cache->put('market', $plugin->cache->keyFor('market', $aaveCoin), ['price' => 1.0]);
$mk = $plugin->cache->peek('market', $plugin->cache->keyFor('market', $aaveCoin));
$post = (object) ['ID' => array_search('aave', $ids, true), 'post_modified_gmt' => '2024-01-01 00:00:00', 'post_type' => 'thb_coin'];
$entry = $sm->core(['loc' => 'x'], $post, 'thb_coin');
ok(isset($entry['lastmod']) && strtotime($entry['lastmod']) === (int) ($mk['fetchedAt'] ?? -1) && (int) $mk['fetchedAt'] > strtotime('2024-01-01'),
   'sitemap: a coin page’s lastmod is its last market refresh, not the post’s creation date');
$old = (object) ['ID' => array_search('aave', $ids, true), 'post_modified_gmt' => gmdate('Y-m-d H:i:s', (int) $mk['fetchedAt'] + 3600), 'post_type' => 'thb_coin'];
ok(strtotime($sm->core([], $old, 'thb_coin')['lastmod']) === (int) $mk['fetchedAt'] + 3600, 'and an edit newer than the data wins');
ok($sm->core(['loc' => 'y'], $post, 'post') === ['loc' => 'y'], 'other post types are left alone');
ok(($sm->yoast(['loc' => 'x'], 'post', $post)['mod'] ?? null) === $entry['lastmod'], 'and the same date in Yoast’s sitemap');
$aavePage = $pages['aave'];
ok(str_contains($aavePage, 'class="v2-peers__link" href="https://thehybit.com/post/') && str_contains($aavePage, 'صفحه همتایان:'),
   'internal links: the peers table links to the other coins we have pages for');
ok(str_contains($aavePage, '<nav class="v2-crumbs"') && str_contains($aavePage, '>ارزها</a>'), 'and the breadcrumb links back to /coins/');

if (getenv('THB_STATUS_MD')) {
    file_put_contents((string) getenv('THB_STATUS_MD'), $md);
}
if (getenv('THB_TOP20_DIR')) {
    foreach (['solana', 'tether', 'dogecoin', 'xrp', 'tron', 'ethereum', 'aave', 'zcash', 'near'] as $slug) {
        file_put_contents(rtrim((string) getenv('THB_TOP20_DIR'), '/') . "/v2-{$slug}.html", $pages[$slug]);
    }
}

echo $failed ? "\n{$failed} FAILED\n" : "\nAll top-20 tests passed.\n";
exit($failed ? 1 : 0);
