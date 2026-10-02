<?php
/**
 * Manual overrides, applied at presentation time only.
 *
 * The rule this file enforces: an editor's correction changes what is DISPLAYED
 * and never what was RECORDED. The provider's value stays in the cache and in
 * the history table exactly as it arrived, so:
 *
 *   - historical series stay continuous and comparable;
 *   - a wrong override can be removed and the true value reappears;
 *   - we can later measure how often, and by how much, we disagreed with a
 *     provider — which is itself a data-quality signal.
 *
 * Every overridden value is tagged so the template and the admin can show which
 * numbers are ours rather than the provider's.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class Overrides
{
    /**
     * Apply a coin's overrides to a normalized view model.
     *
     * @param array $model  normalized view model (provider values)
     * @param Coin  $coin
     * @return array  same shape, plus `_overridden` listing the paths replaced
     */
    public static function apply(array $model, Coin $coin): array
    {
        $applied = [];

        foreach (self::flatten($coin->overrides) as $path => $value) {
            $current = self::dig($model, $path);

            // Only override something that exists in the model, so a typo'd
            // field name cannot invent a value out of nowhere.
            if ($current === null && !self::pathExists($model, $path)) {
                continue;
            }

            self::place($model, $path, self::coerce($value, $current));
            $applied[$path] = [
                'provider' => $current,   // what the API said, preserved for display
                'manual'   => self::dig($model, $path),
            ];
        }

        $model['_overridden'] = $applied;
        return $model;
    }

    /** Was this path manually set? Lets the UI mark it. */
    public static function isOverridden(array $model, string $path): bool
    {
        return isset($model['_overridden'][$path]);
    }

    /** Keep the override's type consistent with the value it replaces. */
    private static function coerce(mixed $value, mixed $current): mixed
    {
        if (is_float($current) || is_int($current)) {
            return is_numeric($value) ? (float) $value : $current;
        }
        return $value;
    }

    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value) && $value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
                $out += self::flatten($value, $path);
            } else {
                $out[$path] = $value;
            }
        }
        return $out;
    }

    private static function dig(array $data, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }
        return $data;
    }

    private static function pathExists(array $data, string $path): bool
    {
        foreach (explode('.', $path) as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return false;
            }
            $data = $data[$key];
        }
        return true;
    }

    private static function place(array &$data, string $path, mixed $value): void
    {
        $parts = explode('.', $path);
        $node = &$data;
        foreach ($parts as $part) {
            if (!isset($node[$part]) || !is_array($node[$part])) {
                $node[$part] = [];
            }
            $node = &$node[$part];
        }
        $node = $value;
    }
}
