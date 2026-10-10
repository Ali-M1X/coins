<?php
/**
 * A WordPress-shaped test double.
 *
 * Enough of WordPress to boot the plugin's real classes: transients, options,
 * the HTTP transport, the hook registry, WP_Query, $wpdb and the template
 * functions the pipeline touches. Nothing here is clever — the point is that
 * the code under test is the SHIPPING code, with only its edges replaced.
 *
 * TWO PRINCIPLES, LEARNED THE HARD WAY
 *
 * 1. A STUB MUST BE ABLE TO FAIL. wp_schedule_event() validates its recurrence
 *    against wp_get_schedules() exactly as WordPress does, and the options
 *    table honours INSERT IGNORE and compare-and-swap semantics. A stub that
 *    accepted anything would let a broken lock or a never-scheduled cron event
 *    pass every test in the suite — which is precisely how both of those bugs
 *    survived into production once already.
 *
 * 2. THE TRANSPORT COUNTS EVERYTHING. "How many requests does one page make"
 *    is the question this project keeps needing answered, and it is answerable
 *    only if every outbound call is recorded. The rate limiter is rolling, like
 *    a real free tier, so a budget refills as the clock advances.
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-clock.php';

define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
define('MONTH_IN_SECONDS', 2592000);
define('YEAR_IN_SECONDS', 31536000);
define('ARRAY_A', 'ARRAY_A');
define('OBJECT', 'OBJECT');

/** Assumed round-trip cost of one provider call. Counts do not depend on it. */
const ASSUMED_LATENCY = 0.45;

/* ---------------------------------------------------------------------------
 * Instrumentation
 * ------------------------------------------------------------------------ */

final class Probe
{
    /** @var array<int, array{url:string, host:string}> */
    public static array $calls = [];
    public static float $network = 0.0;
    public static float $sleep = 0.0;

    /**
     * host => ['max' => calls, 'per' => seconds]
     *
     * A ROLLING allowance, which is what a free tier actually enforces: it
     * refills as the clock advances. The fixed-budget model could not show the
     * chart's tail windows starving, because they were always requested last,
     * always after the allowance was spent, and never refilled into existence.
     */
    public static array $rateLimit = [];
    /** host => requests the simulated provider answered with 429 */
    public static array $refused = [];

    /** @var array<string, array<int, int>> host => timestamps of served calls */
    public static array $served = [];

    /** host => response headers the stub sends back. */
    public static array $responseHeaders = [];

    public static array $transients = [];

    public static function reset(bool $keepCache = false): void
    {
        self::$calls = [];
        self::$network = 0.0;
        self::$sleep = 0.0;
        self::$rateLimit = [];
        self::$served = [];
        self::$refused = [];
        self::$responseHeaders = [];
        if (!$keepCache) {
            self::$transients = [];
        }
    }

    public static function count(?string $needle = null): int
    {
        if ($needle === null) {
            return count(self::$calls);
        }
        return count(array_filter(self::$calls, static fn($c) => str_contains($c['url'], $needle)));
    }

    public static function byEndpoint(): array
    {
        $out = [];
        foreach (self::$calls as $c) {
            $key = preg_replace('#(\?.*)$#', '', $c['url']);
            $key = preg_replace('#/api/v3|/latest|/v1#', '', (string) $key);
            $key = str_replace('https://', '', (string) $key);
            $out[$key] = ($out[$key] ?? 0) + 1;
        }
        arsort($out);
        return $out;
    }
}

/* ---------------------------------------------------------------------------
 * Errors
 * ------------------------------------------------------------------------ */

class WP_Error
{
    public function __construct(private string $code = 'error', private string $msg = 'error') {}
    public function get_error_message(): string { return $this->msg; }
    public function get_error_code(): string { return $this->code; }
}

function is_wp_error($t): bool { return $t instanceof WP_Error; }

/* ---------------------------------------------------------------------------
 * Hooks
 *
 * Actions and filters share one registry, as they do in WordPress where both
 * are WP_Hook. Callbacks are keyed by identity the way
 * _wp_filter_build_unique_id() does it, so registering the same static method
 * twice REPLACES rather than duplicates — which the scheduler relies on, since
 * it registers its cron_schedules filter from three separate call sites.
 * ------------------------------------------------------------------------ */

$GLOBALS['thb_hooks'] = [];

