<?php
/**
 * Render v2 pages for design screenshots.
 *
 *   php tools/v2-showcase.php            writes v2-ethereum.html, v2-bitcoin.html
 *
 * Uses the SHOWCASE payloads (tools/showcase-payloads.php): plausible shapes,
 * not real figures. Every page is stamped with a visible banner saying so.
 * Asset paths are repo-relative, so open the files from the repository root.
 */

declare(strict_types=1);

require_once __DIR__ . '/showcase-payloads.php';
$GLOBALS['thb_probe_scenario'] = 'showcase';
require_once __DIR__ . '/v2-bootstrap.php';

Probe::reset();
Clock::reset();
v2_warm(11);
v2_warm(10);

$banner = '<div role="note" style="position:relative;z-index:99;background:#f59e0b;color:#111;'
        . 'font:600 13px Vazirmatn,Tahoma,sans-serif;text-align:center;padding:6px 12px">'
        . 'پیش‌نمایش طراحی با داده آزمایشی — اعداد این صفحه واقعی نیستند. روی سایت، همین بخش‌ها با داده زنده پر می‌شوند.'
        . '</div>';

foreach ([10 => 'ethereum', 11 => 'bitcoin'] as $id => $slug) {
    $html = v2_render($id);
    $html = preg_replace('#<body>#', '<body>' . $banner, $html, 1);
    file_put_contents(__DIR__ . "/../v2-{$slug}.html", $html);
    echo "wrote v2-{$slug}.html (" . number_format(strlen($html)) . " bytes)\n";
}
