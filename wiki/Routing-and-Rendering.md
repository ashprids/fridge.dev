# Routing and Rendering

## Route Structure

most pages are folder routes:

- `/feed` -> `feed/index.php` + `feed/content.html`
- `/journal/posts/12` -> `journal/posts/index.php`
- `/settings` -> `settings/index.php` + `settings/content.html`

error routes are the main exception:

- `error/403/index.html`
- `error/404/index.php`
- `error/50x/index.html`
- `error/wip/index.php`

production nginx redirects unknown paths to `/error/404` instead of falling back to the homepage.

## Upward File Lookup

most PHP routes define a local `find_template_file()` helper that walks up parent directories until it finds a requested file.

that means nested routes can still find:

- `template.html`
- `template_mobile.html`
- `content.html`
- `lib/render.php`
- root-level assets or data paths

admin account pages use equivalent helpers in `account/admin/helpers.php`.

## Standard Render Flow

typical route flow:

1. start session through `fridg3_start_session()` from `lib/session.php`
2. optionally enforce auth/admin checks
3. load render helper from `lib/render.php`
4. choose template with `get_preferred_template_name(__DIR__)`
5. load local `content.html`
6. inject placeholders like `{content}`, `{title}`, `{description}`, `{user_greeting}`
7. optionally swap account footer button to logout when logged in

theme selection also runs through `lib/render.php`. `default` is blackprint and uses the base template/style. desktop requests for selectable themes can use a theme HTML template from `/themes/lib`; mobile requests always keep `template_mobile.html` and append the selected theme CSS after the mobile inline styles. legacy saved values are normalized (`blackprint` to `default`, `custom` to `classic`, `newsprint` to `whiteprint`).

some routes also pull in extra shared libs like `lib/feed.php` for route-specific persistence helpers instead of keeping all that logic inline.

## Homepage Special Case

root `index.php` is more dynamic than the wrapper routes.

it injects:

- latest feed post from `data/feed/*.txt`
- latest journal post from `data/journal/*.txt`
- up to 3 music cards from `data/music/frdg3/*.json`

it also contains older bookmark-loading logic that still points at `/data/users`, which is legacy behavior and worth keeping an eye on.

## Wrapper Routes vs Data Routes

wrapper routes are mostly just shell + static content:

- `discord/`
- `merch/`
- parts of `others/`

data-backed routes do real work:

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

maintenance mode is driven by `data/etc/wip`.

`lib/session.php` enforces it during PHP session startup, and `lib/render.php` enforces it again during PHP rendering:

- reads the flag from `data/etc/wip`
- redirects non-admins to `/error/wip` before page content renders or mutating POST handlers continue
- allows `/account/login` and `/error/wip`
- shows the maintenance banner from the server-rendered template through `lib/render.php`
- redirects `/error/wip` back to `/` when maintenance mode is off
- `/error/wip/wip.js` polls the flag while the WIP page is open and redirects home after maintenance ends

the admin bypass uses the server session user `isAdmin` flag, so it still works when JavaScript is disabled.

## Local Dev Mode

`lib/render.php` treats `localhost`, `127.x.x.x`, `0.0.0.0`, `::1`, `*.localhost`, `*.test`, or truthy `FRIDG3_DEV_MODE` as local development. Local renders inject a sidebar `dev mode` banner beside the maintenance banner.

## Page View Counting

page views are not baked in by PHP. the footer view count is hydrated by `main.js`, which posts the current path to `/api/page-view`.
