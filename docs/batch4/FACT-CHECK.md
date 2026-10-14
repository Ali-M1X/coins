# Batch 4 (2.7.0) — facts for the editor to verify

Live market data could not be reached from the build environment, so CoinGecko ids, contract addresses and dates come from the builder's own knowledge. Each item below is something to confirm.

## CoinGecko ids to confirm first

A wrong id means the page shows no price until it is corrected (Settings of the coin → CoinGecko id). Least sure:

- `global-dollar` (Global Dollar, USDG)
- `usd1-wlfi` (USD1)
- `canton-network` (Canton, CC)
- `blackrock-usd-institutional-digital-liquidity-fund` (BUIDL)
- `venice-token` (VVV)

Also confirm: `ondo-us-dollar-yield`, `pump-fun`, `htx-dao`, `gatechain-token` (Gate GT), `kucoin-shares`, `xdce-crowd-sale` (XDC), `blockstack` (STX), `jupiter-exchange-solana`, `worldcoin-wld`, `quant-network`.

## Contract addresses (DEX data)

A wrong address only makes the DEX row disappear. It can never show another coin's data, because pairs are also filtered by symbol. Least sure: USD1 `0x8d0D000E…8b0d`, VVV (Base) `0xacfE6019…21bf`, MORPHO `0x58D97B57…c2b2`.

## Live-price pairs

These may not exist. A missing pair is rejected by the feed, and that coin stays on polling, with nothing shown wrongly.

- **Kraken:** SKY/USD, MORPHO/USD, AERO/USD, USDG/USD, PUMP/USD.
- **Nobitex:** DAIIRT, QNTIRT, WLDIRT, ZROIRT, JSTIRT, and the matching USDT markets.

## Timeline and chart lines to check

Each line below appears on the page. Correct it in the coin's fields in wp-admin, or delete it. An edited field is never overwritten by a later update.

