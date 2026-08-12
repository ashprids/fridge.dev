# Data Contracts

For how account restrictions, posting IP bans, hard bans, and browser/IP propagation interact, see [Restrictions and Moderation](Restrictions-and-Moderation).

This page documents live data shapes. The separate [Developer Data](Developer-data) page is the single source of truth for which fields and files are sanitized or excluded from public development copies.

## Big Rule

Runtime data lives under `/data`.

Repo intent:

- `/data` is not supposed to be versioned normally
- `.gitignore` ignores `/data/**/*`
- `.rsyncignore` excludes `/data/**` from deployment sync

So local/dev/prod data has to be managed separately.

## `data/accounts/`

### `accounts.json`

Expected top-level shape:

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
      "fruityDanceEnabled": false,
      "fruityDanceSpritesheet": "fl_chan.png",
      "fruityDanceLoop": 0,
      "fruityDanceSpeed": 100,
      "fruityDanceReflection": 30,
      "discordNotificationsEnabled": true,
      "reduceMotion": false,
      "titleAnimation": "wobble",
      "titleAnimationAlways": false,
      "titleAnimationDesync": true,
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

Notes:

- Extra unknown keys can exist and are preserved by `account/admin/edit`
- Bookmarks are the current source of truth for logged-in users
- Bookmark ids currently use raw feed ids and `journal:{id}`; legacy `newsletter:{id}` values can exist but are ignored
- `theme: default` is Blackprint and uses the base template plus `/style.css`; `theme: classic` enables saved `colors` for `bg`/`fg`/`border`/`subtle`/`links`; `theme: ambercrt` is shown as `CRT` and uses only saved `colors.links` as its main phosphor color; any other valid value refers to a `/themes/{theme-id}.json` file with `name`, `description`, `thumbnail`, `html`, and `css`
- Legacy `blackprint` normalizes to `default`, `custom` normalizes to `classic`, `newsprint` normalizes to `whiteprint`, `crt` normalizes to `ambercrt`, and removed `liminal`/`syswave` preferences normalize to `default`
- Text glow is stored in `glowIntensity`; the settings UI writes `none` for off and `medium` for on, while legacy `low`/`high` values are treated as enabled medium glow when saved again
- Title motion is stored in `titleAnimation` (`wobble`, `bounce`, `rubberhose`, `bubble`, `slot-machine`, `moonwalk`, or `heartbeat`), `titleAnimationAlways` (boolean), and `titleAnimationDesync` (boolean, default `true`); removed `pinball` values migrate to `wobble`; legacy `orbit`, `domino`, and `lava-lamp` values migrate to `bubble`; removed `tidal-wave`, `accordion`, and `typewriter` values migrate to `slot-machine`, while `helicopter`, `haunted`, and `juggle` migrate to `moonwalk`; guests keep the same values in local storage
- Accessibility toggles are stored as account booleans such as `reduceMotion`; logged-out browsers keep the same preferences in localStorage. Debug mode applies immediately when its checkbox changes and, for logged-in users, posts its account value independently of the general “save changes” action; changing that checkbox alone therefore does not mark the settings form dirty
- Fruity Dance stores `fruityDanceEnabled`, a `fruityDanceSpritesheet` filename discovered from `resources/images/fruity-dance`, a zero-based `fruityDanceLoop` from `0` through `9`, a speed percentage from `25` through `200`, and reflection strength from `0` through `100`; changes apply and persist immediately, while logged-out browsers keep the same normalized object in localStorage `fruityDancePrefs`
- The special `__custom__` spritesheet selection remains browser-local. Its image data is stored in localStorage `fruityDanceCustomImage`, while an optional JSON array of line-based `.txt` animation names is stored as `fruityDanceCustomMeta`; each name maps to one equal-height row containing eight frames. With no metadata, the standard ten-row animation list is used
- Directory spritesheets can have a same-basename `.txt` companion (for example, `fl_chan.png` and `fl_chan.txt`). Line 1 is the display name, line 2 is the spritesheet description, and subsequent non-empty lines after the separator are animation names in row order. The API exposes the selected sheet's parsed list as `fruityDanceAnimations`, allowing the eight-frame row layout to remain correct on every page
- `resources/images/fruity-dance/_custom.png` and `_custom2.png` are reserved and excluded from the directory catalog. The former is the static custom-spritesheet picker and live fallback, while the latter supplies that fallback's shadow. The live sprite is treated like corrupted 1990s sprite memory, using stepped palette-table failures, bad-address jumps, duplicated tile banks, missing scanlines, horizontal wrapping, and occasional sprite-buffer collapse. Removing the custom image clears both browser-local custom keys and restores these placeholders plus the standard animation list
- During one page session, a successful custom-image removal increments the in-memory placeholder corruption counter only while `/resources/images/fruity-dance/dance.mp3` is the active, unpaused, unmuted mini-player track at an effective volume above 5%. The first qualifying removal produces no visual change; removals two through seven progressively introduce slower, initially subtle corruption, with the seventh reaching level 10, defined as “maximum corruption.” The special track becomes available only on `/music` when Fruity Dance is enabled with the empty custom placeholder visible and the user has executed the exact global function `operationGetMeTheFuckOutOfHere()` while that placeholder is visible. It is mounted as an absolutely positioned child of the page scroller, well below the completed music page and outside `#content`, so discovering it requires scrolling while neither its fixed footprint nor randomized transforms can resize the ordinary content layout. A valid invocation produces a brief non-resetting screen fault before revealing the entry. The global function is installed only for that visible state; changing away deletes it and clears the unlock, so direct console invocation produces the browser's normal unknown-command `ReferenceError`. A saved reference also checks visibility and throws without changing state. It never persists as the mini-player's reload state: player-state writes substitute a random non-secret library track, and legacy saved secret states are similarly replaced during initialization. While it plays, device-level Media Session metadata publishes independently generated garbled title and artist fields, a blank album field, and the solid-black `resources/images/device-cover.svg` artwork; ordinary tracks retain their supplied metadata. Confirmed playback also corrupts the visible debug console into horizontal fragments and hides it without changing the saved debug preference. Its seek bar continues to display playback progress but is disabled, and attempts to seek through the site control, the native audio surface, or Media Session seek controls invoke the reset sequence; internal corruption rewinds are explicitly exempted. Once its playback is armed, ending or pausing it, or replacing its source, invokes the full-screen corruption reload (referred to as “reset sequence”). Before maximum, the placeholder reflects `_custom.png` normally and receives no palette distortion. At maximum, a separate intermittent palette-drift cycle introduces subtle hue, saturation, brightness, and contrast errors; reaching reflection strength 40% or lower latches `_custom2.png` as the shadow for the rest of that spritesheet selection, even if reflection is subsequently raised. The latch clears when the spritesheet changes or the page reloads. The counter is deliberately not persisted and resets when the page is left or reloaded
- On a locally detected developer-mode page (`#dev-mode-banner` is present), the escalation is shortened for testing: the first removal remains clean and the second immediately reaches maximum corruption
- Selecting the empty custom placeholder starts a one-shot contact window lasting a random 60 seconds through five minutes, or exactly ten seconds when `#dev-mode-banner` is present. Enabling debug mode at any point during that window permanently disqualifies the current selection. Otherwise, the window produces a persistent local toast headed `...can you hear me?`; activating it plays a non-refreshing one-second reset-style fault with a momentary centred placeholder frame, then removes the fault, frame, and toast. Once displayed, the toast is consumed for the current continuous Fruity Dance enabled session: switching spritesheets and returning to the placeholder cannot display it again until Fruity Dance has first been turned off and back on. The active timer and toast are cleared when Fruity Dance is disabled, a custom image is supplied, or another spritesheet is selected. Only the empty custom-placeholder container is raised above the debug console; normal and uploaded spritesheets retain the standard Fruity Dance stacking level
- `resources/images/fruity-dance/custom.txt` is a page-session-only placeholder client-log diagnostics script. It starts once per tab only when debug mode and the empty custom placeholder are simultaneously active, and its `[seconds]`, blank `[]`, parenthesized event, and `#` corruption directives respectively become timed waits, Enter gates, interaction gates/actions, and randomized garbled glyphs. Lines are emitted as transient `[?] > …` client entries that are explicitly excluded from restored debug history, while a session marker prevents the diagnostics restarting after refresh. Once any reset sequence has occurred, a durable browser-local branch flag makes future diagnostics load `custom2.txt` instead, using a separate session marker so the alternate script can begin later in the same tab but still cannot restart after its own refresh. That branch also permanently suppresses and removes the `...can you hear me?` contact toast. Event gates cover feed/text-box availability, generic interaction, active music, the scripted local notification, and the shadow-selection phase. Text-box detection is restricted to visible enabled controls inside `#content-main` and explicitly excludes the debug console and its search fields. The shadow phase invisibly suppresses page controls, reports `Nothing here.` for attempted clicks, ignores coordinate-less keyboard-synthesized clicks, and restores normal input only after a trusted pointer click lands on the visible portion of the shadow. Disabling debug mode, hiding the placeholder, or successfully running `operationGetMeTheFuckOutOfHere()` cancels all pending diagnostics timers and listeners
- At maximum corruption, the custom image button silently ignores activation without changing its appearance. The placeholder also becomes immovable: holding a primary pointer over her temporarily replaces dragging with an extreme VRAM-meltdown state—rapid palette cycling, blackout and overexposure frames, large colored buffer ghosts, block flashes, inversion, rotation faults, and accelerated tile corruption—which ends on pointer release or cancellation
- As placeholder's session corruption level rises, intermittent address tears, scanline corruption, displacement, and palette faults begin subtly across the page and increase nonlinearly toward maximum corruption. At maximum, active playback in the shared music player is corrupted through irregular short rewinds plus randomized speed and pitch faults without starting paused audio. Rotating the `_custom2.png` shadow amplifies the page and audio faults far beyond their normal maximum as it approaches upright; completing the upright snap rapidly decays the visual corruption and fades active music to silence
- When maximum corruption and the latched `_custom2.png` shadow are active at exactly 100% reflection, the otherwise noninteractive shadow becomes a rotation handle. Dragging it performs a plain `document.body` rotation around the point midway between placeholder and her shadow, with no scaling or overscan. Pointer rotation is geared down to 6% and rate-limited to 48° per second, requiring roughly eight complete circular gestures under ideal movement; accumulated rotation is hard-clamped to ±180° and never snaps across a threshold. Releasing early eases the page angle, tint, displacement, corruption, and reverb back to zero over 560ms. Reaching exactly ±180° advances immediately while the pointer is still held, permanently locks rotation for that page session, disables page interaction, reverses the document title and replaces supported characters with upside-down Unicode forms, settles the transform origin at the viewport centre, then drops the non-shadow sprite under gravity. The transformed title remains locked against notification-count updates for the rest of the terminal state. Playing music remains at normal `1×` speed and its chosen volume, while a granular AudioWorklet lowers pitch independently and a much longer, substantially louder convolution tail passes through the underwater filter. If AudioWorklet is unavailable, playback stays at normal speed rather than using speed-based pitch fallback. After the corruption fades, the upright page retains a stronger CRT-style displacement that waves narrow horizontal bands independently and a uniform blue tint without separate page-surface colour overrides. A monochrome-safe blue filter is applied to their common parent so both sprite layers receive matching blue colour and shadows without replacing their individual animated filters. The detached shadow remains fixed independently of scrolling, smoothly movable with captured WASD controls, and leans in the opposite direction around its own top-centre pivot. Partial vertical viewport escape scrolls only the sidebar and main `#container` pane in the upside-down direction opposite the previous tracking behavior; moving the entire shadow above or below the viewport triggers reset sequence
- Seven seconds after the fallen non-shadow placeholder sprite completely leaves the viewport, it becomes a pursuer from that same off-screen fall position. It retains maximum corruption, counter-rotates against the upside-down page so it appears upright, moves at `150px/s` versus `_custom2`'s `190px/s`, tilts with horizontal velocity, and uses deliberately slow steering/deceleration so abrupt WASD reversals can dodge it. Contact is tested with centred inset hitboxes that exclude the transparent margins around both 110×128 elements. Contact freezes both actors, captures further movement input, applies a severe rapid caught-meltdown stack to `_custom2`, and triggers reset sequence after a 520ms impact beat
- On `/journal` during the detached upright state, `_custom2` can engage one `.journal-post-link` at a time by moving left into the card's visible right edge using an inset shadow hitbox. That push face is the only permeable card edge; shallow-axis collision resolution makes every other side solid, including the non-push sides of an already active card. Continued leftward movement pushes that card toward the visible left edge only while the inset box remains in contact; losing contact releases it at its partial offset for later re-engagement. Once the card is wholly outside `#content-main`, it is released from the push and falls off-screen under gravity; only after it fully leaves the viewport is the removal counted. Its original layout box then remains invisibly in place, preserving the content height and inter-card gaps. Counts one through nine produce increasingly frequent and intense randomized imagery, clipping, displacement, and palette faults matching the special player's visual language, alongside irregular music rewinds/rate faults while preserving the detached mode's louder underwater reverb. The tenth reaches “maximum blue corruption”: page/pursuer/sprite-frame/CSS/audio animation freezes, active audio pauses after its reset trigger is explicitly disarmed, and pursuer collision can no longer invoke reset sequence; `_custom2` alone retains WASD movement and lean animation
- When the first journal card finishes falling, the browser permanently stores `displayAuxJournalAccess=1` in localStorage. With that flag, merely enabling Fruity Dance with the custom spritesheet choice selected unlocks the distant `/music` entry without the console function, and the next custom-image removal that meets the active-track and volume requirements advances the current page session directly to maximum corruption. The Fruity page initializer re-evaluates this flag after every SPA content replacement, so entering or leaving `/music` creates or removes the detached entry without requiring a refresh
- After maximum blue corruption freezes the tenth journal removal, `_custom2` retains unbounded WASD world coordinates while a terminal camera keeps its visible sprite inside inset viewport bounds. The frozen site shell moves with that camera over a pitch-black document background, while the frozen pursuer remains at its world coordinate and is left behind rather than following the camera. `_custom2` remains normally lit until the shell is entirely outside the viewport, then both its brightness/opacity and a dedicated black viewport shade crossfade linearly across the next 720 pixels of travel; returning toward the shell reverses both fades. Only after the shade is fully opaque and `_custom2` has zero opacity does the runtime store `displayAuxUnknownAccess=1` in localStorage and navigate to `/error/unknown`. That unfinished route is an empty black document for unlocked browsers; without the browser-local flag it displays only a small centred CSS padlock
- Once `displayAuxUnknownAccess` is present, activating the concealed `/music` grid entry is intercepted during capture before the mini-player can load its track. A top-level black layer fades in over 850ms and then navigates directly to `/error/unknown`
- Upright shadow movement uses compositor-backed `translate`/`rotate` properties rather than per-frame layout coordinates. The pursuing sprite uses `left`/`top` for its movement because its independent memory-fault animation owns the `translate` property. Viewport, journal, and pursuer collision geometry is sampled from a shared shadow rectangle at no more than 25Hz while the visual movement loop remains display-rate smooth. The full-page CRT displacement is likewise capped at 25Hz and turbulence seeds update at roughly 3Hz, limiting forced layout and filter regeneration without reducing movement responsiveness
- Every reset sequence immediately persists Fruity Dance as disabled with `fl_chan.png` selected and disables debug mode locally before showing the corruption transition; logged-in sessions send both resets through the settings API with keepalive requests. Attempting to pause, close, end, or replace the armed special track keeps its corrupted player presentation active and enters a dedicated broken-tape loop: capture-phase controls cannot clear the player, the source and last valid position are repeatedly reclaimed, playback is resumed, and irregular 14–34ms rewinds plus rate faults continue until the transition replaces browser history with the top of `/`. Any reset initiated after `_custom2` has locked upright displays a centred, corrupted `SIGNAL LOST` message in the IBM VGA font, including collision, audio-interruption, and viewport-exit paths
- Once maximum corruption has been reached during a page session, disabling Fruity Dance or selecting a different spritesheet triggers a full-screen corruption transition that captures input and force-reloads the page after the newly selected preference has been stored, whether or not `_custom2.png` has appeared
- Legacy `browserNotificationsEnabled` and `journalBrowserNotificationsEnabled` keys may remain in older account records as unknown preserved fields, but the application no longer reads, writes, or exposes them and does not use the browser Notification API
- `mustResetPassword` is used by the shared session bootstrap to force first-login password changes
- `postingRestricted` is an admin-managed account boolean; when enabled, server handlers reject new or edited feed posts, journal posts/drafts, feed replies, chat conversations/messages, guestbook entries, contact submissions, mdpaste creation, and upload room/signaling use, while matching composer notices keep text fields, formatting controls, uploads, and submit controls disabled
- `discordUserId` links a site account to a Discord member for bot DMs and notifications
- `discordNotificationsEnabled` controls automated Toast feed-notification DMs and defaults to `true` when absent; it has no effect until `discordUserId` is linked
- `emailAddress` marks accounts with a fridge.dev email mailbox; when present and valid, shared chrome swaps the footer Discord button to `/account/email`, and `/account/email` shows the assigned address
- `allowedPages` currently includes functional grants like `feed`, `journal`, `comments`, and `chat`

