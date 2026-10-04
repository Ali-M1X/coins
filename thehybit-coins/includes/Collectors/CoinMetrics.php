<?php
/**
 * CoinMetrics Community API — daily on-chain metrics, free and keyless.
 *
 * The source for figures that were "unavailable" in the first v2 build:
 *
 *   AdrActCnt    daily active addresses
 *   TxCnt        daily transactions
 *   SplyCur      current supply — two readings a week apart give the ACTUAL
 *                net inflation (issuance minus burn), which is what real yield
 *                subtracts
 *   IssTotNtv    new coins issued in the day
 *   SplyExNtv    supply held on exchanges            } included in the
 *   FlowInExNtv  coins sent to exchanges in the day   } community tier for
 *   FlowOutExNtv coins withdrawn from exchanges       } some assets only
 *   SplyAct1yr   supply that moved in the last year — its complement is
 *                supply untouched for a year (long-term holders)
 *   HashRate     proof-of-work hash rate (Bitcoin)
 *
 * WHICH METRICS THE FREE TIER INCLUDES IS CoinMetrics' DECISION and has
 * changed over time. The request asks for all of them with
 * ignore_forbidden_errors / ignore_unsupported_errors, so the API returns what
 * it allows instead of refusing the whole request; a metric it withholds is
 * simply absent, and the page reports that figure as unavailable rather than
 * substituting anything.
 *
 * RESPONSE SHAPE UNVERIFIED from the build environment (egress is blocked);
 * the Provider Probe has an entry for it.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\Collectors;

use TheHybit\Coins\Coin;

defined('ABSPATH') || exit;

final class CoinMetrics extends Collector
{
    public function id(): string { return 'coinmetrics'; }

    public function datasets(): array { return ['chainstats']; }

    public function fetch(Coin $coin, string $dataset): ?array
    {
        if ($dataset !== 'chainstats') {
            return null;
        }

        $settings = $this->settings();
        $asset = (string) ($settings['assets'][$coin->coingeckoId] ?? strtolower($coin->symbol));
        $metrics = array_values(array_filter(array_map('strval', (array) ($settings['metrics'] ?? []))));
        if ($asset === '' || $metrics === []) {
            return null;
        }

        $raw = $this->get('/timeseries/asset-metrics', [
            'assets'                    => $asset,
            'metrics'                   => implode(',', $metrics),
            'frequency'                 => '1d',
            // Ten days: today's row may not exist yet, and a week-ago
            // comparison needs eight consecutive days.
            'start_time'                => gmdate('Y-m-d', time() - 10 * DAY_IN_SECONDS),
            'page_size'                 => '30',
            'ignore_forbidden_errors'   => 'true',
            'ignore_unsupported_errors' => 'true',
        ]);
        $rows = is_array($raw['data'] ?? null) ? $raw['data'] : null;
        if ($rows === null) {
            return null;
        }

        // Chronological, whatever order the page came back in.
        usort($rows, static fn($a, $b) => strcmp((string) ($a['time'] ?? ''), (string) ($b['time'] ?? '')));

        $out = [];
        foreach ($metrics as $m) {
            $series = [];
            foreach ($rows as $r) {
                if (is_array($r) && isset($r[$m], $r['time']) && is_numeric($r[$m])) {
                    $series[substr((string) $r['time'], 0, 10)] = (float) $r[$m];
                }
            }
            if ($series === []) {
                continue;   // withheld by the free tier, or not tracked for this asset
            }
            $dates = array_keys($series);
            $last = end($dates);
            $weekAgo = gmdate('Y-m-d', strtotime($last . ' UTC') - 7 * DAY_IN_SECONDS);
            $out[$m] = [
                'latest'   => $series[$last],
                'date'     => $last,
                'weekAgo'  => $series[$weekAgo] ?? null,
                'series'   => array_values(array_slice($series, -8)),
            ];
        }

        return $out === [] ? null : ['asset' => $asset, 'metrics' => $out];
    }
}
