<?php
/**
 * The coin configuration model.
 *
 * FOUR IDENTIFIERS, DELIBERATELY SEPARATE. Conflating any pair of these is the
 * mistake this class exists to prevent:
 *
 *   $slug             WordPress/route identity      "ethereum"
 *   $symbol           ticker                        "ETH"
 *   $coingeckoId      market-data provider id       "ethereum"
 *   $newsCategorySlug WP news category              "ethereum-news"
 *
 * They coincide for Ethereum, which is exactly why they must not be derived
 * from one another: the first coin whose CoinGecko id differs from its slug
 * (and there are many — "polygon-ecosystem-token", "binancecoin") would break
 * silently, and a news category that has not been created yet would empty the
 * section with no indication of why.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class Coin
{
    public function __construct(
        public readonly int $postId,
        public readonly string $slug,
        public readonly string $symbol,
        public readonly string $name,          // Persian
        public readonly string $nameEn,
        public readonly string $coingeckoId,
        public readonly string $newsCategorySlug,
        public readonly array $meta = [],      // description, founder, launch date, …
        public readonly array $overrides = [], // manual values, see Overrides.php
        public readonly array $flags = []      // per-coin provider toggles
    ) {}

    /** Public archive URL for this coin's news category. */
    public function newsCategoryUrl(): ?string
    {
        if ($this->newsCategorySlug === '') {
            return null;
        }
        // Built from the site's own category base so a permalink change follows.
        $term = get_term_by('slug', $this->newsCategorySlug, 'category');
        if ($term && !is_wp_error($term)) {
            $link = get_term_link($term);
            if (!is_wp_error($link)) {
                return $link;
            }
        }
        return home_url('/category/news/' . $this->newsCategorySlug . '/');
    }

    /**
     * Is a provider active for this coin?
     *
     * Global config first, then a per-coin opt-out. Bitcoin has no meaningful
     * DeFi TVL, so it switches DefiLlama off without disabling it site-wide.
     */
    public function usesProvider(string $provider, array $config): bool
    {
        if (empty($config['providers'][$provider]['enabled'])) {
            return false;
        }
        return $this->flags[$provider] ?? true;
    }

    public function meta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    /** Cache-key fragment. Includes the provider id so re-pointing it busts caches. */
    public function cacheKey(string $dataset): string
    {
        return sprintf('%s:%s:%s', $dataset, $this->slug, $this->coingeckoId);
    }
}
