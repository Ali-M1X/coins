<?php
/**
 * A controllable clock for the test harness.
 *
 * PHP resolves an unqualified call to an internal function against the CURRENT
 * namespace before the global one. Declaring time() inside the plugin's own
 * namespaces therefore overrides it for the plugin's code without touching a
 * single shipping file — Cache, History, Budget, Lock and Collector all call
 * time() unqualified.
 *
 * This is what makes "what does the cache look like after two hours of
 * five-minute page loads" an answerable question rather than a guess: the probe
 * advances Clock::$now and every TTL, throttle window, cooldown and lock expiry
 * moves with it. Without it, testing a 24-hour TTL would take 24 hours.
 *
 * sleep() is intercepted the same way — accounted for, and advancing the clock
 * exactly as a real sleep would, so a throttle costs simulated time instead of
 * real time.
 *
 * The braced namespace form is required: a file declaring functions in several
 * namespaces cannot use the statement form.
 */

namespace {
    class Clock
    {
        /** Seconds since the epoch, as the plugin sees it. */
        public static int $now = 1_760_000_000;

        /** Total seconds spent inside Collector::throttle(). */
        public static float $slept = 0.0;

        public static function advance(int $seconds): void { self::$now += $seconds; }

        public static function reset(): void
        {
            self::$now = 1_760_000_000;
            self::$slept = 0.0;
        }
    }
}

namespace TheHybit\Coins {
    function time(): int { return \Clock::$now; }
}

namespace TheHybit\Coins\Collectors {
    function time(): int { return \Clock::$now; }

    function sleep(int $seconds): int
    {
        \Clock::$slept += $seconds;
        \Probe::$sleep += $seconds;
        \Clock::advance($seconds);
        return 0;
    }
}
