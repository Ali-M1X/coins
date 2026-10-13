<?php
/**
 * Live price in the visitor's browser — the server's whole part of it.
 *
 * The server does not connect to anything here. It decides whether the
 * feature is on, and writes the symbol map for the coins on the page into the
 * page as JSON; assets/v2/live.js does the rest in the browser, straight
 * against the exchanges' public WebSockets. So a page render costs exactly what
 * it cost before — not one request — and nothing is relayed through us.
 *
 * Off switch: Settings → «قیمت زنده (WebSocket)». Off means no script and no
 * JSON at all: the page is the 2.3.0 page, polling included.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class LiveFeed
{
    public const SETTING = 'live_ws';
    public const HANDLE = 'thb-live';

    public static function enabled(array $config): bool
    {
        return (string) Settings::get(self::SETTING, 'on') !== 'off'
            && ($config['live_ws']['enabled'] ?? false) === true;
    }

    /** @return array<string, array> the feeds switched on in config */
    private static function feeds(array $config): array
    {
        return array_filter(
            (array) ($config['live_ws']['feeds'] ?? []),
            static fn($f) => is_array($f) && ($f['enabled'] ?? true) === true
        );
    }

    /**
     * Which feeds carry a coin, by CoinGecko id. Empty = the coin stays on
     * polling (or, on the list, on the server-rendered value).
     *
     * @return array{usd?:array<int, array{feed:string, sym:string, quote:string}>, irt?:array{feed:string, sym:string}}
     */
    public static function map(array $config, string $coingeckoId): array
    {
        $feeds = self::feeds($config);
        $out = [];
        /* The USD price: Kraken's USD pair first, Nobitex's USDT pair as the
           labelled fallback. Order here is the order live.js prefers them. */
        if (isset($feeds['kraken']['symbols'][$coingeckoId])) {
            $out['usd'][] = ['feed' => 'kraken', 'sym' => (string) $feeds['kraken']['symbols'][$coingeckoId], 'quote' => 'USD'];
        }
        if (isset($feeds['nobitex']['usdt'][$coingeckoId])) {
            $out['usd'][] = ['feed' => 'nobitex', 'sym' => (string) $feeds['nobitex']['usdt'][$coingeckoId], 'quote' => 'USDT'];
        }
        if (isset($feeds['nobitex']['irt'][$coingeckoId])) {
            $out['irt'] = ['feed' => 'nobitex', 'sym' => (string) $feeds['nobitex']['irt'][$coingeckoId]];
        }
        return $out;
    }

    /**
     * The JSON live.js reads: feeds in use and the coins on this page. Null
     * when the feature is off or no coin on the page is carried by any feed.
     *
     * @param string[] $coingeckoIds
     */
    public static function client(array $config, array $coingeckoIds): ?array
    {
        if (!self::enabled($config)) {
            return null;
        }
        $coins = [];
        foreach (array_unique(array_filter($coingeckoIds)) as $id) {
            $m = self::map($config, (string) $id);
            if ($m !== []) {
                $coins[$id] = $m;
            }
        }
        if ($coins === []) {
            return null;
        }
        $used = [];
        foreach ($coins as $m) {
            foreach ($m['usd'] ?? [] as $c) { $used[$c['feed']] = true; }
            if (isset($m['irt'])) { $used[$m['irt']['feed']] = true; }
        }
        $feeds = [];
        foreach (self::feeds($config) as $name => $f) {
            if (isset($used[$name])) {
                $feeds[$name] = [
                    'label' => (string) ($f['label'] ?? $name),
                    'url'   => (string) $f['url'],
                    'stale' => (int) ($f['stale'] ?? 30) * 1000,
                ];
            }
        }
        return [
            'feeds'  => $feeds,
            'coins'  => $coins,
            'paint'  => (int) ($config['live_ws']['paint_ms'] ?? 1000),
            'hidden' => (int) ($config['live_ws']['hidden_grace'] ?? 10) * 1000,
        ];
    }

    /** Enqueue live.js with its JSON, if there is anything to stream. */
    public static function enqueue(array $config, array $coingeckoIds): void
    {
        $client = self::client($config, $coingeckoIds);
        if ($client === null) {
            return;
        }
        wp_enqueue_script(self::HANDLE, THB_COINS_URL . 'assets/v2/live.js', [], THB_COINS_VERSION, true);
        wp_add_inline_script(
            self::HANDLE,
            'window.__THB_LIVE__ = ' . wp_json_encode($client, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';',
            'before'
        );
    }

    /** True when the list row for this coin is carried by a feed (for data-live-id). */
    public static function covers(array $config, string $coingeckoId): bool
    {
        return self::enabled($config) && self::map($config, $coingeckoId) !== [];
    }
}
