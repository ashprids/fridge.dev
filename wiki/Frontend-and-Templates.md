# Frontend and Templates

## Shared Templates

### `template.html`

desktop shell with:

- sidebar navigation
- footer buttons
- `{user_greeting}` placeholder
- `{content}` placeholder
- maintenance banner
- local dev-mode banner injected by `lib/render.php`; when the current development client is hard-banned, the same render pass adds a red hard-ban warning beneath it for testing
- mini player markup
- page view footer

`lib/render.php` post-processes the shared shell for logged-in users whose account has a valid `emailAddress`: the footer Discord icon button is replaced with an email button linking to `/account/email`, and route renderers separately keep swapping the account button to logout where that behavior exists.

### `template_mobile.html`

mobile shell with:

- responsive header/nav grid
- adjusted sidebar/content layout
- shared placeholders from the desktop shell
- explicit stylesheet cache-busting query string on `/style.css`

this is not just a tiny CSS tweak. it is a separate HTML shell, so shared structural edits usually need to be mirrored in both templates.

## Global Frontend Script

`main.js` is the site-wide bootstrap layer. keep it for shared shell basics and cross-page orchestration:

- dev-mode display is server-rendered when the host looks local; if the expected developer data copy is missing, `lib/render.php` also injects a small runtime payload so `main.js` can show a one-per-session popup pointing to `/settings`
- SPA-ish navigation and route transitions
- page view footer updates
- ASCII time / usage widgets
- on-site popup notices, confirmations, and text prompts

translation: if you change shared ids, buttons, or route transitions, test more than one page or you will summon weird bugs.

larger shared frontend systems live in `/js/`, loaded by both desktop and mobile templates after `/main.js`:

