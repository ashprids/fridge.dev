# Routes and Features

## Core Content Routes

### `/`

Homepage with dynamic latest feed, latest journal, and music cards.

### `/feed`

- List/search/paginate feed posts from `data/feed/*.txt`
- Feed and journal pagination measures the available content width to show as many background-free page controls as fit on one line, expanding across the row only when enough pages exist to fill it and otherwise shrink-wrapping at the center; it retains previous/next, first/last, current-page context, and ellipses across remaining gaps, while selecting a page pins the viewport to the bottom through delayed image loading and layout changes
- Create visibility depends on admin or `allowedPages` containing `feed`
- Create composer supports recorded voice notes; accepted recordings request browser noise suppression, echo cancellation, and auto gain when available, are previewed before posting, capped at 2 minutes, transcoded to compressed `.m4a`, stored under `data/audio/voice/`, and played with inline controls that include a `1x`/`1.5x`/`2x` speed toggle
- The attach-media control keeps the existing URL-or-upload flow and accepts images, audio, and video; uploaded audio uses the voice-note player, while uploaded video uses a compact native player; file-extension detection covers mobile file providers that omit the browser MIME type, while the server still verifies the media container before saving it
- Deleting a feed post removes voice note files referenced by the post body and its replies
- Writes derived `index.toml`
- `@mentions` in BBCode are highlighted client-side for notification-aware feed posts
- Plain YouTube, Vimeo, and Dailymotion video URLs render as responsive embedded players and the original link text is hidden; URLs inside any rendered BBCode element remain unchanged

Related:

- `/feed/create`
- `/feed/edit`
- `/feed/posts/{id}`

### `/feed/create`

- Requires admin or `allowedPages` containing `feed`
- Toast's feed generator and mention behavior are documented on [Toast](Toast#ai-feed-posts)

### `/feed/posts/{id}`

- Single-post thread view for a feed item
- New replies use the same restricted Markdown editor, toolbar, syntax highlighting, preview renderer, media uploads, and recorded voice notes as new feed posts; `format: "v2"` distinguishes them from existing legacy BBCode replies, whose rendering and edit controls remain unchanged
- Guests can reply to the post or to an individual comment without creating feed posts; they receive the same Markdown editor shell and guide but no upload or voice controls, are identified by plaintext IP, may enter an optional display name that falls back to italic `Anonymous`, cannot use a registered account username as that display name, and can still link media manually; guest display names and reply bodies are filtered through `/feed/filters/*.txt`, matching body text is replaced with `★` plus the tooltip `this phrase was automatically filtered.`, and Markdown previews apply the same filtering
- Guest replies that are mostly filter-list terms are rejected, and guest replies containing filtered text cannot be edited by guests after posting
- Reply edit/delete is allowed for the reply author, same-IP guest replies, admins, the original post owner, or accounts with `allowedPages` containing `comments`
- Replies persist under `data/feed/replies/{postId}.json`; comment replies stay in the same flat list with optional `parentId` metadata and render directly beneath their parent comment
- Guest replies store a browser-local inbox identity so logged-out visitors can receive in-site notifications when someone replies to their comments
- Deleting a reply removes voice note files referenced by that reply
- Admin IP moderation actions appear beside guest reply edit/delete icons; admins can ban an IP or purge guest replies by exact plaintext IP without deleting the feed post or changing the IP ban list. IPs are not printed beside names; admins can right-click every relevant guest or registered feed author name to reveal the exact IP recorded for that submission. Content without historical IP metadata shows `No IP associated` instead of omitting the context tooltip or substituting another address
- Toast's automatic thread replies are documented on [Toast](Toast#automatic-feed-replies)

### `/journal`

