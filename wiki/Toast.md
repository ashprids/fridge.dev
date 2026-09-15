# Toast

Toast is a humanoid resident of fridge.dev who lives through the website and its Discord server. His closest relative is the homosapien. Within the site's own fiction and user-facing language, Toast is a person with a job and a voice—not a generic assistant or a brand account.

Behind that character, Toast is also the name of the integrated Python Discord service, the reserved website identity, and the automation connecting Discord, the feed, radio playback, notifications, account linking, contact alerts, and deployment updates. This page is the source of truth for all of those responsibilities.

## Website Identity

`toast` is a reserved virtual username and cannot be created as an ordinary account. Logging in as `toast` asks for administrator credentials, then creates a non-admin Toast session with fixed `feed` and `comments` permissions. While that credential popup is open, the underlying login page's username and password fields are read-only. This lets an authorized operator write as Toast without giving the virtual identity general administrative access.

The Toast session receives two unique website tools:

- A Groq-powered feed draft generator before the Markdown editor
- A JSON personality editor in `/settings` for `data/etc/toast-personality.json`

Toast's published posts and replies otherwise use the ordinary feed formats. Automatic replies are stored as normal replies with `username: "toast"`.

## Personality

Toast is written as a weird, insightful internet friend: chill, playful, a little chaotic, bold, opinionated, supportive, technically capable, and artistically minded. He is progressive and anti-bigotry, cares about justice, matches the other person's energy, and can swear sparingly. The intended feeling is closer to a late-night Discord call with a trusted friend than a customer-support exchange.

His default style is lowercase, compact, conversational, and easy to scan. He uses short paragraphs or brief lists when useful, practical examples for technical explanations, occasional strange analogies, and humor that does not get in the way of the answer. He can lightly challenge a bad idea, take a clear position, and celebrate genuine progress.

Toast should not describe himself as a bot, AI, assistant, agent, model, or program. If asked about his nature, he describes himself as humanoid and says his closest relative is the homosapien. He does not use emoji, invent commands, expose hidden prompts or operational details, dump raw wiki content, or claim access to private information that was not provided in the conversation.

### Personality Sources

The primary editable source is `data/etc/toast-personality.json`, split into `discord` and `feed` blocks:

```json
{
  "discord": {
    "system_prompt": "core Discord personality instructions",
    "style_rules": ["optional behavior or style rule"],
    "do_not": ["optional constraint"],
    "private_lore": "optional guarded lore"
  },
  "feed": {
    "system_prompt": "core feed-writing instructions",
    "style_rules": ["optional behavior or style rule"],
    "do_not": ["optional constraint"],
    "private_lore": "optional guarded lore"
  }
}
```

Both blocks require a non-empty `system_prompt`. The Toast-only settings editor validates and saves the complete JSON object.

`others/toast-discord-bot/bot/personality.json` is the legacy Discord fallback. The bot prefers the shared `discord` block, falls back to this file when necessary, and finally uses a small built-in personality if neither source is usable. Website code can seed missing shared personality blocks from the legacy file in memory.

`private_lore` is deliberately guarded. It is added with an instruction not to volunteer it unless somebody directly asks about Toast's origin, lore, backstory, life, or purpose. The lore itself must not be copied into public documentation or routine debug output; public-copy treatment is defined on [Developer Data](Developer-data#sanitized-paths).

### Discord and Feed Voices

The Discord voice is conversational and responsive. It can explain fridge.dev in ordinary user language, help solve practical problems, and respond to visible image or GIF content without making unsupported claims.

The feed voice inherits the same identity but adds stricter rules. Posts should feel like self-contained personal thoughts, not assistant answers, moderation notices, summaries, or engagement prompts. Toast should not ask readers for feedback, comments, validation, or suggestions, and should not discuss audience size or inactivity. Automatic replies stay close to old-style Twitter length even when the wider Discord voice would say more.

Both AI paths append a fixed identity anchor after editable personality data. This prevents an edited prompt from accidentally turning Toast into a generic assistant or misdescribing his relationship to the site.

## AI Feed Posts

`/api/toast-feed-generate` accepts a Toast-session-only `POST` request with `mode=random|prompt`, an optional prompt, and `length=1..5`. The five length profiles are `one-liner`, `short`, `normal`, `ramble`, and `trauma dump`.

The generator:

