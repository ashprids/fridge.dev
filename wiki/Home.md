# fridge.dev Developer Wiki

This wiki serves to provide documentation to developers and AI agents developing the website.

These pages are mirrored on the GitHub repository's wiki (https://github.com/ashprids/fridge.dev/wiki) and the website itself (https://fridge.dev/wiki).

When docs and code disagree, trust the code.

## Project Snapshot

`fridge.dev` is a PHP-first, file-backed personal site with a shared HTML shell, route-local content templates, and one big JavaScript layer for navigation and interactive features.

Core traits:

- Most routes are directory-based and use `index.php` + `content.html`
- Rendering is mostly server-side
- `template.html` is the default shell
- `template_mobile.html` is selected when mobile view is enabled
- `main.js` adds SPA-ish navigation, settings, bookmarks, page views, and other client behaviors; Toast's website integration is documented on [Toast](Toast)
- Shared PHP helpers now live in `lib/render.php`, `lib/session.php`, and `lib/feed.php`
- Runtime content lives under `/data` and is intentionally excluded from deployment sync

## Source of Truth

Useful files:

- `README.md`
- `lib/render.php`
- `template.html`
- `template_mobile.html`
- `main.js`
- `style.css`
- `.github/workflows/*`
- `scripts/*`

## Practical Rule

If you need to change:

- One page layout: edit that route’s `content.html`
- One page’s server behavior: edit that route’s `index.php`
- Shared UI: edit `template.html`, `template_mobile.html`, `style.css`, or `main.js`
- Persistence or auth: edit the relevant PHP writer/reader and update the data contract

For moderation terminology and enforcement boundaries, see [Restrictions and Moderation](Restrictions-and-Moderation).
