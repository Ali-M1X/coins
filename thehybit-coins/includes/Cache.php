<?php
/**
 * TTL-aware cache in front of every provider call.
 *
 * Two behaviours that matter more than the storage mechanism:
 *
 * 1. TTLs come from config/providers.php. Nothing here decides how long
 *    anything lives.
 *
 * 2. STALE-WHILE-ERROR. A cached payload is kept past its TTL for the
 *    configured grace window. If a refresh fails — rate limit, timeout, an
 *    outage — the last good value is served with `stale => true` rather than a
 *    blank section. A page briefly showing a five-minute-old price is strictly
 *    better than one showing nothing, and the flag lets the UI say so.
 *
 * Backed by transients so it works on shared hosting with no object cache; it
 * transparently uses Redis/Memcached when one is installed.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class Cache
{
    private const PREFIX = 'thb_c_';

    public function __construct(private array $config) {}

    /** Dataset name for one chart window, e.g. "chart.90d". */
    public const CHART_PREFIX = 'chart.';

    /**
     * The cache key for one dataset, honouring its SCOPE.
     *
     * A site-scoped dataset gets a key with no coin in it, so every coin reads
     * and writes the same entry — one request for the whole installation rather
     * than one per coin. A chain-scoped dataset is shared by every coin on that
     * chain. Only coin scope carries the coin.
     *
     * Callers should use this rather than Coin::cacheKey() directly; the latter
     * remains for the coin-scoped case it was written for.
     */
    public function keyFor(string $dataset, ?Coin $coin = null): string
    {
        return (new Datasets($this->config))->scopeKey($dataset, $coin);
    }

    public function ttl(string $dataset): int
    {
        // Each chart window is its own dataset with its own lifetime, taken
        // from the granularity CoinGecko actually samples that window at.
        // See config/providers.php for why that is the right number.
        if (str_starts_with($dataset, self::CHART_PREFIX)) {
            $period = substr($dataset, strlen(self::CHART_PREFIX));
            return (int) ($this->config['chart']['windows'][$period]['ttl']
                ?? $this->config['ttl']['chart']);
        }

        return (int) ($this->config['ttl'][$dataset] ?? 5 * MINUTE_IN_SECONDS);
    }

    /**
     * Look at a cached entry WITHOUT fetching on a miss.
     *
     * The chart scheduler needs to know how stale each window is before it
     * decides which ones this request can afford to refresh. remember() cannot
     * answer that, because asking it is what triggers the call.
     *
     * @return array{data:mixed, fetchedAt:int, miss?:bool}|null
     */
    public function peek(string $dataset, string $key): ?array
    {
        $entry = get_transient(self::PREFIX . md5($dataset . '|' . $key));
        return is_array($entry) ? $entry : null;
    }

    /** How long a FAILURE is remembered. Short by design; see remember(). */
    public function missTtl(): int
    {
        return (int) ($this->config['miss_ttl'] ?? 60);
    }

    /**
     * Fetch through the cache.
     *
     * @param string        $dataset  drives the TTL, e.g. 'market'
     * @param string        $key      unique within the dataset (Coin::cacheKey())
     * @param callable      $fetch    () => array  raw provider payload
     * @param callable|null $merge    (array $fresh, array $previous) => array
     *        Optional. Lets a PARTIAL refresh keep what the last good fetch had.
     *        Used for the chart, where five windows are fetched independently
     *        and a rate limit can return only some of them; without it a
     *        successful 24h refresh would drop the 1y series we already held.
     * @return array{data:mixed, fetchedAt:int, stale:bool, source:string}
     */
    public function remember(string $dataset, string $key, callable $fetch, ?callable $merge = null): array
    {
        $name = self::PREFIX . md5($dataset . '|' . $key);
        $entry = get_transient($name);
        $now = time();
        $ttl = $this->ttl($dataset);

        if (is_array($entry) && isset($entry['fetchedAt'])) {
            $age = $now - (int) $entry['fetchedAt'];

            // A remembered FAILURE. Held far more briefly than a success, and
            // only to stop the same doomed call being repeated several times
            // within one page render and on every subsequent view. Measured
            // before this existed: a provider returning null cost five
            // identical HTTP calls per page, warm cache or not, forever.
            if (!empty($entry['miss'])) {
                if ($age < $this->missTtl()) {
                    return ['data' => null, 'fetchedAt' => 0, 'stale' => true, 'source' => 'unavailable'];
                }
            } elseif ($age < $ttl) {
                return $entry + ['stale' => false];
            }
        }

        $previous = (is_array($entry) && isset($entry['data']) && is_array($entry['data']))
            ? $entry['data']
            : null;

        try {
            $fresh = $fetch();
            if ($fresh === null) {
                throw new \RuntimeException('collector returned null');
            }

            if ($merge !== null && $previous !== null && is_array($fresh)) {
                $fresh = $merge($fresh, $previous);
            }

            $entry = [
                'data'      => $fresh,
                'fetchedAt' => $now,
                'stale'     => false,
                'source'    => 'live',
            ];

            // Stored for TTL + grace so the stale copy outlives its freshness.
            set_transient($name, $entry, $ttl + (int) $this->config['stale_grace']);
            self::clearError($dataset);
            return $entry;

        } catch (\Throwable $e) {
            error_log('[thb] refresh failed for ' . $dataset . '/' . $key . ': ' . $e->getMessage());
            self::noteError($dataset, $e->getMessage());

            if ($previous !== null) {
                $entry['stale'] = true;
                $entry['source'] = 'stale';
                return $entry;
            }

            // Nothing cached and nothing fetched. Record the miss so the next
            // four pipeline runs in this same request do not each retry it, then
            // render empty — which every section already handles.
            set_transient($name, ['data' => null, 'fetchedAt' => $now, 'miss' => true], $this->missTtl());

            return ['data' => null, 'fetchedAt' => 0, 'stale' => true, 'source' => 'unavailable'];
        }
    }

    /* ------------------------------------------------------------------
     * The last failure of each dataset, for the diagnostics screen.
     *
     * The error log is not something a site owner reads, and a dataset that
     * fails every time looks, on the page, exactly like one that has not been
     * fetched yet. One small option keeps the most recent reason per dataset;
     * a success clears it. Written only when something changes.
     * ---------------------------------------------------------------- */
    public const OPTION_ERRORS = 'thb_coins_last_errors';

    private static function noteError(string $dataset, string $message): void
    {
        $all = get_option(self::OPTION_ERRORS, []);
        $all = is_array($all) ? $all : [];
        $all[$dataset] = ['at' => gmdate('c', time()), 'message' => mb_substr($message, 0, 300)];
        update_option(self::OPTION_ERRORS, $all, false);
    }

    private static function clearError(string $dataset): void
    {
        $all = get_option(self::OPTION_ERRORS, []);
        if (is_array($all) && isset($all[$dataset])) {
            unset($all[$dataset]);
            update_option(self::OPTION_ERRORS, $all, false);
        }
    }

    /** @return array<string, array{at:string, message:string}> */
    public static function lastErrors(): array
    {
        $all = get_option(self::OPTION_ERRORS, []);
        return is_array($all) ? $all : [];
    }

    /**
     * Store a payload obtained OUTSIDE remember().
     *
     * The batched market fetch gets many coins' data from one request, so it
     * cannot go through remember(), which is built around one key and one
     * fetch. The entry it writes is byte-identical to what remember() would
     * have written, which is what lets a page render stay ignorant of how the
     * data arrived.
     */
    public function put(string $dataset, string $key, array $data): void
    {
        set_transient(
            self::PREFIX . md5($dataset . '|' . $key),
            ['data' => $data, 'fetchedAt' => time(), 'stale' => false, 'source' => 'live'],
            $this->ttl($dataset) + (int) $this->config['stale_grace']
        );
    }

    public function forget(string $dataset, string $key): void
    {
        delete_transient(self::PREFIX . md5($dataset . '|' . $key));
    }

    /** Drop every cached dataset for one coin. Used by the "refresh now" action. */
    public function forgetCoin(Coin $coin): void
    {
        foreach ($this->datasets() as $dataset) {
            $this->forget($dataset, $coin->cacheKey($dataset));
        }
    }

    /**
     * Every dataset name the cache can hold, chart windows included.
     *
     * The windows are not keys of the `ttl` map — they carry their own
     * lifetimes — so anything walking "all datasets" has to come through here
     * or it silently skips the largest part of the cache.
     *
     * @return array<int, string>
     */
    public function datasets(): array
    {
        $names = array_keys($this->config['ttl']);
        foreach (array_keys($this->config['chart']['windows'] ?? []) as $period) {
            $names[] = self::CHART_PREFIX . $period;
        }
        return $names;
    }

    /**
     * Drop every cached dataset for one coin, scope included.
     *
     * Site-scoped entries are deliberately left alone: they do not belong to
     * this coin, and clearing them because someone refreshed one coin would
     * force a refetch that every other coin then pays for.
     */
    public function forgetCoinScoped(Coin $coin): void
    {
        $datasets = new Datasets($this->config);
        foreach ($this->datasets() as $dataset) {
            if ($datasets->scope($dataset) === Datasets::SCOPE_SITE) {
                continue;
            }
            $this->forget($dataset, $datasets->scopeKey($dataset, $coin));
        }
    }
}
