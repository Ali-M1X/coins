<?php
/**
 * The market-wide coin list at /coins/ — the post type's archive.
 *
 * WHERE THE DATA COMES FROM
 *
 * CoinGecko's /coins/markets, ordered by market cap, `per_page` coins per
 * request. One request fills one page of the list, whatever the page size, so
 * the list costs requests per PAGE, never per coin:
 *
 *   page 1 (coins 1–100)    refreshed every  5 min   12 requests/hour
 *   pages 2–5 (101–500)     refreshed every 30 min    8 requests/hour
 *                                                     20 requests/hour, ~480/day
 *
 * against our CoinGecko budget of 200/hour and 4,000/day, of which the rest of
 * the plugin uses about 100/hour. The scheduler refreshes at most ONE page per
 * tick, after the live price and through the same budget, so it can never
 * crowd the ticker out or burst past the per-minute limit.
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

    private function ttl(int $page): int
    {
        return $page === 1
            ? (int) ($this->settings()['ttl_first'] ?? 5 * MINUTE_IN_SECONDS)
            : (int) ($this->settings()['ttl_rest'] ?? 30 * MINUTE_IN_SECONDS);
    }

    private static function key(int $page): string
    {
        return 'page:' . $page;
    }

    public function entry(int $page): ?array
    {
        $e = $this->cache->peek(self::DATASET, self::key($page));
        return is_array($e) && is_array($e['data']['rows'] ?? null) ? $e : null;
    }

    /** Age over TTL: ≥ 1 is due. A page never fetched is infinitely overdue. */
    public function overdue(int $page): float
    {
        $e = $this->entry($page);
        if ($e === null) {
            return INF;
        }
        return (time() - (int) ($e['fetchedAt'] ?? 0)) / max(1, $this->ttl($page));
    }

    /**
     * The page the scheduler should refresh now, or null. Page 1 first — it is
     * the page most visitors open and the one the market figure is computed
     * from — then whichever other page is most overdue.
     */
    public function due(): ?int
    {
        if (($this->settings()['enabled'] ?? true) === false) {
            return null;
        }
        if ($this->overdue(1) >= 1.0) {
            return 1;
        }
        $best = null;
        $worst = 1.0;
        for ($p = 2; $p <= $this->pages(); $p++) {
            $o = $this->overdue($p);
            if ($o >= $worst) {
                [$best, $worst] = [$p, $o];
            }
        }
        return $best;
    }

    /**
     * Fetch one page from CoinGecko and cache it. One request. Failures are
     * recorded for the diagnostics screen and leave the last good copy in place.
     *
     * @return int rows written
     */
    public function refresh(int $page): int
    {
        $collector = $this->pipeline->collector('coingecko');
        if (!$collector instanceof CoinGecko || !$collector->isEnabled()) {
            return 0;
        }
        try {
            $rows = $collector->listing($page, $this->perPage());
        } catch (\Throwable $e) {
            Cache::noteError(self::DATASET, 'page ' . $page . ': ' . $e->getMessage(),
                $e->getCode() === Budget::REFUSED);
            throw $e;
        }
        if ($rows === []) {
            Cache::noteError(self::DATASET, 'page ' . $page . ': CoinGecko returned no rows');
            return 0;
        }
        $this->cache->put(self::DATASET, self::key($page), ['rows' => $rows]);
        Cache::clearError(self::DATASET);
        return count($rows);
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
    public function view(int $page): array
    {
        $page = max(1, $page);
        $e = $this->entry($page);
        $market = $this->market7d();
        $ours = $this->ours();

        $rows = [];
        foreach ((array) ($e['data']['rows'] ?? []) as $i => $r) {
            $own = $ours[$r['id']] ?? null;
            $rows[] = $r + [
                'position' => ($page - 1) * $this->perPage() + $i + 1,
                'url'      => $own['url'] ?? null,
                'nameFa'   => $own['name'] ?? null,
                'logo'     => $own['logo'] ?? $r['image'],
                'vsMarket' => $market !== null && isset($r['change7d']) ? (float) $r['change7d'] - $market : null,
            ];
        }

        return [
            'page'      => $page,
            'pages'     => $this->pages(),
            'perPage'   => $this->perPage(),
            'state'     => $e === null ? 'pending' : ($this->overdue($page) > 3 ? 'stale' : 'ok'),
            'fetchedAt' => $e !== null ? gmdate('c', (int) $e['fetchedAt']) : null,
            'market7d'  => $market,
            'rows'      => $rows,
        ];
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
