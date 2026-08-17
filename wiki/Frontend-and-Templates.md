# Frontend and Templates

## Shared Templates

### `template.html`

Desktop shell with:

- Sidebar navigation
- Footer buttons
- `{user_greeting}` placeholder
- `{content}` placeholder
- Maintenance banner
- Local dev-mode banner injected by `lib/render.php`; when the current development client is hard-banned, the same render pass adds a red hard-ban warning beneath it for testing
- Mini player markup
- Page view footer

`lib/render.php` post-processes the shared shell for logged-in users whose account has a valid `emailAddress`: the footer Discord icon button is replaced with an email button linking to `/account/email`, and route renderers separately keep swapping the account button to logout where that behavior exists.

### `template_mobile.html`

Mobile shell with:

- Responsive header/nav grid
- Adjusted sidebar/content layout
- Shared placeholders from the desktop shell
- Explicit stylesheet cache-busting query string on `/style.css`

This is not just a tiny CSS tweak. It is a separate HTML shell, so shared structural edits usually need to be mirrored in both templates.

## Global Frontend Script

`main.js` is the site-wide bootstrap layer. Keep it for shared shell basics and cross-page orchestration:

- Dev-mode display is server-rendered when the host looks local; if the expected developer data copy is missing, `lib/render.php` also injects a small runtime payload so `main.js` can show a one-per-session popup pointing to `/settings`
- SPA-ish navigation and route transitions
- Page view footer updates
- ASCII time / usage widgets
- On-site popup notices, confirmations, and text prompts

Translation: if you change shared ids, buttons, or route transitions, test more than one page or you will summon weird bugs.

Larger shared frontend systems live in `/js/`, loaded by both desktop and mobile templates after `/main.js`:

