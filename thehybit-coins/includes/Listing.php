<?php
/**
 * The market-wide coin list at /coins/ — the post type's archive.
 *
 * WHERE THE DATA COMES FROM
 *
 * CoinGecko's /coins/markets, ordered by market cap. 2.14.0: fetched in
 * CHUNKS of 250 (the endpoint's per_page maximum), independent of the 100
 * rows a displayed page shows, so the 500 coins are two requests:
 *
 *   chunk 1 (ranks 1–250)    every 15 min    4 requests/hour
 *   chunk 2 (ranks 251–500)  every  2 h      0.5 requests/hour
 *                                            ~108 requests/day (was ~180)
 *
 * The scheduler refreshes at most ONE chunk per tick, at the highest budget
 * tier (the list is what must never go empty). A displayed page is assembled
 * from whichever chunk(s) hold its ranks.
 *
 * A page render reads the cache only, like every other v2 page: a visitor
 * never triggers a provider request. A page the scheduler has not filled yet
 * says so; it never shows zeros.
 *
 * THE "VS THE MARKET" FIGURE
 *
 * Each row's 7-day change minus the market's 7-day change, where "the market"
 * is the top 100 by market cap weighted by market cap: did this coin beat the
 * market this week, and by how much. It is derived from page 1 of the same
 * response — no request of its own.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

use TheHybit\Coins\Collectors\CoinGecko;

defined('ABSPATH') || exit;

final class Listing
{
    public const DATASET = 'listing';

    /** @var array<string, array{url:string, name:string, logo:?string}>|null our coin pages by CoinGecko id */
    private ?array $ours = null;

    public function __construct(
        private array $config,
        private Cache $cache,
        private Pipeline $pipeline,
        private CoinRepository $coins
    ) {}

    private function settings(): array
    {
        return (array) ($this->config['listing'] ?? []);
    }

    public function perPage(): int
    {
        return max(10, min(250, (int) ($this->settings()['per_page'] ?? 100)));
    }

    /** How many pages the list has — the top `pages × per_page` coins. */
    public function pages(): int
    {
        return max(1, (int) ($this->settings()['pages'] ?? 5));
    }

    /** Coins per request (2.14.0): CoinGecko's maximum, 250. */
    public function fetchSize(): int
    {
        return max($this->perPage(), min(250, (int) ($this->settings()['fetch_size'] ?? $this->perPage())));
    }

    /** Requests that cover the whole list: 2 for 500 coins at 250 each. */
    public function chunks(): int
    {
        return (int) ceil($this->pages() * $this->perPage() / $this->fetchSize());
    }

    private function ttl(int $page): int
    {
        return $page === 1
            ? (int) ($this->settings()['ttl_first'] ?? 5 * MINUTE_IN_SECONDS)
            : (int) ($this->settings()['ttl_rest'] ?? 30 * MINUTE_IN_SECONDS);
    }

    private static function key(int $chunk): string
    {
        return 'chunk:' . $chunk;
    }

    /** One fetched chunk's cache entry, or null. */
    public function chunkEntry(int $chunk): ?array
    {
        $e = $this->cache->peek(self::DATASET, self::key($chunk));
        return is_array($e) && is_array($e['data']['rows'] ?? null) ? $e : null;
    }

    /**
     * One DISPLAYED page (100 rows), assembled from the chunk(s) holding its
     * ranks. fetchedAt is the older of them. Entries cached by 2.13.0 and
     * earlier, one per displayed page, are still read until they expire, so
     * an update never empties the list while the new chunks are fetched.
     */
    public function entry(int $page): ?array
    {
        $per = $this->perPage();
        $size = $this->fetchSize();
        $lo = ($page - 1) * $per + 1;
        $hi = $page * $per;
        $rows = [];
        $at = null;
        for ($c = intdiv($lo - 1, $size) + 1; $c <= intdiv($hi - 1, $size) + 1; $c++) {
            $e = $this->chunkEntry($c);
            if ($e === null) {
                continue;
            }
            foreach ($e['data']['rows'] as $i => $r) {
                $pos = (int) ($r['pos'] ?? (($c - 1) * $size + $i + 1));
                if ($pos >= $lo && $pos <= $hi) {
                    $rows[] = ['pos' => $pos] + $r;
                }
            }
            $at = $at === null ? (int) $e['fetchedAt'] : min($at, (int) $e['fetchedAt']);
        }
        if ($rows !== []) {
            return ['data' => ['rows' => $rows], 'fetchedAt' => $at];
        }
        $legacy = $this->cache->peek(self::DATASET, 'page:' . $page);
        return is_array($legacy) && is_array($legacy['data']['rows'] ?? null) ? $legacy : null;
    }

    /**
     * 2.15.0: does this chunk have NOTHING to show — never fetched, or expired
     * past its maximum stale age, and no older per-page entry either? Only
     * then may the scheduler use the emergency slot for it.
     */
    public function chunkEmpty(int $chunk): bool
    {
        if ($this->chunkEntry($chunk) !== null) {
            return false;
        }
        /* Older per-page entries (2.13.0 and before) for the pages that START
           in this chunk still count as something to show. */
        $size = $this->fetchSize();
        $per = $this->perPage();
        for ($p = 1; $p <= $this->pages(); $p++) {
            if (intdiv(($p - 1) * $per, $size) + 1 !== $chunk) {
                continue;
            }
            $legacy = $this->cache->peek(self::DATASET, 'page:' . $p);
            if (is_array($legacy) && is_array($legacy['data']['rows'] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 2.15.0: refresh one chunk now — through the normal budget when it allows,
     * otherwise through the emergency slot (`$emergency` says when that is
     * allowed). Returns what happened, for the scheduler and the admin button.
     *
     * @return array{chunk:int, ok:bool, rows:int, emergency:bool, error:?string}
     */
    public function refreshWithReserve(int $chunk, bool $emergency, string $why = 'empty list'): array
    {
        $budget = new Budget($this->config);
        $out = ['chunk' => $chunk, 'ok' => false, 'rows' => 0, 'emergency' => false, 'error' => null];
        $tier = Budget::$tier;
        Budget::$tier = 0;
        try {
            $refusal = $budget->refuse('coingecko');
            if ($refusal !== null && $emergency) {
                Budget::$emergency = true;
                Budget::$emergencyWhy = $why;
                $out['emergency'] = true;
                $refusal = $budget->refuse('coingecko') === null ? null : $budget->refuse('coingecko');
            }
            if ($refusal !== null) {
                $out['error'] = $refusal;
                return $out;
            }
            $out['rows'] = $this->refresh($chunk);
            $out['ok'] = $out['rows'] > 0;
            if (!$out['ok']) {
                $out['error'] = 'no rows in the response';
            }
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        } finally {
            Budget::$emergency = false;
            Budget::$tier = $tier;
        }
        return $out;
    }

    private function chunkTtl(int $chunk): int
    {
        return $this->ttl($chunk === 1 ? 1 : 2);
    }

    /** Age over TTL: ≥ 1 is due. Never fetched: infinitely overdue. Backing off after a failure: not due. */
    public function chunkOverdue(int $chunk): float
    {
        if ((int) get_transient('thb_list_retry_' . $chunk) > time()) {
            return 0.0;
        }
        $e = $this->chunkEntry($chunk);
        if ($e === null) {
            return INF;
        }
        return (time() - (int) ($e['fetchedAt'] ?? 0)) / max(1, $this->chunkTtl($chunk));
    }

    /** A displayed page's staleness, from the chunk holding its first rank. */
    public function overdue(int $page): float
    {
        $chunk = intdiv(($page - 1) * $this->perPage(), $this->fetchSize()) + 1;
        $e = $this->chunkEntry($chunk);
        return $e === null ? ($this->entry($page) === null ? INF : 0.0)
            : (time() - (int) ($e['fetchedAt'] ?? 0)) / max(1, $this->chunkTtl($chunk));
    }

    /**
     * The chunk the scheduler should refresh now, or null. Chunk 1 first (the
     * page most visitors open, and the market figure), then the rest.
     */
    public function due(): ?int
    {
        if (($this->settings()['enabled'] ?? true) === false) {
            return null;
        }
        $best = null;
        $worst = 1.0;
        for ($c = 1; $c <= $this->chunks(); $c++) {
            $o = $this->chunkOverdue($c);
            if ($c === 1 && $o >= 1.0) {
                return 1;
            }
            if ($o >= $worst) {
                [$best, $worst] = [$c, $o];
            }
        }
        return $best;
    }

    /**
     * Fetch one CHUNK (250 coins) and cache it. One request. A failure keeps
     * the last good copy and waits (5 min, 10, 20 … up to 6 h) before the
     * next attempt; our own budget refusal made no request and does not.
     *
     * @return int rows written
     */
    public function refresh(int $chunk): int
    {
        $collector = $this->pipeline->collector('coingecko');
        if (!$collector instanceof CoinGecko || !$collector->isEnabled()) {
            return 0;
        }
        $size = $this->fetchSize();
        try {
            $rows = $collector->listing($chunk, $size);
        } catch (\Throwable $e) {
            $refused = $e->getCode() === Budget::REFUSED;
            Cache::noteError(self::DATASET, 'chunk ' . $chunk . ': ' . $e->getMessage(), $refused);
            if (!$refused) {
                $this->backoff($chunk);
            }
            throw $e;
        }
        if ($rows === []) {
            Cache::noteError(self::DATASET, 'chunk ' . $chunk . ': CoinGecko returned no rows');
            $this->backoff($chunk);
            return 0;
        }
        $this->cache->put(self::DATASET, self::key($chunk), ['rows' => $rows]);
        delete_transient('thb_list_retry_' . $chunk);
        delete_transient('thb_list_fails_' . $chunk);
        Cache::clearError(self::DATASET);
        return count($rows);
    }

    private function backoff(int $chunk): void
    {
        $fails = (int) get_transient('thb_list_fails_' . $chunk) + 1;
        set_transient('thb_list_fails_' . $chunk, $fails, DAY_IN_SECONDS);
        $wait = Cache::backoff($fails);
        set_transient('thb_list_retry_' . $chunk, time() + $wait, $wait);
    }

    /**
     * The market's 7-day change: the top page, weighted by market cap. Null
     * until page 1 is cached, or if too few rows carry both figures.
     */
    public function market7d(): ?float
    {
        $rows = (array) ($this->entry(1)['data']['rows'] ?? []);
        $sum = 0.0;
        $weight = 0.0;
        $n = 0;
        foreach ($rows as $r) {
            if (isset($r['change7d'], $r['marketCap']) && $r['marketCap'] > 0) {
                $sum += (float) $r['change7d'] * (float) $r['marketCap'];
                $weight += (float) $r['marketCap'];
                $n++;
            }
        }
        return $n >= 10 && $weight > 0 ? $sum / $weight : null;
    }

    /** Our own coin pages, by CoinGecko id: link, Persian name, logo. */
    private function ours(): array
    {
        if ($this->ours !== null) {
            return $this->ours;
        }
        $this->ours = [];
        foreach ($this->coins->all() as $coin) {
            if ($coin->coingeckoId === '') {
                continue;
            }
            $this->ours[$coin->coingeckoId] = [
                'url'  => (string) get_permalink($coin->postId),
                'name' => $coin->name,
                'logo' => get_the_post_thumbnail_url($coin->postId, 'thumbnail') ?: null,
                'category' => Categories::of($coin),   // 2.8.0
            ];
        }
        return $this->ours;
    }

    /**
     * Everything the list template draws for one page.
     *
     * @return array{page:int, pages:int, perPage:int, state:string, fetchedAt:?string,
     *               market7d:?float, rows:array<int, array>}
     */
    public function view(int $page, ?string $category = null, string $query = ''): array
    {
        /* 2.9.0 — /coins/?q=…: the search, server-side, for visitors without
           script and for shared links. Every coin, ranked by match, within
           the category when one is chosen; one page, no pagination. */
        if (trim($query) !== '') {
            $market = $this->market7d();
            $all = array_values(array_filter($this->allRows(), static fn($r) => $category === null || ($r['category']['key'] ?? null) === $category));
            $found = Search::match($all, $query);
            $counts = [];
            foreach ($this->ours() as $own) {
                if (!empty($own['category'])) {
                    $counts[$own['category']['key']] = ($counts[$own['category']['key']] ?? 0) + 1;
                }
            }
            return [
                'page' => 1, 'pages' => 1, 'perPage' => $this->perPage(),
                'state' => 'ok', 'fetchedAt' => null, 'market7d' => $market,
                'rows' => array_map(static fn($r) => $r + ['vsMarket' => $market !== null && isset($r['change7d']) ? (float) $r['change7d'] - $market : null], $found['rows']),
                'category' => $category !== null ? ['key' => $category] + (Categories::all()[$category] ?? ['fa' => $category, 'en' => $category]) : null,
                'counts' => $counts,
                'search' => ['query' => trim($query), 'relayout' => $found['relayout'], 'total' => count($found['rows'])],
            ];
        }

        $page = max(1, $page);
        $market = $this->market7d();
        $ours = $this->ours();
        $row = fn(array $r, int $position) => $r + [
            'position' => (int) ($r['pos'] ?? $position),
            'url'      => $ours[$r['id']]['url'] ?? null,
            'nameFa'   => $ours[$r['id']]['name'] ?? null,
            'logo'     => $ours[$r['id']]['logo'] ?? $r['image'],
            'category' => $ours[$r['id']]['category'] ?? null,
            'vsMarket' => $market !== null && isset($r['change7d']) ? (float) $r['change7d'] - $market : null,
        ];

        /* 2.8.0 — /coins/?cat=stablecoin: every coin WITH A PAGE in that
           category, gathered from all the list pages already in the cache
           (no request), in market-cap order, on one page. Categories are
           assigned only to coins with a page, so the filter is over those. */
        $counts = [];
        foreach ($ours as $own) {
            if (!empty($own['category'])) {
                $counts[$own['category']['key']] = ($counts[$own['category']['key']] ?? 0) + 1;
            }
        }
        if ($category !== null) {
            $rows = [];
            $newest = null;
            for ($p = 1; $p <= $this->pages(); $p++) {
                $e = $this->entry($p);
                foreach ((array) ($e['data']['rows'] ?? []) as $i => $r) {
                    if (($ours[$r['id']]['category']['key'] ?? null) === $category) {
                        $rows[] = $row($r, ($p - 1) * $this->perPage() + $i + 1);
                    }
                }
                $newest = max($newest ?? 0, (int) ($e['fetchedAt'] ?? 0)) ?: $newest;
            }
            return [
                'page' => 1, 'pages' => 1, 'perPage' => $this->perPage(),
                'state' => $newest === null ? 'pending' : 'ok',
                'fetchedAt' => $newest ? gmdate('c', $newest) : null,
                'market7d' => $market, 'rows' => $rows,
                'category' => ['key' => $category] + (Categories::all()[$category] ?? ['fa' => $category, 'en' => $category]),
                'counts' => $counts,
                'search' => null,
            ];
        }

        $e = $this->entry($page);
        $rows = [];
        foreach ((array) ($e['data']['rows'] ?? []) as $i => $r) {
            $rows[] = $row($r, ($page - 1) * $this->perPage() + $i + 1);
        }

        return [
            'page'      => $page,
            'pages'     => $this->pages(),
            'perPage'   => $this->perPage(),
            'state'     => $e === null ? 'pending' : ($this->overdue($page) > 3 ? 'stale' : 'ok'),
            'fetchedAt' => $e !== null ? gmdate('c', (int) $e['fetchedAt']) : null,
            'market7d'  => $market,
            'rows'      => $rows,
            'category'  => null,
            'counts'    => $counts,
            'search'    => null,
        ];
    }

    /**
     * Every coin the list knows (2.9.0): all list pages in the cache, in
     * rank order, then any coin with a page here that none of them carries,
     * from its own cached market entry. Cache reads only.
     *
     * @return array<int, array> list rows plus position, page, url, nameFa, logo, category, fetchedAt
     */
    public function allRows(): array
    {
        $ours = $this->ours();
        $out = [];
        $seen = [];
        for ($p = 1; $p <= $this->pages(); $p++) {
            $e = $this->entry($p);
            foreach ((array) ($e['data']['rows'] ?? []) as $i => $r) {
                if (isset($seen[$r['id']])) {
                    continue;
                }
                $seen[$r['id']] = true;
                $out[] = $r + [
                    'position'  => (int) ($r['pos'] ?? (($p - 1) * $this->perPage() + $i + 1)),
                    'page'      => $p,
                    'url'       => $ours[$r['id']]['url'] ?? null,
                    'nameFa'    => $ours[$r['id']]['name'] ?? null,
                    'logo'      => $ours[$r['id']]['logo'] ?? $r['image'],
                    'category'  => $ours[$r['id']]['category'] ?? null,
                    'fetchedAt' => (int) ($e['fetchedAt'] ?? 0),
                ];
            }
        }
        foreach ($this->coins->all() as $coin) {
            if ($coin->coingeckoId === '' || isset($seen[$coin->coingeckoId])) {
                continue;
            }
            $m = $this->cache->peek('market', $this->cache->keyFor('market', $coin));
            $d = is_array($m['data'] ?? null) ? $m['data'] : [];
            $own = $ours[$coin->coingeckoId] ?? [];
            $out[] = [
                'id' => $coin->coingeckoId, 'symbol' => strtoupper($coin->symbol),
                'name' => $coin->nameEn !== '' ? $coin->nameEn : $coin->name, 'image' => null,
                'rank' => isset($d['rank']) ? (int) $d['rank'] : null,
                'price' => isset($d['price']) ? (float) $d['price'] : null,
                'marketCap' => $d['marketCap'] ?? null, 'volume24h' => $d['volume24h'] ?? null,
                'change24h' => $d['change24h'] ?? null, 'change7d' => $d['change7d'] ?? null, 'sparkline' => null,
                'position' => null, 'page' => null,
                'url' => $own['url'] ?? null, 'nameFa' => $own['name'] ?? null, 'logo' => $own['logo'] ?? null,
                'category' => $own['category'] ?? null, 'fetchedAt' => (int) ($m['fetchedAt'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * The list header's figures (2.13.0) — cache reads only, each null when
     * the data behind it is missing, so the template draws no empty box.
     *
     * @return array{total:int, cap:?array, volume:?float, shares:array, fng:?array,
     *               gainer:?array, loser:?array, market7d:?float, catBest:?array, catWorst:?array}
     */
    public function stats(): array
    {
        $peek = fn(string $ds): ?array => is_array($d = $this->cache->peek($ds, $this->cache->keyFor($ds))['data'] ?? null) ? $d : null;
        $g = $peek('global') ?? [];
        $num = static fn($v): ?float => is_numeric($v) && (float) $v > 0 ? (float) $v : null;
        $cap = $num($g['totalMarketCap'] ?? null);

        $shares = [];
        foreach (['BTC' => 'btcDominance', 'ETH' => 'ethDominance'] as $sym => $k) {
            if (($v = $num($g[$k] ?? null)) !== null) { $shares[$sym] = $v; }
        }
        $stable = $num(($peek('stablecoins') ?? [])['totalUsd'] ?? null);
        if ($stable !== null && $cap !== null && $stable < $cap) {
            $shares['stable'] = $stable / $cap * 100;
        }

        $s = $peek('sentiment');
        $fng = $s !== null && isset($s['value']) && is_numeric($s['value'])
            ? ['value' => (int) $s['value'], 'label' => (string) ($s['labelFa'] ?? ''), 'change' => isset($s['change']) && is_numeric($s['change']) ? (int) $s['change'] : null]
            : null;

        /* Movers and categories: coins with a page here, so every name links
           somewhere. Pegged assets (stablecoins, gold tokens) are left out of
           both — a peg's ±0.1% says nothing about the market's mood. */
        $rows = $this->allRows();
        $total = count(array_filter($rows, static fn($r) => $r['position'] !== null));
        $pegged = static fn($r) => in_array($r['category']['key'] ?? null, ['stablecoin', 'gold'], true);
        $mine = array_values(array_filter($rows, static fn($r) => $r['url'] !== null && isset($r['change24h']) && is_numeric($r['change24h']) && !$pegged($r)));
        usort($mine, static fn($a, $b) => $b['change24h'] <=> $a['change24h']);
        $mover = static fn(?array $r) => $r === null ? null : [
            'name' => (string) ($r['nameFa'] ?? $r['name']), 'symbol' => strtoupper((string) $r['symbol']),
            'logo' => $r['logo'] ?? null, 'url' => (string) $r['url'], 'change' => (float) $r['change24h'],
        ];
        $byCat = [];
        foreach ($mine as $r) {
            $k = $r['category']['key'] ?? null;
            if ($k !== null) {
                $byCat[$k][] = (float) $r['change24h'];
            }
        }
        $cats = [];
        foreach ($byCat as $k => $v) {
            if (count($v) >= 3) {
                $cats[$k] = ['key' => $k, 'fa' => (string) (Categories::all()[$k]['fa'] ?? $k), 'change' => array_sum($v) / count($v), 'n' => count($v)];
            }
        }
        uasort($cats, static fn($a, $b) => $b['change'] <=> $a['change']);

        return [
            'total'    => $total,
            'cap'      => $cap !== null ? ['usd' => $cap, 'change' => isset($g['marketCapChange24h']) && is_numeric($g['marketCapChange24h']) ? (float) $g['marketCapChange24h'] : null] : null,
            'volume'   => $num($g['totalVolume24h'] ?? null),
            'shares'   => $shares,
            'fng'      => $fng,
            'gainer'   => count($mine) >= 2 ? $mover($mine[0]) : null,
            'loser'    => count($mine) >= 2 ? $mover($mine[count($mine) - 1]) : null,
            'market7d' => $this->market7d(),
            'catBest'  => count($cats) >= 2 ? reset($cats) : null,
            'catWorst' => count($cats) >= 2 ? end($cats) : null,
        ];
    }

    /**
     * Logo URL by CoinGecko id, for every coin the list cache or our pages
     * hold (2.13.0, the converter's dropdown). Cache reads only.
     *
     * @return array<string, string>
     */
    public function logos(): array
    {
        $out = [];
        foreach ($this->allRows() as $r) {
            if (!empty($r['logo'])) {
                $out[$r['id']] = (string) $r['logo'];
            }
        }
        return $out;
    }

    /** The ?cat= filter of the list, when it names a known category (2.8.0). */
    public static function currentCategory(): ?string
    {
        $cat = isset($_GET['cat']) ? sanitize_key(wp_unslash((string) $_GET['cat'])) : '';
        return $cat !== '' && isset(Categories::all()[$cat]) ? $cat : null;
    }

    /* ------------------------------------------------------------------
     * Routing: /coins/ and /coins/page/N/ are the post type's archive.
     * ---------------------------------------------------------------- */

    public function register(): void
    {
        add_filter('archive_template', [$this, 'template']);
        add_filter('pre_handle_404', [$this, 'keepPage'], 10, 2);
        add_filter('redirect_canonical', [$this, 'noRedirect']);
        add_action('pre_get_posts', [$this, 'lightQuery']);
    }

    private static function isList(): bool
    {
        return function_exists('is_post_type_archive') && is_post_type_archive(CoinRepository::POST_TYPE);
    }

    public static function currentPage(): int
    {
        return max(1, (int) get_query_var('paged'));
    }

    public function template(string $template): string
    {
        if (!self::isList()) {
            return $template;
        }
        $theme = locate_template(['thehybit/v2/archive-coins.php']);
        return $theme ?: THB_COINS_DIR . 'templates/v2/archive-coins.php';
    }

    /**
     * The archive's posts are our few coin pages, so WordPress would call
     * /coins/page/3/ a 404. Pages within the list's range are real pages;
     * anything beyond still 404s.
     */
    public function keepPage(bool $preempt, $query): bool
    {
        if (self::isList() && self::currentPage() <= $this->pages()) {
            return true;
        }
        return $preempt;
    }

    /** @param string|false $redirect */
    public function noRedirect($redirect)
    {
        return self::isList() && self::currentPage() > 1 ? false : $redirect;
    }

    /** The archive's own post query is not used by the list: keep it cheap. */
    public function lightQuery($query): void
    {
        if (is_object($query) && method_exists($query, 'is_main_query') && $query->is_main_query()
            && method_exists($query, 'is_post_type_archive') && $query->is_post_type_archive(CoinRepository::POST_TYPE)) {
            $query->set('posts_per_page', 1);
            $query->set('no_found_rows', true);
        }
    }
}