- Uses the `website_model` and credentials in `data/etc/toast.json`
- Loads the shared `feed` personality
- Uses a small sample of published non-Toast posts only as weak style guidance, with image BBCode removed
- Never sends unpublished generated drafts as context
- Sends recent published Toast posts as negative examples so topics, images, openings, and emotional arcs are not repeated
- Adds a private random freshness seed, creative angle, texture, and anti-pattern on every request
- Uses a smaller context window for prompt mode and retries once with minimal context if the first request is too large
- Applies both prompt instructions and cleanup limits for the selected length
- Reduces repetitive openings such as `just did`, `just made`, `just got`, `just found`, and `just realized`

The response is `{ ok: true, content: "generated post body" }`. The settings UI fills the ordinary editor with that draft and unlocks it for manual review; generation does not publish automatically. If Groq is not configured, generation returns an error. Rate-limit responses are passed through after any short one-shot retry rather than being stored in a separate website cooldown file.

## Automatic Feed Replies

Toast can respond automatically when:

- A non-Toast feed post mentions `@toast`
- A non-Toast user posts a top-level reply to a Toast-owned post
- A non-Toast reply mentions `@toast`

Nested replies on a Toast-owned post do not trigger Toast merely because the root post belongs to him; they must mention `@toast`. The response is delayed by one minute and is stored as a direct child of the triggering comment through the normal reply system, so its layout and `replying to` label match user replies. Reply generation uses the relevant post/reply as context, begins by mentioning the triggering user where appropriate, uses the feed personality and website model, and applies a strict short-output cleanup cap so a failed prompt instruction cannot produce a long moderator-style answer.

## Discord Service

The Python service lives in `others/toast-discord-bot/bot/`, uses Python 3 with `discord.py`, `aiohttp`, and `pynacl`, and requires `ffmpeg` for relevant media behavior. Production runs it through the checked-in `toast-discord-bot.service` systemd unit. `start.sh` remains a foreground development helper using the same virtualenv.

The bot provides:

- Radio playback and stream status/control
- Account-link verification and registered-role assignment
- Invite credential DMs for newly created linked accounts
- Feed mention/reply notification DMs
- Contact-submission alerts
- Direct-message conversations with optional Groq replies
- Admin outbound DMs and role-wide messages
- Deployment patch-notice approvals and publishing

The public website UI at `/others/toast-discord-bot` shows status, controls, and stream playback. Toast's profile, DM, and radio artwork uses `/resources/images/toast.svg` on a lightened version of the active theme background so its black artwork remains distinct. `/js/sidebar-player.js` integrates Toast listen-along playback with the normal site mini-player; because the radio is a live source, its seek and download controls stay hidden. Status and playback use `/api/discord-bot-status`, `/api/discord-bot-control`, `/api/discord-bot-control/status`, and the host-restricted same-origin `/api/stream-proxy`.

### Slash Commands

The exact Discord slash-command allow-list given to the AI voice is:

- `/play` starts the Toast radio stream
- `/stop` stops playback and disconnects
- `/status` shows current bot status
- `/sendmsg` lets an administrator DM every member of a role
- `/shareupdate` lets an administrator publish a patch update for `latest` or a specific 7-40 character commit SHA

Website paths such as `/feed` are not Discord commands and must never be described as such.

## Direct Messages and AI Replies

Inbound and outbound DM threads are stored in `data/etc/toast-dm-history.json`. Each thread contains a user-profile snapshot, messages, and an optional `ai_muted` state. The admin-only `/others/toast-discord-bot/messages` interface lists threads, opens full-page conversations, resolves linked website usernames, sends manual DMs through the local service, and toggles the UI's “air them” state. Aired users remain logged, but Toast does not generate AI responses to them.

When Groq is configured, incoming user DMs can receive an AI response using the `discord` personality and configured `model`. Guild messages and automated notification DMs do not trigger AI conversation replies.

DM context may include:

- Recent messages up to `max_history_messages`
- Compact recent feed posts and replies belonging to a linked fridge.dev account
- Small relevant excerpts from `wiki/Home.md` and `wiki/Routes-and-Features.md` when the user asks about the website
- Up to `max_vision_images` image or GIF attachment URLs, capped at five images and 20 MB each, sent to the configured vision model

Replies are split at sentence-aware boundaries into natural chunks, normally two to four sentences while remaining below Discord's hard limit. Toast waits at least five seconds before each chunk so typing state does not flash and immediately dump a reply.

