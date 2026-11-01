<?php
/**
 * The coin hub (2.16.0): coin page → pillars → articles.
 *
 *   /coins/ethereum/                 the coin page (the hub) — unchanged
 *   /coins/ethereum/learn/           a PILLAR: intro, its articles, its FAQ
 *   /coins/ethereum/learn/<slug>/    an ARTICLE: a normal WordPress post
 *
 * CONTENT MODEL. Articles are ordinary posts with two taxonomies:
 *   thb_coin_topic  one term per coin page, slug = the coin's slug (kept in
 *                   sync with the thb_coin entries; the term stores the coin's
 *                   post id), carrying each pillar's intro and FAQ as term meta
 *   thb_pillar      nine fixed terms (PILLARS)
 * A post with both is a hub article and lives at its hub URL; a post without
 * them is untouched.
 *
 * A pillar EXISTS only when it has something: published articles or an intro.
 * An empty pillar answers 404 and is linked from nowhere (pillarsWithContent()
 * is the one list every link comes from).
 *
 * Nothing here reads a provider: posts and terms are WordPress data, and the
 * figures in the blocks come from the cache (Blocks.php).
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class Hub
{
    public const TAX_COIN   = 'thb_coin_topic';
    public const TAX_PILLAR = 'thb_pillar';
    public const VAR_COIN   = 'thb_hub_coin';
    public const VAR_PILLAR = 'thb_hub_pillar';
    public const OPTION_REWRITE = 'thb_hub_rewrite';

    /** slug => [Persian name, one-line description used when a pillar has no intro] */
    public const PILLARS = [
        'learn'      => ['آموزش', 'آموزش و راهنمای %s، از مفاهیم پایه تا جزئیات فنی'],
        'analysis'   => ['تحلیل', 'تحلیل‌های بازار و شبکه %s'],
        'news'       => ['اخبار', 'تازه‌ترین خبرهای %s'],
        'prediction' => ['پیش‌بینی و آینده', 'برنامه‌ها، به‌روزرسانی‌ها و دیدگاه‌ها درباره آینده %s'],
        'buy'        => ['خرید و نگهداری', 'راهنمای خرید، کیف پول و نگهداری امن %s'],
        'staking'    => ['استیکینگ و درآمد', 'استیکینگ و راه‌های کسب درآمد با %s'],
        'ecosystem'  => ['اکوسیستم', 'پروژه‌ها و شبکه‌های اکوسیستم %s'],
        'compare'    => ['مقایسه', 'مقایسه %s با ارزهای دیگر'],
        'tools'      => ['ابزارها', 'ابزارها و ماشین‌حساب‌های %s'],
    ];

    /** @var array<string, mixed> per-request memo */
    private array $memo = [];

    public function __construct(private CoinRepository $coins) {}

    /* ==================================================================
     * Registration
     * ================================================================ */

    public function register(): void
    {
        add_action('init', [self::class, 'registerTaxonomies'], 5);
        add_action('init', [$this, 'rules']);
        add_filter('query_vars', static fn(array $v): array => array_merge($v, [self::VAR_COIN, self::VAR_PILLAR]));
        add_filter('post_link', [$this, 'postLink'], 20, 2);
        add_action('template_redirect', [$this, 'route'], 1);
        add_filter('template_include', [$this, 'template'], 25);
        add_filter('the_content', [$this, 'anchorHeadings'], 20);
        add_action('admin_init', [$this, 'flushOnce']);
        add_action('admin_init', [$this, 'syncTerms']);
        add_action(self::TAX_COIN . '_edit_form_fields', [$this, 'termFields']);
        add_action('edited_' . self::TAX_COIN, [$this, 'saveTermFields']);

        add_filter('document_title_parts', [$this, 'documentTitle'], 20);
        add_filter('wpseo_title', [$this, 'seoTitle'], 20);
        add_filter('wpseo_opengraph_title', [$this, 'seoTitle'], 20);
        add_filter('wpseo_metadesc', [$this, 'seoDescription'], 20);
        add_filter('wpseo_opengraph_desc', [$this, 'seoDescription'], 20);
        add_filter('wpseo_canonical', [$this, 'canonical'], 20);
        add_action('wp_head', [$this, 'head'], 3);

        /* The plugin's own sitemap: pillars and articles with honest lastmod
           (core sitemaps and Yoast). Hub articles are then left out of the
           posts sitemap so no URL is listed twice. */
        add_action('wp_sitemaps_init', [$this, 'coreSitemap']);
        add_filter('wp_sitemaps_posts_query_args', [$this, 'excludeFromPostsSitemap'], 10, 2);
        add_filter('wpseo_sitemap_index', [$this, 'yoastIndex']);
        add_action('init', [$this, 'yoastRegister'], 20);
        add_filter('wpseo_exclude_from_sitemap_by_post_ids', [$this, 'yoastExclude']);
    }

    public static function registerTaxonomies(): void
    {
        register_taxonomy(self::TAX_COIN, ['post'], [
            'label'        => 'ارز (هاب)',
            'labels'       => ['name' => 'ارزهای هاب', 'singular_name' => 'ارز', 'edit_item' => 'ویرایش ارز و مقدمه ستون‌ها'],
            'public'       => false,
            'show_ui'      => true,
            'show_in_rest' => true,      // the block editor's side panel
            'show_admin_column' => true,
            'hierarchical' => true,      // checkbox UI: pick one coin
            'rewrite'      => false,
        ]);
        register_taxonomy(self::TAX_PILLAR, ['post'], [
            'label'        => 'ستون محتوا',
            'labels'       => ['name' => 'ستون‌های محتوا', 'singular_name' => 'ستون'],
            'public'       => false,
            'show_ui'      => true,
            'show_in_rest' => true,
            'show_admin_column' => true,
            'hierarchical' => true,
            'rewrite'      => false,
        ]);
    }

    /** Pillar and article routes, ahead of WordPress's own /coins/<x>/<y>/ (attachment) rule. */
    public function rules(): void
    {
        $p = implode('|', array_keys(self::PILLARS));
        add_rewrite_rule('^coins/([^/]+)/(' . $p . ')/?$',
            'index.php?' . self::VAR_COIN . '=$matches[1]&' . self::VAR_PILLAR . '=$matches[2]', 'top');
        add_rewrite_rule('^coins/([^/]+)/(' . $p . ')/([^/]+)/?$',
            'index.php?name=$matches[3]&' . self::VAR_COIN . '=$matches[1]&' . self::VAR_PILLAR . '=$matches[2]', 'top');
    }

    /** New rules need one flush; once per version, from wp-admin (activation flushes too). */
    public function flushOnce(): void
    {
        if (get_option(self::OPTION_REWRITE) !== THB_COINS_VERSION) {
            self::registerTaxonomies();
            $this->rules();
            flush_rewrite_rules(false);
            update_option(self::OPTION_REWRITE, THB_COINS_VERSION, false);
        }
    }

    /** Fixed pillar terms, and one coin term per coin page. Adds what is missing; renames nothing an editor changed. */
    public function syncTerms(): void
    {
        if (!current_user_can('manage_options') || !function_exists('term_exists')) {
            return;
        }
        foreach (self::PILLARS as $slug => [$name]) {
            if (!term_exists($slug, self::TAX_PILLAR)) {
                wp_insert_term($name, self::TAX_PILLAR, ['slug' => $slug]);
            }
        }
        foreach ($this->coins->all() as $coin) {
            if (!term_exists($coin->slug, self::TAX_COIN)) {
                $t = wp_insert_term($coin->name, self::TAX_COIN, ['slug' => $coin->slug]);
                if (is_array($t) && isset($t['term_id'])) {
                    update_term_meta((int) $t['term_id'], 'thb_coin_id', $coin->postId);
                }
            }
        }
    }

    /* ==================================================================
     * Content
     * ================================================================ */

    public function coin(string $slug): ?Coin
    {
        foreach ($this->coins->all() as $coin) {
            if ($coin->slug === $slug) {
                return $coin;
            }
        }
        return null;
    }

    public static function pillarName(string $pillar): string
    {
        return self::PILLARS[$pillar][0] ?? $pillar;
    }

    private function term(string $coinSlug): ?object
    {
        $t = get_term_by('slug', $coinSlug, self::TAX_COIN);
        return is_object($t) ? $t : null;
    }

    /** The pillar's intro (HTML the editor wrote), or ''. */
    public function intro(string $coinSlug, string $pillar): string
    {
        $t = $this->term($coinSlug);
        return $t ? trim((string) get_term_meta((int) $t->term_id, 'thb_intro_' . $pillar, true)) : '';
    }

    /** The pillar's FAQ: one «پرسش | پاسخ» per line. @return array<int, array{q:string, a:string}> */
    public function faq(string $coinSlug, string $pillar): array
    {
        $t = $this->term($coinSlug);
        return $t ? self::parseFaq((string) get_term_meta((int) $t->term_id, 'thb_faq_' . $pillar, true)) : [];
    }

    /** @return array<int, array{q:string, a:string}> */
    public static function parseFaq(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
                $out[] = ['q' => $parts[0], 'a' => $parts[1]];
            }
        }
        return $out;
    }

    /**
     * Published articles of a coin, optionally of one pillar, newest first.
     *
     * @param int[] $exclude
     * @return array<int, object> WP_Post objects
     */
    public function articles(string $coinSlug, ?string $pillar = null, int $limit = 12, array $exclude = []): array
    {
        $key = implode('|', [$coinSlug, $pillar ?? '*', $limit, implode(',', $exclude)]);
        if (isset($this->memo['a'][$key])) {
            return $this->memo['a'][$key];
        }
        $tax = [['taxonomy' => self::TAX_COIN, 'field' => 'slug', 'terms' => [$coinSlug]]];
        if ($pillar !== null) {
            $tax[] = ['taxonomy' => self::TAX_PILLAR, 'field' => 'slug', 'terms' => [$pillar]];
        } else {
            $tax[] = ['taxonomy' => self::TAX_PILLAR, 'operator' => 'EXISTS'];
        }
        $posts = get_posts([
            'post_type'        => 'post',
            'post_status'      => 'publish',
            'numberposts'      => $limit,
            'orderby'          => 'date',
            'order'            => 'DESC',
            'post__not_in'     => $exclude,
            'tax_query'        => ['relation' => 'AND'] + $tax,
            'suppress_filters' => false,
        ]);
        return $this->memo['a'][$key] = array_values(array_filter((array) $posts, 'is_object'));
    }

    /** Sticky posts among a pillar's articles. */
    public function featured(string $coinSlug, string $pillar): array
    {
        $sticky = array_map('intval', (array) get_option('sticky_posts', []));
        return array_values(array_filter($this->articles($coinSlug, $pillar, 50), static fn($p) => in_array((int) $p->ID, $sticky, true)));
    }

    public function hasContent(string $coinSlug, string $pillar): bool
    {
        if (!isset(self::PILLARS[$pillar]) || $this->coin($coinSlug) === null) {
            return false;
        }
        return $this->intro($coinSlug, $pillar) !== '' || $this->articles($coinSlug, $pillar, 1) !== [];
    }

    /** The pillars of a coin that have content, in fixed order. Every link to a pillar comes from here. @return string[] */
    public function pillarsWithContent(string $coinSlug): array
    {
        return $this->memo['p'][$coinSlug] ??= array_values(array_filter(array_keys(self::PILLARS), fn($p) => $this->hasContent($coinSlug, $p)));
    }

    /** [coin slug, pillar] of a post, or null when it is not a hub article. */
    public function postHub($post): ?array
    {
        $post = is_object($post) ? $post : (function_exists('get_post') ? get_post((int) $post) : null);
        if (!is_object($post) || ($post->post_type ?? 'post') !== 'post') {
            return null;
        }
        $coins = (array) wp_get_post_terms((int) $post->ID, self::TAX_COIN, ['fields' => 'slugs']);
        $pillars = (array) wp_get_post_terms((int) $post->ID, self::TAX_PILLAR, ['fields' => 'slugs']);
        $coin = null;
        foreach ($coins as $c) {
            if (is_string($c) && $this->coin($c) !== null) { $coin = $c; break; }
        }
        $pillar = null;
        foreach (array_keys(self::PILLARS) as $p) {
            if (in_array($p, $pillars, true)) { $pillar = $p; break; }
        }
        return $coin !== null && $pillar !== null ? [$coin, $pillar] : null;
    }

    public static function hubUrl(string $coinSlug): string
    {
        return trailingslashit(home_url('/coins/' . $coinSlug));
    }

    public static function pillarUrl(string $coinSlug, string $pillar): string
    {
        return trailingslashit(home_url('/coins/' . $coinSlug . '/' . $pillar));
    }

    /** A hub article's permalink is its hub URL. */
    public function postLink(string $url, $post): string
    {
        $hub = $this->postHub($post);
        if ($hub === null || empty($post->post_name) || ($post->post_status ?? 'publish') !== 'publish') {
            return $url;
        }
        return trailingslashit(home_url('/coins/' . $hub[0] . '/' . $hub[1] . '/' . $post->post_name));
    }

    /* ==================================================================
     * The request
     * ================================================================ */

    /** The pillar route being served: [coin, pillar], valid or not; null elsewhere. */
    public static function requested(): ?array
    {
        $coin = sanitize_title((string) get_query_var(self::VAR_COIN));
        $pillar = sanitize_key((string) get_query_var(self::VAR_PILLAR));
        return $coin !== '' && $pillar !== '' ? [$coin, $pillar] : null;
    }

    /** A pillar PAGE (not an article) that has content. */
    public function currentPillar(): ?array
    {
        $r = self::requested();
        if ($r === null || (string) get_query_var('name') !== '') {
            return null;
        }
        return $this->hasContent($r[0], $r[1]) ? $r : null;
    }

    /** The hub article being viewed: [post, coin, pillar], or null. */
    public function currentArticle(): ?array
    {
        if (!function_exists('is_singular') || !is_singular('post')) {
            return null;
        }
        $post = function_exists('get_queried_object') ? get_queried_object() : null;
        $hub = is_object($post) ? $this->postHub($post) : null;
        return $hub === null ? null : [$post, $hub[0], $hub[1]];
    }

    /**
     * 404 for an empty or unknown pillar; 301 for an article reached at any URL
     * but its own (it moved to another pillar or coin, or an old /slug/ link).
     */
    public function route(): void
    {
        global $wp_query;
        $r = self::requested();
        $isArticleRoute = $r !== null && (string) get_query_var('name') !== '';

        if ($r !== null && !$isArticleRoute) {
            if ($this->hasContent($r[0], $r[1])) {
                if (is_object($wp_query)) {
                    $wp_query->is_404 = false;
                    $wp_query->is_home = false;
                }
                status_header(200);
            } else {
                if (is_object($wp_query) && method_exists($wp_query, 'set_404')) {
                    $wp_query->set_404();
                }
                status_header(404);
                nocache_headers();
            }
            return;
        }

        if (function_exists('is_singular') && is_singular('post')) {
            $post = get_queried_object();
            $hub = is_object($post) ? $this->postHub($post) : null;
            if (($post->post_status ?? 'publish') !== 'publish' || (function_exists('is_preview') && is_preview())) {
                return;   // drafts and previews keep WordPress's own URLs
            }
            if ($hub !== null && ($r === null || $r[0] !== $hub[0] || $r[1] !== $hub[1])) {
                $this->redirect((string) get_permalink($post));
            } elseif ($hub === null && $isArticleRoute) {
                $this->redirect((string) get_permalink($post));   // not a hub article: its own URL
            }
        }
    }

    /** 301 to an article's one URL. The filter lets a test (or another plugin) observe it instead. */
    private function redirect(string $url): void
    {
        if (apply_filters('thb_hub_redirect', true, $url)) {
            wp_safe_redirect($url, 301);
            exit;
        }
    }

    public function template(string $template): string
    {
        $file = null;
        if ($this->currentPillar() !== null) {
            $file = 'pillar.php';
        } elseif ($this->currentArticle() !== null) {
            $file = 'article.php';
        }
        if ($file === null) {
            return $template;
        }
        $child = locate_template('thehybit/v2/' . $file);
        return $child !== '' ? $child : THB_COINS_DIR . 'templates/v2/' . $file;
    }

    /* ==================================================================
     * Table of contents: ids on the article's h2/h3
     * ================================================================ */

    public static function headingId(string $text, int $i): string
    {
        $slug = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower(wp_strip_all_tags($text))), '-');
        return 'h-' . ($slug !== '' ? mb_substr($slug, 0, 60) : (string) $i);
    }

    /** @return array<int, array{level:int, id:string, text:string}> */
    public static function headings(string $html): array
    {
        preg_match_all('#<h([23])\b([^>]*)>(.*?)</h\1>#isu', $html, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $i => $h) {
            $text = trim(html_entity_decode(wp_strip_all_tags($h[3]), ENT_QUOTES, 'UTF-8'));
            if ($text === '') {
                continue;
            }
            $id = preg_match('#\bid="([^"]+)"#', $h[2], $idm) ? $idm[1] : self::headingId($text, $i);
            $out[] = ['level' => (int) $h[1], 'id' => $id, 'text' => $text];
        }
        return $out;
    }

    /** Give each h2/h3 of a post with a TOC (or of a hub article) a stable id. */
    public function anchorHeadings(string $html): string
    {
        $i = -1;
        return (string) preg_replace_callback('#<h([23])\b([^>]*)>(.*?)</h\1>#isu', static function ($m) use (&$i) {
            $i++;
            if (preg_match('#\bid="#', $m[2])) {
                return $m[0];
            }
            $text = trim(html_entity_decode(wp_strip_all_tags($m[3]), ENT_QUOTES, 'UTF-8'));
            return $text === '' ? $m[0] : sprintf('<h%s%s id="%s">%s</h%1$s>', $m[1], $m[2], esc_attr(self::headingId($text, $i)), $m[3]);
        }, $html);
    }

    /* ==================================================================
     * SEO for pillar pages (articles are posts: the SEO plugin handles them)
     * ================================================================ */

    public function title(string $coinSlug, string $pillar): string
    {
        $coin = $this->coin($coinSlug);
        return self::pillarName($pillar) . ' ' . ($coin ? $coin->name : $coinSlug) . ' | ' . Seo::BRAND_SUFFIX;
    }

    public function description(string $coinSlug, string $pillar): string
    {
        $coin = $this->coin($coinSlug);
        $name = $coin ? $coin->name : $coinSlug;
        $intro = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($this->intro($coinSlug, $pillar))));
        if ($intro !== '') {
            return mb_strlen($intro) > 155 ? rtrim(mb_substr($intro, 0, 154)) . '…' : $intro;
        }
        $titles = array_map(static fn($p) => (string) $p->post_title, $this->articles($coinSlug, $pillar, 3));
        return sprintf(self::PILLARS[$pillar][1], $name) . ($titles ? ': ' . implode('، ', $titles) : '') . ' — ' . Seo::BRAND_SUFFIX;
    }

    public function documentTitle(array $parts): array
    {
        $p = $this->currentPillar();
        return $p === null ? $parts : ['title' => $this->title($p[0], $p[1])];
    }

    public function seoTitle(string $t): string
    {
        $p = $this->currentPillar();
        return $p === null ? $t : $this->title($p[0], $p[1]);
    }

    public function seoDescription(string $d): string
    {
        $p = $this->currentPillar();
        return $p === null ? $d : $this->description($p[0], $p[1]);
    }

    public function canonical(string $url): string
    {
        $p = $this->currentPillar();
        return $p === null ? $url : self::pillarUrl($p[0], $p[1]);
    }

    /** Structured data (and, without an SEO plugin, description + canonical). */
    public function head(): void
    {
        $graph = [];
        if (($p = $this->currentPillar()) !== null) {
            [$c, $pl] = $p;
            if (!defined('WPSEO_VERSION') && !class_exists('RankMath')) {
                printf("<meta name=\"description\" content=\"%s\">\n<link rel=\"canonical\" href=\"%s\">\n",
                    esc_attr($this->description($c, $pl)), esc_url(self::pillarUrl($c, $pl)));
            }
            $graph = $this->pillarSchema($c, $pl);
        } elseif (($a = $this->currentArticle()) !== null) {
            $graph = $this->articleSchema($a[0], $a[1], $a[2]);
        }
        if ($graph !== []) {
            echo '<script type="application/ld+json">'
                . wp_json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . "</script>\n";
        }
    }

    /** @return array<int, array{name:string, url:string}> */
    public function trail(string $coinSlug, ?string $pillar = null, ?object $post = null): array
    {
        $coin = $this->coin($coinSlug);
        $t = [
            ['name' => 'کوین‌ها', 'url' => trailingslashit(home_url('/coins'))],
            ['name' => $coin ? $coin->name : $coinSlug, 'url' => self::hubUrl($coinSlug)],
        ];
        if ($pillar !== null) {
            $t[] = ['name' => self::pillarName($pillar), 'url' => self::pillarUrl($coinSlug, $pillar)];
        }
        if ($post !== null) {
            $t[] = ['name' => (string) $post->post_title, 'url' => (string) get_permalink($post)];
        }
        return $t;
    }

    private static function breadcrumbSchema(array $trail): array
    {
        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(static fn($i, $t) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $t['name'], 'item' => $t['url']],
                array_keys($trail), $trail),
        ];
    }

    private static function faqSchema(array $faq): ?array
    {
        if ($faq === []) {
            return null;
        }
        return [
            '@type' => 'FAQPage',
            'mainEntity' => array_map(static fn($f) => [
                '@type' => 'Question', 'name' => wp_strip_all_tags($f['q']),
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => wp_strip_all_tags($f['a'])],
            ], $faq),
        ];
    }

    public function pillarSchema(string $coinSlug, string $pillar): array
    {
        $url = self::pillarUrl($coinSlug, $pillar);
        $items = $this->articles($coinSlug, $pillar, 24);
        $graph = [
            self::breadcrumbSchema($this->trail($coinSlug, $pillar)),
            [
                '@type' => 'CollectionPage', '@id' => $url . '#page', 'url' => $url, 'inLanguage' => 'fa-IR',
                'name' => trim(explode('|', $this->title($coinSlug, $pillar))[0]),
                'description' => $this->description($coinSlug, $pillar),
                'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => array_map(static fn($i, $p) => [
                    '@type' => 'ListItem', 'position' => $i + 1, 'url' => (string) get_permalink($p), 'name' => (string) $p->post_title,
                ], array_keys($items), $items)],
            ],
        ];
        if (($faq = self::faqSchema($this->faq($coinSlug, $pillar))) !== null) {
            $graph[] = $faq;
        }
        return $graph;
    }

    public function articleSchema(object $post, string $coinSlug, string $pillar): array
    {
        $url = (string) get_permalink($post);
        $author = (int) ($post->post_author ?? 0);
        $image = function_exists('get_the_post_thumbnail_url') ? get_the_post_thumbnail_url($post, 'large') : false;
        $article = array_filter([
            '@type' => 'Article', '@id' => $url . '#article', 'mainEntityOfPage' => $url, 'inLanguage' => 'fa-IR',
            'headline' => mb_substr(wp_strip_all_tags((string) $post->post_title), 0, 110),
            'datePublished' => self::iso((string) ($post->post_date_gmt ?? '')),
            'dateModified' => self::iso((string) ($post->post_modified_gmt ?? $post->post_date_gmt ?? '')),
            'author' => $author ? ['@type' => 'Person', 'name' => (string) get_the_author_meta('display_name', $author)] : null,
            'publisher' => ['@type' => 'Organization', 'name' => Seo::BRAND_SUFFIX, 'url' => home_url('/')],
            'image' => $image ?: null,
            'about' => ['@type' => 'Thing', 'name' => ($c = $this->coin($coinSlug)) ? $c->name : $coinSlug, 'url' => self::hubUrl($coinSlug)],
            'articleSection' => self::pillarName($pillar),
        ], static fn($v) => $v !== null && $v !== '');
        $graph = [self::breadcrumbSchema($this->trail($coinSlug, $pillar, $post)), $article];
        if (($faq = self::faqSchema(Blocks::faqFromContent((string) ($post->post_content ?? '')))) !== null) {
            $graph[] = $faq;
        }
        return $graph;
    }

    public static function iso(string $gmt): ?string
    {
        if ($gmt === '' || str_starts_with($gmt, '0000')) {
            return null;
        }
        $t = strtotime($gmt . ' UTC');
        return $t === false ? null : gmdate('c', $t);
    }

    /* ==================================================================
     * Sitemap
     * ================================================================ */

    /**
     * Every pillar that has content and every hub article, with lastmod:
     * the article's own modified time; for a pillar, its newest article's
     * modified time or the time its intro was last saved, whichever is later.
     *
     * @return array<int, array{loc:string, lastmod:?string}>
     */
    public function sitemapEntries(): array
    {
        $out = [];
        foreach ($this->coins->all() as $coin) {
            foreach ($this->pillarsWithContent($coin->slug) as $pillar) {
                $times = [];
                $t = $this->term($coin->slug);
                if ($t && ($at = (int) get_term_meta((int) $t->term_id, 'thb_intro_' . $pillar . '_at', true)) > 0) {
                    $times[] = $at;
                }
                $articles = $this->articles($coin->slug, $pillar, 200);
                foreach ($articles as $p) {
                    $m = strtotime((string) ($p->post_modified_gmt ?? '') . ' UTC');
                    if ($m) { $times[] = $m; }
                }
                $out[] = ['loc' => self::pillarUrl($coin->slug, $pillar), 'lastmod' => $times ? gmdate('c', max($times)) : null];
                foreach ($articles as $p) {
                    $out[] = ['loc' => (string) get_permalink($p), 'lastmod' => self::iso((string) ($p->post_modified_gmt ?? ''))];
                }
            }
        }
        return $out;
    }

    /** IDs of hub articles (kept out of the posts sitemap: they are in ours). */
    public function articleIds(): array
    {
        $ids = [];
        foreach ($this->coins->all() as $coin) {
            foreach ($this->articles($coin->slug, null, 500) as $p) {
                $ids[] = (int) $p->ID;
            }
        }
        return array_values(array_unique($ids));
    }

    public function coreSitemap($server): void
    {
        if (is_object($server) && class_exists('WP_Sitemaps_Provider')) {
            require_once THB_COINS_DIR . 'includes/HubSitemapProvider.php';
            $server->registry->add_provider('thbhub', new HubSitemapProvider($this));
        }
    }

    public function excludeFromPostsSitemap(array $args, string $postType): array
    {
        if ($postType === 'post') {
            $args['tax_query'] = [['taxonomy' => self::TAX_PILLAR, 'operator' => 'NOT EXISTS']];
        }
        return $args;
    }

    public function yoastIndex(string $xml): string
    {
        $entries = array_filter(array_column($this->sitemapEntries(), 'lastmod'));
        return $xml . sprintf("<sitemap>\n<loc>%s</loc>\n%s</sitemap>\n", esc_url(home_url('/thbhub-sitemap.xml')),
            $entries ? '<lastmod>' . esc_html(max($entries)) . "</lastmod>\n" : '');
    }

    public function yoastRegister(): void
    {
        if (isset($GLOBALS['wpseo_sitemaps']) && is_object($GLOBALS['wpseo_sitemaps']) && method_exists($GLOBALS['wpseo_sitemaps'], 'register_sitemap')) {
            $GLOBALS['wpseo_sitemaps']->register_sitemap('thbhub', function (): void {
                $GLOBALS['wpseo_sitemaps']->set_sitemap($this->sitemapXml());
            });
        }
    }

    public function yoastExclude(array $ids): array
    {
        return array_values(array_unique(array_merge($ids, $this->articleIds())));
    }

    public function sitemapXml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($this->sitemapEntries() as $e) {
            $xml .= '<url><loc>' . esc_url($e['loc']) . '</loc>' . ($e['lastmod'] ? '<lastmod>' . esc_html($e['lastmod']) . '</lastmod>' : '') . "</url>\n";
        }
        return $xml . '</urlset>';
    }

    /* ==================================================================
     * Admin: each pillar's intro and FAQ, on the coin's term screen
     * ================================================================ */

    public function termFields($term): void
    {
        if (!is_object($term)) {
            return;
        }
        wp_nonce_field('thb_hub_term', 'thb_hub_nonce');
        echo '<tr><th colspan="2"><h2>ستون‌های محتوا</h2><p class="description">برای هر ستون یک مقدمه بنویسید. '
           . 'ستونی که نه مقدمه دارد و نه مقاله منتشرشده، صفحه ندارد (۴۰۴) و در هیچ جای سایت به آن پیوند داده نمی‌شود. '
           . 'پرسش‌ها: هر خط یک «پرسش | پاسخ».</p></th></tr>';
        foreach (self::PILLARS as $slug => [$name]) {
            printf('<tr class="form-field"><th scope="row"><label for="thb-intro-%1$s">%2$s — مقدمه</label></th><td>'
                . '<textarea id="thb-intro-%1$s" name="thb_intro[%1$s]" rows="4">%3$s</textarea></td></tr>'
                . '<tr class="form-field"><th scope="row"><label for="thb-faq-%1$s">%2$s — پرسش‌ها</label></th><td>'
                . '<textarea id="thb-faq-%1$s" name="thb_faq[%1$s]" rows="3" placeholder="پرسش؟ | پاسخ">%4$s</textarea></td></tr>',
                esc_attr($slug), esc_html($name),
                esc_textarea((string) get_term_meta((int) $term->term_id, 'thb_intro_' . $slug, true)),
                esc_textarea((string) get_term_meta((int) $term->term_id, 'thb_faq_' . $slug, true)));
        }
    }

    public function saveTermFields(int $termId): void
    {
        if (!current_user_can('manage_categories') || !isset($_POST['thb_hub_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['thb_hub_nonce'])), 'thb_hub_term')) {
            return;
        }
        foreach (array_keys(self::PILLARS) as $slug) {
            $intro = isset($_POST['thb_intro'][$slug]) ? wp_kses_post(wp_unslash((string) $_POST['thb_intro'][$slug])) : null;
            $faq = isset($_POST['thb_faq'][$slug]) ? sanitize_textarea_field(wp_unslash((string) $_POST['thb_faq'][$slug])) : null;
            if ($intro !== null && $intro !== (string) get_term_meta($termId, 'thb_intro_' . $slug, true)) {
                update_term_meta($termId, 'thb_intro_' . $slug, $intro);
                update_term_meta($termId, 'thb_intro_' . $slug . '_at', time());
            }
            if ($faq !== null) {
                update_term_meta($termId, 'thb_faq_' . $slug, $faq);
            }
        }
    }
}
