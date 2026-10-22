<?php
/**
 * open.er-api.com — the fallback for EUR and GBP (2.13.0). See Fiat.php.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\Collectors;

use TheHybit\Coins\Coin;

defined('ABSPATH') || exit;

final class ErApi extends Collector
{
    public function id(): string { return 'erapi'; }

    public function datasets(): array { return ['fiatalt']; }

    public function fetch(Coin $coin, string $dataset): ?array
    {
        if ($dataset !== 'fiatalt') {
            return null;
        }
        $raw = $this->get('/v6/latest/USD');
        if (!is_array($raw) || ($raw['result'] ?? '') !== 'success' || strtoupper((string) ($raw['base_code'] ?? '')) !== 'USD') {
            return null;
        }
        $rates = Fiat::rates($raw['rates'] ?? null);
        $at = is_numeric($raw['time_last_update_unix'] ?? null) ? gmdate('Y-m-d', (int) $raw['time_last_update_unix']) : '';
        return $rates === [] ? null : ['rates' => $rates, 'date' => $at, 'fetchedAt' => gmdate('c')];
    }
}