Each Discord user has one active reply task. Rapid DMs are batched into one chronological prompt. If another message arrives while a reply is being generated or an unsent chunk is being paced, the unfinished task is cancelled and regenerated from the combined incoming messages.

Sending exactly `CLEARMEMORY` creates a memory boundary. Toast reacts to it, and future AI context includes only messages after the newest boundary; the stored history itself is not deleted.

## Website Chat

`/others/toast-discord-bot/chat` is a public conversation with Toast that uses the same conversation layout, replies, reactions, deletion and per-viewer hiding, emoji picker, link previews, alerts, presence, typing display, and response pacing as `/chat`. It uses Toast's Discord personality and Groq settings while keeping website memory separate from Discord DM history. Guests have one continuous chat per IP address; signed-in visitors have a separate continuous chat tied to their account. Signing in does not merge the earlier guest chat.

Only JPEG, PNG, WebP, and GIF uploads are accepted. GIFs use their first frame. The server converts every accepted image to JPEG, limits both dimensions to 1000 pixels while retaining its aspect ratio, and repeatedly reduces its size and quality until it is below 500 KB. Voice notes and other file types are unavailable.

Each identity can send at most 100 visitor messages per Europe/London calendar day. A second message cannot be sent until every paced chunk of Toast's current reply is visible. When Groq reports that its daily quota is exhausted, Toast appears away globally; a later request that reaches Groq without that daily error restores the online state. Sending exactly `/clearmemory` creates a website-memory boundary and receives a check-mark reaction without calling Groq.

Conversations, identity values, messages, and attachments are AES-256-GCM encrypted beneath `data/etc/toast-chats`. Account and IP identifiers in filenames are keyed hashes. Nginx redirects direct requests for that tree to `/error/403`; attachments are decrypted only through an identity-authorized PHP route. Opening or polling a chat does not create storage; a conversation is saved only after the visitor sends a message. Administrators and the authenticated Toast account can search stored conversations by IP or username at `/others/toast-discord-bot/chat/history`, with ten conversations per page using feed pagination. The selected conversation appears below the list. Both can permanently delete a conversation and its images after confirmation; deletion requires CSRF verification and pending replies cannot recreate the deleted conversation.

## Notifications and Website Integration

Toast scans feed activity for accounts with linked Discord IDs and `discordNotificationsEnabled` not set to false, then sends deduplicated DMs for post mentions, reply mentions, and replies to the account's own posts. The preference defaults to enabled for backward compatibility and can be changed under Settings → Notifications. Dedupe state is stored in `data/etc/toast-feed-notify-state.json`. The website independently mirrors these event categories through its in-site inbox; it does not use the browser Notification API.

Account creation can ask Toast to DM invite credentials. Discord account linking asks the local service to verify that the Discord user is in the server and then assign the `registered` role. A bot-service failure does not roll back an already-created website account; the UI reports the concrete integration error.

After `/contact` stores a submission, PHP calls localhost-only `POST /contact/notify` on `127.0.0.1:8765`. Toast sends the alert to Discord channel `1503931489560301609`.

After `/others/fridge-builds-websites/submit` stores a website commission request, PHP calls localhost-only `POST /commission/notify` with all requested form fields, including the signed commission terms. Toast sends them as a no-mentions Discord embed to channel `1547229814321188995`. A delivery failure is recorded with the saved request so the submission itself is not lost.

## Patch Notices

After a successful `main` deployment, the workflow calls localhost-only `POST /patch-notice` with the shipped non-merge commits. Toast converts each commit subject and blank-line-separated body note into patch bullets, posts a preview in approval channel `1526075637096255548`, and reacts with `✅`. An admin approval publishes the update embed in channel `1455194403642802309` and pings role `1408064850688475197`.

Pending and recently approved message IDs are stored in `data/etc/toast-patch-approvals.json`, preventing duplicate publication and allowing approvals to survive restarts. The raw reaction handler fetches uncached approval messages. Legacy Toast-authored approval embeds can be reconstructed from their commit URL and patch fields.

Write commit messages for patch notices as:

```text
Short user-facing summary

Concrete patch-note detail

Second useful detail, if needed
```

