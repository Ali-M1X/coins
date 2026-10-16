<?php
/**
 * A small per-visitor rate limit for this plugin's public REST endpoints
 * (2.9.0).
 *
 * Keyed by a salted hash of REMOTE_ADDR — the address the connection really
 * came from. X-Forwarded-For is NOT trusted: anyone can write it, so a
 * limiter keyed on it is a limiter anyone can step around. (A site behind a
 * proxy that rewrites REMOTE_ADDR already gives WordPress the real address.)
 * The address itself is never stored, only the hash, for one window.
 *
 * Fixed windows in a transient: one read and one write per allowed request,
 * nothing at all for a refused one beyond the read.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class RateLimit
{
    /**
     * Count one request against $bucket. True when it is allowed.
     *
     * @param int $max    requests per window
     * @param int $window seconds
     */
    public static function allow(string $bucket, int $max, int $window): bool
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $salt = function_exists('wp_salt') ? wp_salt('nonce') : 'thb';
        $key = 'thb_rl_' . $bucket . '_' . substr(hash('sha256', $salt . '|' . $ip), 0, 20);

        $now = time();
        $state = get_transient($key);
        if (!is_array($state) || ($state['until'] ?? 0) <= $now) {
            $state = ['n' => 0, 'until' => $now + $window];
        }
        if ($state['n'] >= $max) {
            return false;
        }
        $state['n']++;
        set_transient($key, $state, max(1, $state['until'] - $now));
        return true;
    }

    /** The standard refusal: HTTP 429 with Retry-After. */
    public static function refusal(int $window): \WP_REST_Response
    {
        $r = new \WP_REST_Response(['error' => 'too many requests'], 429);
        $r->header('Retry-After', (string) $window);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }
}
