<?php
/**
 * Etherscan — gas, and Ethereum's supply arithmetic.
 *
 * WHAT THIS REPLACED
 *
 * The first plan for net issuance was ultrasound.money. It was dropped: its API
 * is undocumented, has changed shape more than once, and would have been a
 * second unverifiable dependency for a number Etherscan already reports. The
 * ethsupply2 endpoint gives total supply, cumulative burn and the staking
 * balance in one response, from a key we are asking for anyway.
 *
 * WHAT IS AND IS NOT DERIVED HERE
 *
 * Burn and staking totals are CUMULATIVE since genesis, not rates. A daily burn
 * or an annual issuance rate cannot be computed from a single reading, and the
 * difference between "118,000 ETH burned" and "118,000 ETH burned per year" is
 * three orders of magnitude. So this collector returns the totals and the
 * staked share, and nothing that implies a rate. Pipeline's history table is
 * what will eventually make a rate honest, by comparing two readings.
 *
 * RESPONSE SHAPES ARE UNVERIFIED FROM THE BUILD ENVIRONMENT. Every outbound
 * host is blocked by the egress policy here, so the parsers below are written
 * against Etherscan's documented responses and every endpoint has a matching
 * entry in ProviderProbe so the real shapes can be confirmed on the server.
 * Nothing is assumed present: each field is checked before it is used.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\Collectors;

use TheHybit\Coins\Coin;

defined('ABSPATH') || exit;

final class Etherscan extends Collector
{
    /** Wei per ETH. Supply figures arrive in wei and overflow a float as ints. */
    private const WEI = 1e18;

    public function id(): string { return 'etherscan'; }

    public function datasets(): array { return ['gas', 'supply', 'ethlocations']; }

    public function fetch(Coin $coin, string $dataset): ?array
    {
        $chainId = $this->chainIdFor($coin);
        if ($chainId === null) {
            return null;   // a chain this explorer does not serve: never asked
        }

        return match ($dataset) {
            'gas'    => $this->gas($chainId),
            'supply' => $this->supply($chainId),
            'ethlocations' => $this->locations($chainId),
            default  => null,
        };
    }

    /** Etherscan's numeric id for the coin's chain, from configuration. */
    private function chainIdFor(Coin $coin): ?int
    {
        $map = (array) ($this->settings()['chainids'] ?? []);
        $chain = (string) $coin->meta('defillamaChain', '');
        return isset($map[$chain]) ? (int) $map[$chain] : null;
    }

    /**
     * Current gas prices, in gwei.
     *
     * The three tiers Etherscan publishes are what a wallet shows a user, so
     * they are passed through as they come rather than averaged into one
     * number that matches nothing anybody else displays.
     */
    private function gas(int $chainId): ?array
    {
        $d = $this->result($this->get('/api', ['chainid' => $chainId, 'module' => 'gastracker', 'action' => 'gasoracle']));
        if (!is_array($d)) {
            return null;
        }

        $num = static fn(string $k): ?float =>
            isset($d[$k]) && is_numeric($d[$k]) ? (float) $d[$k] : null;

        $out = [
            'safe'        => $num('SafeGasPrice'),
            'propose'     => $num('ProposeGasPrice'),
            'fast'        => $num('FastGasPrice'),
            'baseFee'     => $num('suggestBaseFee'),
            'lastBlock'   => isset($d['LastBlock']) && is_numeric($d['LastBlock'])
                ? (int) $d['LastBlock'] : null,
        ];

        return self::anyValue($out) ? $out : null;
    }

    /**
     * Total supply, cumulative burn and the staked balance.
     *
     * The staked SHARE is the one derived figure, and it is a ratio of two
     * numbers in the same response — no assumption, no second source. It is the
     * only part of the design's supply-distribution chart that has a credible
     * free source; the rest (held on exchanges, in DeFi, in bridges, in
     * long-term wallets) needs labelled-address data that only paid providers
     * sell, so the page reports those as unavailable rather than estimating.
     */
    private function supply(int $chainId): ?array
    {
        /* ethsupply2 is Ethereum-specific — burn and beacon-chain staking are
           Ethereum concepts — so it is asked only for chain id 1 even if more
           EVM chains are later mapped for gas. */
        if ($chainId !== 1) {
            return null;
        }
        $d = $this->result($this->get('/api', ['chainid' => $chainId, 'module' => 'stats', 'action' => 'ethsupply2']));
        if (!is_array($d)) {
            return null;
        }

        /* Wei values arrive as decimal STRINGS far beyond PHP's integer range.
           Casting to float loses precision in the last few digits, which at ETH
           scale is fractions of a token — irrelevant for display and the only
           option short of bcmath, which is not guaranteed on shared hosting. */
        $eth = static function (array $d, string $key): ?float {
            if (!isset($d[$key]) || !is_numeric($d[$key])) {
                return null;
            }
            return ((float) $d[$key]) / self::WEI;
        };

        /* WHAT THE FIELDS MEAN — Etherscan's documentation, not their names:
             EthSupply      ETH issued EXCLUDING staking rewards and burn
             Eth2Staking    cumulative beacon-chain staking REWARDS — not the
                            ETH that is staked (2.0.1 read it as the stake and
                            showed ~4.4M "staked" instead of ~34M)
             BurntFees      cumulative EIP-1559 burn
             WithdrawnTotal cumulative withdrawals from the beacon chain
           Current supply = EthSupply + Eth2Staking − BurntFees.
           The staked amount is NOT in this response; it comes from
           beaconcha.in (validators' total balance). */
        $base      = $eth($d, 'EthSupply');
        $rewards   = $eth($d, 'Eth2Staking');
        $burned    = $eth($d, 'BurntFees');
        $withdrawn = $eth($d, 'WithdrawnTotal');

        if ($base === null || $base <= 0.0) {
            return null;   // without a denominator nothing below means anything
        }
        $total = $base + ($rewards ?? 0.0) - ($burned ?? 0.0);

        return [
            'totalSupply'    => $total,
            'stakingRewards' => $rewards,
            'burnedTotal'    => $burned,
            'withdrawnTotal' => $withdrawn,
            'burnedPct'      => $burned !== null ? ($burned / $total) * 100 : null,
        ];
    }

    /**
     * Where part of the ETH supply sits, read from the chain (v2 supply card).
     *
     * Two measured figures, each with an exact meaning:
     *   - WETH's total supply: ETH wrapped into the ERC-20 that DeFi contracts
     *     trade and lend. It is ETH that has entered DeFi use, but not ALL ETH
     *     in DeFi (native ETH is also deposited), so the page names it as
     *     WETH rather than as "in DeFi".
     *   - the ETH balance of each configured canonical L2 bridge: ETH locked on
     *     Ethereum while its counterpart circulates on that rollup.
     * Two requests per hour, on the key already configured.
     */
    private function locations(int $chainId): ?array
    {
        if ($chainId !== 1) {
            return null;
        }
        $settings = $this->settings();
        $out = ['weth' => null, 'bridges' => [], 'bridgesTotal' => null];

        $weth = (string) ($settings['weth'] ?? '');
        if ($weth !== '') {
            $r = $this->result($this->get('/api', [
                'chainid' => $chainId, 'module' => 'stats', 'action' => 'tokensupply', 'contractaddress' => $weth,
            ]));
            $out['weth'] = is_numeric($r) ? ((float) $r) / self::WEI : null;
        }

        $bridges = (array) ($settings['bridges'] ?? []);
        $wallets = (array) ($settings['exchange_wallets'] ?? []);
        $out['exchanges'] = [];
        $out['exchangesTotal'] = null;
        $addresses = array_slice(array_values(array_unique(array_merge(array_values($bridges), array_values($wallets)))), 0, 20);
        if ($addresses !== []) {
            $rows = $this->result($this->get('/api', [
                'chainid' => $chainId, 'module' => 'account', 'action' => 'balancemulti',
                'address' => implode(',', $addresses), 'tag' => 'latest',
            ]));
            if (is_array($rows)) {
                $byAddress = [];
                foreach ($rows as $row) {
                    if (is_array($row) && isset($row['account'], $row['balance']) && is_numeric($row['balance'])) {
                        $byAddress[strtolower((string) $row['account'])] = ((float) $row['balance']) / self::WEI;
                    }
                }
                foreach ($bridges as $label => $address) {
                    $eth = $byAddress[strtolower((string) $address)] ?? null;
                    if ($eth !== null) {
                        $out['bridges'][] = ['label' => (string) $label, 'address' => (string) $address, 'eth' => $eth];
                    }
                }
                foreach ($wallets as $label => $address) {
                    $eth = $byAddress[strtolower((string) $address)] ?? null;
                    if ($eth !== null) {
                        $out['exchanges'][] = ['label' => (string) $label, 'exchange' => strtok((string) $label, ' '), 'address' => (string) $address, 'eth' => $eth];
                    }
                }
                if ($out['bridges'] !== []) {
                    $out['bridgesTotal'] = array_sum(array_column($out['bridges'], 'eth'));
                }
                if ($out['exchanges'] !== []) {
                    $out['exchangesTotal'] = array_sum(array_column($out['exchanges'], 'eth'));
                }
            }
        }

        return ($out['weth'] !== null || $out['bridgesTotal'] !== null) ? $out : null;
    }

    /**
     * Unwrap Etherscan's envelope.
     *
     * Every response is `{status, message, result}` and a FAILURE still arrives
     * as HTTP 200 — an invalid key, a spent rate limit and a malformed request
     * all return status "0" with the reason in `result` or `message`. Treating
     * the body as the answer would cache the string "Invalid API Key" as data,
     * so the envelope is checked before anything else looks at it.
     */
    private function result(?array $raw): mixed
    {
        if (!is_array($raw)) {
            return null;
        }

        $status = (string) ($raw['status'] ?? '');
        if ($status !== '1') {
            $reason = is_string($raw['result'] ?? null)
                ? $raw['result']
                : (string) ($raw['message'] ?? 'unknown error');

            /* A rate limit is worth a cooldown rather than a silent null: the
               next request would be refused too, and the budget cannot see a
               refusal that arrived as HTTP 200. */
            if (stripos($reason, 'rate limit') !== false || stripos($reason, 'max calls') !== false) {
                $this->budget()->startCooldown($this->id());
            }

            error_log('[thb] etherscan refused: ' . $reason);
            return null;
        }

        return $raw['result'] ?? null;
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
}
