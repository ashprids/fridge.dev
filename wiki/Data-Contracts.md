# Data Contracts

For how account restrictions, posting IP bans, hard bans, and browser/IP propagation interact, see [Account Restrictions and IP Bans](Account-Restrictions-and-IP-Bans).

## Big Rule

runtime data lives under `/data`.

repo intent:

- `/data` is not supposed to be versioned normally
- `.gitignore` ignores `/data/**/*`
- `.rsyncignore` excludes `/data/**` from deployment sync

so local/dev/prod data has to be managed separately.

## `data/accounts/`

### `accounts.json`

expected top-level shape:

```json
{
  "accounts": [
    {
      "username": "string",
      "name": "string",
      "password": "bcrypt-hash or empty",
      "isAdmin": true,
      "postingRestricted": false,
      "mustResetPassword": false,
      "discordUserId": "optional discord snowflake string",
      "emailAddress": "optional @fridge.dev email address",
      "allowedPages": ["feed", "journal", "comments", "chat"],
      "bookmarks": ["2026-01-01_12-00-00", "journal:12"],
      "theme": "default|classic|theme-id",
      "glowIntensity": "none|medium",
      "onekoEnabled": true,
      "reduceMotion": false,
      "titleAnimation": "wobble",
      "titleAnimationAlways": false,
      "titleAnimationDesync": true,
      "browserNotificationsEnabled": true,
      "journalBrowserNotificationsEnabled": true,
      "colors": {
        "bg": "#RRGGBB",
        "fg": "#RRGGBB",
        "border": "#RRGGBB",
        "subtle": "#RRGGBB",
        "links": "#RRGGBB"
      }
    }
  ]
}
```

notes:

- extra unknown keys can exist and are preserved by `account/admin/edit`
- bookmarks are the current source of truth for logged-in users
- bookmark ids currently use raw feed ids and `journal:{id}`; legacy `newsletter:{id}` values can exist but are ignored
- `theme: default` is blackprint and uses the base template plus `/style.css`; `theme: classic` enables saved `colors` for `bg`/`fg`/`border`/`subtle`/`links`; `theme: ambercrt` is shown as `CRT` and uses only saved `colors.links` as its main phosphor color; any other valid value refers to a `/themes/{theme-id}.json` file with `name`, `description`, `thumbnail`, `html`, and `css`
- legacy `blackprint` normalizes to `default`, `custom` normalizes to `classic`, `newsprint` normalizes to `whiteprint`, `crt` normalizes to `ambercrt`, and removed `liminal`/`syswave` preferences normalize to `default`
- text glow is stored in `glowIntensity`; the settings UI writes `none` for off and `medium` for on, while legacy `low`/`high` values are treated as enabled medium glow when saved again
- title motion is stored in `titleAnimation` (`wobble`, `bounce`, `rubberhose`, `bubble`, `slot-machine`, `moonwalk`, or `heartbeat`), `titleAnimationAlways` (boolean), and `titleAnimationDesync` (boolean, default `true`); removed `pinball` values migrate to `wobble`; legacy `orbit`, `domino`, and `lava-lamp` values migrate to `bubble`; removed `tidal-wave`, `accordion`, and `typewriter` values migrate to `slot-machine`, while `helicopter`, `haunted`, and `juggle` migrate to `moonwalk`; guests keep the same values in local storage
- accessibility toggles are stored as account booleans such as `reduceMotion`; logged-out browsers keep the same preferences in localStorage
- `browserNotificationsEnabled` stores the account-backed preference for browser feed notifications; `journalBrowserNotificationsEnabled` stores the account-backed preference for new journal post browser notifications; logged-in notification dedupe state is stored in `data/etc/feed-browser-notify-state.json`, while logged-out browsers keep the same preferences and dedupe state in localStorage
- `mustResetPassword` is used by the shared session bootstrap to force first-login password changes
- `postingRestricted` is an admin-managed account boolean; when enabled, server handlers reject new or edited feed posts, journal posts/drafts, feed replies, chat conversations/messages, guestbook entries, contact submissions, mdpaste creation, and upload room/signaling use, while matching composer notices keep text fields, BBCode controls, uploads, and submit controls disabled
- `discordUserId` links a site account to a Discord member for bot DMs and notifications
- `emailAddress` marks accounts with a fridge.dev email mailbox; when present and valid, shared chrome swaps the footer Discord button to `/account/email`, and `/account/email` shows the assigned address
- `allowedPages` currently includes functional grants like `feed`, `journal`, `comments`, and `chat`

