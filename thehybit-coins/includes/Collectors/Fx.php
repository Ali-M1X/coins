<?php
/**
 * USD -> IRR / Toman.
 *
 * Abstracted behind its own collector because Iranian FX sources are unreliable
 * and change often; we fully expect to replace PersianToolbox. Swapping it means
 * a new class here and one line in the registry — the template never learns
 * where the rate came from.
 *
 * What is stored alongside the rate matters as much as the rate: source,
 * timestamp and freshness, so a page can say how current its Toman figure is
 * rather than implying it is live.
 *
 * Note on units: Iranian prices are quoted in TOMAN colloquially and RIAL
 * officially (1 toman = 10 rial). Both are stored explicitly so no downstream
 * code has to guess which one it received.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\Collectors;

use TheHybit\Coins\Coin;

defined('ABSPATH') || exit;

final class Fx extends Collector
{
    public function id(): string { return 'persiantoolbox'; }

    public function datasets(): array { return ['fx']; }

    /** FX is site-wide, not per coin; $coin is accepted only for interface parity. */
    public function fetch(Coin $coin, string $dataset): ?array
    {
        if ($dataset !== 'fx') {
            return null;
        }

        $raw = $this->get('/market/currency');
        if (!$raw) {
            return null;
        }

        $rial = self::extractUsdRial($raw);
        if ($rial === null || $rial <= 0) {
            // Better no Toman line than a wrong one.
            return null;
        }

        return [
            'source'       => $this->label(),
            'usdToRial'    => $rial,
            'usdToToman'   => $rial / 10,
            'fetchedAt'    => gmdate('c'),
            'freshness'    => 'live',
            'providerId'   => $this->id(),
        ];
    }

    /**
     * Pull the USD rate out of whatever shape the endpoint returns.
     *
     * Deliberately tolerant: this API's response shape is not contractual to us
     * and has no versioning. Several plausible layouts are probed, and an
     * unrecognised one yields null rather than a wrong number.
     */
    private static function extractUsdRial(array $raw): ?float
    {
        // { data: [ { symbol: "USD", price: 830000 }, ... ] }
        foreach (['data', 'result', 'currencies', 'items'] as $key) {
            if (!isset($raw[$key]) || !is_array($raw[$key])) {
                continue;
            }
            foreach ($raw[$key] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $symbol = strtoupper((string) ($row['symbol'] ?? $row['code'] ?? $row['slug'] ?? ''));
                if ($symbol === 'USD' || $symbol === 'USD_SELL' || $symbol === 'DOLLAR') {
                    foreach (['price', 'value', 'sell', 'rate'] as $field) {
                        if (isset($row[$field]) && is_numeric($row[$field])) {
                            return (float) $row[$field];
                        }
                    }
                }
            }
        }

        // { usd: 830000 }
        foreach (['usd', 'USD', 'dollar'] as $key) {
            if (isset($raw[$key]) && is_numeric($raw[$key])) {
                return (float) $raw[$key];
            }
        }

        return null;
    }
}
