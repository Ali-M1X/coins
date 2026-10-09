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
