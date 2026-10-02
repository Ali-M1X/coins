<?php
/**
 * DefiLlama's price API (coins.llama.fi) — the multi-year price history.
 *
 * Its own provider rather than a DefiLlama dataset for one practical reason:
 * a coin's "DeFi" switch turns the DefiLlama provider off for that coin, and
 * Bitcoin has it off. Price history has nothing to do with DeFi, so it must not
 * disappear with it. Same operator, separate budget — a refusal here costs the
 * story chart and nothing else.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\Collectors;

use TheHybit\Coins\Coin;

defined('ABSPATH') || exit;

final class LlamaPrices extends Collector
{
    public function id(): string { return 'llamaprices'; }

    public function datasets(): array { return ['longchart']; }

    public function fetch(Coin $coin, string $dataset): ?array
    {
        return $dataset === 'longchart' ? $this->longchart($coin) : null;
    }

    /**
     * Weekly price since launch, from DefiLlama's price API.
     *
     * CoinGecko's keyless tier caps history at 365 days, which cannot tell a
     * story that starts in 2015. coins.llama.fi serves CoinGecko-sourced prices
     * for any date at no cost, so the long view comes from here — one request a
     * day per coin.
     *
     * RESPONSE SHAPE UNVERIFIED from the build environment (egress is blocked);
     * ProviderProbe has an entry for it so it can be confirmed on the server.
     */
    private function longchart(Coin $coin): ?array
    {
        if ($coin->coingeckoId === '') {
            return null;
        }

        $launch = strtotime((string) $coin->meta('launchDate', '')) ?: (time() - 8 * YEAR_IN_SECONDS);
        $start = max($launch, 1438387200);               // no price exists before Aug 2015
        $span = min(600, (int) ceil((time() - $start) / WEEK_IN_SECONDS));
        if ($span < 2) {
            return null;
        }

        $key = 'coingecko:' . $coin->coingeckoId;
        $d = $this->get('/chart/' . rawurlencode($key), [
            'start'  => $start,
            'span'   => $span,
            'period' => '1w',
        ]);

        $rows = $d['coins'][$key]['prices'] ?? null;
        if (!is_array($rows)) {
            return null;
        }

        $t = $price = [];
        foreach ($rows as $p) {
            if (isset($p['timestamp'], $p['price']) && is_numeric($p['timestamp']) && is_numeric($p['price'])) {
                $t[] = (int) $p['timestamp'];
                $price[] = round((float) $p['price'], 6);
            }
        }

        return count($t) >= 2 ? ['t' => $t, 'price' => $price] : null;
    }
}
