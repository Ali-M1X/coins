/**
 * TheHybit — WordPress normalization.
 *
 * The news section is fed by OUR OWN site, not a market-data provider. But the
 * same rule applies as everywhere else in this codebase: the UI never touches a
 * raw upstream response.
 *
 *   WP REST response  →  normalizeWpPosts()  →  Article[]  →  news component
 *
 * Why the seam matters here specifically:
 *
 *   - The WP REST shape is awkward and not ours to control. Titles arrive as
 *     `title.rendered` — HTML, with entities. Images hide three levels deep
 *     inside `_embedded['wp:featuredmedia']` and are absent unless the request
 *     used `_embed`. Dates come in two flavours, one of which lacks a timezone.
 *   - It can change. WP major versions, a plugin, or a switch to server-rendered
 *     PHP instead of REST would all reshape it. Only this file should care.
 *   - PHP will do this server-side eventually. Keeping it pure and shape-driven
 *     means the port is a translation, not a redesign.
 *
 * PURE. No DOM, no fetch. Runs in Node for tests.
 */

/* -------------------------------------------------------------------------
 * HTML entities
 *
 * WordPress returns rendered HTML for titles, so a Persian headline routinely
 * arrives containing &#8217;, &zwnj; or &amp;. Dropped into textContent
 * unprocessed they render literally as those character sequences — a small,
 * very visible bug. Decoding here (rather than with innerHTML at the call site)
 * also keeps us from injecting markup we did not intend.
 * ---------------------------------------------------------------------- */

const NAMED_ENTITIES = {
  amp: '&', lt: '<', gt: '>', quot: '"', apos: "'",
  nbsp: ' ', zwnj: '‌', zwj: '‍',
  laquo: '«', raquo: '»', hellip: '…', mdash: '—', ndash: '–',
  rsquo: '’', lsquo: '‘', ldquo: '“', rdquo: '”',
  times: '×', middot: '·',
};

function decodeEntities(str) {
  return str.replace(/&(#x?[0-9a-fA-F]+|[a-zA-Z]+);/g, (match, body) => {
    if (body[0] === '#') {
      const code = body[1] === 'x' || body[1] === 'X'
        ? parseInt(body.slice(2), 16)
        : parseInt(body.slice(1), 10);
      return Number.isFinite(code) && code > 0 ? String.fromCodePoint(code) : match;
    }
    return NAMED_ENTITIES[body] ?? match;
  });
}

/** Strip tags, decode entities, collapse whitespace. */
function plainText(html) {
  if (typeof html !== 'string') return '';
  return decodeEntities(html.replace(/<[^>]*>/g, '')).replace(/\s+/g, ' ').trim();
}

/**
 * WordPress `date` is site-local with no offset; `date_gmt` is UTC but also
 * carries no 'Z'. Preferring date_gmt and appending Z is what stops articles
 * appearing hours off in a Tehran-timezone reader.
 */
function toIso(post) {
  const raw = post?.date_gmt ?? post?.date;
  if (typeof raw !== 'string' || !raw) return null;
  const withZone = /[Zz]|[+-]\d{2}:\d{2}$/.test(raw) ? raw : `${raw}Z`;
  const d = new Date(withZone);
  return Number.isNaN(d.getTime()) ? null : d.toISOString();
}

/** Featured image, if the request used `_embed` and one is actually set. */
function featuredImage(post) {
  const media = post?._embedded?.['wp:featuredmedia'];
  const first = Array.isArray(media) ? media[0] : null;
  if (!first || first.code) return null; // `code` marks an embed error object

  // Prefer a small size if the media details expose one — the thumbnail slot is
  // 56px, so shipping a 2000px hero would be wasteful.
  const sizes = first.media_details?.sizes;
  const src =
    sizes?.thumbnail?.source_url ||
    sizes?.medium?.source_url ||
    first.source_url;

  if (typeof src !== 'string' || !src) return null;

  return { src, alt: plainText(first.alt_text) || null };
}

/**
 * The topical category name, for the small chip beside each article's date.
 *
 * `excludeSlug` is the coin's own news category. Every post in the query
 * carries it by definition, so showing it would label all four articles on the
 * Ethereum page "اخبار اتریوم" — true, and completely uninformative. Skipping
 * it surfaces the category that actually distinguishes the article (شبکه,
 * تحلیل, دیفای…). A post filed only under the coin category gets no chip,
 * which the markup already handles.
 */
function primaryCategory(post, excludeSlug) {
  const groups = post?._embedded?.['wp:term'];
  if (!Array.isArray(groups)) return null;

  for (const group of groups) {
    if (!Array.isArray(group)) continue;
    const term = group.find(
      (t) => t?.taxonomy === 'category' && t?.name && t?.slug !== excludeSlug
    );
    if (term) return plainText(term.name);
  }
  return null;
}

/**
 * Normalize a WP REST `/wp/v2/posts` response into the article shape the UI
 * consumes.
 *
 * @param {Array} posts    raw WP REST posts (ideally requested with `_embed`)
 * @param {object} [opts]
 * @param {number} [opts.limit=5]
 * @param {string} [opts.excludeCategorySlug]  the coin's own news category,
 *        omitted from the displayed chip since every post carries it
 * @returns {Array<{id, title, url, publishedAt, image, category}>}
 *
 * Sorted newest first. Entries without a title or a URL are dropped rather
 * than rendered as broken rows — a malformed post should cost one item, not
 * the section.
 */
