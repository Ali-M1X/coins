<?php
/**
 * Related articles from TheHybit's own WordPress — the PHP twin of
 * assets/js/normalize/wordpress.js.
 *
 * coin.newsCategorySlug -> WP category -> latest posts -> normalize -> Article[]
 *
 * WP_Query, not REST: it accepts `category_name` (a SLUG) directly, so there is
 * no id lookup, no HTTP round trip, and the articles land in the initial HTML
 * where search engines can see them.
 *
 * NEVER an unfiltered query. If the category slug is empty or does not resolve
 * to a real term, this returns [] and the section disappears. A missing
 * category must not fall back to "latest posts", which would splash unrelated
 * content across a coin page.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class News
{
    public function __construct(private array $config) {}

    /** @return array<int, array{id:int,title:string,url:string,publishedAt:string,image:?array,category:?string}> */
    public function forCoin(Coin $coin): array
    {
        $slug = $coin->newsCategorySlug;
        if ($slug === '') {
            return [];
        }

        // The category must actually exist. Without this guard WP_Query silently
        // ignores an unknown category_name and returns the newest posts site-wide.
        $term = get_term_by('slug', $slug, 'category');
        if (!$term || is_wp_error($term)) {
            return [];
        }

        $limit = (int) ($this->config['news_limit'] ?? 4);
        $cacheKey = 'thb_news_' . md5($slug . '|' . $limit);

        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $query = new \WP_Query([
            'cat'                    => $term->term_id, // id, so no slug ambiguity remains
            'posts_per_page'         => $limit,
            'orderby'                => 'date',
            'order'                  => 'DESC',
            'post_status'            => 'publish',
            'ignore_sticky_posts'    => true,
            'no_found_rows'          => true,
            'update_post_term_cache' => true,
        ]);

        $articles = [];
        foreach ($query->posts as $post) {
            $article = $this->normalize($post, $slug);
            if ($article !== null) {
                $articles[] = $article;
            }
        }

        set_transient($cacheKey, $articles, (int) $this->config['ttl']['news']);
        return $articles;
    }

    /**
     * One WP_Post -> one article, or null if it is unusable.
     *
     * A post missing a title or permalink costs one row, never the section.
     */
    private function normalize(\WP_Post $post, string $coinCategorySlug): ?array
    {
        // get_the_title() returns RENDERED HTML: entities and inline tags. In
        // Persian &zwnj; is everywhere and renders literally if left alone.
        $title = trim(wp_strip_all_tags(
            html_entity_decode(get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        ));
        $url = get_permalink($post);

        if ($title === '' || !$url) {
            return null;
        }

        return [
            'id'          => $post->ID,
            'title'       => $title,
            'url'         => $url,
            // ISO-8601 UTC, so a Tehran reader never sees a date shifted by hours.
            'publishedAt' => (string) get_post_time('c', true, $post),
            'image'       => $this->thumbnail($post),
            'category'    => $this->topicalCategory($post, $coinCategorySlug),
        ];
    }

    /** Smallest sensible size — the slot is 56px, not a hero. */
    private function thumbnail(\WP_Post $post): ?array
    {
        $id = get_post_thumbnail_id($post);
        if (!$id) {
            return null;
        }
        $src = wp_get_attachment_image_src($id, 'thumbnail');
        if (!$src) {
            return null;
        }
        $alt = get_post_meta($id, '_wp_attachment_image_alt', true);
        return ['src' => $src[0], 'alt' => $alt !== '' ? $alt : null];
    }

    /**
     * The chip shows the article's TOPICAL category, not the coin's own.
     *
     * Every post in this query belongs to the coin category by definition, so
     * showing it would label all four articles "اخبار اتریوم" — true and
     * useless. A post filed only under the coin category gets no chip.
     */
    private function topicalCategory(\WP_Post $post, string $excludeSlug): ?string
    {
        foreach ((array) get_the_category($post->ID) as $term) {
            if ($term->slug !== $excludeSlug) {
                return html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return null;
    }

    /**
     * Cache invalidation.
     *
     * Hooked to save_post/deleted_post: publishing or editing an article in a
     * coin category drops that coin's news cache immediately, so the section
     * does not wait out its TTL to show a new post.
     */
    public function invalidateFor(int $postId): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }
        $limit = (int) ($this->config['news_limit'] ?? 4);
        foreach ((array) get_the_category($postId) as $term) {
            delete_transient('thb_news_' . md5($term->slug . '|' . $limit));
        }
    }
}