| Coin | CoinGecko id | Line (date \| title) |
|---|---|---|
| یو‌اس‌دی‌اس (USDS) | `usds` | 2022 \| طرح اندگیم |
| یو‌اس‌دی‌اس (USDS) | `usds` | 2024 \| انتشار روی سولانا |
| یو‌اس‌دی‌اس (USDS) | `usds` | 2025-10-10 \| بزرگ‌ترین لیکوئید تاریخ |
| اتنا یو‌اس‌دی‌ای (USDe) | `ethena-usde` | 2023 \| معرفی اتنا |
| اتنا یو‌اس‌دی‌ای (USDe) | `ethena-usde` | 2024-08-05 \| آزمون ریزش اوت |
| اتنا یو‌اس‌دی‌ای (USDe) | `ethena-usde` | 2025 \| رشد عرضه |
| کانتون (CC) | `canton-network` | 2014 \| تأسیس دیجیتال اسِت |
| کانتون (CC) | `canton-network` | 2023-05 \| معرفی شبکه کانتون |
| کانتون (CC) | `canton-network` | 2024-07 \| راه‌اندازی هماهنگ‌ساز جهانی |
| کانتون (CC) | `canton-network` | 2024-11 \| بنیاد هماهنگ‌ساز جهانی |
| کانتون (CC) | `canton-network` | 2025-06 \| جذب سرمایه ۱۳۵ میلیون دلاری |
| کانتون (CC) | `canton-network` | 2025 \| توکن‌سازی اوراق |
| کانتون (CC) | `canton-network` | 2025-11 \| فهرست شدن در صرافی‌ها |
| کانتون (CC) | `canton-network` | 2025-10-10 \| بزرگ‌ترین لیکوئید تاریخ |
| دای (DAI) | `dai` | 2015 \| تأسیس میکرداو |
| دای (DAI) | `dai` | 2020-12 \| ماژول ثبات قیمت |
| یو‌اس‌دی وان (USD1) | `usd1-wlfi` | 2024-09 \| معرفی ورلد لیبرتی فایننشال |
| یو‌اس‌دی وان (USD1) | `usd1-wlfi` | 2025-03 \| نگهداری ذخایر |
| یو‌اس‌دی وان (USD1) | `usd1-wlfi` | 2025-05 \| سرمایه‌گذاری MGX در بایننس |
| یو‌اس‌دی وان (USD1) | `usd1-wlfi` | 2025-05 \| فهرست شدن در بایننس |
| یو‌اس‌دی وان (USD1) | `usd1-wlfi` | 2025-05-01 \| سرمایه‌گذاری MGX در بایننس |
| یو‌اس‌دی وان (USD1) | `usd1-wlfi` | 2025-10-10 \| بزرگ‌ترین لیکوئید تاریخ |
| تتر گلد (XAUT) | `tether-gold` | 2020-01 \| عرضه تتر گلد |
| تتر گلد (XAUT) | `tether-gold` | 2024 \| رکوردهای پیاپی طلا |
| تتر گلد (XAUT) | `tether-gold` | 2025 \| گسترش به شبکه‌های دیگر |
| گلوبال دلار (USDG) | `global-dollar` | 2012 \| تأسیس پکسوس |
| گلوبال دلار (USDG) | `global-dollar` | 2019-09 \| پکس گلد |
| گلوبال دلار (USDG) | `global-dollar` | 2024-11 \| عرضه USDG |
| گلوبال دلار (USDG) | `global-dollar` | 2024-11 \| شبکه گلوبال دلار |
| گلوبال دلار (USDG) | `global-dollar` | 2025-10-10 \| بزرگ‌ترین لیکوئید تاریخ |
| کوانت (QNT) | `quant-network` | 2015 \| تأسیس کوانت |
| کوانت (QNT) | `quant-network` | 2018-06 \| فروش توکن |
| کوانت (QNT) | `quant-network` | 2018 \| معرفی اورلجر |
| کوانت (QNT) | `quant-network` | 2021-09-11 \| اوج تاریخی |
| کوانت (QNT) | `quant-network` | 2022 \| کوانت فلو |
| کوانت (QNT) | `quant-network` | 2023 \| همکاری‌های بانکی |
| کوانت (QNT) | `quant-network` | 2024 \| کوانت فیوژن |
| پی‌پل یو‌اس‌دی (PYUSD) | `paypal-usd` | 2023-09 \| پشتیبانی در ونمو |
| پی‌پل یو‌اس‌دی (PYUSD) | `paypal-usd` | 2024 \| رشد عرضه |
| پی‌پل یو‌اس‌دی (PYUSD) | `paypal-usd` | 2025 \| گسترش به شبکه‌های دیگر |
| ریپل یو‌اس‌دی (RLUSD) | `ripple-usd` | 2024-04 \| اعلام RLUSD |
| ریپل یو‌اس‌دی (RLUSD) | `ripple-usd` | 2025-04 \| خرید هیدن رود |
| ریپل یو‌اس‌دی (RLUSD) | `ripple-usd` | 2025-07 \| امانت‌داری بانک نیویورک ملون |
| ریپل یو‌اس‌دی (RLUSD) | `ripple-usd` | 2025-08 \| خرید ریل |
| ریپل یو‌اس‌دی (RLUSD) | `ripple-usd` | 2025-10-10 \| بزرگ‌ترین لیکوئید تاریخ |
| اوندو یو‌اس‌دی‌وای (USDY) | `ondo-us-dollar-yield` | 2021 \| تأسیس اوندو فایننس |
| اوندو یو‌اس‌دی‌وای (USDY) | `ondo-us-dollar-yield` | 2023-01 \| صندوق OUSG |
| اوندو یو‌اس‌دی‌وای (USDY) | `ondo-us-dollar-yield` | 2023-08 \| عرضه USDY |
| اوندو یو‌اس‌دی‌وای (USDY) | `ondo-us-dollar-yield` | 2024 \| انتشار روی شبکه‌های دیگر |
| اوندو یو‌اس‌دی‌وای (USDY) | `ondo-us-dollar-yield` | 2025 \| سهام توکنی‌شده |
| اوندو یو‌اس‌دی‌وای (USDY) | `ondo-us-dollar-yield` | 2025-10-10 \| بزرگ‌ترین لیکوئید تاریخ |
| پامپ دات فان (PUMP) | `pump-fun` | 2024-01 \| راه‌اندازی پامپ دات فان |
| پامپ دات فان (PUMP) | `pump-fun` | 2024-11 \| توقف پخش زنده |
| پامپ دات فان (PUMP) | `pump-fun` | 2025-01 \| شکایت گروهی |
| پامپ دات فان (PUMP) | `pump-fun` | 2025 \| خرید توکن با درآمد |
| پامپ دات فان (PUMP) | `pump-fun` | 2025-07-14 \| آغاز معامله PUMP |
| پامپ دات فان (PUMP) | `pump-fun` | 2025-10-10 \| بزرگ‌ترین لیکوئید تاریخ |
| صندوق بیلد بلک‌راک (BUIDL) | `blackrock-usd-institutional-digital-liquidity-fund` | 2024 \| پشتوانه صندوق OUSG |
| صندوق بیلد بلک‌راک (BUIDL) | `blackrock-usd-institutional-digital-liquidity-fund` | 2024-12 \| پشتوانه USDtb |
| صندوق بیلد بلک‌راک (BUIDL) | `blackrock-usd-institutional-digital-liquidity-fund` | 2025-03 \| عبور از یک میلیارد دلار |
| صندوق بیلد بلک‌راک (BUIDL) | `blackrock-usd-institutional-digital-liquidity-fund` | 2025-03 \| انتشار روی سولانا |
| صندوق بیلد بلک‌راک (BUIDL) | `blackrock-usd-institutional-digital-liquidity-fund` | 2025 \| پذیرش به‌عنوان وثیقه |
| صندوق بیلد بلک‌راک (BUIDL) | `blackrock-usd-institutional-digital-liquidity-fund` | 2025-10-10 \| بزرگ‌ترین لیکوئید تاریخ |
| ورلدکوین (WLD) | `worldcoin-wld` | 2019 \| تأسیس تولز فور هیومنیتی |
| ورلدکوین (WLD) | `worldcoin-wld` | 2021-10 \| معرفی ورلدکوین |
| ورلدکوین (WLD) | `worldcoin-wld` | 2024-03 \| توقف در اسپانیا |
| ورلدکوین (WLD) | `worldcoin-wld` | 2025-04-30 \| ورود به آمریکا |
| ورلدکوین (WLD) | `worldcoin-wld` | 2024-03-10 \| اوج تاریخی |
| ورلدکوین (WLD) | `worldcoin-wld` | 2025-10-10 \| بزرگ‌ترین لیکوئید تاریخ |
| پکس گلد (PAXG) | `pax-gold` | 2019-09 \| عرضه پکس گلد |
| پکس گلد (PAXG) | `pax-gold` | 2025 \| رشد عرضه |
| یو‌اس‌دی‌دی (USDD) | `usdd` | 2022-06-13 \| جدا شدن از دلار |
| یو‌اس‌دی‌دی (USDD) | `usdd` | 2022-06 \| تقویت پشتوانه |
| یو‌اس‌دی‌دی (USDD) | `usdd` | 2025-01 \| نسخه دوم USDD |
| یو‌اس‌دی‌دی (USDD) | `usdd` | 2025 \| انتشار روی اتریوم |
| یو‌اس‌دی‌دی (USDD) | `usdd` | 2025-10-10 \| بزرگ‌ترین لیکوئید تاریخ |
| اسکای (SKY) | `sky` | 2015 \| میکرداو |
| اسکای (SKY) | `sky` | 2022 \| طرح اندگیم |
| اسکای (SKY) | `sky` | 2025 \| خرید SKY با درآمد |
| اسکای (SKY) | `sky` | 2025-10-10 \| بزرگ‌ترین لیکوئید تاریخ |
| مورفو (MORPHO) | `morpho` | 2021 \| تأسیس مورفو لبز |
| مورفو (MORPHO) | `morpho` | 2022 \| بهینه‌ساز مورفو |
| مورفو (MORPHO) | `morpho` | 2024-01 \| مورفو بلو |
| مورفو (MORPHO) | `morpho` | 2025-01 \| وام کوین‌بیس |
| مورفو (MORPHO) | `morpho` | 2025 \| سپرده بنیاد اتریوم |
| مورفو (MORPHO) | `morpho` | 2025-06 \| نسخه دوم مورفو |
| مورفو (MORPHO) | `morpho` | 2025-10-10 \| بزرگ‌ترین لیکوئید تاریخ |
| اچ‌تی‌ایکس دائو (HTX) | `htx-dao` | 2018-01 \| توکن HT |
| اچ‌تی‌ایکس دائو (HTX) | `htx-dao` | 2022-10 \| تغییر مالکیت |
| اچ‌تی‌ایکس دائو (HTX) | `htx-dao` | 2023-09-24 \| نخستین هک ۲۰۲۳ |
| اچ‌تی‌ایکس دائو (HTX) | `htx-dao` | 2023-11-22 \| هک بزرگ |
| اچ‌تی‌ایکس دائو (HTX) | `htx-dao` | 2024-01 \| توکن HTX دائو |
| اچ‌تی‌ایکس دائو (HTX) | `htx-dao` | 2025-10-10 \| بزرگ‌ترین لیکوئید تاریخ |
| جاست (JST) | `just` | 2020 \| معرفی جاست |
| جاست (JST) | `just` | 2020-05 \| عرضه JST |
| جاست (JST) | `just` | 2020 \| جاست‌لند |
| جاست (JST) | `just` | 2024 \| رشد جاست‌لند |
| جاست (JST) | `just` | 2025 \| خرید و سوزاندن JST |
| جاست (JST) | `just` | 2025-10-10 \| بزرگ‌ترین لیکوئید تاریخ |
| ونیز (VVV) | `venice-token` | 2024-05 \| راه‌اندازی ونیز |
| ونیز (VVV) | `venice-token` | 2025-01 \| ایردراپ |
| ونیز (VVV) | `venice-token` | 2025 \| دسترسی با سپرده |
| ونیز (VVV) | `venice-token` | 2025 \| کاهش انتشار |
| ونیز (VVV) | `venice-token` | 2025-10-10 \| بزرگ‌ترین لیکوئید تاریخ |
| گیت توکن (GT) | `gatechain-token` | 2013 \| صرافی بیتر |
| گیت توکن (GT) | `gatechain-token` | 2015 \| هک بیتر |
| گیت توکن (GT) | `gatechain-token` | 2017 \| تغییر نام به Gate.io |
| گیت توکن (GT) | `gatechain-token` | 2019 \| انتشار GT |
| گیت توکن (GT) | `gatechain-token` | 2020 \| زنجیره گیت |
| گیت توکن (GT) | `gatechain-token` | 2025 \| تغییر نام به گیت |
| گیت توکن (GT) | `gatechain-token` | 2025-10-10 \| بزرگ‌ترین لیکوئید تاریخ |
| ژوپیتر (JUP) | `jupiter-exchange-solana` | 2021 \| راه‌اندازی ژوپیتر |
| ژوپیتر (JUP) | `jupiter-exchange-solana` | 2023 \| قراردادهای دائمی |
| ژوپیتر (JUP) | `jupiter-exchange-solana` | 2024 \| لانچ‌پد ژوپیتر |
| ژوپیتر (JUP) | `jupiter-exchange-solana` | 2025-01 \| خرید مون‌شات |
| ژوپیتر (JUP) | `jupiter-exchange-solana` | 2025 \| خرید JUP با درآمد |
| ژوپیتر (JUP) | `jupiter-exchange-solana` | 2025-10-10 \| بزرگ‌ترین لیکوئید تاریخ |
| کوکوین توکن (KCS) | `kucoin-shares` | 2017 \| تأسیس کوکوین |
| کوکوین توکن (KCS) | `kucoin-shares` | 2021 \| زنجیره کوکوین |
| کوکوین توکن (KCS) | `kucoin-shares` | 2025 \| سوزاندن منظم KCS |
| کوکوین توکن (KCS) | `kucoin-shares` | 2025-10-10 \| بزرگ‌ترین لیکوئید تاریخ |
| پای نتورک (PI) | `pi-network` | 2020 \| میلیون‌ها کاربر |
| پای نتورک (PI) | `pi-network` | 2022 \| احراز هویت کاربران |
| پای نتورک (PI) | `pi-network` | 2025-02-26 \| اوج پس از عرضه |
| پای نتورک (PI) | `pi-network` | 2025 \| آزادسازی‌های بزرگ |
| پای نتورک (PI) | `pi-network` | 2025-10-10 \| بزرگ‌ترین لیکوئید تاریخ |
| ایرودروم (AERO) | `aerodrome-finance` | 2022 \| ولودروم |
| ایرودروم (AERO) | `aerodrome-finance` | 2024 \| سرمایه‌گذاری کوین‌بیس |
| ایرودروم (AERO) | `aerodrome-finance` | 2024-04 \| استخرهای متمرکز اسلیپ‌استریم |
| ایرودروم (AERO) | `aerodrome-finance` | 2024 \| بزرگ‌ترین صرافی بیس |
| ایرودروم (AERO) | `aerodrome-finance` | 2025 \| ادغام با ولودروم |
| ایرودروم (AERO) | `aerodrome-finance` | 2025-10-10 \| بزرگ‌ترین لیکوئید تاریخ |
| نکسو (NEXO) | `nexo` | 2018 \| فروش توکن |
| نکسو (NEXO) | `nexo` | 2018 \| راه‌اندازی پلتفرم |
| نکسو (NEXO) | `nexo` | 2022-12 \| خروج از آمریکا |
| نکسو (NEXO) | `nexo` | 2025 \| بازگشت به آمریکا |
| نکسو (NEXO) | `nexo` | 2025-10-10 \| بزرگ‌ترین لیکوئید تاریخ |
| استکس (STX) | `blockstack` | 2013 \| بلاک‌استک |
| استکس (STX) | `blockstack` | 2024-04-01 \| اوج تاریخی |
| استکس (STX) | `blockstack` | 2025 \| رشد دیفای بیت‌کوین |
| اینجکتیو (INJ) | `injective-protocol` | 2018 \| تأسیس اینجکتیو |
| اینجکتیو (INJ) | `injective-protocol` | 2020-10 \| لانچ‌پد بایننس |
| اینجکتیو (INJ) | `injective-protocol` | 2023 \| حراج سوزاندن |
| اینجکتیو (INJ) | `injective-protocol` | 2024-01 \| ارتقای ولان |
| اینجکتیو (INJ) | `injective-protocol` | 2024-03-14 \| اوج تاریخی |
| اینجکتیو (INJ) | `injective-protocol` | 2025 \| ماشین مجازی اتریوم بومی |
| لیرزیرو (ZRO) | `layerzero` | 2021 \| تأسیس لیرزیرو لبز |
| لیرزیرو (ZRO) | `layerzero` | 2022 \| شبکه اصلی |
| لیرزیرو (ZRO) | `layerzero` | 2024-05 \| شکار حساب‌های جعلی |
| لیرزیرو (ZRO) | `layerzero` | 2024-12 \| رأی سوییچ کارمزد |
| لیرزیرو (ZRO) | `layerzero` | 2025 \| خرید استارگیت |
| لیرزیرو (ZRO) | `layerzero` | 2025-10-10 \| بزرگ‌ترین لیکوئید تاریخ |
| پنکیک‌سواپ (CAKE) | `pancakeswap-token` | 2020-09 \| راه‌اندازی پنکیک‌سواپ |
| پنکیک‌سواپ (CAKE) | `pancakeswap-token` | 2021-04-30 \| اوج تاریخی |
| پنکیک‌سواپ (CAKE) | `pancakeswap-token` | 2022 \| گسترش به شبکه‌های دیگر |
| پنکیک‌سواپ (CAKE) | `pancakeswap-token` | 2023-04 \| نسخه سوم |
| پنکیک‌سواپ (CAKE) | `pancakeswap-token` | 2025-04 \| توکنومیک ۳ |
| پنکیک‌سواپ (CAKE) | `pancakeswap-token` | 2025 \| رکورد حجم |
| پنکیک‌سواپ (CAKE) | `pancakeswap-token` | 2025-10-10 \| بزرگ‌ترین لیکوئید تاریخ |
| گو (GHO) | `gho` | 2022-07 \| پیشنهاد GHO |
| گو (GHO) | `gho` | 2023 \| فاصله از دلار |
| گو (GHO) | `gho` | 2024 \| ماژول ثبات |
| گو (GHO) | `gho` | 2024 \| انتشار روی آربیتروم |
| گو (GHO) | `gho` | 2025 \| سپرده‌گذاری GHO |
| ایکس‌دی‌سی نتورک (XDC) | `xdce-crowd-sale` | 2017 \| تأسیس زین‌فین |
| ایکس‌دی‌سی نتورک (XDC) | `xdce-crowd-sale` | 2019-06 \| شبکه اصلی |
| ایکس‌دی‌سی نتورک (XDC) | `xdce-crowd-sale` | 2021 \| بنیاد ایکس‌دی‌سی |
| ایکس‌دی‌سی نتورک (XDC) | `xdce-crowd-sale` | 2021-08 \| اوج ۲۰۲۱ |
| ایکس‌دی‌سی نتورک (XDC) | `xdce-crowd-sale` | 2023 \| توکن‌سازی اسناد تجاری |
| ایکس‌دی‌سی نتورک (XDC) | `xdce-crowd-sale` | 2024 \| ارتقای ایکس‌دی‌سی ۲ |
| ایکس‌دی‌سی نتورک (XDC) | `xdce-crowd-sale` | 2021-08-21 \| اوج ۲۰۲۱ |
| پیت نتورک (PYTH) | `pyth-network` | 2021-08 \| راه‌اندازی پیت |
| پیت نتورک (PYTH) | `pyth-network` | 2022 \| گسترش به شبکه‌های دیگر |
| پیت نتورک (PYTH) | `pyth-network` | 2024-03-16 \| اوج تاریخی |
| پیت نتورک (PYTH) | `pyth-network` | 2024-05 \| نخستین آزادسازی بزرگ |
| پیت نتورک (PYTH) | `pyth-network` | 2025 \| پیت پرو |
| پیت نتورک (PYTH) | `pyth-network` | 2025-10-10 \| بزرگ‌ترین لیکوئید تاریخ |
