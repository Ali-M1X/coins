<?php
/**
 * Lido — the stETH staking APR, 7-day moving average. Keyless.
 *
 * Measured, not modelled: Lido publishes the yield its stakers actually
 * received (consensus plus execution rewards, after Lido's fee). It is ONE
 * provider's yield, not the network average, and the page labels it so. It is
 * shown only when beaconcha.in's network-wide figure is unavailable.
 *
 * RESPONSE SHAPE UNVERIFIED from the build environment; the Provider Probe
 * has an entry for it.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\Collectors;

use TheHybit\Coins\Coin;

defined('ABSPATH') || exit;

final class Lido extends Collector
{
    public function id(): string { return 'lido'; }

    public function datasets(): array { return ['lidoapr']; }

    public function fetch(Coin $coin, string $dataset): ?array
    {
        if ($dataset !== 'lidoapr') {
            return null;
        }
        $d = $this->get('/protocol/steth/apr/sma')['data'] ?? null;
        $apr = is_array($d) && isset($d['smaApr']) && is_numeric($d['smaApr']) ? (float) $d['smaApr'] : null;

        if ($apr === null) {
            throw new \RuntimeException('no data.smaApr in the response');
        }
        // A staking yield outside 0–20% is a unit or parsing problem, not news.
        if ($apr <= 0 || $apr > 20) {
            throw new \RuntimeException('implausible APR ' . $apr . ' — rejected');
        }
        return ['smaApr' => $apr];
    }
}
