<?php
/**
 * Reusable content blocks (2.16.0) — Gutenberg blocks and the same thing as
 * shortcodes, for composing learn / analysis / news articles in the editor.
 *
 *   thb/price      [thb_price coin="ethereum" live="1"]
 *   thb/pillars    [thb_pillars coin="ethereum" current="learn"]
 *   thb/related    [thb_related coin="ethereum" pillar="" count="4"]
 *   thb/next       [thb_next coin="ethereum" pillar="staking" post="" title="" text=""]
 *   thb/converter  [thb_converter coin="ethereum"]
 *   thb/chart      [thb_chart coin="ethereum" period="7d"]
 *   thb/stats      [thb_stats coin="ethereum"]
 *   thb/faq        [thb_faq]پرسش؟ | پاسخ[/thb_faq]   (one «پرسش | پاسخ» per line)
 *   thb/toc        [thb_toc]
 *
 * `coin` may be left out inside a hub article or pillar: the page's coin is
 * used. Every block is SERVER-RENDERED FROM THE CACHE (Cache::peek only — a
 * page view never reaches a provider) and renders NOTHING when the data it
 * shows is not cached: no placeholder, no zero, no "unavailable".
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

use TheHybit\Coins\V2\Converter;
use TheHybit\Coins\V2\View;

defined('ABSPATH') || exit;

final class Blocks
{
    /** name => [Persian title, attributes (name => default)] — the editor builds its panels from this. */
    public const BLOCKS = [
        'price'     => ['کارت قیمت زنده', ['coin' => '', 'live' => true]],
        'pillars'   => ['ناوبری ستون‌ها', ['coin' => '', 'current' => '']],
        'related'   => ['مقاله‌های مرتبط', ['coin' => '', 'pillar' => '', 'count' => 4]],
        'next'      => ['قدم بعدی', ['coin' => '', 'pillar' => '', 'post' => 0, 'title' => '', 'text' => '']],
        'converter' => ['مبدل کوچک', ['coin' => '']],
        'chart'     => ['نمودار کوچک', ['coin' => '', 'period' => '7d']],
        'stats'     => ['آمار کلیدی', ['coin' => '']],
        'faq'       => ['پرسش‌های متداول', ['text' => '']],
        'toc'       => ['فهرست مطالب', []],
    ];

    /** CoinGecko ids that want the live client on this page. */
    private array $liveIds = [];
    private bool $assets = false;

    public function __construct(private array $config, private Cache $cache, private CoinRepository $coins, private Hub $hub) {}

    public function register(): void
    {
        foreach (array_keys(self::BLOCKS) as $name) {
            add_shortcode('thb_' . $name, fn($atts = [], $content = null) => $this->render($name, $this->shortcodeAttrs($name, (array) $atts, $content)));
        }
        add_action('init', [$this, 'registerBlocks'], 20);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_action('wp_footer', [$this, 'footer'], 5);
    }

    private function shortcodeAttrs(string $name, array $atts, ?string $content): array
    {
        $a = shortcode_atts(self::BLOCKS[$name][1], $atts, 'thb_' . $name);
        if ($name === 'faq' && $content !== null && trim($content) !== '') {
            $a['text'] = (string) $content;
        }
        if (array_key_exists('live', $a)) {
            $a['live'] = !in_array(strtolower((string) $a['live']), ['0', 'false', 'no', ''], true);
        }
        return $a;
    }

    public function registerBlocks(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }
        wp_register_script('thb-blocks-editor', THB_COINS_URL . 'assets/blocks/editor.js',
            ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render'], THB_COINS_VERSION, true);
        $defs = [];
        foreach (self::BLOCKS as $name => [$title, $attrs]) {
            $schema = [];
            foreach ($attrs as $k => $default) {
                $schema[$k] = ['type' => is_bool($default) ? 'boolean' : (is_int($default) ? 'number' : 'string'), 'default' => $default];
            }
            register_block_type('thb/' . $name, [
                'api_version'     => 2,
                'title'           => $title,
                'category'        => 'widgets',
                'attributes'      => $schema,
                'editor_script'   => 'thb-blocks-editor',
                'render_callback' => fn($a) => $this->render($name, (array) $a),
            ]);
            $defs[] = ['name' => 'thb/' . $name, 'title' => $title, 'attributes' => $schema];
        }
        wp_add_inline_script('thb-blocks-editor', 'window.__THB_BLOCKS__ = ' . wp_json_encode([
            'blocks' => $defs,
            'coins' => array_map(static fn(Coin $c) => ['value' => $c->slug, 'label' => $c->name], $this->coins->all()),
            'pillars' => array_map(static fn($k, $v) => ['value' => $k, 'label' => $v[0]], array_keys(Hub::PILLARS), Hub::PILLARS),
        ], JSON_UNESCAPED_UNICODE) . ';', 'before');
    }

    /** Styles and the small script, only where a block can appear. */
    public function assets(): void
    {
        $hub = $this->hub->currentPillar() !== null || $this->hub->currentArticle() !== null;
        $post = function_exists('get_post') ? get_post() : null;
        $content = is_object($post) ? (string) ($post->post_content ?? '') : '';
        if ($hub || (function_exists('is_singular') && is_singular() && (str_contains($content, '<!-- wp:thb/') || str_contains($content, '[thb_')))) {
            $this->enqueue($hub);
        }
    }

    public function enqueue(bool $hubPage = false): void
    {
        if ($hubPage) {
            wp_enqueue_style('thb-v2', THB_COINS_URL . 'assets/v2/v2.css', [], THB_COINS_VERSION);
        }
        wp_enqueue_style('thb-blocks', THB_COINS_URL . 'assets/v2/blocks.css', [], THB_COINS_VERSION);
        wp_enqueue_script('thb-blocks', THB_COINS_URL . 'assets/v2/blocks.js', [], THB_COINS_VERSION, true);
        $this->assets = true;
    }

    /** Live prices for the cards rendered on this page — the existing client, one connection per exchange. */
    public function footer(): void
    {
        if ($this->liveIds !== []) {
            LiveFeed::enqueue($this->config, array_values(array_unique($this->liveIds)));
        }
    }

    /** @return string[] */
    public function liveIds(): array
    {
        return array_values(array_unique($this->liveIds));
    }

    /* ==================================================================
     * Rendering
     * ================================================================ */

    public function render(string $name, array $attrs): string
    {
        $attrs += self::BLOCKS[$name][1] ?? [];
        $html = match ($name) {
            'price'     => $this->price($attrs),
            'pillars'   => $this->pillars($attrs),
            'related'   => $this->related($attrs),
            'next'      => $this->next($attrs),
            'converter' => $this->converter($attrs),
            'chart'     => $this->chart($attrs),
            'stats'     => $this->stats($attrs),
            'faq'       => $this->faq($attrs),
            'toc'       => $this->toc(),
            default     => '',
        };
        return $html;
    }

    /** The coin a block is about: its own attribute, else the page's. */
    public function coinFor(array $a): ?Coin
    {
        $slug = sanitize_title((string) ($a['coin'] ?? ''));
        if ($slug === '') {
            $slug = $this->pageCoin() ?? '';
        }
        return $slug !== '' ? $this->hub->coin($slug) : null;
    }

    /** The coin of the page being viewed: a hub article, a pillar, or a coin page. */
    public function pageCoin(): ?string
    {
        if (($p = Hub::requested()) !== null) {
            return $p[0];
        }
        $post = function_exists('get_post') ? get_post() : null;
        if (is_object($post)) {
            $hub = $this->hub->postHub($post);
            if ($hub !== null) {
                return $hub[0];
            }
        }
        return null;
    }

    private function pagePillar(): ?string
    {
        if (($p = Hub::requested()) !== null) {
            return $p[1];
        }
        $post = function_exists('get_post') ? get_post() : null;
        $hub = is_object($post) ? $this->hub->postHub($post) : null;
        return $hub[1] ?? null;
    }

    /* ---- cache reads (peek only) ---- */

    /** @return array{price:float, change:?float, at:int}|null */
    public function priceOf(Coin $coin): ?array
    {
        $t = $this->cache->peek('ticker', $this->cache->keyFor('ticker'));
        $row = $t['data']['prices'][$coin->coingeckoId] ?? null;
        if (is_array($row) && isset($row['price']) && (float) $row['price'] > 0) {
            return ['price' => (float) $row['price'], 'change' => isset($row['change24h']) ? (float) $row['change24h'] : null, 'at' => (int) ($row['fetchedAt'] ?? 0)];
        }
        $m = $this->market($coin);
        return isset($m['price']) && (float) $m['price'] > 0
            ? ['price' => (float) $m['price'], 'change' => isset($m['change24h']) ? (float) $m['change24h'] : null, 'at' => 0]
            : null;
    }

    private function market(Coin $coin): array
    {
        $m = $this->cache->peek('market', $this->cache->keyFor('market', $coin));
        return is_array($m['data'] ?? null) ? $m['data'] : [];
    }

    private function logo(Coin $coin): ?string
    {
        $thumb = get_the_post_thumbnail_url($coin->postId, 'thumbnail');
        if ($thumb) {
            return (string) $thumb;
        }
        return Plugin::instance()->listing->logos()[$coin->coingeckoId] ?? null;
    }

    /** @return float[] prices of a cached chart window (or the 7-day sparkline) */
    private function series(Coin $coin, string $period): array
    {
        $c = $this->cache->peek('chart.' . $period, $this->cache->keyFor('chart.' . $period, $coin));
        $p = is_array($c['data']['price'] ?? null) ? $c['data']['price'] : [];
        if (count($p) < 2 && $period === '7d') {
            $p = (array) ($this->market($coin)['sparkline'] ?? []);
        }
        return array_values(array_map('floatval', array_filter($p, 'is_numeric')));
    }

    /* ---- shared pieces ---- */

    private static function wrap(string $kind, string $inner, string $tag = 'div', string $extra = ''): string
    {
        return sprintf('<%1$s class="thb-b thb-b--%2$s"%3$s>%4$s</%1$s>', $tag, esc_attr($kind), $extra, $inner);
    }

    private static function dir(?float $v): string
    {
        return $v === null ? 'flat' : ($v > 0 ? 'up' : ($v < 0 ? 'down' : 'flat'));
    }

    /** A sparkline polyline, 0..w × 0..h, no request, no script. */
    public static function spark(array $p, int $w = 120, int $h = 36, string $class = 'thb-b-spark'): string
    {
        if (count($p) < 2) {
            return '';
        }
        $step = max(1, (int) floor(count($p) / 120));
        $p = array_values(array_filter($p, static fn($_, $i) => $i % $step === 0, ARRAY_FILTER_USE_BOTH));
        $min = min($p);
        $max = max($p);
        $span = $max - $min ?: 1;
        $n = count($p) - 1;
        $pts = [];
        foreach ($p as $i => $v) {
            $pts[] = round($i / $n * $w, 1) . ',' . round($h - 2 - ($v - $min) / $span * ($h - 4), 1);
        }
        $dir = self::dir(end($p) - $p[0]);
        return sprintf('<svg class="%1$s %1$s--%2$s" viewBox="0 0 %3$d %4$d" width="%3$d" height="%4$d" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
            . '<polygon points="0,%4$d %5$s %3$d,%4$d" class="%1$s__area"/><polyline points="%5$s" class="%1$s__line"/></svg>',
            esc_attr($class), $dir, $w, $h, implode(' ', $pts));
    }

    /* ==================================================================
     * The blocks
     * ================================================================ */

    public function price(array $a): string
    {
        $coin = $this->coinFor($a);
        $p = $coin ? $this->priceOf($coin) : null;
        if ($coin === null || $p === null) {
            return '';
        }
        $toman = Plugin::instance()->converter()->toman();
        $logo = $this->logo($coin);
        $live = !empty($a['live']) && LiveFeed::covers($this->config, $coin->coingeckoId);
        if ($live) {
            $this->liveIds[] = $coin->coingeckoId;
        }
        $spark = self::spark((array) ($this->market($coin)['sparkline'] ?? []));
        $inner = '<span class="thb-b-price__id">'
            . ($logo ? sprintf('<img class="thb-b-price__logo" src="%s" alt="" width="40" height="40" loading="lazy" decoding="async">', esc_url($logo))
                     : '<span class="thb-b-price__logo thb-b-price__logo--glyph" aria-hidden="true">' . esc_html(mb_substr(strtoupper($coin->symbol), 0, 1)) . '</span>')
            . '<span class="thb-b-price__name"><strong>' . esc_html($coin->name) . '</strong> <bdi dir="ltr">' . esc_html(strtoupper($coin->symbol)) . '</bdi></span></span>'
            . '<span class="thb-b-price__fig"><span class="thb-b-price__usd" data-live-usd>' . View::n(Format::usd($p['price'])) . '</span>'
            . ($p['change'] !== null ? '<span class="thb-b-price__chg v2-delta--' . self::dir($p['change']) . '" data-live-chg>' . View::n(Format::pct($p['change'], true, 2)) . '</span>' : '')
            . '</span>'
            . ($toman ? '<span class="thb-b-price__toman">≈ <span data-live-irt>' . View::n(Format::toman($p['price'], $toman['rate'])) . '</span> تومان</span>' : '')
            . ($spark !== '' ? '<span class="thb-b-price__spark">' . $spark . '<span class="thb-b-price__spark-l">۷ روز</span></span>' : '');
        return sprintf('<a class="thb-b thb-b--price" href="%s"%s aria-label="%s">%s</a>',
            esc_url(Hub::hubUrl($coin->slug)),
            $live ? ' data-live-id="' . esc_attr($coin->coingeckoId) . '"' : '',
            esc_attr('قیمت ' . $coin->name . ' — صفحه کامل ' . $coin->name),
            $inner);
    }

    /** Hub + the coin's pillars that have content; the current one marked. Empty when none has content. */
    public function pillars(array $a, string $variant = 'chips'): string
    {
        $coin = $this->coinFor($a);
        if ($coin === null) {
            return '';
        }
        $have = $this->hub->pillarsWithContent($coin->slug);
        if ($have === []) {
            return '';
        }
        $current = (string) ($a['current'] ?? '') ?: ($variant === 'hub' ? 'hub' : ($this->pagePillar() ?? ''));
        $items = '<li><a class="thb-b-chip' . ($current === 'hub' ? ' is-current" aria-current="page' : '') . '" href="' . esc_url(Hub::hubUrl($coin->slug)) . '">نمای کلی ' . esc_html($coin->name) . '</a></li>';
        foreach ($have as $p) {
            $items .= sprintf('<li><a class="thb-b-chip%s" href="%s">%s</a></li>',
                $p === $current ? ' is-current" aria-current="page' : '', esc_url(Hub::pillarUrl($coin->slug, $p)), esc_html(Hub::pillarName($p)));
        }
        return self::wrap('pillars thb-b--pillars-' . $variant, '<ul class="thb-b-chips">' . $items . '</ul>', 'nav', ' aria-label="' . esc_attr('بخش‌های ' . $coin->name) . '"');
    }

    /** Article cards. */
    public static function cards(array $posts, string $class = 'thb-b-cards'): string
    {
        $out = '<ul class="' . esc_attr($class) . '">';
        foreach ($posts as $p) {
            $img = get_the_post_thumbnail_url($p, 'medium');
            $hub = Plugin::instance()->hub->postHub($p);
            $out .= '<li class="thb-b-card"><a class="thb-b-card__link" href="' . esc_url((string) get_permalink($p)) . '">'
                . ($img ? '<img class="thb-b-card__img" src="' . esc_url((string) $img) . '" alt="" width="320" height="180" loading="lazy" decoding="async">' : '')
                . '<span class="thb-b-card__body">'
                . ($hub ? '<span class="thb-b-card__kicker">' . esc_html(Hub::pillarName($hub[1])) . '</span>' : '')
                . '<span class="thb-b-card__title">' . esc_html((string) $p->post_title) . '</span>'
                . (($ex = trim((string) ($p->post_excerpt ?? ''))) !== '' ? '<span class="thb-b-card__ex">' . esc_html(wp_trim_words($ex, 22)) . '</span>' : '')
                . (($d = Hub::iso((string) ($p->post_date_gmt ?? ''))) ? '<time class="thb-b-card__date" datetime="' . esc_attr($d) . '">' . esc_html(Format::gregorianFa($d)) . '</time>' : '')
                . '</span></a></li>';
        }
        return $out . '</ul>';
    }

    public function related(array $a): string
    {
        $coin = $this->coinFor($a);
        if ($coin === null) {
            return '';
        }
        $post = function_exists('get_post') ? get_post() : null;
        $exclude = is_object($post) ? [(int) $post->ID] : [];
        $count = max(1, min(12, (int) ($a['count'] ?? 4)));
        $pillar = sanitize_key((string) ($a['pillar'] ?? ''));
        $posts = $this->hub->articles($coin->slug, $pillar !== '' && isset(Hub::PILLARS[$pillar]) ? $pillar : null, $count, $exclude);
        if ($posts === []) {
            return '';
        }
        return self::wrap('related', '<p class="thb-b__title">مقاله‌های مرتبط با ' . esc_html($coin->name) . '</p>' . self::cards($posts), 'aside');
    }

    /** A "next step" card to an article (by id) or a pillar that has content. */
    public function next(array $a): string
    {
        $coin = $this->coinFor($a);
        $postId = (int) ($a['post'] ?? 0);
        $target = null;
        if ($postId > 0 && function_exists('get_post') && ($p = get_post($postId)) && ($p->post_status ?? '') === 'publish') {
            $target = ['url' => (string) get_permalink($p), 'label' => (string) $p->post_title];
        } elseif ($coin !== null && ($pl = sanitize_key((string) ($a['pillar'] ?? ''))) !== '' && $this->hub->hasContent($coin->slug, $pl)) {
            $target = ['url' => Hub::pillarUrl($coin->slug, $pl), 'label' => Hub::pillarName($pl) . ' ' . $coin->name];
        }
        if ($target === null) {
            return '';
        }
        $title = trim((string) ($a['title'] ?? '')) ?: $target['label'];
        $text = trim((string) ($a['text'] ?? ''));
        return sprintf('<a class="thb-b thb-b--next" href="%s"><span class="thb-b-next__kicker">قدم بعدی</span><span class="thb-b-next__title">%s</span>%s<span class="thb-b-next__arrow" aria-hidden="true">←</span></a>',
            esc_url($target['url']), esc_html($title), $text !== '' ? '<span class="thb-b-next__text">' . esc_html($text) . '</span>' : '');
    }

    public function converter(array $a): string
    {
        $coin = $this->coinFor($a);
        $p = $coin ? $this->priceOf($coin) : null;
        if ($coin === null || $p === null) {
            return '';
        }
        $conv = Plugin::instance()->converter();
        $toman = $conv->toman();
        $rates = ['usd' => 1.0] + ($toman ? ['toman' => $toman['rate']] : []) + $conv->fiat();
        $opts = '';
        foreach (Converter::FIAT as $u) {
            if (isset($rates[$u])) {
                $opts .= sprintf('<option value="%s"%s>%s</option>', esc_attr($u), $u === 'usd' ? ' selected' : '', esc_html(Converter::FIAT_LABELS[$u][0]));
            }
        }
        static $n = 0;
        $id = 'thb-conv-' . esc_attr($coin->slug) . '-' . (++$n);
        $first = Converter::fa(1, 'coin') . ' ' . $coin->name . ' = ' . Converter::fa($p['price'], 'usd') . ' دلار';
        return self::wrap('converter',
            '<p class="thb-b__title">تبدیل ' . esc_html($coin->name) . '</p>'
            . '<div class="thb-b-conv__row"><label class="thb-b-conv__l" for="' . $id . '">مقدار ' . esc_html(strtoupper($coin->symbol)) . '</label>'
            . '<input class="thb-b-conv__in" id="' . $id . '" type="text" inputmode="decimal" value="1" dir="ltr" autocomplete="off" data-thb-conv-in>'
            . '<label class="screen-reader-text" for="' . $id . '-u">به</label><select class="thb-b-conv__unit" id="' . $id . '-u" data-thb-conv-unit>' . $opts . '</select></div>'
            . '<output class="thb-b-conv__out" for="' . $id . '" aria-live="polite" data-thb-conv-out>' . esc_html($first) . '</output>',
            'div', sprintf(' data-thb-conv data-coin-id="%s" data-name="%s" data-usd="%s" data-rates="%s"', esc_attr($coin->coingeckoId), esc_attr($coin->name),
                esc_attr((string) $p['price']), esc_attr((string) wp_json_encode($rates))));
    }

    public function chart(array $a): string
    {
        $coin = $this->coinFor($a);
        $period = in_array($a['period'] ?? '7d', ['24h', '7d', '30d', '90d', '1y'], true) ? $a['period'] : '7d';
        $s = $coin ? $this->series($coin, $period) : [];
        if (count($s) < 2) {
            return '';
        }
        $chg = $s[0] > 0 ? (end($s) / $s[0] - 1) * 100 : null;
        $label = ['24h' => '۲۴ ساعت', '7d' => '۷ روز', '30d' => '۳۰ روز', '90d' => '۹۰ روز', '1y' => 'یک سال'][$period];
        return self::wrap('chart',
            '<p class="thb-b__title">' . esc_html($coin->name) . ' در ' . esc_html($label) . ' گذشته'
            . ($chg !== null ? ' <span class="v2-delta--' . self::dir($chg) . '">' . View::n(Format::pct($chg, true, 2)) . '</span>' : '') . '</p>'
            . self::spark($s, 600, 140, 'thb-b-chart')
            . '<p class="thb-b-chart__range"><span>کمینه ' . View::n(Format::usd(min($s))) . '</span><span>بیشینه ' . View::n(Format::usd(max($s))) . '</span></p>',
            'figure', ' aria-label="' . esc_attr('نمودار قیمت ' . $coin->name . ' در ' . $label . ' گذشته') . '"');
    }

    /** Market cap, rank, gas, staking APR — only the rows with a real cached value. */
    public function stats(array $a): string
    {
        $coin = $this->coinFor($a);
        if ($coin === null) {
            return '';
        }
        $m = $this->market($coin);
        $rows = [];
        if (!empty($m['rank'])) {
            $rows[] = ['رتبه بازار', View::n('#' . (int) $m['rank'])];
        }
        if (!empty($m['marketCap'])) {
            $rows[] = ['ارزش بازار', View::n(Format::usdCompact((float) $m['marketCap']))];
        }
        $sets = new Datasets($this->config);
        if ($sets->covers('gas', $coin)) {
            $g = $this->cache->peek('gas', $this->cache->keyFor('gas', $coin));
            if (isset($g['data']['propose']) && is_numeric($g['data']['propose'])) {
                $rows[] = ['کارمزد شبکه (گس)', View::n(Format::num((float) $g['data']['propose'], 2) . ' Gwei')];
            }
        }
        $apr = null;
        if ($sets->covers('staking', $coin)) {
            $st = $this->cache->peek('staking', $this->cache->keyFor('staking', $coin));
            $apr = isset($st['data']['apr']) && is_numeric($st['data']['apr']) ? (float) $st['data']['apr'] : null;
        }
        if ($apr === null && $sets->covers('stakingyield', $coin)) {
            $sy = $this->cache->peek('stakingyield', $this->cache->keyFor('stakingyield', $coin));
            $apr = isset($sy['data']['apy7d']) && is_numeric($sy['data']['apy7d']) ? (float) $sy['data']['apy7d'] : null;
        }
        if ($apr !== null) {
            $rows[] = ['بازده استیکینگ', View::n(Format::pct($apr, false, 2))];
        }
        if ($rows === []) {
            return '';
        }
        $dl = '';
        foreach ($rows as [$k, $v]) {
            $dl .= '<div class="thb-b-stats__row"><dt>' . esc_html($k) . '</dt><dd>' . $v . '</dd></div>';
        }
        return self::wrap('stats', '<p class="thb-b__title">' . esc_html($coin->name) . ' در یک نگاه</p><dl class="thb-b-stats">' . $dl . '</dl>');
    }

    public function faq(array $a): string
    {
        $items = Hub::parseFaq((string) ($a['text'] ?? ''));
        return self::faqList($items);
    }

    public static function faqList(array $items, string $title = 'پرسش‌های متداول'): string
    {
        if ($items === []) {
            return '';
        }
        $li = '';
        foreach ($items as $f) {
            $li .= '<details class="thb-b-faq__item"><summary>' . esc_html($f['q']) . '</summary><div class="thb-b-faq__a">' . wp_kses_post($f['a']) . '</div></details>';
        }
        return self::wrap('faq', '<p class="thb-b__title">' . esc_html($title) . '</p>' . $li, 'section');
    }

    /** The FAQ items in a post's raw content (block or shortcode), for FAQPage schema. */
    public static function faqFromContent(string $content): array
    {
        $out = [];
        if (preg_match_all('#<!--\s+wp:thb/faq\s+(\{.*?\})\s+/?-->#s', $content, $m)) {
            foreach ($m[1] as $json) {
                $attrs = json_decode($json, true);
                $out = array_merge($out, Hub::parseFaq((string) ($attrs['text'] ?? '')));
            }
        }
        if (preg_match_all('#\[thb_faq[^\]]*\](.*?)\[/thb_faq\]#su', $content, $m)) {
            foreach ($m[1] as $text) {
                $out = array_merge($out, Hub::parseFaq(wp_strip_all_tags($text)));
            }
        }
        return $out;
    }

    public function toc(): string
    {
        $post = function_exists('get_post') ? get_post() : null;
        return is_object($post) ? self::tocFor((string) $post->post_content) : '';
    }

    public static function tocFor(string $content): string
    {
        $h = Hub::headings($content);
        if (count($h) < 2) {
            return '';
        }
        $li = '';
        foreach ($h as $x) {
            $li .= '<li class="thb-b-toc__l' . $x['level'] . '"><a href="#' . esc_attr($x['id']) . '">' . esc_html($x['text']) . '</a></li>';
        }
        return self::wrap('toc', '<p class="thb-b__title">فهرست مطالب</p><ol class="thb-b-toc">' . $li . '</ol>', 'nav', ' aria-label="فهرست مطالب"');
    }
}
