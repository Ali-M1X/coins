<?php
/**
 * Nobitex — the USDT/RLS market, for the toman rate (2.13.0).
 *
 * One public, keyless request: GET /market/stats?srcCurrency=usdt&dstCurrency=rls.
 * The response carries one entry per requested market under `stats`, keyed
 * "usdt-rls", with prices as strings in RIAL:
 *
 *   { "status": "ok",
 *     "stats": { "usdt-rls": { "isClosed": false, "latest": "...",
 *                               "bestSell": "...", "bestBuy": "...", ... } } }
 *
 * NOT VERIFIED LIVE: the build sandbox cannot reach api.nobitex.ir, so this
 * shape is the one Nobitex documents publicly, parsed tolerantly; the Provider
 * Probe has an entry for it, so one button press on the production server
 * confirms it. Anything unrecognised yields null — no toman option rather than
 * a wrong one.
 *
 * What it gives is the price of one TETHER in toman, not of one dollar. The
 * converter divides it by Tether's own cached USD price, so the toman figure
 * per dollar is derived from two real prices, never by assuming 1 USDT = 1 USD.
 *
 * Site-scoped and fetched only by the scheduler (render => cache).
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\Collectors;

use TheHybit\Coins\Coin;

defined('ABSPATH') || exit;

final class Nobitex extends Collector
{
    public function id(): string { return 'nobitex'; }

    public function datasets(): array { return ['irt']; }

    public function fetch(Coin $coin, string $dataset): ?array
    {
        if ($dataset !== 'irt') {
            return null;
        }
        $raw = $this->get('/market/stats', ['srcCurrency' => 'usdt', 'dstCurrency' => 'rls']);
        $rial = is_array($raw) ? self::usdtRial($raw) : null;
        if ($rial === null) {
            return null;
        }
        return [
            'usdtToRial'  => $rial,
            'usdtToToman' => $rial / 10,
            'fetchedAt'   => gmdate('c'),
        ];
    }

    /** The last trade, else the middle of the best bid and ask; null when the market is closed or unreadable. */
    public static function usdtRial(array $raw): ?float
    {
        if (isset($raw['status']) && $raw['status'] !== 'ok') {
            return null;
        }
        $row = $raw['stats']['usdt-rls'] ?? $raw['stats']['usdt-irt'] ?? null;
        if (!is_array($row) || !empty($row['isClosed'])) {
            return null;
        }
        $num = static fn($v): ?float => is_numeric($v) && (float) $v > 0 ? (float) $v : null;
        $rate = $num($row['latest'] ?? null);
        if ($rate === null) {
            $sell = $num($row['bestSell'] ?? null);
            $buy  = $num($row['bestBuy'] ?? null);
            $rate = $sell !== null && $buy !== null ? ($sell + $buy) / 2 : null;
        }
        /* A sanity band, in rial: a figure outside it is a unit or parsing
           mistake (toman read as rial, a decimal shifted), not a price. */
        return $rate !== null && $rate >= 10_000 && $rate <= 100_000_000 ? $rate : null;
    }
}
