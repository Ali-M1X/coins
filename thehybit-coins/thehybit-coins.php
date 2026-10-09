<?php
/**
 * Plugin Name: TheHybit — Coins
 * Description: Coin configuration, provider collectors, caching, historical storage and the coin detail page pipeline.
 * Version:     2.0.3
 * Requires PHP: 8.1
 * Author:      TheHybit
 *
 * A PLUGIN, not a child theme, on purpose: the data layer (collectors, cache,
 * history, ACF mapping) must survive a theme change. Only the templates are
 * theme-overridable — drop a copy into the child theme and it wins.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

define('THB_COINS_FILE', __FILE__);
define('THB_COINS_DIR', plugin_dir_path(__FILE__));
define('THB_COINS_URL', plugin_dir_url(__FILE__));
define('THB_COINS_VERSION', '2.0.3');

require_once THB_COINS_DIR . 'includes/Coin.php';
require_once THB_COINS_DIR . 'includes/Format.php';
require_once THB_COINS_DIR . 'includes/Seeder.php';
require_once THB_COINS_DIR . 'includes/AdminSetup.php';
require_once THB_COINS_DIR . 'includes/CoinRepository.php';
require_once THB_COINS_DIR . 'includes/Settings.php';
require_once THB_COINS_DIR . 'includes/Design.php';
require_once THB_COINS_DIR . 'includes/LivePrice.php';
require_once THB_COINS_DIR . 'includes/Datasets.php';
require_once THB_COINS_DIR . 'includes/Budget.php';
require_once THB_COINS_DIR . 'includes/Lock.php';
require_once THB_COINS_DIR . 'includes/Cache.php';
require_once THB_COINS_DIR . 'includes/History.php';
require_once THB_COINS_DIR . 'includes/Overrides.php';
require_once THB_COINS_DIR . 'includes/Derive.php';
require_once THB_COINS_DIR . 'includes/Context.php';
require_once THB_COINS_DIR . 'includes/Scoring.php';
require_once THB_COINS_DIR . 'includes/News.php';
require_once THB_COINS_DIR . 'includes/Pipeline.php';
require_once THB_COINS_DIR . 'includes/Scheduler.php';
require_once THB_COINS_DIR . 'includes/Seo.php';
require_once THB_COINS_DIR . 'includes/Schema.php';
require_once THB_COINS_DIR . 'includes/ProviderProbe.php';
require_once THB_COINS_DIR . 'includes/Diagnostics.php';
require_once THB_COINS_DIR . 'includes/V2/Svg.php';
require_once THB_COINS_DIR . 'includes/V2/Model.php';
require_once THB_COINS_DIR . 'includes/V2/View.php';
require_once THB_COINS_DIR . 'includes/Collectors/Collector.php';
require_once THB_COINS_DIR . 'includes/Collectors/CoinGecko.php';
require_once THB_COINS_DIR . 'includes/Collectors/DefiLlama.php';
require_once THB_COINS_DIR . 'includes/Collectors/DexScreener.php';
require_once THB_COINS_DIR . 'includes/Collectors/L2Beat.php';
require_once THB_COINS_DIR . 'includes/Collectors/OnChain.php';
require_once THB_COINS_DIR . 'includes/Collectors/Fx.php';
require_once THB_COINS_DIR . 'includes/Collectors/Alternative.php';
require_once THB_COINS_DIR . 'includes/Collectors/Etherscan.php';
require_once THB_COINS_DIR . 'includes/Collectors/Blockchair.php';
require_once THB_COINS_DIR . 'includes/Collectors/BeaconChain.php';
require_once THB_COINS_DIR . 'includes/Collectors/GitHub.php';
require_once THB_COINS_DIR . 'includes/Collectors/LlamaPrices.php';
require_once THB_COINS_DIR . 'includes/Collectors/CoinMetrics.php';
require_once THB_COINS_DIR . 'includes/Collectors/Lido.php';
require_once THB_COINS_DIR . 'includes/Collectors/Wikimedia.php';

final class Plugin
{
    private static ?Plugin $instance = null;

    public array $config;
    public CoinRepository $coins;
    public Cache $cache;
    public History $history;
    public News $news;
    public Pipeline $pipeline;

    public static function instance(): Plugin
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        /* Keys live in a WordPress option, never in the committed config, and
           are merged in here — once, before any collector or the scheduler
           reads the array. See includes/Settings.php. */
        $this->config   = Settings::apply(require THB_COINS_DIR . 'config/providers.php');
        $this->coins    = new CoinRepository();
        $this->cache    = new Cache($this->config);
        $this->history  = new History($this->config);
        $this->news     = new News($this->config);
        $this->pipeline = new Pipeline($this->config, $this->cache, $this->history, $this->news);

        foreach ([
            Collectors\CoinGecko::class,
            Collectors\DefiLlama::class,
            Collectors\DexScreener::class,
            Collectors\L2Beat::class,
            Collectors\OnChain::class,
            Collectors\Fx::class,
            Collectors\Alternative::class,
            Collectors\Etherscan::class,
            Collectors\Blockchair::class,
            Collectors\BeaconChain::class,
            Collectors\GitHub::class,
            Collectors\LlamaPrices::class,
            Collectors\CoinMetrics::class,
            Collectors\Lido::class,
            Collectors\Wikimedia::class,
        ] as $class) {
            $this->pipeline->register(new $class($this->config));
        }
    }

    public function boot(): void
    {
        add_action('init', [CoinRepository::class, 'registerPostType']);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_filter('single_template', [$this, 'template']);

        // ACF field definitions live in the plugin, versioned with the code.
        add_filter('acf/settings/load_json', function (array $paths): array {
            $paths[] = THB_COINS_DIR . 'acf-json';
            return $paths;
        });

        // News cache invalidation: publishing or editing an article in a coin
        // category clears that category immediately rather than waiting a TTL.
        add_action('save_post_post', [$this->news, 'invalidateFor']);
        add_action('deleted_post', [$this->news, 'invalidateFor']);

        /* TEMPORARY one-time setup page. Not part of the pipeline; registers
           nothing once setup is finished. See includes/AdminSetup.php. */
        (new AdminSetup($this->coins))->register();

        /* v2 editorial content for coins that already exist: empty fields
           only, once per version. See Seeder::fillEditorial(). */
        add_action('admin_init', static function (): void {
            if (current_user_can('manage_options')) {
                Seeder::fillEditorial();
            }
        });

        /* Settings — API keys and the design selector. */
        (new Settings())->register();

        /* GET /wp-json/thehybit/v1/price/{slug} — cached live price, never a
           provider call. See includes/LivePrice.php. */
        (new LivePrice($this->config, $this->coins, $this->cache))->register();

                /* noindex for ?thb_design= previews; see includes/Design.php. */
        (new Design())->register();

        /* Admin-only provider diagnostics. Adds nothing to the front-end
           request path — see includes/Diagnostics.php. */
        (new Diagnostics($this->coins, $this->pipeline, $this->config))->register();

        (new Scheduler($this->config, $this->coins, $this->pipeline, $this->history))->register();
        (new Seo($this->coins, $this->pipeline))->register();
        (new Schema($this->coins, $this->pipeline))->register();
    }

    /**
     * The prototype's CSS and JS, unchanged. The approved UI ships as-is.
     */
    public function assets(): void
    {
        if (!is_singular(CoinRepository::POST_TYPE)) {
            return;
        }

        /* The v2 design has its own stylesheet, script and data island and
           loads NONE of the classic files; the classic branch below is the
           v1.8.0 code, unchanged. */
        if (Design::isV2()) {
            $this->assetsV2();
            return;
        }

        $base = THB_COINS_URL . 'assets';
        foreach (['tokens', 'base', 'components', 'coin-page'] as $sheet) {
            wp_enqueue_style("thb-$sheet", "$base/css/$sheet.css", [], THB_COINS_VERSION);
        }

        wp_enqueue_script('thb-coin', "$base/js/main.js", [], THB_COINS_VERSION, true);

        // The data island, server-side. readDataIsland() in dom.js reads this,
        // so the browser never calls a provider — it only draws what PHP sent.
        $coin = $this->coins->find(get_the_ID());
        if ($coin) {
            wp_add_inline_script(
                'thb-coin',
                'window.__THB_COIN__ = ' . wp_json_encode(
                    $this->pipeline->viewModel($coin),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ) . ';',
                'before'
            );
        }
    }

    /**
     * main.js and its imports are ES modules.
     *
     * REWRITE THE TAG IN PLACE — never build a replacement. WordPress applies
     * this filter to a string that ALREADY contains the handle's inline `before`
     * script and its translations:
     *
     *     $tag  = $translations . $before_script;
     *     $tag .= wp_get_script_tag( $attr );
     *     $tag .= $after_script;
     *     $tag  = apply_filters( 'script_loader_tag', $tag, $handle, $src );
     *
     * Returning a fresh <script src> therefore DISCARDS window.__THB_COIN__,
     * and every JS-driven part of the page — the price chart, the sparklines,
     * the watchlist, the copy button — silently stops working while the
     * server-rendered numbers keep looking fine. That is precisely the bug this
     * comment exists to prevent from being reintroduced.
     *
     * Only the tag carrying `src` is touched, so the inline block ahead of it is
     * left exactly as WordPress produced it.
     */
    public static function moduleTag(string $tag, string $handle, string $src): string
    {
        if ($handle !== 'thb-coin' && $handle !== 'thb-coin-v2') {
            return $tag;
        }

        $rewritten = preg_replace_callback(
            '#<script\b[^>]*\bsrc=[^>]*>#i',
            static function (array $m): string {
                // Drop any existing type (themes emit text/javascript or none
                // depending on html5 script support), then declare the module.
                // Idempotent, so a second pass cannot double the attribute.
                $open = preg_replace('#\s+type=([\'"])[^\'"]*\1#i', '', $m[0]);
                return preg_replace('#^<script\b#i', '<script type="module"', (string) $open, 1);
            },
            $tag,
            1
        );

        return is_string($rewritten) ? $rewritten : $tag;
    }

    public function template(string $template): string
    {
        if (!is_singular(CoinRepository::POST_TYPE)) {
            return $template;
        }

        if (Design::isV2()) {
            $theme = locate_template(['thehybit/v2/single-coin.php']);
            return $theme ?: THB_COINS_DIR . 'templates/v2/single-coin.php';
        }

        // A child theme copy wins over the plugin's.
        $theme = locate_template(['thehybit/single-coin.php']);
        return $theme ?: THB_COINS_DIR . 'templates/single-coin.php';
    }

    /** @var array<string, array> per-request v2 view models, keyed by coin slug */
    private array $v2Memo = [];

    /**
     * The v2 view model — built once per request and shared by the template
     * and the data island, exactly as viewModel() is for the classic page.
     * Reads the cache only; it never makes a provider request.
     */
    public function v2(Coin $coin): array
    {
        return $this->v2Memo[$coin->slug] ??= (new V2\Model($this->config, $this->cache))
            ->build($coin, $this->pipeline->viewModel($coin));
    }

    private function assetsV2(): void
    {
        $base = THB_COINS_URL . 'assets/v2';
        wp_enqueue_style('thb-v2', "$base/v2.css", [], THB_COINS_VERSION);
        wp_enqueue_script('thb-coin-v2', "$base/v2.js", [], THB_COINS_VERSION, true);

        $coin = $this->coins->find(get_the_ID());
        if ($coin) {
            /* Only what the scripts need to redraw on interaction — every
               figure on the page is already in the server-rendered HTML. */
            wp_add_inline_script(
                'thb-coin-v2',
                'window.__THB_V2__ = ' . wp_json_encode(
                    V2\Model::client($this->v2($coin)),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ) . ';',
                'before'
            );
        }
    }
}

