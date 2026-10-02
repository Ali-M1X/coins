<?php
/**
 * Group A analytical relationships — market, ecosystem and structure context.
 *
 * WHY THIS IS NOT IN Derive.php
 *
 * Derive::metrics() is the input to Scoring. Adding anything to it risks moving
 * the score, and the score is frozen at 71/100 by explicit instruction. So these
 * live in a separate namespace on the model — `context`, not `derived` — and
 * Scoring never sees them. That separation is the guarantee, not a convention:
 * there is no path from this file to a weight.
 *
 * Derive also has a PHP/JS twin that tools/wp-parity.test.mjs holds byte-honest.
 * These metrics are server-rendered only and have no JS counterpart, so putting
 * them here keeps that parity contract exactly as it was.
 *
 * WHAT A METRIC OWES THE READER
 *
 * Every entry carries its formula, the model paths it consumed, and — when it
 * could not be computed — precisely which of those paths was missing. A metric
 * that cannot be computed is `available => false` with a null value. It is never
 * zero. A zero is a claim about the world, and "we do not have this yet" is not
 * a zero.
 *
 * Division guards are not defensive noise: a denominator of zero is exactly what
 * a not-yet-warmed site-wide dataset looks like, and INF rendered as a
 * percentage is how a page starts lying.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class Context
{
    /**
     * Build every context metric available for this model.
     *
     * @return array<string, array{label:string, value:?float, unit:string, available:bool,
     *                             formula:string, sources:array<int,string>, missing:array<int,string>}>
     */
    public static function build(array $model): array
    {
        $out = [];

        /* ---- market context: is the coin moving, or is the market? ---- */

        $out['marketCapShare'] = self::ratio(
            $model,
            'سهم از کل بازار رمزارز',
            'market.marketCap',
            'globalMarket.data.totalMarketCap',
            'ارزش بازار کوین ÷ ارزش کل بازار × ۱۰۰',
            ['CoinGecko /coins/{id}', 'CoinGecko /global']
        );

        $out['volumeShare'] = self::ratio(
            $model,
            'سهم از حجم کل بازار',
            'market.volume24h',
            'globalMarket.data.totalVolume24h',
            'حجم ۲۴ ساعته کوین ÷ حجم کل بازار × ۱۰۰',
            ['CoinGecko /coins/{id}', 'CoinGecko /global']
        );

        /* The question the Market Context section exists to answer: did this
           coin outperform the market, or merely move with it? A difference of
           percentages, so it is signed and expressed in percentage POINTS. */
        $out['vsMarket24h'] = self::difference(
            $model,
            'عملکرد نسبت به کل بازار (۲۴ ساعت)',
            'market.change24h',
            'globalMarket.data.marketCapChange24h',
            'تغییر ۲۴ ساعته کوین − تغییر ۲۴ ساعته کل بازار',
            ['CoinGecko /coins/{id}', 'CoinGecko /global']
        );

        /* ---- ecosystem: the chain's weight within DeFi as a whole ---- */

        $out['tvlShareOfDefi'] = self::ratio(
            $model,
            'سهم از کل TVL دیفای',
            'defi.tvl',
            'chains.data.totalTvl',
            'TVL زنجیره ÷ مجموع TVL همه زنجیره‌ها × ۱۰۰',
            ['DefiLlama /v2/historicalChainTvl', 'DefiLlama /v2/chains']
        );

        /* Stablecoin supply sitting on this chain, and what it means relative to
           the chain's own DeFi and to every chain's stablecoins. Both are real
           ratios of two measured quantities, not proxies for one another. */
        $stable = self::stablecoinSupply($model);

        $out['stablecoinSupply'] = [
            'label'     => 'عرضه استیبل‌کوین روی زنجیره',
            'value'     => $stable,
            'unit'      => 'usd',
            'available' => $stable !== null,
            'formula'   => 'مجموع عرضه در گردش همه استیبل‌کوین‌های این زنجیره',
            'sources'   => ['DefiLlama /stablecoinchains'],
            'missing'   => $stable === null ? ['stablecoins.data.byChain'] : [],
        ];

        $out['stablecoinToTvl'] = self::divide(
            $stable,
            self::dig($model, 'defi.tvl'),
            'نسبت استیبل‌کوین به TVL',
            'عرضه استیبل‌کوین ÷ TVL زنجیره × ۱۰۰',
            ['DefiLlama /stablecoinchains', 'DefiLlama /v2/historicalChainTvl'],
            ['stablecoins.data.byChain', 'defi.tvl']
        );

        $out['stablecoinDominance'] = self::divide(
            $stable,
            self::dig($model, 'stablecoins.data.totalUsd'),
            'سهم از کل استیبل‌کوین‌ها',
            'عرضه استیبل‌کوین این زنجیره ÷ عرضه کل همه زنجیره‌ها × ۱۰۰',
            ['DefiLlama /stablecoinchains'],
            ['stablecoins.data.byChain', 'stablecoins.data.totalUsd']
        );

        /* ---- market structure ---- */

        $concentration = self::dig($model, 'structure.data.concentration');
        $out['cexConcentration'] = [
            'label'     => 'تمرکز صرافی‌های متمرکز',
            'value'     => is_numeric($concentration) ? (float) $concentration : null,
            'unit'      => 'index',
            'available' => is_numeric($concentration),
            /* Herfindahl over venue volume shares. Reported only when at least
               three venues carried volume — a "concentration" computed from one
               venue is arithmetically 100 and means nothing. */
            'formula'   => 'مجموع مربع سهم حجمی هر صرافی × ۱۰۰ (حداقل سه صرافی)',
            'sources'   => ['CoinGecko /coins/{id}?tickers=true'],
            'missing'   => is_numeric($concentration) ? [] : ['structure.data.concentration'],
        ];

        /* DEX against the venues we can actually see. The denominator is the
           ticker sample, not "all CEX volume in existence", and the label says
           so — CoinGecko returns a page of tickers, not a census. */
        $out['dexVsCex'] = self::divide(
            self::dig($model, 'dex.volume24h'),
            self::dig($model, 'structure.data.volumeSeen'),
            'حجم غیرمتمرکز نسبت به صرافی‌های دیده‌شده',
            'حجم ۲۴ ساعته DEX ÷ حجم صرافی‌های متمرکز نمونه‌برداری‌شده × ۱۰۰',
            ['DexScreener', 'CoinGecko /coins/{id}?tickers=true'],
            ['dex.volume24h', 'structure.data.volumeSeen']
        );

        return $out;
    }

    /**
     * The coin's own categories, joined against the site-wide category list.
     *
     * The list is fetched once for the whole site; the coin's membership comes
     * free from its detail response. Matching happens here rather than in the
     * collector so that adding a coin never re-fetches the categories.
     *
     * NO RANK IS PRODUCED. The endpoint publishes none, and position within a
     * list we sorted ourselves is not a ranking — it is our sort order wearing a
     * ranking's clothes.
     *
     * @return array<int, array{name:string, marketCap:float, change24h:?float, volume24h:?float}>
     */
    public static function categories(array $model, int $limit = 3): array
    {
        $mine = (array) (self::dig($model, 'coin.categories') ?? []);
        $all  = (array) (self::dig($model, 'categories.data.categories') ?? []);

        if ($mine === [] || $all === []) {
            return [];
        }

        // Case- and space-insensitive, because "Smart Contract Platform" and
        // "smart contract platform" are the same sector.
        $index = [];
        foreach ($all as $row) {
            if (is_array($row) && isset($row['name'])) {
                $index[self::normalizeName((string) $row['name'])] = $row;
            }
        }

        $out = [];
        foreach ($mine as $name) {
            $key = self::normalizeName((string) $name);
            if (isset($index[$key])) {
                $out[] = $index[$key];
            }
        }

        usort($out, static fn(array $a, array $b): int => ($b['marketCap'] ?? 0) <=> ($a['marketCap'] ?? 0));

        return array_slice($out, 0, $limit);
    }

    /**
     * Where this chain sits in the DefiLlama chain table.
     *
     * Rank here IS real: /v2/chains publishes TVL for every chain, so ordering
     * them produces a genuine position rather than an invented one.
     *
     * @return array{rank:int, share:float, tvl:float}|null
     */
    public static function chainStanding(array $model, string $chain): ?array
    {
        $chains = (array) (self::dig($model, 'chains.data.chains') ?? []);
        if ($chains === [] || $chain === '') {
            return null;
        }

        foreach ($chains as $row) {
            if (!is_array($row) || !isset($row['name'])) {
                continue;
            }
            if (self::normalizeName((string) $row['name']) !== self::normalizeName($chain)) {
                continue;
            }
            return [
                'rank'  => (int) ($row['rank'] ?? 0),
                'share' => (float) ($row['share'] ?? 0.0),
                'tvl'   => (float) ($row['tvl'] ?? 0.0),
            ];
        }

        return null;
    }

    /* ------------------------------------------------------------------
     * Internals
     * ---------------------------------------------------------------- */

    /** Stablecoin supply on this coin's chain, matched by DefiLlama's chain name. */
    private static function stablecoinSupply(array $model): ?float
    {
        $byChain = (array) (self::dig($model, 'stablecoins.data.byChain') ?? []);
        $chain = (string) (self::dig($model, 'coin.chain') ?? '');

        if ($byChain === [] || $chain === '') {
            return null;
        }

        foreach ($byChain as $name => $value) {
            if (self::normalizeName((string) $name) === self::normalizeName($chain) && is_numeric($value)) {
                return (float) $value;
            }
        }
        return null;
    }

    /** A ÷ B as a percentage, read from model paths so `missing` can name them. */
    private static function ratio(
        array $model,
        string $label,
        string $numerator,
        string $denominator,
        string $formula,
        array $sources
    ): array {
        return self::divide(
            self::dig($model, $numerator),
            self::dig($model, $denominator),
            $label,
            $formula,
            $sources,
            [$numerator, $denominator]
        );
    }

    /** The percentage-point gap between two percentages. */
    private static function difference(
        array $model,
        string $label,
        string $a,
        string $b,
        string $formula,
        array $sources
    ): array {
        $left = self::dig($model, $a);
        $right = self::dig($model, $b);

        $missing = [];
        if (!is_numeric($left)) { $missing[] = $a; }
        if (!is_numeric($right)) { $missing[] = $b; }

        return [
            'label'     => $label,
            'value'     => $missing === [] ? (float) $left - (float) $right : null,
            'unit'      => 'pp',
            'available' => $missing === [],
            'formula'   => $formula,
            'sources'   => $sources,
            'missing'   => $missing,
        ];
    }

    /**
     * The one division in this file, so the guards live in one place.
     *
     * A zero or absent denominator is exactly what a site-wide dataset looks
     * like before cron has warmed it, and dividing by it would render INF as a
     * percentage — a number that looks authoritative and means nothing.
     */
    private static function divide(
        mixed $numerator,
        mixed $denominator,
        string $label,
        string $formula,
        array $sources,
        array $paths
    ): array {
        $missing = [];
        if (!is_numeric($numerator)) { $missing[] = $paths[0] ?? 'numerator'; }
        if (!is_numeric($denominator) || (float) $denominator == 0.0) { $missing[] = $paths[1] ?? 'denominator'; }

        $value = null;
        if ($missing === []) {
            $candidate = ((float) $numerator / (float) $denominator) * 100;
            $value = is_finite($candidate) ? $candidate : null;
        }

        return [
            'label'     => $label,
            'value'     => $value,
            'unit'      => 'percent',
            'available' => $value !== null,
            'formula'   => $formula,
            'sources'   => $sources,
            'missing'   => $missing,
        ];
    }

    private static function normalizeName(string $name): string
    {
        return strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
    }

    /** Dotted path lookup, matching Derive::dig()'s behaviour. */
    public static function dig(array $data, string $path): mixed
    {
        $cursor = $data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
                return null;
            }
            $cursor = $cursor[$key];
        }
        return $cursor;
    }
}