- List/search/paginate legacy `.txt` and v2 `.md` journal posts from `data/journal`
- Journal listing cards use an optional uploaded card image, falling back to the first image in each post body, as a faded edge-to-edge background; local images use the same cached 500×500 JPEG thumbnails as the gallery to avoid loading full-size files, while posts without either image retain the normal card surface
- Create visibility depends on admin or `allowedPages` containing `journal`
- New posts are full Markdown files beginning with `v2`; their generated front matter maps the title and description directly, adds the publish date, omits `author`, and keeps an optional uploaded `card_image` out of the visible body
- The journal Markdown composer exposes the full mdpaste formatting toolbar except its manual article-header control, links to `/formatting/markdown`, and retains feed media uploads, image cropping, and recorded voice notes; its delegated eye-button action works after direct or SPA loading, saves the current content as a Markdown draft, and opens the dedicated journal preview page
- Published v2 posts use the mdpaste reading view inside the normal journal site layout, retaining the sidebar and content placement while adding title-based `.md` download, Fira Sans, and raw/formatted controls; toolbar space is reserved in the article header so long titles wrap without overlapping the controls, and the shared Markdown initializer reruns after direct loads, SPA navigation, and SPA preview submission so MathJax, Mermaid, media, and viewer controls do not require a refresh
- Legacy `.txt` journal posts retain their trusted HTML rendering and legacy edit behavior while sharing the same top-right edit styling, title-clearance spacing, and Fira Sans toggle as v2 posts
- Draft previews recognize `FORMAT:markdown`, `FORMAT:html`, and unmarked legacy BBCode bodies; Markdown previews use the same shared element styles as `/formatting/markdown`, while the older journal typography rules remain isolated to legacy preview bodies

Related:

- `/journal/create`
- `/journal/create/preview`
- `/journal/edit`
- `/journal/edit/preview`
- `/journal/posts/{id}`

### `/guestbook`

- List entries from `data/guestbook/*.txt`
- One-post-per-IP gate via `data/guestbook/ip_index.json`
- New entries store an `IP:` metadata line; IPs in the shared feed ban list cannot submit guestbook posts
- Every successfully stored entry creates an unread in-site notification for each admin account, linking to `/guestbook`
- Owner/admin edit and delete flow
- Admins can ban an entry IP or password-confirm a purge of both feed replies and guestbook posts associated with that IP. Entry IPs are attached only for admins and appear in an in-site tooltip when the guest name is right-clicked

Related:

- `/guestbook/create`
- `/guestbook/edit`

### `/music`

- Builds album grids from `data/music/frdg3/*.json` and `data/music/cactile/*.json`
- Songs reference `data/audio/*`
- Integrates with the shared mini player; album and other multi-track release clicks open an on-site popup track picker, while single-track releases play directly
- Admins see upload buttons for each artist; `/music/upload` saves audio files to `data/audio/` and creates release JSON in that artist's `data/music/{artist}/` folder
- `/music/upload` supports `single`, `remix`, and `album` release types; all release types can add multiple track rows and reorder them before saving, can optionally set a scheduled publish date/time, and release order is assigned automatically from the current highest order for the selected artist

### `/gallery`

- Paginated listing of `data/images/*`; the grid lazily loads cached, center-cropped 500×500 JPEG thumbnails generated with PHP GD or ffmpeg, while the image viewer loads the original full-resolution file only after a thumbnail is clicked
- Gallery pagination uses the same adaptive, bottom-pinned single-line control as feed, journal, and guestbook
- Admin delete actions call `/api/gallery/delete`

### `/tools` and `/others`

- Their static `content.html` post-card lists are discovered automatically and split into pages of at most 10 cards
- When more than one page exists, both listings use the shared adaptive paginator and bottom-position preservation

### `/bookmarks`

- Server-rendered bookmark listing for logged-in users
- Client-side localStorage enhancement for anonymous users
- Supports feed and journal bookmark ids; legacy `newsletter:*` ids are ignored

### `/notifications`