## `data/chat/`

one-time private conversations live as individual encrypted JSON envelopes:

```json
{
  "version": 1,
  "cipher": "aes-256-gcm",
  "nonce": "base64",
  "tag": "base64",
  "ciphertext": "base64"
}
```

notes:

- each file is named `{conversationId}.json`; new chat ids are 9 lowercase letters/numbers
- legacy 32-character lowercase hex ids are still accepted so older active links do not break
- decrypted payloads contain conversation metadata, the recipient label in `name`, the recipient cookie hash, and message records
- messages may include an `attachment` object with encrypted blob metadata: `id`, `name`, `mime`, and `size`; image/audio/video attachments are served inline through the authorized chat route so they can render or play in the chat UI
- messages may include `replyTo` with another message id, plus `reactions` keyed by valid emoji sequences with active viewer roles such as `manager` or `participant`
- conversations may include `participantUsername` when a logged-in account claims the invite, or `participantHash` when an anonymous browser cookie claims it; the first-open popup copy changes based on that claim type
- conversations may include `recipientIntroSeenAt` once the recipient has seen the first-open security/help popup
- recipient cookies are HttpOnly and scoped to `/chat`
- the first non-manager account or anonymous browser to open `/chat/{conversationId}` claims the recipient slot
- account-linked recipients can delete their own active chat; anonymous cookie-linked recipients cannot
- admins and accounts with `allowedPages` containing `chat` can create, view, and delete conversations without claiming the recipient slot
- deleting a conversation unlinks the encrypted JSON file immediately
- encryption uses `FRIDG3_CHAT_KEY` when set; otherwise the app creates `data/chat/.chat_key`
- lightweight presence indicators use sidecar files under `data/chat/.presence/{conversationId}.json`; current entries store `lastSeen`, `active`, and a short-lived `typingUntil`, while older timestamp-only entries are still readable
- attachments are encrypted AES-256-GCM envelopes under `data/chat/.attachments/{conversationId}/`; they are served only through the authorized chat route and are deleted with the conversation
- attachment uploads are capped at 8 MB

## `/themes/`

theme metadata lives as JSON files directly under `/themes`.

```json
{
  "name": "Theme Name",
  "html": "template-file.html",
  "css": "stylesheet-file.css"
}
```

notes:

- the metadata filename is the saved theme id, for example `/themes/cool.json` becomes `cool`
- `name` is the label shown in `/settings`
- `description` is the short supporting text shown under the theme name in the picker
- `thumbnail` is a 4:3 preview path relative to `/themes`, usually `thumbnails/{theme-id}.svg`
- `html` and `css` must be relative paths in `/themes/lib`, for example `aero/aero.html` and `aero/aero.css`
- theme asset paths cannot be absolute, contain `..`, or use characters outside letters, numbers, `.`, `_`, `-`, and `/`
- desktop rendering uses both themed HTML and CSS
- mobile rendering keeps `template_mobile.html` and only swaps the CSS

### `login_attempts.json`

- map of client IP -> unix timestamp array
- used for login throttling

## `data/feed/`

feed post format:

1. `@username`
2. `YYYY-MM-DD HH:MM:SS`
3. body text / BBCode

other file:

- `index.toml` is generated by `/feed/index.php`

feed bodies can include public voice notes as BBCode:

```bbcode
[audio=/data/audio/voice/example.m4a][name:voice-note.m4a]
```

voice notes are created from temporary `[voice:N]` editor placeholders, verified at upload time, transcoded to small mono `.m4a` files, and stored under `data/audio/voice/`.

### `data/feed/replies/`

per-post replies live in `{postId}.json` files shaped roughly like:

```json
{
  "replies": [
    {
      "id": "20260413153000_deadbeef",
      "username": "Anonymous",
      "date": "2026-04-13 15:30:00",
      "body": "reply body with BBCode",
      "parentId": "optional parent reply id for comment replies",
      "isGuest": true,
      "ip": "203.0.113.10",
      "guestBrowserId": "optional same-browser notification token"
    }
  ]
}
```

