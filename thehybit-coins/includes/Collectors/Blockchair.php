<?php
/**
 * Blockchair — network activity, for more than one chain.
 *
 * WHY THIS AND NOT ETHERSCAN FOR THE SAME FIGURES
 *
 * Etherscan is Ethereum only. The design's Network Activity panel has to answer
 * for Bitcoin too, and at a hundred coins for whatever chains those sit on, and
 * one endpoint shape across chains is worth more here than a slightly richer
 * one per chain. It is also KEYLESS on its free tier, so this is the one new
 * provider that needs nothing pasted into settings.
 *
 * HASHRATE IS NOT REPORTED FOR A PROOF-OF-STAKE CHAIN
 *
 * The reference design shows "Network Hashrate 939 TH/s" on an Ethereum page.
 * Ethereum has had no hashrate since the Merge in September 2022; the figure is
 * a placeholder, and rendering one would be stating something false with great
 * confidence. So hashrate is emitted only when the provider reports a non-zero
 * value, which on Ethereum it does not — and the page shows the row as
 * unavailable rather than inventing it.
 *
 * RESPONSE SHAPES ARE UNVERIFIED FROM THE BUILD ENVIRONMENT; see Etherscan.php
 * for why, and ProviderProbe for how to confirm them on the server.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\Collectors;

use TheHybit\Coins\Coin;

defined('ABSPATH') || exit;

final class Blockchair extends Collector
{
    public function id(): string { return 'blockchair'; }

    public function datasets(): array { return ['network', 'whales']; }

    public function fetch(Coin $coin, string $dataset): ?array
    {
        if ($dataset === 'whales') {
            return $this->whales($coin);
        }
        if ($dataset !== 'network') {
            return null;
        }

        $slug = $this->slugFor($coin);
        if ($slug === null) {
            return null;   // a chain this provider does not serve
        }

        $d = $this->get('/' . rawurlencode($slug) . '/stats')['data'] ?? null;
        if (!is_array($d)) {
            return null;
        }

        $num = static fn(string $k): ?float =>
            isset($d[$k]) && is_numeric($d[$k]) ? (float) $d[$k] : null;
        $int = static fn(string $k): ?int =>
            isset($d[$k]) && is_numeric($d[$k]) ? (int) $d[$k] : null;

        $out = [
            'blocks'           => $int('blocks'),
            'blocks24h'        => $int('blocks_24h'),
            'transactions24h'  => $int('transactions_24h'),
            'mempool'          => $int('mempool_transactions'),
            'avgFeeUsd24h'     => $num('average_transaction_fee_usd_24h'),
            'medianFeeUsd24h'  => $num('median_transaction_fee_usd_24h'),
            'difficulty'       => $num('difficulty'),
            'nodes'            => $int('nodes'),
        ];

        /* Average seconds between blocks, from a count rather than a claimed
           figure: 86,400 seconds divided by yesterday's block count. Guarded,
           because a chain that produced no blocks in the window would otherwise
           divide by zero and report an infinite block time. */
        $out['blockTime'] = ($out['blocks24h'] ?? 0) > 0
            ? 86400 / (float) $out['blocks24h']
            : null;

        /* Proof-of-work only. Zero means "this chain does not mine", not "the
           hashrate is zero", and the two must not render the same way. */
        $hashrate = $d['hashrate_24h'] ?? null;
        $out['hashrate'] = (is_numeric($hashrate) && (float) $hashrate > 0.0)
            ? (float) $hashrate
            : null;

        return self::anyValue($out) ? $out : null;
    }

    /**
     * Blockchair's own name for a chain.
     *
     * An eighth identifier for one coin, stored in configuration rather than
     * derived from the DefiLlama name or the slug — for exactly the reason
     * Coin.php lists the first four separately. Returning null for an unmapped
     * chain is deliberate: asking Blockchair about a chain it does not index
     * returns a confident-looking error document.
     */
    private function slugFor(Coin $coin): ?string
    {
        $chain = (string) $coin->meta('defillamaChain', '');
        $map = (array) ($this->settings()['slugs'] ?? []);

        return isset($map[$chain]) ? (string) $map[$chain] : null;
    }

    private static function anyValue(array $row): bool
    {
        foreach ($row as $v) {
            if ($v !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whale radar: the most recent native transfers above a size threshold.
     *
     * Blockchair's transaction table accepts a range filter and a sort, so one
     * request returns "the latest transfers of at least N coins" — the list the
     * reference design shows. Native ETH/BTC only: token transfers (WETH,
     * stablecoins) are a different table and are not included, which the page
     * says. Addresses are shown shortened, without names: wallet labels are a
     * paid product (Arkham, Nansen) and are not guessed.
     *
     * RESPONSE SHAPE UNVERIFIED from the build environment; the Provider Probe
     * has an entry for it.
     */
    private function whales(Coin $coin): ?array
    {
        $slug = $this->slugFor($coin);
        $chain = (string) $coin->meta('defillamaChain', '');
        $min = (float) ($this->settings()['whale_min'][$chain] ?? 0);
        if ($slug === null || $min <= 0) {
            return null;
        }

        $isEth = $slug === 'ethereum';
        // Blockchair stores amounts in the chain's base unit: wei, satoshi.
        $unit = $isEth ? 1e18 : 1e8;
        $field = $isEth ? 'value' : 'output_total';
        $threshold = number_format($min * $unit, 0, '.', '');

        $rows = $this->get('/' . rawurlencode($slug) . '/transactions', [
            'q'     => $field . '(' . $threshold . '..)',
            's'     => 'id(desc)',
            'limit' => '8',
        ])['data'] ?? null;
        if (!is_array($rows)) {
            return null;
        }

        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r) || empty($r['hash']) || !isset($r[$field]) || !is_numeric($r[$field])) {
                continue;
            }
            $amount = ((float) $r[$field]) / $unit;
            if ($amount < $min) {
                continue;   // the filter is the provider's; the threshold is ours
            }
            $usdKey = $field . '_usd';
            $out[] = [
                'hash'   => (string) $r['hash'],
                'time'   => isset($r['time']) ? (string) $r['time'] : null,
                'amount' => $amount,
                'usd'    => isset($r[$usdKey]) && is_numeric($r[$usdKey]) ? (float) $r[$usdKey] : null,
                'from'   => $isEth && is_string($r['sender'] ?? null) ? $r['sender'] : null,
                'to'     => $isEth && is_string($r['recipient'] ?? null) ? $r['recipient'] : null,
            ];
        }

        return ['min' => $min, 'transfers' => $out];
    }
}
