# Routes and Features

## Core Content Routes

### `/`

homepage with dynamic latest feed, latest journal, and music cards.

### `/feed`

- list/search/paginate feed posts from `data/feed/*.txt`
- create visibility depends on admin or `allowedPages` containing `feed`
- create composer supports recorded voice notes; accepted recordings request browser noise suppression, echo cancellation, and auto gain when available, are previewed before posting, capped at 2 minutes, transcoded to compressed `.m4a`, stored under `data/audio/voice/`, and played with inline controls that include a `1x`/`1.5x`/`2x` speed toggle
- the first time a browser submits a new feed post, an in-site popup asks whether to enable browser notifications for replies
- deleting a feed post removes voice note files referenced by the post body and its replies
- writes derived `index.toml`
- `@mentions` in BBCode are highlighted client-side for notification-aware feed posts

Related:

- `/feed/create`
- `/feed/edit`
- `/feed/posts/{id}`

### `/feed/create`

- requires admin or `allowedPages` containing `feed`
- hardcoded `toast` sees a Groq-powered post generator before the BBCode editor
- Toast generation can be random or prompt-guided, uses already-published non-Toast feed posts only as weak text style samples, has a 5-step length slider from one-liner to trauma dump, then fills and unlocks the editor
- if a non-Toast post mentions `@toast`, Toast may automatically add a reply to that post using the post as context after a 1 minute delay

### `/feed/posts/{id}`

- single-post thread view for a feed item
- logged-in users can reply to the post or to an individual comment with BBCode, image uploads, and recorded voice notes using the same inline speed-toggle playback controls
- guests can reply to the post or to an individual comment without creating feed posts; they are identified by plaintext IP, may enter an optional display name that falls back to italic `Anonymous`, cannot use a registered account username as that display name, can link images but cannot upload files or voice notes, and do not get heading or tooltip BBCode controls; guest display names and reply bodies are filtered through `/feed/filters/*.txt`, matching body text is replaced with `★` plus the tooltip `this phrase was automatically filtered.`, and the BBCode preview shows the same filtering
- the first time a browser submits a comment, an in-site popup asks whether to enable browser notifications for replies to comments
- guest replies that are mostly filter-list terms are rejected, and guest replies containing filtered text cannot be edited by guests after posting
- reply edit/delete is allowed for the reply author, same-IP guest replies, admins, the original post owner, or accounts with `allowedPages` containing `comments`
- replies persist under `data/feed/replies/{postId}.json`; comment replies stay in the same flat list with optional `parentId` metadata and render directly beneath their parent comment
- guest replies can store a same-browser notification token so logged-out visitors can receive browser notifications when someone replies to their comments
- deleting a reply removes voice note files referenced by that reply
- admin IP moderation actions appear beside guest reply edit/delete icons; admins can ban an IP or purge guest replies by exact plaintext IP without deleting the feed post or changing the IP ban list
- when a non-Toast user replies to a Toast-owned post or mentions `@toast` in a reply, Toast may automatically reply after a 1 minute delay with a short old-style Twitter-sized response and starts by mentioning that user

### `/journal`

- list/search/paginate journal posts from `data/journal/*.txt`
- create visibility depends on admin or `allowedPages` containing `journal`
- published journal bodies are trusted HTML
- preview/edit flows support draft files and optional `FORMAT:html`

Related:

- `/journal/create`
- `/journal/create/preview`
- `/journal/edit`
- `/journal/edit/preview`
- `/journal/posts/{id}`

### `/guestbook`

- list entries from `data/guestbook/*.txt`
- one-post-per-IP gate via `data/guestbook/ip_index.json`
- new entries store an `IP:` metadata line; IPs in the shared feed ban list cannot submit guestbook posts
- owner/admin edit and delete flow
- admins can ban an entry IP or password-confirm a purge of both feed replies and guestbook posts associated with that IP

Related:

- `/guestbook/create`
- `/guestbook/edit`

### `/music`

