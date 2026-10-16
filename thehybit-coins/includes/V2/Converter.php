<?php
/**
 * The price converter (2.10.0) — on every coin page and at /coins/convert/.
 *
 * EVERY RATE IS ONE WE ALREADY HOLD. Nothing here asks a provider:
 *   - coin → USD: the site-wide live-price entry (one cache read for every
 *     coin, refreshed once a minute by the scheduler), or the coin's own
 *     five-minute market entry before the first refresh;
 *   - USD → toman: the cached USD rate (persiantoolbox, every 15 minutes).
 *     Offered only when that entry exists — with no rate there is no toman
 *     option at all, never an assumed one;
 *   - in the browser, the coin's live ticks (Kraken in USD, Nobitex in toman)
 *     where the 2.4.0 feed already carries them, through live.js's
 *     `thb:live` event. Nothing else is live, and the page says which is.
 *
 * Each rate carries the time it was fetched; the block prints it. Every
 * conversion happens in the browser — no request per keystroke.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\V2;

use TheHybit\Coins\Cache;
use TheHybit\Coins\Categories;
use TheHybit\Coins\Coin;
use TheHybit\Coins\CoinRepository;
use TheHybit\Coins\Format;
use TheHybit\Coins\LiveFeed;

defined('ABSPATH') || exit;

final class Converter
{
    public function __construct(private array $config, private Cache $cache, private CoinRepository $coins) {}

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
            ];
        }
        return $out;
    }

    /** The cached USD → toman rate, or null when we have none. */
    public function toman(): ?array
    {
        $fx = $this->cache->peek('fx', $this->cache->keyFor('fx'));
        $rate = $fx['data']['usdToToman'] ?? null;
        if (!is_numeric($rate) || (float) $rate <= 0) {
            return null;
        }
        return ['rate' => (float) $rate, 'at' => (int) ($fx['fetchedAt'] ?? 0), 'source' => (string) ($fx['data']['source'] ?? 'PersianToolbox')];
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
        $toman = $this->toman();
        $live = LiveFeed::client($this->config, [$base['id']]);
        $liveMap = $live['coins'][$base['id']] ?? [];

        /* Worked amounts, from the same cached price — the SEO text's real
           numbers, and a table a reader can check at a glance. */
        $amounts = $base['price'] >= 1000 ? [0.01, 0.1, 1, 5] : ($base['price'] >= 1 ? [1, 10, 100, 1000] : [100, 1000, 10000, 100000]);
        $examples = array_map(static fn($a) => [
            'amount' => self::fa((float) $a, 'coin'),
            'usd' => self::fa($a * $base['price'], 'usd'),
            'toman' => $toman ? self::fa($a * $base['price'] * $toman['rate'], 'toman') : null,
        ], $amounts);

        return [
            'base'     => $base,
            'coins'    => array_values($prices),
            'toman'    => $toman,
            'live'     => ['usd' => !empty($liveMap['usd']), 'toman' => !empty($liveMap['irt'])],
            'examples' => $examples,
            'text'     => $coin !== null ? self::text($coin, $base, $toman, !empty($liveMap['usd']), !empty($liveMap['irt'])) : null,
            'updated'  => Format::tehranTime(gmdate('c', $base['at'])),
            'tomanUpdated' => $toman ? Format::tehranTime(gmdate('c', $toman['at'])) : null,
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
            'coins' => array_map(static fn($p) => ['i' => $p['id'], 's' => $p['symbol'], 'n' => $p['name'], 'p' => $p['price']], $c['coins']),
            'toman' => $c['toman']['rate'] ?? null,
        ];
    }

    /**
     * A short explanation written from this coin's own facts — its name,
     * category, price, the rates behind each unit and which of them are live
     * — so no two coins' converter text is the same, and none is filler.
     */
    private static function text(Coin $coin, array $base, ?array $toman, bool $liveUsd, bool $liveToman): string
    {
        $cat = Categories::of($coin);
        $sym = strtoupper($coin->symbol);
        $parts = [];
        $parts[] = sprintf('در آخرین به‌روزرسانی هر ۱ %s (%s)%s معادل %s دلار بود%s.',
            $coin->name, $sym, $cat ? '، ' . self::catPhrase($cat['key']) . '،' : '', self::fa($base['price'], 'usd'),
            $toman ? ' و با نرخ دلار همان زمان حدود ' . self::fa($base['price'] * $toman['rate'], 'toman') . ' تومان' : '');
        $parts[] = $liveUsd
            ? sprintf('وقتی این صفحه باز است، قیمت دلاری %s با هر معامله در صرافی به‌روز می‌شود و مبدل همان عدد را به کار می‌برد.', $sym)
            : sprintf('قیمت %s هر دقیقه از داده ذخیره‌شده به‌روز می‌شود؛ مبدل با همان عدد حساب می‌کند و زمان آن را کنار نتیجه نشان می‌دهد.', $sym);
        if ($toman) {
            $parts[] = $liveToman
                ? sprintf('مبلغ تومانی، وقتی صفحه باز است، از قیمت زنده %s در بازار تومانی نوبیتکس می‌آید و تا رسیدن آن، از نرخ دلار %s.', $sym, $toman['source'])
                : sprintf('مبلغ تومانی حاصل ضرب قیمت دلاری در نرخ دلار %s است؛ قیمت واقعی خرید و فروش %s در صرافی‌های ایرانی ممکن است با آن فرق داشته باشد.', $toman['source'], $sym);
        }
        $parts[] = sprintf('برای تبدیل %s به ارز دیگر، قیمت هر دو به دلار در نظر گرفته می‌شود؛ کارمزد معامله و اختلاف قیمت خرید و فروش در این محاسبه نیست.', $sym);
        return implode(' ', $parts);
    }

    /* ------------------------------------------------------------------
     * Arithmetic and formatting — the PHP twins of converter.js, so the
     * page without script (and the first paint) shows what the script would.
     * ---------------------------------------------------------------- */

    /** Dollars per one unit: a coin id, 'usd' or 'toman'. Null if unknown here. */
    public static function usdPer(array $c, string $unit): ?float
    {
        if ($unit === 'usd') {
            return 1.0;
        }
        if ($unit === 'toman') {
            return isset($c['toman']['rate']) ? 1 / (float) $c['toman']['rate'] : null;
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
            $unit === 'usd' => $v >= 1 ? 2 : ($v >= 0.01 ? 4 : 8),
            $unit === 'toman' => $v >= 10 ? 0 : 2,
            default => $v >= 1000 ? 2 : ($v >= 1 ? 4 : ($v >= 0.0001 ? 6 : 8)),
        };
    }

    /** Persian digits, «٬» thousands and «٫» decimal — the converter's own figures. */
    public static function fa(float $v, string $unit): string
    {
        $d = self::decimals($unit, $v);
        $s = number_format($v, $d, '.', ',');
        if ($d > 0 && $unit !== 'usd') {
            $s = rtrim(rtrim($s, '0'), '.');
        } elseif ($unit === 'usd' && $d > 2) {
            $s = rtrim(rtrim($s, '0'), '.');
        }
        return strtr($s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹', ',' => '٬', '.' => '٫']);
    }

    public static function unitName(array $c, string $unit): string
    {
        if ($unit === 'usd') {
            return 'دلار';
        }
        if ($unit === 'toman') {
            return 'تومان';
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

    private static function catPhrase(string $key): string
    {
        return match ($key) {
            'stablecoin' => 'که یک استیبل‌کوین است', 'gold' => 'که پشتوانه طلا دارد', 'meme' => 'که یک میم‌کوین است',
            'layer1' => 'رمزارز یک بلاکچین لایه ۱', 'layer2' => 'توکن یک شبکه لایه ۲', 'exchange' => 'توکن یک صرافی',
            'defi' => 'توکن یک پروتکل دیفای', 'oracle' => 'توکن یک شبکه اوراکل', 'privacy' => 'یک ارز حریم خصوصی',
            'payments' => 'رمزارز یک شبکه پرداخت', 'ai' => 'توکنی از حوزه هوش مصنوعی', 'infra' => 'توکن یک زیرساخت بلاکچینی',
            'rwa' => 'توکن یک دارایی واقعی', default => 'یک رمزارز',
        };
    }
}