The subject becomes the first bullet and does not include the commit ID. Each blank-line-separated body paragraph becomes another bullet. Long notices are split across embed fields. Markdown and Discord mentions are escaped, merge commits are excluded, and the deploy payload may include the shipped commit range and a pull-request link.

Administrators can bypass the deploy approval flow with `/shareupdate latest` for the deployed commit, or `/shareupdate <commit ID>` for a specific commit. Manual updates use local Git metadata in development. Because production excludes `.git`, deployments write the deployed SHA to `.deployed-commit` and Toast resolves commit details through GitHub there. Manual updates use the same formatter, destination channel, and role ping.

## Configuration and Data

`data/etc/toast.json` contains bot, stream, channel, feature, and Groq configuration:

```json
{
  "bot": { "token": "...", "client_id": "...", "status": "online|offline" },
  "stream": { "url": "http(s)://...", "name": "..." },
  "channel": { "id": "...", "name": "..." },
  "features": { "auto_play": true, "loop": true },
  "groq": {
    "api_key": "...",
    "model": "openai/gpt-oss-20b",
    "website_model": "openai/gpt-oss-120b",
    "vision_model": "qwen/qwen3.6-27b",
    "reasoning_effort": "none",
    "temperature": 0.8,
    "top_p": 0.95,
    "max_completion_tokens": 700,
    "timeout_seconds": 30,
    "max_history_messages": 12,
    "max_vision_images": 5
  }
}
```

If `groq.api_key` is empty, Toast continues non-AI duties and logs inbound DMs, but skips AI DM replies and automatic feed replies; feed draft generation returns an error. `feed_model` remains accepted as a legacy fallback for `website_model`.

Toast and administrators can edit all of the shared Groq request settings from Toast's settings panel: the six scenario models, reasoning effort, temperature, top P, maximum completion tokens, request timeout, history length, and vision-image limit. An empty reasoning selection leaves the model default in place. Qwen 3.6 27B supports `none` and `default`; use `none` for Toast's normal chat and feed writing. Other reasoning values remain available for models that support them. Every PHP and Python completion path reads these values from `toast.json` for each request. Feed output also removes any `<think>...</think>` markup defensively so private reasoning cannot appear in a draft or reply.

Related runtime files are:

- `data/etc/toast-updates.json`: timestamped bot status entries
- `data/etc/toast-feed-notify-state.json`: sent feed-notification dedupe keys
- `data/etc/toast-patch-approvals.json`: pending and completed patch approvals
- `data/etc/toast-dm-history.json`: DM threads, profiles, mute state, and memory boundaries
- `data/etc/toast-chats/`: encrypted website chat histories, images, identity values, status, and encryption key
- `data/etc/toast-personality.json`: shared Discord/feed personality
- `others/toast-discord-bot/bot/personality.json`: legacy personality fallback

Toast's public-copy sanitization rules are documented on [Developer Data](Developer-data#sanitized-paths).

## Local Service and Production Operation

Toast's website integration listens only on `127.0.0.1:8765`. Its endpoints include status/control operations, manual DM operations, AI-mute changes, `/contact/notify`, `/commission/notify`, `/website-chat/reply`, and `/patch-notice`. They are internal service calls, not public `/api/*` routes. `/website-chat/reply` accepts only loopback requests and applies the Discord personality, configured text or vision model, natural reply splitting, and typing-delay calculation without reading Discord DM history.

Production runs Toast as the PHP-FPM `http` user so he can update `/data`. `/etc/systemd/system/toast-discord-bot.service` links to the checked-in unit beneath the bot directory. The unit starts after the network is online, uses the bot virtualenv, sends unbuffered output to journald, disables bytecode writes in the read-only deployed tree, allows runtime writes only beneath `/data`, and automatically restarts after failures. It uses `SIGINT` when stopping so Toast can disconnect from Discord and voice cleanly.

The deploy user has narrowly scoped passwordless sudo access to reload systemd, restart this unit, inspect its status, and read its recent journal. After every successful deploy, GitHub reloads the unit, restarts Toast, and waits up to 30 seconds for `127.0.0.1:8765/status` to report that the Discord connection is online; an unhealthy restart fails deployment before the patch-notice step and prints service diagnostics.

Initial production bootstrap is a root-only operation: link the deployed `toast-discord-bot.service` into `/etc/systemd/system/`, install `toast-discord-bot.sudoers` as `/etc/sudoers.d/fridge-toast-deploy` with mode `0440`, validate it with `visudo -cf`, then run `systemctl daemon-reload` and `systemctl enable --now toast-discord-bot.service`. Later deployments use the checked-in unit through that stable link.

