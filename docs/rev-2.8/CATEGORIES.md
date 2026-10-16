# 2.8.0 — asset categories, for review

Each coin page gets ONE main category, editable in wp-admin («دسته», tab معرفی). The seeder writes these into an empty field on the first wp-admin visit after the upgrade; a value an editor has set is never overwritten. Rows marked ⚑ fit more than one category — check the choice.

| Coin | Category | Note |
|---|---|---|
| اتریوم (ETH) `ethereum` | لایه ۱ — Layer 1 |  |
| بیت‌کوین (BTC) `bitcoin` | لایه ۱ — Layer 1 | ⚑ Shown as لایه ۱; often described as ذخیره ارزش. |
| تتر (USDT) `tether` | استیبل‌کوین — Stablecoin |  |
| ایکس‌آر‌پی (XRP) `xrp` | شبکه پرداخت — Payment network | ⚑ The XRP Ledger is a layer 1 built for payments — shown as شبکه پرداخت. |
| بی‌ان‌بی (BNB) `bnb` | لایه ۱ — Layer 1 | ⚑ BNB Chain's native coin and Binance's exchange token — shown as لایه ۱. |
| سولانا (SOL) `solana` | لایه ۱ — Layer 1 |  |
| یو‌اس‌دی کوین (USDC) `usdc` | استیبل‌کوین — Stablecoin |  |
| دوج‌کوین (DOGE) `dogecoin` | میم‌کوین — Meme coin |  |
| ترون (TRX) `tron` | لایه ۱ — Layer 1 |  |
| کاردانو (ADA) `cardano` | لایه ۱ — Layer 1 |  |
| هایپرلیکوئید (HYPE) `hyperliquid` | دیفای — DeFi | ⚑ Its own layer-1 chain, used almost entirely for its perpetuals exchange — shown as دیفای. |
| چین‌لینک (LINK) `chainlink` | اوراکل — Oracle |  |
| استلار (XLM) `stellar` | شبکه پرداخت — Payment network |  |
| سویی (SUI) `sui` | لایه ۱ — Layer 1 |  |
| بیت‌کوین کش (BCH) `bitcoin-cash` | شبکه پرداخت — Payment network |  |
| آوالانچ (AVAX) `avalanche` | لایه ۱ — Layer 1 |  |
| هدرا (HBAR) `hedera` | لایه ۱ — Layer 1 |  |
| لایت‌کوین (LTC) `litecoin` | شبکه پرداخت — Payment network |  |
| تون‌کوین (TON) `toncoin` | لایه ۱ — Layer 1 |  |
| شیبا اینو (SHIB) `shiba-inu` | میم‌کوین — Meme coin |  |
| لئو (LEO) `unus-sed-leo` | توکن صرافی — Exchange token |  |
| زی‌کش (ZEC) `zcash` | ارز حریم خصوصی — Privacy coin |  |
| مونرو (XMR) `monero` | ارز حریم خصوصی — Privacy coin |  |
| وایت‌بیت کوین (WBT) `whitebit-coin` | توکن صرافی — Exchange token |  |
| ورلد لیبرتی فایننشال (WLFI) `world-liberty-financial` | دیفای — DeFi |  |
| منتل (MNT) `mantle` | لایه ۲ — Layer 2 |  |
| یونی‌سواپ (UNI) `uniswap` | دیفای — DeFi |  |
| کرونوس (CRO) `cronos` | توکن صرافی — Exchange token | ⚑ Crypto.com's token and the Cronos chain — shown as توکن صرافی. |
| پولکادات (DOT) `polkadot` | لایه ۱ — Layer 1 |  |
| بیت‌تنسور (TAO) `bittensor` | هوش مصنوعی — AI |  |
| بیت‌گت توکن (BGB) `bitget-token` | توکن صرافی — Exchange token |  |
| آوه (AAVE) `aave` | دیفای — DeFi |  |
| اتنا (ENA) `ethena` | دیفای — DeFi |  |
| نیر پروتکل (NEAR) `near` | لایه ۱ — Layer 1 |  |
| اوکی‌بی (OKB) `okb` | توکن صرافی — Exchange token |  |
| پپه (PEPE) `pepe` | میم‌کوین — Meme coin |  |
| اینترنت کامپیوتر (ICP) `internet-computer` | لایه ۱ — Layer 1 |  |
| اتریوم کلاسیک (ETC) `ethereum-classic` | لایه ۱ — Layer 1 |  |
| اپتوس (APT) `aptos` | لایه ۱ — Layer 1 |  |
| اوندو (ONDO) `ondo` | دارایی واقعی (RWA) — Real-world asset (RWA) |  |
| استر (ASTER) `aster` | دیفای — DeFi |  |
| پالیگان (POL) `polygon` | لایه ۲ — Layer 2 | ⚑ An Ethereum scaling network (PoS chain plus AggLayer), not a rollup in the strict sense — shown as لایه ۲. |
| کسپا (KAS) `kaspa` | لایه ۱ — Layer 1 |  |
| الگورند (ALGO) `algorand` | لایه ۱ — Layer 1 |  |
| کازماس (ATOM) `cosmos` | لایه ۱ — Layer 1 |  |
| آربیتروم (ARB) `arbitrum` | لایه ۲ — Layer 2 |  |
| رندر (RENDER) `render` | هوش مصنوعی — AI | ⚑ GPU rendering and AI compute network — shown as هوش مصنوعی (could be زیرساخت). |
| فایل‌کوین (FIL) `filecoin` | زیرساخت — Infrastructure |  |
| وی‌چین (VET) `vechain` | لایه ۱ — Layer 1 |  |
| آفیشال ترامپ (TRUMP) `official-trump` | میم‌کوین — Meme coin |  |
| یو‌اس‌دی‌اس (USDS) `usds` | استیبل‌کوین — Stablecoin |  |
| اتنا یو‌اس‌دی‌ای (USDe) `ethena-usde` | استیبل‌کوین — Stablecoin |  |
| کانتون (CC) `canton` | لایه ۱ — Layer 1 | ⚑ An institutional network for tokenised assets — shown as لایه ۱ (could be RWA). |
| دای (DAI) `dai` | استیبل‌کوین — Stablecoin |  |
| یو‌اس‌دی وان (USD1) `usd1` | استیبل‌کوین — Stablecoin |  |
| تتر گلد (XAUT) `tether-gold` | توکن پشتوانه طلا — Gold-backed token |  |
| گلوبال دلار (USDG) `global-dollar` | استیبل‌کوین — Stablecoin |  |
| کوانت (QNT) `quant` | زیرساخت — Infrastructure |  |
| پی‌پل یو‌اس‌دی (PYUSD) `paypal-usd` | استیبل‌کوین — Stablecoin |  |
| ریپل یو‌اس‌دی (RLUSD) `ripple-usd` | استیبل‌کوین — Stablecoin |  |
| اوندو یو‌اس‌دی‌وای (USDY) `ondo-usdy` | دارایی واقعی (RWA) — Real-world asset (RWA) | ⚑ A tokenised Treasury note that pays yield — shown as RWA, not استیبل‌کوین. |
| پامپ دات فان (PUMP) `pump-fun` | میم‌کوین — Meme coin | ⚑ Token of the memecoin launchpad — shown as میم‌کوین (could be دیفای). |
| صندوق بیلد بلک‌راک (BUIDL) `blackrock-buidl` | دارایی واقعی (RWA) — Real-world asset (RWA) |  |
| ورلدکوین (WLD) `worldcoin` | هوش مصنوعی — AI | ⚑ Digital identity tied to proof-of-personhood for an AI era — shown as هوش مصنوعی (could be زیرساخت). |
| پکس گلد (PAXG) `pax-gold` | توکن پشتوانه طلا — Gold-backed token |  |
| یو‌اس‌دی‌دی (USDD) `usdd` | استیبل‌کوین — Stablecoin |  |
| اسکای (SKY) `sky` | دیفای — DeFi |  |
| مورفو (MORPHO) `morpho` | دیفای — DeFi |  |
| اچ‌تی‌ایکس دائو (HTX) `htx-dao` | توکن صرافی — Exchange token |  |
| جاست (JST) `just` | دیفای — DeFi |  |
| ونیز (VVV) `venice-token` | هوش مصنوعی — AI |  |
| گیت توکن (GT) `gatetoken` | توکن صرافی — Exchange token |  |
| ژوپیتر (JUP) `jupiter` | دیفای — DeFi |  |
| کوکوین توکن (KCS) `kucoin-token` | توکن صرافی — Exchange token |  |
| پای نتورک (PI) `pi-network` | لایه ۱ — Layer 1 |  |
| ایرودروم (AERO) `aerodrome` | دیفای — DeFi |  |
| نکسو (NEXO) `nexo` | توکن صرافی — Exchange token | ⚑ Token of a centralised lending platform — shown as توکن صرافی (closest of the list; no CeFi category). |
| استکس (STX) `stacks` | لایه ۲ — Layer 2 | ⚑ A Bitcoin layer for smart contracts — shown as لایه ۲. |
| اینجکتیو (INJ) `injective` | لایه ۱ — Layer 1 |  |
| لیرزیرو (ZRO) `layerzero` | زیرساخت — Infrastructure |  |
| پنکیک‌سواپ (CAKE) `pancakeswap` | دیفای — DeFi |  |
| گو (GHO) `gho` | استیبل‌کوین — Stablecoin |  |
| ایکس‌دی‌سی نتورک (XDC) `xdc-network` | لایه ۱ — Layer 1 |  |
| پیت نتورک (PYTH) `pyth-network` | اوراکل — Oracle |  |

**Totals:** Layer 1 23, DeFi 12, Stablecoin 11, Exchange token 9, Meme coin 5, Payment network 4, Layer 2 4, AI 4, Real-world asset (RWA) 3, Infrastructure 3, Oracle 2, Privacy coin 2, Gold-backed token 2.