- builds album grids from `data/music/frdg3/*.json` and `data/music/cactile/*.json`
- songs reference `data/audio/*`
- integrates with the shared mini player; album and other multi-track release clicks open an on-site popup track picker, while single-track releases play directly
- admins see upload buttons for each artist; `/music/upload` saves audio files to `data/audio/` and creates release JSON in that artist's `data/music/{artist}/` folder
- `/music/upload` supports `single`, `remix`, and `album` release types; all release types can add multiple track rows and reorder them before saving, can optionally set a scheduled publish date/time, and release order is assigned automatically from the current highest order for the selected artist

### `/gallery`

- paginated listing of `data/images/*`
- admin delete actions call `/api/gallery/delete`

### `/bookmarks`

- server-rendered bookmark listing for logged-in users
- client-side localStorage enhancement for anonymous users
- supports feed and journal bookmark ids; legacy `newsletter:*` ids are ignored

### `/tools/upload`

- displayed as `serverless upload`; the route remains stable for existing transfer links
- browser-to-browser encrypted file transfer using WebRTC data channels
- accounts with `postingRestricted` and clients on the shared banned-IP list cannot create, join, or signal upload rooms; the page disables its controls and every API action independently enforces the restriction
- PHP stores only short-lived room/signaling metadata under `data/upload/rooms.json`; uploaded file bytes are never stored server-side
- creating a room chooses whether the creator is the sender or receiver, then produces a `/tools/upload/?r={token}` share link
- access is limited to the creator browser plus the first guest browser through the HttpOnly `fridg3_upload_peer` cookie; later browsers receive `room_full`
- peers exchange ephemeral ECDH public keys through signaling and encrypt file chunks with AES-GCM before sending
- plaintext chunks are kept below WebRTC's common 64 KiB message edge after encryption overhead to avoid truncated or dropped data-channel frames
- sender and receiver compute a streaming SHA-256 checksum; the receiver only sends the success ack when file size, chunk count, and checksum match
- enforces a 100 GB client-side file limit; browsers with File System Access API support stream received chunks to disk, while fallback browsers download after completion
- both peers see transfer progress; sender progress reaches 100% only after the receiver confirms completion, and receiver progress reaches 100% after the file is written or downloaded, so either side can close the page once their bar is full
- rooms end when either peer closes the tab via a close beacon, with a short heartbeat timeout fallback for crashed or disconnected tabs

### `/settings`

- UI shell only
- persistence handled by `/api/settings`
- changing a settings control marks the page dirty; link navigation to another page uses an in-site confirmation popup whose primary action saves before continuing, while saving and refreshing the current page never prompt
- successful saves briefly change the main button label to `saved!`; theme or mobile-view changes then reload automatically using the newly persisted preference
- includes accessibility toggles for mobile view and reduced motion, notification toggles for feed events and new journal posts, plus theme selection, a text glow toggle, optional cursor cat, and an in-site title-animation picker with live previews, always-playing mode, and default-on character desync
- title animation controls and previews are disabled with an explanatory message whenever reduced motion or mobile view is active; mobile view also disables the title gradient
- browser notifications use the Notification API while the site is open; logged-in users can receive feed mention/reply events matching Toast Discord feed DMs, guests can receive replies to comments made from the same browser, and both can receive new journal post alerts; logged-in notification events are deduped server-side so a fresh browser login does not replay old matching events
- developer mode can bootstrap a blank-password `admin` / `Administrator` account when no admin accounts exist, and can download the latest sanitized developer data zip, delete local `data/`, and install the new copy
- shows a Discord linking action for logged-in users and disables it once `discordUserId` is already linked
- when logged in as hardcoded `toast`, shows a JSON editor for shared Toast personalities stored in `data/etc/toast-personality.json`
- admins can open a dedicated system diagnostics subsection in `/settings` to jump into `/settings/sysinfo`
- admins can open `/settings/guests`, labeled as manage guests, to review guest feed replies and IP-backed guestbook posts grouped by IP, search by IP or username, individually delete either content type, ban or unban IPs across both posting surfaces, or purge all guest content from an IP after password confirmation
- admins can open `/settings/banned-ips` to edit the separate hard-ban IP list; valid IPv4 and IPv6 addresses may be separated by spaces or newlines, and nginx redirects matching clients away from all pages and static files to `/error/blacklisted`; a durable first-party browser identifier carries an active ban to later IPs, while removing the original manually banned IP releases its associated IPs
- `/error/blacklisted` returns the stripped Blackprint denial page only to an actively hard-banned IP or associated browser identity; other direct visitors receive a server-side redirect to `/`
- admins can open `/settings/sysinfo` to see live system diagnostics, PHP/runtime details, storage usage, website state, and key content counts in a dashboard-style view

