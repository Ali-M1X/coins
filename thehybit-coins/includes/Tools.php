<?php
/**
 * Standalone tool pages under /coins/ (2.10.0): /coins/convert/.
 *
 * A rewrite rule and a query var, served by a v2 template with the theme's
 * own header and footer. These pages read the cache only — like every other
 * page of this plugin, a visit never reaches a provider.
 *
 * SEO: each tool page is ONE indexable page with its own title, description
 * and canonical. The same page with parameters (?from=…&to=…&amount=…) is a
 * state of that page, not a page of its own: noindex, follow, canonical to
 * the bare URL — so a thousand combinations never become a thousand thin
 * pages.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class Tools
{
    public const VAR = 'thb_tool';
    public const OPTION_REWRITE = 'thb_tools_rewrite';

    /** slug => [template, title, description] */
    public const PAGES = [
        'convert' => [
            'template' => 'convert.php',
            'title' => 'مبدل قیمت ارز دیجیتال به دلار و تومان',
            'description' => 'تبدیل بیت‌کوین، اتریوم، تتر و ده‌ها ارز دیگر به دلار، تومان و به یکدیگر با آخرین قیمت ذخیره‌شده و قیمت زنده هر جا در دسترس است؛ با زمان دقیق هر نرخ — %s.',
            'params' => ['from', 'to', 'amount'],
        ],
        /* 2.11.0. Every pair or trio is a state of this one page:
           noindex, canonical here — see parameterised(). */
        'compare' => [
            'template' => 'compare.php',
            'title' => 'مقایسه ارزهای دیجیتال',
            'description' => 'دو یا سه ارز دیجیتال را کنار هم مقایسه کنید: قیمت، ارزش بازار، حجم معاملات، عرضه، امتیاز تحلیلی و مؤلفه‌های آن، دسته و ارزش قفل‌شده؛ فقط با داده واقعی — %s.',
            'params' => ['c', 'c1', 'c2', 'c3'],
        ],
    ];

    public function register(): void
    {
        add_action('init', [$this, 'rules']);
        add_filter('query_vars', static fn(array $v): array => array_merge($v, [self::VAR]));
        add_filter('template_include', [$this, 'template'], 20);
        add_action('admin_init', [$this, 'flushOnce']);
        add_filter('document_title_parts', [$this, 'documentTitle'], 20);
        add_filter('wpseo_title', [$this, 'seoTitle'], 20);
        add_filter('wpseo_opengraph_title', [$this, 'seoTitle'], 20);
        add_filter('wpseo_metadesc', [$this, 'seoDescription'], 20);
        add_filter('wpseo_opengraph_desc', [$this, 'seoDescription'], 20);
        add_filter('wpseo_canonical', [$this, 'canonical'], 20);
        add_filter('wpseo_robots', [$this, 'yoastRobots'], 20);
        add_filter('wp_robots', [$this, 'coreRobots'], 20);
        add_action('wp_head', [$this, 'head'], 2);
    }

    public function rules(): void
    {
        foreach (array_keys(self::PAGES) as $slug) {
            add_rewrite_rule('^coins/' . $slug . '/?$', 'index.php?' . self::VAR . '=' . $slug, 'top');
        }
    }

    /** New rules need one flush; done once per version, from wp-admin. */
    public function flushOnce(): void
    {
        if (get_option(self::OPTION_REWRITE) !== THB_COINS_VERSION) {
            $this->rules();
            flush_rewrite_rules(false);
            update_option(self::OPTION_REWRITE, THB_COINS_VERSION, false);
        }
    }

    public static function current(): ?string
    {
        $tool = function_exists('get_query_var') ? (string) get_query_var(self::VAR) : '';
        return isset(self::PAGES[$tool]) ? $tool : null;
    }

    public static function url(string $slug, array $args = []): string
    {
        $base = trailingslashit(home_url('/coins/' . $slug));
        return $args === [] ? $base : add_query_arg(array_map('rawurlencode', $args), $base);
    }

    /** True when the page carries one of its own parameters: a state, not a page. */
    public static function parameterised(): bool
    {
        $tool = self::current();
        if ($tool === null) {
            return false;
        }
        foreach (self::PAGES[$tool]['params'] as $p) {
            if (isset($_GET[$p]) && $_GET[$p] !== '') {
                return true;
            }
        }
        return false;
    }

    public function template(string $template): string
    {
        $tool = self::current();
        if ($tool === null) {
            return $template;
        }
        $child = locate_template('thehybit/v2/' . self::PAGES[$tool]['template']);
        return $child !== '' ? $child : THB_COINS_DIR . 'templates/v2/' . self::PAGES[$tool]['template'];
    }

    public static function title(string $tool): string
    {
        /* A comparison names its coins (it is noindex, but shared links and
           browser tabs read the title). */
        if ($tool === 'compare' && self::parameterised()) {
            $bySlug = [];
            foreach (Plugin::instance()->coins->all() as $c) {
                $bySlug[$c->slug] = $c->name;
            }
            $names = array_map(static fn($s) => $bySlug[$s], V2\Compare::requested(Plugin::instance()->coins));
            if (count($names) >= 2) {
                return 'مقایسه ' . implode(' و ', $names) . ' | ' . Seo::BRAND_SUFFIX;
            }
        }
        return self::PAGES[$tool]['title'] . ' | ' . Seo::BRAND_SUFFIX;
    }

    public static function description(string $tool): string
    {
        return sprintf(self::PAGES[$tool]['description'], Seo::BRAND_SUFFIX);
    }

    public function documentTitle(array $parts): array
    {
        $tool = self::current();
        return $tool === null ? $parts : ['title' => self::title($tool)];
    }

    public function seoTitle(string $t): string
    {
        $tool = self::current();
        return $tool === null ? $t : self::title($tool);
    }

    public function seoDescription(string $d): string
    {
        $tool = self::current();
        return $tool === null ? $d : self::description($tool);
    }

    public function canonical(string $url): string
    {
        $tool = self::current();
        return $tool === null ? $url : self::url($tool);
    }

    public function coreRobots(array $robots): array
    {
        if (!self::parameterised()) {
            return $robots;
        }
        unset($robots['index']);
        $robots['noindex'] = true;
        $robots['follow'] = true;
        return $robots;
    }

    public function yoastRobots(string $robots): string
    {
        return self::parameterised() ? 'noindex, follow' : $robots;
    }

    /** Without an SEO plugin, the description and canonical a tool page needs. */
    public function head(): void
    {
        $tool = self::current();
        if ($tool === null || defined('WPSEO_VERSION') || class_exists('RankMath')) {
            return;
        }
        printf("<meta name=\"description\" content=\"%s\">\n<link rel=\"canonical\" href=\"%s\">\n",
            esc_attr(self::description($tool)), esc_url(self::url($tool)));
    }
}
