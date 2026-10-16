<?php
/**
 * Geometry for the v2 charts — pure functions, no markup opinions.
 *
 * WHY THE CHARTS ARE DRAWN IN PHP
 *
 * Every chart on the v2 page is an inline SVG produced on the server. A reader
 * (and a crawler) gets the finished picture in the first response; the script
 * only redraws on interaction — a different range, a toggled layer. The
 * alternative, a charting library, is 60–200 KB of JavaScript to draw a dozen
 * lines and one radar, and nothing appears until it has loaded.
 *
 * Each method returns path data or coordinates, never a whole element, so the
 * templates keep control of classes, labels and accessibility attributes.
 *
 * A series that cannot be drawn honestly — fewer than two finite points —
 * returns an empty string, and the template shows its "unavailable" state
 * instead of a flat line that would read as "no change".
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\V2;

defined('ABSPATH') || exit;

final class Svg
{
    /** Keep only finite numbers, preserving order. @return float[] */
    public static function clean(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            if (is_numeric($v) && is_finite((float) $v)) {
                $out[] = (float) $v;
            }
        }
        return $out;
    }

    /**
     * Evenly thin a series to at most $max points, always keeping the last
     * one: the newest value is the one the reader is looking for.
     *
     * @return array<int, mixed>
     */
    public static function thin(array $values, int $max): array
    {
        $values = array_values($values);
        $n = count($values);
        if ($n <= $max || $max < 2) {
            return $values;
        }
        $out = [];
        $step = ($n - 1) / ($max - 1);
        for ($i = 0; $i < $max; $i++) {
            $out[] = $values[(int) round($i * $step)];
        }
        return $out;
    }

    /**
     * Polyline points for a series scaled into a w×h box.
     *
     * @return array<int, array{0:float,1:float}>
     */
    public static function points(array $values, float $w, float $h, float $pad = 2.0, ?float $min = null, ?float $max = null): array
    {
        $v = self::clean($values);
        $n = count($v);
        if ($n < 2) {
            return [];
        }
        $lo = $min ?? min($v);
        $hi = $max ?? max($v);
        $range = $hi - $lo;
        $pts = [];
        foreach ($v as $i => $y) {
            $px = $pad + ($w - 2 * $pad) * ($i / ($n - 1));
            // A flat series sits in the middle rather than on the floor, so it
            // reads as "steady", not as "zero".
            $py = $range > 0
                ? $pad + ($h - 2 * $pad) * (1 - ($y - $lo) / $range)
                : $h / 2;
            $pts[] = [round($px, 1), round($py, 1)];
        }
        return $pts;
    }

    /** "M x y L x y …" for a series. Empty string if it cannot be drawn. */
    public static function line(array $values, float $w, float $h, float $pad = 2.0): string
    {
        return self::pathOf(self::points($values, $w, $h, $pad));
    }

    /** The same line closed down to the baseline, for a filled area. */
    public static function area(array $values, float $w, float $h, float $pad = 2.0): string
    {
        $pts = self::points($values, $w, $h, $pad);
        if ($pts === []) {
            return '';
        }
        $first = $pts[0];
        $last = $pts[count($pts) - 1];
        return self::pathOf($pts) . sprintf(' L%s %s L%s %s Z', $last[0], $h, $first[0], $h);
    }

    /** @param array<int, array{0:float,1:float}> $pts */
    public static function pathOf(array $pts): string
    {
        if (count($pts) < 2) {
            return '';
        }
        $d = '';
        foreach ($pts as $i => [$x, $y]) {
            $d .= ($i === 0 ? 'M' : ' L') . $x . ' ' . $y;
        }
        return $d;
    }

    /* ------------------------------------------------------------------
     * Radar
     * ---------------------------------------------------------------- */

    /**
     * Vertex for axis $i of $n at radius $r around (cx, cy). Axis 0 points up
     * and the rest follow clockwise, which in an RTL layout reads naturally.
     *
     * @return array{0:float,1:float}
     */
    public static function polar(float $cx, float $cy, float $r, int $i, int $n): array
    {
        $a = -M_PI / 2 + 2 * M_PI * $i / max(1, $n);
        return [round($cx + $r * cos($a), 1), round($cy + $r * sin($a), 1)];
    }

    /** A regular polygon ring of the radar grid, as an SVG `points` value. */
    public static function ring(float $cx, float $cy, float $r, int $n): string
    {
        $pts = [];
        for ($i = 0; $i < $n; $i++) {
            [$x, $y] = self::polar($cx, $cy, $r, $i, $n);
            $pts[] = "$x,$y";
        }
        return implode(' ', $pts);
    }

    /**
     * The data polygon. A missing component (null) is drawn at the centre —
     * it contributes nothing, exactly as it contributes nothing to the score —
     * and the template marks that axis as "no data".
     *
     * @param array<int, ?float> $values each 0..100
     */
    public static function radar(float $cx, float $cy, float $r, array $values): string
    {
        $n = count($values);
        $pts = [];
        foreach (array_values($values) as $i => $v) {
            $scaled = $v === null ? 0.0 : max(0.0, min(100.0, (float) $v)) / 100 * $r;
            [$x, $y] = self::polar($cx, $cy, $scaled, $i, $n);
            $pts[] = "$x,$y";
        }
        return implode(' ', $pts);
    }

    /* ------------------------------------------------------------------
     * Arcs: the sentiment gauge and the score ring
     * ---------------------------------------------------------------- */

    /**
     * An arc from angle $a0 to $a1 (degrees, 0 = right, clockwise positive in
     * SVG's y-down space).
     */
    public static function arc(float $cx, float $cy, float $r, float $a0, float $a1): string
    {
        $p0 = [round($cx + $r * cos(deg2rad($a0)), 1), round($cy + $r * sin(deg2rad($a0)), 1)];
        $p1 = [round($cx + $r * cos(deg2rad($a1)), 1), round($cy + $r * sin(deg2rad($a1)), 1)];
        $large = abs($a1 - $a0) > 180 ? 1 : 0;
        $sweep = $a1 > $a0 ? 1 : 0;
        return sprintf('M%s %s A%s %s 0 %d %d %s %s', $p0[0], $p0[1], $r, $r, $large, $sweep, $p1[0], $p1[1]);
    }

    /**
     * Needle tip for a 0..100 value on a 240° gauge that opens downward —
     * the shape of the reference design's market-mood dial.
     *
     * @return array{0:float,1:float}
     */
    public static function needle(float $cx, float $cy, float $r, float $value): array
    {
        $a = deg2rad(150 + 240 * max(0.0, min(100.0, $value)) / 100);
        return [round($cx + $r * cos($a), 1), round($cy + $r * sin($a), 1)];
    }

    /** stroke-dasharray for a progress ring of radius $r filled to $pct. */
    public static function dash(float $r, float $pct): string
    {
        $c = 2 * M_PI * $r;
        $on = $c * max(0.0, min(100.0, $pct)) / 100;
        return round($on, 1) . ' ' . round($c, 1);
    }

    /* ------------------------------------------------------------------
     * Sankey ribbons
     * ---------------------------------------------------------------- */

    /**
     * Ribbons from stacked nodes on one side into a single central node.
     *
     * Each node's band height is proportional to its share; the ribbon is a
     * cubic curve whose thickness is preserved end to end, which is what makes
     * a Sankey readable as "this much went there".
     *
     * @param float[] $shares        fractions, summing to at most 1
     * @param float   $xFrom         the side the ribbons leave from
     * @param float   $xTo           the central node's edge
     * @return array<int, array{d:string, y:float, h:float}>
     */
    public static function ribbons(array $shares, float $xFrom, float $xTo, float $top, float $height, float $gap, float $centreTop, float $centreHeight, float $minBand = 34.0): array
    {
        $n = count($shares);
        if ($n === 0) {
            return [];
        }
        /* Every band gets a floor so its two-line label fits; the rest of the
           height is shared in proportion. The CENTRE end stays strictly
           proportional, so the flow's true sizes are still what joins the
           middle node. */
        $usable = $height - $gap * ($n - 1);
        $minBand = min($minBand, $usable / $n);
        $spare = $usable - $minBand * $n;
        $y = $top;
        $cy = $centreTop;
        $mid = ($xFrom + $xTo) / 2;
        $out = [];
        foreach (array_values($shares) as $share) {
            $h = $minBand + $spare * $share;
            $ch = max(1.0, $centreHeight * $share);
            $d = sprintf(
                'M%s %s C%s %s %s %s %s %s L%s %s C%s %s %s %s %s %s Z',
                $xFrom, round($y, 1),
                $mid, round($y, 1), $mid, round($cy, 1), $xTo, round($cy, 1),
                $xTo, round($cy + $ch, 1),
                $mid, round($cy + $ch, 1), $mid, round($y + $h, 1), $xFrom, round($y + $h, 1)
            );
            // Both ends, so a template can put an arrowhead where the flow arrives.
            $out[] = ['d' => $d, 'y' => round($y, 1), 'h' => round($h, 1), 'cy' => round($cy, 1), 'ch' => round($ch, 1)];
            $y += $h + $gap;
            $cy += $ch;
        }
        return $out;
    }

    /**
     * Width of a price-story label box, from its own two lines (2.8.0).
     *
     * An estimate of the rendered text in viewBox units: Persian letters at
     * the 14-unit bold title average about 8 units, Latin capitals and
     * digits about 9.3; the 11.5-unit date line is narrower. Generous on purpose — a box
     * a little wide costs a little room, one too narrow lets text spill into
     * the next label.
     */
    public static function labelWidth(string $title, string $meta): float
    {
        $units = static function (string $s, float $fa, float $latin): float {
            $w = 0.0;
            foreach (mb_str_split($s) as $ch) {
                $w += preg_match('/[A-Z0-9]/', $ch) ? $latin : (preg_match('/\s/u', $ch) ? $fa * 0.5 : $fa);
            }
            return $w;
        };
        return max(96.0, round(max($units($title, 8.0, 9.3), $units($meta, 6.6, 7.2)) + 22, 1));
    }

    /**
     * Place labels in rows so that none overlap (2.8.0, price story).
     *
     * Input: each label's anchor x and width; output: its row, its left edge
     * (centred on the anchor, kept inside [2, W-2]) and its width. Row 0 is
     * the top. Its arrow runs from its box straight down through every row
     * below it, so a row is chosen in this order of preference:
     *   1. free in that row, its arrow crosses no box in a lower row, and its
     *      box covers no arrow of a label in a higher row;
     *   2. a new bottom row, when that is clean and keeps six rows or fewer;
     * each tried with the box centred on its arrow, then slid sideways (its
     * arrow always stays inside it) — so a neighbour's arrow can be cleared;
     *   3. free in that row;
     *   4. a new row.
     * Labels are taken in x order, so the result does not depend on the
     * order the editor wrote them in.
     *
     * @param array<int, array{x:float, w:float}> $labels
     * @return array<int, array{x:float, w:float, lx:float, row:int}>
     */
    public static function packLabels(array $labels, float $W, float $gap = 8.0): array
    {
        /* Packed from the left and from the right; the layout with fewer
           arrows behind a label wins, then the one with fewer rows. Near the
           right edge recent events are boxed in against the chart's end, and
           packing from that side first lets them take the clear spots. */
        $best = null;
        foreach ([1, -1] as $dir) {
            $try = self::packOnce($labels, $W, $gap, $dir);
            $crossings = 0;
            foreach ($try as $a) {
                foreach ($try as $b) {
                    if ($a['row'] < $b['row'] && $a['x'] > $b['lx'] && $a['x'] < $b['lx'] + $b['w']) {
                        $crossings++;
                    }
                }
            }
            $rows = $try === [] ? 0 : max(array_column($try, 'row')) + 1;
            if ($best === null || [$crossings, $rows] < [$best[0], $best[1]]) {
                $best = [$crossings, $rows, $try];
            }
        }
        return $best[2] ?? [];
    }

    /** One packing pass, labels taken left to right ($dir 1) or right to left (-1). */
    private static function packOnce(array $labels, float $W, float $gap, int $dir): array
    {
        $order = array_keys($labels);
        usort($order, static fn($a, $b) => $dir * ($labels[$a]['x'] <=> $labels[$b]['x']));
        $out = [];
        $rows = [];   // row => list of [lx, rx, x]
        $free = static function (array $rows, int $r, float $lx, float $rx) use ($gap): bool {
            foreach ($rows[$r] ?? [] as [$a, $b]) {
                if ($lx < $b + $gap && $rx > $a - $gap) {
                    return false;
                }
            }
            return true;
        };
        $clean = static function (array $rows, int $r, float $lx, float $rx, float $x): bool {
            foreach ($rows as $rr => $items) {
                foreach ($items as [$a, $b, $ax]) {
                    if ($rr > $r && $x >= $a - 3 && $x <= $b + 3) {
                        return false;   // this arrow would cross a lower label
                    }
                    if ($rr < $r && $ax >= $lx - 3 && $ax <= $rx + 3) {
                        return false;   // this box would sit on a higher label's arrow
                    }
                }
            }
            return true;
        };
        foreach ($order as $k) {
            $x = (float) $labels[$k]['x'];
            $w = (float) $labels[$k]['w'];
            /* Where the box may sit: centred on its arrow first, then slid
               either way in 6-unit steps, always keeping its own arrow at
               least 6 units inside it. */
            $clamp = static fn(float $l): float => max(2.0, min($W - $w - 2, $l));
            $spots = [$clamp($x - $w / 2)];
            for ($d = 6.0; $d <= $w / 2 - 8; $d += 6.0) {
                $spots[] = $clamp($x - $w / 2 + $d);
                $spots[] = $clamp($x - $w / 2 - $d);
            }
            $spots = array_values(array_unique(array_filter($spots, static fn($l) => $x >= $l + 6 && $x <= $l + $w - 6) ?: [$clamp($x - $w / 2)]));
            $n = count($rows);
            $pick = null;
            for ($r = 0; $r < $n && $pick === null; $r++) {
                foreach ($spots as $l) {
                    if ($free($rows, $r, $l, $l + $w) && $clean($rows, $r, $l, $l + $w, $x)) {
                        $pick = [$r, $l];
                        break;
                    }
                }
            }
            /* No clean row: a new bottom row (nothing below it) — opened
               while that keeps the chart within six rows. */
            if ($pick === null && $n < 7) {
                foreach ($spots as $l) {
                    if ($clean($rows, $n, $l, $l + $w, $x)) {
                        $pick = [$n, $l];
                        break;
                    }
                }
            }
            for ($r = 0; $r < $n && $pick === null; $r++) {
                foreach ($spots as $l) {
                    if ($free($rows, $r, $l, $l + $w)) {
                        $pick = [$r, $l];
                        break;
                    }
                }
            }
            $pick ??= [$n, $spots[0]];
            [$row, $lx] = $pick;
            $rows[$row][] = [$lx, $lx + $w, $x];
            $out[$k] = ['x' => $x, 'w' => $w, 'lx' => round($lx, 1), 'row' => $row];
        }
        ksort($out);
        return $out;
    }

    /**
     * Push circles apart until none overlap (plus `gap`), keeping each inside
     * [x0,x1]×[y0,y1]. Each moves the least it must: overlapping pairs are
     * separated along the line between their centres, half each. Used for
     * the peers bubble chart, where log scales put big chains on top of each
     * other.
     *
     * @param array<int|string, array{x:float,y:float,r:float}> $pts
     */
    public static function separate(array $pts, float $x0, float $x1, float $y0, float $y1, float $gap = 4.0): array
    {
        $keys = array_keys($pts);
        for ($iter = 0; $iter < 300; $iter++) {
            $moved = false;
            foreach ($keys as $i => $a) {
                foreach (array_slice($keys, $i + 1) as $b) {
                    $dx = $pts[$b]['x'] - $pts[$a]['x'];
                    $dy = $pts[$b]['y'] - $pts[$a]['y'];
                    $d = sqrt($dx * $dx + $dy * $dy);
                    $min = $pts[$a]['r'] + $pts[$b]['r'] + $gap;
                    if ($d >= $min) {
                        continue;
                    }
                    if ($d < 0.01) {   // identical centres: split sideways
                        $dx = 1.0; $dy = 0.3; $d = sqrt(1.09);
                    }
                    $push = ($min - $d) / 2 + 0.01;
                    $ux = $dx / $d;
                    $uy = $dy / $d;
                    $pts[$a]['x'] -= $ux * $push; $pts[$a]['y'] -= $uy * $push;
                    $pts[$b]['x'] += $ux * $push; $pts[$b]['y'] += $uy * $push;
                    $moved = true;
                }
            }
            foreach ($keys as $k) {
                $r = $pts[$k]['r'];
                $pts[$k]['x'] = max($x0 + $r, min($x1 - $r, $pts[$k]['x']));
                $pts[$k]['y'] = max($y0 + $r, min($y1 - $r, $pts[$k]['y']));
            }
            if (!$moved) {
                break;
            }
        }
        return $pts;
    }

    /* ------------------------------------------------------------------
     * Scales
     * ---------------------------------------------------------------- */

    /** Map $v from a log10 domain onto [$a, $b]. Non-positive values clamp to $a. */
    public static function logScale(?float $v, float $lo, float $hi, float $a, float $b): float
    {
        if ($v === null || $v <= 0 || $lo <= 0 || $hi <= $lo) {
            return $a;
        }
        $t = (log10($v) - log10($lo)) / (log10($hi) - log10($lo));
        return round($a + ($b - $a) * max(0.0, min(1.0, $t)), 1);
    }
}
