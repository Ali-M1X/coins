<?php
/**
 * Derived metrics — the PHP twin of assets/js/derive.js.
 *
 * Same boundary, same registry, same names:
 *
 *   raw provider payloads   what one provider reported. Nothing computed.
 *   Derive::metrics()       ratios and growth, often COMBINING providers.
 *
 * TVL comes from DefiLlama and market cap from CoinGecko, so "TVL / market cap"
 * belongs to neither — it is ours, and it is computed somewhere that records
 * which providers it depends on.
 *
 * KEEPING THE TWO IN SYNC: metric keys, provider lists and section thresholds
 * are asserted identical to the JS by tools/wp-parity.test.mjs. If you add a
 * metric here, add it there, or the test fails.
 *
 * Pure: no WordPress calls, no I/O.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class Derive
{
    /** Mirrors METRICS in derive.js. */
    public const METRICS = [
        'tvlToMarketCap' => [
            'label' => 'نسبت TVL به ارزش بازار',
            'unit' => 'ratio', 'signed' => false,
            'providers' => ['defillama', 'coingecko'],
            'requires' => ['defi.tvl', 'market.marketCap'],
        ],
        'volumeToMarketCap' => [
            'label' => 'نسبت حجم به ارزش بازار',
            'unit' => 'ratio', 'signed' => false,
            'providers' => ['coingecko'],
            'requires' => ['market.volume24h', 'market.marketCap'],
        ],
        'dexShareOfVolume' => [
            'label' => 'سهم معاملات غیرمتمرکز',
            'unit' => 'percent', 'signed' => false,
            'providers' => ['dexscreener', 'coingecko'],
            'requires' => ['dex.volume24h', 'market.volume24h'],
        ],
        'feesToMarketCap' => [
            'label' => 'نسبت کارمزد سالانه به ارزش بازار',
            'unit' => 'percent', 'signed' => false,
            'providers' => ['defillama', 'coingecko'],
            'requires' => ['defi.fees24h', 'market.marketCap'],
        ],
        'tvlGrowth30d' => [
            'label' => 'رشد TVL (30 روز)',
            'unit' => 'percent', 'signed' => true,
            'providers' => ['defillama'],
            'requires' => ['defi.tvl', 'defi.tvl30dAgo'],
        ],
        'volumeGrowth30d' => [
            'label' => 'رشد حجم معاملات (30 روز)',
            'unit' => 'percent', 'signed' => true,
            'providers' => ['coingecko'],
            'requires' => ['market.volume24h', 'market.volume30dAvg'],
        ],
        'activityGrowth30d' => [
            'label' => 'رشد فعالیت شبکه (30 روز)',
            'unit' => 'percent', 'signed' => true,
            'providers' => ['dune'],
            'requires' => ['onchain.activeAddresses24h', 'onchain.activeAddresses30dAgo'],
        ],

        /* ------------------------------------------------------------------
         * Added in v2.0.0. Every one of these is computed from data the plugin
         * ALREADY fetches and caches — no new provider, no new request.
         *
         * None of them is referenced by SCORING_MODEL, so the headline score
         * and its five components are bit-for-bit unchanged. They exist to be
         * displayed, not to be scored.
         * ---------------------------------------------------------------- */

        'marketCapToFdv' => [
            'label' => 'نسبت ارزش بازار به ارزش رقیق‌شده',
            'unit' => 'ratio', 'signed' => false,
            'providers' => ['coingecko'],
            'requires' => ['market.marketCap', 'market.fdv'],
        ],
        'circulatingPct' => [
            'label' => 'درصد عرضه در گردش',
            'unit' => 'percent', 'signed' => false,
            'providers' => ['coingecko'],
            'requires' => ['market.circulatingSupply', 'market.totalSupply'],
        ],
        'drawdownFromAth' => [
            'label' => 'فاصله از بالاترین قیمت تاریخی',
            'unit' => 'percent', 'signed' => true,
            'providers' => ['coingecko'],
            'requires' => ['market.ath.changePct'],
        ],
        'relativeToBenchmark30d' => [
            'label' => 'عملکرد نسبی در برابر بیت‌کوین (30 روز)',
            'unit' => 'percent', 'signed' => true,
            'providers' => ['coingecko'],
            'requires' => ['performance.30d', 'benchmark.performance.30d'],
        ],
        'revenueToMarketCap' => [
            'label' => 'نسبت درآمد سالانه به ارزش بازار',
            'unit' => 'percent', 'signed' => false,
            'providers' => ['defillama', 'coingecko'],
            'requires' => ['defi.revenue24h', 'market.marketCap'],
        ],
        'feesToTvl' => [
            'label' => 'نسبت کارمزد سالانه به ارزش قفل‌شده',
            'unit' => 'percent', 'signed' => false,
            'providers' => ['defillama'],
            'requires' => ['defi.fees24h', 'defi.tvl'],
        ],

        /* The two volatility metrics read a PRICE SERIES rather than scalars,
           so `requires` is empty and compute() returns null when the window is
           not cached. The series is one the chart already holds. */
        'volatility7d' => [
            'label' => 'نوسان قیمت (7 روز)',
            'unit' => 'percent', 'signed' => false,
            'providers' => ['coingecko'],
            'requires' => [],
        ],
        'volatility30d' => [
            'label' => 'نوسان قیمت (30 روز)',
            'unit' => 'percent', 'signed' => false,
            'providers' => ['coingecko'],
            'requires' => [],
        ],
    ];

    /** Mirrors VISIBILITY_RULES in derive.js. */
    public const VISIBILITY_RULES = [
        'dex' => ['minVolume24h' => 1000000, 'minSharePct' => 0.5],
    ];

    /**
     * Compute every metric whose inputs are present.
     *
     * A metric with missing inputs comes back available=false with the missing
     * paths named — never NAN. That is how a coin with no DeFi presence or no
     * DEX listing degrades cleanly.
     */
    public static function metrics(array $model): array
    {
        $out = [];

        foreach (self::METRICS as $key => $def) {
            $missing = [];
            foreach ($def['requires'] as $path) {
                if (!is_numeric(self::dig($model, $path))) {
                    $missing[] = $path;
                }
            }

            $base = [
                'label' => $def['label'],
                'unit' => $def['unit'],
                'signed' => $def['signed'],
                'providers' => $def['providers'],
            ];

            if ($missing) {
                $out[$key] = $base + ['value' => null, 'available' => false, 'missing' => $missing];
                continue;
            }

            $value = self::compute($key, $model);
            $ok = is_float($value) && is_finite($value);
            $out[$key] = $base + ['value' => $ok ? $value : null, 'available' => $ok, 'missing' => []];
        }

        return $out;
    }

    private static function compute(string $key, array $m): ?float
    {
        $n = static fn(string $p) => (float) self::dig($m, $p);

        $value = match ($key) {
            'tvlToMarketCap'    => $n('market.marketCap') != 0.0 ? $n('defi.tvl') / $n('market.marketCap') : null,
            'volumeToMarketCap' => $n('market.marketCap') != 0.0 ? $n('market.volume24h') / $n('market.marketCap') : null,
            'dexShareOfVolume'  => $n('market.volume24h') != 0.0 ? ($n('dex.volume24h') / $n('market.volume24h')) * 100 : null,
            'feesToMarketCap'   => $n('market.marketCap') != 0.0 ? (($n('defi.fees24h') * 365) / $n('market.marketCap')) * 100 : null,
            'tvlGrowth30d'      => self::growth($n('defi.tvl'), $n('defi.tvl30dAgo')),
            'volumeGrowth30d'   => self::growth($n('market.volume24h'), $n('market.volume30dAvg')),
            'activityGrowth30d' => self::growth($n('onchain.activeAddresses24h'), $n('onchain.activeAddresses30dAgo')),

            'marketCapToFdv'    => $n('market.fdv') != 0.0 ? $n('market.marketCap') / $n('market.fdv') : null,
            'circulatingPct'    => $n('market.totalSupply') != 0.0
                ? ($n('market.circulatingSupply') / $n('market.totalSupply')) * 100 : null,
            'drawdownFromAth'   => $n('market.ath.changePct'),
            'relativeToBenchmark30d' => $n('performance.30d') - $n('benchmark.performance.30d'),
            'revenueToMarketCap' => $n('market.marketCap') != 0.0
                ? (($n('defi.revenue24h') * 365) / $n('market.marketCap')) * 100 : null,
            'feesToTvl'         => $n('defi.tvl') != 0.0
                ? (($n('defi.fees24h') * 365) / $n('defi.tvl')) * 100 : null,
            'volatility7d'      => self::volatility($m, '7d'),
            'volatility30d'     => self::volatility($m, '30d'),

            default             => null,
        };

        return is_float($value) ? $value : null;
    }

    /**
     * Annualised volatility, from a price series we already have cached.
     *
     * Standard deviation of log returns, scaled to a year by the square root of
     * the number of samples per year. The chart windows are sampled at
     * CoinGecko's own granularity — hourly for 7 and 30 days — so the scaling
     * factor is derived from the window length and sample count rather than
     * assumed, which keeps the figure comparable across windows.
     *
     * Returns null rather than 0 when the window is not cached: an uncomputed
     * volatility and a genuinely flat price are different statements.
     * Mirrors volatility() in derive.js.
     */
    private static function volatility(array $model, string $window): ?float
    {
        $prices = $model['series'][$window]['price'] ?? null;
        if (!is_array($prices) || count($prices) < 3) {
            return null;
        }

        $returns = [];
        for ($i = 1, $n = count($prices); $i < $n; $i++) {
            $prev = (float) $prices[$i - 1];
            $curr = (float) $prices[$i];
            if ($prev > 0.0 && $curr > 0.0) {
                $returns[] = log($curr / $prev);
            }
        }
        if (count($returns) < 2) {
            return null;
        }

        $mean = array_sum($returns) / count($returns);
        $variance = 0.0;
        foreach ($returns as $r) {
            $variance += ($r - $mean) ** 2;
        }
        $stdev = sqrt($variance / (count($returns) - 1));

        // Samples per year, from how much wall time this window covers.
        $days = $window === '7d' ? 7 : 30;
        $perYear = (count($returns) / $days) * 365;

        return $stdev * sqrt($perYear) * 100;
    }

    private static function growth(float $now, float $then): ?float
    {
        return $then == 0.0 ? null : (($now - $then) / $then) * 100;
    }

    /**
     * Which optional sections should render. Mirrors sections() in derive.js.
     * Each answer carries a reason so an absent section is explainable.
     */
    public static function sectionVisibility(array $model, ?array $derived = null): array
    {
        $derived ??= self::metrics($model);

        return [
            'dex'  => self::dexVisibility($model, $derived),
            'l2'   => self::l2Visibility($model),
            'news' => self::newsVisibility($model),
        ];
    }

    private static function dexVisibility(array $model, array $derived): array
    {
        $dex = $model['dex'] ?? null;
        if (!$dex) {
            return self::no('پیش از این داده‌ای از صرافی‌های غیرمتمرکز ثبت نشده است');
        }

        $rules = self::VISIBILITY_RULES['dex'];
        if (!isset($dex['volume24h']) || $dex['volume24h'] < $rules['minVolume24h']) {
            return self::no('حجم معاملات غیرمتمرکز کمتر از آستانه است');
        }

        /* THE SHARE VETO NEEDS A COMPLETE MEASUREMENT, NOT A SAMPLE.
         *
         * `sampled` means the provider truncated its pair list at a page limit,
         * so volume24h is what the returned pairs happened to carry — not the
         * asset's DEX volume. Dividing that by CoinGecko's TOTAL market volume
         * gives a ratio whose numerator and denominator measure different
         * things, and it systematically understates.
         *
         * On the live Ethereum page that is exactly what hid a section carrying
         * $111.5M of real volume: 30 sampled pairs against a $25–40bn market
         * came to 0.28–0.45%, under the 0.5% floor. The absolute minVolume24h
         * rule — which the same figure clears a hundred times over — is what
         * actually governs whether the presence is meaningful.
         *
         * The threshold is unchanged and still applies whenever the measurement
         * is complete. It simply does not get to veto on an input it cannot
         * support. Mirrors dexVisibility() in derive.js. */
        $share = $derived['dexShareOfVolume'];
        if ($share['available'] && empty($dex['sampled']) && $share['value'] < $rules['minSharePct']) {
            return self::no('سهم صرافی‌های غیرمتمرکز ناچیز است');
        }

        return self::yes('حجم و سهم معنادار');
    }

    private static function l2Visibility(array $model): array
    {
        $l2 = $model['l2'] ?? null;
        if (!$l2) {
            return self::no('این دارایی داده اختصاصی لایه 2 ندارد');
        }
        if (($l2['scope'] ?? null) !== 'chain') {
            return self::no('دامنه داده لایه 2 هنوز تعریف نشده است');
        }
        return self::yes('داده لایه 2 مختص همین شبکه است');
    }

    private static function newsVisibility(array $model): array
    {
        $news = $model['news'] ?? null;
        if (!is_array($news) || $news === []) {
            return self::no('هنوز مطلبی درباره این ارز در سایت منتشر نشده است');
        }
        return self::yes(count($news) . ' مطلب مرتبط');
    }

    private static function yes(string $reason): array { return ['visible' => true, 'reason' => $reason]; }
    private static function no(string $reason): array { return ['visible' => false, 'reason' => $reason]; }

    public static function dig(array $data, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }
        return $data;
    }
}
