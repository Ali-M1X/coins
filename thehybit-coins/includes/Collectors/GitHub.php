<?php
/**
 * GitHub — developer activity.
 *
 * WHAT "DEVELOPER ACTIVITY" MEANS HERE
 *
 * Commits to the project's main repository over the last four weeks, plus the
 * number of people who made them. Nothing more ambitious, because nothing more
 * ambitious is both free and honest: a true measure would span every repository
 * in an organisation, weight them, and exclude bots, and the free API gives
 * neither the quota nor the signal for that.
 *
 * So the figure is labelled for what it is — one repository, four weeks — and
 * the comparison it supports in the design's peer table is between coins
 * measured the same way, which is the only comparison it can honestly support.
 *
 * THE REPOSITORY IS CONFIGURED, NOT GUESSED
 *
 * It comes from the GitHub link CoinGecko already returns in the coin-detail
 * response, or from an ACF field when an editor overrides it. Deriving it from
 * the coin slug would silently measure the wrong project — there is a
 * `github.com/bitcoin/bitcoin` and a `github.com/ethereum/go-ethereum`, and no
 * rule connects either to its slug.
 *
 * WITHOUT A TOKEN this works at 60 requests an hour, shared with everything else
 * on the server's IP. With one it is 5,000. The budget assumes the lower figure
 * so behaviour does not change when a token is added — only the headroom does.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\Collectors;

use TheHybit\Coins\Coin;

defined('ABSPATH') || exit;

final class GitHub extends Collector
{
    public function id(): string { return 'github'; }

    public function datasets(): array { return ['development']; }

    public function fetch(Coin $coin, string $dataset): ?array
    {
        if ($dataset !== 'development') {
            return null;
        }

        $repo = self::repoOf($coin);
        if ($repo === null) {
            return null;   // no repository configured; the section says so
        }

        $d = $this->get('/repos/' . $repo);
        if (!is_array($d) || !isset($d['full_name'])) {
            return null;
        }

        $out = [
            'repo'        => (string) $d['full_name'],
            'url'         => (string) ($d['html_url'] ?? 'https://github.com/' . $repo),
            'stars'       => isset($d['stargazers_count']) ? (int) $d['stargazers_count'] : null,
            'forks'       => isset($d['forks_count']) ? (int) $d['forks_count'] : null,
            'openIssues'  => isset($d['open_issues_count']) ? (int) $d['open_issues_count'] : null,
            'language'    => isset($d['language']) && is_string($d['language']) ? $d['language'] : null,
            'pushedAt'    => isset($d['pushed_at']) && is_string($d['pushed_at']) ? $d['pushed_at'] : null,
            'commits4w'   => null,
            'contributors4w' => null,
        ];

        /* The activity figures come from a second endpoint and are allowed to
           fail on their own. GitHub computes these statistics lazily and
           answers 202 with an empty body while it does, which is not an error
           and must not cost the repository figures already in hand. */
        $activity = $this->activity($repo);
        if ($activity !== null) {
            $out['commits4w'] = $activity['commits'];
            $out['contributors4w'] = $activity['contributors'];
        }

        return $out;
    }

    /**
     * Commits and distinct authors over the last four weeks.
     *
     * /stats/participation would be cheaper but counts only the default branch
     * and gives no author breakdown. Listing commits with a since-date gives
     * both, at one request, and the page size caps what a very busy repository
     * can cost us.
     */
    private function activity(string $repo): ?array
    {
        try {
            $since = gmdate('c', time() - 28 * DAY_IN_SECONDS);
            $commits = $this->get('/repos/' . $repo . '/commits', [
                'since'    => $since,
                'per_page' => 100,
            ]);
        } catch (\Throwable $e) {
            error_log('[thb] github: activity unavailable for ' . $repo . ' — ' . $e->getMessage());
            return null;
        }

        if (!is_array($commits)) {
            return null;
        }

        $authors = [];
        $counted = 0;

        foreach ($commits as $commit) {
            if (!is_array($commit)) {
                continue;
            }
            $counted++;

            /* `author` is the GitHub account and is null for commits whose email
               matches no account; `commit.author.name` is always present. Using
               the login where there is one and the name otherwise avoids
               collapsing every unmatched contributor into a single null bucket. */
            $login = $commit['author']['login']
                ?? $commit['commit']['author']['name']
                ?? null;

            if (is_string($login) && $login !== '') {
                $authors[$login] = true;
            }
        }

        if ($counted === 0) {
            return ['commits' => 0, 'contributors' => 0];   // a real, measured zero
        }

        return [
            /* At the page limit this is a floor, not a count, and the view model
               is what says so. Reporting "100" as though it were exact would be
               wrong for precisely the busiest projects. */
            'commits'      => $counted,
            'contributors' => count($authors),
            'capped'       => $counted >= 100,
        ];
    }

    /**
     * owner/repo for a coin, from configuration rather than convention.
     *
     * An explicit ACF value wins; otherwise the GitHub URL CoinGecko returned
     * in the coin-detail response. Anything that is not a github.com repository
     * URL is rejected rather than half-parsed.
     */
    private static function repoOf(Coin $coin): ?string
    {
        $candidates = array_filter([
            (string) $coin->meta('githubRepo', ''),
            (string) $coin->meta('social.github', ''),
        ]);

        foreach ($candidates as $candidate) {
            // Already in owner/repo form.
            if (preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $candidate)) {
                return $candidate;
            }
            if (preg_match('#github\.com/([A-Za-z0-9._-]+)/([A-Za-z0-9._-]+?)(?:\.git)?/?$#', $candidate, $m)) {
                return $m[1] . '/' . $m[2];
            }
        }

        return null;
    }
}