- `/js/settings.js`: themes, settings page behavior, glow, mobile-view cookie syncing, oneko, browser notification preferences, and tooltips
- desktop-only debug mode is an accessibility preference. it opens a fixed right-side overlay with compact `client`, `server`, and `access` tabs without changing page layout. its complete left edge is a pointer-draggable resize gutter, with Left/Right Arrow keyboard resizing when focused, and the chosen width persists locally. `server` is disabled for non-admins with its security tooltip, while `access` is omitted from their UI entirely. each debug-log entry is prefixed with a local `[HH:MM:SS]` timestamp. use `window.fridg3DebugClientLog(value)` for client messages and `fridg3_debug_log($value)` after loading `lib/render.php` for server messages. the client tab provides `settings`, `network`, `warnings`, and `errors` filters; admins additionally receive `loaded`, `process`, `warnings`, and `errors` filters in the server tab. each tab has a session-persistent search field beneath its controls that shows only entries containing the complete case-insensitive search term and highlights each match. an icon-only clear control at the far right of every filter row asks for confirmation before clearing that tab; access clears its stored JSON through an admin-only request, while client and server clear their retained browser histories. `loaded` filters per-file `[PHP] loaded ...` entries plus PHP request-initialized/completed lifecycle entries; `process` initially loads the most recent 64 KB and then polls the server-wide PHP/FastCGI log, prefixes those entries with `[PROCESS]`, and reports the selected source. source discovery checks `FRIDG3_PHP_PROCESS_LOG`, PHP's file-backed `error_log`, PHP-FPM logs, Nginx's `/var/log/nginx/error.log`, then Apache's development error log
- first-party JavaScript logs high-signal lifecycle, sanitized fetch paths/statuses, console warnings/errors, network state, page visibility, persistence, media, and user-operation outcomes through `window.fridg3DebugClientLog()`. uploads record only their method, path, and status under `[upload]`, never payload contents. uncaught errors and unhandled promise rejections are recorded without stack traces. the entire debug runtime stays dormant while debug mode is disabled: it does not construct the panel, probe process logs, attach diagnostic listeners, wrap fetch/console, or accumulate entries. timestamps render grey and bracketed source tags render light grey. errors and 4xx/5xx network responses render red, warnings and 3xx responses render yellow-orange, and successful operations plus 2xx responses render green. completed network entries use the compact `[network] METHOD /path CODE` format. the JS tab has `settings` and `network` filters (with shared sidebar initialization classified as settings and uploads classified as network), while the PHP tab has a `loaded` filter for per-file/request-lifecycle messages and a `process` filter that pauses process polling; disabling a filter hides its retained entries and re-enabling it restores them. client, server, and access outputs stay pinned to new entries only while already near the bottom; polling, new logs, searches, and filter rerenders preserve the current scroll position when the user has scrolled upward. if the user has an active text selection inside a log, any DOM-rebuilding update is coalesced and deferred until that selection is cleared, while append-only updates continue safely. each client/server tab retains its latest 1,000 entries in session storage across refreshes and destructive actions, and restores the previously selected tab after admin verification; keep entries concise and never include credentials, query strings, tokens, private message/post bodies, upload contents, or per-frame/per-keystroke noise
- `lib/debug.php` provides non-process PHP logging across every first-party PHP include graph. it records the sanitized request method/path, each repository PHP file loaded by the request, PHP warnings/notices/errors, fatal shutdown errors, and the final HTTP status. `fridg3_debug_log($value)` adds route-specific entries; admin rendered pages receive an embedded JSON payload, while admin JSON fetch responses use the `X-Fridg3-Debug-Logs` header imported by the debug-only fetch wrapper. non-admin responses never receive either server-log transport, and their server tab remains locked with a security tooltip. `/formatting/example_page` contains the canonical route-specific example. never log request bodies, credentials, tokens, cookies, private content, upload contents, or raw IP addresses
- PHP `POST`, `PUT`, and `PATCH` requests emit sanitized `[SUBMISSION]` lifecycle records with route, content length, attachment count, final HTTP status, and authenticated username/guest state. every `$_FILES` tree is inspected generically, including nested/multiple fields, and emits `[ATTACHMENT]` metadata containing only field slot, declared MIME type, byte count, and PHP upload error code. feed and journal media/voice processors additionally report validated saved/rejected counts and saved type/size; music reports cover, track, rollback, and release-metadata outcomes; feed, journal, and guestbook saves report success or failure. original filenames, post/message bodies, attachment contents, temporary paths, destination names, tokens, and credentials must never enter these logs. sanitized events from admin submissions that redirect are carried through the session and imported into the next rendered server log so redirect responses do not discard them
- the shared PHP shutdown runtime maintains a permission-restricted JSON array of the latest 10,000 page accesses in `data/etc/access.json`. it records only non-API requests whose executing script is `index.php` and which represent a direct top-level document navigation. browser document requests are identified with Fetch Metadata, while the site's SPA page loads carry the explicit `X-Fridg3-Page-Navigation` header; image, media, script, stylesheet, attachment, polling, API, and other subresource requests are excluded. older browsers fall back to non-XHR requests which accept HTML. all `/chat` and `/error` paths are excluded from both new records and returned legacy records; missing-page requests keep their original path and are logged once with status `404`. stored fields are UTC time, the Nginx-resolved visitor IP, path without its query string, HTTP status, session username when available, and the request-time guest/user/admin role; request methods are not stored. production Nginx replaces Cloudflare's edge address with `CF-Connecting-IP` only for connections from Cloudflare's published trusted networks. paths are canonicalized without a trailing slash (except `/`). records are compacted per IP/username visitor, so revisits and refreshes of that visitor's current canonical page are omitted until they visit a different page; read-time compaction applies this rule to legacy duplicates too. `/api/debug-access-logs` is admin-only, does not log its own polling requests, derives roles for legacy records from the current accounts data, and evaluates each unique logged IP against the current manual and source hard-ban lists. selecting the access tab fetches immediately and polls once per second for new entries; switching to another tab stops polling, and a response that finishes after the tab closes is not rendered. the access view starts at the bottom and provides `guests`, `users`, `admins`, and additive `hard-banned` filters. it displays `[time] [IP] [@username] [HTTP code] page`, omitting the username group for guests. the IP text links directly to that address on whatismyipaddress.com in a new tab, bypasses the general external-link confirmation, retains its normal grey or hard-ban red colour, and turns blue on hover. only the IP text—not its brackets—turns red when hard-banned; only the text inside username brackets is yellow, and status codes use green for 2xx, yellow-orange for 3xx, and red for 4xx/5xx. the development-data archive explicitly excludes this sensitive file
- `/js/sidebar-player.js`: sidebar visibility, mini player, footer/account state, active sidebar/footer buttons, and Toast listen-along playback support

