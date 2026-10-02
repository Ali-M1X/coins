<?php
/**
 * Cron. Bounded work per tick, resumed from where the last one stopped.
 *
 * WHAT THIS REPLACED, AND WHY
 *
 * The previous scheduler ran one cron event per dataset and looped every coin
 * inside a single execution. Modelled at 25 coins, the chart tick took 304
 * seconds — past a typical max_execution_time — and a tick killed mid-loop
 * always died at the same coins, because the coin order never varied. The tail
 * of the list would simply never have been warmed. It failed silently, too: a
 * killed cron tick leaves no error, only coins that quietly stop updating.
 *
 * THE REPLACEMENT
 *
 * One tick. It stops at whichever limit it reaches first — items, seconds, or
 * the provider's remaining budget — and writes down where it stopped. The next
 * tick resumes there. No coin can starve, because the cursor always moves and
 * because the queue is ordered by priority and then by how overdue each entry
 * is.
 *
 * The measured production ceiling is five CoinGecko requests a minute, so a
 * tick that wanted to do more than a handful of work would be refused anyway.
 * Bounding the work is not a safety net here; it is the normal operating mode.
 *
 * BATCHING COMES FIRST. Market data for every coin arrives in one request, so
 * the tick spends its first call on the thing that serves everybody and only
 * then works down the per-coin queue.
 *
 * WHO CALLS THIS
 *
 * A real crontab, hitting wp-cron.php every minute, with WP's own visitor-driven
 * pseudo-cron switched off. That is the only arrangement in which warming is
 * independent of traffic: WP-Cron fires on page loads, so a quiet hour means an
 * hour with no ticks, and the coins that most need refreshing are exactly the
 * ones nobody is looking at. See docs/wordpress-integration.md for the crontab
 * line and the wp-config change; the diagnostics screen reports whether both
 * are actually in place rather than assuming it.
 *
 * Two ticks must never run at once — they would spend the same minute's budget
 * twice and overwrite each other's cursor — so the whole tick is wrapped in a
 * lock. See includes/Lock.php for why that lock is a database statement rather
 * than a transient.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class Scheduler
{
    public const HOOK_TICK  = 'thb_warm_tick';
    private const HOOK_PRUNE = 'thb_prune_history';

    /** The custom recurrence name, registered through the cron_schedules filter. */
    public const SCHEDULE = 'thb_tick';

    /** Config for the static schedule filter, which runs before Plugin exists. */
    private static ?array $bootConfig = null;

    /** Where the last tick stopped, so the next one does not restart at coin #1. */
    private const OPTION_CURSOR = 'thb_warm_cursor';

    /** Last tick's outcome, for the diagnostics screen. */
    public const OPTION_PROGRESS = 'thb_warm_progress';

    /** The last time a tick declined to start because another held the lock. */
    public const OPTION_LAST_SKIP = 'thb_warm_last_skip';

    /** Where the next tick's coin-scoped candidate window starts. */
    public const OPTION_COIN_OFFSET = 'thb_warm_coin_offset';

    /** Where the last queue() call's coin window stopped; tick() persists it. */
    private int $windowEnd = 0;

    private Datasets $datasets;
    private Budget $budget;
    private Lock $lock;

    public function __construct(
        private array $config,
        private CoinRepository $coins,
        private Pipeline $pipeline,
        private History $history
    ) {
        $this->datasets = new Datasets($config);
        $this->budget   = new Budget($config);
        $this->lock     = new Lock($config);
    }

    public function register(): void
    {
        self::registerSchedule();
        add_action(self::HOOK_TICK, [$this, 'tick']);
        add_action(self::HOOK_PRUNE, [$this, 'prune']);

        // Self-healing, for the case activation cannot cover: a site upgraded
        // from a build whose activation silently failed to schedule anything.
        // Its files change, but activation does not re-run, so without this the
        // event would stay missing forever. See ensureScheduled().
        add_action('init', [self::class, 'ensureScheduled']);
    }

    /**
     * Teach WordPress the thb_tick recurrence.
     *
     * THE BUG THIS FIXES
     *
     * This filter used to be added inside register(), which runs from
     * Plugin::boot() on `plugins_loaded`. Activation does not work that way: by
     * the time WordPress includes a plugin's file in order to activate it,
     * `plugins_loaded` has already fired for that request, so boot() never runs
     * and the filter was never added. wp_schedule_event() then looked up
     * 'thb_tick' in wp_get_schedules(), did not find it, and returned false.
     *
     * It failed SILENTLY. Activation reported success, no error appeared
     * anywhere, and the site simply had no warming event — which looks exactly
     * like cron not firing, and sends you to inspect the crontab instead.
     *
     * So the filter is added at plugin-file scope, which runs during activation
     * as well as on every normal request. Registering it a second time is
     * harmless: WordPress keys callbacks by identity, so adding the same static
     * method at the same priority replaces rather than duplicates.
     *
     * It also has to be present for RESCHEDULING. wp-cron.php unschedules an
     * occurrence after running it and relies on wp_reschedule_event() to lay
     * down the next one — and that call fails the same way if the recurrence is
     * unknown, which would silently turn a recurring event into a one-shot.
     */
    public static function registerSchedule(): void
    {
        add_filter('cron_schedules', [self::class, 'filterSchedules']);
    }

    /** @param array<string, array{interval:int, display:string}> $schedules */
    public static function filterSchedules(array $schedules): array
    {
        $interval = self::interval(self::config());
        $schedules[self::SCHEDULE] = [
            'interval' => $interval,
            'display'  => sprintf('TheHybit: warm tick (%ds)', $interval),
        ];
        return $schedules;
    }

    /**
     * Config, loaded once and only when something actually needs it.
     *
     * The filter runs at plugin-file scope, before Plugin::instance() exists, so
     * it cannot read the config from there. Loading lazily keeps a normal
     * request that never touches cron from paying for the read.
     */
    private static function config(): array
    {
        if (self::$bootConfig === null) {
            self::$bootConfig = defined('THB_COINS_DIR')
                ? (array) require THB_COINS_DIR . 'config/providers.php'
                : [];
        }
        return self::$bootConfig;
    }

    /**
     * The tick's interval, deliberately just under the crontab's.
     *
     * WP-Cron only runs an event whose timestamp has passed, and it reschedules
     * from the moment the event ran, not from the moment it was due. With both
     * set to 60 seconds a tick that starts at 12:00:03 is next due at 12:01:03,
     * so the 12:01:00 crontab run finds nothing to do and the real cadence
     * halves to one tick every two minutes — silently, and worse the longer a
     * tick takes.
     *
     * A slightly shorter interval removes the drift: the event is always already
     * due when the minute arrives. It does not make ticks more frequent, because
     * the crontab is what actually fires them.
     */
    public static function interval(array $config): int
    {
        return max(30, (int) ($config['scheduler']['interval'] ?? 55));
    }

    /**
     * Create the cron events. Safe to call repeatedly.
     *
     * @return bool whether the warm tick is scheduled when this returns
     */
    public static function activate(array $config = []): bool
    {
        /* The schedule must exist BEFORE wp_schedule_event() looks it up. At
           plugin-file scope it already does; this makes activation correct even
           when this class is reached some other way. */
        self::registerSchedule();

        self::clearLegacyEvents();

        $scheduled = self::ensureScheduled();

        if (!wp_next_scheduled(self::HOOK_PRUNE)) {
            wp_schedule_event(time() + 300, 'daily', self::HOOK_PRUNE);
        }

        return $scheduled;
    }

    /**
     * Make sure exactly one warm tick is scheduled, with the right recurrence.
     *
     * Idempotent by construction, and the only place the event is created — so
     * activation, reactivation and the `init` safety net cannot disagree about
     * what "scheduled" means or race each other into two events.
     *
     * @return bool whether the event is scheduled when this returns
     */
    public static function ensureScheduled(): bool
    {
        $next = wp_next_scheduled(self::HOOK_TICK);

        if ($next !== false) {
            /* An event exists. It still has to carry OUR recurrence: one left
               behind by an older build, or laid down while the schedule was
               unknown, would run once and never again. Clearing and recreating
               is the only way to change an existing event's recurrence, and
               clearing first is also what keeps this from ever producing two. */
            $event = function_exists('wp_get_scheduled_event')
                ? wp_get_scheduled_event(self::HOOK_TICK)
                : null;

            if (!is_object($event) || ($event->schedule ?? '') === self::SCHEDULE) {
                return true;
            }

            error_log(sprintf(
                '[thb] warm tick had recurrence "%s"; replacing it with %s',
                (string) ($event->schedule ?: 'none'),
                self::SCHEDULE
            ));
            wp_clear_scheduled_hook(self::HOOK_TICK);
        }

        self::registerSchedule();

        /* Verify rather than hope. wp_schedule_event() returns false for an
           unknown recurrence and says nothing else about it, which is precisely
           how this went unnoticed: activation succeeded, no event existed, and
           the symptom looked like a cron misconfiguration. */
        $schedules = wp_get_schedules();
        if (!isset($schedules[self::SCHEDULE])) {
            error_log(
                '[thb] the ' . self::SCHEDULE . ' schedule is not registered, so the warm tick '
                . 'cannot be created. Something removed the cron_schedules filter.'
            );
            return false;
        }

        // A short delay so the first tick does not land inside the activation
        // request itself, where it would compete with the redirect.
        $result = wp_schedule_event(time() + 30, self::SCHEDULE, self::HOOK_TICK);

        if ($result === false || is_wp_error($result)) {
            error_log('[thb] wp_schedule_event() refused the warm tick: ' . (
                is_wp_error($result) ? $result->get_error_message() : 'returned false'
            ));
            return false;
        }

        return wp_next_scheduled(self::HOOK_TICK) !== false;
    }

    public static function deactivate(array $config = []): void
    {
        wp_clear_scheduled_hook(self::HOOK_TICK);
        wp_clear_scheduled_hook(self::HOOK_PRUNE);
        self::clearLegacyEvents();

        // A tick killed by the deactivation itself would otherwise leave its
        // lock behind for the whole TTL, so reactivating would appear to do
        // nothing for two minutes.
        (new Lock($config))->forceRelease();
    }

    /** The per-dataset events this scheduler used to register. */
    private static function clearLegacyEvents(): void
    {
        foreach (['market', 'chart', 'dex', 'defi', 'onchain', 'l2', 'fx', 'metadata', 'historical'] as $dataset) {
            wp_clear_scheduled_hook('thb_warm_dataset', [$dataset]);
        }
    }

    /* ------------------------------------------------------------------
     * The tick
     * ---------------------------------------------------------------- */

    /**
     * Do a bounded amount of the most valuable outstanding work.
     *
     * Refuses to start if another tick is already running. That refusal is a
     * normal, expected outcome once a crontab is driving this every minute, not
     * an error — so it is recorded and returned rather than logged as a failure.
     *
     * @return array the progress record, also stored for diagnostics
     */
    public function tick(): array
    {
        $token = $this->lock->acquire();

        if ($token === null) {
            $status = $this->lock->status();
            $skipped = sprintf(
                'another tick has been running for %ds (pid %d)',
                (int) $status['ageSeconds'],
                (int) $status['pid']
            );

            // Deliberately NOT written to OPTION_PROGRESS: the running tick owns
            // that record, and overwriting it would erase the work in flight.
            update_option(self::OPTION_LAST_SKIP, [
                'at'     => gmdate('c'),
                'reason' => $skipped,
            ], false);

            return [
                'ranAt'     => gmdate('c'),
                'status'    => 'locked',
                'stoppedBy' => 'another tick holds the lock',
                'skipped'   => [$skipped],
                'refreshed' => [],
                'batched'   => 0,
                'seconds'   => 0.0,
            ];
        }

        /* A fatal error, an OOM, or max_execution_time does not unwind the
           stack, so `finally` alone would leak the lock until it expired.
           release() is token-guarded and idempotent, so running from both here
           and the finally block is safe. */
        register_shutdown_function([$this->lock, 'release'], $token);

        try {
            return $this->run();
        } finally {
            $this->lock->release($token);
        }
    }

    /** The tick proper. Always called with the lock held. */
    private function run(): array
    {
        $started = microtime(true);
        $limits = (array) ($this->config['scheduler'] ?? []);

        /* Ask for room first — but only ever RAISE the limit.
         *
         * PHP CLI reports max_execution_time as 0, meaning unlimited, and yet it
         * DOES honour set_time_limit(). So calling this unconditionally would
         * impose a ceiling on a CLI cron run that had none, which is the exact
         * opposite of the intent. On a web request, where the host default is
         * typically 30, it grants the room the tick needs; where the function is
         * disabled it is a harmless no-op and the deadline below does the work.
         */
        $want = (int) ($limits['max_seconds'] ?? 20) + 60;
        $current = (int) ini_get('max_execution_time');
        if ($current > 0 && $current < $want && function_exists('set_time_limit')) {
            @set_time_limit($want);
        }

        $deadline = $this->hardDeadline($started);
        $maxItems = (int) ($limits['max_items'] ?? 8);
        $maxSeconds = (int) ($limits['max_seconds'] ?? 20);

        $coins = $this->coins->all();
        $progress = [
            'ranAt'       => gmdate('c'),
            'ranAtUnix'   => time(),
            'status'      => 'ran',
            'coins'       => count($coins),
            'batched'     => 0,
            'refreshed'   => [],
            'lastDataset' => '',
            'skipped'     => [],
            'stoppedBy'   => 'nothing due',
            'seconds'     => 0.0,
            'cursor'      => 0,
        ];

        if ($coins === []) {
            update_option(self::OPTION_PROGRESS, $progress, false);
            return $progress;
        }

        /* ---- 1. The batch, which serves every coin at once ---- */
        if ($this->batchDue($coins)) {
            if (($refusal = $this->budget->refuse('coingecko')) !== null) {
                $progress['skipped'][] = 'market (batched): ' . $refusal;
            } else {
                $size = (int) ($limits['batch_size'] ?? 50);
                foreach (array_chunk($coins, max(1, $size)) as $chunk) {
                    if ($this->budget->refuse('coingecko') !== null) {
                        $progress['skipped'][] = 'market (batched): budget spent mid-batch';
                        break;
                    }
                    $progress['batched'] += $this->pipeline->warmBatch($chunk, 'market');
                    $progress['lastDataset'] = 'market (batched)';
                }
            }
        }

        /* ---- 2. Shared datasets, served from the front ----
         *
         * WHY THESE ARE NOT CURSORED. The cursor rotates through the queue so
         * that no coin sits permanently at the back — which is exactly right for
         * the hundred coin-scoped entries it was built for, and exactly wrong
         * for the handful of shared ones.
         *
         * There is only ever ONE /global entry. Cursored, it is skipped until
         * the position pointer wraps all the way around a 239-entry queue, which
         * at eight items a tick takes an hour: the capacity model measured
         * /global refreshing six times an hour at two coins and ONCE at a
         * hundred, despite a ten-minute TTL and a priority above the charts it
         * was losing to. Site-wide market context would have quietly stopped
         * updating at the scale it exists for.
         *
         * So shared entries are served from the front of their own short list,
         * strictly on how overdue they are. There are at most a dozen, each
         * TTL-gated and each serving every coin that shares its scope, so this
         * cannot run away — and the budget still refuses it like anything else.
         */
        $done = 0;

        /* Built ONCE. Shared and coin-scoped entries do not affect each other's
           due-ness, so splitting one queue is equivalent to building two — and
           at a hundred coins the shared scan walks every coin, which is worth
           doing once per tick, not twice. */
        $all = $this->queue($coins);
        update_option(self::OPTION_COIN_OFFSET, $this->windowEnd, false);

        $shared = array_values(array_filter(
            $all,
            fn(array $item): bool => $this->reach($item['dataset']) < 2
        ));

        /* Shared work may not take the whole tick.
         *
         * Serving shared entries first fixes one starvation problem and would
         * create its own if left unbounded: on a cold cache every shared dataset
         * is due at once, and six of them would consume an eight-item tick
         * entirely, leaving the per-coin queue untouched for as long as that
         * lasted. Half the allowance each means neither pass can starve the
         * other, and since shared entries are few and slow-moving they simply
         * fill in over two ticks instead of one. */
        $sharedBudget = max(1, intdiv($maxItems, 2));

        foreach ($shared as $item) {
            if ($done >= $sharedBudget) {
                break;
            }
            if ($done >= $maxItems) {
                $progress['stoppedBy'] = 'item limit';
                break;
            }
            if ((microtime(true) - $started) >= $maxSeconds) {
                $progress['stoppedBy'] = 'time limit';
                break;
            }
            /* The same deadline guard the per-coin pass uses. Shared entries are
               not cheaper to abandon halfway — a tick killed mid-request here
               leaves its lock held exactly as it would there. */
            if ($deadline !== null && (microtime(true) + $this->worstItemSeconds()) >= $deadline) {
                $progress['stoppedBy'] = 'no room to finish another item before max_execution_time';
                break;
            }

            if (!$this->spend($item, $progress)) {
                continue;
            }
            $done++;
        }

        /* ---- 3. The per-coin queue, most valuable first ---- */
        $queue = array_values(array_filter(
            $all,
            fn(array $item): bool => $this->reach($item['dataset']) === 2
        ));
        $total = count($queue);

        /* The cursor is a position in the queue, walked one step at a time.
         *
         * It used to be recomputed as ($cursor + $i + 1) % $total inside the
         * loop while ALSO being read as ($cursor + $i) to pick the item, so each
         * iteration advanced by two rather than one and the tick served a
         * scattered subset of the queue instead of a contiguous run. Nothing
         * starved permanently — the cursor still moved and still wrapped — but
         * which entries a tick actually reached depended on arithmetic nobody
         * intended. A separate position, incremented exactly once per item
         * considered, is the whole fix.
         */
        $position = $total > 0 ? ((int) get_option(self::OPTION_CURSOR, 0)) % $total : 0;
        $cursor = $position;

        for ($i = 0; $i < $total; $i++) {
            if ($done >= $maxItems) {
                $progress['stoppedBy'] = 'item limit';
                break;
            }
            if ((microtime(true) - $started) >= $maxSeconds) {
                $progress['stoppedBy'] = 'time limit';
                break;
            }

            /* The configured ceiling is checked BETWEEN items, so a tick that
               starts an item at 19.9s still has to wait out that item — up to a
               12-second HTTP timeout plus a 5-second throttle. On a host holding
               PHP to 30 seconds that overshoot is what kills the process, and a
               killed tick is the silent failure this whole scheduler exists to
               avoid. So an item is only STARTED if there is room to finish it. */
            if ($deadline !== null && (microtime(true) + $this->worstItemSeconds()) >= $deadline) {
                $progress['stoppedBy'] = 'no room to finish another item before max_execution_time';
                break;
            }

            // Resume where the last tick stopped, wrapping. This is what stops
            // the queue being re-served from the top every minute.
            $item = $queue[$position % $total];

            /* Advance past this entry whatever happens to it. A refused item
               that left the cursor behind would be reconsidered first on the
               next tick, and refused again, and the queue behind it would never
               be reached while the provider stayed cold. */
            $position++;
            $cursor = $position % $total;

            if (!$this->spend($item, $progress)) {
                continue;   // a refused provider must not block other providers
            }

            $done++;
        }

        if ($done > 0 && $progress['stoppedBy'] === 'nothing due') {
            $progress['stoppedBy'] = 'queue exhausted';
        }

        update_option(self::OPTION_CURSOR, $cursor, false);

        $progress['cursor'] = $cursor;
        $progress['queued'] = $total;
        $progress['seconds'] = round(microtime(true) - $started, 2);
        update_option(self::OPTION_PROGRESS, $progress, false);

        return $progress;
    }

    /**
     * When PHP will kill this process, with a margin — or null if it will not.
     *
     * max_execution_time of 0 means unlimited, which is the normal case for CLI
     * and for many hosts' cron. Reading it rather than assuming 30 matters both
     * ways: assuming a limit that is not there would throw away throughput, and
     * assuming one that is longer than reality is how ticks get killed.
     */
    private function hardDeadline(float $started): ?float
    {
        $limit = (int) ini_get('max_execution_time');
        if ($limit <= 0) {
            return null;
        }

        // Four fifths of it. The rest absorbs bootstrap, other plugins' cron
        // events sharing this same request, and the shutdown work after us.
        return $started + ($limit * 0.8);
    }

    /**
     * The longest a single queue entry can take, from configuration.
     *
     * The slowest provider's HTTP timeout plus the throttle's own ceiling —
     * derived rather than guessed, so raising a timeout in config cannot
     * silently invalidate the margin.
     */
    private function worstItemSeconds(): float
    {
        $timeout = 0;
        foreach ((array) ($this->config['providers'] ?? []) as $settings) {
            $timeout = max($timeout, (int) ($settings['timeout'] ?? 0));
        }

        return $timeout + 5.0;   // Collector::throttle() sleeps at most 5s
    }

    /**
     * Everything that is due, most important first.
     *
     * Ordered by priority, then by how far past its TTL each entry is measured
     * in multiples of its own lifetime — so a dataset is compared against its
     * own cadence rather than against a faster one. A dataset never fetched has
     * infinite staleness and sorts to the front of its priority band, which is
     * what guarantees a new coin fills in rather than waiting behind everything
     * that merely needs refreshing.
     *
     * @param Coin[] $coins
     * @return array<int, array{coin:Coin, dataset:string, priority:int, overdue:float}>
     */
    public function queue(array $coins, ?int $offset = null): array
    {
        $maxCandidates = (int) ($this->config['scheduler']['max_candidates'] ?? 200);
        $queue = [];
        $seen = [];
        $total = count($coins);

        /* ---- 1. Shared entries, from EVERY coin ----
         *
         * THE BUG THIS FIXES. Candidates used to be collected in one pass over
         * the coin list, stopping at max_candidates — about twenty coins. At a
         * hundred, the other eighty were never looked at, and a chain-scoped
         * dataset whose chain only appears among them was never refreshed AT
         * ALL. The capacity model showed it plainly: Bitcoin's network stats and
         * eight chains' DeFi figures got zero requests an hour.
         *
         * Shared entries are few by construction — at most one per site dataset
         * plus one per chain dataset per chain — so they are collected from the
         * whole list. Duplicates are rejected BEFORE they cost anything: the
         * seen-check is an array lookup, and only a new entry pays for a cache
         * peek. */
        foreach ($coins as $coin) {
            foreach ($this->warmable($coin) as $dataset) {
                if ($this->reach($dataset) === 2) {
                    continue;   // coin-scoped: the bounded pass below
                }
                $this->consider($coin, $dataset, $seen, $queue);
            }
        }

        /* ---- 2. Coin-scoped entries, from a rotating window ----
         *
         * These genuinely scale with the coin count, so the candidate cap still
         * applies — but the window now starts where the last tick's stopped and
         * wraps, so every coin gets its turn instead of the head of the list
         * getting all of them. */
        $start = $total > 0 ? ((int) ($offset ?? get_option(self::OPTION_COIN_OFFSET, 0))) % $total : 0;
        $inspected = 0;
        $scanned = 0;

        for ($i = 0; $i < $total; $i++) {
            $coin = $coins[($start + $i) % $total];
            $datasets = array_values(array_filter(
                $this->warmable($coin),
                fn(string $d): bool => $this->reach($d) === 2
            ));

            // Whole coins only: half a coin's datasets in one window and half in
            // the next would split its chart windows across ticks for no reason.
            if ($inspected > 0 && $inspected + count($datasets) > $maxCandidates) {
                break;
            }

            foreach ($datasets as $dataset) {
                $this->consider($coin, $dataset, $seen, $queue);
            }
            $inspected += count($datasets);
            $scanned++;
        }

        $this->windowEnd = $total > 0 ? ($start + $scanned) % $total : 0;

        /* Priority, then REACH, then staleness.
         *
         * Reach is the tie-breaker because a site-scoped entry serves every
         * coin for one request while a coin-scoped entry serves one. Without
         * it, a single /global entry competes on equal terms with a hundred
         * chart entries of the same priority and simply never wins: the
         * capacity model measured /global getting SIX refreshes an hour at two
         * coins and ZERO at a hundred. Site-wide market context would have
         * quietly stopped updating at exactly the scale it was built for.
         *
         * This is not a thumb on the scale. At equal priority the entry that
         * serves more coins per request is genuinely the better spend, and
         * because there is only ever one of each, promoting them costs the
         * per-coin queue at most a handful of slots an hour. */
        usort($queue, static function (array $a, array $b): int {
            return [$a['priority'], $a['reach'], -$a['overdue']]
               <=> [$b['priority'], $b['reach'], -$b['overdue']];
        });

        return $queue;
    }

    /**
     * Add one candidate to the queue if it is new and due.
     *
     * @param array<string,bool> $seen
     * @param array<int,array>   $queue
     */
    private function consider(Coin $coin, string $dataset, array &$seen, array &$queue): void
    {
        $key = $this->cacheKeyOf($dataset, $coin);

        // Scope means one entry can serve many coins; queue it once.
        if (isset($seen[$dataset . '|' . $key])) {
            return;
        }
        $seen[$dataset . '|' . $key] = true;

        $overdue = $this->overdue($dataset, $key);
        if ($overdue < 1.0) {
            return;   // still fresh
        }

        $queue[] = [
            'coin'     => $coin,
            'dataset'  => $dataset,
            'priority' => $this->datasets->priority($dataset),
            'overdue'  => $overdue,
            'reach'    => $this->reach($dataset),
        ];
    }

    /**
     * Datasets worth warming for a coin.
     *
     * Market is excluded: the batch handles it, and queueing it per coin would
     * spend one request each on data that one request already covered.
     *
     * @return array<int, string>
     */
    private function warmable(Coin $coin): array
    {
        $out = [];
        foreach ($this->datasets->forCoin($coin) as $dataset) {
            if ($dataset === 'market' && $this->datasets->isBatched($dataset)) {
                continue;
            }
            $out[] = $dataset;
        }
        return $out;
    }

    /**
     * Refresh one queue entry, if its provider will have us.
     *
     * @param array $progress passed by reference so both passes record into the
     *                        same tick report
     * @return bool whether a request was actually attempted — false means the
     *              provider refused and this entry did not consume an item slot
     */
    private function spend(array $item, array &$progress): bool
    {
        $provider = $this->datasets->provider($item['dataset']);

        if ($provider !== null && ($refusal = $this->budget->refuse($provider)) !== null) {
            $key = $provider . ': ' . $refusal;
            $progress['skipped'][$key] = ($progress['skipped'][$key] ?? 0) + 1;
            return false;
        }

        $label = $item['coin']->slug . '/' . $item['dataset'];

        try {
            $this->pipeline->warm($item['coin'], $item['dataset']);
            $progress['refreshed'][] = $label;
            $progress['lastDataset'] = $label;
        } catch (\Throwable $e) {
            // A failed fetch still spent its slot and its budget; the cache
            // keeps serving whatever it held.
            error_log('[thb] warm failed: ' . $label . ': ' . $e->getMessage());
        }

        return true;
    }

    /**
     * How many coins one refresh of this dataset serves. Lower sorts first.
     *
     * A rank rather than a count, because the actual number of coins is not
     * what matters — the ordering is. Site beats chain beats coin at every
     * installation size, and the gap only widens as coins are added.
     */
    private function reach(string $dataset): int
    {
        return match ($this->datasets->scope($dataset)) {
            Datasets::SCOPE_SITE  => 0,
            Datasets::SCOPE_CHAIN => 1,
            default               => 2,
        };
    }

    /** How many multiples of its own TTL an entry is past due. INF when never fetched. */
    private function overdue(string $dataset, string $key): float
    {
        $cache = new Cache($this->config);
        $entry = $cache->peek($dataset, $key);

        if (!is_array($entry) || empty($entry['fetchedAt'])) {
            return INF;
        }
        // A remembered failure is retried once its short miss TTL lapses, not
        // treated as fresh data.
        $ttl = !empty($entry['miss']) ? $cache->missTtl() : $cache->ttl($dataset);

        return (time() - (int) $entry['fetchedAt']) / max(1, $ttl);
    }

    private function cacheKeyOf(string $dataset, Coin $coin): string
    {
        return (new Cache($this->config))->keyFor($dataset, $coin);
    }

    /** Is the batched market data due for anybody? */
    private function batchDue(array $coins): bool
    {
        foreach ($coins as $coin) {
            if ($this->overdue('market', $this->cacheKeyOf('market', $coin)) >= 1.0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Is background warming actually happening?
     *
     * The failure this exists to catch is the quiet one. Nothing breaks when
     * cron stops firing — pages keep rendering whatever the cache last held, so
     * the site looks fine while every figure on it slowly ages out. The only
     * visible symptom is data that is a bit old, which is indistinguishable from
     * a provider being slow. So the answer has to be asked for directly.
     *
     * @return array{status:string, lastRunAt:string, lastRunAgo:?int, nextRunAt:?int,
     *               nextRunIn:?int, interval:int, wpCronDisabled:bool, scheduled:bool,
     *               lock:array, lastSkip:?array, note:string}
     */
    public function health(): array
    {
        $progress = get_option(self::OPTION_PROGRESS);
        $lock     = $this->lock->status();
        $interval = self::interval($this->config);
        $now      = time();

        $lastRun = is_array($progress) ? (int) ($progress['ranAtUnix'] ?? 0) : 0;
        $ago     = $lastRun > 0 ? max(0, $now - $lastRun) : null;

        $next   = function_exists('wp_next_scheduled') ? wp_next_scheduled(self::HOOK_TICK) : false;
        $nextAt = is_int($next) && $next > 0 ? $next : null;

        // Cron firing on page views is the thing this phase exists to replace,
        // so its state is reported whether or not it looks healthy right now:
        // a low-traffic site with WP-Cron enabled can look fine all day and
        // stop warming overnight.
        $disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

        /* Two intervals of slack before calling it stalled. One is not enough —
           a tick can legitimately be a few seconds late, and a screen that cries
           wolf gets ignored exactly when it is right. */
        $tolerance = max(180, $interval * 3);

        if ($lock['held']) {
            $status = 'running';
            $note   = sprintf('a tick has been running for %ds', (int) $lock['ageSeconds']);
        } elseif ($lastRun === 0) {
            $status = 'never';
            $note   = 'no tick has run yet';
        } elseif ($ago !== null && $ago > $tolerance) {
            $status = 'stalled';
            $note   = sprintf('the last tick was %ds ago, past the %ds tolerance', $ago, $tolerance);
        } else {
            $status = 'idle';
            $note   = 'waiting for the next tick';
        }

        // An expired lock with no tick running means the last one died.
        if ($status !== 'running' && $lock['expired']) {
            $note .= '; an expired lock is still present, so the last tick did not finish';
        }

        $lastSkip = get_option(self::OPTION_LAST_SKIP);

        return [
            'status'         => $status,
            'lastRunAt'      => is_array($progress) ? (string) ($progress['ranAt'] ?? '') : '',
            'lastRunAgo'     => $ago,
            'nextRunAt'      => $nextAt,
            'nextRunIn'      => $nextAt !== null ? $nextAt - $now : null,
            'interval'       => $interval,
            'wpCronDisabled' => $disabled,
            'scheduled'      => $nextAt !== null,
            'lock'           => $lock,
            'lastSkip'       => is_array($lastSkip) ? $lastSkip : null,
            'note'           => $note,
        ];
    }

    /**
     * The cron command for THIS installation, with real paths filled in.
     *
     * Printed on the diagnostics screen so the administrator copies a line that
     * is already correct rather than adapting one from documentation. Both parts
     * are computed on the server: guessing either of them from here would be
     * guessing about a machine we cannot see.
     */
    public static function cronCommand(): string
    {
        return self::phpBinary() . ' ' . self::runnerPath();
    }

    /** Absolute path to the plugin's cron runner. */
    public static function runnerPath(): string
    {
        return (defined('THB_COINS_DIR') ? THB_COINS_DIR : __DIR__ . '/../') . 'cron.php';
    }

    /**
     * The CLI PHP binary most likely to match the site's own PHP version.
     *
     * PHP_BINARY under php-fpm or CGI names the web SAPI's executable, not a
     * CLI one — but on cPanel's EasyApache 4 it sits under
     * /opt/cpanel/ea-phpNN/, and the CLI binary for that same version is at a
     * fixed place inside it. Deriving the path from the running interpreter is
     * what makes this match the site rather than the server default: on shared
     * cPanel, /usr/local/bin/php is frequently an older PHP than the one the
     * site is configured to use, and this plugin requires 8.1.
     */
    public static function phpBinary(): string
    {
        $binary = defined('PHP_BINARY') ? PHP_BINARY : '';

        if ($binary !== '' && preg_match('#^(/opt/cpanel/ea-php\d+)/#', $binary, $m) === 1) {
            $candidate = $m[1] . '/root/usr/bin/php';
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        // Already a usable CLI binary (some hosts run the site under CLI-ish SAPIs).
        if ($binary !== '' && str_ends_with($binary, '/php') && is_executable($binary)) {
            return $binary;
        }

        // Last resort. Correct on many hosts, but it is the SERVER default
        // rather than this site's version, so the screen says so alongside it.
        return '/usr/local/bin/php';
    }

    /** Did phpBinary() find a version-matched binary, or fall back to a guess? */
    public static function phpBinaryIsDerived(): bool
    {
        return self::phpBinary() !== '/usr/local/bin/php';
    }

    public function prune(): void
    {
        $deleted = $this->history->prune();
        if ($deleted > 0) {
            error_log('[thb] pruned ' . $deleted . ' history rows');
        }
    }
}
