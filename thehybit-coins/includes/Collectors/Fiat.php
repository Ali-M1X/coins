<?php
/**
 * USD → EUR and GBP, for the converter (2.13.0).
 *
 * Two keyless daily sources, each its own provider so a failure, a budget and
 * a cooldown stay where they happen:
 *
 *   frankfurter  GET https://api.frankfurter.dev/v1/latest?base=USD&symbols=EUR,GBP
 *                { "amount": 1.0, "base": "USD", "date": "YYYY-MM-DD",
 *                  "rates": { "EUR": 0.9, "GBP": 0.8 } }
 *                ECB reference rates, published once per working day.
 *   erapi        GET https://open.er-api.com/v6/latest/USD
 *                { "result": "success", "base_code": "USD",
 *                  "time_last_update_unix": 1700000000, "rates": { "EUR": ..., "GBP": ... } }
 *                The fallback, used only when the first has no entry.
 *
 * NOT VERIFIED LIVE: neither host is reachable from the build sandbox. The
 * shapes above are the documented ones; the parser is tolerant and the
 * Provider Probe covers both. Rates are units of the currency per ONE dollar.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\Collectors;

use TheHybit\Coins\Coin;

defined('ABSPATH') || exit;

final class Fiat extends Collector
{
    public const CODES = ['EUR', 'GBP'];

    public function id(): string { return 'frankfurter'; }

    public function datasets(): array { return ['fiat']; }

    public function fetch(Coin $coin, string $dataset): ?array
    {
        if ($dataset !== 'fiat') {
            return null;
        }
        $raw = $this->get('/latest', ['base' => 'USD', 'symbols' => implode(',', self::CODES)]);
        if (!is_array($raw) || strtoupper((string) ($raw['base'] ?? 'USD')) !== 'USD') {
            return null;
        }
        $rates = self::rates($raw['rates'] ?? null);
        return $rates === [] ? null : ['rates' => $rates, 'date' => (string) ($raw['date'] ?? ''), 'fetchedAt' => gmdate('c')];
    }

    /** Only the codes we offer, only positive numbers in a plausible band (per dollar). */
    public static function rates($rates): array
    {
        $out = [];
        foreach (self::CODES as $code) {
            $v = is_array($rates) ? ($rates[$code] ?? null) : null;
            if (is_numeric($v) && (float) $v >= 0.2 && (float) $v <= 5) {
                $out[$code] = (float) $v;
            }
        }
        return $out;
    }
}