notes:

- reply ids are generated on write; older data may be normalized into `legacy_*` ids at read time
- replies to individual comments are stored in the same flat array with optional `parentId`; older top-level replies simply omit it
- guest replies may include `guestBrowserId`, a random browser-local token used only so guests can receive browser notifications when someone replies to their comments from another browser/account
- reply bodies can contain image BBCode that points at `/data/images/*`
- new reply bodies can also contain voice note audio BBCode that points at `/data/audio/voice/*`
- guest replies include `isGuest: true` plus a plaintext `ip`; guest display names are stored in `username`, default to `Anonymous`, cannot match a registered account username case-insensitively, and are filtered with guest reply bodies through `/feed/filters/*.txt` before storage; matching body text becomes tooltip-wrapped `★` text explaining `this phrase was automatically filtered.`; guest replies that are mostly filter-list terms are rejected, and guest replies containing filtered text are locked from later guest edits; admin moderation can purge all guest replies with a matching IP without changing the IP ban list
- sanitized developer data copies blank guest reply `ip` values, remove guest reply browser notification tokens, remove `IP:` metadata from guestbook entry files, clear guestbook ownership mappings, and clear the shared feed/guestbook IP ban list before the copy is zipped
- automatic Toast replies are stored as normal `username: "toast"` replies when a user replies to Toast's post or mentions `@toast`; generated Toast replies begin by mentioning the triggering user and are delayed by 1 minute before posting

### `data/feed/banned_ips.json`

IP-keyed posting ban list shared by feed replies, guestbook submissions, the contact form, mdpaste creation, and upload room/signaling use, and managed through `/settings/guests`.

- entries can record ban metadata and usernames seen for that IP
- purging an IP's feed replies and guestbook posts is a separate action and does not add, remove, or mutate ban entries
- the manage guests page also scans `data/feed/replies/*.json` for `isGuest: true` replies and groups them by plaintext `ip`

### `data/etc/hard-banned-ips.txt`

Site-wide hard-ban list managed by the admin-only `/settings/banned-ips` editor.

- contains exact IPv4 or IPv6 addresses separated by whitespace; saves normalize the list to one address per line and reject invalid tokens
- this list is separate from posting bans in `data/feed/banned_ips.json`
- nginx checks it through the internal hard-ban authorization endpoint before serving pages or static files and redirects matches to `/error/blacklisted`
- direct web access to the list and to the checker is blocked; `/error/blacklisted` uses self-contained local templates because hard-banned clients cannot load global website assets
- hard-banned clients may additionally load font files beneath `/resources`; all other shared assets remain unavailable
- the developer-data sanitizer empties this file and the publishing archive excludes it entirely

### `data/etc/hard-ban-identities.json`

Private browser-to-IP associations used to carry an active hard ban across IP changes.

- a random first-party identifier is mirrored in a five-year cookie and browser local storage; the server stores its original manually banned IP, observed IPs, timestamps, and a hash of the user agent
- when an identifier tied to an active original IP appears from a new IP, the new IP is appended to `hard-banned-ips.txt`
- removing the original IP through `/settings/banned-ips` removes every automatically associated IP and the corresponding identifier records
- direct client access is blocked; the developer-data sanitizer replaces the file with an empty `identities` object and the publishing archive excludes it entirely

### `data/etc/banlists/**/*.txt`

Read-only hard-ban source lists. Every valid whitespace-separated IPv4 or IPv6 address or CIDR subnet from every `.txt` file anywhere beneath this directory is merged with the manual hard-ban set. Subdirectories are scanned recursively. IPv4 prefixes from `/0` through `/32` and IPv6 prefixes from `/0` through `/128` are supported.

