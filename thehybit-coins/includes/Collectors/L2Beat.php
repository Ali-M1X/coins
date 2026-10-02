<?php
/**
 * L2BEAT — total value secured, stage, risk rows.
 *
 * Disabled in config for the validation phase. The unresolved question is not
 * technical: `scope` distinguishes an asset that IS an L2 (its own TVS and
 * stage) from one that HOSTS L2s (an ecosystem aggregate). The same labels mean
 * different things, so until that is defined the section renders for neither
 * Ethereum nor Bitcoin.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\Collectors;

use TheHybit\Coins\Coin;

defined('ABSPATH') || exit;

final class L2Beat extends Collector
{
    public function id(): string { return 'l2beat'; }

    public function datasets(): array { return ['l2']; }

    public function fetch(Coin $coin, string $dataset): ?array
    {
        if ($dataset !== 'l2') {
            return null;
        }

        // Only assets explicitly marked as an L2 chain in their configuration.
        $scope = (string) $coin->meta('l2Scope', '');
        if ($scope !== 'chain') {
            return null;
        }

        $slug = (string) $coin->meta('l2beatSlug', $coin->slug);
        $raw = $this->get('/scaling/summary');
        $projects = $raw['projects'] ?? [];
        $project = $projects[$slug] ?? null;
        if (!is_array($project)) {
            return null;
        }

        return [
            'scope' => 'chain',
            'tvs'   => isset($project['tvs']) ? (float) $project['tvs'] : null,
            'tps'   => isset($project['tps']) ? (float) $project['tps'] : null,
            'stage' => $project['stage'] ?? null,
            'risks' => $project['risks'] ?? [],
            'url'   => 'https://l2beat.com/scaling/projects/' . rawurlencode($slug),
        ];
    }
}
