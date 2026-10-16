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
ok(count($seeds) === 84, 'config/coins.php lists 84 coins: the 20 of 2.3, the 30 of batch 3 and the 34 of batch 4 (' . count($seeds) . ')');
$gecko = array_column(array_column($seeds, 'fields'), 'thb_coingecko_id');
ok(count(array_unique($gecko)) === 84, 'each with its own CoinGecko id');
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
ok(count(array_unique($titles)) === 84 && !in_array('fallback', $titles, true), 'all 84 titles are different and hand-written');
ok(count(array_unique($descs)) === 84 && min(array_map('mb_strlen', $descs)) >= 100, 'all 84 meta descriptions are different and substantial');
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
$scores = [];
$pulseRows = [];
$labelOverlaps = $arrowCrossings = [];
$psRows = [];
foreach ($ids as $id => $slug) {
    $html = v2_render($id);
    $pages[$slug] = $html;
    $v = $plugin->v2($plugin->coins->find($id));
    foreach ((array) ($v['priceStory']['events'] ?? []) as $i => $e1) {
        foreach ((array) $v['priceStory']['events'] as $j => $e2) {
            if ($j <= $i) { continue; }
            if ($e1['row'] === $e2['row'] && $e1['lx'] < $e2['lx'] + $e2['lw'] && $e2['lx'] < $e1['lx'] + $e1['lw']) {
                $labelOverlaps[] = "{$slug}: «{$e1['title']}» / «{$e2['title']}»";
            }
            foreach ([[$e1, $e2], [$e2, $e1]] as [$up, $low]) {
                if ($up['row'] < $low['row'] && $up['x'] > $low['lx'] && $up['x'] < $low['lx'] + $low['lw']) {
                    $arrowCrossings[] = "{$slug}: «{$up['title']}» arrow behind «{$low['title']}»";
                }
            }
        }
    }
    $psRows[$slug] = count(array_unique(array_column((array) ($v['priceStory']['events'] ?? []), 'row')));
    $pulseRows[$slug] = array_map(static fn($r) => $r['label'] . ' (' . ($r['source'] ?? '') . ')', (array) ($v['flow']['pulse'] ?? []));
    $scores[$slug] = ['score' => $v['dna']['score'], 'coverage' => $v['dna']['coverage'],
                      'axes' => array_column($v['dna']['axes'], 'value', 'label'), 'activity' => $v['dna']['activity'] ?? null];
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

section('batch 4 (2.7.0) — the rest of the live-price list');
$batch4 = array_filter($seeds, static fn($sp) => ($sp['batch'] ?? null) === 4);
ok(count($batch4) === 34, 'batch 4 adds exactly 34 coins (' . count($batch4) . ')');
$b4Ids = array_map(static fn($sp) => $sp['fields']['thb_coingecko_id'], $batch4);
$before = array_map(static fn($sp) => $sp['fields']['thb_coingecko_id'], array_filter($seeds, static fn($sp) => ($sp['batch'] ?? null) !== 4));
ok(count($before) === 50 && array_intersect($b4Ids, $before) === [], 'no batch-4 coin already has a page (by CoinGecko id), and none appears twice');
$never = ['staked-ether', 'wrapped-steth', 'wrapped-bitcoin', 'weth', 'wrapped-eeth', 'coinbase-wrapped-btc', 'binance-bridged-usdt-bnb-smart-chain',
          'rocket-pool-eth', 'mantle-staked-ether', 'jito-staked-sol', 'binance-staked-sol', 'savings-dai', 'ethena-staked-usde', 'susds', 'kelp-dao-restaked-eth'];
ok(array_intersect($b4Ids, $never) === [], 'no wrapped, bridged, staked or liquid-staking token');
foreach (['dai', 'usds', 'tether-gold', 'pax-gold', 'pi-network', 'blackrock-buidl'] as $slug) {
    ok(isset($batch4[$slug]), "batch 4 now includes /coins/{$slug}/ (the old stablecoin/gold/fund/Pi skip is lifted)");
}
$b4Src = (string) file_get_contents(THB_COINS_DIR . 'config/coins-batch4.php');
preg_match('#\$active = \[(.*?)\];#s', $b4Src, $am4);
preg_match_all("#'([a-z0-9-]+)',#", $am4[1] ?? '', $al4);
ok($al4[1] === array_keys($batch4), 'batch 4 is exactly its one-line-per-coin $active list, in its order');
preg_match('#\$notBuilt = \[(.*?)\];#s', $b4Src, $nb);
preg_match_all("#^\s*'([^']+)'\s*=>\s*'([^']{20,})'#m", $nb[1] ?? '', $nbl);
ok(count($nbl[1]) === 18, 'the 18 coins not built are each listed with a reason (' . count($nbl[1]) . ')');
foreach ($batch4 as $slug => $sp) {
    $v = $plugin->v2($plugin->coins->find(array_search($slug, $ids, true)));
    $f = $sp['fields'];
    if (!empty($f['thb_enable_dex']) && empty($f['thb_dex_token_address'])) {
        ok(false, "{$slug}: DEX on without a token address");
    }
    if (!empty($f['thb_enable_defi']) && empty($f['thb_defillama_chain'])) {
        ok(false, "{$slug}: DeFi on without a DefiLlama chain");
    }
    foreach (['overview', 'story', 'price-story', 'pulse', 'peers'] as $always) {
        if (!($v['sections'][$always]['show'] ?? false)) {
            ok(false, "{$slug}: the {$always} section is missing");
        }
    }
    $lines = array_merge(preg_split('/\n/', (string) $f['thb_timeline']), preg_split('/\n/', (string) $f['thb_price_events']));
    foreach ((array) ($sp['verify'] ?? []) as $vv) {
        if (array_filter($lines, static fn($l) => str_starts_with($l, $vv . ' |')) === []) {
            ok(false, "{$slug}: fact-check item «{$vv}» matches no line");
        }
    }
}
ok(true, 'every batch-4 coin: DEX only by contract address, DeFi only with a chain, core sections present, fact-check items name real lines');
$stable = $plugin->v2($plugin->coins->find(array_search('dai', $ids, true)));
ok(!($stable['sections']['staking']['show'] ?? false), 'a stablecoin page has no staking section');
$gold = $plugin->v2($plugin->coins->find(array_search('pax-gold', $ids, true)));
ok(!($gold['sections']['staking']['show'] ?? false), 'nor does a gold token');
$live4 = \TheHybit\Coins\LiveFeed::client($plugin->config, array_values($b4Ids));
$live4Usd = array_keys(array_filter($live4['coins'] ?? [], static fn($m) => !empty($m['usd'])));
ok(count($live4Usd) >= 16, 'live prices: ' . count($live4Usd) . ' of 34 batch-4 coins mapped to a live feed');
$pg = $plugin->config['peer_groups'];
$grouped = array_merge(...array_values($pg));
ok(array_diff($b4Ids, $grouped) === [], 'every batch-4 coin sits in a peer group');
ok(count(array_unique($grouped)) <= 250, 'and every peer id still fits the one /coins/markets request (' . count(array_unique($grouped)) . ' ≤ 250)');
$daiPage = $pages['dai'];
ok(str_contains($daiPage, 'class="v2-peers__link"') && str_contains($daiPage, '<nav class="v2-crumbs"'), 'internal links: peers and breadcrumb on a new page');
$diCoin = $plugin->coins->find(array_search('dai', $ids, true));
$plugin->cache->put('market', $plugin->cache->keyFor('market', $diCoin), ['price' => 1.0]);
$dp = (object) ['ID' => array_search('dai', $ids, true), 'post_modified_gmt' => '2024-01-01 00:00:00', 'post_type' => 'thb_coin'];
ok(isset($sm->core(['loc' => 'x'], $dp, 'thb_coin')['lastmod']), 'sitemap: the new pages carry lastmod too');

section('price story labels (2.8.0)');
ok($labelOverlaps === [], 'no two event labels overlap on any of the ' . count($ids) . ' price charts' . ($labelOverlaps ? ': ' . implode('; ', array_slice($labelOverlaps, 0, 5)) : ''));
/* An arrow behind another label is not an overlap — arrows are drawn under
   every box — and two events days apart cannot avoid it (their arrows share
   an x). 2.7.0 had 39 such cases; this keeps them from creeping back. */
ok(count($arrowCrossings) <= 20, 'arrows that pass behind another label: ' . count($arrowCrossings) . ' across all charts (2.7.0 layout: 39)');
ok(max($psRows) <= 7, 'label rows per chart: at most ' . max($psRows));

if (getenv('THB_PULSE_MD')) {
    $pm = "| Coin | Network Pulse rows shown (source) |\n|---|---|\n";
    foreach ($pulseRows as $slug => $rows) {
        $pm .= sprintf("| %s (%s) | %s |\n", $seeds[$slug]['fields']['thb_name_fa'], $seeds[$slug]['fields']['thb_symbol'], $rows ? implode('<br>', $rows) : '— (no free network source: rows and box removed)');
    }
    file_put_contents((string) getenv('THB_PULSE_MD'), $pm);
}
section('«آینده» — planned items (2.8.0)');
$future = require THB_COINS_DIR . 'config/future-2.8.php';
$withPlan = [];
foreach ($pages as $slug => $html) {
    if (substr_count($html, 'برنامه‌ریزی‌شده') > 0) {
        $withPlan[] = $slug;
    }
}
$expected = array_values(array_unique(array_merge(['ethereum', 'bitcoin', 'solana', 'litecoin'], array_keys($future))));
sort($expected);
sort($withPlan);
ok($withPlan === $expected, 'planned items appear on exactly the coins with an announced or scheduled item (' . count($withPlan) . '): ' . implode(', ', $withPlan));
$bad = [];
foreach ($future as $slug => $lines) {
    foreach ($lines as $line) {
        [$d, $t, $x] = array_map('trim', explode('|', $line));
        if (!str_starts_with($d, '>') || !str_contains($seeds[$slug]['fields']['thb_timeline'], $line)) {
            $bad[] = $slug;
        }
        if (!preg_match('/^(\d{4}|بدون تاریخ رسمی|حدود \d{4}|حداکثر .+ \d{4}|پس از .+|ژوئیه \d{4})$/u', ltrim($d, '>'))) {
            $bad[] = "{$slug} date «{$d}»";
        }
    }
}
ok($bad === [], 'every planned item is marked planned, is on its coin\'s timeline, and its date is a year, an approximate year, a condition or «بدون تاریخ رسمی» — never an invented day' . ($bad ? ': ' . implode(', ', $bad) : ''));
ok(substr_count($pages['usdc'], 'is-planned') === 2 && str_contains($pages['usdc'], 'اجرای قانون GENIUS'), 'USDC: the GENIUS Act\'s effective date and Circle\'s Arc');
ok(!str_contains($pages['dogecoin'], 'برنامه‌ریزی‌شده') && !str_contains($pages['pepe'], 'برنامه‌ریزی‌شده'), 'a coin with nothing credible planned shows nothing — no filler');

section('asset categories (2.8.0)');
$catLabels = \TheHybit\Coins\Categories::all();
$noCat = array_keys(array_filter($seeds, static fn($sp) => !isset($catLabels[$sp['fields']['thb_category'] ?? ''])));
ok($noCat === [], 'every one of the ' . count($seeds) . ' coins ships with a main category' . ($noCat ? ': missing ' . implode(', ', $noCat) : ''));
ok(in_array('thb_category', \TheHybit\Coins\Seeder::EDITORIAL, true), 'and the seeder writes it into an empty field on existing coins (never over an editor\'s choice)');
$acf = json_decode((string) file_get_contents(THB_COINS_DIR . 'acf-json/group_thb_coin.json'), true);
$catField = array_values(array_filter($acf['fields'], static fn($f) => ($f['name'] ?? '') === 'thb_category'))[0] ?? null;
ok($catField !== null && $catField['type'] === 'select' && array_keys($catField['choices']) === array_keys($catLabels),
   'wp-admin: «دسته» is a select with exactly the configured categories');
ok(str_contains($pages['ethereum'], 'class="v2-chip v2-chip--cat"') && str_contains($pages['ethereum'], '>لایه ۱</a>')
   && str_contains($pages['dai'], '>استیبل‌کوین</a>') && str_contains($pages['pax-gold'], '>توکن پشتوانه طلا</a>'),
   'the hero shows the category beside the name and symbol (Ethereum: لایه ۱, Dai: استیبل‌کوین, PAX Gold: طلا)');
ok(str_contains($pages['ethereum'], '<dt>دسته</dt><dd>لایه ۱</dd>'), 'and the about box names it');
v2_warm_list();
$_GET = [];
$list1 = v2_render_list(1);
ok(str_contains($list1, '<nav class="list-cats"') && str_contains($list1, '?cat=stablecoin') && str_contains($list1, 'class="list-coin__cat"'),
   '/coins/: a category filter bar, and each coin with a page carries its category');
$_GET = ['cat' => 'stablecoin'];
$listS = v2_render_list(1);
preg_match_all('#<tr[^>]*>.*?</tr>#s', explode('<tbody>', $listS)[1] ?? '', $trs);
$names = array_map(static fn($tr) => preg_match('#class="list-coin__name"[^>]*>([^<]+)<#', $tr, $mm) ? $mm[1] : '', $trs[0]);
ok(in_array('تتر', $names, true) && in_array('دای', $names, true) && !in_array('بیت‌کوین', $names, true) && !str_contains($listS, 'class="list-pages"'),
   '/coins/?cat=stablecoin lists the stablecoins with a page across all list pages (Tether on page 1, Dai on page 2), nothing else, no pagination (' . implode('، ', $names) . ')');
ok(str_contains($listS, 'قیمت ارزهای دسته «استیبل‌کوین»') && str_contains($listS, 'aria-current="page">استیبل‌کوین'), 'with its own heading and the filter marked');
$GLOBALS['thb_is_list'] = true;
ok((new \TheHybit\Coins\Design())->coreRobots(['index' => true])['noindex'] ?? false, 'a category view is noindex, follow — a filter of /coins/, not a page of its own');
$_GET = [];
ok(!isset((new \TheHybit\Coins\Design())->coreRobots(['index' => true])['noindex']), 'the unfiltered list stays indexable');
unset($GLOBALS['thb_is_list']);
$_GET = ['thb_design' => 'v2'];
$GLOBALS['thb_current_post'] = array_search('dai', $ids, true);
ob_start();
(new \TheHybit\Coins\Schema($plugin->coins, $plugin->pipeline))->output();
$ld = (string) ob_get_clean();
ok(str_contains($ld, '"disambiguatingDescription":"استیبل‌کوین"') && str_contains($ld, '"keywords":"دای، DAI، استیبل‌کوین، Stablecoin"'),
   'structured data (v2): WebPage.about names the asset with its category; keywords include it');
$_GET = [];
ob_start();
(new \TheHybit\Coins\Schema($plugin->coins, $plugin->pipeline))->output();
ok(!str_contains((string) ob_get_clean(), 'disambiguatingDescription'), 'the classic page\'s structured data is unchanged');
unset($GLOBALS['thb_current_post']);

section('search on /coins/ (2.9.0)');
use TheHybit\Coins\Search;
$norm = [
    ['بيتكوين', 'بیت‌کوین'], ['بیت کوین', 'بیت‌کوین'], ['BTC', 'btc'], ['اِتِریوم', 'اتریوم'], ['۱۲۳', '123'], ['١٢٣', '123'],
    ['آوه', 'اوه'], ['لایه‌۲', 'لایه2'], ['  Shiba-Inu ', 'shibainu'],
];
$bad = array_filter($norm, static fn($c) => Search::normalize($c[0]) !== Search::normalize($c[1]));
ok($bad === [], 'normalisation: ي/ك, ZWNJ and spaces, case, diacritics, Persian and Arabic digits, hyphens all fold together');
ok(Search::relayout(Search::normalize('ذفز')) === 'btc' && Search::relayout('btc') === null, 'a symbol typed on the Persian layout is read again as English keys («ذفز» → btc)');
v2_warm_list();
(new \TheHybit\Coins\Budget($plugin->config))->reset('coingecko');
$GLOBALS['thb_probe_background'] = true;
foreach (array_chunk($plugin->coins->all(), 50) as $chunk) { $plugin->pipeline->warmBatch($chunk, 'market'); }
$GLOBALS['thb_probe_background'] = false;
$allRows = $plugin->listing->allRows();
ok(array_filter($allRows, static fn($r) => $r['url'] !== null && $r['price'] === null) === [], 'every coin with a page has its cached price in the index');
$search = new Search($plugin->listing);
$top = static fn(string $q, ?string $cat = null) => (Search::match(array_values(array_filter($allRows, static fn($r) => $cat === null || ($r['category']['key'] ?? null) === $cat)), $q)['rows'][0]['id'] ?? null);
ok($top('btc') === 'bitcoin' && $top('BTC') === 'bitcoin' && $top('بيتكوين') === 'bitcoin' && $top('بیت کوین') === 'bitcoin' && $top('bitcoin') === 'bitcoin',
   'by symbol, English name, Persian name — with Arabic letters or a space instead of the half-space');
ok($top('ذفز') === 'bitcoin' && Search::match($allRows, 'ذفز')['relayout'] === 'btc', 'and «ذفز» finds Bitcoin, saying it read «btc»');
ok($top('eth') === 'ethereum' && $top('تتر') === 'tether' && $top('دای') === 'dai', 'ETH, تتر, دای');
ok($top('coin 487') === 'coin-487', 'every coin of the list is searched, not just page 1 (rank 487 is on page 5)');
ok($top('xaut') === 'tether-gold' && in_array('tether-gold', array_column($allRows, 'id'), true),
   'and a coin with a page here that is not in the 500-coin list (Tether Gold in the fixture) is searched too');
$inCat = Search::match(array_values(array_filter($allRows, static fn($r) => ($r['category']['key'] ?? null) === 'stablecoin')), 'usd')['rows'];
ok($inCat !== [] && array_unique(array_map(static fn($r) => $r['category']['key'], $inCat)) === ['stablecoin'], 'with ?cat=, the search stays inside the category (' . count($inCat) . ' stablecoins match «usd»)');
ok(Search::match($allRows, 'zzzzqq')['rows'] === [], 'nonsense finds nothing (the page says so)');
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
delete_transient('thb_coins_index');
$res = $search->respond();
$body = $res->get_data();
ok($res->get_status() === 200 && count($body['rows']) >= 500 && str_contains($res->headers['Cache-Control'] ?? '', 'max-age=300'),
   'GET /wp-json/thehybit/v1/coins-index: ' . count($body['rows']) . ' coins, browser-cacheable for five minutes');
$json = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
ok(strlen($json) < 90000, 'the index is compact: ' . number_format(strlen($json)) . ' bytes before compression, fetched once per visit');
$before = Probe::$calls;
for ($i = 0; $i < Search::RATE_MAX + 2; $i++) { $last = $search->respond(); }
ok(count(Probe::$calls) === count($before), 'it never calls a provider — built from the cache only');
ok($last->get_status() === 429 && ($last->headers['Retry-After'] ?? '') === (string) Search::RATE_WINDOW,
   'rate-limited per visitor: more than ' . Search::RATE_MAX . ' a minute get 429 with Retry-After');
$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
ok($search->respond()->get_status() === 200, 'another visitor is unaffected');
$_GET = ['q' => 'بيتكوين'];
$listQ = v2_render_list(1);
ok(str_contains($listQ, 'جستجوی «بيتكوين» در ارزها') && str_contains($listQ, '>بیت‌کوین</a>') && !str_contains($listQ, 'data-list-pages'),
   '/coins/?q= works without script: server-rendered results, no pagination');
$GLOBALS['thb_is_list'] = true;
ok((new \TheHybit\Coins\Design())->coreRobots(['index' => true])['noindex'] ?? false, 'and a search view is noindex, follow');
unset($GLOBALS['thb_is_list']);
$_GET = ['q' => 'zzzzqq'];
ok(str_contains(v2_render_list(1), 'هیچ ارزی با «zzzzqq» در نام یا نماد پیدا نشد'), 'no results: said plainly, with a way back to all coins');
$_GET = ['q' => 'usd', 'cat' => 'stablecoin'];
$listQC = v2_render_list(1);
ok(str_contains($listQC, 'فقط دسته «استیبل‌کوین»') && !str_contains($listQC, '>بیت‌کوین</a>'), 'search and category filter together');
$_GET = [];
$list0 = v2_render_list(1);
ok(str_contains($list0, '<label class="list-search__label" for="list-q">') && str_contains($list0, 'data-index="https://thehybit.com/wp-json/thehybit/v1/coins-index"')
   && str_contains($list0, 'id="rank-1"'), 'the form has a real label, points at the index, and every row can be linked to');
if (getenv('THB_TOP20_DIR')) {
    $dir = rtrim((string) getenv('THB_TOP20_DIR'), '/');
    file_put_contents($dir . '/v2-list.html', $list0);
    file_put_contents($dir . '/coins-index.json', $json);
    file_put_contents($dir . '/search-cases.json', wp_json_encode([
        'normalize' => array_map(static fn($c) => [$c[0], Search::normalize($c[0])], $norm),
        'top' => array_map(static fn($q) => [$q, $top($q)], ['btc', 'بيتكوين', 'بیت کوین', 'ذفز', 'eth', 'تتر', 'coin 487', 'xaut']),
    ], JSON_UNESCAPED_UNICODE));
}

if (getenv('THB_SCORES')) {
    file_put_contents((string) getenv('THB_SCORES'), json_encode($scores, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
if (getenv('THB_STATUS_MD')) {
    file_put_contents((string) getenv('THB_STATUS_MD'), $md);
}
if (getenv('THB_TOP20_DIR')) {
    foreach (['solana', 'tether', 'dogecoin', 'xrp', 'tron', 'ethereum', 'aave', 'zcash', 'near', 'dai', 'worldcoin', 'stacks', 'pax-gold', 'usdc', 'bittensor'] as $slug) {
        file_put_contents(rtrim((string) getenv('THB_TOP20_DIR'), '/') . "/v2-{$slug}.html", $pages[$slug]);
    }
}

echo $failed ? "\n{$failed} FAILED\n" : "\nAll top-20 tests passed.\n";
exit($failed ? 1 : 0);
