<?php
/**
 * The two coins for the validation phase.
 *
 * NOT the runtime source of truth — ACF is. This file exists so the two coins
 * can be created reproducibly (see Seeder below) and so the intended
 * configuration is reviewable in version control rather than only in a database.
 *
 * Note how the four identifiers relate. For Ethereum and Bitcoin the slug and
 * the CoinGecko id happen to match, which is exactly why they are stored
 * separately: the first coin where they diverge must not require a code change.
 *
 * @package TheHybit\Coins
 */

defined('ABSPATH') || exit;

return [

    'ethereum' => [
        'post_title' => 'اتریوم',
        'fields' => [
            'thb_symbol'              => 'ETH',
            'thb_name_fa'             => 'اتریوم',
            'thb_name_en'             => 'Ethereum',
            'thb_coingecko_id'        => 'ethereum',      // == slug here, by coincidence
            'thb_news_category_slug'  => 'ethereum-news', // the existing category
            'thb_description'         => 'اتریوم یک پلتفرم بلاکچینی غیرمتمرکز است که با قابلیت قراردادهای هوشمند، امکان ساخت و اجرای برنامه‌های غیرمتمرکز (DApp) را فراهم می‌کند.',
            'thb_founder'             => 'ویتالیک بوترین',
            'thb_launch_date'         => '2015-07-30',
            'thb_consensus'           => 'اثبات سهام (PoS)',
            'thb_tags'                => 'بلاکچین, پلتفرم قرارداد هوشمند',
            'thb_website'             => 'https://ethereum.org',
            'thb_whitepaper'          => 'https://ethereum.org/en/whitepaper/',
            'thb_explorer'            => 'https://etherscan.io',
            'thb_social_twitter'      => 'https://twitter.com/ethereum',
            'thb_social_github'       => 'https://github.com/ethereum',
            'thb_social_telegram'     => 'https://t.me/ethereum',
            'thb_defillama_chain'     => 'Ethereum',
            'thb_github_repo'         => 'ethereum/go-ethereum',
            'thb_wikipedia'           => 'Ethereum',
            'thb_unlocks'             => 'ندارد — اتر تخصیص قفل‌شده یا برنامه آزادسازی ندارد؛ عرضه تازه فقط از پاداش اعتبارسنج‌ها می‌آید.',
            /* v2 editorial content. Dates and events are public record; nothing
               here is a market figure — those come only from the providers. */
            'thb_tagline'             => 'کامپیوتر جهانی؛ بستری برای برنامه‌ها و پول برنامه‌پذیر',
            'thb_timeline'            => implode("\n", [
                '2015-07-30 | پیدایش | شبکه اصلی اتریوم با بلوک پیدایش «فرانتیر» راه‌اندازی شد.',
                '2016-07-20 | هاردفورک DAO | پس از هک قرارداد The DAO، زنجیره دوشاخه شد و اتریوم کلاسیک جدا ماند.',
                '2017 | رونق ICO | عرضه اولیه توکن‌ها روی استاندارد ERC-20 اتریوم را به مرکز جذب سرمایه پروژه‌ها تبدیل کرد.',
                '2020 | تابستان دیفای | پروتکل‌های وام‌دهی و صرافی‌های غیرمتمرکز رشد انفجاری را تجربه کردند.',
                '2021-08-05 | ارتقای لندن | EIP-1559 بخشی از کارمزد هر تراکنش را برای همیشه می‌سوزاند.',
                '2022-09-15 | ادغام (The Merge) | اتریوم از اثبات کار به اثبات سهام مهاجرت کرد و ماینینگ پایان یافت.',
                '2023-04-12 | شانگهای | برداشت اتر سپرده‌گذاری‌شده برای اعتبارسنج‌ها ممکن شد.',
                '2024-03-13 | دنکون | پروتو-دنک‌شاردینگ (EIP-4844) هزینه انتشار داده لایه‌های دوم را پایین آورد.',
                '2025-05-07 | پکترا | ارتقای حساب‌های کاربری و افزایش سقف سپرده هر اعتبارسنج.',
            ]),
            'thb_learn'               => implode("\n", [
                'بلاکچین | دفتر کل مشترکی که هزاران رایانه آن را نگه می‌دارند و هیچ‌کس به‌تنهایی کنترلش نمی‌کند.',
                'قرارداد هوشمند | برنامه‌ای روی بلاکچین که وقتی شرطش برقرار شود، خودکار و بدون واسطه اجرا می‌شود.',
                'کارمزد گس | هزینه‌ای که برای اجرای هر تراکنش به شبکه می‌پردازید؛ با شلوغی شبکه بالا و پایین می‌رود.',
                'استیکینگ | قفل‌کردن اتر برای اعتبارسنجی بلوک‌ها و دریافت پاداش؛ امنیت شبکه به آن وابسته است.',
                'لایه ۲ | شبکه‌هایی روی اتریوم که تراکنش‌ها را ارزان‌تر پردازش و نتیجه را روی لایه اصلی ثبت می‌کنند.',
            ]),
            'thb_audience'            => implode("\n", [
                'سرمایه‌گذار بلندمدت | زیاد | دارایی پایه اکوسیستم قراردادهای هوشمند با سابقه طولانی.',
                'کاربر دیفای | زیاد | بیشترین تنوع پروتکل و نقدشوندگی روی همین زنجیره است.',
                'معامله‌گر کوتاه‌مدت | متوسط | نقدشوندگی بالا دارد، اما نوسانش از بیت‌کوین بیشتر است.',
            ]),
            'thb_faq'                 => implode("\n", [
                'اتریوم چیست؟ | اتریوم یک بلاکچین عمومی برای اجرای قراردادهای هوشمند است و اتر (ETH) رمزارز بومی آن است.',
                'تفاوت اتریوم و بیت‌کوین چیست؟ | بیت‌کوین بیشتر برای ذخیره و انتقال ارزش طراحی شده؛ اتریوم بستری برای اجرای برنامه‌های غیرمتمرکز است.',
                'آیا عرضه اتر سقف دارد؟ | خیر. عرضه اتر سقف ثابت ندارد؛ بخشی از کارمزدها سوزانده می‌شود و پاداش اعتبارسنج‌ها اتر تازه منتشر می‌کند.',
            ]),
            /* WETH. Native ETH is not an ERC-20, so no liquidity pool can hold
               it — every real ETH market on a DEX is quoted against the wrapped
               token. Asking DexScreener by this address is unambiguous, where
               a search for the ticker "ETH" also returns unrelated tokens that
               happen to share the name. */
            'thb_dex_token_address'   => '0xC02aaA39b223FE8D0A0e5C4F27eAD9083C756Cc2',
            'thb_enable_defi'         => 1,
            'thb_enable_dex'          => 1,
            'thb_enable_onchain'      => 0,  // no API key yet
            'thb_enable_l2'           => 0,  // ecosystem-vs-chain scope undefined
        ],
    ],

    'bitcoin' => [
        'post_title' => 'بیت‌کوین',
        'fields' => [
            'thb_symbol'              => 'BTC',
            'thb_name_fa'             => 'بیت‌کوین',
            'thb_name_en'             => 'Bitcoin',
            'thb_coingecko_id'        => 'bitcoin',
            'thb_news_category_slug'  => 'bitcoin-news',  // must exist in WP before launch
            'thb_description'         => 'بیت‌کوین نخستین ارز دیجیتال غیرمتمرکز جهان است که در سال ۲۰۰۹ معرفی شد و بر پایه الگوریتم اثبات کار کار می‌کند.',
            'thb_founder'             => 'ساتوشی ناکاموتو',
            'thb_launch_date'         => '2009-01-03',
            'thb_consensus'           => 'اثبات کار (PoW)',
            'thb_tags'                => 'بلاکچین, ذخیره ارزش',
            'thb_website'             => 'https://bitcoin.org',
            'thb_whitepaper'          => 'https://bitcoin.org/bitcoin.pdf',
            'thb_explorer'            => 'https://blockstream.info',
            'thb_social_twitter'      => 'https://twitter.com/bitcoin',
            'thb_social_github'       => 'https://github.com/bitcoin/bitcoin',
            'thb_defillama_chain'     => 'Bitcoin',
            'thb_github_repo'         => 'bitcoin/bitcoin',
            'thb_wikipedia'           => 'Bitcoin',
            'thb_unlocks'             => 'ندارد — بیت‌کوین تخصیص قفل‌شده ندارد؛ عرضه تازه فقط از پاداش استخراج می‌آید.',
            'thb_audience'            => implode("\n", [
                'سرمایه‌گذار بلندمدت | زیاد | قدیمی‌ترین و بزرگ‌ترین رمزارز با سقف عرضه ثابت.',
                'پس‌انداز در برابر تورم | متوسط | سقف عرضه دارد، اما نوسان قیمتش در کوتاه‌مدت زیاد است.',
                'کاربر دیفای | کم | بیشتر فعالیت دیفای روی زنجیره‌های دیگر و با نسخه‌های رپ‌شده انجام می‌شود.',
            ]),
            'thb_faq'                 => implode("\n", [
                'بیت‌کوین چیست؟ | بیت‌کوین نخستین رمزارز غیرمتمرکز است که بدون بانک یا واسطه، انتقال ارزش همتا به همتا را ممکن می‌کند.',
                'عرضه بیت‌کوین چقدر است؟ | سقف عرضه بیت‌کوین ۲۱ میلیون واحد است و با هر هاوینگ سرعت انتشار سکه تازه نصف می‌شود.',
                'هاوینگ چیست؟ | تقریباً هر چهار سال، پاداش استخراج هر بلوک نصف می‌شود؛ آخرین هاوینگ در آوریل ۲۰۲۴ بود.',
            ]),
            'thb_tagline'             => 'نخستین پول دیجیتال غیرمتمرکز جهان',
            'thb_timeline'            => implode("\n", [
                '2008-10-31 | وایت‌پیپر | ساتوشی ناکاموتو مقاله «بیت‌کوین: یک سیستم پول نقد همتا به همتا» را منتشر کرد.',
                '2009-01-03 | بلوک پیدایش | نخستین بلوک بیت‌کوین استخراج شد.',
                '2012-11-28 | نخستین هاوینگ | پاداش استخراج هر بلوک از ۵۰ به ۲۵ بیت‌کوین رسید.',
                '2017-08-01 | سگویت | ارتقای SegWit فعال شد و بیت‌کوین کش جدا شد.',
                '2021-11-14 | تپروت | ارتقای Taproot حریم خصوصی و قابلیت اسکریپت را بهبود داد.',
                '2024-01-10 | ETFهای نقدی | نخستین صندوق‌های قابل معامله نقدی بیت‌کوین در آمریکا تأیید شدند.',
                '2024-04-20 | چهارمین هاوینگ | پاداش هر بلوک به ۳٫۱۲۵ بیت‌کوین کاهش یافت.',
            ]),
            'thb_learn'               => implode("\n", [
                'بلاکچین | دفتر کل مشترکی که هزاران رایانه آن را نگه می‌دارند و هیچ‌کس به‌تنهایی کنترلش نمی‌کند.',
                'استخراج | رقابت محاسباتی برای افزودن بلوک بعدی و دریافت پاداش؛ امنیت شبکه از همین می‌آید.',
                'هاوینگ | هر حدود چهار سال پاداش استخراج نصف می‌شود و سرعت انتشار بیت‌کوین تازه کم می‌شود.',
                'کیف پول | ابزاری برای نگهداری کلید خصوصی؛ هر کس کلید را داشته باشد، صاحب دارایی است.',
                'سقف عرضه | عرضه بیت‌کوین هرگز از ۲۱ میلیون واحد بیشتر نمی‌شود.',
            ]),
            /* Bitcoin has no meaningful DeFi TVL and little DEX presence, so
               those providers are off for THIS COIN rather than site-wide. The
               sections then never render, which is the same outcome the
               visibility thresholds would produce — but without spending calls
               to discover it. */
            'thb_enable_defi'         => 0,
            'thb_enable_dex'          => 0,
            'thb_enable_onchain'      => 0,
            'thb_enable_l2'           => 0,
        ],
    ],
];
