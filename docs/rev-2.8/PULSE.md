# 2.8.0 — Network Pulse: why rows were stuck, what changed, what each coin shows

## Why your Ethereum rows read «در حال دریافت داده»

| Row | Source | What was wrong |
|---|---|---|
| زمان بلوک · تراکنش‌های در انتظار · تراکنش‌ها (۲۴ ساعت) | Blockchair (keyless) | Blockchair is not answering your server. The likeliest reason is its keyless IP ban (HTTP 430), which has hit this server before. Other possibilities are a network block or an outage. |
| اعتبارسنج‌های فعال · اتر در استیکینگ | beaconcha.in | beaconcha.in's API terms require a valid API key on every request. The plugin called it without one, because the setting was marked optional. |
| (all of the above) | — | A page bug. A provider that fails, is paused or is refused by our own budget never writes a cache entry, and "no entry" was shown as «در حال دریافت داده» — forever. |

The two that work on your page show the code path is sound: gas (Etherscan, with your key) and active addresses (growthepie). The scheduler is not the cause either. A simulation at 84 coins fills Ethereum's network, staking, gas and activity entries on time (worst age ÷ TTL: 0.6–0.7).

**To confirm on the server:** wp-admin → TheHybit Coins → Diagnostics. The «آخرین خطا» column shows the providers' own words for `network` and `staking`.

## What 2.8.0 changes

1. **No more endless loading.** A row is shown with its figure. Without a figure it is shown as loading only during the scheduler's first two hours (`Scheduler::FIRST_GRACE`, measured from its first tick), and only if no error was recorded. After that, a row whose source failed, is paused, or never answered is removed.
2. **beaconcha.in needs a key.** Without one it is never called (no wasted requests) and its rows are not drawn. It is not switched off, so the frozen classic page is unchanged. Add the free key in Settings → «beaconcha.in» (from beaconcha.in → account → API) and the two rows come back within half an hour.
3. **Blockchair.** Nothing to fix in code. If Diagnostics shows a 430, the ban lifts by itself; the plugin pauses for an hour after one and stays inside 30 requests an hour. Until it answers, its rows are removed rather than loading. No proxy or relay is used.

## Rows shown per coin when every source answers

This is the test fixture's view: every provider responds. On your server, the Blockchair rows (block time, pending, 24h transactions, hash rate) and the beaconcha.in rows (validators, staked ETH) appear only once those providers answer, as described above. Coins without a native network source show the chain's DeFi activity from DefiLlama instead, or no box at all.

