<?php
/**
 * DefiLlama Yields — the stETH staking yield of Lido's pool. Keyless.
 *
 * Replaces Lido's own API, which refuses this site's server by location.
 * DefiLlama computes the pool's yield itself from on-chain data and serves it
 * from its own domain, which this server already reaches for fees and TVL.
 *
 *   GET https://yields.llama.fi/chart/{pool}
 *   {"status":"success","data":[{"timestamp":"2026-10-08T23:01:52.103Z","tvlUsd":…,"apy":2.61,"apyBase":2.61,…}, …]}
 *
 * One row a day for the pool's whole life. The pool id is configuration
 * (`pools` in config/providers.php): 747c1d2a-… is DefiLlama's "stETH —
 * Lido" pool (defillama.com/yields/pool/747c1d2a-c668-4682-b9f9-296708a3dd90).
 *
 * Measured, not modelled, and ONE provider's yield (after Lido's 10% fee), not
 * the network average — labelled so on the page. Shown only when beaconcha.in's
 * network-wide figure is unavailable.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\Collectors;

use TheHybit\Coins\Coin;

defined('ABSPATH') || exit;

final class LlamaYields extends Collector
{
    public function id(): string { return 'llamayields'; }

    public function datasets(): array { return ['stakingyield']; }

    public function fetch(Coin $coin, string $dataset): ?array
    {
        if ($dataset !== 'stakingyield') {
            return null;
        }
        $pool = $this->settings()['pools'][(string) $coin->meta('defillamaChain', '')] ?? null;
        if ($pool === null) {
            return null;
        }
        return self::extract((array) $this->get('/chart/' . rawurlencode((string) $pool)), (string) $pool);
    }

    /** {apy, apy7d, date, pool}: the latest daily yield and its 7-day mean. */
    public static function extract(array $d, string $pool): array
    {
        $rows = [];
        foreach ((array) ($d['data'] ?? []) as $r) {
            $apy = $r['apyBase'] ?? $r['apy'] ?? null;
            if (is_array($r) && is_numeric($apy) && !empty($r['timestamp'])) {
                $rows[] = ['date' => substr((string) $r['timestamp'], 0, 10), 'apy' => (float) $apy];
            }
        }
        if ($rows === []) {
            throw new \RuntimeException('no apy rows in the pool chart' . (isset($d['status']) ? ' (status: ' . $d['status'] . ')' : ''));
        }
        usort($rows, static fn($a, $b) => strcmp($a['date'], $b['date']));
        $last7 = array_slice($rows, -7);
        $apy7d = array_sum(array_column($last7, 'apy')) / count($last7);
        $latest = end($rows);

        // A staking yield outside 0–20% is a unit or parsing problem, not news.
        if ($apy7d <= 0 || $apy7d > 20) {
            throw new \RuntimeException('implausible stETH APY ' . round($apy7d, 3) . ' — rejected');
        }
        return ['apy' => $latest['apy'], 'apy7d' => $apy7d, 'date' => $latest['date'], 'pool' => $pool];
    }
}