## `data/chat/`

One-time private conversations live as individual encrypted JSON envelopes:

```json
{
  "version": 1,
  "cipher": "aes-256-gcm",
  "nonce": "base64",
  "tag": "base64",
  "ciphertext": "base64"
}
```

Notes:

- Each file is named `{conversationId}.json`; new chat ids are 9 lowercase letters/numbers
- Legacy 32-character lowercase hex ids are still accepted so older active links do not break
- Decrypted payloads contain conversation metadata, the recipient label in `name`, the recipient cookie hash, and message records
- Messages may include an `attachment` object with encrypted blob metadata: `id`, `name`, `mime`, and `size`; image/audio/video attachments are served inline through the authorized chat route so they can render or play in the chat UI
- Messages may include `replyTo` with another message id, plus `reactions` keyed by valid emoji sequences with active viewer roles such as `manager` or `participant`
- Conversations may include `participantUsername` when a logged-in account claims the invite, or `participantHash` when an anonymous browser cookie claims it; the first-open popup copy changes based on that claim type
- Conversations may include `recipientIntroSeenAt` once the recipient has seen the first-open security/help popup
- Recipient cookies are HttpOnly and scoped to `/chat`
- The first non-manager account or anonymous browser to open `/chat/{conversationId}` claims the recipient slot
- Account-linked recipients can delete their own active chat; anonymous cookie-linked recipients cannot
- Admins and accounts with `allowedPages` containing `chat` can create, view, and delete conversations without claiming the recipient slot
- Deleting a conversation unlinks the encrypted JSON file immediately
- Encryption uses `FRIDG3_CHAT_KEY` when set; otherwise the app creates `data/chat/.chat_key`
- Lightweight presence indicators use sidecar files under `data/chat/.presence/{conversationId}.json`; current entries store `lastSeen`, `active`, and a short-lived `typingUntil`, while older timestamp-only entries are still readable
- Attachments are encrypted AES-256-GCM envelopes under `data/chat/.attachments/{conversationId}/`; they are served only through the authorized chat route and are deleted with the conversation
- Attachment uploads are capped at 8 MB

