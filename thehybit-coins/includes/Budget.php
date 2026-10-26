<?php
/**
 * Per-provider request budget and cooldown.
 *
 * WHAT THE PRODUCTION SERVER ACTUALLY DOES
 *
 * The provider probe measured it: five consecutive CoinGecko requests
 * succeeded, the sixth returned 429, and Retry-After said 60. That is what our
 * IP gets on our host — on shared cPanel, an allowance shared with every other
 * account on the box. It is NOT CoinGecko's published limit, it could change
 * tomorrow, and nothing here treats it as a constant: the numbers live in
 * config/providers.php and this class only enforces whatever it is told.
 *
 * With a ceiling that low, running out of budget is the normal condition at
 * scale rather than an error case. So the design goal is not "never hit the
 * limit" — it is "notice we are near it, spend what is left on the things that
 * matter, and never turn a refusal into a retry storm".
 *
 * THREE MECHANISMS, IN ORDER OF PRECEDENCE
 *
 *   1. Cooldown.  A 429 stops the provider entirely for Retry-After seconds, or
 *      a configured default. Nothing gets through, and the cache serves its last
 *      good value. This is what prevents the retry storm.
 *
 *   2. Budget.    Rolling per-minute, per-hour and per-day counters. When one is
 *      spent, further requests are refused BEFORE they are made — so we stop
 *      ourselves rather than being stopped, and the provider never sees the
 *      request that would have earned the 429.
 *
 *   3. Throttle.  The existing min_interval spacing, unchanged, and still
 *      applied only in background contexts.
 *
 * Counters live in transients, so they work on shared hosting with no object
 * cache and cost one read per request.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class Budget
{
    /** Exception code for a call WE declined to send (budget or cooldown) —
        as opposed to one the provider refused. */
    public const REFUSED = 4290;

    private const PREFIX = 'thb_budget_';

    /** Rolling windows, in seconds, mapped to their config key. */
    private const WINDOWS = [
        'per_minute' => MINUTE_IN_SECONDS,
        'per_hour'   => HOUR_IN_SECONDS,
        'per_day'    => DAY_IN_SECONDS,
    ];

    public function __construct(private array $config) {}

    /**
     * 2.14.0 — the tier of the request about to be made, set by the caller
     * around its work (the scheduler per step and per item). Null means a
     * request nobody tiered — a page render — which gets `budget_tiers.default`.
     * See config/providers.php `budget_tiers`.
     */
    public static ?int $tier = null;

    /** Run $fn with requests tiered $tier, restoring the previous tier after. */
    public static function as(int $tier, callable $fn): mixed
    {
        $was = self::$tier;
        self::$tier = $tier;
        try {
            return $fn();
        } finally {
            self::$tier = $was;
        }
    }

    /**
     * Would a request of this tier be held back to keep the daily allowance
     * for higher tiers? Null when it may go; the reason otherwise.
     */
    public function tierRefusal(string $provider, int $tier): ?string
    {
        $limits = $this->limits($provider);
        if ($tier <= 0 || !isset($limits['per_day'])) {
            return null;
        }
        $floor = (float) ($this->config['budget_tiers']['floor'][min(3, $tier)] ?? 0);
        $left = ($limits['per_day'] - $this->used($provider, 'per_day')) / $limits['per_day'];
        return $left <= $floor
            ? sprintf('daily budget low (%d%% left): kept for higher-priority work (tier %d stops at %d%%)', (int) floor($left * 100), $tier, (int) round($floor * 100))
            : null;
    }

    /**
     * May we call this provider right now?
     *
     * Returns null when the answer is yes, and a human-readable reason when it
     * is no. A reason rather than a bare false because it ends up in the error
     * log and on the diagnostics screen, where "why did nothing happen" is the
     * question being asked.
     */
    public function refuse(string $provider, bool $reserved = false): ?string
    {
        $cooling = $this->cooldownRemaining($provider);
        if ($cooling > 0) {
            $entry = get_transient($this->cooldownKey($provider));
            $status = is_array($entry) ? (int) ($entry['status'] ?? 429) : 429;
            return sprintf('cooling down for %ds after a %d', $cooling, $status);
        }

        /* 2.14.0: priority. The live ticker (`reserved`) is tier 1. */
        $tier = $reserved ? min(1, self::$tier ?? 1) : (self::$tier ?? (int) ($this->config['budget_tiers']['default'] ?? 2));
        if (($held = $this->tierRefusal($provider, $tier)) !== null) {
            return $held;
        }

        /* RESERVED CALLS. A provider may hold back part of its per-minute
           budget (`reserve_per_minute`) for calls that must not be crowded
           out — the live price ticker. Ordinary calls stop short of the
           reserve; a reserved call may use the whole minute. */
        $reserve = $reserved ? 0 : max(0, (int) ($this->settings($provider)['reserve_per_minute'] ?? 0));

        foreach ($this->limits($provider) as $window => $max) {
            $used = $this->used($provider, $window);
            if ($window === 'per_minute' && $reserve > 0) {
                /* THE RESERVE (2.3.0). What the reserved caller (the live
                   ticker, once a tick) will find in its window next time is,
                   roughly, everything since its own last call — so ordinary
                   calls stop once that leaves too little room for it, while
                   the minute's total still never passes `per_minute`. The old
                   rule subtracted the reserve from the TOTAL, which already
                   held the ticker's own calls: the reserve was counted twice,
                   and with ticks 55 s apart ordinary work got a slot only
                   every other tick. */
                $since = $this->sinceReserved($provider);
                if ($since >= max(1, $max - $reserve)) {
                    return sprintf('%s budget spent (%d/%d since the reserved call)', $window, $since, max(1, $max - $reserve));
                }
            }
            if ($used >= $max) {
                return sprintf('%s budget spent (%d/%d)', $window, $used, $max);
            }
        }

        return null;
    }

    /**
     * Calls in the current minute made at or after the last reserved call —
     * all of the minute's calls when there was no reserved call in it.
     */
    private function sinceReserved(string $provider): int
    {
        $entry = get_transient($this->counterKey($provider, 'per_minute'));
        $now = time();
        $stamps = array_filter((array) ($entry['stamps'] ?? []), static fn($t) => $now - (int) $t < 60);
        $held = array_filter((array) ($entry['reserved'] ?? []), static fn($t) => $now - (int) $t < 60);
        if ($held === []) {
            return count($stamps);
        }
        $last = max(array_map('intval', $held));
        return count(array_filter($stamps, static fn($t) => (int) $t >= $last));
    }

    /** Record one outbound request against every window. */
    public function record(string $provider, bool $reserved = false): void
    {
        $this->recordHistory($provider);
        foreach (array_keys($this->limits($provider)) as $window) {
            $seconds = self::WINDOWS[$window];
            $key = $this->counterKey($provider, $window);

            $entry = get_transient($key);
            $now = time();

            /* The MINUTE window slides: it keeps the request times themselves.
               A bucket that resets every 60s lets four calls at its end and
               four at the start of the next land inside one real minute —
               eight, against a provider measured to refuse the sixth. The
               hour and day windows are long enough that a bucket's edge does
               not matter, and stay cheap counters. */
            if ($window === 'per_minute') {
                $stamps = array_values(array_filter(
                    (array) ($entry['stamps'] ?? []),
                    static fn($t) => $now - (int) $t < $seconds
                ));
                $stamps[] = $now;
                $held = array_values(array_filter(
                    (array) ($entry['reserved'] ?? []),
                    static fn($t) => $now - (int) $t < $seconds
                ));
                if ($reserved) {
                    $held[] = $now;
                }
                set_transient($key, ['stamps' => $stamps, 'reserved' => $held], $seconds * 2);
                continue;
            }

            // A rolling window, reset when the bucket's period has elapsed.
            if (!is_array($entry) || ($now - (int) ($entry['since'] ?? 0)) >= $seconds) {
                $entry = ['since' => $now, 'count' => 0];
            }

            $entry['count']++;
            set_transient($key, $entry, $seconds * 2);
        }
    }

    /**
     * Begin a cooldown after a refusal.
     *
     * Retry-After is believed when the provider sends one — it knows better than
     * our configured guess, and on this host it has been sending 60. Bounded at
     * an hour so a provider asking us to wait a day cannot freeze the site's
     * data for a day.
     */
    public function startCooldown(string $provider, int $retryAfter = 0, int $status = 429, int $seconds = 0): int
    {
        /* $seconds, when given, is the caller's own pause — a provider that
           answers "your IP is blocked" (Blockchair's 430/434) is not asking
           for a minute's patience, and its own configured block pause wins
           over the 429 default and its one-hour cap. */
        if ($seconds <= 0) {
            $default = (int) ($this->settings($provider)['cooldown'] ?? 0);
            if ($default <= 0 && $retryAfter <= 0) {
                return 0;
            }
            $seconds = max(1, min($retryAfter > 0 ? $retryAfter : $default, HOUR_IN_SECONDS));
        }

        set_transient($this->cooldownKey($provider), [
            'until'      => time() + $seconds,
            'seconds'    => $seconds,
            'retryAfter' => $retryAfter,
            'startedAt'  => time(),
            'status'     => $status,
        ], $seconds);

        return $seconds;
    }

    public function cooldownRemaining(string $provider): int
    {
        $entry = get_transient($this->cooldownKey($provider));
        if (!is_array($entry)) {
            return 0;
        }
        $left = (int) ($entry['until'] ?? 0) - time();
        return max(0, $left);
    }

    /** Requests recorded in the current window. */
    public function used(string $provider, string $window): int
    {
        $entry = get_transient($this->counterKey($provider, $window));
        if (!is_array($entry)) {
            return 0;
        }
        if (isset($entry['stamps'])) {
            $now = time();
            return count(array_filter((array) $entry['stamps'], static fn($t) => $now - (int) $t < self::WINDOWS[$window]));
        }
        if ((time() - (int) ($entry['since'] ?? 0)) >= self::WINDOWS[$window]) {
            return 0;   // the bucket has rolled over
        }
        return (int) ($entry['count'] ?? 0);
    }

    /**
     * How many more requests this provider may take right now.
     *
     * The smallest headroom across every window, because the tightest one is
     * what actually stops us. Returns 0 while cooling down.
     */
    public function remaining(string $provider): int
    {
        if ($this->cooldownRemaining($provider) > 0) {
            return 0;
        }

        $remaining = PHP_INT_MAX;
        foreach ($this->limits($provider) as $window => $max) {
            $remaining = min($remaining, max(0, $max - $this->used($provider, $window)));
        }
        return $remaining === PHP_INT_MAX ? PHP_INT_MAX : $remaining;
    }

    /**
     * Everything the diagnostics screen needs about one provider.
     *
     * @return array{provider:string, limits:array, used:array, remaining:int, cooldown:int, cooldownDetail:?array, unlimited:bool}
     */
    public function snapshot(string $provider): array
    {
        $limits = $this->limits($provider);
        $used = [];
        foreach (array_keys($limits) as $window) {
            $used[$window] = $this->used($provider, $window);
        }

        $cooldown = get_transient($this->cooldownKey($provider));

        return [
            'provider'       => $provider,
            'limits'         => $limits,
            'used'           => $used,
            'remaining'      => $limits === [] ? PHP_INT_MAX : $this->remaining($provider),
            'cooldown'       => $this->cooldownRemaining($provider),
            'cooldownDetail' => is_array($cooldown) ? $cooldown : null,
            'unlimited'      => $limits === [],
        ];
    }

    /** Clear a provider's counters and cooldown. Diagnostics only. */
    public function reset(string $provider): void
    {
        foreach (array_keys(self::WINDOWS) as $window) {
            delete_transient($this->counterKey($provider, $window));
        }
        delete_transient($this->cooldownKey($provider));
        delete_transient(self::PREFIX . $provider . '_hist');
        delete_transient(self::PREFIX . $provider . '_strikes');
    }

    /* ------------------------------------------------------------------
     * 2.14.0 — projected daily use, for the diagnostics screen.
     *
     * Requests per clock hour for the last 24 hours, in one small transient.
     * The projection is the rate over the hours actually observed (at least
     * one), times 24 — so it answers "at the pace of the last day, how much
     * of the daily allowance will be spent", which the fixed day counter
     * cannot until it is too late.
     * ---------------------------------------------------------------- */
    private function recordHistory(string $provider): void
    {
        $key = self::PREFIX . $provider . '_hist';
        $hist = get_transient($key);
        $hist = is_array($hist) ? $hist : [];
        $hour = intdiv(time(), HOUR_IN_SECONDS);
        $hist['h'] = array_filter((array) ($hist['h'] ?? []), static fn($n, $h) => (int) $h > $hour - 24, ARRAY_FILTER_USE_BOTH);
        $hist['h'][$hour] = (int) ($hist['h'][$hour] ?? 0) + 1;
        $hist['since'] = (int) ($hist['since'] ?? time());
        set_transient($key, $hist, 2 * DAY_IN_SECONDS);
    }

    /** @return array{perHour:float, perDay:int, hours:float, limit:?int, share:?float} */
    public function projection(string $provider): array
    {
        $hist = get_transient(self::PREFIX . $provider . '_hist');
        $hour = intdiv(time(), HOUR_IN_SECONDS);
        $count = 0;
        foreach ((array) (is_array($hist) ? ($hist['h'] ?? []) : []) as $h => $n) {
            if ((int) $h > $hour - 24) {
                $count += (int) $n;
            }
        }
        $since = is_array($hist) ? (int) ($hist['since'] ?? time()) : time();
        $window = max(HOUR_IN_SECONDS, min(DAY_IN_SECONDS, time() - max($since, ($hour - 23) * HOUR_IN_SECONDS)));
        $perHour = $count / ($window / HOUR_IN_SECONDS);
        $limit = $this->limits($provider)['per_day'] ?? null;
        $perDay = (int) round($perHour * 24);
        return ['perHour' => round($perHour, 1), 'perDay' => $perDay, 'hours' => round($window / HOUR_IN_SECONDS, 1),
                'limit' => $limit, 'share' => $limit ? $perDay / $limit : null];
    }

    /* ------------------------------------------------------------------
     * 2.14.0 — exponential pause after an IP block (Blockchair's 430/434,
     * 402). The first block pauses `block_cooldown`; each further block in a
     * row doubles it, up to `block_backoff_max`. A success clears the count.
     * ---------------------------------------------------------------- */
    public function blockCooldown(string $provider, int $status): int
    {
        $s = $this->settings($provider);
        $key = self::PREFIX . $provider . '_strikes';
        $strikes = (int) get_transient($key) + 1;
        set_transient($key, $strikes, 3 * DAY_IN_SECONDS);
        $base = max(MINUTE_IN_SECONDS, (int) ($s['block_cooldown'] ?? HOUR_IN_SECONDS));
        $max = max($base, (int) ($s['block_backoff_max'] ?? DAY_IN_SECONDS));
        $seconds = (int) min($max, $base * (2 ** min(10, $strikes - 1)));
        return $this->startCooldown($provider, 0, $status, $seconds);
    }

    public function strikes(string $provider): int
    {
        return (int) get_transient(self::PREFIX . $provider . '_strikes');
    }

    public function clearStrikes(string $provider): void
    {
        if (get_transient(self::PREFIX . $provider . '_strikes') !== false) {
            delete_transient(self::PREFIX . $provider . '_strikes');
        }
    }

    /** @return array<string,int> configured windows only; an absent window is unlimited */
    private function limits(string $provider): array
    {
        $budget = (array) ($this->settings($provider)['budget'] ?? []);
        $out = [];
        foreach (self::WINDOWS as $window => $_seconds) {
            if (isset($budget[$window]) && (int) $budget[$window] > 0) {
                $out[$window] = (int) $budget[$window];
            }
        }
        return $out;
    }

    private function settings(string $provider): array
    {
        return (array) ($this->config['providers'][$provider] ?? []);
    }

    private function counterKey(string $provider, string $window): string
    {
        return self::PREFIX . $provider . '_' . $window;
    }

    private function cooldownKey(string $provider): string
    {
        // Same key the collector used before budgets existed, so an upgrade
        // does not resurrect a cooldown that had already expired.
        return 'thb_cooldown_' . $provider;
    }
}
