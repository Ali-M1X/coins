<?php
/**
 * TEMPORARY one-time setup page.
 *
 * Exists solely because cPanel hosting has no SSH and no WP-CLI, so
 * `wp eval 'TheHybit\Coins\Seeder::run();'` is not available. It runs the same
 * Seeder, from the admin, once.
 *
 * IT IS NOT PART OF THE PRODUCTION ARCHITECTURE. Nothing in the pipeline calls
 * it, and it disappears the moment you press "Finish setup".
 *
 * Safety, in layers:
 *
 *   1. CAPABILITY. The menu is not registered, and the handler refuses to run,
 *      unless the current user can `manage_options`. Editors, authors and
 *      subscribers never see it and cannot reach it by URL.
 *   2. NONCE. Seeding happens only via POST to admin-post.php with a valid,
 *      user-bound nonce, so a link or form on another site cannot trigger it.
 *   3. NO INPUT. The seed values come from config/coins.php in the plugin, not
 *      from the request. There is no field an attacker could influence even if
 *      they defeated 1 and 2.
 *   4. IDEMPOTENT. Seeder::run() updates in place and never overwrites a field
 *      an editor has already filled, so an accidental second run is harmless.
 *   5. SELF-REMOVING. "Finish setup" sets an option and the page stops being
 *      registered at all.
 *
 * To bring it back after finishing (should a third coin ever need seeding),
 * add to wp-config.php:
 *
 *     define('THB_COINS_SETUP', true);
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class AdminSetup
{
    private const OPTION_DONE = 'thb_setup_complete';
    private const ACTION_RUN  = 'thb_setup_run';
    private const ACTION_DONE = 'thb_setup_finish';
    private const NOTICE      = 'thb_setup_notice';
    private const CAP         = 'manage_options';

    public function __construct(private CoinRepository $coins) {}

    /** Hidden once setup is finished, unless explicitly re-enabled. */
    public static function isEnabled(): bool
    {
        if (defined('THB_COINS_SETUP') && THB_COINS_SETUP) {
            return true;
        }
        return !get_option(self::OPTION_DONE);
    }

    public function register(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_' . self::ACTION_RUN, [$this, 'handleRun']);
        add_action('admin_post_' . self::ACTION_DONE, [$this, 'handleFinish']);
    }

    public function menu(): void
    {
        // Capability is enforced here AND again in every handler. Registering a
        // menu with a capability only hides it; it does not protect the target.
        add_submenu_page(
            'edit.php?post_type=' . CoinRepository::POST_TYPE,
            'راه‌اندازی اولیه ارزها',
            'راه‌اندازی اولیه',
            self::CAP,
            'thb-setup',
            [$this, 'render']
        );
    }

    /* ------------------------------------------------------------------
     * Handlers
     * ---------------------------------------------------------------- */

    public function handleRun(): void
    {
        $this->authorize(self::ACTION_RUN);

        $result = Seeder::run();

        set_transient(self::NOTICE, ['type' => 'run', 'result' => $result], 60);
        $this->redirectBack();
    }

    public function handleFinish(): void
    {
        $this->authorize(self::ACTION_DONE);

        update_option(self::OPTION_DONE, [
            'completedAt' => gmdate('c'),
            'by'          => get_current_user_id(),
        ], false);

        // Land somewhere that still exists — this page is about to vanish.
        wp_safe_redirect(admin_url('edit.php?post_type=' . CoinRepository::POST_TYPE));
        exit;
    }

    /**
     * Capability + nonce, in that order, on every state-changing request.
     * Also re-checks isEnabled() so a finished setup cannot be re-run by
     * replaying an old URL.
     */
    private function authorize(string $action): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die('اجازه دسترسی ندارید.', 403);
        }
        check_admin_referer($action);

        if (!self::isEnabled()) {
            wp_die('راه‌اندازی اولیه قبلاً تکمیل شده است.', 403);
        }
    }

    private function redirectBack(): void
    {
        wp_safe_redirect(add_query_arg(
            ['post_type' => CoinRepository::POST_TYPE, 'page' => 'thb-setup'],
            admin_url('edit.php')
        ));
        exit;
    }

    /* ------------------------------------------------------------------
     * View
     * ---------------------------------------------------------------- */

    public function render(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die('اجازه دسترسی ندارید.', 403);
        }

        $notice = get_transient(self::NOTICE);
        delete_transient(self::NOTICE);

        $status = $this->status();
        $allReady = $status['coinsOk'] && $status['categoriesOk'] && $status['tableOk'];
        ?>
        <div class="wrap">
            <h1>راه‌اندازی اولیه ارزها</h1>
            <p class="description">
                این صفحه موقت است و فقط برای ایجاد ارزهای اولیه (اتریوم و بیت‌کوین) به کار می‌رود.
                پس از پایان، با دکمه «پایان راه‌اندازی» آن را حذف کنید.
            </p>

            <?php if ($notice && $notice['type'] === 'run') : ?>
                <div class="notice notice-success"><p><strong>نتیجه اجرا:</strong></p>
                    <ul style="margin-inline-start:1.5em;list-style:disc">
                        <?php foreach ($notice['result'] as $slug => $outcome) : ?>
                            <li><code><?= esc_html($slug) ?></code> — <?= esc_html($outcome) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <h2>وضعیت فعلی</h2>
            <table class="widefat striped" style="max-width:960px">
                <thead>
                    <tr>
                        <th>بررسی</th><th>وضعیت</th><th>جزئیات</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>جدول تاریخچه</td>
                        <td><?= self::badge($status['tableOk']) ?></td>
                        <td><code><?= esc_html($status['tableName']) ?></code>
                            <?= $status['tableOk'] ? esc_html(' — ' . number_format_i18n($status['historyRows']) . ' ردیف') : ' — ساخته نشده؛ افزونه را غیرفعال و دوباره فعال کنید' ?>
                        </td>
                    </tr>
                    <tr>
                        <td>ACF</td>
                        <td><?= self::badge($status['acfOk']) ?></td>
                        <td><?= $status['acfOk'] ? 'فعال است' : 'نصب/فعال نیست — فیلدها در پیشخوان دیده نمی‌شوند' ?></td>
                    </tr>

                    <?php foreach ($status['coins'] as $slug => $coin) : ?>
                        <tr>
                            <td>ارز <code><?= esc_html($slug) ?></code></td>
                            <td><?= self::badge($coin['exists']) ?></td>
                            <td>
                                <?php if ($coin['exists']) : ?>
                                    #<?= (int) $coin['id'] ?> —
                                    نماد <code><?= esc_html($coin['symbol']) ?></code>،
                                    شناسه CoinGecko <code><?= esc_html($coin['coingecko']) ?></code>،
                                    دسته اخبار <code><?= esc_html($coin['newsCat']) ?></code>
                                    &nbsp;
                                    <a href="<?= esc_url($coin['edit']) ?>">ویرایش</a> |
                                    <a href="<?= esc_url($coin['view']) ?>" target="_blank" rel="noopener">مشاهده صفحه</a>
                                <?php else : ?>
                                    هنوز ساخته نشده
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding-inline-start:2em">دسته اخبار <code><?= esc_html($coin['newsCatExpected']) ?></code></td>
                            <td><?= self::badge($coin['newsCatExists']) ?></td>
                            <td>
                                <?php if ($coin['newsCatExists']) : ?>
                                    موجود است (<?= (int) $coin['newsCatCount'] ?> نوشته)
                                <?php else : ?>
                                    <strong>ساخته نشده.</strong> تا زمانی که این دسته در
                                    <code>/category/news/</code> ساخته نشود، بخش «مطالب مرتبط» پنهان می‌ماند —
                                    این رفتار درست است، نه خطا.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2>اجرا</h2>
            <p>
                این عملیات ارزهای تعریف‌شده در <code>config/coins.php</code> را می‌سازد یا به‌روز می‌کند.
                فیلدهایی که قبلاً پر شده‌اند بازنویسی <strong>نمی‌شوند</strong>، بنابراین اجرای دوباره بی‌خطر است.
            </p>

            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="display:inline-block">
                <?php wp_nonce_field(self::ACTION_RUN); ?>
                <input type="hidden" name="action" value="<?= esc_attr(self::ACTION_RUN) ?>">
                <?php submit_button('ساخت / به‌روزرسانی ارزها', 'primary', 'submit', false); ?>
            </form>

            <hr style="margin-block:2em">

            <h2>پایان راه‌اندازی</h2>
            <p>
                پس از اطمینان از صحت موارد بالا، این صفحه را غیرفعال کنید. پس از آن، این منو
                دیگر نمایش داده نمی‌شود و اجرای دوباره ممکن نیست.
                <?php if (!$allReady) : ?>
                    <br><strong>توجه:</strong> هنوز همه بررسی‌ها سبز نیستند.
                <?php endif; ?>
            </p>

            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>"
                  onsubmit="return confirm('این صفحه حذف می‌شود. ادامه می‌دهید؟');">
                <?php wp_nonce_field(self::ACTION_DONE); ?>
                <input type="hidden" name="action" value="<?= esc_attr(self::ACTION_DONE) ?>">
                <?php submit_button('پایان راه‌اندازی و حذف این صفحه', 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    private static function badge(bool $ok): string
    {
        return $ok
            ? '<span style="color:#16A34A;font-weight:600">✓ آماده</span>'
            : '<span style="color:#DC2626;font-weight:600">✗ ناقص</span>';
    }

    /* ------------------------------------------------------------------
     * Read-only verification
     * ---------------------------------------------------------------- */

    private function status(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . History::TABLE;
        $tableOk = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $rows = $tableOk ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") : 0;

        $specs = require THB_COINS_DIR . 'config/coins.php';
        $coins = [];
        $coinsOk = true;
        $categoriesOk = true;

        foreach ($specs as $slug => $spec) {
            $post = get_page_by_path($slug, OBJECT, CoinRepository::POST_TYPE);
            $expectedCat = (string) ($spec['fields']['thb_news_category_slug'] ?? '');
            $term = $expectedCat !== '' ? get_term_by('slug', $expectedCat, 'category') : null;
            $catExists = $term && !is_wp_error($term);

            $model = $post ? $this->coins->find($post->ID) : null;

            $coins[$slug] = [
                'exists'          => (bool) $post,
                'id'              => $post->ID ?? 0,
                'symbol'          => $model->symbol ?? '—',
                'coingecko'       => $model->coingeckoId ?? '—',
                'newsCat'         => $model->newsCategorySlug ?? '—',
                'newsCatExpected' => $expectedCat,
                'newsCatExists'   => $catExists,
                'newsCatCount'    => $catExists ? (int) $term->count : 0,
                'edit'            => $post ? get_edit_post_link($post->ID, 'raw') : '',
                'view'            => $post ? get_permalink($post->ID) : '',
            ];

            // A post that exists but fails CoinRepository::find() is misconfigured
            // (missing CoinGecko id or symbol), which is worth failing loudly.
            $coinsOk = $coinsOk && $post && $model;
            $categoriesOk = $categoriesOk && $catExists;
        }

        return [
            'tableOk'      => $tableOk,
            'tableName'    => $table,
            'historyRows'  => $rows,
            'acfOk'        => function_exists('get_field'),
            'coins'        => $coins,
            'coinsOk'      => $coinsOk,
            'categoriesOk' => $categoriesOk,
        ];
    }
}