function _thb_hook_id($cb): string
{
    if (is_string($cb)) {
        return $cb;
    }
    if (is_array($cb)) {
        $target = is_object($cb[0]) ? spl_object_hash($cb[0]) : (string) $cb[0];
        return $target . '::' . $cb[1];
    }
    return spl_object_hash($cb);
}

function add_action(string $hook, $cb, int $priority = 10, int $args = 1): bool
{
    $GLOBALS['thb_hooks'][$hook][_thb_hook_id($cb)] = $cb;
    return true;
}

function add_filter(string $hook, $cb, int $priority = 10, int $args = 1): bool
{
    $GLOBALS['thb_hooks'][$hook][_thb_hook_id($cb)] = $cb;
    return true;
}

function remove_action(string $hook, $cb, int $priority = 10): bool
{
    unset($GLOBALS['thb_hooks'][$hook][_thb_hook_id($cb)]);
    return true;
}

function remove_filter(string $hook, $cb, int $priority = 10): bool
{
    unset($GLOBALS['thb_hooks'][$hook][_thb_hook_id($cb)]);
    return true;
}

function has_action(string $hook, $cb = false): bool
{
    if ($cb === false) {
        return !empty($GLOBALS['thb_hooks'][$hook]);
    }
    return isset($GLOBALS['thb_hooks'][$hook][_thb_hook_id($cb)]);
}

function do_action(string $hook, ...$args): void
{
    foreach ($GLOBALS['thb_hooks'][$hook] ?? [] as $cb) {
        $cb(...$args);
    }
}

/**
 * One filter keeps a special case: whether a collector may block on its
 * throttle. Defaults to FALSE so measurements reflect the visitor-facing path;
 * set $GLOBALS['thb_probe_background'] to measure the cron path instead.
 */
function apply_filters(string $hook, $value, ...$args)
{
    if ($hook === 'thb_coins_is_background') {
        return (bool) ($GLOBALS['thb_probe_background'] ?? false);
    }
    foreach ($GLOBALS['thb_hooks'][$hook] ?? [] as $cb) {
        $value = $cb($value, ...$args);
    }
    return $value;
}

/* ---------------------------------------------------------------------------
 * Transients — expiring against the controllable clock, so a TTL means what it
 * means in production rather than lasting for the whole run.
 * ------------------------------------------------------------------------ */

function get_transient(string $k)
{
    if (!isset(Probe::$transients[$k])) {
        return false;
    }
    [$value, $expires] = Probe::$transients[$k];
    if ($expires > 0 && $expires <= Clock::$now) {
        unset(Probe::$transients[$k]);
        return false;
    }
    return $value;
}

function set_transient(string $k, $v, int $ttl = 0): bool
{
    Probe::$transients[$k] = [$v, $ttl > 0 ? Clock::$now + $ttl : 0];
    return true;
}

function delete_transient(string $k): bool { unset(Probe::$transients[$k]); return true; }

/* Options. The scheduler's cursor, progress record and warm lock live here and
   must survive between simulated cron ticks the way they do in production. */
$GLOBALS['thb_options'] = [];
function get_option(string $k, $default = false) { return $GLOBALS['thb_options'][$k] ?? $default; }
function update_option(string $k, $v, $autoload = null): bool { $GLOBALS['thb_options'][$k] = $v; return true; }
function add_option(string $k, $v, $d = '', $autoload = null): bool
{
    if (array_key_exists($k, $GLOBALS['thb_options'])) { return false; }
    $GLOBALS['thb_options'][$k] = $v;
    return true;
}
function delete_option(string $k): bool { unset($GLOBALS['thb_options'][$k]); return true; }
function wp_cache_delete($key, string $group = ''): bool { return true; }
function wp_using_ext_object_cache(): bool { return false; }

/** Backdate a cached entry so it reads as expired, without waiting. */
function probe_age(string $dataset, string $key, int $seconds): void
{
    $name = 'thb_c_' . md5($dataset . '|' . $key);
    if (isset(Probe::$transients[$name][0]['fetchedAt'])) {
        Probe::$transients[$name][0]['fetchedAt'] -= $seconds;
    }
}

