<?php
/**
 * Markup helpers for the v2 templates.
 *
 * Two things every v2 template does dozens of times and must do the same way
 * every time: isolate a number from the Persian text around it, and say why a
 * figure is missing instead of printing a zero or leaving a hole.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins\V2;

defined('ABSPATH') || exit;

final class View
{
    /**
     * A number, bidi-isolated. Without the isolation "+3.17%" next to Persian
     * text renders as "%3.17+" — the minus or plus sign jumps to the wrong end.
     */
    public static function n(?string $formatted, string $class = ''): string
    {
        return sprintf(
            '<bdi class="v2-num%s" dir="ltr">%s</bdi>',
            $class !== '' ? ' ' . esc_attr($class) : '',
            esc_html($formatted ?? '—')
        );
    }

    /** Up/down/flat class for a signed change. */
    public static function dir(?float $v): string
    {
        return $v === null || $v == 0.0 ? 'flat' : ($v > 0 ? 'up' : 'down');
    }

    /**
     * The visible "no figure" state. Always a sentence, never a dash on its
     * own: a reader should know whether to wait for it or not expect it.
     */
    public static function na(string $state, ?string $note = null): string
    {
        $label = match ($state) {
            'pending'        => 'در حال دریافت داده',
            'disabled'       => 'منبع فعال نیست',
            'not_applicable' => 'کاربرد ندارد',
            default          => 'داده در دسترس نیست',
        };
        return sprintf(
            '<span class="v2-na v2-na--%s"><span class="v2-na__label">%s</span>%s</span>',
            esc_attr($state === 'ok' || $state === 'stale' ? 'unavailable' : $state),
            esc_html($label),
            $note ? '<span class="v2-na__note">' . esc_html($note) . '</span>' : ''
        );
    }

    /** "Source · fetched at 07:15" — the provenance line under a card. */
    public static function source(?string $who, ?string $iso = null): string
    {
        if ($who === null || $who === '') {
            return '';
        }
        $time = $iso ? \TheHybit\Coins\Format::tehranTime($iso) : null;
        return sprintf(
            '<p class="v2-source">منبع: <bdi>%s</bdi>%s</p>',
            esc_html($who),
            $time ? ' · به‌روزرسانی ' . self::n($time) : ''
        );
    }

    /** A section heading block with an anchor id and optional sub-line. */
    public static function heading(string $id, string $title, ?string $sub = null): string
    {
        return sprintf(
            '<header class="v2-head"><h2 class="v2-head__title" id="%s-title">%s</h2>%s</header>',
            esc_attr($id),
            esc_html($title),
            $sub ? '<p class="v2-head__sub">' . esc_html($sub) . '</p>' : ''
        );
    }
}
