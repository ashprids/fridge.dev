# Toast

Toast is a humanoid resident of fridge.dev who lives through the website and its Discord server. His closest relative is the homosapien. Within the site's own fiction and user-facing language, Toast is a person with a job and a voice—not a generic assistant or a brand account.

Behind that character, Toast is also the name of the integrated Python Discord service, the reserved website identity, and the automation connecting Discord, the feed, radio playback, notifications, account linking, contact alerts, and deployment updates. This page is the source of truth for all of those responsibilities.

## Website Identity

`toast` is a reserved virtual username and cannot be created as an ordinary account. Logging in as `toast` asks for administrator credentials, then creates a non-admin Toast session with fixed `feed` and `comments` permissions. This lets an authorized operator write as Toast without giving the virtual identity general administrative access.

The Toast session receives two unique website tools:

- A Groq-powered feed draft generator before the normal BBCode editor
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
- A non-Toast user replies to a Toast-owned post
- A non-Toast reply mentions `@toast`

The response is delayed by one minute and is stored through the normal reply system. Reply generation uses the relevant post/reply as context, begins by mentioning the triggering user where appropriate, uses the feed personality and website model, and applies a strict short-output cleanup cap so a failed prompt instruction cannot produce a long moderator-style answer.

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

The public website UI at `/others/toast-discord-bot` shows status, controls, and stream playback. `/js/sidebar-player.js` integrates Toast listen-along playback with the normal site mini-player. Status and playback use `/api/discord-bot-status`, `/api/discord-bot-control`, `/api/discord-bot-control/status`, and the host-restricted same-origin `/api/stream-proxy`.

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

## Notifications and Website Integration

Toast scans feed activity for accounts with linked Discord IDs and `discordNotificationsEnabled` not set to false, then sends deduplicated DMs for post mentions, reply mentions, and replies to the account's own posts. The preference defaults to enabled for backward compatibility and can be changed under Settings → Notifications. Dedupe state is stored in `data/etc/toast-feed-notify-state.json`. The website independently mirrors these event categories through its in-site inbox; it does not use the browser Notification API.

Account creation can ask Toast to DM invite credentials. Discord account linking asks the local service to verify that the Discord user is in the server and then assign the `registered` role. A bot-service failure does not roll back an already-created website account; the UI reports the concrete integration error.

After `/contact` stores a submission, PHP calls localhost-only `POST /contact/notify` on `127.0.0.1:8765`. Toast sends the alert to Discord channel `1503931489560301609`.

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

Administrators can bypass the deploy approval flow with `/shareupdate latest` for the deployed `HEAD`, or `/shareupdate <commit ID>` for a specific commit. Manual updates use the same formatter, destination channel, and role ping.

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

If `groq.api_key` is empty, Toast continues non-AI duties and logs inbound DMs, but skips AI DM replies and automatic feed replies; feed draft generation returns an error. `feed_model` remains accepted as a legacy fallback for `website_model`.

Related runtime files are:

- `data/etc/toast-updates.json`: timestamped bot status entries
- `data/etc/toast-feed-notify-state.json`: sent feed-notification dedupe keys
- `data/etc/toast-patch-approvals.json`: pending and completed patch approvals
- `data/etc/toast-dm-history.json`: DM threads, profiles, mute state, and memory boundaries
- `data/etc/toast-personality.json`: shared Discord/feed personality
- `others/toast-discord-bot/bot/personality.json`: legacy personality fallback

Toast's public-copy sanitization rules are documented on [Developer Data](Developer-data#sanitized-paths).

## Local Service and Production Operation

Toast's website integration listens only on `127.0.0.1:8765`. Its endpoints include status/control operations, manual DM operations, AI-mute changes, `/contact/notify`, and `/patch-notice`. They are internal service calls, not public `/api/*` routes.

Production runs Toast as the PHP-FPM `http` user so he can update `/data`. `/etc/systemd/system/toast-discord-bot.service` links to the checked-in unit beneath the bot directory. The unit starts after the network is online, uses the bot virtualenv, sends unbuffered output to journald, disables bytecode writes in the read-only deployed tree, allows runtime writes only beneath `/data`, and automatically restarts after failures. It uses `SIGINT` when stopping so Toast can disconnect from Discord and voice cleanly.

The deploy user has narrowly scoped passwordless sudo access to reload systemd, restart this unit, inspect its status, and read its recent journal. After every successful deploy, GitHub reloads the unit, restarts Toast, and waits up to 30 seconds for `127.0.0.1:8765/status` to report that the Discord connection is online; an unhealthy restart fails deployment before the patch-notice step and prints service diagnostics.

Initial production bootstrap is a root-only operation: link the deployed `toast-discord-bot.service` into `/etc/systemd/system/`, install `toast-discord-bot.sudoers` as `/etc/sudoers.d/fridge-toast-deploy` with mode `0440`, validate it with `visudo -cf`, then run `systemctl daemon-reload` and `systemctl enable --now toast-discord-bot.service`. Later deployments use the checked-in unit through that stable link.

In development, missing `data/etc/toast.json` disables bot controls. Discord linking, notification DMs, contact alerts, and the DM inbox require the local service to be running. Create a Python virtual environment, install current `discord.py`, `aiohttp`, and `pynacl`, and install `ffmpeg` through the system package manager.
