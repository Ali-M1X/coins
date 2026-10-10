<?php
/**
 * Provider diagnostics — Tools → TheHybit Coins.
 *
 * WHY THIS IS PART OF THE PLUGIN AND NOT A THROWAWAY SCRIPT
 *
 * Everything upstream of the template is invisible from a browser. When a
 * section does not render there is no way, from the outside, to tell apart:
 *
 *   the provider refused us          (429 / 403 / timeout)
 *   the provider answered nothing    (empty result set)
 *   we asked the wrong question      (wrong endpoint, wrong identifier)
 *   we misread a good answer         (filter discarded everything)
 *   the cache is holding a failure   (miss marker, or a stale payload)
 *   the coin is misconfigured        (an ACF field nobody filled in)
 *
 * All six look identical on the page: the card is simply absent. This screen
 * shows which one it is, on the server where it is actually happening, with the
 * exact URL and the exact response.
 *
 * READ-ONLY BY DEFAULT. It reports what the cache already holds. The live check
 * is a separate, nonced action that bypasses the cache and issues real calls —
 * it costs rate-limit budget, so it is never automatic.
 *
 * Administrators only, and it adds nothing to the front-end request path: the
 * HTTP trace hook it relies on is dormant unless this page is open.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class Diagnostics
{
    private const CAP  = 'manage_options';
    private const SLUG = 'thb-coins-diagnostics';

    /** @var array<int, array<string, mixed>> captured outbound calls */
    private array $calls = [];

    public function __construct(private CoinRepository $coins, private Pipeline $pipeline, private array $config) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_thb_diag_live', [$this, 'handleLive']);
        add_action('admin_post_thb_diag_seed', [$this, 'handleSeed']);
        add_action('admin_post_thb_diag_probe', [$this, 'handleProbe']);
        add_action('admin_post_thb_diag_burst', [$this, 'handleBurst']);
    }

    public function menu(): void
    {
        add_submenu_page(
            'tools.php',
            'TheHybit Coins — تشخیص',
            'TheHybit Coins',
            self::CAP,
            self::SLUG,
            [$this, 'render']
        );
    }

    /* ------------------------------------------------------------------
     * Actions
     * ---------------------------------------------------------------- */

    /** Bypass the cache for one coin and record every call it makes. */
    public function handleLive(): void
    {
        $slug = $this->authorize('thb_diag_live');

        set_transient('thb_diag_run_' . $slug, ['at' => time()], 10 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg(
            ['page' => self::SLUG, 'coin' => $slug, 'live' => '1'],
            admin_url('tools.php')
        ));
        exit;
    }

    /**
     * Re-run the seeder.
     *
     * Needed because config/coins.php gains fields over time — thb_dex_token_address
     * arrived in 1.1.0 — and a coin created by an earlier version simply has no
     * value for them. The seeder only fills blanks, so this can never overwrite
     * an editor's work.
     */
    public function handleSeed(): void
    {
        $this->authorize('thb_diag_seed');
        $result = Seeder::run();
        set_transient('thb_diag_seed_result', $result, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'seeded' => '1'], admin_url('tools.php')));
        exit;
    }

    /**
     * Run the provider probe.
     *
     * Manual only. Nothing schedules this, nothing on the front end can reach
     * it, and it runs exactly once per button press — see includes/ProviderProbe.php.
     */
    public function handleProbe(): void
    {
        $this->authorize('thb_diag_probe');

        $probe = new ProviderProbe($this->config);
        update_option(ProviderProbe::OPTION_RESULT, $probe->run(), false);

        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'probed' => '1'], admin_url('tools.php')));
        exit;
    }

    /**
     * Find CoinGecko's real ceiling from this server's IP.
     *
     * Separate from the probe because it deliberately spends budget: it calls
     * the cheapest endpoint repeatedly until the first refusal. That refusal is
     * the number no documentation can give us.
     */
    public function handleBurst(): void
    {
        $this->authorize('thb_diag_burst');

        $probe = new ProviderProbe($this->config);
        set_transient('thb_probe_burst', $probe->burst(), HOUR_IN_SECONDS);

        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'burst' => '1'], admin_url('tools.php')));
        exit;
    }

    /** Capability + nonce, and the only request input read: a known coin slug. */
    private function authorize(string $action): string
    {
        if (!current_user_can(self::CAP)) {
            wp_die('اجازه دسترسی ندارید.');
        }
        check_admin_referer($action);

        $slug = isset($_POST['coin']) ? sanitize_key(wp_unslash($_POST['coin'])) : '';

        // Validated against the coins that exist, never trusted as a value.
        foreach ($this->coins->all() as $coin) {
            if ($coin->slug === $slug) {
                return $slug;
            }
        }
        return '';
    }

    /* ------------------------------------------------------------------
     * The page
     * ---------------------------------------------------------------- */

    public function render(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die('اجازه دسترسی ندارید.');
        }

        $coins = $this->coins->all();
        $slug = isset($_GET['coin']) ? sanitize_key(wp_unslash($_GET['coin'])) : ($coins[0]->slug ?? '');
        $coin = null;
        foreach ($coins as $c) {
            if ($c->slug === $slug) { $coin = $c; }
        }

        $live = isset($_GET['live']) && $coin && get_transient('thb_diag_run_' . $coin->slug);
        if ($live) {
            delete_transient('thb_diag_run_' . $coin->slug);
        }

        echo '<div class="wrap" dir="rtl"><h1>TheHybit Coins — تشخیص ارائه‌دهندگان</h1>';
        echo '<p>این صفحه فقط برای مدیران است و روی سرعت صفحات سایت اثری ندارد.</p>';

        $this->seedNotice();

        if (!$coin) {
            echo '<div class="notice notice-error"><p>هیچ ارزی پیکربندی نشده است.</p></div></div>';
            return;
        }

        $this->cronTable();
        $this->keysTable();
        $this->errorsTable();
        $this->budgetTable();
        $this->datasetTable($coin);
        $this->sourcesTable($coin);
        $this->schedulerTable();
        $this->probeSummary();
        $this->burstResult();

        $this->coinSwitcher($coins, $coin);
        $this->configTable($coin);

        if ($live) {
            add_action('thb_coins_http', [$this, 'capture']);
            $model = $this->pipeline->viewModel($coin, refresh: true);
            remove_action('thb_coins_http', [$this, 'capture']);
            $this->callsTable();
        } else {
            $model = $this->pipeline->viewModel($coin);
        }

        $this->chartTable($model);
        $this->dexTable($coin, $model);
        $this->cacheTable($coin);
        $this->storageTable();
        $this->actions($coin);

        echo '</div>';
    }

    /** Records one outbound call. Attached only while the live check runs. */
    public function capture(array $call): void
    {
        $this->calls[] = $call;
    }

    /**
     * Is background warming actually running, and is anything holding it up?
     *
     * The first table on the page, because when warming stops nothing else here
     * means what it appears to mean: the cache ages look like provider trouble,
     * the queue grows without explanation, and every figure on the site drifts
     * quietly out of date while the pages themselves keep rendering perfectly.
     */
    private function cronTable(): void
    {
        $scheduler = new Scheduler($this->config, $this->coins, $this->pipeline, new History($this->config));
        $h = $scheduler->health();
        $lock = $h['lock'];

        $label = match ($h['status']) {
            'running' => '🔄 در حال اجرا',
            'idle'    => '✅ بی‌کار — در انتظار تیک بعدی',
            'stalled' => '⛔ متوقف شده',
            default   => '⚠️ هنوز اجرا نشده',
        };

        $this->open('کران سیستمی و وضعیت زمان‌بند');

        $this->row('وضعیت', $label . ' — ' . $h['note']);
        $this->row(
            'آخرین اجرا',
            $h['lastRunAt'] === ''
                ? 'هرگز'
                : sprintf('%s (%s ثانیه پیش)', $h['lastRunAt'], (string) ($h['lastRunAgo'] ?? '?'))
        );
        $this->row(
            'اجرای بعدی',
            $h['nextRunAt'] === null
                ? '⛔ رویدادی زمان‌بندی نشده است — افزونه را غیرفعال و دوباره فعال کنید'
                : sprintf(
                    '%s (تا %s ثانیه دیگر)',
                    gmdate('Y-m-d H:i:s', (int) $h['nextRunAt']),
                    (string) $h['nextRunIn']
                )
        );
        $this->row('فاصله رویداد', sprintf('%d ثانیه', (int) $h['interval']));

        /* The whole point of this phase: warming must not depend on visitors. */
        $this->row(
            'کران داخلی وردپرس',
            $h['wpCronDisabled']
                ? '✅ خاموش (DISABLE_WP_CRON) — گرم‌سازی به بازدید صفحه وابسته نیست'
                : '⚠️ روشن — گرم‌سازی هنوز با بازدید صفحه اجرا می‌شود'
        );

        /* ---- the lock ---- */
        if ($lock['held']) {
            $lockCell = sprintf(
                '🔒 در اختیار pid %d روی %s — %d ثانیه است که گرفته شده، %d ثانیه تا انقضا',
                (int) $lock['pid'],
                (string) $lock['host'],
                (int) $lock['ageSeconds'],
                (int) $lock['expiresIn']
            );
        } elseif ($lock['expired']) {
            $lockCell = sprintf(
                '⚠️ منقضی و رهانشده (pid %d) — تیک قبلی تمام نشده است؛ تیک بعدی آن را تحویل می‌گیرد',
                (int) $lock['pid']
            );
        } else {
            $lockCell = '✅ آزاد';
        }
        $this->row('قفل', $lockCell);
        $this->row('عمر قفل', sprintf('%d ثانیه', (int) $lock['ttl']));

        // A rising takeover count means ticks are dying rather than finishing.
        $this->row(
            'تحویل‌گیری قفل',
            (int) $lock['takeovers'] === 0
                ? '۰ — هیچ تیکی نیمه‌کاره نمانده است'
                : sprintf(
                    '%d بار — آخرین بار %s پس از %d ثانیه',
                    (int) $lock['takeovers'],
                    gmdate('Y-m-d H:i:s', (int) $lock['lastTakeoverAt']),
                    (int) $lock['lastTakeoverHeldFor']
                )
        );

        $progress = get_option(Scheduler::OPTION_PROGRESS);
        $this->row(
            'آخرین مجموعه داده تکمیل‌شده',
            is_array($progress) && ($progress['lastDataset'] ?? '') !== ''
                ? (string) $progress['lastDataset']
                : '—'
        );

        if ($h['lastSkip'] !== null) {
            $this->row(
                'آخرین تیک رد شده',
                sprintf('%s — %s', (string) $h['lastSkip']['at'], (string) $h['lastSkip']['reason'])
            );
        }

        $this->close();

        /* The exact command for this server, always shown — not only when
           something is wrong. Setting cron up is a one-off job done from this
           screen, and hunting for the line afterwards is its own small misery. */
        $runner = Scheduler::runnerPath();
        $this->open('فرمان کران برای همین سرور');

        $this->row('نسخه PHP سایت', PHP_VERSION);
        $this->row('مفسر در حال اجرا', defined('PHP_BINARY') ? PHP_BINARY : '—');
        $this->row(
            'فایل اجراکننده',
            is_readable($runner) ? $runner . ' ✅' : $runner . ' ⛔ یافت نشد'
        );
        echo '<tr><th scope="row">فرمان کران</th><td><code dir="ltr" style="display:block;'
           . 'padding:8px;background:#f6f7f7;word-break:break-all;user-select:all">'
           . esc_html(Scheduler::cronCommand())
           . '</code></td></tr>';
        $this->close();

        if (!Scheduler::phpBinaryIsDerived()) {
            echo '<div class="notice notice-warning inline"><p>'
               . 'مسیر مفسر CLI از روی نسخه PHP سایت قابل استخراج نبود، پس '
               . '<code dir="ltr">/usr/local/bin/php</code> پیشنهاد شده است. این مسیر روی cPanel '
               . '«پیش‌فرض سرور» است و اغلب نسخه‌ای قدیمی‌تر از نسخه سایت را اجرا می‌کند. '
               . 'اگر کران کار نکرد، در cPanel → MultiPHP Manager نسخه دامنه را ببینید و از مسیر '
               . '<code dir="ltr">/opt/cpanel/ea-phpXX/root/usr/bin/php</code> استفاده کنید. '
               . 'این افزونه به PHP 8.1 یا بالاتر نیاز دارد.</p></div>';
        }

        if (!$h['wpCronDisabled'] || $h['status'] === 'stalled') {
            printf(
                '<div class="notice notice-%s inline"><p><strong>%s</strong><br>'
                . '۱. در <code dir="ltr">wp-config.php</code> پیش از خط <code dir="ltr">/* That\'s all, stop editing! */</code> این را اضافه کنید:'
                . '<br><code dir="ltr" style="display:inline-block;margin:6px 0">define(\'DISABLE_WP_CRON\', true);</code>'
                . '<br>۲. سپس در cPanel → Cron Jobs یک وظیفه «هر دقیقه» (<code dir="ltr">* * * * *</code>) '
                . 'با فرمان بالا بسازید. این فرمان یک اسکریپت PHP است، پس محدودیت '
                . '«Only PHP scripts are allowed» را رد می‌کند.</p></div>',
                $h['status'] === 'stalled' ? 'error' : 'warning',
                $h['status'] === 'stalled'
                    ? 'زمان‌بند متوقف شده است. کران سیستمی را راه‌اندازی کنید:'
                    : 'گرم‌سازی هنوز به بازدید صفحه وابسته است. برای مستقل کردن آن:'
            );
        }
    }

    /**
     * Which API keys are stored, and what each one unlocks.
     *
     * Shows a MASKED hint only — enough to recognise which key is stored, never
     * enough to use it — because this is the screen people screenshot. A
     * required key that is missing disables its provider at boot rather than
     * letting it fail on every request, and this table is where that is said.
     */
    /**
     * Why a dataset is not on the page. A dataset that fails every time looks,
     * to a visitor, like one still being fetched; this says which it is, and
     * what the provider answered.
     */
    private function errorsTable(): void
    {
        $this->open('آخرین خطای هر داده', ['داده', 'زمان (UTC)', 'پاسخ']);
        $errors = Cache::lastErrors();
        if ($errors === []) {
            echo '<tr><td colspan="3">هیچ داده‌ای در آخرین تلاش خطا نداشته است.</td></tr>';
        }
        foreach ($errors as $dataset => $e) {
            // Keys never reach the screen, whichever provider's message this is.
            $message = (string) $e['message'];
            foreach ((array) ($this->config['providers'] ?? []) as $ps) {
                $message = Settings::scrub($message, (array) $ps);
            }
            $paused = isset($e['paused']) ? '<br><small>now: ' . esc_html((string) $e['paused']) . '</small>' : '';
            printf('<tr><td><code>%s</code></td><td>%s</td><td dir="ltr" style="text-align:left">%s%s</td></tr>',
                esc_html($dataset), esc_html($e['at']), esc_html($message), $paused);
        }
        $this->close();
    }

    /**
     * What used to be the public page's «منابع داده» footnote: which source
     * each figure on the coin page came from, and when it was fetched. An
     * admin record, not reading matter — so it lives here. Built from the same
     * cached view model the page renders; it never asks a provider itself.
     */
    private function sourcesTable(Coin $coin): void
    {
        $this->open('منابع داده صفحه ' . $coin->name . ' و زمان آخرین به‌روزرسانی', ['داده', 'منبع', 'دریافت (تهران)', 'دریافت (UTC)']);
        $sources = (array) (Plugin::instance()->v2($coin)['sources'] ?? []);
        if ($sources === []) {
            echo '<tr><td colspan="4">هنوز داده‌ای در حافظه نیست.</td></tr>';
        }
        foreach ($sources as $src) {
            printf('<tr><td>%s</td><td dir="ltr" style="text-align:left">%s</td><td dir="ltr">%s</td><td dir="ltr">%s</td></tr>',
                esc_html((string) $src['what']), esc_html((string) $src['who']),
                $src['time'] !== null ? esc_html((string) $src['time']) : '—',
                $src['at'] ? esc_html((string) $src['at']) : 'هنوز دریافت نشده');
        }
        $this->close();
    }

    private function keysTable(): void
    {
        $this->open('کلیدهای API', ['ارائه‌دهنده', 'وضعیت', 'کلید ذخیره‌شده', 'سقف رایگان', 'اثر']);

        foreach (Settings::keys() as $setting => $spec) {
            $has = Settings::hasKey($setting);
            $provider = $spec['provider'];
            $enabled = !empty($this->config['providers'][$provider]['enabled']);

            $status = $has
                ? '✅ تنظیم شده'
                : (!empty($spec['required']) ? '⛔ تنظیم نشده — لازم است' : '— تنظیم نشده (اختیاری)');

            $effect = match (true) {
                $has                         => 'فعال',
                !empty($spec['required'])    => 'ارائه‌دهنده غیرفعال است تا کلید وارد شود',
                $enabled                     => 'بدون کلید با سقف پایین‌تر کار می‌کند',
                default                      => '—',
            };

            printf(
                '<tr><th scope="row">%s</th><td>%s</td><td><code dir="ltr">%s</code></td>'
                . '<td dir="ltr">%s</td><td>%s</td></tr>',
                esc_html($spec['label']),
                $status,
                esc_html($has ? Settings::mask((string) Settings::get($setting, '')) : '—'),
                esc_html($spec['free']),
                esc_html($effect)
            );
        }
        $this->close();

        printf(
            '<p class="description">کلیدها را در <a href="%s">تنظیمات → TheHybit Coins</a> وارد کنید. '
            . 'پس از ذخیره، دکمه «آزمون ارائه‌دهندگان» در پایین همین صفحه تأیید می‌کند که هر کلید کار می‌کند.</p>',
            esc_url(admin_url('options-general.php?page=thb-coins-settings'))
        );
    }

    /**
     * Provider budgets — what is configured, what is spent, what is left.
     *
     * The measured production ceiling is five CoinGecko requests a minute, so
     * "how much budget is left" is not a curiosity here; it is the number that
     * decides whether the next tick does anything at all.
     */
    private function budgetTable(): void
    {
        $budget = new Budget($this->config);

        $this->open('بودجه درخواست ارائه‌دهندگان', [
            'ارائه‌دهنده', 'وضعیت', 'در دقیقه', 'در ساعت', 'در روز', 'باقی‌مانده', 'خنک‌سازی',
        ]);

        foreach (array_keys((array) $this->config['providers']) as $provider) {
            $snap = $budget->snapshot($provider);

            if ($snap['unlimited']) {
                printf(
                    '<tr><th scope="row"><code>%s</code></th><td>—</td>'
                    . '<td colspan="4"><em>بودجه‌ای تنظیم نشده است</em></td><td>—</td></tr>',
                    esc_html($provider)
                );
                continue;
            }

            $cell = static function (array $snap, string $window): string {
                if (!isset($snap['limits'][$window])) {
                    return '—';
                }
                return sprintf('%d / %d', $snap['used'][$window] ?? 0, $snap['limits'][$window]);
            };

            $cooling = (int) $snap['cooldown'];
            $detail = $snap['cooldownDetail'];

            $status = $cooling > 0
                ? '⛔ خنک‌سازی'
                : ((int) $snap['remaining'] === 0 ? '⚠️ بودجه تمام' : '✅ فعال');

            $cooldownCell = $cooling > 0
                ? sprintf(
                    '%ds باقی‌مانده (پس از HTTP %d)%s',
                    $cooling,
                    (int) ($detail['status'] ?? 429),
                    !empty($detail['retryAfter'])
                        ? sprintf(' (Retry-After: %ds)', (int) $detail['retryAfter'])
                        : ''
                )
                : (!empty($detail['startedAt'])
                    ? sprintf('آخرین %d: %s', (int) ($detail['status'] ?? 429), esc_html(gmdate('H:i:s', (int) $detail['startedAt'])))
                    : '—');

            printf(
                '<tr><th scope="row"><code>%s</code></th><td>%s</td>'
                . '<td dir="ltr">%s</td><td dir="ltr">%s</td><td dir="ltr">%s</td>'
                . '<td dir="ltr">%d</td><td dir="ltr">%s</td></tr>',
                esc_html($provider),
                $status,
                esc_html($cell($snap, 'per_minute')),
                esc_html($cell($snap, 'per_hour')),
                esc_html($cell($snap, 'per_day')),
                (int) $snap['remaining'],
                $cooldownCell
            );
        }
        $this->close();

        echo '<p class="description">بودجه CoinGecko از اندازه‌گیری واقعی همین سرور آمده است: '
           . 'پنج درخواست پیاپی موفق، ششمی 429 با Retry-After برابر 60 ثانیه. '
           . 'این سقف رسمی CoinGecko نیست و در <code dir="ltr">config/providers.php</code> قابل تغییر است.</p>';
    }

    /**
     * Every registered dataset: what it is, where it comes from, what it costs.
     *
     * The scope column is the one to read at scale. A site-scoped dataset costs
     * one request however many coins exist; a coin-scoped one costs one per
     * coin. The summary underneath turns that into the number that actually
     * matters — what a hundred coins would cost — because the whole point of
     * building this before onboarding them is to know that in advance rather
     * than discover it.
     *
     * The cache key is shown in full precisely so that a site-wide dataset
     * accidentally carrying a coin in its key is visible at a glance. That was
     * a real bug once: the USD/IRR rate was fetched per coin.
     */
    private function datasetTable(Coin $coin): void
    {
        $sets = new Datasets($this->config);
        $cache = new Cache($this->config);
        $budget = new Budget($this->config);

        $this->open('مجموعه‌های داده', [
            'مجموعه', 'ارائه‌دهنده', 'دامنه', 'اولویت', 'کلید کش', 'TTL', 'وضعیت', 'سن', 'آخرین دریافت',
        ]);

        $counts = ['site' => 0, 'chain' => 0, 'coin' => 0];
        $perCoinProviders = [];

        foreach ($sets->all() as $dataset => $spec) {
            $scope = $sets->scope($dataset);
            $provider = (string) $sets->provider($dataset);
            $enabled = $sets->isEnabled($dataset)
                && !empty($this->config['providers'][$provider]['enabled']);

            if ($enabled) {
                $counts[$scope] = ($counts[$scope] ?? 0) + 1;
                if ($scope === Datasets::SCOPE_COIN) {
                    $perCoinProviders[$provider] = ($perCoinProviders[$provider] ?? 0) + 1;
                }
            }

            $key = $cache->keyFor($dataset, $coin);
            $entry = $cache->peek($dataset, $key);
            $ttl = $cache->ttl($dataset);

            if (!$enabled) {
                $state = '⏸ غیرفعال';
                $age = '—';
                $fetched = '—';
            } elseif (!is_array($entry)) {
                // Never fetched is NOT a failure — it is the state before the
                // scheduler's first pass, and it reads differently.
                $state = '⏳ هنوز دریافت نشده';
                $age = '—';
                $fetched = '—';
            } elseif (!empty($entry['miss'])) {
                $state = '⚠️ شکست ثبت‌شده';
                $age = (time() - (int) ($entry['fetchedAt'] ?? 0)) . 's';
                $fetched = gmdate('H:i:s', (int) ($entry['fetchedAt'] ?? 0));
            } else {
                $seconds = time() - (int) ($entry['fetchedAt'] ?? 0);
                $state = $seconds < $ttl ? '✅ تازه' : '🕒 منقضی (نسخه قدیمی موجود)';
                $age = $seconds . 's';
                $fetched = gmdate('H:i:s', (int) ($entry['fetchedAt'] ?? 0));
            }

            printf(
                '<tr><td><code>%s</code>%s</td><td><code>%s</code></td><td><code>%s</code></td>'
                . '<td dir="ltr">%d</td><td><code dir="ltr" style="font-size:11px;word-break:break-all">%s</code></td>'
                . '<td dir="ltr">%ds</td><td>%s</td><td dir="ltr">%s</td><td dir="ltr">%s</td></tr>',
                esc_html($dataset),
                $sets->rendersFromCacheOnly($dataset)
                    ? '<br><small class="description">فقط از کش — صفحه هرگز درخواست نمی‌دهد</small>'
                    : '',
                esc_html($provider),
                esc_html($scope),
                $sets->priority($dataset),
                esc_html($key),
                $ttl,
                $state,
                esc_html($age),
                esc_html($fetched)
            );
        }
        $this->close();

        /* ---- the summary, and what it implies at a hundred coins ---- */

        $coinCount = max(1, count($this->coins->all()));
        $this->open('خلاصه — مقیاس‌پذیری');

        $this->row('مجموعه‌های سراسری (site)', (string) ($counts['site'] ?? 0) . ' — یک درخواست، مستقل از تعداد ارزها');
        $this->row('مجموعه‌های زنجیره‌ای (chain)', (string) ($counts['chain'] ?? 0) . ' — یک درخواست به ازای هر زنجیره');
        $this->row('مجموعه‌های تک‌ارزی (coin)', (string) ($counts['coin'] ?? 0) . ' — تنها دامنه‌ای که با تعداد ارزها رشد می‌کند');
        $this->row('تعداد ارزهای پیکربندی‌شده', (string) $coinCount);

        $snap = $budget->snapshot('coingecko');
        $this->row(
            'بودجه CoinGecko',
            sprintf(
                '%d/%d در دقیقه · %d/%d در ساعت · %d/%d در روز',
                $snap['used']['per_minute'] ?? 0, $snap['limits']['per_minute'] ?? 0,
                $snap['used']['per_hour'] ?? 0, $snap['limits']['per_hour'] ?? 0,
                $snap['used']['per_day'] ?? 0, $snap['limits']['per_day'] ?? 0
            )
        );
        $this->row(
            'خنک‌سازی CoinGecko',
            $snap['cooldown'] > 0 ? sprintf('فعال — %d ثانیه', (int) $snap['cooldown']) : 'غیرفعال'
        );

        $health = (new Scheduler($this->config, $this->coins, $this->pipeline, new History($this->config)))->health();
        $this->row('زمان‌بند', match ($health['status']) {
            'running' => 'در حال اجرا',
            'idle'    => 'بی‌کار',
            'stalled' => 'متوقف',
            default   => 'هنوز اجرا نشده',
        });

        $this->close();

        echo '<p class="description">مجموعه‌های سراسری با افزایش تعداد ارزها بیشتر نمی‌شوند: '
           . '<code dir="ltr">/global</code>، <code dir="ltr">/coins/categories</code>، '
           . '<code dir="ltr">/fng</code>، <code dir="ltr">/v2/chains</code> و '
           . '<code dir="ltr">/stablecoinchains</code> هرکدام یک بار دریافت و بین همه ارزها به اشتراک گذاشته می‌شوند. '
           . 'ساختار بازار نیز درخواست جداگانه‌ای ندارد و از همان درخواست <code dir="ltr">/coins/{id}</code> '
           . 'موجود خوانده می‌شود.</p>';
    }

    /**
     * Scheduler progress — what the last tick did, and what is waiting.
     *
     * The queue is rebuilt here rather than stored, so the "pending" figure is
     * what a tick starting now would actually find.
     */
    private function schedulerTable(): void
    {
        $progress = get_option(Scheduler::OPTION_PROGRESS);
        $scheduler = new Scheduler($this->config, $this->coins, $this->pipeline, new History($this->config));
        $coins = $this->coins->all();
        $queue = $scheduler->queue($coins);

        $this->open('زمان‌بند');

        if (is_array($progress)) {
            $this->row('آخرین اجرا', (string) ($progress['ranAt'] ?? '—'));
            $this->row('مدت اجرا', sprintf('%s ثانیه', (string) ($progress['seconds'] ?? '0')));
            $this->row('تازه‌سازی‌شده', (string) count((array) ($progress['refreshed'] ?? [])));
            $this->row('از طریق درخواست دسته‌ای', (string) ($progress['batched'] ?? 0));
            $this->row('توقف به دلیل', (string) ($progress['stoppedBy'] ?? '—'));
            $this->row('مکان‌نما', (string) ($progress['cursor'] ?? 0));

            $skipped = (array) ($progress['skipped'] ?? []);
            if ($skipped !== []) {
                $lines = [];
                foreach ($skipped as $key => $value) {
                    $lines[] = is_int($key) ? (string) $value : $key . ' ×' . $value;
                }
                $this->row('رد شده', implode(' · ', $lines));
            }
        } else {
            $this->row('آخرین اجرا', 'هنوز اجرا نشده است');
        }

        $this->row('در انتظار هم‌اکنون', (string) count($queue) . ' مجموعه داده');
        $this->close();

        if ($queue === []) {
            return;
        }

        $this->open('صف — به ترتیب اولویت', ['اولویت', 'مجموعه داده', 'ارز', 'دامنه', 'چند برابر TTL گذشته']);
        $datasets = new Datasets($this->config);

        foreach (array_slice($queue, 0, 15) as $item) {
            printf(
                '<tr><td dir="ltr">%d</td><td><code>%s</code></td><td>%s</td><td><code>%s</code></td><td dir="ltr">%s</td></tr>',
                (int) $item['priority'],
                esc_html((string) $item['dataset']),
                esc_html($item['coin']->name),
                esc_html($datasets->scope($item['dataset'])),
                is_infinite($item['overdue']) ? 'هرگز دریافت نشده' : esc_html(number_format((float) $item['overdue'], 1) . '×')
            );
        }
        $this->close();
    }

    /**
     * Last provider probe: a per-provider summary, then every endpoint.
     *
     * Shows nothing at all until the probe has been run once — an empty table
     * would read as "everything failed" rather than "nothing measured yet".
     */
    private function probeSummary(): void
    {
        $probe = get_option(ProviderProbe::OPTION_RESULT);
        if (!is_array($probe) || empty($probe['results'])) {
            echo '<div class="notice notice-info inline"><p><strong>آزمون ارائه‌دهندگان هنوز اجرا نشده است.</strong> '
               . 'دکمه «آزمون ارائه‌دهندگان» در پایین همین صفحه هر نقطه پایانی را یک بار صدا می‌زند و '
               . 'وضعیت واقعی این سرور را ثبت می‌کند.</p></div>';
            return;
        }

        printf(
            '<h2>خلاصه ارائه‌دهندگان</h2><p class="description">آخرین اجرا: <code dir="ltr">%s</code> — %s ثانیه</p>',
            esc_html((string) $probe['startedAt']),
            esc_html(number_format(((int) $probe['durationMs']) / 1000, 1))
        );

        echo '<table class="widefat striped" style="max-width:960px"><thead><tr>'
           . '<th>ارائه‌دهنده</th><th>وضعیت</th><th>کلید</th><th>سقف نرخ</th><th>میانگین پاسخ</th><th>نقاط پایانی</th>'
           . '</tr></thead><tbody>';

        foreach ((array) $probe['summary'] as $provider => $row) {
            $icon = match ($row['status']) {
                'ok'           => '✅ سالم',
                'partial'      => '⚠️ ناقص',
                'rate limited' => '⛔ محدودشده',
                default        => '❌ ناموفق',
            };

            // A provider that sends no rate-limit headers must SAY so. A blank
            // cell reads as "not checked", which is a different claim.
            $limit = $row['headers'] === []
                ? '<em>سقف نرخ در پاسخ اعلام نشده است</em>'
                : esc_html(self::headerLine($row['headers']));

            $key = $row['keyConfigured']
                ? 'پیکربندی‌شده'
                : ($row['needsKey'] ? 'لازم است — تنظیم نشده' : 'بدون کلید');

            printf(
                '<tr><th scope="row"><code>%s</code></th><td>%s</td><td>%s</td><td dir="ltr">%s</td>'
                . '<td dir="ltr">%dms</td><td dir="ltr">%d/%d</td></tr>',
                esc_html((string) $provider),
                $icon,
                esc_html($key),
                $limit,
                (int) $row['avgMs'],
                (int) $row['ok'],
                (int) $row['tested']
            );
        }
        echo '</tbody></table>';

        /* ---- every endpoint ---- */
        $this->open('نتیجه هر نقطه پایانی', ['نقطه پایانی', 'وضعیت', 'زمان', 'حجم', 'سقف نرخ', 'فیلدهای لازم']);

        foreach ((array) $probe['results'] as $r) {
            $status = $r['status'] === 0
                ? '❌ ' . esc_html((string) $r['error'])
                : sprintf('%s %d', $r['ok'] ? '✅' : ($r['status'] === 429 ? '⛔' : '⚠️'), (int) $r['status']);

            $fields = $r['missing'] === []
                ? '✅ کامل'
                : '⚠️ ناموجود: <code dir="ltr">' . esc_html(implode(', ', (array) $r['missing'])) . '</code>';

            $limit = $r['rateHeaders'] === []
                ? '<em>اعلام نشده</em>'
                : '<code dir="ltr">' . esc_html(self::headerLine((array) $r['rateHeaders'])) . '</code>';

            printf(
                '<tr><td><strong>%s</strong> — %s<br><code dir="ltr" style="word-break:break-all;font-size:11px">%s</code>%s%s</td>'
                . '<td>%s</td><td dir="ltr">%dms</td><td dir="ltr">%s</td><td>%s</td><td>%s</td></tr>',
                esc_html((string) $r['provider']),
                esc_html((string) $r['label']),
                esc_html((string) $r['url']),
                $r['note'] ? '<br><span class="description">' . esc_html((string) $r['note']) . '</span>' : '',
                $r['quotaNote'] ? '<br><span style="color:#b32d2e">' . esc_html((string) $r['quotaNote']) . '</span>' : '',
                $status,
                (int) $r['durationMs'],
                esc_html(size_format(max(0, (int) $r['bytes']))),
                $limit,
                $fields
            );
        }
        $this->close();
    }

    /** The CoinGecko ceiling measurement, when one has been taken. */
    private function burstResult(): void
    {
        $burst = get_transient('thb_probe_burst');
        if (!is_array($burst) || empty($burst['calls'])) {
            return;
        }

        $refusal = $burst['firstRefusalAt'] ?? null;

        echo '<h2>سقف نرخ CoinGecko از این سرور</h2>';
        printf(
            '<div class="notice notice-%s inline"><p>%s</p></div>',
            $refusal === null ? 'success' : 'warning',
            $refusal === null
                ? sprintf(
                    '<strong>%d درخواست پشت سر هم بدون رد شدن.</strong> سقف واقعی از این تعداد بیشتر است.',
                    (int) $burst['attempted']
                )
                : sprintf(
                    '<strong>اولین پاسخ 429 در درخواست شماره %d.</strong> سقف عملی این سرور همین حدود است.',
                    (int) $refusal
                )
        );

        $this->open('درخواست‌های پیاپی', ['#', 'وضعیت', 'زمان', 'سرآیندها']);
        foreach ((array) $burst['calls'] as $c) {
            printf(
                '<tr><td dir="ltr">%d</td><td>%s %d</td><td dir="ltr">%dms</td><td dir="ltr"><code>%s</code></td></tr>',
                (int) $c['n'],
                ((int) $c['status']) === 429 ? '⛔' : (((int) $c['status']) === 200 ? '✅' : '⚠️'),
                (int) $c['status'],
                (int) $c['durationMs'],
                esc_html($c['headers'] === [] ? '—' : self::headerLine((array) $c['headers']))
            );
        }
        $this->close();
    }

    /** @param array<string,string> $headers */
    private static function headerLine(array $headers): string
    {
        $parts = [];
        foreach ($headers as $name => $value) {
            $parts[] = $name . ': ' . $value;
        }
        return implode(' · ', $parts);
    }

    private function coinSwitcher(array $coins, Coin $current): void
    {
        echo '<p>';
        foreach ($coins as $c) {
            $url = add_query_arg(['page' => self::SLUG, 'coin' => $c->slug], admin_url('tools.php'));
            printf(
                '<a class="button %s" href="%s">%s</a> ',
                $c->slug === $current->slug ? 'button-primary' : '',
                esc_url($url),
                esc_html($c->name)
            );
        }
        echo '</p>';
    }

    /* ------------------------------------------------------------------
     * Sections
     * ---------------------------------------------------------------- */

    private function configTable(Coin $coin): void
    {
        $this->open('پیکربندی ارز (از ACF)');

        $address = (string) $coin->meta('dexTokenAddress', '');

        $rows = [
            ['نامک وردپرس', $coin->slug, true],
            ['نماد', $coin->symbol, $coin->symbol !== ''],
            ['شناسه CoinGecko', $coin->coingeckoId, $coin->coingeckoId !== ''],
            ['نامک دسته اخبار', $coin->newsCategorySlug, $coin->newsCategorySlug !== ''],
            ['زنجیره DefiLlama', (string) $coin->meta('defillamaChain', ''), (string) $coin->meta('defillamaChain', '') !== ''],
            ['نشانی توکن DexScreener', $address ?: '— خالی —', $address !== ''],
            ['نمادهای معادل DEX', (string) $coin->meta('dexSymbols', '') ?: '—', true],
        ];

        foreach ($rows as [$label, $value, $good]) {
            printf(
                '<tr><th scope="row">%s</th><td><code dir="ltr">%s</code></td><td>%s</td></tr>',
                esc_html($label),
                esc_html((string) $value),
                $good ? '✅' : '⚠️ تنظیم نشده'
            );
        }

        foreach (['defillama' => 'DefiLlama', 'dexscreener' => 'DexScreener', 'onchain' => 'On-chain', 'l2beat' => 'L2BEAT'] as $id => $label) {
            $onGlobally = !empty($this->config['providers'][$id]['enabled']);
            $onForCoin  = $coin->usesProvider($id, $this->config);
            printf(
                '<tr><th scope="row">ارائه‌دهنده %s</th><td><code>%s</code></td><td>%s</td></tr>',
                esc_html($label),
                esc_html($onForCoin ? 'فعال' : ($onGlobally ? 'برای این ارز خاموش' : 'در تنظیمات خاموش')),
                $onForCoin ? '✅' : '—'
            );
        }

        $this->close();

        if ($address === '') {
            echo '<div class="notice notice-warning inline"><p><strong>نشانی توکن DexScreener خالی است.</strong> '
               . 'در نبود آن، جست‌وجو بر پایه نماد انجام می‌شود که مبهم است. '
               . 'برای اتریوم نشانی WETH صحیح است: <code dir="ltr">0xC02aaA39b223FE8D0A0e5C4F27eAD9083C756Cc2</code> — '
               . 'دکمه «تکمیل فیلدهای جدید» در پایین همین صفحه آن را پر می‌کند.</p></div>';
        }
    }

    private function callsTable(): void
    {
        $this->open('درخواست‌های واقعی این اجرا', ['URL', 'وضعیت', 'حجم', 'زمان']);

        if ($this->calls === []) {
            echo '<tr><td colspan="4">هیچ درخواستی انجام نشد.</td></tr>';
        }

        foreach ($this->calls as $c) {
            $okish = $c['code'] >= 200 && $c['code'] < 300;
            printf(
                '<tr><td><code dir="ltr" style="word-break:break-all">%s</code>%s</td><td>%s %s</td><td dir="ltr">%s</td><td dir="ltr">%.2fs</td></tr>',
                esc_html($c['url']),
                $c['error'] ? '<br><strong style="color:#b32d2e">' . esc_html((string) $c['error']) . '</strong>' : '',
                $okish ? '✅' : '❌',
                esc_html($c['code'] ? (string) $c['code'] : 'خطای شبکه'),
                esc_html(size_format(max(0, (int) $c['bytes']))),
                (float) $c['seconds']
            );
        }
        $this->close();
    }

    private function chartTable(array $model): void
    {
        $this->open('نمودار قیمت — وضعیت هر بازه', ['بازه', 'نقاط', 'از', 'تا', 'حجم داده']);

        $series = $model['series'] ?? null;
        if (!is_array($series)) {
            echo '<tr><td colspan="5">❌ هیچ داده نموداری در دسترس نیست.</td></tr>';
            $this->close();
            return;
        }

        foreach (['24h', '7d', '30d', '90d', '1y'] as $period) {
            $s = $series[$period] ?? null;
            if (!is_array($s) || empty($s['t'])) {
                printf('<tr><td><code>%s</code></td><td colspan="4">❌ موجود نیست</td></tr>', esc_html($period));
                continue;
            }
            $t = $s['t'];
            printf(
                '<tr><td><code>%s</code></td><td dir="ltr">%d</td><td dir="ltr">%s</td><td dir="ltr">%s</td><td dir="ltr">%s</td></tr>',
                esc_html($period),
                count($t),
                esc_html(gmdate('Y-m-d H:i', (int) ($t[0] / 1000))),
                esc_html(gmdate('Y-m-d H:i', (int) (end($t) / 1000))),
                esc_html(size_format(strlen((string) wp_json_encode($s))))
            );
        }
        $this->close();
    }

    private function dexTable(Coin $coin, array $model): void
    {
        $this->open('بخش صرافی‌های غیرمتمرکز');

        $dex = $model['dex'] ?? null;
        $visibility = $model['sections']['dex'] ?? ['visible' => false, 'reason' => '—'];

        $this->row('داده دریافتی', $dex ? '✅ بله' : '❌ خیر (کالکتور چیزی برنگرداند)');

        if (is_array($dex)) {
            $this->row('تعداد جفت‌ارز', (string) ($dex['pairCount'] ?? 0));
            $this->row('حجم ۲۴ ساعته', '$' . number_format((float) ($dex['volume24h'] ?? 0)));
            $this->row('نقدینگی', '$' . number_format((float) ($dex['liquidity'] ?? 0)));
            $this->row('جفت‌ارز برتر', (string) ($dex['topPair']['pair'] ?? '—'));
            $this->row('آستانه حجم', '$' . number_format((float) Derive::VISIBILITY_RULES['dex']['minVolume24h']));
            $share = $model['derived']['dexShareOfVolume'] ?? null;
            $this->row(
                'سهم از کل حجم',
                ($share && $share['available'])
                    ? number_format((float) $share['value'], 2) . '% (حداقل ' . Derive::VISIBILITY_RULES['dex']['minSharePct'] . '%)'
                    : 'قابل محاسبه نیست'
            );
        }

        $this->row(
            'تصمیم نمایش',
            ($visibility['visible'] ? '✅ نمایش داده می‌شود' : '❌ پنهان') . ' — ' . $visibility['reason']
        );
        $this->close();
    }

    private function cacheTable(Coin $coin): void
    {
        $this->open('کش — هر مجموعه داده', ['مجموعه', 'وضعیت', 'سن', 'TTL', 'حجم']);

        $cache = new Cache($this->config);

        foreach ($cache->datasets() as $dataset) {
            if ($dataset === 'news' || $dataset === 'chart') {
                continue;   // news is event-driven; chart is held per window
            }
            $entry = $cache->peek($dataset, $coin->cacheKey($dataset));
            $ttl = $cache->ttl($dataset);

            if (!is_array($entry)) {
                printf('<tr><td><code>%s</code></td><td>— خالی —</td><td>—</td><td dir="ltr">%ds</td><td>—</td></tr>',
                    esc_html($dataset), $ttl);
                continue;
            }

            $age = time() - (int) ($entry['fetchedAt'] ?? 0);
            $state = !empty($entry['miss'])
                ? '⚠️ شکست ثبت‌شده'
                : ($age < $ttl ? '✅ تازه' : '🕒 منقضی (نسخه قدیمی موجود)');

            printf(
                '<tr><td><code>%s</code></td><td>%s</td><td dir="ltr">%ds</td><td dir="ltr">%ds</td><td dir="ltr">%s</td></tr>',
                esc_html($dataset),
                $state,
                $age,
                $ttl,
                esc_html(size_format(strlen((string) wp_json_encode($entry))))
            );
        }
        $this->close();
    }

    /**
     * Can the cache actually hold what we put in it?
     *
     * A persistent object cache with a per-item ceiling (Memcached defaults to
     * 1MB) makes set_transient() fail SILENTLY. Every render would then refetch
     * everything and burn rate-limit budget for nothing, which looks exactly
     * like a provider problem. Writing a realistically-sized value and reading
     * it back is the only way to be sure.
     */
    private function storageTable(): void
    {
        $this->open('ذخیره‌سازی کش');

        $external = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
        $this->row('کش شیء خارجی', $external ? 'بله (Redis/Memcached)' : 'خیر — از جدول options استفاده می‌شود');

        $payload = str_repeat('x', 512 * 1024);   // 512KB, above a typical chart entry
        set_transient('thb_diag_probe', $payload, 60);
        $readBack = get_transient('thb_diag_probe');
        delete_transient('thb_diag_probe');

        $ok = is_string($readBack) && strlen($readBack) === strlen($payload);
        $this->row(
            'نوشتن ۵۱۲ کیلوبایت در کش',
            $ok ? '✅ موفق' : '❌ ناموفق — مقادیر بزرگ ذخیره نمی‌شوند'
        );
        $this->close();

        if (!$ok) {
            echo '<div class="notice notice-error inline"><p><strong>کش مقادیر بزرگ را نگه نمی‌دارد.</strong> '
               . 'در این حالت داده نمودار هرگز ذخیره نمی‌شود و هر بازدید دوباره از CoinGecko درخواست می‌کند.</p></div>';
        }
    }

    private function actions(Coin $coin): void
    {
        echo '<h2>اقدامات</h2>';

        printf(
            '<form method="post" action="%s" style="display:inline-block;margin-left:8px">%s'
            . '<input type="hidden" name="action" value="thb_diag_live">'
            . '<input type="hidden" name="coin" value="%s">'
            . '<button class="button button-primary">بررسی زنده (کش را دور می‌زند)</button></form>',
            esc_url(admin_url('admin-post.php')),
            wp_nonce_field('thb_diag_live', '_wpnonce', true, false),
            esc_attr($coin->slug)
        );

        printf(
            '<form method="post" action="%s" style="display:inline-block">%s'
            . '<input type="hidden" name="action" value="thb_diag_seed">'
            . '<input type="hidden" name="coin" value="%s">'
            . '<button class="button">تکمیل فیلدهای جدید از پیکربندی</button></form>',
            esc_url(admin_url('admin-post.php')),
            wp_nonce_field('thb_diag_seed', '_wpnonce', true, false),
            esc_attr($coin->slug)
        );

        printf(
            '<form method="post" action="%s" style="display:inline-block;margin-inline-start:8px">%s'
            . '<input type="hidden" name="action" value="thb_diag_probe">'
            . '<input type="hidden" name="coin" value="%s">'
            . '<button class="button">آزمون ارائه‌دهندگان</button></form>',
            esc_url(admin_url('admin-post.php')),
            wp_nonce_field('thb_diag_probe', '_wpnonce', true, false),
            esc_attr($coin->slug)
        );

        printf(
            '<form method="post" action="%s" style="display:inline-block;margin-inline-start:8px">%s'
            . '<input type="hidden" name="action" value="thb_diag_burst">'
            . '<input type="hidden" name="coin" value="%s">'
            . '<button class="button">سنجش سقف نرخ CoinGecko</button></form>',
            esc_url(admin_url('admin-post.php')),
            wp_nonce_field('thb_diag_burst', '_wpnonce', true, false),
            esc_attr($coin->slug)
        );

        echo '<p class="description">بررسی زنده از سهمیه رایگان CoinGecko خرج می‌کند، پس آن را پشت سر هم اجرا نکنید. '
           . '«تکمیل فیلدهای جدید» فقط فیلدهای خالی را پر می‌کند و چیزی را بازنویسی نمی‌کند. '
           . '«آزمون ارائه‌دهندگان» هر نقطه پایانی را یک بار صدا می‌زند. '
           . '«سنجش سقف نرخ» عمداً پشت سر هم درخواست می‌فرستد تا نقطه رد شدن پیدا شود — فقط وقتی لازم است اجرا کنید.</p>';
    }

    private function seedNotice(): void
    {
        $result = get_transient('thb_diag_seed_result');
        if (!is_array($result)) {
            return;
        }
        delete_transient('thb_diag_seed_result');

        echo '<div class="notice notice-success"><p><strong>پیکربندی تکمیل شد:</strong> ';
        foreach ($result as $slug => $what) {
            printf('<code>%s</code> — %s. ', esc_html((string) $slug), esc_html((string) $what));
        }
        echo '</p></div>';
    }

    /* ------------------------------------------------------------------
     * Table helpers
     * ---------------------------------------------------------------- */

    private function open(string $title, array $headings = []): void
    {
        printf('<h2>%s</h2><table class="widefat striped" style="max-width:960px">', esc_html($title));
        if ($headings !== []) {
            echo '<thead><tr>';
            foreach ($headings as $h) {
                printf('<th>%s</th>', esc_html($h));
            }
            echo '</tr></thead>';
        }
        echo '<tbody>';
    }

    private function close(): void
    {
        echo '</tbody></table>';
    }

    private function row(string $label, string $value): void
    {
        printf('<tr><th scope="row" style="width:220px">%s</th><td>%s</td></tr>', esc_html($label), esc_html($value));
    }
}
