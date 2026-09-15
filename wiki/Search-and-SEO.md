# Search and SEO

## Search appearance

The homepage title is `fridge.dev`. Its longer metadata description introduces the personal website, music, journal, feed, browser tools, Minecraft archive, and other independent projects. The matching HTML paragraph is hidden and occupies no layout space, so it should not be treated as an indexing signal. The ASCII logo has a named level-one heading role. Decorative ASCII and live server statistics are excluded from search snippets using `data-nosnippet`.

Google chooses sitelinks automatically from its understanding of a site's structure and the search query. No tag, sitemap field, or schema can force the two-column links shown beneath some branded results. Clear titles, useful descriptions, consistent navigation, and crawlable public pages support eligibility. See [Google's sitelink guidance](https://developers.google.com/search/docs/appearance/sitelinks).

## Shared metadata

`lib/seo.php` is called by `apply_preferred_theme_stylesheet()` in `lib/render.php`, before route placeholders are filled. It builds metadata on the server for both mobile and desktop templates and every packaged theme:

- Distinct public-page titles and descriptions, stored in `fridge_seo_page_copy()`
- Canonical URLs on `https://fridge.dev`, independent of the visitor's host
- Open Graph and Twitter cards with absolute URLs, site identity, descriptions, and preview images
- `WebSite`, page, and breadcrumb JSON-LD; journal posts use `BlogPosting` and feed entries use `SocialMediaPosting`
- Content-derived feed snippets and journal descriptions, publication dates when available, and real modification timestamps
- Journal card images or the first Markdown/BBCode image; the site's existing 512px icon is the fallback

No fabricated author, review, rating, or business details are added. The journal format does not record a reliable author, so that optional field is omitted. Article and social markup does not guarantee a rich result. Metadata is escaped and JSON-LD is protected from both script injection and the site's later placeholder substitutions. `syncSeoMetadata()` in `main.js` updates titles, descriptions, canonical links, robots directives, social tags, and structured data after SPA navigation and form responses.

## One host for both layouts

The repository's `.nginx/fridge.dev` config permanently redirects `m.fridge.dev`, `www.fridge.dev`, and HTTP requests to `https://fridge.dev`, preserving the path and query. Redirect status 308 preserves request methods and POST bodies. These redirects take effect when the updated Nginx configuration is deployed and reloaded. Mobile devices receive `template_mobile.html` on the main host on their first request, using user-agent detection. An explicit `mobile_friendly_view` preference takes precedence. Client touch detection handles iPads presenting a desktop user agent without redirecting to a second domain.

Shared-domain cookies survive the host move. Host-specific browser storage, such as localStorage bookmarks and IndexedDB pending uploads, is not automatically transferred from the old mobile origin; already-open mobile tabs should finish uploads before the deployment. The old host still has canonical tags if PHP is served without the updated Nginx redirect. No user-agent-specific crawler redirects or cloaking are used.

Canonical links normalize `/index.php`, trailing-slash aliases, legacy feed `?=id` links, and journal `?post=id` links. Genuine listing page numbers retain their own canonical URLs; wiki document queries retain their identity. Tracking parameters do not create extra canonical URLs.

The shared request canonicalizer permanently redirects browser requests to the
preferred form. It removes `legacy_domain`, collapses listing `page=1`, maps
`wiki?page=Home` to `/wiki/`, adds trailing slashes to public directory pages,
and removes them from individual feed and journal post URLs. Nginx routes
no-slash public aliases through this canonicalizer so several corrections are
combined into one redirect. Internal navigation and pagination should link
directly to these canonical forms.

Redirects, canonical links, and sitemap inclusion reinforce one another. Google still makes the final canonical selection, and existing mobile results disappear only after recrawling. Do not block the old host in robots.txt: crawlers must see its redirect. See [Google's canonicalization guidance](https://developers.google.com/search/docs/crawling-indexing/consolidate-duplicate-urls).

Search Console's **Page with redirect** status is expected for an HTTP URL, a
`www` URL, a no-slash alias, `page=1`, or a URL containing the old-domain
marker. Google indexes the final `200` target instead of every alias. Account,
settings, bookmarks, chats and other private pages remain intentionally
`noindex`; they are not sitemap candidates even if Search Console has
discovered their URLs.

## Indexing policy

Public static URLs are explicitly listed in `lib/sitemap.php`. New routes are excluded until reviewed. Account pages, staff tools, private chats, guest edit/create forms, saved items, notifications, commission submissions, Markdown pastes, internal search results, action/token variants, errors, and development hosts receive `noindex`. Login and authorization rules remain enforced by PHP; robots.txt is not access control.

`robots.txt` advertises only `https://fridge.dev/sitemap.xml`. It avoids crawling API endpoints and private runtime directories while allowing styles, scripts, public images, and pages whose canonical/noindex tags must be read.

Missing journal posts return HTTP 404. The maintenance page returns HTTP 503 with `Retry-After: 600` so it is recognized as temporary downtime.

## Sitemap generation

`fridge_sitemap_xml()` is shared by the admin **regenerate sitemap** button and `sitemap/index.php`. Nginx routes `/sitemap.xml` to the public XML handler, which discovers current published content on each request, with a five-minute public HTTP cache. New posts and deletions therefore do not depend on remembering to press the button. The button still writes a disk snapshot for environments that serve a static sitemap.

The sitemap includes reviewed public sections, published feed `.txt` posts, published journal `.txt` and `.md` posts, and public wiki documents. Numeric journal IDs are preserved; Markdown wins when both formats share an ID. Drafts, invalid journal files, reply records, and wiki sidebar/footer fragments are excluded. Content `lastmod` values use source-file timestamps. Static shells omit `lastmod` rather than pretending a regeneration changes their content. All entries use the same `https://fridge.dev` URLs as the canonical tags.

When introducing an indexable public route, update the public route inventory and its SEO copy together. The current single sitemap is appropriate below the standard 50,000-URL/50-MB limits; split it into sitemap files and an index before reaching those limits.

## After deployment

1. Validate and reload Nginx through the normal deployment workflow. Verify an old mobile URL redirects to the equivalent main-domain page with no loop.
2. Verify a Domain property for `fridge.dev` in [Google Search Console](https://search.google.com/search-console). Domain verification needs control of DNS; do not invent a verification token.
3. Submit `https://fridge.dev/sitemap.xml` in Search Console and Bing Webmaster Tools.
4. Inspect the homepage, a feed post, and a journal post. Request recrawling of the updated homepage; check Google's selected canonical and mobile rendering.
5. Validate markup with Google's Rich Results Test and Schema.org's validator. The site-name `WebSite` markup is not itself a Rich Results Test feature.
6. Watch indexing reports, query impressions, title/snippet appearance, and real-user performance. Search engines may rewrite titles/descriptions and cannot be given a guaranteed position or sitelink layout.

No Search Console verification, search-engine submission, or production deployment is performed by the code changes alone.

## Checks

- `php scripts/test-seo.php`
- `php scripts/test-sitemap.php`
- `php scripts/test-seo-render.php`
- PHP/JavaScript/CSS repository lint scripts

These cover canonical aliases, pagination, guest-only indexing policy, Unicode snippets, injection-safe structured data, published content, mobile template selection, and rendered metadata across themes.