client performance notes: templates preload only the IBM VGA font and the regular Iosevka face needed by initial content; do not re-add the 400KB+ Bold Italic preload unless it becomes render-critical again. title-animation settling samples active letter transforms every 80ms rather than every animation frame and skips computed-style reads while the document is hidden, preserving smooth exits without continuous high-frequency layout/style queries.
- feed and journal use compact windowed pagination; Blackprint keeps its frame and 30×30 controls square, while every selectable theme supplies its own pager surface, corner treatment, and shadow/glow styling without changing the single-line layout
- `/js/bookmarks.js`: bookmark/save icons, anonymous bookmark storage, image modal behavior, and `/bookmarks` hydration
- `/js/bbcode.js`: BBCode editor, attach-media URL/upload flow for images, audio, and video, inline media players, voice notes, feed generator, `parseBBCode`, and plain-link video embeds for YouTube, Vimeo, and Dailymotion; right-clicking a queued image in feed or journal preview opens a crop action with a drag-selection editor that replaces the pending upload; queued editor files are appended directly when `main.js` builds an SPA submission payload, and automatic video conversion examines only top-level text so links inside BBCode-rendered elements are not embedded

page-specific behavior belongs in a route-local `{page-name}.js` file and the page's `content.html` should include that script. examples:

- `/music/upload` uses `/music/upload/upload.js`
- `/others/toast-discord-bot` uses `/others/toast-discord-bot/toast-discord-bot.js`
- `/others/off-topic-archive` uses `/others/off-topic-archive/off-topic-archive.js`

if several pages in the same route family need the same code, put one script at the highest shared route directory and reference that script from each page instead of duplicating it. keep genuinely cross-page helpers in `main.js`.

the mini player is shared chrome. `/music` album cards should open the site popup track picker and send the chosen song into the mini player; do not rebuild album track lists inside the sidebar player.

## Popups And External Links

use the on-site popup helpers in `main.js`, not native browser `alert()`, `confirm()`, or `prompt()`. submitting a new feed or journal post shows a non-dismissible, buttonless `please wait...` popup with `uploading post...` body text until the SPA request finishes; journal draft saves do not show it.

- notices: `showSiteNotice(title, detail)`
- confirmations: `showSitePopup({ title, detail/html, okText, cancelText })`
- text input: `showSitePrompt(title, detail, value)`

The shared renderer injects the active site notice for either logged-in users or guests. Dismissible banners and popup acknowledgements are stored in browser local storage by notice revision; saving a notice from `/settings/notices` creates a new revision, so it is shown again. Popup custom buttons accept only site-relative destinations.
- form confirmations: add `data-site-confirm="1"` plus `data-confirm-*` text attributes
- account deletion can use `data-delete-animation="account-rip"`; other destructive forms should use plain in-site confirmations

all clicked `http(s)` links that leave `fridge.dev`, `www.fridge.dev`, or `m.fridge.dev` automatically show a safety popup before navigation. use `data-no-external-popup` only for a deliberately exempt link, and document why because bypassing safety popups is usually sus.

Cloudflare handles legacy `fridg3.org` redirects to `fridge.dev`. the redirect must add `legacy_domain=fridg3.org` to the destination URL so `main.js` can show the one-time rebrand popup; browser referrers are not reliable for detecting a 301 hop. after showing the popup, `main.js` removes the marker with `history.replaceState()`.

Cloudflare dynamic redirects need two rules because the target URL expression editor supports `concat(...)` but not `if(...)`.

rule for requests without an existing query string:

- match: `(http.host eq "fridg3.org" or http.host eq "www.fridg3.org") and http.request.uri.query eq ""`
- target URL expression:

```text
concat("https://fridge.dev", http.request.uri.path, "?legacy_domain=fridg3.org")
```

rule for requests with an existing query string:

- match: `(http.host eq "fridg3.org" or http.host eq "www.fridg3.org") and http.request.uri.query ne ""`
- target URL expression:

```text
concat("https://fridge.dev", http.request.uri.path, "?", http.request.uri.query, "&legacy_domain=fridg3.org")
```

turn off Cloudflare's separate `preserve query string` toggle for both rules, because the expressions build the final query string themselves.

## Styling

`style.css` defines:

- root color variables
- font-face declarations
- layout rules for shell and content
- reusable component styles
- mobile-template-specific overrides
- mini player, ASCII blocks, cards, grids, and assorted route UI

