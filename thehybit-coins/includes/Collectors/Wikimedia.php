<?php
/**
 * Wikipedia page views — the public-interest figure.
 *
 * Google Trends, which the reference design's "search interest" implies, has
 * no API at all; the unofficial scrapers break and violate its terms. The
 * Wikimedia REST API is official, free and keyless, and how many people read
 * a coin's encyclopedia article is a direct, auditable measure of attention.
 * Both the Persian and the English article are counted, separately: for an
 * Iranian audience the Persian figure is the more telling one.
 *
 * Article titles: English from the coin's ACF field `thb_wikipedia`, Persian
 * from its Persian name. A title that does not exist returns 404 for that
 * language only; the other still counts.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\Collectors;

use TheHybit\Coins\Coin;

defined('ABSPATH') || exit;

final class Wikimedia extends Collector
{
    public function id(): string { return 'wikimedia'; }

    public function datasets(): array { return ['interest']; }

    public function fetch(Coin $coin, string $dataset): ?array
    {
        if ($dataset !== 'interest') {
            return null;
        }

        $articles = array_filter([
            'fa' => trim($coin->name),
            'en' => trim((string) $coin->meta('wikipedia', '')),
        ]);

        // Fifteen full days ending yesterday: two complete weeks, compared.
        $end = gmdate('Ymd', time() - DAY_IN_SECONDS);
        $start = gmdate('Ymd', time() - 15 * DAY_IN_SECONDS);

        $out = [];
        foreach ($articles as $lang => $title) {
            try {
                $raw = $this->get(sprintf(
                    '/metrics/pageviews/per-article/%s.wikipedia/all-access/user/%s/daily/%s00/%s00',
                    $lang,
                    rawurlencode(str_replace(' ', '_', $title)),
                    $start,
                    $end
                ));
            } catch (\Throwable $e) {
                continue;   // one language missing must not cost the other
            }
            $views = [];
            foreach ((array) ($raw['items'] ?? []) as $item) {
                if (is_array($item) && isset($item['views']) && is_numeric($item['views'])) {
                    $views[] = (int) $item['views'];
                }
            }
            if (count($views) < 14) {
                continue;   // two full weeks or nothing: a partial week compares nothing
            }
            $views = array_slice($views, -14);
            $last = array_sum(array_slice($views, 7));
            $prev = array_sum(array_slice($views, 0, 7));
            $out[$lang] = [
                'title'   => $title,
                'last7'   => $last,
                'prev7'   => $prev,
                'change'  => $prev > 0 ? ($last - $prev) / $prev * 100 : null,
                'daily'   => array_slice($views, 7),
            ];
        }

        return $out === [] ? null : $out;
    }
}
