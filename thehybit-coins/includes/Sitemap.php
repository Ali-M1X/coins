<?php
/**
 * Coin pages in the XML sitemap with an honest <lastmod> (2.6.0).
 *
 * WordPress core and Yoast both list the coin post type already, but date
 * each page by its post's own modified time — which for a coin page is the
 * day it was created, while its figures change every few minutes. lastmod is
 * the later of the two: the post's modified time and the coin's last market
 * refresh, read from the cache (never a provider request).
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class Sitemap
{
    public function __construct(private Cache $cache, private CoinRepository $coins) {}

    public function register(): void
    {
        add_filter('wp_sitemaps_posts_entry', [$this, 'core'], 10, 3);
        add_filter('wpseo_sitemap_entry', [$this, 'yoast'], 10, 3);
    }

    /** Unix time of the page's last real change, or null if it is not a coin. */
    public function lastmod(int $postId, ?string $postModifiedGmt = null): ?int
    {
        $coin = $this->coins->find($postId);
        if ($coin === null) {
            return null;
        }
        $times = [];
        if ($postModifiedGmt) {
            $t = strtotime($postModifiedGmt . ' UTC');
            if ($t !== false) {
                $times[] = $t;
            }
        }
        $market = $this->cache->peek('market', $this->cache->keyFor('market', $coin));
        if (is_array($market) && !empty($market['fetchedAt'])) {
            $times[] = (int) $market['fetchedAt'];
        }
        return $times === [] ? null : max($times);
    }

    /** WordPress core sitemaps (wp-sitemap-posts-thb_coin-1.xml). */
    public function core(array $entry, $post, string $postType): array
    {
        if ($postType !== CoinRepository::POST_TYPE || !is_object($post)) {
            return $entry;
        }
        $t = $this->lastmod((int) $post->ID, (string) ($post->post_modified_gmt ?? ''));
        if ($t !== null) {
            $entry['lastmod'] = gmdate('c', $t);
        }
        return $entry;
    }

    /** Yoast SEO sitemaps: `mod` is its lastmod. */
    public function yoast($url, $type, $post)
    {
        if (!is_array($url) || !is_object($post) || ($post->post_type ?? '') !== CoinRepository::POST_TYPE) {
            return $url;
        }
        $t = $this->lastmod((int) $post->ID, (string) ($post->post_modified_gmt ?? ''));
        if ($t !== null) {
            $url['mod'] = gmdate('c', $t);
        }
        return $url;
    }
}
