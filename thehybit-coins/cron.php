<?php
/**
 * PHP-only cron entry point.
 *
 * WHY THIS FILE EXISTS
 *
 * This host's cPanel refuses any cron command that is not a PHP script —
 * "Only PHP scripts are allowed in cron jobs" — so the usual
 * `wget … wp-cron.php` line cannot be used. This file is the PHP script that
 * cron runs instead. Point the cron job at it with the PHP CLI binary and
 * nothing else is needed:
 *
 *     /opt/cpanel/ea-php81/root/usr/bin/php /home/USER/public_html/wp-content/plugins/thehybit-coins/cron.php
 *
 * The exact line for THIS installation, with the real paths already filled in,
 * is printed on Tools → TheHybit Coins.
 *
 * WHAT IT DOES, AND WHAT IT DELIBERATELY DOES NOT DO
 *
 * It hands over to WordPress's own wp-cron.php. It does not reimplement the
 * cron loop, because that loop owns WordPress's cron lock, its rescheduling and
 * its error handling, and a private copy would drift out of step with core.
 *
 * It also does NOT run only this plugin's event. With DISABLE_WP_CRON set,
 * this is the single thing waking cron for the entire site — scheduled posts,
 * updates, backups, every other plugin. A runner that served only TheHybit
 * would quietly break all of them, and the breakage would look like anything
 * except a cron job.
 *
 * SILENT ON SUCCESS
 *
 * cPanel emails whatever a cron job prints. At once a minute, a single stray
 * notice becomes 1,440 emails a day, so display_errors is turned off here and
 * nothing is printed unless something is actually wrong. Errors still reach the
 * PHP error log, where they belong. A failure prints one line and exits
 * non-zero, so the notification that does arrive is worth reading.
 *
 * @package TheHybit\Coins
 */

/* ---------------------------------------------------------------------------
 * 1. CLI only.
 *
 * The file sits inside the plugin directory, which is web-reachable. Nothing
 * here is dangerous — it runs the same cron WordPress would — but an endpoint
 * anyone can hit at will is an endpoint anyone can hold open, so the web is
 * simply refused.
 * ------------------------------------------------------------------------ */
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit('This script runs from the command line only.');
}

/* ---------------------------------------------------------------------------
 * 2. The right PHP.
 *
 * On cPanel, /usr/local/bin/php is the SERVER's default interpreter, which is
 * frequently not the version the site itself runs under MultiPHP. The plugin
 * needs 8.1, and a 7.4 interpreter would fatal somewhere deep in a typed
 * signature with a message that says nothing about cron. Failing here instead
 * gives an error that names the actual problem and the actual fix.
 * ------------------------------------------------------------------------ */
if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, sprintf(
        "TheHybit cron: PHP %s is too old — 8.1 or newer is required.\n"
        . "The cron job is using: %s\n"
        . "Point it at the same PHP version the site uses (cPanel → MultiPHP Manager),\n"
        . "for example /opt/cpanel/ea-php81/root/usr/bin/php\n",
        PHP_VERSION,
        PHP_BINARY
    ));
    exit(1);
}

/* ---------------------------------------------------------------------------
 * 3. Find WordPress.
 *
 * By walking up from this file rather than by a configured path, so moving or
 * renaming the installation cannot silently stop cron. The plugin normally sits
 * four levels below the root; the loop allows more in case of a non-standard
 * wp-content location.
 * ------------------------------------------------------------------------ */
$thbRoot = null;
$thbDir  = __DIR__;

for ($i = 0; $i < 10; $i++) {
    if (is_readable($thbDir . '/wp-load.php')) {
        $thbRoot = $thbDir;
        break;
    }
    $parent = dirname($thbDir);
    if ($parent === $thbDir) {
        break;   // reached the filesystem root
    }
    $thbDir = $parent;
}

if ($thbRoot === null) {
    fwrite(STDERR, "TheHybit cron: could not find wp-load.php above " . __DIR__ . "\n");
    exit(1);
}

if (!is_readable($thbRoot . '/wp-cron.php')) {
    fwrite(STDERR, "TheHybit cron: wp-cron.php is missing from {$thbRoot}\n");
    exit(1);
}

/* ---------------------------------------------------------------------------
 * 4. Server variables WordPress expects a request to have provided.
 *
 * Single-site WordPress resolves its own URLs from the options table, so cron
 * does not need a real hostname. Some plugins nonetheless read $_SERVER during
 * load and emit notices when it is bare — and notices become cron email. These
 * are safe defaults, not a pretend request: nothing here changes what any URL
 * resolves to.
 *
 * Pass the site URL as the first argument if a plugin genuinely needs the real
 * host during its own load:
 *
 *     … /cron.php https://thehybit.com
 *
 * MULTISITE IS NOT SUPPORTED THIS WAY. A network resolves which site it is
 * serving from the hostname, so it needs a real one; use the argument above, or
 * keep multisite on an HTTP-based cron.
 * ------------------------------------------------------------------------ */
$thbHost = 'localhost';

if (isset($argv[1]) && filter_var($argv[1], FILTER_VALIDATE_URL)) {
    $thbHost = (string) (parse_url($argv[1], PHP_URL_HOST) ?: 'localhost');
}

$_SERVER['HTTP_HOST']       = $_SERVER['HTTP_HOST'] ?? $thbHost;
$_SERVER['SERVER_NAME']     = $_SERVER['SERVER_NAME'] ?? $thbHost;
$_SERVER['REQUEST_URI']     = $_SERVER['REQUEST_URI'] ?? '/wp-cron.php';
$_SERVER['REQUEST_METHOD']  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['SERVER_PROTOCOL'] = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
$_SERVER['REMOTE_ADDR']     = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

/* wp-cron.php refuses to run if any of these are set, and rightly so — each
   means it was reached in a way it was not meant to be. Nothing should have
   populated them under CLI, but this file is the one place that can be sure. */
$_POST = [];
$_GET  = [];

/* ---------------------------------------------------------------------------
 * 5. Quiet.
 *
 * Errors keep going to the log; they just stop going to stdout, and therefore
 * stop becoming a minute-by-minute stream of cPanel email.
 * ------------------------------------------------------------------------ */
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

/* CLI reports max_execution_time as 0 and yet honours set_time_limit(), so the
   scheduler leaves an unlimited CLI run alone. Nothing is imposed here either:
   the tick bounds its own work, and a cron process should not be racing a
   clock that only exists because a wrapper invented one. */

/* ---------------------------------------------------------------------------
 * 6. Hand over to WordPress.
 *
 * DOING_CRON is deliberately NOT defined here — wp-cron.php defines it itself
 * and dies immediately if it is already set, treating that as a sign it was
 * reached recursively. It also takes its own lock, runs every due event, and
 * reschedules them. Everything after this line is core's job.
 * ------------------------------------------------------------------------ */
require $thbRoot . '/wp-cron.php';