blackprint is the base/default theme in `/style.css` and `template.html`. its default-only CSS is scoped to `body.blackprint-theme`; keep it scoped so selectable themes are not forced to override blackprint details just to look normal. blackprint uses a dark charcoal grey base with a #776490-to-#6caaa7 accent range, and blackprint plus whiteprint intentionally keep clipped title gradients across that purple-to-teal range. sidebar titles are always rendered lowercase; Classic alone retains the “grab a snack from the” subtitle. non-classic desktop theme titles use the local `Streetbomber` face and show `fridge` without the `.dev` suffix. The title gradient animates continuously unless reduced motion is active. `/settings` exposes wobble, bounce, rubberhose, bubble, slot machine, moonwalk, and heartbeat through a theme-picker-width in-site control whose button and menu options contain animated previews that immediately follow the desync setting; there is no separate preview card. It also provides optional always-playing mode and default-on per-character desynchronization. Desync uses positive staggered starts rather than negative mid-cycle offsets, and every selectable animation begins from the neutral letter pose so its first movement eases in instead of snapping. When Reduce Motion is enabled, the entire title-animation panel is disabled and explains that the feature cannot be configured; previews and runtime-driven rolls stop until motion is re-enabled. Preferences apply locally for guests, sync to the `titleAnimation`, `titleAnimationAlways`, and `titleAnimationDesync` account fields for logged-in users, and respect reduced-motion preferences. When hover or always-playing ends, the runtime snapshots the last rendered letter transforms and transitions them back to their neutral state instead of snapping. Slot Machine is runtime-driven and caps itself at seven active reels, hiding non-reel suffix characters such as Classic’s `.dev` while rolling: six reels normally cycle random glyphs and lock onto `fridge`; one mutually exclusive 1-in-20 result lands on `fridg3`, pauses, then rerolls only the sixth reel until it locks onto `e`, while another 1-in-20 result uses a seventh reel to land on `freezer`, pauses, then rerolls all seven characters before the first six lock back onto `fridge` and the extra reel disappears. The runtime freezes the title’s measured inline and block size throughout each roll so variable-width glyphs and the temporary seventh reel cannot resize the sidebar header. Bubble floats letters upward until they pop, respawns them above the viewport, then rapidly drops and squash-settles them back into the title; picker previews use a shorter local fall distance. Bubble desync is phase-aware: letters start together and rise at different speeds, synchronize at the pop checkpoint, then cascade through the fall and impact phases. `#title` includes balanced animation-safe padding and negative margins so transformed letters remain inside its gradient paint area without changing header spacing; retain or extend that safe area when adding animations with greater travel. selectable themes are declared by `/themes/*.json`, include picker descriptions and 4:3 thumbnails from `/themes/thumbnails`, and assets live in `/themes/lib`; `classic` is the old default look and exposes full color picker overrides, while `CRT` exposes a single main phosphor color and derives the rest of its palette in CSS. `aero` keeps blackprint's sidebar/content structure but renders it through blue glass panels, `thinkpad` uses late-2000s/early-2010s matte laptop styling with red trackpoint accents and a compact desktop sidebar sized for 1366x768-era laptop screens, `occult` layers runes and sigil geometry over its ritual green/brass layout, `gothic` uses cathedral-inspired rose-window, spire, cross, and candle motifs, `modern-sleek` uses a Windows 10 dark-mode style, and `little helps` uses a Tesco-inspired blue/red top-header layout with the local Tesco Modern fonts from `/themes/lib/littlehelps`, ITC New Text for the lowercase `fridge` title, and preserved ASCII font blocks. `little helps` also includes a desktop header search form that submits `q` to `/feed` plus Tesco-style mobile header, nav, footer, mini-player, and feed search styling. desktop theme selection can use themed HTML and CSS; mobile view keeps the mobile template and appends theme CSS after mobile-specific inline styles. default mobile rendering receives `blackprint-theme` from `lib/render.php`.

the sidebar show/hide state is animated through `body.sidebar-is-hidden`, which `main.js` toggles while preserving the `sidebarVisible` localStorage preference. classic retains the desktop collapse control; other desktop themes omit it. blackprint and whiteprint keep their desktop `>>` menu prefixes, but mobile nav buttons suppress those prefixes for a cleaner grid.