## Account Routes

### `/account`

currently just redirects:

- logged in -> `/`
- logged out -> `/account/login`

### `/account/login`

- secure session config
- CSRF protection
- login throttling via `data/accounts/login_attempts.json`
- reads `data/accounts/accounts.json`
- sets session user payload and `is_admin` cookie
- session cookies use the `fridg3_session` name, last 90 days, use `SameSite=Lax`, and are shared across `fridge.dev`, `www.fridge.dev`, `m.fridge.dev`, and other `*.fridge.dev` hosts so mobile/desktop host switches do not look like a logout
- successful login/logout clears the legacy `PHPSESSID` cookie on both the shared domain and current host so stale host-only cookies cannot shadow the shared session
- username `toast` is reserved for a hardcoded virtual account; it prompts for admin credentials, then logs in as non-admin Toast with fixed `feed` and `comments` permissions
- users with `mustResetPassword` are redirected into the password-change flow before using the rest of the site

### `/account/logout`

destroys session and auth cookies, then redirects back to login.

### `/account/create`

admin-only account creation flow that writes to `data/accounts/accounts.json`.

- can seed `discordUserId`
- can seed an optional `emailAddress` for fridge.dev mailboxes
- can grant `comments` and `chat` permissions
- can create the account with `postingRestricted` already enabled
- newly created accounts are flagged with `mustResetPassword`
- username `toast` is reserved and cannot be created as a normal account
- if a Discord id is provided, it asks the local toast bot to DM the invite credentials
- if that DM fails, the account is still created and the UI now shows the bot's concrete failure reason instead of a generic HTTP 500
- local dev mode shows a random dev-account generator that creates `userXXXX` / `User #XXXX` with feed/comment permissions, a blank password, no forced password reset, and no Discord invite

### `/account/change-password` and `/account/password`

both update the current user password hash in `accounts.json`.

- first-login forced password reset lands here via `?first_login=1`

### `/account/link-discord`

- logged-in-only Discord linking flow
- validates the Discord user id, checks uniqueness across accounts, and asks the local toast bot to verify the member is in the server
- stores `discordUserId` on the account and assigns the Discord `registered` role through the bot

### `/account/admin`

not covered in the older references, but very real.

- admin-only account directory
- reads all accounts and renders permission badges
- links to per-account edit page

### `/account/admin/edit`

- admin-only account editor
- supports rename, display-name change, optional `emailAddress`, permission changes, reset password, and delete
- delete confirmation plays a centered rip-in-half account card animation before the destructive POST continues
- the `purge user content` danger button must purge all user-owned content; currently this includes feed posts, attached images, voice notes, and reply data
- preserves unknown extra account fields through an editable JSON object field
- blocks deleting the currently logged-in account
- includes `comments` and `chat` as grantable `allowedPages` permissions
- includes a `restricted from posting` checkbox backed by `postingRestricted`; restricted accounts retain their page permissions and deletion/moderation access, but cannot create or edit posts, replies, chat messages, or guestbook entries
- password resets now preserve the account and flip `mustResetPassword` back on

Helpers live in `account/admin/helpers.php`.

### `/account/email`

- shows fridge.dev email web-client and custom-client setup details
- if the logged-in account has a valid `emailAddress`, the page shows that assigned fridge.dev address near the top
- shared shell rendering swaps the footer Discord button to this route only for accounts with a valid `emailAddress`

## Private Chat Routes

### `/chat`

one-time private conversation manager.

- requires admin or `allowedPages` containing `chat`
- creates conversations with a recipient label
- lists active conversation files from `data/chat/*.json`
- shows canonical share links shaped like `https://fridge.dev/chat/{conversationId}` that copy to clipboard when clicked
- can end a conversation through an in-site confirmation popup, which deletes the encrypted JSON file immediately

### `/chat/{conversationId}`

one-to-one conversation view.

