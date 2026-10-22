<?php
/**
 * Coin comparison (2.11.0) — /coins/compare/?c=bitcoin,ethereum[,solana].
 *
 * Two or three of our coin pages side by side, from the very models those
 * pages are drawn from (Plugin::v2() and the pipeline's view model). Both
 * read the cache only, so a comparison never reaches a provider. A field a
 * coin has no real source for is a dash — the same rule as its own page —
 * never a number made up to fill the cell.
 *
 * Neutral by design: no "winner" column and no colour for "better". Which
 * is preferable depends on what the reader wants; the table shows the
 * figures and where they come from.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\V2;

use TheHybit\Coins\Categories;
use TheHybit\Coins\Coin;
use TheHybit\Coins\CoinRepository;
use TheHybit\Coins\Format;
use TheHybit\Coins\Plugin;

defined('ABSPATH') || exit;

final class Compare
{
    public const MAX = 3;

    public function __construct(private Plugin $plugin) {}

    /**
     * The slugs asked for, valid and distinct, in order: ?c=a,b,c or (the
     * form without script) ?c1=&c2=&c3=.
     *
     * @return string[]
     */
    public static function requested(CoinRepository $coins): array
    {
        $raw = [];
        if (isset($_GET['c'])) {
            $raw = explode(',', (string) wp_unslash($_GET['c']));
        }
        foreach (['c1', 'c2', 'c3'] as $k) {
            if (isset($_GET[$k])) {
                $raw[] = (string) wp_unslash($_GET[$k]);
            }
        }
        $known = [];
        foreach ($coins->all() as $coin) {
            $known[$coin->slug] = true;
        }
        $out = [];
        foreach ($raw as $s) {
            $s = sanitize_title($s);
            if ($s !== '' && isset($known[$s]) && !in_array($s, $out, true)) {
                $out[] = $s;
            }
        }
        return array_slice($out, 0, self::MAX);
    }

    /** /coins/compare/?c=a,b — the shareable address of a comparison. */
    public static function url(array $slugs): string
    {
        $base = trailingslashit(home_url('/coins/compare'));
        return $slugs === [] ? $base : $base . '?c=' . implode(',', array_map('rawurlencode', $slugs));
    }

    /**
     * Columns (one per coin) and rows (one per figure).
     *
     * @param Coin[] $coins
     * @return array{cols: array<int, array>, groups: array<int, array{title:string, rows: array<int, array{label:string, cells: string[], note:?string}>}>}
     */
    public function build(array $coins): array
    {
        /* Nothing on this page may reach a provider: the models are built
           from the cache as it stands, stale or not. */
        $was = \TheHybit\Coins\Pipeline::$cacheOnly;
        \TheHybit\Coins\Pipeline::$cacheOnly = true;
        try {
            return $this->table($coins);
        } finally {
            \TheHybit\Coins\Pipeline::$cacheOnly = $was;
        }
    }

    private function table(array $coins): array
    {
        $cols = [];
        $data = [];
        foreach ($coins as $coin) {
            $v = $this->plugin->v2($coin);
            $m = $this->plugin->pipeline->viewModel($coin);
            $cat = Categories::of($coin);
            $cols[] = [
                'slug' => $coin->slug, 'name' => $coin->name, 'symbol' => strtoupper($coin->symbol),
                'url' => (string) get_permalink($coin->postId), 'logo' => $v['coin']['logo'] ?? null,
                'category' => $cat['fa'] ?? null,
            ];
            $data[] = ['v' => $v, 'm' => $m, 'cat' => $cat];
        }

        $usd = static fn($x) => isset($x) && is_numeric($x) ? Format::usd((float) $x) : null;
        $big = static fn($x) => isset($x) && is_numeric($x) ? Format::usdCompact((float) $x, 2) : null;
        $pct = static fn($x) => isset($x) && is_numeric($x) ? Format::pct((float) $x, true, 2) : null;
        $supply = static fn($x, string $sym) => isset($x) && is_numeric($x) ? Format::compact((float) $x) . ' ' . $sym : null;
        $row = static function (string $label, callable $f, ?string $note = null) use ($data, $cols): array {
            $cells = [];
            foreach ($data as $i => $d) {
                $val = $f($d, $cols[$i]);
                $cells[] = $val === null || $val === '' ? '—' : (string) $val;
            }
            return ['label' => $label, 'cells' => $cells, 'note' => $note];
        };

        $groups = [];
        $groups[] = ['title' => 'بازار', 'rows' => [
            $row('قیمت', static fn($d) => $usd($d['v']['hero']['price'] ?? null)),
            $row('تغییر ۲۴ ساعته', static fn($d) => $pct($d['v']['hero']['change24h'] ?? null)),
            $row('ارزش بازار', static fn($d) => $big($d['v']['hero']['marketCap'] ?? null)),
            $row('رتبه ارزش بازار', static fn($d) => isset($d['m']['coin']['rank']) ? '#' . (int) $d['m']['coin']['rank'] : null),
            $row('حجم معاملات ۲۴ ساعته', static fn($d) => $big($d['v']['hero']['volume24h'] ?? null)),
            $row('تغییر ۷ روزه', static fn($d) => $pct($d['m']['performance']['7d'] ?? null)),
            $row('تغییر ۳۰ روزه', static fn($d) => $pct($d['m']['performance']['30d'] ?? null)),
            $row('فاصله از سقف تاریخی', static fn($d) => $pct($d['m']['market']['ath']['changePct'] ?? null)),
        ]];
        $groups[] = ['title' => 'عرضه', 'rows' => [
            $row('عرضه در گردش', static fn($d, $c) => $supply($d['m']['market']['circulatingSupply'] ?? null, $c['symbol'])),
            $row('حداکثر عرضه', static fn($d, $c) => isset($d['m']['market']['maxSupply']) ? $supply($d['m']['market']['maxSupply'], $c['symbol']) : 'بدون سقف یا اعلام‌نشده'),
            $row('ارزش با کل عرضه (FDV)', static fn($d) => $big($d['v']['hero']['fdv'] ?? null)),
        ]];
        $groups[] = ['title' => 'دیفای', 'rows' => [
            $row('ارزش قفل‌شده (TVL) زنجیره', static fn($d) => $big($d['m']['defi']['tvl'] ?? null), 'فقط برای بلاکچین‌هایی که ارزش قفل‌شده برایشان ثبت می‌شود.'),
        ]];

        $scoreRows = [$row('امتیاز تحلیلی', static fn($d) => isset($d['v']['dna']['score'])
            ? $d['v']['dna']['score'] . ' از ۱۰۰' . (isset($d['v']['dna']['band']) ? ' · ' . $d['v']['dna']['band'] : '')
              . ' (' . $d['v']['dna']['coverage'][0] . '/' . $d['v']['dna']['coverage'][1] . ' مؤلفه)' : null,
            'امتیاز فقط وقتی نمایش داده می‌شود که دست‌کم نیمی از مؤلفه‌ها داده داشته باشند.')];
        $axes = [];
        foreach ($data as $d) {
            foreach ((array) ($d['v']['dna']['axes'] ?? []) as $a) {
                $axes[$a['label']] = true;
            }
        }
        foreach (array_keys($axes) as $label) {
            $scoreRows[] = $row('مؤلفه: ' . $label, static function ($d) use ($label) {
                foreach ((array) ($d['v']['dna']['axes'] ?? []) as $a) {
                    if ($a['label'] === $label) {
                        return $a['value'] !== null ? (string) $a['value'] : null;
                    }
                }
                return null;
            });
        }
        $groups[] = ['title' => 'امتیاز', 'rows' => $scoreRows];

        $groups[] = ['title' => 'شناسنامه', 'rows' => [
            $row('دسته', static fn($d) => $d['cat']['fa'] ?? null),
            $row('راه‌اندازی', static fn($d) => !empty($d['m']['about']['launchDate']) ? Format::gregorian((string) $d['m']['about']['launchDate']) : null),
            $row('سازوکار اجماع', static fn($d) => $d['m']['about']['consensus'] ?? null),
            $row('بنیان‌گذار / سازمان', static fn($d) => $d['m']['about']['founder'] ?? null),
        ]];

        /* A row with no figure for any coin says nothing: dropped. */
        foreach ($groups as &$g) {
            $g['rows'] = array_values(array_filter($g['rows'], static fn($r) => array_filter($r['cells'], static fn($c) => $c !== '—') !== []));
        }
        unset($g);
        $groups = array_values(array_filter($groups, static fn($g) => $g['rows'] !== []));

        return ['cols' => $cols, 'groups' => $groups];
    }

    /** The coins for the picker: every coin with a page, by name. */
    public static function choices(CoinRepository $coins): array
    {
        $out = [];
        foreach ($coins->all() as $coin) {
            $out[] = ['slug' => $coin->slug, 'label' => $coin->name . ' (' . strtoupper($coin->symbol) . ')'];
        }
        return $out;
    }

    /**
     * "Compare with" for a coin page: the first peer (same peer group) that
     * has a page here, else Bitcoin (or Ethereum for Bitcoin itself).
     */
    public function partner(Coin $coin): ?string
    {
        $v = $this->plugin->v2($coin);   // memoised: the page's own model
        foreach ((array) ($v['peers']['rows'] ?? []) as $p) {
            $slug = $this->slugFor((string) ($p['url'] ?? ''));
            if ($slug !== null && $slug !== $coin->slug) {
                return $slug;
            }
        }
        return $coin->slug === 'bitcoin' ? 'ethereum' : 'bitcoin';
    }

    private function slugFor(string $url): ?string
    {
        if ($url === '') {
            return null;
        }
        foreach ($this->plugin->coins->all() as $c) {
            if ((string) get_permalink($c->postId) === $url) {
                return $c->slug;
            }
        }
        return null;
    }
}
