<?php
/**
 * Creates or updates the configured coins from config/coins.php.
 *
 * Idempotent: run it repeatedly and it updates in place rather than duplicating.
 * It never overwrites a field an editor has already filled — ACF remains the
 * source of truth once a coin exists, and this only bootstraps.
 *
 *   wp eval 'TheHybit\Coins\Seeder::run();'
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class Seeder
{
    /** Fields fillEditorial() may write: content only, never configuration. */
    public const EDITORIAL = ['thb_tagline', 'thb_timeline', 'thb_learn', 'thb_audience', 'thb_faq',
                              'thb_unlocks', 'thb_wikipedia', 'thb_github_repo', 'thb_price_events'];

    public const OPTION_EDITORIAL = 'thb_coins_editorial_seeded';

    /**
     * Fill the v2 editorial fields of coins that ALREADY EXIST, once per
     * plugin version.
     *
     * The setup page that runs run() is switched off once a site is set up, so
     * on a live site the fields added in 2.0.0 stayed empty and the story,
     * learning and FAQ sections showed nothing. This fills them from
     * config/coins.php, under three rules:
     *
     *   - only fields that are EMPTY — an editor's text is never overwritten;
     *   - only the content fields in EDITORIAL — identifiers, provider
     *     switches and overrides are never touched;
     *   - only coins that exist — no post is ever created here.
     *
     * Runs on admin_init, the first time an administrator opens wp-admin
     * after the upgrade, and records the version so it does not run again.
     *
     * @return array<string,int> slug => fields written
     */
    public static function fillEditorial(bool $force = false): array
    {
        if (!$force && get_option(self::OPTION_EDITORIAL) === THB_COINS_VERSION) {
            return [];
        }
        $coins = require THB_COINS_DIR . 'config/coins.php';
        $result = [];

        foreach ($coins as $slug => $spec) {
            $post = get_page_by_path($slug, OBJECT, CoinRepository::POST_TYPE);
            if (!$post) {
                continue;
            }
            $written = 0;
            foreach (self::EDITORIAL as $field) {
                if (!isset($spec['fields'][$field])) {
                    continue;
                }
                $current = function_exists('get_field') ? get_field($field, $post->ID) : get_post_meta($post->ID, $field, true);
                /* An earlier version's seed, still exactly as shipped, is
                   upgraded to the current one; anything an editor changed — by
                   even a character — is left alone. */
                $previous = (array) ($spec['fields']['previous'][$field] ?? []);
                $untouched = is_string($current) && in_array($current, $previous, true);
                if ($current !== null && $current !== '' && $current !== false && !$untouched) {
                    continue;
                }
                if (function_exists('update_field')) {
                    update_field($field, $spec['fields'][$field], $post->ID);
                } else {
                    update_post_meta($post->ID, $field, $spec['fields'][$field]);
                }
                $written++;
            }
            $result[$slug] = $written;
        }

        update_option(self::OPTION_EDITORIAL, THB_COINS_VERSION, false);
        return $result;
    }

    /** @return array<string,string> slug => action taken */
    public static function run(bool $force = false): array
    {
        $coins = require THB_COINS_DIR . 'config/coins.php';
        $result = [];

        foreach ($coins as $slug => $spec) {
            $existing = get_page_by_path($slug, OBJECT, CoinRepository::POST_TYPE);

            $postId = $existing
                ? $existing->ID
                : wp_insert_post([
                    'post_type'   => CoinRepository::POST_TYPE,
                    'post_name'   => $slug,
                    'post_title'  => $spec['post_title'],
                    'post_status' => 'publish',
                ]);

            if (is_wp_error($postId)) {
                $result[$slug] = 'error: ' . $postId->get_error_message();
                continue;
            }

            $written = 0;
            foreach ($spec['fields'] as $field => $value) {
                if ($field === 'previous') {
                    continue;   // earlier seeds, for fillEditorial(); not a field
                }
                $current = function_exists('get_field') ? get_field($field, $postId) : null;
                // Only fill blanks, so an editor's work is never clobbered.
                if (!$force && $current !== null && $current !== '' && $current !== false) {
                    continue;
                }
                if (function_exists('update_field')) {
                    update_field($field, $value, $postId);
                } else {
                    update_post_meta($postId, $field, $value);
                }
                $written++;
            }

            $result[$slug] = $existing
                ? sprintf('updated (%d fields)', $written)
                : sprintf('created #%d (%d fields)', $postId, $written);

            self::warnIfCategoryMissing($spec['fields']['thb_news_category_slug'] ?? '');
        }

        return $result;
    }

    /**
     * The news section is silent when its category does not exist — correct
     * behaviour, but easy to mistake for a bug. Say so at seed time instead.
     */
    private static function warnIfCategoryMissing(string $slug): void
    {
        if ($slug === '') {
            return;
        }
        $term = get_term_by('slug', $slug, 'category');
        if (!$term || is_wp_error($term)) {
            error_log(sprintf(
                '[thb] news category "%s" does not exist yet — the related-articles section will stay hidden until it is created under /category/news/.',
                $slug
            ));
        }
    }
}