the homepage FRIDGE.DEV ASCII hero uses the shared `--hero-ascii-*` color variables so each theme can tune the gradient without editing homepage markup. the server-time and resource ASCII use `--time-*` and `--resource-*` variables, which default to the hero palette unless a theme overrides them. the server-time glyphs are loaded from `/resources/ascii-time.txt`: twelve glyph blocks in fixed `0123456789:?` order, separated by lines containing exactly 16 hyphens. this external font applies only to the clock; system-usage percentages retain their separate renderer and glyphs. the clock renderer preserves spacing contained inside each glyph and adds one explicit character cell after every non-final glyph, including colons. on each five-second usage refresh, metrics whose displayed rounded value changed briefly scramble only their number glyphs before settling; unchanged metrics and every percent glyph remain stable. the server-time ASCII drops seconds when the homepage is too narrow and rechecks on resize/SPAs so it does not need a refresh. resource cards are intentionally unboxed so the ASCII itself carries the theme.

Mobile view disables both title-letter motion and the title gradient. On `/settings`, the title-animation section is disabled in mobile view and explains that it cannot be configured there, matching the existing Reduce Motion treatment.

Text selection uses the active theme's `--links` color over its `--bg` color. Main titles and shared ASCII displays are intentionally non-selectable so dragging across decorative text does not highlight it.

ThinkPad and CRT use IBM VGA throughout their interfaces while leaving the shared desktop and mobile title typefaces unchanged; ThinkPad also gives its generated tooltips a matching hardware-panel treatment. ThinkPad's desktop navigation rows use the spare sidebar height while retaining enough room for the player, track list, greeting, and footer at 1366×768 without sidebar scrolling.

fonts and icons come from:

- local font files in `resources/`
- Twemoji COLR from jsDelivr as the global emoji font fallback
- Font Awesome CDN
- Highlight.js CDN

The installable web-app manifest at `/resources/site.webmanifest` uses `https://m.fridge.dev/` as its app id, launch URL, and navigation scope so installed copies open the mobile site.

the desktop and mobile templates preconnect to the CDN hosts and preload the primary local fonts. global scripts are mounted outside `#content` and loaded with `defer`, because SPA navigation replaces `#content`; putting shared scripts inside that swapped area re-executes them and causes top-level `let`/`const` redeclaration errors. `main.js` also skips already-loaded shared scripts when older/theme templates include them in fetched content.

## Formatting Lab

`/formatting` is the shared UI specimen page. it loads normal page chrome, theme CSS, route-local `content.html`, and small examples of reusable elements used around the site: typography, links, buttons, forms, status blocks, popups, tooltips, cards, grids, pagination, dashboard cards, and BBCode editor pieces. it includes a full-page PNG capture button that loads `html2canvas` on demand and expands the scrollable app shell in the cloned render so theme screenshots include the full specimen page.

when a reusable element or shared interaction is added, changed, or restyled, add a representative sample to `formatting/content.html` too. route-specific systems that realistically will never appear elsewhere should stay documented and tested with their own page.

## Frontend State

local/browser state used by the site includes:

- `mobile_friendly_view` cookie
- `theme_pref` cookie
- `is_admin` cookie
- localStorage bookmarks for anonymous users
- localStorage dismissal state for some prompts
- localStorage feed/journal browser notification preferences, first-submit notification prompt state, notification seen keys, and the guest feed comment browser token

server-backed user state is exposed through:

- `/api/settings`
- `/api/themes`
- `/api/bookmark`
- session-based auth

## Fragile Bits

- account/logout button swapping relies on exact HTML string matching in many routes
- some routes and helpers do not use the exact same logout icon markup, so template edits there deserve extra care
- `main.js` is route-sensitive and very DOM-id-sensitive
- bookmark UI exists in both server and client paths
- `/bookmarks` also rehydrates anonymous saves client-side, so shared bookmark helpers in `main.js` are exposed on `window`

## Rule Of Thumb

edit:

- `content.html` for page-specific markup
- route `index.php` for server-side data flow
- `template.html` and `template_mobile.html` for shared shell changes
- route-local `{page-name}.js` for page-specific client interactions
- `main.js` for shared shell bootstrap or cross-page orchestration
- `/js/*.js` for larger shared client systems used across multiple pages
- `style.css` for shared styling
