<?php
/**
 * Plugin settings, and the API keys the new providers need.
 *
 * WHY KEYS LIVE HERE AND NOT IN config/providers.php
 *
 * config/providers.php is committed to the repository and shipped inside the
 * plugin zip. A key written there is a key published — to this repository, to
 * every backup of the site, and to anyone who can read a file in wp-content.
 * So keys are stored in one WordPress option and merged into the provider
 * config at boot; nothing in version control ever holds one.
 *
 * THE SCREEN NEVER SHOWS A KEY BACK
 *
 * A stored key renders as a masked hint — first four characters, last four,
 * dots between — and the input is left empty. Submitting an empty field
 * therefore means "leave it alone" rather than "delete it", which is the
 * behaviour an administrator expects from a password field and the opposite of
 * what a naive implementation does. Clearing a key is an explicit action.
 *
 * This matters beyond tidiness: the diagnostics screen is the place somebody
 * takes a screenshot when asking for help.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class Settings
{
    public const OPTION = 'thb_coins_settings';
    private const CAP   = 'manage_options';
    private const SLUG  = 'thb-coins-settings';

    /** Sentinel written into an input so an unchanged field is not re-saved. */
    private const UNCHANGED = '__thb_unchanged__';

    /**
     * Every key the plugin can use, and where each one comes from.
     *
     * `free` is what the provider's free tier actually gives, recorded here
     * because it is the number that decides the TTLs in config/providers.php
     * and because it is otherwise only ever found in a browser tab somebody
     * closed.
     *
     * @return array<string, array{label:string, provider:string, url:string, free:string, required:bool}>
     */
    public static function keys(): array
    {
        return [
            'etherscan_key' => [
                'label'    => 'Etherscan',
                'provider' => 'etherscan',
                'url'      => 'https://etherscan.io/myapikey',
                'free'     => '5 calls/second, 100,000 calls/day',
                'required' => true,
                'note'     => 'گس، زمان بلاک، عرضه و سوخت اتر از این کلید می‌آید.',
            ],
            'beaconchain_key' => [
                'label'    => 'beaconcha.in',
                'provider' => 'beaconchain',
                'url'      => 'https://beaconcha.in/user/settings#api',
                'free'     => '10 calls/minute on the free tier',
                'required' => false,
                'note'     => 'اعتبارسنج‌ها و بازده استیکینگ. بدون کلید هم پاسخ می‌دهد اما سقف آن بسیار پایین‌تر است.',
            ],
            'github_token' => [
                'label'    => 'GitHub',
                'provider' => 'github',
                'url'      => 'https://github.com/settings/personal-access-tokens',
                'free'     => '60 calls/hour without a token, 5,000 with one',
                'required' => false,
                'note'     => 'فعالیت توسعه‌دهندگان. توکن فقط به دسترسی عمومی نیاز دارد — هیچ اسکوپی لازم نیست.',
            ],
            'blockchair_key' => [
                'label'    => 'Blockchair',
                'provider' => 'blockchair',
                'url'      => 'https://blockchair.com/api/plans',
                'free'     => '1,440 calls/day without a key',
                'required' => false,
                'note'     => 'آمار شبکه و تراکنش‌های بزرگ. بدون کلید کار می‌کند؛ کلید فقط سقف را بالا می‌برد.',
            ],
        ];
    }

    /* ------------------------------------------------------------------
     * Reading
     * ---------------------------------------------------------------- */

    /** @return array<string, mixed> */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    public static function get(string $key, mixed $default = ''): mixed
    {
        $all = self::all();
        $value = $all[$key] ?? $default;
        return $value === '' ? $default : $value;
    }

    public static function hasKey(string $key): bool
    {
        return trim((string) self::get($key, '')) !== '';
    }

    /**
     * Merge stored keys into the provider config.
     *
     * Called once at boot, so collectors never know a key came from an option
     * rather than from configuration — they just see headers and query defaults
     * like any other provider setting.
     *
     * A provider whose key is missing is DISABLED rather than left to fail on
     * every request: a provider that cannot succeed should not consume budget,
     * fill the error log, or occupy a slot in the scheduler's queue. The
     * diagnostics screen says which ones and why.
     */
    public static function apply(array $config): array
    {
        foreach (self::keys() as $setting => $spec) {
            $provider = $spec['provider'];
            if (!isset($config['providers'][$provider])) {
                continue;
            }

            $value = trim((string) self::get($setting, ''));
            $auth = $config['providers'][$provider]['auth'] ?? null;

            if ($value === '') {
                if (!empty($spec['required'])) {
                    $config['providers'][$provider]['enabled'] = false;
                    $config['providers'][$provider]['disabled_reason'] = 'no API key configured';
                }
                continue;
            }

            if (!is_array($auth)) {
                continue;
            }

            if (($auth['in'] ?? 'query') === 'header') {
                $config['providers'][$provider]['headers'][$auth['name']] =
                    sprintf((string) ($auth['format'] ?? '%s'), $value);
            } else {
                $config['providers'][$provider]['query'][$auth['name']] = $value;
            }
        }

        return $config;
    }

    /* ------------------------------------------------------------------
     * The screen
     * ---------------------------------------------------------------- */

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_thb_save_settings', [$this, 'save']);
    }

    public function menu(): void
    {
        add_submenu_page(
            'options-general.php',
            'TheHybit Coins',
            'TheHybit Coins',
            self::CAP,
            self::SLUG,
            [$this, 'render']
        );
    }

    public function save(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die('اجازه دسترسی ندارید.');
        }
        check_admin_referer('thb_save_settings');

        $stored = self::all();

        foreach (self::keys() as $setting => $_spec) {
            $submitted = isset($_POST[$setting]) ? trim((string) wp_unslash($_POST[$setting])) : '';

            // Explicit clearing, which an empty field must NOT do by accident.
            if (!empty($_POST['clear_' . $setting])) {
                unset($stored[$setting]);
                continue;
            }

            if ($submitted === '' || $submitted === self::UNCHANGED) {
                continue;   // left alone
            }

            /* Keys are alphanumeric with a few separators across every provider
               here. Sanitising to that set stops a pasted URL or a stray quote
               becoming part of a request. */
            $stored[$setting] = preg_replace('/[^A-Za-z0-9_\-]/', '', $submitted) ?? '';
        }

        update_option(self::OPTION, $stored, false);

        wp_safe_redirect(add_query_arg(
            ['page' => self::SLUG, 'saved' => '1'],
            admin_url('options-general.php')
        ));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die('اجازه دسترسی ندارید.');
        }

        echo '<div class="wrap" dir="rtl"><h1>TheHybit Coins — تنظیمات</h1>';

        if (!empty($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>تنظیمات ذخیره شد.</p></div>';
        }

        printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
        wp_nonce_field('thb_save_settings');
        echo '<input type="hidden" name="action" value="thb_save_settings">';

        echo '<h2>کلیدهای API</h2>';
        echo '<p class="description">کلیدها در پایگاه‌داده ذخیره می‌شوند و هرگز در کد یا مخزن قرار نمی‌گیرند. '
           . 'پس از ذخیره، فقط چند نویسه ابتدایی و انتهایی نمایش داده می‌شود. '
           . 'خالی گذاشتن یک فیلد یعنی «تغییر نده» — برای حذف، گزینه حذف را علامت بزنید.</p>';

        echo '<table class="form-table" role="presentation"><tbody>';

        foreach (self::keys() as $setting => $spec) {
            $has = self::hasKey($setting);

            printf(
                '<tr><th scope="row"><label for="%s">%s</label>%s</th><td>',
                esc_attr($setting),
                esc_html($spec['label']),
                !empty($spec['required'])
                    ? '<br><span style="color:#b32d2e;font-weight:400">لازم است</span>'
                    : '<br><span class="description" style="font-weight:400">اختیاری</span>'
            );

            printf(
                '<input type="text" dir="ltr" class="regular-text" id="%s" name="%s" value="" autocomplete="off" placeholder="%s">',
                esc_attr($setting),
                esc_attr($setting),
                $has ? esc_attr(self::mask((string) self::get($setting, ''))) : 'هنوز تنظیم نشده'
            );

            if ($has) {
                printf(
                    ' <label style="margin-inline-start:10px"><input type="checkbox" name="clear_%s" value="1"> حذف</label>',
                    esc_attr($setting)
                );
            }

            printf(
                '<p class="description">%s<br>سقف رایگان: <code dir="ltr">%s</code><br>'
                . 'دریافت کلید: <a href="%s" target="_blank" rel="noopener noreferrer"><code dir="ltr">%s</code></a></p>',
                esc_html((string) ($spec['note'] ?? '')),
                esc_html($spec['free']),
                esc_url($spec['url']),
                esc_html($spec['url'])
            );

            echo '</td></tr>';
        }

        echo '</tbody></table>';
        submit_button('ذخیره تنظیمات');
        echo '</form></div>';
    }

    /**
     * Remove every configured secret from a string about to be shown or logged.
     *
     * Query-string keys appear in URLs; header keys can appear in error text
     * that echoes a request. Replacing the VALUE rather than pattern-matching a
     * parameter name means a provider that changes its parameter name, or an
     * error that quotes the key out of context, is still covered.
     */
    public static function scrub(string $text, array $providerSettings): string
    {
        $secrets = [];
        foreach ((array) ($providerSettings['query'] ?? []) as $value) {
            $secrets[] = (string) $value;
        }
        $auth = (array) ($providerSettings['auth'] ?? []);
        if (($auth['in'] ?? null) === 'header') {
            $header = (string) ($providerSettings['headers'][$auth['name'] ?? ''] ?? '');
            if ($header !== '') {
                $secrets[] = $header;
                // The bare token too, for a "Bearer %s" format.
                $secrets[] = (string) preg_replace('/^\S+\s+/', '', $header);
            }
        }

        foreach (array_unique(array_filter($secrets, static fn(string $s): bool => strlen($s) >= 6)) as $secret) {
            $text = str_replace([$secret, rawurlencode($secret)], self::mask($secret), $text);
        }
        return $text;
    }

    /**
     * A key, shown safely.
     *
     * Enough characters to recognise which key is stored, never enough to use
     * it. A short string is masked entirely rather than mostly revealed, which
     * is the case a naive first-four/last-four implementation gets wrong.
     */
    public static function mask(string $key): string
    {
        $len = strlen($key);
        if ($len === 0) {
            return '';
        }
        if ($len <= 10) {
            return str_repeat('•', $len);
        }
        return substr($key, 0, 4) . str_repeat('•', max(4, $len - 8)) . substr($key, -4);
    }
}
