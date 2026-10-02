<?php
/**
 * beaconcha.in — Ethereum's validator set and staking yield.
 *
 * TWO ENDPOINTS, AND WHY THE SECOND ONE IS OPTIONAL
 *
 * /epoch/latest gives the validator count and participation, and is the one
 * figure the design's Network Pulse needs. /ethstore/{day} gives the realised
 * staking APR, which is the input to the Real Yield panel.
 *
 * The APR is fetched separately and allowed to fail without taking the
 * validator figures with it. That is not defensive padding: ethstore has moved
 * and been gated before, and a page that loses its validator count because an
 * unrelated endpoint changed would be a worse outcome than one that shows the
 * yield as unavailable.
 *
 * THE APR IS NOT COMPUTED HERE
 *
 * It would be easy to approximate one from issuance and total stake, and it
 * would be wrong — realised yield includes execution-layer tips and MEV, which
 * no formula over supply figures can see. If the endpoint does not give it, the
 * page says so. An approximate yield presented as a yield is the kind of number
 * somebody makes a decision on.
 *
 * RESPONSE SHAPES ARE UNVERIFIED FROM THE BUILD ENVIRONMENT; see Etherscan.php.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\Collectors;

use TheHybit\Coins\Coin;

defined('ABSPATH') || exit;

final class BeaconChain extends Collector
{
    /** Gwei per ETH — balances arrive in gwei, not wei. */
    private const GWEI = 1e9;

    public function id(): string { return 'beaconchain'; }

    public function datasets(): array { return ['staking']; }

    public function fetch(Coin $coin, string $dataset): ?array
    {
        if ($dataset !== 'staking') {
            return null;
        }

        $epoch = $this->data($this->get('/epoch/latest'));
        if (!is_array($epoch)) {
            return null;
        }

        $num = static fn(array $d, string $k): ?float =>
            isset($d[$k]) && is_numeric($d[$k]) ? (float) $d[$k] : null;

        $validators = $num($epoch, 'validatorscount');
        $balance    = $num($epoch, 'totalvalidatorbalance');

        $out = [
            'validators'    => $validators !== null ? (int) $validators : null,
            'stakedEth'     => $balance !== null ? $balance / self::GWEI : null,
            'epoch'         => $num($epoch, 'epoch') !== null ? (int) $num($epoch, 'epoch') : null,
            'finalized'     => isset($epoch['finalized']) ? (bool) $epoch['finalized'] : null,
            /* Published as a fraction; shown as a percentage. Both exist in the
               wild for this field, so it is range-checked rather than assumed:
               a value above 1 is already a percentage. */
            'participation' => self::asPercent($num($epoch, 'globalparticipationrate')),
            'apr'           => null,
        ];

        $out['apr'] = $this->apr();

        return $out;
    }

    /**
     * Realised staking APR, or null.
     *
     * Deliberately swallows its own failure. The validator figures above are
     * already in hand by this point and must not be lost because this endpoint
     * is gated, moved or slow.
     */
    private function apr(): ?float
    {
        try {
            $store = $this->data($this->get('/ethstore/latest'));
        } catch (\Throwable $e) {
            error_log('[thb] beaconchain: staking APR unavailable — ' . $e->getMessage());
            return null;
        }

        if (!is_array($store)) {
            return null;
        }

        // The field has appeared as both `apr` and `effective_apr`; neither is
        // assumed, and a value arriving as a fraction is converted once.
        foreach (['apr', 'effective_apr', 'cl_apr'] as $field) {
            if (isset($store[$field]) && is_numeric($store[$field])) {
                return self::asPercent((float) $store[$field]);
            }
        }

        return null;
    }

    /**
     * A rate that may arrive as a fraction or as a percentage.
     *
     * 0.032 and 3.2 mean the same thing here, and rendering the first as "0.03%"
     * would understate a staking yield by a factor of a hundred. Anything at or
     * below 1 is treated as a fraction, which is safe for every rate this
     * collector handles — a 100% participation rate reads as 1.0 and a 100% APR
     * does not occur.
     */
    private static function asPercent(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }
        return $value <= 1.0 ? $value * 100 : $value;
    }

    /**
     * Unwrap beaconcha.in's envelope.
     *
     * `{status: "OK", data: {...}}`, where a failure is `status: "ERROR"` with
     * the reason in `data` as a string. Checking the envelope stops an error
     * message being cached as though it were a validator count.
     */
    private function data(?array $raw): mixed
    {
        if (!is_array($raw)) {
            return null;
        }
        if (($raw['status'] ?? '') !== 'OK') {
            error_log('[thb] beaconchain refused: ' . (is_string($raw['data'] ?? null)
                ? $raw['data'] : (string) ($raw['status'] ?? 'unknown')));
            return null;
        }
        return $raw['data'] ?? null;
    }
}
