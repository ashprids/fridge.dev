# Development Workflow

## Where To Edit

- change one page’s layout or copy: route `content.html`
- change one page’s server logic: route `index.php`
- change one page’s client behavior: route-local `{page-name}.js`, included from that page’s `content.html`
- change shared shell: `template.html` and probably `template_mobile.html`
- change shared interaction logic: `main.js` for bootstrap/orchestration, or the relevant `/js/*.js` shared system file
- change shared look: `style.css`
- change persistence or permissions: relevant PHP code plus data contract

## Safe Change Flow

1. decide whether the change is content, route logic, shared shell, frontend behavior, or persistence
2. edit the smallest correct surface
3. if data shape changes, update read path, write path, and defaults
4. if auth/admin behavior changes, enforce it in PHP, not just JS
5. if the feature adds routes, APIs, uploads, or private `/data` files, check `.nginx/fridg3.org` before assuming PHP can see or protect it
6. if the feature adds or changes reusable UI, add a representative sample to `/formatting`
7. test the target page and at least one unrelated page that shares the shell

## Linting

GitHub Actions runs three lint steps:

- `bash scripts/lint-php.sh`
- `bash scripts/lint-javascript.sh`
- `bash scripts/lint-css.sh`

custom linting details:

- PHP uses `php -l`
- JavaScript uses `node --check`
- inline JS in `.html` and `.php` files is syntax-checked too
- CSS uses custom Node scripts that validate standalone CSS, inline `<style>`, and `style=""` attributes

this setup is simple but honestly pretty smart for a repo with lots of inline markup/script/style.

## Gotchas

- login/logout footer swap depends on exact HTML strings
- `main.js` is route-sensitive; move clearly page-owned code into route-local scripts, and larger shared systems into `/js/*.js`
- feed and journal have different storage models
- bookmarks have both server and localStorage behavior
- some old code still references legacy bookmark storage patterns
- mobile view is browser-only via the `mobile_friendly_view` cookie
- `.nginx/fridg3.org` is live nginx config source via symlink, so route changes need nginx sanity checks too
- native JS `alert()`, `confirm()`, and `prompt()` are not used; use the on-site popup helpers in `main.js`
- external links are guarded by the shared on-site popup unless a link explicitly opts out with `data-no-external-popup`
- reusable UI belongs on `/formatting`; if it can reasonably appear on another page, give it a specimen there

## Broad Refactors Checklist

before making a sweeping change, review:

- root files: `index.php`, `content.html`, `template.html`, `template_mobile.html`, `main.js`, `style.css`
- affected route directory
- related API endpoint
- `lib/render.php`
- `.nginx/fridg3.org`
- relevant workflow or script if the change affects deploy/lint/runtime ops

## Practical Advice

- trust code over docs when they conflict
- prefer boring safe edits over galaxy-brain rewrites
- if you touch shared DOM ids or route transitions, click around the site after
- if you touch `/data` schema, document it immediately so future-you doesn’t get jump-scared
