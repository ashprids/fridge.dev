# API Reference

all API routes live under `/api/*` and are handled by PHP.

## Auth And Account

### `/api/account/is-admin`

- returns `{ isAdmin: boolean }`
- refreshes frontend admin awareness for maintenance-mode bypass logic

### `/api/settings`

`GET`

- requires logged-in user
- returns current settings from `data/accounts/accounts.json`
- currently exposes `theme`, `glowIntensity`, `colors`, `onekoEnabled`, `reduceMotion`, `titleAnimation`, `titleAnimationAlways`, `titleAnimationDesync`, `browserNotificationsEnabled`, and `journalBrowserNotificationsEnabled`; `colors` is honored by `classic` for the full palette and by `ambercrt`/`CRT` for the single `links` phosphor color
- for the hardcoded `toast` session, also returns `toastPersonalityJson`

`POST`

- requires logged-in user
- updates user settings in `accounts.json`
- can set `theme` to `default`, `classic`, or a valid `/themes/*.json` theme id
- can set the reduced-motion accessibility boolean
- can set the validated title animation id plus the always-playing and character-desync booleans
- can set `onekoEnabled` for the optional cursor-following cat
- can set `browserNotificationsEnabled` for account-backed browser feed notification polling
- can set `journalBrowserNotificationsEnabled` for account-backed new journal post browser notifications
- syncs the `theme_pref` cookie so anonymous and first-load rendering can pick the active theme
- validates color fields as `#RRGGBB`; the settings UI sends the full palette for `classic` and only `links` for `CRT`
- admin users can also toggle maintenance mode through the settings flow
- the hardcoded `toast` session can save `toastPersonalityJson` to `data/etc/toast-personality.json`

### `/api/dev-bootstrap`

`POST`

- developer-mode-only route used by `/settings`
- allowed for admin sessions, or for local setups with no admin account yet
- streams newline-delimited JSON progress while it finds the latest sanitized Google Drive developer data zip, downloads it, extracts it, deletes existing local `data/`, and installs the new copy
- progress events may include a `log` field for the settings popup; download logs show byte counts/percentages, and extraction logs include entry counts/percentages

### `/api/themes`

`GET`

- public route
- returns selectable themes, with `default` displayed as `blackprint` before discovered themes
- each valid theme must include `name`, `description`, `thumbnail`, `html`, and `css`
- theme `html` and `css` paths are resolved from `/themes/lib`; picker thumbnails are resolved from `/themes`

### `/api/bookmark`

`POST` only.

- requires logged-in user for server persistence
- supports single toggle via `postId`
- supports full replacement via `bookmarks`
- writes normalized bookmark ids back to `accounts.json`
- bookmark ids currently include raw feed ids and `journal:{id}`; legacy `newsletter:{id}` values may exist but are ignored
- anonymous bookmarking is handled client-side in localStorage instead

## Content / Media

### `/tools/upload/?api=*`

route-local JSON endpoints for the `/tools/upload` peer-to-peer transfer page.

- `POST ?api=create` with `role=sender|receiver` creates a short-lived room and returns `/tools/upload/?r={token}`
- `GET ?api=room&r={token}` claims/loads a room for the creator browser or first guest browser
- `POST ?api=key&r={token}` stores one peer's ephemeral ECDH public key
- `POST ?api=signal&r={token}` stores WebRTC offer/answer/ICE signaling messages
- `GET ?api=signals&r={token}&since={id}` polls signaling messages from the other peer
- `POST ?api=heartbeat&r={token}` keeps the peer's side alive while the tab is open
- `POST ?api=end&r={token}` ends the room when either peer closes the tab
- room access is locked by the HttpOnly `fridg3_upload_peer` browser cookie
- stores only room metadata/signaling in `data/upload/rooms.json`; file contents are sent peer-to-peer and are not written by PHP

### `/tools/mdpaste/`

`POST` JSON payload with `{ markdown, password, hardBreaks }`.