/* ---------------------------------------------------------------------------
 * Cron
 *
 * wp_schedule_event() VALIDATES THE RECURRENCE, exactly as WordPress does:
 *
 *     $schedules = wp_get_schedules();
 *     if ( ! isset( $schedules[ $recurrence ] ) ) { return false; }
 *
 * This is not incidental fidelity. A stub that accepted any recurrence would
 * report a healthy event for the bug where activation scheduled 'thb_tick'
 * before anything had registered that schedule, got false back, and said
 * nothing — leaving a site with no warming event at all.
 * ------------------------------------------------------------------------ */

$GLOBALS['thb_cron'] = [];

function wp_schedule_event(int $ts, string $recurrence, string $hook, array $args = [], bool $wpError = false)
{
    $schedules = wp_get_schedules();
    if (!isset($schedules[$recurrence])) {
        return $wpError ? new WP_Error('invalid_schedule', 'Event schedule does not exist.') : false;
    }
    $GLOBALS['thb_cron'][$hook] = ['timestamp' => $ts, 'schedule' => $recurrence, 'args' => $args];
    return true;
}

function wp_get_schedules(): array
{
    $core = [
        'hourly'     => ['interval' => HOUR_IN_SECONDS, 'display' => 'Once Hourly'],
        'twicedaily' => ['interval' => 12 * HOUR_IN_SECONDS, 'display' => 'Twice Daily'],
        'daily'      => ['interval' => DAY_IN_SECONDS, 'display' => 'Once Daily'],
        'weekly'     => ['interval' => 7 * DAY_IN_SECONDS, 'display' => 'Once Weekly'],
    ];
    return array_merge((array) apply_filters('cron_schedules', []), $core);
}

function wp_next_scheduled(string $hook, array $args = [])
{
    return isset($GLOBALS['thb_cron'][$hook]) ? (int) $GLOBALS['thb_cron'][$hook]['timestamp'] : false;
}

function wp_get_scheduled_event(string $hook, array $args = [], $timestamp = null)
{
    if (!isset($GLOBALS['thb_cron'][$hook])) {
        return false;
    }
    $e = $GLOBALS['thb_cron'][$hook];
    return (object) ['hook' => $hook, 'timestamp' => $e['timestamp'], 'schedule' => $e['schedule'], 'args' => $e['args']];
}

function wp_clear_scheduled_hook(string $hook, array $args = []): int
{
    unset($GLOBALS['thb_cron'][$hook]);
    return 1;
}

function wp_reschedule_event(int $ts, string $recurrence, string $hook, array $args = [], bool $wpError = false)
{
    return wp_schedule_event($ts, $recurrence, $hook, $args, $wpError);
}

/** Runs due events the way wp-cron.php does: reschedule, then dispatch. */
function thb_run_due_cron(int $now): array
{
    $fired = [];
    foreach (($GLOBALS['thb_cron'] ?? []) as $hook => $event) {
        if ((int) $event['timestamp'] > $now) {
            continue;
        }
        $schedules = wp_get_schedules();
        if (isset($schedules[$event['schedule']])) {
            $GLOBALS['thb_cron'][$hook]['timestamp'] = $now + (int) $schedules[$event['schedule']]['interval'];
        } else {
            unset($GLOBALS['thb_cron'][$hook]);   // core drops what it cannot reschedule
        }
        do_action($hook);
        $fired[] = $hook;
    }
    return $fired;
}

/* ---------------------------------------------------------------------------
 * $wpdb
 *
 * History's reads and writes are SQL rather than HTTP, and the options table is
 * emulated FOR REAL because the warming lock's whole correctness argument is
 * that its acquire is one statement rather than a read followed by a write. A
 * query() answering with a constant would let a broken lock pass. So the three
 * statements Lock issues — INSERT IGNORE, a compare-and-swap UPDATE and a
 * value-guarded DELETE — execute against the same array get_option() reads, and
 * each returns the row count MySQL would return.
 * ------------------------------------------------------------------------ */

class wpdb {}

final class FakeWpdb extends wpdb
{
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public int $queries = 0;

    /** @var array<int, array<string, mixed>> rows History has inserted */
    public array $historyRows = [];

    /** Enough of wpdb::prepare() to substitute the values the plugin passes. */
    public function prepare(string $sql, ...$a): string
    {
        foreach ($a as $value) {
            $replacement = is_int($value) || is_float($value)
                ? (string) $value
                : "'" . str_replace("'", "''", (string) $value) . "'";
            $sql = preg_replace('/%[sdfF]/', str_replace('$', '\\$', $replacement), $sql, 1) ?? $sql;
        }
        return $sql;
    }

