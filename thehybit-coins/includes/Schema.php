<?php
/**
 * JSON-LD.
 *
 * Deliberately conservative: only types schema.org actually defines and Google
 * actually consumes.
 *
 *   WebPage         the page itself
 *   BreadcrumbList  خانه / ارزها / {coin}
 *   Dataset         our analytics + market data, which IS a dataset
 *   Article         the related-article list items (as ItemList of Articles)
 *
 * NOT emitted: invented financial types. There is no schema.org
 * "Cryptocurrency" or "FinancialQuote" that search engines honour for this, and
 * marking prices up as Product/Offer would be misrepresenting an asset page as
 * a commerce listing. Better to emit less and have it be valid.
 *
 * Yoast owns its own graph; this adds a separate script so nothing conflicts.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class Schema
{
    public function __construct(private CoinRepository $coins, private Pipeline $pipeline) {}

    public function register(): void
    {
        add_action('wp_head', [$this, 'output'], 20);
    }

    public function output(): void
    {
        if (!is_singular(CoinRepository::POST_TYPE)) {
            return;
        }
        $coin = $this->coins->find(get_the_ID());
        if (!$coin) {
            return;
        }

        $model = $this->pipeline->viewModel($coin);
        $url = get_permalink($coin->postId);

        $graph = [
            $this->webPage($coin, $model, $url),
            $this->breadcrumbs($coin, $url),
        ];

        $dataset = $this->dataset($coin, $model, $url);
        if ($dataset) {
            $graph[] = $dataset;
        }

        $articles = $this->articles($model);
        if ($articles) {
            $graph[] = $articles;
        }

        echo "\n<script type=\"application/ld+json\">"
            . wp_json_encode(
                ['@context' => 'https://schema.org', '@graph' => $graph],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
            . "</script>\n";
    }

    private function webPage(Coin $coin, array $model, string $url): array
    {
        return [
            '@type'       => 'WebPage',
            '@id'         => $url . '#webpage',
            'url'         => $url,
            'name'        => sprintf('قیمت %s (%s)', $coin->name, $coin->symbol),
            'inLanguage'  => 'fa-IR',
            'description' => wp_trim_words((string) ($model['about']['description'] ?? ''), 30, '…'),
            'dateModified'=> $model['meta']['generatedAt'] ?? gmdate('c'),
            'isPartOf'    => ['@id' => home_url('/') . '#website'],
        ];
    }

    private function breadcrumbs(Coin $coin, string $url): array
    {
        $items = [
            ['name' => 'خانه', 'item' => home_url('/')],
            ['name' => 'ارزها', 'item' => get_post_type_archive_link(CoinRepository::POST_TYPE) ?: home_url('/coins/')],
            ['name' => $coin->name, 'item' => $url],
        ];

        return [
            '@type' => 'BreadcrumbList',
            '@id'   => $url . '#breadcrumb',
            'itemListElement' => array_map(
                static fn($i, $item) => [
                    '@type'    => 'ListItem',
                    'position' => $i + 1,
                    'name'     => $item['name'],
                    'item'     => $item['item'],
                ],
                array_keys($items),
                $items
            ),
        ];
    }

    /**
     * Dataset is the honest type for what this page publishes: measurements
     * gathered from named sources on a stated schedule, plus metrics we derive.
     */
    private function dataset(Coin $coin, array $model, string $url): ?array
    {
        if (($model['market']['price'] ?? null) === null) {
            return null;
        }

        $providers = [];
        foreach (['defi', 'onchain', 'dex', 'l2'] as $key) {
            if (!empty($model[$key]['source'])) {
                $providers[] = $model[$key]['source'];
            }
        }
        $providers[] = 'CoinGecko';

        return [
            '@type'       => 'Dataset',
            '@id'         => $url . '#dataset',
            'name'        => sprintf('داده‌های بازار و تحلیل %s', $coin->name),
            'description' => sprintf(
                'قیمت، ارزش بازار، حجم معاملات و شاخص‌های تحلیلی %s، گردآوری‌شده از %s.',
                $coin->name,
                implode('، ', array_unique($providers))
            ),
            'license'     => home_url('/terms/'),
            'creator'     => ['@type' => 'Organization', 'name' => Seo::BRAND_SUFFIX, 'url' => home_url('/')],
            'dateModified'=> $model['meta']['generatedAt'] ?? gmdate('c'),
            'isAccessibleForFree' => true,
            'variableMeasured' => array_values(array_filter([
                self::variable('قیمت', $model['market']['price'] ?? null, 'USD'),
                self::variable('ارزش بازار', $model['market']['marketCap'] ?? null, 'USD'),
                self::variable('حجم معاملات ۲۴ ساعته', $model['market']['volume24h'] ?? null, 'USD'),
                self::variable('ارزش کل قفل‌شده', $model['defi']['tvl'] ?? null, 'USD'),
            ])),
        ];
    }

    private static function variable(string $name, ?float $value, string $unit): ?array
    {
        if ($value === null) {
            return null;
        }
        return [
            '@type' => 'PropertyValue',
            'name'  => $name,
            'value' => $value,
            'unitText' => $unit,
        ];
    }

    /** The related-articles list. Each item is a real Article on this site. */
    private function articles(array $model): ?array
    {
        $news = $model['news'] ?? [];
        if (!is_array($news) || $news === []) {
            return null;
        }

        return [
            '@type' => 'ItemList',
            'name'  => 'مطالب مرتبط',
            'itemListElement' => array_map(
                static fn($i, $a) => [
                    '@type'    => 'ListItem',
                    'position' => $i + 1,
                    'item'     => [
                        '@type'         => 'Article',
                        'headline'      => $a['title'],
                        'url'           => $a['url'],
                        'datePublished' => $a['publishedAt'],
                    ],
                ],
                array_keys($news),
                $news
            ),
        ];
    }
}