## `/themes/`

Theme metadata lives as JSON files directly under `/themes`.

```json
{
  "name": "Theme Name",
  "html": "template-file.html",
  "css": "stylesheet-file.css"
}
```

Notes:

- The metadata filename is the saved theme id, for example `/themes/cool.json` becomes `cool`
- `name` is the label shown in `/settings`
- `description` is the short supporting text shown under the theme name in the picker
- `thumbnail` is a 4:3 preview path relative to `/themes`, usually `thumbnails/{theme-id}.svg`
- `html` and `css` must be relative paths in `/themes/lib`, for example `aero/aero.html` and `aero/aero.css`
- Theme asset paths cannot be absolute, contain `..`, or use characters outside letters, numbers, `.`, `_`, `-`, and `/`
- Desktop rendering uses both themed HTML and CSS
- Mobile rendering keeps `template_mobile.html` and only swaps the CSS

### `login_attempts.json`

- Map of client IP -> unix timestamp array
- Used for login throttling

## `data/feed/`

Legacy feed post format:

1. `@username`
2. `YYYY-MM-DD HH:MM:SS`
3. Body text / BBCode

Version 2 feed post format:

1. `v2`
2. `@username`
3. `YYYY-MM-DD HH:MM:SS`
4. Feed-subset Markdown body

