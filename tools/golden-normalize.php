<?php
/**
 * Strip the two values in a rendered page that come from the real wall clock.
 *
 * Everything else in the page is driven by the test harness's controllable
 * clock and fixtures, so two renders are byte-identical — except
 * `meta.generatedAt` and the FX payload's `fetchedAt`, which the shipping code
 * stamps with gmdate() at render time. Those two are replaced with a fixed
 * token; nothing else is touched, so any other difference is a real change.
 */
function thb_golden_normalize(string $html): string
{
    $html = (string) preg_replace('/"generatedAt":"[^"]*"/', '"generatedAt":"<now>"', $html);
    $html = (string) preg_replace('/"fetchedAt":"[^"]*","freshness"/', '"fetchedAt":"<now>","freshness"', $html);
    return $html;
}
