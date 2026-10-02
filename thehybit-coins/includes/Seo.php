<?php
/**
 * Yoast integration.
 *
 * THE RULE: generate sensible defaults, never override a manual value.
 *
 * Yoast stores a manual title/description in post meta (_yoast_wpseo_title,
 * _yoast_wpseo_metadesc). Every filter below checks for one first and returns
 * the existing value untouched if present. An editor who writes their own title
 * keeps it; everyone else gets a decent generated one.
 *
 * Generated titles end with "| های بیت" as specified.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class Seo
{
    public const BRAND_SUFFIX = 'های بیت';

    public function __construct(private CoinRepository $coins, private Pipeline $pipeline) {}

    public function register(): void
    {
        add_filter('wpseo_title', [$this, 'title'], 10, 1);
        add_filter('wpseo_metadesc', [$this, 'description'], 10, 1);
        add_filter('wpseo_opengraph_title', [$this, 'title'], 10, 1);
        add_filter('wpseo_opengraph_desc', [$this, 'description'], 10, 1);
        add_filter('wpseo_canonical', [$this, 'canonical'], 10, 1);
    }

    private function currentCoin(): ?Coin
    {
        if (!is_singular(CoinRepository::POST_TYPE)) {
            return null;
        }
        return $this->coins->find(get_the_ID());
    }

    public function title(string $title): string
    {
        $coin = $this->currentCoin();
        if (!$coin) {
            return $title;
        }

        // Manual Yoast value wins, always.
        $manual = get_post_meta($coin->postId, '_yoast_wpseo_title', true);
        if (is_string($manual) && trim($manual) !== '') {
            return $title;
        }

        return sprintf(
            'قیمت %s (%s) — نمودار، تحلیل و اخبار | %s',
            $coin->name,
            $coin->symbol,
            self::BRAND_SUFFIX
        );
    }

    public function description(string $desc): string
    {
        $coin = $this->currentCoin();
        if (!$coin) {
            return $desc;
        }

        $manual = get_post_meta($coin->postId, '_yoast_wpseo_metadesc', true);
        if (is_string($manual) && trim($manual) !== '') {
            return $desc;
        }

        // Built from live values so the description reflects the actual page.
        $model = $this->pipeline->viewModel($coin);
        $price = $model['market']['price'] ?? null;

        if ($price !== null) {
            return sprintf(
                'قیمت لحظه‌ای %s (%s)، نمودار قیمت، ارزش بازار، عملکرد و تحلیل‌های اختصاصی %s.',
                $coin->name,
                $coin->symbol,
                self::BRAND_SUFFIX
            );
        }

        return sprintf(
            'اطلاعات، نمودار و تحلیل %s (%s) در %s.',
            $coin->name,
            $coin->symbol,
            self::BRAND_SUFFIX
        );
    }

    public function canonical(string $url): string
    {
        $coin = $this->currentCoin();
        return $coin ? get_permalink($coin->postId) : $url;
    }
}
