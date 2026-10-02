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