- `/js/settings.js`: shared settings-page behavior and general preference runtimes, including themes, glow, mobile-view cookie syncing, notification-audio and hourly-beep preferences, account-backed Toast DM preferences, accessibility, title animation, oneko, guest inbox identity setup, and tooltips
- `/js/fruity-dance.js`: the isolated Fruity Dance preference/runtime module, including its immediate settings persistence, custom asset controls, animated spritesheet and loop pickers, draggable sprite/reflection behavior, and related diagnostics. It receives debug-mode changes from the general settings runtime through `fridg3:accessibility-change`; `main.js` calls its narrow `fridg3InitFruityDanceSettings()` hook after SPA content replacement so newly inserted settings controls reflect the active local preference
- The desktop diagnostic overlay, its client/server/access log pipelines, and logging safety rules are documented on [Debug Mode](Debug-Mode)
- `/js/sidebar-player.js`: sidebar visibility, mini player, footer/account state, and active sidebar/footer buttons; its legacy `.post-content` BBCode pass must skip elements containing `data-feed-format="v2"` so server-rendered Markdown is not flattened through `textContent`; listen-along integration is documented on [Toast](Toast#discord-service)
- `/js/sidebar-player.js` checks a lightweight notification revision every 10 seconds in foreground and background tabs and fetches the inbox only when it changes. It maintains the full-width sidebar count shortcut, prefixes the page title with the unread count across direct and SPA navigation, and adds a fixed teal unread-count circle to the mobile menu control. Genuinely new events are limited to unseen entries at the leading edge of the ordered inbox, preventing older events entering the first page from being announced as new. On the desktop template, new inbox events play `/resources/notification.mp3` at 30% volume when the device-local sound preference is enabled and audio has been unlocked by a pointer or keyboard interaction; blocked playback is discarded instead of playing later, and notification audio is disabled entirely on the mobile template. It also queues top-centre notification toasts that SPA-navigate to `/notifications` and marks notifications read when their target feed-post page is visited. A toast's dismissal timer and CSS animation only advance while the document is visible, preserving its full on-screen lifetime when it arrives in a background tab

Client performance notes: templates preload only the IBM VGA font and the regular Iosevka face needed by initial content; do not re-add the 400KB+ Bold Italic preload unless it becomes render-critical again. Title-animation settling samples active letter transforms every 80ms rather than every animation frame and skips computed-style reads while the document is hidden, preserving smooth exits without continuous high-frequency layout/style queries.
- Feed and journal use compact windowed pagination; Blackprint keeps its frame and 30×30 controls square, while every selectable theme supplies its own pager surface, corner treatment, and shadow/glow styling without changing the single-line layout
- `/js/bookmarks.js`: bookmark/save icons, anonymous bookmark storage, image modal behavior, and `/bookmarks` hydration
- `/js/bbcode.js`: legacy BBCode editing/rendering plus the shared feed, reply, and journal Markdown-editor controls; attach-media URL/upload flows for images, audio, and video; inline media players; voice notes; the feed generator; and plain-link video embeds for YouTube, Vimeo, and Dailymotion. Feed-thread Markdown previews use the thread's own JSON endpoint so accounts without feed-post creation permission and guests can preview replies, with guest filtering applied server-side. Right-clicking a queued image in an inline preview opens a crop action with a drag-selection editor that replaces the pending upload, while the journal eye button submits the draft to its dedicated full-post preview route; queued editor files are appended directly when `main.js` builds an SPA submission payload, journal card images over 1 MB are resized and converted to a sub-1 MB JPEG in the browser before that payload is built, and automatic video conversion examines only top-level text so links inside legacy BBCode elements are not embedded. On mobile, Markdown textareas and their syntax-highlight mirrors share the same 16 px font metrics to prevent Safari focus zoom and keep the caret aligned.

Page-specific behavior belongs in a route-local `{page-name}.js` file and the page's `content.html` should include that script. Examples:

- `/music/upload` uses `/music/upload/upload.js`
- Toast's route-local frontend is documented on [Toast](Toast#discord-service)
- `/others/off-topic-archive` uses `/others/off-topic-archive/off-topic-archive.js`

If several pages in the same route family need the same code, put one script at the highest shared route directory and reference that script from each page instead of duplicating it. Keep genuinely cross-page helpers in `main.js`.

The mini player is shared chrome. `/music` album cards should open the site popup track picker and send the chosen song into the mini player; do not rebuild album track lists inside the sidebar player. Its compact layout displays song title and artist on separate lines, places a theme-coloured mute/volume control on the playback row, exposes downloads while paused as well as playing, and pins a small close control to the panel's top-right corner. Play and pause controls, including Media Session hardware controls, use a short audio-volume ramp so playback fades in and out without replacing the listener's saved volume. Hovering or focusing volume switches the row from seek mode to volume mode: the seek bar hides and the volume bar takes the same flexible track width. On each fresh hover, the bar expands out from the volume icon while the icon briefly scales and changes colour, making the mode change explicit; reduced-motion mode suppresses this cue. Closing stops and unloads audio, clears persisted player state, and hides the player until another track or live stream is selected. The player, track panel, and sidebar footer deliberately suppress theme shadows through a final high-specificity shared override.

## Popups and External Links

Use the on-site popup helpers in `main.js`, not native browser `alert()`, `confirm()`, or `prompt()`. Submitting a new feed or journal post, or saving changes to a journal post, shows a non-dismissible, buttonless `please wait...` popup with the shared upload message until the SPA request finishes; journal draft saves and previews do not show it.

- Notices: `showSiteNotice(title, detail)`
- Confirmations: `showSitePopup({ title, detail/html, okText, cancelText })`
- Text input: `showSitePrompt(title, detail, value)`

The shared renderer injects the active site notice for either logged-in users or guests. Dismissible banners and popup acknowledgements are stored in browser local storage by notice revision; saving a notice from `/settings/notices` creates a new revision, so it is shown again. Popup custom buttons accept only site-relative destinations.
- Form confirmations: add `data-site-confirm="1"` plus `data-confirm-*` text attributes
- Account deletion can use `data-delete-animation="account-rip"`; other destructive forms should use plain in-site confirmations

All clicked `http(s)` links that leave `fridge.dev`, `www.fridge.dev`, or `m.fridge.dev` automatically show a safety popup before navigation. Use `data-no-external-popup` only for a deliberately exempt link, and document why because bypassing safety popups is usually sus.

Cloudflare handles legacy `fridg3.org` redirects to `fridge.dev`. The redirect must add `legacy_domain=fridg3.org` to the destination URL so `main.js` can show the one-time rebrand popup; browser referrers are not reliable for detecting a 301 hop. After showing the popup, `main.js` removes the marker with `history.replaceState()`.

Cloudflare dynamic redirects need two rules because the target URL expression editor supports `concat(...)` but not `if(...)`.

Rule for requests without an existing query string:

- Match: `(http.host eq "fridg3.org" or http.host eq "www.fridg3.org") and http.request.uri.query eq ""`
- Target URL expression:

```text
concat("https://fridge.dev", http.request.uri.path, "?legacy_domain=fridg3.org")
```

Rule for requests with an existing query string:

- Match: `(http.host eq "fridg3.org" or http.host eq "www.fridg3.org") and http.request.uri.query ne ""`
- Target URL expression:

```text
concat("https://fridge.dev", http.request.uri.path, "?", http.request.uri.query, "&legacy_domain=fridg3.org")
```

Turn off Cloudflare's separate `preserve query string` toggle for both rules, because the expressions build the final query string themselves.

## Styling

`style.css` defines:

- Root color variables
- Font-face declarations
- Layout rules for shell and content
- Reusable component styles
- Mobile-template-specific overrides
- Mini player, ASCII blocks, cards, grids, and assorted route UI

Submitted site search forms use the shared SPA content loader so results replace `#content` without refreshing the surrounding page or interrupting sidebar audio. A buttonless in-site “searching” popup remains visible until that content swap completes. Instant client-side filters, such as log and emoji filtering, remain immediate and do not display the network-wait popup.

Blackprint is the built-in default theme in `/style.css`, `/template.html`, and `/template_mobile.html`; it is not a JSON theme package. Its default-only layer is scoped to `body.blackprint-theme` and uses a dark charcoal base with a #776490-to-#6caaa7 accent range. Classic is currently the only packaged selectable theme, lives in `/themes/classic.json` and `/themes/lib/classic`, retains the original “grab a snack from the” subtitle and `.dev` suffix, and exposes full `bg`/`fg`/`border`/`subtle`/`links` color controls. Other theme packages are discovered from `/themes/*.json`, use 4:3 thumbnails under `/themes/thumbnails`, and load assets from `/themes/lib`; desktop may use their HTML and CSS while mobile always keeps the shared mobile template and appends only theme CSS. The title is lowercase and uses per-character spans with a clipped purple-to-teal gradient. `/settings` exposes wobble, bounce, rubberhose, bubble, slot machine, moonwalk, and heartbeat, plus optional always-playing and default-on character desynchronization. Reduce Motion disables previews and runtime title motion. Slot Machine and Bubble include runtime behavior that depends on the existing title spans, measured safe area, and overflow rules, so themes must preserve those contracts. The complete current theme-authoring contract and component inventory is maintained in `/themes/AGENTS.md` from the canonical cascade in `/style.css`.

Classic's mobile menu is pinned with explicit 8px left and right insets, capped to the viewport width, and uses border-box sizing and internal vertical scrolling. It has a four-sided border plus an inset right-edge rule so the overlay scrollbar cannot obscure the panel boundary, and keeps the ten primary navigation items in two columns even below 420px. Its header, navigation, player, and footer use compact consistent insets. Classic's desktop template uses the current shared mini-player structure: title and artist occupy separate metadata rows, play/seek/mute share the playback row, the volume control expands in place, and only close remains pinned at the top-right. Selecting Classic in the settings theme picker immediately resets all five customizable colors to Classic's defaults and persists those defaults locally and, for logged-in users, to the account.

Whiteprint is a packaged light counterpart to Blackprint. Its metadata declares `base: blackprint`, so the renderer applies both `whiteprint-theme` and `blackprint-theme` and it automatically shares Blackprint's desktop/mobile layout, title behavior, navigation, player, controls, and component coverage. Its appended stylesheet uses soft neutral off-whites, charcoal ink, dusty purple, and restrained sage accents. The page retains a low-contrast light version of Blackprint's purple-to-sage wash, 18px line grid, and paper-grain pattern while cards and controls remain flat, contrasting muted surfaces; ASCII/resource readouts and image-modal content wrappers stay transparent.

Aero is a packaged Frutiger Aero interpretation of the Blackprint layout. It uses the bundled Tahoma face and a softly blurred, cover-scaled `background.jpg` beneath translucent XP/Windows 7-inspired glass panels, muted blue-grey and sage controls, and rounded content cards. Its BBCode/Markdown editor uses compact blue-glass formatting buttons, independently scrollable formatting controls, and a bright unified writing/preview surface rather than the general green submit-button skin. Its persistent music player switches to a compact Winamp-inspired dark beveled chassis with a soft phosphor display, metallic transport controls, slate-blue range tracks, and a matching playlist while retaining the shared player hooks. Aero supplies explicit light checked controls: checkboxes show a white tick on sage and radio buttons show an inset sage dot. The shared title and Font Awesome faces are left untouched, IBM VGA remains reserved for shared ASCII displays, and the monospace face remains reserved for code. Aero menu labels are lowercase and omit Blackprint's `>>` prefix. Its mobile layer preserves the shared safe menu gutter and restyles the complete shell without requiring separate mobile markup.

Whiteprint and Aero each include an explicit mobile coverage block after their desktop component rules. These blocks match the shared mobile template's high-specificity shell, post-card, footer-button, and player selectors, preserve the 8px menu gutter, retain two navigation columns at narrow widths, replace inline dark content surfaces, constrain wide media/forms/tables, and apply each theme's player, footer, active-navigation, editor, and form treatment inside the expanded menu.

Base16 is a packaged flat interpretation of the Blackprint layout using the complete Base16 Default Dark `base00`–`base0F` palette. A theme-wide invariant removes inherited gradients, decorative background images, shadows, text shadows, filters, backdrop filters, and paper grain from the shell and components; functional theme thumbnails and Fruity Dance sprites remain visible. Solid Base16 surfaces cover cards, navigation, editors, forms, dialogs, chat, the player, and the explicit two-column mobile layout. Native checked controls are restored and accented with `base0D`, keeping their indicators visible without custom image textures.

Catpuccin is a packaged dark palette theme derived from the Blackprint layout. It uses the darker Catppuccin crust tone for the outer site canvas, with soft mocha panel surfaces and rose, peach, yellow, green, teal, blue, and mauve accents. It covers the desktop and two-column mobile shells, cards, forms, editor, player, popups, and checked controls. Delete and destructive controls—including feed replies, guestbook posts, grid items, chat, and account administration—intentionally share the same base and hover treatment as ordinary buttons instead of switching to a separate red danger style.

Theme-picker and Fruity Dance spritesheet-picker subtitles use the site's `MainRegular` face explicitly in both the selected button and menu options, rather than inheriting a browser button font.

The sidebar show/hide state is animated through `body.sidebar-is-hidden`, which `main.js` toggles while preserving the `sidebarVisible` localStorage preference. Classic retains the desktop collapse control; Blackprint omits it. Blackprint keeps its desktop `>>` menu prefixes, while mobile nav buttons suppress those prefixes for a cleaner grid. The complete sidebar suppresses text selection and native image/link dragging while preserving normal control interaction.

The homepage fridge.dev ASCII hero uses the shared `--hero-ascii-*` color variables so each theme can tune the gradient without editing homepage markup. The server-time and resource ASCII use `--time-*` and `--resource-*` variables, which default to the hero palette unless a theme overrides them. The server-time glyphs are loaded from `/resources/ascii-time.txt`: twelve glyph blocks in fixed `0123456789:?` order, separated by lines containing exactly 16 hyphens. This external font applies only to the clock; system-usage percentages retain their separate renderer and glyphs. The clock renderer preserves spacing contained inside each glyph and adds one explicit character cell after every non-final glyph, including colons. On each five-second usage refresh, metrics whose displayed rounded value changed briefly scramble only their number glyphs before settling; unchanged metrics and every percent glyph remain stable. The server-time ASCII drops seconds when the homepage is too narrow and rechecks on resize/SPAs so it does not need a refresh. Resource cards are intentionally unboxed so the ASCII itself carries the theme. Shared mobile `#ascii` fitting measures the parent panel's content box after subtracting horizontal padding, ensuring wide page titles such as Guestbook and Notifications fit inside `#content-main` without being clipped. It runs forced passes after initial layout settling, direct-load font readiness, and SPA swaps. Later resize observations only refit when the content width actually changes, preventing mobile browser chrome movement while scrolling from resizing the title, and mobile ASCII font-size transitions are disabled.

Mobile view renders the sidebar title with the same per-letter spans as desktop and supports the same title motion and settings. The selected animation always plays in the mobile menu, so the Always Playing checkbox is hidden there while the saved desktop preference remains unchanged. Animated letters paint their clipped gradient individually for Mobile Safari compatibility rather than depending on a transformed child to expose its parent's text-clipped background; compensated, slightly asymmetric per-letter inline padding preserves italic and scaled glyph overhang without changing the word's spacing. Mobile picker previews use full-size 112px animation columns, 28px glyphs, taller transform-safe boxes, and visible overflow so bouncing, scaling, and bubble movement are legible and not clipped. Reduce Motion still disables runtime motion and the picker in both layouts. The debug-mode checkbox is hidden entirely on mobile rather than shown disabled.

Text selection uses the active theme's `--links` color over its `--bg` color. Main titles and shared ASCII displays are intentionally non-selectable so dragging across decorative text does not highlight it.

Native page, component, and sidebar scrollbars use shared colours derived from the active theme's background, border, subtle, and link variables. Theme styles should change those foundation variables rather than introduce hard-coded scrollbar colours.

Fonts and icons come from:

- Local font files in `resources/`
- Twemoji COLR from jsDelivr as the global emoji font fallback
- Font Awesome CDN
- Highlight.js CDN

The installable web-app manifest at `/resources/site.webmanifest` uses `https://m.fridge.dev/` as its app id, launch URL, and navigation scope so installed copies open the mobile site.

Mobile-host redirects are production behavior and run automatically without a cramped-screen prompt: detected phones use the same path on `m.fridge.dev` until Force Mobile View is explicitly unchecked, while detected desktop devices on `m.fridge.dev` return to `fridge.dev`. Routing bootstrap in `main.js` owns self-contained `mobile_friendly_view` cookie helpers because it executes before the settings runtime; do not depend on functions declared by `js/settings.js` from this early path. The settings toggle changes the template on the current host and never sends desktop users to the mobile host; unchecking from `m.fridge.dev` records the mobile opt-out and returns to the main host. Developer mode keeps the current host and automatically enables Force Mobile View for detected phones, reloading once in place to apply the mobile template.

The collapsed mobile layout has no separate branded header or title-row close button. Its compact, shadow-free menu control stays fixed at the top-right of the viewport while the page scrolls, remains available while the menu is open, and toggles that menu in either direction. The menu is always closed on a fresh page load and opens only from that control. It remains fully laid out around its final centre point while hidden, then animates opacity and scale into a fully bordered, shadow-free panel without height reflow or position snapping. It uses the same 8px horizontal gutter as the content panel, locks document scrolling, and dims the rest of the page with a clickable backdrop that closes it. Both the menu and backdrop transitions are disabled by the site Reduce Motion setting and the system reduced-motion preference.

Developer mode is shown inside the expanded mobile sidebar and as a compact indicator in the collapsed mobile header, so collapsing navigation does not hide the current environment state. `/wiki` deliberately suppresses both general indicators in favor of its own `Developer Wiki` label.

The desktop and mobile templates preconnect to the CDN hosts and preload the primary local fonts. Global scripts are mounted outside `#content` and loaded with `defer`, because SPA navigation replaces `#content`; putting shared scripts inside that swapped area re-executes them and causes top-level `let`/`const` redeclaration errors. `main.js` also skips already-loaded shared scripts when older/theme templates include them in fetched content.

## Formatting Lab

`/formatting` is the shared UI specimen page. It loads normal page chrome, theme CSS, route-local `content.html`, and small examples of reusable elements used around the site: typography, links, buttons, forms, status blocks, popups, tooltips, cards, grids, pagination, dashboard cards, and BBCode editor pieces. It includes a full-page PNG capture button that loads `html2canvas` on demand and expands the scrollable app shell in the cloned render so theme screenshots include the full specimen page.

When a reusable element or shared interaction is added, changed, or restyled, add a representative sample to `formatting/content.html` too. Route-specific systems that realistically will never appear elsewhere should stay documented and tested with their own page.

## Frontend State

Local/browser state used by the site includes:

- `mobile_friendly_view` cookie
- `theme_pref` cookie
- `is_admin` cookie
- LocalStorage bookmarks for anonymous users
- LocalStorage dismissal state for some prompts
- LocalStorage guest feed-comment inbox identity
- LocalStorage `notificationSoundsEnabled`, defaulting to enabled, controls desktop in-site notification audio; mobile templates suppress that audio regardless of the stored desktop preference. LocalStorage `hourlyBeepEnabled`, also defaulting to enabled, controls the top-of-hour watch beep. Both controls live under Settings → Notifications, with “beep beep!” last

Server-backed user state is exposed through:

- `/api/settings`
- `/api/themes`
- `/api/bookmark`
- Session-based auth

## Fragile Bits

- Account/logout button swapping relies on exact HTML string matching in many routes
- Some routes and helpers do not use the exact same logout icon markup, so template edits there deserve extra care
- `main.js` is route-sensitive and very DOM-id-sensitive
- Bookmark UI exists in both server and client paths
- `/bookmarks` also rehydrates anonymous saves client-side, so shared bookmark helpers in `main.js` are exposed on `window`

## Rule of Thumb

Edit:

- `content.html` for page-specific markup
- Route `index.php` for server-side data flow
- `template.html` and `template_mobile.html` for shared shell changes
- Route-local `{page-name}.js` for page-specific client interactions
- `main.js` for shared shell bootstrap or cross-page orchestration
- `/js/*.js` for larger shared client systems used across multiple pages
- `style.css` for shared styling
