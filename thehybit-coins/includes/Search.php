<?php
/**
 * Search over the coin list (2.9.0).
 *
 * WHAT IS SEARCHED: every coin the list knows — all the /coins/ pages already
 * in the cache (up to 500 coins by market cap) plus any coin we have a page
 * for that sits below them. Nothing is fetched: the index is built from
 * cache entries the scheduler keeps, so a search never reaches a provider.
 *
 * HOW: names (Persian and English) and symbols, normalised so that what a
 * visitor types matches however it was written —
 *   - Arabic ي ى → ی, ك → ک, ة → ه, أ إ آ ٱ → ا, ؤ → و, ئ → ی
 *   - Persian ۰–۹ and Arabic ٠–٩ digits → 0–9
 *   - diacritics, tatweel, ZWNJ, spaces and punctuation removed
 *     («بیت کوین» = «بیت‌کوین» = «بيتكوين»)
 *   - case folded
 * and, when a query written in Persian letters finds nothing, it is read
 * again as the same keys on an English layout («ذفز» → btc): the commonest
 * slip when typing a symbol.
 *
 * The browser does the same in assets/v2/list.js (instant results, no request
 * per keystroke: the index is fetched once, from the cached endpoint below).
 * Both are tested against the same cases.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class Search
{
    /** Results shown at most; the list is a lookup, not a second table. */
    public const LIMIT = 50;

    /** The index endpoint: per visitor, at most this many in RATE_WINDOW. */
    public const RATE_MAX = 30;
    public const RATE_WINDOW = 60;

    private const FOLD = [
        'ي' => 'ی', 'ى' => 'ی', 'ئ' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ۀ' => 'ه',
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ؤ' => 'و',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    /** Persian (ISIRI 9147 / Windows) key → the same key on an English layout. */
    private const LAYOUT = [
        'ض' => 'q', 'ص' => 'w', 'ث' => 'e', 'ق' => 'r', 'ف' => 't', 'غ' => 'y', 'ع' => 'u', 'ه' => 'i', 'خ' => 'o', 'ح' => 'p',
        'ش' => 'a', 'س' => 's', 'ی' => 'd', 'ب' => 'f', 'ل' => 'g', 'ا' => 'h', 'ت' => 'j', 'ن' => 'k', 'م' => 'l',
        'ظ' => 'z', 'ط' => 'x', 'ز' => 'c', 'ر' => 'v', 'ذ' => 'b', 'د' => 'n', 'پ' => 'm',
    ];

    public function __construct(private Listing $listing) {}

    public static function normalize(string $s): string
    {
        $s = mb_strtolower(strtr($s, self::FOLD));
        // Diacritics (U+064B–065F, U+0670), tatweel, ZWNJ/ZWJ, then anything not a letter or digit.
        $s = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}\x{200C}\x{200D}]/u', '', $s);
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $s);
    }

    /** The query as typed on the wrong layout, or null if it is not all Persian letters. */
    public static function relayout(string $normalized): ?string
    {
        if ($normalized === '' || preg_match('/[^\x{0600}-\x{06FF}]/u', $normalized)) {
            return null;
        }
        $out = '';
        foreach (mb_str_split($normalized) as $ch) {
            if (!isset(self::LAYOUT[$ch])) {
                return null;
            }
            $out .= self::LAYOUT[$ch];
        }
        return $out;
    }

    /**
     * Rank the index against a query.
     *
     * @param array<int, array> $rows rows with id, symbol, name, nameFa, rank
     * @return array{rows: array<int, array>, query: string, relayout: ?string}
     */
    public static function match(array $rows, string $query, int $limit = self::LIMIT): array
    {
        $q = self::normalize($query);
        $hits = $q === '' ? [] : self::score($rows, $q);
        $alt = null;
        if ($hits === [] && ($alt = self::relayout($q)) !== null) {
            $hits = self::score($rows, $alt);
        }
        usort($hits, static fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return [
            'rows' => array_map(static fn($h) => $h[2], array_slice($hits, 0, $limit)),
            'query' => $q,
            'relayout' => $hits !== [] ? $alt : null,
        ];
    }

    /** @return array<int, array{0:int, 1:int, 2:array}> [tier, rank, row] */
    private static function score(array $rows, string $q): array
    {
        $out = [];
        foreach ($rows as $r) {
            $sym = self::normalize((string) ($r['symbol'] ?? ''));
            $names = array_filter([self::normalize((string) ($r['name'] ?? '')), self::normalize((string) ($r['nameFa'] ?? ''))]);
            $tier = null;
            if ($sym === $q) {
                $tier = 0;
            } elseif (in_array($q, $names, true)) {
                $tier = 1;
            } elseif (str_starts_with($sym, $q)) {
                $tier = 2;
            } elseif (array_filter($names, static fn($n) => str_starts_with($n, $q))) {
                $tier = 3;
            } elseif (mb_strlen($q) >= 2 && array_filter($names, static fn($n) => str_contains($n, $q))) {
                $tier = 4;
            }
            if ($tier !== null) {
                $out[] = [$tier, (int) ($r['rank'] ?? PHP_INT_MAX), $r];
            }
        }
        return $out;
    }

    /**
     * The compact index the browser searches: one row per coin, values
     * already formatted the way the table prints them.
     *
     * @return array{v:int, at:?string, rows: array<int, array>}
     */
    public function index(): array
    {
        $rows = [];
        $newest = 0;
        foreach ($this->listing->allRows() as $r) {
            $rows[] = [
                'i' => (string) $r['id'],
                's' => (string) $r['symbol'],
                'n' => (string) $r['name'],
                'f' => (string) ($r['nameFa'] ?? ''),
                'r' => $r['rank'] ?? null,
                'p' => Format::usd(isset($r['price']) ? (float) $r['price'] : null),
                'c' => isset($r['change24h']) ? round((float) $r['change24h'], 2) : null,
                'u' => $r['url'] ?? null,
                'g' => $r['page'] ?? null,
                'k' => $r['category']['key'] ?? null,
            ];
            $newest = max($newest, (int) ($r['fetchedAt'] ?? 0));
        }
        return ['v' => 1, 'at' => $newest ? gmdate('c', $newest) : null, 'rows' => $rows];
    }

    /* ------------------------------------------------------------------
     * GET /wp-json/thehybit/v1/coins-index
     * ---------------------------------------------------------------- */

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route(LivePrice::NS, '/coins-index', [
                'methods'             => 'GET',
                'callback'            => [$this, 'respond'],
                'permission_callback' => '__return_true',   // public, read-only, the same rows the list prints
            ]);
        });
    }

    public function respond($request = null)
    {
        if (!RateLimit::allow('index', self::RATE_MAX, self::RATE_WINDOW)) {
            return RateLimit::refusal(self::RATE_WINDOW);
        }
        /* Built at most once a minute however many visitors ask: the rows
           change when a list page is refreshed (every 5–30 minutes). */
        $body = get_transient('thb_coins_index');
        if (!is_array($body)) {
            $body = $this->index();
            set_transient('thb_coins_index', $body, 60);
        }
        $r = new \WP_REST_Response($body, 200);
        // Browsers keep it five minutes; a page cache or CDN one minute.
        $r->header('Cache-Control', 'public, max-age=300, s-maxage=60');
        return $r;
    }

    public static function url(): string
    {
        return rest_url(LivePrice::NS . '/coins-index');
    }

    /** The ?q= of the list, trimmed and bounded. */
    public static function currentQuery(): string
    {
        $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : '';
        return mb_substr(trim($q), 0, 40);
    }
}