Readers must inspect the first line before assigning username/date offsets. Files without the exact `v2` marker remain legacy BBCode and must not be passed through the Markdown renderer.

The v2 feed subset supports `**bold**`, `*italic*`, `<u>underline</u>`, `~~strikethrough~~`, `==highlight==`, `[links](URL)`, feed media syntax, `>` blockquotes, inline/fenced code, pipe tables, `||spoiler text||`, nested ordered/unordered lists, and Font Awesome icons using `!fa style icon-name`. Lists and icons are supported through typed syntax without toolbar buttons. Other Markdown constructs and arbitrary HTML are displayed literally.

Other file:

- `index.toml` is generated by `/feed/index.php`

Feed bodies can include public voice notes as BBCode:

```bbcode
[audio=/data/audio/voice/example.m4a][name:voice-note.m4a]
```

Voice notes are created from temporary `[voice:N]` editor placeholders, verified at upload time, normally transcoded to small mono `.m4a` files, and stored under `data/audio/voice/`. If ffmpeg cannot decode a valid browser recording container, the validated original WebM, Ogg, MP4/M4A, MP3, or WAV recording is retained so posting does not fail solely because of browser codec differences.

### `data/feed/replies/`

Per-post replies live in `{postId}.json` files shaped roughly like:

```json
{
  "replies": [
    {
      "id": "20260413153000_deadbeef",
      "username": "Anonymous",
      "date": "2026-04-13 15:30:00",
      "body": "reply body with Markdown",
      "format": "v2",
      "parentId": "optional parent reply id for comment replies",
      "isGuest": true,
      "ip": "203.0.113.10",
      "guestBrowserId": "optional browser-local in-site inbox identity"
    }
  ]
}
```