    public function get_var(string $sql) { $this->queries++; return null; }
    public function get_results(string $sql, $mode = null): array
    {
        $this->queries++;
        /* History::series() reads back what History::record() inserted —
           only when a test asks for it, so every other suite keeps the empty
           history it was written against. */
        if (empty($GLOBALS['thb_history_reads'])
            || !preg_match("/coin_slug = '(.*?)' AND dataset = '(.*?)' AND captured_at >= '(.*?)'/", $sql, $m)) {
            return [];
        }
        $rows = array_values(array_filter($this->historyRows,
            static fn($r) => $r['coin_slug'] === $m[1] && $r['dataset'] === $m[2] && $r['captured_at'] >= $m[3]));
        usort($rows, static fn($a, $b) => strcmp($a['captured_at'], $b['captured_at']));
        return array_map(static fn($r) => ['captured_at' => $r['captured_at'], 'payload' => $r['payload']], $rows);
    }
    public function get_charset_collate(): string { return ''; }

    public function insert(string $t, array $d, array $f = []): int
    {
        $this->queries++;
        $this->historyRows[] = $d;
        return 1;
    }

    public function query(string $sql): int
    {
        $this->queries++;

        // INSERT IGNORE ... VALUES ('name', 'value', 'no')
        if (preg_match("/^INSERT IGNORE INTO .*VALUES \\('(.*?)', '(.*)', '(?:no|off)'\\)$/s", $sql, $m)) {
            $name = self::unquote($m[1]);
            if (array_key_exists($name, $GLOBALS['thb_options'])) {
                return 0;   // the unique index refused it; somebody else holds it
            }
            $GLOBALS['thb_options'][$name] = self::unquote($m[2]);
            return 1;
        }

        // UPDATE ... SET option_value = 'to' WHERE option_name = 'n' AND option_value = 'from'
        if (preg_match("/^UPDATE .*SET `option_value` = '(.*)' WHERE `option_name` = '(.*?)' AND `option_value` = '(.*)'$/s", $sql, $m)) {
            $name = self::unquote($m[2]);
            if (($GLOBALS['thb_options'][$name] ?? null) !== self::unquote($m[3])) {
                return 0;   // somebody changed it first; this process lost the race
            }
            $GLOBALS['thb_options'][$name] = self::unquote($m[1]);
            return 1;
        }

        // DELETE ... WHERE option_name = 'n' AND option_value = 'v'
        if (preg_match("/^DELETE FROM .*WHERE `option_name` = '(.*?)' AND `option_value` = '(.*)'$/s", $sql, $m)) {
            $name = self::unquote($m[1]);
            if (($GLOBALS['thb_options'][$name] ?? null) !== self::unquote($m[2])) {
                return 0;
            }
            unset($GLOBALS['thb_options'][$name]);
            return 1;
        }

        return 0;
    }

    private static function unquote(string $s): string { return str_replace("''", "'", $s); }
}
$GLOBALS['wpdb'] = new FakeWpdb();

function dbDelta(string $sql): array { return []; }

/* ---------------------------------------------------------------------------
 * HTTP transport
 *
 * Returns plausible payloads and counts every call. The rolling rate limiter
 * reproduces what the production server actually measured: five CoinGecko
 * requests succeed, the sixth returns 429 with Retry-After: 60.
 * ------------------------------------------------------------------------ */

function wp_remote_get(string $url, array $args = [])
{
    $host = parse_url($url, PHP_URL_HOST) ?: '';
    Probe::$calls[] = ['url' => $url, 'host' => $host, 'at' => Clock::$now];
    Probe::$network += ASSUMED_LATENCY;

    if (!empty($GLOBALS['thb_probe_fail_all'])) {
        return new WP_Error('http_request_failed', 'cURL error 6: Could not resolve host');
    }

    // Rolling allowance, refilling as the clock advances.
    if (isset(Probe::$rateLimit[$host])) {
        $window = Probe::$rateLimit[$host];
        Probe::$served[$host] = array_values(array_filter(
            Probe::$served[$host] ?? [],
            static fn(int $t): bool => $t > Clock::$now - $window['per']
        ));
        if (count(Probe::$served[$host]) >= $window['max']) {
            Probe::$refused[$host] = (Probe::$refused[$host] ?? 0) + 1;
            return [
                'response' => ['code' => 429],
                'headers'  => ['retry-after' => '60'],
                'body'     => '{"status":{"error_code":429}}',
            ];
        }
        Probe::$served[$host][] = Clock::$now;
    }

    if (!empty($GLOBALS['thb_probe_fail_hosts'][$host])) {
        $code = (int) $GLOBALS['thb_probe_fail_hosts'][$host];
        return [
            'response' => ['code' => $code],
            'headers'  => Probe::$responseHeaders[$host] ?? [],
            'body'     => (string) ($GLOBALS['thb_probe_fail_bodies'][$host] ?? '{}'),
        ];
    }

    return [
        'response' => ['code' => 200],
        'headers'  => Probe::$responseHeaders[$host] ?? [],
        'body'     => (string) json_encode(probe_payload($url)),
    ];
}

