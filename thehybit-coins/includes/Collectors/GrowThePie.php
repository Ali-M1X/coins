<?php
/**
 * growthepie — daily active addresses on Ethereum mainnet. Keyless.
 *
 * Replaces CoinMetrics' AdrActCnt, which refuses this site's server by
 * location. growthepie publishes its fundamentals as one flat export:
 *
 *   GET https://api.growthepie.com/v1/fundamentals.json
 *   [{"metric_key":"daa","origin_key":"ethereum","date":"2026-10-08","value":512345.0}, …]
 *
 * Every chain and metric for the last ~90 days, so the payload is a few MB;
 * it is fetched a few times a day by the scheduler, never by a page view,
 * and only the Ethereum `daa` rows are kept. Public rate guidance: ≤ 10
 * calls a minute.
 *
 * RESPONSE SHAPE from growthepie's documentation, not yet confirmed from the
 * production server; the Provider Probe has an entry for it.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\Collectors;

use TheHybit\Coins\Coin;

defined('ABSPATH') || exit;

final class GrowThePie extends Collector
{
    public function id(): string { return 'growthepie'; }

    public function datasets(): array { return ['activity']; }

    public function fetch(Coin $coin, string $dataset): ?array
    {
        if ($dataset !== 'activity') {
            return null;
        }
        $origin = $this->settings()['origins'][(string) $coin->meta('defillamaChain', '')] ?? null;
        if ($origin === null) {
            return null;   // a chain growthepie does not cover
        }
        return self::extract((array) $this->get('/fundamentals.json'), (string) $origin,
            (string) ($this->settings()['metric'] ?? 'daa'));
    }

    /**
     * {latest, date, weekAgo, series} — the same shape the model reads from
     * CoinMetrics, so either source fills the same rows.
     */
    public static function extract(array $rows, string $origin, string $metric): array
    {
        $rows = isset($rows['data']) && is_array($rows['data']) ? $rows['data'] : $rows;
        $series = [];
        foreach ($rows as $r) {
            if (!is_array($r) || ($r['origin_key'] ?? null) !== $origin || ($r['metric_key'] ?? null) !== $metric) {
                continue;
            }
            $date = substr((string) ($r['date'] ?? ''), 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && is_numeric($r['value'] ?? null) && (float) $r['value'] > 0) {
                $series[$date] = (float) $r['value'];
            }
        }
        if ($series === []) {
            throw new \RuntimeException(sprintf('no "%s" rows for "%s" in fundamentals.json (%d rows read)', $metric, $origin, count($rows)));
        }
        ksort($series);
        $date = array_key_last($series);
        $weekAgo = gmdate('Y-m-d', strtotime($date . ' UTC') - 7 * 86400);

        return [
            'latest'  => $series[$date],
            'date'    => $date,
            'weekAgo' => $series[$weekAgo] ?? null,
            'series'  => array_slice($series, -30, null, true),
        ];
    }
}