- managers can open without claiming recipient access
- the first non-manager visitor sees a concise chat invite/auth page and receives an HttpOnly recipient cookie
- the recipient's first full chat view shows an in-site security/help popup explaining browser/account locking, encrypted storage, replies, and reactions
- later visits from that browser are allowed through
- if the recipient is logged into an account when they open an unclaimed invite, the chat links to that account instead of a browser cookie
- logged-in recipients with an active linked chat get a sidebar button above the mini-player/sidebar footer and can delete that chat themselves
- other browsers without the matching cookie get a custom access-denied page
- if the backing file is deleted, returning recipients see the ended-conversation page
- messages are stored inside the encrypted per-conversation JSON envelope under `data/chat`
- image/file attachments up to 8 MB are stored as encrypted per-chat blobs and served only after chat access checks
- the composer `+` menu supports file upload or recording a voice note; voice notes are previewed before send, capped at 2 minutes, transcoded to compressed `.m4a`, and stored as encrypted chat attachments
- selecting an attachment shows an attached-file indicator before send; image attachments use the site image viewer, while audio, voice, and video attachments embed with custom themed playback controls inside the chat; audio/voice controls include the `1x`/`1.5x`/`2x` speed toggle
- messages can visually reply to a previous message, and clicking/tapping a message opens reply/react/delete actions; message deletion uses an in-site confirmation popup, and deleted messages stay in place as dimmed `message deleted` placeholders
- reactions are emoji-based, searchable from the message context menu or the desktop-only emoji button beside the composer; the picker loads Emoji 16 Emojibase data from jsDelivr, lazy-renders results as users search/scroll, supports typed or pasted emoji from the search box, and falls back to a tiny local set if unavailable
- both sides send active/away presence heartbeats plus short-lived typing state, and the page live-polls whether the other side is online, away, or offline while showing a non-layout-shifting typing indicator inside the message box
- message sends update the current page immediately, and open chat pages poll for new messages; unfocused/hidden chat tabs play `/chat/alert.ogg` and prefix the page title with an unread count when the other side sends new messages
- message timestamps show time only, with a date divider inserted at the first message for each day

## Contact Route

### `/contact`

- accounts with `postingRestricted` and clients on the shared banned-IP list cannot submit the public form; the server enforces the restriction and the form renders with its controls disabled
- public contact form with name, email, message, and server-side anti-spam checks
- replies are sent manually from `me@fridge.dev`
- accepted submissions are stored under `data/contact/*.json`
- after storage, PHP asks the local toast service to send a Discord channel notification

### `/contact?dashboard=1`

- admin-only contact submission dashboard
- lists submissions newest-first
- supports permanent delete

Retired legacy paths:

- `/email` and `/email/*` redirect to `/contact` in nginx
- newsletter and mailing-list routes have been removed

## Other Public Routes

### `/discord`

simple wrapper page for the Discord community entry point.

### `/merch`

simple wrapper page for merch links/content.

### `/others`

misc landing page for routes that do not fit elsewhere.

Subroutes:

- `/others/firefox-theme`
- `/others/off-topic-archive`
- `/others/toast-discord-bot`
- `/others/fridge-builds-websites`

### `/others/firefox-theme`

- public page for the fridge.dev blackprint Firefox theme
- explains the two-step install flow: install the signed theme from Mozilla Add-ons, then run the local userChrome setup for square chrome styling
- `build-downloads.sh` refreshes the downloadable userChrome setup package and the AMO-ready source zip
- the userChrome setup package extracts to a `fridg3-firefox-userchrome` folder containing `userChrome.css`, `install-linux.sh`, `install-windows.bat`, and `install-windows.ps1`
- userChrome setup scripts prompt for install/update or uninstall; uninstall removes only the fridge.dev profile CSS file and import line
- `userChrome.css` remains outside the add-ons upload package because Firefox WebExtension themes cannot install profile chrome stylesheets

### `/wiki`

developer-facing documentation rendered from Markdown files in `/wiki/`.