function wp_remote_retrieve_response_code($r): int { return (int) $r['response']['code']; }
function wp_remote_retrieve_header($r, string $name) { return $r['headers'][strtolower($name)] ?? ''; }
function wp_remote_retrieve_body($r): string { return (string) $r['body']; }

require_once __DIR__ . '/wp-payloads.php';

/* ---------------------------------------------------------------------------
 * Posts, terms and templates
 * ------------------------------------------------------------------------ */

class WP_Post
{
    public int $ID = 1;
    public string $post_title = 'عنوان خبر';
    public string $post_name = 'ethereum';
    public string $post_type = 'thb_coin';
    public string $post_excerpt = 'خلاصه خبر';
    public string $post_content = 'متن خبر';
}

class WP_Query
{
    public array $posts = [];
    public function __construct(array $args = [])
    {
        $n = (int) ($args['posts_per_page'] ?? 4);
        for ($i = 1; $i <= max(0, $n); $i++) {
            $p = new WP_Post();
            $p->ID = 500 + $i;
            $p->post_title = 'خبر شماره ' . $i;
            $this->posts[] = $p;
        }
    }
}

function get_post_type($id = null): string
{
    return $GLOBALS['thb_post_types'][$id] ?? 'thb_coin';
}

function get_post_field(string $field, $id = 0)
{
    if ($field === 'post_name') {
        return $GLOBALS['thb_post_slugs'][$id] ?? ($GLOBALS['thb_probe_slug'] ?? 'ethereum');
    }
    return '';
}

/**
 * ACF, per post where the caller set it up.
 *
 * Keyed by post id first so several coins can be configured at once, which the
 * scheduler and capacity models need; falls back to a flat map for the simple
 * single-coin cases.
 */
function get_field(string $field, $id = false)
{
    if ($id !== false && isset($GLOBALS['thb_coin_fields'][$id])) {
        return $GLOBALS['thb_coin_fields'][$id][$field] ?? null;
    }
    return $GLOBALS['thb_acf'][$field] ?? null;
}

function get_post_meta($id, $k = '', bool $single = false) { return ''; }
function get_posts(array $a): array { return $GLOBALS['thb_coin_ids'] ?? []; }
function get_page_by_path(string $p, $out = null, $type = '') { return $GLOBALS['thb_pages'][$p] ?? null; }
function update_post_meta($id, $k, $v, $prev = '') { $GLOBALS['thb_coin_fields'][$id][$k] = $v; return true; }
function get_the_title($p = 0): string { return is_object($p) ? ($p->post_title ?? '') : 'اتریوم'; }
function get_permalink($p = 0): string { return 'https://thehybit.com/post/' . (is_object($p) ? $p->ID : $p); }
function get_post_time(string $f, bool $gmt = false, $p = null) { return '2026-08-10T09:00:00+00:00'; }
function get_post_thumbnail_id($p = null): int { return 0; }
function get_the_post_thumbnail_url($id = null, $size = 'thumbnail') { return false; }
function wp_get_attachment_image_src($id, $size) { return false; }
function get_edit_post_link($id = 0) { return 'https://thehybit.com/wp-admin/post.php?post=' . $id; }
function get_current_user_id(): int { return 1; }
function wp_insert_post(array $a, bool $wpError = false) { return 99; }
function wp_is_post_revision($p) { return false; }
function wp_is_post_autosave($p) { return false; }
function get_post_type_archive_link(string $t) { return 'https://thehybit.com/coins/'; }
/* The /coins/ list: a test sets $GLOBALS['thb_is_list'] and 'thb_paged'. */
function is_post_type_archive($t = ''): bool { return !empty($GLOBALS['thb_is_list']); }
function get_query_var(string $v, $default = '') { return $v === 'paged' ? ($GLOBALS['thb_paged'] ?? 0) : $default; }
function trailingslashit(string $s): string { return rtrim($s, '/\\') . '/'; }

