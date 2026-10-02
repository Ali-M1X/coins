<?php
/**
 * A mutual-exclusion lock for the warming tick.
 *
 * WHY THE SCHEDULER NEEDS ONE AT ALL
 *
 * Once a real crontab hits wp-cron.php every minute, two ticks overlapping stops
 * being hypothetical. A slow tick, a cron run that fires while the previous one
 * is still in an 8-second HTTP timeout, an administrator pressing a button in
 * Tools while cron runs — all three put two ticks in flight at once, and two
 * ticks are not merely wasteful:
 *
 *   - They both read the same cursor and both write it back, so one tick's
 *     progress is silently discarded and those datasets are served twice while
 *     others are never reached.
 *   - They spend the same minute's CoinGecko budget twice. With a measured
 *     ceiling of five requests a minute, two ticks each taking four is exactly
 *     how a 429 gets earned by a system built to avoid them.
 *
 * WHY NOT A TRANSIENT
 *
 * The obvious `get_transient()`-then-`set_transient()` lock is not a lock. Both
 * processes read "free" before either writes, and both proceed. Whether that
 * race is ever lost depends on timing we do not control, which is the worst
 * kind of bug to ship: correct in testing, wrong under load, invisible either
 * way.
 *
 * So the acquire is a single atomic statement, exactly as WordPress core does it
 * in WP_Upgrader::create_lock(): `INSERT IGNORE` against the unique index on
 * option_name. The row either did not exist and is now ours, or it existed and
 * is not. There is no window between the two.
 *
 * EXPIRY, AND WHY IT IS A TAKEOVER RATHER THAN A DELETE
 *
 * A process killed by max_execution_time, an OOM, or a hosting restart never
 * runs its release. The lock must therefore expire, or warming stops forever
 * and nothing on the site says why. But "expired, so delete it and carry on" is
 * itself a race: several waiting processes all see the same expired lock and
 * all delete-then-insert, and more than one wins.
 *
 * The takeover is a compare-and-swap instead — an UPDATE guarded by the exact
 * value that was read. Whichever process the database serves first changes the
 * value; every other one's WHERE clause no longer matches and it backs off. One
 * winner, always, with no coordination.
 *
 * A takeover is counted, because a lock that keeps needing to be stolen means
 * ticks are dying rather than finishing, and that is worth seeing on the
 * diagnostics screen rather than inferring from missing data.
 *
 * RELEASE IS TOKEN-GUARDED
 *
 * A process that stalled long enough to be taken over must not, on waking up,
 * delete the lock that now belongs to somebody else. So release deletes only if
 * the stored token is still the one it was given. This also makes release
 * idempotent, which matters because the tick releases from both a `finally` and
 * a shutdown handler.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class Lock
{
    public const OPTION = 'thb_warm_lock';

    /** Diagnostics: how often a dead holder had to be displaced. */
    public const OPTION_TAKEOVERS = 'thb_warm_lock_takeovers';

    public function __construct(private array $config) {}

    /**
     * Take the lock, or report that somebody else has it.
     *
     * @return string|null the token to release with, or null if not acquired
     */
    public function acquire(): ?string
    {
        $now   = time();
        $token = self::mint();
        $mine  = $this->encode($token, $now);

        if ($this->insertIfAbsent($mine)) {
            return $token;
        }

        $held = $this->read();
        if ($held === null) {
            // Released in the moment between our INSERT and our read. One more
            // attempt, then give up — a retry loop here would be its own bug.
            return $this->insertIfAbsent($mine) ? $token : null;
        }

        if ($now < (int) $held['expires']) {
            return null;   // a healthy holder; this is the normal overlap case
        }

        // Past expiry. Exactly one of the waiting processes may win this.
        if (!$this->swap($held['raw'], $mine)) {
            return null;
        }

        $this->countTakeover($held, $now);
        return $token;
    }

    /** Give the lock back. Only ours, and safe to call more than once. */
    public function release(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        $held = $this->read();
        if ($held === null || ($held['token'] ?? '') !== $token) {
            return false;   // already expired and taken over; not ours to drop
        }

        return $this->deleteIfMatches($held['raw']);
    }

    public function isHeld(): bool
    {
        $held = $this->read();
        return $held !== null && time() < (int) $held['expires'];
    }

    /**
     * Everything the diagnostics screen shows about the lock.
     *
     * @return array{held:bool, expired:bool, token:string, pid:int, host:string,
     *               since:int, ageSeconds:int, expiresIn:int, ttl:int,
     *               takeovers:int, lastTakeoverAt:int, lastTakeoverHeldFor:int}
     */
    public function status(): array
    {
        $held  = $this->read();
        $now   = time();
        $stats = (array) get_option(self::OPTION_TAKEOVERS, []);

        $base = [
            'held'                => false,
            'expired'             => false,
            'token'               => '',
            'pid'                 => 0,
            'host'                => '',
            'since'               => 0,
            'ageSeconds'          => 0,
            'expiresIn'           => 0,
            'ttl'                 => $this->ttl(),
            'takeovers'           => (int) ($stats['count'] ?? 0),
            'lastTakeoverAt'      => (int) ($stats['at'] ?? 0),
            'lastTakeoverHeldFor' => (int) ($stats['heldFor'] ?? 0),
        ];

        if ($held === null) {
            return $base;
        }

        $expires = (int) $held['expires'];

        return array_merge($base, [
            // A row that is past its expiry is still a row: it is reported as
            // present but expired, because "nothing there" and "a dead worker
            // left this behind" are different problems.
            'held'       => $now < $expires,
            'expired'    => $now >= $expires,
            'token'      => (string) ($held['token'] ?? ''),
            'pid'        => (int) ($held['pid'] ?? 0),
            'host'       => (string) ($held['host'] ?? ''),
            'since'      => (int) ($held['since'] ?? 0),
            'ageSeconds' => max(0, $now - (int) ($held['since'] ?? $now)),
            'expiresIn'  => max(0, $expires - $now),
        ]);
    }

    /** Drop the lock whoever holds it. Deactivation and diagnostics only. */
    public function forceRelease(): void
    {
        delete_option(self::OPTION);
    }

    /**
     * How long a lock outlives the process that took it.
     *
     * Long enough that a tick doing its full allowance of work is never
     * displaced mid-flight, short enough that a killed worker costs a couple of
     * ticks rather than an afternoon. The floor exists so a misconfiguration
     * cannot produce a lock that expires while ticks are still running, which
     * would be worse than no lock at all.
     */
    public function ttl(): int
    {
        $maxSeconds = (int) ($this->config['scheduler']['max_seconds'] ?? 20);
        $configured = (int) ($this->config['scheduler']['lock_ttl'] ?? 0);

        return max($maxSeconds * 3, 60, $configured);
    }

    /* ------------------------------------------------------------------
     * Storage
     *
     * Two primitives, each with an atomic database implementation and a
     * single-process fallback. The fallback is what runs in the CLI test
     * harness, where there is no MySQL and no concurrency to lose a race to;
     * it has identical semantics, so the surrounding logic — expiry, takeover,
     * token-guarded release — is the same code either way.
     * ---------------------------------------------------------------- */

    /** True only if the row did not already exist. */
    private function insertIfAbsent(string $value): bool
    {
        $wpdb = self::db();

        if ($wpdb === null) {
            if (get_option(self::OPTION, null) !== null) {
                return false;
            }
            update_option(self::OPTION, $value, false);
            return true;
        }

        // INSERT IGNORE against the unique index on option_name: one statement,
        // so there is no read-then-write window for a second process to slip
        // into. This is WP_Upgrader::create_lock()'s mechanism.
        $rows = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')",
            self::OPTION,
            $value
        ));

        // Raw SQL bypasses the options cache, which would otherwise keep
        // serving the pre-insert state to this process.
        self::flush();

        return (int) $rows === 1;
    }

    /** Compare-and-swap. True only for the one process whose $from still matched. */
    private function swap(string $from, string $to): bool
    {
        $wpdb = self::db();

        if ($wpdb === null) {
            if ((string) get_option(self::OPTION, '') !== $from) {
                return false;
            }
            update_option(self::OPTION, $to, false);
            return true;
        }

        $rows = $wpdb->query($wpdb->prepare(
            "UPDATE `{$wpdb->options}` SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s",
            $to,
            self::OPTION,
            $from
        ));

        self::flush();

        return (int) $rows === 1;
    }

    /** Delete, but only while the row still holds the value we read. */
    private function deleteIfMatches(string $value): bool
    {
        $wpdb = self::db();

        if ($wpdb === null) {
            if ((string) get_option(self::OPTION, '') !== $value) {
                return false;
            }
            delete_option(self::OPTION);
            return true;
        }

        $rows = $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$wpdb->options}` WHERE `option_name` = %s AND `option_value` = %s",
            self::OPTION,
            $value
        ));

        self::flush();

        return (int) $rows === 1;
    }

    /** @return array{token:string, pid:int, host:string, since:int, expires:int, raw:string}|null */
    private function read(): ?array
    {
        self::flush();

        $raw = get_option(self::OPTION, null);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['token'], $decoded['expires'])) {
            // Unreadable: treat it as a dead lock rather than a permanent one.
            return ['token' => '', 'pid' => 0, 'host' => '', 'since' => 0, 'expires' => 0, 'raw' => $raw];
        }

        $decoded['raw'] = $raw;
        return $decoded;
    }

    private function encode(string $token, int $now): string
    {
        return (string) wp_json_encode([
            'token'   => $token,
            'pid'     => function_exists('getmypid') ? (int) getmypid() : 0,
            'host'    => function_exists('gethostname') ? (string) gethostname() : '',
            'since'   => $now,
            'expires' => $now + $this->ttl(),
        ]);
    }

    private function countTakeover(array $held, int $now): void
    {
        $stats = (array) get_option(self::OPTION_TAKEOVERS, []);

        update_option(self::OPTION_TAKEOVERS, [
            'count'   => (int) ($stats['count'] ?? 0) + 1,
            'at'      => $now,
            'heldFor' => max(0, $now - (int) ($held['since'] ?? $now)),
            'pid'     => (int) ($held['pid'] ?? 0),
        ], false);

        error_log(sprintf(
            '[thb] warm lock taken over from pid %d after %ds — the previous tick did not finish',
            (int) ($held['pid'] ?? 0),
            max(0, $now - (int) ($held['since'] ?? $now))
        ));
    }

    private static function mint(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return (string) uniqid('thb', true);
        }
    }

    /** The real wpdb, or null when there is no database to be atomic against. */
    private static function db(): ?object
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;
        return ($wpdb instanceof \wpdb && isset($wpdb->options)) ? $wpdb : null;
    }

    private static function flush(): void
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::OPTION, 'options');
        }
    }
}