- uses the site shell while hiding normal navigation so the docs can use a full-height two-column layout
- sidebar ordering follows `_Sidebar.md`, with any extra Markdown pages appended after the listed pages
- renderer supports headings, paragraphs, links, inline code, fenced code blocks, blockquotes, horizontal rules, and simple ordered/unordered lists
- blackprint-specific styling lives in `wiki/content.html`, with a sticky sidebar, constrained reading width, themed code blocks, and a compact mobile page grid

### `/tools`

tools and utilities landing page.

Subroutes:

- `/tools/mdpaste`

Any current or future tool that creates, uploads, or shares user content must enforce both the account `postingRestricted` flag and the shared `data/feed/banned_ips.json` list in every write/API handler. Disabled controls and notices are only the UI layer and must not be the sole enforcement. Read-only tool pages may remain available.

### `/tools/mdpaste`

standalone markdown paste service for sharing notes without exposing a whole vault.

- accepts pasted markdown or client-loaded `.md` / `.txt` files
- live previews markdown before publishing
- supports normal markdown images plus Obsidian-style `![[image.png]]` embeds that point at `/data/images`
- optional hard-break mode keeps single line breaks in formatted paragraphs
- `POST /tools/mdpaste/` writes temporary paste JSON under `data/mdpaste`
- accounts with `postingRestricted` and clients on the shared banned-IP list cannot create pastes; the editor controls are disabled and the JSON endpoint independently returns `403`
- optional password mode encrypts the markdown with AES-256-GCM before storage
- shared links render from `/tools/mdpaste/s/{pasteId}`
- pastes expire after 30 days

### `/others/off-topic-archive`

frontend archive viewer backed by `data/etc/off-topic-archive.json`.

### `/others/toast-discord-bot`

UI shell for toast bot status, controls, and stream playback.

The bot also exposes localhost-only service endpoints on `127.0.0.1:8765`, including contact submission notifications to Discord channel `1503931489560301609`.
It also scans `/feed` activity for linked Discord accounts and sends DMs for post mentions, reply mentions, and replies to a user's own feed posts.
It also receives deploy-time patch notices, posts the fully formatted patch notice preview to approval channel `1526075637096255548`, and posts to update channel `1455194403642802309` with role `1408064850688475197` only after an admin approves with `✅`. Pending approvals survive bot restarts, and reactions on older uncached Toast approval messages are handled as well.
Admins can also use `/shareupdate latest` or `/shareupdate <commit ID>` in Discord to manually post a patch notice for the deployed `HEAD` commit or a specific commit SHA.

### `/others/toast-discord-bot/messages`

- admin-only DM inbox/sender for toast with a thread inbox and full-page conversation view with a back button
- renders through the normal site template and mobile/desktop theme selection instead of a standalone Discord-style shell
- reads tracked DM history, resolves linked website usernames to Discord ids, and can send outbound DMs through the local bot service
- inbound user DMs are logged and can receive Groq-powered Toast replies using the local `personality.json`; when users ask about fridge.dev, the bot can include small relevant snippets from the wiki and explain them in plain language
- rapid inbound DMs from the same user are batched into one AI prompt; if Toast is still generating or pacing an unsent reply chunk when another DM arrives, it cancels the unfinished reply and regenerates from the queued messages
- admins can toggle an "air them" state per thread; aired users are still logged, but Toast does not generate AI replies for them
- if the Discord user is linked to a fridge.dev account, AI replies also receive compact context from that account's own recent feed posts and replies
- image and GIF DMs are sent to Groq's configured vision model as Discord attachment URLs, capped at 5 images and 20 MB per image
- AI replies are split into natural 2-4 sentence chunks and wait at least 5 seconds before each chunk is sent
- a user can send exactly `CLEARMEMORY` in DM to make Toast react and ignore older DM history for future AI context
- AI replies are also told about Toast's non-chat duties: radio playback, slash-command radio controls, account-linking support, and automated notification DMs
- AI replies are given an exact slash-command allow-list so website paths like `/feed` are not described as Discord commands
- guild messages and notification DMs do not trigger AI replies

### `/others/fridge-builds-websites`

wrapper/marketing page for custom website work. this exists in code even though the older docs mostly ignored it.

## Formatting / Examples / Errors

- `/formatting`
- `/formatting/example_page`
- `/error/403`
- `/error/404`
- `/error/50x`
- `/error/wip`
