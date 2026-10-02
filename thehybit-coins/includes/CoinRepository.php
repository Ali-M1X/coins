<?php
/**
 * Reads coin configuration out of WordPress (ACF) into Coin objects.
 *
 * This is the ONLY place that knows ACF field names. Everything downstream
 * takes a Coin, so replacing ACF later touches one file. Nothing coin-specific
 * is hard-coded in a template — adding a coin is: create the CPT post, fill the
 * fields, create the news category.
 *
 * ACF Free is sufficient: all fields below are basic types (text, textarea,
 * date picker, url, true/false). No repeaters, no flexible content, no options
 * page — those are Pro. Field definitions live in acf-json/ and sync
 * automatically.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class CoinRepository
{
    public const POST_TYPE = 'thb_coin';

    /** ACF field key => Coin property/meta slot. The whole mapping, in one table. */
    private const FIELDS = [
        // identity — the four separate identifiers
        'thb_symbol'              => 'symbol',
        'thb_name_fa'             => 'name',
        'thb_name_en'             => 'nameEn',
        'thb_coingecko_id'        => 'coingeckoId',
        'thb_news_category_slug'  => 'newsCategorySlug',

        // metadata shown by the approved UI
        'thb_description'         => 'meta.description',
        'thb_founder'             => 'meta.founder',
        'thb_launch_date'         => 'meta.launchDate',
        'thb_consensus'           => 'meta.consensus',
        'thb_website'             => 'meta.website',
        'thb_whitepaper'          => 'meta.whitepaper',
        'thb_explorer'            => 'meta.explorer',
        'thb_contract_address'    => 'meta.contractAddress',
        'thb_contract_network'    => 'meta.contractNetwork',
        'thb_contract_network_fa' => 'meta.contractNetworkFa',
        'thb_tags'                => 'meta.tags',
        'thb_social_twitter'      => 'meta.social.twitter',
        'thb_social_github'       => 'meta.social.github',
        'thb_social_discord'      => 'meta.social.discord',
        'thb_social_telegram'     => 'meta.social.telegram',

        // provider-specific identifiers — the fifth, sixth and seventh names for
        // one coin. Every one of them is stored, never derived, for the same
        // reason the first four are; see Coin.php.
        'thb_defillama_chain'     => 'meta.defillamaChain',
        'thb_dex_token_address'   => 'meta.dexTokenAddress',
        'thb_dex_symbols'         => 'meta.dexSymbols',
        'thb_github_repo'         => 'meta.githubRepo',

        // v2 editorial content — written by an editor, never fetched. Each is
        // plain text with one entry per line; includes/V2/Model.php parses it.
        'thb_tagline'             => 'meta.tagline',
        'thb_timeline'            => 'meta.timeline',
        'thb_learn'               => 'meta.learn',
        'thb_audience'            => 'meta.audience',
        'thb_faq'                 => 'meta.faq',

        // per-coin provider toggles
        'thb_enable_defi'         => 'flags.defillama',
        'thb_enable_dex'          => 'flags.dexscreener',
        'thb_enable_onchain'      => 'flags.onchain',
        'thb_enable_l2'           => 'flags.l2beat',

        // manual overrides — see Overrides.php for how these are applied
        'thb_override_price'      => 'overrides.market.price',
        'thb_override_market_cap' => 'overrides.market.marketCap',
        'thb_override_tvl'        => 'overrides.defi.tvl',
        'thb_override_rank'       => 'overrides.market.rank',
    ];

    public function find(int $postId): ?Coin
    {
        if (get_post_type($postId) !== self::POST_TYPE) {
            return null;
        }

        $raw = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $raw[$field] = function_exists('get_field')
                ? get_field($field, $postId)
                : get_post_meta($postId, $field, true);
        }

        $meta = [];
        $overrides = [];
        $flags = [];
        $scalar = [];

        foreach (self::FIELDS as $field => $target) {
            $value = $raw[$field];
            // An empty override must not shadow a live API value.
            if ($value === '' || $value === null) {
                continue;
            }

            if (str_starts_with($target, 'meta.')) {
                self::assign($meta, substr($target, 5), $value);
            } elseif (str_starts_with($target, 'overrides.')) {
                self::assign($overrides, substr($target, 10), $value);
            } elseif (str_starts_with($target, 'flags.')) {
                $flags[substr($target, 6)] = (bool) $value;
            } else {
                $scalar[$target] = $value;
            }
        }

        $slug = get_post_field('post_name', $postId);

        // A coin without these cannot be fetched or linked, so refuse rather
        // than render a broken page.
        if (empty($scalar['coingeckoId']) || empty($scalar['symbol'])) {
            return null;
        }

        return new Coin(
            postId: $postId,
            slug: $slug,
            symbol: strtoupper((string) $scalar['symbol']),
            name: (string) ($scalar['name'] ?? get_the_title($postId)),
            nameEn: (string) ($scalar['nameEn'] ?? ''),
            coingeckoId: (string) $scalar['coingeckoId'],
            newsCategorySlug: (string) ($scalar['newsCategorySlug'] ?? ''),
            meta: $meta,
            overrides: $overrides,
            flags: $flags
        );
    }

    /** Every published coin. Used by the scheduler. */
    public function all(): array
    {
        $ids = get_posts([
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'numberposts'    => -1,
            'fields'         => 'ids',
            'orderby'        => 'menu_order title',
            'order'          => 'ASC',
        ]);

        return array_values(array_filter(array_map([$this, 'find'], $ids)));
    }

    public function findBySlug(string $slug): ?Coin
    {
        $post = get_page_by_path($slug, OBJECT, self::POST_TYPE);
        return $post ? $this->find($post->ID) : null;
    }

    /** Writes a dotted path into a nested array. */
    private static function assign(array &$target, string $path, mixed $value): void
    {
        $parts = explode('.', $path);
        $node = &$target;
        foreach ($parts as $part) {
            if (!isset($node[$part]) || !is_array($node[$part])) {
                $node[$part] = [];
            }
            $node = &$node[$part];
        }
        $node = $value;
    }

    /** Registers the coin post type. Public, so each coin gets a real URL. */
    public static function registerPostType(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name'          => 'ارزها',
                'singular_name' => 'ارز',
                'add_new_item'  => 'افزودن ارز',
            ],
            'public'       => true,
            'has_archive'  => 'coins',
            'rewrite'      => ['slug' => 'coins', 'with_front' => false],
            'menu_icon'    => 'dashicons-chart-line',
            'supports'     => ['title', 'thumbnail', 'custom-fields', 'revisions'],
            'show_in_rest' => true,
        ]);
    }
}