- Lists notifications for the current account or guest browser newest first, with at most 10 items per page; the first inbox check establishes existing events as a read baseline so deployment does not surface historical events as new
- Logged-in users receive feed mentions and replies to their feed posts or comments; logged-out visitors receive only replies to feed comments created with the same browser-local inbox identity. Journal posts do not create notifications
- Valid registered `@username` mentions in feed Markdown render automatically as inline code, show a `registered fridge.dev account` in-site tooltip on hover in both published content and the asynchronous editor preview, and use the site purple in the live syntax-highlight layer once complete. Feed post and reply Markdown editors refresh the public username list on focus, query it while typing a mention, and show up to six compact prefix matches at the caret—below the typed text when space permits or above it otherwise—with mouse, arrow-key, Enter, and Tab selection; the suggestion box is fully removed when no prefix remains
- Opening the page does not mark notifications read. Clicking one records it as read, then performs a full navigation to its feed post or anchored reply; unread cards remain prominent while old cards are greyed out but clickable
- A full-width `mark all read` action clears every relevant unread event across all pages in one request; it remains visible but greyed out and disabled when the inbox has no unread notifications
- Each notification card has an X that persistently removes that event from the inbox without reloading the list. Cards can also be swiped or pointer-dragged horizontally in either direction to remove them, while vertical touch movement remains available for page scrolling. Card text is non-selectable and the list clips horizontal drag overflow so gestures neither select content nor widen the page. A matching `clear all` bulk action sits beside `mark all read`, asks for confirmation in an in-site popup, and dismisses every notification for the current account or guest browser. Both bulk controls remain visible and grey out when they have nothing to do
- Notification cards prefix registered usernames with `@` and render guest display names in italics; pages with notifications omit a redundant total-count sentence above the cards
- Notification timestamps read `just now`, then use whole minutes, hours, or days through seven days old; older items show their calendar date. The API emits ISO 8601 timestamps with the server timezone offset so relative ages remain correct in other timezones. Hovering or focusing the time uses the shared in-site tooltip to reveal the exact local date and time
- Notification-card bodies use the matching restricted feed Markdown or legacy BBCode renderer, but cap very long previews at 8.5rem with a bottom fade; the complete notification card remains clickable. Temporary top-of-screen notifications strip all formatting syntax into plain text, hard-limit their title and body preview lengths, and show at most two body lines
- The shared sidebar checks one tiny notification revision value every 10 seconds while the tab is visible and fetches the inbox only after that value changes; returning to a hidden tab triggers an immediate check. This avoids holding PHP-FPM workers open and keeps full inbox generation out of the steady-state path. It always shows the notification-count shortcut for logged-in users and shows it to guests only while they have unread notifications. It is always moved immediately above the sidebar footer, uses the compact bell/label/count layout, inherits the normal theme link margins for button-width alignment, and uses the standard sidebar border colour without a glow. A new event briefly animates its background colour
- The redesigned sidebar music player and its track panel suppress theme box shadows, show song and artist on separate lines, keep volume controls compact, expose paused-track downloads, and provide a small close button that stops/unloads playback and hides the player until another selection; the footer is also shadow-free. Mobile uses a large 64px artwork-and-metadata grid with an unboxed play control, full-width seek bar, accessible download, and boxed close control; volume controls are intentionally omitted from the mobile layout. Explicit 8px margins keep the player-to-notification and notification-to-footer gaps equal, and the notification count uses a compact 16px single-digit circle that expands only for wider counts
- Newly observed events drop down from the top centre as a phone-style in-site notification, remain visible for five seconds, and slide back up. Every dropdown SPA-navigates to `/notifications` when clicked; on touch devices, the dropdown captures the gesture so the page does not scroll, and dragging it upward by at least 32 pixels or horizontally by at least 55 pixels dismisses it immediately without triggering navigation
- Direct page loads and browser refreshes show one square-cornered top-centre summary when unread notifications already exist; SPA navigation never produces this summary. The sidebar notification row is also square-cornered, keeps an extra 8px gap above it after the greeting/player area, uses an accented background while unread items exist, capitalizes its label in both layouts, and is slightly taller than the standard desktop navigation rows without changing its horizontal width
- Visiting a feed-post page marks all notifications targeting that post as read, both on a direct request and after SPA navigation
- Unread counts prefix the browser tab title and remain synchronized across direct loads and SPA navigation
- Inbox read and dismissal state is stored in `data/etc/notification-inbox-state.json`; the site does not use the browser Notification API
- The normal page-view counter footer is omitted from `/notifications`

### `/tools/upload`

- Displayed as `serverless upload`; the route remains stable for existing transfer links
- Browser-to-browser encrypted file transfer using WebRTC data channels
- Accounts with `postingRestricted` and clients on the shared banned-IP list cannot create, join, or signal upload rooms; the page disables its controls and every API action independently enforces the restriction
- PHP stores only short-lived room/signaling metadata under `data/upload/rooms.json`; uploaded file bytes are never stored server-side
- Creating a room chooses whether the creator is the sender or receiver, then produces a `/tools/upload/?r={token}` share link
- Access is limited to the creator browser plus the first guest browser through the HttpOnly `fridg3_upload_peer` cookie; later browsers receive `room_full`
- Peers exchange ephemeral ECDH public keys through signaling and encrypt file chunks with AES-GCM before sending
- Plaintext chunks are kept below WebRTC's common 64 KiB message edge after encryption overhead to avoid truncated or dropped data-channel frames
- Sender and receiver compute a streaming SHA-256 checksum; the receiver only sends the success ack when file size, chunk count, and checksum match
- Enforces a 100 GB client-side file limit; browsers with File System Access API support stream received chunks to disk, while fallback browsers download after completion
- Both peers see transfer progress; sender progress reaches 100% only after the receiver confirms completion, and receiver progress reaches 100% after the file is written or downloaded, so either side can close the page once their bar is full
- Rooms end when either peer closes the tab via a close beacon, with a short heartbeat timeout fallback for crashed or disconnected tabs

