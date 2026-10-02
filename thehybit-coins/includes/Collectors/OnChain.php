<?php
/**
 * On-chain activity — active addresses, transactions, new addresses.
 *
 * Disabled in config: every free source for this needs an API key, and none was
 * issued for the validation phase. The collector exists so the section can be
 * switched on with a key and no other change; until then `onchain` stays null
 * and the section is absent, which is the behaviour the UI already handles.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\Collectors;

use TheHybit\Coins\Coin;

defined('ABSPATH') || exit;

final class OnChain extends Collector
{
    public function id(): string { return 'onchain'; }

    public function datasets(): array { return ['onchain']; }

    public function fetch(Coin $coin, string $dataset): ?array
    {
        if ($dataset !== 'onchain' || empty($this->settings()['base'])) {
            return null;
        }

        $queryId = $coin->meta('duneQueryId');
        if (!$queryId) {
            return null;
        }

        $raw = $this->get('/query/' . rawurlencode((string) $queryId) . '/results');
        $row = $raw['result']['rows'][0] ?? null;
        if (!is_array($row)) {
            return null;
        }

        return [
            'activeAddresses24h' => isset($row['active_addresses']) ? (int) $row['active_addresses'] : null,
            'transactions24h'    => isset($row['transactions']) ? (int) $row['transactions'] : null,
            'newAddresses24h'    => isset($row['new_addresses']) ? (int) $row['new_addresses'] : null,
        ];
    }
}