- source entries are enforced without appearing in the `/settings/banned-ips` textarea or being copied to `hard-banned-ips.txt`
- unreadable files and subdirectories, non-text files, and invalid tokens are ignored without disabling the other source lists
- PHP-FPM builds a stat-signature-keyed binary range index beneath `data/etc/banlists/index/` and reuses it until a source path, inode, size, modification time, or change time differs
- `data/etc/banlists/index/` must be writable by the PHP-FPM user; its binary files are not treated as source lists because discovery accepts only `.txt` files
- the next locked index access removes interrupted `.build-*` directories, while completed superseded signature versions are retained for one hour before pruning so in-flight requests can finish safely
- index construction is streaming and bounded-memory; if index creation fails, checks fall back to scanning the source files in fixed-size chunks
- nginx blocks the entire directory from client access
- the developer-data sanitizer clears the directory and the publishing archive excludes its contents

## `data/journal/`

published journal post:

1. `YYYY-MM-DD`
2. title
3. description
4. trusted HTML body

draft format:

1. `USER:<username>`
2. title
3. description
4. optional `FORMAT:html`
5. draft body

without `FORMAT:html`, preview treats the body as BBCode.
with it, preview treats the body as raw HTML.

## `data/guestbook/`

entry format:

1. timestamp
2. display name
3. optional `IP:<address>` metadata for new entries
4. message body (line 3 for legacy or sanitized entries without IP metadata)

plus:

- `ip_index.json` for one-post-per-IP ownership tracking
- nginx blocks direct client access to `/data/guestbook` and its descendants; entries are exposed only through the PHP guestbook and admin moderation views
- the sanitizer removes entry `IP:` metadata and clears `ip_index.json` while retaining the public guestbook messages

## `data/images/`

- uploaded images used across feed, journal, and gallery content
- expected web path is `/data/images/<filename>`

## `data/music/`

artist folders currently include:

- `frdg3`
- `cactile`

album JSON shape:

```json
{
  "album_name": "string",
  "album_caption": "string",
  "album_type": "Album|EP|Single|Remix|...",
  "album_art": "/data/images/example.jpg",
  "album_art_directory": "/data/images/example.jpg",
  "scheduled_at": "2026-07-06T21:30:00+01:00",
  "order": 6,
  "songs": [
    { "name": "Track", "directory": "/data/audio/file.wav" }
  ]
}
```

`album_art_directory` is preferred by current code.

The `/music/upload` admin page writes audio files to `data/audio/`, cover art to `data/images/`, and creates release JSON in the selected artist folder. `single`, `remix`, and `album` uploads can store multiple tracks, preserving the submitted row order in `songs`. Release `order` is assigned automatically as one higher than the current highest order in that artist folder. Admins can optionally set `scheduled_at` with a publish date/time; future-dated releases stay hidden from non-admins on `/music` until that timestamp, while admins can still see them with a scheduled label. Uploaded audio accepts `mp3`, `wav`, `m4a`, `ogg`, and `flac` with no app-level size cap; uploaded cover art accepts `jpg`, `png`, `gif`, and `webp`. The deployed nginx and PHP-FPM config must also allow unlimited request bodies; local PHP dev servers may need equivalent `-d upload_max_filesize=0 -d post_max_size=0` startup flags because they do not always read `.user.ini`.

## `data/audio/`

- track files referenced by music metadata
- also used by shared playback features
- `data/audio/voice/` stores public feed voice notes as compressed `.m4a` files

## `data/contact/`

- private contact submissions as `{YYYYMMDDHHMMSS}_{random}.json`
- each submission stores `id`, `createdAt`, hashed IP, user agent, name, email, message, notification channel id, and optional `notifyError`
- `rate_limits.json` stores hashed client IP keys mapped to recent submission timestamps for throttling
- nginx blocks direct web access to this directory; submissions are only shown through the admin-only `/contact?dashboard=1` route

## `data/mdpaste/`

- temporary markdown paste records as `{id}.json`
- ids are 16 lowercase hex characters
- records expire after 30 days and are cleaned up opportunistically on create/view
- unencrypted records store a `markdown` string
- encrypted records store only AES-256-GCM ciphertext plus PBKDF2-SHA256 salt/nonce/tag metadata; the password is never stored
- `hard_breaks` controls whether single paragraph newlines render as `<br>` instead of spaces

## `data/etc/`

### `wip`

- plain text maintenance flag
- truthy values such as `true`, `1`, `yes`, `on`, `enabled`, or `wip` enable maintenance mode
- enforced server-side by `lib/session.php` and `lib/render.php`; non-admin requests redirect to `/error/wip`

