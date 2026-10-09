<?php
/**
 * Base for every provider collector.
 *
 * A collector's ONLY job is: call one provider, return its raw payload in a
 * shape our normalizer understands. It does not cache (Cache.php), does not
 * compute (Derive.php) and does not know about the template.
 *
 * This is the server-side half of the rule the browser depends on:
 *
 *   Provider API -> collector -> normalization -> cache -> WordPress -> browser
 *
 * The browser never calls a provider. No API key or rate-limit budget is ever
 * exposed to a visitor, and one cache entry serves every reader.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\Collectors;

use TheHybit\Coins\Coin;

defined('ABSPATH') || exit;

abstract class Collector
{
    public function __construct(protected array $config)
    {
    }

    /** Provider id, matching a key in config/providers.php. */
    /** Set around a call that may use the provider's reserved budget. See Budget::refuse(). */
    protected bool $reserved = false;

    abstract public function id(): string;

    /** Datasets this collector can produce. */
    abstract public function datasets(): array;

    /**
     * Fetch one dataset for one coin.
     * @return array|null  raw payload, or null when unavailable
     */
    abstract public function fetch(Coin $coin, string $dataset): ?array;

    protected function settings(): array
    {
        return $this->config['providers'][$this->id()] ?? [];
    }

    public function isEnabled(): bool
    {
        return !empty($this->settings()['enabled']);
    }

    public function label(): string
    {
        return (string) ($this->settings()['label'] ?? $this->id());
    }

    /**
     * GET JSON, throttled OUTSIDE the request path.
     *
     * `min_interval` spaces calls to one provider apart when several coins
     * refresh in the same cron tick — the cheapest way to stay inside a free
     * tier without a queue. It was always meant for cron.
     *
     * It was, however, also running for visitors, and sleep() in a web request
     * is time a reader spends staring at nothing. Measured on a cold Ethereum
     * page: 19 seconds of throttle against 7 seconds of actual network — the
     * single largest component of the page load, and larger than everything it
     * was waiting for.
     *
     * So the wait now applies to cron and WP-CLI only. A web request is already
     * protected from hammering a provider by the cache in front of it, and by
     * the collectors' own per-request memoization; what it must not do is make
     * the visitor pay for pacing that exists for the benefit of a background
     * job.
     */
    /**
     * @param string|null $variant a dataset name whose host/timeout override the
     *                             provider defaults. DefiLlama is one operator
     *                             across several hostnames — stablecoins and
     *                             bridges are not on api.llama.fi — but one
     *                             budget and one cooldown, because being refused
     *                             by one is being refused by all.
     */
    protected function get(string $path, array $query = [], ?string $variant = null): ?array
    {
        $settings = $this->settings();

        $base = $variant !== null
            ? (string) ($settings['bases'][$variant] ?? $settings['base'])
            : (string) $settings['base'];

        $timeout = $variant !== null
            ? (int) ($settings['timeouts'][$variant] ?? $settings['timeout'] ?? 8)
            : (int) ($settings['timeout'] ?? 8);

        /* Provider-level query defaults, merged UNDER the caller's.
         *
         * This is how an API key reaches a provider that wants one in the query
         * string rather than a header — Settings::apply() puts it here at boot,
         * so no collector has to know a key exists. The caller's own parameters
         * win, which keeps a default from silently overriding a deliberate
         * value. */
        $query = $query + (array) ($settings['query'] ?? []);

        $url = rtrim($base, '/') . '/' . ltrim($path, '/');
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        /* THE BUDGET GATE. Refuses before the request is made, rather than
           after the provider refuses it — a request we do not send cannot earn
           a 429, and a 429 we do not earn cannot start a cooldown. Throwing here
           means Cache::remember() serves the last good payload, exactly as it
           does for any other failure. */
        $refusal = $this->budget()->refuse($this->id(), $this->reserved);
        if ($refusal !== null) {
            throw new \RuntimeException($this->id() . ': ' . $refusal, \TheHybit\Coins\Budget::REFUSED);
        }

        $this->throttle((int) ($settings['min_interval'] ?? 1));

        // Counted before the response arrives: a request that times out still
        // consumed the provider's allowance.
        $this->budget()->record($this->id());

        $started = microtime(true);
        $response = wp_remote_get($url, [
            'timeout' => $timeout,
            'headers' => array_merge(
                ['Accept' => 'application/json', 'User-Agent' => 'TheHybit/1.0 (+https://thehybit.com)'],
                (array) ($settings['headers'] ?? [])
            ),
        ]);
        $elapsed = microtime(true) - $started;

        if (is_wp_error($response)) {
            $this->trace($url, 0, 0, $elapsed, $response->get_error_message());
            throw new \RuntimeException($this->id() . ': ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $this->trace($url, $code, strlen($body), $elapsed, null, $body);

        // 429 is the one every free tier throws. Surfaced explicitly so the
        // cache's stale-while-error path is obviously the right response.
        if ($code === 429) {
            $retryAfter = 0;
            if (function_exists('wp_remote_retrieve_header')) {
                $header = wp_remote_retrieve_header($response, 'retry-after');
                if (is_numeric($header)) {
                    $retryAfter = (int) $header;
                }
            }
            $seconds = $this->budget()->startCooldown($this->id(), $retryAfter);
            error_log(sprintf(
                '[thb] %s returned 429 (Retry-After: %s) — pausing calls for %ds',
                $this->id(),
                $retryAfter > 0 ? $retryAfter . 's' : 'absent',
                $seconds
            ));
            throw new \RuntimeException($this->id() . ': rate limited (429)' . $this->excerpt($body));
        }
        /* A refusal that means "this IP is blocked", not "slow down" —
           Blockchair's 430/434 (and 402, its daily quota). Calling again a
           minute later is what extends such a ban, so the provider is paused
           for its configured `block_cooldown` instead of the 429 default. */
        if (in_array($code, (array) ($settings['block_statuses'] ?? []), true)) {
            $seconds = $this->budget()->startCooldown($this->id(), 0, $code,
                max(MINUTE_IN_SECONDS, (int) ($settings['block_cooldown'] ?? HOUR_IN_SECONDS)));
            error_log(sprintf('[thb] %s returned %d (IP blocked or quota spent) — pausing calls for %ds', $this->id(), $code, $seconds));
            throw new \RuntimeException(sprintf('%s: HTTP %d — blocked by the provider, paused %d min', $this->id(), $code, intdiv($seconds, 60))
                . $this->excerpt($body));
        }
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException($this->id() . ": HTTP {$code}" . $this->excerpt($body));
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Announce one outbound call.
     *
     * `do_action` with no listeners costs a hash lookup, so this is free on
     * every normal page render. It exists because the alternative — reasoning
     * about what a provider returned on someone else's server — is guesswork.
     * The diagnostics screen subscribes to it and can then report the exact
     * URL, status, size and timing of each call, which is the only way to tell
     * "the API refused us" apart from "the API answered and we misread it".
     *
     * The body is passed last and only inspected by a listener that asks for
     * it; nothing here retains it.
     */
    /**
     * The first ~200 characters of a refusal's body, as plain text: the
     * provider's own words for why it said no ("country blocked", "invalid
     * key", "IP blacklisted"), which the status code alone never says.
     * HTML pages (Cloudflare and other WAFs) are reduced to their text; any
     * configured key is scrubbed before it can reach the diagnostics screen.
     */
    private function excerpt(string $body): string
    {
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $body) ?? $body;
        $text = html_entity_decode(strip_tags((string) preg_replace('/</', ' <', $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) > 200) {
            $text = rtrim(mb_substr($text, 0, 200)) . '…';
        }
        return ' — "' . \TheHybit\Coins\Settings::scrub($text, $this->settings()) . '"';
    }

    private function trace(
        string $url,
        int $code,
        int $bytes,
        float $seconds,
        ?string $error = null,
        string $body = ''
    ): void {
        if (!has_action('thb_coins_http')) {
            return;
        }
        /* The diagnostics screen's live check renders this URL. A provider that
           takes its key in the query string would otherwise have that key
           printed in an admin table — exactly the screen people screenshot when
           asking for help. Scrubbed here, at the only exit. */
        $settings = $this->settings();
        do_action('thb_coins_http', [
            'provider' => $this->id(),
            'url'      => \TheHybit\Coins\Settings::scrub($url, $settings),
            'code'     => $code,
            'bytes'    => $bytes,
            'seconds'  => $seconds,
            'error'    => $error !== null ? \TheHybit\Coins\Settings::scrub($error, $settings) : null,
            'body'     => $body,
        ]);
    }

    /* ------------------------------------------------------------------
     * Budget
     *
     * Cooldown and request accounting used to live here, per collector. They
     * now live in one place — Budget — so every provider is governed by the
     * same rules and the diagnostics screen has a single thing to read. The
     * instance is lazy because most collectors never make a request in a given
     * page render.
     * ---------------------------------------------------------------- */

    private ?\TheHybit\Coins\Budget $budget = null;

    protected function budget(): \TheHybit\Coins\Budget
    {
        return $this->budget ??= new \TheHybit\Coins\Budget($this->config);
    }

    private function throttle(int $seconds): void
    {
        if ($seconds < 1 || !self::isBackground()) {
            return;
        }
        $key = 'thb_last_call_' . $this->id();
        $last = (int) get_transient($key);
        $wait = $last + $seconds - time();
        if ($wait > 0) {
            sleep(min($wait, 5));
        }
        set_transient($key, time(), 60);
    }

    /**
     * Are we outside a page render?
     *
     * DOING_CRON covers both WP's own pseudo-cron and a real system crontab
     * hitting wp-cron.php; WP_CLI covers manual warming. Anything else is a
     * visitor waiting on a response.
     */
    private static function isBackground(): bool
    {
        $background = (defined('DOING_CRON') && DOING_CRON)
            || (defined('WP_CLI') && WP_CLI)
            || (PHP_SAPI === 'cli');

        /**
         * Filters whether the current context may block on the throttle.
         *
         * Exists so a different background runner (Action Scheduler, a queue
         * worker) can opt in, and so the throttle can be exercised from a test
         * harness without pretending to be CLI.
         */
        return (bool) apply_filters('thb_coins_is_background', $background);
    }
}
