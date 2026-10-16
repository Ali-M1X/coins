<?php
/**
 * Asset categories (2.8.0): what kind of asset each coin is.
 *
 * `labels` are the choices of the «دسته» field in wp-admin (ACF select
 * thb_category) and what the page, the /coins/ list and the structured data
 * show. `defaults` is the main category of every coin page this plugin
 * ships, by slug: one each, the one the asset is best known as. The seeder
 * writes it into an EMPTY field on the first wp-admin visit after the
 * upgrade; an editor's choice is never overwritten. A coin with no value
 * shows no category rather than a guessed one.
 *
 * @package TheHybit\Coins
 */

defined('ABSPATH') || exit;

return [
    'labels' => [
        'layer1'     => ['fa' => 'لایه ۱',              'en' => 'Layer 1'],
        'layer2'     => ['fa' => 'لایه ۲',              'en' => 'Layer 2'],
        'stablecoin' => ['fa' => 'استیبل‌کوین',          'en' => 'Stablecoin'],
        'rwa'        => ['fa' => 'دارایی واقعی (RWA)',   'en' => 'Real-world asset (RWA)'],
        'meme'       => ['fa' => 'میم‌کوین',             'en' => 'Meme coin'],
        'exchange'   => ['fa' => 'توکن صرافی',           'en' => 'Exchange token'],
        'defi'       => ['fa' => 'دیفای',                'en' => 'DeFi'],
        'oracle'     => ['fa' => 'اوراکل',               'en' => 'Oracle'],
        'privacy'    => ['fa' => 'ارز حریم خصوصی',       'en' => 'Privacy coin'],
        'gold'       => ['fa' => 'توکن پشتوانه طلا',     'en' => 'Gold-backed token'],
        'payments'   => ['fa' => 'شبکه پرداخت',          'en' => 'Payment network'],
        'ai'         => ['fa' => 'هوش مصنوعی',           'en' => 'AI'],
        'infra'      => ['fa' => 'زیرساخت',              'en' => 'Infrastructure'],
    ],

    /* The main category of each shipped coin. Where an asset fits more than
       one (BNB: chain and exchange token; Stacks: Bitcoin layer and smart
       contracts), the one it is primarily used as is chosen; the choices to
       review are listed in docs/rev-2.8/CATEGORIES.md. */
    'defaults' => [
        'ethereum' => 'layer1', 'bitcoin' => 'layer1', 'tether' => 'stablecoin', 'xrp' => 'payments',
        'bnb' => 'layer1', 'solana' => 'layer1', 'usdc' => 'stablecoin', 'dogecoin' => 'meme',
        'tron' => 'layer1', 'cardano' => 'layer1', 'hyperliquid' => 'defi', 'chainlink' => 'oracle',
        'stellar' => 'payments', 'sui' => 'layer1', 'bitcoin-cash' => 'payments', 'avalanche' => 'layer1',
        'hedera' => 'layer1', 'litecoin' => 'payments', 'toncoin' => 'layer1', 'shiba-inu' => 'meme',
        'unus-sed-leo' => 'exchange', 'zcash' => 'privacy', 'monero' => 'privacy', 'whitebit-coin' => 'exchange',
        'world-liberty-financial' => 'defi', 'mantle' => 'layer2', 'uniswap' => 'defi', 'cronos' => 'exchange',
        'polkadot' => 'layer1', 'bittensor' => 'ai', 'bitget-token' => 'exchange', 'aave' => 'defi',
        'ethena' => 'defi', 'near' => 'layer1', 'okb' => 'exchange', 'pepe' => 'meme',
        'internet-computer' => 'layer1', 'ethereum-classic' => 'layer1', 'aptos' => 'layer1', 'ondo' => 'rwa',
        'aster' => 'defi', 'polygon' => 'layer2', 'kaspa' => 'layer1', 'algorand' => 'layer1',
        'cosmos' => 'layer1', 'arbitrum' => 'layer2', 'render' => 'ai', 'filecoin' => 'infra',
        'vechain' => 'layer1', 'official-trump' => 'meme',
        'usds' => 'stablecoin', 'ethena-usde' => 'stablecoin', 'canton' => 'layer1', 'dai' => 'stablecoin',
        'usd1' => 'stablecoin', 'tether-gold' => 'gold', 'global-dollar' => 'stablecoin', 'quant' => 'infra',
        'paypal-usd' => 'stablecoin', 'ripple-usd' => 'stablecoin', 'ondo-usdy' => 'rwa', 'pump-fun' => 'meme',
        'blackrock-buidl' => 'rwa', 'worldcoin' => 'ai', 'pax-gold' => 'gold', 'usdd' => 'stablecoin',
        'sky' => 'defi', 'morpho' => 'defi', 'htx-dao' => 'exchange', 'just' => 'defi',
        'venice-token' => 'ai', 'gatetoken' => 'exchange', 'jupiter' => 'defi', 'kucoin-token' => 'exchange',
        'pi-network' => 'layer1', 'aerodrome' => 'defi', 'nexo' => 'exchange', 'stacks' => 'layer2',
        'injective' => 'layer1', 'layerzero' => 'infra', 'pancakeswap' => 'defi', 'gho' => 'stablecoin',
        'xdc-network' => 'layer1', 'pyth-network' => 'oracle',
    ],

    /* Choices the editor should look at: an asset that fits two categories. */
    'review' => [
        'bnb' => 'BNB Chain\'s native coin and Binance\'s exchange token — shown as لایه ۱.',
        'hyperliquid' => 'Its own layer-1 chain, used almost entirely for its perpetuals exchange — shown as دیفای.',
        'cronos' => 'Crypto.com\'s token and the Cronos chain — shown as توکن صرافی.',
        'polygon' => 'An Ethereum scaling network (PoS chain plus AggLayer), not a rollup in the strict sense — shown as لایه ۲.',
        'stacks' => 'A Bitcoin layer for smart contracts — shown as لایه ۲.',
        'render' => 'GPU rendering and AI compute network — shown as هوش مصنوعی (could be زیرساخت).',
        'worldcoin' => 'Digital identity tied to proof-of-personhood for an AI era — shown as هوش مصنوعی (could be زیرساخت).',
        'pump-fun' => 'Token of the memecoin launchpad — shown as میم‌کوین (could be دیفای).',
        'nexo' => 'Token of a centralised lending platform — shown as توکن صرافی (closest of the list; no CeFi category).',
        'canton' => 'An institutional network for tokenised assets — shown as لایه ۱ (could be RWA).',
        'ondo-usdy' => 'A tokenised Treasury note that pays yield — shown as RWA, not استیبل‌کوین.',
        'xrp' => 'The XRP Ledger is a layer 1 built for payments — shown as شبکه پرداخت.',
        'bitcoin' => 'Shown as لایه ۱; often described as ذخیره ارزش.',
    ],
];
