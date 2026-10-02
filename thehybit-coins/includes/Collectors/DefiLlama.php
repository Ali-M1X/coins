<?php
/**
 * DefiLlama — TVL, fees, revenue.
 *
 * Only meaningful for chains that host DeFi. Bitcoin switches this off per-coin
 * (thb_enable_defi) rather than the section rendering an empty card.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\Collectors;

use TheHybit\Coins\Coin;

defined('ABSPATH') || exit;

final class DefiLlama extends Collector
{
    public function id(): string { return 'defillama'; }

    public function datasets(): array { return ['defi', 'chains', 'stablecoins', 'bridges']; }

    public function fetch(Coin $coin, string $dataset): ?array
    {
        return match ($dataset) {
            'defi'        => $this->defi($coin),
            'chains'      => $this->chains(),
            'stablecoins' => $this->stablecoins(),
            'bridges'     => $this->bridges($coin),
            default       => null,
        };
    }

    /** DefiLlama's chain name for a coin — a fifth identifier, configured per coin. */
    private static function chainOf(Coin $coin): string
    {
        return (string) $coin->meta('defillamaChain', ucfirst($coin->slug));
    }

    /* ------------------------------------------------------------------
     * Every chain, once. SITE-SCOPED.
     *
     * /v2/chains returns the complete list in one document, so fetching it per
     * chain would be one identical copy per chain. It is fetched once and the
     * relevant record is selected locally — which is also what makes it useful
     * for context the per-chain endpoints cannot give: a chain's rank and share
     * of total DeFi TVL only exist relative to all the others.
     * ---------------------------------------------------------------- */

    private function chains(): ?array
    {
        $rows = $this->get('/v2/chains');
        if (!is_array($rows) || $rows === []) {
            return null;
        }

        $chains = [];
        $total = 0.0;

        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['name']) || !isset($row['tvl']) || !is_numeric($row['tvl'])) {
                continue;
            }
            $tvl = (float) $row['tvl'];
            if ($tvl <= 0.0) {
                continue;
            }
            $total += $tvl;
            $chains[] = [
                'name'        => (string) $row['name'],
                'tvl'         => $tvl,
                'tokenSymbol' => isset($row['tokenSymbol']) ? (string) $row['tokenSymbol'] : null,
                'geckoId'     => isset($row['gecko_id']) ? (string) $row['gecko_id'] : null,
            ];
        }

        if ($chains === []) {
            return null;
        }

        usort($chains, static fn(array $a, array $b): int => $b['tvl'] <=> $a['tvl']);

        // Rank is assigned here because it is a genuine property of the sorted
        // list — unlike CoinGecko's categories, where no ranking exists and
        // none is invented.
        foreach ($chains as $i => $_) {
            $chains[$i]['rank'] = $i + 1;
            $chains[$i]['share'] = ($chains[$i]['tvl'] / $total) * 100;
        }

        return [
            'chains'    => array_slice($chains, 0, 100),
            'totalTvl'  => $total,
            'chainCount' => count($chains),
        ];
    }

    /* ------------------------------------------------------------------
     * Stablecoins. SITE-SCOPED, because the endpoint returns every chain.
     *
     * The production probe timed this out at 8 seconds. The payload covers
     * every stablecoin on every chain, so the request is slow rather than
     * broken; config gives it 15 seconds of its own. If it still times out the
     * collector throws, the negative cache absorbs the retry, and the page says
     * so — no partial parse, no zeros.
     * ---------------------------------------------------------------- */

    private function stablecoins(): ?array
    {
        $rows = $this->get('/stablecoinchains', [], 'stablecoins');
        if (!is_array($rows) || $rows === []) {
            return null;
        }

        $chains = [];
        $total = 0.0;

        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['name'])) {
                continue;
            }
            /* totalCirculatingUSD is a map keyed by peg type — peggedUSD,
               peggedEUR and so on. Summing the numeric members is the only
               honest total: assuming a single key would silently drop every
               non-dollar stablecoin. */
            $circulating = 0.0;
            foreach ((array) ($row['totalCirculatingUSD'] ?? []) as $value) {
                if (is_numeric($value)) {
                    $circulating += (float) $value;
                }
            }
            if ($circulating <= 0.0) {
                continue;
            }

            $total += $circulating;
            $chains[(string) $row['name']] = $circulating;
        }

        if ($chains === []) {
            return null;
        }

        arsort($chains);

        return [
            'byChain'  => array_slice($chains, 0, 60, true),
            'totalUsd' => $total,
        ];
    }

    /* ------------------------------------------------------------------
     * Bridge volume. PER-CHAIN.
     *
     * DISABLED IN CONFIG. The production probe received HTTP 407 from this
     * endpoint — a proxy/authorisation rejection, which no parser change
     * reaches. The parser below is written and tested against the documented
     * shape so that enabling it is one config line once the probe confirms it
     * answers, but nothing calls it until then.
     *
     * The probe also reported a missing `date` field. The response is an array
     * of daily rows whose timestamp key has appeared as both `date` and `ts`,
     * so both are accepted rather than betting on one.
     * ---------------------------------------------------------------- */

    private function bridges(Coin $coin): ?array
    {
        $rows = $this->get('/bridgevolume/' . rawurlencode(self::chainOf($coin)), [], 'bridges');
        if (!is_array($rows) || $rows === []) {
            return null;
        }

        $series = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ts = $row['date'] ?? $row['ts'] ?? null;
            $in  = $row['depositUSD'] ?? null;
            $out = $row['withdrawUSD'] ?? null;

            if (!is_numeric($ts) || (!is_numeric($in) && !is_numeric($out))) {
                continue;
            }

            $series[] = [
                't'       => (int) $ts,
                'deposit' => is_numeric($in) ? (float) $in : 0.0,
                'withdraw' => is_numeric($out) ? (float) $out : 0.0,
            ];
        }

        if (count($series) < 2) {
            return null;
        }

        usort($series, static fn(array $a, array $b): int => $a['t'] <=> $b['t']);

        $volumeOf = static fn(array $r): float => $r['deposit'] + $r['withdraw'];
        $latest = $series[count($series) - 1];

        return [
            'volume24h'  => $volumeOf($latest),
            'deposit24h' => $latest['deposit'],
            'withdraw24h' => $latest['withdraw'],
            'volume7d'   => array_sum(array_map($volumeOf, self::tail($series, 7))),
            'volume30d'  => array_sum(array_map($volumeOf, self::tail($series, 30))),
            'updatedAt'  => $latest['t'],
        ];
    }

    private function defi(Coin $coin): ?array
    {
        $chain = self::chainOf($coin);

        $tvlSeries = $this->get('/v2/historicalChainTvl/' . rawurlencode($chain));
        if (!is_array($tvlSeries) || $tvlSeries === []) {
            return null;
        }

        $latest = end($tvlSeries);
        $tvl = isset($latest['tvl']) ? (float) $latest['tvl'] : null;
        if ($tvl === null) {
            return null;
        }

        return [
            'tvl'          => $tvl,
            // THE RESPONSE IS THE WHOLE DAILY HISTORY. It always was — this call
            // returns every day since the chain launched, and until now the
            // collector kept two points and threw the rest away. Reading 7, 30
            // and 90 days out of it costs nothing: no second endpoint, no extra
            // request, no waiting for our own history table to fill up.
            'tvlChange24h' => self::changeOverDays($tvlSeries, 1),
            'tvlChange7d'  => self::changeOverDays($tvlSeries, 7),
            'tvlChange30d' => self::changeOverDays($tvlSeries, 30),
            'tvlChange90d' => self::changeOverDays($tvlSeries, 90),
            'sparkline'    => self::tail($tvlSeries, 40),
            // No *30dAgo here: the SCORED growth metric still comes from OUR
            // history table, which is the whole reason History.php exists. The
            // figures above are for display and are deliberately kept separate
            // so the scoring model's inputs do not change.
        ] + $this->fees($chain);
    }

    /**
     * Percentage change between the newest point and the one N days before it.
     *
     * The series is daily and chronological, so "N days ago" is N entries back.
     * A chain younger than the window returns null rather than measuring against
     * its first ever day, which would report a meaningless four-digit gain.
     */
    private static function changeOverDays(array $series, int $days): ?float
    {
        $count = count($series);
        if ($count < $days + 1) {
            return null;
        }

        $now = (float) ($series[$count - 1]['tvl'] ?? 0);
        $then = (float) ($series[$count - 1 - $days]['tvl'] ?? 0);

        if ($then <= 0.0 || $now <= 0.0) {
            return null;
        }
        return (($now - $then) / $then) * 100;
    }

    /**
     * Fees and revenue.
     *
     * The same response already carries the 7- and 30-day totals and DefiLlama's
     * own change percentages; only total24h and totalRevenue24h were being kept.
     * Everything below arrives in the call we were already making.
     */
    private function fees(string $chain): array
    {
        try {
            $d = $this->get('/overview/fees/' . rawurlencode($chain), [
                'excludeTotalDataChart'          => 'true',
                'excludeTotalDataChartBreakdown' => 'true',
            ]);
        } catch (\Throwable) {
            // Fees are optional; TVL alone still renders a useful card.
            return [];
        }

        $num = static fn(string $key): ?float =>
            isset($d[$key]) && is_numeric($d[$key]) ? (float) $d[$key] : null;

        return [
            'fees24h'          => $num('total24h'),
            'fees7d'           => $num('total7d'),
            'fees30d'          => $num('total30d'),
            'feesChange24h'    => $num('change_1d'),
            'feesChange7d'     => $num('change_7d'),
            'feesChange30d'    => $num('change_1m'),
            'revenue24h'       => $num('totalRevenue24h'),
            'revenue7d'        => $num('totalRevenue7d'),
            'revenue30d'       => $num('totalRevenue30d'),
        ];
    }

    private static function tail(array $series, int $n): array
    {
        $slice = array_slice($series, -$n);
        return array_values(array_map(static fn($p) => round((float) ($p['tvl'] ?? 0), 2), $slice));
    }
}
