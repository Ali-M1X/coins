<?php
/**
 * Alternative.me — the Fear & Greed index.
 *
 * WHAT IT IS, AND WHAT IT IS NOT
 *
 * A 0–100 reading of overall crypto-market sentiment, published once a day.
 * It is NOT an Ethereum metric and must never be presented as one: it says
 * something about the mood of the whole market, which is exactly why it earns a
 * place next to global market cap and dominance rather than next to a coin's own
 * figures. The dataset is site-scoped for the same reason — one reading serves
 * every coin on the installation, forever, at one request.
 *
 * WHY IT IS ITS OWN PROVIDER
 *
 * It could have been hung off an existing collector, but then a CoinGecko 429
 * would silence market sentiment too, and a slow sentiment endpoint would spend
 * CoinGecko's cooldown. Separate provider, separate budget, separate cooldown —
 * failures stay where they happen.
 *
 * The budget is deliberately tiny. The index is recomputed daily, so more than a
 * handful of requests an hour is asking for a number that cannot have changed.
 *
 * The endpoint was confirmed working by the production provider probe.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\Collectors;

use TheHybit\Coins\Coin;

defined('ABSPATH') || exit;

final class Alternative extends Collector
{
    /** Enough history for a short trend without carrying a year of noise. */
    private const HISTORY_DAYS = 8;

    public function id(): string { return 'alternative'; }

    public function datasets(): array { return ['sentiment']; }

    public function fetch(Coin $coin, string $dataset): ?array
    {
        if ($dataset !== 'sentiment') {
            return null;
        }

        $rows = $this->get('/fng/', ['limit' => self::HISTORY_DAYS, 'format' => 'json'])['data'] ?? null;
        if (!is_array($rows) || $rows === []) {
            return null;
        }

        /* Newest first, which is the order the endpoint documents — but the
           order is not relied upon: rows carry timestamps and are sorted by
           them, so a provider that changes its mind cannot silently invert the
           trend arrow. */
        $history = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['value']) || !is_numeric($row['value'])) {
                continue;
            }
            $history[] = [
                't'     => isset($row['timestamp']) && is_numeric($row['timestamp'])
                    ? (int) $row['timestamp']
                    : 0,
                'value' => (int) $row['value'],
                'label' => isset($row['value_classification'])
                    ? (string) $row['value_classification']
                    : '',
            ];
        }

        if ($history === []) {
            return null;
        }

        usort($history, static fn(array $a, array $b): int => $a['t'] <=> $b['t']);
        $latest = $history[count($history) - 1];

        // Yesterday's reading, when there is one. Absent rather than zero on a
        // first-ever fetch that returned a single row.
        $previous = count($history) > 1 ? $history[count($history) - 2]['value'] : null;

        return [
            'value'      => $latest['value'],
            'label'      => $latest['label'],
            'labelFa'    => self::persian($latest['label'], $latest['value']),
            'previous'   => $previous,
            'change'     => $previous !== null ? $latest['value'] - $previous : null,
            'weekAgo'    => count($history) >= 8 ? $history[count($history) - 8]['value'] : null,
            'history'    => array_map(static fn(array $r): int => $r['value'], $history),
            'updatedAt'  => $latest['t'],
        ];
    }

    /**
     * The Persian label.
     *
     * Translated from the VALUE rather than from the provider's English string,
     * so an unfamiliar classification still lands in the right band instead of
     * falling through to an empty label. The English text is kept alongside for
     * anyone checking the figure against the source.
     */
    private static function persian(string $label, int $value): string
    {
        return match (true) {
            $value <= 24 => 'ترس شدید',
            $value <= 44 => 'ترس',
            $value <= 55 => 'خنثی',
            $value <= 74 => 'طمع',
            default      => 'طمع شدید',
        };
    }
}
