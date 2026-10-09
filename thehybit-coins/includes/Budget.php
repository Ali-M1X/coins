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

        /* RESERVED CALLS. A provider may hold back part of its per-minute
           budget (`reserve_per_minute`) for calls that must not be crowded
           out — the live price ticker. Ordinary calls stop short of the
           reserve; a reserved call may use the whole minute. */
        $reserve = $reserved ? 0 : max(0, (int) ($this->settings($provider)['reserve_per_minute'] ?? 0));

        foreach ($this->limits($provider) as $window => $max) {
            if ($window === 'per_minute') {
                $max = max(1, $max - $reserve);
            }
            $used = $this->used($provider, $window);
            if ($used >= $max) {
                return sprintf('%s budget spent (%d/%d)', $window, $used, $max);
            }
        }

        return null;
    }

    /** Record one outbound request against every window. */
    public function record(string $provider): void
    {
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
                set_transient($key, ['stamps' => $stamps], $seconds * 2);
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
