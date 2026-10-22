<?php
/**
 * The price converter (2.10.0) — on every coin page and at /coins/convert/.
 *
 * EVERY RATE IS ONE WE ALREADY HOLD. Nothing here asks a provider:
 *   - coin → USD: the site-wide live-price entry (one cache read for every
 *     coin, refreshed once a minute by the scheduler), or the coin's own
 *     five-minute market entry before the first refresh;
 *   - USD → toman, EUR, GBP (2.13.0): site-wide entries the scheduler fills
 *     (see toman() and fiat()). A currency with no rate is not offered at
 *     all — never an assumed one;
 *   - in the browser, the coin's live USD ticks where the 2.4.0 feed carries
 *     them, through live.js's `thb:live` event.
 *
 * Every conversion happens in the browser — no request per keystroke.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\V2;

use TheHybit\Coins\Cache;
use TheHybit\Coins\Coin;
use TheHybit\Coins\CoinRepository;

defined('ABSPATH') || exit;

final class Converter
{
    public function __construct(private array $config, private Cache $cache, private CoinRepository $coins, private ?\TheHybit\Coins\Listing $listing = null) {}

    /**
     * Every coin we can price, from the one ticker entry: id => row.
     *
     * @return array<string, array{id:string, slug:string, symbol:string, name:string, price:float, at:int, url:string}>
     */
    public function prices(): array
    {
        $ticker = $this->cache->peek('ticker', $this->cache->keyFor('ticker'));
        $rows = is_array($ticker['data']['prices'] ?? null) ? $ticker['data']['prices'] : [];
        $out = [];
        $logos = $this->listing?->logos() ?? [];
        foreach ($this->coins->all() as $coin) {
            $row = $rows[$coin->coingeckoId] ?? null;
            $price = is_array($row) && isset($row['price']) && $row['price'] > 0 ? (float) $row['price'] : null;
            $at = (int) ($row['fetchedAt'] ?? $ticker['fetchedAt'] ?? 0);
            if ($price === null) {
                $m = $this->cache->peek('market', $this->cache->keyFor('market', $coin));
                if (isset($m['data']['price']) && $m['data']['price'] > 0) {
                    $price = (float) $m['data']['price'];
                    $at = (int) ($m['fetchedAt'] ?? 0);
                }
            }
            if ($price === null) {
                continue;   // no price we hold: the coin is simply not offered
            }
            $out[$coin->coingeckoId] = [
                'id' => $coin->coingeckoId, 'slug' => $coin->slug, 'symbol' => strtoupper($coin->symbol),
                'name' => $coin->name, 'price' => $price, 'at' => $at,
                'url' => (string) get_permalink($coin->postId),
                'logo' => $logos[$coin->coingeckoId] ?? null,
            ];
        }
        return $out;
    }

    /** Fiat units the converter can offer, in display order. */
    public const FIAT = ['usd', 'toman', 'eur', 'gbp'];

    /**
     * Toman per ONE dollar, or null when we have no real rate.
     *
     * First choice (2.13.0): Nobitex's USDT/toman market divided by Tether's
     * own cached USD price — two real prices, no 1 USDT = 1 USD assumption.
     * Fallback: the PersianToolbox USD rate the hero already used.
     *
     * @param array|null $prices prices() when the caller already has it
     */
    public function toman(?array $prices = null): ?array
    {
        $irt = $this->cache->peek('irt', $this->cache->keyFor('irt'));
        $usdt = $irt['data']['usdtToToman'] ?? null;
        if (is_numeric($usdt) && (float) $usdt > 0) {
            $prices ??= $this->prices();
            $tether = (float) ($prices['tether']['price'] ?? 0);
            if ($tether > 0.5 && $tether < 1.5) {
                return ['rate' => (float) $usdt / $tether, 'at' => (int) ($irt['fetchedAt'] ?? 0)];
            }
        }
        $fx = $this->cache->peek('fx', $this->cache->keyFor('fx'));
        $rate = $fx['data']['usdToToman'] ?? null;
        if (!is_numeric($rate) || (float) $rate <= 0) {
            return null;
        }
        return ['rate' => (float) $rate, 'at' => (int) ($fx['fetchedAt'] ?? 0)];
    }

    /** Units of EUR and GBP per dollar that we hold — ECB first, the fallback second; absent codes are simply absent. */
    public function fiat(): array
    {
        $out = [];
        foreach (['fiat', 'fiatalt'] as $dataset) {
            $entry = $this->cache->peek($dataset, $this->cache->keyFor($dataset));
            foreach ((array) ($entry['data']['rates'] ?? []) as $code => $v) {
                $k = strtolower((string) $code);
                if (!isset($out[$k]) && in_array($k, self::FIAT, true) && is_numeric($v) && (float) $v > 0) {
                    $out[$k] = (float) $v;
                }
            }
        }
        return $out;
    }

    /**
     * Everything the block draws for one coin (null for the standalone page's
     * neutral start). Null when this coin has no price we hold.
     */
    public function forCoin(?Coin $coin): ?array
    {
        $prices = $this->prices();
        if ($prices === []) {
            return null;
        }
        $base = $coin !== null ? ($prices[$coin->coingeckoId] ?? null) : (reset($prices) ?: null);
        if ($base === null) {
            return null;
        }
        $toman = $this->toman($prices);
        /* Units of each fiat currency per dollar — only the ones with a real
           rate. A currency missing here is not offered anywhere. */
        $fiat = ['usd' => 1.0] + ($toman ? ['toman' => $toman['rate']] : []) + $this->fiat();
        $fiat = array_intersect_key(array_merge(array_flip(self::FIAT), $fiat), $fiat);

        /* Worked amounts, from the same cached price: a small data-only table. */
        $amounts = $base['price'] >= 1000 ? [0.01, 0.1, 1, 5] : ($base['price'] >= 1 ? [1, 10, 100, 1000] : [100, 1000, 10000, 100000]);
        $examples = array_map(static fn($a) => [
            'amount' => self::fa((float) $a, 'coin'),
            'usd' => self::fa($a * $base['price'], 'usd'),
            'toman' => $toman ? self::fa($a * $base['price'] * $toman['rate'], 'toman') : null,
        ], $amounts);

        return [
            'base'     => $base,
            'coins'    => array_values($prices),
            'fiat'     => $fiat,
            'toman'    => $toman,
            'examples' => $examples,
        ];
    }

    /** 2.12.0: what alerts.js needs about this page's coin. */
    public static function alertClient(array $c): array
    {
        return ['coin' => $c['base']['id'], 'name' => $c['base']['name'], 'symbol' => $c['base']['symbol'], 'price' => $c['base']['price']];
    }

    /** The data island the script converts from. */
    public static function client(array $c): array
    {
        return [
            'base'  => $c['base']['id'],
            'coins' => array_map(static fn($p) => ['i' => $p['id'], 's' => $p['symbol'], 'n' => $p['name'], 'p' => $p['price'], 'l' => $p['logo'] ?? null], $c['coins']),
            'fiat'  => $c['fiat'],
        ];
    }

    /* ------------------------------------------------------------------
     * Arithmetic and formatting — the PHP twins of converter.js, so the
     * page without script (and the first paint) shows what the script would.
     * ---------------------------------------------------------------- */

    /** Dollars per one unit: a coin id or a fiat code. Null if we hold no rate for it. */
    public static function usdPer(array $c, string $unit): ?float
    {
        if (in_array($unit, self::FIAT, true)) {
            $r = $c['fiat'][$unit] ?? null;
            return $r !== null && $r > 0 ? 1 / (float) $r : null;
        }
        foreach ($c['coins'] as $p) {
            if ($p['id'] === $unit) {
                return (float) $p['price'];
            }
        }
        return null;
    }

    public static function convert(array $c, float $amount, string $from, string $to): ?float
    {
        $a = self::usdPer($c, $from);
        $b = self::usdPer($c, $to);
        return $a === null || $b === null || $b <= 0 ? null : $amount * $a / $b;
    }

    /** "۱٫۵", "1,500", "۱۲۰۰" → float; null for anything that is not a non-negative number. */
    public static function parse(string $s): ?float
    {
        $s = strtr(trim($s), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٫' => '.', '٬' => '', ',' => '', '،' => '', ' ' => '', "\u{200C}" => '',
        ]);
        return preg_match('/^\d+(\.\d+)?$|^\.\d+$/', $s) ? (float) $s : null;
    }

    /** Decimals by unit and size: cents for dollars, whole tomans, enough for a coin to matter. */
    public static function decimals(string $unit, float $v): int
    {
        $v = abs($v);
        return match (true) {
            $unit === 'usd' || $unit === 'eur' || $unit === 'gbp' => $v >= 1 ? 2 : ($v >= 0.01 ? 4 : 8),
            $unit === 'toman' => $v >= 10 ? 0 : 2,
            default => $v >= 1000 ? 2 : ($v >= 1 ? 4 : ($v >= 0.0001 ? 6 : 8)),
        };
    }

    /** Persian digits, «٬» thousands and «٫» decimal — the converter's own figures. */
    public static function fa(float $v, string $unit): string
    {
        $d = self::decimals($unit, $v);
        $s = number_format($v, $d, '.', ',');
        $cents = in_array($unit, ['usd', 'eur', 'gbp'], true);
        if ($d > 0 && !$cents) {
            $s = rtrim(rtrim($s, '0'), '.');
        } elseif ($cents && $d > 2) {
            $s = rtrim(rtrim($s, '0'), '.');
        }
        return strtr($s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹', ',' => '٬', '.' => '٫']);
    }

    /** Short names, as the result sentence uses them. */
    public const FIAT_NAMES = ['usd' => 'دلار', 'toman' => 'تومان', 'eur' => 'یورو', 'gbp' => 'پوند'];

    /** The dropdown's labels and symbols. */
    public const FIAT_LABELS = [
        'toman' => ['تومان', 'IRT', 'ت'],
        'usd'   => ['دلار آمریکا', 'USD', '$'],
        'eur'   => ['یورو', 'EUR', '€'],
        'gbp'   => ['پوند انگلیس', 'GBP', '£'],
    ];

    public static function unitName(array $c, string $unit): string
    {
        if (isset(self::FIAT_NAMES[$unit])) {
            return self::FIAT_NAMES[$unit];
        }
        foreach ($c['coins'] as $p) {
            if ($p['id'] === $unit) {
                return $p['name'];
            }
        }
        return $unit;
    }

    /** The sentence the result box shows, e.g. «۱ اتریوم = ۳٬۲۴۵٫۶۷ دلار». */
    public static function sentence(array $c, float $amount, string $from, string $to): ?string
    {
        $r = self::convert($c, $amount, $from, $to);
        return $r === null ? null
            : self::fa($amount, 'coin') . ' ' . self::unitName($c, $from) . ' = ' . self::fa($r, $to) . ' ' . self::unitName($c, $to);
    }
}
