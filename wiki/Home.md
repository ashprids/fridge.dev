# fridge.dev Developer Wiki

this wiki serves to provide documentation to developers and AI agents developing the website.

these pages are mirrored on the GitHub repository's wiki (https://github.com/ashprids/fridge.dev/wiki) and the website itself (https://fridge.dev/wiki).

when docs and code disagree, trust the code.

## Project Snapshot

`fridge.dev` is a PHP-first, file-backed personal site with a shared HTML shell, route-local content templates, and one big JavaScript layer for navigation and interactive features.

Core traits:

- most routes are directory-based and use `index.php` + `content.html`
- rendering is mostly server-side
- `template.html` is the default shell
- `template_mobile.html` is selected when mobile view is enabled
- `main.js` adds SPA-ish navigation, settings, bookmarks, toast bot UI, page views, and other client behaviors
- shared PHP helpers now live in `lib/render.php`, `lib/session.php`, and `lib/feed.php`
- runtime content lives under `/data` and is intentionally excluded from deployment sync

## Source Of Truth

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

- one page layout: edit that route’s `content.html`
- one page’s server behavior: edit that route’s `index.php`
- shared UI: edit `template.html`, `template_mobile.html`, `style.css`, or `main.js`
- persistence or auth: edit the relevant PHP writer/reader and update the data contract

For moderation terminology and enforcement boundaries, see [Account Restrictions and IP Bans](Account-Restrictions-and-IP-Bans).