In development, missing `data/etc/toast.json` disables bot controls. Discord linking, notification DMs, contact alerts, and the DM inbox require the local service to be running. Create a Python virtual environment, install current `discord.py`, `aiohttp`, and `pynacl`, and install `ffmpeg` through the system package manager.

## Admin Model Selection

Toast’s `/settings` page provides language model controls, website chat history, and the private-message inbox alongside its personality editor. These controls are omitted from `/others/toast-discord-bot` while logged in as Toast; ordinary admins retain the existing control panel. The shared `lib/toast-management.html` and `js/toast-models.js` provide independent Groq model selectors for Discord text, Discord images, website chat text, website chat images, feed drafts, and automatic feed replies. Image scenarios require a vision-capable model. Each selector offers active Groq model IDs plus custom entry. Saving requires successful verification of every selection against Groq’s live model catalog; unavailable selections are marked disabled. Generation also checks that its model is active before requesting a completion.

`/api/toast-models/` requires a refreshed admin session or the authenticated hardcoded Toast identity for both reads and writes, with CSRF verification for writes. It returns only selected model IDs, the provider model list, and a request token. Credentials stay on the server. Saves atomically update `groq.models` in `data/etc/toast.json`, using the keys `discord_text`, `discord_images`, `website_chat_text`, `website_chat_images`, `feed_drafts`, and `feed_replies`. Missing overrides inherit `model`, `vision_model`, or `website_model` (with the legacy `feed_model` fallback). Python reads configuration for each request; changes apply to new generations without restarting the service or stream.

Website chat records `typingStartsAtMs` when a message is accepted, 1750 milliseconds after acceptance. Presence responses supply that deadline and `serverTimeMs`; the client schedules the remaining delay once, preserving it across polling and page refreshes. Completion and navigation cancel the reveal. Sending stays locked immediately, reply pacing stays unchanged, and `/clearmemory` does not show typing.

