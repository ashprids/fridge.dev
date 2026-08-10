# API Reference

All API routes live under `/api/*` and are handled by PHP.

## Auth and Account

### `/api/account/is-admin`

- Returns `{ isAdmin: boolean }`
- Refreshes frontend admin awareness for maintenance-mode bypass logic

### `/api/settings`

`GET`

- Requires logged-in user
- Returns current settings from `data/accounts/accounts.json`
- Currently exposes `theme`, `glowIntensity`, `colors`, `onekoEnabled`, `reduceMotion`, `titleAnimation`, `titleAnimationAlways`, and `titleAnimationDesync`; `colors` is honored by `classic` for the full palette and by `ambercrt`/`CRT` for the single `links` phosphor color
- Toast-only settings fields are documented on [Toast](Toast#personality-sources)

`POST`

- Requires logged-in user
- Updates user settings in `accounts.json`
- Can set `theme` to `default`, `classic`, or a valid `/themes/*.json` theme id
- Can set the reduced-motion accessibility boolean
- Can set the validated title animation id plus the always-playing and character-desync booleans
- Can set `onekoEnabled` for the optional cursor-following cat
- Syncs the `theme_pref` cookie so anonymous and first-load rendering can pick the active theme
- Validates color fields as `#RRGGBB`; the settings UI sends the full palette for `classic` and only `links` for `CRT`
- Admin users can also toggle maintenance mode through the settings flow
- Toast-only personality persistence is documented on [Toast](Toast#personality-sources)

### `/api/dev-bootstrap`

`POST`

- Developer-mode-only route used by `/settings`
- Allowed for admin sessions, or for local setups with no admin account yet
- Streams newline-delimited JSON progress while it finds the latest sanitized Google Drive developer data zip, downloads it, extracts it, deletes existing local `data/`, and installs the new copy
- Progress events may include a `log` field for the settings popup; download logs show byte counts/percentages, and extraction logs include entry counts/percentages
- Bootstrap-stream diagnostics and their sanitization rules are documented on [Debug Mode](Debug-Mode#bootstrap-diagnostics)

### `/api/themes`

`GET`

- Public route
- Returns selectable themes, with `default` displayed as `blackprint` before discovered themes
- Each valid theme must include `name`, `description`, `thumbnail`, `html`, and `css`
- Theme `html` and `css` paths are resolved from `/themes/lib`; picker thumbnails are resolved from `/themes`

### `/api/bookmark`

`POST` only.

- Requires logged-in user for server persistence
- Supports single toggle via `postId`
- Supports full replacement via `bookmarks`
- Writes normalized bookmark ids back to `accounts.json`
- Bookmark ids currently include raw feed ids and `journal:{id}`; legacy `newsletter:{id}` values may exist but are ignored
- Anonymous bookmarking is handled client-side in localStorage instead

## Content / Media

### `/tools/upload/?api=*`

Route-local JSON endpoints for the `/tools/upload` peer-to-peer transfer page, displayed as `serverless upload`.

- `POST ?api=create` with `role=sender|receiver` creates a short-lived room and returns `/tools/upload/?r={token}`
- `GET ?api=room&r={token}` claims/loads a room for the creator browser or first guest browser
- `POST ?api=key&r={token}` stores one peer's ephemeral ECDH public key
- `POST ?api=signal&r={token}` stores WebRTC offer/answer/ICE signaling messages
- `GET ?api=signals&r={token}&since={id}` polls signaling messages from the other peer
- `POST ?api=heartbeat&r={token}` keeps the peer's side alive while the tab is open
- `POST ?api=end&r={token}` ends the room when either peer closes the tab
- Room access is locked by the HttpOnly `fridg3_upload_peer` browser cookie
- Stores only room metadata/signaling in `data/upload/rooms.json`; file contents are sent peer-to-peer and are not written by PHP

### `/tools/mdpaste/`

`POST` JSON payload with `{ markdown, password, hardBreaks }`.

- Stores temporary markdown paste records in `data/mdpaste`
- Empty passwords create public pastes
- Non-empty passwords encrypt the markdown before storage
- `hardBreaks` stores whether single line breaks render as line breaks in formatted paragraphs
- Returns `{ ok, id, url, expires_at, encrypted }`
- Rejects blank pastes and content over 512 KiB

### `/api/feed-post`

- Returns parsed feed post JSON for a supplied `?id=`
- Does not expose replies; thread replies are loaded directly by `/feed/posts/{id}` from `data/feed/replies/*.json`

### `/api/feed-notifications`

`GET ?view=inbox`, optionally with `guestBrowserId={32 hex chars}` for logged-out browsers.

- Returns the current in-site inbox; requests without `view=inbox` are rejected because browser Notification API delivery is not supported
- Account notification event parity with Discord is documented on [Toast](Toast#notifications-and-website-integration)
- Guest events cover replies to guest comments created by the same browser token
- `GET ?view=inbox&page=N` returns the notification-page view with 10 events per page, total/page metadata, read flags, the overall unread count, and the active account CSRF token needed when a global notification toast is clicked
- Inbox events include `actor`, `actorIsGuest`, and `action` fields so clients can distinguish `@registered` account names from italic guest display names without parsing presentation text
- Each event exposes a plain-text `body` with Markdown/BBCode syntax removed for temporary alerts and a server-sanitized `bodyHtml` rendered with the content's original feed format for `/notifications`
- `POST view=inbox` accepts JSON-encoded `keys` to mark clicked notifications read, or `markAll=1` to mark every currently relevant inbox event read across all pages; account requests require the session CSRF token, while guests are scoped by their browser token
- The same inbox POST accepts `dismiss=1` with `keys` to persistently remove selected notifications, or `dismissAll=1` to remove every current event for that account or guest-browser identity

### `/api/notification-revision`

- Returns one opaque notification revision value without starting a session or generating inbox content
- Visible tabs check it every 10 seconds and request `/api/feed-notifications?view=inbox` only when it changes; hidden tabs stop checking and perform one immediate check when shown again
- This short request releases PHP-FPM immediately instead of reserving one worker per visitor with a long-lived stream

### `/api/feed-usernames`

`GET` returns the sorted public registered-username list used by Feed mention autocomplete. It exposes usernames only and no private account fields.

### Toast Feed Generation

The Toast-only feed generation API is documented on [Toast](Toast#ai-feed-posts).

### `/api/gallery/delete`

- Admin-only image deletion from `data/images`
- Validates filename/path and allowed image extensions

### `/api/sitemap`

- Admin-only sitemap generator
- Scans routes and content files
- Writes `/sitemap.xml`
- Writes a two-line XML comment immediately after the declaration containing `This sitemap was automatically generated.` and the generation time in `DD/MM/YY HH:MM:SS` format

## Toast APIs

Toast feed generation, status, stream control, playback proxy, and localhost service integrations are documented on [Toast](Toast#discord-service).

## Telemetry / System

### `/api/page-view`

`POST` JSON payload with `{ path }`.

- Normalizes route path
- Rejects `/api/*` paths
- Hashes client IP before storage
- Updates `data/etc/page_views.json`
- Returns updated page count

### `/api/system/usage`

- Returns CPU, memory, and disk usage data
- Includes Linux and Windows code paths

## Implementation Notes

- Most endpoints return JSON and perform direct file IO
- Write-heavy endpoints should be treated carefully because there is no database transaction safety blanket here
- `/api/page-view` already uses file locking, which is the sane move
- Toast's localhost-only endpoints and integrations are documented on [Toast](Toast#local-service-and-production-operation)
