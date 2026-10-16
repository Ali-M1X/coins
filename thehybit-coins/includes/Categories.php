<?php
/**
 * Asset categories (2.8.0) — what kind of asset a coin is.
 *
 * The choices and the shipped defaults live in config/categories.php; the
 * coin's own value is the «دسته» field (thb_category). Only a known key is
 * shown: a value that is not one of the choices is treated as none rather
 * than printed raw.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class Categories
{
    private static ?array $config = null;

    private static function config(): array
    {
        return self::$config ??= (array) require THB_COINS_DIR . 'config/categories.php';
    }

    /** @return array<string, array{fa:string, en:string}> key => labels, in display order */
    public static function all(): array
    {
        return (array) (self::config()['labels'] ?? []);
    }

    /** @return array{key:string, fa:string, en:string}|null */
    public static function of(Coin $coin): ?array
    {
        $key = trim((string) $coin->meta('category', ''));
        $labels = self::all()[$key] ?? null;
        return $labels === null ? null : ['key' => $key] + $labels;
    }

    /** The shipped default for a slug, or null. */
    public static function default(string $slug): ?string
    {
        return self::config()['defaults'][$slug] ?? null;
    }
}
