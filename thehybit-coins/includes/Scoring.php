<?php
/**
 * TheHybit analytical score — the PHP twin of assets/js/scoring.js.
 *
 * !!  THE MODEL IS PROVISIONAL. Unchanged from the approved prototype.  !!
 *
 * Every weight, threshold and band cutoff below is placeholder calibration
 * pending the data-source mapping. It is NOT a methodology. All of that
 * guesswork stays inside SCORING_MODEL so replacing it is a config edit.
 *
 * Behaviour preserved from the prototype, including the 71/100 for Ethereum on
 * the fixture inputs — tools/wp-parity.test.mjs asserts PHP and JS agree.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class Scoring
{
    /** @provisional Placeholder calibration pending data-source mapping. */
    public const SCORING_MODEL = [
        'version' => 'provisional-0',
        'provisional' => true,
        'components' => [
            'tvlToMarketCap'    => ['weight' => 25, 'scale' => [[0, 0], [0.02, 25], [0.08, 55], [0.16, 75], [0.35, 100]]],
            'volumeToMarketCap' => ['weight' => 20, 'scale' => [[0, 0], [0.01, 25], [0.03, 55], [0.06, 80], [0.15, 100]]],
            'tvlGrowth30d'      => ['weight' => 20, 'scale' => [[-30, 0], [-5, 30], [5, 50], [20, 80], [50, 100]]],
            'activityGrowth30d' => ['weight' => 20, 'scale' => [[-30, 0], [-5, 30], [5, 50], [20, 80], [50, 100]]],
            'dexShareOfVolume'  => ['weight' => 15, 'scale' => [[0, 0], [2, 30], [8, 55], [20, 80], [45, 100]]],
        ],
        'bands' => [
            ['min' => 80, 'label' => 'عالی', 'tone' => 'up'],
            ['min' => 65, 'label' => 'خوب', 'tone' => 'up'],
            ['min' => 45, 'label' => 'متوسط', 'tone' => 'warn'],
            ['min' => 0,  'label' => 'ضعیف', 'tone' => 'down'],
        ],
        'minCoverage' => 0.5,
    ];

    /**
     * Score a coin from its DERIVED metrics.
     *
     * A missing provider does not score as zero — that would punish a coin for
     * OUR lack of data. The component drops out, remaining weights renormalise,
     * and coverage reports how much of the model actually ran. Below
     * minCoverage the score is withheld rather than shown misleadingly.
     */
    public static function score(array $derived, ?array $model = null): array
    {
        $model ??= self::SCORING_MODEL;

        $components = [];
        $weightAvailable = 0.0;
        $weightTotal = 0.0;
        $acc = 0.0;

        foreach ($model['components'] as $key => $cfg) {
            $weightTotal += $cfg['weight'];
            $metric = $derived[$key] ?? null;

            if (!$metric || empty($metric['available'])) {
                $components[] = [
                    'key' => $key, 'weight' => $cfg['weight'], 'available' => false,
                    'raw' => null, 'normalized' => null,
                    'label' => $metric['label'] ?? $key,
                    'providers' => $metric['providers'] ?? [],
                ];
                continue;
            }

            $normalized = self::applyScale((float) $metric['value'], $cfg['scale']);
            $weightAvailable += $cfg['weight'];
            $acc += $normalized * $cfg['weight'];

            $components[] = [
                'key' => $key, 'weight' => $cfg['weight'], 'available' => true,
                'raw' => (float) $metric['value'],
                'normalized' => $normalized,
                'unit' => $metric['unit'],
                'signed' => $metric['signed'] ?? false,
                'label' => $metric['label'],
                'providers' => $metric['providers'],
                'band' => self::bandFor($normalized, $model),
            ];
        }

        $coverage = $weightTotal > 0 ? $weightAvailable / $weightTotal : 0.0;

        if ($coverage < $model['minCoverage'] || $weightAvailable == 0.0) {
            return [
                'available' => false, 'value' => null, 'band' => null,
                'coverage' => $coverage, 'components' => $components,
                'provisional' => $model['provisional'], 'version' => $model['version'],
            ];
        }

        $value = (int) round($acc / $weightAvailable);

        return [
            'available' => true,
            'value' => $value,
            'band' => self::bandFor($value, $model),
            'coverage' => $coverage,
            'components' => $components,
            'provisional' => $model['provisional'],
            'version' => $model['version'],
        ];
    }

    public static function bandFor(float $score, ?array $model = null): array
    {
        $model ??= self::SCORING_MODEL;
        foreach ($model['bands'] as $band) {
            if ($score >= $band['min']) {
                return $band;
            }
        }
        return $model['bands'][count($model['bands']) - 1];
    }

    /** Linear interpolation across the breakpoint table, clamped at both ends. */
    private static function applyScale(float $value, array $scale): float
    {
        if ($value <= $scale[0][0]) {
            return (float) $scale[0][1];
        }
        $last = $scale[count($scale) - 1];
        if ($value >= $last[0]) {
            return (float) $last[1];
        }
        for ($i = 1; $i < count($scale); $i++) {
            [$x0, $y0] = $scale[$i - 1];
            [$x1, $y1] = $scale[$i];
            if ($value <= $x1) {
                return $y0 + (($value - $x0) / ($x1 - $x0)) * ($y1 - $y0);
            }
        }
        return (float) $last[1];
    }

    /** Deterministic templates keyed on strongest/weakest signal. Also provisional. */
    public static function insight(array $scored): ?string
    {
        $usable = array_values(array_filter($scored['components'], static fn($c) => $c['available']));
        if (count($usable) < 2) {
            return null;
        }

        usort($usable, static fn($a, $b) => $b['normalized'] <=> $a['normalized']);
        $best = $usable[0];
        $worst = $usable[count($usable) - 1];

        if (($best['normalized'] - $worst['normalized']) < 12) {
            return 'شاخص‌های این دارایی در این دوره تصویری متعادل نشان می‌دهند و هیچ سیگنال غالبی دیده نمی‌شود.';
        }

        $strength = self::STRENGTH[$best['key']] ?? null;
        $weakness = self::WEAKNESS[$worst['key']] ?? null;
        if (!$strength || !$weakness) {
            return null;
        }
        return $strength . ' در مقابل، ' . $weakness;
    }

    private const STRENGTH = [
        'tvlToMarketCap' => 'نسبت سرمایه قفل‌شده به ارزش بازار در سطح قابل‌توجهی است، که نشان می‌دهد بخش معناداری از ارزش شبکه واقعاً در پروتکل‌ها به کار گرفته شده است.',
        'volumeToMarketCap' => 'نقدشوندگی روزانه نسبت به اندازه بازار بالاست و ورود و خروج سرمایه به‌سادگی انجام می‌شود.',
        'tvlGrowth30d' => 'رشد سرمایه قفل‌شده در یک ماه گذشته قوی بوده و سرمایه تازه وارد اکوسیستم شده است.',
        'activityGrowth30d' => 'فعالیت کاربران شبکه در یک ماه گذشته رشد محسوسی داشته است.',
        'dexShareOfVolume' => 'سهم بالای معاملات غیرمتمرکز نشان‌دهنده عمق نقدشوندگی خارج از صرافی‌های متمرکز است.',
    ];

    private const WEAKNESS = [
        'tvlToMarketCap' => 'سرمایه قفل‌شده نسبت به ارزش بازار کم است و بخش بزرگی از ارزش‌گذاری به کاربرد مستقیم شبکه متکی نیست.',
        'volumeToMarketCap' => 'حجم معاملات نسبت به اندازه بازار پایین است و نقدشوندگی محدودتر به نظر می‌رسد.',
        'tvlGrowth30d' => 'رشد سرمایه قفل‌شده در یک ماه گذشته کند بوده است.',
        'activityGrowth30d' => 'رشد فعالیت کاربران در یک ماه گذشته ضعیف بوده است.',
        'dexShareOfVolume' => 'سهم معاملات غیرمتمرکز پایین است و نقدشوندگی عمدتاً به صرافی‌های متمرکز وابسته است.',
    ];
}