| Coin | Network Pulse rows shown (source) |
|---|---|
| اتریوم (ETH) | زمان بلوک (میانگین) (Blockchair)<br>گس پیشنهادی (Etherscan)<br>تراکنش‌های در انتظار (Blockchair)<br>تراکنش‌ها (۲۴ ساعت) (Blockchair)<br>آدرس‌های فعال (روز گذشته) (growthepie)<br>اعتبارسنج‌های فعال (beaconcha.in)<br>اتر در استیکینگ (امنیت شبکه) (beaconcha.in) |
| بیت‌کوین (BTC) | زمان بلوک (میانگین) (Blockchair)<br>تراکنش‌های در انتظار (Blockchair)<br>تراکنش‌ها (۲۴ ساعت) (Blockchair)<br>هش‌ریت (Blockchair) |
| تتر (USDT) | — (no free network source: rows and box removed) |
| ایکس‌آر‌پی (XRP) | ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| بی‌ان‌بی (BNB) | عرضه استیبل‌کوین روی این شبکه (DefiLlama)<br>ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| سولانا (SOL) | ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| یو‌اس‌دی کوین (USDC) | — (no free network source: rows and box removed) |
| دوج‌کوین (DOGE) | زمان بلوک (میانگین) (Blockchair)<br>تراکنش‌های در انتظار (Blockchair)<br>تراکنش‌ها (۲۴ ساعت) (Blockchair)<br>هش‌ریت (Blockchair) |
| ترون (TRX) | عرضه استیبل‌کوین روی این شبکه (DefiLlama)<br>ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| کاردانو (ADA) | ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| هایپرلیکوئید (HYPE) | ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| چین‌لینک (LINK) | — (no free network source: rows and box removed) |
| استلار (XLM) | ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| سویی (SUI) | ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| بیت‌کوین کش (BCH) | زمان بلوک (میانگین) (Blockchair)<br>تراکنش‌های در انتظار (Blockchair)<br>تراکنش‌ها (۲۴ ساعت) (Blockchair)<br>هش‌ریت (Blockchair) |
| آوالانچ (AVAX) | ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| هدرا (HBAR) | ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| لایت‌کوین (LTC) | زمان بلوک (میانگین) (Blockchair)<br>تراکنش‌های در انتظار (Blockchair)<br>تراکنش‌ها (۲۴ ساعت) (Blockchair)<br>هش‌ریت (Blockchair) |
| تون‌کوین (TON) | ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| شیبا اینو (SHIB) | — (no free network source: rows and box removed) |
| لئو (LEO) | — (no free network source: rows and box removed) |
| زی‌کش (ZEC) | زمان بلوک (میانگین) (Blockchair)<br>تراکنش‌های در انتظار (Blockchair)<br>تراکنش‌ها (۲۴ ساعت) (Blockchair)<br>هش‌ریت (Blockchair) |
| مونرو (XMR) | — (no free network source: rows and box removed) |
| وایت‌بیت کوین (WBT) | — (no free network source: rows and box removed) |
| ورلد لیبرتی فایننشال (WLFI) | — (no free network source: rows and box removed) |
| منتل (MNT) | ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| یونی‌سواپ (UNI) | — (no free network source: rows and box removed) |
| کرونوس (CRO) | ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| پولکادات (DOT) | — (no free network source: rows and box removed) |
| بیت‌تنسور (TAO) | — (no free network source: rows and box removed) |
| بیت‌گت توکن (BGB) | — (no free network source: rows and box removed) |
| آوه (AAVE) | — (no free network source: rows and box removed) |
| اتنا (ENA) | — (no free network source: rows and box removed) |
| نیر پروتکل (NEAR) | ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| اوکی‌بی (OKB) | — (no free network source: rows and box removed) |
| پپه (PEPE) | — (no free network source: rows and box removed) |
| اینترنت کامپیوتر (ICP) | ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| اتریوم کلاسیک (ETC) | ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| اپتوس (APT) | ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| اوندو (ONDO) | — (no free network source: rows and box removed) |
| استر (ASTER) | — (no free network source: rows and box removed) |
| پالیگان (POL) | ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| کسپا (KAS) | — (no free network source: rows and box removed) |
| الگورند (ALGO) | ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| کازماس (ATOM) | ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| آربیتروم (ARB) | آدرس‌های فعال (روز گذشته) (growthepie)<br>ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| رندر (RENDER) | — (no free network source: rows and box removed) |
| فایل‌کوین (FIL) | ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| وی‌چین (VET) | ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| آفیشال ترامپ (TRUMP) | — (no free network source: rows and box removed) |
| یو‌اس‌دی‌اس (USDS) | — (no free network source: rows and box removed) |
| اتنا یو‌اس‌دی‌ای (USDe) | — (no free network source: rows and box removed) |
| کانتون (CC) | — (no free network source: rows and box removed) |
| دای (DAI) | — (no free network source: rows and box removed) |
| یو‌اس‌دی وان (USD1) | — (no free network source: rows and box removed) |
| تتر گلد (XAUT) | — (no free network source: rows and box removed) |
| گلوبال دلار (USDG) | — (no free network source: rows and box removed) |
| کوانت (QNT) | — (no free network source: rows and box removed) |
| پی‌پل یو‌اس‌دی (PYUSD) | — (no free network source: rows and box removed) |
| ریپل یو‌اس‌دی (RLUSD) | — (no free network source: rows and box removed) |
| اوندو یو‌اس‌دی‌وای (USDY) | — (no free network source: rows and box removed) |
| پامپ دات فان (PUMP) | — (no free network source: rows and box removed) |
| صندوق بیلد بلک‌راک (BUIDL) | — (no free network source: rows and box removed) |
| ورلدکوین (WLD) | — (no free network source: rows and box removed) |
| پکس گلد (PAXG) | — (no free network source: rows and box removed) |
| یو‌اس‌دی‌دی (USDD) | — (no free network source: rows and box removed) |
| اسکای (SKY) | — (no free network source: rows and box removed) |
| مورفو (MORPHO) | — (no free network source: rows and box removed) |
| اچ‌تی‌ایکس دائو (HTX) | — (no free network source: rows and box removed) |
| جاست (JST) | — (no free network source: rows and box removed) |
| ونیز (VVV) | — (no free network source: rows and box removed) |
| گیت توکن (GT) | — (no free network source: rows and box removed) |
| ژوپیتر (JUP) | — (no free network source: rows and box removed) |
| کوکوین توکن (KCS) | — (no free network source: rows and box removed) |
| پای نتورک (PI) | — (no free network source: rows and box removed) |
| ایرودروم (AERO) | — (no free network source: rows and box removed) |
| نکسو (NEXO) | — (no free network source: rows and box removed) |
| استکس (STX) | ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| اینجکتیو (INJ) | ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| لیرزیرو (ZRO) | — (no free network source: rows and box removed) |
| پنکیک‌سواپ (CAKE) | — (no free network source: rows and box removed) |
| گو (GHO) | — (no free network source: rows and box removed) |
| ایکس‌دی‌سی نتورک (XDC) | ارزش قفل‌شده در برنامه‌های شبکه (TVL) (DefiLlama)<br>تغییر ارزش قفل‌شده در ۷ روز (DefiLlama)<br>کارمزد پرداختی کاربران (۲۴ ساعت) (DefiLlama)<br>درآمد پروتکل‌ها (۲۴ ساعت) (DefiLlama) |
| پیت نتورک (PYTH) | — (no free network source: rows and box removed) |
