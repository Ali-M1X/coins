<?php
/**
 * DefiLlama's price API (coins.llama.fi) — the multi-year price history.
 *
 * Its own provider rather than a DefiLlama dataset for one practical reason:
 * a coin's "DeFi" switch turns the DefiLlama provider off for that coin, and
 * Bitcoin has it off. Price history has nothing to do with DeFi, so it must not
 * disappear with it. Same operator, separate budget — a refusal here costs the
 * story chart and nothing else.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\Collectors;

use TheHybit\Coins\Coin;

defined('ABSPATH') || exit;

final class LlamaPrices extends Collector
{
    public function id(): string { return 'llamaprices'; }

    public function datasets(): array { return ['longchart']; }

    public function fetch(Coin $coin, string $dataset): ?array
    {
        return $dataset === 'longchart' ? $this->longchart($coin) : null;
    }

    /**
     * Weekly price since launch, from DefiLlama's price API.
     *
     * CoinGecko's keyless tier caps history at 365 days, which cannot tell a
     * story that starts in 2015. coins.llama.fi serves CoinGecko-sourced prices
     * for any date at no cost, so the long view comes from here — one request a
     * day per coin.
     *
     * RESPONSE SHAPE UNVERIFIED from the build environment (egress is blocked);
     * ProviderProbe has an entry for it so it can be confirmed on the server.
     */
    private function longchart(Coin $coin): ?array
    {
        if ($coin->coingeckoId === '') {
            return null;
        }

        try {
            return $this->fromLlama($coin);
        } catch (\Throwable $llama) {
            /* Kraken's public OHLC, as the second source: keyless, documented,
               and its ETH/USD and XBT/USD weekly candles go back to 2015 —
               within a week of Ethereum's first trading. */
            $pair = (array) ($this->settings()['kraken_pairs'] ?? []);
            $code = $pair[$coin->coingeckoId] ?? null;
            if ($code === null) {
                throw $llama;
            }
            try {
                return $this->fromKraken((string) $code);
            } catch (\Throwable $kraken) {
                throw new \RuntimeException('DefiLlama: ' . $llama->getMessage() . ' | Kraken: ' . $kraken->getMessage());
            }
        }
    }

    /** DefiLlama prices, one point every two weeks since launch (~290 points). */
    private function fromLlama(Coin $coin): array
    {
        $launch = strtotime((string) $coin->meta('launchDate', '')) ?: (time() - 8 * YEAR_IN_SECONDS);
        $start = max($launch, 1438387200);               // no price exists before Aug 2015
        $span = min(400, (int) ceil((time() - $start) / (2 * WEEK_IN_SECONDS)));
        if ($span < 2) {
            throw new \RuntimeException('coin too young for a long chart');
        }

        /* The key goes in the path AS IS. 2.0.1 percent-encoded its colon
           ("coingecko%3Aethereum"), which this API does not decode: the request
           matched no coin and every refresh came back empty. */
        $key = 'coingecko:' . $coin->coingeckoId;
        $d = $this->get('/chart/' . $key, [
            'start'       => $start,
            'span'        => $span,
            'period'      => '2w',
            'searchWidth' => '600',
        ]);

        $rows = $d['coins'][$key]['prices'] ?? null;
        if (!is_array($rows)) {
            throw new \RuntimeException('no "coins.' . $key . '.prices" in the response (keys: ' . implode(',', array_keys((array) ($d['coins'] ?? $d ?? []))) . ')');
        }
        return self::columns(array_map(static fn($p) => [$p['timestamp'] ?? null, $p['price'] ?? null], $rows));
    }

    /** Kraken weekly candles: [time, open, high, low, close, vwap, volume, count]. */
    private function fromKraken(string $pair): array
    {
        $d = $this->get('/OHLC', ['pair' => $pair, 'interval' => '10080'], 'kraken');
        if (!empty($d['error'])) {
            throw new \RuntimeException(implode('; ', (array) $d['error']));
        }
        $result = (array) ($d['result'] ?? []);
        unset($result['last']);
        $rows = reset($result);
        if (!is_array($rows)) {
            throw new \RuntimeException('no candles in the response');
        }
        return self::columns(array_map(static fn($c) => [$c[0] ?? null, $c[4] ?? null], $rows));
    }

    /** @param array<int, array{0:mixed,1:mixed}> $points @return array{t:int[], price:float[]} */
    private static function columns(array $points): array
    {
        $t = $price = [];
        foreach ($points as [$ts, $p]) {
            if (is_numeric($ts) && is_numeric($p) && (float) $p > 0) {
                $t[] = (int) $ts;
                $price[] = round((float) $p, 6);
            }
        }
        if (count($t) < 2) {
            throw new \RuntimeException('fewer than two usable price points');
        }
        return ['t' => $t, 'price' => $price];
    }
}
