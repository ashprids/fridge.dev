# Architecture

## High-Level Shape

The site is a classic folder-routed PHP app with a shared shell and file-based storage.

Usual pattern:

1. Bootstrap shared session handling via `lib/session.php`
2. Set `$title` and `$description`
3. Locate the preferred template
4. Load route-local `content.html`
5. Inject dynamic placeholders
6. Echo final HTML

## Shared Building Blocks

- `template.html`
  Default desktop shell with sidebar, footer, placeholders, and global asset includes
- `template_mobile.html`
  Alternate shell for mobile-friendly view
- `lib/render.php`
  Shared helpers for upward file lookup and mobile template selection
- `lib/session.php`
  Shared session bootstrap, persistent cookie config, `mustResetPassword` enforcement, and admin-cookie refresh helper
- `lib/feed.php`
  Feed-specific helpers for reply persistence, permission checks, datetime formatting, and inline image upload replacement
- `main.js`
  Shared client behavior layer
- `style.css`
  Global styling and component rules

## Template Selection

Mobile/desktop template choice is centralized in `lib/render.php`.

Mobile mode is enabled when any of these are true:

- Host is `m.fridge.dev`
- Cookie `mobile_friendly_view` is truthy

The `mobile_friendly_view` preference is browser-only and is not stored in account JSON.

Production may switch between `fridge.dev` and `m.fridge.dev` to match the active layout. Developer mode never performs that host redirect: enabling mobile view sets the same cookie and reloads the current local URL, allowing `template_mobile.html` to render on the development host.

Developer mode is detected for `localhost`, loopback addresses, `.localhost` and `.test` hostnames, RFC1918 private IPv4 addresses (`10/8`, `172.16/12`, and `192.168/16`), IPv4 link-local addresses (`169.254/16`), and IPv6 unique-local or link-local addresses. This allows another device on the same LAN to use the development features when opening a server such as `http://192.168.1.20:8000`.

If the mobile template is requested but missing, routes fall back to `template.html`.

## Session and Auth Model

- Logged-in state lives in `$_SESSION['user']`
- Frontend admin awareness uses a non-HttpOnly `is_admin` cookie
- Backend authorization is still done in PHP, which is correct and non-cursed

Common session fields:

- `username`
- `name`
- `isAdmin`
- `mustResetPassword`
- `allowedPages`

## Persistence Model

There is no database. The app reads and writes JSON, TXT, HTML, and media files under `/data`.

Main stores:

- `data/accounts/accounts.json`
- `data/accounts/login_attempts.json`
- `data/feed/*.txt`
- `data/feed/replies/*.json`
- `data/journal/*.txt`
- `data/journal/drafts/*.txt`
- `data/chat/*.json`
- `data/contact/*.json`
- `data/guestbook/*.txt`
- `data/etc/*.json`

## Important Couplings

- Many routes still do literal string replacement on the footer account button to swap login/logout
- `main.js` assumes certain DOM ids exist across templates
- Bookmarks are stored in `accounts.json`, but some old code paths still reference a legacy `/data/users` pattern
- Contact submissions are private runtime JSON under `data/contact/` and must never be web-served directly
- Toast's cross-system role and service boundaries are documented on [Toast](Toast)
- Page views are updated from shared frontend flow, so nav changes can silently break view counts