export function normalizeWpPosts(posts, { limit = 5, excludeCategorySlug = null } = {}) {
  if (!Array.isArray(posts)) return [];

  return posts
    .map((post) => {
      const title = plainText(post?.title?.rendered ?? post?.title);
      const url = typeof post?.link === 'string' ? post.link : null;
      const publishedAt = toIso(post);

      if (!title || !url) return null;

      return {
        id: post?.id ?? url,
        title,
        url,
        publishedAt,
        image: featuredImage(post),
        category: primaryCategory(post, excludeCategorySlug),
      };
    })
    .filter(Boolean)
    .sort((a, b) => {
      // Undated posts sink rather than jumping to the top via NaN comparison.
      const ta = a.publishedAt ? Date.parse(a.publishedAt) : -Infinity;
      const tb = b.publishedAt ? Date.parse(b.publishedAt) : -Infinity;
      return tb - ta;
    })
    .slice(0, Math.max(0, limit));
}

/* -------------------------------------------------------------------------
 * Coin → WordPress category
 *
 * Each coin has an ordinary WordPress category under /category/news/, and that
 * category IS the relationship between a coin and its articles. There is no
 * custom taxonomy, no coin entity and no second linking system to keep in sync.
 *
 *   coin.newsCategorySlug  →  WP category  →  latest posts  →  normalizeWpPosts()
 *
 * The slug is stored explicitly on the coin rather than derived from its name
 * or id at runtime. Deriving it would silently break the day a category slug
 * does not match the coin's canonical slug, and that mismatch would be
 * invisible — the section would simply go empty.
 * ---------------------------------------------------------------------- */

/** Where the categories live. Only used to build human-facing links. */
export const SITE_BASE = 'https://thehybit.com';
export const NEWS_CATEGORY_BASE = `${SITE_BASE}/category/news`;

/**
 * The public archive URL for a coin's category, e.g.
 * https://thehybit.com/category/news/ethereum-news/
 *
 * For the "all articles" link only. Articles themselves are never scraped from
 * this page — they come from the REST API or WP_Query below.
 */
export function categoryUrlFor(coin) {
  const slug = coin?.newsCategorySlug;
  return slug ? `${NEWS_CATEGORY_BASE}/${slug}/` : null;
}

/**
 * Describe how to fetch a coin's related articles.
 *
 * Returns a descriptor rather than a URL string so the caller chooses how to
 * run it. Two paths are given because the two WordPress interfaces differ in
 * an important way:
 *
 *   wpQuery  — one step. WP_Query accepts `category_name` (a SLUG) directly.
 *              This is the better production path: no round trip, and the
 *              articles land in the initial HTML.
 *
 *   rest     — TWO steps. The REST `/wp/v2/posts` endpoint filters by category
 *              ID, not slug; there is no `categories_slug` parameter in core.
 *              So the slug must first be resolved to an id via
 *              `/wp/v2/categories?slug=…`. That resolution belongs here, on the
 *              data side — the news component must never know an id exists.
 *
 * @param {object} coin    must carry `newsCategorySlug`
 * @param {object} [opts]
 * @param {number} [opts.limit=4]
 */
export function newsQueryFor(coin, { limit = 4 } = {}) {
  const slug = coin?.newsCategorySlug;
  if (!slug) return null;

  return {
    categorySlug: slug,
    categoryUrl: categoryUrlFor(coin),

    rest: {
      /* Step 1 — slug to id. */
      resolveCategory: {
        endpoint: '/wp-json/wp/v2/categories',
        params: { slug, per_page: 1, _fields: 'id,slug,count' },
      },
      /* Step 2 — posts in that category. `categories` is filled from step 1. */
      posts: {
        endpoint: '/wp-json/wp/v2/posts',
        params: {
          categories: null, // ← resolveCategoryId() supplies this
          per_page: limit,
          orderby: 'date',
          order: 'desc',
          status: 'publish',
          _embed: 1,        // without this there are no images and no categories
        },
      },
    },

    /* Server-side equivalent, in one step. */
    wpQuery: {
      category_name: slug,
      posts_per_page: limit,
      orderby: 'date',
      order: 'DESC',
      post_status: 'publish',
      ignore_sticky_posts: true,
    },
  };
}

/**
 * Pull the category id out of a `/wp/v2/categories?slug=…` response.
 *
 * An unknown slug yields `null` — a typo'd or not-yet-created category must
 * result in NO news rather than an unfiltered query that would splash every
 * post on the site across a coin page.
 *
 * @param {Array} response
 * @param {string} [slug]  when given, guards against a fuzzy match
 * @returns {number|null}
 */
export function resolveCategoryId(response, slug) {
  if (!Array.isArray(response) || response.length === 0) return null;

  const match = slug
    ? response.find((c) => c?.slug === slug)
    : response[0];

  return typeof match?.id === 'number' ? match.id : null;
}

/**
 * Fill in the resolved id, producing a ready-to-run posts request.
 * Returns null when the category could not be resolved, which the caller must
 * treat as "no articles" — never as "fetch everything".
 */
export function postsRequestFor(query, categoryId) {
  if (!query || typeof categoryId !== 'number') return null;
  return {
    endpoint: query.rest.posts.endpoint,
    params: { ...query.rest.posts.params, categories: categoryId },
  };
}
