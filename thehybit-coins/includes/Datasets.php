<?php
/**
 * The dataset registry — scope, priority, provider.
 *
 * WHY SCOPE IS A FIRST-CLASS IDEA
 *
 * Before this existed, every cache key was built by Coin::cacheKey(), which
 * embeds the coin slug and the CoinGecko id. That made every dataset per-coin
 * BY CONSTRUCTION, whether or not it had anything to do with a coin. The
 * USD->IRR rate is the proof: Fx.php says in its own docblock that the rate is
 * site-wide, and it was still being fetched once for Ethereum and again for
 * Bitcoin. At a hundred coins that is four hundred requests an hour for a single
 * exchange rate.
 *
 * Scope answers one question — what does this value actually vary with? — and
 * the answer decides whether a dataset costs one request or a hundred:
 *
 *   site    one value for the installation      1 request, ever
 *   chain   one value per blockchain            1 request per chain
 *   coin    genuinely per coin                  the only scope that scales
 *
 * PRIORITY exists because the production probe measured five CoinGecko requests
 * before a 429. Running short of budget is therefore the normal condition at
 * scale, not an exception, and something has to decide what gets refreshed
 * first. Lower number wins.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class Datasets
{
    public const SCOPE_SITE  = 'site';
    public const SCOPE_CHAIN = 'chain';
    public const SCOPE_COIN  = 'coin';

    /** Chart windows are registered individually; this is their shared prefix. */
    public const CHART_PREFIX = 'chart.';

    public function __construct(private array $config) {}

    /** @return array<string, array{provider:string,scope:string,priority:int,batch?:bool}> */
    public function all(): array
    {
        return (array) ($this->config['datasets'] ?? []);
    }

    public function exists(string $dataset): bool
    {
        return isset($this->config['datasets'][$dataset]);
    }

    public function scope(string $dataset): string
    {
        return (string) ($this->config['datasets'][$dataset]['scope'] ?? self::SCOPE_COIN);
    }

    public function provider(string $dataset): ?string
    {
        return $this->config['datasets'][$dataset]['provider'] ?? null;
    }

    /** Lower is more important. Unregistered datasets sort last. */
    public function priority(string $dataset): int
    {
        return (int) ($this->config['datasets'][$dataset]['priority'] ?? 9);
    }

    public function isBatched(string $dataset): bool
    {
        return !empty($this->config['datasets'][$dataset]['batch']);
    }

    /**
     * May a PAGE RENDER fetch this dataset, or only read what is cached?
     *
     * The Group A datasets are all 'cache'. Six extra provider calls in series
     * is not something a visitor should ever wait for, and against CoinGecko's
     * measured four-a-minute ceiling the first of them would succeed and the
     * rest would earn a 429 — turning one cold page view into a provider
     * cooldown for the whole site.
     *
     * So the scheduler fills them and the page reads them. The honest cost is
     * that a section says "not yet received" until the first tick covers it,
     * which is a truthful thing to say and a far better failure than a slow
     * page or a fabricated zero.
     */
    public function rendersFromCacheOnly(string $dataset): bool
    {
        return ($this->config['datasets'][$dataset]['render'] ?? 'fetch') === 'cache';
    }

    /**
     * Datasets can be switched off individually, not just by provider.
     *
     * `bridges` is the case that needs it: DefiLlama is healthy and serving
     * TVL, fees and chain data, while that one endpoint returned 407 from the
     * production server. Disabling the provider would lose four working
     * datasets to fix one broken one.
     */
    public function isEnabled(string $dataset): bool
    {
        return ($this->config['datasets'][$dataset]['enabled'] ?? true) !== false;
    }

    /**
     * The cache key fragment for one dataset, given a coin.
     *
     * This is the whole point of the class. A site-scoped dataset gets a key
     * with no coin in it at all, so every coin reads and writes the same entry —
     * which is what makes it one request instead of one per coin.
     *
     * A chain-scoped dataset is keyed by the chain name the coin declares, so
     * two coins on the same chain share a single entry. For Ethereum and Bitcoin
     * the chains differ, so nothing changes today; at a hundred coins, where
     * dozens share a chain, it is the difference between dozens of requests and
     * one.
     */
    public function scopeKey(string $dataset, ?Coin $coin = null): string
    {
        return match ($this->scope($dataset)) {
            self::SCOPE_SITE  => $dataset . ':site',
            self::SCOPE_CHAIN => $dataset . ':chain:' . self::chainOf($coin),
            default           => $coin ? $coin->cacheKey($dataset) : $dataset . ':unknown',
        };
    }

    /**
     * Which chain a coin's chain-scoped datasets belong to.
     *
     * The DefiLlama chain name is the identifier we already store for exactly
     * this purpose — a fifth identifier, configured per coin and never derived.
     * Falling back to the slug keeps a misconfigured coin isolated in its own
     * cache entry rather than silently sharing another chain's data, which would
     * be far worse than an extra request.
     */
    private static function chainOf(?Coin $coin): string
    {
        if (!$coin) {
            return 'unknown';
        }
        $chain = (string) $coin->meta('defillamaChain', '');
        return $chain !== '' ? $chain : $coin->slug;
    }

    /**
     * Every dataset a coin actually uses, cheapest question first.
     *
     * Respects both the global provider switch and the per-coin flag, so a coin
     * with DeFi turned off never appears in the scheduler's queue for it.
     *
     * @return array<int, string>
     */
    public function forCoin(Coin $coin): array
    {
        $out = [];
        foreach ($this->all() as $dataset => $spec) {
            $provider = $spec['provider'] ?? null;
            if ($provider === null || !$coin->usesProvider($provider, $this->config)) {
                continue;
            }
            if (!$this->isEnabled($dataset)) {
                continue;
            }
            $out[] = $dataset;
        }
        return $out;
    }
}