Notes:

- Reply ids are generated on write; older data may be normalized into `legacy_*` ids at read time
- New account and guest replies store `format: "v2"` and use the restricted feed Markdown renderer. For compatibility with stale cached writers, missing markers fall back to the rollout timestamp and then body inspection: recognized legacy BBCode stays legacy, while plain or Markdown bodies use the restricted Markdown renderer; plain older replies remain visually unchanged
- Replies to individual comments are stored in the same flat array with optional `parentId`; older top-level replies simply omit it
- Guest replies may include `guestBrowserId`, a random browser-local identity used only so guests can receive in-site inbox notifications when someone replies to their comments from another browser/account
- V2 reply bodies store uploaded images as Markdown and uploaded audio/video or voice notes as the renderer's supported safe media HTML; legacy replies retain their existing media BBCode
- Guest replies include `isGuest: true` plus a plaintext `ip`; guest display names are stored in `username`, default to `Anonymous`, cannot match a registered account username case-insensitively, and are filtered with guest reply bodies through `/feed/filters/*.txt` before storage; matching body text becomes tooltip-wrapped `★` text explaining `this phrase was automatically filtered.`; guest replies that are mostly filter-list terms are rejected, and guest replies containing filtered text are locked from later guest edits; admin moderation can purge all guest replies with a matching IP without changing the IP ban list
- Toast-authored reply storage and automatic reply behavior are documented on [Toast](Toast#automatic-feed-replies)

### `data/feed/banned_ips.json`

IP-keyed posting-ban records can contain ban metadata and usernames observed for each address. Enforcement and moderation behavior are documented on [Restrictions and Moderation](Restrictions-and-Moderation#posting-ip-bans).

### `data/etc/hard-banned-ips.txt`

Whitespace-separated exact IPv4 or IPv6 addresses, normalized to one address per line by the admin editor. Enforcement and access behavior are documented on [Restrictions and Moderation](Restrictions-and-Moderation#hard-bans).

### `data/etc/hard-ban-identities.json`

Private records containing a browser identifier's original manually banned IP, observed IPs, first/last-seen timestamps, and user-agent hash. Propagation and removal behavior are documented on [Restrictions and Moderation](Restrictions-and-Moderation#browser-identity-propagation).

### `data/etc/hard-ban-settings.json`

Global object containing `strictIdentityEnforcement` and `enforcementEnabled` booleans, both defaulting to `true`, plus the exact-address `whitelistedIps` array. Policy behavior is documented on [Restrictions and Moderation](Restrictions-and-Moderation#browser-identity-propagation), and its access-log controls are documented on [Debug Mode](Debug-Mode#access-tab).

### `data/etc/site-notices.json`

Global visitor notices managed through the admin-only `/settings/notices` page. It has independent `users` and `guests` blocks, each with an optional `banner` and `popup`, plus a `pages` array for exact-path notices. Page records include `path`, an `audiences` array containing `users`, `guests`, or both, and `type` alongside the corresponding banner or popup fields; legacy page records with one string `audience` are normalized into the array. A matching page notice overrides the global notice of the same type for each selected audience on that path. Banner records contain a revision `id`, plaintext `message`, and `dismissible` flag. Popup records contain a revision `id`, plaintext `title` and `message`, plus optional `buttonLabel` and site-relative `buttonUrl`. A new save receives a new revision ID, so browser-local banner dismissal and popup acknowledgement apply only to that saved revision.

### `data/etc/targeted-notifications.json`

Admin-issued persistent inbox notifications for a registered username, exact IP, all logged-in users, or all guests. Multiple selected users/IPs are stored as individual records. Each record contains an immutable ID, target type/value, title, message, site-relative URL, and creation date. Read and dismissal state remains per inbox identity in `notification-inbox-state.json`.

### `data/etc/banlists/**/*.txt`

Recursive read-only `.txt` sources containing whitespace-separated exact IPv4/IPv6 addresses or CIDR ranges. Generated binary index data lives beneath `data/etc/banlists/index/`. Source validation, index construction, caching, fallback behavior, and enforcement are documented on [Restrictions and Moderation](Restrictions-and-Moderation#hard-bans).

## `data/journal/`

Legacy `.txt` journal post:

1. `YYYY-MM-DD`
2. Title
3. Description
4. Optional `CARD_IMAGE:<url>`
5. Trusted HTML body

New journal posts are `{id}.md` files:

1. Exact `v2` version marker
2. YAML front matter containing `title`, `description`, `date`, and optional `card_image`; journal posts do not set `author`
3. Full site-supported Markdown body

The description uses the article subtitle styling without an author prefix, and the card-image metadata is not part of the rendered body. When the card image is absent, the listing falls back to the first Markdown or legacy HTML image. Readers discover both `.md` and `.txt`, prefer `.md` when resolving an ID, and render unversioned `.txt` bodies with the unchanged trusted-HTML legacy path. The parser accepts the earlier v2 `author` field as a description fallback so development posts created during the transition remain readable.

Draft format:

1. `USER:<username>`
2. Title
3. Description
4. Optional `FORMAT:html` or `FORMAT:markdown`
5. Draft body

Without a format marker, preview treats the body as legacy BBCode. `FORMAT:html` preserves raw-HTML legacy edits, while `FORMAT:markdown` uses the full Markdown renderer.

## `data/guestbook/`

Entry format:

1. Timestamp
2. Display name
3. Optional `IP:<address>` metadata for new entries
4. Message body (line 3 for legacy or sanitized entries without IP metadata)

Plus:

- `ip_index.json` for one-post-per-IP ownership tracking
- Successful entry creation adds one targeted in-site notification per admin account to `data/etc/targeted-notifications.json`
- Nginx blocks direct client access to `/data/guestbook` and its descendants; entries are exposed only through the PHP guestbook and admin moderation views

## `data/images/`

- Uploaded images used across feed, journal, and gallery content
- Expected web path is `/data/images/<filename>`
- `data/images/thumbnails/` contains regenerable 500×500 JPEG thumbnails used by gallery tiles and local journal card backgrounds, keyed by a hash of the original filename; these are excluded from the top-level gallery listing and removed alongside originals by the gallery delete API

Feed and journal attachment uploads use typed temporary `[img:N]`, `[audio:N]`, or `[video:N]` editor placeholders. Every source media attachment is capped at 8 MB. V2 feed and journal posts resolve these to Markdown images or safe audio/video elements; legacy writers retain their BBCode records. Images keep the existing 1 MB stored-image limit: files already under the limit retain their validated JPEG, PNG, GIF, or WebP format, while larger images are converted to JPEG with PHP GD or an `ffmpeg` fallback. Validation uses the detected image MIME rather than trusting the filename extension. Audio is stored beneath `data/audio/uploads/`; video is stored beneath `data/video/`. Allowed audio formats are MP3, AAC, M4A, OGG, WAV, FLAC, and WebM; allowed video formats are MP4, WebM, Ogg video, and QuickTime. Because libmagic can report audio-only WebM, MP4/M4A, and Ogg containers as their shared video/application types, validated shared containers declared by the browser as audio are classified as audio; MIME parameters such as WebM codecs are ignored during this comparison. Temporary media indexes are reset when SPA navigation creates a new editor, and content with an unresolved upload placeholder is rejected rather than persisted.

## `data/music/`

Artist folders currently include:

- `frdg3`
- `cactile`

Album JSON shape:

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

The `/music/upload` admin page writes audio files to `data/audio/`, cover art to `data/images/`, and creates release JSON in the selected artist folder. `single`, `remix`, and `album` uploads can store multiple tracks, preserving the submitted row order in `songs`. Release `order` is assigned automatically as one higher than the current highest order in that artist folder. Admins can optionally set `scheduled_at` with a publish date/time; future-dated releases stay hidden from non-admins on `/music` until that timestamp, while admins can still see them with a scheduled label. Uploaded audio accepts `mp3`, `wav`, `m4a`, `ogg`, and `flac` with no app-level size cap; uploaded cover art accepts `jpg`, `png`, `gif`, and `webp`. The deployed Nginx and PHP-FPM config must also allow unlimited request bodies; local PHP dev servers may need equivalent `-d upload_max_filesize=0 -d post_max_size=0` startup flags because they do not always read `.user.ini`.

## `data/audio/`

- Track files referenced by music metadata
- Also used by shared playback features
- `data/audio/voice/` stores public feed voice notes as compressed `.m4a` files
- `data/audio/uploads/` stores feed and journal audio uploads in the same square player style as voice notes, but without the voice-note playback-speed control; the legacy `data/audio/attachments/` path remains recognized for cleanup of older posts

## `data/video/`

- Stores feed and journal video attachments for the inline, site-styled video player; its controls overlay the video and attachment filenames are not displayed

## `data/contact/`

- Private contact submissions as `{YYYYMMDDHHMMSS}_{random}.json`
- Each submission stores `id`, `createdAt`, hashed IP, user agent, name, email, message, notification channel id, and optional `notifyError`
- Successful submissions also append one targeted in-site notification per admin account to `data/etc/targeted-notifications.json`
- `rate_limits.json` stores hashed client IP keys mapped to recent submission timestamps for throttling
- Nginx blocks direct web access to this directory; submissions are only shown through the admin-only `/contact?dashboard=1` route

## `data/mdpaste/`

- Temporary markdown paste records as `{id}.json`
- Ids are 16 lowercase hex characters
- Records expire after 30 days and are cleaned up opportunistically on create/view
- Unencrypted records store a `markdown` string
- Encrypted records store only AES-256-GCM ciphertext plus PBKDF2-SHA256 salt/nonce/tag metadata; the password is never stored
- `hard_breaks` controls whether single paragraph newlines render as `<br>` instead of spaces

## `data/etc/`

### `wip`

- Plain text maintenance flag
- Truthy values such as `true`, `1`, `yes`, `on`, `enabled`, or `wip` enable maintenance mode
- Enforced server-side by `lib/session.php` and `lib/render.php`; non-admin requests redirect to `/error/wip`

### `webhooks.json`

Used key:

```json
{
  "discord_feed": "https://discord.com/api/webhooks/..."
}
```

### Toast Data

Toast configuration, personality, AI behavior, notification state, approvals, DM history, and internal service endpoints are documented on [Toast](Toast#configuration-and-data).

### `notification-inbox-state.json`

- Stores in-site notification read and dismissal state
- `identities` keys are prefixed with `account:` for lowercase usernames or `guest:` for browser-local guest inbox identities
- Each identity retains up to 4,000 `readKeys` and `dismissedKeys` plus an `updatedAt` timestamp; feed notification content is derived from feed and reply records rather than duplicated in this file

### `notification-revision.txt`

- Opaque revision value updated after notification-producing feed/targeted writes and inbox read/dismissal changes
- The lightweight revision endpoint reads this single small file so browsers do not repeatedly rebuild or poll their full inboxes

### `off-topic-archive.json`

- Discord export blob used by the archive viewer

### `page_views.json`

Shape is roughly:

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

- Downloadable binaries, archives, presets, and similar files linked from the site
