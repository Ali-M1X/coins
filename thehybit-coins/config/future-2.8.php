<?php
/**
 * «آینده» — planned items for the story timeline (2.8.0).
 *
 * Only what is announced, scheduled or fixed by the protocol's own rules:
 * a halving at a set block height, an upgrade the developers have published
 * and are building, a date written into a law. A date that is not official is
 * said to be approximate or is left out («بدون تاریخ رسمی»). A coin with
 * nothing credible planned has no entry here and shows no planned item.
 *
 * Every line is appended to the coin's timeline with the «>» mark (rendered
 * with the «برنامه‌ریزی‌شده» badge) and listed for the editor's fact-check in
 * docs/rev-2.8/FACT-CHECK.md. Ethereum, Bitcoin, Solana and Litecoin already
 * carry theirs from earlier versions.
 *
 * @package TheHybit\Coins
 */

defined('ABSPATH') || exit;

/* The US GENIUS Act (signed 18 July 2025): in force at the earlier of 18
   months after signing or 120 days after the final regulations; three years
   after signing, US service providers may no longer offer a payment
   stablecoin from an issuer that is not permitted under it. */
$geniusEffective = '>حداکثر ژانویه 2027 | اجرای قانون GENIUS | قانون استیبل‌کوین آمریکا حداکثر ۱۸ ماه پس از امضا یا ۱۲۰ روز پس از صدور مقررات نهایی، هر کدام زودتر، اجرایی می‌شود.';

return [
    'bitcoin-cash' => [
        '>حدود 2028 | هاوینگ بعدی | در بلوک ۱٬۰۵۰٬۰۰۰ پاداش هر بلوک بیت‌کوین کش نصف می‌شود؛ تاریخ دقیق به سرعت تولید بلوک‌ها بستگی دارد.',
    ],
    'zcash' => [
        '>حدود 2028 | هاوینگ بعدی | پاداش هر بلوک زی‌کش دوباره نصف می‌شود؛ زمان دقیق آن به ارتفاع بلوک تعیین‌شده در پروتکل و سرعت شبکه بستگی دارد.',
    ],
    'monero' => [
        '>بدون تاریخ رسمی | ارتقای FCMP++ | اثبات عضویت روی کل زنجیره، که مجموعه ناشناس هر تراکنش را به همه خروجی‌ها گسترش می‌دهد؛ در حال آزمایش است و تاریخ فعال‌سازی اعلام نشده.',
    ],
    'cardano' => [
        '>بدون تاریخ رسمی | ارتقای لئوس (Leios) | نسخه مقیاس‌پذیر پروتکل اجماع اوروبوروس برای افزایش چشمگیر ظرفیت تراکنش؛ طراحی منتشر شده و تاریخ فعال‌سازی تأیید نشده است.',
    ],
    'polkadot' => [
        '>بدون تاریخ رسمی | جم (JAM) | بازطراحی هسته پولکادات بر اساس «گری پیپر» گوین وود؛ چند تیم در حال پیاده‌سازی آن‌اند و تاریخ استقرار هنوز تعیین نشده.',
    ],
    'bittensor' => [
        '>بدون تاریخ رسمی | دومین هاوینگ TAO | وقتی عرضه منتشرشده به ۱۵٫۷۵ میلیون TAO برسد، انتشار روزانه دوباره نصف می‌شود؛ زمان آن به سرعت انتشار بستگی دارد و تاریخ ثابتی ندارد.',
    ],
    'aave' => [
        '>بدون تاریخ رسمی | آوه نسخه ۴ (Aave V4) | معماری تازه با یک مرکز نقدینگی مشترک برای چند بازار؛ تیم آن را اعلام کرده و تاریخ عرضه نهایی تأیید نشده است.',
    ],
    'ondo' => [
        '>بدون تاریخ رسمی | اوندو چین (Ondo Chain) | بلاکچین لایه ۱ مخصوص دارایی‌های واقعی توکنی‌شده که اوندو در ۲۰۲۵ معرفی کرد؛ تاریخ راه‌اندازی شبکه اصلی تأیید نشده است.',
    ],
    'usdc' => [
        $geniusEffective,
        '>بدون تاریخ رسمی | شبکه آرک (Arc) | بلاکچین لایه ۱ سرکل که USDC در آن واحد کارمزد است؛ شبکه آزمایشی آن فعال شده و تاریخ شبکه اصلی تأیید نشده است.',
    ],
    'tether' => [
        $geniusEffective,
        '>ژوئیه 2028 | پایان مهلت قانون GENIUS | سه سال پس از امضای قانون، ارائه استیبل‌کوین ناشری که مجوز این قانون را ندارد در آمریکا ممنوع می‌شود.',
    ],
    'paypal-usd' => [$geniusEffective],
    'usd1' => [$geniusEffective],
    'ripple-usd' => [$geniusEffective],
    'global-dollar' => [$geniusEffective],
];
