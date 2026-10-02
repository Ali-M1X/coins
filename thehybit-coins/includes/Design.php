<?php
/**
 * Which coin-page design a request gets — classic or v2.
 *
 * THREE INPUTS, ONE ANSWER
 *
 *   1. ?thb_design=v2 or ?thb_design=classic, for THIS visit only. This is how
 *      the new design is tried on the live site before anyone else sees it.
 *   2. The site-wide setting under Settings → TheHybit Coins. Default: classic.
 *   3. Nothing else. No cookie, no session: a preview that stuck to a browser
 *      would make "what does the site show" depend on who is asking, which is
 *      exactly the confusion a preview switch is meant to avoid.
 *
 * The parameter wins over the setting, in both directions, so the classic page
 * can still be compared after v2 becomes the default.
 *
 * WHY ANY URL CARRYING THE PARAMETER IS NOINDEX
 *
 * `/coin/ethereum/?thb_design=v2` and `/coin/ethereum/` are the same coin, the
 * same figures, the same text. Indexed separately they compete with each other
 * for the same query and split whatever authority the page earns. So a request
 * that carries the parameter is marked noindex,follow — through WordPress's own
 * robots API and through Yoast and Rank Math when they are installed, since
 * either of those replaces core's tag with its own — and the canonical URL never
 * carries a parameter at all. The un-parameterised URL is unaffected and remains
 * the one search engines should index, whichever design it currently serves.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class Design
{
    public const CLASSIC = 'classic';
    public const V2      = 'v2';
    public const PARAM   = 'thb_design';

    /** @return array<string,string> value => Persian label, for the settings screen */
    public static function choices(): array
    {
        return [
            self::CLASSIC => 'کلاسیک (نسخه ۱.۸)',
            self::V2      => 'جدید (نسخه ۲)',
        ];
    }

    /** The site-wide default. Anything unrecognised is classic. */
    public static function setting(): string
    {
        $value = (string) Settings::get('design', self::CLASSIC);
        return isset(self::choices()[$value]) ? $value : self::CLASSIC;
    }

    /** The per-visit override, if a valid one was asked for. */
    public static function requested(): ?string
    {
        if (!isset($_GET[self::PARAM])) {
            return null;
        }
        $value = sanitize_key(wp_unslash((string) $_GET[self::PARAM]));
        return isset(self::choices()[$value]) ? $value : null;
    }

    public static function current(): string
    {
        return self::requested() ?? self::setting();
    }

    public static function isV2(): bool
    {
        return self::current() === self::V2;
    }

    /**
     * Is this request a parameterised duplicate of the canonical page?
     *
     * Any value of the parameter counts — including ?thb_design=classic once v2
     * is the default — because every such URL duplicates the canonical one.
     */
    public static function isParameterised(): bool
    {
        return isset($_GET[self::PARAM]);
    }

    /* ------------------------------------------------------------------
     * Robots
     * ---------------------------------------------------------------- */

    public function register(): void
    {
        add_filter('wp_robots', [$this, 'coreRobots']);
        add_filter('wpseo_robots', [$this, 'yoastRobots']);
        add_filter('rank_math/frontend/robots', [$this, 'rankMathRobots']);
    }

    private static function applies(): bool
    {
        return self::isParameterised()
            && function_exists('is_singular')
            && is_singular(CoinRepository::POST_TYPE);
    }

    /** WordPress 5.7+ robots API. @param array<string,bool|string> $robots */
    public function coreRobots(array $robots): array
    {
        if (!self::applies()) {
            return $robots;
        }
        unset($robots['index']);
        $robots['noindex'] = true;
        $robots['follow'] = true;
        return $robots;
    }

    /** Yoast replaces core's tag with its own, so it gets told too. */
    public function yoastRobots(string $robots): string
    {
        return self::applies() ? 'noindex, follow' : $robots;
    }

    /** Rank Math likewise. @param array<string,string> $robots */
    public function rankMathRobots(array $robots): array
    {
        if (!self::applies()) {
            return $robots;
        }
        $robots['index'] = 'noindex';
        $robots['follow'] = 'follow';
        return $robots;
    }
}
