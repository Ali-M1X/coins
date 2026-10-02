<?php
/**
 * Long-term storage of raw provider snapshots.
 *
 * The point of the whole exercise: a page that only mirrors the current API
 * response can never grow historical charts, real growth figures, or any
 * analytics of our own. Those need OUR OWN record of what the providers said,
 * accumulated over time.
 *
 * Design decisions worth stating:
 *
 * - SNAPSHOTS ARE RAW, never derived. Ratios and growth stay in Derive.php, as
 *   they do in derive.js. Storing derived values would freeze today's formula
 *   into the archive and make it impossible to recompute after the provisional
 *   scoring model changes — which it will.
 *
 * - APPEND FAR LESS OFTEN THAN WE CACHE. The market cache refreshes every two
 *   minutes; history appends hourly. Storing every refresh would mean ~260k
 *   rows per coin per year to describe a curve that hourly resolution captures
 *   perfectly. Intervals are per dataset in config/providers.php.
 *
 * - ONE NARROW TABLE, not a table per provider. Datasets arrive at different
 *   times with different shapes; a single (coin, dataset, captured_at, payload)
 *   row keeps writes cheap and lets a new provider land without a migration.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class History
{
    public const TABLE = 'thb_coin_history';
    private const DB_VERSION = '1.0.0';

    /** @var array<string, array> per-request memo for series(); see that method */
    private array $seriesMemo = [];

    public function __construct(private array $config) {}

    private function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function install(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        // `payload` is the normalized-but-raw provider slice as JSON. Indexed on
        // (coin, dataset, captured_at) because every read is "this coin's series
        // for this dataset over a window".
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            coin_slug VARCHAR(64) NOT NULL,
            dataset VARCHAR(32) NOT NULL,
            provider VARCHAR(32) NOT NULL,
            captured_at DATETIME NOT NULL,
            payload LONGTEXT NOT NULL,
            PRIMARY KEY (id),
            KEY coin_dataset_time (coin_slug, dataset, captured_at),
            KEY captured_at (captured_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option('thb_history_db_version', self::DB_VERSION);
    }

    /**
     * Append a snapshot, if enough time has passed since the last one.
     * Returns true when a row was written.
     */
    public function record(Coin $coin, string $dataset, string $provider, array $payload): bool
    {
        if (empty($this->config['history']['enabled'])) {
            return false;
        }

        $rule = $this->config['history']['datasets'][$dataset] ?? null;
        if (!$rule) {
            return false;
        }

        $last = $this->lastCapturedAt($coin->slug, $dataset);
        if ($last !== null && (time() - $last) < (int) $rule['interval']) {
            return false; // too soon; the cache already serves this
        }

        global $wpdb;
        $wpdb->insert($this->table(), [
            'coin_slug'   => $coin->slug,
            'dataset'     => $dataset,
            'provider'    => $provider,
            'captured_at' => gmdate('Y-m-d H:i:s'),
            'payload'     => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
        ], ['%s', '%s', '%s', '%s', '%s']);

        // A new row invalidates any window already read in this request.
        $this->seriesMemo = [];

        return true;
    }

    private function lastCapturedAt(string $slug, string $dataset): ?int
    {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT captured_at FROM {$this->table()} WHERE coin_slug = %s AND dataset = %s ORDER BY captured_at DESC LIMIT 1",
            $slug,
            $dataset
        ));
        return $value ? strtotime($value . ' UTC') : null;
    }

    /**
     * Read a series back out. This is what future historical charts and growth
     * calculations will consume instead of asking a provider for history.
     *
     * @return array<int, array{capturedAt:string, payload:array}>
     */
    public function series(string $slug, string $dataset, string $since = '-30 days', int $limit = 2000): array
    {
        // Per-request memo. One page render asked for the same 30-day market
        // window twice (growth() and the volume average) for each of five
        // pipeline runs; the rows cannot change mid-request, so reading them
        // once is both cheaper and more consistent.
        $memoKey = $slug . '|' . $dataset . '|' . $since . '|' . $limit;
        if (isset($this->seriesMemo[$memoKey])) {
            return $this->seriesMemo[$memoKey];
        }

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT captured_at, payload FROM {$this->table()}
             WHERE coin_slug = %s AND dataset = %s AND captured_at >= %s
             ORDER BY captured_at ASC LIMIT %d",
            $slug,
            $dataset,
            gmdate('Y-m-d H:i:s', strtotime($since)),
            $limit
        ), ARRAY_A) ?: [];

        return $this->seriesMemo[$memoKey] = array_map(static fn($r) => [
            'capturedAt' => $r['captured_at'],
            'payload'    => json_decode($r['payload'], true) ?: [],
        ], $rows);
    }

    /**
     * Growth between the oldest and newest snapshot in a window, as a percentage.
     * The eventual replacement for the *30dAgo fields the fixture carries today.
     */
    public function growth(string $slug, string $dataset, string $path, string $since = '-30 days'): ?float
    {
        $series = $this->series($slug, $dataset, $since);
        if (count($series) < 2) {
            return null; // not enough history yet — say so rather than guess
        }

        $first = self::dig($series[0]['payload'], $path);
        $last  = self::dig($series[count($series) - 1]['payload'], $path);

        if (!is_numeric($first) || !is_numeric($last) || (float) $first == 0.0) {
            return null;
        }
        return (((float) $last - (float) $first) / (float) $first) * 100;
    }

    /** Prune beyond each dataset's retention window. Runs daily. */
    public function prune(): int
    {
        global $wpdb;
        $deleted = 0;
        foreach ($this->config['history']['datasets'] as $dataset => $rule) {
            $cutoff = gmdate('Y-m-d H:i:s', time() - (int) $rule['retain']);
            $deleted += (int) $wpdb->query($wpdb->prepare(
                "DELETE FROM {$this->table()} WHERE dataset = %s AND captured_at < %s",
                $dataset,
                $cutoff
            ));
        }
        return $deleted;
    }

    private static function dig(array $data, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }
        return $data;
    }
}
