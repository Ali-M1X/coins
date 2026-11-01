# 2.16.0 — coin hub → pillars → articles, and content blocks

## URLs

| Page | URL | What it is |
|---|---|---|
| Coin hub | `/coins/ethereum/` | the existing coin page, unchanged; v2 adds a pillar tab bar |
| Pillar | `/coins/ethereum/learn/` | intro, featured then newest articles, FAQ |
| Article | `/coins/ethereum/learn/<post-slug>/` | a normal WordPress post |

The nine pillars are:

| Slug | Persian name |
|---|---|
| `learn` | آموزش |
| `analysis` | تحلیل |
| `news` | اخبار |
| `prediction` | پیش‌بینی و آینده |
| `buy` | خرید و نگهداری |
| `staking` | استیکینگ و درآمد |
| `ecosystem` | اکوسیستم |
| `compare` | مقایسه |
| `tools` | ابزارها |

**Routing**
- The routes are two rewrite rules at the top. They don't touch `/coins/`, `/coins/<coin>/`, `/coins/page/N/`, `/coins/convert/` or `/coins/compare/`.
- They are flushed on activation, and once from wp-admin after updating.

**Empty pillars**
- A pillar with no published article and no intro answers **404**.
- It is linked from nowhere: tabs, chips, next-step cards and the sitemap all come from the same "has content" list.

**Moved articles**
- If an article moves to another pillar or coin, its old hub URL **301**s to the new one.
- So does its old `/slug/` URL.
- Drafts and previews keep WordPress's own URLs.

## Writing content

**1. Set up the intro and FAQ for each pillar**

Open **Posts → ارزهای هاب → (e.g. اتریوم)**. The page has an intro box and a FAQ box for each of the nine pillars.
- **FAQ format:** one `پرسش | پاسخ` per line.
- **One-time setup:** the coin terms (one per coin page) and the nine pillar terms are created automatically the first time an admin opens wp-admin.

**2. Write an article**

Write an ordinary post. In the side panel, tick:
- **ارز (هاب):** the coin, e.g. اتریوم;
- **ستون محتوا:** the pillar, e.g. آموزش.

Once published:
- its URL becomes `/coins/ethereum/learn/<slug>/`;
- the pillar page appears with this article in it, if the pillar was empty before.

**3. Featured articles**

Mark a post **Sticky** (Status → «سنجاق به بالای صفحه»). It appears under «مقاله‌های برگزیده» on its pillar.

**4. Excerpt**

The post's excerpt is the text under its title on cards.

**Article pages.** These parts are added by the template; you don't place them:
- breadcrumb;
- dates and author box;
- table of contents, built from your H2/H3 headings;
- next-step card, pointing to the next pillar with content;
- related articles;
- links to the pillar and the hub.

**Schema:**
- Pillar pages: BreadcrumbList, CollectionPage and FAQPage.
- Articles: BreadcrumbList, Article, and FAQPage when the post has a FAQ block.

## Blocks

In the block editor, click **+** and search «های‌بیت» or the block's name; the blocks are under **Widgets**. Each block has its settings in the right-hand panel. The editor preview is rendered by the same PHP as the page.

**Leaving «ارز» empty uses the coin of the article** (or of the pillar). Every block reads only the plugin's cache; a page view makes no request to any data provider. **A block with no cached data renders nothing on the page**, and the editor says so.

| Block | Shortcode | What it shows |
|---|---|---|
| کارت قیمت زنده | `[thb_price coin="ethereum" live="1"]` | Logo, name, price in USD and toman, 24h change, and a 7-day sparkline. The whole card links to the coin hub. `live="0"` turns off the live update over the existing WebSocket client. |
| ناوبری ستون‌ها | `[thb_pillars coin="ethereum" current="learn"]` | Chips for the hub and each pillar with content, with the current one highlighted. |
| مقاله‌های مرتبط | `[thb_related coin="ethereum" pillar="staking" count="4"]` | Article cards from the same coin. `pillar` may be empty to mean any pillar. The article being read is excluded. |
| قدم بعدی | `[thb_next pillar="staking" title="" text=""]` or `[thb_next post="123"]` | A card to a pillar (only if it has content) or to a published article. |
| مبدل کوچک | `[thb_converter coin="ethereum"]` | Amount to USD, toman, EUR or GBP, using only the rates the cache holds. |
| نمودار کوچک | `[thb_chart coin="ethereum" period="7d"]` | A price line for `24h`, `7d`, `30d`, `90d` or `1y`, with the change, low and high. |
| آمار کلیدی | `[thb_stats coin="ethereum"]` | Rank, market cap, gas and staking APR. Each row appears only when there is real data. |
| پرسش‌های متداول | `[thb_faq]پرسش؟ \| پاسخ[/thb_faq]` | Expandable questions, one `پرسش \| پاسخ` per line. On an article this also produces FAQPage schema. |
| فهرست مطالب | `[thb_toc]` | The table of contents of the current post's H2/H3. Placing it moves the TOC from the sidebar into the text. |

**Using shortcodes.** Shortcodes work in the classic editor, in a Shortcode block, and in any post or page; outside our article template, the blocks carry their own dark styling. To put a price card in the middle of an article, place the «کارت قیمت زنده» block (or `[thb_price]`) between two paragraphs.

## Requests

- Blocks, pillar pages and articles read only WordPress data and the cache. Tested: rendering the pillar page, the article with five blocks, and the hub makes **0** provider requests.
- In the browser, the only external connections are logo images and the existing live-price WebSockets (one per exchange, as on the coin pages).
- The classic coin page is byte-identical to the frozen snapshot.

## Sitemap

- The plugin sitemap has pillars with content and their articles: `wp-sitemap-thbhub-1.xml` (core sitemaps), or `thbhub-sitemap.xml` (Yoast).
- **Article lastmod:** the post's own modified time.
- **Pillar lastmod:** its newest article's modified time, or when its intro was last saved, whichever is later.
- Hub articles are left out of the posts sitemap, so no URL is listed twice.