- stores temporary markdown paste records in `data/mdpaste`
- empty passwords create public pastes
- non-empty passwords encrypt the markdown before storage
- `hardBreaks` stores whether single line breaks render as line breaks in formatted paragraphs
- returns `{ ok, id, url, expires_at, encrypted }`
- rejects blank pastes and content over 512 KiB

### `/api/feed-post`

- returns parsed feed post JSON for a supplied `?id=`
- does not expose replies; thread replies are loaded directly by `/feed/posts/{id}` from `data/feed/replies/*.json`

### `/api/feed-notifications`

`GET`, optionally with `guestBrowserId={32 hex chars}` for logged-out browsers.

- returns current browser notification candidates for feed and journal events
- account events mirror Toast Discord feed DMs: post mentions, reply mentions, and replies to the account's own feed posts
- guest events cover replies to guest comments created by the same browser token
- journal events cover new published journal posts for both guests and logged-in users
- clients keep seen notification keys in localStorage, filter event `type`, and use this endpoint for browser Notification API polling

### `/api/toast-feed-generate`

`POST` form payload with `mode=random|prompt`, optional `prompt`, and `length=1..5`.

- hardcoded Toast session only
- reads Groq settings from `data/etc/toast.json`
- reads feed-writing personality from `data/etc/toast-personality.json`
- sends a small weak style sample from already-published non-Toast feed posts, with image BBCode stripped
- generated drafts that have not been posted are not sent as context
- sends recent published Toast posts only as negative examples to avoid repeating
- injects a per-request private freshness seed and creative spark so repeated generations vary more
- prompt mode uses a smaller context window than random mode and retries once with minimal context on oversized requests
- `length` selects one of five generated post profiles: `one-liner`, `short`, `normal`, `ramble`, or `trauma dump`
- generated feed drafts are constrained by the selected length profile so Toast can stay tiny when asked or get much more vulnerable at max
- returns `{ ok: true, content: "generated post body" }`

### `/api/gallery/delete`

- admin-only image deletion from `data/images`
- validates filename/path and allowed image extensions

### `/api/sitemap`

- admin-only sitemap generator
- scans routes and content files
- writes `/sitemap.xml`

## Toast / Stream / Status

### `/api/discord-bot-status`

- reads `data/etc/toast.json`
- returns bot and stream status payload for UI consumers

### `/api/discord-bot-control`

`POST` JSON payload with stream info.

- updates stream URL and name in `data/etc/toast.json`
- writes a stream update signal for downstream consumers

### `/api/discord-bot-control/status`

`POST` JSON payload with bot status.

- updates bot online/offline state in `data/etc/toast.json`

### `/api/stream-proxy`

- same-origin proxy for stream audio playback
- host-restricted based on configured stream host
- used by toast playback UI

## Telemetry / System

### `/api/page-view`

`POST` JSON payload with `{ path }`.

- normalizes route path
- rejects `/api/*` paths
- hashes client IP before storage
- updates `data/etc/page_views.json`
- returns updated page count

### `/api/system/usage`

- returns CPU, memory, and disk usage data
- includes Linux and Windows code paths

## Implementation Notes

- most endpoints return JSON and perform direct file IO
- write-heavy endpoints should be treated carefully because there is no database transaction safety blanket here
- `/api/page-view` already uses file locking, which is the sane move
- some account, contact, and toast integrations also talk to a localhost-only bot HTTP service on `127.0.0.1:8765`, but those are not public `/api/*` routes
- the toast DM inbox uses that local bot service to send manual DMs and toggle per-thread AI reply muting
- contact submissions call `POST /contact/notify` on that local toast service after successful storage so toast can notify the configured Discord channel
- deploys call `POST /patch-notice` on that same local service after a successful `main` deploy so toast can post a fully formatted approval preview in channel `1526075637096255548`; an admin `✅` reaction posts the update embed to channel `1455194403642802309` and pings role `1408064850688475197`