### `webhooks.json`

used key:

```json
{
  "discord_feed": "https://discord.com/api/webhooks/..."
}
```

### `toast.json`

expected shape:

```json
{
  "bot": { "token": "...", "client_id": "...", "status": "online|offline" },
  "stream": { "url": "http(s)://...", "name": "..." },
  "channel": { "id": "...", "name": "..." },
  "features": { "auto_play": true, "loop": true },
  "groq": {
    "api_key": "...",
    "model": "llama-3.1-8b-instant",
    "website_model": "llama-3.3-70b-versatile",
    "vision_model": "meta-llama/llama-4-scout-17b-16e-instruct",
    "temperature": 0.8,
    "top_p": 0.95,
    "max_completion_tokens": 700,
    "timeout_seconds": 30,
    "max_history_messages": 12,
    "max_vision_images": 5
  }
}
```

`groq` powers Toast's AI replies to direct messages, the hardcoded Toast feed generator, and automatic Toast feed replies. If `api_key` is empty, Toast still logs inbound DMs but skips the AI reply, the feed generator returns an error instead of drafting, and automatic feed replies are skipped. `model` defaults to `llama-3.1-8b-instant` and is used by the Discord Toast DM flow. `website_model` defaults to `llama-3.3-70b-versatile` and is used by website Toast feed generation and automatic feed replies; legacy `feed_model` is also accepted as a fallback name. Image/GIF DMs use `vision_model`, defaulting to Groq's `meta-llama/llama-4-scout-17b-16e-instruct`. `max_history_messages` controls how many recent logged DM messages are sent as conversation context, and `max_vision_images` caps image attachments at Groq's 5-image request limit. Toast also sends Groq a compact summary of its bot duties, including radio playback, slash-command radio controls, account-linking support, and automated notification DMs. When a DM appears to ask about fridge.dev, Toast can also send small relevant context from `wiki/Home.md` and `wiki/Routes-and-Features.md` to Groq so replies can describe the site without sounding like developer docs.

Website feed generation adds stricter feed-only voice limits on top of the editable personality: Toast feed posts and automatic replies should stay short, closer to old-style Twitter posts than assistant answers. Automatic replies are capped after cleanup so an ignored prompt cannot turn into a forum-moderator essay.

Website and Discord AI prompts also append an identity anchor after loading editable personality JSON: Toast should describe themself as a humanoid fridge.dev resident whose closest relative is the homosapien, not as a bot, AI, assistant, agent, model, or program.

The website Toast feed generator and auto replies add feed-only wording rails to reduce repetitive openings like `just did`, `just made`, `just got`, `just found`, or `just realized`. Website feed post generation accepts a 1-5 length setting with deliberately strong steps: `one-liner`, `short`, `normal`, `ramble`, and `trauma dump`. The selected length applies both prompt instructions and cleanup caps, with max allowing a much longer vulnerable post while the shortest setting is forced down to a single short sentence. Feed generation sends only a small weak style sample from already-published non-Toast feed posts, never unpublished generated drafts, and instructs Groq not to reuse those samples' topics or phrasing. Recent published Toast posts are sent only as negative examples to avoid repeating their topics, imagery, openings, or emotional arc. Each generation also gets a private freshness seed with a random creative angle, texture, and anti-pattern so repeated clicks vary more. Toast feed posts are instructed to be self-contained personal thoughts rather than conversation starters, so they should not ask readers for feedback, replies, comments, validation, or suggestions, and should not acknowledge audience size or being alone. The website no longer stores its own Groq cooldown state; Groq 429s are returned directly after any short one-shot retry.

AI DM replies are split into sentence-aware Discord messages, usually 2-4 sentences per send depending on sentence length, while still staying below Discord's hard message limit. Toast waits at least 5 seconds before every AI reply chunk so the visible typing state never flashes and instantly dumps a response. Each Discord user has one active AI reply task: if another DM arrives while Toast is generating or pacing an unsent chunk, Toast cancels the unfinished reply and regenerates from the queued inbound DMs combined into one chronological prompt.

