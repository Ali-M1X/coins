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
