<?php
/**
 * The live-price endpoint.
 *
 *   GET /wp-json/thehybit/v1/price/{slug}
 *
 * Returns the price and 24h change the scheduler last cached, as JSON, for
 * the page's script to swap into the number in place.
 *
 * IT NEVER CALLS A PROVIDER. It reads one transient (the site-wide ticker
 * entry, falling back to the coin's five-minute market entry) and returns it.
 * A thousand visitors polling it cost a thousand cheap local reads and not a
 * single CoinGecko request: the scheduler's one request a minute is the only
 * thing that ever reaches CoinGecko for the live price.
 *
 * The response says when the figure was fetched and when the next refresh is
 * due, so the page can poll once per refresh instead of on a blind timer.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class LivePrice
{
    public const NS = 'thehybit/v1';

    public function __construct(
        private array $config,
        private CoinRepository $coins,
        private Cache $cache
    ) {}

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route(self::NS, '/price/(?P<slug>[a-z0-9-]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'respond'],
            'permission_callback' => '__return_true',   // public, read-only, already on the page
            'args'                => ['slug' => ['sanitize_callback' => 'sanitize_title']],
        ]);
    }

    /** @param \WP_REST_Request|array $request */
    public function respond($request)
    {
        $slug = is_array($request) ? (string) ($request['slug'] ?? '') : (string) $request->get_param('slug');
        $coin = $this->coins->findBySlug($slug);
        $body = $coin ? $this->read($coin) : null;

        $response = new \WP_REST_Response($body ?? ['error' => 'unknown coin'], $body ? 200 : 404);
        /* A shared cache (page cache, CDN) may hold it for 20 seconds: well
           inside the one-minute refresh, and it turns a crowd of visitors into
           one read per 20 seconds. Browsers revalidate every time. */
        $response->header('Cache-Control', $body ? 'public, max-age=0, s-maxage=20' : 'no-store');
        return $response;
    }

    /**
     * The current cached price for one coin — from the cache only.
     *
     * @return array{coin:string, price:float, change24h:?float, fetchedAt:string,
     *               updatedAt:?string, nextRefreshAt:string, source:string, toman:?float}|null
     */
    public function read(Coin $coin): ?array
    {
        $tickerTtl = $this->cache->ttl('ticker');
        $interval = max($tickerTtl, Scheduler::interval($this->config));

        $ticker = $this->cache->peek('ticker', $this->cache->keyFor('ticker'));
        $row = $ticker['data']['prices'][$coin->coingeckoId] ?? null;

        if (is_array($row) && isset($row['price'])) {
            $price = (float) $row['price'];
            $change = isset($row['change24h']) ? (float) $row['change24h'] : null;
            $fetched = (int) ($row['fetchedAt'] ?? $ticker['fetchedAt'] ?? time());
            $updated = isset($row['updatedAt']) ? (int) $row['updatedAt'] : null;
            $source = 'ticker';
        } else {
            /* Before the first ticker refresh — or if the ticker is refused —
               the five-minute market entry is still a real price. */
            $market = $this->cache->peek('market', $this->cache->keyFor('market', $coin));
            if (!is_array($market['data'] ?? null) || !isset($market['data']['price'])) {
                return null;
            }
            $price = (float) $market['data']['price'];
            $change = isset($market['data']['change24h']) ? (float) $market['data']['change24h'] : null;
            $fetched = (int) ($market['fetchedAt'] ?? time());
            $updated = null;
            $source = 'market';
            $interval = $this->cache->ttl('market');
        }

        $fx = $this->cache->peek('fx', $this->cache->keyFor('fx', $coin));
        $rate = $fx['data']['usdToToman'] ?? null;

        return [
            'coin'          => $coin->slug,
            'price'         => $price,
            'change24h'     => $change,
            'fetchedAt'     => gmdate('c', $fetched),
            'updatedAt'     => $updated ? gmdate('c', $updated) : null,
            // When the next server refresh should have landed. Never in the
            // past by more than one interval, so a stalled cron does not make
            // every page poll in a tight loop.
            'nextRefreshAt' => gmdate('c', max($fetched + $interval, time() + 15)),
            'source'        => $source,
            'toman'         => is_numeric($rate) ? round($price * (float) $rate) : null,
        ];
    }

    /** The URL the page polls, for the data island. */
    public static function url(string $slug): string
    {
        return function_exists('rest_url') ? rest_url(self::NS . '/price/' . $slug) : home_url('/wp-json/' . self::NS . '/price/' . $slug);
    }
}