function get_term_by(string $field, $value, string $tax)
{
    if (!empty($GLOBALS['thb_missing_terms'][$value])) {
        return false;
    }
    return (object) ['term_id' => 7, 'slug' => (string) $value, 'name' => 'اخبار اتریوم'];
}
function get_term_link($term, string $tax = '') { return 'https://thehybit.com/category/news/ethereum-news/'; }
function get_the_category($id = 0): array
{
    return [(object) ['term_id' => 7, 'slug' => 'ethereum-news', 'name' => 'اخبار اتریوم']];
}

/* ---------------------------------------------------------------------------
 * Escaping, formatting and the handful of template helpers
 * ------------------------------------------------------------------------ */

function wp_json_encode($d, int $f = 0, int $depth = 512) { return json_encode($d, $f | JSON_UNESCAPED_UNICODE); }
function wp_strip_all_tags(string $s, bool $breaks = false): string { return strip_tags($s); }
function wp_trim_words(string $text, int $n = 55, string $more = '…'): string
{
    $words = preg_split('/\s+/u', strip_tags($text)) ?: [];
    return count($words) <= $n ? $text : implode(' ', array_slice($words, 0, $n)) . $more;
}
function esc_html($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_textarea($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function sanitize_key($k): string { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $k) ?? ''); }
function sanitize_text_field($s): string { return trim(strip_tags((string) $s)); }
function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }
function absint($v): int { return abs((int) $v); }
function number_format_i18n($n, int $decimals = 0): string { return number_format((float) $n, $decimals); }
function size_format($bytes, $decimals = 0) { return round(((int) $bytes) / 1024) . ' KB'; }
function home_url(string $p = '/'): string { return 'https://thehybit.com' . $p; }
function rest_url(string $p = ''): string { return 'https://thehybit.com/wp-json/' . ltrim($p, '/'); }
function register_rest_route(string $ns, string $route, array $args): bool { $GLOBALS['thb_rest_routes'][$ns . $route] = $args; return true; }
function sanitize_title($t): string { return strtolower(preg_replace('/[^a-z0-9-]+/i', '-', (string) $t) ?? ''); }
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public array $headers = [];
        public function __construct(public $data = null, public int $status = 200) {}
        public function header(string $k, string $v): void { $this->headers[$k] = $v; }
        public function get_data() { return $this->data; }
        public function get_status(): int { return $this->status; }
    }
}
function get_bloginfo(string $show = ''): string { return $show === 'name' ? ($GLOBALS['thb_blogname'] ?? 'های‌بیت') : ''; }
function site_url(string $p = ''): string { return 'https://thehybit.com/' . ltrim($p, '/'); }
function admin_url(string $p = ''): string { return 'https://thehybit.com/wp-admin/' . ltrim($p, '/'); }
function add_query_arg(array $args, string $url = ''): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
}
function current_user_can(string $cap): bool { return (bool) ($GLOBALS['thb_can'] ?? true); }
function check_admin_referer(string $action, string $arg = '_wpnonce'): bool { return true; }
function wp_nonce_field(string $a, string $n = '_wpnonce', bool $referer = true, bool $echo = true): string
{
    $html = '<input type="hidden" name="' . $n . '" value="nonce">';
    if ($echo) { echo $html; }
    return $html;
}
function wp_die($msg = '', $title = '', $args = []) { throw new RuntimeException('wp_die: ' . (string) $msg); }
/**
 * Admin handlers end with `wp_safe_redirect(...); exit;`. A stub that returned
 * would let execution reach that `exit` and end the whole test run silently —
 * every assertion after it simply never happens, and the suite still reports
 * the ones before it as passing. Throwing stops the handler exactly where a
 * real redirect would, and the caller catches it.
 */
final class ThbRedirect extends RuntimeException {}
function wp_safe_redirect(string $l, int $status = 302): bool
{
    $GLOBALS['thb_redirect'] = $l;
    throw new ThbRedirect($l);
}
function register_shutdown_function_stub(): void {}