add_filter('script_loader_tag', [Plugin::class, 'moduleTag'], 10, 3);

/* THE CUSTOM CRON RECURRENCE IS REGISTERED HERE, AT FILE SCOPE, ON PURPOSE.
 *
 * It cannot wait for Plugin::boot(). WordPress includes a plugin's file in
 * order to activate it, and by that point `plugins_loaded` has already fired
 * for that request — so boot() never runs during activation, and anything it
 * registers does not exist when register_activation_hook() fires moments later.
 *
 * That is what broke scheduling: wp_schedule_event() looked up 'thb_tick',
 * wp_get_schedules() did not have it, and the call returned false without a
 * word. Activation reported success and the site had no warming event at all.
 *
 * At file scope the filter is present for activation, for every normal request,
 * and — just as importantly — for wp-cron.php, which needs it to reschedule the
 * event after each run. */
Scheduler::registerSchedule();

add_action('plugins_loaded', static fn() => Plugin::instance()->boot());

register_activation_hook(__FILE__, static function (): void {
    History::install();
    CoinRepository::registerPostType();
    Scheduler::activate(require THB_COINS_DIR . 'config/providers.php');
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, static function (): void {
    Scheduler::deactivate(require THB_COINS_DIR . 'config/providers.php');
    flush_rewrite_rules();
});