Toast's AI prompt includes an exact Discord slash-command allow-list: `/play`, `/stop`, `/status`, `/sendmsg`, and `/shareupdate`. Website paths such as `/feed` must be described as fridge.dev pages, not Discord slash commands.

### `toast-personality.json`

shared editable Toast personality source:

```json
{
  "discord": {
    "system_prompt": "core Discord Toast personality instructions",
    "style_rules": ["optional behavior/style rule"],
    "do_not": ["optional constraint"],
    "private_lore": "optional guarded lore"
  },
  "feed": {
    "system_prompt": "core feed-writing Toast personality instructions",
    "style_rules": ["optional behavior/style rule"],
    "do_not": ["optional constraint"],
    "private_lore": "optional guarded lore"
  }
}
```

`/settings` exposes this JSON only to the hardcoded `toast` session. Both `discord` and `feed` must be objects with non-empty `system_prompt` values. If the file is missing, website code seeds both blocks from `others/toast-discord-bot/bot/personality.json` in memory.

### `others/toast-discord-bot/bot/personality.json`

expected shape:

```json
{
  "system_prompt": "core Toast personality instructions",
  "style_rules": ["optional behavior/style rule"],
  "do_not": ["optional constraint"],
  "private_lore": "optional lore that Toast only shares when directly asked"
}
```

legacy Discord bot fallback. The bot now prefers `data/etc/toast-personality.json` and uses this file only when the shared file is missing or lacks a usable `discord` block. If both are missing, empty, or invalid, the bot logs a warning and uses a small built-in Toast fallback prompt. `private_lore` is included in the system prompt with an explicit guardrail to avoid volunteering it unless the user asks about Toast's origin, lore, backstory, life, or purpose.

### `toast-updates.json`

- array of timestamped bot status entries

### `toast-feed-notify-state.json`

- internal bot dedupe state for sent feed mention/reply notifications
- stores which feed post mentions, feed reply mentions, and replies to a user's own posts have already triggered DMs

### `toast-patch-approvals.json`

- persists pending Discord update approvals across bot restarts by mapping approval message IDs to their patch payload and destination channel
- retains recently approved message IDs so later reactions cannot publish the same update twice
- older Toast-authored approval embeds that predate this state file can be recovered from their commit URL and patch-note fields; legacy recoveries use the standard update channel
- sanitized developer data clears both pending and approved message IDs

### `feed-browser-notify-state.json`

- internal browser-notification dedupe state for logged-in users
- stores per-account `seenKeys` for feed mention/reply and journal notification events so a fresh browser login does not replay all historical matching events
- sanitized developer data replaces it with an empty `users` object

### `toast-dm-history.json`

- tracked inbound/outbound DM threads used by `/others/toast-discord-bot/messages`
- stores per-user profile snapshot data, optional `ai_muted` reply-suppression state, plus message history
- an inbound DM containing exactly `CLEARMEMORY` acts as a memory boundary for AI replies; future Groq context only includes messages after the newest boundary

### contact notification endpoint

- the toast bot exposes localhost-only `POST /contact/notify` on `127.0.0.1:8765`
- `/contact` calls it after saving a submission
- toast sends the alert to Discord channel `1503931489560301609`

### patch notice endpoint

- the toast bot exposes localhost-only `POST /patch-notice` on `127.0.0.1:8765`
- the deploy workflow calls it after a successful deploy to `main`
- toast sends the fully formatted patch notice preview to approval channel `1526075637096255548` and reacts to its own message with `✅`
- when an admin approves with `✅`, including on an older uncached approval message, toast posts the Discord embed patch note to channel `1455194403642802309`
- pending approvals persist across bot restarts; Toast can also reconstruct legacy approval messages from their stored Discord embed
- approved patch notices ping role `1408064850688475197` in the update channel so the update gets the right attention
- the payload includes the shipped commit range plus a PR link when the update came from a merged pull request

### `off-topic-archive.json`

- Discord export blob used by the archive viewer

### `page_views.json`

shape is roughly:

```json
{
  "pages": {
    "/": {
      "count": 12,
      "visitors": {
        "<sha256>": 1730931224
      }
    }
  },
  "updated_at": "2026-03-02T00:00:00Z"
}
```

## `data/downloads/`

- downloadable binaries, archives, presets, and similar files linked from the site
