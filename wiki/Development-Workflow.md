# Development Workflow

## Where to Edit

- Change one page’s layout or copy: route `content.html`
- Change one page’s server logic: route `index.php`
- Change one page’s client behavior: route-local `{page-name}.js`, included from that page’s `content.html`
- Change shared shell: `template.html` and probably `template_mobile.html`
- Change shared interaction logic: `main.js` for bootstrap/orchestration, or the relevant `/js/*.js` shared system file
- Change shared look: `style.css`
- Change persistence or permissions: relevant PHP code plus data contract

## Safe Change Flow

1. Decide whether the change is content, route logic, shared shell, frontend behavior, or persistence
2. Edit the smallest correct surface
3. If data shape changes, update read path, write path, and defaults
4. If auth/admin behavior changes, enforce it in PHP, not just JS
5. If the feature adds routes, APIs, uploads, or private `/data` files, check `.nginx/fridge.dev` before assuming PHP can see or protect it
6. If the feature adds or changes reusable UI, add a representative sample to `/formatting`
7. Test the target page and at least one unrelated page that shares the shell

## Linting

GitHub Actions runs three lint steps:

- `bash scripts/lint-php.sh`
- `bash scripts/lint-javascript.sh`
- `bash scripts/lint-css.sh`

Custom linting details:

- PHP uses `php -l`
- JavaScript uses `node --check`
- Inline JS in `.html` and `.php` files is syntax-checked too
- CSS uses custom Node scripts that validate standalone CSS, inline `<style>`, and `style=""` attributes

This setup is simple but honestly pretty smart for a repo with lots of inline markup/script/style.

## Gotchas

- Login/logout footer swap depends on exact HTML strings
- Account sessions use the shared-domain `fridg3_session` cookie on production hosts; keep logout clearing both shared-domain and legacy host-only cookies
- `main.js` is route-sensitive; move clearly page-owned code into route-local scripts, and larger shared systems into `/js/*.js`
- Feed and journal have different storage models
- Bookmarks have both server and localStorage behavior
- Some old code still references legacy bookmark storage patterns
- Mobile view is browser-only via the `mobile_friendly_view` cookie
- `.nginx/fridge.dev` is live Nginx config source via symlink, so route changes need Nginx sanity checks too
- Native JS `alert()`, `confirm()`, and `prompt()` are not used; use the on-site popup helpers in `main.js`
- External links are guarded by the shared on-site popup unless a link explicitly opts out with `data-no-external-popup`
- Reusable UI belongs on `/formatting`; if it can reasonably appear on another page, give it a specimen there

## Broad Refactors Checklist

Before making a sweeping change, review:

- Root files: `index.php`, `content.html`, `template.html`, `template_mobile.html`, `main.js`, `style.css`
- Affected route directory
- Related API endpoint
- `lib/render.php`
- `.nginx/fridge.dev`
- Relevant workflow or script if the change affects deploy/lint/runtime ops

## Practical Advice

- Trust code over docs when they conflict
- Prefer boring safe edits over galaxy-brain rewrites
- If you touch shared DOM ids or route transitions, click around the site after
- If you touch `/data` schema, document it immediately so future-you doesn’t get jump-scared
