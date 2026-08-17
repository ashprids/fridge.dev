# Routing and Rendering

## Route Structure

Most pages are folder routes:

- `/feed` -> `feed/index.php` + `feed/content.html`
- `/journal/posts/12` -> `journal/posts/index.php`
- `/settings` -> `settings/index.php` + `settings/content.html`

Error routes are the main exception:

- `error/403/index.html`
- `error/404/index.php`
- `error/50x/index.html`
- `error/wip/index.php`

Production Nginx internally renders `/error/404/index.php` for unknown paths instead of falling back to the homepage. The original requested URL stays visible and receives the `404` response. Root `index.php` also rejects non-homepage request paths as a fallback guard against stale webserver configuration.

## Upward File Lookup

Most PHP routes define a local `find_template_file()` helper that walks up parent directories until it finds a requested file.

That means nested routes can still find:

- `template.html`
- `template_mobile.html`
- `content.html`
- `lib/render.php`
- Root-level assets or data paths

Admin account pages use equivalent helpers in `account/admin/helpers.php`.

## Standard Render Flow

Typical route flow:

1. Start session through `fridg3_start_session()` from `lib/session.php`
2. Optionally enforce auth/admin checks
3. Load render helper from `lib/render.php`
4. Choose template with `get_preferred_template_name(__DIR__)`
5. Load local `content.html`
6. Inject placeholders like `{content}`, `{title}`, `{description}`, `{user_greeting}`
7. Optionally swap account footer button to logout when logged in

Theme selection also runs through `lib/render.php`. `default` is Blackprint and uses the base template/style. Desktop requests for selectable themes can use a theme HTML template from `/themes/lib`; mobile requests always keep `template_mobile.html` and append the selected theme CSS after the mobile inline styles. Legacy saved values are normalized (`blackprint` to `default`, `custom` to `classic`, `newsprint` to `whiteprint`).

Some routes also pull in extra shared libs like `lib/feed.php` for route-specific persistence helpers instead of keeping all that logic inline.

`lib/session.php` uses the configured PHP session save path when it is writable. If that path is missing or unwritable, it falls back to a per-site, per-Unix-user directory under the system temp directory. This avoids production logins failing because a shared fallback directory was created by `deploy` while PHP-FPM runs as `http`.

## Homepage Special Case

Root `index.php` is more dynamic than the wrapper routes.

It injects:

- Latest feed post from `data/feed/*.txt`
- Latest journal post from `data/journal/*.txt`
- Up to 3 music cards from `data/music/frdg3/*.json`

It also contains older bookmark-loading logic that still points at `/data/users`, which is legacy behavior and worth keeping an eye on.

## Wrapper Routes vs Data Routes

Wrapper routes are mostly just shell + static content:

- `discord/`
- `merch/`
- Parts of `others/`

Data-backed routes do real work:

- `feed/`
- `journal/`
- `guestbook/`
- `bookmarks/`
- `contact/`
- `music/`
- `gallery/`
- `account/*`
- `api/*`

## WIP / Maintenance Behavior

Maintenance mode is driven by `data/etc/wip`.

`lib/session.php` enforces it during PHP session startup, and `lib/render.php` enforces it again during PHP rendering:

- Reads the flag from `data/etc/wip`
- Redirects non-admins to `/error/wip` before page content renders or mutating POST handlers continue
- Allows `/account/login` and `/error/wip`
- Renders `/account/login` through `error/wip/template.html` while maintenance is active, and only creates sessions for admin accounts; valid non-admin login attempts are rejected with an in-site popup
- Shows the maintenance banner from the server-rendered template through `lib/render.php`
- Redirects `/error/wip` back to `/` when maintenance mode is off
- `/error/wip/wip.js` polls the flag while the WIP page is open and redirects home after maintenance ends

The admin bypass uses the server session user `isAdmin` flag, so it still works when JavaScript is disabled.

## Local Dev Mode

`lib/render.php` treats `localhost`, `127.x.x.x`, `0.0.0.0`, `::1`, `*.localhost`, `*.test`, or truthy `FRIDG3_DEV_MODE` as local development. Local renders prefix the document title with `[DEV] ` and inject a sidebar `dev mode` banner beside the maintenance banner, with a tooltip explaining that localhost-triggered developer mode can differ from production/server behavior and pointing developers to `/settings` for options. The prefix is applied idempotently before each route replaces its `{title}` placeholder, so full loads and SPA navigation produce titles such as `[DEV] feed | fridge.dev` without duplication.

## Page View Counting

Page views are not baked in by PHP. The footer view count is hydrated by `main.js`, which posts the current path to `/api/page-view`.