### `/settings`

- UI shell only
- Persistence handled by `/api/settings`
- Changing a settings control marks the page dirty; link navigation to another page uses an in-site confirmation popup whose primary action saves before continuing, while saving and refreshing the current page never prompt
- Successful saves briefly change the main button label to `saved!`; theme or mobile-view changes then reload automatically using the newly persisted preference
- Includes accessibility toggles for mobile view and reduced motion, plus theme selection, a text glow toggle, optional cursor cat, and an in-site title-animation picker with live previews, always-playing mode, and default-on character desync. The debug-mode checkbox is omitted on mobile layouts
- Title animation controls, previews, and sidebar-title motion work on desktop and mobile; Reduce Motion remains the only setting that disables them
- Feed mention and reply alerts are delivered through the in-site inbox only; Discord notification parity is documented on [Toast](Toast#notifications-and-website-integration)
- Developer mode can bootstrap a blank-password `admin` / `Administrator` account when no admin accounts exist, and can download the latest sanitized developer data zip, delete local `data/`, and install the new copy
- Shows a Discord linking action for logged-in users and disables it once `discordUserId` is already linked
- Toast-only settings behavior is documented on [Toast](Toast#personality-sources)
- The complete admin-settings area is a collapsed-by-default disclosure. Its `site management` group contains sitemap regeneration, system information, and notice management
- Admins can open `/settings/guests`, labeled as manage guests, to review guest feed replies and IP-backed guestbook posts grouped by IP, search by IP or username, individually delete either content type, ban or unban IPs across both posting surfaces, or purge all guest content from an IP after password confirmation
- Admins can open `/settings/banned-ips` to edit the separate hard-ban IP list; valid IPv4 and IPv6 addresses may be separated by spaces or newlines, and Nginx redirects matching clients away from all pages and static files to `/error/blacklisted`; access-log moderation controls are documented on [Debug Mode](Debug-Mode#access-tab), while the enforcement model is documented on [Restrictions and Moderation](Restrictions-and-Moderation)
- Admins can open `/settings/notices` to independently create or clear banner and one-time popup notices for logged-in users and guests, globally or for one exact page path; page-notice audience checkboxes allow logged-in users, guests, or both, page notices override the matching global notice type on that page, banners may be dismissible, popups can include a site-relative custom-link button, and every editor section is collapsible and closed by default. The same page has a send-only notification form for comma-separated registered users, comma-separated exact IPs, all logged-in users, or all guests, with a title, message, and optional site-relative destination; it submits asynchronously, preserves the completed form fields, and confirms success or failure through an in-site popup. Previously issued notifications are not listed there
- `/error/blacklisted` returns the stripped Blackprint denial page only to an actively hard-banned IP or associated browser identity; other direct visitors receive a server-side redirect to `/`
- Admins can open `/settings/sysinfo` to see live system diagnostics, PHP/runtime details, storage usage, website state, and key content counts in a dashboard-style view

## Account Routes

### `/account`

Currently just redirects:

- Logged in -> `/`
- Logged out -> `/account/login`

### `/account/login`

- Secure session config
- CSRF protection
- Login throttling via `data/accounts/login_attempts.json`
- Reads `data/accounts/accounts.json`
- Sets session user payload and `is_admin` cookie
- Session cookies use the `fridg3_session` name, last 90 days, use `SameSite=Lax`, and are shared across `fridge.dev`, `www.fridge.dev`, `m.fridge.dev`, and other `*.fridge.dev` hosts so mobile/desktop host switches do not look like a logout
- Successful login/logout clears the legacy `PHPSESSID` cookie on both the shared domain and current host so stale host-only cookies cannot shadow the shared session
- The reserved Toast identity and login behavior are documented on [Toast](Toast#website-identity)
- Users with `mustResetPassword` are redirected into the password-change flow before using the rest of the site

### `/account/logout`

Destroys session and auth cookies, then redirects back to login.

### `/account/create`

Admin-only account creation flow that writes to `data/accounts/accounts.json`.

- Can seed `discordUserId`
- Can seed an optional `emailAddress` for fridge.dev mailboxes
- Can grant `comments` and `chat` permissions
- Can create the account with `postingRestricted` already enabled
- Newly created accounts are flagged with `mustResetPassword`
- Toast's reserved username and account-invite integration are documented on [Toast](Toast#notifications-and-website-integration)
- If that DM fails, the account is still created and the UI now shows the bot's concrete failure reason instead of a generic HTTP 500
- Local dev mode shows a random dev-account generator that creates `userXXXX` / `User #XXXX` with feed/comment permissions, a blank password, no forced password reset, and no Discord invite

### `/account/change-password` and `/account/password`

Both update the current user password hash in `accounts.json`.

- First-login forced password reset lands here via `?first_login=1`

### `/account/link-discord`

- Logged-in-only Discord linking flow
- Validates the Discord user id and checks uniqueness across accounts; Toast's verification and role assignment are documented on [Toast](Toast#notifications-and-website-integration)
- Stores `discordUserId` on the account and assigns the Discord `registered` role through the bot

### `/account/admin`

Not covered in the older references, but very real.

- Admin-only account directory
- Reads all accounts and renders permission badges
- Links to per-account edit page

### `/account/admin/edit`

- Admin-only account editor
- Supports rename, display-name change, optional `emailAddress`, permission changes, reset password, and delete
- Delete confirmation plays a centered rip-in-half account card animation before the destructive POST continues
- The `purge user content` danger button must purge all user-owned content; currently this includes feed posts, attached images, voice notes, and reply data
- Preserves unknown extra account fields through an editable JSON object field
- Blocks deleting the currently logged-in account
- Includes `comments` and `chat` as grantable `allowedPages` permissions
- Includes a `restricted from posting` checkbox backed by `postingRestricted`; restricted accounts retain their page permissions and deletion/moderation access, but cannot create or edit posts, replies, chat messages, or guestbook entries
- Password resets now preserve the account and flip `mustResetPassword` back on
- Lists the account's deduplicated, read-only IP history recorded from successful logins and new registered feed posts/replies; saving other account fields preserves this history

Helpers live in `account/admin/helpers.php`.

### `/account/email`

- Shows fridge.dev email web-client and custom-client setup details
- If the logged-in account has a valid `emailAddress`, the page shows that assigned fridge.dev address near the top
- Shared shell rendering swaps the footer Discord button to this route only for accounts with a valid `emailAddress`

## Private Chat Routes

### `/chat`

One-time private conversation manager.

- Requires admin or `allowedPages` containing `chat`
- Creates conversations with a recipient label
- Lists active conversation files from `data/chat/*.json`
- Shows canonical share links shaped like `https://fridge.dev/chat/{conversationId}` that copy to clipboard when clicked
- Can end a conversation through an in-site confirmation popup, which deletes the encrypted JSON file immediately

### `/chat/{conversationId}`

One-to-one conversation view.

- Managers can open without claiming recipient access
- The first non-manager visitor sees a concise chat invite/auth page and receives an HttpOnly recipient cookie
- The recipient's first full chat view shows an in-site security/help popup explaining browser/account locking, encrypted storage, replies, and reactions
- Later visits from that browser are allowed through
- If the recipient is logged into an account when they open an unclaimed invite, the chat links to that account instead of a browser cookie
- Logged-in recipients with an active linked chat get a sidebar button above the mini-player/sidebar footer and can delete that chat themselves
- Other browsers without the matching cookie get a custom access-denied page
- If the backing file is deleted, returning recipients see the ended-conversation page
- Messages are stored inside the encrypted per-conversation JSON envelope under `data/chat`
- Image/file attachments up to 8 MB are stored as encrypted per-chat blobs and served only after chat access checks
- The composer `+` menu supports file upload or recording a voice note; voice notes are previewed before send, capped at 2 minutes, transcoded to compressed `.m4a`, and stored as encrypted chat attachments
- Selecting an attachment shows an attached-file indicator before send; image attachments use the site image viewer, while audio, voice, and video attachments embed with custom themed playback controls inside the chat; audio/voice controls include the `1x`/`1.5x`/`2x` speed toggle
- Messages can visually reply to a previous message, and clicking/tapping a message opens reply/react/delete actions; message deletion uses an in-site confirmation popup, and deleted messages stay in place as dimmed `message deleted` placeholders
- Reactions are emoji-based, searchable from the message context menu or the desktop-only emoji button beside the composer; the picker loads Emoji 16 Emojibase data from jsDelivr, lazy-renders results as users search/scroll, supports typed or pasted emoji from the search box, and falls back to a tiny local set if unavailable
- Both sides send active/away presence heartbeats plus short-lived typing state, and the page live-polls whether the other side is online, away, or offline while showing a non-layout-shifting typing indicator inside the message box
- Message sends update the current page immediately, and open chat pages poll for new messages; unfocused/hidden chat tabs play `/chat/alert.ogg` and prefix the page title with an unread count when the other side sends new messages
- Message timestamps show time only, with a date divider inserted at the first message for each day

## Contact Route

### `/contact`

- Accounts with `postingRestricted` and clients on the shared banned-IP list cannot submit the public form; the server enforces the restriction and the form renders with its controls disabled
- Public contact form with name, email, message, and server-side anti-spam checks
- Replies are sent manually from `me@fridge.dev`
- Accepted submissions are stored under `data/contact/*.json`
- Every accepted submission adds an unread in-site notification for each admin account, linking directly to `/contact?dashboard=1`
- The contact notification integration is documented on [Toast](Toast#notifications-and-website-integration)

### `/contact?dashboard=1`

- Admin-only contact submission dashboard
- Lists submissions newest-first
- Supports permanent delete

Retired legacy paths:

- `/email` and `/email/*` redirect to `/contact` in Nginx
- Newsletter and mailing-list routes have been removed

## Other Public Routes

### `/discord`

Simple wrapper page for the Discord community entry point.

### `/merch`

Simple wrapper page for merch links/content.

### `/others`

Misc landing page for routes that do not fit elsewhere.

Subroutes:

- `/others/firefox-theme`
- `/others/off-topic-archive`
- Toast's website routes are documented on [Toast](Toast#discord-service)
- `/others/fridge-builds-websites`

### `/others/firefox-theme`

- Public page for the fridge.dev Blackprint Firefox theme
- Explains the two-step install flow: install the signed theme from Mozilla Add-ons, then run the local userChrome setup for square chrome styling
- `build-downloads.sh` refreshes the downloadable userChrome setup package and the AMO-ready source zip
- The userChrome setup package extracts to a `fridg3-firefox-userchrome` folder containing `userChrome.css`, `install-linux.sh`, `install-windows.bat`, and `install-windows.ps1`
- UserChrome setup scripts prompt for install/update or uninstall; uninstall removes only the fridge.dev profile CSS file and import line
- `userChrome.css` remains outside the add-ons upload package because Firefox WebExtension themes cannot install profile chrome stylesheets

### `/wiki`

Developer-facing documentation rendered from Markdown files in `/wiki/`.

- Uses the normal site shell and replaces its standard navigation links with the wiki page list; the usual sidebar styling, header, player, account greeting, sidebar footer controls, content panel, themes, and mobile layout remain unchanged, while the content page-view footer is omitted
- Shows a `Developer Wiki` indicator in the standard sidebar header; the general developer-mode indicator is hidden on `/wiki` to avoid displaying two development labels, while its hidden marker continues to support developer-only frontend behavior
- Sidebar ordering follows `_Sidebar.md`, with any extra Markdown pages appended after the listed pages
- Renderer supports headings, paragraphs, links, inline code, fenced code blocks, blockquotes, horizontal rules, and simple ordered/unordered lists
- Markdown links, angle-bracket URLs, and plain `http(s)` URLs are automatically rendered as safe links that open in a new tab; URLs inside inline or fenced code remain literal
- Markdown renders directly inside the standard content panel and inherits the active site's normal theme styling; `wiki/content.html` adds no wiki-specific visual theme

### `/tools`

Tools and utilities landing page.

Subroutes:

- `/tools/mdpaste`

Any current or future tool that creates, uploads, or shares user content must enforce both the account `postingRestricted` flag and the shared `data/feed/banned_ips.json` list in every write/API handler. Disabled controls and notices are only the UI layer and must not be the sole enforcement. Read-only tool pages may remain available.

### `/tools/mdpaste`

Markdown paste service for sharing notes without exposing a whole vault. Its editor and shared-paste views use the standard site template, preferred theme, sidebar, and mobile layout.

- Accepts pasted markdown or client-loaded `.md` / `.txt` files
- Uses the standard feed-style eye button to toggle between the editable Markdown source and a formatted preview
- Preview and published links share the site-wide Markdown renderer documented by `/formatting/markdown` and its canonical `formatting/markdown/formatting.md` source
- The horizontally scrolling editor toolbar provides selection-aware shortcuts for common Markdown formatting and supported rich elements, including a compact site-styled heading-level dropdown and an article-header button that inserts supported `title`, `author`, `date`, and `tags` front matter at the start of the document. Markdown textareas use a synchronized, non-editable highlighting layer to colour recognized syntax with the active link colour while preserving native caret, selection, scrolling, resizing, and SPA behavior. These controls stay separate from file upload and preview actions and are hidden while the rendered preview is open
- The renderer preserves nested blockquotes, mixed and Roman-numbered child lists, nested tasks, internal and angle-bracket links, protected inline code, GitHub alerts, Font Awesome `!fa style icon-name` icons, the inline fridge.dev `!frdg` icon, mathematical notation, constrained tables, and feed-style audio/video players
- YAML front matter renders as a journal-style article header using `title`, `author`, `date`, and `tags` while ignoring `draft`; MathJax typesets TeX notation and Mermaid renders fenced flowchart/sequence diagrams after direct or SPA loading
- Security-sensitive HTML tags remain escaped; creating a paste containing one displays an on-site warning with `go back` and `upload anyway` choices, and acknowledging the warning does not enable unsafe rendering
- Supports normal markdown images plus Obsidian-style `![[image.png]]` embeds that point at `/data/images`
- Optional hard-break mode keeps single line breaks in formatted paragraphs
- `POST /tools/mdpaste/` writes temporary paste JSON under `data/mdpaste`
- Accounts with `postingRestricted` and clients on the shared banned-IP list cannot create pastes; the editor controls are disabled and the JSON endpoint independently returns `403`
- Optional password mode encrypts the markdown with AES-256-GCM before storage
- Shared and password-unlock links render through the standard site shell at `/tools/mdpaste/s/{pasteId}`
- Shared paste pages omit the standard page-view footer and do not record or display view counts
- Unlocked shared pastes include subtle download and raw/formatted toggle controls; download filenames prefer the front-matter article title, then the first heading, then a timestamped `mdpaste` fallback
- Shared pastes suppress site notices and include a font control between download and formatting that toggles paste text between the active website font and the bundled Fira Sans family
- Pastes expire after 30 days

### Feed post formatting versions

New top-level feed post files use `v2` as their first line, followed by the existing `@username`, timestamp, and body fields. A `v2` body uses a deliberately limited Markdown renderer supporting bold, italic, underline, strikethrough, highlight, links and uploaded media, blockquotes, inline/fenced code, tables, spoiler text, Font Awesome icons, the inline fridge.dev `!frdg` icon, and nested ordered/unordered lists. Lists are the deliberate syntax-only exception and have no toolbar button; other unsupported Markdown and arbitrary HTML render literally. The shared Markdown toolbar keeps media uploads, pasted-image handling, image cropping, and voice notes; voice recording sits in the scrolling formatting group, while fixed guide and preview buttons remain on the right. The guide opens `/formatting/markdown/feed` in a new tab. Files without the marker remain legacy BBCode posts with their existing renderer, styling, and editor so saving one cannot silently reinterpret its BBCode.

### `/formatting/markdown` and `/formatting/markdown/feed`

These centered, sidebar-free reference pages use the standard Markdown presentation and fixed top-right controls to switch between rendered output and raw source or toggle the content between the website font and Fira Sans. `/formatting/markdown` is the canonical site-wide reference backed by `formatting/markdown/formatting.md`. `/formatting/markdown/feed` renders its route-local source through the restricted v2 feed renderer, including literal examples of unsupported syntax so the boundary remains visible and testable.

### `/others/off-topic-archive`

Frontend archive viewer backed by `data/etc/off-topic-archive.json`.

### Toast Routes

Toast status, controls, radio playback, the admin DM inbox, AI conversation behavior, and Discord integrations are documented on [Toast](Toast).

### `/others/fridge-builds-websites`

Wrapper/marketing page for custom website work. This exists in code even though the older docs mostly ignored it.

## Formatting / Examples / Errors

- `/formatting`
- `/formatting/example_page`
- `/error/403`
- `/error/404`
- `/error/50x`
- `/error/wip`
