<?php
/**
 * Number, currency and date formatting — the PHP twin of assets/js/format.js.
 *
 * Every user-visible number goes through here, for the same reason as in JS:
 * the Latin-vs-Persian digit decision stays a ONE-LINE change rather than a
 * find-and-replace across templates.
 *
 * Calendars are split exactly as in the approved UI:
 *   Jalali    recent content — news dates, chart axes
 *   Gregorian historical dates — launch, all-time high, all-time low
 *
 * JS gets Jalali free from Intl. PHP needs the intl extension; there is a
 * documented arithmetic fallback below so the page never dies on a host
 * without it.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class Format
{
    /** 'latn' => 3,245.67   |   'arabext' => ۳٬۲۴۵٫۶۷ */
    public const DIGITS = 'latn';

    private const EMPTY = '—';

    public static function num(?float $n, int $decimals = 2): string
    {
        return $n === null ? self::EMPTY : self::digits(number_format($n, $decimals));
    }

    /**
     * Price with precision that adapts to magnitude. At or above $1 exactly two
     * decimals, so columns align; below $1 more precision but trailing zeros
     * dropped, so 0.42 is "$0.42" and not "$0.4200".
     */
    public static function usd(?float $n): string
    {
        if ($n === null) {
            return self::EMPTY;
        }
        $abs = abs($n);

        if ($abs >= 1) {
            return '$' . self::digits(number_format($n, 2));
        }

        $max = $abs >= 0.01 ? 4 : ($abs >= 0.0001 ? 6 : ($abs > 0 ? 8 : 2));
        $formatted = rtrim(rtrim(number_format($n, $max, '.', ','), '0'), '.');
        // Keep at least two decimals so "$0.4" never appears.
        if (!str_contains($formatted, '.') || strlen(explode('.', $formatted)[1]) < 2) {
            $formatted = number_format($n, 2, '.', ',');
        }
        return '$' . self::digits($formatted);
    }

    private const STEPS = [[1e12, 'T'], [1e9, 'B'], [1e6, 'M'], [1e3, 'K']];

    public static function compact(?float $n, int $decimals = 2): string
    {
        if ($n === null) {
            return self::EMPTY;
        }
        $abs = abs($n);
        foreach (self::STEPS as [$size, $suffix]) {
            if ($abs >= $size) {
                return self::digits(number_format($n / $size, $decimals)) . $suffix;
            }
        }
        return self::digits(number_format($n, 0));
    }

    public static function usdCompact(?float $n, int $decimals = 2): string
    {
        return $n === null ? self::EMPTY : '$' . self::compact($n, $decimals);
    }

    /** Percentage with an explicit sign: "+2.45%", "−33.65%". */
    public static function pct(?float $n, bool $sign = true, int $decimals = 2): string
    {
        if ($n === null) {
            return self::EMPTY;
        }
        $body = self::digits(number_format(abs($n), $decimals)) . '%';
        if (!$sign) {
            return $body;
        }
        $mark = $n > 0 ? '+' : ($n < 0 ? '−' : '');
        return $mark . $body;
    }

    /** Change since an all-time low can reach six figures, where decimals are noise. */
    public static function pctSmart(?float $n): string
    {
        return self::pct($n, true, ($n !== null && abs($n) >= 1000) ? 0 : 2);
    }

    public static function ratio(?float $n, int $decimals = 3): string
    {
        return $n === null ? self::EMPTY : self::digits(number_format($n, $decimals));
    }

    /** 'up' | 'down' | 'flat' — drives both the colour token and the arrow. */
    public static function direction(?float $n): string
    {
        if ($n === null || $n == 0.0) {
            return 'flat';
        }
        return $n > 0 ? 'up' : 'down';
    }

    /** Colour must never carry the meaning alone. */
    public static function arrow(?float $n): string
    {
        return match (self::direction($n)) {
            'up' => '▲',
            'down' => '▼',
            default => '•',
        };
    }

    /** Toman NUMERAL only — the unit belongs in markup so bidi stays correct. */
    public static function toman(?float $usd, ?float $rate): string
    {
        if ($usd === null || $rate === null) {
            return self::EMPTY;
        }
        return self::digits(number_format(round($usd * $rate), 0));
    }

    /* --------------------------------------------------------------------
     * Dates
     * ----------------------------------------------------------------- */

    /** Jalali "19 مرداد" — recent content. */
    public static function jalaliShort(?string $iso): string
    {
        return self::jalali($iso, false);
    }

    /** Jalali with the year, "10 مهر 1405" — the v2 daily pulse's dateline. */
    public static function jalaliLong(?string $iso): string
    {
        return self::jalali($iso, true);
    }

    /** Wall-clock time in Tehran, "07:15" — when a figure was fetched. */
    public static function tehranTime(?string $iso): string
    {
        $ts = self::stamp($iso);
        if ($ts === null) {
            return self::EMPTY;
        }
        $d = (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('Asia/Tehran'));
        return self::digits($d->format('H:i'));
    }

    /** Gregorian in Persian script, "30 ژوئیه 2015" — historical records. */
    public static function gregorianFa(?string $iso): string
    {
        $ts = self::stamp($iso);
        if ($ts === null) {
            return self::EMPTY;
        }
        if (class_exists('IntlDateFormatter')) {
            $f = new \IntlDateFormatter(
                self::locale('gregorian'),
                \IntlDateFormatter::LONG,
                \IntlDateFormatter::NONE,
                'UTC',
                \IntlDateFormatter::GREGORIAN,
                'd MMMM y'
            );
            return (string) $f->format($ts);
        }
        return self::digits(gmdate('j F Y', $ts));
    }

    /**
     * ICU locale for a calendar, including the NUMBERING SYSTEM.
     *
     * Without `numbers=latn`, ICU renders "fa_IR" dates with Persian digits
     * (۳۰ ژوئیهٔ ۲۰۱۵) no matter what the rest of the page does — the exact
     * counterpart of needing `-u-nu-latn` in the JS Intl locale. The digit
     * system has to be declared to the formatter, not applied after it.
     */
    private static function locale(string $calendar): string
    {
        $numbers = self::DIGITS === 'latn' ? 'latn' : 'arabext';
        return sprintf('fa_IR@calendar=%s;numbers=%s', $calendar, $numbers);
    }

    private static function jalali(?string $iso, bool $withYear): string
    {
        $ts = self::stamp($iso);
        if ($ts === null) {
            return self::EMPTY;
        }

        if (class_exists('IntlDateFormatter')) {
            $f = new \IntlDateFormatter(
                self::locale('persian'),
                \IntlDateFormatter::LONG,
                \IntlDateFormatter::NONE,
                'Asia/Tehran',
                \IntlDateFormatter::TRADITIONAL,
                $withYear ? 'd MMMM y' : 'd MMMM'
            );
            return (string) $f->format($ts);
        }

        // Fallback: arithmetic conversion, so a host without ext-intl still
        // renders a correct Persian date rather than an English one.
        [$jy, $jm, $jd] = self::toJalali((int) gmdate('Y', $ts), (int) gmdate('n', $ts), (int) gmdate('j', $ts));
        $months = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
        $out = $jd . ' ' . $months[$jm - 1] . ($withYear ? ' ' . $jy : '');
        return self::digits($out);
    }

    /** Gregorian -> Jalali. Standard algorithm; only used without ext-intl. */
    private static function toJalali(int $gy, int $gm, int $gd): array
    {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + (int) (($gy2 + 3) / 4) - (int) (($gy2 + 99) / 100)
              + (int) (($gy2 + 399) / 400) + $gd + $g_d_m[$gm - 1];

        $jy = -1595 + (33 * (int) ($days / 12053));
        $days %= 12053;
        $jy += 4 * (int) ($days / 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += (int) (($days - 1) / 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jm = 1 + (int) ($days / 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + (int) (($days - 186) / 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return [$jy, $jm, $jd];
    }

    private static function stamp(?string $iso): ?int
    {
        if (!$iso) {
            return null;
        }
        $ts = strtotime($iso);
        return $ts === false ? null : $ts;
    }

    /** The single switch point for the whole page's digit system. */
    private static function digits(string $s): string
    {
        if (self::DIGITS === 'latn') {
            return $s;
        }
        return strtr($s, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    }

    /** Truncate a hex address. Caller MUST wrap the result in <bdi>. */
    public static function shortAddress(?string $addr, int $lead = 6, int $tail = 6): string
    {
        if (!$addr || mb_strlen($addr) <= $lead + $tail + 1) {
            return $addr ?: self::EMPTY;
        }
        return mb_substr($addr, 0, $lead) . '…' . mb_substr($addr, -$tail);
    }
}
