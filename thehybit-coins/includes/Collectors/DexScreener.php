<?php
/**
 * DexScreener — DEX volume, liquidity, top pair.
 *
 * The section only renders when the numbers are MEANINGFUL, not merely present;
 * those thresholds live in Derive::sectionVisibility(), mirroring derive.js.
 *
 * TWO THINGS THIS FILE HAS TO GET RIGHT, AND ORIGINALLY DID NOT:
 *
 * 1. A LIQUIDITY POOL HOLDS THE WRAPPED TOKEN. Native ETH is not an ERC-20, so
 *    no Ethereum pool can hold it; every real ETH market on DexScreener reports
 *    baseToken.symbol as "WETH". Matching the coin's bare ticker therefore threw
 *    away the entire section's data and the card silently disappeared — which is
 *    exactly what happened on the live Ethereum page. Wrapped forms are now
 *    accepted as the same asset.
 *
 * 2. A TICKER IS NOT AN IDENTIFIER. /dex/search?q=ETH is a text search: it also
 *    returns unrelated tokens that happen to be called ETH on other chains, and
 *    summing those would report a number that belongs to somebody else. When the
 *    coin has a contract address configured we ask by ADDRESS instead, which is
 *    unambiguous. The symbol search stays as the fallback for assets with no
 *    contract address, but its results are filtered on both symbol AND chain.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\Collectors;

use TheHybit\Coins\Coin;

defined('ABSPATH') || exit;

final class DexScreener extends Collector
{
    /**
     * How many pairs DexScreener returns per response.
     *
     * Both endpoints page at 30. For an asset with more pools than that — WETH
     * has thousands — the result is a SAMPLE, and the totals below describe the
     * pairs we were given rather than the asset's whole DEX activity. Saying so
     * in the payload is what stops a ratio being computed from it downstream;
     * see Derive::dexVisibility().
     */
    private const PAGE_SIZE = 30;

    public function id(): string { return 'dexscreener'; }

    public function datasets(): array { return ['dex']; }

    public function fetch(Coin $coin, string $dataset): ?array
    {
        if ($dataset !== 'dex') {
            return null;
        }

        $pairs = $this->pairsFor($coin);
        if ($pairs === []) {
            return null;
        }

        $volume = 0.0;
        $liquidity = 0.0;
        foreach ($pairs as $p) {
            $volume    += (float) ($p['volume']['h24'] ?? 0);
            $liquidity += (float) ($p['liquidity']['usd'] ?? 0);
        }

        usort($pairs, static fn($a, $b) =>
            ((float) ($b['volume']['h24'] ?? 0)) <=> ((float) ($a['volume']['h24'] ?? 0))
        );
        $top = $pairs[0];

        return [
            'volume24h' => $volume,
            'liquidity' => $liquidity,
            'pairCount' => count($pairs),
            // Truncated at the endpoint's page size: these totals cover the
            // pairs we were given, not every pool that exists.
            'sampled'   => count($pairs) >= self::PAGE_SIZE,
            'topPair'   => [
                // The ticker shown to readers is the COIN's, not the wrapped
                // token's: a Persian reader looking at the اتریوم page expects
                // ETH/USDC, and "WETH" is an implementation detail of the pool.
                'pair'      => sprintf(
                    '%s/%s',
                    strtoupper($coin->symbol),
                    strtoupper((string) ($top['quoteToken']['symbol'] ?? ''))
                ),
                'exchange'  => $top['dexId'] ?? null,
                'volume24h' => (float) ($top['volume']['h24'] ?? 0),
                'url'       => $top['url'] ?? null,
            ],
        ];
    }

    /**
     * Every pair in which this coin is the base asset.
     *
     * Address lookup first, because it cannot confuse two assets that share a
     * ticker. Falls back to the symbol search when no contract address is
     * configured for the coin.
     *
     * @return array<int, array>
     */
    private function pairsFor(Coin $coin): array
    {
        $address = trim((string) $coin->meta('dexTokenAddress', $coin->meta('contractAddress', '')));

        if ($address !== '') {
            $raw = $this->get('/dex/tokens/' . rawurlencode($address));
            $pairs = $this->baseOnly($raw['pairs'] ?? null, $coin);
            if ($pairs !== []) {
                return $pairs;
            }
            // An address that returns nothing is worth knowing about; fall
            // through to the search rather than reporting no DEX presence.
            error_log('[thb] dexscreener: no pairs for ' . $coin->slug . ' address ' . $address);
        }

        $raw = $this->get('/dex/search', ['q' => $coin->symbol]);
        return $this->baseOnly($raw['pairs'] ?? null, $coin);
    }

    /**
     * Keep only pairs where this coin is the BASE asset — otherwise the totals
     * would sweep in every unrelated market that merely quotes in it.
     *
     * @param mixed $pairs raw `pairs` array from either endpoint
     * @return array<int, array>
     */
    private function baseOnly(mixed $pairs, Coin $coin): array
    {
        if (!is_array($pairs) || $pairs === []) {
            error_log('[thb] dexscreener: ' . $coin->slug . ' — the endpoint returned no pairs at all');
            return [];
        }

        $accepted = self::acceptedSymbols($coin);

        $kept = array_values(array_filter($pairs, static function ($p) use ($accepted): bool {
            if (!is_array($p)) {
                return false;
            }
            $base = strtoupper((string) ($p['baseToken']['symbol'] ?? ''));
            return $base !== '' && in_array($base, $accepted, true);
        }));

        /* A filter that discards EVERYTHING is the failure mode that hid this
           section for weeks: the response was fine, the section simply vanished.
           Naming the symbols we saw against the ones we would have accepted
           turns that from a mystery into a one-line answer in the log. */
        if ($kept === []) {
            $seen = array_count_values(array_map(
                static fn($p) => strtoupper((string) ($p['baseToken']['symbol'] ?? '?')),
                array_filter($pairs, 'is_array')
            ));
            arsort($seen);
            $summary = [];
            foreach (array_slice($seen, 0, 8, true) as $symbol => $count) {
                $summary[] = $symbol . '×' . $count;
            }
            error_log(sprintf(
                '[thb] dexscreener: %s — %d pairs returned but none had an accepted base symbol. Accepted: %s. Seen: %s.',
                $coin->slug,
                count($pairs),
                implode(', ', $accepted),
                implode(', ', $summary)
            ));
        }

        return $kept;
    }

    /**
     * The symbols that mean "this coin" to a DEX.
     *
     * The wrapped form is the same economic asset — WETH is redeemable 1:1 for
     * ETH and is what every pool actually holds — so its markets are this coin's
     * markets. Additional aliases can be configured per coin (WBTC vs BTC on
     * chains where both trade) without touching this file.
     *
     * @return array<int, string>
     */
    private static function acceptedSymbols(Coin $coin): array
    {
        $symbol = strtoupper($coin->symbol);
        $accepted = [$symbol, 'W' . $symbol];

        foreach (explode(',', (string) $coin->meta('dexSymbols', '')) as $extra) {
            $extra = strtoupper(trim($extra));
            if ($extra !== '') {
                $accepted[] = $extra;
            }
        }

        return array_values(array_unique($accepted));
    }
}