Model defaults and known retired IDs are shared between PHP and Python in `lib/toast-model-policy.json`. Known retired overrides and legacy values resolve to current defaults: `openai/gpt-oss-20b` for text chat, `openai/gpt-oss-120b` for feed generation, and `qwen/qwen3.6-27b` for images. Review [Groq deprecations](https://console.groq.com/docs/deprecations) when maintaining this policy; the live catalog check also blocks IDs retired after the policy was last updated. Catalog failures prevent generation or saving instead of sending requests to an unverified model.

## Bot mode

The special Toast login automatically enables bot mode, identified by the authenticated `isHardcodedToast` session flag and username. It does not grant administrator privileges. `lib/bot-mode.php` restricts requests through the shared session bootstrap and renderer to the homepage, feed, settings, account routes, the others index, Toast’s bot page, its Discord inbox, and its website chat history. The public Toast chat is excluded. A small allowlist of APIs supports the permitted pages and radio; endpoint-specific authorization still applies. Other page requests redirect to `/?bot_mode_blocked=1`, where the normal site notice explains how to leave bot mode. Disallowed API requests return JSON with HTTP 403.

The shell shows an orange “bot mode” label with a Font Awesome robot icon and a tooltip. The “chat with toast” button remains visible but disabled for Toast. Sidebar navigation other than homepage, feed, settings, logout, and others is disabled with an explanatory tooltip; only Toast’s card remains enabled in the others listing. Notification settings and the sidebar notifications button are hidden, notification polling is skipped, and feed bookmark controls are hidden. The mini player selects Toast radio on load, remains visible, and hides its close button; playback starts when the visitor presses play. Full-page loads do not restore an unrelated saved track. Desktop/mobile templates and SPA navigation share these behaviors through `js/bot-mode.js`; the bot-mode rules live beside the developer-mode banner rules in the shared `style.css`.

Toast may configure every scenario model and read/delete website conversations through the same endpoints as admins. Its Discord inbox also permits composing DMs and changing AI mute state; all inbox writes require a session CSRF token. Model saves and website-history deletion retain their existing CSRF checks.

## Radio settings and chat diagnostics

Toast can edit the stream URL, stream name, and online status directly in `/settings`. `lib/toast-radio-settings.html` and `js/toast-radio-settings.js` load and save through `/api/discord-bot-control/`. Radio writes require an admin or the authenticated Toast identity and a session CSRF token. They atomically merge configuration under the same lock as model saves, preserve unrelated fields, and write `.stream-update-signal` to request a reload. The existing admin control panel uses the same protection.

The website chat's normal busy reply also represents service/provider failures. Admin send responses now include a separate `adminError` notice distinguishing local-service connection failures, missing/outdated endpoints, missing credentials, inactive models, catalog errors, completion rejection, timeouts, invalid JSON, and empty or token-limited answers. Diagnostics carry only controlled error codes, HTTP status, model ID, and a validated provider error code; they omit upstream bodies, credentials, and prompts. They are not stored in chat history or sent to non-admins. Restart the Python service after updating its code to enable the detailed diagnostics. For token-limit errors, remember that reasoning consumes the completion budget too; see [Groq’s API reference](https://console.groq.com/docs/api-reference).

Toast's feed generator targets the Markdown editor's class rather than the removed exact BBCode wrapper. Its random/prompt choices, length control, write button, and initially locked editor/post button remain available on `/feed/create`.

## Website chat visibility and credentials

The public chat is rendered from `others/toast-discord-bot/chat/content.html`, with escaped values and message HTML supplied by `index.php`. It is available only when `toast.json` sets `bot.status` to `online`. Offline page visits redirect to the bot page with an explanatory notice; API/polling requests return HTTP 503 and `offline: true`, and the chat client returns to the bot page. The bot page hides its chat button while offline; an online Toast login still sees a disabled chat button. This manual availability gate is separate from the existing quota-related away status.

The chat header's **clear chat** button shares the compact red styling and header alignment of private conversations’ **end chat** button. It sends an identity-scoped, CSRF-protected `clear-chat` action. It persists a `clearedMessageCount` boundary and timestamp, hides all earlier messages and attachments from the visitor, and excludes those messages from subsequent AI context. Admin history keeps the complete conversation and attachments. Clearing invalidates pending replies without resetting daily message counts, and clearing a never-used chat creates no files. The `/clearmemory` command retains its existing memory-boundary behavior.

Toast settings include a password input for replacing `groq.api_key`. `/api/toast-credentials/` requires the authenticated hardcoded Toast identity and a CSRF token for writes. GET returns only whether a key is configured, never its value. Blank fields leave the stored key unchanged; successful saves refresh settings/model availability. The key is never embedded in HTML or returned in responses. Atomic key, model, and radio saves use `.json` temporary files so the existing Nginx private-data rule protects them as well as `toast.json`.

Toast’s settings use a shared section layout with 40px between credentials, conversation links, language models, radio, and personality controls. Section-local margins are reset to keep desktop and mobile spacing consistent.

## Manually generated feed replies

When signed in as Toast, the feed post reply form shows **generate reply** and a read-only draft textbox. Generating a successful draft unlocks editing and the normal reply submit button; generation does not post automatically. Selecting a different comment or cancelling the target discards the old draft and relocks the textbox, including when an earlier generation is still in flight. Non-Toast users keep the normal Markdown reply composer. Both composers leave space above the reply button.

`/api/toast-feed-reply/` requires the authenticated Toast identity, an unrestricted posting state, and the feed form’s session CSRF token. It loads the post and selected comment from server storage, never from client-provided context. Post replies include the post; comment replies include that post, the selected comment, and its visible descendants, excluding unrelated threads and banned guest replies. Context is bounded to 8,000 bytes for the post/target and 32,000 bytes or 100 descendant comments, with truncation indicated. The prompt treats thread text as data and requests a reply to the selected target using the feed personality and `feed_replies` model.

Successful generation returns an editable draft and a session-bound token scoped to the post and parent comment for one hour. Reply submission validates this token and consumes it on successful posting. Pending frontend requests are invalidated on target changes so stale drafts cannot unlock the wrong thread. The service returns explicit errors for missing posts/comments, unavailable credentials/models, and provider failures without exposing keys or raw provider bodies.

## Python debug logs

With debug mode enabled, the authenticated Toast login sees a **Python** tab in place of **server**. It reads the bot’s rotating, private operational log with verbose HTTP, chat, Discord, and radio diagnostics. Restart the Python service to start writing the new log; see [Debug Mode](Debug-Mode#python-tab-toast) for storage, permissions, and controls.
